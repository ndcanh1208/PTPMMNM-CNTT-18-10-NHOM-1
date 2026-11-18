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

// Bật kiểm tra kiểu dữ liệu nghiêm ngặt để đảm bảo an toàn khi ép kiểu tham số
declare(strict_types=1);

// Thiết lập kiểu phản hồi là JSON chuẩn UTF-8 (hiển thị đúng tiếng Việt có dấu)
header('Content-Type: application/json; charset=utf-8');

// Nạp tệp kết nối CSDL (PDO) và lớp Mailer phụ trách gửi email OTP (SMTP hoặc ghi file log cục bộ)
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../core/Mailer.php';

// =========================================================================
// BƯỚC 0: KIỂM TRA PHƯƠNG THỨC HTTP & NHẬN DỮ LIỆU ĐẦU VÀO
// =========================================================================
// Chỉ cho phép phương thức POST (tránh lộ thông tin nhạy cảm như email, OTP, mật khẩu mới trên thanh URL GET)
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    // HTTP 405 Method Not Allowed: phương thức gửi yêu cầu không được hỗ trợ
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Phương thức không hợp lệ (chỉ chấp nhận POST).'], JSON_UNESCAPED_UNICODE);
    exit;
}

// Đọc dữ liệu JSON từ thân request (php://input); nếu không phải JSON thì lấy từ form $_POST
$raw = file_get_contents('php://input');
$input = json_decode((string)$raw, true);
if (!is_array($input)) {
    $input = $_POST;
}

// Lấy tên bước cần thực hiện ('request_otp', 'verify_otp' hoặc 'reset_password')
$action     = trim((string)($input['action'] ?? ''));
// Lấy thông tin nhận diện tài khoản (chấp nhận trường 'identifier' hoặc 'email')
$identifier = trim((string)($input['identifier'] ?? $input['email'] ?? ''));

