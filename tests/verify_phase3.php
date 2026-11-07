<?php
/**
 * ByteFlow Online Judge - Kịch bản kiểm thử nghiệm thu Giai đoạn 3 (HTTP End-to-End)
 * Kiểm tra các API qua HTTP Server thực tế:
 * 1. api/run_test.php (Lưu nháp & chạy thử custom input)
 * 2. api/submit.php (Chống nộp trùng - Idempotency: Phép thử 3)
 * 3. api/submission_status.php (Quyền riêng tư A chặn B & Bảo vệ dữ liệu kín: Phép thử 5)
 * 4. api/version_rejudge.php (Tạo phiên bản mới & Rejudge giữ nguyên lịch sử: Tình huống 2)
 *
 * Chạy qua CLI: php tests/verify_phase3.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../core/worker.php';

echo "====================================================================\n";
echo "   BYTEFLOW ONLINE JUDGE - KIỂM THỬ NGHIỆM THU GIAI ĐOẠN 3 (HTTP)   \n";
echo "====================================================================\n";

$baseUrl = 'http://localhost/BaiTapLon/api';
$cookieDir = __DIR__ . '/../temp';
if (!is_dir($cookieDir)) {
    mkdir($cookieDir, 0777, true);
}

/**
 * Hàm gọi API qua HTTP cURL với Cookie Jar
 */
function httpApiRequest(string $url, string $method = 'GET', ?array $data = null, ?string $cookieFile = null): array
{
    $ch = curl_init();
    
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        if ($data !== null) {
            $json = json_encode($data);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $json);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Content-Type: application/json',
                'Content-Length: ' . strlen($json)
            ]);
        }
    } else {
        if ($data !== null && !empty($data)) {
            $url .= '?' . http_build_query($data);
        }
    }

    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);

    if ($cookieFile !== null) {
        curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieFile);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile);
    }

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if ($response === false) {
        return [
            'status' => 0,
            'error'  => $err,
            'body'   => null
        ];
    }

    $json = json_decode((string)$response, true);
    return [
        'status' => $httpCode,
        'body'   => $json ?: $response,
        'raw'    => $response
    ];
}

$cookieLearner1    = $cookieDir . '/cookie_learner1.txt';
$cookieLearner2    = $cookieDir . '/cookie_learner2.txt';
$cookieInstructor1 = $cookieDir . '/cookie_instructor1.txt';

@unlink($cookieLearner1);
@unlink($cookieLearner2);
@unlink($cookieInstructor1);

// Đăng nhập các tài khoản mẫu
$login1 = httpApiRequest("{$baseUrl}/auth.php", 'POST', ['action' => 'login', 'username' => 'learner1', 'password' => 'Password123@'], $cookieLearner1);
$login2 = httpApiRequest("{$baseUrl}/auth.php", 'POST', ['action' => 'login', 'username' => 'learner2', 'password' => 'Password123@'], $cookieLearner2);
$loginInst = httpApiRequest("{$baseUrl}/auth.php", 'POST', ['action' => 'login', 'username' => 'instructor1', 'password' => 'Password123@'], $cookieInstructor1);

if ($login1['status'] !== 200 || empty($login1['body']['user'])) {
    echo "  [FAIL] Không thể đăng nhập learner1 qua HTTP API!\n";
    exit(1);
}
if ($login2['status'] !== 200 || empty($login2['body']['user'])) {
    echo "  [FAIL] Không thể đăng nhập learner2 qua HTTP API!\n";
    exit(1);
}
if ($loginInst['status'] !== 200 || empty($loginInst['body']['user'])) {
    echo "  [FAIL] Không thể đăng nhập instructor1 qua HTTP API!\n";
    exit(1);
}

$pdo = getDbConnection();

// ------------------------------------------------------------------
// BƯỚC 1: KIỂM THỬ API CHẠY THỬ & LƯU NHÁP (api/run_test.php)
// ------------------------------------------------------------------
echo "\n[BƯỚC 1] Kiểm tra API Chạy thử (Custom Input) & Lưu bản nháp:\n";

// 1.1 Kiểm tra chặn khi chưa đăng nhập
$unauthRes = httpApiRequest("{$baseUrl}/run_test.php", 'POST', [
    'problem_id' => 1,
    'language'   => 'python',
    'source_code'=> 'print("test")'
], null);

if ($unauthRes['status'] !== 401) {
    echo "  [FAIL] Không chặn 401 khi chưa đăng nhập! HTTP Status: {$unauthRes['status']}\n";
    exit(1);
}
echo "  [PASS] 1.1. Chặn 401 Unauthorized khi truy cập không có Session đăng nhập.\n";

