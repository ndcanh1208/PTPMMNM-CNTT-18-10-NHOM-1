<?php
/**
 * Nhom1 Online Judge - API Tạo phiên bản bộ dữ liệu mới & Chấm lại (Rejudge)
 * POST /api/version_rejudge.php
 * 
 * Hỗ trợ Tình huống sử dụng 2:
 * Sửa bộ dữ liệu -> tạo phiên bản mới -> chấm lại -> đối chiếu hai lần chấm mà vẫn giữ nguyên lịch sử
 *
 * Tổng quan luồng xử lý của file API này:
 * 1. Kiểm tra đăng nhập và phân quyền (chỉ Giảng viên hoặc Admin mới được gọi).
 * 2. Đọc dữ liệu đầu vào (JSON body hoặc GET/POST) để xác định `action` và `problem_id`.
 * 3. Kiểm tra bài tập có tồn tại và người dùng có quyền quản trị bài tập đó hay không.
 * 4. Phân nhánh thực hiện một trong 4 thao tác:
 *    - `get_testcases` : Lấy danh sách test case của phiên bản hiện tại.
 *    - `save_testcases`: Lưu/sửa bộ test case (tự động chọn sửa trực tiếp nếu chưa có bài nộp,
 *                        hoặc tạo phiên bản mới + tự động chấm lại nếu đã có bài nộp).
 *    - `create_version`: Tạo thủ công một phiên bản đề bài mới (kèm test case mới hoặc sao chép bản cũ).
 *    - `rejudge`       : Đưa toàn bộ hoặc một số bài nộp vào hàng đợi chấm lại mà vẫn giữ nguyên lịch sử chấm cũ.
 */

// Bật chế độ kiểm tra kiểu dữ liệu nghiêm ngặt (strict types) trong PHP để hạn chế lỗi ép kiểu ngầm.
declare(strict_types=1);

// Khai báo với trình duyệt / client rằng API này luôn trả về dữ liệu định dạng JSON, mã hóa UTF-8 (hiển thị đúng tiếng Việt).
header('Content-Type: application/json; charset=utf-8');

// Nạp các file thư viện lõi cần thiết:
// - database.php: kết nối cơ sở dữ liệu MySQL qua PDO.
// - Auth.php: kiểm tra phiên đăng nhập và phân quyền người dùng.
// - Security.php: các hàm hỗ trợ bảo mật.
// - WorkerLauncher.php: kích hoạt tiến trình chạy nền (Worker) để chấm các bài đang nằm trong hàng đợi.
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../core/Auth.php';
require_once __DIR__ . '/../core/Security.php';
require_once __DIR__ . '/../core/WorkerLauncher.php';

