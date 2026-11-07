<?php
/**
 * ByteFlow Online Judge - Kịch bản Nghiệm thu Giai đoạn 4
 * Kiểm tra toàn diện UI, Phân quyền giao diện và Luồng người dùng
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../core/Auth.php';

echo "====================================================================\n";
echo "   BYTEFLOW ONLINE JUDGE - NGHIỆM THU GIAI ĐOẠN 4 (GIAO DIỆN)\n";
echo "====================================================================\n\n";

$passed = 0;
$total = 0;

function assertCondition(bool $cond, string $msg): void {
    global $passed, $total;
    $total++;
    if ($cond) {
        $passed++;
        echo "  [PASS] {$msg}\n";
    } else {
        echo "  [FAIL] {$msg}\n";
    }
}

// 1. Kiểm tra tồn tại các file UI cốt lõi
echo "1. Kiểm tra cấu trúc tệp Giao diện & Assets:\n";
$requiredFiles = [
    'assets/css/style.css',
    'includes/header.php',
    'includes/sidebar.php',
    'includes/footer.php',
    'index.php',
    'login.php',
    'problems.php',
    'solve.php',
    'submissions.php',
    'rejudge_compare.php',
    'manage_problems.php'
];

foreach ($requiredFiles as $file) {
    assertCondition(file_exists(__DIR__ . '/../' . $file), "Tồn tại tệp giao diện: {$file}");
}

// 2. Kiểm tra CSS Dark Glassmorphism
echo "\n2. Kiểm tra nội dung CSS Dark Glassmorphism:\n";
$cssContent = file_get_contents(__DIR__ . '/../assets/css/style.css');
assertCondition(str_contains($cssContent, '#050507'), "CSS định nghĩa màu nền đen sâu (#050507)");
assertCondition(str_contains($cssContent, '#00FFFF'), "CSS định nghĩa màu nhấn neon cyan (#00FFFF)");
assertCondition(str_contains($cssContent, 'glass-panel'), "CSS định nghĩa lớp hiệu ứng kính mờ .glass-panel");

// 3. Kiểm tra HTTP Status & Tích hợp trên Web Server XAMPP
echo "\n3. Kiểm tra phản hồi HTTP từ Web Server Apache:\n";
$baseUrl = "http://localhost/BaiTapLon";

function testHttpUrl(string $url): array {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    $body = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['code' => $httpCode, 'body' => (string)$body];
}

$resLogin = testHttpUrl("{$baseUrl}/login.php");
assertCondition($resLogin['code'] === 200 && str_contains($resLogin['body'], 'ByteFlow'), "Trang đăng nhập login.php trả về HTTP 200");

$resProblems = testHttpUrl("{$baseUrl}/problems.php");
assertCondition($resProblems['code'] === 200 && str_contains($resProblems['body'], 'two-sum'), "Trang thư viện bài tập problems.php trả về HTTP 200 và chứa bài two-sum");

$resSolve = testHttpUrl("{$baseUrl}/solve.php?slug=two-sum");
assertCondition($resSolve['code'] === 200 && str_contains($resSolve['body'], 'code-editor'), "Trang workspace solve.php trả về HTTP 200 và tích hợp trình soạn thảo code-editor");

// 4. Kiểm tra bảo vệ phân quyền trên trang quản trị
echo "\n4. Kiểm tra phân quyền truy cập trang Quản trị (manage_problems.php & rejudge_compare.php):\n";
$resManageAnon = testHttpUrl("{$baseUrl}/manage_problems.php");
// Người dùng chưa đăng nhập sẽ bị chuyển hướng sang login.php (HTTP 302)
assertCondition(in_array($resManageAnon['code'], [302, 403]), "Khách vãng lai bị chặn/chuyển hướng khi vào manage_problems.php (HTTP {$resManageAnon['code']})");

$resRejudgeAnon = testHttpUrl("{$baseUrl}/rejudge_compare.php");
assertCondition(in_array($resRejudgeAnon['code'], [302, 403]), "Khách vãng lai bị chặn/chuyển hướng khi vào rejudge_compare.php (HTTP {$resRejudgeAnon['code']})");

// 5. Kiểm tra dữ liệu bài tập và bộ test trên CSDL
echo "\n5. Kiểm tra khả năng hiển thị bộ test và phiên bản:\n";
$pdo = getDbConnection();
$probCount = (int)$pdo->query("SELECT COUNT(*) FROM problems")->fetchColumn();
$verCount = (int)$pdo->query("SELECT COUNT(*) FROM problem_versions")->fetchColumn();
$testCount = (int)$pdo->query("SELECT COUNT(*) FROM test_cases")->fetchColumn();

assertCondition($probCount >= 10, "CSDL có ít nhất 10 bài tập sẵn sàng hiển thị (hiện có: {$probCount})");
assertCondition($verCount >= 10, "CSDL có ít nhất 10 phiên bản bài tập (hiện có: {$verCount})");
assertCondition($testCount >= 50, "CSDL có bộ test cases đầy đủ (hiện có: {$testCount})");

echo "\n--------------------------------------------------------------------\n";
echo "KẾT QUẢ NGHIỆM THU GIAI ĐOẠN 4: {$passed}/{$total} TIÊU CHÍ ĐẠT (" . round(($passed/$total)*100, 1) . "%)\n";
echo "====================================================================\n";

if ($passed === $total) {
    echo ">>> XÁC NHẬN: GIAI ĐOẠN 4 ĐÃ HOÀN THÀNH XUẤT SẮC 100%! <<<\n";
    exit(0);
} else {
    echo ">>> CẢNH BÁO: CÓ TIÊU CHÍ CHƯA ĐẠT! <<<\n";
    exit(1);
}
