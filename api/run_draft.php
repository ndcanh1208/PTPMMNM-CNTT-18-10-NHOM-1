<?php
/**
 * API XỬ LÝ CHẠY THỬ VỚI INPUT TỰ NHẬP (RUN DRAFT CODE)
 * Đề tài 20: Mục 2.2 - Soạn mã và chạy thử với dữ liệu tự nhập, tách biệt khỏi bảng nộp chính
 */

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../core/ExecutionService.php';

// Chỉ nhận phương thức POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Chỉ chấp nhận phương thức POST']);
    exit;
}

$rawInput = file_get_contents('php://input');
$data = json_decode($rawInput, true);

if (!$data) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Dữ liệu JSON không hợp lệ']);
    exit;
}

$sourceCode  = trim($data['source_code'] ?? '');
$languageId  = trim($data['language_id'] ?? 'python3');
$customInput = $data['custom_input'] ?? '';
$timeLimitMs = (int)($data['time_limit_ms'] ?? DEFAULT_TIME_LIMIT_MS);

if (empty($sourceCode)) {
    echo json_encode([
        'success' => false,
        'status'  => 'EMPTY_CODE',
        'message' => 'Mã nguồn không được để trống'
    ]);
    exit;
}

// Gọi dịch vụ thực thi cách ly Piston API
$result = ExecutionService::execute($sourceCode, $languageId, $customInput, $timeLimitMs);

echo json_encode([
    'success' => true,
    'status'  => $result['status'],
    'stdout'  => $result['stdout'] ?? '',
    'stderr'  => $result['stderr'] ?? '',
    'message' => $result['message'] ?? '',
    'time_ms' => $result['time_ms'] ?? 0
]);
