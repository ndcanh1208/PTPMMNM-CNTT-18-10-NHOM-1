<?php
/**
 * API LẤY KẾT QUẢ BÀI NỘP VÀ LỊCH SỬ CHẤM
 * Đề tài 20: Yêu cầu 2 & Phép thử 5 - Tuyệt đối không để lộ dữ liệu chấm kín về trình duyệt
 */

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config/db.php';

$submissionId = (int)($_GET['id'] ?? 0);

if ($submissionId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Thiếu mã bài nộp']);
    exit;
}

try {
    $pdo = Database::getConnection();

    // 1. Lấy thông tin bài nộp và lượt chấm mới nhất
    $stmtSub = $pdo->prepare("
        SELECT s.id, s.user_id, s.problem_id, s.problem_version_id, s.language_id, s.source_code, 
               s.queue_status, s.created_at, p.title as problem_title,
               jr.id as run_id, jr.run_number, jr.verdict, jr.total_score, 
               jr.passed_test_count, jr.total_test_count, jr.max_time_ms, 
               jr.compile_log, jr.system_log, jr.started_at, jr.finished_at
        FROM submissions s
        JOIN problems p ON s.problem_id = p.id
        LEFT JOIN judging_runs jr ON s.id = jr.submission_id AND jr.is_latest = 1
        WHERE s.id = ?
    ");
    $stmtSub->execute([$submissionId]);
    $sub = $stmtSub->fetch();

    if (!$sub) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Không tìm thấy bài nộp']);
        exit;
    }

    // Bảo mật phân quyền theo Phép thử 5: Người A không đọc được mã riêng của B
    if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
        session_start();
    }
    $currentUser = $_SESSION['user'] ?? null;
    $currentUserId = $currentUser['id'] ?? (isset($_GET['user_id']) ? (int)$_GET['user_id'] : null);
    $currentUserRole = $currentUser['role'] ?? (isset($_GET['role']) ? $_GET['role'] : 'STUDENT');

    $isOwner = ($currentUserId !== null && (int)$sub['user_id'] === (int)$currentUserId);
    $isStaff = (strtoupper($currentUserRole) === 'ADMIN' || strtoupper($currentUserRole) === 'LECTURER');

    if (!$isOwner && !$isStaff) {
        $sub['source_code'] = '[Mã nguồn riêng tư - Bạn không có quyền xem mã của học viên khác]';
    }

    // Che giấu chi tiết kết nối nội bộ của bộ thực thi
    if (!empty($sub['system_log']) && !$isStaff) {
        $sub['system_log'] = 'Lỗi dịch vụ thực thi cách ly. Đã ghi nhận lỗi hệ thống.';
    }

    // 2. Lấy chi tiết các test case (BẢO VỆ TEST KÍN TUYỆT ĐỐI THEO PHÉP THỬ 5)
    $cases = [];
    if (!empty($sub['run_id'])) {
        $stmtCases = $pdo->prepare("
            SELECT cr.id, cr.test_case_id, cr.verdict, cr.execution_time_ms, 
                   tc.order_index, tc.is_sample, tc.input_data, tc.expected_output,
                   cr.actual_output, cr.error_message
            FROM case_results cr
            JOIN test_cases tc ON cr.test_case_id = tc.id
            WHERE cr.judging_run_id = ?
            ORDER BY tc.order_index ASC
        ");
        $stmtCases->execute([(int)$sub['run_id']]);
        $rawCases = $stmtCases->fetchAll();

        foreach ($rawCases as $rc) {
            $isSample = (int)$rc['is_sample'] === 1;

            // Nếu là test mẫu (is_sample = 1): Được phép hiển thị input và output công khai
            // Nếu là test kín (is_sample = 0): CHE HOÀN TOÀN input, expected_output và actual_output!
            $cases[] = [
                'order_index'       => (int)$rc['order_index'],
                'is_sample'         => $isSample,
                'verdict'           => $rc['verdict'],
                'execution_time_ms' => (int)$rc['execution_time_ms'],
                'input_data'        => $isSample ? $rc['input_data'] : '[Dữ liệu kiểm tra kín]',
                'expected_output'   => $isSample ? $rc['expected_output'] : '[Đáp án kiểm tra kín]',
                'actual_output'     => $isSample ? $rc['actual_output'] : null,
                'error_message'     => $rc['error_message']
            ];
        }
    }

    // 3. Lấy lịch sử tất cả các lần chấm để đối chiếu (Tình huống 2)
    $stmtAllRuns = $pdo->prepare("
        SELECT jr.id, jr.run_number, jr.verdict, jr.total_score, jr.passed_test_count, 
               jr.total_test_count, jr.max_time_ms, jr.is_latest, jr.finished_at,
               pv.version_number
        FROM judging_runs jr
        JOIN problem_versions pv ON jr.problem_version_id = pv.id
        WHERE jr.submission_id = ?
        ORDER BY jr.run_number ASC
    ");
    $stmtAllRuns->execute([$submissionId]);
    $runs = $stmtAllRuns->fetchAll();

    echo json_encode([
        'success'    => true,
        'submission' => $sub,
        'cases'      => $cases,
        'runs'       => $runs
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