// 1.2 Người học 1 lưu nháp và chạy thử với custom input "15 25\n"
$testCode = "import sys\na, b = map(int, sys.stdin.read().split())\nprint(a + b)";
$runTestRes = httpApiRequest("{$baseUrl}/run_test.php", 'POST', [
    'problem_id'   => 1,
    'language'     => 'python',
    'source_code'  => $testCode,
    'custom_input' => "15 25\n",
    'action'       => 'both'
], $cookieLearner1);

if ($runTestRes['status'] !== 200 || empty($runTestRes['body']['success'])) {
    echo "  [FAIL] Gọi API run_test.php thất bại: " . $runTestRes['raw'] . "\n";
    exit(1);
}

$execData = $runTestRes['body']['execution'];
if (trim($execData['stdout']) !== '40' || $execData['verdict'] !== 'AC') {
    echo "  [FAIL] Kết quả chạy thử không đúng (Kỳ vọng: 40): " . json_encode($execData) . "\n";
    exit(1);
}

// Kiểm tra bản nháp trong DB
$draftInDb = $pdo->query("SELECT source_code FROM code_drafts WHERE user_id = 3 AND problem_id = 1 AND language = 'python'")->fetchColumn();
if ($draftInDb !== $testCode) {
    echo "  [FAIL] Bản nháp chưa được lưu chuẩn xác vào DB!\n";
    exit(1);
}
echo "  [PASS] 1.2. Lưu nháp thành công và thực thi custom input cho kết quả chính xác (Stdout: 40, AC).\n";

// ------------------------------------------------------------------
// BƯỚC 2: KIỂM THỬ API NỘP BÀI & IDEMPOTENCY (api/submit.php - Phép thử 3)
// ------------------------------------------------------------------
echo "\n[BƯỚC 2] Kiểm tra API Nộp bài & Chống nộp trùng (Idempotency - Phép thử 3):\n";

$idempotencyKey1 = 'req_idemp_' . bin2hex(random_bytes(8));

// 2.1 Lần gửi 1: Tạo bản nộp mới
$submitRes1 = httpApiRequest("{$baseUrl}/submit.php", 'POST', [
    'problem_id'      => 1,
    'language'        => 'python',
    'source_code'     => $testCode,
    'idempotency_key' => $idempotencyKey1
], $cookieLearner1);

if ($submitRes1['status'] !== 201 || empty($submitRes1['body']['submission_id'])) {
    echo "  [FAIL] Lần nộp 1 thất bại: " . $submitRes1['raw'] . "\n";
    exit(1);
}
$subId1 = $submitRes1['body']['submission_id'];
$isDup1 = $submitRes1['body']['is_duplicate'];
if ($isDup1 !== false) {
    echo "  [FAIL] Lần nộp 1 bị đánh dấu nhầm là duplicate!\n";
    exit(1);
}
echo "  [PASS] 2.1. Lần nộp 1 tạo bản nộp mới thành công: Submission ID = {$subId1}.\n";

// 2.2 Lần gửi 2: Gửi lại cùng idempotency_key do mạng lag / bấm đúp
$submitRes2 = httpApiRequest("{$baseUrl}/submit.php", 'POST', [
    'problem_id'      => 1,
    'language'        => 'python',
    'source_code'     => $testCode,
    'idempotency_key' => $idempotencyKey1
], $cookieLearner1);

$subId2 = $submitRes2['body']['submission_id'];
$isDup2 = $submitRes2['body']['is_duplicate'];

if ($subId2 !== $subId1 || $isDup2 !== true) {
    echo "  [FAIL] Idempotency thất bại: Lần 2 tạo bản nộp mới hoặc không trả về cùng ID!\n";
    exit(1);
}

// Kiểm tra số lượng bản ghi trong DB
$countSub = $pdo->prepare("SELECT COUNT(*) FROM submissions WHERE user_id = 3 AND idempotency_key = ?");
$countSub->execute([$idempotencyKey1]);
if ((int)$countSub->fetchColumn() !== 1) {
    echo "  [FAIL] Phát hiện sinh bản ghi trùng trong DB!\n";
    exit(1);
}
echo "  [PASS] 2.2. Gửi lại cùng yêu cầu (Idempotent) trả về đúng ID = {$subId1}, không sinh bản ghi trùng.\n";

// 2.3 Lần gửi 3: Người học chủ động nộp lần mới với idempotency_key mới
$idempotencyKey2 = 'req_idemp_' . bin2hex(random_bytes(8));
$submitRes3 = httpApiRequest("{$baseUrl}/submit.php", 'POST', [
    'problem_id'      => 1,
    'language'        => 'python',
    'source_code'     => $testCode,
    'idempotency_key' => $idempotencyKey2
], $cookieLearner1);

