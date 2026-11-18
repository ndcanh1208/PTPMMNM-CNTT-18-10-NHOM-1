<?php
/**
 * Nhom1 Online Judge - API Quản lý Người dùng, Phân quyền & Khóa Tài khoản (Admin Only)
 * POST /api/admin_users.php
 *
 * Tổng quan chức năng của API:
 * 1. Xác thực phiên đăng nhập và chỉ cho phép Quản trị viên (Admin) thao tác bằng phương thức POST.
 * 2. Đọc tham số hành động (`action`) và ID tài khoản mục tiêu (`user_id`), kiểm tra tồn tại trong CSDL.
 * 3. Phân nhánh thực hiện 3 nghiệp vụ quản trị tài khoản:
 *    - `update_role`   : Thay đổi vai trò (Sinh viên / Giảng viên / Quản trị viên) kèm cơ chế tự bảo vệ Admin.
 *    - `toggle_lock`   : Khóa hoặc mở khóa tài khoản vi phạm (chặn Admin tự khóa chính mình).
 *    - `update_profile`: Cập nhật họ tên, mã sinh viên (kiểm tra trùng lặp) và lớp học.
 */

// Bật chế độ kiểm tra kiểu dữ liệu nghiêm ngặt trong PHP.
declare(strict_types=1);

// Khai báo phản hồi dạng JSON mã hóa UTF-8 để hiển thị đúng tiếng Việt.
header('Content-Type: application/json; charset=utf-8');

// Nạp file cấu hình kết nối CSDL và lớp quản lý xác thực/phân quyền.
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../core/Auth.php';

// Khởi động Session để truy xuất thông tin tài khoản hiện đang đăng nhập.
Auth::startSession();

// Bước 1: Kiểm tra đăng nhập & Phân quyền (CHỈ Admin được phép quản lý tài khoản người dùng).
// Nếu chưa đăng nhập hoặc không có quyền Admin -> trả về HTTP 403 (Forbidden) và dừng ngay.
if (!Auth::check() || !Auth::isAdmin()) {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'error'   => '403 Forbidden - Chỉ Quản trị viên (Admin) mới có quyền quản lý tài khoản người dùng.'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// Bước 2: Chỉ chấp nhận phương thức HTTP POST vì mọi thao tác ở đây đều làm thay đổi dữ liệu người dùng.
// Nếu client gọi bằng GET/PUT/... -> trả về HTTP 405 (Method Not Allowed).
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Phương thức không hợp lệ (chỉ chấp nhận POST).'], JSON_UNESCAPED_UNICODE);
    exit;
}

// Bước 3: Đọc dữ liệu đầu vào từ body dạng JSON (nếu không phải JSON thì lấy từ form $_POST).
$rawBody = file_get_contents('php://input');
$input = json_decode((string)$rawBody, true);
if (!is_array($input)) {
    $input = $_POST;
}

// Lấy tên hành động, ID tài khoản cần chỉnh sửa và ID của chính Admin đang thực hiện thao tác.
$action = trim((string)($input['action'] ?? ''));
$targetUserId = (int)($input['user_id'] ?? 0);
$currentAdminId = (int)Auth::id();

