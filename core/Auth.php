<?php
/**
 * Nhom1 Online Judge - Module Xác thực & Phân quyền Chính thức (Auth)
 *
 * Mục đích của tệp này:
 * - Quản lý toàn bộ vòng đời đăng nhập, đăng ký, đăng xuất và phiên làm việc (Session) của người dùng.
 * - Hỗ trợ Đăng nhập linh hoạt bằng: Tên đăng nhập (username), Email, hoặc Mã sinh viên (student_code).
 * - Hỗ trợ Đăng ký tài khoản Sinh viên với vai trò mặc định cứng là 'learner' (chống leo thang đặc quyền).
 * - Mã hóa mật khẩu an toàn bằng thuật toán Bcrypt (password_hash / password_verify).
 * - Chống tấn công chiếm đoạt phiên (Session Fixation) bằng session_regenerate_id(true).
 * - Đồng bộ tức thì trạng thái khóa tài khoản (is_locked) & thay đổi vai trò trực tiếp từ CSDL.
 * - Kiểm soát quyền riêng tư nghiêm ngặt: Học viên A tuyệt đối không được xem mã nguồn/bài nộp của Học viên B.
 */

// Bật kiểm tra kiểu dữ liệu nghiêm ngặt (strict_types) cho toàn tệp
declare(strict_types=1);

// Nạp tệp cấu hình kết nối CSDL để sử dụng hàm getDbConnection() trả về đối tượng PDO
require_once __DIR__ . '/../config/database.php';

/**
 * Lớp Auth (Xác thực & Phân quyền)
 *
 * Thiết kế toàn bộ thuộc tính và phương thức ở dạng static (tĩnh) để có thể gọi nhanh
 * ở mọi trang PHP (ví dụ: Auth::check(), Auth::requireLogin(), Auth::isAdmin())
 * mà không cần khởi tạo đối tượng.
 */
class Auth
{
    /**
     * Lưu thông báo lỗi tiếng Việt gần nhất phát sinh trong quá trình kiểm tra/đăng nhập
     * (ví dụ: sai mật khẩu, tài khoản bị khóa) để giao diện hiển thị cho người dùng.
     */
    private static ?string $lastError = null;

    /**
     * Lưu mã lỗi ngắn dạng máy đọc được (ví dụ: 'locked', 'invalid_credentials')
     * để luồng xử lý dễ rẽ nhánh (như gắn ?locked=1 khi chuyển hướng về trang đăng nhập).
     */
    private static ?string $lastErrorCode = null;

    /**
     * Bộ nhớ đệm (cache) trong phạm vi 1 yêu cầu HTTP (per-request cache):
     * Lưu ID của người dùng đã được xác thực lại với CSDL trong lượt tải trang hiện tại.
     * Mục đích: Nếu trong cùng 1 trang ta gọi Auth::check(), Auth::user(), Auth::role() nhiều lần,
     * hệ thống chỉ cần truy vấn CSDL ĐÚNG 1 LẦN thay vì truy vấn lặp lại nhiều lần gây tốn tài nguyên.
     */
    private static ?int $validatedUserId = null;

    /**
     * Lấy thông báo lỗi gần nhất từ quá trình xác thực.
     *
     * @return string|null Chuỗi thông báo lỗi, hoặc null nếu không có lỗi.
     */
    public static function getLastError(): ?string
    {
        return self::$lastError;
    }

    /**
     * Lấy mã lỗi gần nhất ('locked', 'invalid_credentials', ...).
     *
     * @return string|null Mã lỗi gần nhất, hoặc null nếu không có lỗi.
     */
    public static function getLastErrorCode(): ?string
    {
        return self::$lastErrorCode;
    }