$subId3 = $submitRes3['body']['submission_id'];
if ($subId3 === $subId1 || $submitRes3['body']['is_duplicate'] !== false) {
    echo "  [FAIL] Nộp bài mới có chủ ý không sinh bản nộp mới!\n";
    exit(1);
}
echo "  [PASS] 2.3. Nộp mới có chủ ý với key mới sinh bản nộp mới: Submission ID = {$subId3}.\n";

// Cho Worker xử lý các lượt chấm trong hàng đợi
$worker = new JudgingWorker(true, 1);
$worker->processNextQueuedRun(); // Chấm SubId1
$worker->processNextQueuedRun(); // Chấm SubId3

// ------------------------------------------------------------------
// BƯỚC 3: KIỂM THỬ API THEO DÕI & BẢO VỆ DỮ LIỆU KÍN (api/submission_status.php - Phép thử 5)
// ------------------------------------------------------------------
echo "\n[BƯỚC 3] Kiểm tra API Theo dõi & Bảo mật Dữ liệu kín (Phép thử 5):\n";

// 3.1 Người học B (learner2) xem mã riêng của Người học A (learner1)
$statusForbidden = httpApiRequest("{$baseUrl}/submission_status.php", 'GET', [
    'id' => $subId1
], $cookieLearner2);

if ($statusForbidden['status'] !== 403) {
    echo "  [FAIL] Người học B không bị chặn 403 khi xem bài của Người học A! Status: {$statusForbidden['status']}\n";
    exit(1);
}
echo "  [PASS] 3.1. Người học B bị chặn (HTTP 403) khi cố truy cập bài nộp của Người học A.\n";

// 3.2 Người học A (learner1) xem bài của chính mình
$statusAllowed = httpApiRequest("{$baseUrl}/submission_status.php", 'GET', [
    'id' => $subId1
], $cookieLearner1);

if ($statusAllowed['status'] !== 200) {
    echo "  [FAIL] Người học A không xem được bài của chính mình! Status: {$statusAllowed['status']}\n";
    exit(1);
}

$subDetails = $statusAllowed['body']['submission'];
$runsDetails = $statusAllowed['body']['runs'];

if ($subDetails['final_verdict'] !== 'AC' || count($runsDetails) === 0) {
    echo "  [FAIL] Dữ liệu bài nộp không đúng hoặc thiếu lịch sử run!\n";
    exit(1);
}

// 3.3 Kiểm tra TUYỆT ĐỐI KHÔNG LỘ DỮ LIỆU CHẤM KÍN
$caseResults = $runsDetails[0]['case_results'];
$foundProtected = 0;
foreach ($caseResults as $cr) {
    if ($cr['is_hidden'] == 1) {
        $foundProtected++;
        if ($cr['input_data'] !== '[DỮ LIỆU ĐƯỢC BẢO VỆ]' || 
            $cr['expected_output'] !== '[DỮ LIỆU ĐƯỢC BẢO VỆ]' || 
            $cr['actual_output'] !== '[DỮ LIỆU ĐƯỢC BẢO VỆ]') {
            echo "  [FAIL] Dữ liệu testcase kín bị lộ trong JSON trả về cho người học!\n";
            echo "         Case: " . json_encode($cr) . "\n";
            exit(1);
        }
    } else {
        // Sample case công khai phải hiển thị được
        if (empty($cr['input_data']) || empty($cr['expected_output'])) {
            echo "  [FAIL] Sample case công khai bị mất dữ liệu!\n";
            exit(1);
        }
    }
}
if ($foundProtected < 3) {
    echo "  [FAIL] Không đủ 3 testcase ẩn được bảo vệ!\n";
    exit(1);
}
echo "  [PASS] 3.2. Bảo mật tuyệt đối: Toàn bộ {$foundProtected} ca kiểm thử kín được che dấu an toàn.\n";

// ------------------------------------------------------------------
// BƯỚC 4: KIỂM THỬ API TẠO PHIÊN BẢN & REJUDGE (api/version_rejudge.php - Tình huống 2)
// ------------------------------------------------------------------
echo "\n[BƯỚC 4] Kiểm tra Tạo Phiên bản mới & Chấm lại (Tình huống sử dụng 2):\n";

// 4.1 Người học không được phép tạo phiên bản mới
$createVerLearner = httpApiRequest("{$baseUrl}/version_rejudge.php", 'POST', [
    'action'     => 'create_version',
    'problem_id' => 1
], $cookieLearner1);