// Kiểm tra `user_id` phải là số nguyên dương hợp lệ.
if ($targetUserId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'ID người dùng không hợp lệ.'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    // Kết nối CSDL và tìm thông tin tài khoản mục tiêu bằng Prepared Statement (chống SQL Injection).
    $pdo = getDbConnection();
    $uStmt = $pdo->prepare("SELECT id, username, full_name, role, is_locked FROM users WHERE id = ? LIMIT 1");
    $uStmt->execute([$targetUserId]);
    $targetUser = $uStmt->fetch(PDO::FETCH_ASSOC);

    // Nếu không tìm thấy tài khoản tương ứng với `user_id`, trả về mã HTTP 404 (Not Found).
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
        // Kiểm tra vai trò mới phải nằm trong danh sách trắng 3 giá trị hợp lệ (tránh gửi giá trị rác).
        if (!in_array($newRole, ['learner', 'instructor', 'admin'], true)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Vai trò không hợp lệ (chỉ nhận: learner, instructor, admin).'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        // Bảo vệ 1: Admin không được tự thu hồi quyền Admin của chính mình (tránh tự khóa mất đường quản trị).
        if ($targetUserId === $currentAdminId && $newRole !== 'admin') {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'error'   => 'Không thể tự thu hồi quyền Quản trị viên (Admin) của chính tài khoản đang đăng nhập.'
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        // Bảo vệ 2: Nếu đang hạ quyền một Admin khác, phải đảm bảo hệ thống vẫn còn ít nhất 1 Admin đang hoạt động (chưa bị khóa).
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

        // Cập nhật cột `role` mới vào bảng `users`.
        $pdo->prepare("UPDATE users SET role = ? WHERE id = ?")->execute([$newRole, $targetUserId]);

        // Bảng ánh xạ mã vai trò sang tên tiếng Việt để tạo thông báo dễ đọc cho giao diện.
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
    // Khi `is_locked = 1`, người dùng sẽ bị chặn đăng nhập và chặn nộp bài ở các API khác.
    // =========================================================================
    if ($action === 'toggle_lock') {
        // Chuẩn hóa cờ khóa về số nguyên: 1 (khóa) hoặc 0 (mở khóa).
        $lock = !empty($input['lock']) ? 1 : 0;
        $reason = trim((string)($input['lock_reason'] ?? ''));

        // Bảo vệ: Admin không thể tự khóa chính tài khoản đang đăng nhập của mình.
        if ($targetUserId === $currentAdminId && $lock === 1) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'error'   => 'Không thể tự khóa chính tài khoản Quản trị viên đang đăng nhập.'
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        // Nếu khóa tài khoản mà Admin không nhập lý do cụ thể -> dùng lý do mặc định.
        if ($lock === 1 && $reason === '') {
            $reason = 'Vi phạm quy chế luyện tập / thi cử';
        }

        // Lưu trạng thái khóa vào CSDL; khi mở khóa (`$lock = 0`) sẽ xóa `lock_reason` về NULL cho sạch dữ liệu.
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
        // Lấy dữ liệu hồ sơ gửi lên:
        // - `full_name`   : nếu không truyền thì giữ nguyên tên hiện tại.
        // - `student_code`: tự động chuyển sang CHỮ IN HOA để đồng nhất dữ liệu (vd: b21dccn001 -> B21DCCN001).
        // - `class_name`  : cắt bỏ khoảng trắng thừa hai đầu.
        $fullName    = trim((string)($input['full_name'] ?? $targetUser['full_name']));
        $studentCode = strtoupper(trim((string)($input['student_code'] ?? '')));
        $className   = trim((string)($input['class_name'] ?? ''));

        // Họ và tên là trường bắt buộc, không được bỏ trống.
        if ($fullName === '') {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Họ và tên không được để trống.'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        // Nếu có nhập mã sinh viên, kiểm tra tính duy nhất (không được trùng với bất kỳ tài khoản nào khác `id != ?`).
        if ($studentCode !== '') {
            $chk = $pdo->prepare("SELECT id FROM users WHERE student_code = ? AND id != ? LIMIT 1");
            $chk->execute([$studentCode, $targetUserId]);
            if ($chk->fetch()) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => "Mã sinh viên '{$studentCode}' đã thuộc về tài khoản khác."], JSON_UNESCAPED_UNICODE);
                exit;
            }
        }

        // Cập nhật hồ sơ vào bảng `users` (nếu `student_code` hoặc `class_name` để trống thì lưu NULL thay vì chuỗi rỗng).
        $pdo->prepare("UPDATE users SET full_name = ?, student_code = ?, class_name = ? WHERE id = ?")
            ->execute([$fullName, $studentCode !== '' ? $studentCode : null, $className !== '' ? $className : null, $targetUserId]);

        echo json_encode([
            'success' => true,
            'message' => "Đã cập nhật hồ sơ cho tài khoản @{$targetUser['username']}."
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Nếu tham số `action` không khớp 3 hành động trên -> trả về lỗi HTTP 400 (Bad Request).
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => "Hành động '{$action}' không hợp lệ."], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    // Bắt mọi lỗi hệ thống/CSDL phát sinh và trả về mã HTTP 500 kèm thông báo lỗi rõ ràng.
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error'   => 'Lỗi hệ thống khi cập nhật tài khoản: ' . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}