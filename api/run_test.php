<?php
/**
 * Nhom1 Online Judge - API Chạy thử (Custom Input) & Lưu bản nháp (Draft)
 * POST /api/run_test.php
 *
 * Mục đích của API:
 * - Cho phép người học lưu nháp mã nguồn đang gõ (để không bị mất code khi tải lại trang).
 * - Cho phép biên dịch và chạy thử trực tiếp mã nguồn với dữ liệu đầu vào tùy chỉnh (Custom Input)
 *   hoặc test case mẫu (Sample Test) mà không ghi vào lịch sử nộp bài chính thức.
 * - Hỗ trợ 3 chế độ qua tham số `action`: 'draft' (chỉ lưu nháp), 'run' (chỉ chạy thử), 'both' (cả hai).
 */

// Bật kiểm tra kiểu dữ liệu nghiêm ngặt trong PHP
declare(strict_types=1);

// Báo cho client biết phản hồi trả về là JSON mã hóa UTF-8
header('Content-Type: application/json; charset=utf-8');

// Nạp các thành phần lõi: kết nối CSDL, xác thực, lọc lỗi bảo mật, bộ chạy hộp cát (SandboxRunner) và bộ so sánh kết quả
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../core/Auth.php';
require_once __DIR__ . '/../core/Security.php';
require_once __DIR__ . '/../core/SandboxRunner.php';
require_once __DIR__ . '/../core/Comparator.php';