// Validate đầu vào bắt buộc: không được bỏ trống thông tin định danh tài khoản
if ($identifier === '') {
    // HTTP 400 Bad Request: thiếu tham số bắt buộc
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Vui lòng nhập Email, Tên đăng nhập hoặc Mã sinh viên.'], JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Hàm che bớt ký tự địa chỉ email nhằm bảo mật thông tin cá nhân khi hiển thị ra giao diện.
 * Ví dụ: "nguyenvana@gmail.com" -> "ng********@gmail.com"
 */
function maskEmailAddress(string $email): string {
    // Tách email thành 2 phần: tên hộp thư (trước @) và tên miền (sau @)
    $parts = explode('@', $email);
    if (count($parts) !== 2) {
        return '***';
    }
    $local = $parts[0];
    $domain = $parts[1];
    // Giữ lại tối đa 2 ký tự đầu của phần tên hộp thư, thay phần còn lại bằng ít nhất 3 dấu sao (*)
    $keep = min(2, strlen($local));
    return substr($local, 0, $keep) . str_repeat('*', max(3, strlen($local) - $keep)) . '@' . $domain;
}

// Bọc toàn bộ xử lý CSDL vào try...catch để bắt mọi lỗi phát sinh và hoàn tác transaction nếu cần
try {
    // Mở kết nối PDO tới cơ sở dữ liệu
    $pdo = getDbConnection();

    // =========================================================================
    // TRUY VẤN TÌM TÀI KHOẢN & KIỂM TRA TRẠNG THÁI KHÓA
    // =========================================================================
    // Tìm tài khoản theo Email, Username hoặc MSSV
    // Sử dụng Prepared Statement (:id1, :id2, :id3) để chống tấn công SQL Injection
    // So sánh email không phân biệt chữ hoa/thường bằng LOWER(), còn mã sinh viên chuẩn hóa thành chữ in hoa
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

    // Nếu không tìm thấy bản ghi người dùng nào khớp -> trả về HTTP 404 Not Found
    if (!$user) {
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'error'   => 'Không tìm thấy tài khoản nào khớp với Email / Tên đăng nhập bạn đã nhập.'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Kiểm tra quyền/trạng thái: không cho phép đặt lại mật khẩu đối với tài khoản đang bị khóa (is_locked = 1)
    if ((int)$user['is_locked'] === 1) {
        // HTTP 403 Forbidden: bị từ chối quyền thao tác
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'error'   => 'Tài khoản này hiện đang bị khóa. Vui lòng liên hệ Quản trị viên để được hỗ trợ.'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Lưu lại các biến dùng chung cho cả 3 bước
    $userId = (int)$user['id'];
    $userEmail = (string)$user['email'];
    $maskedEmail = maskEmailAddress($userEmail);

    // =========================================================================
    // BƯỚC 1: TẠO VÀ GỬI MÃ OTP VỀ EMAIL
    // =========================================================================
    if ($action === 'request_otp') {
        // Kiểm tra chống spam gửi lại (Cooldown 60 giây)
        // Lấy thời gian đã trôi qua (tính bằng giây) kể từ lần tạo OTP gần nhất của user này
        $coolStmt = $pdo->prepare("
            SELECT TIMESTAMPDIFF(SECOND, created_at, NOW()) AS elapsed
            FROM password_reset_otps
            WHERE user_id = ?
            ORDER BY id DESC
            LIMIT 1
        ");
        $coolStmt->execute([$userId]);
        $lastOtp = $coolStmt->fetch(PDO::FETCH_ASSOC);
        // Nếu chưa đủ 60 giây kể từ lần yêu cầu trước -> chặn lại với HTTP 429 Too Many Requests
        if ($lastOtp !== false && (int)$lastOtp['elapsed'] < 60 && (int)$lastOtp['elapsed'] >= 0) {
            $wait = 60 - (int)$lastOtp['elapsed'];
            http_response_code(429);
            // Trả về thêm `wait_seconds` để giao diện hiển thị đồng hồ đếm ngược
            echo json_encode([
                'success'      => false,
                'wait_seconds' => $wait,
                'error'        => "Bạn vừa yêu cầu mã OTP. Vui lòng đợi thêm {$wait} giây trước khi gửi lại."
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        // Sinh mã OTP 6 chữ số ngẫu nhiên an toàn mật mã
        // Dùng random_int() (an toàn mật mã hơn rand()) và thêm số '0' ở đầu nếu số nhỏ hơn 100000
        $otpCode = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        // Băm (hash) mã OTP bằng thuật toán Bcrypt trước khi lưu CSDL, tránh lộ mã OTP gốc nếu CSDL bị rò rỉ
        $otpHash = password_hash($otpCode, PASSWORD_DEFAULT);

        // Bắt đầu Transaction: gom bước vô hiệu hóa OTP cũ + lưu OTP mới + gửi mail thành một khối nguyên tử.
        // Nếu gửi mail thất bại sẽ rollBack() toàn bộ để không làm mất hiệu lực OTP cũ một cách vô ích.
        $pdo->beginTransaction();

        // Vô hiệu hóa các mã OTP cũ chưa dùng của tài khoản này
        $pdo->prepare("UPDATE password_reset_otps SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL")
            ->execute([$userId]);

        // Lưu OTP mới (hết hạn sau 10 phút)
        // Cột attempts khởi tạo bằng 0 để đếm số lần nhập sai; expires_at = thời điểm hiện tại + 10 phút
        $insStmt = $pdo->prepare("
            INSERT INTO password_reset_otps (user_id, email, otp_hash, attempts, expires_at, created_at)
            VALUES (?, ?, ?, 0, DATE_ADD(NOW(), INTERVAL 10 MINUTE), NOW())
        ");
        $insStmt->execute([$userId, $userEmail, $otpHash]);
        $otpRowId = (int)$pdo->lastInsertId();

        // Gửi Email chứa mã OTP
        $mailRes = Mailer::sendPasswordResetOtp($userEmail, (string)$user['full_name'], $otpCode, 10);
        // Nếu quá trình gửi email lỗi -> hoàn tác toàn bộ thay đổi trong CSDL và trả về HTTP 500 Internal Server Error
        if (!$mailRes['success']) {
            $pdo->rollBack();
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'error'   => 'Không thể gửi email OTP: ' . ($mailRes['error'] ?? 'Lỗi cấu hình SMTP.')
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        // Gửi mail thành công -> xác nhận lưu vĩnh viễn giao dịch vào CSDL
        $pdo->commit();

        // Phân biệt chế độ chạy localhost (ghi file log HTML) hay gửi SMTP thật để trả thông báo phù hợp
        $isLogMode = ($mailRes['mode'] === 'log');
        $msg = $isLogMode
            ? "Đã tạo mã OTP cho email {$maskedEmail}! (Môi trường localhost chưa cấu hình SMTP Gmail — mở tệp temp/mail_logs/last_otp.html để xem email OTP)."
            : "Đã gửi mã xác nhận OTP 6 chữ số tới email {$maskedEmail}. Vui lòng kiểm tra hộp thư (kể cả mục Spam/Thư rác).";

        // Trả về JSON (HTTP 200 OK) gồm:
        // - masked_email: email đã che để hiển thị
        // - delivery_mode / log_path: chế độ gửi và đường dẫn log (nếu chạy local)
        // - cooldown_seconds / expire_minutes: thời gian chờ gửi lại (60s) và thời hạn OTP (10 phút)
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
    // Xóa mọi khoảng trắng người dùng có thể copy nhầm vào ô OTP, rồi kiểm tra bằng Regex đúng 6 chữ số (0-9)
    $otpInput = preg_replace('/\s+/', '', (string)($input['otp'] ?? ''));
    if (!preg_match('/^\d{6}$/', $otpInput)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Mã OTP phải gồm đúng 6 chữ số.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Truy vấn mã OTP mới nhất chưa được sử dụng (used_at IS NULL) của người dùng này,
    // đồng thời tính luôn cờ `is_expired` ngay trong SQL bằng cách so sánh expires_at với NOW() của máy chủ CSDL
    $otpStmt = $pdo->prepare("
        SELECT id, otp_hash, attempts, expires_at, (expires_at < NOW()) AS is_expired
        FROM password_reset_otps
        WHERE user_id = ? AND used_at IS NULL
        ORDER BY id DESC
        LIMIT 1
    ");
    $otpStmt->execute([$userId]);
    $otpRow = $otpStmt->fetch(PDO::FETCH_ASSOC);

    // Không tìm thấy OTP còn hiệu lực -> yêu cầu người dùng xin mã mới
    if (!$otpRow) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error'   => 'Không có mã OTP nào đang hiệu lực. Vui lòng yêu cầu gửi mã OTP mới.'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Kiểm tra hết hạn (quá 10 phút): nếu hết hạn thì đánh dấu đã dùng (used_at = NOW()) để đóng bản ghi
    if ((int)$otpRow['is_expired'] === 1) {
        $pdo->prepare("UPDATE password_reset_otps SET used_at = NOW() WHERE id = ?")->execute([(int)$otpRow['id']]);
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error'   => 'Mã OTP đã hết hạn (quá 10 phút). Vui lòng yêu cầu gửi lại mã mới.'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Kiểm tra chống dò mã (Brute-force): tối đa 5 lần nhập sai cho mỗi mã OTP
    if ((int)$otpRow['attempts'] >= 5) {
        $pdo->prepare("UPDATE password_reset_otps SET used_at = NOW() WHERE id = ?")->execute([(int)$otpRow['id']]);
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error'   => 'Bạn đã nhập sai mã OTP quá 5 lần. Mã OTP đã bị hủy, vui lòng gửi mã mới.'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Đối chiếu mã OTP người dùng nhập với chuỗi băm lưu trong CSDL bằng password_verify()
    if (!password_verify($otpInput, (string)$otpRow['otp_hash'])) {
        // Nhập sai -> tăng bộ đếm attempts lên 1 và tính số lần thử còn lại
        $newAttempts = (int)$otpRow['attempts'] + 1;
        $remaining = max(0, 5 - $newAttempts);
        if ($remaining === 0) {
            // Hết lượt thử -> hủy luôn mã OTP này bằng cách cập nhật used_at = NOW()
            $pdo->prepare("UPDATE password_reset_otps SET attempts = ?, used_at = NOW() WHERE id = ?")
                ->execute([$newAttempts, (int)$otpRow['id']]);
            $errMsg = 'Mã OTP không chính xác và đã vượt quá 5 lần thử. Vui lòng yêu cầu mã OTP mới.';
        } else {
            // Còn lượt thử -> chỉ cập nhật số lần nhập sai attempts
            $pdo->prepare("UPDATE password_reset_otps SET attempts = ? WHERE id = ?")
                ->execute([$newAttempts, (int)$otpRow['id']]);
            $errMsg = "Mã OTP không chính xác. Bạn còn {$remaining} lần thử.";
        }
        // Trả về HTTP 400 kèm `remaining_attempts` để frontend thông báo số lần thử còn lại
        http_response_code(400);
        echo json_encode(['success' => false, 'remaining_attempts' => $remaining, 'error' => $errMsg], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // =========================================================================
    // BƯỚC 2: XÁC THỰC OTP HỢP LỆ
    // =========================================================================
    // Khi mã OTP đúng và action là 'verify_otp': chưa hủy OTP ngay mà cho phép giao diện chuyển sang bước nhập mật khẩu mới
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
        // Lấy mật khẩu mới và mật khẩu xác nhận từ dữ liệu gửi lên
        $newPass     = (string)($input['new_password'] ?? '');
        $confirmPass = (string)($input['confirm_password'] ?? '');

        // Validate mật khẩu mới: tối thiểu 6 ký tự và phải trùng khớp với ô xác nhận
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

        // Dùng Transaction để đảm bảo đổi mật khẩu và hủy OTP cùng thành công hoặc cùng thất bại (tránh OTP dùng lại nhiều lần)
        $pdo->beginTransaction();

        // Cập nhật mật khẩu mới (Bcrypt)
        $newHash = password_hash($newPass, PASSWORD_DEFAULT);
        $pdo->prepare("UPDATE users SET password_hash = ? WHERE id = ?")
            ->execute([$newHash, $userId]);

        // Đánh dấu toàn bộ OTP của tài khoản đã sử dụng (chống dùng lại OTP)
        $pdo->prepare("UPDATE password_reset_otps SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL")
            ->execute([$userId]);

        // Hoàn tất Transaction
        $pdo->commit();

        // Trả về JSON (HTTP 200 OK) báo đặt lại mật khẩu thành công
        echo json_encode([
            'success' => true,
            'message' => 'Đặt lại mật khẩu thành công! Bạn có thể đăng nhập bằng mật khẩu mới ngay bây giờ.'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Trường hợp tham số action không khớp với bất kỳ bước nào ở trên
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => "Hành động '{$action}' không hợp lệ."], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    // Nếu có lỗi ngoại lệ xảy ra khi đang mở Transaction thì hoàn tác (rollBack) để giữ dữ liệu nhất quán
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    // Trả về HTTP 500 Internal Server Error kèm thông điệp lỗi hệ thống
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error'   => 'Lỗi hệ thống khi xử lý quên mật khẩu: ' . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
