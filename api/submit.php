<?php
/**
 * Nhom1 Online Judge - API Nộp bài chính thức & Xử lý Idempotency
 * POST /api/submit.php
 * 
 * Tuân thủ:
 * - Nguyên tắc 1: Web chỉ nhận bài đẩy vào hàng đợi CSDL, không chạy mã trong Web process
 * - Nguyên tắc 2 & Phép thử 3: Idempotency - Yêu cầu gửi lại không tạo bản nộp trùng
 *
 * Luồng hoạt động tổng quan:
 * 1. Kiểm tra đăng nhập (401 nếu chưa đăng nhập).
 * 2. Đọc & validate dữ liệu JSON/POST (idempotency_key, problem_id, language, source_code).
 * 3. Kiểm tra chống nộp trùng (Idempotency) theo cặp (user_id, idempotency_key).
 * 4. Kiểm tra tồn tại bài tập và quyền nộp theo chế độ hiển thị (public / hidden / exam_only).
 * 5. Mở Transaction: ghi bản ghi `submissions`, đẩy lượt chấm vào hàng đợi `judging_runs` ('Queued'), cập nhật `code_drafts`.
 * 6. Kích hoạt tiến trình Worker chạy ngầm (`WorkerLauncher::triggerAsync()`) để chấm bài bất đồng bộ.
 * 7. Trả về HTTP 201 cùng mã bài nộp (`submission_id`) và mã lượt chấm (`run_id`) để giao diện theo dõi tiến độ.
 */

// Bật chế độ kiểm tra kiểu dữ liệu nghiêm ngặt trong PHP
declare(strict_types=1);

// Khai báo phản hồi API luôn ở định dạng JSON UTF-8
header('Content-Type: application/json; charset=utf-8');

// Nạp các mô-đun cốt lõi: CSDL, xác thực, bảo mật (validate key), và bộ khởi chạy Worker chấm bài ngầm
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../core/Auth.php';
require_once __DIR__ . '/../core/Security.php';
require_once __DIR__ . '/../core/WorkerLauncher.php';

