<?php
/**
 * Nhom1 Online Judge - Tiến trình Worker Điều phối Chấm bài Độc lập (`core/worker.php`)
 *
 * Cách chạy qua dòng lệnh (CLI):
 *   - Chạy nền liên tục (daemon): php core/worker.php --sleep=1
 *   - Chạy một lượt duy nhất    : php core/worker.php --once
 *   - Chấm sạch hàng đợi rồi tắt: php core/worker.php --drain (được WorkerLauncher tự động gọi)
 *
 * Kiến trúc tuân thủ tuyệt đối 4 nguyên tắc cốt lõi của hệ thống:
 * - Nguyên tắc 1: Tách hoàn toàn khỏi tiến trình Web (Apache/HTTP); chấm bài bất đồng bộ qua hàng đợi CSDL.
 * - Nguyên tắc 3: Chống ghi đè kết quả cũ về muộn (Stale Run Protection) khi có chấm lại (rejudge) song song.
 * - Nguyên tắc 4: Bảo mật dữ liệu chấm kín (tuyệt đối không lưu `actual_output` của test kín vào CSDL).
 * - Nguyên tắc 5: Phân biệt rạch ròi lỗi bài làm của thí sinh (AC/WA/TLE/RE/CE) với lỗi hạ tầng hệ thống (SE).
 */

// Bật kiểm tra kiểu dữ liệu nghiêm ngặt
declare(strict_types=1);

// Nạp các thành phần phụ thuộc: kết nối CSDL, môi trường cách ly, bộ so sánh, bảo mật và bộ quản lý khóa Worker
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/SandboxRunner.php';
require_once __DIR__ . '/Comparator.php';
require_once __DIR__ . '/Security.php';
require_once __DIR__ . '/WorkerLauncher.php';

/**
 * Lớp JudgingWorker (Tiến trình Worker chấm bài)
 *
 * Chịu trách nhiệm lắng nghe bảng `judging_runs`, lấy từng lượt chấm đang ở trạng thái 'Queued',
 * biên dịch và chạy lần lượt các ca kiểm thử trong `SandboxRunner`, so khớp kết quả bằng `Comparator`,
 * lưu kết quả chi tiết vào `case_results` và cập nhật an toàn trạng thái cuối cùng vào `submissions`.
 */
class JudgingWorker
{
    /** Kết nối cơ sở dữ liệu MySQL qua PDO. */
    private PDO $pdo;

    /** Đối tượng thực thi mã nguồn cách ly (biên dịch & chạy test case). */
    private SandboxRunner $sandbox;

    /**
     * Mã định danh duy nhất của Worker này (dạng `worker_<PID>_<8 ký tự hex ngẫu nhiên>`),
     * được ghi vào cột `judging_runs.worker_id` để truy vết xem Worker nào đã chấm lượt nào.
     */
    private string $workerId;

    /** Nếu true (--once): chỉ chấm tối đa 1 lượt rồi thoát ngay. */
    private bool $runOnce = false;

    /** Nếu true (--drain): chấm liên tục đến khi hàng đợi trống hoàn toàn thì tự động thoát để giải phóng RAM. */
    private bool $drainMode = false;

    /** Số giây tạm nghỉ giữa mỗi lần kiểm tra hàng đợi khi chạy chế độ lắng nghe liên tục (mặc định 1 giây). */
    private int $sleepSeconds = 1;

    /**
     * Con trỏ tệp (file handle) đang giữ khóa độc quyền trên `temp/worker.lock`.
     * Cần giữ biến này sống suốt vòng đời Worker; nếu biến bị đóng/hủy, khóa tệp sẽ bị nhả.
     *
     * @var resource|null
     */
    private $lockHandle = null;

