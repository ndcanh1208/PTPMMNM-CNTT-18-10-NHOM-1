<?php
/**
 * ByteFlow Online Judge - Module Bảo mật & Lọc Dữ liệu Nhạy cảm
 * Tuân thủ Nguyên tắc 4 (MASTER_PLAN.md) và Phép thử 5 (Rubric)
 */

declare(strict_types=1);

class Security
{
    /**
     * Escape chuỗi hiển thị HTML để chống XSS
     */
    public static function escape(?string $str): string
    {
        if ($str === null) {
            return '';
        }
        return htmlspecialchars($str, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * Lọc sạch đường dẫn file nhạy cảm trên máy chủ khỏi thông báo lỗi
     * Chuyển các đường dẫn như 'E:\Phat_Trien...\' hoặc 'C:\xampp\...' thành tên file ngắn
     */
    public static function sanitizeErrorMessage(?string $message): string
    {
        if ($message === null || $message === '') {
            return '';
        }

        // Loại bỏ đường dẫn tuyệt đối Windows và Linux
        $sanitized = preg_replace('/[A-Za-z]:\\\\[^\s\r\n\'"]+\\\\([^\s\r\n\'"]+)/', '$1', $message);
        $sanitized = preg_replace('/\/[^\s\r\n\'"]+\/([^\s\r\n\'"]+)/', '$1', $sanitized);

        // Giới hạn độ dài lỗi trả về (tránh spam bộ nhớ hoặc DoS client)
        if (strlen($sanitized) > 4000) {
            $sanitized = substr($sanitized, 0, 4000) . "\n... [Nội dung lỗi bị cắt ngắn vì vượt quá giới hạn]";
        }

        return $sanitized;
    }

    /**
     * Lọc dữ liệu kết quả chấm trước khi trả về cho Client
     * ĐẢM BẢO TUYỆT ĐỐI KHÔNG LỘ DỮ LIỆU CHẤM KÍN (is_hidden = 1)
     *
     * @param array $caseResults Mảng các kết quả ca kiểm thử
     * @param bool $isPrivileged True nếu là Admin hoặc Giảng viên quản lý bài tập
     * @return array
     */
    public static function sanitizeCaseResultsForUser(array $caseResults, bool $isPrivileged = false): array
    {
        $sanitizedList = [];

        foreach ($caseResults as $case) {
            $isHidden = (bool)($case['is_hidden'] ?? false);
            $isSample = (bool)($case['is_sample'] ?? false);

            $cleanCase = [
                'id'                => $case['id'] ?? null,
                'case_order'        => $case['case_order'] ?? null,
                'verdict'           => $case['verdict'] ?? 'SE',
                'execution_time_ms' => (int)($case['execution_time_ms'] ?? 0),
                'memory_used_kb'    => (int)($case['memory_used_kb'] ?? 0),
                'is_sample'         => $isSample ? 1 : 0,
                'is_hidden'         => $isHidden ? 1 : 0,
                'points'            => (int)($case['points'] ?? 0)
            ];

            // Nếu người xem là Admin/Giảng viên phụ trách, hoặc là ca ví dụ công khai:
            if ($isPrivileged || $isSample) {
                $cleanCase['input_data']      = $case['input_data'] ?? null;
                $cleanCase['expected_output'] = $case['expected_output'] ?? null;
                $cleanCase['actual_output']   = $case['actual_output'] ?? null;
                $cleanCase['error_message']   = self::sanitizeErrorMessage($case['error_message'] ?? null);
            } else {
                // Với testcase ẩn (is_hidden = 1) đối với người học thông thường:
                // TUYỆT ĐỐI KHÔNG TRẢ VỀ INPUT, EXPECTED_OUTPUT HOẶC ACTUAL_OUTPUT!
                $cleanCase['input_data']      = '[DỮ LIỆU ĐƯỢC BẢO VỆ]';
                $cleanCase['expected_output'] = '[DỮ LIỆU ĐƯỢC BẢO VỆ]';
                $cleanCase['actual_output']   = '[DỮ LIỆU ĐƯỢC BẢO VỆ]';
                $cleanCase['error_message']   = !empty($case['error_message']) ? 'Lỗi xảy ra trong quá trình thực thi testcase kín.' : null;
            }

            $sanitizedList[] = $cleanCase;
        }

        return $sanitizedList;
    }

    /**
     * Lọc thông tin lần chấm (judging_run) trước khi trả về
     * Không lộ system_error_log ra ngoài nếu không phải Admin
     */
    public static function sanitizeRunDetails(array $run, bool $isAdmin = false): array
    {
        if (!$isAdmin) {
            unset($run['system_error_log']);
            unset($run['worker_id']);
        }
        if (isset($run['compiler_message'])) {
            $run['compiler_message'] = self::sanitizeErrorMessage($run['compiler_message']);
        }
        return $run;
    }

    /**
     * Kiểm tra tính hợp lệ của Idempotency Key
     */
    public static function validateIdempotencyKey(?string $key): bool
    {
        if ($key === null || strlen($key) < 8 || strlen($key) > 64) {
            return false;
        }
        return (bool)preg_match('/^[a-zA-Z0-9_\-]+$/', $key);
    }

    /**
     * Sinh CSRF Token cho Session
     */
    public static function generateCsrfToken(): string
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }

    /**
     * Xác thực CSRF Token
     */
    public static function verifyCsrfToken(?string $token): bool
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        if (empty($_SESSION['csrf_token']) || empty($token)) {
            return false;
        }
        return hash_equals($_SESSION['csrf_token'], $token);
    }
}
