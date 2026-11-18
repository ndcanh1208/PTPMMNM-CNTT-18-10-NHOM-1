<?php
/**
 * ByteFlow Online Judge - API Quản lý Người dùng, Phân quyền & Khóa Tài khoản (Admin Only)
 * POST /api/admin_users.php
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../core/Auth.php';

Auth::startSession();

if (!Auth::check() || !Auth::isAdmin()) {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'error'   => '403 Forbidden - Chỉ Quản trị viên (Admin) mới có quyền quản lý tài khoản người dùng.'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Phương thức không hợp lệ (chỉ chấp nhận POST).'], JSON_UNESCAPED_UNICODE);
    exit;
}

$rawBody = file_get_contents('php://input');
$input = json_decode((string)$rawBody, true);
if (!is_array($input)) {
    $input = $_POST;
}

$action = trim((string)($input['action'] ?? ''));
$targetUserId = (int)($input['user_id'] ?? 0);
$currentAdminId = (int)Auth::id();

if ($targetUserId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'ID người dùng không hợp lệ.'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $pdo = getDbConnection();
    $uStmt = $pdo->prepare("SELECT id, username, full_name, role, is_locked FROM users WHERE id = ? LIMIT 1");
    $uStmt->execute([$targetUserId]);
    $targetUser = $uStmt->fetch(PDO::FETCH_ASSOC);

    if (!$targetUser) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Không tìm thấy tài khoản người dùng.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // =========================================================================
    // 1. CẤP QUYỀN / THU HỒI QUYỀN QUẢN TRỊ (ROLE MANAGEMENT)
    // =========================================================================
    if ($action === 'update_role') {
        $newRole = trim((string)($input['role'] ?? ''));
        if (!in_array($newRole, ['learner', 'instructor', 'admin'], true)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Vai trò không hợp lệ (chỉ nhận: learner, instructor, admin).'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        // Bảo vệ: Admin không được tự thu hồi quyền Admin của chính mình
        if ($targetUserId === $currentAdminId && $newRole !== 'admin') {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'error'   => 'Không thể tự thu hồi quyền Quản trị viên (Admin) của chính tài khoản đang đăng nhập.'
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        // Bảo vệ: Không được thu hồi quyền Admin cuối cùng đang hoạt động
        if ($targetUser['role'] === 'admin' && $newRole !== 'admin') {
            $adminCount = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role = 'admin' AND is_locked = 0")->fetchColumn();
            if ($adminCount <= 1) {
                http_response_code(400);
                echo json_encode([
                    'success' => false,
                    'error'   => 'Hệ thống phải có ít nhất 1 Quản trị viên đang hoạt động.'
                ], JSON_UNESCAPED_UNICODE);
                exit;
            }
        }

        $pdo->prepare("UPDATE users SET role = ? WHERE id = ?")->execute([$newRole, $targetUserId]);

        $roleLabels = [
            'admin'      => 'Quản trị viên (Admin)',
            'instructor' => 'Giảng viên / Trợ giảng',
            'learner'    => 'Sinh viên'
        ];

        echo json_encode([
            'success'  => true,
            'user_id'  => $targetUserId,
            'new_role' => $newRole,
            'message'  => "Đã cập nhật quyền cho tài khoản @{$targetUser['username']} thành {$roleLabels[$newRole]}."
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // =========================================================================
    // 2. KHÓA / MỞ KHÓA TÀI KHOẢN (VI PHẠM / GIAN LẬN)
    // =========================================================================
    if ($action === 'toggle_lock') {
        $lock = !empty($input['lock']) ? 1 : 0;
        $reason = trim((string)($input['lock_reason'] ?? ''));

        if ($targetUserId === $currentAdminId && $lock === 1) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'error'   => 'Không thể tự khóa chính tài khoản Quản trị viên đang đăng nhập.'
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        if ($lock === 1 && $reason === '') {
            $reason = 'Vi phạm quy chế luyện tập / thi cử';
        }

        $pdo->prepare("UPDATE users SET is_locked = ?, lock_reason = ? WHERE id = ?")
            ->execute([$lock, $lock ? $reason : null, $targetUserId]);

        echo json_encode([
            'success'   => true,
            'user_id'   => $targetUserId,
            'is_locked' => $lock,
            'message'   => $lock
                ? "Đã khóa tài khoản @{$targetUser['username']} ({$reason}). Người dùng sẽ lập tức không thể đăng nhập hoặc nộp bài."
                : "Đã mở khóa tài khoản @{$targetUser['username']}. Người dùng có thể đăng nhập trở lại bình thường."
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // =========================================================================
    // 3. CẬP NHẬT THÔNG TIN SINH VIÊN (MÃ SINH VIÊN, LỚP, HỌ TÊN)
    // =========================================================================
    if ($action === 'update_profile') {
        $fullName    = trim((string)($input['full_name'] ?? $targetUser['full_name']));
        $studentCode = strtoupper(trim((string)($input['student_code'] ?? '')));
        $className   = trim((string)($input['class_name'] ?? ''));

        if ($fullName === '') {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Họ và tên không được để trống.'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        if ($studentCode !== '') {
            $chk = $pdo->prepare("SELECT id FROM users WHERE student_code = ? AND id != ? LIMIT 1");
            $chk->execute([$studentCode, $targetUserId]);
            if ($chk->fetch()) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => "Mã sinh viên '{$studentCode}' đã thuộc về tài khoản khác."], JSON_UNESCAPED_UNICODE);
                exit;
            }
        }

        $pdo->prepare("UPDATE users SET full_name = ?, student_code = ?, class_name = ? WHERE id = ?")
            ->execute([$fullName, $studentCode !== '' ? $studentCode : null, $className !== '' ? $className : null, $targetUserId]);

        echo json_encode([
            'success' => true,
            'message' => "Đã cập nhật hồ sơ cho tài khoản @{$targetUser['username']}."
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    http_response_code(400);
    echo json_encode(['success' => false, 'error' => "Hành động '{$action}' không hợp lệ."], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error'   => 'Lỗi hệ thống khi cập nhật tài khoản: ' . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
