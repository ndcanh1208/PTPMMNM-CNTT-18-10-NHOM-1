<?php
/**
 * ByteFlow Online Judge - API Nhập & Thẩm định Bộ Test Case từ File (ZIP / JSON)
 * POST /api/import_testcases.php
 * 
 * Hỗ trợ 3 Cấp độ Thẩm định chuẩn mực:
 * - Cấp độ 1: Kiểm định cấu trúc file (Ghép cặp .in/.out, chống Zip Slip, loại file nhị phân)
 * - Cấp độ 2: Kiểm định quy cách dữ liệu (Chuẩn hóa CRLF -> LF, gỡ UTF-8 BOM, kiểm tra rỗng)
 * - Cấp độ 3: Đối soát tính đúng đắn với Lời giải chuẩn (Model Solution Verification)
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../core/Auth.php';
require_once __DIR__ . '/../core/Security.php';
require_once __DIR__ . '/../core/Comparator.php';
require_once __DIR__ . '/../core/SandboxRunner.php';

// 1. Kiểm tra xác thực & Phân quyền (Admin / Giảng viên)
if (!Auth::check() || (!Auth::isAdmin() && !Auth::isInstructor())) {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'error'   => '403 Forbidden - Bạn không có quyền nhập bộ test case.'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'error'   => 'Phương thức không được hỗ trợ (chỉ nhận POST).'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!isset($_FILES['testcase_file']) || $_FILES['testcase_file']['error'] !== UPLOAD_ERR_OK) {
    http_response_code(400);
    $errorCode = $_FILES['testcase_file']['error'] ?? UPLOAD_ERR_NO_FILE;
    $errorMsg = match ($errorCode) {
        UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'Kích thước tệp vượt quá giới hạn tải lên của máy chủ.',
        UPLOAD_ERR_NO_FILE                        => 'Vui lòng chọn tệp ZIP hoặc JSON chứa bộ test case.',
        default                                   => 'Lỗi tải lên tệp (Mã lỗi: ' . $errorCode . ').'
    };
    echo json_encode(['success' => false, 'error' => $errorMsg], JSON_UNESCAPED_UNICODE);
    exit;
}

$uploadedFile = $_FILES['testcase_file'];
$fileName     = $uploadedFile['name'];
$fileTmpPath  = $uploadedFile['tmp_name'];
$fileSize     = (int)$uploadedFile['size'];

// Giới hạn file tải lên tối đa 25MB
if ($fileSize > 25 * 1024 * 1024) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error'   => 'Kích thước tệp quá lớn (Tối đa 25MB).'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
if (!in_array($ext, ['zip', 'json'], true)) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error'   => 'Định dạng tệp không được hỗ trợ. Vui lòng tải lên tệp .ZIP hoặc .JSON.'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// Thông số Model Solution (Tùy chọn)
$modelSolution = trim((string)($_POST['model_solution'] ?? ''));
$solutionLang  = in_array($_POST['solution_lang'] ?? '', ['python', 'cpp'], true) ? $_POST['solution_lang'] : 'python';

$rawFiles = [];
$warnings = [];

// =============================================================================
// CẤP ĐỘ 1 & 2: GIẢI NÉN VÀ ĐỌC NỘI DUNG VĂN BẢN
// =============================================================================
if ($ext === 'zip') {
    if (!class_exists('ZipArchive')) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error'   => 'Máy chủ chưa kích hoạt phần mở rộng php_zip (ZipArchive).'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $zip = new ZipArchive();
    $openRes = $zip->open($fileTmpPath);
    if ($openRes !== true) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error'   => 'Không thể mở tệp nén ZIP (Mã lỗi ZipArchive: ' . $openRes . '). Tệp có thể bị hỏng.'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Đọc từng entry trong ZIP
    $allowedExts = ['in', 'inp', 'out', 'ans', 'txt'];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $entryName = $zip->getNameIndex($i);

        // Bỏ qua thư mục
        if (str_ends_with($entryName, '/') || str_ends_with($entryName, '\\')) {
            continue;
        }

        // Bỏ qua file ẩn hoặc file rác OS
        if (str_starts_with(basename($entryName), '.') || str_contains($entryName, '__MACOSX')) {
            continue;
        }

        // Chống Zip Slip / Path Traversal
        if (str_contains($entryName, '../') || str_contains($entryName, '..\\')) {
            $warnings[] = "Bỏ qua file nghi vấn đường dẫn không an toàn: " . htmlspecialchars($entryName);
            continue;
        }

        $entryExt = strtolower(pathinfo($entryName, PATHINFO_EXTENSION));
        if (!in_array($entryExt, $allowedExts, true)) {
            $warnings[] = "Bỏ qua file không phải dữ liệu test: " . htmlspecialchars($entryName);
            continue;
        }

        $content = $zip->getFromIndex($i);
        if ($content === false) {
            $warnings[] = "Không đọc được nội dung file: " . htmlspecialchars($entryName);
            continue;
        }

        // Kiểm tra nhị phân (Binary check)
        if (str_contains($content, "\0")) {
            $warnings[] = "Bỏ qua file nhị phân (chứa byte null): " . htmlspecialchars($entryName);
            continue;
        }

        // Chuẩn hóa văn bản: gỡ UTF-8 BOM và đưa CRLF về LF
        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content);
        $content = str_replace(["\r\n", "\r"], "\n", $content);

        $rawFiles[$entryName] = $content;
    }
    $zip->close();

} elseif ($ext === 'json') {
    $jsonContent = file_get_contents($fileTmpPath);
    $data = json_decode((string)$jsonContent, true);
    if (!is_array($data)) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error'   => 'Nội dung tệp JSON không hợp lệ (cần định dạng mảng các test case).'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $formattedCases = [];
    foreach ($data as $idx => $item) {
        $in = (string)($item['input_data'] ?? ($item['input'] ?? ''));
        $out = (string)($item['expected_output'] ?? ($item['output'] ?? ''));
        $isSample = !empty($item['is_sample']) || (($item['type'] ?? '') === 'sample') ? 1 : 0;
        $isHidden = $isSample ? 0 : 1;
        $points = max(1, (int)($item['points'] ?? 10));

        $in = preg_replace('/^\xEF\xBB\xBF/', '', $in);
        $in = str_replace(["\r\n", "\r"], "\n", $in);
        $out = preg_replace('/^\xEF\xBB\xBF/', '', $out);
        $out = str_replace(["\r\n", "\r"], "\n", $out);

        $formattedCases[] = [
            'case_order'      => $idx + 1,
            'base_name'       => "Case #" . ($idx + 1),
            'input_name'      => "case" . ($idx + 1) . ".in",
            'output_name'     => "case" . ($idx + 1) . ".out",
            'input_data'      => $in,
            'expected_output' => $out,
            'input_size'      => strlen($in),
            'output_size'     => strlen($out),
            'input_preview'   => mb_substr($in, 0, 150),
            'output_preview'  => mb_substr($out, 0, 150),
            'is_sample'       => $isSample,
            'is_hidden'       => $isHidden,
            'points'          => $points,
            'status'          => 'VALID',
            'status_label'    => 'Hợp lệ',
            'model_verified'  => null
        ];
    }

    echo json_encode([
        'success'      => true,
        'source_type'  => 'JSON',
        'total_found'  => count($formattedCases),
        'valid_count'  => count($formattedCases),
        'orphan_count' => 0,
        'warnings'     => $warnings,
        'test_cases'   => $formattedCases,
        'orphans'      => []
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// =============================================================================
// THUẬT TOÁN GHÉP CẶP FILE TEST (.in/.out) TỪ TỆP ZIP
// =============================================================================
$inputs  = [];
$outputs = [];

foreach ($rawFiles as $path => $content) {
    $normalizedPath = str_replace('\\', '/', $path);
    $fileNameOnly   = basename($normalizedPath);
    $dirName        = dirname($normalizedPath);
    $dirPrefix      = ($dirName !== '.' && $dirName !== '') ? $dirName . '/' : '';

    // Phân loại Input
    if (preg_match('/^(.*)\.(in|inp)$/i', $fileNameOnly, $m)) {
        $key = $dirPrefix . $m[1];
        $inputs[$key] = ['file' => $path, 'content' => $content, 'base' => $m[1]];
    } elseif (preg_match('/^input[-_]?(.*)\.txt$/i', $fileNameOnly, $m)) {
        $key = $dirPrefix . ($m[1] ?: '1');
        $inputs[$key] = ['file' => $path, 'content' => $content, 'base' => ($m[1] ?: '1')];
    } elseif ($fileNameOnly === 'input.txt' && $dirPrefix !== '') {
        $key = rtrim($dirPrefix, '/');
        $inputs[$key] = ['file' => $path, 'content' => $content, 'base' => $key];
    }
    // Phân loại Output
    elseif (preg_match('/^(.*)\.(out|ans)$/i', $fileNameOnly, $m)) {
        $key = $dirPrefix . $m[1];
        $outputs[$key] = ['file' => $path, 'content' => $content, 'base' => $m[1]];
    } elseif (preg_match('/^output[-_]?(.*)\.txt$/i', $fileNameOnly, $m)) {
        $key = $dirPrefix . ($m[1] ?: '1');
        $outputs[$key] = ['file' => $path, 'content' => $content, 'base' => ($m[1] ?: '1')];
    } elseif (($fileNameOnly === 'output.txt' || $fileNameOnly === 'ans.txt') && $dirPrefix !== '') {
        $key = rtrim($dirPrefix, '/');
        $outputs[$key] = ['file' => $path, 'content' => $content, 'base' => $key];
    }
}

// Gom danh sách tất cả các key duy nhất
$allKeys = array_unique(array_merge(array_keys($inputs), array_keys($outputs)));

// Sắp xếp tự nhiên (Natural Sort: 1, 2, 10 thay vì 1, 10, 2)
uksort($allKeys, fn($a, $b) => strnatcasecmp((string)$allKeys[$a], (string)$allKeys[$b]));
$sortedKeys = array_values($allKeys);
usort($sortedKeys, 'strnatcasecmp');

$pairedCases = [];
$orphans     = [];

foreach ($sortedKeys as $key) {
    $hasIn  = isset($inputs[$key]);
    $hasOut = isset($outputs[$key]);

    if ($hasIn && $hasOut) {
        $inContent  = $inputs[$key]['content'];
        $outContent = $outputs[$key]['content'];

        $pairedCases[] = [
            'base_name'       => $key,
            'input_name'      => basename($inputs[$key]['file']),
            'output_name'     => basename($outputs[$key]['file']),
            'input_data'      => $inContent,
            'expected_output' => $outContent,
            'input_size'      => strlen($inContent),
            'output_size'     => strlen($outContent),
            'input_preview'   => mb_substr($inContent, 0, 150),
            'output_preview'  => mb_substr($outContent, 0, 150),
            'points'          => 10,
            'status'          => 'VALID',
            'status_label'    => 'Ghép cặp thành công',
            'model_verified'  => null
        ];
    } elseif ($hasIn && !$hasOut) {
        $orphans[] = [
            'key'     => $key,
            'type'    => 'missing_output',
            'file'    => $inputs[$key]['file'],
            'message' => "Có file đầu vào ({$inputs[$key]['file']}) nhưng thiếu file kết quả (.out / .ans)."
        ];
    } elseif (!$hasIn && $hasOut) {
        $orphans[] = [
            'key'     => $key,
            'type'    => 'missing_input',
            'file'    => $outputs[$key]['file'],
            'message' => "Có file kết quả ({$outputs[$key]['file']}) nhưng thiếu file đầu vào (.in / .inp)."
        ];
    }
}

if (empty($pairedCases) && empty($orphans)) {
    http_response_code(400);
    echo json_encode([
        'success'  => false,
        'error'    => 'Không tìm thấy cặp test case hợp lệ nào trong tệp ZIP. Vui lòng đặt tên file theo cặp (ví dụ: 1.in & 1.out, test1.in & test1.out, hoặc input01.txt & output01.txt).',
        'warnings' => $warnings
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// Mặc định: Test case đầu tiên là Công khai (Sample), các test sau là Chấm kín (Hidden)
foreach ($pairedCases as $idx => &$tc) {
    $tc['case_order'] = $idx + 1;
    $tc['is_sample']  = ($idx === 0) ? 1 : 0;
    $tc['is_hidden']  = ($idx === 0) ? 0 : 1;
}
unset($tc);

// =============================================================================
// CẤP ĐỘ 3: ĐỐI SOÁT VỚI LỜI GIẢI CHUẨN (MODEL SOLUTION VERIFICATION)
// =============================================================================
$modelSolutionRan = false;
$modelCompileError = null;

if ($modelSolution !== '') {
    $sandbox = new SandboxRunner();
    $tempRunDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'model_verify_' . bin2hex(random_bytes(6));
    @mkdir($tempRunDir, 0777, true);

    $compileRes = $sandbox->compile($solutionLang, $modelSolution, $tempRunDir);

    if (!$compileRes['success']) {
        $modelCompileError = $compileRes['message'] ?: 'Lời giải chuẩn gặp lỗi biên dịch (Compile Error).';
        $warnings[] = "Lời giải chuẩn lỗi biên dịch: " . $modelCompileError;
    } else {
        $modelSolutionRan = true;
        $executable = $compileRes['executable'];

        foreach ($pairedCases as &$tc) {
            $runRes = $sandbox->runTestCase($solutionLang, $executable, $tc['input_data'], 2000, 262144);
            
            if ($runRes['verdict'] === 'AC' || $runRes['actual_output'] !== '') {
                $actual = $runRes['actual_output'];
                $comp   = Comparator::compare($actual, $tc['expected_output']);

                if (!empty($comp['match'])) {
                    $tc['model_verified'] = true;
                    $tc['status']         = 'VERIFIED';
                    $tc['status_label']   = '✅ Khớp Lời giải chuẩn';
                    $tc['actual_output']  = $actual;
                } else {
                    $tc['model_verified'] = false;
                    $tc['status']         = 'MISMATCH';
                    $tc['status_label']   = '⚠️ Lệch so với Lời giải chuẩn';
                    $tc['actual_output']  = $actual;
                    $tc['diff_line']      = $comp['diff_line'] ?? null;
                    $warnings[] = "Test case #{$tc['case_order']} ({$tc['input_name']}) có kết quả khác với Lời giải chuẩn.";
                }
            } else {
                $tc['model_verified'] = false;
                $tc['status']         = 'RUNTIME_ERROR';
                $tc['status_label']   = '❌ Lời giải chuẩn bị lỗi ' . $runRes['verdict'];
                $warnings[] = "Lời giải chuẩn bị lỗi ({$runRes['verdict']}) khi chạy trên test case #{$tc['case_order']}.";
            }
        }
        unset($tc);
    }

    // Dọn dẹp thư mục tạm an toàn
    $cleanTempDir = function ($dir) use (&$cleanTempDir) {
        if (!is_dir($dir)) return;
        $items = @scandir($dir);
        if ($items === false) return;
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') continue;
            $p = $dir . DIRECTORY_SEPARATOR . $item;
            is_dir($p) ? $cleanTempDir($p) : @unlink($p);
        }
        @rmdir($dir);
    };
    $cleanTempDir($tempRunDir);
}

// 4. Trả về Báo cáo Thẩm định (Validation Report)
echo json_encode([
    'success'              => true,
    'source_type'          => 'ZIP',
    'total_found'          => count($pairedCases) + count($orphans),
    'valid_count'          => count($pairedCases),
    'orphan_count'         => count($orphans),
    'model_solution_ran'   => $modelSolutionRan,
    'model_compile_error'  => $modelCompileError,
    'warnings'             => $warnings,
    'test_cases'           => $pairedCases,
    'orphans'              => $orphans
], JSON_UNESCAPED_UNICODE);
