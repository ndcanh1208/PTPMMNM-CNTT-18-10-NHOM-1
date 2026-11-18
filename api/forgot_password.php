<?php
/**
 * Nhom1 Online Judge - API Quên Mật khẩu & Xác thực Mã OTP qua Email
 * POST /api/forgot_password.php
 * 
 * Hỗ trợ 3 bước:
 * 1. request_otp    : Sinh mã OTP 6 chữ số (Bcrypt hash, hết hạn 10 phút, cooldown 60s) & gửi về Email
 * 2. verify_otp     : Xác thực mã OTP (tối đa 5 lần nhập sai)
 * 3. reset_password : Xác thực lại OTP, đặt mật khẩu mới và vô hiệu hóa OTP (dùng 1 lần)
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../core/Mailer.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Phương thức không hợp lệ (chỉ chấp nhận POST).'], JSON_UNESCAPED_UNICODE);
    exit;
}

$raw = file_get_contents('php://input');
$input = json_decode((string)$raw, true);
if (!is_array($input)) {
    $input = $_POST;
}

$action     = trim((string)($input['action'] ?? ''));
$identifier = trim((string)($input['identifier'] ?? $input['email'] ?? ''));

if ($identifier === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Vui lòng nhập Email, Tên đăng nhập hoặc Mã sinh viên.'], JSON_UNESCAPED_UNICODE);
    exit;
}

function maskEmailAddress(string $email): string {
    $parts = explode('@', $email);
    if (count($parts) !== 2) {
        return '***';
    }
    $local = $parts[0];
    $domain = $parts[1];
    $keep = min(2, strlen($local));
    return substr($local, 0, $keep) . str_repeat('*', max(3, strlen($local) - $keep)) . '@' . $domain;
}

try {
    $pdo = getDbConnection();

    // Tìm tài khoản theo Email, Username hoặc MSSV
    $uStmt = $pdo->prepare("
        SELECT id, username, email, full_name, is_locked, lock_reason
        FROM users
        WHERE LOWER(email) = LOWER(:id1) OR username = :id2 OR (student_code IS NOT NULL AND student_code = :id3)
        LIMIT 1
    ");
    $uStmt->execute([
        'id1' => $identifier,
        'id2' => $identifier,
        'id3' => strtoupper($identifier)
    ]);
    $user = $uStmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'error'   => 'Không tìm thấy tài khoản nào khớp với Email / Tên đăng nhập bạn đã nhập.'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ((int)$user['is_locked'] === 1) {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'error'   => 'Tài khoản này hiện đang bị khóa. Vui lòng liên hệ Quản trị viên để được hỗ trợ.'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $userId = (int)$user['id'];
    $userEmail = (string)$user['email'];
    $maskedEmail = maskEmailAddress($userEmail);

    // =========================================================================
    // BƯỚC 1: TẠO VÀ GỬI MÃ OTP VỀ EMAIL
    // =========================================================================
    if ($action === 'request_otp') {
        // Kiểm tra chống spam gửi lại (Cooldown 60 giây)
        $coolStmt = $pdo->prepare("
            SELECT TIMESTAMPDIFF(SECOND, created_at, NOW()) AS elapsed
            FROM password_reset_otps
            WHERE user_id = ?
            ORDER BY id DESC
            LIMIT 1
        ");
        $coolStmt->execute([$userId]);
        $lastOtp = $coolStmt->fetch(PDO::FETCH_ASSOC);
        if ($lastOtp !== false && (int)$lastOtp['elapsed'] < 60 && (int)$lastOtp['elapsed'] >= 0) {
            $wait = 60 - (int)$lastOtp['elapsed'];
            http_response_code(429);
            echo json_encode([
                'success'      => false,
                'wait_seconds' => $wait,
                'error'        => "Bạn vừa yêu cầu mã OTP. Vui lòng đợi thêm {$wait} giây trước khi gửi lại."
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        // Sinh mã OTP 6 chữ số ngẫu nhiên an toàn mật mã
        $otpCode = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $otpHash = password_hash($otpCode, PASSWORD_DEFAULT);

        $pdo->beginTransaction();

        // Vô hiệu hóa các mã OTP cũ chưa dùng của tài khoản này
        $pdo->prepare("UPDATE password_reset_otps SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL")
            ->execute([$userId]);

        // Lưu OTP mới (hết hạn sau 10 phút)
        $insStmt = $pdo->prepare("
            INSERT INTO password_reset_otps (user_id, email, otp_hash, attempts, expires_at, created_at)
            VALUES (?, ?, ?, 0, DATE_ADD(NOW(), INTERVAL 10 MINUTE), NOW())
        ");
        $insStmt->execute([$userId, $userEmail, $otpHash]);
        $otpRowId = (int)$pdo->lastInsertId();

        // Gửi Email chứa mã OTP
        $mailRes = Mailer::sendPasswordResetOtp($userEmail, (string)$user['full_name'], $otpCode, 10);
        if (!$mailRes['success']) {
            $pdo->rollBack();
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'error'   => 'Không thể gửi email OTP: ' . ($mailRes['error'] ?? 'Lỗi cấu hình SMTP.')
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $pdo->commit();

        $isLogMode = ($mailRes['mode'] === 'log');
        $msg = $isLogMode
            ? "Đã tạo mã OTP cho email {$maskedEmail}! (Môi trường localhost chưa cấu hình SMTP Gmail — mở tệp temp/mail_logs/last_otp.html để xem email OTP)."
            : "Đã gửi mã xác nhận OTP 6 chữ số tới email {$maskedEmail}. Vui lòng kiểm tra hộp thư (kể cả mục Spam/Thư rác).";

        echo json_encode([
            'success'          => true,
            'masked_email'     => $maskedEmail,
            'delivery_mode'    => $mailRes['mode'],
            'log_path'         => $mailRes['log_path'],
            'cooldown_seconds' => 60,
            'expire_minutes'   => 10,
            'message'          => $msg
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // =========================================================================
    // HÀM KIỂM TRA OTP DÙNG CHUNG CHO BƯỚC 2 & BƯỚC 3
    // =========================================================================
    $otpInput = preg_replace('/\s+/', '', (string)($input['otp'] ?? ''));
    if (!preg_match('/^\d{6}$/', $otpInput)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Mã OTP phải gồm đúng 6 chữ số.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $otpStmt = $pdo->prepare("
        SELECT id, otp_hash, attempts, expires_at, (expires_at < NOW()) AS is_expired
        FROM password_reset_otps
        WHERE user_id = ? AND used_at IS NULL
        ORDER BY id DESC
        LIMIT 1
    ");
    $otpStmt->execute([$userId]);
    $otpRow = $otpStmt->fetch(PDO::FETCH_ASSOC);

    if (!$otpRow) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error'   => 'Không có mã OTP nào đang hiệu lực. Vui lòng yêu cầu gửi mã OTP mới.'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ((int)$otpRow['is_expired'] === 1) {
        $pdo->prepare("UPDATE password_reset_otps SET used_at = NOW() WHERE id = ?")->execute([(int)$otpRow['id']]);
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error'   => 'Mã OTP đã hết hạn (quá 10 phút). Vui lòng yêu cầu gửi lại mã mới.'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ((int)$otpRow['attempts'] >= 5) {
        $pdo->prepare("UPDATE password_reset_otps SET used_at = NOW() WHERE id = ?")->execute([(int)$otpRow['id']]);
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error'   => 'Bạn đã nhập sai mã OTP quá 5 lần. Mã OTP đã bị hủy, vui lòng gửi mã mới.'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if (!password_verify($otpInput, (string)$otpRow['otp_hash'])) {
        $newAttempts = (int)$otpRow['attempts'] + 1;
        $remaining = max(0, 5 - $newAttempts);
        if ($remaining === 0) {
            $pdo->prepare("UPDATE password_reset_otps SET attempts = ?, used_at = NOW() WHERE id = ?")
                ->execute([$newAttempts, (int)$otpRow['id']]);
            $errMsg = 'Mã OTP không chính xác và đã vượt quá 5 lần thử. Vui lòng yêu cầu mã OTP mới.';
        } else {
            $pdo->prepare("UPDATE password_reset_otps SET attempts = ? WHERE id = ?")
                ->execute([$newAttempts, (int)$otpRow['id']]);
            $errMsg = "Mã OTP không chính xác. Bạn còn {$remaining} lần thử.";
        }
        http_response_code(400);
        echo json_encode(['success' => false, 'remaining_attempts' => $remaining, 'error' => $errMsg], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // =========================================================================
    // BƯỚC 2: XÁC THỰC OTP HỢP LỆ
    // =========================================================================
    if ($action === 'verify_otp') {
        echo json_encode([
            'success'      => true,
            'masked_email' => $maskedEmail,
            'message'      => 'Mã OTP hợp lệ! Hãy đặt mật khẩu mới cho tài khoản của bạn.'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // =========================================================================
    // BƯỚC 3: ĐẶT MẬT KHẨU MỚI & HỦY OTP (DÙNG 1 LẦN)
    // =========================================================================
    if ($action === 'reset_password') {
        $newPass     = (string)($input['new_password'] ?? '');
        $confirmPass = (string)($input['confirm_password'] ?? '');

        if (strlen($newPass) < 6) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Mật khẩu mới phải có ít nhất 6 ký tự.'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        if ($newPass !== $confirmPass) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Mật khẩu xác nhận không khớp.'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $pdo->beginTransaction();

        // Cập nhật mật khẩu mới (Bcrypt)
        $newHash = password_hash($newPass, PASSWORD_DEFAULT);
        $pdo->prepare("UPDATE users SET password_hash = ? WHERE id = ?")
            ->execute([$newHash, $userId]);

        // Đánh dấu toàn bộ OTP của tài khoản đã sử dụng (chống dùng lại OTP)
        $pdo->prepare("UPDATE password_reset_otps SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL")
            ->execute([$userId]);

        $pdo->commit();

        echo json_encode([
            'success' => true,
            'message' => 'Đặt lại mật khẩu thành công! Bạn có thể đăng nhập bằng mật khẩu mới ngay bây giờ.'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    http_response_code(400);
    echo json_encode(['success' => false, 'error' => "Hành động '{$action}' không hợp lệ."], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error'   => 'Lỗi hệ thống khi xử lý quên mật khẩu: ' . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
