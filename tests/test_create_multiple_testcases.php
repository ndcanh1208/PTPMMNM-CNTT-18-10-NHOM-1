<?php
/**
 * Test problem creation with MULTIPLE test cases via POST to manage_problems.php
 */

declare(strict_types=1);

$baseUrl = 'http://localhost/BaiTapLon';
$cookieFile = sys_get_temp_dir() . '/create_multi_tc_cookie.txt';
@unlink($cookieFile);

echo "============================================================\n";
echo "   KIỂM THỬ TẠO BÀI TẬP VỚI NHIỀU TEST CASES BAN ĐẦU       \n";
echo "============================================================\n\n";

// 1. Login as instructor1
echo "1. Đăng nhập với tài khoản Giảng viên (instructor1)...\n";
$ch = curl_init("{$baseUrl}/api/auth.php");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['action' => 'login', 'username' => 'instructor1', 'password' => 'Password123@']));
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieFile);
curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile);
$res = curl_exec($ch);
curl_close($ch);
$loginData = json_decode((string)$res, true);
if (empty($loginData['success'])) {
    echo "FAIL: Đăng nhập thất bại!\n";
    exit(1);
}
echo "  [OK] Đăng nhập thành công.\n";

// 2. Tạo bài tập với 3 test cases:
// Test 1: Sample (Công khai) - 10 điểm
// Test 2: Hidden (Chấm kín) - 20 điểm
// Test 3: Hidden (Chấm kín) - 30 điểm
$testSlug = 'test-multi-tc-' . time();
echo "2. Gửi yêu cầu tạo bài tập '{$testSlug}' với 3 test cases...\n";

$postFields = [
    'action'              => 'create_problem',
    'slug'                => $testSlug,
    'title'               => 'Bài toán Kiểm thử Nhiều Test Cases',
    'topic'               => 'Mảng và Chuỗi',
    'difficulty'          => 'Medium',
    'time_limit_ms'       => 1500,
    'memory_limit_mb'     => 128,
    'source_attribution'  => 'ByteFlow Multi-TC Test Suite',
    'description'         => 'Cho 2 số a, b. In ra tổng a + b.',
    'input_description'   => 'Dòng duy nhất gồm 2 số nguyên a, b.',
    'output_description'  => 'Tổng a + b.',
    'constraints'         => '-10^9 <= a, b <= 10^9',
    'test_cases'          => [
        [
            'input_data'      => "3 5\n",
            'expected_output' => "8\n",
            'is_sample'       => "1",
            'is_hidden'       => "0",
            'points'          => "10"
        ],
        [
            'input_data'      => "100 200\n",
            'expected_output' => "300\n",
            'is_sample'       => "0",
            'is_hidden'       => "1",
            'points'          => "20"
        ],
        [
            'input_data'      => "-50 50\n",
            'expected_output' => "0\n",
            'is_sample'       => "0",
            'is_hidden'       => "1",
            'points'          => "30"
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

echo "  -> HTTP Status: {$status}\n";

// 3. Kiểm tra trong CSDL
require_once __DIR__ . '/../config/database.php';
$pdo = getDbConnection();

$stmt = $pdo->prepare("SELECT * FROM problems WHERE slug = ?");
$stmt->execute([$testSlug]);
$prob = $stmt->fetch();

if (!$prob) {
    echo "  [FAIL] Không tìm thấy bài tập trong CSDL!\n";
    exit(1);
}
echo "  [PASS] Bài tập đã được tạo thành công với ID #{$prob['id']} (current_version_id = {$prob['current_version_id']}).\n";

$tcStmt = $pdo->prepare("SELECT * FROM test_cases WHERE problem_version_id = ? ORDER BY case_order ASC");
$tcStmt->execute([$prob['current_version_id']]);
$testCases = $tcStmt->fetchAll();

echo "  -> Số lượng test cases lưu trong CSDL: " . count($testCases) . "\n";

if (count($testCases) !== 3) {
    echo "  [FAIL] Số lượng test case không đúng (mong đợi: 3, thực tế: " . count($testCases) . ")\n";
    exit(1);
}

// Kiểm tra chi tiết từng test case
$expectedTCs = [
    ['order' => 1, 'input' => "3 5\n", 'output' => "8\n", 'sample' => 1, 'hidden' => 0, 'points' => 10],
    ['order' => 2, 'input' => "100 200\n", 'output' => "300\n", 'sample' => 0, 'hidden' => 1, 'points' => 20],
    ['order' => 3, 'input' => "-50 50\n", 'output' => "0\n", 'sample' => 0, 'hidden' => 1, 'points' => 30],
];

foreach ($expectedTCs as $idx => $exp) {
    $actual = $testCases[$idx];
    $match = ($actual['case_order'] == $exp['order'])
          && ($actual['input_data'] === $exp['input'])
          && ($actual['expected_output'] === $exp['output'])
          && ($actual['is_sample'] == $exp['sample'])
          && ($actual['is_hidden'] == $exp['hidden'])
          && ($actual['points'] == $exp['points']);

    if ($match) {
        echo "  [PASS] Test Case #{$exp['order']}: Sample={$actual['is_sample']}, Hidden={$actual['is_hidden']}, Points={$actual['points']} => ĐÚNG 100%.\n";
    } else {
        echo "  [FAIL] Test Case #{$exp['order']} không khớp dữ liệu mong đợi:\n";
        print_r($actual);
        exit(1);
    }
}

// 4. Dọn dẹp bài test
$pdo->beginTransaction();
$pdo->prepare("DELETE FROM problems WHERE id = ?")->execute([$prob['id']]);
$pdo->commit();
@unlink($cookieFile);
echo "\n[TEARDOWN] Đã dọn dẹp bài test #{$prob['id']}.\n";

echo "\n============================================================\n";
echo "   TẤT CẢ KIỂM THỬ TẠO ĐỀ NHIỀU TEST CASE ĐÃ THÀNH CÔNG!     \n";
echo "============================================================\n";