    /**
     * Khởi tạo một thể hiện JudgingWorker với cấu hình chế độ chạy mong muốn.
     *
     * @param bool $runOnce      true để chạy chế độ một lượt (--once).
     * @param int  $sleepSeconds Thời gian nghỉ (giây) khi hàng đợi rỗng ở chế độ liên tục (tối thiểu 1 giây).
     * @param bool $drainMode    true để chạy chế độ tự thoát sau khi chấm sạch hàng đợi (--drain).
     */
    public function __construct(bool $runOnce = false, int $sleepSeconds = 1, bool $drainMode = false)
    {
        $this->pdo = getDbConnection();
        $this->sandbox = new SandboxRunner();
        // Tạo mã định danh duy nhất kết hợp Process ID của hệ điều hành (getmypid) và 4 byte ngẫu nhiên
        $this->workerId = sprintf('worker_%d_%s', getmypid(), bin2hex(random_bytes(4)));
        $this->runOnce = $runOnce;
        $this->drainMode = $drainMode;
        $this->sleepSeconds = max(1, $sleepSeconds);
    }

    /**
     * Xin và giữ khóa độc quyền trên tệp `temp/worker.lock` bằng `flock` để chống chạy trùng nhiều Worker.
     *
     * Cơ chế chống chạy trùng (Single-Instance Lock):
     * - Mở tệp khóa ở chế độ 'c+' (tạo nếu chưa có, không xóa nội dung cũ).
     * - Gọi `flock($fp, LOCK_EX | LOCK_NB)`: xin khóa độc quyền không chờ.
     *   + Nếu thất bại -> đã có Worker khác đang giữ khóa -> đóng tệp và trả về false để tiến trình này tự rút lui.
     *   + Nếu thành công -> xóa nội dung cũ (`ftruncate`), ghi `workerId` cùng thời gian bắt đầu vào tệp để tiện kiểm tra,
     *     đẩy bộ đệm xuống đĩa (`fflush`) và lưu giữ `$fp` vào `$this->lockHandle`.
     * - Lưu ý: Không gọi `fclose($fp)` ở đây vì đóng tệp sẽ làm hệ điều hành tự nhả khóa ngay!
     *
     * @return bool true nếu chiếm khóa thành công (được phép chạy); false nếu đã có Worker khác đang hoạt động.
     */
    private function acquireWorkerLock(): bool
    {
        $lockPath = WorkerLauncher::getLockFilePath();
        $fp = @fopen($lockPath, 'c+');
        if (!$fp) {
            // Nếu không mở được tệp khóa do lỗi quyền thư mục, vẫn cho phép chạy thay vì làm tê liệt chấm bài
            return true;
        }
        // Thử xin khóa độc quyền ở chế độ không chặn (non-blocking)
        if (!flock($fp, LOCK_EX | LOCK_NB)) {
            fclose($fp);
            return false;
        }
        // Ghi thông tin nhận diện Worker đang giữ khóa vào tệp worker.lock để phục vụ theo dõi/gỡ lỗi
        ftruncate($fp, 0);
        fwrite($fp, $this->workerId . ' | ' . date('Y-m-d H:i:s') . "\n");
        fflush($fp);
        $this->lockHandle = $fp;
        return true;
    }

    /**
     * Giải phóng khóa tệp `worker.lock` và đóng file handle khi Worker dừng lại.
     */
    private function releaseWorkerLock(): void
    {
        if (is_resource($this->lockHandle)) {
            @flock($this->lockHandle, LOCK_UN);
            @fclose($this->lockHandle);
            $this->lockHandle = null;
        }
    }