// 1. Kiểm tra xác thực & Phân quyền (Chỉ Giảng viên hoặc Admin)
// Bước 1a: Kiểm tra người dùng đã đăng nhập chưa. Nếu chưa, trả về mã HTTP 401 (Unauthorized) và dừng ngay.
if (!Auth::check()) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'error'   => 'Vui lòng đăng nhập.'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// Bước 1b: Kiểm tra vai trò người dùng. Sinh viên (learner) không được phép sửa test hay rejudge.
// Nếu không phải Admin và cũng không phải Giảng viên, trả về mã HTTP 403 (Forbidden - Cấm truy cập).
if (!Auth::isAdmin() && !Auth::isInstructor()) {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'error'   => '403 Forbidden - Chỉ Giảng viên hoặc Quản trị viên mới có quyền thực hiện thao tác này.'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// Lấy thông tin tài khoản hiện đang đăng nhập và ID của họ để lưu vết người tạo phiên bản (created_by).
$currentUser = Auth::user();
$userId = $currentUser['id'];

// 2. Đọc payload (dữ liệu client gửi lên)
// Đọc chuỗi thô từ phần thân (body) của HTTP request (thường là JSON do fetch/AJAX gửi lên).
$rawInput = file_get_contents('php://input');
// Chuyển chuỗi JSON thành mảng kết hợp (associative array) của PHP.
$data = json_decode($rawInput, true);
// Nếu body không phải JSON hợp lệ (ví dụ gọi bằng form truyền thống hoặc query string), gộp $_GET và $_POST.
// Ngược lại, gộp $_GET với mảng JSON vừa giải mã (giá trị trong JSON sẽ ưu tiên ghi đè nếu trùng tên tham số).
if (!is_array($data)) {
    $data = array_merge($_GET, $_POST);
} else {
    $data = array_merge($_GET, $data);
}

// Xác định hành động cần thực hiện (mặc định là 'rejudge' nếu client không truyền tham số action).
$action = isset($data['action']) ? trim((string)$data['action']) : 'rejudge';
// Ép kiểu mã bài tập sang số nguyên để đảm bảo an toàn khi truy vấn DB.
$problemId = isset($data['problem_id']) ? (int)$data['problem_id'] : 0;

// Nếu problem_id không hợp lệ (<= 0), trả về mã HTTP 400 (Bad Request - Yêu cầu thiếu/sai tham số).
if ($problemId <= 0) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error'   => 'Thiếu mã bài tập (problem_id).'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// Khởi tạo đối tượng PDO kết nối đến cơ sở dữ liệu MySQL.
$pdo = getDbConnection();

// Kiểm tra quyền đối với bài tập cụ thể
// Dùng Prepared Statement (dấu `?`) để chống tấn công SQL Injection khi lấy thông tin bài tập.
$probStmt = $pdo->prepare("SELECT id, title, slug, created_by, current_version_id FROM problems WHERE id = ?");
$probStmt->execute([$problemId]);
$problem = $probStmt->fetch();

// Nếu không tìm thấy bản ghi bài tập trong CSDL, trả về mã HTTP 404 (Not Found).
if (!$problem) {
    http_response_code(404);
    echo json_encode([
        'success' => false,
        'error'   => 'Không tìm thấy bài tập.'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// Kiểm tra quyền sở hữu / quản trị trên đúng bài tập này:
// Admin có toàn quyền, còn Giảng viên thường chỉ được quản lý bài tập do chính mình tạo ra.
if (!Auth::canManageProblem((int)$problem['created_by'])) {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'error'   => 'Bạn không có quyền quản trị bài tập này.'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// -----------------------------------------------------------------------------
// THAO TÁC 0: LẤY DANH SÁCH TEST CASE HIỆN TẠI (get_testcases)
// Dùng khi mở giao diện chỉnh sửa test case để tải toàn bộ dữ liệu hiện có lên trình duyệt.
// -----------------------------------------------------------------------------
if ($action === 'get_testcases') {
    // Lấy ID phiên bản hiện hành của bài tập.
    $currentVersionId = (int)($problem['current_version_id'] ?? 0);
    // Phòng trường hợp bài tập chưa gắn `current_version_id` (<= 0), tự động tìm phiên bản có số thứ tự lớn nhất.
    if ($currentVersionId <= 0) {
        $verStmt = $pdo->prepare("SELECT id FROM problem_versions WHERE problem_id = ? ORDER BY version_number DESC LIMIT 1");
        $verStmt->execute([$problemId]);
        $currentVersionId = (int)$verStmt->fetchColumn();
        // Nếu tìm thấy, cập nhật ngược lại vào bảng `problems` để đồng bộ dữ liệu cho các lần gọi sau.
        if ($currentVersionId > 0) {
            $pdo->prepare("UPDATE problems SET current_version_id = ? WHERE id = ?")->execute([$currentVersionId, $problemId]);
        }
    }

    // Lấy thông tin chi tiết của phiên bản hiện tại (số phiên bản, ghi chú thay đổi, thời gian tạo).
    $verInfo = null;
    if ($currentVersionId > 0) {
        $vStmt = $pdo->prepare("SELECT id, version_number, changelog, created_at FROM problem_versions WHERE id = ?");
        $vStmt->execute([$currentVersionId]);
        $verInfo = $vStmt->fetch();
    }

    // Truy vấn danh sách tất cả các test case thuộc phiên bản hiện tại, sắp xếp theo thứ tự `case_order` tăng dần.
    $tcStmt = $pdo->prepare("
        SELECT id, case_order, input_data, expected_output, is_sample, is_hidden, points 
        FROM test_cases 
        WHERE problem_version_id = ? 
        ORDER BY case_order ASC, id ASC
    ");
    $tcStmt->execute([$currentVersionId]);
    $testCases = $tcStmt->fetchAll();

    // Đếm tổng số bài nộp của bài tập này để giao diện biết trước sẽ sửa trực tiếp hay sẽ phát hành phiên bản mới.
    $subStmt = $pdo->prepare("SELECT COUNT(*) FROM submissions WHERE problem_id = ?");
    $subStmt->execute([$problemId]);
    $totalSubs = (int)$subStmt->fetchColumn();

    // Trả về toàn bộ thông tin bài tập, phiên bản, số bài nộp và danh sách test case dưới dạng JSON.
    echo json_encode([
        'success'           => true,
        'problem_id'        => $problemId,
        'problem_title'     => $problem['title'] ?? '',
        'problem_slug'      => $problem['slug'] ?? '',
        'version_id'        => $currentVersionId,
        'version_number'    => (int)($verInfo['version_number'] ?? 1),
        'changelog'         => $verInfo['changelog'] ?? '',
        'total_submissions' => $totalSubs,
        'test_cases'        => $testCases
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// -----------------------------------------------------------------------------
// THAO TÁC MỚI: LƯU / SỬA TEST CASES (save_testcases)
// Tự động phân nhánh thông minh theo trạng thái bài tập:
// - total_submissions == 0: Cập nhật trực tiếp (in-place) vì chưa ảnh hưởng kết quả chấm của ai.
// - total_submissions > 0 : Tạo phiên bản v(N+1) & tự động Rejudge các bài nộp cũ để đảm bảo công bằng và giữ lịch sử.
// -----------------------------------------------------------------------------
if ($action === 'save_testcases') {
    // Lấy mảng test cases và ghi chú thay đổi (changelog) từ dữ liệu gửi lên.
    $testCases = isset($data['test_cases']) && is_array($data['test_cases']) ? $data['test_cases'] : [];
    $changelog = isset($data['changelog']) ? trim((string)$data['changelog']) : '';

    // Không cho phép lưu một bài tập không có test case nào (trả về lỗi 400).
    if (empty($testCases)) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error'   => 'Bộ test case không được rỗng. Cần ít nhất 1 test case.'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Đếm số bài nộp hiện có của bài tập này để quyết định đi theo Trường hợp 1 hay Trường hợp 2.
    $subStmt = $pdo->prepare("SELECT COUNT(*) FROM submissions WHERE problem_id = ?");
    $subStmt->execute([$problemId]);
    $totalSubmissions = (int)$subStmt->fetchColumn();

    // Xác định ID phiên bản hiện tại (nếu chưa có trong cột current_version_id thì tìm bản mới nhất).
    $currentVersionId = (int)($problem['current_version_id'] ?? 0);
    if ($currentVersionId <= 0) {
        $verStmt = $pdo->prepare("SELECT id FROM problem_versions WHERE problem_id = ? ORDER BY version_number DESC LIMIT 1");
        $verStmt->execute([$problemId]);
        $currentVersionId = (int)$verStmt->fetchColumn();
    }

    if ($totalSubmissions === 0 && $currentVersionId > 0) {
        // -------------------------------------------------------------
        // TRƯỜNG HỢP 1: Chưa có bài nộp nào -> Sửa trực tiếp (In-place)
        // Sử dụng Transaction (giao dịch CSDL) để đảm bảo xóa cũ & thêm mới xảy ra trọn vẹn:
        // nếu có lỗi giữa chừng thì hoàn tác (rollBack), không bị mất trắng bộ test cũ.
        // -------------------------------------------------------------
        $pdo->beginTransaction();
        try {
            // Xóa toàn bộ test case cũ thuộc current_version_id
            $delTc = $pdo->prepare("DELETE FROM test_cases WHERE problem_version_id = ?");
            $delTc->execute([$currentVersionId]);

            // Chuẩn bị câu lệnh SQL chèn từng test case mới vào bảng `test_cases`.
            $tcInsertStmt = $pdo->prepare("
                INSERT INTO test_cases 
                (problem_version_id, case_order, input_data, expected_output, is_sample, is_hidden, points, created_at)
                VALUES (:pvid, :corder, :input, :output, :sample, :hidden, :points, NOW())
            ");

            // Duyệt qua từng test case người dùng gửi lên để chuẩn hóa thuộc tính công khai/ẩn và điểm số.
            foreach ($testCases as $idx => $tc) {
                // Mặc định là test ẩn (hidden) dùng để chấm điểm, không phải test mẫu (sample).
                $isSample = 0;
                $isHidden = 1;
                // Hỗ trợ cả 2 cách truyền từ frontend: truyền trường `type` ('sample'/'hidden') hoặc cờ `is_sample`.
                if (isset($tc['type'])) {
                    if ($tc['type'] === 'sample') {
                        $isSample = 1;
                        $isHidden = 0;
                    } else {
                        $isSample = 0;
                        $isHidden = 1;
                    }
                } else {
                    $isSample = !empty($tc['is_sample']) ? 1 : 0;
                    $isHidden = $isSample ? 0 : 1;
                }

                // Thực thi chèn test case (thứ tự bắt đầu từ 1, điểm tối thiểu là 1 để tránh điểm 0 hoặc âm).
                $tcInsertStmt->execute([
                    'pvid'   => $currentVersionId,
                    'corder' => $idx + 1,
                    'input'  => (string)($tc['input_data'] ?? ''),
                    'output' => (string)($tc['expected_output'] ?? ''),
                    'sample' => $isSample,
                    'hidden' => $isHidden,
                    'points' => max(1, (int)($tc['points'] ?? 10))
                ]);
            }

            // Cập nhật changelog nếu người dùng có nhập ghi chú thay đổi.
            if ($changelog !== '') {
                $updVer = $pdo->prepare("UPDATE problem_versions SET changelog = ? WHERE id = ?");
                $updVer->execute([$changelog, $currentVersionId]);
            }

            // Xác nhận lưu toàn bộ thay đổi xuống cơ sở dữ liệu.
            $pdo->commit();

            // Lấy số phiên bản (version_number) để hiển thị trong thông báo trả về cho người dùng.
            $vStmt = $pdo->prepare("SELECT version_number FROM problem_versions WHERE id = ?");
            $vStmt->execute([$currentVersionId]);
            $verNum = (int)$vStmt->fetchColumn();

            echo json_encode([
                'success'         => true,
                'mode'            => 'inplace',
                'problem_id'      => $problemId,
                'version_id'      => $currentVersionId,
                'version_number'  => $verNum,
                'testcases_count' => count($testCases),
                'message'         => "Đã cập nhật trực tiếp " . count($testCases) . " test case vào phiên bản v{$verNum} (do bài tập chưa có bài nộp nào)."
            ], JSON_UNESCAPED_UNICODE);
            exit;

        } catch (Throwable $e) {
            // Nếu có bất kỳ ngoại lệ nào xảy ra, hủy bỏ toàn bộ thao tác trong transaction để giữ nguyên dữ liệu ban đầu.
            $pdo->rollBack();
            error_log("save_testcases inplace error: " . $e->getMessage());
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'error'   => 'Lỗi hệ thống khi cập nhật test case: ' . $e->getMessage()
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

    } else {
        // -------------------------------------------------------------
        // TRƯỜNG HỢP 2: Đã có >= 1 bài nộp -> Tạo version mới & Tự động Rejudge
        // Không sửa đè test case của phiên bản cũ để lịch sử chấm lần trước vẫn giữ nguyên vẹn.
        // -------------------------------------------------------------
        // Lấy thông tin phiên bản mới nhất hiện có để kế thừa đề bài, giới hạn thời gian và bộ nhớ.
        $latestVerStmt = $pdo->prepare("
            SELECT * FROM problem_versions 
            WHERE problem_id = ? 
            ORDER BY version_number DESC 
            LIMIT 1
        ");
        $latestVerStmt->execute([$problemId]);
        $latestVer = $latestVerStmt->fetch();

        // Tăng số phiên bản thêm 1 (ví dụ v1 -> v2); nếu bài chưa có phiên bản nào thì bắt đầu từ v1.
        $newVersionNumber = $latestVer ? ((int)$latestVer['version_number'] + 1) : 1;
        // Nếu người dùng không ghi changelog, tự sinh mô tả mặc định rõ ràng.
        $effectiveChangelog = $changelog !== '' ? $changelog : "Cập nhật, sửa đổi test case (tạo phiên bản v{$newVersionNumber} & tự động chấm lại)";

        // Mở Transaction bao bọc 4 bước liên hoàn: tạo phiên bản -> thêm test -> cập nhật bài tập -> tạo hàng đợi rejudge.
        $pdo->beginTransaction();
        try {
            // 1. Thêm phiên bản mới vào bảng `problem_versions`, sao chép nguyên văn mô tả và giới hạn từ phiên bản gần nhất.
            $insertVerStmt = $pdo->prepare("
                INSERT INTO problem_versions 
                (problem_id, version_number, description, input_description, output_description,
                 constraints, time_limit_ms, memory_limit_kb, changelog, created_by, created_at)
                VALUES (:pid, :vnum, :desc, :indesc, :outdesc, :cons, :time_lim, :mem_lim, :change, :uid, NOW())
            ");
            $insertVerStmt->execute([
                'pid'      => $problemId,
                'vnum'     => $newVersionNumber,
                'desc'     => $latestVer['description'] ?? '',
                'indesc'   => $latestVer['input_description'] ?? '',
                'outdesc'  => $latestVer['output_description'] ?? '',
                'cons'     => $latestVer['constraints'] ?? '',
                'time_lim' => $latestVer['time_limit_ms'] ?? 1000,
                'mem_lim'  => $latestVer['memory_limit_kb'] ?? 262144,
                'change'   => $effectiveChangelog,
                'uid'      => $userId
            ]);
            // Lấy ID tự tăng vừa được sinh ra của phiên bản mới.
            $newVersionId = (int)$pdo->lastInsertId();

            // 2. Thêm các test case mới gắn với `newVersionId` (bộ test của phiên bản cũ vẫn giữ nguyên).
            $tcInsertStmt = $pdo->prepare("
                INSERT INTO test_cases 
                (problem_version_id, case_order, input_data, expected_output, is_sample, is_hidden, points, created_at)
                VALUES (:pvid, :corder, :input, :output, :sample, :hidden, :points, NOW())
            ");
            foreach ($testCases as $idx => $tc) {
                $isSample = 0;
                $isHidden = 1;
                if (isset($tc['type'])) {
                    if ($tc['type'] === 'sample') {
                        $isSample = 1;
                        $isHidden = 0;
                    } else {
                        $isSample = 0;
                        $isHidden = 1;
                    }
                } else {
                    $isSample = !empty($tc['is_sample']) ? 1 : 0;
                    $isHidden = $isSample ? 0 : 1;
                }

                $tcInsertStmt->execute([
                    'pvid'   => $newVersionId,
                    'corder' => $idx + 1,
                    'input'  => (string)($tc['input_data'] ?? ''),
                    'output' => (string)($tc['expected_output'] ?? ''),
                    'sample' => $isSample,
                    'hidden' => $isHidden,
                    'points' => max(1, (int)($tc['points'] ?? 10))
                ]);
            }

            // 3. Cập nhật `current_version_id` trên bảng `problems` trỏ sang phiên bản mới vừa phát hành.
            $updProb = $pdo->prepare("UPDATE problems SET current_version_id = ? WHERE id = ?");
            $updProb->execute([$newVersionId, $problemId]);

            // 4. Tự động đưa tất cả bài nộp cũ vào hàng đợi chấm lại (Rejudge) trên phiên bản mới.
            // Lấy danh sách ID tất cả bài nộp của bài tập này (PDO::FETCH_COLUMN trả về mảng 1 chiều các ID).
            $subStmt2 = $pdo->prepare("SELECT id FROM submissions WHERE problem_id = ?");
            $subStmt2->execute([$problemId]);
            $subsToRejudge = $subStmt2->fetchAll(PDO::FETCH_COLUMN);

            $queuedRuns = [];
            if (!empty($subsToRejudge)) {
                // Câu lệnh tìm số thứ tự lần chấm lớn nhất hiện tại của mỗi bài nộp (COALESCE trả 0 nếu chưa có).
                $maxRunStmt = $pdo->prepare("SELECT COALESCE(MAX(run_number), 0) FROM judging_runs WHERE submission_id = ?");
                // Tạo bản ghi lượt chấm mới (triggered_by = 'rejudge', status = 'Queued', verdict = 'Pending')
                // thay vì ghi đè bản ghi cũ -> giúp đối chiếu kết quả giữa các phiên bản.
                $insertRunStmt = $pdo->prepare("
                    INSERT INTO judging_runs 
                    (submission_id, problem_version_id, run_number, triggered_by, status, verdict)
                    VALUES (:sub_id, :ver_id, :run_num, 'rejudge', 'Queued', 'Pending')
                ");

                foreach ($subsToRejudge as $sId) {
                    $sId = (int)$sId;
                    $maxRunStmt->execute([$sId]);
                    // Lần chấm mới có run_number = lần chấm lớn nhất trước đó + 1.
                    $nextRunNum = (int)$maxRunStmt->fetchColumn() + 1;

                    $insertRunStmt->execute([
                        'sub_id'  => $sId,
                        'ver_id'  => $newVersionId,
                        'run_num' => $nextRunNum
                    ]);

                    $newRunId = (int)$pdo->lastInsertId();
                    $queuedRuns[] = [
                        'submission_id' => $sId,
                        'run_id'        => $newRunId,
                        'run_number'    => $nextRunNum
                    ];
                }
            }

            // Hoàn tất và ghi nhận toàn bộ 4 bước vào cơ sở dữ liệu.
            $pdo->commit();

            // Sau khi commit thành công, đánh thức tiến trình Worker chạy ngầm (bất đồng bộ) để bắt đầu chấm bài ngay,
            // giúp API phản hồi nhanh về trình duyệt mà không phải chờ chấm xong hết mới trả kết quả.
            if (!empty($queuedRuns)) {
                WorkerLauncher::triggerAsync();
            }

            echo json_encode([
                'success'         => true,
                'mode'            => 'new_version_rejudge',
                'problem_id'      => $problemId,
                'version_id'      => $newVersionId,
                'version_number'  => $newVersionNumber,
                'rejudged_count'  => count($queuedRuns),
                'testcases_count' => count($testCases),
                'message'         => "Đã phát hành phiên bản mới v{$newVersionNumber} với " . count($testCases) . " test case và tự động đưa " . count($queuedRuns) . " bài nộp cũ vào hàng đợi chấm lại!"
            ], JSON_UNESCAPED_UNICODE);
            exit;

        } catch (Throwable $e) {
            // Có lỗi xảy ra -> hoàn tác toàn bộ Transaction để dữ liệu không bị nửa vời.
            $pdo->rollBack();
            error_log("save_testcases rejudge error: " . $e->getMessage());
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'error'   => 'Lỗi hệ thống khi tạo phiên bản mới & rejudge: ' . $e->getMessage()
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }
}

// -----------------------------------------------------------------------------
// THAO TÁC 1: TẠO PHIÊN BẢN BỘ DỮ LIỆU MỚI (create_version)
// Cho phép tạo phiên bản đề mới độc lập (có thể đổi mô tả, giới hạn thời gian/bộ nhớ,
// hoặc thay bộ test case mới) mà chưa bắt buộc phải kích hoạt chấm lại ngay.
// -----------------------------------------------------------------------------
if ($action === 'create_version') {
    // Đọc các tham số cấu hình cho phiên bản mới, kèm giá trị mặc định nếu không gửi lên.
    $changelog     = isset($data['changelog']) ? trim((string)$data['changelog']) : 'Cập nhật bộ dữ liệu kiểm tra';
    $timeLimitMs   = isset($data['time_limit_ms']) ? (int)$data['time_limit_ms'] : 1000;
    $memoryLimitKb = isset($data['memory_limit_kb']) ? (int)$data['memory_limit_kb'] : 262144;
    $testCases     = isset($data['test_cases']) && is_array($data['test_cases']) ? $data['test_cases'] : [];

    // Lấy thông tin phiên bản cũ gần nhất để kế thừa mô tả nếu người dùng không cung cấp nội dung mới.
    $latestVerStmt = $pdo->prepare("
        SELECT * FROM problem_versions 
        WHERE problem_id = ? 
        ORDER BY version_number DESC 
        LIMIT 1
    ");
    $latestVerStmt->execute([$problemId]);
    $latestVer = $latestVerStmt->fetch();

    // Toán tử `??` (null coalescing): ưu tiên dữ liệu mới gửi lên; nếu không có thì lấy của bản cũ; cuối cùng là chuỗi rỗng.
    $description       = $data['description'] ?? ($latestVer['description'] ?? '');
    $inputDescription  = $data['input_description'] ?? ($latestVer['input_description'] ?? '');
    $outputDescription = $data['output_description'] ?? ($latestVer['output_description'] ?? '');
    $constraints       = $data['constraints'] ?? ($latestVer['constraints'] ?? '');

    // Tính số thứ tự cho phiên bản kế tiếp.
    $newVersionNumber = $latestVer ? ((int)$latestVer['version_number'] + 1) : 1;

    // Bắt đầu Transaction để tạo phiên bản, ghi/sao chép test case và cập nhật bài tập một cách đồng bộ.
    $pdo->beginTransaction();
    try {
        // 1. Thêm phiên bản mới vào bảng `problem_versions`.
        $insertVerStmt = $pdo->prepare("
            INSERT INTO problem_versions 
            (problem_id, version_number, description, input_description, output_description,
             constraints, time_limit_ms, memory_limit_kb, changelog, created_by, created_at)
            VALUES (:pid, :vnum, :desc, :indesc, :outdesc, :cons, :time_lim, :mem_lim, :change, :uid, NOW())
        ");
        $insertVerStmt->execute([
            'pid'      => $problemId,
            'vnum'     => $newVersionNumber,
            'desc'     => $description,
            'indesc'   => $inputDescription,
            'outdesc'  => $outputDescription,
            'cons'     => $constraints,
            'time_lim' => $timeLimitMs,
            'mem_lim'  => $memoryLimitKb,
            'change'   => $changelog,
            'uid'      => $userId
        ]);
        $newVersionId = (int)$pdo->lastInsertId();

        // 2. Thêm các test case mới cho phiên bản này.
        $tcInsertStmt = $pdo->prepare("
            INSERT INTO test_cases 
            (problem_version_id, case_order, input_data, expected_output, is_sample, is_hidden, points, created_at)
            VALUES (:pvid, :corder, :input, :output, :sample, :hidden, :points, NOW())
        ");

        if (!empty($testCases)) {
            // Nếu có gửi danh sách test case mới, duyệt và chèn từng test vào bảng `test_cases`.
            foreach ($testCases as $idx => $tc) {
                $isSample = 0;
                $isHidden = 1;
                if (isset($tc['type'])) {
                    if ($tc['type'] === 'sample') {
                        $isSample = 1;
                        $isHidden = 0;
                    } else {
                        $isSample = 0;
                        $isHidden = 1;
                    }
                } else {
                    $isSample = !empty($tc['is_sample']) ? 1 : 0;
                    $isHidden = $isSample ? 0 : 1;
                }

                $tcInsertStmt->execute([
                    'pvid'   => $newVersionId,
                    'corder' => $tc['case_order'] ?? ($idx + 1),
                    'input'  => $tc['input_data'] ?? '',
                    'output' => $tc['expected_output'] ?? '',
                    'sample' => $isSample,
                    'hidden' => $isHidden,
                    'points' => (int)($tc['points'] ?? 10)
                ]);
            }
        } elseif ($latestVer) {
            // Nếu không cung cấp bộ test mới, sao chép nguyên toàn bộ test từ phiên bản cũ sang phiên bản mới
            // bằng một câu lệnh SQL `INSERT INTO ... SELECT` rất nhanh và gọn.
            $copyTc = $pdo->prepare("
                INSERT INTO test_cases (problem_version_id, case_order, input_data, expected_output, is_sample, is_hidden, points, created_at)
                SELECT :new_vid, case_order, input_data, expected_output, is_sample, is_hidden, points, NOW()
                FROM test_cases
                WHERE problem_version_id = :old_vid
            ");
            $copyTc->execute([
                'new_vid' => $newVersionId,
                'old_vid' => $latestVer['id']
            ]);
        }

        // 3. Cập nhật `current_version_id` trên bảng `problems` sang phiên bản vừa tạo.
        $updateProb = $pdo->prepare("UPDATE problems SET current_version_id = ? WHERE id = ?");
        $updateProb->execute([$newVersionId, $problemId]);

        // Lưu toàn bộ thay đổi của Transaction.
        $pdo->commit();

        echo json_encode([
            'success'        => true,
            'version_id'     => $newVersionId,
            'version_number' => $newVersionNumber,
            'message'        => "Đã phát hành phiên bản mới v{$newVersionNumber} cho bài tập #{$problemId}."
        ], JSON_UNESCAPED_UNICODE);
        exit;

    } catch (Throwable $e) {
        // Hủy Transaction nếu gặp sự cố và trả về mã HTTP 500 (Internal Server Error).
        $pdo->rollBack();
        error_log("create_version error: " . $e->getMessage());
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error'   => 'Lỗi hệ thống khi tạo phiên bản mới: ' . $e->getMessage()
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

// -----------------------------------------------------------------------------
// THAO TÁC 2: CHẤM LẠI BÀI NỘP (rejudge)
// Tạo lượt chấm mới trong bảng `judging_runs` cho tất cả (hoặc một số) bài nộp của bài tập
// trên phiên bản bộ dữ liệu mong muốn, giữ nguyên hoàn toàn lịch sử các lần chấm trước.
// -----------------------------------------------------------------------------
if ($action === 'rejudge') {
    // Phiên bản muốn áp dụng để chấm lại (nếu client không chỉ định thì mặc định lấy `current_version_id`).
    $targetVersionId = !empty($data['problem_version_id']) ? (int)$data['problem_version_id'] : (int)$problem['current_version_id'];
    // Danh sách ID bài nộp cụ thể muốn chấm lại (ép toàn bộ phần tử sang số nguyên bằng array_map('intval', ...)).
    // Nếu mảng rỗng, hệ thống sẽ chấm lại toàn bộ bài nộp của bài tập này.
    $submissionIds   = isset($data['submission_ids']) && is_array($data['submission_ids']) ? array_map('intval', $data['submission_ids']) : [];

    // Tìm danh sách bài nộp cần rejudge
    if (!empty($submissionIds)) {
        // Tạo chuỗi dấu hỏi chấm tương ứng với số lượng ID (ví dụ 3 ID -> "?,?,?") để dùng trong mệnh đề IN (...).
        $inPlaceholders = implode(',', array_fill(0, count($submissionIds), '?'));
        $sql = "SELECT id FROM submissions WHERE problem_id = ? AND id IN ({$inPlaceholders})";
        $params = array_merge([$problemId], $submissionIds);
    } else {
        // Chấm lại toàn bộ bài nộp thuộc bài tập này.
        $sql = "SELECT id FROM submissions WHERE problem_id = ?";
        $params = [$problemId];
    }

    // Thực thi truy vấn lấy danh sách ID bài nộp hợp lệ.
    $subStmt = $pdo->prepare($sql);
    $subStmt->execute($params);
    $subsToRejudge = $subStmt->fetchAll(PDO::FETCH_COLUMN);

    // Nếu không có bài nộp nào thỏa điều kiện, trả về thông báo thành công với số lượng = 0.
    if (empty($subsToRejudge)) {
        echo json_encode([
            'success'        => true,
            'rejudged_count' => 0,
            'message'        => 'Không có bài nộp nào phù hợp để chấm lại.'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Bắt đầu Transaction để tạo đồng loạt các lượt chấm lại trong bảng `judging_runs`.
    $pdo->beginTransaction();
    $queuedRuns = [];

    try {
        // Chuẩn bị 2 câu lệnh dùng lại trong vòng lặp (tối ưu hiệu năng và chống SQL Injection).
        $maxRunStmt = $pdo->prepare("SELECT COALESCE(MAX(run_number), 0) FROM judging_runs WHERE submission_id = ?");
        $insertRunStmt = $pdo->prepare("
            INSERT INTO judging_runs 
            (submission_id, problem_version_id, run_number, triggered_by, status, verdict)
            VALUES (:sub_id, :ver_id, :run_num, 'rejudge', 'Queued', 'Pending')
        ");

        // Với mỗi bài nộp, tính số thứ tự lần chấm kế tiếp và thêm 1 hàng mới ở trạng thái chờ ('Queued').
        foreach ($subsToRejudge as $sId) {
            $sId = (int)$sId;
            $maxRunStmt->execute([$sId]);
            $nextRunNum = (int)$maxRunStmt->fetchColumn() + 1;

            $insertRunStmt->execute([
                'sub_id'  => $sId,
                'ver_id'  => $targetVersionId,
                'run_num' => $nextRunNum
            ]);

            // Lưu thông tin lượt chạy vừa tạo để trả về cho client theo dõi.
            $newRunId = (int)$pdo->lastInsertId();
            $queuedRuns[] = [
                'submission_id' => $sId,
                'run_id'        => $newRunId,
                'run_number'    => $nextRunNum
            ];
        }

        // Xác nhận ghi toàn bộ các lượt chấm mới vào CSDL.
        $pdo->commit();

        // Kích hoạt tiến trình chấm bài chạy ngầm (async) để xử lý ngay các lượt vừa xếp hàng.
        if (!empty($queuedRuns)) {
            WorkerLauncher::triggerAsync();
        }

        echo json_encode([
            'success'        => true,
            'rejudged_count' => count($queuedRuns),
            'version_id'     => $targetVersionId,
            'queued_runs'    => $queuedRuns,
            'message'        => 'Đã tạo ' . count($queuedRuns) . ' lượt chấm lại vào hàng đợi (giữ nguyên toàn bộ lịch sử các lần chấm trước).'
        ], JSON_UNESCAPED_UNICODE);
        exit;

    } catch (Throwable $e) {
        // Hoàn tác Transaction nếu xảy ra lỗi và trả về mã HTTP 500.
        $pdo->rollBack();
        error_log("rejudge error: " . $e->getMessage());
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error'   => 'Lỗi hệ thống khi kích hoạt chấm lại: ' . $e->getMessage()
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

// Nếu tham số `action` không khớp bất kỳ thao tác hợp lệ nào ở trên, trả về mã lỗi HTTP 400 (Bad Request).
http_response_code(400);
echo json_encode([
    'success' => false,
    'error'   => "Hành động (action) '{$action}' không được hỗ trợ."
], JSON_UNESCAPED_UNICODE);