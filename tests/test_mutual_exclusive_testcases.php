<?php
/**
 * Test Suite: Đảm bảo tính loại trừ lẫn nhau giữa Test Công khai (Sample) và Chấm kín (Hidden)
 * Không bao giờ xảy ra tình trạng chọn cả 2 hoặc không chọn cái nào.
 */

declare(strict_types=1);

$baseUrl = 'http://localhost/BaiTapLon';
$cookieFile = sys_get_temp_dir() . '/cookie_exclusive_test.txt';
@unlink($cookieFile);

echo "============================================================\n";
echo "   KIỂM THỬ TÍNH LOẠI TRỪ: CÔNG KHAI vs CHẤM KÍN TEST CASE  \n";
echo "============================================================\n\n";

// 1. Đăng nhập Admin
echo "1. Đăng nhập Admin...\n";
$ch = curl_init("{$baseUrl}/api/auth.php");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['action' => 'login', 'username' => 'admin1', 'password' => 'Password123@']));
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieFile);
curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile);
$res = curl_exec($ch);
curl_close($ch);
$loginData = json_decode((string)$res, true);
if (empty($loginData['success'])) {
    echo "FAIL: Đăng nhập admin thất bại!\n";
    exit(1);
}
echo "  [OK] Đăng nhập Admin thành công.\n";

// 2. Tạo bài tập bằng form POST chứa 'type' = 'sample' và 'type' = 'hidden'
$testSlug = 'test-exclusive-' . time();
echo "\n2. Tạo bài tập '{$testSlug}' với form radio buttons ('type' = 'sample' / 'hidden')...\n";

$postFields = [
    'action'              => 'create_problem',
    'slug'                => $testSlug,
    'title'               => 'Bài toán Kiểm thử Loại trừ Test Case',
    'topic'               => 'Cơ bản',
    'difficulty'          => 'Easy',
    'time_limit_ms'       => 1000,
    'memory_limit_mb'     => 256,
    'source_attribution'  => 'ByteFlow Test',
    'description'         => 'Mô tả bài toán',
    'input_description'   => 'Đầu vào',
    'output_description'  => 'Đầu ra',
    'constraints'         => '1 <= N <= 100',
    'test_cases'          => [
        [
            'input_data'      => "1 2\n",
            'expected_output' => "3\n",
            'type'            => 'sample',
            'points'          => 10
        ],
        [
            'input_data'      => "10 20\n",
            'expected_output' => "30\n",
            'type'            => 'hidden',
            'points'          => 20
        ]
    ]
];

$ch = curl_init("{$baseUrl}/manage_problems.php");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postFields));
curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile);
$body = curl_exec($ch);
$status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

require_once __DIR__ . '/../config/database.php';
$pdo = getDbConnection();

$stmt = $pdo->prepare("SELECT * FROM problems WHERE slug = ?");
$stmt->execute([$testSlug]);
$prob = $stmt->fetch();

if (!$prob) {
    echo "  [FAIL] Không tạo được bài tập!\n";
    exit(1);
}

$tcStmt = $pdo->prepare("SELECT case_order, is_sample, is_hidden FROM test_cases WHERE problem_version_id = ? ORDER BY case_order ASC");
$tcStmt->execute([$prob['current_version_id']]);
$tcs = $tcStmt->fetchAll();

echo "  -> Số lượng test cases: " . count($tcs) . "\n";
if (count($tcs) !== 2) {
    echo "  [FAIL] Sai số lượng test cases!\n";
    exit(1);
}

// Kiểm tra Test Case 1: Phải là sample=1, hidden=0
if ($tcs[0]['is_sample'] == 1 && $tcs[0]['is_hidden'] == 0) {
    echo "  [PASS] Test Case #1 (sample): is_sample = 1, is_hidden = 0 (ĐÚNG LOẠI TRỪ)\n";
} else {
    echo "  [FAIL] Test Case #1 không loại trừ: is_sample={$tcs[0]['is_sample']}, is_hidden={$tcs[0]['is_hidden']}\n";
    exit(1);
}

// Kiểm tra Test Case 2: Phải là sample=0, hidden=1
if ($tcs[1]['is_sample'] == 0 && $tcs[1]['is_hidden'] == 1) {
    echo "  [PASS] Test Case #2 (hidden): is_sample = 0, is_hidden = 1 (ĐÚNG LOẠI TRỪ)\n";
} else {
    echo "  [FAIL] Test Case #2 không loại trừ: is_sample={$tcs[1]['is_sample']}, is_hidden={$tcs[1]['is_hidden']}\n";
    exit(1);
}

// 3. Kiểm tra API save_testcases khi gửi payload cố ý gửi cả is_sample=1 lẫn is_hidden=1
echo "\n3. Thử gửi payload vi phạm (cố ý set cả 2 bằng 1) qua save_testcases...\n";
$ch = curl_init("{$baseUrl}/api/version_rejudge.php");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
    'action'     => 'save_testcases',
    'problem_id' => $prob['id'],
    'changelog'  => 'Test sửa testcase chống xung đột',
    'test_cases' => [
        [
            'input_data'      => "5 5\n",
            'expected_output' => "10\n",
            'is_sample'       => 1,
            'is_hidden'       => 1, // Vi phạm: cố tình gửi cả 2 bằng 1
            'points'          => 10
        ]
    ]
]));
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile);
$res = curl_exec($ch);
curl_close($ch);
$saveData = json_decode((string)$res, true);

if (empty($saveData['success'])) {
    echo "  [FAIL] API trả về lỗi: " . print_r($saveData, true) . "\n";
    exit(1);
}

// Kiểm tra lại trong DB xem hệ thống có tự động ép về loại trừ hay không
$tcCheck = $pdo->prepare("SELECT is_sample, is_hidden FROM test_cases WHERE problem_version_id = ?");
$tcCheck->execute([$prob['current_version_id']]);
$savedTc = $tcCheck->fetch();

if ($savedTc['is_sample'] == 1 && $savedTc['is_hidden'] == 0) {
    echo "  [PASS] Hệ thống đã tự động bảo vệ: is_sample = 1 ép is_hidden = 0! Không bao giờ có cả 2 bằng 1.\n";
} else {
    echo "  [FAIL] Vẫn còn cả 2 cùng bật: is_sample={$savedTc['is_sample']}, is_hidden={$savedTc['is_hidden']}\n";
    exit(1);
}

// 4. Dọn dẹp
$pdo->beginTransaction();
$pdo->prepare("DELETE FROM problems WHERE id = ?")->execute([$prob['id']]);
$pdo->commit();
@unlink($cookieFile);
echo "\n[TEARDOWN] Đã dọn dẹp sạch sẽ bài test #{$prob['id']}.\n";

echo "\n============================================================\n";
echo "   TẤT CẢ PHÉP THỬ TÍNH LOẠI TRỪ TEST CASE ĐÃ PASS 100%!   \n";
echo "============================================================\n";
