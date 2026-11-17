<?php
/**
 * ByteFlow Online Judge - API Xóa & Khôi phục Bài tập Thông minh
 * POST /api/delete_problem.php
 * 
 * Hỗ trợ 3 chế độ an toàn chuẩn Online Judge:
 * - soft_delete: Xóa mềm (đặt deleted_at = NOW(), giải phóng slug gốc để không gây xung đột khi tạo lại bài mới).
 * - restore:     Khôi phục bài tập từ Thùng rác (đặt deleted_at = NULL, phục hồi slug gốc nếu còn trống).
 * - hard_delete: Xóa vĩnh viễn (CHỈ cho phép khi bài tập có 0 lượt nộp bài để bảo toàn dữ liệu học viên).
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../core/Auth.php';
require_once __DIR__ . '/../core/Security.php';

Auth::startSession();

// 1. Kiểm tra đăng nhập & vai trò
if (!Auth::check() || (!Auth::isAdmin() && !Auth::isInstructor())) {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'error'   => '403 Forbidden - Bạn không có quyền thực hiện thao tác này.'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'error'   => 'Phương thức không hợp lệ (chỉ chấp nhận POST).'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// 2. Đọc dữ liệu đầu vào (Hỗ trợ cả JSON body và form POST)
$rawBody = file_get_contents('php://input');
$inputData = json_decode((string)$rawBody, true);
if (!is_array($inputData)) {
    $inputData = $_POST;
}

$action    = trim((string)($inputData['action'] ?? 'soft_delete'));
$problemId = (int)($inputData['problem_id'] ?? 0);

if ($problemId <= 0) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error'   => 'ID bài tập không hợp lệ.'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!in_array($action, ['soft_delete', 'hard_delete', 'restore'], true)) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error'   => 'Hành động không hợp lệ. Chỉ hỗ trợ: soft_delete, hard_delete, restore.'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $pdo = getDbConnection();

    // 3. Truy vấn thông tin bài tập & số lượt nộp bài
    $stmt = $pdo->prepare("
        SELECT 
            p.id, p.slug, p.title, p.created_by, p.deleted_at,
            (SELECT COUNT(*) FROM submissions s WHERE s.problem_id = p.id) AS total_submissions
        FROM problems p
        WHERE p.id = ?
    ");
    $stmt->execute([$problemId]);
    $problem = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$problem) {
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'error'   => 'Không tìm thấy bài tập yêu cầu.'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // 4. Kiểm tra quyền sở hữu (Admin toàn quyền; Giảng viên chỉ được xóa bài do mình tạo)
    if (!Auth::canManageProblem((int)$problem['created_by'])) {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'error'   => 'Bạn không có quyền quản lý hoặc xóa bài tập này (chỉ người tạo hoặc Quản trị viên mới có quyền).'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $totalSubmissions = (int)$problem['total_submissions'];

    // =========================================================================
    // TRƯỜNG HỢP 1: XÓA MỀM (SOFT DELETE)
    // =========================================================================
    if ($action === 'soft_delete') {
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
        $baseSlug = preg_replace('/-deleted-\d+$/', '', (string)$problem['slug']);
        $baseSlug = substr((string)$baseSlug, 0, 72);
        $releasedSlug = $baseSlug . '-deleted-' . time();

        // Đảm bảo slug mới không trùng lặp
        $chkStmt = $pdo->prepare("SELECT COUNT(*) FROM problems WHERE slug = ? AND id != ?");
        $chkStmt->execute([$releasedSlug, $problemId]);
        if ((int)$chkStmt->fetchColumn() > 0) {
            $releasedSlug .= '-' . $problemId;
        }

        $upd = $pdo->prepare("
            UPDATE problems 
            SET deleted_at = NOW(), slug = ? 
            WHERE id = ?
        ");
        $upd->execute([$releasedSlug, $problemId]);

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
        if ($problem['deleted_at'] === null) {
            echo json_encode([
                'success' => true,
                'mode'    => 'restore',
                'message' => 'Bài tập này hiện đang hoạt động bình thường.'
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        // Cố gắng phục hồi lại slug ban đầu
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

        $upd = $pdo->prepare("
            UPDATE problems 
            SET deleted_at = NULL, slug = ? 
            WHERE id = ?
        ");
        $upd->execute([$restoredSlug, $problemId]);

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
        if ($totalSubmissions > 0) {
            http_response_code(400);
            echo json_encode([
                'success'           => false,
                'error'             => "Không thể xóa vĩnh viễn bài tập đã có {$totalSubmissions} lượt nộp bài của học viên! Theo nguyên tắc toàn vẹn dữ liệu Online Judge, chỉ được phép Xóa mềm (Soft Delete).",
                'total_submissions' => $totalSubmissions
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $pdo->beginTransaction();

        // 1. Gỡ liên kết current_version_id để tránh vòng lặp khóa ngoại
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

        $pdo->commit();

        echo json_encode([
            'success'    => true,
            'mode'       => 'hard_delete',
            'problem_id' => $problemId,
            'message'    => "Đã xóa vĩnh viễn bài tập \"{$problem['title']}\" cùng toàn bộ phiên bản và test cases khỏi hệ thống."
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error'   => 'Lỗi hệ thống khi xử lý yêu cầu xóa bài tập: ' . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
    exit;
}
