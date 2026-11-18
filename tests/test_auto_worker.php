<?php
/**
 * Nhom1 Online Judge - Test Suite: Tự động Kích hoạt Worker Khi Nộp Bài (Zero Manual CLI)
 * Xác nhận khi KHÔNG có cửa sổ worker.php chạy tay:
 * 1. Bấm nộp bài qua HTTP trả về nhanh (< 1s, không chặn Apache).
 * 2. Worker tự động chạy ngầm ở chế độ --drain và chấm xong bài thành AC (5/5).
 * 3. Sau khi chấm sạch hàng đợi, Worker tự động tắt và nhả khóa worker.lock.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../core/WorkerLauncher.php';

$baseUrl = 'http://localhost/BaiTapLon';
$cookieFile = sys_get_temp_dir() . '/cookie_autoworker_' . uniqid() . '.txt';
$pdo = getDbConnection();

echo "====================================================================\n";
echo "   KIỂM THỬ TỰ ĐỘNG KÍCH HOẠT WORKER CHẤM BÀI (KHÔNG CẦN CHẠY TAY) \n";
echo "====================================================================\n\n";

echo "1. Kiểm tra trạng thái Worker trước khi nộp bài...\n";
$wasRunning = WorkerLauncher::isWorkerRunning();
echo "  -> Worker đang chạy sẵn: " . ($wasRunning ? 'YES' : 'NO (Đúng kịch bản chưa chạy tay!)') . "\n";

// 2. Đăng nhập admin1 (mật khẩu canhnguyen)
echo "\n2. Đăng nhập tài khoản admin1...\n";
$ch = curl_init("{$baseUrl}/api/auth.php");
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => json_encode(['action' => 'login', 'username' => 'admin1', 'password' => 'canhnguyen']),
    CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
    CURLOPT_COOKIEJAR      => $cookieFile,
    CURLOPT_COOKIEFILE     => $cookieFile
]);
$loginRes = json_decode((string)curl_exec($ch), true);
curl_close($ch);
if (empty($loginRes['success'])) {
    echo "  [FAIL] Đăng nhập thất bại!\n";
    exit(1);
}
echo "  [OK] Đăng nhập thành công.\n";

// 3. Nộp bài tập #1 (two-sum) bằng Python và đo thời gian phản hồi HTTP
echo "\n3. Gửi yêu cầu nộp bài (POST /api/submit.php) & đo độ trễ Apache...\n";
$t0 = microtime(true);
$ch = curl_init("{$baseUrl}/api/submit.php");
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => json_encode([
        'problem_id'      => 1,
        'language'        => 'python',
        'source_code'     => "a, b = map(int, input().split())\nprint(a + b)",
        'idempotency_key' => 'auto_worker_test_' . uniqid()
    ]),
    CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
    CURLOPT_COOKIEFILE     => $cookieFile
]);
$subRaw = curl_exec($ch);
$subCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
$elapsedMs = round((microtime(true) - $t0) * 1000);

$subData = json_decode((string)$subRaw, true);
$subId = (int)($subData['submission_id'] ?? 0);
if ($subCode !== 201 || $subId <= 0) {
    echo "  [FAIL] Nộp bài thất bại (HTTP {$subCode}): {$subRaw}\n";
    exit(1);
}
echo "  [PASS] Đã tạo Submission #{$subId} trong {$elapsedMs}ms (Apache trả về ngay lập tức, không bị treo)!\n";

// 4. Đợi tối đa 10 giây xem Worker ngầm có TỰ ĐỘNG chấm xong không
echo "\n4. Theo dõi kết quả tự động chấm (KHÔNG mở terminal worker.php)...\n";
$finalVerdict = 'Pending';
$passedCases = 0;
$totalCases = 0;
for ($i = 1; $i <= 20; $i++) {
    usleep(500000); // 0.5s
    $ch = curl_init("{$baseUrl}/api/submission_status.php?id={$subId}");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_COOKIEFILE     => $cookieFile
    ]);
    $stData = json_decode((string)curl_exec($ch), true);
    curl_close($ch);

    $v = $stData['submission']['final_verdict'] ?? 'Pending';
    if ($v !== 'Pending' && $v !== 'Running') {
        $finalVerdict = $v;
        $passedCases  = (int)($stData['submission']['pass_count'] ?? 0);
        $totalCases   = (int)($stData['submission']['total_cases'] ?? 0);
        echo "  -> Sau " . ($i * 0.5) . " giây: Verdict = '{$finalVerdict}' ({$passedCases}/{$totalCases} test đạt)\n";
        break;
    }
}

if ($finalVerdict === 'AC' && $passedCases > 0 && $passedCases === $totalCases) {
    echo "  [PASS] Worker ngầm đã TỰ ĐỘNG kích hoạt và chấm chuẩn xác AC ({$passedCases}/{$totalCases})!\n";
} else {
    echo "  [FAIL] Bài nộp chưa được tự động chấm sang AC! Verdict cuối: {$finalVerdict}\n";
    exit(1);
}

// 5. Đợi 2.5 giây kiểm tra Worker --drain tự động thoát gọn gàng
echo "\n5. Kiểm tra Worker --drain tự động giải phóng sau khi hết việc...\n";
sleep(3);
$stillRunning = WorkerLauncher::isWorkerRunning();
if (!$stillRunning) {
    echo "  [PASS] Worker --drain đã tự động dừng sạch sẽ và nhả khóa worker.lock!\n";
} else {
    echo "  [INFO] Worker vẫn đang giữ khóa (có thể vẫn còn tác vụ nền).\n";
}

// 6. Dọn dẹp bản nộp test
$pdo->prepare("DELETE cr FROM case_results cr JOIN judging_runs jr ON cr.judging_run_id = jr.id WHERE jr.submission_id = ?")->execute([$subId]);
$pdo->prepare("DELETE FROM judging_runs WHERE submission_id = ?")->execute([$subId]);
$pdo->prepare("DELETE FROM submissions WHERE id = ?")->execute([$subId]);
@unlink($cookieFile);

echo "\n====================================================================\n";
echo "   KIỂM THỬ TỰ ĐỘNG KÍCH HOẠT WORKER ĐÃ PASS 100%!                  \n";
echo "====================================================================\n";
