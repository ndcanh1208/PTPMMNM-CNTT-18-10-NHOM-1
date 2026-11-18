<?php
/**
 * Nhom1 Online Judge - API Xuất Báo cáo Kết quả Học tập & Lịch sử Nộp bài (Admin Only)
 * GET /api/export_report.php?type=student_summary|submissions&format=csv|json
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../core/Auth.php';

Auth::startSession();

if (!Auth::check() || !Auth::isAdmin()) {
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success' => false,
        'error'   => '403 Forbidden - Chỉ Quản trị viên (Admin) mới có quyền xuất báo cáo học tập.'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$pdo       = getDbConnection();
$type      = trim((string)($_GET['type'] ?? 'student_summary'));
$format    = strtolower(trim((string)($_GET['format'] ?? 'csv')));
$className = trim((string)($_GET['class_name'] ?? ''));
$problemId = isset($_GET['problem_id']) && $_GET['problem_id'] !== '' ? (int)$_GET['problem_id'] : 0;
$dateFrom  = trim((string)($_GET['date_from'] ?? ''));
$dateTo    = trim((string)($_GET['date_to'] ?? ''));

if (!in_array($type, ['student_summary', 'submissions'], true)) {
    $type = 'student_summary';
}
if (!in_array($format, ['csv', 'json'], true)) {
    $format = 'csv';
}

$timestamp = date('Ymd_His');

if ($type === 'student_summary') {
    $totalActiveProblems = max(1, (int)$pdo->query("SELECT COUNT(*) FROM problems WHERE deleted_at IS NULL")->fetchColumn());

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

    // Dùng LEFT JOIN để không bỏ sót sinh viên chưa nộp bài nào
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

    $userParams = [];
    $whereUserSql = "u.role = 'learner'";
    if ($className !== '') {
        $whereUserSql .= " AND u.class_name = ?";
        $userParams[] = $className;
    }

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
    $stmt->execute(array_merge($joinParams, $userParams));
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $report = [];
    foreach ($rows as $idx => $r) {
        $tot    = (int)$r['total_submissions'];
        $ac     = (int)$r['ac_submissions'];
        $solved = (int)$r['solved_problems'];
        $acRate = $tot > 0 ? round(($ac / $tot) * 100, 1) : 0.0;
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

    header('Content-Type: text/csv; charset=utf-8');
    header("Content-Disposition: attachment; filename=\"nhom1_bang_diem_{$timestamp}.csv\"");
    // UTF-8 BOM để Microsoft Excel mở hiển thị đúng tiếng Việt
    echo "\xEF\xBB\xBF";
    $out = fopen('php://output', 'w');
    fputcsv($out, [
        'STT', 'Mã sinh viên', 'Họ và tên', 'Tên đăng nhập', 'Lớp', 'Email',
        'Số bài giải đúng (AC)', 'Tổng số bài', 'Điểm đánh giá (Thang 10)',
        'Tổng lượt nộp', 'Lượt AC', 'Lượt WA', 'Lượt TLE', 'Tỷ lệ AC (%)',
        'Trạng thái tài khoản', 'Lần nộp gần nhất'
    ]);
    foreach ($report as $item) {
        fputcsv($out, array_values($item));
    }
    fclose($out);
    exit;
}

// =============================================================================
// LOẠI 2: XUẤT CHI TIẾT LỊCH SỬ NỘP BÀI (SUBMISSIONS)
// =============================================================================
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

header('Content-Type: text/csv; charset=utf-8');
header("Content-Disposition: attachment; filename=\"nhom1_lich_su_nop_{$timestamp}.csv\"");
echo "\xEF\xBB\xBF";
$out = fopen('php://output', 'w');
fputcsv($out, [
    'Mã bài nộp', 'Mã sinh viên', 'Họ và tên', 'Tên đăng nhập', 'Lớp',
    'Mã bài tập', 'Tên bài tập', 'Phiên bản đề', 'Ngôn ngữ', 'Phán quyết',
    'Số test đạt', 'Tổng số test', 'Thời gian chạy (ms)', 'Thời điểm nộp'
]);
foreach ($subs as $row) {
    fputcsv($out, array_values($row));
}
fclose($out);
exit;
