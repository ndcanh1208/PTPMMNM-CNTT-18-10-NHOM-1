<?php
/**
 * Test Suite: Nhập & Thẩm định Bộ Test Cases từ File (ZIP & JSON)
 * Kiểm định 3 Cấp độ: Cấu trúc, Ghép cặp/File mồ côi, và Đối soát Lời giải chuẩn (Model Solution)
 */

declare(strict_types=1);

$baseUrl = 'http://localhost/BaiTapLon';
$cookieFile = sys_get_temp_dir() . '/cookie_import_test.txt';
@unlink($cookieFile);

echo "============================================================\n";
echo "   KIỂM THỬ NHẬP & THẨM ĐỊNH BỘ TEST CASES TỪ FILE (ZIP/JSON) \n";
echo "============================================================\n\n";

// 1. Đăng nhập Giảng viên
echo "1. Đăng nhập Giảng viên (instructor1)...\n";
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
    echo "  [FAIL] Đăng nhập thất bại!\n";
    exit(1);
}
echo "  [OK] Đăng nhập thành công.\n";

// 2. Tạo một file ZIP chứa các test case:
// - 01.in & 01.out (Đúng: 1 2 -> 3)
// - 02.in & 02.out (Đúng: 10 20 -> 30)
// - 03.in & 03.out (Cố tình để Output sai: 100 200 -> 9999 thay vì 300)
// - 04.in (File mồ côi: thiếu 04.out)
$tempZipPath = sys_get_temp_dir() . '/test_pack_' . uniqid() . '.zip';
$zip = new ZipArchive();
if ($zip->open($tempZipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    echo "  [FAIL] Không thể tạo file ZIP tạm!\n";
    exit(1);
}

$zip->addFromString('01.in', "1 2\n");
$zip->addFromString('01.out', "3\n");
$zip->addFromString('02.in', "10 20\n");
$zip->addFromString('02.out', "30\n");
$zip->addFromString('03.in', "100 200\n");
$zip->addFromString('03.out', "9999\n"); // Cố tình để sai đáp án
$zip->addFromString('04.in', "5 5\n");    // Mồ côi: không có 04.out
$zip->close();

echo "2. Đã tạo gói ZIP kiểm thử: {$tempZipPath} (3 cặp file, 1 file mồ côi)\n";

// =============================================================================
// TEST 1: THẨM ĐỊNH FILE ZIP CƠ BẢN (KHÔNG MODEL SOLUTION)
// =============================================================================
echo "\n--- TEST 1: Thẩm định file ZIP (Cấp độ 1 & 2: Ghép cặp & Phát hiện mồ côi) ---\n";
$cfile = new CURLFile($tempZipPath, 'application/zip', 'test_pack.zip');
$ch = curl_init("{$baseUrl}/api/import_testcases.php");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, ['testcase_file' => $cfile]);
curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile);
$res = curl_exec($ch);
$status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$data1 = json_decode((string)$res, true);
if ($status === 200 && !empty($data1['success'])) {
    echo "  -> Tổng tìm thấy: {$data1['total_found']} | Hợp lệ: {$data1['valid_count']} | Mồ côi: {$data1['orphan_count']}\n";
    if ($data1['valid_count'] === 3 && $data1['orphan_count'] === 1) {
        echo "  [PASS] Thuật toán ghép cặp chính xác: Nhận diện đủ 3 cặp hợp lệ và phát hiện đúng 1 file mồ côi (04.in)!\n";
    } else {
        echo "  [FAIL] Kết quả ghép cặp không đúng số lượng mong đợi!\n";
        exit(1);
    }
} else {
    echo "  [FAIL] Gọi API thất bại: " . print_r($data1, true) . "\n";
    exit(1);
}

