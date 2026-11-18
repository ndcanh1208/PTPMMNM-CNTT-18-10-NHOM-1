<?php
/**
 * Nhom1 Online Judge - API Quản lý Chấm bài, Giám sát Hàng đợi & Điều khiển Rejudge (Admin Only)
 * GET/POST /api/admin_judging.php
 *
 * Tổng quan chức năng:
 * - GET  (?action=status): Kiểm tra tình trạng trình biên dịch (Python, C++ g++), thống kê hàng đợi chấm bài
 *                          theo ngôn ngữ, phát hiện tiến trình treo quá 60 giây và đếm số lỗi hệ thống (SE/CE).
 * - POST (action=rejudge): Kích hoạt chấm lại linh hoạt theo Bài tập, Sinh viên cụ thể, hoặc 1 Bài nộp duy nhất.
 * - POST (action=retry_se): Thử chấm lại tất cả các bài nộp có lượt chấm mới nhất bị lỗi hệ thống (SE).
 * - POST (action=release_stalled): Giải phóng các lượt chấm bị kẹt ở trạng thái 'Running' quá 60 giây và xếp hàng chấm lại.
 */

// Bật kiểm tra kiểu dữ liệu nghiêm ngặt trong PHP.
declare(strict_types=1);

// Khai báo phản hồi dạng JSON mã hóa UTF-8.
header('Content-Type: application/json; charset=utf-8');

// Nạp các file kết nối CSDL, xác thực quyền, kiểm tra trình biên dịch và kích hoạt Worker chấm bài.
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../core/Auth.php';
require_once __DIR__ . '/../core/SandboxRunner.php';
require_once __DIR__ . '/../core/WorkerLauncher.php';

// Khởi động phiên làm việc (PHP Session) để đọc thông tin đăng nhập của người dùng hiện tại.
Auth::startSession();

