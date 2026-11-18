<?php
/**
 * Nhom1 Online Judge - API Xuất Báo cáo Kết quả Học tập & Lịch sử Nộp bài (Admin Only)
 * GET /api/export_report.php?type=student_summary|submissions&format=csv|json
 *
 * Tổng quan chức năng:
 * - Chỉ Quản trị viên (Admin) mới được phép tải xuống báo cáo.
 * - Hỗ trợ 2 loại báo cáo (`type`):
 *   1) `student_summary`: Bảng tổng hợp kết quả học tập của từng sinh viên (số bài AC, điểm thang 10, tỷ lệ AC...).
 *   2) `submissions`    : Lịch sử chi tiết tối đa 5.000 lượt nộp bài gần nhất.
 * - Hỗ trợ lọc theo lớp (`class_name`), bài tập (`problem_id`), khoảng ngày (`date_from`, `date_to`).
 * - Hỗ trợ 2 định dạng xuất file tải về (`format`): `csv` (kèm UTF-8 BOM mở chuẩn bằng Excel) hoặc `json`.
 */

// Bật kiểm tra kiểu dữ liệu nghiêm ngặt trong PHP.
declare(strict_types=1);

// Nạp kết nối CSDL và lớp quản lý đăng nhập/phân quyền.
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../core/Auth.php';

// Khởi động Session để kiểm tra phiên làm việc hiện tại.
Auth::startSession();