// =========================================================================
// BƯỚC 1: KIỂM TRA XÁC THỰC NGƯỜI DÙNG
// =========================================================================
// 1. Kiểm tra xác thực
if (!Auth::check()) {
    // HTTP 401 Unauthorized: bắt buộc phải có phiên đăng nhập mới được nộp bài
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'error'   => 'Vui lòng đăng nhập để nộp bài.'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// Lấy thông tin và ID người dùng hiện tại từ Session
$currentUser = Auth::user();
$userId = $currentUser['id'];

// =========================================================================
// BƯỚC 2: NHẬN DỮ LIỆU ĐẦU VÀO (JSON / POST) & VALIDATE
// =========================================================================
// 2. Đọc payload
// Đọc nội dung JSON từ thân request; nếu không phải JSON hợp lệ thì tự động lấy từ $_POST
$rawInput = file_get_contents('php://input');
$data = json_decode($rawInput, true);
if (!is_array($data)) {
    $data = $_POST;
}

// Trích xuất và ép kiểu dữ liệu đầu vào:
// - problem_id     : ID bài tập muốn nộp
// - language       : ngôn ngữ lập trình ('python' hoặc 'cpp')
// - source_code    : toàn bộ mã nguồn bài làm
// - idempotency_key: khóa duy nhất do frontend sinh ra cho mỗi lần bấm nút "Nộp bài" (chống nộp đúp khi nhấn đúp / rớt mạng gửi lại)
// - classroom_id   : ID lớp học (tùy chọn, dùng khi nộp bài trong ngữ cảnh một lớp học)
$problemId      = isset($data['problem_id']) ? (int)$data['problem_id'] : 0;
$language       = isset($data['language']) ? strtolower(trim((string)$data['language'])) : '';
$sourceCode     = isset($data['source_code']) ? (string)$data['source_code'] : '';
$idempotencyKey = isset($data['idempotency_key']) ? trim((string)$data['idempotency_key']) : '';
$classroomId    = !empty($data['classroom_id']) ? (int)$data['classroom_id'] : null;

// Thẩm định tính hợp lệ của Idempotency Key (Nguyên tắc 2)
// Đảm bảo khóa có độ dài từ 8 đến 64 ký tự hợp lệ (chữ, số, gạch ngang, gạch dưới) để tránh khóa rỗng hoặc rác
if (!Security::validateIdempotencyKey($idempotencyKey)) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error'   => 'Khóa chống nộp trùng (idempotency_key) không hợp lệ (cần độ dài 8-64 ký tự an toàn).'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// Kiểm tra mã bài tập bắt buộc là số nguyên dương
if ($problemId <= 0) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error'   => 'Thiếu mã bài tập (problem_id).'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// Kiểm tra ngôn ngữ lập trình chỉ thuộc danh sách hỗ trợ ('python' hoặc 'cpp')
if (!in_array($language, ['python', 'cpp'], true)) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error'   => 'Ngôn ngữ lập trình không hợp lệ. Chỉ chấp nhận python hoặc cpp.'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// Kiểm tra mã nguồn không được rỗng hoặc chỉ chứa toàn khoảng trắng / xuống dòng
if (trim($sourceCode) === '') {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error'   => 'Mã nguồn không được để trống khi nộp bài.'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// Mở kết nối PDO tới CSDL
$pdo = getDbConnection();

try {
    // =========================================================================
    // BƯỚC 3: KIỂM TRA CHỐNG NỘP TRÙNG (IDEMPOTENCY CHECK)
    // =========================================================================
    // 3. KIỂM TRA IDEMPOTENCY: Nếu đã tồn tại bản ghi với (user_id, idempotency_key)
    // Tình huống thực tế: người dùng bấm "Nộp bài" 2 lần liên tiếp hoặc mạng chập chờn khiến trình duyệt gửi lại cùng request.
    $stmtCheck = $pdo->prepare("
        SELECT id, final_verdict, latest_run_id, submitted_at
        FROM submissions
        WHERE user_id = :user_id AND idempotency_key = :idemp
        LIMIT 1
    ");
    $stmtCheck->execute([
        'user_id' => $userId,
        'idemp'   => $idempotencyKey
    ]);
    $existing = $stmtCheck->fetch();

    if ($existing) {
        // Trả về đúng mã bài nộp đã tạo trước đó mà không sinh bản ghi trùng
        // Trả về JSON (HTTP 200 OK) với `is_duplicate: true` để frontend biết đây là bài đã tiếp nhận từ trước và tiếp tục theo dõi kết quả
        echo json_encode([
            'success'       => true,
            'is_duplicate'  => true,
            'submission_id' => (int)$existing['id'],
            'final_verdict' => $existing['final_verdict'],
            'latest_run_id' => $existing['latest_run_id'] !== null ? (int)$existing['latest_run_id'] : null,
            'message'       => 'Yêu cầu nộp trùng lặp (Idempotent). Đã trả về bản ghi bài nộp hiện hữu.'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // =========================================================================
    // BƯỚC 4: KIỂM TRA BÀI TẬP & PHÂN QUYỀN NỘP BÀI THEO TRẠNG THÁI HIỂN THỊ
    // =========================================================================
    // 4. Lấy phiên bản đề hiện hành & kiểm tra phân quyền hiển thị (public / hidden / exam_only)
    // Chỉ chấp nhận bài tập chưa bị xóa mềm (deleted_at IS NULL) và đã có phiên bản phát hành (current_version_id)
    $stmtProb = $pdo->prepare("
        SELECT current_version_id, visibility, is_public, created_by
        FROM problems 
        WHERE id = :id AND deleted_at IS NULL
    ");
    $stmtProb->execute(['id' => $problemId]);
    $probRow = $stmtProb->fetch(PDO::FETCH_ASSOC);

    if (!$probRow || empty($probRow['current_version_id'])) {
        // HTTP 404 Not Found: bài tập không tồn tại, đang ở Thùng rác hoặc chưa phát hành bộ test
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'error'   => 'Bài tập không tồn tại, đã bị xóa hoặc chưa được phát hành phiên bản bộ dữ liệu.'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Xác định chế độ hiển thị của bài tập (ưu tiên cột `visibility`, tương thích ngược với cột `is_public`)
    $vis = $probRow['visibility'] ?? ($probRow['is_public'] ? 'public' : 'hidden');
    // Kiểm tra người nộp có phải là Admin hoặc chính Giảng viên tạo ra bài tập này không
    $isStaff = Auth::isAdmin() || (Auth::isInstructor() && (int)$probRow['created_by'] === $userId);

    // Chế độ 'hidden' (Ẩn bảo trì): chỉ Admin hoặc Giảng viên tạo đề mới được phép nộp thử, sinh viên bị chặn (HTTP 403)
    if ($vis === 'hidden' && !$isStaff) {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'error'   => 'Bài tập này hiện đang được ẩn để bảo trì, chưa mở nhận bài nộp.'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Chế độ 'exam_only' (Chỉ dành cho kỳ thi): sinh viên phải thuộc ít nhất một lớp học trong `classroom_members` mới được nộp
    if ($vis === 'exam_only' && !$isStaff) {
        $cmCheckExam = $pdo->prepare("SELECT COUNT(*) FROM classroom_members WHERE user_id = ?");
        $cmCheckExam->execute([$userId]);
        if ((int)$cmCheckExam->fetchColumn() === 0) {
            http_response_code(403);
            echo json_encode([
                'success' => false,
                'error'   => 'Bài tập này chỉ dành riêng cho kỳ thi / phòng thi. Bạn chưa thuộc danh sách lớp tham dự.'
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }

    // Lưu lại ID phiên bản đề tại đúng thời điểm nộp để chấm nhất quán (kể cả khi đề được sửa phiên bản mới sau đó)
    $versionId = (int)$probRow['current_version_id'];

    // Kiểm tra lớp học nếu có cung cấp
    // Xác minh người dùng thực sự là thành viên của lớp học gửi lên (tránh giả mạo classroom_id của lớp khác)
    if ($classroomId !== null) {
        $cmCheck = $pdo->prepare("
            SELECT COUNT(*) FROM classroom_members 
            WHERE classroom_id = :cid AND user_id = :uid
        ");
        $cmCheck->execute(['cid' => $classroomId, 'uid' => $userId]);
        if ((int)$cmCheck->fetchColumn() === 0) {
            $classroomId = null; // Nếu không thuộc lớp, bỏ qua lớp học
        }
    }

    // =========================================================================
    // BƯỚC 5: TRANSACTION GHI BÀI NỘP + ĐẨY VÀO HÀNG ĐỢI CHẤM + CẬP NHẬT NHÁP
    // =========================================================================
    // 5. Thêm bản nộp mới và lượt chấm đầu tiên trong một Transaction
    // Dùng Transaction để đảm bảo cả 3 thao tác ghi vào CSDL cùng thành công trọn vẹn; nếu 1 bước lỗi sẽ rollBack() hết
    $pdo->beginTransaction();

    // 5.1 Lưu submissions gắn cứng với problem_version_id lúc nộp (Yêu cầu 2)
    // Trạng thái ban đầu là 'Pending' (đang chờ chấm), sẽ được Worker cập nhật thành AC/WA/TLE/RE/CE sau khi chạy xong
    $subInsert = $pdo->prepare("
        INSERT INTO submissions 
        (user_id, classroom_id, problem_id, problem_version_id, language, source_code, idempotency_key, final_verdict, submitted_at)
        VALUES (:uid, :cid, :pid, :vid, :lang, :code, :idemp, 'Pending', NOW())
    ");
    $subInsert->execute([
        'uid'   => $userId,
        'cid'   => $classroomId,
        'pid'   => $problemId,
        'vid'   => (int)$versionId,
        'lang'  => $language,
        'code'  => $sourceCode,
        'idemp' => $idempotencyKey
    ]);
    // Lấy ID tự tăng của bản ghi bài nộp vừa chèn
    $submissionId = (int)$pdo->lastInsertId();

    // 5.2 Đẩy lượt thực thi đầu tiên vào hàng đợi judging_runs (status = 'Queued')
    // Bảng judging_runs đóng vai trò hàng đợi công việc (Job Queue): run_number = 1 (lượt chấm đầu tiên khi nộp), triggered_by = 'submit'
    $runInsert = $pdo->prepare("
        INSERT INTO judging_runs 
        (submission_id, problem_version_id, run_number, triggered_by, status, verdict)
        VALUES (:sub_id, :vid, 1, 'submit', 'Queued', 'Pending')
    ");
    $runInsert->execute([
        'sub_id' => $submissionId,
        'vid'    => (int)$versionId
    ]);
    // Lấy ID tự tăng của lượt chấm vừa xếp vào hàng đợi
    $runId = (int)$pdo->lastInsertId();

    // 5.3 Cập nhật bản nháp tương ứng
    // Đồng bộ lại code vừa nộp vào bảng code_drafts (Upsert) để lần sau mở lại đề vẫn thấy đoạn code mới nhất
    $draftUpdate = $pdo->prepare("
        INSERT INTO code_drafts (user_id, problem_id, language, source_code, updated_at)
        VALUES (:uid, :pid, :lang, :code, NOW())
        ON DUPLICATE KEY UPDATE source_code = VALUES(source_code), updated_at = NOW()
    ");
    $draftUpdate->execute([
        'uid'  => $userId,
        'pid'  => $problemId,
        'lang' => $language,
        'code' => $sourceCode
    ]);

    // Xác nhận hoàn tất Transaction: dữ liệu bài nộp & lượt chấm chính thức hiện diện cho Worker đọc
    $pdo->commit();

    // =========================================================================
    // BƯỚC 6: KÍCH HOẠT WORKER CHẤM BÀI NGẦM & TRẢ PHẢN HỒI JSON
    // =========================================================================
    // Tự động kích hoạt Worker chấm bài chạy ngầm độc lập (không cần mở terminal tay)
    // Quan trọng: gọi SAU $pdo->commit() để Worker chắc chắn nhìn thấy bản ghi mới; chạy bất đồng bộ nên API trả về ngay không bị treo web
    WorkerLauncher::triggerAsync();

    // HTTP 201 Created: tạo mới bài nộp thành công
    // Cấu trúc JSON trả về gồm:
    // - success: true, is_duplicate: false (bài nộp mới hoàn toàn)
    // - submission_id: ID bài nộp để frontend polling sang api/submission_status.php
    // - run_id: ID lượt chấm đầu tiên, status: 'Queued' (đang nằm trong hàng đợi)
    http_response_code(201);
    echo json_encode([
        'success'       => true,
        'is_duplicate'  => false,
        'submission_id' => $submissionId,
        'run_id'        => $runId,
        'status'        => 'Queued',
        'message'       => 'Bài nộp đã được tiếp nhận và đẩy vào hàng đợi chấm.'
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    // Nếu có lỗi phát sinh (lỗi CSDL, khóa ngoại, v.v.) khi Transaction chưa commit -> hoàn tác toàn bộ (rollBack)
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    // Ghi chi tiết lỗi vào log máy chủ và trả về HTTP 500 Internal Server Error với thông báo thân thiện cho người dùng
    error_log("submit.php error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error'   => 'Lỗi hệ thống trong quá trình tiếp nhận bài nộp.'
    ], JSON_UNESCAPED_UNICODE);
}
