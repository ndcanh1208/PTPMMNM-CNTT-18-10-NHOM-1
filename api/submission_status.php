<?php
/**
 * ByteFlow Online Judge - API Theo dõi Trạng thái Chấm & Chi tiết Bài nộp
 * GET /api/submission_status.php?id={submission_id}
 * 
 * Tuân thủ:
 * - Nguyên tắc 4: Tuyệt đối không trả về input/output của testcase ẩn (is_hidden = 1)
 * - Nguyên tắc 5: Quyền riêng tư - Người A không được xem mã người B (Trả về HTTP 403)
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../core/Auth.php';
require_once __DIR__ . '/../core/Security.php';

// 1. Kiểm tra đăng nhập
if (!Auth::check()) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'error'   => 'Vui lòng đăng nhập để xem thông tin bài nộp.'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$submissionId = isset($_GET['id']) ? (int)$_GET['id'] : (isset($_POST['id']) ? (int)$_POST['id'] : 0);

if ($submissionId <= 0) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error'   => 'Thiếu mã bài nộp (id).'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$pdo = getDbConnection();

// 2. Lấy thông tin bài nộp
$stmt = $pdo->prepare("
    SELECT s.id, s.user_id, s.classroom_id, s.problem_id, s.problem_version_id,
           s.language, s.source_code, s.final_verdict, s.pass_count, s.total_cases,
           s.execution_time_ms, s.memory_used_kb, s.submitted_at, s.latest_run_id,
           u.username as author_username, u.full_name as author_name,
           p.title as problem_title, p.slug as problem_slug, p.created_by as problem_creator_id,
           pv.version_number
    FROM submissions s
    JOIN users u ON s.user_id = u.id
    JOIN problems p ON s.problem_id = p.id
    JOIN problem_versions pv ON s.problem_version_id = pv.id
    WHERE s.id = :id
");
$stmt->execute(['id' => $submissionId]);
$sub = $stmt->fetch();

if (!$sub) {
    http_response_code(404);
    echo json_encode([
        'success' => false,
        'error'   => 'Không tìm thấy bài nộp yêu cầu.'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// 3. KIỂM SOÁT QUYỀN TRUY CẬP (Quyền riêng tư - Phép thử 5)
// Người A không đọc được mã của B trừ khi là Admin hoặc Giảng viên phụ trách lớp/tạo đề
$submissionUserId = (int)$sub['user_id'];
$classroomId = $sub['classroom_id'] !== null ? (int)$sub['classroom_id'] : null;
$problemCreatorId = (int)$sub['problem_creator_id'];

if (!Auth::canViewSubmission($submissionUserId, $classroomId, $problemCreatorId)) {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'error'   => '403 Forbidden - Bạn không có quyền xem chi tiết bài nộp này.'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// Xác định quyền đặc quyền (Admin hoặc Giảng viên tạo đề/dạy lớp)
$isPrivileged = Auth::isAdmin();
if (!$isPrivileged && Auth::isInstructor()) {
    if (Auth::id() === $problemCreatorId) {
        $isPrivileged = true;
    } elseif ($classroomId !== null) {
        $cCheck = $pdo->prepare("SELECT COUNT(*) FROM classrooms WHERE id = ? AND instructor_id = ?");
        $cCheck->execute([$classroomId, Auth::id()]);
        if ((int)$cCheck->fetchColumn() > 0) {
            $isPrivileged = true;
        }
    }
}

// 4. Lấy toàn bộ lịch sử các lần chấm (judging_runs)
$runStmt = $pdo->prepare("
    SELECT jr.id, jr.submission_id, jr.problem_version_id, jr.run_number, jr.triggered_by,
           jr.worker_id, jr.status, jr.verdict, jr.passed_cases, jr.total_cases,
           jr.max_time_ms, jr.max_memory_kb, jr.compiler_message, jr.system_error_log,
           jr.started_at, jr.finished_at, pv.version_number
    FROM judging_runs jr
    JOIN problem_versions pv ON jr.problem_version_id = pv.id
    WHERE jr.submission_id = :sub_id
    ORDER BY jr.run_number ASC
");
$runStmt->execute(['sub_id' => $submissionId]);
$rawRuns = $runStmt->fetchAll();

$runs = [];
foreach ($rawRuns as $r) {
    $runId = (int)$r['id'];

    // 4.1 Lọc bỏ thông tin hạ tầng nội bộ nếu không phải Admin
    $cleanRun = Security::sanitizeRunDetails($r, Auth::isAdmin());

    // 4.2 Lấy danh sách kết quả từng testcase của lần chấm này
    $caseStmt = $pdo->prepare("
        SELECT cr.id, cr.judging_run_id, cr.test_case_id, cr.verdict, cr.execution_time_ms,
               cr.memory_used_kb, cr.actual_output, cr.error_message,
               tc.case_order, tc.input_data, tc.expected_output, tc.is_sample, tc.is_hidden, tc.points
        FROM case_results cr
        JOIN test_cases tc ON cr.test_case_id = tc.id
        WHERE cr.judging_run_id = :run_id
        ORDER BY tc.case_order ASC
    ");
    $caseStmt->execute(['run_id' => $runId]);
    $rawCaseResults = $caseStmt->fetchAll();

    // 4.3 BẢO VỆ DỮ LIỆU KÍN: Ẩn tuyệt đối input/output của testcase ẩn với người học
    $cleanCaseResults = Security::sanitizeCaseResultsForUser($rawCaseResults, $isPrivileged);

    $cleanRun['case_results'] = $cleanCaseResults;
    $runs[] = $cleanRun;
}

// 5. Trả về kết quả JSON
echo json_encode([
    'success'    => true,
    'submission' => [
        'id'                => (int)$sub['id'],
        'user_id'           => (int)$sub['user_id'],
        'author_username'   => $sub['author_username'],
        'author_name'       => $sub['author_name'],
        'classroom_id'      => $sub['classroom_id'] !== null ? (int)$sub['classroom_id'] : null,
        'problem_id'        => (int)$sub['problem_id'],
        'problem_title'     => $sub['problem_title'],
        'problem_slug'      => $sub['problem_slug'],
        'problem_version'   => (int)$sub['version_number'],
        'language'          => $sub['language'],
        'source_code'       => $sub['source_code'],
        'final_verdict'     => $sub['final_verdict'],
        'pass_count'        => (int)$sub['pass_count'],
        'total_cases'       => (int)$sub['total_cases'],
        'execution_time_ms' => (int)$sub['execution_time_ms'],
        'memory_used_kb'    => (int)$sub['memory_used_kb'],
        'latest_run_id'     => $sub['latest_run_id'] !== null ? (int)$sub['latest_run_id'] : null,
        'submitted_at'      => $sub['submitted_at']
    ],
    'runs'       => $runs
], JSON_UNESCAPED_UNICODE);