// =========================================================================
// BƯỚC 1: KIỂM TRA QUYỀN TRUY CẬP (YÊU CẦU ĐĂNG NHẬP)
// =========================================================================
// 1. Kiểm tra xác thực người dùng
if (!Auth::check()) {
    // HTTP 401 Unauthorized: chưa đăng nhập thì không được lưu nháp hay chạy code trên máy chủ
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'error'   => 'Vui lòng đăng nhập để sử dụng chức năng này.'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// Lấy thông tin và ID của người dùng đang đăng nhập từ Session
$currentUser = Auth::user();
$userId = $currentUser['id'];

// =========================================================================
// BƯỚC 2: NHẬN DỮ LIỆU ĐẦU VÀO & VALIDATE THAM SỐ
// =========================================================================
// 2. Đọc payload (hỗ trợ cả JSON body lẫn FormData)
// Đọc dữ liệu JSON thô; nếu frontend gửi dạng Form POST thì fallback sang $_POST
$inputRaw = file_get_contents('php://input');
$data = json_decode($inputRaw, true);
if (!is_array($data)) {
    $data = $_POST;
}

// Trích xuất và chuẩn hóa từng tham số đầu vào với giá trị mặc định an toàn
$problemId   = isset($data['problem_id']) ? (int)$data['problem_id'] : 0;
$language    = isset($data['language']) ? strtolower(trim((string)$data['language'])) : 'python';
$sourceCode  = isset($data['source_code']) ? (string)$data['source_code'] : '';
// Lưu ý: custom_input giữ giá trị null nếu client KHÔNG truyền lên (để phân biệt với chuỗi rỗng "")
$customInput = isset($data['custom_input']) ? (string)$data['custom_input'] : null;
$action      = isset($data['action']) ? trim((string)$data['action']) : 'both'; // 'draft', 'run', 'both'

// Kiểm tra mã bài tập hợp lệ (phải là số nguyên dương > 0)
if ($problemId <= 0) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error'   => 'Thiếu mã bài tập (problem_id).'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// Kiểm tra ngôn ngữ lập trình nằm trong danh sách hệ thống hỗ trợ (python, cpp)
if (!in_array($language, ['python', 'cpp'], true)) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error'   => 'Ngôn ngữ không hợp lệ. Chỉ hỗ trợ python và cpp.'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// Mở kết nối CSDL bằng PDO
$pdo = getDbConnection();

// =========================================================================
// BƯỚC 3: TRUY VẤN BÀI TẬP & GIỚI HẠN THỜI GIAN / BỘ NHỚ CỦA PHIÊN BẢN HIỆN TẠI
// =========================================================================
// 3. Lấy thông tin bài tập & phiên bản hiện tại
// Kết nối (JOIN) bảng problems với problem_versions theo current_version_id và chỉ lấy bài chưa bị xóa mềm (deleted_at IS NULL)
$stmt = $pdo->prepare("
    SELECT p.id, p.slug, p.title, pv.id as version_id, pv.time_limit_ms, pv.memory_limit_kb
    FROM problems p
    JOIN problem_versions pv ON p.current_version_id = pv.id
    WHERE p.id = :id AND p.deleted_at IS NULL
");
$stmt->execute(['id' => $problemId]);
$problem = $stmt->fetch();

// Nếu không tìm thấy bài tập hoặc bài chưa có phiên bản hợp lệ -> trả về HTTP 404 Not Found
if (!$problem) {
    http_response_code(404);
    echo json_encode([
        'success' => false,
        'error'   => 'Không tìm thấy bài tập hoặc bài tập chưa có phiên bản hiệu lực.'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// Biến cờ theo dõi xem bản nháp đã được lưu trong lần gọi API này hay chưa
$draftSaved = false;

// =========================================================================
// BƯỚC 4: LƯU BẢN NHÁP MÃ NGUỒN (UPSERT VÀO BẢNG code_drafts)
// =========================================================================
// 4. Lưu bản nháp (nếu action là 'draft' hoặc 'both')
if ($action === 'draft' || $action === 'both') {
    // Dùng cú pháp INSERT ... ON DUPLICATE KEY UPDATE (Upsert của MySQL):
    // - Nếu người dùng chưa từng có nháp cho (user_id, problem_id, language) -> thêm dòng mới.
    // - Nếu đã có -> cập nhật lại source_code và thời gian updated_at mới nhất.
    $draftStmt = $pdo->prepare("
        INSERT INTO code_drafts (user_id, problem_id, language, source_code, updated_at)
        VALUES (:user_id, :problem_id, :language, :source_code, NOW())
        ON DUPLICATE KEY UPDATE source_code = VALUES(source_code), updated_at = NOW()
    ");
    $draftStmt->execute([
        'user_id'     => $userId,
        'problem_id'  => $problemId,
        'language'    => $language,
        'source_code' => $sourceCode
    ]);
    $draftSaved = true;
}

// Nếu chỉ lưu nháp
// Trả về JSON thành công ngay lập tức mà không cần khởi tạo Sandbox biên dịch/chạy code
if ($action === 'draft') {
    echo json_encode([
        'success'     => true,
        'draft_saved' => true,
        'message'     => 'Bản nháp đã được lưu thành công.'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// =========================================================================
// BƯỚC 5: BIÊN DỊCH & CHẠY THỬ MÃ NGUỒN TRONG SANDBOX
// =========================================================================
// 5. Chạy thử mã nguồn (action là 'run' hoặc 'both')
// Validate: không thể chạy thử nếu mã nguồn trống hoàn toàn
if (trim($sourceCode) === '') {
    http_response_code(400);
    echo json_encode([
        'success'     => false,
        'draft_saved' => $draftSaved,
        'error'       => 'Mã nguồn không được để trống khi chạy thử.'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// Khởi tạo trình chạy cách ly (SandboxRunner) và tạo thư mục tạm riêng biệt cho lượt chạy này
// Tên thư mục gồm userId + timestamp + chuỗi hex ngẫu nhiên để tránh xung đột khi nhiều người chạy cùng lúc
$sandbox = new SandboxRunner();
$runIdentifier = 'testrun_' . $userId . '_' . time() . '_' . bin2hex(random_bytes(3));
$runDir = $sandbox->prepareRunDir($runIdentifier);

try {
    // 5.1 Biên dịch / kiểm tra cú pháp
    // Với C++ sẽ gọi g++ biên dịch ra file thực thi; với Python sẽ kiểm tra lỗi cú pháp (py_compile)
    $compileRes = $sandbox->compile($language, $sourceCode, $runDir);
    if (!$compileRes['success']) {
        // Dọn dẹp thư mục tạm ngay sau khi biên dịch thất bại để tránh rác ổ cứng
        $sandbox->cleanupRunDir($runDir);
        // Lưu ý: API vẫn trả HTTP 200 & success = true (hệ thống xử lý thành công),
        // còn kết quả biên dịch lỗi (verdict = CE, thông báo lỗi đã lọc đường dẫn máy chủ trong stderr) nằm trong `execution`
        echo json_encode([
            'success'     => true,
            'draft_saved' => $draftSaved,
            'execution'   => [
                'verdict'           => $compileRes['verdict'],
                'stdout'            => '',
                'stderr'            => Security::sanitizeErrorMessage($compileRes['message']),
                'execution_time_ms' => 0
            ]
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Lấy đường dẫn file thực thi và giới hạn tài nguyên (thời gian chạy ms, bộ nhớ KB) của bài tập
    $executable = $compileRes['executable'];
    $timeLimitMs = (int)$problem['time_limit_ms'];
    $memoryLimitKb = (int)$problem['memory_limit_kb'];

    // 5.2 Nếu người dùng không nhập custom input, lấy sample testcase đầu tiên
    // Chỉ lấy testcase công khai mẫu (is_sample = 1), tuyệt đối không đụng đến testcase ẩn
    if ($customInput === null) {
        $sampleStmt = $pdo->prepare("
            SELECT input_data, expected_output 
            FROM test_cases 
            WHERE problem_version_id = :ver_id AND is_sample = 1 
            ORDER BY case_order ASC 
            LIMIT 1
        ");
        $sampleStmt->execute(['ver_id' => $problem['version_id']]);
        $sample = $sampleStmt->fetch();
        // Nếu bài không có sample nào thì truyền input rỗng ''
        $inputToRun = $sample ? $sample['input_data'] : '';
    } else {
        // Sử dụng đúng dữ liệu đầu vào mà người dùng gõ trên giao diện
        $inputToRun = $customInput;
    }

    // 5.3 Thực thi
    // Chạy chương trình trong Sandbox với giới hạn timeLimitMs và memoryLimitKb, sau đó xóa thư mục tạm
    $runRes = $sandbox->runTestCase($language, $executable, $inputToRun, $timeLimitMs, $memoryLimitKb);
    $sandbox->cleanupRunDir($runDir);

    // Trả về JSON (HTTP 200 OK) chứa kết quả chạy thử:
    // - draft_saved: trạng thái lưu nháp
    // - execution: verdict (trạng thái chạy), stdout (đầu ra chuẩn), stderr (lỗi runtime đã ẩn đường dẫn nhạy cảm), execution_time_ms (thời gian chạy)
    echo json_encode([
        'success'     => true,
        'draft_saved' => $draftSaved,
        'execution'   => [
            'verdict'           => $runRes['verdict'],
            'stdout'            => $runRes['actual_output'],
            'stderr'            => Security::sanitizeErrorMessage($runRes['error_message']),
            'execution_time_ms' => $runRes['execution_time_ms']
        ]
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    // Luôn dọn dẹp thư mục tạm ngay cả khi có ngoại lệ bất ngờ xảy ra
    $sandbox->cleanupRunDir($runDir);
    // Ghi log lỗi chi tiết vào file log máy chủ để lập trình viên kiểm tra, không lộ chi tiết nội bộ ra client
    error_log("run_test error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success'     => false,
        'draft_saved' => $draftSaved,
        'error'       => 'Lỗi hệ thống trong quá trình thực thi chạy thử.'
    ], JSON_UNESCAPED_UNICODE);
}
