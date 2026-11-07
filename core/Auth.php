<?php
/**
 * ByteFlow Online Judge - Module Xác thực & Phân quyền (Auth)
 * Kiểm soát quyền riêng tư: Người A không được xem mã của Người B
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

class Auth
{
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
     * Kiểm tra trạng thái đăng nhập
     */
    public static function check(): bool
    {
        self::startSession();
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
            'id'        => (int)$_SESSION['user_id'],
            'username'  => $_SESSION['username'] ?? '',
            'full_name' => $_SESSION['full_name'] ?? '',
            'email'     => $_SESSION['email'] ?? '',
            'role'      => $_SESSION['role'] ?? 'learner'
        ];
    }

    /**
     * Lấy vai trò của người dùng ('learner', 'instructor', 'admin')
     */
    public static function role(): string
    {
        self::startSession();
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
     * Đăng nhập người dùng bằng username/email và mật khẩu
     */
    public static function attempt(string $identifier, string $password): bool
    {
        self::startSession();
        $pdo = getDbConnection();

        $stmt = $pdo->prepare("
            SELECT id, username, email, password_hash, full_name, role 
            FROM users 
            WHERE username = :id1 OR email = :id2 
            LIMIT 1
        ");
        $stmt->execute(['id1' => $identifier, 'id2' => $identifier]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password_hash'])) {
            // Đăng nhập thành công, tái tạo session id để chống Session Fixation
            session_regenerate_id(true);
            $_SESSION['user_id']   = (int)$user['id'];
            $_SESSION['username']  = $user['username'];
            $_SESSION['full_name'] = $user['full_name'];
            $_SESSION['email']     = $user['email'];
            $_SESSION['role']      = $user['role'];
            return true;
        }

        return false;
    }

    /**
     * Đăng xuất
     */
    public static function logout(): void
    {
        self::startSession();
        $_SESSION = [];
        if (ini_get("session.use_cookies")) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000,
                $params["path"], $params["domain"],
                $params["secure"], $params["httponly"]
            );
        }
        session_destroy();
    }

    /**
     * Yêu cầu người dùng phải đăng nhập, nếu chưa thì chuyển hướng
     */
    public static function requireLogin(string $redirectUrl = 'login.php'): void
    {
        if (!self::check()) {
            header("Location: {$redirectUrl}");
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
