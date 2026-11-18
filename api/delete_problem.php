<?php
/**
 * Nhom1 Online Judge - API Xóa & Khôi phục Bài tập Thông minh
 * POST /api/delete_problem.php
 * 
 * Hỗ trợ 3 chế độ an toàn chuẩn Online Judge:
 * - soft_delete: Xóa mềm (đặt deleted_at = NOW(), giải phóng slug gốc để không gây xung đột khi tạo lại bài mới).
 * - restore:     Khôi phục bài tập từ Thùng rác (đặt deleted_at = NULL, phục hồi slug gốc nếu còn trống).
 * - hard_delete: Xóa vĩnh viễn (CHỈ cho phép khi bài tập có 0 lượt nộp bài để bảo toàn dữ liệu học viên).
 */

// Bật chế độ kiểm tra kiểu dữ liệu nghiêm ngặt trong PHP
declare(strict_types=1);

// Khai báo phản hồi API luôn ở định dạng JSON mã hóa tiếng Việt UTF-8
header('Content-Type: application/json; charset=utf-8');

// Nạp các tệp cấu hình và lõi hệ thống: kết nối CSDL, xác thực/phân quyền (Auth), bảo mật (Security)
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../core/Auth.php';
require_once __DIR__ . '/../core/Security.php';

// Chủ động khởi động Session trước khi đọc thông tin tài khoản và vai trò người dùng
Auth::startSession();