    /**
     * Điểm bắt đầu vòng lặp chính của Worker.
     *
     * Luồng hoạt động:
     * 1. Xin khóa chống trùng (trừ chế độ `--once` vốn dùng để kiểm thử thủ công).
     * 2. In bảng thông tin khởi động (Worker ID, phiên bản PHP, đường dẫn Python/G++, chế độ chạy).
     * 3. Lặp gọi `processNextQueuedRun()`:
     *    - Nếu vừa chấm xong 1 bài -> đặt lại bộ đếm rỗng `$emptyChecks = 0` và kiểm tra ngay bài tiếp theo không cần nghỉ.
     *    - Nếu hàng đợi rỗng:
     *      + Chế độ `--once` : thoát ngay.
     *      + Chế độ `--drain`: chờ thêm 1 giây rồi kiểm tra lại thêm 1 lần nữa (`$emptyChecks >= 2`)
     *        đề phòng có bài nộp mới vừa được ghi vào CSDL đúng tích tắc hàng đợi vừa cạn, sau đó tự thoát.
     *      + Chế độ thường   : ngủ `$this->sleepSeconds` giây rồi tiếp tục quét.
     * 4. Khối `finally` đảm bảo LUÔN giải phóng khóa tệp `worker.lock` ngay cả khi xảy ra ngoại lệ.
     */
    public function start(): void
    {
        // Giữ khóa độc quyền ở chế độ liên tục và chế độ --drain để chống mở trùng Worker
        if (!$this->runOnce) {
            if (!$this->acquireWorkerLock()) {
                // Nếu là chế độ chạy tay thì in cảnh báo; còn chế độ --drain tự kích hoạt thì thoát êm
                if (!$this->drainMode) {
                    echo "[Worker] Đã có một tiến trình Worker khác đang chạy. Hủy khởi chạy trùng.\n";
                }
                return;
            }
        }

        try {
            $modeLabel = $this->runOnce
                ? 'Chạy một lượt (--once)'
                : ($this->drainMode ? 'Tự động chấm sạch hàng đợi rồi thoát (--drain)' : "Lắng nghe liên tục (Chu kỳ {$this->sleepSeconds}s)");

            echo "====================================================================\n";
            echo "   NHOM1 ONLINE JUDGE - WORKER CHẤM BÀI NỀN ĐỘC LẬP                 \n";
            echo "====================================================================\n";
            echo "Worker ID   : {$this->workerId}\n";
            echo "Môi trường  : CLI độc lập (PHP " . PHP_VERSION . ")\n";
            echo "Trình biên dịch Python: " . ($this->sandbox->getPythonPath() ?: '[Chưa tìm thấy]') . "\n";
            echo "Trình biên dịch G++   : " . ($this->sandbox->getGppPath() ?: '[Chưa tìm thấy]') . "\n";
            echo "Chế độ chạy : {$modeLabel}\n";
            echo "====================================================================\n";
            echo "Đang sẵn sàng nhận việc từ hàng đợi...\n\n";

            // Đếm số lần liên tiếp thấy hàng đợi rỗng (dùng riêng cho cơ chế kiểm tra kép của --drain)
            $emptyChecks = 0;
            do {
                $processed = $this->processNextQueuedRun();

                // Nếu vừa xử lý được 1 lượt chấm -> lập tức quay đầu kiểm tra tiếp xem còn bài đang chờ không
                if ($processed) {
                    $emptyChecks = 0;
                    continue;
                }

                // Chế độ --once: hàng đợi rỗng -> kết thúc ngay
                if ($this->runOnce) {
                    echo "[Worker] Hàng đợi rỗng. Kết thúc lượt chạy đơn lẻ (--once).\n";
                    break;
                }

                // Chế độ --drain: kiểm tra lại thêm 1 nhịp sau 1 giây phòng bài nộp mới vừa vào đúng lúc chuyển giao
                if ($this->drainMode) {
                    $emptyChecks++;
                    // Kiểm tra lại thêm 1 nhịp sau 1 giây phòng bài nộp mới vừa vào
                    if ($emptyChecks >= 2) {
                        echo "[Worker] Đã chấm sạch hàng đợi. Tự động dừng (--drain).\n";
                        break;
                    }
                    sleep(1);
                    continue;
                }

                // Chế độ lắng nghe liên tục: tạm nghỉ vài giây để tránh gửi truy vấn SQL liên tục làm nóng CPU
                sleep($this->sleepSeconds);
            } while (!$this->runOnce);
        } finally {
            // Đảm bảo khóa tệp luôn được nhả sạch sẽ khi vòng lặp kết thúc hoặc có lỗi bất ngờ
            $this->releaseWorkerLock();
        }
    }

