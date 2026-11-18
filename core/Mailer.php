<?php
/**
 * Nhom1 Online Judge - Dịch vụ Gửi Email SMTP thuần PHP (Hỗ trợ Gmail TLS/SSL & Log Local)
 *
 * Mục đích của tệp này:
 * - Gửi email chứa mã xác thực OTP 6 chữ số phục vụ chức năng "Quên mật khẩu".
 * - Tự cài đặt giao thức SMTP (Simple Mail Transfer Protocol) bằng socket thuần PHP,
 *   KHÔNG cần cài đặt thư viện ngoài (như PHPMailer hay Composer), giúp dự án gọn nhẹ và chạy ngay trên XAMPP.
 * - Hỗ trợ đầy đủ 2 cơ chế mã hóa bảo mật phổ biến nhất của máy chủ thư (như Gmail):
 *   + STARTTLS trên cổng 587 (kết nối TCP thường rồi nâng cấp lên TLS 1.2/1.3).
 *   + SSL/TLS ngầm định trên cổng 465 (mã hóa ngay từ lúc mở kết nối socket `ssl://`).
 * - Hỗ trợ chế độ ghi log cục bộ (`log`) vào `temp/mail_logs/` để lập trình viên dễ dàng kiểm thử
 *   chức năng Quên mật khẩu trên localhost ngay cả khi chưa cấu hình tài khoản Gmail SMTP.
 */

// Bật kiểm tra kiểu dữ liệu nghiêm ngặt
declare(strict_types=1);

// Nạp tệp cấu hình hằng số email: MAIL_MODE, MAIL_HOST, MAIL_PORT, MAIL_ENCRYPTION, MAIL_USERNAME, MAIL_PASSWORD...
require_once __DIR__ . '/../config/mail.php';

/**
 * Lớp Mailer
 *
 * Cung cấp phương thức gửi email OTP đặt lại mật khẩu, tự động chuyển đổi giữa chế độ SMTP thật và chế độ ghi log local.
 */
class Mailer
{
    /**
     * Xác định chế độ gửi email thực tế ('smtp' hay 'log') dựa trên cấu hình trong `config/mail.php`.
     *
     * Quy tắc quyết định:
     * - Nếu MAIL_MODE === 'log'  -> luôn ghi ra tệp cục bộ (không kết nối Internet).
     * - Nếu MAIL_MODE === 'smtp' -> luôn gửi thật qua máy chủ SMTP.
     * - Nếu MAIL_MODE === 'auto' -> tự động dùng 'smtp' khi đã điền đủ tài khoản & mật khẩu ứng dụng;
     *                               ngược lại tự lùi về 'log' để không làm lỗi ứng dụng khi chạy thử local.
     *
     * @return string 'smtp' hoặc 'log'.
     */
    public static function resolveMode(): string
    {
        if (MAIL_MODE === 'log') {
            return 'log';
        }
        if (MAIL_MODE === 'smtp') {
            return 'smtp';
        }
        // Chế độ 'auto': dùng SMTP nếu đã cấu hình đủ MAIL_USERNAME & MAIL_PASSWORD
        return (trim(MAIL_USERNAME) !== '' && trim(MAIL_PASSWORD) !== '') ? 'smtp' : 'log';
    }