if ($createVerLearner['status'] !== 403) {
    echo "  [FAIL] Người học lại có quyền tạo phiên bản mới! Status: {$createVerLearner['status']}\n";
    exit(1);
}
echo "  [PASS] 4.1. Chặn người học (HTTP 403), chỉ cho phép Giảng viên/Admin tạo phiên bản mới.\n";

// 4.2 Giảng viên tạo phiên bản mới v2 cho Bài 1 (bổ sung testcase biên số cực lớn)
$createVerInst = httpApiRequest("{$baseUrl}/version_rejudge.php", 'POST', [
    'action'        => 'create_version',
    'problem_id'    => 1,
    'changelog'     => 'Phiên bản v2: Nâng cấp giới hạn và thêm testcase biên cực lớn',
    'time_limit_ms' => 1200,
    'test_cases'    => [
        ['case_order' => 1, 'input_data' => "10 20\n", 'expected_output' => "30\n", 'is_sample' => 1, 'is_hidden' => 0, 'points' => 20],
        ['case_order' => 2, 'input_data' => "-5 5\n", 'expected_output' => "0\n", 'is_sample' => 1, 'is_hidden' => 0, 'points' => 20],
        ['case_order' => 3, 'input_data' => "0 0\n", 'expected_output' => "0\n", 'is_sample' => 0, 'is_hidden' => 1, 'points' => 20],
        ['case_order' => 4, 'input_data' => "500000000 500000000\n", 'expected_output' => "1000000000\n", 'is_sample' => 0, 'is_hidden' => 1, 'points' => 20],
        ['case_order' => 5, 'input_data' => "-1000000000 -1000000000\n", 'expected_output' => "-2000000000\n", 'is_sample' => 0, 'is_hidden' => 1, 'points' => 20]
    ]
], $cookieInstructor1);

if ($createVerInst['status'] !== 200 || empty($createVerInst['body']['version_number'])) {
    echo "  [FAIL] Tạo phiên bản mới thất bại: " . $createVerInst['raw'] . "\n";
    exit(1);
}
$newVerNum = $createVerInst['body']['version_number'];
$newVerId  = $createVerInst['body']['version_id'];
echo "  [PASS] 4.2. Giảng viên tạo thành công phiên bản mới v{$newVerNum} (ID = {$newVerId}).\n";

// 4.3 Giảng viên kích hoạt Chấm lại (Rejudge) trên phiên bản mới
$rejudgeRes = httpApiRequest("{$baseUrl}/version_rejudge.php", 'POST', [
    'action'             => 'rejudge',
    'problem_id'         => 1,
    'problem_version_id' => $newVerId,
    'submission_ids'     => [$subId1]
], $cookieInstructor1);

if ($rejudgeRes['status'] !== 200 || empty($rejudgeRes['body']['queued_runs'])) {
    echo "  [FAIL] Kích hoạt Rejudge thất bại: " . $rejudgeRes['raw'] . "\n";
    exit(1);
}
echo "  [PASS] 4.3. Kích hoạt Rejudge bài nộp #{$subId1} vào hàng đợi thành công.\n";

// Cho Worker thực hiện lượt chấm lại
$worker->processNextQueuedRun();

// 4.4 Kiểm tra đối chiếu: Lịch sử Run #1 và Run #2 đều được bảo lưu nguyên vẹn
$checkHistory = httpApiRequest("{$baseUrl}/submission_status.php", 'GET', [
    'id' => $subId1
], $cookieInstructor1);

$runsHistory = $checkHistory['body']['runs'];
if (count($runsHistory) < 2) {
    echo "  [FAIL] Lịch sử không đủ 2 lần chấm sau khi Rejudge! Số lượng: " . count($runsHistory) . "\n";
    exit(1);
}

$run1 = $runsHistory[0];
$run2 = $runsHistory[1];

echo "  [PASS] 4.4. Đối chiếu 2 lần chấm bảo lưu đầy đủ lịch sử:\n";
echo "         - Lần chấm 1: Run #{$run1['id']} (Bộ dữ liệu v{$run1['version_number']}) -> Verdict: {$run1['verdict']}\n";
echo "         - Lần chấm 2: Run #{$run2['id']} (Bộ dữ liệu v{$run2['version_number']}) -> Verdict: {$run2['verdict']}\n";

// Dọn dẹp cookie tạm
@unlink($cookieLearner1);
@unlink($cookieLearner2);
@unlink($cookieInstructor1);

echo "\n====================================================================\n";
echo "   KẾT QUẢ: TẤT CẢ CÁC MỤC GIAI ĐOẠN 3 ĐÃ HOÀN TẤT XUẤT SẮC!         \n";
echo "====================================================================\n";
