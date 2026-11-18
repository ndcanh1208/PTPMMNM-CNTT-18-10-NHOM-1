<?php
/**
 * Nhom1 Online Judge - API Nhập & Thẩm định Bộ Test Case từ File (ZIP / JSON)
 * POST /api/import_testcases.php
 * 
 * Hỗ trợ 3 Cấp độ Thẩm định chuẩn mực:
 * - Cấp độ 1: Kiểm định cấu trúc file (Ghép cặp .in/.out, chống Zip Slip, loại file nhị phân)
 * - Cấp độ 2: Kiểm định quy cách dữ liệu (Chuẩn hóa CRLF -> LF, gỡ UTF-8 BOM, kiểm tra rỗng)
 * - Cấp độ 3: Đối soát tính đúng đắn với Lời giải chuẩn (Model Solution Verification)
 *
 * Lưu ý cho người mới:
 * - API này KHÔNG lưu trực tiếp vào cơ sở dữ liệu mà đóng vai trò "kiểm duyệt & xem trước" (Preview/Validation).
 * - Nó đọc file ZIP hoặc JSON tải lên, làm sạch dữ liệu, ghép cặp input/output, chạy thử với lời giải mẫu (nếu có)
 *   rồi trả về bản báo cáo JSON chi tiết để Giảng viên/Admin kiểm tra trước khi bấm Lưu.
 */

// Bật chế độ kiểm tra kiểu dữ liệu nghiêm ngặt trong PHP.
declare(strict_types=1);

// Báo cho client biết phản hồi của API luôn là dữ liệu JSON mã hóa UTF-8.
header('Content-Type: application/json; charset=utf-8');

// Nạp các thành phần lõi:
// - database.php   : kết nối CSDL (nếu Auth hoặc các thành phần khác cần).
// - Auth.php       : kiểm tra đăng nhập và vai trò.
// - Security.php   : tiện ích bảo mật.
// - Comparator.php : bộ so sánh đầu ra thực tế với đáp án kỳ vọng (bỏ qua khoảng trắng thừa/dòng trống chuẩn OJ).
// - SandboxRunner.php: môi trường chạy thử mã nguồn (Lời giải chuẩn) an toàn cho Python / C++.
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../core/Auth.php';
require_once __DIR__ . '/../core/Security.php';
require_once __DIR__ . '/../core/Comparator.php';
require_once __DIR__ . '/../core/SandboxRunner.php';