    /**
     * Lấy và xử lý MỘT lượt chấm đang xếp hàng (status = 'Queued') theo thứ tự FIFO (vào trước chấm trước).
     *
     * Quy trình chấm bài đầy đủ gồm 7 bước chặt chẽ:
     * - Bước 1: Khóa nguyên tử (Transaction + `SELECT ... FOR UPDATE`) lấy 1 lượt chấm `Queued` và chuyển sang `Running`.
     * - Bước 2: Nạp mã nguồn bài nộp, giới hạn thời gian/bộ nhớ của ĐÚNG phiên bản đề (`problem_version_id`) và danh sách `test_cases`.
     * - Bước 3: Tạo thư mục sandbox cách ly và biên dịch / kiểm tra cú pháp (phân tách `SE` và `CE`).
     * - Bước 4: Lần lượt thực thi từng test case, so sánh kết quả bằng `Comparator::compare()`,
     *           tổng hợp verdict chung và bảo vệ tuyệt đối đầu ra của test kín (không lưu vào CSDL).
     * - Bước 5: Lưu kết quả từng test case vào bảng `case_results`.
     * - Bước 6: Cập nhật tổng kết lượt chấm vào bảng `judging_runs` (`Completed`).
     * - Bước 7: Áp dụng cơ chế Stale Run Protection cập nhật an toàn bảng `submissions`.
     *
     * @return bool true nếu đã nhận và xử lý 1 lượt chấm; false nếu hàng đợi hiện đang rỗng hoặc lỗi lấy khóa.
     */
    public function processNextQueuedRun(): bool
    {
        // ==================== BƯỚC 1: KHÓA NGUYÊN TỬ LẤY VIỆC TỪ HÀNG ĐỢI ====================
        // Bắt đầu Transaction kết hợp khóa dòng `FOR UPDATE` (Pessimistic Locking của MySQL InnoDB):
        // Nếu giả sử có nhiều Worker cùng hỏi bảng `judging_runs` một lúc, chỉ duy nhất 1 Worker
        // khóa được bản ghi `Queued` có ID nhỏ nhất (FIFO - First In First Out), ngăn chặn 2 Worker chấm trùng 1 bài.
        $this->pdo->beginTransaction();

        try {
            // SQL: Chọn 1 lượt chấm cũ nhất (ORDER BY id ASC) đang ở trạng thái 'Queued' và khóa độc quyền dòng đó
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

            // Nếu không còn bản ghi 'Queued' nào -> kết thúc transaction và trả về false (hàng đợi rỗng)
            if (!$run) {
                $this->pdo->commit();
                return false;
            }

            $runId = (int)$run['id'];
            $submissionId = (int)$run['submission_id'];
            $versionId = (int)$run['problem_version_id'];

            // SQL: Đánh dấu lượt chấm này đã được Worker hiện tại tiếp nhận ('Running') và ghi thời điểm bắt đầu
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

            // SQL: Nếu bài nộp gốc đang là 'Pending' (chấm lần đầu), đổi sang 'Running' để giao diện hiện trạng thái đang chấm.
            // Lưu ý: Điều kiện `AND final_verdict = 'Pending'` giúp KHÔNG ghi đè kết quả cũ đang hiển thị nếu đây là lần chấm lại (rejudge).
            $subUpdateStmt = $this->pdo->prepare("
                UPDATE submissions
                SET final_verdict = 'Running'
                WHERE id = :sub_id AND final_verdict = 'Pending'
            ");
            $subUpdateStmt->execute(['sub_id' => $submissionId]);

            // Xác nhận hoàn tất giao dịch nhận việc và nhả khóa dòng FOR UPDATE
            $this->pdo->commit();
        } catch (Throwable $e) {
            // Nếu có lỗi DB giữa chừng, hoàn tác toàn bộ giao dịch để bản ghi không bị kẹt trạng thái
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

        // ==================== BƯỚC 2: TẢI DỮ LIỆU BÀI NỘP & BỘ TEST CASE ====================
        // SQL: JOIN bảng `submissions` với đúng phiên bản đề `problem_versions` của lượt chấm này
        // (rất quan trọng khi chấm lại trên phiên bản đề mới có giới hạn thời gian / bộ nhớ khác phiên bản cũ)
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

        // Nếu dữ liệu bài nộp hoặc phiên bản đề bị thiếu -> đánh dấu lỗi hệ thống (SE)
        if (!$subData) {
            $this->markRunSystemError($runId, $submissionId, "Không tìm thấy dữ liệu bài nộp #{$submissionId} hoặc phiên bản #{$versionId}");
            return true;
        }

        $language       = $subData['language'];
        $sourceCode     = $subData['source_code'];
        $timeLimitMs    = (int)$subData['time_limit_ms'];
        $memoryLimitKb  = (int)$subData['memory_limit_kb'];

        // SQL: Lấy toàn bộ các ca kiểm thử (bao gồm cả test mẫu và test kín) của phiên bản đề, sắp xếp theo thứ tự tăng dần
        $tcStmt = $this->pdo->prepare("
            SELECT id, case_order, input_data, expected_output, is_sample, is_hidden, points
            FROM test_cases
            WHERE problem_version_id = :version_id
            ORDER BY case_order ASC
        ");
        $tcStmt->execute(['version_id' => $versionId]);
        $testCases = $tcStmt->fetchAll();

        // Một đề bài hợp lệ bắt buộc phải có ít nhất 1 test case; nếu không có thì đây là lỗi cấu hình/hệ thống (SE)
        if (empty($testCases)) {
            $this->markRunSystemError($runId, $submissionId, "Phiên bản đề #{$versionId} chưa có bất kỳ ca kiểm thử (test_cases) nào.");
            return true;
        }

        // ==================== BƯỚC 3: CHUẨN BỊ SANDBOX & BIÊN DỊCH ====================
        // Tạo thư mục cách ly riêng biệt `temp/runs/run_<runId>` cho lượt chấm này
        $runDir = $this->sandbox->prepareRunDir("run_{$runId}");

        // Biên dịch mã nguồn (C++) hoặc kiểm tra cú pháp (Python)
        $compileRes = $this->sandbox->compile($language, $sourceCode, $runDir);

        if (!$compileRes['success']) {
            $verdict = $compileRes['verdict'];
            if ($verdict === 'SE') {
                // Lỗi hệ thống (ví dụ chưa cài g++/python hoặc không ghi được file)
                $this->markRunSystemError($runId, $submissionId, $compileRes['message']);
            } else {
                // Lỗi biên dịch / lỗi cú pháp của bài làm thí sinh (CE)
                $this->recordCompileError($runId, $submissionId, $versionId, $testCases, $compileRes['message']);
            }
            // Dọn dẹp thư mục tạm trước khi kết thúc lượt chấm
            $this->sandbox->cleanupRunDir($runDir);
            return true;
        }

        // Đường dẫn tệp đã sẵn sàng thực thi (solution.exe với C++ hoặc solution.py với Python)
        $executable = $compileRes['executable'];

        // ==================== BƯỚC 4: CHẠY LẦN LƯỢT TỪNG CA KIỂM THỬ ====================
        $totalCases   = count($testCases);
        $passedCases  = 0;     // Số test case đạt AC
        $maxTimeMs    = 0;     // Thời gian chạy lâu nhất trong tất cả các test case
        $maxMemoryKb  = 0;     // Bộ nhớ tiêu thụ cao nhất trong tất cả các test case
        $finalVerdict = 'AC';  // Mặc định giả sử AC; sẽ bị ghi đè bởi lỗi đầu tiên gặp phải (nếu có)
        $caseResults  = [];    // Mảng gom kết quả chi tiết từng test case để ghi vào CSDL

        foreach ($testCases as $tc) {
            $testId = (int)$tc['id'];
            $caseOrder = (int)$tc['case_order'];
            $isHidden = (bool)$tc['is_hidden'];
            $isSample = (bool)$tc['is_sample'];

            // Chạy chương trình trong SandboxRunner với đầu vào của test case hiện tại
            $runRes = $this->sandbox->runTestCase(
                $language,
                $executable,
                $tc['input_data'],
                $timeLimitMs,
                $memoryLimitKb
            );

            // Cập nhật thống kê thời gian chạy và bộ nhớ lớn nhất trên toàn bộ bài nộp
            $maxTimeMs = max($maxTimeMs, $runRes['execution_time_ms']);
            $maxMemoryKb = max($maxMemoryKb, $runRes['memory_used_kb']);

            $caseVerdict = $runRes['verdict'];

            // Nếu tiến trình chạy êm đẹp (không RE, không TLE, không SE), tiến hành so sánh đầu ra với đáp án chuẩn
            if ($caseVerdict === 'AC') {
                $comp = Comparator::compare($runRes['actual_output'], $tc['expected_output']);
                if ($comp['match']) {
                    $caseVerdict = 'AC';
                    $passedCases++;
                } else {
                    // Đầu ra sai khác so với đáp án kỳ vọng -> Wrong Answer (WA)
                    $caseVerdict = 'WA';
                }
            }

            // Quy tắc tổng hợp phán quyết chung (Verdict Aggregation):
            // Ghi nhận phán quyết lỗi ĐẦU TIÊN không phải AC làm phán quyết chung của cả lượt chấm
            // (ví dụ: test 1 AC, test 2 WA, test 3 TLE -> phán quyết chung là WA).
            if ($caseVerdict !== 'AC' && $finalVerdict === 'AC') {
                $finalVerdict = $caseVerdict;
            }

            // BẢO MẬT TEST KÍN (Nguyên tắc 4 - Ẩn dữ liệu test kín ngay từ tầng lưu trữ):
            // Nếu là test kín (is_hidden = 1 và không phải test ví dụ mẫu), TUYỆT ĐỐI lưu NULL
            // thay vì lưu actual_output vào CSDL để chống thí sinh lợi dụng in dữ liệu kín ra stdout rồi tìm cách đọc lại.
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

        // Xóa sạch thư mục cách ly ngay sau khi hoàn thành tất cả các test case
        $this->sandbox->cleanupRunDir($runDir);

        // ==================== BƯỚC 5: LƯU KẾT QUẢ CHI TIẾT VÀO `case_results` ====================
        // SQL: Chèn từng bản ghi kết quả chi tiết (verdict, thời gian, bộ nhớ, output, lỗi) của từng test case
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

        // ==================== BƯỚC 6: CẬP NHẬT TỔNG KẾT LƯỢT CHẤM `judging_runs` ====================
        // SQL: Chuyển trạng thái lượt chấm sang 'Completed' kèm kết quả tổng hợp và thời điểm kết thúc
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

        // ==================== BƯỚC 7: BẢO VỆ CHỐNG GHI ĐÈ KẾT QUẢ CŨ VỀ MUỘN ====================
        // Chỉ cập nhật bảng `submissions` nếu lượt chấm hiện tại mới hơn hoặc bằng lượt chấm mới nhất đã ghi nhận
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
     * Cơ chế Chống ghi đè kết quả cũ về muộn - Stale Run Protection (Nguyên tắc 3 & Phép thử 4).
     *
     * Bài toán thực tế cần giải quyết:
     * - Giả sử bài nộp #100 được chấm lần 1 (Run #50) trên phiên bản đề v1, nhưng Run #50 chạy rất lâu.
     * - Trong lúc đó, Giảng viên sửa đề lên v2 và kích hoạt Chấm lại (Rejudge), tạo ra Run #51.
     * - Giả sử Run #51 hoàn thành TRƯỚC và đã cập nhật `submissions.latest_run_id = 51` với kết quả AC.
     * - Vài giây sau, Run #50 (cũ hơn) mới hoàn thành với kết quả WA!
     * - Nếu không có cơ chế bảo vệ, Run #50 sẽ ghi đè kết quả WA cũ lên kết quả AC mới của Run #51!
     *
     * Cách thuật toán này bảo vệ tuyệt đối dữ liệu:
     * 1. Mở Transaction và khóa độc quyền bản ghi bài nộp bằng `SELECT ... FROM submissions WHERE id = :id FOR UPDATE`.
     * 2. So sánh `$runId` hiện tại với `$currentLatestRunId` đang lưu trong `submissions`:
     *    - Nếu chưa có lượt chấm nào (`$currentLatestRunId === null`) HOẶC `$runId >= $currentLatestRunId`:
     *      => Đây là kết quả mới hợp lệ -> Cập nhật `latest_run_id`, `final_verdict`, số test đạt, thời gian, bộ nhớ.
     *    - Ngược lại (`$runId < $currentLatestRunId`):
     *      => ĐÂY LÀ LẦN CHẤM CŨ VỀ MUỘN (STALE RUN)!
     *      => Đánh dấu bản ghi lượt chấm cũ thành `status = 'Superseded'` (đã bị thay thế bởi lượt mới hơn)
     *         và TUYỆT ĐỐI KHÔNG động vào bảng `submissions`.
     *
     * @param int    $runId        ID lượt chấm vừa hoàn thành.
     * @param int    $submissionId ID bài nộp tương ứng.
     * @param string $finalVerdict Phán quyết chung của lượt chấm (AC, WA, TLE, RE, CE).
     * @param int    $passedCases  Số test case đã vượt qua.
     * @param int    $totalCases   Tổng số test case của phiên bản đề.
     * @param int    $maxTimeMs    Thời gian chạy lớn nhất (ms).
     * @param int    $maxMemoryKb  Bộ nhớ sử dụng lớn nhất (KB).
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
        // Bọc trong Transaction + khóa dòng FOR UPDATE để tránh tranh chấp (race condition) khi 2 run cùng kết thúc 1 lúc
        $this->pdo->beginTransaction();
        try {
            // SQL: Khóa dòng bài nộp trong bảng `submissions` và đọc `latest_run_id` hiện hành
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

            // Trường hợp 1: Chấm lần đầu tiên (null) HOẶC lượt chấm này có ID lớn hơn/bằng lượt chấm mới nhất
            if ($currentLatestRunId === null || $runId >= $currentLatestRunId) {
                // SQL: Cập nhật kết quả chính thức mới nhất của bài nộp
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
                // Trường hợp 2: ĐÂY LÀ LẦN CHẤM CŨ VỀ MUỘN (STALE RUN: $runId < $currentLatestRunId)
                // Đánh dấu lần chấm này là 'Superseded' (đã bị thay thế) và TUYỆT ĐỐI KHÔNG GHI ĐÈ bảng submissions
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
     * Xử lý và lưu trữ kết quả khi bài nộp bị lỗi biên dịch hoặc lỗi cú pháp (CE - Compile Error).
     *
     * Quy trình:
     * 1. Cập nhật bảng `judging_runs` thành `Completed`, `verdict = 'CE'`, lưu chi tiết `compiler_message`.
     * 2. Chèn các bản ghi `case_results` mang trạng thái `CE` cho tất cả các test case để giao diện hiển thị đồng nhất.
     * 3. Gọi `applyStaleRunProtection()` để cập nhật bảng `submissions` một cách an toàn.
     *
     * @param int    $runId           ID lượt chấm.
     * @param int    $submissionId    ID bài nộp.
     * @param int    $versionId       ID phiên bản đề bài.
     * @param array  $testCases       Danh sách các test case của phiên bản đề.
     * @param string $compilerMessage Thông báo lỗi biên dịch đã được lọc sạch đường dẫn máy chủ.
     */
    private function recordCompileError(
        int $runId,
        int $submissionId,
        int $versionId,
        array $testCases,
        string $compilerMessage
    ): void {
        // SQL: Cập nhật tổng kết lượt chấm thành CE (0 test đạt) kèm chi tiết lỗi biên dịch
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

        // SQL: Tạo bản ghi case_results mang verdict 'CE' cho toàn bộ test cases của phiên bản đề
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

        // Cập nhật bài nộp thông qua cơ chế Stale Run Protection để không ghi đè lượt chấm mới hơn
        $this->applyStaleRunProtection($runId, $submissionId, 'CE', 0, count($testCases), 0, 0);
        echo "   [Result] Lỗi biên dịch / cú pháp (CE).\n";
    }

    /**
     * Đánh dấu lỗi hệ thống thực thi (SE - System Error) khi hạ tầng chấm bài gặp sự cố.
     *
     * Tuân thủ Nguyên tắc 5:
     * - Lỗi hệ thống (thiếu trình biên dịch, đề bài chưa có test, không tạo được tiến trình...)
     *   phải được tách biệt hoàn toàn thành `status = 'SystemError'`, `verdict = 'SE'`,
     *   và ghi nhật ký kỹ thuật vào cột `system_error_log` (chỉ Admin mới được xem, bị ẩn qua `Security::sanitizeRunDetails()`).
     * - Khi cập nhật `submissions.final_verdict = 'SE'`, điều kiện `latest_run_id IS NULL OR latest_run_id = :run_id`
     *   cũng đảm bảo một lỗi SE của lượt chấm cũ không ghi đè lên kết quả của lượt chấm mới hơn.
     *
     * @param int    $runId        ID lượt chấm gặp sự cố.
     * @param int    $submissionId ID bài nộp liên quan.
     * @param string $systemError  Thông báo mô tả chi tiết lỗi hệ thống để Admin kiểm tra.
     */
    private function markRunSystemError(int $runId, int $submissionId, string $systemError): void
    {
        // SQL: Ghi nhận trạng thái SystemError và lưu log kỹ thuật nội bộ vào cột system_error_log
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

        // SQL: Cập nhật final_verdict của bài nộp thành 'SE' nếu chưa có lượt chấm mới hơn thay thế
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

// ==================== ĐIỂM KHỞI CHẠY KHI GỌI TRỰC TIẾP TỪ DÒNG LỆNH (CLI) ====================
// Kiểm tra 2 điều kiện:
// 1. `php_sapi_name() === 'cli'`: chỉ chạy khi được thực thi qua trình dòng lệnh PHP CLI (không chạy từ trình duyệt/Apache).
// 2. `basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')`: chỉ tự khởi chạy vòng lặp khi tệp worker.php
//    là tệp chính được gọi trực tiếp; nếu tệp khác dùng `require_once 'core/worker.php'` chỉ để nạp lớp JudgingWorker thì không tự chạy.
if (php_sapi_name() === 'cli' && basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    // Phân tích các cờ dòng lệnh tùy chọn:
    // - `--once`    : chỉ xử lý 1 bài rồi thoát
    // - `--drain`   : chấm sạch toàn bộ hàng đợi rồi tự thoát
    // - `--sleep=N` : dấu `::` nghĩa là giá trị tùy chọn (mặc định 1 giây)
    $options = getopt('', ['once', 'drain', 'sleep::']);
    $runOnce = isset($options['once']);
    $drainMode = isset($options['drain']);
    $sleepSeconds = isset($options['sleep']) ? (int)$options['sleep'] : 1;

    // Khởi tạo đối tượng Worker và bắt đầu vòng lặp tiếp nhận chấm bài
    $worker = new JudgingWorker($runOnce, $sleepSeconds, $drainMode);
    $worker->start();
}
