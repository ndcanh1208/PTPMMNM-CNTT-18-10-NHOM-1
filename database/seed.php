<?php
/**
 * ByteFlow Online Judge - Script khởi tạo & nạp dữ liệu mẫu tự động
 * Chạy qua CLI: php database/seed.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

echo "========================================================\n";
echo "   BYTEFLOW ONLINE JUDGE - SEED DATABASE SCRIPT        \n";
echo "========================================================\n";

try {
    // 1. Kết nối không cần chỉ định database để tạo DB nếu chưa có
    $rootDsn = sprintf('mysql:host=%s;port=%s;charset=%s', DB_HOST, DB_PORT, DB_CHARSET);
    $rootPdo = new PDO($rootDsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
    ]);
    
    echo "[1/4] Đảm bảo cơ sở dữ liệu '" . DB_NAME . "' tồn tại...\n";
    $rootPdo->exec("CREATE DATABASE IF NOT EXISTS `" . DB_NAME . "` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

    // 2. Kết nối tới database chính
    $pdo = getDbConnection();

    // 3. Thực thi schema.sql
    echo "[2/4] Đang khởi tạo cấu trúc bảng từ schema.sql...\n";
    $schemaFile = __DIR__ . '/schema.sql';
    if (!file_exists($schemaFile)) {
        throw new RuntimeException("Không tìm thấy file {$schemaFile}");
    }
    $schemaSql = file_get_contents($schemaFile);
    $pdo->exec($schemaSql);
    echo "      -> Cấu trúc bảng (10 bảng) đã được khởi tạo thành công.\n";

    // 4. Thực thi seed.sql
    echo "[3/4] Đang nạp dữ liệu mẫu từ seed.sql...\n";
    $seedFile = __DIR__ . '/seed.sql';
    if (!file_exists($seedFile)) {
        throw new RuntimeException("Không tìm thấy file {$seedFile}");
    }
    $seedSql = file_get_contents($seedFile);
    $pdo->exec($seedSql);
    echo "      -> Dữ liệu mẫu (Users, Classrooms, 10 Problems, Testcases) đã nạp thành công.\n";

    // 5. Kiểm tra và thống kê
    echo "[4/4] Thống kê dữ liệu đã nạp:\n";
    $userCount = $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
    $classCount = $pdo->query("SELECT COUNT(*) FROM classrooms")->fetchColumn();
    $probCount = $pdo->query("SELECT COUNT(*) FROM problems")->fetchColumn();
    $versionCount = $pdo->query("SELECT COUNT(*) FROM problem_versions")->fetchColumn();
    $testCount = $pdo->query("SELECT COUNT(*) FROM test_cases")->fetchColumn();
    $sampleTestCount = $pdo->query("SELECT COUNT(*) FROM test_cases WHERE is_sample = 1")->fetchColumn();
    $hiddenTestCount = $pdo->query("SELECT COUNT(*) FROM test_cases WHERE is_hidden = 1")->fetchColumn();

    echo "      - Số lượng Người dùng: {$userCount}\n";
    echo "      - Số lượng Lớp học: {$classCount}\n";
    echo "      - Số lượng Bài tập: {$probCount}\n";
    echo "      - Số lượng Phiên bản bài tập: {$versionCount}\n";
    echo "      - Tổng số Ca kiểm thử: {$testCount} (Ví dụ công khai: {$sampleTestCount}, Chấm kín: {$hiddenTestCount})\n";
    echo "\n=> KHỞI TẠO CƠ SỞ DỮ LIỆU HOÀN TẤT THÀNH CÔNG!\n";
    echo "========================================================\n";

} catch (Throwable $e) {
    echo "\n[LỖI]: " . $e->getMessage() . "\n";
    exit(1);
}
