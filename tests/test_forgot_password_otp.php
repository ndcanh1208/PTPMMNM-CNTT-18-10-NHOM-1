<?php
/**
 * Nhom1 Online Judge - Test Suite: Quên Mật Khẩu & Xác Thực OTP qua Email
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

$baseUrl = 'http://localhost/BaiTapLon';
$pdo = getDbConnection();
$ts = time();
$username = "otptest_{$ts}";
$email    = "otptest_{$ts}@gmail.com";
$oldPass  = "OldPass123@";
$newPass  = "NewPass456@";

echo "====================================================================\n";
echo "   KIỂM THỬ CHỨC NĂNG QUÊN MẬT KHẨU BẰNG MÃ OTP QUA EMAIL          \n";
echo "====================================================================\n\n";

function postJson(string $url, array $payload): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json']
    ]);
    $res = curl_exec($ch);
    $st  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['status' => $st, 'body' => (string)$res, 'json' => json_decode((string)$res, true)];
}

// 1. Kiểm tra trang giao diện forgot_password.php & liên kết trên login.php
$fpHtml = (string)@file_get_contents("{$baseUrl}/forgot_password.php");
$lgHtml = (string)@file_get_contents("{$baseUrl}/login.php");
if (strpos($fpHtml, 'handleRequestOtp') !== false && strpos($lgHtml, 'forgot_password.php') !== false) {
    echo "  [PASS] Giao diện forgot_password.php hoạt động chuẩn & login.php có link Quên mật khẩu!\n";
} else {
    echo "  [FAIL] Thiếu giao diện hoặc link Quên mật khẩu!\n";
    exit(1);
}

// 2. Tạo tài khoản test
$reg = postJson("{$baseUrl}/api/auth.php", [
    'action'           => 'register',
    'full_name'        => 'Sinh Viên Test OTP',
    'username'         => $username,
    'email'            => $email,
    'password'         => $oldPass,
    'password_confirm' => $oldPass
]);
$userId = (int)($reg['json']['user_id'] ?? 0);
if ($userId <= 0) {
    echo "  [FAIL] Không tạo được tài khoản test: " . $reg['body'] . "\n";
    exit(1);
}
echo "  [OK] Đã tạo tài khoản kiểm thử #{$userId} ({$email}).\n";

// 3. Thử yêu cầu OTP cho email không tồn tại -> 404
$r404 = postJson("{$baseUrl}/api/forgot_password.php", [
    'action'     => 'request_otp',
    'identifier' => 'khongtontai_99999@gmail.com'
]);
if ($r404['status'] === 404) {
    echo "  [PASS] Chặn email không tồn tại chính xác (HTTP 404)!\n";
} else {
    echo "  [FAIL] Không chặn email không tồn tại! Status={$r404['status']}\n";
    exit(1);
}

// 4. Yêu cầu gửi OTP hợp lệ
$req = postJson("{$baseUrl}/api/forgot_password.php", [
    'action'     => 'request_otp',
    'identifier' => $email
]);
if ($req['status'] !== 200 || empty($req['json']['success'])) {
    echo "  [FAIL] Gửi OTP thất bại: " . $req['body'] . "\n";
    exit(1);
}

// Đọc mã OTP từ mail.log (chế độ local log)
$logLines = @file(__DIR__ . '/../temp/mail_logs/mail.log', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
$lastLine = $logLines ? end($logLines) : '';
if (!preg_match('/OTP:\s*(\d{6})/', (string)$lastLine, $m)) {
    echo "  [FAIL] Không tìm thấy mã OTP 6 chữ số trong temp/mail_logs/mail.log!\n";
    exit(1);
}
$realOtp = $m[1];

// Xác nhận trong CSDL lưu dạng Bcrypt hash chứ KHÔNG lưu plaintext
$dbHash = (string)$pdo->query("SELECT otp_hash FROM password_reset_otps WHERE user_id = {$userId} ORDER BY id DESC LIMIT 1")->fetchColumn();
if ($dbHash !== $realOtp && password_verify($realOtp, $dbHash)) {
    echo "  [PASS] Sinh OTP 6 chữ số thành công & lưu Bcrypt hash an toàn trong CSDL (Email che: {$req['json']['masked_email']})!\n";
} else {
    echo "  [FAIL] OTP trong CSDL chưa được mã hóa hash đúng chuẩn!\n";
    exit(1);
}

// 5. Kiểm tra chống spam Cooldown 60 giây
$spam = postJson("{$baseUrl}/api/forgot_password.php", [
    'action'     => 'request_otp',
    'identifier' => $email
]);
if ($spam['status'] === 429) {
    echo "  [PASS] Chống spam gửi lại OTP liên tục trong 60 giây hoạt động (HTTP 429)!\n";
} else {
    echo "  [FAIL] Cooldown 60s không chặn! Status={$spam['status']}\n";
    exit(1);
}

// 6. Nhập sai OTP -> bị chặn & giảm số lần thử còn lại
$wrongOtp = ($realOtp === '000000') ? '111111' : '000000';
$badVerify = postJson("{$baseUrl}/api/forgot_password.php", [
    'action'     => 'verify_otp',
    'identifier' => $email,
    'otp'        => $wrongOtp
]);
if ($badVerify['status'] === 400 && (int)($badVerify['json']['remaining_attempts'] ?? -1) === 4) {
    echo "  [PASS] Nhập sai OTP bị từ chối (HTTP 400) & đếm chính xác còn 4/5 lần thử!\n";
} else {
    echo "  [FAIL] Kiểm tra sai OTP không đúng: " . $badVerify['body'] . "\n";
    exit(1);
}

// 7. Nhập đúng OTP -> verify thành công
$okVerify = postJson("{$baseUrl}/api/forgot_password.php", [
    'action'     => 'verify_otp',
    'identifier' => $email,
    'otp'        => $realOtp
]);
if ($okVerify['status'] === 200 && !empty($okVerify['json']['success'])) {
    echo "  [PASS] Xác thực đúng mã OTP thành công (HTTP 200)!\n";
} else {
    echo "  [FAIL] Xác thực đúng OTP thất bại: " . $okVerify['body'] . "\n";
    exit(1);
}

// 8. Đặt mật khẩu mới
$reset = postJson("{$baseUrl}/api/forgot_password.php", [
    'action'           => 'reset_password',
    'identifier'       => $email,
    'otp'              => $realOtp,
    'new_password'     => $newPass,
    'confirm_password' => $newPass
]);
if ($reset['status'] === 200 && !empty($reset['json']['success'])) {
    echo "  [PASS] Đặt mật khẩu mới thành công!\n";
} else {
    echo "  [FAIL] Đặt mật khẩu mới thất bại: " . $reset['body'] . "\n";
    exit(1);
}

// 9. Kiểm tra OTP chỉ dùng 1 lần (dùng lại phải bị chặn!)
$reuse = postJson("{$baseUrl}/api/forgot_password.php", [
    'action'           => 'reset_password',
    'identifier'       => $email,
    'otp'              => $realOtp,
    'new_password'     => 'Another123@',
    'confirm_password' => 'Another123@'
]);
if ($reuse['status'] === 400) {
    echo "  [PASS] Chống dùng lại mã OTP cũ thành công (HTTP 400)!\n";
} else {
    echo "  [FAIL] Mã OTP cũ vẫn dùng lại được!\n";
    exit(1);
}

// 10. Xác nhận mật khẩu cũ bị từ chối & mật khẩu mới đăng nhập thành công
$oldLogin = postJson("{$baseUrl}/api/auth.php", ['action' => 'login', 'username' => $username, 'password' => $oldPass]);
$newLogin = postJson("{$baseUrl}/api/auth.php", ['action' => 'login', 'username' => $username, 'password' => $newPass]);
if ($oldLogin['status'] === 401 && $newLogin['status'] === 200) {
    echo "  [PASS] Xác nhận mật khẩu cũ hết hiệu lực (401) & đăng nhập bằng mật khẩu mới thành công (200)!\n";
} else {
    echo "  [FAIL] Kiểm tra đăng nhập sau reset thất bại!\n";
    exit(1);
}

// Dọn dẹp tài khoản test
$pdo->prepare("DELETE FROM users WHERE id = ?")->execute([$userId]);

echo "\n====================================================================\n";
echo "   TẤT CẢ PHÉP THỬ QUÊN MẬT KHẨU BẰNG MÃ OTP ĐÃ PASS 100%!         \n";
echo "====================================================================\n";
