<?php
/**
 * ByteFlow Online Judge - Kịch bản kiểm thử nghiệm thu Giai đoạn 2
 * Kiểm tra:
 * 1. Comparator (Chuẩn hóa \r\n, trailing spaces, dòng trống)
 * 2. Security (Bảo vệ dữ liệu kín, lọc path nhạy cảm, CSRF, Idempotency)
 * 3. SandboxRunner (Phân loại chính xác AC, WA, CE, RE, TLE)
 * 4. Worker Điều phối Hàng đợi (Queued -> Running -> Completed, ghi nhận case_results)
 * 5. Stale Run Protection (Chống ghi đè kết quả cũ về muộn)
 *
 * Chạy qua CLI: php tests/verify_phase2.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../core/Comparator.php';
require_once __DIR__ . '/../core/Security.php';
require_once __DIR__ . '/../core/SandboxRunner.php';
require_once __DIR__ . '/../core/worker.php';

echo "====================================================================\n";
echo "   BYTEFLOW ONLINE JUDGE - KIỂM THỬ NGHIỆM THU GIAI ĐOẠN 2          \n";
echo "====================================================================\n";

$pdo = getDbConnection();

// ------------------------------------------------------------------
// BƯỚC 1: KIỂM THỬ COMPARATOR
// ------------------------------------------------------------------
echo "\n[BƯỚC 1] Kiểm tra Bộ so sánh đầu ra (Comparator):\n";

// 1.1 Kiểm tra chuẩn hóa xuống dòng và khoảng trắng cuối dòng
$actual1   = "8   \r\n";
$expected1 = "8\n";
$res1 = Comparator::compare($actual1, $expected1);
if (!$res1['match']) {
    echo "  [FAIL] Comparator không khớp khi khác biệt \\r\\n và trailing space!\n";
    exit(1);
}
echo "  [PASS] 1.1. Chuẩn hóa \\r\\n và khoảng trắng cuối dòng thành công.\n";

// 1.2 Kiểm tra nhiều dòng với các dòng trống ở cuối
$actual2   = "Line 1  \nLine 2\t\n\n\n";
$expected2 = "Line 1\r\nLine 2\r\n";
$res2 = Comparator::compare($actual2, $expected2);
if (!$res2['match']) {
    echo "  [FAIL] Comparator không khớp khi có dòng trống ở cuối!\n";
    exit(1);
}
echo "  [PASS] 1.2. Chuẩn hóa nhiều dòng và dòng trống ở cuối thành công.\n";

// 1.3 Kiểm tra phát hiện khác biệt nội dung (Wrong Answer)
$actual3   = "Output A\nDifferent Line\n";
$expected3 = "Output A\nExpected Line\n";
$res3 = Comparator::compare($actual3, $expected3);
if ($res3['match'] || $res3['diff_line'] !== 2) {
    echo "  [FAIL] Comparator không phát hiện đúng dòng sai khác!\n";
    exit(1);
}
echo "  [PASS] 1.3. Bắt chính xác dòng sai khác (Dòng {$res3['diff_line']}).\n";

// ------------------------------------------------------------------
// BƯỚC 2: KIỂM THỬ SECURITY & BẢO VỆ DỮ LIỆU KÍN
// ------------------------------------------------------------------
echo "\n[BƯỚC 2] Kiểm tra Bảo mật & Bảo vệ dữ liệu kín (Security):\n";

// 2.1 Lọc đường dẫn tuyệt đối nhạy cảm
$rawError = "Traceback (most recent call last):\n  File \"E:\\Phat_Trien_Phan_Mem_Ma_Nguon_Mo\\BaiTapLon\\temp\\runs\\run_1\\solution.py\", line 5, in <module>\n    1/0\nZeroDivisionError: division by zero";
$cleanError = Security::sanitizeErrorMessage($rawError);
if (strpos($cleanError, 'E:\\Phat_Trien') !== false) {
    echo "  [FAIL] Lọc đường dẫn nhạy cảm thất bại: {$cleanError}\n";
    exit(1);
}
echo "  [PASS] 2.1. Đã lọc sạch đường dẫn tuyệt đối khỏi thông báo lỗi.\n";

// 2.2 Bảo vệ dữ liệu kín: Ẩn input/output của testcase ẩn đối với learner
$rawCases = [
    [
        'id' => 1, 'case_order' => 1, 'is_sample' => 1, 'is_hidden' => 0,
        'input_data' => '3 5', 'expected_output' => '8', 'actual_output' => '8',
        'verdict' => 'AC', 'execution_time_ms' => 12
    ],
    [
        'id' => 2, 'case_order' => 2, 'is_sample' => 0, 'is_hidden' => 1,
        'input_data' => 'SECRET_INPUT_100', 'expected_output' => 'SECRET_EXP_200', 'actual_output' => 'SECRET_ACT_200',
        'verdict' => 'AC', 'execution_time_ms' => 15
    ]
];

$learnerView = Security::sanitizeCaseResultsForUser($rawCases, false);
if (
    strpos(json_encode($learnerView), 'SECRET_INPUT_100') !== false ||
    strpos(json_encode($learnerView), 'SECRET_EXP_200') !== false ||
    strpos(json_encode($learnerView), 'SECRET_ACT_200') !== false
) {
    echo "  [FAIL] Lộ dữ liệu testcase ẩn cho người học!\n";
    exit(1);
}
if ($learnerView[0]['input_data'] !== '3 5') {
    echo "  [FAIL] Ca ví dụ công khai bị ẩn nhầm!\n";
    exit(1);
}
echo "  [PASS] 2.2. Dữ liệu testcase ẩn được bảo vệ tuyệt đối (người học không thể xem).\n";

// 2.3 Phân quyền xem bài nộp (Phép thử 5 - Rubric)
require_once __DIR__ . '/../core/Auth.php';
// Giả lập user learner1 (ID 3) kiểm tra quyền xem bài của learner2 (ID 4)
Auth::startSession();
$_SESSION['user_id'] = 3;
$_SESSION['role'] = 'learner';
if (Auth::canViewSubmission(4)) {
    echo "  [FAIL] Người học A (ID 3) được phép xem bài nộp của người học B (ID 4)!\n";
    exit(1);
}
echo "  [PASS] 2.3. Kiểm soát quyền riêng tư: Người học A bị từ chối khi xem bài của Người học B.\n";

// ------------------------------------------------------------------
// BƯỚC 3: KIỂM THỬ SANDBOX RUNNER (PHÂN LOẠI 5 KẾT QUẢ: AC, WA, CE, RE, TLE)
// ------------------------------------------------------------------
echo "\n[BƯỚC 3] Kiểm tra Dịch vụ Thực thi Cách ly (SandboxRunner - Phép thử 1):\n";

$sandbox = new SandboxRunner();
$tempDir = $sandbox->prepareRunDir('verify_test_' . time());

// 3.1 Kiểm tra AC (Chạy đúng)
$acCode = file_get_contents(__DIR__ . '/sample_solutions/python/ac_solution.py');
$compRes = $sandbox->compile('python', $acCode, $tempDir);
if (!$compRes['success']) {
    echo "  [FAIL] Không thể compile/check mã AC: {$compRes['message']}\n";
    exit(1);
}
$runAC = $sandbox->runTestCase('python', $compRes['executable'], "10 20\n", 1000);
$compAC = Comparator::compare($runAC['actual_output'], "30\n");
if (!$compAC['match']) {
    echo "  [FAIL] Mã AC không cho kết quả đúng!\n";
    exit(1);
}
echo "  [PASS] 3.1. Phân loại kết quả [AC - Accepted] chính xác.\n";

// 3.2 Kiểm tra WA (Sai kết quả)
$waCode = file_get_contents(__DIR__ . '/sample_solutions/python/wa_solution.py');
$compWa = $sandbox->compile('python', $waCode, $tempDir);
$runWA = $sandbox->runTestCase('python', $compWa['executable'], "10 20\n", 1000);
$compWA = Comparator::compare($runWA['actual_output'], "30\n");
if ($compWA['match']) {
    echo "  [FAIL] Mã WA lại trả về kết quả khớp expected!\n";
    exit(1);
}
echo "  [PASS] 3.2. Phân loại kết quả [WA - Wrong Answer] chính xác.\n";

// 3.3 Kiểm tra CE (Lỗi cú pháp / biên dịch)
$ceCode = file_get_contents(__DIR__ . '/sample_solutions/python/ce_solution.py');
$compCE = $sandbox->compile('python', $ceCode, $tempDir);
if ($compCE['success'] || $compCE['verdict'] !== 'CE') {
    echo "  [FAIL] Không phát hiện lỗi cú pháp (CE)!\n";
    exit(1);
}
echo "  [PASS] 3.3. Phân loại kết quả [CE - Compilation Error] chính xác.\n";

// 3.4 Kiểm tra RE (Lỗi khi chạy - ZeroDivisionError)
$reCode = file_get_contents(__DIR__ . '/sample_solutions/python/re_solution.py');
$compRE = $sandbox->compile('python', $reCode, $tempDir);
$runRE = $sandbox->runTestCase('python', $compRE['executable'], "10 20\n", 1000);
if ($runRE['verdict'] !== 'RE') {
    echo "  [FAIL] Không bắt được lỗi khi chạy (RE)! Trả về: {$runRE['verdict']}\n";
    exit(1);
}
echo "  [PASS] 3.4. Phân loại kết quả [RE - Runtime Error] chính xác.\n";

// 3.5 Kiểm tra TLE (Vượt giới hạn thời gian - Giới hạn 200ms)
$tleCode = file_get_contents(__DIR__ . '/sample_solutions/python/tle_solution.py');
$compTLE = $sandbox->compile('python', $tleCode, $tempDir);
$runTLE = $sandbox->runTestCase('python', $compTLE['executable'], "10 20\n", 200);
if ($runTLE['verdict'] !== 'TLE') {
    echo "  [FAIL] Không ngắt được tiến trình lặp vô hạn (TLE)! Trả về: {$runTLE['verdict']}\n";
    exit(1);
}
echo "  [PASS] 3.5. Giám sát Timeout & Diệt tiến trình lặp vô hạn [TLE - Time Limit Exceeded] thành công.\n";

$sandbox->cleanupRunDir($tempDir);

// ------------------------------------------------------------------
// BƯỚC 4: KIỂM THỬ WORKER ĐIỀU PHỐI HÀNG ĐỢI ĐỘC LẬP
// ------------------------------------------------------------------
echo "\n[BƯỚC 4] Kiểm tra Worker Điều phối Hàng đợi Độc lập (End-to-End):\n";

// Dọn dẹp hàng đợi cũ nếu còn tồn đọng
$cleanWorker = new JudgingWorker(true, 1);
while ($cleanWorker->processNextQueuedRun()) {}

// 4.1 Tạo một bài nộp giả lập ở trạng thái Pending / Queued
$idempotencyKey = 'test_worker_' . bin2hex(random_bytes(8));
$subInsert = $pdo->prepare("
    INSERT INTO submissions 
    (user_id, classroom_id, problem_id, problem_version_id, language, source_code, idempotency_key, final_verdict)
    VALUES (3, 1, 1, 1, 'python', :code, :idemp, 'Pending')
");
$subInsert->execute([
    'code'  => $acCode,
    'idemp' => $idempotencyKey
]);
$testSubId = (int)$pdo->lastInsertId();

// Tạo bản ghi judging_runs ở trạng thái Queued
$runInsert = $pdo->prepare("
    INSERT INTO judging_runs
    (submission_id, problem_version_id, run_number, triggered_by, status, verdict)
    VALUES (:sub_id, 1, 1, 'submit', 'Queued', 'Pending')
");
$runInsert->execute(['sub_id' => $testSubId]);
$testRunId = (int)$pdo->lastInsertId();

// Chạy worker ở chế độ xử lý 1 lượt (--once)
$worker = new JudgingWorker(true, 1);
$processed = $worker->processNextQueuedRun();

if (!$processed) {
    echo "  [FAIL] Worker không lấy được lượt chấm Queued để xử lý!\n";
    exit(1);
}

// Kiểm tra lại trạng thái sau khi Worker hoàn tất
$checkRun = $pdo->query("SELECT status, verdict, passed_cases, total_cases FROM judging_runs WHERE id = {$testRunId}")->fetch();
$checkSub = $pdo->query("SELECT final_verdict, latest_run_id, pass_count, total_cases FROM submissions WHERE id = {$testSubId}")->fetch();
$caseResultsCount = $pdo->query("SELECT COUNT(*) FROM case_results WHERE judging_run_id = {$testRunId}")->fetchColumn();

if ($checkRun['status'] !== 'Completed' || $checkRun['verdict'] !== 'AC') {
    echo "  [FAIL] judging_runs không hoàn tất đúng trạng thái: Status={$checkRun['status']}, Verdict={$checkRun['verdict']}\n";
    exit(1);
}
if ($checkSub['final_verdict'] !== 'AC' || (int)$checkSub['latest_run_id'] !== $testRunId) {
    echo "  [FAIL] submissions không cập nhật đúng: Verdict={$checkSub['final_verdict']}, LatestRun={$checkSub['latest_run_id']}\n";
    exit(1);
}
if ((int)$caseResultsCount !== 5) {
    echo "  [FAIL] Số lượng case_results không đủ 5: {$caseResultsCount}\n";
    exit(1);
}
echo "  [PASS] 4.1. Worker xử lý trọn vẹn: Queued -> Running -> Completed (Verdict = AC, 5/5 cases).\n";

// ------------------------------------------------------------------
// BƯỚC 5: KIỂM THỬ STALE RUN PROTECTION (PHÉP THỬ 4)
// ------------------------------------------------------------------
echo "\n[BƯỚC 5] Kiểm tra Cơ chế Chống ghi đè kết quả cũ về muộn (Stale Run Protection):\n";

// Giả lập tình huống: Bài nộp $testSubId được Rejudge
// Tạo Run #2 (chấm lại) và hoàn tất trước với verdict WA
$run2Insert = $pdo->prepare("
    INSERT INTO judging_runs
    (id, submission_id, problem_version_id, run_number, triggered_by, status, verdict, passed_cases, total_cases, finished_at)
    VALUES (:run2_id, :sub_id, 1, 2, 'rejudge', 'Completed', 'WA', 3, 5, NOW())
");
$testRun2Id = $testRunId + 10;
$run2Insert->execute([
    'run2_id' => $testRun2Id,
    'sub_id'  => $testSubId
]);

// Cập nhật submissions trỏ tới Run #2 mới hơn
$pdo->exec("
    UPDATE submissions 
    SET latest_run_id = {$testRun2Id}, final_verdict = 'WA', pass_count = 3 
    WHERE id = {$testSubId}
");

// Bây giờ giả lập một Run cũ (Run #1 hoặc Run #3 có ID nhỏ hơn Run #2, ví dụ ID = $testRunId) trả về muộn
// Gọi applyStaleRunProtection thông qua phương thức phản chiếu hoặc trực tiếp kiểm tra logic:
$staleRunId = $testRunId; // ID nhỏ hơn $testRun2Id
$method = new ReflectionMethod('JudgingWorker', 'applyStaleRunProtection');
$method->setAccessible(true);

$method->invoke(
    $worker,
    $staleRunId,
    $testSubId,
    'AC', // Kết quả cũ về muộn muốn đổi lại thành AC
    5,
    5,
    100,
    1024
);

// Kiểm tra lại: submissions vẫn PHẢI giữ kết quả của Run #2 (WA, latest_run_id = $testRun2Id)
$verifiedSub = $pdo->query("SELECT final_verdict, latest_run_id, pass_count FROM submissions WHERE id = {$testSubId}")->fetch();
$verifiedOldRun = $pdo->query("SELECT status FROM judging_runs WHERE id = {$staleRunId}")->fetch();

if ($verifiedSub['final_verdict'] !== 'WA' || (int)$verifiedSub['latest_run_id'] !== $testRun2Id) {
    echo "  [FAIL] STALE RUN PROTECTION THẤT BẠI: Kết quả cũ đã ghi đè bài nộp!\n";
    echo "         Sub Verdict: {$verifiedSub['final_verdict']} (Kỳ vọng: WA)\n";
    echo "         Latest Run ID: {$verifiedSub['latest_run_id']} (Kỳ vọng: {$testRun2Id})\n";
    exit(1);
}

if ($verifiedOldRun['status'] !== 'Superseded') {
    echo "  [FAIL] Lần chấm cũ về muộn không được gắn cờ 'Superseded'! Status: {$verifiedOldRun['status']}\n";
    exit(1);
}

echo "  [PASS] 5.1. Lần chấm cũ về muộn bị chặn không được ghi đè bài nộp (Bảo lưu Run #{$testRun2Id} - WA).\n";
echo "  [PASS] 5.2. Lần chấm cũ được đánh dấu trạng thái 'Superseded' trong lịch sử.\n";

echo "\n====================================================================\n";
echo "   KẾT QUẢ: TẤT CẢ CÁC MỤC GIAI ĐOẠN 2 ĐÃ HOÀN TẤT XUẤT SẮC!         \n";
echo "====================================================================\n";
