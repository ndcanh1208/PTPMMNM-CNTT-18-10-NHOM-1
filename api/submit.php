<?php
/**
 * ByteFlow Online Judge - API Nộp bài chính thức & Xử lý Idempotency
 * POST /api/submit.php
 * 
 * Tuân thủ:
 * - Nguyên tắc 1: Web chỉ nhận bài đẩy vào hàng đợi CSDL, không chạy mã trong Web process
 * - Nguyên tắc 2 & Phép thử 3: Idempotency - Yêu cầu gửi lại không tạo bản nộp trùng
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../core/Auth.php';
require_once __DIR__ . '/../core/Security.php';

// 1. Kiểm tra xác thực
if (!Auth::check()) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'error'   => 'Vui lòng đăng nhập để nộp bài.'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$currentUser = Auth::user();
$userId = $currentUser['id'];

// 2. Đọc payload
$rawInput = file_get_contents('php://input');
$data = json_decode($rawInput, true);
if (!is_array($data)) {
    $data = $_POST;
}

$problemId      = isset($data['problem_id']) ? (int)$data['problem_id'] : 0;
$language       = isset($data['language']) ? strtolower(trim((string)$data['language'])) : '';
$sourceCode     = isset($data['source_code']) ? (string)$data['source_code'] : '';
$idempotencyKey = isset($data['idempotency_key']) ? trim((string)$data['idempotency_key']) : '';
$classroomId    = !empty($data['classroom_id']) ? (int)$data['classroom_id'] : null;

// Thẩm định tính hợp lệ của Idempotency Key (Nguyên tắc 2)
if (!Security::validateIdempotencyKey($idempotencyKey)) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error'   => 'Khóa chống nộp trùng (idempotency_key) không hợp lệ (cần độ dài 8-64 ký tự an toàn).'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($problemId <= 0) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error'   => 'Thiếu mã bài tập (problem_id).'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!in_array($language, ['python', 'cpp'], true)) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error'   => 'Ngôn ngữ lập trình không hợp lệ. Chỉ chấp nhận python hoặc cpp.'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if (trim($sourceCode) === '') {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error'   => 'Mã nguồn không được để trống khi nộp bài.'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$pdo = getDbConnection();

try {
    // 3. KIỂM TRA IDEMPOTENCY: Nếu đã tồn tại bản ghi với (user_id, idempotency_key)
    $stmtCheck = $pdo->prepare("
        SELECT id, final_verdict, latest_run_id, submitted_at
        FROM submissions
        WHERE user_id = :user_id AND idempotency_key = :idemp
        LIMIT 1
    ");
    $stmtCheck->execute([
        'user_id' => $userId,
        'idemp'   => $idempotencyKey
    ]);
    $existing = $stmtCheck->fetch();

    if ($existing) {
        // Trả về đúng mã bài nộp đã tạo trước đó mà không sinh bản ghi trùng
        echo json_encode([
            'success'       => true,
            'is_duplicate'  => true,
            'submission_id' => (int)$existing['id'],
            'final_verdict' => $existing['final_verdict'],
            'latest_run_id' => $existing['latest_run_id'] !== null ? (int)$existing['latest_run_id'] : null,
            'message'       => 'Yêu cầu nộp trùng lặp (Idempotent). Đã trả về bản ghi bài nộp hiện hữu.'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // 4. Lấy phiên bản đề hiện hành (current_version_id)
    $stmtProb = $pdo->prepare("
        SELECT current_version_id 
        FROM problems 
        WHERE id = :id AND is_public = 1
    ");
    $stmtProb->execute(['id' => $problemId]);
    $versionId = $stmtProb->fetchColumn();

    if (!$versionId) {
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'error'   => 'Bài tập không tồn tại hoặc chưa được phát hành phiên bản bộ dữ liệu.'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Kiểm tra lớp học nếu có cung cấp
    if ($classroomId !== null) {
        $cmCheck = $pdo->prepare("
            SELECT COUNT(*) FROM classroom_members 
            WHERE classroom_id = :cid AND user_id = :uid
        ");
        $cmCheck->execute(['cid' => $classroomId, 'uid' => $userId]);
        if ((int)$cmCheck->fetchColumn() === 0) {
            $classroomId = null; // Nếu không thuộc lớp, bỏ qua lớp học
        }
    }

    // 5. Thêm bản nộp mới và lượt chấm đầu tiên trong một Transaction
    $pdo->beginTransaction();

    // 5.1 Lưu submissions gắn cứng với problem_version_id lúc nộp (Yêu cầu 2)
    $subInsert = $pdo->prepare("
        INSERT INTO submissions 
        (user_id, classroom_id, problem_id, problem_version_id, language, source_code, idempotency_key, final_verdict, submitted_at)
        VALUES (:uid, :cid, :pid, :vid, :lang, :code, :idemp, 'Pending', NOW())
    ");
    $subInsert->execute([
        'uid'   => $userId,
        'cid'   => $classroomId,
        'pid'   => $problemId,
        'vid'   => (int)$versionId,
        'lang'  => $language,
        'code'  => $sourceCode,
        'idemp' => $idempotencyKey
    ]);
    $submissionId = (int)$pdo->lastInsertId();

    // 5.2 Đẩy lượt thực thi đầu tiên vào hàng đợi judging_runs (status = 'Queued')
    $runInsert = $pdo->prepare("
        INSERT INTO judging_runs 
        (submission_id, problem_version_id, run_number, triggered_by, status, verdict)
        VALUES (:sub_id, :vid, 1, 'submit', 'Queued', 'Pending')
    ");
    $runInsert->execute([
        'sub_id' => $submissionId,
        'vid'    => (int)$versionId
    ]);
    $runId = (int)$pdo->lastInsertId();

    // 5.3 Cập nhật bản nháp tương ứng
    $draftUpdate = $pdo->prepare("
        INSERT INTO code_drafts (user_id, problem_id, language, source_code, updated_at)
        VALUES (:uid, :pid, :lang, :code, NOW())
        ON DUPLICATE KEY UPDATE source_code = VALUES(source_code), updated_at = NOW()
    ");
    $draftUpdate->execute([
        'uid'  => $userId,
        'pid'  => $problemId,
        'lang' => $language,
        'code' => $sourceCode
    ]);

    $pdo->commit();

    http_response_code(201);
    echo json_encode([
        'success'       => true,
        'is_duplicate'  => false,
        'submission_id' => $submissionId,
        'run_id'        => $runId,
        'status'        => 'Queued',
        'message'       => 'Bài nộp đã được tiếp nhận và đẩy vào hàng đợi chấm.'
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log("submit.php error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error'   => 'Lỗi hệ thống trong quá trình tiếp nhận bài nộp.'
    ], JSON_UNESCAPED_UNICODE);
}