// Bước 1: Kiểm tra quyền truy cập (CHỈ Admin được xuất báo cáo).
// Lưu ý: chỉ đặt header JSON ở nhánh lỗi này vì khi xuất thành công, Content-Type có thể là `text/csv` hoặc `application/json`.
if (!Auth::check() || !Auth::isAdmin()) {
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success' => false,
        'error'   => '403 Forbidden - Chỉ Quản trị viên (Admin) mới có quyền xuất báo cáo học tập.'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// Bước 2: Mở kết nối CSDL và đọc các tham số lọc từ chuỗi truy vấn trên URL (`$_GET`).
$pdo       = getDbConnection();
$type      = trim((string)($_GET['type'] ?? 'student_summary')); // Loại báo cáo muốn xuất
$format    = strtolower(trim((string)($_GET['format'] ?? 'csv'))); // Định dạng file: csv hoặc json
$className = trim((string)($_GET['class_name'] ?? '')); // Lọc theo tên lớp (tùy chọn)
$problemId = isset($_GET['problem_id']) && $_GET['problem_id'] !== '' ? (int)$_GET['problem_id'] : 0; // Lọc theo bài tập (dùng cho loại submissions)
$dateFrom  = trim((string)($_GET['date_from'] ?? '')); // Ngày bắt đầu (YYYY-MM-DD)
$dateTo    = trim((string)($_GET['date_to'] ?? ''));   // Ngày kết thúc (YYYY-MM-DD)

// Chuẩn hóa giá trị đầu vào theo danh sách trắng: nếu người dùng truyền sai thì tự đưa về giá trị mặc định an toàn.
if (!in_array($type, ['student_summary', 'submissions'], true)) {
    $type = 'student_summary';
}
if (!in_array($format, ['csv', 'json'], true)) {
    $format = 'csv';
}

// Tạo dấu thời gian (VD: 20261118_231005) để gắn vào tên file tải về giúp không bị trùng tên.
$timestamp = date('Ymd_His');

// =============================================================================
// LOẠI 1: XUẤT BẢNG TỔNG HỢP KẾT QUẢ HỌC TẬP SINH VIÊN (STUDENT SUMMARY)
// =============================================================================
if ($type === 'student_summary') {
    // Đếm tổng số bài tập đang hoạt động (chưa bị xóa mềm `deleted_at IS NULL`) làm mẫu số tính điểm thang 10.
    // Dùng `max(1, ...)` để tránh lỗi chia cho 0 nếu hệ thống chưa có bài tập nào.
    $totalActiveProblems = max(1, (int)$pdo->query("SELECT COUNT(*) FROM problems WHERE deleted_at IS NULL")->fetchColumn());

    // Chuẩn bị bộ lọc điều kiện người dùng và khoảng thời gian nộp bài.
    $whereUser = ["u.role = 'learner'"];
    $params = [];

    if ($className !== '') {
        $whereUser[] = "u.class_name = ?";
        $params[] = $className;
    }

    $subDateFilter = '';
    if ($dateFrom !== '') {
        $subDateFilter .= " AND s.submitted_at >= ?";
        $params[] = $dateFrom . ' 00:00:00';
    }
    if ($dateTo !== '') {
        $subDateFilter .= " AND s.submitted_at <= ?";
        $params[] = $dateTo . ' 23:59:59';
    }

    // Dùng LEFT JOIN giữa `users` và `submissions`, đồng thời đặt điều kiện lọc ngày nộp ngay trong mệnh đề `ON`
    // thay vì đặt ở `WHERE`: nhờ đó các sinh viên chưa nộp bài nào trong khoảng thời gian lọc vẫn xuất hiện với số bài = 0.
    $joinParams = [];
    $dateJoin = '';
    if ($dateFrom !== '') {
        $dateJoin .= " AND s.submitted_at >= ?";
        $joinParams[] = $dateFrom . ' 00:00:00';
    }
    if ($dateTo !== '') {
        $dateJoin .= " AND s.submitted_at <= ?";
        $joinParams[] = $dateTo . ' 23:59:59';
    }

    // Điều kiện lọc danh sách sinh viên (chỉ lấy tài khoản vai trò 'learner', và lọc theo lớp nếu có).
    $userParams = [];
    $whereUserSql = "u.role = 'learner'";
    if ($className !== '') {
        $whereUserSql .= " AND u.class_name = ?";
        $userParams[] = $className;
    }

    // Truy vấn tổng hợp số liệu từng sinh viên:
    // - COUNT(s.id)                                : tổng số lượt nộp bài.
    // - SUM(CASE WHEN final_verdict = '...' ...)   : đếm số lượt đạt AC, sai đáp án WA, quá thời gian TLE.
    // - COUNT(DISTINCT CASE WHEN AC THEN problem_id): số bài tập KHÁC NHAU đã giải đúng (mỗi bài AC nhiều lần chỉ tính 1).
    // - MAX(s.submitted_at)                        : thời điểm nộp bài gần nhất.
    $stmt = $pdo->prepare("
        SELECT
            u.id,
            COALESCE(u.student_code, '') AS student_code,
            u.full_name,
            u.username,
            COALESCE(u.class_name, '') AS class_name,
            u.email,
            u.is_locked,
            COUNT(s.id) AS total_submissions,
            SUM(CASE WHEN s.final_verdict = 'AC' THEN 1 ELSE 0 END) AS ac_submissions,
            SUM(CASE WHEN s.final_verdict = 'WA' THEN 1 ELSE 0 END) AS wa_submissions,
            SUM(CASE WHEN s.final_verdict = 'TLE' THEN 1 ELSE 0 END) AS tle_submissions,
            COUNT(DISTINCT CASE WHEN s.final_verdict = 'AC' THEN s.problem_id END) AS solved_problems,
            MAX(s.submitted_at) AS last_submitted_at
        FROM users u
        LEFT JOIN submissions s ON s.user_id = u.id {$dateJoin}
        WHERE {$whereUserSql}
        GROUP BY u.id
        ORDER BY solved_problems DESC, ac_submissions DESC, u.student_code ASC, u.id ASC
    ");
    // Ghép mảng tham số theo đúng thứ tự xuất hiện của dấu `?` trong câu SQL: tham số của `ON` trước, `WHERE` sau.
    $stmt->execute(array_merge($joinParams, $userParams));
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Biến đổi từng dòng dữ liệu thô từ CSDL thành dòng báo cáo hoàn chỉnh (tính thêm tỷ lệ AC và điểm thang 10).
    $report = [];
    foreach ($rows as $idx => $r) {
        $tot    = (int)$r['total_submissions'];
        $ac     = (int)$r['ac_submissions'];
        $solved = (int)$r['solved_problems'];
        // Tỷ lệ AC (%) = (số lượt AC / tổng lượt nộp) * 100, làm tròn 1 chữ số thập phân (tránh chia cho 0 nếu chưa nộp).
        $acRate = $tot > 0 ? round(($ac / $tot) * 100, 1) : 0.0;
        // Điểm đánh giá quy đổi thang 10 = (số bài giải đúng / tổng số bài đang mở) * 10, làm tròn 2 chữ số thập phân.
        $score10 = round(($solved / $totalActiveProblems) * 10, 2);

        $report[] = [
            'stt'               => $idx + 1,
            'student_code'      => $r['student_code'],
            'full_name'         => $r['full_name'],
            'username'          => $r['username'],
            'class_name'        => $r['class_name'],
            'email'             => $r['email'],
            'solved_problems'   => $solved,
            'total_problems'    => $totalActiveProblems,
            'score_scale_10'    => $score10,
            'total_submissions' => $tot,
            'ac_submissions'    => $ac,
            'wa_submissions'    => (int)$r['wa_submissions'],
            'tle_submissions'   => (int)$r['tle_submissions'],
            'ac_rate_percent'   => $acRate,
            'status'            => (int)$r['is_locked'] === 1 ? 'Đã khóa' : 'Hoạt động',
            'last_submitted_at' => $r['last_submitted_at'] ?? ''
        ];
    }

    // Nhánh xuất định dạng JSON: gửi header `Content-Disposition: attachment` để trình duyệt tự tải file .json về máy.
    if ($format === 'json') {
        header('Content-Type: application/json; charset=utf-8');
        header("Content-Disposition: attachment; filename=\"nhom1_bang_diem_{$timestamp}.json\"");
        echo json_encode([
            'exported_at'    => date('Y-m-d H:i:s'),
            'report_type'    => 'student_summary',
            'total_students' => count($report),
            'total_problems' => $totalActiveProblems,
            'data'           => $report
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit;
    }

    // Nhánh xuất định dạng CSV (mặc định):
    header('Content-Type: text/csv; charset=utf-8');
    header("Content-Disposition: attachment; filename=\"nhom1_bang_diem_{$timestamp}.csv\"");
    // Xuất 3 byte UTF-8 BOM ở đầu tệp để Microsoft Excel nhận diện đúng bảng mã UTF-8, không bị lỗi font tiếng Việt.
    echo "\xEF\xBB\xBF";
    // Mở luồng xuất trực tiếp ra phản hồi HTTP (`php://output`) để ghi từng dòng CSV bằng `fputcsv` (tự động xử lý dấu phẩy/ngoặc kép).
    $out = fopen('php://output', 'w');
    // Ghi dòng tiêu đề các cột bằng tiếng Việt.
    fputcsv($out, [
        'STT', 'Mã sinh viên', 'Họ và tên', 'Tên đăng nhập', 'Lớp', 'Email',
        'Số bài giải đúng (AC)', 'Tổng số bài', 'Điểm đánh giá (Thang 10)',
        'Tổng lượt nộp', 'Lượt AC', 'Lượt WA', 'Lượt TLE', 'Tỷ lệ AC (%)',
        'Trạng thái tài khoản', 'Lần nộp gần nhất'
    ]);
    // Ghi lần lượt dữ liệu của từng sinh viên vào tệp CSV.
    foreach ($report as $item) {
        fputcsv($out, array_values($item));
    }
    fclose($out);
    exit;
}

// =============================================================================
// LOẠI 2: XUẤT CHI TIẾT LỊCH SỬ NỘP BÀI (SUBMISSIONS)
// =============================================================================
// Khởi tạo mệnh đề WHERE với `1=1` để dễ dàng nối thêm các điều kiện `AND ...` tùy chọn ở bên dưới.
$where = ['1=1'];
$params = [];

if ($className !== '') {
    $where[] = "u.class_name = ?";
    $params[] = $className;
}
if ($problemId > 0) {
    $where[] = "s.problem_id = ?";
    $params[] = $problemId;
}
if ($dateFrom !== '') {
    $where[] = "s.submitted_at >= ?";
    $params[] = $dateFrom . ' 00:00:00';
}
if ($dateTo !== '') {
    $where[] = "s.submitted_at <= ?";
    $params[] = $dateTo . ' 23:59:59';
}

// Kết 4 bảng (`submissions`, `users`, `problems`, `problem_versions`) để lấy đầy đủ thông tin sinh viên,
// đề bài, phiên bản đề được chấm, phán quyết cuối, số test đạt và thời gian chạy. Giới hạn 5.000 bản ghi mới nhất để bảo vệ RAM.
$stmt = $pdo->prepare("
    SELECT
        s.id AS submission_id,
        COALESCE(u.student_code, '') AS student_code,
        u.full_name,
        u.username,
        COALESCE(u.class_name, '') AS class_name,
        p.slug AS problem_code,
        p.title AS problem_title,
        pv.version_number,
        s.language,
        s.final_verdict,
        s.pass_count,
        s.total_cases,
        s.execution_time_ms,
        s.submitted_at
    FROM submissions s
    JOIN users u ON s.user_id = u.id
    JOIN problems p ON s.problem_id = p.id
    JOIN problem_versions pv ON s.problem_version_id = pv.id
    WHERE " . implode(' AND ', $where) . "
    ORDER BY s.id DESC
    LIMIT 5000
");
$stmt->execute($params);
$subs = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Nếu định dạng yêu cầu là JSON -> gửi header tải file .json kèm dữ liệu đẹp (`JSON_PRETTY_PRINT`).
if ($format === 'json') {
    header('Content-Type: application/json; charset=utf-8');
    header("Content-Disposition: attachment; filename=\"nhom1_lich_su_nop_{$timestamp}.json\"");
    echo json_encode([
        'exported_at'       => date('Y-m-d H:i:s'),
        'report_type'       => 'submissions',
        'total_submissions' => count($subs),
        'data'              => $subs
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

// Mặc định xuất file CSV tải về kèm UTF-8 BOM để mở trực tiếp trên Excel không lỗi tiếng Việt.
header('Content-Type: text/csv; charset=utf-8');
header("Content-Disposition: attachment; filename=\"nhom1_lich_su_nop_{$timestamp}.csv\"");
echo "\xEF\xBB\xBF";
$out = fopen('php://output', 'w');
// Ghi dòng tiêu đề các cột của lịch sử nộp bài.
fputcsv($out, [
    'Mã bài nộp', 'Mã sinh viên', 'Họ và tên', 'Tên đăng nhập', 'Lớp',
    'Mã bài tập', 'Tên bài tập', 'Phiên bản đề', 'Ngôn ngữ', 'Phán quyết',
    'Số test đạt', 'Tổng số test', 'Thời gian chạy (ms)', 'Thời điểm nộp'
]);
// Ghi từng bản ghi bài nộp ra luồng CSV.
foreach ($subs as $row) {
    fputcsv($out, array_values($row));
}
fclose($out);
exit;