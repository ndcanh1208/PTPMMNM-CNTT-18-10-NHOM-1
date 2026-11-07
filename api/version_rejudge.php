<?php
/**
 * ByteFlow Online Judge - API Tạo phiên bản bộ dữ liệu mới & Chấm lại (Rejudge)
 * POST /api/version_rejudge.php
 * 
 * Hỗ trợ Tình huống sử dụng 2:
 * Sửa bộ dữ liệu -> tạo phiên bản mới -> chấm lại -> đối chiếu hai lần chấm mà vẫn giữ nguyên lịch sử
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../core/Auth.php';
require_once __DIR__ . '/../core/Security.php';

// 1. Kiểm tra xác thực & Phân quyền (Chỉ Giảng viên hoặc Admin)
if (!Auth::check()) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'error'   => 'Vui lòng đăng nhập.'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!Auth::isAdmin() && !Auth::isInstructor()) {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'error'   => '403 Forbidden - Chỉ Giảng viên hoặc Quản trị viên mới có quyền thực hiện thao tác này.'
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

$action = isset($data['action']) ? trim((string)$data['action']) : 'rejudge';
$problemId = isset($data['problem_id']) ? (int)$data['problem_id'] : 0;

if ($problemId <= 0) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error'   => 'Thiếu mã bài tập (problem_id).'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$pdo = getDbConnection();

// Kiểm tra quyền đối với bài tập cụ thể
$probStmt = $pdo->prepare("SELECT id, created_by, current_version_id FROM problems WHERE id = ?");
$probStmt->execute([$problemId]);
$problem = $probStmt->fetch();

if (!$problem) {
    http_response_code(404);
    echo json_encode([
        'success' => false,
        'error'   => 'Không tìm thấy bài tập.'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!Auth::canManageProblem((int)$problem['created_by'])) {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'error'   => 'Bạn không có quyền quản trị bài tập này.'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// -----------------------------------------------------------------------------
// THAO TÁC 1: TẠO PHIÊN BẢN BỘ DỮ LIỆU MỚI (create_version)
// -----------------------------------------------------------------------------
if ($action === 'create_version') {
    $changelog     = isset($data['changelog']) ? trim((string)$data['changelog']) : 'Cập nhật bộ dữ liệu kiểm tra';
    $timeLimitMs   = isset($data['time_limit_ms']) ? (int)$data['time_limit_ms'] : 1000;
    $memoryLimitKb = isset($data['memory_limit_kb']) ? (int)$data['memory_limit_kb'] : 262144;
    $testCases     = isset($data['test_cases']) && is_array($data['test_cases']) ? $data['test_cases'] : [];

    // Lấy thông tin phiên bản cũ gần nhất để kế thừa mô tả nếu không cung cấp mới
    $latestVerStmt = $pdo->prepare("
        SELECT * FROM problem_versions 
        WHERE problem_id = ? 
        ORDER BY version_number DESC 
        LIMIT 1
    ");
    $latestVerStmt->execute([$problemId]);
    $latestVer = $latestVerStmt->fetch();

    $description       = $data['description'] ?? ($latestVer['description'] ?? '');
    $inputDescription  = $data['input_description'] ?? ($latestVer['input_description'] ?? '');
    $outputDescription = $data['output_description'] ?? ($latestVer['output_description'] ?? '');
    $constraints       = $data['constraints'] ?? ($latestVer['constraints'] ?? '');

    $newVersionNumber = $latestVer ? ((int)$latestVer['version_number'] + 1) : 1;

    $pdo->beginTransaction();
    try {
        // 1. Thêm phiên bản mới vào problem_versions
        $insertVerStmt = $pdo->prepare("
            INSERT INTO problem_versions 
            (problem_id, version_number, description, input_description, output_description,
             constraints, time_limit_ms, memory_limit_kb, changelog, created_by, created_at)
            VALUES (:pid, :vnum, :desc, :indesc, :outdesc, :cons, :time_lim, :mem_lim, :change, :uid, NOW())
        ");
        $insertVerStmt->execute([
            'pid'      => $problemId,
            'vnum'     => $newVersionNumber,
            'desc'     => $description,
            'indesc'   => $inputDescription,
            'outdesc'  => $outputDescription,
            'cons'     => $constraints,
            'time_lim' => $timeLimitMs,
            'mem_lim'  => $memoryLimitKb,
            'change'   => $changelog,
            'uid'      => $userId
        ]);
        $newVersionId = (int)$pdo->lastInsertId();

        // 2. Thêm các testcases mới
        $tcInsertStmt = $pdo->prepare("
            INSERT INTO test_cases 
            (problem_version_id, case_order, input_data, expected_output, is_sample, is_hidden, points, created_at)
            VALUES (:pvid, :corder, :input, :output, :sample, :hidden, :points, NOW())
        ");

        if (!empty($testCases)) {
            foreach ($testCases as $idx => $tc) {
                $tcInsertStmt->execute([
                    'pvid'   => $newVersionId,
                    'corder' => $tc['case_order'] ?? ($idx + 1),
                    'input'  => $tc['input_data'] ?? '',
                    'output' => $tc['expected_output'] ?? '',
                    'sample' => !empty($tc['is_sample']) ? 1 : 0,
                    'hidden' => !empty($tc['is_hidden']) ? 1 : 0,
                    'points' => (int)($tc['points'] ?? 10)
                ]);
            }
        } elseif ($latestVer) {
            // Nếu không cung cấp bộ test mới, sao chép toàn bộ test từ phiên bản cũ sang
            $copyTc = $pdo->prepare("
                INSERT INTO test_cases (problem_version_id, case_order, input_data, expected_output, is_sample, is_hidden, points, created_at)
                SELECT :new_vid, case_order, input_data, expected_output, is_sample, is_hidden, points, NOW()
                FROM test_cases
                WHERE problem_version_id = :old_vid
            ");
            $copyTc->execute([
                'new_vid' => $newVersionId,
                'old_vid' => $latestVer['id']
            ]);
        }

        // 3. Cập nhật current_version_id trên problems
        $updateProb = $pdo->prepare("UPDATE problems SET current_version_id = ? WHERE id = ?");
        $updateProb->execute([$newVersionId, $problemId]);

        $pdo->commit();

        echo json_encode([
            'success'        => true,
            'version_id'     => $newVersionId,
            'version_number' => $newVersionNumber,
            'message'        => "Đã phát hành phiên bản mới v{$newVersionNumber} cho bài tập #{$problemId}."
        ], JSON_UNESCAPED_UNICODE);
        exit;

    } catch (Throwable $e) {
        $pdo->rollBack();
        error_log("create_version error: " . $e->getMessage());
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error'   => 'Lỗi hệ thống khi tạo phiên bản mới: ' . $e->getMessage()
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

// -----------------------------------------------------------------------------
// THAO TÁC 2: CHẤM LẠI BÀI NỘP (rejudge)
// -----------------------------------------------------------------------------
if ($action === 'rejudge') {
    // Phiên bản muốn áp dụng để chấm lại (mặc định lấy current_version_id)
    $targetVersionId = !empty($data['problem_version_id']) ? (int)$data['problem_version_id'] : (int)$problem['current_version_id'];
    $submissionIds   = isset($data['submission_ids']) && is_array($data['submission_ids']) ? array_map('intval', $data['submission_ids']) : [];

    // Tìm danh sách bài nộp cần rejudge
    if (!empty($submissionIds)) {
        $inPlaceholders = implode(',', array_fill(0, count($submissionIds), '?'));
        $sql = "SELECT id FROM submissions WHERE problem_id = ? AND id IN ({$inPlaceholders})";
        $params = array_merge([$problemId], $submissionIds);
    } else {
        $sql = "SELECT id FROM submissions WHERE problem_id = ?";
        $params = [$problemId];
    }

    $subStmt = $pdo->prepare($sql);
    $subStmt->execute($params);
    $subsToRejudge = $subStmt->fetchAll(PDO::FETCH_COLUMN);

    if (empty($subsToRejudge)) {
        echo json_encode([
            'success'        => true,
            'rejudged_count' => 0,
            'message'        => 'Không có bài nộp nào phù hợp để chấm lại.'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $pdo->beginTransaction();
    $queuedRuns = [];

    try {
        $maxRunStmt = $pdo->prepare("SELECT COALESCE(MAX(run_number), 0) FROM judging_runs WHERE submission_id = ?");
        $insertRunStmt = $pdo->prepare("
            INSERT INTO judging_runs 
            (submission_id, problem_version_id, run_number, triggered_by, status, verdict)
            VALUES (:sub_id, :ver_id, :run_num, 'rejudge', 'Queued', 'Pending')
        ");

        foreach ($subsToRejudge as $sId) {
            $sId = (int)$sId;
            $maxRunStmt->execute([$sId]);
            $nextRunNum = (int)$maxRunStmt->fetchColumn() + 1;

            $insertRunStmt->execute([
                'sub_id'  => $sId,
                'ver_id'  => $targetVersionId,
                'run_num' => $nextRunNum
            ]);

            $newRunId = (int)$pdo->lastInsertId();
            $queuedRuns[] = [
                'submission_id' => $sId,
                'run_id'        => $newRunId,
                'run_number'    => $nextRunNum
            ];
        }

        $pdo->commit();

        echo json_encode([
            'success'        => true,
            'rejudged_count' => count($queuedRuns),
            'version_id'     => $targetVersionId,
            'queued_runs'    => $queuedRuns,
            'message'        => 'Đã tạo ' . count($queuedRuns) . ' lượt chấm lại vào hàng đợi (giữ nguyên toàn bộ lịch sử các lần chấm trước).'
        ], JSON_UNESCAPED_UNICODE);
        exit;

    } catch (Throwable $e) {
        $pdo->rollBack();
        error_log("rejudge error: " . $e->getMessage());
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error'   => 'Lỗi hệ thống khi kích hoạt chấm lại: ' . $e->getMessage()
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

http_response_code(400);
echo json_encode([
    'success' => false,
    'error'   => "Hành động (action) '{$action}' không được hỗ trợ."
], JSON_UNESCAPED_UNICODE);
