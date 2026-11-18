<?php
/**
 * Nhom1 Online Judge - Cấu hình Dịch vụ Gửi Email (SMTP / Local Log)
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

declare(strict_types=1);

if (file_exists(__DIR__ . '/mail.local.php')) {
    require_once __DIR__ . '/mail.local.php';
}

if (!defined('MAIL_HOST')) {
    define('MAIL_HOST', getenv('MAIL_HOST') ?: 'smtp.gmail.com');
}
if (!defined('MAIL_PORT')) {
    define('MAIL_PORT', (int)(getenv('MAIL_PORT') ?: 587));
}
if (!defined('MAIL_ENCRYPTION')) {
    define('MAIL_ENCRYPTION', getenv('MAIL_ENCRYPTION') ?: 'tls'); // 'tls' (587) hoặc 'ssl' (465)
}
if (!defined('MAIL_USERNAME')) {
    define('MAIL_USERNAME', getenv('MAIL_USERNAME') ?: '');
}
if (!defined('MAIL_PASSWORD')) {
    define('MAIL_PASSWORD', getenv('MAIL_PASSWORD') ?: '');
}
if (!defined('MAIL_FROM_EMAIL')) {
    define('MAIL_FROM_EMAIL', getenv('MAIL_FROM_EMAIL') ?: (MAIL_USERNAME ?: 'no-reply@nhom1.local'));
}
if (!defined('MAIL_FROM_NAME')) {
    define('MAIL_FROM_NAME', getenv('MAIL_FROM_NAME') ?: 'Nhom1 Online Judge');
}
if (!defined('MAIL_MODE')) {
    define('MAIL_MODE', getenv('MAIL_MODE') ?: 'auto'); // 'auto' | 'smtp' | 'log'
}
