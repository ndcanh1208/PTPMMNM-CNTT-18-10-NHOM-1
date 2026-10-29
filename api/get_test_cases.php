<?php
/**
 * API LẤY DANH SÁCH TEST CASES DÀNH CHO GIẢNG VIÊN VÀ QUẢN TRỊ VIÊN
 * Đề tài 20: Giảng viên tra cứu đầy đủ cả test công khai (sample) và test kín (hidden)
 */

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';

// Chỉ cho phép Giảng viên hoặc Quản trị viên
if (!isLoggedIn() || !hasRole(['LECTURER', 'ADMIN'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Bạn không có quyền truy cập dữ liệu ca kiểm thử!']);
    exit;
}

$problemId = (int)($_GET['problem_id'] ?? 0);
$versionId = (int)($_GET['version_id'] ?? 0);

if ($problemId <= 0) {
    echo json_encode(['success' => false, 'message' => 'Mã bài tập không hợp lệ!']);
    exit;
}

try {
    $pdo = Database::getConnection();

    // 1. Lấy thông tin bài tập
    $stmtP = $pdo->prepare("SELECT id, code, title, topic, difficulty, current_version_id FROM problems WHERE id = ?");
    $stmtP->execute([$problemId]);
    $problem = $stmtP->fetch();

    if (!$problem) {
        echo json_encode(['success' => false, 'message' => 'Không tìm thấy bài tập được yêu cầu!']);
        exit;
    }

    // 2. Lấy danh sách toàn bộ các phiên bản của bài tập này
    $stmtVersions = $pdo->prepare("
        SELECT id, version_number, change_log, time_limit_ms, memory_limit_kb, created_at,
               (SELECT COUNT(*) FROM test_cases tc WHERE tc.problem_version_id = pv.id) as test_count
        FROM problem_versions pv
        WHERE pv.problem_id = ?
        ORDER BY pv.version_number DESC
    ");
    $stmtVersions->execute([$problemId]);
    $allVersions = $stmtVersions->fetchAll();

    // Xác định phiên bản cần lấy
    if ($versionId <= 0) {
        $versionId = (int)($problem['current_version_id'] ?? ($allVersions[0]['id'] ?? 0));
    }

    // Lấy thông tin phiên bản mục tiêu
    $stmtCurVer = $pdo->prepare("SELECT * FROM problem_versions WHERE id = ? AND problem_id = ?");
    $stmtCurVer->execute([$versionId, $problemId]);
    $currentVer = $stmtCurVer->fetch();

    // 3. Lấy toàn bộ danh sách test cases của phiên bản này
    $testCases = [];
    if ($currentVer) {
        $stmtTests = $pdo->prepare("
            SELECT id, problem_version_id, input_data, expected_output, is_sample, score_weight, order_index
            FROM test_cases
            WHERE problem_version_id = ?
            ORDER BY order_index ASC, id ASC
        ");
        $stmtTests->execute([$versionId]);
        $testCases = $stmtTests->fetchAll();
    }

    // Thống kê nhanh
    $sampleCount = 0;
    $hiddenCount = 0;
    $totalWeight = 0;
    foreach ($testCases as $tc) {
        if ($tc['is_sample']) $sampleCount++;
        else $hiddenCount++;
        $totalWeight += (int)$tc['score_weight'];
    }

    echo json_encode([
        'success' => true,
        'problem' => [
            'id' => (int)$problem['id'],
            'code' => $problem['code'],
            'title' => $problem['title'],
            'topic' => $problem['topic'],
            'difficulty' => $problem['difficulty']
        ],
        'current_version' => $currentVer ? [
            'id' => (int)$currentVer['id'],
            'version_number' => (int)$currentVer['version_number'],
            'change_log' => $currentVer['change_log'],
            'time_limit_ms' => (int)$currentVer['time_limit_ms'],
            'memory_limit_kb' => (int)$currentVer['memory_limit_kb'],
            'created_at' => $currentVer['created_at']
        ] : null,
        'versions_list' => $allVersions,
        'stats' => [
            'total_tests' => count($testCases),
            'sample_count' => $sampleCount,
            'hidden_count' => $hiddenCount,
            'total_weight' => $totalWeight
        ],
        'test_cases' => $testCases
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Lỗi hệ thống: ' . $e->getMessage()]);
}
