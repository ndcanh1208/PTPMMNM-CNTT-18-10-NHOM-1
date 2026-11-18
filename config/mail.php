<?php
/**
 * ==============================================================================
 * Nhom1 Online Judge - Cấu hình Dịch vụ Gửi Email (SMTP / Local Log)
 * ==============================================================================
 * Mục đích của file này:
 * - Lưu trữ các hằng số cấu hình máy chủ gửi thư (SMTP) phục vụ gửi mã OTP xác thực, quên mật khẩu...
 * - Hỗ trợ tách riêng thông tin mật khẩu thật sang file `config/mail.local.php` để đảm bảo bảo mật khi đẩy code lên GitHub.
 * - Nếu chưa cấu hình tài khoản SMTP, hệ thống tự chuyển sang chế độ ghi log cục bộ để lập trình viên vẫn test được trên localhost.
 * 
 * Hướng dẫn bật gửi Email thật qua Gmail:
 * 1. Tạo tệp config/mail.local.php (đã nằm trong .gitignore để không lộ mật khẩu lên GitHub)
 *    hoặc điền trực tiếp vào các hằng số bên dưới:
 *    <?php
 *    define('MAIL_HOST', 'smtp.gmail.com');
 *    define('MAIL_PORT', 587);
 *    define('MAIL_ENCRYPTION', 'tls');
 *    define('MAIL_USERNAME', 'email_cua_ban@gmail.com');
 *    define('MAIL_PASSWORD', 'xxxx xxxx xxxx xxxx'); // Mật khẩu ứng dụng Gmail (App Password 16 ký tự)
 *    define('MAIL_FROM_EMAIL', 'email_cua_ban@gmail.com');
 *    define('MAIL_FROM_NAME', 'Nhom1 Online Judge');
 * 
 * 2. Khi MAIL_USERNAME và MAIL_PASSWORD để trống, hệ thống tự chạy chế độ 'log' trên localhost:
 *    Lưu email + mã OTP vào thư mục temp/mail_logs/ để kiểm thử không cần cấu hình SMTP.
 */

// Bật chế độ kiểm tra kiểu dữ liệu nghiêm ngặt trong PHP
declare(strict_types=1);

// ==============================================================================
// PHẦN 1: NẠP FILE CẤU HÌNH CỤC BỘ (NẾU CÓ)
// ==============================================================================
// __DIR__ trả về đường dẫn tuyệt đối đến thư mục hiện tại (thư mục config/).
// Nếu file config/mail.local.php tồn tại, nạp nó trước để các hằng số bên trong được ưu tiên sử dụng.
if (file_exists(__DIR__ . '/mail.local.php')) {
    require_once __DIR__ . '/mail.local.php';
}

// ==============================================================================
// PHẦN 2: KHAI BÁO CÁC HẰNG SỐ CẤU HÌNH EMAIL MẶC ĐỊNH
// ==============================================================================
// Lưu ý cách viết `if (!defined('TÊN_HẰNG'))`:
// Chỉ định nghĩa hằng số nếu nó CHƯA được định nghĩa trước đó trong file mail.local.php (tránh lỗi khai báo trùng).
// Thứ tự ưu tiên giá trị: mail.local.php -> Biến môi trường (getenv) -> Giá trị mặc định dự phòng.

// Địa chỉ máy chủ SMTP gửi thư (mặc định dùng máy chủ SMTP của Gmail)
if (!defined('MAIL_HOST')) {
    define('MAIL_HOST', getenv('MAIL_HOST') ?: 'smtp.gmail.com');
}

// Cổng (Port) kết nối đến máy chủ SMTP. Ép kiểu sang số nguyên (int).
// Thông thường: cổng 587 dùng cho mã hóa TLS, cổng 465 dùng cho mã hóa SSL.
if (!defined('MAIL_PORT')) {
    define('MAIL_PORT', (int)(getenv('MAIL_PORT') ?: 587));
}

// Giao thức mã hóa bảo mật đường truyền khi gửi email ('tls' đi với cổng 587, hoặc 'ssl' đi với cổng 465)
if (!defined('MAIL_ENCRYPTION')) {
    define('MAIL_ENCRYPTION', getenv('MAIL_ENCRYPTION') ?: 'tls'); // 'tls' (587) hoặc 'ssl' (465)
}

// Tài khoản email dùng để đăng nhập vào máy chủ SMTP (mặc định rỗng nếu chưa cấu hình)
if (!defined('MAIL_USERNAME')) {
    define('MAIL_USERNAME', getenv('MAIL_USERNAME') ?: '');
}

// Mật khẩu đăng nhập SMTP (với Gmail phải dùng App Password 16 ký tự chứ không dùng mật khẩu đăng nhập thường)
if (!defined('MAIL_PASSWORD')) {
    define('MAIL_PASSWORD', getenv('MAIL_PASSWORD') ?: '');
}

// Địa chỉ email hiển thị ở mục "Người gửi" (From).
// Nếu không đặt riêng, hệ thống tự lấy theo MAIL_USERNAME; nếu vẫn rỗng thì dùng 'no-reply@nhom1.local'.
if (!defined('MAIL_FROM_EMAIL')) {
    define('MAIL_FROM_EMAIL', getenv('MAIL_FROM_EMAIL') ?: (MAIL_USERNAME ?: 'no-reply@nhom1.local'));
}

// Tên người gửi hiển thị trong hộp thư đến của người nhận
if (!defined('MAIL_FROM_NAME')) {
    define('MAIL_FROM_NAME', getenv('MAIL_FROM_NAME') ?: 'Nhom1 Online Judge');
}

// Chế độ hoạt động của dịch vụ gửi mail:
// - 'auto': Tự động kiểm tra, có username/password thì gửi SMTP thật, không thì ghi ra file log
// - 'smtp': Luôn gửi thật qua máy chủ SMTP
// - 'log':  Chỉ ghi nội dung email ra file văn bản trong thư mục tạm để test
if (!defined('MAIL_MODE')) {
    define('MAIL_MODE', getenv('MAIL_MODE') ?: 'auto'); // 'auto' | 'smtp' | 'log'
}