// Kiểm tra quyền truy cập: API vận hành hệ thống chấm bài CHỈ dành cho Quản trị viên (Admin).
// Nếu chưa đăng nhập hoặc không phải Admin -> trả về mã lỗi HTTP 403 (Forbidden) và dừng ngay.
if (!Auth::check() || !Auth::isAdmin()) {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'error'   => '403 Forbidden - Chỉ Quản trị viên (Admin) mới có quyền vận hành hệ thống chấm bài.'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// Mở kết nối đến cơ sở dữ liệu MySQL.
$pdo = getDbConnection();

// =============================================================================
// NHÁNH HTTP GET: XEM TRẠNG THÁI HỆ THỐNG CHẤM BÀI & HÀNG ĐỢI
// =============================================================================
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    // Lấy tham số `action` trên URL (mặc định là 'status').
    $action = trim((string)($_GET['action'] ?? 'status'));

    if ($action === 'status') {
        // 1. Kiểm tra tính sẵn sàng và phiên bản của trình thông dịch Python và trình biên dịch C++ (g++).
        $sandbox = new SandboxRunner();
        $pyPath  = $sandbox->getPythonPath();
        $gppPath = $sandbox->getGppPath();

        // Chạy lệnh `python --version` (gộp stderr vào stdout bằng `2>&1`) để lấy chuỗi phiên bản Python.
        $pyVersion = null;
        if ($pyPath) {
            $out = @shell_exec("\"{$pyPath}\" --version 2>&1");
            $pyVersion = $out ? trim($out) : 'Sẵn sàng';
        }

        // Chạy lệnh `g++ --version` và chỉ lấy dòng đầu tiên (vì g++ in ra nhiều dòng thông tin bản quyền).
        $gppVersion = null;
        if ($gppPath) {
            $out = @shell_exec("\"{$gppPath}\" --version 2>&1");
            if ($out) {
                $lines = explode("\n", trim($out));
                $gppVersion = trim($lines[0]);
            } else {
                $gppVersion = 'Sẵn sàng';
            }
        }

        // 2. Thống kê số lượng lượt chấm trong hàng đợi phân theo ngôn ngữ (Python & C++) và trạng thái.
        // Kết bảng `judging_runs` với `submissions` để biết mỗi lượt chạy thuộc ngôn ngữ nào.
        $queueStatsRaw = $pdo->query("
            SELECT s.language, jr.status, COUNT(*) AS cnt
            FROM judging_runs jr
            JOIN submissions s ON jr.submission_id = s.id
            GROUP BY s.language, jr.status
        ")->fetchAll(PDO::FETCH_ASSOC);

        // Khởi tạo bộ khung đếm mặc định bằng 0 cho cả 2 ngôn ngữ và 5 trạng thái để JSON trả về luôn đầy đủ khóa.
        $byLang = [
            'python' => ['Queued' => 0, 'Running' => 0, 'Completed' => 0, 'SystemError' => 0, 'Superseded' => 0],
            'cpp'    => ['Queued' => 0, 'Running' => 0, 'Completed' => 0, 'SystemError' => 0, 'Superseded' => 0]
        ];
        // Đổ dữ liệu thống kê từ CSDL vào mảng `$byLang`.
        foreach ($queueStatsRaw as $row) {
            $lang = $row['language'];
            $st   = $row['status'];
            if (isset($byLang[$lang][$st])) {
                $byLang[$lang][$st] = (int)$row['cnt'];
            }
        }

        // 3. Đếm số lượt chạy nghi bị treo: trạng thái vẫn là 'Running' nhưng đã bắt đầu hơn 60 giây trước.
        $stalledCount = (int)$pdo->query("
            SELECT COUNT(*) FROM judging_runs
            WHERE status = 'Running' AND started_at IS NOT NULL AND started_at < DATE_SUB(NOW(), INTERVAL 60 SECOND)
        ")->fetchColumn();

        // Đếm tổng số lượt chấm bị Lỗi Hệ Thống (SE - System Error) và Lỗi Biên Dịch (CE - Compile Error).
        $seCount = (int)$pdo->query("SELECT COUNT(*) FROM judging_runs WHERE status = 'SystemError' OR verdict = 'SE'")->fetchColumn();
        $ceCount = (int)$pdo->query("SELECT COUNT(*) FROM judging_runs WHERE verdict = 'CE'")->fetchColumn();
        // Tổng số lượt đang chờ trong hàng đợi của cả Python và C++.
        $totalQueued = $byLang['python']['Queued'] + $byLang['cpp']['Queued'];

        // Trả về báo cáo sức khỏe hệ thống chấm bài cho bảng điều khiển Admin.
        echo json_encode([
            'success' => true,
            'compilers' => [
                'python' => ['available' => (bool)$pyPath, 'path' => $pyPath, 'version' => $pyVersion],
                'cpp'    => ['available' => (bool)$gppPath, 'path' => $gppPath, 'version' => $gppVersion]
            ],
            'queue_by_language' => $byLang,
            'summary' => [
                'total_queued'  => $totalQueued,
                'total_running' => $byLang['python']['Running'] + $byLang['cpp']['Running'],
                'stalled_count' => $stalledCount,
                'se_count'      => $seCount,
                'ce_count'      => $ceCount,
                // Bật cờ cảnh báo tràn hàng đợi nếu có từ 50 lượt trở lên đang xếp hàng chờ chấm.
                'queue_overflow_warning' => $totalQueued >= 50
            ]
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Hành động GET không hợp lệ -> trả về mã HTTP 400 (Bad Request).
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Hành động GET không hợp lệ.'], JSON_UNESCAPED_UNICODE);
    exit;
}

// =============================================================================
// NHÁNH HTTP POST: THỰC HIỆN CÁC LỆNH ĐIỀU KHIỂN CHẤM LẠI / XỬ LÝ SỰ CỐ
// =============================================================================
// Nếu không phải GET (đã xử lý ở trên) và cũng không phải POST -> trả về lỗi HTTP 405 (Method Not Allowed).
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Phương thức không hợp lệ.'], JSON_UNESCAPED_UNICODE);
    exit;
}

// Đọc dữ liệu JSON từ body request; nếu không phải JSON thì lấy từ `$_POST` (form-urlencoded).
$rawBody = file_get_contents('php://input');
$input = json_decode((string)$rawBody, true);
if (!is_array($input)) {
    $input = $_POST;
}

// Xác định hành động Admin yêu cầu thực hiện.
$action = trim((string)($input['action'] ?? ''));

try {
    // =========================================================================
    // 1. KÍCH HOẠT REJUDGE (THEO BÀI TẬP, SINH VIÊN CỤ THỂ, HOẶC 1 BÀI NỘP)
    // =========================================================================
    if ($action === 'rejudge') {
        // Đọc các tiêu chí lọc phạm vi chấm lại (ép sang số nguyên dương; nếu bỏ trống thì bằng 0).
        $problemId    = isset($input['problem_id']) && $input['problem_id'] !== '' ? (int)$input['problem_id'] : 0;
        $userId       = isset($input['user_id']) && $input['user_id'] !== '' ? (int)$input['user_id'] : 0;
        $submissionId = isset($input['submission_id']) && $input['submission_id'] !== '' ? (int)$input['submission_id'] : 0;
        $versionId    = isset($input['version_id']) && $input['version_id'] !== '' ? (int)$input['version_id'] : 0;

        // Bắt buộc chọn ít nhất 1 phạm vi để tránh Admin bấm nhầm rejudge toàn bộ CSDL cùng lúc.
        if ($problemId <= 0 && $userId <= 0 && $submissionId <= 0) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'error'   => 'Vui lòng chọn ít nhất một phạm vi Rejudge: Bài tập, Sinh viên cụ thể, hoặc Mã bài nộp.'
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        // Xây dựng mệnh đề WHERE động kèm mảng tham số `$params` (giữ an toàn tuyệt đối chống SQL Injection).
        $where = [];
        $params = [];
        if ($submissionId > 0) {
            $where[] = "s.id = ?";
            $params[] = $submissionId;
        }
        if ($problemId > 0) {
            $where[] = "s.problem_id = ?";
            $params[] = $problemId;
        }
        if ($userId > 0) {
            $where[] = "s.user_id = ?";
            $params[] = $userId;
        }

        // Truy vấn danh sách các bài nộp thỏa điều kiện, kèm `current_version_id` hiện hành của từng bài tập.
        $sql = "
            SELECT s.id, s.problem_id, p.current_version_id
            FROM submissions s
            JOIN problems p ON s.problem_id = p.id
            WHERE " . implode(' AND ', $where) . "
            ORDER BY s.id ASC
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $targets = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Nếu không tìm thấy bài nộp nào phù hợp, trả về thông báo với `queued_count = 0`.
        if (empty($targets)) {
            echo json_encode([
                'success'      => true,
                'queued_count' => 0,
                'message'      => 'Không có bài nộp nào khớp điều kiện để chấm lại.'
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        // Bắt đầu Transaction: tạo đồng loạt các lượt chấm mới và trỏ `latest_run_id` trên từng bài nộp sang lượt mới.
        $pdo->beginTransaction();
        // Lấy số thứ tự lần chạy cao nhất hiện có của mỗi bài nộp để tăng lên +1.
        $maxRunStmt = $pdo->prepare("SELECT COALESCE(MAX(run_number), 0) FROM judging_runs WHERE submission_id = ?");
        // Chèn bản ghi lượt chạy mới với trạng thái chờ (`Queued`), không xóa hay ghi đè các lượt chấm trước đó.
        $insRunStmt = $pdo->prepare("
            INSERT INTO judging_runs (submission_id, problem_version_id, run_number, triggered_by, status, verdict)
            VALUES (?, ?, ?, 'rejudge', 'Queued', 'Pending')
        ");
        // Cập nhật bảng `submissions` trỏ sang phiên bản mới, lượt chấm mới nhất và đưa trạng thái hiển thị về 'Pending'.
        $updSubStmt = $pdo->prepare("
            UPDATE submissions
            SET problem_version_id = ?, latest_run_id = ?, final_verdict = 'Pending'
            WHERE id = ?
        ");

        $runIds = [];
        foreach ($targets as $sub) {
            // Chỉ áp dụng `version_id` tùy chọn khi lọc theo một bài tập cụ thể; ngược lại dùng phiên bản hiện hành của bài tập đó.
            $targetVerId = ($versionId > 0 && $problemId > 0) ? $versionId : (int)$sub['current_version_id'];
            if ($targetVerId <= 0) {
                continue;
            }
            $maxRunStmt->execute([(int)$sub['id']]);
            $nextRunNumber = (int)$maxRunStmt->fetchColumn() + 1;

            $insRunStmt->execute([(int)$sub['id'], $targetVerId, $nextRunNumber]);
            $newRunId = (int)$pdo->lastInsertId();
            $updSubStmt->execute([$targetVerId, $newRunId, (int)$sub['id']]);
            $runIds[] = $newRunId;
        }
        // Xác nhận lưu toàn bộ giao dịch vào CSDL.
        $pdo->commit();

        // Kích hoạt Worker chạy ngầm để lấy các bài vừa xếp hàng ra chấm ngay.
        if (!empty($runIds)) {
            WorkerLauncher::triggerAsync();
        }

        echo json_encode([
            'success'      => true,
            'queued_count' => count($runIds),
            'run_ids'      => $runIds,
            'message'      => 'Đã đưa thành công ' . count($runIds) . ' bài nộp vào hàng đợi chấm lại (Rejudge) với các bản ghi judging_runs mới!'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // =========================================================================
    // 2. XỬ LÝ LỖI HỆ THỐNG: THỬ LẠI CÁC LƯỢT SE / GIẢI PHÓNG LƯỢT TREO
    // - `release_stalled`: Kết thúc các lượt bị kẹt 'Running' quá 60s (đánh dấu SE) rồi xếp lượt mới.
    // - `retry_se`       : Tìm các bài nộp có lượt chấm mới nhất đang bị SE rồi xếp lượt mới.
    // =========================================================================
    if ($action === 'retry_se' || $action === 'release_stalled') {
        $pdo->beginTransaction();

        if ($action === 'release_stalled') {
            // Tìm các lượt chấm đang ở trạng thái 'Running' quá 60 giây (dấu hiệu Worker bị sập/treo giữa chừng).
            $stalledStmt = $pdo->query("
                SELECT jr.id, jr.submission_id, jr.problem_version_id
                FROM judging_runs jr
                WHERE jr.status = 'Running' AND jr.started_at IS NOT NULL AND jr.started_at < DATE_SUB(NOW(), INTERVAL 60 SECOND)
            ");
            $items = $stalledStmt->fetchAll(PDO::FETCH_ASSOC);

            // Chuyển các lượt bị treo đó sang trạng thái 'SystemError' và nối thêm ghi chú vào cột nhật ký lỗi (`system_error_log`).
            $markErr = $pdo->prepare("
                UPDATE judging_runs
                SET status = 'SystemError', verdict = 'SE', finished_at = NOW(),
                    system_error_log = CONCAT(COALESCE(system_error_log, ''), '\n[Admin] Phát hiện tiến trình treo quá 60s, đã giải phóng và đưa vào hàng đợi chấm lại.')
                WHERE id = ?
            ");
            foreach ($items as $it) {
                $markErr->execute([(int)$it['id']]);
            }
        } else {
            // Lấy các bài nộp có lượt chấm mới nhất (`latest_run_id`) đang bị Lỗi Hệ Thống (SystemError / SE).
            $items = $pdo->query("
                SELECT jr.id, jr.submission_id, p.current_version_id AS problem_version_id
                FROM submissions s
                JOIN judging_runs jr ON s.latest_run_id = jr.id
                JOIN problems p ON s.problem_id = p.id
                WHERE jr.status = 'SystemError' OR s.final_verdict = 'SE'
            ")->fetchAll(PDO::FETCH_ASSOC);
        }

        // Chuẩn bị các câu lệnh tạo lượt chấm thử lại (`triggered_by = 'retry'`) và cập nhật trạng thái bài nộp.
        $maxRunStmt = $pdo->prepare("SELECT COALESCE(MAX(run_number), 0) FROM judging_runs WHERE submission_id = ?");
        $insRunStmt = $pdo->prepare("
            INSERT INTO judging_runs (submission_id, problem_version_id, run_number, triggered_by, status, verdict)
            VALUES (?, ?, ?, 'retry', 'Queued', 'Pending')
        ");
        $updSubStmt = $pdo->prepare("UPDATE submissions SET latest_run_id = ?, final_verdict = 'Pending' WHERE id = ?");

        // Duyệt từng mục gặp sự cố để tạo lượt chấm mới an toàn vào hàng đợi.
        $retried = 0;
        foreach ($items as $it) {
            $subId = (int)$it['submission_id'];
            $verId = (int)$it['problem_version_id'];
            if ($verId <= 0) continue;

            $maxRunStmt->execute([$subId]);
            $nextRun = (int)$maxRunStmt->fetchColumn() + 1;

            $insRunStmt->execute([$subId, $verId, $nextRun]);
            $newRunId = (int)$pdo->lastInsertId();
            $updSubStmt->execute([$newRunId, $subId]);
            $retried++;
        }

        // Lưu toàn bộ thay đổi của Transaction xuống CSDL.
        $pdo->commit();

        // Nếu có ít nhất 1 lượt được xếp vào hàng đợi, đánh thức Worker chạy ngầm để chấm lại ngay.
        if ($retried > 0) {
            WorkerLauncher::triggerAsync();
        }

        echo json_encode([
            'success'       => true,
            'retried_count' => $retried,
            'message'       => $retried > 0
                ? "Đã tạo {$retried} lượt chấm thử lại (Retry) an toàn vào hàng đợi."
                : "Không có lượt chấm lỗi hệ thống (SE) hoặc bị treo nào cần xử lý."
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Nếu hành động POST không nằm trong các lệnh được hỗ trợ -> trả về mã lỗi HTTP 400.
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => "Hành động '{$action}' không được hỗ trợ."], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    // Bắt mọi ngoại lệ phát sinh trong khối try: nếu đang mở Transaction thì hoàn tác (rollBack) để bảo toàn dữ liệu.
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error'   => 'Lỗi vận hành hệ thống chấm bài: ' . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}