<?php
/**
 * ==============================================================================
 * ByteFlow Online Judge - Script khởi tạo & nạp dữ liệu mẫu tự động (Seed Script)
 * ==============================================================================
 * Cách chạy qua cửa sổ dòng lệnh (CLI / Terminal):
 *   php database/seed.php
 * 
 * Mục đích của file này:
 * - Giúp người cài đặt dự án lần đầu tự động tạo Database MySQL nếu chưa tồn tại.
 * - Tự động chạy file `schema.sql` để tạo toàn bộ cấu trúc bảng dữ liệu.
 * - Tự động chạy file `seed.sql` để nạp sẵn dữ liệu mẫu (tài khoản, lớp học, 10 bài tập, bộ test).
 * - In ra màn hình thống kê số lượng bản ghi sau khi nạp xong để kiểm chứng.
 */

// Bật chế độ kiểm tra kiểu dữ liệu nghiêm ngặt trong PHP
declare(strict_types=1);

// Nạp file cấu hình CSDL để dùng các hằng số DB_HOST, DB_PORT, DB_NAME, DB_USER, DB_PASS và hàm getDbConnection()
require_once __DIR__ . '/../config/database.php';

// In tiêu đề mở đầu trên màn hình dòng lệnh (Terminal)
echo "========================================================\n";
echo "   BYTEFLOW ONLINE JUDGE - SEED DATABASE SCRIPT        \n";
echo "========================================================\n";

