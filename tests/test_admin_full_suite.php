<?php
/**
 * ByteFlow Online Judge - Test Suite Toàn diện:
 * Đăng nhập chính thức, Đăng ký, Phân quyền Admin 5 chức năng,
 * Khóa/Mở khóa tài khoản, Phân quyền hiển thị đề, Rejudge theo SV & Xuất báo cáo.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

$baseUrl = 'http://localhost/BaiTapLon';
$cookieAdmin = sys_get_temp_dir() . '/cookie_adm_' . uniqid() . '.txt';
$cookieUser  = sys_get_temp_dir() . '/cookie_usr_' . uniqid() . '.txt';

echo "========================================================================\n";
echo "   KIỂM THỬ TOÀN DIỆN PHÂN QUYỀN CHÍNH THỨC & 5 CHỨC NĂNG QUẢN TRỊ     \n";
echo "========================================================================\n\n";

function httpReq(string $url, string $method = 'GET', $body = null, ?string $cookieFile = null, bool $isJson = false): array {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HEADER, false);
    if ($cookieFile) {
        curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieFile);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile);
    }
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        if ($isJson) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        } else {
            curl_setopt($ch, CURLOPT_POSTFIELDS, is_array($body) ? http_build_query($body) : $body);
        }
    }
    $res = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['status' => $code, 'body' => (string)$res, 'json' => json_decode((string)$res, true)];
}

$pdo = getDbConnection();
$ts = time();
$testUser   = "svtest_{$ts}";
$testEmail  = "svtest_{$ts}@cntt.edu.vn";
$testCode   = "CNTT" . substr((string)$ts, -6);
$testPass   = "StudentPass123@";
$createdUserId = 0;
$createdProbId = 0;

// =========================================================================
// TEST 1: LOẠI BỎ ĐĂNG NHẬP THỬ NGHIỆM TRÊN LOGIN.PHP
// =========================================================================
echo "--- TEST 1: Kiểm tra Trang Đăng nhập Chính thức (Không còn nút thử nghiệm) ---\n";
$loginPage = httpReq("{$baseUrl}/login.php");
if ($loginPage['status'] === 200
    && strpos($loginPage['body'], 'quickLogin') === false
    && strpos($loginPage['body'], 'Password123@') === false
    && strpos($loginPage['body'], 'register.php') !== false) {
    echo "  [PASS] login.php đã gỡ sạch đăng nhập thử nghiệm và có liên kết sang register.php!\n";
} else {
    echo "  [FAIL] login.php vẫn còn dấu vết đăng nhập thử nghiệm hoặc thiếu link đăng ký!\n";
    exit(1);
}

$regPage = httpReq("{$baseUrl}/register.php");
if ($regPage['status'] === 200 && strpos($regPage['body'], 'student_code') !== false) {
    echo "  [PASS] Trang đăng ký register.php hoạt động chuẩn (HTTP 200, có trường Mã sinh viên)!\n";
} else {
    echo "  [FAIL] register.php lỗi!\n";
    exit(1);
}

// =========================================================================
// TEST 2: ĐĂNG KÝ TÀI KHOẢN CHÍNH THỨC & CHỐNG LEO THANG ĐẶC QUYỀN
// =========================================================================
echo "\n--- TEST 2: Đăng ký Tài khoản mới & Chống tự đăng ký quyền Admin ---\n";
$regRes = httpReq("{$baseUrl}/api/auth.php", 'POST', [
    'action'           => 'register',
    'full_name'        => 'Nguyễn Minh Kiểm Thử',
    'username'         => $testUser,
    'student_code'     => $testCode,
    'class_name'       => 'CNTT-18-10',
    'email'            => $testEmail,
    'password'         => $testPass,
    'password_confirm' => $testPass,
    'role'             => 'admin' // Cố tình truyền role=admin để kiểm tra bảo mật
], null, true);

if ($regRes['status'] === 201 && !empty($regRes['json']['success'])) {
    $createdUserId = (int)$regRes['json']['user_id'];
    $uRow = $pdo->query("SELECT role, student_code FROM users WHERE id = {$createdUserId}")->fetch(PDO::FETCH_ASSOC);
    if ($uRow['role'] === 'learner' && $uRow['student_code'] === $testCode) {
        echo "  [PASS] Đăng ký thành công User #{$createdUserId} ({$testCode}), hệ thống ép role = 'learner' (chống leo thang quyền)!\n";
    } else {
        echo "  [FAIL] Role không bị ép về learner: " . print_r($uRow, true) . "\n";
        exit(1);
    }
} else {
    echo "  [FAIL] Đăng ký thất bại: " . $regRes['body'] . "\n";
    exit(1);
}

// Đăng nhập bằng Mã sinh viên (student_code)
$loginByCode = httpReq("{$baseUrl}/api/auth.php", 'POST', [
    'action'   => 'login',
    'username' => $testCode,
    'password' => $testPass
], $cookieUser, true);
if ($loginByCode['status'] === 200 && !empty($loginByCode['json']['success'])) {
    echo "  [PASS] Sinh viên đăng nhập chính thức thành công bằng Mã sinh viên ({$testCode})!\n";
} else {
    echo "  [FAIL] Đăng nhập bằng Mã sinh viên thất bại: " . $loginByCode['body'] . "\n";
    exit(1);
}

// =========================================================================
// TEST 3: PHÂN QUYỀN ADMIN (CHẶN SINH VIÊN VÀO CÁC TRANG QUẢN TRỊ)
// =========================================================================
echo "\n--- TEST 3: Kiểm tra Sinh viên bị chặn truy cập các phân hệ Admin (HTTP 403) ---\n";
foreach (['admin_users.php', 'admin_judging.php', 'admin_analytics.php', 'api/export_report.php'] as $adminRoute) {
    $r = httpReq("{$baseUrl}/{$adminRoute}", 'GET', null, $cookieUser);
    if ($r['status'] !== 403) {
        echo "  [FAIL] Sinh viên truy cập được {$adminRoute} (Status {$r['status']})!\n";
        exit(1);
    }
}
echo "  [PASS] Sinh viên bị chặn HTTP 403 ở tất cả 4 phân hệ Admin (users, judging, analytics, export)!\n";

// Đăng nhập Admin
$admLogin = httpReq("{$baseUrl}/api/auth.php", 'POST', [
    'action'   => 'login',
    'username' => 'admin1',
    'password' => 'Password123@'
], $cookieAdmin, true);
if ($admLogin['status'] !== 200 || empty($admLogin['json']['success'])) {
    echo "  [FAIL] Admin đăng nhập thất bại!\n";
    exit(1);
}

// =========================================================================
// TEST 4: QUẢN LÝ NGƯỜI DÙNG (CẤP/THU HỒI QUYỀN ADMIN & KHÓA/MỞ KHÓA)
// =========================================================================
echo "\n--- TEST 4: Quản lý Người dùng (Cấp/Thu hồi quyền Admin & Khóa tài khoản) ---\n";
// Cấp quyền Admin cho user test
$prom = httpReq("{$baseUrl}/api/admin_users.php", 'POST', [
    'action'  => 'update_role',
    'user_id' => $createdUserId,
    'role'    => 'admin'
], $cookieAdmin, true);
if ($prom['status'] === 200 && !empty($prom['json']['success'])) {
    echo "  [PASS] Admin cấp quyền Quản trị viên thành công cho @{$testUser}!\n";
} else {
    echo "  [FAIL] Cấp quyền thất bại: " . $prom['body'] . "\n";
    exit(1);
}

// Thu hồi lại về learner
$dem = httpReq("{$baseUrl}/api/admin_users.php", 'POST', [
    'action'  => 'update_role',
    'user_id' => $createdUserId,
    'role'    => 'learner'
], $cookieAdmin, true);
if ($dem['status'] === 200 && !empty($dem['json']['success'])) {
    echo "  [PASS] Admin thu hồi quyền Admin về Sinh viên thành công!\n";
} else {
    echo "  [FAIL] Thu hồi quyền thất bại!\n";
    exit(1);
}

// Khóa tài khoản vì gian lận
$lock = httpReq("{$baseUrl}/api/admin_users.php", 'POST', [
    'action'      => 'toggle_lock',
    'user_id'     => $createdUserId,
    'lock'        => 1,
    'lock_reason' => 'Gian lận kiểm thử tự động'
], $cookieAdmin, true);
if ($lock['status'] === 200 && !empty($lock['json']['success'])) {
    // Phiên cũ của sinh viên phải lập tức bị đá ra
    $meChk = httpReq("{$baseUrl}/api/auth.php?action=me", 'GET', null, $cookieUser);
    // Và đăng nhập lại phải nhận 403
    $reLogin = httpReq("{$baseUrl}/api/auth.php", 'POST', [
        'action'   => 'login',
        'username' => $testUser,
        'password' => $testPass
    ], $cookieUser, true);
    if (($meChk['json']['logged_in'] ?? true) === false && $reLogin['status'] === 403) {
        echo "  [PASS] Khóa tài khoản hiệu lực tức thì: Phiên hiện tại bị ngắt & đăng nhập mới bị chặn HTTP 403!\n";
    } else {
        echo "  [FAIL] Tài khoản bị khóa nhưng không bị chặn đúng cách!\n";
        exit(1);
    }
} else {
    echo "  [FAIL] Khóa tài khoản thất bại!\n";
    exit(1);
}

// Mở khóa lại tài khoản
$unlock = httpReq("{$baseUrl}/api/admin_users.php", 'POST', [
    'action'  => 'toggle_lock',
    'user_id' => $createdUserId,
    'lock'    => 0
], $cookieAdmin, true);
$loginAfterUnlock = httpReq("{$baseUrl}/api/auth.php", 'POST', [
    'action'   => 'login',
    'username' => $testUser,
    'password' => $testPass
], $cookieUser, true);
if ($unlock['status'] === 200 && $loginAfterUnlock['status'] === 200) {
    echo "  [PASS] Mở khóa tài khoản thành công: Sinh viên đăng nhập lại bình thường!\n";
} else {
    echo "  [FAIL] Mở khóa tài khoản thất bại!\n";
    exit(1);
}

// =========================================================================
// TEST 5: PHÂN QUYỀN HIỂN THỊ ĐỀ (HIDDEN) & PHÁT HÀNH PHIÊN BẢN MỚI (v2)
// =========================================================================
echo "\n--- TEST 5: Phân quyền Hiển thị Đề (Ẩn bảo trì) & Phiên bản Đề (v2) ---\n";
$slugVis = "test-vis-{$ts}";
$createHidden = httpReq("{$baseUrl}/manage_problems.php", 'POST', [
    'action'          => 'create_problem',
    'slug'            => $slugVis,
    'title'           => 'Bài toán Bảo trì Test',
    'topic'           => 'Đồ thị',
    'difficulty'      => 'Medium',
    'visibility'      => 'hidden',
    'time_limit_ms'   => 1500,
    'memory_limit_mb' => 256,
    'description'     => 'Mô tả v1',
    'sample_input'    => "1 2\n",
    'sample_output'   => "3\n"
], $cookieAdmin, false);

$createdProbId = (int)$pdo->query("SELECT id FROM problems WHERE slug = " . $pdo->quote($slugVis))->fetchColumn();
if ($createdProbId <= 0) {
    echo "  [FAIL] Không tạo được bài tập ẩn!\n";
    exit(1);
}

// Sinh viên thử nộp bài vào đề đang ẩn -> Phải bị chặn 403!
$subHidden = httpReq("{$baseUrl}/api/submit.php", 'POST', [
    'problem_id'      => $createdProbId,
    'language'        => 'python',
    'source_code'     => 'print(3)',
    'idempotency_key' => 'test_hid_' . uniqid()
], $cookieUser, true);

if ($subHidden['status'] === 403) {
    echo "  [PASS] Đề trạng thái 'hidden' chặn thành công sinh viên nộp bài (HTTP 403)!\n";
} else {
    echo "  [FAIL] Đề ẩn không chặn sinh viên nộp bài! Status = {$subHidden['status']}\n";
    exit(1);
}

// Admin cập nhật bài tập và chọn 'Phát hành phiên bản mới (v2)' + đổi sang 'public'
$editNewVer = httpReq("{$baseUrl}/manage_problems.php", 'POST', [
    'action'             => 'edit_problem',
    'problem_id'         => $createdProbId,
    'title'              => 'Bài toán Bảo trì Test (v2)',
    'topic'              => 'Đồ thị',
    'difficulty'         => 'Medium',
    'visibility'         => 'public',
    'time_limit_ms'      => 2000,
    'memory_limit_mb'    => 512,
    'description'        => 'Mô tả v2 đã cập nhật',
    'input_description'  => 'Hai số nguyên',
    'output_description' => 'Tổng hai số',
    'constraints'        => '1 <= a, b <= 10^9',
    'create_new_version' => '1',
    'changelog'          => 'Tăng time limit 2000ms và mở công khai'
], $cookieAdmin, false);

$verCount = (int)$pdo->query("SELECT COUNT(*) FROM problem_versions WHERE problem_id = {$createdProbId}")->fetchColumn();
$curVerNum = (int)$pdo->query("
    SELECT pv.version_number FROM problems p
    JOIN problem_versions pv ON p.current_version_id = pv.id
    WHERE p.id = {$createdProbId}
")->fetchColumn();
if ($verCount === 2 && $curVerNum === 2) {
    echo "  [PASS] Phát hành phiên bản mới v2 thành công (Tổng {$verCount} phiên bản được lưu giữ nguyên vẹn lịch sử đề cũ)!\n";
} else {
    echo "  [FAIL] Phiên bản mới chưa được tạo đúng (verCount={$verCount}, curVer={$curVerNum})!\n";
    exit(1);
}

// =========================================================================
// TEST 6: VẬN HÀNH CHẤM BÀI, GIÁM SÁT SANDBOX & REJUDGE THEO SINH VIÊN
// =========================================================================
echo "\n--- TEST 6: Giám sát Sandbox (Python & C++) & Kích hoạt Rejudge theo Sinh viên ---\n";
$statusRes = httpReq("{$baseUrl}/api/admin_judging.php?action=status", 'GET', null, $cookieAdmin);
if ($statusRes['status'] === 200
    && !empty($statusRes['json']['compilers']['python']['available'])
    && isset($statusRes['json']['queue_by_language']['python'])
    && isset($statusRes['json']['queue_by_language']['cpp'])) {
    echo "  [PASS] API Giám sát Sandbox hoạt động: Python OK, C++ sẵn sàng, tách hàng đợi Python & C++!\n";
} else {
    echo "  [FAIL] API Giám sát Sandbox lỗi: " . $statusRes['body'] . "\n";
    exit(1);
}

// Sinh viên nộp 1 bài vào đề vừa mở public
$subOk = httpReq("{$baseUrl}/api/submit.php", 'POST', [
    'problem_id'      => $createdProbId,
    'language'        => 'python',
    'source_code'     => "a, b = map(int, input().split())\nprint(a + b)",
    'idempotency_key' => 'test_pub_' . uniqid()
], $cookieUser, true);
$newSubId = (int)($subOk['json']['submission_id'] ?? 0);

// Admin kích hoạt Rejudge riêng cho sinh viên này trên bài tập này
$rjRes = httpReq("{$baseUrl}/api/admin_judging.php", 'POST', [
    'action'     => 'rejudge',
    'problem_id' => $createdProbId,
    'user_id'    => $createdUserId
], $cookieAdmin, true);

if ($rjRes['status'] === 200 && (int)($rjRes['json']['queued_count'] ?? 0) === 1) {
    $runCnt = (int)$pdo->query("SELECT COUNT(*) FROM judging_runs WHERE submission_id = {$newSubId}")->fetchColumn();
    if ($runCnt >= 2) {
        echo "  [PASS] Admin kích hoạt Rejudge theo sinh viên thành công: Tạo bản ghi judging_runs mới (Tổng {$runCnt} runs cho Sub #{$newSubId})!\n";
    } else {
        echo "  [FAIL] Số lượt judging_runs không tăng!\n";
        exit(1);
    }
} else {
    echo "  [FAIL] Rejudge thất bại: " . $rjRes['body'] . "\n";
    exit(1);
}

// =========================================================================
// TEST 7: THỐNG KÊ & XUẤT BÁO CÁO HỌC TẬP (CSV/EXCEL & JSON)
// =========================================================================
echo "\n--- TEST 7: Kiểm tra Trang Quản trị & Xuất Báo cáo (CSV / JSON) ---\n";
foreach (['admin_users.php', 'admin_judging.php', 'admin_analytics.php'] as $p) {
    $r = httpReq("{$baseUrl}/{$p}", 'GET', null, $cookieAdmin);
    if ($r['status'] !== 200 || preg_match('/(<b>(Fatal error|Warning|Parse error|Notice)<\/b>|PHP (Warning|Fatal error):|Uncaught )/i', $r['body'], $m)) {
        echo "  [FAIL] Trang {$p} có lỗi HTTP ({$r['status']}) hoặc PHP error!\n";
        if (!empty($m)) {
            echo "  -> Chi tiết: " . strip_tags($m[0]) . "\n";
        }
        exit(1);
    }
}
echo "  [PASS] Tất cả 3 trang quản trị mới (admin_users, admin_judging, admin_analytics) trả HTTP 200 với 0 lỗi PHP!\n";

$csvRes = httpReq("{$baseUrl}/api/export_report.php?type=student_summary&format=csv", 'GET', null, $cookieAdmin);
$jsonRes = httpReq("{$baseUrl}/api/export_report.php?type=submissions&format=json", 'GET', null, $cookieAdmin);
if ($csvRes['status'] === 200
    && str_starts_with($csvRes['body'], "\xEF\xBB\xBF")
    && strpos($csvRes['body'], $testCode) !== false
    && $jsonRes['status'] === 200
    && is_array($jsonRes['json']['data'] ?? null)) {
    echo "  [PASS] Xuất Báo cáo Điểm CSV (UTF-8 BOM cho Excel, chứa MSSV {$testCode}) & Lịch sử nộp JSON thành công!\n";
} else {
    echo "  [FAIL] Xuất báo cáo thất bại!\n";
    exit(1);
}

// =========================================================================
// TEARDOWN: DỌN DẸP DỮ LIỆU KIỂM THỬ SẠCH SẼ
// =========================================================================
if ($newSubId > 0) {
    $pdo->prepare("DELETE FROM judging_runs WHERE submission_id = ?")->execute([$newSubId]);
    $pdo->prepare("DELETE FROM submissions WHERE id = ?")->execute([$newSubId]);
}
if ($createdProbId > 0) {
    $pdo->prepare("UPDATE problems SET current_version_id = NULL WHERE id = ?")->execute([$createdProbId]);
    $pdo->prepare("DELETE tc FROM test_cases tc JOIN problem_versions pv ON tc.problem_version_id = pv.id WHERE pv.problem_id = ?")->execute([$createdProbId]);
    $pdo->prepare("DELETE FROM problem_versions WHERE problem_id = ?")->execute([$createdProbId]);
    $pdo->prepare("DELETE FROM problems WHERE id = ?")->execute([$createdProbId]);
}
if ($createdUserId > 0) {
    $pdo->prepare("DELETE FROM users WHERE id = ?")->execute([$createdUserId]);
}
@unlink($cookieAdmin);
@unlink($cookieUser);

echo "\n========================================================================\n";
echo "   TẤT CẢ 7 PHÉP THỬ PHÂN QUYỀN & 5 CHỨC NĂNG ADMIN ĐÃ PASS 100%!      \n";
echo "========================================================================\n";