    /**
     * Tạo nội dung HTML đẹp mắt và gửi Email chứa mã OTP 6 chữ số để đặt lại mật khẩu.
     *
     * @param string $toEmail       Địa chỉ email người nhận.
     * @param string $fullName      Họ và tên người nhận (dùng để chào cá nhân hóa trong nội dung thư).
     * @param string $otpCode       Mã xác nhận OTP 6 chữ số.
     * @param int    $expireMinutes Thời gian hiệu lực của mã OTP tính theo phút (mặc định 10 phút).
     * @return array Mảng kết quả gồm 4 khóa:
     *               - 'success'  (bool)   : true nếu gửi/ghi log thành công, false nếu có lỗi.
     *               - 'mode'     (string) : 'smtp' hoặc 'log'.
     *               - 'error'    (?string): Thông báo lỗi chi tiết (nếu thất bại).
     *               - 'log_path' (?string): Đường dẫn tương đối tới tệp HTML xem trước (nếu chạy chế độ 'log').
     */
    public static function sendPasswordResetOtp(string $toEmail, string $fullName, string $otpCode, int $expireMinutes = 10): array
    {
        // Tiêu đề email có gắn sẵn mã OTP để người dùng nhìn thấy nhanh trên thông báo điện thoại
        $subject = "[Nhom1] Mã xác nhận OTP đặt lại mật khẩu: {$otpCode}";
        // Mã hóa HTML các biến chèn vào mẫu email để chống chèn mã HTML/XSS độc hại vào thư
        $safeName = htmlspecialchars($fullName, ENT_QUOTES, 'UTF-8');
        $safeOtp  = htmlspecialchars($otpCode, ENT_QUOTES, 'UTF-8');

        // Tạo thân email bằng cú pháp Heredoc (<<<HTML ... HTML;) giúp viết mã HTML nhiều dòng sạch sẽ.
        // Dùng CSS nội tuyến (inline style) vì hầu hết trình đọc email (Gmail, Outlook) chặn thẻ <style> ngoài.
        $htmlBody = <<<HTML
<!DOCTYPE html>
<html lang="vi">
<head><meta charset="UTF-8"></head>
<body style="margin:0;padding:24px;background-color:#0b0f19;font-family:Arial,Helvetica,sans-serif;color:#e2e8f0;">
  <div style="max-width:520px;margin:0 auto;background-color:#121826;border:1px solid #1e293b;border-radius:16px;padding:32px;">
    <div style="text-align:center;margin-bottom:24px;">
      <span style="display:inline-block;padding:8px 16px;border-radius:10px;background:#06b6d4;color:#050507;font-weight:900;font-size:18px;letter-spacing:1px;">Nhom1 Online Judge</span>
    </div>
    <h2 style="margin:0 0 12px 0;color:#ffffff;font-size:20px;">Yêu cầu đặt lại mật khẩu</h2>
    <p style="margin:0 0 16px 0;font-size:14px;line-height:1.6;color:#cbd5e1;">
      Xin chào <strong>{$safeName}</strong>,<br>
      Hệ thống vừa nhận được yêu cầu đặt lại mật khẩu cho tài khoản của bạn. Vui lòng nhập mã xác nhận OTP dưới đây để tiếp tục:
    </p>
    <div style="margin:24px 0;padding:20px;background-color:#050811;border:1px dashed #22d3ee;border-radius:12px;text-align:center;">
      <div style="font-size:11px;text-transform:uppercase;letter-spacing:2px;color:#94a3b8;margin-bottom:8px;">Mã xác nhận OTP của bạn</div>
      <div style="font-size:36px;font-weight:900;letter-spacing:10px;color:#22d3ee;font-family:'Courier New',monospace;">{$safeOtp}</div>
    </div>
    <p style="margin:0 0 12px 0;font-size:13px;color:#fbbf24;">
      ⏱ Mã OTP có hiệu lực trong vòng <strong>{$expireMinutes} phút</strong> và chỉ sử dụng được một lần duy nhất (tối đa 5 lần nhập sai).
    </p>
    <p style="margin:0;font-size:12px;color:#64748b;line-height:1.5;">
      Nếu bạn không thực hiện yêu cầu này, vui lòng bỏ qua email và tuyệt đối không chia sẻ mã OTP cho bất kỳ ai.
    </p>
    <hr style="border:none;border-top:1px solid #1e293b;margin:24px 0;">
    <div style="text-align:center;font-size:11px;color:#475569;">
      Nhom1 Online Judge &copy; Hệ thống Luyện tập &amp; Chấm bài Trực tuyến
    </div>
  </div>
</body>
</html>
HTML;

        // Xác định chế độ gửi: nếu là 'log' thì lưu ra đĩa thay vì kết nối SMTP
        $mode = self::resolveMode();
        if ($mode === 'log') {
            return self::writeLocalMailLog($toEmail, $subject, $htmlBody, $otpCode);
        }

        // Gửi email thật qua giao thức SMTP
        $res = self::sendViaSmtp($toEmail, $subject, $htmlBody);
        if (!$res['success']) {
            return [
                'success'  => false,
                'mode'     => 'smtp',
                'error'    => $res['error'],
                'log_path' => null
            ];
        }

        return [
            'success'  => true,
            'mode'     => 'smtp',
            'error'    => null,
            'log_path' => null
        ];
    }

    /**
     * Ghi email ra thư mục cục bộ `temp/mail_logs/` khi chạy chế độ 'log' trên localhost.
     *
     * Tạo/cập nhật 2 tệp để hỗ trợ kiểm thử thuận tiện:
     * - `temp/mail_logs/last_otp.html`: tệp HTML email gần nhất (có thể mở thẳng trên trình duyệt để xem).
     * - `temp/mail_logs/mail.log`: nhật ký dạng văn bản ghi nối tiếp (FILE_APPEND) toàn bộ lịch sử OTP đã tạo.
     *
     * @param string $toEmail  Email người nhận.
     * @param string $subject  Tiêu đề thư.
     * @param string $htmlBody Toàn bộ nội dung HTML của thư.
     * @param string $otpCode  Mã OTP để ghi nhanh vào dòng log.
     * @return array Kết quả ghi log thành công kèm đường dẫn tệp HTML xem trước.
     */
    private static function writeLocalMailLog(string $toEmail, string $subject, string $htmlBody, string $otpCode): array
    {
        $dir = __DIR__ . '/../temp/mail_logs';
        // Tự động tạo thư mục lưu log mail nếu chưa tồn tại
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }

