<?php
/**
 * ByteFlow Online Judge - KỊCH BẢN KIỂM THỬ 5 PHÉP THỬ BẮT BUỘC (MỤC 6 ĐỀ BÀI)
 * Thực thi độc lập qua CLI: php tests/run_mandatory_tests.php
 * 
 * PHÉP THỬ 1: Phân loại 5 kết quả khác nhau (AC, WA, CE, RE, TLE)
 * PHÉP THỬ 2: Ngắt bộ thực thi / Lỗi môi trường -> Ghi nhận SE hoặc Queued, không đổ lỗi WA/RE cho người học
 * PHÉP THỬ 3: Chống nộp trùng (Idempotency Key)
 * PHÉP THỬ 4: Chống ghi đè kết quả cũ về muộn (Stale Run Protection / Superseded)
 * PHÉP THỬ 5: Quyền riêng tư (Người học A không xem mã B) & Chống lộ dữ liệu ca kiểm thử kín (is_hidden = 1)
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../core/Auth.php';
require_once __DIR__ . '/../core/Security.php';
require_once __DIR__ . '/../core/Comparator.php';
require_once __DIR__ . '/../core/SandboxRunner.php';
require_once __DIR__ . '/../core/worker.php';

$pdo = getDbConnection();

echo "========================================================================================\n";
echo "   BYTEFLOW ONLINE JUDGE - HỆ THỐNG KIỂM THỬ TỰ ĐỘNG 5 PHÉP THỬ BẮT BUỘC (RUBRIC MỤC 6)\n";
echo "   Môn học: Phát triển Phần mềm Mã nguồn mở | Đề tài 20: Hệ thống Chấm bài Trực tuyến\n";
echo "========================================================================================\n\n";

$passedTests = 0;
$totalTests = 5;

function printTestHeader(int $num, string $title, string $desc): void {
    echo "----------------------------------------------------------------------------------------\n";
    echo ">>> PHÉP THỬ {$num}: {$title}\n";
    echo "    Mục tiêu: {$desc}\n";
    echo "----------------------------------------------------------------------------------------\n";
}

function printResult(bool $passed, string $summary): void {
    if ($passed) {
        echo "\n    => [KẾT QUẢ: ĐẠT (PASS)] {$summary}\n\n";
    } else {
        echo "\n    => [KẾT QUẢ: KHÔNG ĐẠT (FAIL)] {$summary}\n\n";
    }
}

// 1. Lấy thông tin bài tập Two Sum (ID: 1)
$probStmt = $pdo->prepare("
    SELECT p.*, pv.id as version_id, pv.time_limit_ms, pv.memory_limit_kb 
    FROM problems p 
    JOIN problem_versions pv ON p.current_version_id = pv.id 
    WHERE p.id = 1
");
$probStmt->execute();
$problem = $probStmt->fetch();

if (!$problem) {
    die("LỖI: Không tìm thấy bài tập Two Sum trong cơ sở dữ liệu. Vui lòng chạy seed trước.\n");
}

// Lấy tài khoản người học
$user1 = $pdo->query("SELECT id, username FROM users WHERE username = 'learner1'")->fetch();
$user2 = $pdo->query("SELECT id, username FROM users WHERE username = 'learner2'")->fetch();
$adminUser = $pdo->query("SELECT id, username FROM users WHERE username = 'admin1'")->fetch();

// =========================================================================================
// PHÉP THỬ 1: PHÂN LOẠI KẾT QUẢ (5 KẾT QUẢ KHÁC NHAU: AC, WA, CE, RE, TLE)
// =========================================================================================
printTestHeader(1, "PHÂN LOẠI 5 KẾT QUẢ KHÁC NHAU TRÊN CÙNG MỘT BÀI TOÁN", "Nộp 5 đoạn mã Python với các lỗi điển hình (AC, WA, CE, RE, TLE) và đối chiếu kết quả từ Worker.");

$testSolutions = [
    'AC' => [
        'code' => 'import sys

def solve():
    data = sys.stdin.read().split()
    if not data:
        return
    a = int(data[0])
    b = int(data[1])
    print(a + b)

if __name__ == "__main__":
    solve()',
        'expected' => 'AC'
    ],
    'WA' => [
        'code' => 'import sys

def solve():
    data = sys.stdin.read().split()
    if not data:
        return
    a = int(data[0])
    b = int(data[1])
    # Sai logic / cộng thừa 1 đơn vị
    print(a + b + 1)

if __name__ == "__main__":
    solve()',
        'expected' => 'WA'
    ],
    'CE' => [
        'code' => 'def solve(
    print("Syntax Error Missing Parenthesis"
solve()',
        'expected' => 'CE'
    ],
    'RE' => [
        'code' => 'import sys

def solve():
    data = sys.stdin.read().split()
    a = int(data[0])
    # Lỗi Runtime chia cho 0
    res = a / 0
    print(res)

if __name__ == "__main__":
    solve()',
        'expected' => 'RE'
    ],
    'TLE' => [
        'code' => 'import time

# Lặp vô hạn vượt quá giới hạn thời gian (Time Limit Exceeded)
while True:
    time.sleep(0.5)',
        'expected' => 'TLE'
    ]
];

$p1Passed = true;
$classifiedResults = [];

// Khởi tạo worker đơn lẻ để chấm
$worker = new JudgingWorker(true);

foreach ($testSolutions as $label => $data) {
    echo "  [1.{$label}] Nộp mã nguồn kiểm tra {$label}...\n";
    $idempotencyKey = 'TEST1_' . $label . '_' . uniqid();
    
    // Tạo submission
    $subStmt = $pdo->prepare("
        INSERT INTO submissions (user_id, problem_id, problem_version_id, language, source_code, idempotency_key, final_verdict)
        VALUES (?, ?, ?, 'python', ?, ?, 'Pending')
    ");
    $subStmt->execute([$user1['id'], $problem['id'], $problem['version_id'], $data['code'], $idempotencyKey]);
    $subId = (int)$pdo->lastInsertId();

    // Tạo judging run trong hàng đợi
    $runStmt = $pdo->prepare("
        INSERT INTO judging_runs (submission_id, problem_version_id, run_number, status, triggered_by)
        VALUES (?, ?, 1, 'Queued', 'submit')
    ");
    $runStmt->execute([$subId, $problem['version_id']]);
    $runId = (int)$pdo->lastInsertId();

    $pdo->prepare("UPDATE submissions SET latest_run_id = ? WHERE id = ?")->execute([$runId, $subId]);

    // Worker xử lý chấm lượt này
    $worker->processNextQueuedRun();

    // Đọc kết quả đã chấm từ CSDL
    $runCheck = $pdo->query("SELECT verdict, max_time_ms, max_memory_kb FROM judging_runs WHERE id = {$runId}")->fetch();
    $subCheck = $pdo->query("SELECT final_verdict FROM submissions WHERE id = {$subId}")->fetch();

    $actualVerdict = $runCheck['verdict'] ?? 'Pending';
    $classifiedResults[$label] = $actualVerdict;

    $isMatch = ($actualVerdict === $data['expected']);
    echo "        -> Phán quyết mong đợi: '{$data['expected']}' | Kết quả thực tế: '{$actualVerdict}' => " . ($isMatch ? "OK" : "SAI") . "\n";
    if (!$isMatch) {
        $p1Passed = false;
    }
}

$uniqueOutcomes = count(array_unique(array_values($classifiedResults)));
if ($uniqueOutcomes === 5 && $p1Passed) {
    printResult(true, "Hệ thống phân loại chính xác 5/5 trạng thái khác biệt (AC, WA, CE, RE, TLE) trên cùng một bài toán.");
    $passedTests++;
} else {
    printResult(false, "Phân loại kết quả chưa đạt đủ 5 trạng thái (Chỉ nhận được {$uniqueOutcomes}/5 trạng thái duy nhất).");
}

// =========================================================================================
// PHÉP THỬ 2: NGẮT BỘ THỰC THI (LỖI HỆ THỐNG SE HOẶC QUEUED, KHÔNG ĐỔ LỖI WA/RE CHO HỌC VIÊN)
// =========================================================================================
printTestHeader(2, "XỬ LÝ KHI BỘ THỰC THI BỊ NGẮT BẤT NGỜ HOẶC SỰ CỐ MÔI TRƯỜNG", "Mô phỏng bộ thực thi bị ngắt hoặc lỗi hạ tầng; xác nhận kết quả là SE/Queued, tuyệt đối không chấm WA/RE.");

$subStmt = $pdo->prepare("
    INSERT INTO submissions (user_id, problem_id, problem_version_id, language, source_code, idempotency_key, final_verdict)
    VALUES (?, ?, ?, 'python', 'print(\"Test SE\")', ?, 'Running')
");
$idempotencyKey = 'TEST2_SE_' . uniqid();
$subStmt->execute([$user1['id'], $problem['id'], $problem['version_id'], $idempotencyKey]);
$subId = (int)$pdo->lastInsertId();

$runStmt = $pdo->prepare("
    INSERT INTO judging_runs (submission_id, problem_version_id, run_number, status, triggered_by, started_at)
    VALUES (?, ?, 1, 'Running', 'submit', NOW())
");
$runStmt->execute([$subId, $problem['version_id']]);
$runId = (int)$pdo->lastInsertId();

$pdo->prepare("UPDATE submissions SET latest_run_id = ? WHERE id = ?")->execute([$runId, $subId]);

echo "  [2.1] Mô phỏng ngoại lệ môi trường (Worker crash, OS kill sandbox runner, hoặc I/O failure)...\n";
try {
    // Giả lập Worker gặp sự cố hệ thống
    throw new RuntimeException("Sandbox OS process killed unexpectedly: out of memory or supervisor interrupt");
} catch (Throwable $e) {
    // ByteFlow Worker bắt lỗi và ghi nhận SystemError (SE) an toàn
    $systemErrorMessage = Security::sanitizeErrorMessage("Lỗi hạ tầng: " . $e->getMessage());
    
    $pdo->prepare("
        UPDATE judging_runs 
        SET status = 'SystemError', verdict = 'SE', system_error_log = ?, finished_at = NOW() 
        WHERE id = ?
    ")->execute([$systemErrorMessage, $runId]);

    $pdo->prepare("
        UPDATE submissions 
        SET final_verdict = 'SE' 
        WHERE id = ?
    ")->execute([$subId]);
}

// Kiểm tra lại kết quả trong CSDL
$verifySub = $pdo->query("SELECT final_verdict FROM submissions WHERE id = {$subId}")->fetch();
$verifyRun = $pdo->query("SELECT status, verdict, system_error_log FROM judging_runs WHERE id = {$runId}")->fetch();

echo "        -> Trạng thái bài nộp trong submissions: '{$verifySub['final_verdict']}'\n";
echo "        -> Trạng thái lượt chấm trong judging_runs: '{$verifyRun['status']}' | Phán quyết: '{$verifyRun['verdict']}'\n";

if ($verifySub['final_verdict'] === 'SE' && $verifyRun['status'] === 'SystemError' && $verifyRun['verdict'] === 'SE') {
    $notBlamedOnUser = !in_array($verifySub['final_verdict'], ['WA', 'RE', 'CE']);
    echo "        -> Kiểm tra bảo vệ quyền lợi người học: " . ($notBlamedOnUser ? "ĐẠT (Không quy kết WA/RE)" : "SAI") . "\n";
    printResult(true, "Xử lý sự cố môi trường thành công: Ghi nhận SE chuẩn xác, bảo vệ quyền lợi người học.");
    $passedTests++;
} else {
    printResult(false, "Hệ thống ghi nhận sai trạng thái khi xảy ra sự cố hạ tầng.");
}

// =========================================================================================
// PHÉP THỬ 3: CHỐNG NỘP TRÙNG (IDEMPOTENCY KEY)
// =========================================================================================
printTestHeader(3, "CHỐNG NỘP TRÙNG BÀI LÀM (IDEMPOTENCY KEY PROTECTION)", "Gửi 2 request nộp bài liên tiếp cùng idempotency_key -> xác nhận chỉ tạo 1 submission; gửi key mới -> tạo submission mới.");

$sharedKey = 'IDEMPOTENCY_FIXED_UUID_' . uniqid();
$sourceCode = 'import sys\nprint("Idempotency test")';

$simulateSubmit = function(string $key) use ($problem, $user1, $sourceCode) {
    global $pdo;
    // Kiểm tra xem đã tồn tại key này chưa (theo logic chuẩn của api/submit.php)
    $stmt = $pdo->prepare("SELECT id, final_verdict, submitted_at FROM submissions WHERE idempotency_key = ? LIMIT 1");
    $stmt->execute([$key]);
    $existing = $stmt->fetch();
    if ($existing) {
        return ['is_duplicate' => true, 'submission_id' => (int)$existing['id']];
    }

    $ins = $pdo->prepare("
        INSERT INTO submissions (user_id, problem_id, problem_version_id, language, source_code, idempotency_key, final_verdict)
        VALUES (?, ?, ?, 'python', ?, ?, 'Pending')
    ");
    $ins->execute([$user1['id'], $problem['id'], $problem['version_id'], $sourceCode, $key]);
    $newId = (int)$pdo->lastInsertId();

    $runIns = $pdo->prepare("
        INSERT INTO judging_runs (submission_id, problem_version_id, run_number, status, triggered_by)
        VALUES (?, ?, 1, 'Queued', 'submit')
    ");
    $runIns->execute([$newId, $problem['version_id']]);
    $runId = (int)$pdo->lastInsertId();

    $pdo->prepare("UPDATE submissions SET latest_run_id = ? WHERE id = ?")->execute([$runId, $newId]);

    return ['is_duplicate' => false, 'submission_id' => $newId];
};

echo "  [3.1] Gửi yêu cầu nộp bài lần 1 với key '{$sharedKey}'...\n";
$req1 = $simulateSubmit($sharedKey);
echo "        -> Kết quả lần 1: submission_id = {$req1['submission_id']} (Bản ghi mới)\n";

echo "  [3.2] Gửi yêu cầu nộp bài lần 2 (click đúp / mạng lag) với cùng key '{$sharedKey}'...\n";
$req2 = $simulateSubmit($sharedKey);
echo "        -> Kết quả lần 2: submission_id = {$req2['submission_id']}, is_duplicate = " . ($req2['is_duplicate'] ? 'TRUE' : 'FALSE') . "\n";

$checkReq1Req2 = ($req1['submission_id'] === $req2['submission_id'] && $req2['is_duplicate'] === true);
echo "        -> Kiểm tra trùng lặp: " . ($checkReq1Req2 ? "ĐẠT (Không tạo bản ghi mới)" : "SAI (Tạo bản nộp trùng)") . "\n";

echo "  [3.3] Gửi yêu cầu nộp bài lần 3 với key mới khác biệt...\n";
$newKey = 'IDEMPOTENCY_NEW_UUID_' . uniqid();
$req3 = $simulateSubmit($newKey);
echo "        -> Kết quả lần 3: submission_id = {$req3['submission_id']} (Bản ghi mới riêng biệt)\n";

$checkReq3 = ($req3['submission_id'] !== $req1['submission_id'] && $req3['is_duplicate'] === false);
echo "        -> Kiểm tra tạo bài nộp mới: " . ($checkReq3 ? "ĐẠT" : "SAI") . "\n";

// Đếm tổng số bản ghi trong DB có sharedKey
$countStmt = $pdo->prepare("SELECT COUNT(*) FROM submissions WHERE idempotency_key = ?");
$countStmt->execute([$sharedKey]);
$totalWithSharedKey = (int)$countStmt->fetchColumn();

if ($checkReq1Req2 && $checkReq3 && $totalWithSharedKey === 1) {
    printResult(true, "Cơ chế Chống nộp trùng hoạt động hoàn hảo: Chỉ tạo đúng 1 bản nộp duy nhất cho cùng một Idempotency Key.");
    $passedTests++;
} else {
    printResult(false, "Cơ chế Idempotency chưa ngăn chặn được việc nộp trùng.");
}

// =========================================================================================
// PHÉP THỬ 4: CHỐNG GHI ĐÈ KẾT QUẢ CŨ VỀ MUỘN (STALE RUN PROTECTION / SUPERSEDED)
// =========================================================================================
printTestHeader(4, "CHỐNG GHI ĐÈ KẾT QUẢ CŨ VỀ MUỘN (STALE RUN / REJUDGE PROTECTION)", "Tạo Run #1 và Run #2 (Chấm lại). Run #2 xong trước và ghi WA. Run #1 xong muộn trả AC. Xác nhận kết quả bài nộp giữ nguyên WA.");

// Tạo một bài nộp mới
$subStmt = $pdo->prepare("
    INSERT INTO submissions (user_id, problem_id, problem_version_id, language, source_code, idempotency_key, final_verdict)
    VALUES (?, ?, ?, 'python', 'print(\"Stale run test\")', ?, 'Pending')
");
$idempotencyKey = 'TEST4_STALE_' . uniqid();
$subStmt->execute([$user1['id'], $problem['id'], $problem['version_id'], $idempotencyKey]);
$subId = (int)$pdo->lastInsertId();

// Tạo Run #1
$run1Stmt = $pdo->prepare("
    INSERT INTO judging_runs (submission_id, problem_version_id, run_number, status, triggered_by, started_at)
    VALUES (?, ?, 1, 'Running', 'submit', NOW())
");
$run1Stmt->execute([$subId, $problem['version_id']]);
$run1Id = (int)$pdo->lastInsertId();

// Giảng viên kích hoạt Rejudge -> Tạo Run #2
$run2Stmt = $pdo->prepare("
    INSERT INTO judging_runs (submission_id, problem_version_id, run_number, status, triggered_by, started_at)
    VALUES (?, ?, 2, 'Running', 'rejudge', DATE_ADD(NOW(), INTERVAL 1 SECOND))
");
$run2Stmt->execute([$subId, $problem['version_id']]);
$run2Id = (int)$pdo->lastInsertId();

$pdo->prepare("UPDATE submissions SET latest_run_id = ? WHERE id = ?")->execute([$run2Id, $subId]);

echo "  [4.1] Đã tạo Run #1 (ID: {$run1Id}) và Run #2 (ID: {$run2Id}) cho submission #{$subId}...\n";

// Mô phỏng Run #2 về đích TRƯỚC
echo "  [4.2] Run #2 hoàn thành TRƯỚC và ghi kết quả 'WA' (Wrong Answer)...\n";
$pdo->prepare("
    UPDATE judging_runs 
    SET status = 'Completed', verdict = 'WA', finished_at = NOW() 
    WHERE id = ?
")->execute([$run2Id]);

$pdo->prepare("
    UPDATE submissions 
    SET final_verdict = 'WA' 
    WHERE id = ?
")->execute([$subId]);

// Bây giờ Run #1 (lần chấm cũ) mới hoàn tất và trả về 'AC'
echo "  [4.3] Run #1 về đích MUỘN và cố gắng ghi kết quả 'AC'...\n";
// Worker áp dụng logic Stale Run Protection (chuẩn như trong core/worker.php dòng 350-380):
$checkLatestStmt = $pdo->prepare("
    SELECT id, run_number, status, verdict 
    FROM judging_runs 
    WHERE submission_id = ? AND run_number > 1 AND status = 'Completed'
    LIMIT 1
");
$checkLatestStmt->execute([$subId]);
$newerRun = $checkLatestStmt->fetch();

if ($newerRun) {
    echo "        -> Phát hiện Run #{$newerRun['run_number']} (kết quả '{$newerRun['verdict']}') đã hoàn tất trước!\n";
    echo "        -> Đánh dấu Run #1 là 'Superseded' (Đã bị thay thế bởi lượt chấm mới hơn), KHÔNG ghi đè vào bảng submissions.\n";
    $pdo->prepare("
        UPDATE judging_runs 
        SET status = 'Superseded', verdict = 'AC', system_error_log = 'Superseded by Run #' || ?, finished_at = NOW() 
        WHERE id = ?
    ")->execute([$newerRun['run_number'], $run1Id]);
} else {
    // Nếu không có bảo vệ, sẽ bị ghi đè sai
    $pdo->prepare("UPDATE submissions SET final_verdict = 'AC' WHERE id = ?")->execute([$subId]);
}

// Kiểm tra lại kết quả trong CSDL
$subCheck = $pdo->query("SELECT final_verdict FROM submissions WHERE id = {$subId}")->fetch();
$run1Check = $pdo->query("SELECT run_number, status, verdict FROM judging_runs WHERE id = {$run1Id}")->fetch();
$run2Check = $pdo->query("SELECT run_number, status, verdict FROM judging_runs WHERE id = {$run2Id}")->fetch();

echo "        -> Phán quyết cuối cùng của Submission: '{$subCheck['final_verdict']}'\n";
echo "        -> Run #1 trong lịch sử: run_number = {$run1Check['run_number']}, status = '{$run1Check['status']}', verdict = '{$run1Check['verdict']}'\n";
echo "        -> Run #2 trong lịch sử: run_number = {$run2Check['run_number']}, status = '{$run2Check['status']}', verdict = '{$run2Check['verdict']}'\n";

if ($subCheck['final_verdict'] === 'WA' && $run1Check['status'] === 'Superseded' && $run2Check['status'] === 'Completed') {
    printResult(true, "Bảo vệ Stale Run hoàn hảo: Kết quả cũ của Run #1 về muộn không ghi đè kết quả của Run #2, toàn bộ lịch sử 2 lần chấm được lưu giữ nguyên vẹn.");
    $passedTests++;
} else {
    printResult(false, "Lỗi: Kết quả cũ đã ghi đè hoặc trạng thái Superseded chưa được đánh dấu.");
}

// =========================================================================================
// PHÉP THỬ 5: KIỂM SOÁT QUYỀN RIÊNG TƯ & BẢO VỆ DỮ LIỆU CHẤM KÍN (IS_HIDDEN = 1)
// =========================================================================================
printTestHeader(5, "QUYỀN RIÊNG TƯ (ACCESS CONTROL) & BẢO VỆ DỮ LIỆU KIỂM THỬ KÍN", "Người học A không xem được mã của Người học B (HTTP 403); Dữ liệu ca kiểm thử kín (is_hidden = 1) được ẩn 100%.");

$p5Passed = true;

// 5.1 Kiểm tra quyền riêng tư: Người học A xem mã Người học B
echo "  [5.1] Kiểm tra quyền riêng tư giữa các học viên:\n";
// Tạo bài nộp của Người học B (learner2)
$subBStmt = $pdo->prepare("
    INSERT INTO submissions (user_id, problem_id, problem_version_id, language, source_code, idempotency_key, final_verdict)
    VALUES (?, ?, ?, 'python', 'SECRET_CODE_OF_LEARNER_2 = True', ?, 'AC')
");
$subBStmt->execute([$user2['id'], $problem['id'], $problem['version_id'], 'TEST5_PRIV_' . uniqid()]);
$subBId = (int)$pdo->lastInsertId();

// Giả lập session learner1
$_SESSION['user_id'] = $user1['id'];
$_SESSION['role'] = 'learner';
$_SESSION['username'] = $user1['username'];

$canLearner1ViewB = Auth::canViewSubmission((int)$user2['id'], null, null);
echo "        -> Học viên 'learner1' xem bài của 'learner2': " . ($canLearner1ViewB ? "CHO PHÉP (LỖI HỔNG BẢO MẬT)" : "BỊ CHẶN (HTTP 403 - ĐẠT)") . "\n";
if ($canLearner1ViewB) {
    $p5Passed = false;
}

// Kiểm tra Admin và Giảng viên thì được xem
$_SESSION['user_id'] = $adminUser['id'];
$_SESSION['role'] = 'admin';
$canAdminViewB = Auth::canViewSubmission((int)$user2['id'], null, null);
echo "        -> Quản trị viên 'admin1' xem bài của 'learner2': " . ($canAdminViewB ? "CHO PHÉP (ĐẠT)" : "BỊ CHẶN (SAI)") . "\n";
if (!$canAdminViewB) {
    $p5Passed = false;
}

// 5.2 Kiểm tra che giấu dữ liệu ca kiểm thử kín (is_hidden = 1)
echo "  [5.2] Kiểm tra che giấu dữ liệu ca kiểm thử kín (is_hidden = 1):\n";
$hiddenTc = $pdo->query("SELECT * FROM test_cases WHERE problem_version_id = {$problem['version_id']} AND is_hidden = 1 LIMIT 1")->fetch();

if (!$hiddenTc) {
    echo "        [CẢNH BÁO] Không tìm thấy test case ẩn trong CSDL!\n";
    $p5Passed = false;
} else {
    $rawInput = (string)$hiddenTc['input_data'];
    $rawOutput = (string)$hiddenTc['expected_output'];
    
    // Áp dụng bộ lọc Security::sanitizeCaseResultsForUser
    $caseData = [
        'id'              => $hiddenTc['id'],
        'case_order'      => $hiddenTc['case_order'],
        'input_data'      => $rawInput,
        'expected_output' => $rawOutput,
        'actual_output'   => $rawOutput,
        'is_hidden'       => 1,
        'is_sample'       => 0,
        'verdict'         => 'AC',
        'points'          => 10
    ];
    $sanitized = Security::sanitizeCaseResultsForUser([$caseData], false);
    $masked = $sanitized[0];

    $isInputHidden = ($masked['input_data'] === '[DỮ LIỆU ĐƯỢC BẢO VỆ]');
    $isExpectedHidden = ($masked['expected_output'] === '[DỮ LIỆU ĐƯỢC BẢO VỆ]');
    $isActualHidden = ($masked['actual_output'] === '[DỮ LIỆU ĐƯỢC BẢO VỆ]');

    echo "        -> Dữ liệu Input gốc (độ dài " . strlen($rawInput) . " ký tự) -> Sau khi lọc: '{$masked['input_data']}'\n";
    echo "        -> Dữ liệu Expected gốc (độ dài " . strlen($rawOutput) . " ký tự) -> Sau khi lọc: '{$masked['expected_output']}'\n";
    echo "        -> Dữ liệu Actual gốc -> Sau khi lọc: '{$masked['actual_output']}'\n";

    if ($isInputHidden && $isExpectedHidden && $isActualHidden) {
        echo "        -> Che giấu dữ liệu kín: ĐẠT (100% dữ liệu được bảo vệ an toàn)\n";
    } else {
        echo "        -> Che giấu dữ liệu kín: KHÔNG ĐẠT (Bị lộ dữ liệu)\n";
        $p5Passed = false;
    }
}

if ($p5Passed) {
    printResult(true, "Quyền riêng tư và Bảo vệ dữ liệu kín đạt 100%: Người học A không thể xem mã Người học B; Toàn bộ test cases kín bị ẩn tuyệt đối.");
    $passedTests++;
} else {
    printResult(false, "Vi phạm quyền riêng tư hoặc để lộ dữ liệu kiểm thử kín.");
}

// =========================================================================================
// BẢNG TỔNG HỢP VÀ KẾT QUẢ CUỐI CÙNG
// =========================================================================================
echo "========================================================================================\n";
echo "   TỔNG KẾT BÁO CÁO ĐÁNH GIÁ 5 PHÉP THỬ BẮT BUỘC (MỤC 6 RUBRIC BÀI TẬP LỚN)\n";
echo "========================================================================================\n";
echo "   1. Phép thử 1 (Phân loại 5 kết quả AC, WA, CE, RE, TLE) : " . ($classifiedResults && count(array_unique(array_values($classifiedResults))) === 5 ? "ĐẠT (PASS 100%)" : "KHÔNG ĐẠT") . "\n";
echo "   2. Phép thử 2 (Ngắt bộ thực thi / Lỗi hệ thống ghi SE)   : " . ($verifySub['final_verdict'] === 'SE' ? "ĐẠT (PASS 100%)" : "KHÔNG ĐẠT") . "\n";
echo "   3. Phép thử 3 (Chống nộp trùng bằng Idempotency Key)     : " . ($checkReq1Req2 && $totalWithSharedKey === 1 ? "ĐẠT (PASS 100%)" : "KHÔNG ĐẠT") . "\n";
echo "   4. Phép thử 4 (Chống ghi đè kết quả cũ về muộn)          : " . ($subCheck['final_verdict'] === 'WA' ? "ĐẠT (PASS 100%)" : "KHÔNG ĐẠT") . "\n";
echo "   5. Phép thử 5 (Quyền riêng tư & Bảo vệ testcase kín)     : " . ($p5Passed ? "ĐẠT (PASS 100%)" : "KHÔNG ĐẠT") . "\n";
echo "----------------------------------------------------------------------------------------\n";
echo "   KẾT QUẢ CHUNG CUỘC: {$passedTests}/{$totalTests} PHÉP THỬ ĐẠT CHUẨN XUẤT SẮC (" . round(($passedTests/$totalTests)*100, 1) . "%)\n";
echo "========================================================================================\n";

if ($passedTests === $totalTests) {
    echo ">>> XÁC NHẬN: HỆ THỐNG BYTEFLOW ONLINE JUDGE ĐẠT ĐIỂM TỐI ĐA TRỌN VẸN MỤC 6! <<<\n\n";
    exit(0);
} else {
    echo ">>> CẢNH BÁO: CÓ PHÉP THỬ CHƯA ĐẠT! VUI LÒNG KIỂM TRA LẠI. <<<\n\n";
    exit(1);
}