// Bọc toàn bộ quy trình vào khối try...catch để nếu có bất kỳ lỗi nào (sai mật khẩu, thiếu file SQL, lỗi cú pháp SQL...)
// chương trình sẽ in thông báo lỗi gọn gàng và thoát với mã lỗi 1.
try {
    // ==========================================================================
    // BƯỚC 1: KẾT NỐI ĐẾN MÁY CHỦ MYSQL (CHƯA CHỌN DB) ĐỂ TẠO DATABASE NẾU CHƯA CÓ
    // ==========================================================================
    // Tạo chuỗi DSN không chứa `dbname=...` vì lúc mới cài đặt thì database `db_baitaplon` có thể chưa tồn tại.
    // 1. Kết nối không cần chỉ định database để tạo DB nếu chưa có
    $rootDsn = sprintf('mysql:host=%s;port=%s;charset=%s', DB_HOST, DB_PORT, DB_CHARSET);
    // Mở kết nối PDO tạm tới máy chủ MySQL và bật chế độ ném ngoại lệ khi gặp lỗi SQL
    $rootPdo = new PDO($rootDsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
    ]);
    
    echo "[1/4] Đảm bảo cơ sở dữ liệu '" . DB_NAME . "' tồn tại...\n";
    // Thực thi lệnh SQL tạo database (IF NOT EXISTS giúp tránh báo lỗi nếu database đã có sẵn từ trước)
    // Bộ mã utf8mb4 + utf8mb4_unicode_ci đảm bảo lưu chuẩn tiếng Việt có dấu
    $rootPdo->exec("CREATE DATABASE IF NOT EXISTS `" . DB_NAME . "` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

    // ==========================================================================
    // BƯỚC 2: KẾT NỐI CHÍNH THỨC VÀO DATABASE VỪA TẠO
    // ==========================================================================
    // 2. Kết nối tới database chính
    $pdo = getDbConnection();

    // ==========================================================================
    // BƯỚC 3: ĐỌC VÀ THỰC THI FILE `schema.sql` ĐỂ TẠO CÁC BẢNG (TABLES)
    // ==========================================================================
    // 3. Thực thi schema.sql
    echo "[2/4] Đang khởi tạo cấu trúc bảng từ schema.sql...\n";
    // Đường dẫn đầy đủ đến file database/schema.sql (dựa trên thư mục hiện tại __DIR__)
    $schemaFile = __DIR__ . '/schema.sql';
    // Kiểm tra file có tồn tại hay không trước khi đọc; nếu thiếu thì ném lỗi dừng chương trình
    if (!file_exists($schemaFile)) {
        throw new RuntimeException("Không tìm thấy file {$schemaFile}");
    }
    // Đọc toàn bộ nội dung văn bản SQL trong file schema.sql vào biến chuỗi $schemaSql
    $schemaSql = file_get_contents($schemaFile);
    // Chạy toàn bộ các câu lệnh CREATE TABLE / ALTER TABLE trong schema.sql thông qua $pdo->exec()
    $pdo->exec($schemaSql);
    echo "      -> Cấu trúc bảng (10 bảng) đã được khởi tạo thành công.\n";

    // ==========================================================================
    // BƯỚC 4: ĐỌC VÀ THỰC THI FILE `seed.sql` ĐỂ NẠP DỮ LIỆU MẪU
    // ==========================================================================
    // 4. Thực thi seed.sql
    echo "[3/4] Đang nạp dữ liệu mẫu từ seed.sql...\n";
    // Đường dẫn đầy đủ đến file database/seed.sql
    $seedFile = __DIR__ . '/seed.sql';
    // Kiểm tra sự tồn tại của file seed.sql
    if (!file_exists($seedFile)) {
        throw new RuntimeException("Không tìm thấy file {$seedFile}");
    }
    // Đọc toàn bộ câu lệnh INSERT dữ liệu mẫu trong seed.sql
    $seedSql = file_get_contents($seedFile);
    // Thực thi các câu lệnh INSERT để đổ dữ liệu vào các bảng
    $pdo->exec($seedSql);
    echo "      -> Dữ liệu mẫu (Users, Classrooms, 10 Problems, Testcases) đã nạp thành công.\n";

    // ==========================================================================
    // BƯỚC 5: TRUY VẤN ĐẾM SỐ BẢN GHI VÀ IN BÁO CÁO THỐNG KÊ RA MÀN HÌNH
    // ==========================================================================
    // 5. Kiểm tra và thống kê
    echo "[4/4] Thống kê dữ liệu đã nạp:\n";
    // Mỗi câu lệnh SELECT COUNT(*) trả về tổng số dòng trong bảng tương ứng; fetchColumn() lấy ra đúng con số đó
    $userCount = $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();                                  // Tổng số người dùng
    $classCount = $pdo->query("SELECT COUNT(*) FROM classrooms")->fetchColumn();                            // Tổng số lớp học
    $probCount = $pdo->query("SELECT COUNT(*) FROM problems")->fetchColumn();                               // Tổng số bài tập
    $versionCount = $pdo->query("SELECT COUNT(*) FROM problem_versions")->fetchColumn();                    // Tổng số phiên bản đề bài
    $testCount = $pdo->query("SELECT COUNT(*) FROM test_cases")->fetchColumn();                             // Tổng số ca kiểm thử (test case)
    $sampleTestCount = $pdo->query("SELECT COUNT(*) FROM test_cases WHERE is_sample = 1")->fetchColumn();   // Số test ví dụ công khai cho sinh viên xem
    $hiddenTestCount = $pdo->query("SELECT COUNT(*) FROM test_cases WHERE is_hidden = 1")->fetchColumn();   // Số test ẩn dùng để chấm kín

    // In chi tiết từng số liệu thống kê ra cửa sổ dòng lệnh
    echo "      - Số lượng Người dùng: {$userCount}\n";
    echo "      - Số lượng Lớp học: {$classCount}\n";
    echo "      - Số lượng Bài tập: {$probCount}\n";
    echo "      - Số lượng Phiên bản bài tập: {$versionCount}\n";
    echo "      - Tổng số Ca kiểm thử: {$testCount} (Ví dụ công khai: {$sampleTestCount}, Chấm kín: {$hiddenTestCount})\n";
    echo "\n=> KHỞI TẠO CƠ SỞ DỮ LIỆU HOÀN TẤT THÀNH CÔNG!\n";
    echo "========================================================\n";

} catch (Throwable $e) {
    // Throwable bắt được cả Exception lẫn Error nghiêm trọng trong PHP 7+/8+
    // Hiển thị thông báo lỗi rõ ràng ra terminal và thoát với mã trạng thái 1 (quy ước lỗi trong CLI)
    echo "\n[LỖI]: " . $e->getMessage() . "\n";
    exit(1);
}