        // Ghi đè tệp HTML xem trước của email OTP mới nhất
        $htmlFile = $dir . '/last_otp.html';
        @file_put_contents($htmlFile, $htmlBody);

        // Định dạng 1 dòng nhật ký có dấu thời gian và ghi nối tiếp vào cuối tệp mail.log
        $logLine = sprintf(
            "[%s] TO: %s | SUBJECT: %s | OTP: %s\n",
            date('Y-m-d H:i:s'),
            $toEmail,
            $subject,
            $otpCode
        );
        @file_put_contents($dir . '/mail.log', $logLine, FILE_APPEND);

        return [
            'success'  => true,
            'mode'     => 'log',
            'error'    => null,
            'log_path' => 'temp/mail_logs/last_otp.html'
        ];
    }

    /**
     * Gửi email thật qua giao thức SMTP bằng kết nối socket trực tiếp (hỗ trợ STARTTLS / SSL + AUTH LOGIN).
     *
     * Tổng quan quy trình "bắt tay" (handshake) chuẩn của giao thức SMTP (RFC 5321):
     * - Kết nối TCP (cổng 587 với STARTTLS) hoặc SSL (cổng 465 với SSL/TLS ngầm định).
     * - Bước 1: Đọc lời chào (banner) mã `220` từ máy chủ SMTP.
     * - Bước 2: Gửi lệnh `EHLO localhost` để giới thiệu máy khách và nhận danh sách tính năng hỗ trợ (mã `250`).
     * - Bước 3 (nếu dùng STARTTLS): Gửi lệnh `STARTTLS` (`220`), bật mã hóa TLS 1.2/1.3 trên socket,
     *           sau đó gửi lại `EHLO localhost` lần 2 trên kênh đã mã hóa.
     * - Bước 4: Xác thực `AUTH LOGIN` (`334`), gửi username mã hóa Base64 (`334`), gửi password mã hóa Base64 (`235`).
     * - Bước 5: Khai báo phong bì thư bằng `MAIL FROM:<...>` (`250`) và `RCPT TO:<...>` (`250`/`251`).
     * - Bước 6: Gửi lệnh `DATA` (`354`), truyền các tiêu đề MIME + nội dung HTML đã mã hóa Base64,
     *           kết thúc bằng dấu chấm đơn độc trên một dòng (`\r\n.`), nhận mã `250`, rồi gửi `QUIT` và đóng socket.
     *
     * @param string $toEmail  Địa chỉ email người nhận.
     * @param string $subject  Tiêu đề email (hỗ trợ tiếng Việt UTF-8).
     * @param string $htmlBody Nội dung HTML của email.
     * @return array ['success' => bool, 'error' => ?string]
     */
    private static function sendViaSmtp(string $toEmail, string $subject, string $htmlBody): array
    {
        // Đọc thông số máy chủ SMTP từ các hằng số cấu hình trong config/mail.php
        $host = MAIL_HOST;
        $port = (int)MAIL_PORT;
        $encryption = strtolower(MAIL_ENCRYPTION);
        $username = MAIL_USERNAME;
        // Xóa bỏ khoảng trắng nếu người dùng copy Gmail App Password dạng "abcd efgh ijkl mnop"
        $password = str_replace(' ', '', MAIL_PASSWORD); // Hỗ trợ Gmail App Password có hoặc không có khoảng trắng
        $fromEmail = MAIL_FROM_EMAIL;
        $fromName  = MAIL_FROM_NAME;

        // Chọn giao thức kết nối ban đầu:
        // - Nếu cấu hình 'ssl' (thường là cổng 465): dùng 'ssl://' để mã hóa ngay từ lúc thiết lập kết nối.
        // - Nếu cấu hình 'tls' (thường là cổng 587): mở kết nối 'tcp://' trước rồi nâng cấp bằng STARTTLS ở bước 3.
        $remote = ($encryption === 'ssl' ? 'ssl://' : 'tcp://') . $host . ':' . $port;

        // Tạo ngữ cảnh SSL: tắt kiểm tra chứng chỉ nghiêm ngặt để tương thích với môi trường XAMPP local
        // (nơi PHP thường chưa cấu hình sẵn tệp CA certificate bundle cacert.pem).
        $context = stream_context_create([
            'ssl' => [
                'verify_peer'       => false,
                'verify_peer_name'  => false,
                'allow_self_signed' => true
            ]
        ]);

        // Mở kết nối socket mạng tới máy chủ SMTP với thời gian chờ kết nối tối đa 15 giây
        $errno = 0;
        $errstr = '';
        $fp = @stream_socket_client($remote, $errno, $errstr, 15, STREAM_CLIENT_CONNECT, $context);
        if (!$fp) {
            return ['success' => false, 'error' => "Không thể kết nối máy chủ SMTP ({$host}:{$port}): {$errstr} ({$errno})"];
        }

        // Đặt giới hạn thời gian đọc/ghi trên socket là 15 giây để tránh treo tiến trình nếu mạng bị đứt giữa chừng
        stream_set_timeout($fp, 15);

        // Hàm ẩn danh (Closure) $read: Đọc trọn vẹn phản hồi từ máy chủ SMTP.
        // Quy ước của giao thức SMTP cho phản hồi nhiều dòng (multi-line response):
        // - Các dòng trung gian có dấu gạch nối '-' ở vị trí ký tự thứ 4 (chỉ số 3), ví dụ: "250-SIZE 35882577"
        // - Dòng cuối cùng có dấu cách ' ' ở vị trí ký tự thứ 4 (chỉ số 3), ví dụ: "250 SMTPUTF8"
        // Do đó vòng lặp đọc từng dòng (tối đa 515 byte) và dừng khi gặp `$line[3] === ' '`.
        $read = function () use ($fp): string {
            $resp = '';
            while ($line = fgets($fp, 515)) {
                $resp .= $line;
                if (isset($line[3]) && $line[3] === ' ') {
                    break;
                }
            }
            return $resp;
        };

        // Hàm ẩn danh (Closure) $cmd: Gửi 1 lệnh SMTP kết thúc bằng CRLF ("\r\n"), đọc phản hồi,
        // tách mã trạng thái 3 chữ số đầu tiên và kiểm tra xem có nằm trong danh sách mã kỳ vọng ($expectedCodes) không.
        $cmd = function (string $command, array $expectedCodes) use ($fp, $read): array {
            fwrite($fp, $command . "\r\n");
            $resp = $read();
            $code = (int)substr($resp, 0, 3);
            if (!in_array($code, $expectedCodes, true)) {
                return ['ok' => false, 'resp' => trim($resp)];
            }
            return ['ok' => true, 'resp' => trim($resp)];
        };

        // Bước 1: Đọc lời chào kết nối ban đầu từ máy chủ (kỳ vọng mã 220 - Service ready)
        $banner = $read();
        if ((int)substr($banner, 0, 3) !== 220) {
            fclose($fp);
            return ['success' => false, 'error' => 'Máy chủ SMTP phản hồi không hợp lệ: ' . trim($banner)];
        }

        // Bước 2: Gửi lệnh chào mở rộng EHLO để khởi đầu phiên giao tiếp SMTP (kỳ vọng mã 250)
        $ehlo = $cmd('EHLO localhost', [250]);
        if (!$ehlo['ok']) {
            fclose($fp);
            return ['success' => false, 'error' => 'Lỗi EHLO: ' . $ehlo['resp']];
        }

        // Bước 3: Nâng cấp kênh truyền sang mã hóa TLS (STARTTLS) nếu dùng cổng 587 / chế độ 'tls'
        if ($encryption === 'tls') {
            // Yêu cầu máy chủ sẵn sàng chuyển sang TLS (mã 220 - Ready to start TLS)
            $starttls = $cmd('STARTTLS', [220]);
            if (!$starttls['ok']) {
                fclose($fp);
                return ['success' => false, 'error' => 'Lỗi STARTTLS: ' . $starttls['resp']];
            }
            // Thực hiện bắt tay mã hóa TLS 1.2 hoặc TLS 1.3 ngay trên socket hiện tại
            $cryptoOk = @stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT);
            if (!$cryptoOk) {
                fclose($fp);
                return ['success' => false, 'error' => 'Không thể thiết lập mã hóa TLS với máy chủ SMTP.'];
            }
            // Theo chuẩn SMTP, sau khi bật TLS phải gửi lại lệnh EHLO để máy chủ công bố lại các phương thức AUTH an toàn
            $ehlo2 = $cmd('EHLO localhost', [250]);
            if (!$ehlo2['ok']) {
                fclose($fp);
                return ['success' => false, 'error' => 'Lỗi EHLO sau TLS: ' . $ehlo2['resp']];
            }
        }

        // Bước 4: Xác thực tài khoản SMTP bằng cơ chế AUTH LOGIN (mã hóa Base64 từng bước)
        // 4a. Gửi lệnh AUTH LOGIN -> máy chủ trả 334 yêu cầu gửi Username
        $auth = $cmd('AUTH LOGIN', [334]);
        if (!$auth['ok']) {
            fclose($fp);
            return ['success' => false, 'error' => 'Máy chủ SMTP từ chối AUTH LOGIN: ' . $auth['resp']];
        }
        // 4b. Gửi tên tài khoản SMTP đã mã hóa Base64 -> máy chủ trả 334 yêu cầu gửi Password
        $uRes = $cmd(base64_encode($username), [334]);
        if (!$uRes['ok']) {
            fclose($fp);
            return ['success' => false, 'error' => 'Tài khoản SMTP không hợp lệ: ' . $uRes['resp']];
        }
        // 4c. Gửi mật khẩu ứng dụng đã mã hóa Base64 -> máy chủ trả 235 xác nhận đăng nhập thành công
        $pRes = $cmd(base64_encode($password), [235]);
        if (!$pRes['ok']) {
            fclose($fp);
            return ['success' => false, 'error' => 'Xác thực mật khẩu ứng dụng SMTP thất bại. Hãy kiểm tra lại Gmail App Password.'];
        }

        // Bước 5: Khai báo địa chỉ người gửi (MAIL FROM) và người nhận (RCPT TO)
        $mf = $cmd("MAIL FROM:<{$fromEmail}>", [250]);
        if (!$mf['ok']) {
            fclose($fp);
            return ['success' => false, 'error' => 'Lỗi MAIL FROM: ' . $mf['resp']];
        }
        // Mã 250 (OK) hoặc 251 (User not local; will forward) đều được chấp nhận
        $rt = $cmd("RCPT TO:<{$toEmail}>", [250, 251]);
        if (!$rt['ok']) {
            fclose($fp);
            return ['success' => false, 'error' => 'Địa chỉ người nhận bị từ chối: ' . $rt['resp']];
        }

        // Bước 6: Gửi nội dung email bằng lệnh DATA (kỳ vọng mã 354 - Start mail input)
        $dt = $cmd('DATA', [354]);
        if (!$dt['ok']) {
            fclose($fp);
            return ['success' => false, 'error' => 'Lỗi lệnh DATA: ' . $dt['resp']];
        }

        // Mã hóa tiêu đề và tên người gửi theo chuẩn RFC 2047 (`=?UTF-8?B?...?=`)
        // để chữ tiếng Việt có dấu hiển thị chính xác trên mọi hộp thư.
        $encodedSubject  = '=?UTF-8?B?' . base64_encode($subject) . '?=';
        $encodedFromName = '=?UTF-8?B?' . base64_encode($fromName) . '?=';
        // Mã hóa toàn bộ nội dung HTML sang Base64 và tự động ngắt dòng mỗi 76 ký tự bằng chunk_split() (chuẩn RFC 2045)
        $encodedBody     = chunk_split(base64_encode($htmlBody));

        // Tạo các tiêu đề chuẩn MIME cho email HTML mã hóa UTF-8 + Base64
        $headers = [
            "Date: " . date('r'),
            "From: {$encodedFromName} <{$fromEmail}>",
            "To: <{$toEmail}>",
            "Subject: {$encodedSubject}",
            "MIME-Version: 1.0",
            "Content-Type: text/html; charset=UTF-8",
            "Content-Transfer-Encoding: base64",
            ""
        ];

        // Ghép tiêu đề + thân thư và kết thúc bằng "\r\n." (dấu chấm đứng riêng trên 1 dòng báo hiệu kết thúc lệnh DATA)
        $message = implode("\r\n", $headers) . "\r\n" . $encodedBody . "\r\n.";
        // Gửi toàn bộ bức thư và kỳ vọng máy chủ trả mã 250 xác nhận đã tiếp nhận để chuyển phát
        $sendRes = $cmd($message, [250]);
        // Lịch sự gửi lệnh QUIT kết thúc phiên SMTP và đóng kết nối socket
        $cmd('QUIT', [221, 250]);
        fclose($fp);

        if (!$sendRes['ok']) {
            return ['success' => false, 'error' => 'Không gửi được nội dung email: ' . $sendRes['resp']];
        }

        return ['success' => true, 'error' => null];
    }
}
