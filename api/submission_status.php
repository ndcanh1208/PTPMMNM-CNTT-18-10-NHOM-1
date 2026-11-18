<?php
/**
 * Nhom1 Online Judge - API Theo dõi Trạng thái Chấm & Chi tiết Bài nộp
 * GET /api/submission_status.php?id={submission_id}
 * 
 * Tuân thủ:
 * - Nguyên tắc 4: Tuyệt đối không trả về input/output của testcase ẩn (is_hidden = 1)
 * - Nguyên tắc 5: Quyền riêng tư - Người A không được xem mã người B (Trả về HTTP 403)
 *
 * Cách frontend sử dụng API này:
 * - Sau khi gọi /api/submit.php, trình duyệt sẽ gọi định kỳ (polling) endpoint này theo `id` bài nộp
 *   để cập nhật trạng thái chấm (Queued -> Running -> Done/AC/WA/TLE/RE/CE) và hiển thị kết quả từng test case.
 */

// Bật chế độ kiểm tra kiểu dữ liệu nghiêm ngặt trong PHP
declare(strict_types=1);

// Thiết lập kiểu dữ liệu phản hồi là JSON chuẩn UTF-8
header('Content-Type: application/json; charset=utf-8');

// Nạp các tệp lõi: kết nối CSDL, quản lý xác thực/phân quyền (Auth), bộ lọc bảo mật dữ liệu (Security) và bộ kích hoạt Worker
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../core/Auth.php';
require_once __DIR__ . '/../core/Security.php';
require_once __DIR__ . '/../core/WorkerLauncher.php';

