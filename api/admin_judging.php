<?php
/**
 * Nhom1 Online Judge - API Quản lý Chấm bài, Giám sát Hàng đợi & Điều khiển Rejudge (Admin Only)
 * GET/POST /api/admin_judging.php
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../core/Auth.php';
require_once __DIR__ . '/../core/SandboxRunner.php';
require_once __DIR__ . '/../core/WorkerLauncher.php';

Auth::startSession();

if (!Auth::check() || !Auth::isAdmin()) {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'error'   => '403 Forbidden - Chỉ Quản trị viên (Admin) mới có quyền vận hành hệ thống chấm bài.'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$pdo = getDbConnection();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $action = trim((string)($_GET['action'] ?? 'status'));

    if ($action === 'status') {
        $sandbox = new SandboxRunner();
        $pyPath  = $sandbox->getPythonPath();
        $gppPath = $sandbox->getGppPath();

        $pyVersion = null;
        if ($pyPath) {
            $out = @shell_exec("\"{$pyPath}\" --version 2>&1");
            $pyVersion = $out ? trim($out) : 'Sẵn sàng';
        }

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

        // Thống kê hàng đợi theo ngôn ngữ (Python & C++)
        $queueStatsRaw = $pdo->query("
            SELECT s.language, jr.status, COUNT(*) AS cnt
            FROM judging_runs jr
            JOIN submissions s ON jr.submission_id = s.id
            GROUP BY s.language, jr.status
        ")->fetchAll(PDO::FETCH_ASSOC);

        $byLang = [
            'python' => ['Queued' => 0, 'Running' => 0, 'Completed' => 0, 'SystemError' => 0, 'Superseded' => 0],
            'cpp'    => ['Queued' => 0, 'Running' => 0, 'Completed' => 0, 'SystemError' => 0, 'Superseded' => 0]
        ];
        foreach ($queueStatsRaw as $row) {
            $lang = $row['language'];
            $st   = $row['status'];
            if (isset($byLang[$lang][$st])) {
                $byLang[$lang][$st] = (int)$row['cnt'];
            }
        }

        // Số lượt chạy bị treo quá 60 giây
        $stalledCount = (int)$pdo->query("
            SELECT COUNT(*) FROM judging_runs
            WHERE status = 'Running' AND started_at IS NOT NULL AND started_at < DATE_SUB(NOW(), INTERVAL 60 SECOND)
        ")->fetchColumn();

        $seCount = (int)$pdo->query("SELECT COUNT(*) FROM judging_runs WHERE status = 'SystemError' OR verdict = 'SE'")->fetchColumn();
        $ceCount = (int)$pdo->query("SELECT COUNT(*) FROM judging_runs WHERE verdict = 'CE'")->fetchColumn();
        $totalQueued = $byLang['python']['Queued'] + $byLang['cpp']['Queued'];

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
                'queue_overflow_warning' => $totalQueued >= 50
            ]
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Hành động GET không hợp lệ.'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Phương thức không hợp lệ.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$rawBody = file_get_contents('php://input');
$input = json_decode((string)$rawBody, true);
if (!is_array($input)) {
    $input = $_POST;
}

$action = trim((string)($input['action'] ?? ''));

try {
    // =========================================================================
    // 1. KÍCH HOẠT REJUDGE (THEO BÀI TẬP, SINH VIÊN CỤ THỂ, HOẶC 1 BÀI NỘP)
    // =========================================================================
    if ($action === 'rejudge') {
        $problemId    = isset($input['problem_id']) && $input['problem_id'] !== '' ? (int)$input['problem_id'] : 0;
        $userId       = isset($input['user_id']) && $input['user_id'] !== '' ? (int)$input['user_id'] : 0;
        $submissionId = isset($input['submission_id']) && $input['submission_id'] !== '' ? (int)$input['submission_id'] : 0;
        $versionId    = isset($input['version_id']) && $input['version_id'] !== '' ? (int)$input['version_id'] : 0;

        if ($problemId <= 0 && $userId <= 0 && $submissionId <= 0) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'error'   => 'Vui lòng chọn ít nhất một phạm vi Rejudge: Bài tập, Sinh viên cụ thể, hoặc Mã bài nộp.'
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

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

        if (empty($targets)) {
            echo json_encode([
                'success'      => true,
                'queued_count' => 0,
                'message'      => 'Không có bài nộp nào khớp điều kiện để chấm lại.'
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $pdo->beginTransaction();
        $maxRunStmt = $pdo->prepare("SELECT COALESCE(MAX(run_number), 0) FROM judging_runs WHERE submission_id = ?");
        $insRunStmt = $pdo->prepare("
            INSERT INTO judging_runs (submission_id, problem_version_id, run_number, triggered_by, status, verdict)
            VALUES (?, ?, ?, 'rejudge', 'Queued', 'Pending')
        ");
        $updSubStmt = $pdo->prepare("
            UPDATE submissions
            SET problem_version_id = ?, latest_run_id = ?, final_verdict = 'Pending'
            WHERE id = ?
        ");

        $runIds = [];
        foreach ($targets as $sub) {
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
        $pdo->commit();

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
    // =========================================================================
    if ($action === 'retry_se' || $action === 'release_stalled') {
        $pdo->beginTransaction();

        if ($action === 'release_stalled') {
            $stalledStmt = $pdo->query("
                SELECT jr.id, jr.submission_id, jr.problem_version_id
                FROM judging_runs jr
                WHERE jr.status = 'Running' AND jr.started_at IS NOT NULL AND jr.started_at < DATE_SUB(NOW(), INTERVAL 60 SECOND)
            ");
            $items = $stalledStmt->fetchAll(PDO::FETCH_ASSOC);

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
            // Lấy các bài nộp có lượt chấm mới nhất đang bị SE
            $items = $pdo->query("
                SELECT jr.id, jr.submission_id, p.current_version_id AS problem_version_id
                FROM submissions s
                JOIN judging_runs jr ON s.latest_run_id = jr.id
                JOIN problems p ON s.problem_id = p.id
                WHERE jr.status = 'SystemError' OR s.final_verdict = 'SE'
            ")->fetchAll(PDO::FETCH_ASSOC);
        }

        $maxRunStmt = $pdo->prepare("SELECT COALESCE(MAX(run_number), 0) FROM judging_runs WHERE submission_id = ?");
        $insRunStmt = $pdo->prepare("
            INSERT INTO judging_runs (submission_id, problem_version_id, run_number, triggered_by, status, verdict)
            VALUES (?, ?, ?, 'retry', 'Queued', 'Pending')
        ");
        $updSubStmt = $pdo->prepare("UPDATE submissions SET latest_run_id = ?, final_verdict = 'Pending' WHERE id = ?");

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

        $pdo->commit();

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

    http_response_code(400);
    echo json_encode(['success' => false, 'error' => "Hành động '{$action}' không được hỗ trợ."], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error'   => 'Lỗi vận hành hệ thống chấm bài: ' . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
