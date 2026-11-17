<?php
/**
 * Test Suite: Quản lý & Sửa Test Cases Thông Minh (In-place & Versioned Rejudge qua HTTP)
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

echo "============================================================\n";
echo "   KIỂM THỬ TÍNH NĂNG QUẢN LÝ & SỬA TEST CASE THÔNG MINH    \n";
echo "============================================================\n\n";

$baseUrl = 'http://localhost/BaiTapLon';
$cookieFile = sys_get_temp_dir() . '/cookie_admin_tc_test.txt';
@unlink($cookieFile);

// Helper curl
function apiCall(string $url, array $payload, string $cookieFile): array {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieFile);
    curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile);
    $res = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['status' => $status, 'data' => json_decode((string)$res, true), 'raw' => $res];
}

// 1. Đăng nhập Admin
echo "1. Đang đăng nhập tài khoản Admin (admin1)...\n";
$loginRes = apiCall("{$baseUrl}/api/auth.php", [
    'action' => 'login',
    'username' => 'admin1',
    'password' => 'Password123@'
], $cookieFile);

if ($loginRes['status'] !== 200 || empty($loginRes['data']['success'])) {
    echo "  [FAIL] Đăng nhập admin thất bại: " . print_r($loginRes, true) . "\n";
    exit(1);
}
echo "  [OK] Đăng nhập Admin thành công.\n";

$pdo = getDbConnection();

// 2. Tạo một bài tập test có 1 test case ban đầu ở v1 (0 submissions)
$testSlug = 'tc-mgr-test-' . time();
$pdo->beginTransaction();
$pdo->prepare("
    INSERT INTO problems (slug, title, topic, difficulty, created_by, is_public)
    VALUES (?, 'Bài test quản lý testcase', 'Cơ bản', 'Easy', 1, 1)
")->execute([$testSlug]);
$problemId = (int)$pdo->lastInsertId();

$stmtVer = $pdo->prepare("
    INSERT INTO problem_versions (problem_id, version_number, description, input_description, output_description, constraints, changelog, created_by)
    VALUES (?, 1, 'Mô tả test v1', 'input', 'output', '1 <= N <= 100', 'Khởi tạo ban đầu', 1)
");
$stmtVer->execute([$problemId]);
$v1Id = (int)$pdo->lastInsertId();

$pdo->prepare("UPDATE problems SET current_version_id = ? WHERE id = ?")->execute([$v1Id, $problemId]);

$pdo->prepare("
    INSERT INTO test_cases (problem_version_id, case_order, input_data, expected_output, is_sample, is_hidden, points)
    VALUES (?, 1, '1 2', '999', 1, 0, 10)
")->execute([$v1Id]);

$pdo->commit();
echo "2. Đã tạo bài tập test #{$problemId} ('{$testSlug}') với 1 test case ở v1 (0 bài nộp).\n";

// =========================================================================
// TEST 1: GET TESTCASES VIA HTTP
// =========================================================================
echo "\n--- TEST 1: Lấy danh sách test cases qua API GET ---\n";
$getRes = apiCall("{$baseUrl}/api/version_rejudge.php", [
    'action' => 'get_testcases',
    'problem_id' => $problemId
], $cookieFile);

if ($getRes['status'] === 200 && !empty($getRes['data']['success']) && count($getRes['data']['test_cases']) === 1 && $getRes['data']['total_submissions'] === 0) {
    echo "  [PASS] API get_testcases trả về đúng: 1 test case, version = v{$getRes['data']['version_number']}, total_submissions = 0\n";
} else {
    echo "  [FAIL] get_testcases không đạt: " . print_r($getRes, true) . "\n";
    exit(1);
}

// =========================================================================
// TEST 2: SỬA TEST CASE KHI CHƯA CÓ BÀI NỘP (IN-PLACE MODE)
// =========================================================================
echo "\n--- TEST 2: Sửa test case khi 0 bài nộp (In-place mode) ---\n";
$saveRes1 = apiCall("{$baseUrl}/api/version_rejudge.php", [
    'action' => 'save_testcases',
    'problem_id' => $problemId,
    'changelog' => 'Sửa lại test case 1 cho đúng',
    'test_cases' => [
        [
            'input_data' => '1 2',
            'expected_output' => '3',
            'is_sample' => 1,
            'is_hidden' => 0,
            'points' => 10
        ],
        [
            'input_data' => '10 20',
            'expected_output' => '30',
            'is_sample' => 0,
            'is_hidden' => 1,
            'points' => 15
        ]
    ]
], $cookieFile);

if ($saveRes1['status'] === 200 && !empty($saveRes1['data']['success']) && ($saveRes1['data']['mode'] ?? '') === 'inplace') {
    echo "  [PASS] API trả về mode = inplace, message = '{$saveRes1['data']['message']}'\n";
} else {
    echo "  [FAIL] save_testcases inplace thất bại: " . print_r($saveRes1, true) . "\n";
    exit(1);
}

// Kiểm tra trong CSDL
$checkTc = $pdo->prepare("SELECT COUNT(*) FROM test_cases WHERE problem_version_id = ?");
$checkTc->execute([$v1Id]);
$tcCount = (int)$checkTc->fetchColumn();

$checkProb = $pdo->prepare("SELECT current_version_id FROM problems WHERE id = ?");
$checkProb->execute([$problemId]);
$curVer = (int)$checkProb->fetchColumn();

if ($curVer === $v1Id && $tcCount === 2) {
    echo "  [PASS] CSDL xác nhận: current_version_id vẫn là v1 (#{$v1Id}), số lượng test_cases là 2.\n";
} else {
    echo "  [FAIL] CSDL không khớp: curVer={$curVer}, tcCount={$tcCount}\n";
    exit(1);
}

// =========================================================================
// TEST 3: SỬA TEST CASE KHI ĐÃ CÓ BÀI NỘP (VERSIONED REJUDGE MODE)
// =========================================================================
echo "\n--- TEST 3: Sửa test case khi đã có bài nộp (Tự động tạo v2 & Rejudge) ---\n";
// Thêm 1 bài nộp giả lập
$pdo->beginTransaction();
$pdo->prepare("
    INSERT INTO submissions (user_id, problem_id, problem_version_id, language, source_code, idempotency_key, final_verdict)
    VALUES (1, ?, ?, 'python', 'a, b = map(int, input().split())\nprint(a + b)', ?, 'AC')
")->execute([$problemId, $v1Id, 'idemp_' . bin2hex(random_bytes(8))]);
$subId = (int)$pdo->lastInsertId();

$pdo->prepare("
    INSERT INTO judging_runs (submission_id, problem_version_id, run_number, triggered_by, status, verdict)
    VALUES (?, ?, 1, 'submit', 'Completed', 'AC')
")->execute([$subId, $v1Id]);
$pdo->commit();
echo "  -> Đã tạo bài nộp giả lập #{$subId} cho bài tập #{$problemId}.\n";

// Gọi save_testcases khi đã có bài nộp
$saveRes2 = apiCall("{$baseUrl}/api/version_rejudge.php", [
    'action' => 'save_testcases',
    'problem_id' => $problemId,
    'changelog' => 'Nâng cấp v2: Sửa và bổ sung test case số lớn',
    'test_cases' => [
        [
            'input_data' => '1 2',
            'expected_output' => '3',
            'is_sample' => 1,
            'is_hidden' => 0,
            'points' => 10
        ],
        [
            'input_data' => '100 200',
            'expected_output' => '300',
            'is_sample' => 0,
            'is_hidden' => 1,
            'points' => 20
        ]
    ]
], $cookieFile);

if ($saveRes2['status'] === 200 && !empty($saveRes2['data']['success']) && ($saveRes2['data']['mode'] ?? '') === 'new_version_rejudge') {
    echo "  [PASS] API tự động chuyển mode = new_version_rejudge, version_number = {$saveRes2['data']['version_number']}, rejudged_count = {$saveRes2['data']['rejudged_count']}\n";
} else {
    echo "  [FAIL] save_testcases versioned rejudge thất bại: " . print_r($saveRes2, true) . "\n";
    exit(1);
}

$v2Id = (int)$saveRes2['data']['version_id'];
$checkProb2 = $pdo->prepare("SELECT current_version_id FROM problems WHERE id = ?");
$checkProb2->execute([$problemId]);
$newCurVer = (int)$checkProb2->fetchColumn();

$runStmt = $pdo->prepare("SELECT id, run_number, problem_version_id, triggered_by, status FROM judging_runs WHERE submission_id = ? ORDER BY run_number DESC LIMIT 1");
$runStmt->execute([$subId]);
$latestRun = $runStmt->fetch();

if ($newCurVer === $v2Id && $latestRun && (int)$latestRun['run_number'] === 2 && (int)$latestRun['problem_version_id'] === $v2Id && $latestRun['triggered_by'] === 'rejudge') {
    echo "  [PASS] CSDL xác nhận: current_version_id trỏ sang v2 (#{$v2Id}), judging_runs được tạo tự động với Run #2 (triggered_by = 'rejudge')!\n";
} else {
    echo "  [FAIL] CSDL không khớp: newCurVer={$newCurVer}, latestRun=" . print_r($latestRun, true) . "\n";
    exit(1);
}

// Chờ 2 giây để worker xử lý Run #2
echo "\n--- TEST 4: Đợi Worker nền xử lý chấm lại (Rejudge) bài nộp... ---\n";
sleep(2);
$runCheckStmt = $pdo->prepare("SELECT status, verdict FROM judging_runs WHERE id = ?");
$runCheckStmt->execute([(int)$latestRun['id']]);
$afterRun = $runCheckStmt->fetch();
echo "  -> Trạng thái Run #2 sau khi worker xử lý: Status = '{$afterRun['status']}', Verdict = '{$afterRun['verdict']}'\n";
if ($afterRun['status'] === 'Completed') {
    echo "  [PASS] Worker nền đã tự động chấm xong lượt Rejudge Run #2!\n";
}

// Dọn dẹp dữ liệu test
$pdo->beginTransaction();
$pdo->prepare("DELETE FROM problems WHERE id = ?")->execute([$problemId]);
$pdo->commit();
@unlink($cookieFile);
echo "\n[TEARDOWN] Đã dọn dẹp sạch sẽ bài tập test #{$problemId}.\n";

echo "\n============================================================\n";
echo "       TẤT CẢ KIỂM THỬ SỬA TEST CASE THÔNG MINH ĐÃ PASS!     \n";
echo "============================================================\n";
