<?php
/**
 * TRÌNH CÀI ĐẶT & KHỞI TẠO CƠ SỞ DỮ LIỆU TỰ ĐỘNG (1-CLICK INSTALLER)
 * Đề tài 20: Hệ thống luyện tập và chấm bài trực tuyến
 */

header('Content-Type: text/html; charset=utf-8');

$host = '127.0.0.1';
$user = 'root';
$pass = '';
$dbName = 'db_baitaplon';

$isCli = (php_sapi_name() === 'cli');

function logMsg($msg, $type = 'info') {
    global $isCli;
    if ($isCli) {
        $prefix = ($type === 'success') ? '[SUCCESS] ' : (($type === 'error') ? '[ERROR] ' : '[INFO] ');
        echo $prefix . strip_tags($msg) . PHP_EOL;
    } else {
        $color = ($type === 'success') ? '#10b981' : (($type === 'error') ? '#ef4444' : '#6366f1');
        echo "<div style='color: {$color}; margin: 6px 0; font-family: monospace; font-size: 14px;'>{$msg}</div>";
    }
}

if (!$isCli) {
    echo "<!DOCTYPE html><html><head><meta charset='utf-8'><title>Khởi tạo CSDL - Đề tài 20</title>
    <style>
        body { background: #0b0f19; color: #f3f4f6; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; padding: 40px; }
        .card { background: #111827; border: 1px solid rgba(255,255,255,0.1); border-radius: 12px; padding: 24px; max-width: 800px; margin: 0 auto; box-shadow: 0 10px 25px rgba(0,0,0,0.5); }
        h1 { font-size: 20px; margin-bottom: 20px; border-bottom: 1px solid #374151; padding-bottom: 12px; color: #818cf8; }
        .btn { display: inline-block; background: #4f46e5; color: white; padding: 10px 20px; border-radius: 6px; text-decoration: none; margin-top: 20px; font-weight: bold; }
        .btn:hover { background: #4338ca; }
    </style></head><body><div class='card'><h1>🚀 Bắt Đầu Khởi Tạo CSDL `db_baitaplon`</h1>";
}

try {
    // 1. Kết nối MySQL server
    $pdo = new PDO("mysql:host={$host}", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4"
    ]);
    logMsg("✓ Kết nối máy chủ MySQL thành công trên {$host}!", 'success');

    // 2. Chạy schema.sql
    $schemaFile = __DIR__ . '/schema.sql';
    if (!file_exists($schemaFile)) {
        throw new Exception("Không tìm thấy tệp schema.sql tại: {$schemaFile}");
    }
    
    logMsg("Đang đọc và thực thi cấu trúc bảng từ schema.sql...");
    $sqlSchema = file_get_contents($schemaFile);
    $pdo->exec($sqlSchema);
    logMsg("✓ Đã khởi tạo hoàn tất cấu trúc 13 bảng trong CSDL `{$dbName}`!", 'success');

    // 3. Kết nối thẳng vào db vừa tạo
    $pdo->exec("USE `{$dbName}`;");

    // 4. Chạy seed_data.sql
    $seedFile = __DIR__ . '/seed_data.sql';
    if (file_exists($seedFile)) {
        logMsg("Đang nạp dữ liệu mẫu từ seed_data.sql (10 bài tập, 50+ test cases, tài khoản)...");
        $sqlSeed = file_get_contents($seedFile);
        $pdo->exec($sqlSeed);
        logMsg("✓ Đã nạp thành công 10 bài tập, 50+ test cases, tài khoản và ngôn ngữ mẫu!", 'success');
    }

    // 5. Thống kê nhanh dữ liệu vừa tạo
    $stmtUser = $pdo->query("SELECT COUNT(*) FROM users");
    $countUser = $stmtUser->fetchColumn();

    $stmtProb = $pdo->query("SELECT COUNT(*) FROM problems");
    $countProb = $stmtProb->fetchColumn();

    $stmtTest = $pdo->query("SELECT COUNT(*) FROM test_cases");
    $countTest = $stmtTest->fetchColumn();

    logMsg("----------------------------------------------------------------", 'info');
    logMsg("🎉 <strong>TỔNG KẾT DỮ LIỆU ĐÃ TẠO:</strong>", 'success');
    logMsg("- Người dùng: {$countUser} tài khoản (Admin, Giảng viên, Sinh viên)");
    logMsg("- Bài tập: {$countProb} bài toán thuật toán");
    logMsg("- Test cases: {$countTest} bộ dữ liệu kiểm tra (có test mẫu & test kín)");
    logMsg("----------------------------------------------------------------", 'info');

    if (!$isCli) {
        echo "<a href='/BaiTapLon/' class='btn'>← Quay lại Trang Chủ Dự Án</a>";
    }

} catch (PDOException $e) {
    logMsg("Lỗi kết nối MySQL: " . $e->getMessage(), 'error');
    logMsg("Gợi ý: Hãy đảm bảo bạn đã bấm nút <strong>Start</strong> ở dịch vụ MySQL trong XAMPP Control Panel!", 'error');
} catch (Exception $e) {
    logMsg("Lỗi: " . $e->getMessage(), 'error');
}

if (!$isCli) {
    echo "</div></body></html>";
}
