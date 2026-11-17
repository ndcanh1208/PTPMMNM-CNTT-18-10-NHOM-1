<?php
/**
 * ByteFlow Online Judge - Test Suite: Tính Năng Xóa & Khôi Phục Bài Tập Thông Minh
 * Kiểm tra đầy đủ:
 * 1. Phân quyền (Learner bị 403, Instructor chỉ xóa bài mình, Admin toàn quyền).
 * 2. Xóa mềm (Soft Delete) & giải phóng slug gốc.
 * 3. Tạo lại bài mới cùng slug không bị lỗi UNIQUE conflict.
 * 4. Khôi phục bài tập (Restore) xử lý xung đột slug thông minh.
 * 5. Bảo vệ bài tập có bài nộp (Chặn Hard Delete, cho phép Soft Delete bảo toàn dữ liệu).
 * 6. Xóa vĩnh viễn (Hard Delete) an toàn khi 0 bài nộp.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

$baseUrl = 'http://localhost/BaiTapLon';
$cookieFileAdmin = sys_get_temp_dir() . '/cookie_admin_' . uniqid() . '.txt';
$cookieFileInst  = sys_get_temp_dir() . '/cookie_inst_' . uniqid() . '.txt';
$cookieFileLearn = sys_get_temp_dir() . '/cookie_learn_' . uniqid() . '.txt';

echo "============================================================\n";
echo "   KIỂM THỬ TÍNH NĂNG XÓA & KHÔI PHỤC BÀI TẬP THÔNG MINH    \n";
echo "============================================================\n\n";

function loginUser(string $baseUrl, string $cookieFile, string $username, string $password = 'Password123@'): bool {
    @unlink($cookieFile);
    $ch = curl_init("{$baseUrl}/api/auth.php");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
        'action'   => 'login',
        'username' => $username,
        'password' => $password
    ]));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieFile);
    curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile);
    $res = curl_exec($ch);
    $loginData = json_decode((string)$res, true);
    curl_close($ch);
    return !empty($loginData['success']);
}

function callDeleteApi(string $baseUrl, string $cookieFile, string $action, int $problemId): array {
    $ch = curl_init("{$baseUrl}/api/delete_problem.php");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
        'action'     => $action,
        'problem_id' => $problemId
    ]));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile);
    $res = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['status' => $status, 'data' => json_decode((string)$res, true)];
}

// 1. Đăng nhập 3 vai trò
echo "1. Đăng nhập các tài khoản kiểm thử...\n";
if (!loginUser($baseUrl, $cookieFileAdmin, 'admin1')) {
    echo "  [FAIL] Đăng nhập admin1 thất bại!\n";
    exit(1);
}
if (!loginUser($baseUrl, $cookieFileInst, 'instructor1')) {
    echo "  [FAIL] Đăng nhập instructor1 thất bại!\n";
    exit(1);
}
if (!loginUser($baseUrl, $cookieFileLearn, 'learner1')) {
    echo "  [FAIL] Đăng nhập learner1 thất bại!\n";
    exit(1);
}
echo "  [OK] Đã đăng nhập: admin1, instructor1, learner1.\n";

$pdo = getDbConnection();

// Lấy user_id của admin1 và instructor1
$adminId = (int)$pdo->query("SELECT id FROM users WHERE username = 'admin1'")->fetchColumn();
$instId  = (int)$pdo->query("SELECT id FROM users WHERE username = 'instructor1'")->fetchColumn();

// =============================================================================
// PHÉP THỬ 1: PHÂN QUYỀN TRUY CẬP (AUTHORIZATION)
// =============================================================================
echo "\n--- TEST 1: Kiểm tra Phân quyền Xóa Bài tập ---\n";
// Tạo bài do Admin tạo
$baseSlug1 = 'test-del-perm-' . time();
$pdo->prepare("
    INSERT INTO problems (slug, title, topic, difficulty, created_by, is_public)
    VALUES (?, 'Bài test phân quyền', 'Kiểm thử', 'Easy', ?, 1)
")->execute([$baseSlug1, $adminId]);
$permProblemId = (int)$pdo->lastInsertId();

// 1.1: Learner thử xóa bài -> Phải bị chặn 403
$resL = callDeleteApi($baseUrl, $cookieFileLearn, 'soft_delete', $permProblemId);
if ($resL['status'] === 403) {
    echo "  [PASS] Học viên (learner1) không thể xóa bài tập (Bị chặn HTTP 403)!\n";
} else {
    echo "  [FAIL] Học viên không bị chặn xóa bài! Status = {$resL['status']}\n";
    exit(1);
}

// 1.2: Giảng viên thử xóa bài do Admin tạo -> Phải bị chặn 403
$resI = callDeleteApi($baseUrl, $cookieFileInst, 'soft_delete', $permProblemId);
if ($resI['status'] === 403) {
    echo "  [PASS] Giảng viên (instructor1) không thể xóa bài của Admin (Bị chặn HTTP 403)!\n";
} else {
    echo "  [FAIL] Giảng viên xóa được bài của người khác! Status = {$resI['status']}\n";
    exit(1);
}

// 1.3: Admin xóa bài -> Thành công 200
$resA = callDeleteApi($baseUrl, $cookieFileAdmin, 'soft_delete', $permProblemId);
if ($resA['status'] === 200 && !empty($resA['data']['success'])) {
    echo "  [PASS] Quản trị viên (admin1) xóa mềm bài tập thành công (HTTP 200)!\n";
} else {
    echo "  [FAIL] Admin xóa bài thất bại!\n";
    exit(1);
}

// Dọn dẹp bài test 1
$pdo->prepare("DELETE FROM problems WHERE id = ?")->execute([$permProblemId]);

// =============================================================================
// PHÉP THỬ 2: XÓA MỀM & GIẢI PHÓNG SLUG GỐC (SOFT DELETE & RELEASE SLUG)
// =============================================================================
echo "\n--- TEST 2: Thử nghiệm Xóa mềm & Giải phóng Slug gốc ---\n";
$origSlug = 'slug-release-test-' . time();
$pdo->prepare("
    INSERT INTO problems (slug, title, topic, difficulty, created_by, is_public)
    VALUES (?, 'Bài toán Giải phóng Slug', 'Quy hoạch động', 'Medium', ?, 1)
")->execute([$origSlug, $instId]);
$probIdA = (int)$pdo->lastInsertId();

// Giảng viên xóa mềm bài do chính mình tạo
$resSoft = callDeleteApi($baseUrl, $cookieFileInst, 'soft_delete', $probIdA);
if ($resSoft['status'] === 200 && !empty($resSoft['data']['success'])) {
    // Kiểm tra CSDL: deleted_at phải khác NULL, slug phải đổi có hậu tố -deleted-
    $chk = $pdo->query("SELECT slug, deleted_at FROM problems WHERE id = {$probIdA}")->fetch(PDO::FETCH_ASSOC);
    if (!empty($chk['deleted_at']) && strpos($chk['slug'], '-deleted-') !== false) {
        echo "  [PASS] Xóa mềm thành công: deleted_at = '{$chk['deleted_at']}', slug được đổi thành '{$chk['slug']}'!\n";
    } else {
        echo "  [FAIL] Dữ liệu CSDL chưa được cập nhật đúng khi xóa mềm!\n";
        exit(1);
    }
} else {
    echo "  [FAIL] Gọi API soft_delete thất bại!\n";
    exit(1);
}

// =============================================================================
// PHÉP THỬ 3: TẠO LẠI BÀI MỚI CÙNG SLUG GỐC KHÔNG BỊ XUNG ĐỘT UNIQUE
// =============================================================================
echo "\n--- TEST 3: Tạo bài mới cùng Slug gốc (Chống xung đột UNIQUE KEY) ---\n";
$createSuccess = false;
try {
    $pdo->prepare("
        INSERT INTO problems (slug, title, topic, difficulty, created_by, is_public)
        VALUES (?, 'Bài toán Mới Trùng Slug', 'Quy hoạch động', 'Medium', ?, 1)
    ")->execute([$origSlug, $instId]);
    $probIdB = (int)$pdo->lastInsertId();
    $createSuccess = true;
    echo "  [PASS] Tạo bài mới (#{$probIdB}) với cùng slug '{$origSlug}' thành công mỹ mãn! Không bị lỗi trùng lặp UNIQUE.\n";
} catch (PDOException $e) {
    echo "  [FAIL] Bị lỗi xung đột UNIQUE slug: " . $e->getMessage() . "\n";
    exit(1);
}

// =============================================================================
// PHÉP THỬ 4: KHÔI PHỤC BÀI TẬP (RESTORE) KHI SLUG CŨ ĐÃ BỊ CHIẾM
// =============================================================================
echo "\n--- TEST 4: Khôi phục bài tập từ Thùng rác (Restore) ---\n";
$resRestore = callDeleteApi($baseUrl, $cookieFileInst, 'restore', $probIdA);
if ($resRestore['status'] === 200 && !empty($resRestore['data']['success'])) {
    $chkB = $pdo->query("SELECT slug, deleted_at FROM problems WHERE id = {$probIdA}")->fetch(PDO::FETCH_ASSOC);
    if ($chkB['deleted_at'] === null && strpos($chkB['slug'], 'restored') !== false) {
        echo "  [PASS] Khôi phục thành công! Hệ thống phát hiện slug cũ '{$origSlug}' đã bị chiếm và tự động cấp slug an toàn: '{$chkB['slug']}'.\n";
    } else {
        echo "  [FAIL] Trạng thái sau khôi phục không đúng!\n";
        exit(1);
    }
} else {
    echo "  [FAIL] Gọi API restore thất bại: " . print_r($resRestore, true) . "\n";
    exit(1);
}

// Dọn dẹp probIdA và probIdB
$pdo->prepare("DELETE FROM problems WHERE id IN (?, ?)")->execute([$probIdA, $probIdB]);

// =============================================================================
// PHÉP THỬ 5: BẢO VỆ BÀI TẬP ĐÃ CÓ BÀI NỘP (CHẶN HARD DELETE)
// =============================================================================
echo "\n--- TEST 5: Bảo vệ toàn vẹn dữ liệu khi bài tập đã có bài nộp ---\n";
// Tạo bài tập có bài nộp giả lập
$slugWithSub = 'test-protect-' . time();
$pdo->prepare("
    INSERT INTO problems (slug, title, topic, difficulty, created_by, is_public)
    VALUES (?, 'Bài tập có bài nộp', 'Toán học', 'Easy', ?, 1)
")->execute([$slugWithSub, $adminId]);
$probSubId = (int)$pdo->lastInsertId();

// Tạo phiên bản v1
$pdo->prepare("
    INSERT INTO problem_versions (problem_id, version_number, description, input_description, output_description, constraints, changelog, time_limit_ms, memory_limit_kb, created_by)
    VALUES (?, 1, 'Mô tả test', 'Input test', 'Output test', '1 <= N <= 100', 'Initial', 1000, 262144, ?)
")->execute([$probSubId, $adminId]);
$vId = (int)$pdo->lastInsertId();
$pdo->prepare("UPDATE problems SET current_version_id = ? WHERE id = ?")->execute([$vId, $probSubId]);

// Tạo bài nộp
$pdo->prepare("
    INSERT INTO submissions (user_id, problem_id, problem_version_id, language, source_code, final_verdict)
    VALUES (?, ?, ?, 'python', 'print(1)', 'AC')
")->execute([$adminId, $probSubId, $vId]);
$subId = (int)$pdo->lastInsertId();

// Thử gọi Hard Delete trên bài tập đã có bài nộp -> Phải bị từ chối 400!
$resHardFail = callDeleteApi($baseUrl, $cookieFileAdmin, 'hard_delete', $probSubId);
if ($resHardFail['status'] === 400 && empty($resHardFail['data']['success'])) {
    echo "  [PASS] Chặn Hard Delete thành công: {$resHardFail['data']['error']}\n";
} else {
    echo "  [FAIL] Hệ thống không chặn Hard Delete trên bài đã có bài nộp!\n";
    exit(1);
}

// Xóa mềm bài tập này -> Cho phép
$resSoftSuccess = callDeleteApi($baseUrl, $cookieFileAdmin, 'soft_delete', $probSubId);
if ($resSoftSuccess['status'] === 200 && !empty($resSoftSuccess['data']['success'])) {
    // Kiểm tra bài nộp của học viên trong bảng submissions vẫn nguyên vẹn
    $subChk = $pdo->query("SELECT id, final_verdict FROM submissions WHERE id = {$subId}")->fetch(PDO::FETCH_ASSOC);
    if (!empty($subChk) && $subChk['final_verdict'] === 'AC') {
        echo "  [PASS] Xóa mềm bài tập có bài nộp thành công: Bản ghi bài nộp #{$subId} và điểm số AC vẫn được bảo toàn 100%!\n";
    } else {
        echo "  [FAIL] Bài nộp bị mất mát sau khi xóa mềm!\n";
        exit(1);
    }
} else {
    echo "  [FAIL] Xóa mềm bài tập có bài nộp thất bại!\n";
    exit(1);
}

// Dọn dẹp
$pdo->prepare("DELETE FROM submissions WHERE id = ?")->execute([$subId]);
$pdo->prepare("DELETE FROM problem_versions WHERE id = ?")->execute([$vId]);
$pdo->prepare("DELETE FROM problems WHERE id = ?")->execute([$probSubId]);

// =============================================================================
// PHÉP THỬ 6: XÓA VĨNH VIỄN (HARD DELETE) AN TOÀN KHI 0 BÀI NỘP
// =============================================================================
echo "\n--- TEST 6: Xóa vĩnh viễn (Hard Delete) bài tập khi 0 bài nộp ---\n";
$slugHard = 'test-hard-' . time();
$pdo->prepare("
    INSERT INTO problems (slug, title, topic, difficulty, created_by, is_public)
    VALUES (?, 'Bài tập xóa vĩnh viễn', 'Mảng', 'Easy', ?, 1)
")->execute([$slugHard, $adminId]);
$probHardId = (int)$pdo->lastInsertId();

$resHard = callDeleteApi($baseUrl, $cookieFileAdmin, 'hard_delete', $probHardId);
if ($resHard['status'] === 200 && !empty($resHard['data']['success'])) {
    $exists = $pdo->query("SELECT COUNT(*) FROM problems WHERE id = {$probHardId}")->fetchColumn();
    if ((int)$exists === 0) {
        echo "  [PASS] Xóa vĩnh viễn thành công! Dữ liệu bài tập đã được dọn sạch hoàn toàn khỏi CSDL.\n";
    } else {
        echo "  [FAIL] Bản ghi vẫn còn tồn tại trong CSDL!\n";
        exit(1);
    }
} else {
    echo "  [FAIL] Xóa vĩnh viễn thất bại: " . print_r($resHard, true) . "\n";
    exit(1);
}

// Xóa file cookie
@unlink($cookieFileAdmin);
@unlink($cookieFileInst);
@unlink($cookieFileLearn);

echo "\n============================================================\n";
echo "   TẤT CẢ PHÉP THỬ XÓA & KHÔI PHỤC BÀI TẬP ĐÃ PASS 100%!    \n";
echo "============================================================\n";
