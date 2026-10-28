<?php
/**
 * API LƯU BẢN NHÁP TỰ ĐỘNG (AUTO-SAVE DRAFT)
 * Đề tài 20: Mục 2.2 - Lưu bản nháp khi người học đang soạn thảo trên trình duyệt
 */

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Chỉ chấp nhận phương thức POST']);
    exit;
}

$rawInput = file_get_contents('php://input');
$data = json_decode($rawInput, true);

$problemId  = (int)($data['problem_id'] ?? 0);
$languageId = trim($data['language_id'] ?? 'python3');
$draftCode  = $data['draft_code'] ?? '';
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$userId = $_SESSION['user']['id'] ?? (int)($data['user_id'] ?? 3);

if ($problemId <= 0) {
    echo json_encode(['success' => false, 'message' => 'Thiếu mã bài tập']);
    exit;
}

try {
    $pdo = Database::getConnection();
    $stmt = $pdo->prepare("
        INSERT INTO code_drafts (user_id, problem_id, language_id, draft_code, updated_at)
        VALUES (?, ?, ?, ?, NOW())
        ON DUPLICATE KEY UPDATE draft_code = VALUES(draft_code), updated_at = NOW()
    ");
    $stmt->execute([$userId, $problemId, $languageId, $draftCode]);

    echo json_encode([
        'success'    => true,
        'message'    => 'Đã lưu bản nháp',
        'saved_time' => date('H:i:s')
    ]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
