<?php
/**
 * Nhom1 Online Judge - Module Bảo mật & Lọc Dữ liệu Nhạy cảm (Security)
 *
 * Mục đích của tệp này:
 * - Cung cấp các hàm tiện ích bảo mật dùng chung cho toàn bộ hệ thống:
 *   1. Chống tấn công XSS (Cross-Site Scripting) khi hiển thị dữ liệu ra HTML.
 *   2. Che giấu đường dẫn thư mục vật lý trên máy chủ khỏi thông báo lỗi biên dịch/thực thi.
 *   3. Bảo vệ tuyệt đối dữ liệu của các ca kiểm thử kín (hidden test cases) không bị lộ cho học viên.
 *   4. Ẩn thông tin nội bộ hệ thống (log lỗi hệ thống, ID tiến trình worker) với người dùng thường.
 *   5. Kiểm tra tính hợp lệ của Idempotency Key (chống nộp trùng bài khi bấm đúp hoặc lỗi mạng).
 *   6. Sinh và xác thực CSRF Token (chống tấn công giả mạo yêu cầu từ trang web khác).
 * - Tuân thủ Nguyên tắc 4 (MASTER_PLAN.md) và Phép thử 5 (Rubric).
 */

// Bật kiểm tra kiểu dữ liệu nghiêm ngặt để đảm bảo an toàn kiểu truyền vào các hàm bảo mật
declare(strict_types=1);

/**
 * Lớp Security
 *
 * Tập hợp các phương thức tĩnh (static) xử lý bảo mật đầu vào, đầu ra và phiên làm việc.
 */
