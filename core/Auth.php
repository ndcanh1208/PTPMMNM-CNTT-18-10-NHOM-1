<?php
/**
 * ByteFlow Online Judge - Module Xác thực & Phân quyền Chính thức (Auth)
 * - Hỗ trợ Đăng nhập chính thức (Username / Email / Mã sinh viên)
 * - Hỗ trợ Đăng ký tài khoản Sinh viên (Role mặc định 'learner')
 * - Kiểm tra tức thì trạng thái khóa tài khoản (is_locked) & thay đổi vai trò
 * - Kiểm soát quyền riêng tư: Người A không được xem mã của Người B
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

class Auth
{
    private static ?string $lastError = null;
    private static ?string $lastErrorCode = null;
    private static ?int $validatedUserId = null;

    /**
     * Lấy thông báo lỗi gần nhất từ quá trình xác thực
     */
    public static function getLastError(): ?string
    {
        return self::$lastError;
    }

    /**
     * Lấy mã lỗi gần nhất ('locked', 'invalid_credentials', ...)
     */
    public static function getLastErrorCode(): ?string
    {
        return self::$lastErrorCode;
    }

    /**
     * Bắt đầu phiên làm việc nếu chưa khởi tạo
     */
    public static function startSession(): void
    {
        if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
            @session_start();
        }
    }

    /**
     * Kiểm tra trạng thái đăng nhập (đồng bộ trực tiếp trạng thái khóa & vai trò từ CSDL)
     */
    public static function check(): bool
    {
        self::startSession();
        if (empty($_SESSION['user_id'])) {
            return false;
        }

        $uid = (int)$_SESSION['user_id'];
        if (self::$validatedUserId !== $uid) {
            try {
                $pdo = getDbConnection();
                $stmt = $pdo->prepare("
                    SELECT id, username, full_name, email, student_code, class_name, role, is_locked, lock_reason
                    FROM users
                    WHERE id = ?
                    LIMIT 1
                ");
                $stmt->execute([$uid]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$row || (int)$row['is_locked'] === 1) {
                    $reason = $row && !empty($row['lock_reason']) ? " Lý do: {$row['lock_reason']}" : '';
                    self::$lastError = 'Tài khoản của bạn đã bị khóa bởi Quản trị viên.' . $reason;
                    self::$lastErrorCode = 'locked';
                    self::logout();
                    return false;
                }

                // Đồng bộ tức thì vai trò & thông tin (nếu Admin vừa cấp/thu hồi quyền)
                $_SESSION['username']     = $row['username'];
                $_SESSION['full_name']    = $row['full_name'];
                $_SESSION['email']        = $row['email'];
                $_SESSION['student_code'] = $row['student_code'];
                $_SESSION['class_name']   = $row['class_name'];
                $_SESSION['role']         = $row['role'];
                self::$validatedUserId    = $uid;
            } catch (Throwable $e) {
                // Giữ nguyên session nếu lỗi kết nối tạm thời
            }
        }

        return !empty($_SESSION['user_id']);
    }

    /**
     * Lấy ID người dùng hiện tại
     */
    public static function id(): ?int
    {
        self::startSession();
        return isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
    }

    /**
     * Lấy thông tin user hiện tại từ session
     */
    public static function user(): ?array
    {
        self::startSession();
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
     * Lấy vai trò của người dùng ('learner', 'instructor', 'admin')
     */
    public static function role(): string
    {
        self::startSession();
        if (!empty($_SESSION['user_id']) && self::$validatedUserId !== (int)$_SESSION['user_id']) {
            self::check();
        }
        return $_SESSION['role'] ?? 'guest';
    }

    public static function isAdmin(): bool
    {
        return self::role() === 'admin';
    }

    public static function isInstructor(): bool
    {
        return self::role() === 'instructor';
    }

    public static function isLearner(): bool
    {
        return self::role() === 'learner';
    }

    /**
     * Đăng nhập chính thức bằng username, email hoặc mã sinh viên
     */
    public static function attempt(string $identifier, string $password): bool
    {
        self::startSession();
        self::$lastError = null;
        self::$lastErrorCode = null;
        $pdo = getDbConnection();

        $stmt = $pdo->prepare("
            SELECT id, username, email, password_hash, full_name, student_code, class_name, role, is_locked, lock_reason
            FROM users 
            WHERE username = :id1 OR email = :id2 OR (student_code IS NOT NULL AND student_code = :id3)
            LIMIT 1
        ");
        $stmt->execute([
            'id1' => $identifier,
            'id2' => $identifier,
            'id3' => strtoupper($identifier)
        ]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user || !password_verify($password, $user['password_hash'])) {
            self::$lastError = 'Tên đăng nhập, email hoặc mật khẩu không chính xác.';
            self::$lastErrorCode = 'invalid_credentials';
            return false;
        }

        if ((int)$user['is_locked'] === 1) {
            $reason = !empty($user['lock_reason']) ? " Lý do: {$user['lock_reason']}" : '';
            self::$lastError = 'Tài khoản của bạn hiện đang bị khóa.' . $reason . ' Vui lòng liên hệ Quản trị viên.';
            self::$lastErrorCode = 'locked';
            return false;
        }

        // Đăng nhập thành công, tái tạo session id để chống Session Fixation
        if (session_status() === PHP_SESSION_ACTIVE && !headers_sent()) {
            @session_regenerate_id(true);
        }
        $_SESSION['user_id']      = (int)$user['id'];
        $_SESSION['username']     = $user['username'];
        $_SESSION['full_name']    = $user['full_name'];
        $_SESSION['email']        = $user['email'];
        $_SESSION['student_code'] = $user['student_code'];
        $_SESSION['class_name']   = $user['class_name'];
        $_SESSION['role']         = $user['role'];
        self::$validatedUserId    = (int)$user['id'];

        // Ghi nhận thời điểm đăng nhập gần nhất
        $pdo->prepare("UPDATE users SET last_login_at = NOW() WHERE id = ?")->execute([(int)$user['id']]);

        return true;
    }

    /**
     * Đăng ký tài khoản người dùng chính thức (Vai trò mặc định: learner - Sinh viên)
     *
     * @param array $input
     * @return array ['success' => bool, 'user_id' => ?int, 'error' => ?string]
     */
    public static function register(array $input): array
    {
        $username        = trim((string)($input['username'] ?? ''));
        $email           = strtolower(trim((string)($input['email'] ?? '')));
        $fullName        = trim((string)($input['full_name'] ?? ''));
        $studentCode     = strtoupper(trim((string)($input['student_code'] ?? '')));
        $className       = trim((string)($input['class_name'] ?? ''));
        $password        = (string)($input['password'] ?? '');
        $passwordConfirm = array_key_exists('password_confirm', $input) ? (string)$input['password_confirm'] : null;

        if ($fullName === '' || mb_strlen($fullName) < 2) {
            return ['success' => false, 'error' => 'Vui lòng nhập Họ và tên đầy đủ (tối thiểu 2 ký tự).'];
        }

        if (!preg_match('/^[a-zA-Z0-9_]{3,30}$/', $username)) {
            return ['success' => false, 'error' => 'Tên đăng nhập phải dài từ 3–30 ký tự, chỉ gồm chữ cái, chữ số và dấu gạch dưới (_).'];
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['success' => false, 'error' => 'Địa chỉ Email không đúng định dạng.'];
        }

        if ($studentCode !== '' && !preg_match('/^[A-Z0-9_-]{4,30}$/', $studentCode)) {
            return ['success' => false, 'error' => 'Mã sinh viên chỉ gồm chữ in hoa, số và dấu gạch ngang/gạch dưới (4–30 ký tự).'];
        }

        if (strlen($password) < 6) {
            return ['success' => false, 'error' => 'Mật khẩu phải có ít nhất 6 ký tự.'];
        }

        if ($passwordConfirm !== null && $password !== $passwordConfirm) {
            return ['success' => false, 'error' => 'Mật khẩu xác nhận không khớp.'];
        }

        $pdo = getDbConnection();

        // Kiểm tra trùng lặp username
        $chk = $pdo->prepare("SELECT id FROM users WHERE username = ? LIMIT 1");
        $chk->execute([$username]);
        if ($chk->fetch()) {
            return ['success' => false, 'error' => "Tên đăng nhập '{$username}' đã tồn tại. Vui lòng chọn tên khác."];
        }

        // Kiểm tra trùng lặp email
        $chk = $pdo->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
        $chk->execute([$email]);
        if ($chk->fetch()) {
            return ['success' => false, 'error' => "Email '{$email}' đã được đăng ký trước đó."];
        }

        // Kiểm tra trùng lặp mã sinh viên (nếu có nhập)
        if ($studentCode !== '') {
            $chk = $pdo->prepare("SELECT id FROM users WHERE student_code = ? LIMIT 1");
            $chk->execute([$studentCode]);
            if ($chk->fetch()) {
                return ['success' => false, 'error' => "Mã sinh viên '{$studentCode}' đã được đăng ký bởi tài khoản khác."];
            }
        }

        // Bảo mật: Tài khoản tự đăng ký LUÔN có vai trò 'learner', tuyệt đối không nhận role từ request
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
            $studentCode !== '' ? $studentCode : null,
            $className !== '' ? $className : null
        ]);

        return [
            'success' => true,
            'user_id' => (int)$pdo->lastInsertId(),
            'message' => 'Đăng ký tài khoản thành công! Bạn có thể đăng nhập ngay bây giờ.'
        ];
    }

    /**
     * Đăng xuất
     */
    public static function logout(): void
    {
        self::startSession();
        self::$validatedUserId = null;
        $_SESSION = [];
        if (ini_get("session.use_cookies") && !headers_sent()) {
            $params = session_get_cookie_params();
            @setcookie(session_name(), '', time() - 42000,
                $params["path"], $params["domain"],
                $params["secure"], $params["httponly"]
            );
        }
        if (session_status() === PHP_SESSION_ACTIVE) {
            @session_destroy();
        }
    }

    /**
     * Yêu cầu người dùng phải đăng nhập, nếu chưa thì chuyển hướng
     */
    public static function requireLogin(string $redirectUrl = '/BaiTapLon/login.php'): void
    {
        if (!self::check()) {
            $qs = self::$lastErrorCode === 'locked' ? '?locked=1' : '';
            header("Location: {$redirectUrl}{$qs}");
            exit;
        }
    }

    /**
     * Yêu cầu vai trò cụ thể
     */
    public static function requireRoles(array $allowedRoles): void
    {
        self::requireLogin();
        if (!in_array(self::role(), $allowedRoles, true)) {
            http_response_code(403);
            die("403 Forbidden - Bạn không có quyền truy cập chức năng này.");
        }
    }

    /**
     * Kiểm tra quyền xem chi tiết bài nộp & mã nguồn (Phép thử 5 - Rubric)
     * - Admin: Xem được tất cả.
     * - Chủ sở hữu (Owner): Xem được bài nộp của chính mình.
     * - Giảng viên: Xem được bài nộp của học viên trong lớp mình phụ trách, hoặc bài tập do mình tạo.
     * - Học viên khác: TUYỆT ĐỐI KHÔNG ĐƯỢC XEM (Trả về false).
     */
    public static function canViewSubmission(int $submissionUserId, ?int $classroomId = null, ?int $problemCreatorId = null): bool
    {
        $currentUserId = self::id();
        if ($currentUserId === null) {
            return false;
        }

        $currentRole = self::role();

        // Admin luôn có quyền
        if ($currentRole === 'admin') {
            return true;
        }

        // Chính chủ bài nộp luôn có quyền xem bài của mình
        if ($currentUserId === $submissionUserId) {
            return true;
        }

        // Giảng viên kiểm tra quyền theo lớp hoặc theo người tạo đề
        if ($currentRole === 'instructor') {
            if ($problemCreatorId !== null && $currentUserId === $problemCreatorId) {
                return true;
            }

            if ($classroomId !== null) {
                $pdo = getDbConnection();
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM classrooms WHERE id = ? AND instructor_id = ?");
                $stmt->execute([$classroomId, $currentUserId]);
                if ((int)$stmt->fetchColumn() > 0) {
                    return true;
                }
            }
        }

        // Người học A không được đọc bài của Người học B
        return false;
    }

    /**
     * Kiểm tra quyền quản lý / chỉnh sửa bài tập & tạo phiên bản mới
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