// =========================================================================
// BƯỚC 1: KIỂM TRA ĐĂNG NHẬP & NHẬN THAM SỐ ĐẦU VÀO
// =========================================================================
// 1. Kiểm tra đăng nhập
if (!Auth::check()) {
    // HTTP 401 Unauthorized: khách chưa đăng nhập không thể xem chi tiết bài nộp
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'error'   => 'Vui lòng đăng nhập để xem thông tin bài nộp.'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// Đọc mã bài nộp `id` từ query string GET (?id=123), nếu không có thì thử lấy từ $_POST; ép kiểu sang số nguyên
$submissionId = isset($_GET['id']) ? (int)$_GET['id'] : (isset($_POST['id']) ? (int)$_POST['id'] : 0);

// Validate tham số: ID bài nộp phải là số nguyên dương (> 0)
if ($submissionId <= 0) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error'   => 'Thiếu mã bài nộp (id).'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// Mở kết nối PDO tới cơ sở dữ liệu
$pdo = getDbConnection();

// =========================================================================
// BƯỚC 2: TRUY VẤN THÔNG TIN TỔNG QUAN BÀI NỘP (JOIN 4 BẢNG)
// =========================================================================
// 2. Lấy thông tin bài nộp
// Kết nối (JOIN) `submissions` với:
// - `users`: lấy tên tài khoản & họ tên người nộp
// - `problems`: lấy tiêu đề, slug và `created_by` (người tạo đề, dùng để kiểm tra quyền xem)
// - `problem_versions`: lấy số thứ tự phiên bản đề mà bài nộp đã bám theo
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

// Nếu không tìm thấy bài nộp tương ứng trong CSDL -> trả về HTTP 404 Not Found
if (!$sub) {
    http_response_code(404);
    echo json_encode([
        'success' => false,
        'error'   => 'Không tìm thấy bài nộp yêu cầu.'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// =========================================================================
// BƯỚC 3: KIỂM SOÁT QUYỀN TRUY CẬP & XÁC ĐỊNH ĐẶC QUYỀN XEM TEST ẨN
// =========================================================================
// 3. KIỂM SOÁT QUYỀN TRUY CẬP (Quyền riêng tư - Phép thử 5)
// Người A không đọc được mã của B trừ khi là Admin hoặc Giảng viên phụ trách lớp/tạo đề
$submissionUserId = (int)$sub['user_id'];
$classroomId = $sub['classroom_id'] !== null ? (int)$sub['classroom_id'] : null;
$problemCreatorId = (int)$sub['problem_creator_id'];

// Kiểm tra quyền xem bài nộp bằng Auth::canViewSubmission(); nếu không đủ quyền -> trả về HTTP 403 Forbidden
if (!Auth::canViewSubmission($submissionUserId, $classroomId, $problemCreatorId)) {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'error'   => '403 Forbidden - Bạn không có quyền xem chi tiết bài nộp này.'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// Xác định quyền đặc quyền (Admin hoặc Giảng viên tạo đề/dạy lớp)
// Biến `$isPrivileged` quyết định liệu người gọi API có được phép thấy dữ liệu đầu vào/đầu ra của các test case ẩn hay không:
// - Admin: luôn có đặc quyền.
// - Giảng viên: chỉ có đặc quyền nếu là người tạo ra bài tập này HOẶC là giảng viên đứng lớp của `classroom_id`.
// - Sinh viên (kể cả chủ bài nộp): `$isPrivileged = false` -> bị che hoàn toàn dữ liệu test ẩn!
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

// =========================================================================
// BƯỚC 4: TRUY VẤN LỊCH SỬ CÁC LẦN CHẤM (judging_runs) & KẾT QUẢ TỪNG TEST CASE
// =========================================================================
// 4. Lấy toàn bộ lịch sử các lần chấm (judging_runs)
// Một bài nộp có thể có nhiều lượt chấm (lần nộp đầu tiên + các lần chấm lại Rejudge khi đề cập nhật bộ test)
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
// Cờ kiểm tra xem bài nộp hiện còn lượt chấm nào đang ở trạng thái 'Queued' (đợi Worker nhận xử lý) hay không
$hasQueuedRun = false;
foreach ($rawRuns as $r) {
    if ($r['status'] === 'Queued') {
        $hasQueuedRun = true;
    }
    $runId = (int)$r['id'];

    // 4.1 Lọc bỏ thông tin hạ tầng nội bộ nếu không phải Admin
    // Ẩn các trường nhạy cảm như `worker_id`, `system_error_log` và làm sạch đường dẫn máy chủ trong `compiler_message`
    $cleanRun = Security::sanitizeRunDetails($r, Auth::isAdmin());

    // 4.2 Lấy danh sách kết quả từng testcase của lần chấm này
    // JOIN `case_results` với `test_cases` để lấy thứ tự, cờ is_sample / is_hidden, điểm số và dữ liệu test
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
    // Nếu `$isPrivileged === false` và test case có `is_hidden = 1`, hàm sẽ xóa/che `input_data`, `expected_output`, `actual_output`
    $cleanCaseResults = Security::sanitizeCaseResultsForUser($rawCaseResults, $isPrivileged);

    // Gắn danh sách kết quả test case đã lọc vào lượt chấm tương ứng rồi thêm vào mảng kết quả
    $cleanRun['case_results'] = $cleanCaseResults;
    $runs[] = $cleanRun;
}

// =========================================================================
// BƯỚC 5: TỰ ĐỘNG ĐÁNH THỨC WORKER NGẦM NẾU CÒN LƯỢT ĐANG CHỜ (SELF-HEALING)
// =========================================================================
// Nếu phát hiện vẫn còn lượt chấm đang 'Queued' (ví dụ tiến trình Worker trước đó đã thoát hoặc bận),
// gọi lại WorkerLauncher::triggerAsync() để đảm bảo hàng đợi luôn được xử lý mà không bị kẹt vĩnh viễn
if ($hasQueuedRun) {
    WorkerLauncher::triggerAsync();
}

// =========================================================================
// BƯỚC 6: ĐÓNG GÓI & TRẢ VỀ CẤU TRÚC JSON ĐẦY ĐỦ (HTTP 200 OK)
// =========================================================================
// 5. Trả về kết quả JSON
// Cấu trúc trả về gồm 3 khóa chính:
// - `success`: true
// - `submission`: đối tượng chứa thông tin tổng hợp của bài nộp (tác giả, bài tập, phiên bản, mã nguồn, verdict cuối, số test đạt, thời gian/bộ nhớ tối đa...)
// - `runs`: mảng các lượt chấm (mỗi phần tử có kèm mảng `case_results` đã được bảo vệ dữ liệu test ẩn)
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
