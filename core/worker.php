<?php
/**
 * ByteFlow Online Judge - Tiến trình Worker Điều phối Chấm bài Độc lập
 * Chạy nền qua CLI: php core/worker.php [--once] [--sleep=1]
 * 
 * Tuân thủ tuyệt đối:
 * - Nguyên tắc 1: Tách hoàn toàn khỏi tiến trình Web
 * - Nguyên tắc 3: Chống ghi đè kết quả cũ về muộn (Stale Run Protection)
 * - Nguyên tắc 4: Bảo mật dữ liệu chấm kín
 * - Nguyên tắc 5: Phân biệt lỗi bài làm (AC/WA/TLE/RE/CE) và lỗi hệ thống (SE)
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/SandboxRunner.php';
require_once __DIR__ . '/Comparator.php';
require_once __DIR__ . '/Security.php';

class JudgingWorker
{
    private PDO $pdo;
    private SandboxRunner $sandbox;
    private string $workerId;
    private bool $runOnce = false;
    private int $sleepSeconds = 1;

    public function __construct(bool $runOnce = false, int $sleepSeconds = 1)
    {
        $this->pdo = getDbConnection();
        $this->sandbox = new SandboxRunner();
        $this->workerId = sprintf('worker_%d_%s', getmypid(), bin2hex(random_bytes(4)));
        $this->runOnce = $runOnce;
        $this->sleepSeconds = max(1, $sleepSeconds);
    }

    public function start(): void
    {
        echo "====================================================================\n";
        echo "   BYTEFLOW ONLINE JUDGE - WORKER CHẤM BÀI NỀN ĐỘC LẬP              \n";
        echo "====================================================================\n";
        echo "Worker ID   : {$this->workerId}\n";
        echo "Môi trường  : CLI độc lập (PHP " . PHP_VERSION . ")\n";
        echo "Trình biên dịch Python: " . ($this->sandbox->getPythonPath() ?: '[Chưa tìm thấy]') . "\n";
        echo "Trình biên dịch G++   : " . ($this->sandbox->getGppPath() ?: '[Chưa tìm thấy]') . "\n";
        echo "Chế độ chạy : " . ($this->runOnce ? 'Chạy một lượt (--once)' : "Lắng nghe liên tục (Chu kỳ {$this->sleepSeconds}s)") . "\n";
        echo "====================================================================\n";
        echo "Đang sẵn sàng nhận việc từ hàng đợi...\n\n";

        do {
            $processed = $this->processNextQueuedRun();

            if (!$processed) {
                if ($this->runOnce) {
                    echo "[Worker] Hàng đợi rỗng. Kết thúc lượt chạy đơn lẻ (--once).\n";
                    break;
                }
                sleep($this->sleepSeconds);
            }
        } while (!$this->runOnce);
    }

    /**
     * Lấy và xử lý một lượt chấm đang xếp hàng (Queued)
     */
    public function processNextQueuedRun(): bool
    {
        // 1. Khóa nguyên tử (Atomic lock): Chọn bản ghi Queued có ID nhỏ nhất
        $this->pdo->beginTransaction();

        try {
            $stmt = $this->pdo->prepare("
                SELECT jr.id, jr.submission_id, jr.problem_version_id, jr.run_number, jr.triggered_by
                FROM judging_runs jr
                WHERE jr.status = 'Queued'
                ORDER BY jr.id ASC
                LIMIT 1
                FOR UPDATE
            ");
            $stmt->execute();
            $run = $stmt->fetch();

            if (!$run) {
                $this->pdo->commit();
                return false;
            }

            $runId = (int)$run['id'];
            $submissionId = (int)$run['submission_id'];
            $versionId = (int)$run['problem_version_id'];

            // Cập nhật trạng thái lượt chấm thành Running
            $lockStmt = $this->pdo->prepare("
                UPDATE judging_runs
                SET status = 'Running',
                    worker_id = :worker_id,
                    started_at = NOW()
                WHERE id = :run_id
            ");
            $lockStmt->execute([
                'worker_id' => $this->workerId,
                'run_id'    => $runId
            ]);

            // Cập nhật trạng thái bài nộp thành Running
            $subUpdateStmt = $this->pdo->prepare("
                UPDATE submissions
                SET final_verdict = 'Running'
                WHERE id = :sub_id AND final_verdict = 'Pending'
            ");
            $subUpdateStmt->execute(['sub_id' => $submissionId]);

            $this->pdo->commit();
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            error_log("Worker lock error: " . $e->getMessage());
            return false;
        }

        echo sprintf(
            "[%s] Bắt đầu chấm Run #%d (Bài nộp #%d, Phiên bản đề #%d, Lần #%d)\n",
            date('Y-m-d H:i:s'),
            $runId,
            $submissionId,
            $versionId,
            $run['run_number']
        );

        // 2. Lấy thông tin bài nộp và phiên bản đề
        $subStmt = $this->pdo->prepare("
            SELECT s.language, s.source_code, pv.time_limit_ms, pv.memory_limit_kb
            FROM submissions s
            JOIN problem_versions pv ON pv.id = :version_id
            WHERE s.id = :sub_id
        ");
        $subStmt->execute([
            'version_id' => $versionId,
            'sub_id'     => $submissionId
        ]);
        $subData = $subStmt->fetch();

        if (!$subData) {
            $this->markRunSystemError($runId, $submissionId, "Không tìm thấy dữ liệu bài nộp #{$submissionId} hoặc phiên bản #{$versionId}");
            return true;
        }

        $language       = $subData['language'];
        $sourceCode     = $subData['source_code'];
        $timeLimitMs    = (int)$subData['time_limit_ms'];
        $memoryLimitKb  = (int)$subData['memory_limit_kb'];

        // Lấy danh sách testcase
        $tcStmt = $this->pdo->prepare("
            SELECT id, case_order, input_data, expected_output, is_sample, is_hidden, points
            FROM test_cases
            WHERE problem_version_id = :version_id
            ORDER BY case_order ASC
        ");
        $tcStmt->execute(['version_id' => $versionId]);
        $testCases = $tcStmt->fetchAll();

        if (empty($testCases)) {
            $this->markRunSystemError($runId, $submissionId, "Phiên bản đề #{$versionId} chưa có bất kỳ ca kiểm thử (test_cases) nào.");
            return true;
        }

        // 3. Chuẩn bị thư mục cách ly & Biên dịch
        $runDir = $this->sandbox->prepareRunDir("run_{$runId}");

        $compileRes = $this->sandbox->compile($language, $sourceCode, $runDir);

        if (!$compileRes['success']) {
            $verdict = $compileRes['verdict'];
            if ($verdict === 'SE') {
                $this->markRunSystemError($runId, $submissionId, $compileRes['message']);
            } else {
                // Lỗi biên dịch (CE)
                $this->recordCompileError($runId, $submissionId, $versionId, $testCases, $compileRes['message']);
            }
            $this->sandbox->cleanupRunDir($runDir);
            return true;
        }

        $executable = $compileRes['executable'];

        // 4. Chạy từng ca kiểm thử (Test Case)
        $totalCases   = count($testCases);
        $passedCases  = 0;
        $maxTimeMs    = 0;
        $maxMemoryKb  = 0;
        $finalVerdict = 'AC';
        $caseResults  = [];

        foreach ($testCases as $tc) {
            $testId = (int)$tc['id'];
            $caseOrder = (int)$tc['case_order'];
            $isHidden = (bool)$tc['is_hidden'];
            $isSample = (bool)$tc['is_sample'];

            $runRes = $this->sandbox->runTestCase(
                $language,
                $executable,
                $tc['input_data'],
                $timeLimitMs,
                $memoryLimitKb
            );

            $maxTimeMs = max($maxTimeMs, $runRes['execution_time_ms']);
            $maxMemoryKb = max($maxMemoryKb, $runRes['memory_used_kb']);

            $caseVerdict = $runRes['verdict'];

            // Nếu thực thi không crash / timeout, so sánh output với expected_output
            if ($caseVerdict === 'AC') {
                $comp = Comparator::compare($runRes['actual_output'], $tc['expected_output']);
                if ($comp['match']) {
                    $caseVerdict = 'AC';
                    $passedCases++;
                } else {
                    $caseVerdict = 'WA';
                }
            }

            // Ghi nhận phán quyết đầu tiên không phải AC làm phán quyết chung
            if ($caseVerdict !== 'AC' && $finalVerdict === 'AC') {
                $finalVerdict = $caseVerdict;
            }

            // Bảo mật: Nếu là test kín (is_hidden = 1), KHÔNG lưu actual_output vào CSDL
            $actualOutputToStore = ($isHidden && !$isSample) ? null : $runRes['actual_output'];

            $caseResults[] = [
                'judging_run_id'    => $runId,
                'test_case_id'      => $testId,
                'verdict'           => $caseVerdict,
                'execution_time_ms' => $runRes['execution_time_ms'],
                'memory_used_kb'    => $runRes['memory_used_kb'],
                'actual_output'     => $actualOutputToStore,
                'error_message'     => $runRes['error_message'] ?: null
            ];

            echo "   [Case #{$caseOrder}] Verdict: {$caseVerdict} ({$runRes['execution_time_ms']}ms)\n";
        }

        // Dọn dẹp thư mục cách ly
        $this->sandbox->cleanupRunDir($runDir);

        // 5. Lưu chi tiết từng case vào case_results
        $crInsertStmt = $this->pdo->prepare("
            INSERT INTO case_results 
            (judging_run_id, test_case_id, verdict, execution_time_ms, memory_used_kb, actual_output, error_message)
            VALUES (:run_id, :tc_id, :verdict, :exec_time, :mem_kb, :act_out, :err_msg)
        ");
        foreach ($caseResults as $cr) {
            $crInsertStmt->execute([
                'run_id'    => $cr['judging_run_id'],
                'tc_id'     => $cr['test_case_id'],
                'verdict'   => $cr['verdict'],
                'exec_time' => $cr['execution_time_ms'],
                'mem_kb'    => $cr['memory_used_kb'],
                'act_out'   => $cr['actual_output'],
                'err_msg'   => $cr['error_message']
            ]);
        }

        // 6. Cập nhật judging_runs
        $runUpdateStmt = $this->pdo->prepare("
            UPDATE judging_runs
            SET status = 'Completed',
                verdict = :verdict,
                passed_cases = :passed,
                total_cases = :total,
                max_time_ms = :max_time,
                max_memory_kb = :max_mem,
                finished_at = NOW()
            WHERE id = :run_id
        ");
        $runUpdateStmt->execute([
            'verdict'  => $finalVerdict,
            'passed'   => $passedCases,
            'total'    => $totalCases,
            'max_time' => $maxTimeMs,
            'max_mem'  => $maxMemoryKb,
            'run_id'   => $runId
        ]);

        // 7. CƠ CHẾ CHỐNG GHI ĐÈ KẾT QUẢ CŨ VỀ MUỘN (STALE RUN PROTECTION)
        $this->applyStaleRunProtection(
            $runId,
            $submissionId,
            $finalVerdict,
            $passedCases,
            $totalCases,
            $maxTimeMs,
            $maxMemoryKb
        );

        echo sprintf(
            "[%s] Hoàn thành Run #%d: Verdict = %s (%d/%d tests, %dms)\n\n",
            date('Y-m-d H:i:s'),
            $runId,
            $finalVerdict,
            $passedCases,
            $totalCases,
            $maxTimeMs
        );

        return true;
    }

    /**
     * Cơ chế chống ghi đè kết quả cũ về muộn (Nguyên tắc 3 & Phép thử 4)
     */
    private function applyStaleRunProtection(
        int $runId,
        int $submissionId,
        string $finalVerdict,
        int $passedCases,
        int $totalCases,
        int $maxTimeMs,
        int $maxMemoryKb
    ): void {
        $this->pdo->beginTransaction();
        try {
            $subStmt = $this->pdo->prepare("
                SELECT latest_run_id, final_verdict 
                FROM submissions 
                WHERE id = :id 
                FOR UPDATE
            ");
            $subStmt->execute(['id' => $submissionId]);
            $sub = $subStmt->fetch();

            if (!$sub) {
                $this->pdo->commit();
                return;
            }

            $currentLatestRunId = $sub['latest_run_id'] !== null ? (int)$sub['latest_run_id'] : null;

            // Nếu đây là lần chấm đầu tiên hoặc lần chấm này mới hơn lần chấm đã ghi nhận
            if ($currentLatestRunId === null || $runId >= $currentLatestRunId) {
                $updateSubStmt = $this->pdo->prepare("
                    UPDATE submissions
                    SET latest_run_id = :run_id,
                        final_verdict = :verdict,
                        pass_count = :pass_count,
                        total_cases = :total_cases,
                        execution_time_ms = :exec_time,
                        memory_used_kb = :mem_kb
                    WHERE id = :sub_id
                ");
                $updateSubStmt->execute([
                    'run_id'      => $runId,
                    'verdict'     => $finalVerdict,
                    'pass_count'  => $passedCases,
                    'total_cases' => $totalCases,
                    'exec_time'   => $maxTimeMs,
                    'mem_kb'      => $maxMemoryKb,
                    'sub_id'      => $submissionId
                ]);
            } else {
                // ĐÂY LÀ LẦN CHẤM CŨ VỀ MUỘN (STALE RUN)
                // Đánh dấu lần chấm này là Superseded và TUYỆT ĐỐI KHÔNG GHI ĐÈ bảng submissions
                $markSupersededStmt = $this->pdo->prepare("
                    UPDATE judging_runs
                    SET status = 'Superseded'
                    WHERE id = :run_id
                ");
                $markSupersededStmt->execute(['run_id' => $runId]);

                echo sprintf(
                    "   [STALE RUN PROTECTION] Lần chấm Run #%d trả về muộn hơn Run #%d hiện hành. Giữ nguyên trạng thái bài nộp #%d!\n",
                    $runId,
                    $currentLatestRunId,
                    $submissionId
                );
            }

            $this->pdo->commit();
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            error_log("Stale Run Protection error: " . $e->getMessage());
        }
    }

    /**
     * Xử lý trường hợp bài nộp bị lỗi biên dịch / cú pháp (CE)
     */
    private function recordCompileError(
        int $runId,
        int $submissionId,
        int $versionId,
        array $testCases,
        string $compilerMessage
    ): void {
        // Cập nhật judging_runs
        $stmt = $this->pdo->prepare("
            UPDATE judging_runs
            SET status = 'Completed',
                verdict = 'CE',
                passed_cases = 0,
                total_cases = :total,
                compiler_message = :msg,
                finished_at = NOW()
            WHERE id = :run_id
        ");
        $stmt->execute([
            'total'  => count($testCases),
            'msg'    => $compilerMessage,
            'run_id' => $runId
        ]);

        // Tạo case_results cho tất cả testcases với CE
        $crStmt = $this->pdo->prepare("
            INSERT INTO case_results (judging_run_id, test_case_id, verdict, error_message)
            VALUES (:run_id, :tc_id, 'CE', :msg)
        ");
        foreach ($testCases as $tc) {
            $crStmt->execute([
                'run_id' => $runId,
                'tc_id'  => $tc['id'],
                'msg'    => 'Biên dịch không thành công.'
            ]);
        }

        $this->applyStaleRunProtection($runId, $submissionId, 'CE', 0, count($testCases), 0, 0);
        echo "   [Result] Lỗi biên dịch / cú pháp (CE).\n";
    }

    /**
     * Đánh dấu lỗi hệ thống thực thi (SE)
     */
    private function markRunSystemError(int $runId, int $submissionId, string $systemError): void
    {
        $stmt = $this->pdo->prepare("
            UPDATE judging_runs
            SET status = 'SystemError',
                verdict = 'SE',
                system_error_log = :err,
                finished_at = NOW()
            WHERE id = :run_id
        ");
        $stmt->execute([
            'err'    => $systemError,
            'run_id' => $runId
        ]);

        $subStmt = $this->pdo->prepare("
            UPDATE submissions
            SET final_verdict = 'SE'
            WHERE id = :sub_id AND (latest_run_id IS NULL OR latest_run_id = :run_id)
        ");
        $subStmt->execute([
            'sub_id' => $submissionId,
            'run_id' => $runId
        ]);

        echo "   [LỖI HỆ THỐNG - SE]: {$systemError}\n";
    }
}

// Nếu chạy trực tiếp từ CLI
if (php_sapi_name() === 'cli' && basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    $options = getopt('', ['once', 'sleep::']);
    $runOnce = isset($options['once']);
    $sleepSeconds = isset($options['sleep']) ? (int)$options['sleep'] : 1;

    $worker = new JudgingWorker($runOnce, $sleepSeconds);
    $worker->start();
}