// =========================================================================
// BƯỚC 1: KIỂM TRA QUYỀN TRUY CẬP (CHỈ ADMIN / GIẢNG VIÊN) & PHƯƠNG THỨC HTTP
// =========================================================================
// 1. Kiểm tra đăng nhập & vai trò
// Chặn khách chưa đăng nhập và sinh viên: chỉ Admin hoặc Giảng viên mới có quyền gọi API quản lý xóa bài tập
if (!Auth::check() || (!Auth::isAdmin() && !Auth::isInstructor())) {
    // HTTP 403 Forbidden: không có quyền thực hiện hành động này
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'error'   => '403 Forbidden - Bạn không có quyền thực hiện thao tác này.'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// Thao tác thay đổi/xóa dữ liệu chỉ được phép đi qua phương thức POST (tránh vô tình xóa qua link GET)
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    // HTTP 405 Method Not Allowed
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'error'   => 'Phương thức không hợp lệ (chỉ chấp nhận POST).'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// =========================================================================
// BƯỚC 2: NHẬN DỮ LIỆU ĐẦU VÀO (JSON / POST) & VALIDATE
// =========================================================================
// 2. Đọc dữ liệu đầu vào (Hỗ trợ cả JSON body và form POST)
$rawBody = file_get_contents('php://input');
$inputData = json_decode((string)$rawBody, true);
if (!is_array($inputData)) {
    $inputData = $_POST;
}

// Lấy chế độ hành động (mặc định là 'soft_delete' để ưu tiên an toàn) và ID bài tập cần xử lý
$action    = trim((string)($inputData['action'] ?? 'soft_delete'));
$problemId = (int)($inputData['problem_id'] ?? 0);

// Validate ID bài tập: phải là số nguyên dương (> 0)
if ($problemId <= 0) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error'   => 'ID bài tập không hợp lệ.'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// Validate `action`: chỉ chấp nhận đúng 3 giá trị trong danh sách cho phép
if (!in_array($action, ['soft_delete', 'hard_delete', 'restore'], true)) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error'   => 'Hành động không hợp lệ. Chỉ hỗ trợ: soft_delete, hard_delete, restore.'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// Bọc toàn bộ truy vấn và transaction vào try...catch để xử lý lỗi tập trung
try {
    // Mở kết nối PDO tới CSDL
    $pdo = getDbConnection();

    // =========================================================================
    // BƯỚC 3: TRUY VẤN THÔNG TIN BÀI TẬP & ĐẾM SỐ LƯỢT NỘP BÀI HIỆN CÓ
    // =========================================================================
    // 3. Truy vấn thông tin bài tập & số lượt nộp bài
    // Sử dụng subquery đếm số bản ghi trong `submissions` để biết bài tập này đã có sinh viên nộp hay chưa
    $stmt = $pdo->prepare("
        SELECT 
            p.id, p.slug, p.title, p.created_by, p.deleted_at,
            (SELECT COUNT(*) FROM submissions s WHERE s.problem_id = p.id) AS total_submissions
        FROM problems p
        WHERE p.id = ?
    ");
    $stmt->execute([$problemId]);
    $problem = $stmt->fetch(PDO::FETCH_ASSOC);

    // Không tìm thấy bài tập theo ID -> trả về HTTP 404 Not Found
    if (!$problem) {
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'error'   => 'Không tìm thấy bài tập yêu cầu.'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // =========================================================================
    // BƯỚC 4: KIỂM TRA QUYỀN SỞ HỮU BÀI TẬP
    // =========================================================================
    // 4. Kiểm tra quyền sở hữu (Admin toàn quyền; Giảng viên chỉ được xóa bài do mình tạo)
    // Auth::canManageProblem() đối chiếu vai trò Admin hoặc so khớp Auth::id() với cột `created_by`
    if (!Auth::canManageProblem((int)$problem['created_by'])) {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'error'   => 'Bạn không có quyền quản lý hoặc xóa bài tập này (chỉ người tạo hoặc Quản trị viên mới có quyền).'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Lưu tổng số lượt nộp bài vào biến số nguyên để tái sử dụng ở các nhánh xử lý bên dưới
    $totalSubmissions = (int)$problem['total_submissions'];

    // =========================================================================
    // TRƯỜNG HỢP 1: XÓA MỀM (SOFT DELETE)
    // =========================================================================
    if ($action === 'soft_delete') {
        // Nếu bài tập đã có ngày xóa (`deleted_at` khác NULL) -> trả về thành công ngay (tránh thao tác dư thừa)
        if ($problem['deleted_at'] !== null) {
            echo json_encode([
                'success' => true,
                'mode'    => 'soft_delete',
                'message' => 'Bài tập này đã nằm trong Thùng rác từ trước.'
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        // Giải phóng slug gốc bằng cách gắn hậu tố -deleted-<timestamp>
        // Giúp người dùng có thể tạo mới bài tập trùng slug sau này mà không bị lỗi UNIQUE
        // Cắt ngắn phần slug gốc tối đa 72 ký tự để sau khi nối hậu tố không vượt quá độ dài cột VARCHAR trong CSDL
        $baseSlug = preg_replace('/-deleted-\d+$/', '', (string)$problem['slug']);
        $baseSlug = substr((string)$baseSlug, 0, 72);
        $releasedSlug = $baseSlug . '-deleted-' . time();

        // Đảm bảo slug mới không trùng lặp
        // Nếu tình cờ cùng 1 giây có bài khác mang slug giống hệt, nối thêm `-$problemId` để chắc chắn duy nhất
        $chkStmt = $pdo->prepare("SELECT COUNT(*) FROM problems WHERE slug = ? AND id != ?");
        $chkStmt->execute([$releasedSlug, $problemId]);
        if ((int)$chkStmt->fetchColumn() > 0) {
            $releasedSlug .= '-' . $problemId;
        }

        // Cập nhật `deleted_at = NOW()` (đưa vào Thùng rác) và đổi `slug` sang slug đã giải phóng
        $upd = $pdo->prepare("
            UPDATE problems 
            SET deleted_at = NOW(), slug = ? 
            WHERE id = ?
        ");
        $upd->execute([$releasedSlug, $problemId]);

        // Trả về JSON (HTTP 200 OK) gồm chế độ thực hiện, slug mới sau khi giải phóng, tổng số bài nộp được bảo toàn và thông báo chi tiết
        echo json_encode([
            'success'           => true,
            'mode'              => 'soft_delete',
            'problem_id'        => $problemId,
            'released_slug'     => $releasedSlug,
            'total_submissions' => $totalSubmissions,
            'message'           => "Đã đưa bài tập \"{$problem['title']}\" vào Thùng rác (Xóa mềm). "
                . ($totalSubmissions > 0
                    ? "Toàn bộ {$totalSubmissions} lượt nộp bài và lịch sử điểm số của học viên vẫn được bảo toàn nguyên vẹn."
                    : "Bạn có thể khôi phục lại bất kỳ lúc nào hoặc xóa vĩnh viễn trong mục Thùng rác.")
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // =========================================================================
    // TRƯỜNG HỢP 2: KHÔI PHỤC BÀI TẬP (RESTORE)
    // =========================================================================
    if ($action === 'restore') {
        // Nếu bài tập chưa bị xóa (`deleted_at` là NULL) -> trả về thành công luôn
        if ($problem['deleted_at'] === null) {
            echo json_encode([
                'success' => true,
                'mode'    => 'restore',
                'message' => 'Bài tập này hiện đang hoạt động bình thường.'
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        // Cố gắng phục hồi lại slug ban đầu
        // Dùng Regex gỡ bỏ hậu tố `-deleted-<timestamp>` (và `-<id>` nếu có) đã thêm lúc xóa mềm
        $originalSlug = preg_replace('/-deleted-\d+(-\d+)?$/', '', (string)$problem['slug']);
        if ($originalSlug === '' || $originalSlug === null) {
            $originalSlug = 'problem-' . $problemId;
        }

        // Kiểm tra xem slug gốc đã bị bài tập mới nào chiếm dụng chưa
        $chkStmt = $pdo->prepare("SELECT COUNT(*) FROM problems WHERE slug = ? AND id != ?");
        $chkStmt->execute([$originalSlug, $problemId]);
        $restoredSlug = $originalSlug;
        $slugChangedNote = '';

        if ((int)$chkStmt->fetchColumn() > 0) {
            // Slug cũ đã có bài mới lấy -> cấp slug mới không đụng độ
            // Thử lần lượt `-restored-1`, `-restored-2`, ... cho đến khi tìm được slug chưa tồn tại trong CSDL
            $counter = 1;
            do {
                $candidate = substr($originalSlug, 0, 82) . '-restored-' . $counter;
                $chkStmt->execute([$candidate, $problemId]);
                $exists = (int)$chkStmt->fetchColumn() > 0;
                $counter++;
            } while ($exists && $counter < 100);
            $restoredSlug = $candidate;
            $slugChangedNote = " (Lưu ý: Slug gốc '{$originalSlug}' đã được bài khác sử dụng nên bài tập được cấp slug mới là '{$restoredSlug}')";
        }

        // Đặt lại `deleted_at = NULL` (khôi phục trạng thái hoạt động) và cập nhật slug đã chốt
        $upd = $pdo->prepare("
            UPDATE problems 
            SET deleted_at = NULL, slug = ? 
            WHERE id = ?
        ");
        $upd->execute([$restoredSlug, $problemId]);

        // Trả về JSON (HTTP 200 OK) kèm `restored_slug` và thông báo (có ghi chú nếu slug bị đổi do đụng độ)
        echo json_encode([
            'success'       => true,
            'mode'          => 'restore',
            'problem_id'    => $problemId,
            'restored_slug' => $restoredSlug,
            'message'       => "Đã khôi phục thành công bài tập \"{$problem['title']}\" về trạng thái đang hoạt động!{$slugChangedNote}"
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // =========================================================================
    // TRƯỜNG HỢP 3: XÓA VĨNH VIỄN (HARD DELETE - CHỈ KHI 0 BÀI NỘP)
    // =========================================================================
    if ($action === 'hard_delete') {
        // Ràng buộc nghiệp vụ bảo vệ dữ liệu học viên: nếu đã có ít nhất 1 bài nộp -> cấm xóa vĩnh viễn (HTTP 400)
        if ($totalSubmissions > 0) {
            http_response_code(400);
            echo json_encode([
                'success'           => false,
                'error'             => "Không thể xóa vĩnh viễn bài tập đã có {$totalSubmissions} lượt nộp bài của học viên! Theo nguyên tắc toàn vẹn dữ liệu Online Judge, chỉ được phép Xóa mềm (Soft Delete).",
                'total_submissions' => $totalSubmissions
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        // Bắt đầu Transaction để xóa sạch theo đúng thứ tự phụ thuộc khóa ngoại (con trước, cha sau).
        // Nếu bất kỳ lệnh DELETE nào thất bại, toàn bộ thao tác sẽ được rollBack() để không để lại dữ liệu mồ côi.
        $pdo->beginTransaction();

        // 1. Gỡ liên kết current_version_id để tránh vòng lặp khóa ngoại
        // Vì `problems.current_version_id` trỏ tới `problem_versions.id` và ngược lại `problem_versions.problem_id` trỏ tới `problems.id`
        $pdo->prepare("UPDATE problems SET current_version_id = NULL WHERE id = ?")->execute([$problemId]);

        // 2. Xóa các bản nháp mã nguồn liên quan (nếu có)
        $pdo->prepare("DELETE FROM code_drafts WHERE problem_id = ?")->execute([$problemId]);

        // 3. Xóa các test cases thuộc tất cả các phiên bản của bài tập này
        $pdo->prepare("
            DELETE tc FROM test_cases tc
            JOIN problem_versions pv ON tc.problem_version_id = pv.id
            WHERE pv.problem_id = ?
        ")->execute([$problemId]);

        // 4. Xóa các phiên bản bài tập
        $pdo->prepare("DELETE FROM problem_versions WHERE problem_id = ?")->execute([$problemId]);

        // 5. Xóa bài tập gốc
        $pdo->prepare("DELETE FROM problems WHERE id = ?")->execute([$problemId]);

        // Xác nhận hoàn tất Transaction xóa vĩnh viễn
        $pdo->commit();

        // Trả về JSON (HTTP 200 OK) xác nhận bài tập và toàn bộ dữ liệu phụ thuộc đã bị xóa hoàn toàn
        echo json_encode([
            'success'    => true,
            'mode'       => 'hard_delete',
            'problem_id' => $problemId,
            'message'    => "Đã xóa vĩnh viễn bài tập \"{$problem['title']}\" cùng toàn bộ phiên bản và test cases khỏi hệ thống."
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

} catch (Throwable $e) {
    // Nếu xảy ra lỗi ngoại lệ khi đang trong Transaction (ví dụ trong hard_delete), hoàn tác (rollBack) để bảo toàn CSDL
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    // Trả về HTTP 500 Internal Server Error kèm thông tin lỗi
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error'   => 'Lỗi hệ thống khi xử lý yêu cầu xóa bài tập: ' . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
    exit;
}
