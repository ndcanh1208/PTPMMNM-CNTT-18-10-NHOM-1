<?php
/**
 * Nhom1 Online Judge - Dịch vụ Gửi Email SMTP thuần PHP (Hỗ trợ Gmail TLS/SSL & Log Local)
 * Không phụ thuộc thư viện ngoài; hỗ trợ STARTTLS (587), SSL (465) và AUTH LOGIN.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/mail.php';

class Mailer
{
    /**
     * Xác định chế độ gửi thực tế ('smtp' hay 'log')
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
     * Gửi Email chứa mã OTP 6 chữ số để đặt lại mật khẩu
     *
     * @return array ['success' => bool, 'mode' => string, 'error' => ?string, 'log_path' => ?string]
     */
    public static function sendPasswordResetOtp(string $toEmail, string $fullName, string $otpCode, int $expireMinutes = 10): array
    {
        $subject = "[Nhom1] Mã xác nhận OTP đặt lại mật khẩu: {$otpCode}";
        $safeName = htmlspecialchars($fullName, ENT_QUOTES, 'UTF-8');
        $safeOtp  = htmlspecialchars($otpCode, ENT_QUOTES, 'UTF-8');

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

        $mode = self::resolveMode();
        if ($mode === 'log') {
            return self::writeLocalMailLog($toEmail, $subject, $htmlBody, $otpCode);
        }

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
     * Ghi email ra thư mục temp/mail_logs/ khi chưa cấu hình SMTP trên localhost
     */
    private static function writeLocalMailLog(string $toEmail, string $subject, string $htmlBody, string $otpCode): array
    {
        $dir = __DIR__ . '/../temp/mail_logs';
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }

        $htmlFile = $dir . '/last_otp.html';
        @file_put_contents($htmlFile, $htmlBody);

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
     * Gửi email thật qua giao thức SMTP (STARTTLS / SSL + AUTH LOGIN)
     */
    private static function sendViaSmtp(string $toEmail, string $subject, string $htmlBody): array
    {
        $host = MAIL_HOST;
        $port = (int)MAIL_PORT;
        $encryption = strtolower(MAIL_ENCRYPTION);
        $username = MAIL_USERNAME;
        $password = str_replace(' ', '', MAIL_PASSWORD); // Hỗ trợ Gmail App Password có hoặc không có khoảng trắng
        $fromEmail = MAIL_FROM_EMAIL;
        $fromName  = MAIL_FROM_NAME;

        $remote = ($encryption === 'ssl' ? 'ssl://' : 'tcp://') . $host . ':' . $port;
        $context = stream_context_create([
            'ssl' => [
                'verify_peer'       => false,
                'verify_peer_name'  => false,
                'allow_self_signed' => true
            ]
        ]);

        $errno = 0;
        $errstr = '';
        $fp = @stream_socket_client($remote, $errno, $errstr, 15, STREAM_CLIENT_CONNECT, $context);
        if (!$fp) {
            return ['success' => false, 'error' => "Không thể kết nối máy chủ SMTP ({$host}:{$port}): {$errstr} ({$errno})"];
        }

        stream_set_timeout($fp, 15);

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

        $cmd = function (string $command, array $expectedCodes) use ($fp, $read): array {
            fwrite($fp, $command . "\r\n");
            $resp = $read();
            $code = (int)substr($resp, 0, 3);
            if (!in_array($code, $expectedCodes, true)) {
                return ['ok' => false, 'resp' => trim($resp)];
            }
            return ['ok' => true, 'resp' => trim($resp)];
        };

        // 1. Chào kết nối (220)
        $banner = $read();
        if ((int)substr($banner, 0, 3) !== 220) {
            fclose($fp);
            return ['success' => false, 'error' => 'Máy chủ SMTP phản hồi không hợp lệ: ' . trim($banner)];
        }

        // 2. EHLO
        $ehlo = $cmd('EHLO localhost', [250]);
        if (!$ehlo['ok']) {
            fclose($fp);
            return ['success' => false, 'error' => 'Lỗi EHLO: ' . $ehlo['resp']];
        }

        // 3. Nâng cấp STARTTLS nếu port 587 / tls
        if ($encryption === 'tls') {
            $starttls = $cmd('STARTTLS', [220]);
            if (!$starttls['ok']) {
                fclose($fp);
                return ['success' => false, 'error' => 'Lỗi STARTTLS: ' . $starttls['resp']];
            }
            $cryptoOk = @stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT);
            if (!$cryptoOk) {
                fclose($fp);
                return ['success' => false, 'error' => 'Không thể thiết lập mã hóa TLS với máy chủ SMTP.'];
            }
            $ehlo2 = $cmd('EHLO localhost', [250]);
            if (!$ehlo2['ok']) {
                fclose($fp);
                return ['success' => false, 'error' => 'Lỗi EHLO sau TLS: ' . $ehlo2['resp']];
            }
        }

        // 4. Xác thực AUTH LOGIN
        $auth = $cmd('AUTH LOGIN', [334]);
        if (!$auth['ok']) {
            fclose($fp);
            return ['success' => false, 'error' => 'Máy chủ SMTP từ chối AUTH LOGIN: ' . $auth['resp']];
        }
        $uRes = $cmd(base64_encode($username), [334]);
        if (!$uRes['ok']) {
            fclose($fp);
            return ['success' => false, 'error' => 'Tài khoản SMTP không hợp lệ: ' . $uRes['resp']];
        }
        $pRes = $cmd(base64_encode($password), [235]);
        if (!$pRes['ok']) {
            fclose($fp);
            return ['success' => false, 'error' => 'Xác thực mật khẩu ứng dụng SMTP thất bại. Hãy kiểm tra lại Gmail App Password.'];
        }

        // 5. MAIL FROM & RCPT TO
        $mf = $cmd("MAIL FROM:<{$fromEmail}>", [250]);
        if (!$mf['ok']) {
            fclose($fp);
            return ['success' => false, 'error' => 'Lỗi MAIL FROM: ' . $mf['resp']];
        }
        $rt = $cmd("RCPT TO:<{$toEmail}>", [250, 251]);
        if (!$rt['ok']) {
            fclose($fp);
            return ['success' => false, 'error' => 'Địa chỉ người nhận bị từ chối: ' . $rt['resp']];
        }

        // 6. DATA
        $dt = $cmd('DATA', [354]);
        if (!$dt['ok']) {
            fclose($fp);
            return ['success' => false, 'error' => 'Lỗi lệnh DATA: ' . $dt['resp']];
        }

        $encodedSubject  = '=?UTF-8?B?' . base64_encode($subject) . '?=';
        $encodedFromName = '=?UTF-8?B?' . base64_encode($fromName) . '?=';
        $encodedBody     = chunk_split(base64_encode($htmlBody));

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

        $message = implode("\r\n", $headers) . "\r\n" . $encodedBody . "\r\n.";
        $sendRes = $cmd($message, [250]);
        $cmd('QUIT', [221, 250]);
        fclose($fp);

        if (!$sendRes['ok']) {
            return ['success' => false, 'error' => 'Không gửi được nội dung email: ' . $sendRes['resp']];
        }

        return ['success' => true, 'error' => null];
    }
}
