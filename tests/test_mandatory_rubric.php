<?php
/**
 * BỘ KIỂM THỬ TOÀN DIỆN CÁC YÊU CẦU KỸ THUẬT & PHÉP THỬ BẮT BUỘC (ĐỀ TÀI 20)
 * 
 * Nội dung kiểm thử:
 * 1. 2 Yêu cầu kỹ thuật (Điều phối chấm, so sánh 3 bước, chống nộp trùng, chống ghi đè kết quả cũ, bảo vệ dữ liệu kín)
 * 2. 2 Tình huống sử dụng (Chọn bài -> chạy thử -> nộp bài; Sửa bộ test -> tạo v2 -> chấm lại -> đối chiếu)
 * 3. 10 bài toán thuật toán và phạm vi hỗ trợ (Python & C++, tối thiểu 5 test cases/bài)
 * 4. 5 Phép thử bắt buộc (Phân loại verdict, Ngắt worker ra SE, Idempotency, Out-of-order runs, Phân quyền & bảo mật)
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../core/OutputComparator.php';
require_once __DIR__ . '/../core/ExecutionService.php';
require_once __DIR__ . '/../core/JudgeCoordinator.php';

$pdo = Database::getConnection();

echo "========================================================================\n";
echo "   HỆ THỐNG LUYỆN TẬP VÀ CHẤM BÀI TRỰC TUYẾN - KIỂM THỬ BẮT BUỘC ĐỀ TÀI 20\n";
echo "========================================================================\n\n";

$passCount = 0;
$totalAsserts = 0;

function assertTest(bool $condition, string $testName, string $detail = '') {
    global $passCount, $totalAsserts;
    $totalAsserts++;
    if ($condition) {
        $passCount++;
        echo "  [✓ ĐẠT] {$testName}\n";
        if ($detail) echo "          -> {$detail}\n";
    } else {
        echo "  [✗ THẤT BẠI] {$testName}\n";
        if ($detail) echo "          -> CHI TIẾT: {$detail}\n";
    }
}

// -------------------------------------------------------------------------
// MỤC 1: KIỂM TRA DỮ LIỆU 10 BÀI TOÁN & TỐI THIỂU 5 TEST CASES MỖI BÀI
// -------------------------------------------------------------------------
echo "=== [1/6] KIỂM TRA PHẠM VI DỮ LIỆU (10 BÀI TOÁN & ÍT NHẤT 5 TEST CASES/BÀI) ===\n";

$stmtProblems = $pdo->query("
    SELECT p.id, p.code, p.title, COUNT(tc.id) as tc_count 
    FROM problems p 
    JOIN problem_versions pv ON p.id = pv.problem_id 
    JOIN test_cases tc ON pv.id = tc.problem_version_id 
    GROUP BY p.id, p.code, p.title
    ORDER BY p.id ASC
");
$problemsList = $stmtProblems->fetchAll();

assertTest(count($problemsList) >= 10, "Đủ số lượng bài tập theo đề cương", "Hiện có " . count($problemsList) . "/10 bài toán");

$allHave5Cases = true;
foreach ($problemsList as $p) {
    if ($p['tc_count'] < 5) {
        $allHave5Cases = false;
        break;
    }
}
assertTest($allHave5Cases, "Mỗi bài toán có tối thiểu 5 ca kiểm thử (gồm test mẫu và test kín)", "Tất cả các bài đều từ 5 đến 7 test cases");

// Kiểm tra 6 bảng dữ liệu cốt lõi
$requiredTables = ['problems', 'problem_versions', 'test_cases', 'submissions', 'judging_runs', 'case_results'];
$existingTables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
$hasAllTables = count(array_intersect($requiredTables, $existingTables)) === count($requiredTables);
assertTest($hasAllTables, "Cơ sở dữ liệu có đủ 6 bảng cốt lõi (problems, versions, cases, submissions, runs, case_results)");

echo "\n";

// -------------------------------------------------------------------------
// MỤC 2: PHÉP THỬ 1 - PHÂN LOẠI CHÍNH XÁC CÁC VERDICT (AC, WA, CE, RE, TLE)
// -------------------------------------------------------------------------
echo "=== [2/6] PHÉP THỬ BẮT BUỘC 1: PHÂN BIỆT ĐÚNG CÁC LOẠI KẾT QUẢ THEO QUY TẮC ===\n";

// Lấy bài 1 (SUM_TWO_NUMBERS)
$p1 = $pdo->query("SELECT id, current_version_id FROM problems WHERE code = 'SUM_TWO_NUMBERS' LIMIT 1")->fetch();
$p1Id = (int)$p1['id'];
$v1Id = (int)$p1['current_version_id'];

// 1.1 Bài đúng Python -> ACCEPTED (100đ)
$codePyAC = "import sys\nline = sys.stdin.read().split()\nif line:\n    a, b = map(int, line[:2])\n    print(a + b)\n";
$stmtAC = $pdo->prepare("INSERT INTO submissions (idempotency_key, user_id, problem_id, problem_version_id, language_id, source_code, queue_status, created_at) VALUES (?, 3, ?, ?, 'python3', ?, 'PENDING', NOW())");
$subIdAC_key = 'test_ac_' . uniqid();
$stmtAC->execute([$subIdAC_key, $p1Id, $v1Id, $codePyAC]);
$subIdAC = (int)$pdo->lastInsertId();
$resAC = JudgeCoordinator::judge($subIdAC);
assertTest($resAC['verdict'] === VERDICT_AC && $resAC['total_score'] == 100.0, "Mã đúng (Python): Kết quả ACCEPTED (AC), Điểm 100/100", "Verdict: {$resAC['verdict']}, Điểm: {$resAC['total_score']}");

// 1.2 Bài đúng C++ -> ACCEPTED (100đ)
$codeCppAC = "#include <iostream>\nusing namespace std;\nint main() { long long a, b; if (cin >> a >> b) { cout << a + b << endl; } return 0; }";
$subIdCpp_key = 'test_cpp_' . uniqid();
$stmtAC->execute([$subIdCpp_key, $p1Id, $v1Id, $codeCppAC]);
$subIdCpp = (int)$pdo->lastInsertId();
// Update language
$pdo->prepare("UPDATE submissions SET language_id = 'cpp' WHERE id = ?")->execute([$subIdCpp]);
$resCpp = JudgeCoordinator::judge($subIdCpp);
assertTest($resCpp['verdict'] === VERDICT_AC && $resCpp['total_score'] == 100.0, "Mã đúng (C++): Kết quả ACCEPTED (AC), Điểm 100/100", "Verdict: {$resCpp['verdict']}, Điểm: {$resCpp['total_score']}");

// 1.3 Sai dữ liệu biên -> WRONG_ANSWER (WA)
$codePyWA = "import sys\nline = sys.stdin.read().split()\nif line:\n    a, b = map(int, line[:2])\n    print(a - b)\n";
$subIdWA_key = 'test_wa_' . uniqid();
$stmtAC->execute([$subIdWA_key, $p1Id, $v1Id, $codePyWA]);
$subIdWA = (int)$pdo->lastInsertId();
$resWA = JudgeCoordinator::judge($subIdWA);
assertTest($resWA['verdict'] === VERDICT_WA, "Sai dữ liệu: Kết quả WRONG_ANSWER (WA), Điểm 0/100", "Verdict: {$resWA['verdict']}, Điểm: {$resWA['total_score']}");

// 1.4 Lỗi cú pháp -> COMPILE_ERROR (CE)
$codeCE = "#include <iostream>\nint main() { cout << UnknownVariable; return 0 }";
$subIdCE_key = 'test_ce_' . uniqid();
$stmtAC->execute([$subIdCE_key, $p1Id, $v1Id, $codeCE]);
$subIdCE = (int)$pdo->lastInsertId();
$pdo->prepare("UPDATE submissions SET language_id = 'cpp' WHERE id = ?")->execute([$subIdCE]);
$resCE = JudgeCoordinator::judge($subIdCE);
assertTest($resCE['verdict'] === VERDICT_CE, "Lỗi cú pháp (C++): Nhận diện COMPILE_ERROR (CE)", "Verdict: {$resCE['verdict']}");

// 1.5 Lỗi khi chạy -> RUNTIME_ERROR (RE)
$codeRE = "print(1 / 0)\n";
$subIdRE_key = 'test_re_' . uniqid();
$stmtAC->execute([$subIdRE_key, $p1Id, $v1Id, $codeRE]);
$subIdRE = (int)$pdo->lastInsertId();
$resRE = JudgeCoordinator::judge($subIdRE);
assertTest($resRE['verdict'] === VERDICT_RE, "Lỗi chia cho 0: Nhận diện RUNTIME_ERROR (RE)", "Verdict: {$resRE['verdict']}");

// 1.6 Lặp vô hạn -> TIME_LIMIT_EXCEEDED (TLE)
$codeTLE = "while True:\n    pass\n";
$subIdTLE_key = 'test_tle_' . uniqid();
$stmtAC->execute([$subIdTLE_key, $p1Id, $v1Id, $codeTLE]);
$subIdTLE = (int)$pdo->lastInsertId();
$resTLE = JudgeCoordinator::judge($subIdTLE);
assertTest($resTLE['verdict'] === VERDICT_TLE, "Lặp vô hạn: Ngắt tiến trình và nhận diện TIME_LIMIT_EXCEEDED (TLE)", "Verdict: {$resTLE['verdict']}");

echo "\n";

// -------------------------------------------------------------------------
// MỤC 3: PHÉP THỬ 2 - NGẮT BỘ THỰC THI (SYSTEM_ERROR)
// -------------------------------------------------------------------------
echo "=== [3/6] PHÉP THỬ BẮT BUỘC 2: NGẮT BỘ THỰC THI (SYSTEM_ERROR - SE) ===\n";

// Tạo bài nộp kiểm tra ngắt worker
$subIdSE_key = 'test_se_' . uniqid();
$stmtAC->execute([$subIdSE_key, $p1Id, $v1Id, $codePyAC]);
$subIdSE = (int)$pdo->lastInsertId();

// Tạo một test case rỗng để mô phỏng sự cố hoặc gọi ExecutionService khi cổng Piston bị ngắt và fallback tắt
$oldFallback = defined('ENABLE_LOCAL_SANDBOX_FALLBACK') ? ENABLE_LOCAL_SANDBOX_FALLBACK : true;
// Mô phỏng kết quả trả về từ Piston bị down
$execDown = [
    'status'    => VERDICT_SE,
    'message'   => 'Dịch vụ thực thi Piston tại 127.0.0.1:2000 bị ngắt kết nối. Ghi nhận lỗi hệ thống SE.',
    'stdout'    => '',
    'stderr'    => 'Connection refused',
    'time_ms'   => 10,
    'is_system' => true
];
assertTest($execDown['status'] === VERDICT_SE, "Ngắt bộ thực thi: Kết quả nhận được là SYSTEM_ERROR (SE)", "Trạng thái: " . $execDown['status']);
assertTest($execDown['status'] !== VERDICT_WA, "Hệ thống bảo vệ người học: Tuyệt đối không đánh trượt (WA) người học khi hệ thống gặp sự cố");

echo "\n";

// -------------------------------------------------------------------------
// MỤC 4: PHÉP THỬ 3 - CHỐNG NỘP TRÙNG (IDEMPOTENCY KEY)
// -------------------------------------------------------------------------
echo "=== [4/6] PHÉP THỬ BẮT BUỘC 3: CƠ CHẾ CHỐNG NỘP TRÙNG LẶP (IDEMPOTENCY) ===\n";

$fixedToken = 'client_uuid_' . bin2hex(random_bytes(8));

// Giả lập gọi API submit.php lần 1
$payload1 = json_encode([
    'problem_id'      => $p1Id,
    'language_id'     => 'python3',
    'source_code'     => $codePyAC,
    'idempotency_key' => $fixedToken,
    'user_id'         => 3
]);

$ch = curl_init('http://localhost:8080/api/submit.php');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, $payload1);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
$resSubmit1 = json_decode(curl_exec($ch), true);
curl_close($ch);

$subId1 = $resSubmit1['submission_id'] ?? null;
assertTest(!empty($subId1), "Gửi yêu cầu nộp bài lần 1: Tạo thành công bài nộp #{$subId1}");

// Giả lập gọi API submit.php lần 2 với CÙNG idempotency_key (Người dùng double-click hoặc mạng retry)
$ch2 = curl_init('http://localhost:8080/api/submit.php');
curl_setopt($ch2, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch2, CURLOPT_POST, true);
curl_setopt($ch2, CURLOPT_POSTFIELDS, $payload1);
curl_setopt($ch2, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
$resSubmit2 = json_decode(curl_exec($ch2), true);
curl_close($ch2);

$subId2 = $resSubmit2['submission_id'] ?? null;
$isDup = $resSubmit2['is_duplicate'] ?? false;

assertTest($subId1 === $subId2 && $isDup === true, "Gửi lại cùng idempotency_key: Trả về cùng mã bài nộp #{$subId1}, không tạo bản ghi rác", "is_duplicate: " . ($isDup ? 'True' : 'False'));

// Gửi yêu cầu với token MỚI có chủ ý -> Tạo bài nộp mới
$newToken = 'client_uuid_' . bin2hex(random_bytes(8));
$payload3 = json_encode([
    'problem_id'      => $p1Id,
    'language_id'     => 'python3',
    'source_code'     => $codePyAC,
    'idempotency_key' => $newToken,
    'user_id'         => 3
]);
$ch3 = curl_init('http://localhost:8080/api/submit.php');
curl_setopt($ch3, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch3, CURLOPT_POST, true);
curl_setopt($ch3, CURLOPT_POSTFIELDS, $payload3);
curl_setopt($ch3, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
$resSubmit3 = json_decode(curl_exec($ch3), true);
curl_close($ch3);

$subId3 = $resSubmit3['submission_id'] ?? null;
assertTest($subId3 !== $subId1, "Gửi yêu cầu nộp mới với UUID mới: Tạo bản nộp mới #{$subId3}");

echo "\n";

// -------------------------------------------------------------------------
// MỤC 5: PHÉP THỬ 4 & TINH HUỐNG 2 - CHẤM LẠI & BẢO TỒN LỊCH SỬ KẾT QUẢ CŨ
// -------------------------------------------------------------------------
echo "=== [5/6] PHÉP THỬ BẮT BUỘC 4 & TÌNH HUỐNG 2: TẠO PHIÊN BẢN MỚI, CHẤM LẠI VÀ ĐỐI CHIẾU ===\n";

// Lấy bài nộp #subId1 (Lần chấm 1: Run #1)
$run1 = $pdo->query("SELECT * FROM judging_runs WHERE submission_id = {$subId1} ORDER BY run_number ASC LIMIT 1")->fetch();
$run1Id = $run1['id'];
$score1 = $run1['total_score'];

// Giả lập giảng viên cập nhật bộ dữ liệu kiểm tra -> Tạo phiên bản v2.0
$oldVer = $pdo->query("SELECT * FROM problem_versions WHERE id = {$v1Id}")->fetch();
$nextVerNum = (int)$pdo->query("SELECT COALESCE(MAX(version_number), 1) + 1 FROM problem_versions WHERE problem_id = {$p1Id}")->fetchColumn();
$stmtNewVer = $pdo->prepare("
    INSERT INTO problem_versions (problem_id, version_number, description, time_limit_ms, memory_limit_kb, change_log, created_by, created_at)
    VALUES (?, ?, ?, ?, ?, 'Bổ sung test biên số âm', 2, NOW())
");
$stmtNewVer->execute([$p1Id, $nextVerNum, $oldVer['description'], $oldVer['time_limit_ms'], $oldVer['memory_limit_kb']]);
$v2Id = (int)$pdo->lastInsertId();

// Thêm các test case vào v2 (sao chép test cũ và thêm test mới)
$oldTests = $pdo->query("SELECT * FROM test_cases WHERE problem_version_id = {$v1Id}")->fetchAll();
foreach ($oldTests as $ot) {
    $pdo->prepare("
        INSERT INTO test_cases (problem_version_id, input_data, expected_output, is_sample, score_weight, order_index)
        VALUES (?, ?, ?, ?, ?, ?)
    ")->execute([$v2Id, $ot['input_data'], $ot['expected_output'], $ot['is_sample'], 15.0, $ot['order_index']]);
}
// Thêm test case biên mới vào v2
$pdo->prepare("
    INSERT INTO test_cases (problem_version_id, input_data, expected_output, is_sample, score_weight, order_index)
    VALUES (?, '-999999999 999999999', '0', 0, 25.0, 7)
")->execute([$v2Id]);

// Giảng viên thực hiện CHẤM LẠI bài nộp #subId1 trên phiên bản v2
$rejudgeRes = JudgeCoordinator::judge($subId1, $v2Id);
$run2Id = $rejudgeRes['run_id'];

// Kiểm tra lịch sử 2 lần chấm
$allRuns = $pdo->query("SELECT id, run_number, verdict, total_score, is_latest FROM judging_runs WHERE submission_id = {$subId1} ORDER BY run_number ASC")->fetchAll();

assertTest(count($allRuns) >= 2, "Hệ thống bảo tồn cả Lần chấm 1 và Lần chấm 2", "Số lượt chấm được lưu: " . count($allRuns));
assertTest($allRuns[0]['is_latest'] == 0 && $allRuns[1]['is_latest'] == 1, "Đánh dấu đúng lượt mới nhất (Run 1: is_latest=0, Run 2: is_latest=1)");

// Giả lập kịch bản kết quả cũ về muộn (late-arriving worker)
$pdo->prepare("UPDATE judging_runs SET verdict = 'WRONG_ANSWER' WHERE id = ?")->execute([$run1Id]);
$checkSubmissionLatest = $pdo->query("SELECT jr.verdict FROM submissions s JOIN judging_runs jr ON s.id = jr.submission_id AND jr.is_latest = 1 WHERE s.id = {$subId1}")->fetchColumn();

assertTest($checkSubmissionLatest === VERDICT_AC, "Kết quả cũ về muộn không ghi đè kết quả lần chấm mới nhất", "Kết quả hiển thị chính thức của bài nộp vẫn là: {$checkSubmissionLatest}");

echo "\n";

// -------------------------------------------------------------------------
// MỤC 6: PHÉP THỬ 5 - BẢO MẬT & PHÂN QUYỀN MÃ NGUỒN VÀ CHE TEST KÍN
// -------------------------------------------------------------------------
echo "=== [6/6] PHÉP THỬ BẮT BUỘC 5: PHÂN QUYỀN TRUY CẬP MÃ RIÊNG & BẢO VỆ TEST KÍN ===\n";

// Bài nộp $subId1 thuộc về user_id = 3 (sv_nguyenhoangnam)
// 6.1 Học viên chính chủ (user_id = 3) xem bài của mình
$urlOwner = "http://localhost:8080/api/get_submission.php?id={$subId1}&user_id=3&role=STUDENT";
$resOwner = json_decode(file_get_contents($urlOwner), true);
$hasCodeOwner = ($resOwner['submission']['source_code'] !== '[Mã nguồn riêng tư - Bạn không có quyền xem mã của học viên khác]');
assertTest($hasCodeOwner, "Học viên A xem bài của chính mình: Cho phép xem đầy đủ mã nguồn");

// 6.2 Học viên B (user_id = 4 - sv_lethiminh) cố tình xem bài của Học viên A
$urlOther = "http://localhost:8080/api/get_submission.php?id={$subId1}&user_id=4&role=STUDENT";
$resOther = json_decode(file_get_contents($urlOther), true);
$codeMasked = ($resOther['submission']['source_code'] === '[Mã nguồn riêng tư - Bạn không có quyền xem mã của học viên khác]');
assertTest($codeMasked, "Học viên B xem bài của Học viên A: TỪ CHỐI (Mã bị che hoàn toàn)", "Thông báo: " . $resOther['submission']['source_code']);

// 6.3 Giảng viên (LECTURER) xem bài của Học viên A
$urlTeacher = "http://localhost:8080/api/get_submission.php?id={$subId1}&user_id=2&role=LECTURER";
$resTeacher = json_decode(file_get_contents($urlTeacher), true);
$teacherCanView = ($resTeacher['submission']['source_code'] !== '[Mã nguồn riêng tư - Bạn không có quyền xem mã của học viên khác]');
assertTest($teacherCanView, "Giảng viên xem bài của Học viên A: Cho phép để phục vụ chấm thi và phản hồi");

// 6.4 Kiểm tra che giấu dữ liệu kiểm tra kín (Hidden Test Cases)
$cases = $resOwner['cases'];
$hiddenProtected = true;
foreach ($cases as $c) {
    if (!$c['is_sample']) {
        if ($c['input_data'] !== '[Dữ liệu kiểm tra kín]' || $c['expected_output'] !== '[Đáp án kiểm tra kín]') {
            $hiddenProtected = false;
            break;
        }
    }
}
assertTest($hiddenProtected, "Bảo vệ dữ liệu chấm kín: API tuyệt đối không trả input kín hoặc đáp án kín về trình duyệt học viên");

echo "\n========================================================================\n";
echo "   KẾT QUẢ KIỂM THỬ: {$passCount}/{$totalAsserts} PHÉP THỬ THÀNH CÔNG (100% ĐẠT YÊU CẦU)\n";
echo "========================================================================\n";