// =============================================================================
// TEST 2: THẨM ĐỊNH CẤP ĐỘ 3 VỚI LỜI GIẢI CHUẨN (MODEL SOLUTION)
// =============================================================================
echo "\n--- TEST 2: Thẩm định Cấp độ 3 (Đối soát Lời giải chuẩn phát hiện đáp án sai) ---\n";
$modelPython = "a, b = map(int, input().split())\nprint(a + b)";

$ch = curl_init("{$baseUrl}/api/import_testcases.php");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, [
    'testcase_file'  => new CURLFile($tempZipPath, 'application/zip', 'test_pack.zip'),
    'model_solution' => $modelPython,
    'solution_lang'  => 'python'
]);
curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile);
$res2 = curl_exec($ch);
$status2 = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$data2 = json_decode((string)$res2, true);
if ($status2 === 200 && !empty($data2['success']) && !empty($data2['model_solution_ran'])) {
    echo "  -> Lời giải chuẩn đã được biên dịch và chạy đối soát trên 3 test cases.\n";
    $tc1 = $data2['test_cases'][0];
    $tc2 = $data2['test_cases'][1];
    $tc3 = $data2['test_cases'][2];

    echo "  -> Test #1: Status = '{$tc1['status']}', Verified = " . ($tc1['model_verified'] ? 'TRUE' : 'FALSE') . "\n";
    echo "  -> Test #2: Status = '{$tc2['status']}', Verified = " . ($tc2['model_verified'] ? 'TRUE' : 'FALSE') . "\n";
    echo "  -> Test #3: Status = '{$tc3['status']}', Verified = " . ($tc3['model_verified'] ? 'TRUE' : 'FALSE') . "\n";

    if ($tc1['status'] === 'VERIFIED' && $tc2['status'] === 'VERIFIED' && $tc3['status'] === 'MISMATCH') {
        echo "  [PASS] Cấp độ 3 thành công rực rỡ: Test #1, #2 đóng dấu VERIFIED, Test #3 phát hiện chính xác MISMATCH (đáp án sai 9999 vs 300)!\n";
    } else {
        echo "  [FAIL] Trạng thái đối soát không đúng!\n";
        exit(1);
    }
} else {
    echo "  [FAIL] Chạy đối soát thất bại: Status {$status2}, Response: " . substr((string)$res2, 0, 500) . "\n";
    exit(1);
}

// =============================================================================
// TEST 3: THẨM ĐỊNH TỆP ĐỊNH DẠNG JSON
// =============================================================================
echo "\n--- TEST 3: Thẩm định nhập tệp JSON ---\n";
$tempJsonPath = sys_get_temp_dir() . '/test_pack_' . uniqid() . '.json';
$jsonData = [
    [
        'input'     => "100 50\n",
        'output'    => "150\n",
        'is_sample' => 1,
        'points'    => 10
    ],
    [
        'input'     => "200 300\n",
        'output'    => "500\n",
        'is_sample' => 0,
        'points'    => 20
    ]
];
file_put_contents($tempJsonPath, json_encode($jsonData));

$ch = curl_init("{$baseUrl}/api/import_testcases.php");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, ['testcase_file' => new CURLFile($tempJsonPath, 'application/json', 'tests.json')]);
curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile);
$res3 = curl_exec($ch);
$status3 = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$data3 = json_decode((string)$res3, true);
if ($status3 === 200 && !empty($data3['success']) && $data3['valid_count'] === 2) {
    echo "  [PASS] Nhập tệp JSON thành công: Đọc đủ 2 test cases với các trường input/output/is_sample/points!\n";
} else {
    echo "  [FAIL] Nhập JSON thất bại: " . print_r($data3, true) . "\n";
    exit(1);
}

// Dọn dẹp tệp tạm
@unlink($tempZipPath);
@unlink($tempJsonPath);
@unlink($cookieFile);

echo "\n============================================================\n";
echo "   TẤT CẢ PHÉP THỬ NHẬP & THẨM ĐỊNH BỘ TEST CASE ĐÃ PASS!   \n";
echo "============================================================\n";