    /**
     * Bắt đầu phiên làm việc (PHP Session) nếu chưa được khởi tạo.
     *
     * Kiểm tra 2 điều kiện an toàn:
     * - `session_status() === PHP_SESSION_NONE`: session chưa bật (tránh báo cảnh báo gọi session_start() 2 lần).
     * - `!headers_sent()`: trình chủ chưa gửi HTTP header về trình duyệt (vì session cần gửi cookie PHPSESSID qua header).
     */
    public static function startSession(): void
    {
        if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
            @session_start();
        }
    }

    /**
     * Kiểm tra trạng thái đăng nhập hiện tại VÀ đồng bộ tức thì trạng thái khóa / vai trò từ CSDL.
     *
     * Cơ chế Đồng bộ Khóa tài khoản & Quyền hạn (Real-time Account Lock & Role Sync):
     * - Giả sử Sinh viên A đang đăng nhập, lúc này Admin khóa tài khoản A (is_locked = 1)
     *   hoặc thu hồi quyền Giảng viên của B trên CSDL.
     * - Nếu chỉ tin vào $_SESSION cũ thì A/B vẫn thao tác được cho đến khi hết phiên!
     * - Vì vậy, ở mỗi yêu cầu HTTP mới (khi $validatedUserId !== $uid), hàm này truy vấn trực tiếp bảng `users`:
     *   + Nếu tài khoản bị xóa hoặc `is_locked = 1` -> lập tức ghi mã lỗi 'locked', hủy phiên (logout) và trả về false.
     *   + Ngược lại -> cập nhật lại toàn bộ thông tin & vai trò mới nhất vào $_SESSION và đánh dấu đã xác thực.
     *
     * @return bool true nếu người dùng đang đăng nhập hợp lệ và không bị khóa; ngược lại false.
     */
    public static function check(): bool
    {
        self::startSession();
        // Nếu trong $_SESSION chưa có user_id thì chắc chắn là khách chưa đăng nhập
        if (empty($_SESSION['user_id'])) {
            return false;
        }

        $uid = (int)$_SESSION['user_id'];
        // Chỉ truy vấn CSDL 1 lần duy nhất cho mỗi request nhờ biến cache $validatedUserId
        if (self::$validatedUserId !== $uid) {
            try {
                $pdo = getDbConnection();
                // Câu lệnh SQL (Prepared Statement dùng tham số ? chống SQL Injection):
                // Lấy thông tin hồ sơ, vai trò và trạng thái khóa mới nhất của user theo khóa chính id
                $stmt = $pdo->prepare("
                    SELECT id, username, full_name, email, student_code, class_name, role, is_locked, lock_reason
                    FROM users
                    WHERE id = ?
                    LIMIT 1
                ");
                $stmt->execute([$uid]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);

                // Nếu tài khoản không còn tồn tại trong CSDL HOẶC đang bị khóa (is_locked = 1)
                if (!$row || (int)$row['is_locked'] === 1) {
                    $reason = $row && !empty($row['lock_reason']) ? " Lý do: {$row['lock_reason']}" : '';
                    self::$lastError = 'Tài khoản của bạn đã bị khóa bởi Quản trị viên.' . $reason;
                    self::$lastErrorCode = 'locked';
                    // Cưỡng chế đăng xuất và xóa sạch session ngay lập tức
                    self::logout();
                    return false;
                }

                // Đồng bộ tức thì vai trò & thông tin (nếu Admin vừa cấp/thu hồi quyền hoặc sửa hồ sơ)
                $_SESSION['username']     = $row['username'];
                $_SESSION['full_name']    = $row['full_name'];
                $_SESSION['email']        = $row['email'];
                $_SESSION['student_code'] = $row['student_code'];
                $_SESSION['class_name']   = $row['class_name'];
                $_SESSION['role']         = $row['role'];
                // Lưu lại $uid để các lệnh Auth::check()/role() tiếp theo trong cùng request không phải query lại
                self::$validatedUserId    = $uid;
            } catch (Throwable $e) {
                // Giữ nguyên session hiện tại nếu CSDL gặp lỗi kết nối tạm thời để tránh đăng xuất oan người dùng
            }
        }

        return !empty($_SESSION['user_id']);
    }

    /**
     * Lấy ID (khóa chính dạng số nguyên) của người dùng đang đăng nhập từ Session.
     *
     * @return int|null ID người dùng nếu đã đăng nhập, hoặc null nếu chưa đăng nhập.
     */
    public static function id(): ?int
    {
        self::startSession();
        return isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
    }

    /**
     * Lấy mảng thông tin đầy đủ của người dùng hiện tại sau khi đã kiểm tra hợp lệ với CSDL.
     *
     * @return array|null Mảng chứa id, username, full_name, email, student_code, class_name, role;
     *                    hoặc null nếu chưa đăng nhập / tài khoản đã bị khóa.
     */
    public static function user(): ?array
    {
        self::startSession();
        // Gọi self::check() để đảm bảo tài khoản chưa bị khóa và thông tin trong session luôn mới nhất
        if (!self::check()) {
            return null;
        }
        return [
            'id'           => (int)$_SESSION['user_id'],
            'username'     => $_SESSION['username'] ?? '',
            'full_name'    => $_SESSION['full_name'] ?? '',
            'email'        => $_SESSION['email'] ?? '',
            'student_code' => $_SESSION['student_code'] ?? null,
            'class_name'   => $_SESSION['class_name'] ?? null,
            'role'         => $_SESSION['role'] ?? 'learner'
        ];
    }

    /**
     * Lấy vai trò hiện tại của người dùng: 'learner' (Học viên), 'instructor' (Giảng viên),
     * 'admin' (Quản trị viên), hoặc 'guest' (Khách chưa đăng nhập).
     *
     * @return string Tên vai trò.
     */
    public static function role(): string
    {
        self::startSession();
        // Nếu đã có user_id nhưng chưa đồng bộ trong request này -> gọi check() để cập nhật role mới nhất từ CSDL
        if (!empty($_SESSION['user_id']) && self::$validatedUserId !== (int)$_SESSION['user_id']) {
            self::check();
        }
        return $_SESSION['role'] ?? 'guest';
    }

    /**
     * Kiểm tra nhanh người dùng hiện tại có phải Quản trị viên ('admin') hay không.
     *
     * @return bool
     */
    public static function isAdmin(): bool
    {
        return self::role() === 'admin';
    }

    /**
     * Kiểm tra nhanh người dùng hiện tại có phải Giảng viên ('instructor') hay không.
     *
     * @return bool
     */
    public static function isInstructor(): bool
    {
        return self::role() === 'instructor';
    }

    /**
     * Kiểm tra nhanh người dùng hiện tại có phải Học viên/Sinh viên ('learner') hay không.
     *
     * @return bool
     */
    public static function isLearner(): bool
    {
        return self::role() === 'learner';
    }

    /**
     * Thực hiện đăng nhập chính thức bằng tên đăng nhập (username), email hoặc mã sinh viên.
     *
     * Luồng bảo mật trong hàm này:
     * 1. Tìm tài khoản bằng Prepared Statement (chống SQL Injection).
     * 2. So khớp mật khẩu bằng `password_verify()` (thuật toán Bcrypt - băm một chiều kèm muối ngẫu nhiên).
     *    Lưu ý: Nếu sai tên đăng nhập HAY sai mật khẩu đều trả về CÙNG MỘT thông báo lỗi chung
     *    để kẻ tấn công không thể dò xem username/email nào đang tồn tại trong hệ thống (User Enumeration).
     * 3. Kiểm tra tài khoản có bị khóa (`is_locked = 1`) hay không.
     * 4. Tái tạo Session ID bằng `session_regenerate_id(true)` để chống tấn công Session Fixation
     *    (kẻ tấn công gài sẵn mã phiên PHPSESSID cho nạn nhân trước khi nạn nhân đăng nhập; khi đổi sang ID mới
     *    và xóa phiên cũ, mã phiên kẻ tấn công biết trước lập tức vô hiệu).
     * 5. Lưu thông tin người dùng vào $_SESSION và cập nhật mốc thời gian đăng nhập cuối (`last_login_at`).
     *
     * @param string $identifier Tên đăng nhập, địa chỉ email hoặc mã sinh viên.
     * @param string $password   Mật khẩu dạng văn bản thuần (plaintext) người dùng vừa nhập.
     * @return bool true nếu đăng nhập thành công; false nếu thất bại (xem chi tiết qua getLastError()).
     */
    public static function attempt(string $identifier, string $password): bool
    {
        self::startSession();
        // Xóa trạng thái lỗi cũ trước khi thử đăng nhập mới
        self::$lastError = null;
        self::$lastErrorCode = null;
        $pdo = getDbConnection();

        // Câu lệnh SQL tìm kiếm linh hoạt theo 1 trong 3 định danh: username, email hoặc student_code.
        // Dùng named parameters (:id1, :id2, :id3) vì PDO ở chế độ không mô phỏng (EMULATE_PREPARES = false)
        // yêu cầu mỗi placeholder mang tên riêng biệt.
        $stmt = $pdo->prepare("
            SELECT id, username, email, password_hash, full_name, student_code, class_name, role, is_locked, lock_reason
            FROM users 
            WHERE username = :id1 OR email = :id2 OR (student_code IS NOT NULL AND student_code = :id3)
            LIMIT 1
        ");
        $stmt->execute([
            'id1' => $identifier,
            'id2' => $identifier,
            // Mã sinh viên luôn lưu chữ in hoa trong CSDL nên chuyển $identifier sang chữ hoa khi so khớp
            'id3' => strtoupper($identifier)
        ]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        // Kiểm tra tồn tại tài khoản VÀ xác thực mật khẩu với chuỗi băm Bcrypt lưu trong cột password_hash.
        // password_verify() tự trích xuất salt + cost từ chuỗi hash và so sánh theo thời gian hằng số.
        if (!$user || !password_verify($password, $user['password_hash'])) {
            self::$lastError = 'Tên đăng nhập, email hoặc mật khẩu không chính xác.';
            self::$lastErrorCode = 'invalid_credentials';
            return false;
        }

        // Từ chối đăng nhập nếu tài khoản đang bị Quản trị viên khóa
        if ((int)$user['is_locked'] === 1) {
            $reason = !empty($user['lock_reason']) ? " Lý do: {$user['lock_reason']}" : '';
            self::$lastError = 'Tài khoản của bạn hiện đang bị khóa.' . $reason . ' Vui lòng liên hệ Quản trị viên.';
            self::$lastErrorCode = 'locked';
            return false;
        }

        // Đăng nhập thành công: tái tạo Session ID mới và xóa tệp phiên cũ (tham số true) để chống Session Fixation
        if (session_status() === PHP_SESSION_ACTIVE && !headers_sent()) {
            @session_regenerate_id(true);
        }
        // Nạp thông tin người dùng vào phiên làm việc $_SESSION
        $_SESSION['user_id']      = (int)$user['id'];
        $_SESSION['username']     = $user['username'];
        $_SESSION['full_name']    = $user['full_name'];
        $_SESSION['email']        = $user['email'];
        $_SESSION['student_code'] = $user['student_code'];
        $_SESSION['class_name']   = $user['class_name'];
        $_SESSION['role']         = $user['role'];
        self::$validatedUserId    = (int)$user['id'];

        // Ghi nhận thời điểm đăng nhập gần nhất vào cột last_login_at để phục vụ thống kê & kiểm toán
        $pdo->prepare("UPDATE users SET last_login_at = NOW() WHERE id = ?")->execute([(int)$user['id']]);

        return true;
    }

    /**
     * Đăng ký tài khoản người dùng mới (Vai trò mặc định cố định: 'learner' - Sinh viên).
     *
     * Quy trình xử lý gồm 4 bước:
     * 1. Chuẩn hóa dữ liệu đầu vào (cắt khoảng trắng đầu/cuối, viết thường email, viết hoa mã sinh viên).
     * 2. Kiểm tra hợp lệ (Validation): họ tên, định dạng username, email, mã SV, độ dài & khớp mật khẩu.
     * 3. Kiểm tra trùng lặp (Uniqueness) trong CSDL đối với username, email và student_code.
     * 4. Băm mật khẩu bằng Bcrypt (`password_hash`) và chèn bản ghi vào bảng `users` với role cứng là 'learner'.
     *
     * @param array $input Mảng dữ liệu từ form đăng ký (username, email, full_name, student_code, class_name, password, password_confirm).
     * @return array Mảng kết quả: ['success' => bool, 'user_id' => ?int, 'message' => ?string, 'error' => ?string].
     */
    public static function register(array $input): array
    {
        // Bước 1: Chuẩn hóa dữ liệu đầu vào trước khi kiểm tra
        $username        = trim((string)($input['username'] ?? ''));
        $email           = strtolower(trim((string)($input['email'] ?? '')));
        $fullName        = trim((string)($input['full_name'] ?? ''));
        $studentCode     = strtoupper(trim((string)($input['student_code'] ?? '')));
        $className       = trim((string)($input['class_name'] ?? ''));
        $password        = (string)($input['password'] ?? '');
        // Chỉ kiểm tra mật khẩu xác nhận nếu trường 'password_confirm' có được gửi lên trong mảng $input
        $passwordConfirm = array_key_exists('password_confirm', $input) ? (string)$input['password_confirm'] : null;

        // Bước 2: Kiểm tra hợp lệ từng trường dữ liệu
        // Dùng mb_strlen() thay vì strlen() để đếm đúng số ký tự tiếng Việt có dấu (UTF-8)
        if ($fullName === '' || mb_strlen($fullName) < 2) {
            return ['success' => false, 'error' => 'Vui lòng nhập Họ và tên đầy đủ (tối thiểu 2 ký tự).'];
        }

        // Username chỉ cho phép chữ cái không dấu, chữ số và gạch dưới, độ dài 3-30 ký tự
        if (!preg_match('/^[a-zA-Z0-9_]{3,30}$/', $username)) {
            return ['success' => false, 'error' => 'Tên đăng nhập phải dài từ 3–30 ký tự, chỉ gồm chữ cái, chữ số và dấu gạch dưới (_).'];
        }

        // Kiểm tra cấu trúc địa chỉ email chuẩn bằng bộ lọc tích hợp sẵn của PHP
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['success' => false, 'error' => 'Địa chỉ Email không đúng định dạng.'];
        }

        // Mã sinh viên là tùy chọn; nếu có nhập thì phải đúng định dạng 4-30 ký tự in hoa/số/-/_
        if ($studentCode !== '' && !preg_match('/^[A-Z0-9_-]{4,30}$/', $studentCode)) {
            return ['success' => false, 'error' => 'Mã sinh viên chỉ gồm chữ in hoa, số và dấu gạch ngang/gạch dưới (4–30 ký tự).'];
        }

        // Yêu cầu mật khẩu tối thiểu 6 ký tự
        if (strlen($password) < 6) {
            return ['success' => false, 'error' => 'Mật khẩu phải có ít nhất 6 ký tự.'];
        }

        // Kiểm tra mật khẩu nhập lại có trùng khớp với mật khẩu gốc không
        if ($passwordConfirm !== null && $password !== $passwordConfirm) {
            return ['success' => false, 'error' => 'Mật khẩu xác nhận không khớp.'];
        }

        $pdo = getDbConnection();

        // Bước 3: Kiểm tra trùng lặp trong CSDL để thông báo rõ lỗi nào bị trùng thay vì lỗi SQL chung chung
        // 3a. Kiểm tra trùng lặp username
        $chk = $pdo->prepare("SELECT id FROM users WHERE username = ? LIMIT 1");
        $chk->execute([$username]);
        if ($chk->fetch()) {
            return ['success' => false, 'error' => "Tên đăng nhập '{$username}' đã tồn tại. Vui lòng chọn tên khác."];
        }

        // 3b. Kiểm tra trùng lặp email
        $chk = $pdo->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
        $chk->execute([$email]);
        if ($chk->fetch()) {
            return ['success' => false, 'error' => "Email '{$email}' đã được đăng ký trước đó."];
        }

        // 3c. Kiểm tra trùng lặp mã sinh viên (chỉ kiểm tra nếu người dùng có điền mã sinh viên)
        if ($studentCode !== '') {
            $chk = $pdo->prepare("SELECT id FROM users WHERE student_code = ? LIMIT 1");
            $chk->execute([$studentCode]);
            if ($chk->fetch()) {
                return ['success' => false, 'error' => "Mã sinh viên '{$studentCode}' đã được đăng ký bởi tài khoản khác."];
            }
        }

        // Bước 4: Băm mật khẩu & Lưu tài khoản mới
        // Bảo mật chống leo thang đặc quyền (Privilege Escalation):
        // Tài khoản tự đăng ký LUÔN được gán cứng vai trò 'learner' và is_locked = 0 trong SQL,
        // tuyệt đối không tin hay nhận trường 'role' từ request của người dùng.
        // PASSWORD_DEFAULT sử dụng thuật toán Bcrypt với salt ngẫu nhiên an toàn cho mỗi tài khoản.
        $passwordHash = password_hash($password, PASSWORD_DEFAULT);

        $stmt = $pdo->prepare("
            INSERT INTO users (username, email, password_hash, full_name, student_code, class_name, role, is_locked)
            VALUES (?, ?, ?, ?, ?, ?, 'learner', 0)
        ");
        $stmt->execute([
            $username,
            $email,
            $passwordHash,
            $fullName,
            // Lưu NULL thay vì chuỗi rỗng '' nếu người dùng bỏ trống mã SV hoặc tên lớp (tránh vi phạm UNIQUE index)
            $studentCode !== '' ? $studentCode : null,
            $className !== '' ? $className : null
        ]);

        // Trả về thành công kèm ID tài khoản vừa được sinh tự động (AUTO_INCREMENT)
        return [
            'success' => true,
            'user_id' => (int)$pdo->lastInsertId(),
            'message' => 'Đăng ký tài khoản thành công! Bạn có thể đăng nhập ngay bây giờ.'
        ];
    }

    /**
     * Đăng xuất hoàn toàn người dùng hiện tại.
     *
     * Thực hiện dọn sạch ở cả 3 lớp để không còn dấu vết phiên cũ:
     * 1. Xóa biến cache `$validatedUserId` và toàn bộ dữ liệu trong mảng `$_SESSION`.
     * 2. Hủy cookie phiên (`PHPSESSID`) trên trình duyệt bằng cách đặt hạn sử dụng về quá khứ (`time() - 42000`).
     * 3. Gọi `session_destroy()` để xóa tệp phiên trên ổ đĩa máy chủ.
     */
    public static function logout(): void
    {
        self::startSession();
        self::$validatedUserId = null;
        // Xóa sạch tất cả khóa trong bộ nhớ $_SESSION
        $_SESSION = [];
        // Nếu PHP dùng cookie để lưu Session ID và chưa gửi header, gửi lệnh hết hạn cookie cho trình duyệt
        if (ini_get("session.use_cookies") && !headers_sent()) {
            $params = session_get_cookie_params();
            @setcookie(session_name(), '', time() - 42000,
                $params["path"], $params["domain"],
                $params["secure"], $params["httponly"]
            );
        }
        // Hủy tệp session lưu phía máy chủ
        if (session_status() === PHP_SESSION_ACTIVE) {
            @session_destroy();
        }
    }

    /**
     * Middleware kiểm tra bắt buộc đăng nhập cho các trang cần bảo vệ.
     *
     * Nếu người dùng chưa đăng nhập (hoặc vừa bị khóa tài khoản), hệ thống gửi HTTP Header Location
     * để chuyển hướng về trang đăng nhập và gọi `exit` dừng thực thi toàn bộ mã phía sau.
     *
     * @param string $redirectUrl Đường dẫn trang đăng nhập sẽ chuyển hướng tới.
     */
    public static function requireLogin(string $redirectUrl = '/BaiTapLon/login.php'): void
    {
        if (!self::check()) {
            // Nếu nguyên nhân bị đẩy ra là do tài khoản bị khóa, gắn thêm ?locked=1 để trang login hiện cảnh báo rõ ràng
            $qs = self::$lastErrorCode === 'locked' ? '?locked=1' : '';
            header("Location: {$redirectUrl}{$qs}");
            exit;
        }
    }

    /**
     * Kiểm tra phân quyền theo vai trò (Role-Based Access Control - RBAC).
     *
     * Yêu cầu người dùng phải đăng nhập VÀ vai trò hiện tại phải nằm trong danh sách `$allowedRoles`.
     * Nếu không đủ quyền, trả về mã trạng thái HTTP 403 (Forbidden) và dừng thực thi ngay lập tức.
     *
     * @param array $allowedRoles Mảng các vai trò được phép truy cập, ví dụ: ['admin', 'instructor'].
     */
    public static function requireRoles(array $allowedRoles): void
    {
        self::requireLogin();
        // Tham số thứ 3 = true bật so sánh nghiêm ngặt (===) trong in_array()
        if (!in_array(self::role(), $allowedRoles, true)) {
            http_response_code(403);
            die("403 Forbidden - Bạn không có quyền truy cập chức năng này.");
        }
    }

    /**
     * Kiểm tra quyền xem chi tiết bài nộp & mã nguồn của một submission (Phép thử 5 - Rubric).
     *
     * Ma trận phân quyền riêng tư (Privacy Isolation):
     * - Admin (Quản trị viên): Xem được TẤT CẢ bài nộp trong hệ thống.
     * - Chủ sở hữu (Owner): Người nộp bài luôn xem được bài nộp của chính mình.
     * - Giảng viên (Instructor): Chỉ xem được bài nộp thuộc đề bài do chính mình tạo (`$problemCreatorId`),
     *   HOẶC bài nộp thuộc lớp học (`$classroomId`) do mình trực tiếp phụ trách (`classrooms.instructor_id`).
     * - Học viên khác: TUYỆT ĐỐI KHÔNG ĐƯỢC XEM bài làm của học viên khác (trả về false).
     *
     * @param int      $submissionUserId ID của người đã nộp bài.
     * @param int|null $classroomId      ID lớp học (nếu bài nộp thuộc một lớp học cụ thể).
     * @param int|null $problemCreatorId ID người tạo đề bài (nếu có).
     * @return bool true nếu được phép xem; false nếu bị từ chối.
     */
    public static function canViewSubmission(int $submissionUserId, ?int $classroomId = null, ?int $problemCreatorId = null): bool
    {
        $currentUserId = self::id();
        // Khách chưa đăng nhập tuyệt đối không được xem chi tiết bài nộp
        if ($currentUserId === null) {
            return false;
        }

        $currentRole = self::role();

        // Quy tắc 1: Admin luôn có toàn quyền xem
        if ($currentRole === 'admin') {
            return true;
        }

        // Quy tắc 2: Chính chủ bài nộp luôn có quyền xem bài của mình
        if ($currentUserId === $submissionUserId) {
            return true;
        }

        // Quy tắc 3: Giảng viên kiểm tra quyền sở hữu đề bài hoặc quyền phụ trách lớp học
        if ($currentRole === 'instructor') {
            // 3a. Bài nộp thuộc đề bài do chính giảng viên này biên soạn
            if ($problemCreatorId !== null && $currentUserId === $problemCreatorId) {
                return true;
            }

            // 3b. Bài nộp nằm trong lớp học do giảng viên này đứng lớp (kiểm tra trong bảng classrooms)
            if ($classroomId !== null) {
                $pdo = getDbConnection();
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM classrooms WHERE id = ? AND instructor_id = ?");
                $stmt->execute([$classroomId, $currentUserId]);
                if ((int)$stmt->fetchColumn() > 0) {
                    return true;
                }
            }
        }

        // Quy tắc mặc định (Deny by Default): Người học A không bao giờ được đọc mã nguồn của Người học B
        return false;
    }

    /**
     * Kiểm tra quyền quản lý, chỉnh sửa đề bài, quản lý bộ test & phát hành phiên bản đề mới.
     *
     * Quy tắc:
     * - Admin: được quản lý mọi bài tập.
     * - Giảng viên: chỉ được quản lý bài tập do chính mình tạo ra (`$problemCreatedBy`).
     * - Học viên / Giảng viên khác: không có quyền (false).
     *
     * @param int $problemCreatedBy ID tài khoản người đã tạo bài tập.
     * @return bool true nếu có quyền quản lý bài tập; ngược lại false.
     */
    public static function canManageProblem(int $problemCreatedBy): bool
    {
        if (self::isAdmin()) {
            return true;
        }
        if (self::isInstructor() && self::id() === $problemCreatedBy) {
            return true;
        }
        return false;
    }
}
