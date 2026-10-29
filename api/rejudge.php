<?php
/**
 * API CHẤM LẠI BÀI NỘP (REJUDGE SUBMISSION)
 * Đề tài 20: Chức năng 5 & Tình huống 2 - Giảng viên chấm lại bài nộp và bảo tồn lịch sử
 */

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../core/JudgeCoordinator.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Chỉ chấp nhận phương thức POST']);
    exit;
}

$rawInput = file_get_contents('php://input');
$data = json_decode($rawInput, true);

$submissionId = (int)($data['submission_id'] ?? 0);
$targetVersionId = isset($data['target_version_id']) ? (int)$data['target_version_id'] : null;

if ($submissionId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Thiếu mã bài nộp (submission_id)']);
    exit;
}

try {
    $pdo = Database::getConnection();

    // Kiểm tra quyền: Chỉ Giảng viên hoặc Quản trị viên mới được thực hiện Chấm lại
    $currentUser = getCurrentUser();
    $role = $currentUser['role'] ?? ($data['role'] ?? 'LECTURER');
    if (strtoupper($role) !== 'ADMIN' && strtoupper($role) !== 'LECTURER') {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Chỉ Giảng viên hoặc Quản trị viên mới có quyền chấm lại bài nộp']);
        exit;
    }

    // Thực hiện điều phối chấm lại (Tạo Run #2, bảo lưu Run #1)
    $result = JudgeCoordinator::judge($submissionId, $targetVersionId);

    echo json_encode([
        'success' => true,
        'message' => 'Đã chấm lại bài nộp thành công',
        'result'  => $result
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
