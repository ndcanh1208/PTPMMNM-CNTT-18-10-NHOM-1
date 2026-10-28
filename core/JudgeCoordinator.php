<?php
/**
 * BỘ TỰ VIẾT ĐIỀU PHỐI CHẤM BÀI (JUDGE COORDINATOR)
 * Đề tài 20: Tự điều phối quy trình chấm, bảo vệ test kín, chống ghi đè kết quả cũ và phân biệt lỗi hệ thống
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/OutputComparator.php';
require_once __DIR__ . '/ExecutionService.php';

class JudgeCoordinator {
    /**
     * Điều phối toàn bộ quá trình chấm điểm một bài nộp
     *
     * @param int $submissionId Mã bài nộp cần chấm
     * @param int|null $targetVersionId Phiên bản test case sử dụng (dùng khi chấm lại)
     * @return array Kết quả tổng hợp của lượt chấm
     */
    public static function judge(int $submissionId, ?int $targetVersionId = null): array {
        $pdo = Database::getConnection();

        // 1. Lấy thông tin bài nộp
        $stmtSub = $pdo->prepare("
            SELECT s.*, p.title as problem_title, pv.time_limit_ms, pv.memory_limit_kb 
            FROM submissions s
            JOIN problems p ON s.problem_id = p.id
            JOIN problem_versions pv ON s.problem_version_id = pv.id
            WHERE s.id = ?
        ");
        $stmtSub->execute([$submissionId]);
        $sub = $stmtSub->fetch();

        if (!$sub) {
            return ['success' => false, 'message' => "Không tìm thấy bài nộp ID {$submissionId}"];
        }

        $versionId = $targetVersionId ?: (int)$sub['problem_version_id'];
        $timeLimitMs = (int)($sub['time_limit_ms'] ?: DEFAULT_TIME_LIMIT_MS);

        // 2. Cập nhật hàng đợi sang PROCESSING
        $pdo->prepare("UPDATE submissions SET queue_status = 'PROCESSING' WHERE id = ?")->execute([$submissionId]);

        // 3. Quản lý phiên chấm (Run Number & Chống ghi đè kết quả cũ)
        // Tìm số thứ tự lần chấm tiếp theo
        $stmtRunNo = $pdo->prepare("SELECT COALESCE(MAX(run_number), 0) + 1 FROM judging_runs WHERE submission_id = ?");
        $stmtRunNo->execute([$submissionId]);
        $runNumber = (int)$stmtRunNo->fetchColumn();

        // Đánh dấu các lần chấm trước đó là is_latest = 0
        $pdo->prepare("UPDATE judging_runs SET is_latest = 0 WHERE submission_id = ?")->execute([$submissionId]);

        // Tạo bản ghi lượt chấm mới (judging_run)
        $stmtNewRun = $pdo->prepare("
            INSERT INTO judging_runs (
                submission_id, problem_version_id, run_number, verdict, 
                total_score, passed_test_count, total_test_count, is_latest, started_at
            ) VALUES (?, ?, ?, ?, 0.00, 0, 0, 1, NOW())
        ");
        $stmtNewRun->execute([$submissionId, $versionId, $runNumber, VERDICT_RUNNING]);
        $runId = (int)$pdo->lastInsertId();

        // 4. Lấy bộ dữ liệu kiểm tra của phiên bản đề này
        $stmtTests = $pdo->prepare("
            SELECT id, input_data, expected_output, is_sample, score_weight, order_index 
            FROM test_cases 
            WHERE problem_version_id = ? 
            ORDER BY order_index ASC
        ");
        $stmtTests->execute([$versionId]);
        $testCases = $stmtTests->fetchAll();

        $totalTests = count($testCases);
        if ($totalTests === 0) {
            // Không có test case nào được cấu hình
            self::finishRun($runId, $submissionId, VERDICT_SE, 0, 0, 0, 0, null, "Bài tập chưa có bộ dữ liệu kiểm tra.");
            return ['success' => false, 'verdict' => VERDICT_SE, 'message' => 'Bài tập chưa có test case'];
        }

        $passedTests = 0;
        $totalScore = 0.00;
        $maxTimeMs = 0;
        $finalVerdict = VERDICT_AC;
        $firstFailingVerdict = null;
        $compileLog = null;
        $systemLog = null;

        // 5. Vòng lặp điều phối thực thi từng test case
        foreach ($testCases as $index => $tc) {
            $input = $tc['input_data'];
            $expected = $tc['expected_output'];
            $weight = (float)$tc['score_weight'];

            // Gửi sang dịch vụ thực thi cách ly Piston
            $execResult = ExecutionService::execute($sub['source_code'], $sub['language_id'], $input, $timeLimitMs);
            $maxTimeMs = max($maxTimeMs, (int)($execResult['time_ms'] ?? 0));

            // A. XỬ LÝ LỖI HỆ THỐNG (SE)
            if ($execResult['status'] === VERDICT_SE) {
                $finalVerdict = VERDICT_SE;
                $systemLog = $execResult['message'];
                
                // Ghi nhận test case bị lỗi hệ thống
                $pdo->prepare("
                    INSERT INTO case_results (judging_run_id, test_case_id, verdict, execution_time_ms, error_message)
                    VALUES (?, ?, 'RUNTIME_ERROR', ?, ?)
                ")->execute([$runId, $tc['id'], (int)($execResult['time_ms'] ?? 0), 'Lỗi dịch vụ thực thi (System Error)']);

                // Dừng chấm ngay lập tức và bảo lưu trạng thái hệ thống
                break;
            }

            // B. XỬ LÝ LỖI BIÊN DỊCH (CE)
            if ($execResult['status'] === VERDICT_CE) {
                $finalVerdict = VERDICT_CE;
                $compileLog = $execResult['stderr'];
                // Lỗi biên dịch thì dừng ngay lập tức, không cần chạy các test sau
                break;
            }

            // C. XỬ LÝ QUÁ THỜI GIAN (TLE)
            if ($execResult['status'] === VERDICT_TLE) {
                $caseVerdict = 'TIME_LIMIT_EXCEEDED';
                if (!$firstFailingVerdict) $firstFailingVerdict = VERDICT_TLE;

                $pdo->prepare("
                    INSERT INTO case_results (judging_run_id, test_case_id, verdict, execution_time_ms, error_message)
                    VALUES (?, ?, ?, ?, ?)
                ")->execute([$runId, $tc['id'], $caseVerdict, $timeLimitMs, 'Chạy quá thời gian cho phép']);
                continue;
            }

            // D. XỬ LÝ LỖI KHI CHẠY (RE)
            if ($execResult['status'] === VERDICT_RE) {
                $caseVerdict = 'RUNTIME_ERROR';
                if (!$firstFailingVerdict) $firstFailingVerdict = VERDICT_RE;

                $pdo->prepare("
                    INSERT INTO case_results (judging_run_id, test_case_id, verdict, execution_time_ms, error_message)
                    VALUES (?, ?, ?, ?, ?)
                ")->execute([$runId, $tc['id'], $caseVerdict, (int)$execResult['time_ms'], mb_substr($execResult['stderr'], 0, 255)]);
                continue;
            }

            // E. XỬ LÝ SO SÁNH ĐẦU RA 3 BƯỚC (AC hoặc WA)
            $actualOutput = $execResult['stdout'] ?? '';
            $isCorrect = OutputComparator::compare($actualOutput, $expected);

            if ($isCorrect) {
                $caseVerdict = 'PASSED';
                $passedTests++;
                $totalScore += $weight;

                // Test PASSED: actual_output để NULL để tối ưu dung lượng CSDL
                $pdo->prepare("
                    INSERT INTO case_results (judging_run_id, test_case_id, verdict, execution_time_ms, actual_output)
                    VALUES (?, ?, 'PASSED', ?, NULL)
                ")->execute([$runId, $tc['id'], (int)$execResult['time_ms']]);
            } else {
                $caseVerdict = 'FAILED';
                if (!$firstFailingVerdict) $firstFailingVerdict = VERDICT_WA;

                // Test FAILED: Chỉ lưu actual_output (cắt 500 ký tự) phục vụ giảng viên kiểm tra, không trả về cho học viên
                $safeActual = mb_substr($actualOutput, 0, 500);
                $pdo->prepare("
                    INSERT INTO case_results (judging_run_id, test_case_id, verdict, execution_time_ms, actual_output)
                    VALUES (?, ?, 'FAILED', ?, ?)
                ")->execute([$runId, $tc['id'], (int)$execResult['time_ms'], $safeActual]);
            }
        }

        // 6. Tổng hợp kết luận cuối cùng (Final Verdict)
        if ($finalVerdict !== VERDICT_SE && $finalVerdict !== VERDICT_CE) {
            if ($passedTests === $totalTests) {
                $finalVerdict = VERDICT_AC;
            } else {
                $finalVerdict = $firstFailingVerdict ?: VERDICT_WA;
            }
        }

        // 7. Cập nhật hoàn tất lượt chấm
        self::finishRun($runId, $submissionId, $finalVerdict, $totalScore, $passedTests, $totalTests, $maxTimeMs, $compileLog, $systemLog);

        return [
            'success'            => true,
            'run_id'             => $runId,
            'verdict'            => $finalVerdict,
            'total_score'        => $totalScore,
            'passed_test_count'  => $passedTests,
            'total_test_count'   => $totalTests,
            'max_time_ms'        => $maxTimeMs,
            'compile_log'        => $compileLog,
            'system_log'         => $systemLog
        ];
    }

    /**
     * Cập nhật kết thúc lượt chấm bài
     */
    private static function finishRun(
        int $runId, int $submissionId, string $verdict, float $score, 
        int $passed, int $total, int $maxTime, ?string $compileLog, ?string $systemLog
    ): void {
        $pdo = Database::getConnection();

        $stmt = $pdo->prepare("
            UPDATE judging_runs SET
                verdict = ?,
                total_score = ?,
                passed_test_count = ?,
                total_test_count = ?,
                max_time_ms = ?,
                compile_log = ?,
                system_log = ?,
                finished_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([$verdict, $score, $passed, $total, $maxTime, $compileLog, $systemLog, $runId]);

        // Đánh dấu hoàn thành trong bảng submissions
        $queueStatus = ($verdict === VERDICT_SE) ? 'FAILED' : 'COMPLETED';
        $pdo->prepare("UPDATE submissions SET queue_status = ? WHERE id = ?")->execute([$queueStatus, $submissionId]);
    }
}