// 1. Kiểm tra xác thực & Phân quyền (Admin / Giảng viên)
// Nếu chưa đăng nhập HOẶC không phải Admin và cũng không phải Giảng viên -> từ chối với mã HTTP 403 (Forbidden).
if (!Auth::check() || (!Auth::isAdmin() && !Auth::isInstructor())) {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'error'   => '403 Forbidden - Bạn không có quyền nhập bộ test case.'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// Chỉ chấp nhận phương thức HTTP POST vì API này nhận tệp tải lên (multipart/form-data).
// Nếu gọi bằng GET/PUT/DELETE... thì trả về mã HTTP 405 (Method Not Allowed).
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'error'   => 'Phương thức không được hỗ trợ (chỉ nhận POST).'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// Kiểm tra xem trình duyệt có gửi file với tên trường `testcase_file` và quá trình upload có thành công (UPLOAD_ERR_OK) không.
if (!isset($_FILES['testcase_file']) || $_FILES['testcase_file']['error'] !== UPLOAD_ERR_OK) {
    http_response_code(400);
    // Lấy mã lỗi upload của PHP (mặc định UPLOAD_ERR_NO_FILE nếu không chọn file).
    $errorCode = $_FILES['testcase_file']['error'] ?? UPLOAD_ERR_NO_FILE;
    // Dùng biểu thức `match` (PHP 8+) để chuyển mã lỗi kỹ thuật thành thông báo tiếng Việt thân thiện.
    $errorMsg = match ($errorCode) {
        UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'Kích thước tệp vượt quá giới hạn tải lên của máy chủ.',
        UPLOAD_ERR_NO_FILE                        => 'Vui lòng chọn tệp ZIP hoặc JSON chứa bộ test case.',
        default                                   => 'Lỗi tải lên tệp (Mã lỗi: ' . $errorCode . ').'
    };
    echo json_encode(['success' => false, 'error' => $errorMsg], JSON_UNESCAPED_UNICODE);
    exit;
}

// Trích xuất thông tin cơ bản của file vừa tải lên thư mục tạm của máy chủ.
$uploadedFile = $_FILES['testcase_file'];
$fileName     = $uploadedFile['name'];     // Tên gốc của file trên máy người dùng (ví dụ: testcases.zip).
$fileTmpPath  = $uploadedFile['tmp_name']; // Đường dẫn file tạm trên server do PHP lưu tạm.
$fileSize     = (int)$uploadedFile['size']; // Dung lượng file tính theo byte.

// Giới hạn file tải lên tối đa 25MB (25 * 1024 * 1024 bytes) để tránh tràn RAM hoặc tấn công làm đầy ổ đĩa.
if ($fileSize > 25 * 1024 * 1024) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error'   => 'Kích thước tệp quá lớn (Tối đa 25MB).'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// Lấy phần mở rộng (đuôi file) và chuyển thành chữ thường để kiểm tra (chỉ cho phép .zip hoặc .json).
$ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
if (!in_array($ext, ['zip', 'json'], true)) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error'   => 'Định dạng tệp không được hỗ trợ. Vui lòng tải lên tệp .ZIP hoặc .JSON.'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// Thông số Model Solution (Lời giải chuẩn - Tùy chọn cho Thẩm định Cấp độ 3)
// Nếu người dùng dán kèm code lời giải mẫu, hệ thống sẽ chạy code đó trên từng test để đối chiếu đáp án.
$modelSolution = trim((string)($_POST['model_solution'] ?? ''));
// Chỉ chấp nhận ngôn ngữ 'python' hoặc 'cpp'; nếu gửi giá trị khác sẽ mặc định về 'python'.
$solutionLang  = in_array($_POST['solution_lang'] ?? '', ['python', 'cpp'], true) ? $_POST['solution_lang'] : 'python';

// `$rawFiles`: lưu danh sách [đường_dẫn_file_trong_zip => nội_dung_đã_chuẩn_hóa].
// `$warnings`: gom các cảnh báo không nghiêm trọng (ví dụ file rác bị bỏ qua, test bị lệch...) để báo cho người dùng.
$rawFiles = [];
$warnings = [];

// =============================================================================
// CẤP ĐỘ 1 & 2: GIẢI NÉN VÀ ĐỌC NỘI DUNG VĂN BẢN
// - Nhánh ZIP : Kiểm tra an toàn từng file trong gói nén và chuẩn hóa văn bản.
// - Nhánh JSON: Đọc trực tiếp mảng test case, chuẩn hóa văn bản và trả kết quả ngay.
// =============================================================================
if ($ext === 'zip') {
    // Kiểm tra PHP trên máy chủ đã bật extension `zip` (lớp ZipArchive) hay chưa.
    if (!class_exists('ZipArchive')) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error'   => 'Máy chủ chưa kích hoạt phần mở rộng php_zip (ZipArchive).'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Khởi tạo đối tượng ZipArchive và mở tệp ZIP tạm vừa tải lên.
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

    // Đọc từng entry (mục/tệp con) nằm bên trong gói ZIP.
    // Chỉ cho phép các phần mở rộng quen thuộc của file dữ liệu chấm bài.
    $allowedExts = ['in', 'inp', 'out', 'ans', 'txt'];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        // Lấy tên/đường dẫn tương đối của mục thứ $i trong gói ZIP.
        $entryName = $zip->getNameIndex($i);

        // Bỏ qua các mục chỉ là thư mục (tên kết thúc bằng dấu '/' hoặc '\').
        if (str_ends_with($entryName, '/') || str_ends_with($entryName, '\\')) {
            continue;
        }

        // Bỏ qua file ẩn (bắt đầu bằng dấu chấm như .DS_Store) hoặc thư mục rác do macOS tạo ra (__MACOSX).
        if (str_starts_with(basename($entryName), '.') || str_contains($entryName, '__MACOSX')) {
            continue;
        }

        // [THẨM ĐỊNH CẤP ĐỘ 1] Chống lỗ hổng Zip Slip / Path Traversal:
        // Chặn các đường dẫn chứa `../` hoặc `..\` nhằm ngăn kẻ xấu thoát ra khỏi thư mục dự kiến.
        if (str_contains($entryName, '../') || str_contains($entryName, '..\\')) {
            $warnings[] = "Bỏ qua file nghi vấn đường dẫn không an toàn: " . htmlspecialchars($entryName);
            continue;
        }

        // [THẨM ĐỊNH CẤP ĐỘ 1] Kiểm tra đuôi file có thuộc danh sách cho phép hay không (loại ảnh, exe, pdf...).
        $entryExt = strtolower(pathinfo($entryName, PATHINFO_EXTENSION));
        if (!in_array($entryExt, $allowedExts, true)) {
            $warnings[] = "Bỏ qua file không phải dữ liệu test: " . htmlspecialchars($entryName);
            continue;
        }

        // Đọc thẳng nội dung file từ bộ nhớ ZIP mà KHÔNG giải nén ra ổ cứng (nhanh hơn và an toàn hơn).
        $content = $zip->getFromIndex($i);
        if ($content === false) {
            $warnings[] = "Không đọc được nội dung file: " . htmlspecialchars($entryName);
            continue;
        }

        // [THẨM ĐỊNH CẤP ĐỘ 1] Kiểm tra file nhị phân (Binary check):
        // File văn bản thuần không chứa ký tự byte null ("\0"); nếu có chứng tỏ là file nhị phân trá hình.
        if (str_contains($content, "\0")) {
            $warnings[] = "Bỏ qua file nhị phân (chứa byte null): " . htmlspecialchars($entryName);
            continue;
        }

        // [THẨM ĐỊNH CẤP ĐỘ 2] Chuẩn hóa văn bản:
        // 1. Gỡ 3 byte UTF-8 BOM (\xEF\xBB\xBF) ở đầu file (thường do Notepad Windows sinh ra gây lệch dữ liệu).
        // 2. Đưa mọi kiểu xuống dòng của Windows (\r\n) hoặc macOS cũ (\r) về chuẩn thống nhất LF (\n).
        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content);
        $content = str_replace(["\r\n", "\r"], "\n", $content);

        // Lưu lại nội dung sạch vào mảng `$rawFiles` để bước sau tiến hành ghép cặp .in / .out.
        $rawFiles[$entryName] = $content;
    }
    // Đóng tệp ZIP sau khi đọc xong để giải phóng tài nguyên hệ thống.
    $zip->close();

} elseif ($ext === 'json') {
    // Nhánh xử lý tệp JSON: đọc toàn bộ nội dung tệp và giải mã thành mảng PHP.
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

    // Duyệt từng phần tử trong mảng JSON và chuẩn hóa theo đúng cấu trúc thống nhất mà frontend cần.
    $formattedCases = [];
    foreach ($data as $idx => $item) {
        // Hỗ trợ linh hoạt cả khóa `input_data`/`input` và `expected_output`/`output`.
        $in = (string)($item['input_data'] ?? ($item['input'] ?? ''));
        $out = (string)($item['expected_output'] ?? ($item['output'] ?? ''));
        // Xác định test mẫu (công khai) hay test ẩn (chấm kín).
        $isSample = !empty($item['is_sample']) || (($item['type'] ?? '') === 'sample') ? 1 : 0;
        $isHidden = $isSample ? 0 : 1;
        // Điểm của test case, đảm bảo tối thiểu 1 điểm (mặc định 10 điểm).
        $points = max(1, (int)($item['points'] ?? 10));

        // [THẨM ĐỊNH CẤP ĐỘ 2] Gỡ UTF-8 BOM và chuẩn hóa ký tự xuống dòng về LF (\n) cho cả input và output.
        $in = preg_replace('/^\xEF\xBB\xBF/', '', $in);
        $in = str_replace(["\r\n", "\r"], "\n", $in);
        $out = preg_replace('/^\xEF\xBB\xBF/', '', $out);
        $out = str_replace(["\r\n", "\r"], "\n", $out);

        // Đóng gói thông tin chi tiết cùng đoạn xem trước (preview 150 ký tự đầu bằng mb_substr để không cắt vỡ chữ tiếng Việt).
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

    // Trả về danh sách test case đã chuẩn hóa từ JSON và kết thúc API.
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
// THUẬT TOÁN GHÉP CẶP FILE TEST (.in/.out) TỪ TỆP ZIP (THẨM ĐỊNH CẤP ĐỘ 1)
// Hỗ trợ nhiều kiểu đặt tên phổ biến của các kỳ thi lập trình:
//   1) Cùng tên gốc: `test1.in` <-> `test1.out` hoặc `1.inp` <-> `1.ans`
//   2) Theo tiền tố: `input01.txt` <-> `output01.txt` hoặc `input_1.txt` <-> `output_1.txt`
//   3) Theo thư mục con: `test01/input.txt` <-> `test01/output.txt` (hoặc `ans.txt`)
// =============================================================================
$inputs  = []; // Lưu các file đầu vào theo khóa chung ($key)
$outputs = []; // Lưu các file đáp án theo khóa chung ($key)

foreach ($rawFiles as $path => $content) {
    // Đổi dấu gạch chéo ngược '\' thành '/' để xử lý thống nhất trên cả Windows và Linux.
    $normalizedPath = str_replace('\\', '/', $path);
    $fileNameOnly   = basename($normalizedPath); // Chỉ lấy tên file (vd: "1.in")
    $dirName        = dirname($normalizedPath);  // Lấy tên thư mục chứa file (vd: "tests" hoặc ".")
    $dirPrefix      = ($dirName !== '.' && $dirName !== '') ? $dirName . '/' : '';

    // Phân loại Input: dùng biểu thức chính quy (Regex) không phân biệt hoa thường (/i) để trích khóa `$key` chung.
    if (preg_match('/^(.*)\.(in|inp)$/i', $fileNameOnly, $m)) {
        // Dạng `<ten>.in` hoặc `<ten>.inp` -> khóa là `<thu_muc>/<ten>`
        $key = $dirPrefix . $m[1];
        $inputs[$key] = ['file' => $path, 'content' => $content, 'base' => $m[1]];
    } elseif (preg_match('/^input[-_]?(.*)\.txt$/i', $fileNameOnly, $m)) {
        // Dạng `input01.txt`, `input-1.txt`, `input_1.txt` -> khóa là `<thu_muc>/<so_hoac_hau_to>`
        $key = $dirPrefix . ($m[1] ?: '1');
        $inputs[$key] = ['file' => $path, 'content' => $content, 'base' => ($m[1] ?: '1')];
    } elseif ($fileNameOnly === 'input.txt' && $dirPrefix !== '') {
        // Dạng nằm trong thư mục riêng như `case01/input.txt` -> dùng chính tên thư mục `case01` làm khóa ghép cặp.
        $key = rtrim($dirPrefix, '/');
        $inputs[$key] = ['file' => $path, 'content' => $content, 'base' => $key];
    }
    // Phân loại Output: áp dụng quy tắc đối xứng hoàn toàn với Input để sinh ra cùng `$key`.
    elseif (preg_match('/^(.*)\.(out|ans)$/i', $fileNameOnly, $m)) {
        // Dạng `<ten>.out` hoặc `<ten>.ans`
        $key = $dirPrefix . $m[1];
        $outputs[$key] = ['file' => $path, 'content' => $content, 'base' => $m[1]];
    } elseif (preg_match('/^output[-_]?(.*)\.txt$/i', $fileNameOnly, $m)) {
        // Dạng `output01.txt`, `output-1.txt`, `output_1.txt`
        $key = $dirPrefix . ($m[1] ?: '1');
        $outputs[$key] = ['file' => $path, 'content' => $content, 'base' => ($m[1] ?: '1')];
    } elseif (($fileNameOnly === 'output.txt' || $fileNameOnly === 'ans.txt') && $dirPrefix !== '') {
        // Dạng `case01/output.txt` hoặc `case01/ans.txt`
        $key = rtrim($dirPrefix, '/');
        $outputs[$key] = ['file' => $path, 'content' => $content, 'base' => $key];
    }
}

// Gom danh sách tất cả các key duy nhất từ cả mảng `$inputs` và `$outputs` (không bỏ sót file bị lẻ cặp).
$allKeys = array_unique(array_merge(array_keys($inputs), array_keys($outputs)));

// Sắp xếp tự nhiên (Natural Sort: 1, 2, 10 thay vì kiểu chuỗi máy tính 1, 10, 2) và không phân biệt hoa thường.
uksort($allKeys, fn($a, $b) => strnatcasecmp((string)$allKeys[$a], (string)$allKeys[$b]));
$sortedKeys = array_values($allKeys);
usort($sortedKeys, 'strnatcasecmp');

$pairedCases = []; // Danh sách các cặp Input + Output ghép thành công
$orphans     = []; // Danh sách các file "mồ côi" (có Input nhưng thiếu Output hoặc ngược lại)

// Duyệt qua từng khóa đã sắp xếp để ghép cặp.
foreach ($sortedKeys as $key) {
    $hasIn  = isset($inputs[$key]);
    $hasOut = isset($outputs[$key]);

    if ($hasIn && $hasOut) {
        // Có đủ cả 2 file Input và Output -> ghép thành một test case hợp lệ.
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
        // Chỉ có file Input mà không tìm thấy file Output tương ứng -> đưa vào danh sách file mồ côi.
        $orphans[] = [
            'key'     => $key,
            'type'    => 'missing_output',
            'file'    => $inputs[$key]['file'],
            'message' => "Có file đầu vào ({$inputs[$key]['file']}) nhưng thiếu file kết quả (.out / .ans)."
        ];
    } elseif (!$hasIn && $hasOut) {
        // Chỉ có file Output mà thiếu file Input tương ứng -> đưa vào danh sách file mồ côi.
        $orphans[] = [
            'key'     => $key,
            'type'    => 'missing_input',
            'file'    => $outputs[$key]['file'],
            'message' => "Có file kết quả ({$outputs[$key]['file']}) nhưng thiếu file đầu vào (.in / .inp)."
        ];
    }
}

// Nếu giải nén xong mà không tìm thấy bất kỳ cặp test hay file mồ côi nào -> báo lỗi 400 hướng dẫn cách đặt tên.
if (empty($pairedCases) && empty($orphans)) {
    http_response_code(400);
    echo json_encode([
        'success'  => false,
        'error'    => 'Không tìm thấy cặp test case hợp lệ nào trong tệp ZIP. Vui lòng đặt tên file theo cặp (ví dụ: 1.in & 1.out, test1.in & test1.out, hoặc input01.txt & output01.txt).',
        'warnings' => $warnings
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// Đánh số thứ tự (`case_order`) và phân loại mặc định:
// Test case đầu tiên ($idx === 0) là Công khai (Sample) để sinh viên xem ví dụ; các test còn lại là Chấm kín (Hidden).
// Lưu ý: dùng tham chiếu `&$tc` để sửa trực tiếp phần tử trong mảng `$pairedCases`, sau vòng lặp cần `unset($tc)` để tránh lỗi tham chiếu ngoài ý muốn.
foreach ($pairedCases as $idx => &$tc) {
    $tc['case_order'] = $idx + 1;
    $tc['is_sample']  = ($idx === 0) ? 1 : 0;
    $tc['is_hidden']  = ($idx === 0) ? 0 : 1;
}
unset($tc);

// =============================================================================
// CẤP ĐỘ 3: ĐỐI SOÁT VỚI LỜI GIẢI CHUẨN (MODEL SOLUTION VERIFICATION)
// Nếu giảng viên cung cấp mã nguồn lời giải chuẩn, hệ thống sẽ biên dịch và chạy nó
// trên từng test case vừa ghép cặp để kiểm chứng đáp án (.out) trong file ZIP có chính xác không.
// =============================================================================
$modelSolutionRan = false; // Đánh dấu lời giải chuẩn có chạy thẩm định thành công hay không
$modelCompileError = null; // Lưu thông báo lỗi biên dịch nếu lời giải chuẩn bị lỗi cú pháp

if ($modelSolution !== '') {
    $sandbox = new SandboxRunner();
    // Tạo một thư mục tạm duy nhất (tên ngẫu nhiên bằng random_bytes) trong thư mục tạm của hệ điều hành
    // để biên dịch và chạy thử, tránh xung đột khi nhiều giảng viên cùng nhập test một lúc.
    $tempRunDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'model_verify_' . bin2hex(random_bytes(6));
    @mkdir($tempRunDir, 0777, true);

    // Bước 3a: Biên dịch lời giải chuẩn (với C++ sẽ gọi g++, với Python sẽ kiểm tra cú pháp/tạo file .py).
    $compileRes = $sandbox->compile($solutionLang, $modelSolution, $tempRunDir);

    if (!$compileRes['success']) {
        // Nếu lời giải chuẩn không biên dịch được, ghi nhận lỗi và đưa vào danh sách cảnh báo.
        $modelCompileError = $compileRes['message'] ?: 'Lời giải chuẩn gặp lỗi biên dịch (Compile Error).';
        $warnings[] = "Lời giải chuẩn lỗi biên dịch: " . $modelCompileError;
    } else {
        // Biên dịch thành công -> tiến hành chạy lời giải chuẩn qua từng test case.
        $modelSolutionRan = true;
        $executable = $compileRes['executable'];

        foreach ($pairedCases as &$tc) {
            // Chạy lời giải chuẩn với đầu vào của test case (giới hạn 2000ms = 2 giây, bộ nhớ 262144KB = 256MB).
            $runRes = $sandbox->runTestCase($solutionLang, $executable, $tc['input_data'], 2000, 262144);
            
            // Nếu chương trình chạy thành công (AC) hoặc có sinh ra đầu ra thực tế thì đem đi so sánh với đáp án kỳ vọng.
            if ($runRes['verdict'] === 'AC' || $runRes['actual_output'] !== '') {
                $actual = $runRes['actual_output'];
                // Dùng Comparator so sánh kết quả thực tế với `expected_output` đọc từ file ZIP.
                $comp   = Comparator::compare($actual, $tc['expected_output']);

                if (!empty($comp['match'])) {
                    // Đáp án khớp hoàn toàn -> Đạt thẩm định Cấp độ 3 (VERIFIED).
                    $tc['model_verified'] = true;
                    $tc['status']         = 'VERIFIED';
                    $tc['status_label']   = '✅ Khớp Lời giải chuẩn';
                    $tc['actual_output']  = $actual;
                } else {
                    // Đáp án bị lệch -> Đánh dấu MISMATCH kèm vị trí dòng bị lệch (`diff_line`) để giảng viên kiểm tra lại file .out.
                    $tc['model_verified'] = false;
                    $tc['status']         = 'MISMATCH';
                    $tc['status_label']   = '⚠️ Lệch so với Lời giải chuẩn';
                    $tc['actual_output']  = $actual;
                    $tc['diff_line']      = $comp['diff_line'] ?? null;
                    $warnings[] = "Test case #{$tc['case_order']} ({$tc['input_name']}) có kết quả khác với Lời giải chuẩn.";
                }
            } else {
                // Chương trình bị lỗi khi chạy (ví dụ RE - Runtime Error, TLE - Quá thời gian...).
                $tc['model_verified'] = false;
                $tc['status']         = 'RUNTIME_ERROR';
                $tc['status_label']   = '❌ Lời giải chuẩn bị lỗi ' . $runRes['verdict'];
                $warnings[] = "Lời giải chuẩn bị lỗi ({$runRes['verdict']}) khi chạy trên test case #{$tc['case_order']}.";
            }
        }
        unset($tc);
    }

    // Dọn dẹp thư mục tạm an toàn bằng hàm đệ quy ẩn danh (Closure):
    // Xóa toàn bộ tệp và thư mục con bên trong rồi mới xóa thư mục gốc `$tempRunDir` để tránh rác ổ đĩa.
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

// 4. Trả về Báo cáo Thẩm định (Validation Report) đầy đủ dưới dạng JSON cho giao diện hiển thị.
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