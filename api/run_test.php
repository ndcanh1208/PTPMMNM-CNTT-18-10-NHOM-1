<?php
/**
 * ByteFlow Online Judge - API Chạy thử (Custom Input) & Lưu bản nháp (Draft)
 * POST /api/run_test.php
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../core/Auth.php';
require_once __DIR__ . '/../core/Security.php';
require_once __DIR__ . '/../core/SandboxRunner.php';
require_once __DIR__ . '/../core/Comparator.php';

// 1. Kiểm tra xác thực người dùng
if (!Auth::check()) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'error'   => 'Vui lòng đăng nhập để sử dụng chức năng này.'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$currentUser = Auth::user();
$userId = $currentUser['id'];

// 2. Đọc payload (hỗ trợ cả JSON body lẫn FormData)
$inputRaw = file_get_contents('php://input');
$data = json_decode($inputRaw, true);
if (!is_array($data)) {
    $data = $_POST;
}

$problemId   = isset($data['problem_id']) ? (int)$data['problem_id'] : 0;
$language    = isset($data['language']) ? strtolower(trim((string)$data['language'])) : 'python';
$sourceCode  = isset($data['source_code']) ? (string)$data['source_code'] : '';
$customInput = isset($data['custom_input']) ? (string)$data['custom_input'] : null;
$action      = isset($data['action']) ? trim((string)$data['action']) : 'both'; // 'draft', 'run', 'both'

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
        'error'   => 'Ngôn ngữ không hợp lệ. Chỉ hỗ trợ python và cpp.'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$pdo = getDbConnection();

// 3. Lấy thông tin bài tập & phiên bản hiện tại
$stmt = $pdo->prepare("
    SELECT p.id, p.slug, p.title, pv.id as version_id, pv.time_limit_ms, pv.memory_limit_kb
    FROM problems p
    JOIN problem_versions pv ON p.current_version_id = pv.id
    WHERE p.id = :id
");
$stmt->execute(['id' => $problemId]);
$problem = $stmt->fetch();

if (!$problem) {
    http_response_code(404);
    echo json_encode([
        'success' => false,
        'error'   => 'Không tìm thấy bài tập hoặc bài tập chưa có phiên bản hiệu lực.'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$draftSaved = false;

// 4. Lưu bản nháp (nếu action là 'draft' hoặc 'both')
if ($action === 'draft' || $action === 'both') {
    $draftStmt = $pdo->prepare("
        INSERT INTO code_drafts (user_id, problem_id, language, source_code, updated_at)
        VALUES (:user_id, :problem_id, :language, :source_code, NOW())
        ON DUPLICATE KEY UPDATE source_code = VALUES(source_code), updated_at = NOW()
    ");
    $draftStmt->execute([
        'user_id'     => $userId,
        'problem_id'  => $problemId,
        'language'    => $language,
        'source_code' => $sourceCode
    ]);
    $draftSaved = true;
}

// Nếu chỉ lưu nháp
if ($action === 'draft') {
    echo json_encode([
        'success'     => true,
        'draft_saved' => true,
        'message'     => 'Bản nháp đã được lưu thành công.'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// 5. Chạy thử mã nguồn (action là 'run' hoặc 'both')
if (trim($sourceCode) === '') {
    http_response_code(400);
    echo json_encode([
        'success'     => false,
        'draft_saved' => $draftSaved,
        'error'       => 'Mã nguồn không được để trống khi chạy thử.'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$sandbox = new SandboxRunner();
$runIdentifier = 'testrun_' . $userId . '_' . time() . '_' . bin2hex(random_bytes(3));
$runDir = $sandbox->prepareRunDir($runIdentifier);

try {
    // 5.1 Biên dịch / kiểm tra cú pháp
    $compileRes = $sandbox->compile($language, $sourceCode, $runDir);
    if (!$compileRes['success']) {
        $sandbox->cleanupRunDir($runDir);
        echo json_encode([
            'success'     => true,
            'draft_saved' => $draftSaved,
            'execution'   => [
                'verdict'           => $compileRes['verdict'],
                'stdout'            => '',
                'stderr'            => Security::sanitizeErrorMessage($compileRes['message']),
                'execution_time_ms' => 0
            ]
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $executable = $compileRes['executable'];
    $timeLimitMs = (int)$problem['time_limit_ms'];
    $memoryLimitKb = (int)$problem['memory_limit_kb'];

    // 5.2 Nếu người dùng không nhập custom input, lấy sample testcase đầu tiên
    if ($customInput === null) {
        $sampleStmt = $pdo->prepare("
            SELECT input_data, expected_output 
            FROM test_cases 
            WHERE problem_version_id = :ver_id AND is_sample = 1 
            ORDER BY case_order ASC 
            LIMIT 1
        ");
        $sampleStmt->execute(['ver_id' => $problem['version_id']]);
        $sample = $sampleStmt->fetch();
        $inputToRun = $sample ? $sample['input_data'] : '';
    } else {
        $inputToRun = $customInput;
    }

    // 5.3 Thực thi
    $runRes = $sandbox->runTestCase($language, $executable, $inputToRun, $timeLimitMs, $memoryLimitKb);
    $sandbox->cleanupRunDir($runDir);

    echo json_encode([
        'success'     => true,
        'draft_saved' => $draftSaved,
        'execution'   => [
            'verdict'           => $runRes['verdict'],
            'stdout'            => $runRes['actual_output'],
            'stderr'            => Security::sanitizeErrorMessage($runRes['error_message']),
            'execution_time_ms' => $runRes['execution_time_ms']
        ]
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    $sandbox->cleanupRunDir($runDir);
    error_log("run_test error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success'     => false,
        'draft_saved' => $draftSaved,
        'error'       => 'Lỗi hệ thống trong quá trình thực thi chạy thử.'
    ], JSON_UNESCAPED_UNICODE);
}