class Security
{
    /**
     * Mã hóa (escape) chuỗi văn bản trước khi hiển thị ra HTML nhằm chống tấn công XSS.
     *
     * Cơ chế:
     * - Chuyển các ký tự đặc biệt của HTML như <, >, ", ', & thành thực thể HTML an toàn
     *   (&lt;, &gt;, &quot;, &#039;, &amp;) để trình duyệt chỉ hiển thị dạng chữ thuần,
     *   không thực thi các thẻ <script> độc hại do người dùng nhập vào.
     * - Cờ ENT_QUOTES: mã hóa cả dấu nháy kép (") và nháy đơn (').
     * - Cờ ENT_SUBSTITUTE: thay thế các byte UTF-8 lỗi bằng ký tự thay thế Unicode thay vì trả về chuỗi rỗng.
     *
     * @param string|null $str Chuỗi gốc có thể là null.
     * @return string Chuỗi an toàn để in trực tiếp trong HTML.
     */
    public static function escape(?string $str): string
    {
        // Nếu chuỗi là null thì trả về chuỗi rỗng để tránh lỗi khi truyền vào htmlspecialchars()
        if ($str === null) {
            return '';
        }
        return htmlspecialchars($str, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * Lọc sạch đường dẫn file nhạy cảm trên máy chủ khỏi thông báo lỗi (Information Disclosure).
     *
     * Ví dụ:
     * - Trình biên dịch C++ hoặc Python khi báo lỗi thường in đường dẫn đầy đủ như:
     *   'E:\Phat_Trien_Phan_Mem_Ma_Nguon_Mo\BaiTapLon\temp\runs\run_1\solution.cpp:5:1: error...'
     * - Hàm này dùng Biểu thức chính quy (Regex) rút gọn đường dẫn đó thành tên tệp ngắn 'solution.cpp',
     *   giúp kẻ tấn công không biết được cấu trúc thư mục hay tên tài khoản trên máy chủ.
     * - Đồng thời cắt ngắn thông báo nếu vượt quá 4000 byte để tránh tràn bộ nhớ / làm đơ trình duyệt.
     *
     * @param string|null $message Nội dung lỗi gốc từ trình biên dịch hoặc stderr.
     * @return string Nội dung lỗi đã được làm sạch đường dẫn và giới hạn độ dài.
     */
    public static function sanitizeErrorMessage(?string $message): string
    {
        // Trả về chuỗi rỗng nếu không có thông báo lỗi
        if ($message === null || $message === '') {
            return '';
        }

        // Biểu thức chính quy 1: Nhận diện đường dẫn tuyệt đối trên Windows (ví dụ: C:\dir\sub\file.cpp)
        // - [A-Za-z]:\\\\ khớp ổ đĩa như C:\ hoặc E:\
        // - [^\s\r\n\'"]+\\\\ khớp toàn bộ phần thư mục cha ở giữa
        // - ([^\s\r\n\'"]+) là nhóm bắt giữ $1 chứa duy nhất tên tệp ở cuối -> thay toàn bộ đường dẫn bằng $1
        $sanitized = preg_replace('/[A-Za-z]:\\\\[^\s\r\n\'"]+\\\\([^\s\r\n\'"]+)/', '$1', $message);

        // Biểu thức chính quy 2: Nhận diện đường dẫn tuyệt đối trên Linux/Unix (ví dụ: /var/www/app/solution.py)
        // Giữ lại duy nhất tên tệp ở cuối ($1)
        $sanitized = preg_replace('/\/[^\s\r\n\'"]+\/([^\s\r\n\'"]+)/', '$1', $sanitized);

        // Giới hạn độ dài lỗi tối đa 4000 ký tự để tránh spam dung lượng phản hồi hoặc DoS phía trình duyệt
        if (strlen($sanitized) > 4000) {
            $sanitized = substr($sanitized, 0, 4000) . "\n... [Nội dung lỗi bị cắt ngắn vì vượt quá giới hạn]";
        }

        return $sanitized;
    }

    /**
     * Lọc dữ liệu kết quả từng ca kiểm thử (test case) trước khi trả về cho phía Client (API/Giao diện).
     *
     * Cơ chế bảo vệ Test Kín (Hidden Test Case Protection):
     * - ĐẢM BẢO TUYỆT ĐỐI KHÔNG LỘ dữ liệu đầu vào (input), đáp án (expected_output),
     *   đầu ra chương trình (actual_output) và chi tiết lỗi của các test kín (is_hidden = 1)
     *   cho học viên thông thường (nhằm chống gian lận, viết cứng đáp án theo test).
     * - Học viên CHỈ được xem đầy đủ dữ liệu ở các ca ví dụ công khai (is_sample = 1).
     * - Riêng Admin hoặc Giảng viên quản lý bài tập ($isPrivileged = true) được xem đầy đủ mọi test.
     *
     * @param array $caseResults  Mảng các bản ghi kết quả chấm từng test case lấy từ CSDL.
     * @param bool  $isPrivileged true nếu người xem là Admin hoặc Giảng viên có quyền quản lý đề; ngược lại false.
     * @return array Mảng kết quả test case đã được lọc sạch theo đúng quyền hạn.
     */
    public static function sanitizeCaseResultsForUser(array $caseResults, bool $isPrivileged = false): array
    {
        $sanitizedList = [];

        foreach ($caseResults as $case) {
            // Xác định test này có phải test ẩn (kín) hay test ví dụ (công khai) hay không
            $isHidden = (bool)($case['is_hidden'] ?? false);
            $isSample = (bool)($case['is_sample'] ?? false);

            // Tạo bản sao "sạch" chỉ giữ lại các trường siêu dữ liệu (metadata) an toàn được phép công khai
            // như: thứ tự test, phán quyết (AC/WA/TLE...), thời gian chạy, bộ nhớ, điểm số
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

            // Trường hợp 1: Người xem có đặc quyền (Admin/Giảng viên phụ trách) HOẶC đây là ca ví dụ công khai
            if ($isPrivileged || $isSample) {
                $cleanCase['input_data']      = $case['input_data'] ?? null;
                $cleanCase['expected_output'] = $case['expected_output'] ?? null;
                $cleanCase['actual_output']   = $case['actual_output'] ?? null;
                // Vẫn lọc đường dẫn máy chủ trong thông báo lỗi để đảm bảo an toàn
                $cleanCase['error_message']   = self::sanitizeErrorMessage($case['error_message'] ?? null);
            } else {
                // Trường hợp 2: Testcase kín (is_hidden = 1 / không phải sample) đối với học viên thường:
                // TUYỆT ĐỐI KHÔNG TRẢ VỀ input_data, expected_output HOẶC actual_output!
                // Thay thế toàn bộ bằng nhãn che dữ liệu để ngăn lộ thông tin dù inspect Network/JSON.
                $cleanCase['input_data']      = '[DỮ LIỆU ĐƯỢC BẢO VỆ]';
                $cleanCase['expected_output'] = '[DỮ LIỆU ĐƯỢC BẢO VỆ]';
                $cleanCase['actual_output']   = '[DỮ LIỆU ĐƯỢC BẢO VỆ]';
                // Không lộ nội dung stderr chi tiết (có thể bị lợi dụng in lén input ra stderr), chỉ báo chung chung
                $cleanCase['error_message']   = !empty($case['error_message']) ? 'Lỗi xảy ra trong quá trình thực thi testcase kín.' : null;
            }

            $sanitizedList[] = $cleanCase;
        }

        return $sanitizedList;
    }

    /**
     * Lọc thông tin chi tiết của một lần chấm (bản ghi bảng judging_runs) trước khi trả về Client.
     *
     * Cơ chế:
     * - Với người không phải Admin: xóa hoàn toàn 'system_error_log' (log lỗi nội bộ máy chủ)
     *   và 'worker_id' (mã tiến trình chấm bài trên máy chủ) để tránh lộ hạ tầng nội bộ.
     * - Luôn lọc sạch đường dẫn máy chủ trong 'compiler_message' (lỗi biên dịch).
     *
     * @param array $run     Mảng dữ liệu một lượt chấm từ bảng judging_runs.
     * @param bool  $isAdmin true nếu người xem là Quản trị viên (Admin).
     * @return array Mảng dữ liệu lượt chấm đã được lọc an toàn.
     */
    public static function sanitizeRunDetails(array $run, bool $isAdmin = false): array
    {
        // Chỉ Admin mới được xem log lỗi hệ thống và định danh worker
        if (!$isAdmin) {
            unset($run['system_error_log']);
            unset($run['worker_id']);
        }
        // Làm sạch đường dẫn thư mục máy chủ trong thông báo lỗi biên dịch (nếu có)
        if (isset($run['compiler_message'])) {
            $run['compiler_message'] = self::sanitizeErrorMessage($run['compiler_message']);
        }
        return $run;
    }

    /**
     * Kiểm tra tính hợp lệ của Idempotency Key (Khóa chống trùng lặp yêu cầu).
     *
     * Khái niệm cho người mới:
     * - Idempotency Key là một chuỗi định danh duy nhất do phía Client tạo ra cho mỗi lần bấm "Nộp bài".
     * - Nếu người dùng bấm đúp chuột hoặc mạng chập chờn gửi lại cùng yêu cầu 2 lần với cùng 1 khóa,
     *   máy chủ dựa vào khóa này để chỉ tạo duy nhất 1 bài nộp, tránh tạo bản ghi trùng lặp.
     * - Quy tắc hợp lệ: không null, dài từ 8 đến 64 ký tự, chỉ chứa chữ cái, chữ số, gạch dưới (_) và gạch ngang (-).
     *
     * @param string|null $key Chuỗi khóa Idempotency cần kiểm tra.
     * @return bool true nếu hợp lệ, false nếu sai định dạng hoặc độ dài.
     */
    public static function validateIdempotencyKey(?string $key): bool
    {
        if ($key === null || strlen($key) < 8 || strlen($key) > 64) {
            return false;
        }
        return (bool)preg_match('/^[a-zA-Z0-9_\-]+$/', $key);
    }

    /**
     * Sinh (hoặc lấy lại) CSRF Token gắn với phiên làm việc (Session) hiện tại.
     *
     * Khái niệm cho người mới:
     * - Tấn công CSRF (Cross-Site Request Forgery) xảy ra khi một trang web độc hại lén gửi yêu cầu
     *   (như xóa bài, đổi mật khẩu) sang hệ thống bằng cookie đăng nhập sẵn có của nạn nhân.
     * - Giải pháp: Sinh một chuỗi bí mật ngẫu nhiên (CSRF Token) lưu trong $_SESSION và nhúng vào form/API;
     *   trang web lạ không thể đọc được token này nên mọi yêu cầu giả mạo sẽ bị từ chối.
     * - random_bytes(32) tạo 32 byte ngẫu nhiên an toàn mật mã (CSPRNG), bin2hex() đổi thành chuỗi hex 64 ký tự.
     *
     * @return string Chuỗi CSRF Token 64 ký tự hệ thập lục phân (hex).
     */
    public static function generateCsrfToken(): string
    {
        // Đảm bảo phiên làm việc (session) đã được khởi chạy trước khi truy cập $_SESSION
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        // Chỉ tạo token mới nếu phiên hiện tại chưa có token
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }

    /**
     * Xác thực CSRF Token gửi lên từ Client có khớp với Token lưu trong Session hay không.
     *
     * Lưu ý bảo mật quan trọng:
     * - Sử dụng hash_equals() thay vì toán tử === thông thường để chống tấn công đo thời gian (Timing Attack).
     *   Toán tử === dừng ngay khi gặp ký tự đầu tiên khác nhau, còn hash_equals() luôn mất thời gian
     *   so sánh cố định (constant-time) cho toàn bộ chuỗi.
     *
     * @param string|null $token Chuỗi CSRF Token nhận được từ form hoặc HTTP header.
     * @return bool true nếu token hợp lệ và khớp hoàn toàn; ngược lại false.
     */
    public static function verifyCsrfToken(?string $token): bool
    {
        // Đảm bảo session đã mở để đọc $_SESSION['csrf_token']
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        // Nếu session chưa có token hoặc client không gửi token lên -> từ chối ngay
        if (empty($_SESSION['csrf_token']) || empty($token)) {
            return false;
        }
        // So sánh an toàn theo thời gian hằng số
        return hash_equals($_SESSION['csrf_token'], $token);
    }
}
