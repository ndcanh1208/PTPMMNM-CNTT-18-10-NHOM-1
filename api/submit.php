<?php
/**
 * API NHẬN BÀI NỘP CHÍNH THỨC & KÍCH HOẠT CHẤM (SUBMIT CODE)
 * Đề tài 20: Chống nộp trùng (Idempotency Key), gắn đúng phiên bản đề và tự điều phối chấm
 */

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../core/JudgeCoordinator.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Chỉ chấp nhận phương thức POST']);
    exit;
}

$rawInput = file_get_contents('php://input');
$data = json_decode($rawInput, true);

if (!$data) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Dữ liệu JSON không hợp lệ']);
    exit;
}

$problemId      = (int)($data['problem_id'] ?? 0);
$languageId     = trim($data['language_id'] ?? 'python3');
$sourceCode     = trim($data['source_code'] ?? '');
$idempotencyKey = trim($data['idempotency_key'] ?? '');
$userId         = (int)($data['user_id'] ?? 3); // Mặc định là tài khoản sinh viên mẫu (ID: 3)

if ($problemId <= 0 || empty($sourceCode)) {
    echo json_encode(['success' => false, 'message' => 'Thiếu mã bài tập hoặc mã nguồn nộp']);
    exit;
}

// Nếu client không gửi idempotency_key, tự sinh bằng UUID v4
if (empty($idempotencyKey)) {
    $idempotencyKey = bin2hex(random_bytes(16));
}

try {
    $pdo = Database::getConnection();

    // 1. KIỂM TRA CHỐNG NỘP TRÙNG (PHÉP THỬ 3)
    // Nếu yêu cầu gửi lại có cùng idempotency_key thì trả về đúng bản nộp cũ, không tạo bản ghi mới
    $stmtCheck = $pdo->prepare("
        SELECT s.id, s.queue_status, jr.id as run_id, jr.verdict, jr.total_score, 
               jr.passed_test_count, jr.total_test_count, jr.compile_log, jr.system_log
        FROM submissions s
        LEFT JOIN judging_runs jr ON s.id = jr.submission_id AND jr.is_latest = 1
        WHERE s.idempotency_key = ?
    ");
    $stmtCheck->execute([$idempotencyKey]);
    $existing = $stmtCheck->fetch();

    if ($existing) {
        echo json_encode([
            'success'           => true,
            'is_duplicate'      => true,
            'message'           => 'Yêu cầu gửi lại đã tồn tại, trả về bản nộp cũ (Chống nộp trùng)',
            'submission_id'     => (int)$existing['id'],
            'queue_status'      => $existing['queue_status'],
            'verdict'           => $existing['verdict'],
            'total_score'       => (float)$existing['total_score'],
            'passed_test_count' => (int)$existing['passed_test_count'],
            'total_test_count'  => (int)$existing['total_test_count'],
            'compile_log'       => $existing['compile_log'],
            'system_log'        => $existing['system_log']
        ]);
        exit;
    }

    // 2. Lấy phiên bản đề bài hiện tại (Gắn bài nộp với đúng phiên bản đề lúc nộp)
    $stmtProb = $pdo->prepare("SELECT id, current_version_id FROM problems WHERE id = ?");
    $stmtProb->execute([$problemId]);
    $prob = $stmtProb->fetch();

    if (!$prob) {
        echo json_encode(['success' => false, 'message' => 'Không tìm thấy bài tập']);
        exit;
    }

    $versionId = (int)$prob['current_version_id'];
    $codeHash  = hash('sha256', $sourceCode);

    // 3. Tạo bản nộp mới trong bảng submissions với queue_status = PENDING
    $stmtInsert = $pdo->prepare("
        INSERT INTO submissions (
            idempotency_key, user_id, problem_id, problem_version_id, 
            language_id, source_code, code_hash, queue_status, created_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, 'PENDING', NOW())
    ");
    $stmtInsert->execute([
        $idempotencyKey, $userId, $problemId, $versionId, 
        $languageId, $sourceCode, $codeHash
    ]);
    $submissionId = (int)$pdo->lastInsertId();

    // 4. Gọi bộ điều phối chấm bài (JudgeCoordinator)
    $judgeResult = JudgeCoordinator::judge($submissionId, $versionId);

    echo json_encode([
        'success'           => true,
        'is_duplicate'      => false,
        'submission_id'     => $submissionId,
        'verdict'           => $judgeResult['verdict'] ?? 'PENDING',
        'total_score'       => $judgeResult['total_score'] ?? 0,
        'passed_test_count' => $judgeResult['passed_test_count'] ?? 0,
        'total_test_count'  => $judgeResult['total_test_count'] ?? 0,
        'max_time_ms'       => $judgeResult['max_time_ms'] ?? 0,
        'compile_log'       => $judgeResult['compile_log'] ?? null,
        'system_log'        => $judgeResult['system_log'] ?? null
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Lỗi máy chủ: ' . $e->getMessage()]);
}
