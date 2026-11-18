<?php
/**
 * ==============================================================================
 * ByteFlow Online Judge - Cấu hình kết nối Cơ sở dữ liệu (Database Config)
 * ==============================================================================
 * Mục đích của file này:
 * - Khai báo các thông số kết nối đến hệ quản trị cơ sở dữ liệu MySQL (host, port, tên DB, user, mật khẩu...).
 * - Cung cấp hàm getDbConnection() trả về đối tượng PDO dùng chung cho toàn bộ website.
 * - Sử dụng PDO với Prepared Statements thật để chống triệt để lỗ hổng tấn công SQL Injection.
 */

// Bật chế độ kiểm tra kiểu dữ liệu nghiêm ngặt (Strict Types) trong PHP:
// Nếu hàm yêu cầu tham số/kiểu trả về là kiểu gì (VD: PDO, int, string), PHP sẽ kiểm tra chính xác kiểu đó.
declare(strict_types=1);

// ==============================================================================
// PHẦN 1: KHAI BÁO CÁC HẰNG SỐ THÔNG SỐ KẾT NỐI MYSQL (MẶC ĐỊNH CHO XAMPP)
// ==============================================================================
// Giải thích hàm getenv('TÊN_BIẾN'): đọc giá trị từ biến môi trường của hệ điều hành (nếu có cấu hình trên server/Docker).
// Toán tử `?:` (Elvis operator): nếu giá trị bên trái rỗng/false thì lấy giá trị mặc định bên phải.

// Địa chỉ máy chủ MySQL (trên máy cá nhân XAMPP mặc định là 'localhost')
define('DB_HOST', getenv('DB_HOST') ?: 'localhost');

// Cổng kết nối của dịch vụ MySQL (mặc định là 3306)
define('DB_PORT', getenv('DB_PORT') ?: '3306');

// Tên cơ sở dữ liệu (Database) chứa toàn bộ bảng của dự án
define('DB_NAME', getenv('DB_NAME') ?: 'db_baitaplon');

// Tên tài khoản đăng nhập MySQL (XAMPP mặc định là 'root')
define('DB_USER', getenv('DB_USER') ?: 'root');

// Mật khẩu tài khoản MySQL (XAMPP mặc định để chuỗi rỗng '').
// Dùng toán tử !== false vì mật khẩu hợp lệ có thể là chuỗi rỗng "", không được nhầm với "chưa đặt biến môi trường".
define('DB_PASS', getenv('DB_PASS') !== false ? getenv('DB_PASS') : '');

// Bảng mã ký tự utf8mb4: hỗ trợ đầy đủ tiếng Việt có dấu và cả biểu tượng cảm xúc (Emoji)
define('DB_CHARSET', 'utf8mb4');

// ==============================================================================
// PHẦN 2: HÀM LẤY KẾT NỐI PDO THEO MẪU THIẾT KẾ SINGLETON
// ==============================================================================
/**
 * Lấy đối tượng kết nối PDO (Singleton)
 * - Singleton nghĩa là trong suốt 1 lượt tải trang (request), hệ thống chỉ mở duy nhất 1 kết nối DB
 *   và tái sử dụng lại, giúp tiết kiệm RAM và tránh quá tải MySQL.
 *
 * @return PDO Đối tượng PDO đã kết nối sẵn sàng để chạy câu lệnh SQL
 * @throws PDOException / RuntimeException Ném ra ngoại lệ nếu kết nối thất bại
 */
function getDbConnection(): PDO
{
    // Biến static giữ nguyên giá trị giữa các lần gọi hàm getDbConnection() trong cùng 1 request
    static $pdo = null;

    // Chỉ khởi tạo kết nối mới khi biến $pdo chưa tồn tại (lần gọi đầu tiên)
    if ($pdo === null) {
        // Tạo chuỗi DSN (Data Source Name) mô tả địa chỉ, cổng, tên DB và bảng mã cho PDO biết cần kết nối đi đâu.
        // Hàm sprintf() thay thế các ký tự %s lần lượt bằng các hằng số phía sau.
        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=%s',
            DB_HOST,
            DB_PORT,
            DB_NAME,
            DB_CHARSET
        );

        // Mảng cấu hình các tùy chọn nâng cao cho kết nối PDO
        $options = [
            // Khi truy vấn SQL gặp lỗi, tự động ném ra ngoại lệ (PDOException) để dễ bắt lỗi bằng try...catch
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            // Khi lấy dữ liệu (fetch), mặc định trả về mảng kết hợp (tên cột => giá trị), gọn gàng và dễ đọc
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            // Tắt chế độ giả lập Prepared Statements -> ép MySQL biên dịch câu lệnh tham số hóa thật sự (chống SQL Injection tốt hơn)
            PDO::ATTR_EMULATE_PREPARES   => false,
            // Lệnh SQL chạy ngay khi vừa kết nối: đảm bảo đường truyền sử dụng chuẩn utf8mb4 tiếng Việt không phân biệt hoa thường
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci"
        ];

        // Dùng khối try...catch để bắt lỗi kết nối an toàn, không làm lộ thông tin nhạy cảm lên trình duyệt
        try {
            // Khởi tạo đối tượng kết nối PDO thật sự tới MySQL
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        } catch (PDOException $e) {
            // Ghi chi tiết lỗi kỹ thuật vào file log của máy chủ (Apache/PHP) để lập trình viên kiểm tra
            // Không lộ mật khẩu hoặc cấu hình nhạy cảm ra ngoài
            error_log('Database Connection Error: ' . $e->getMessage());
            // Ném ra thông báo lỗi thân thiện bằng tiếng Việt cho người dùng / tầng xử lý phía trên
            throw new RuntimeException('Không thể kết nối cơ sở dữ liệu. Vui lòng kiểm tra lại dịch vụ MySQL.');
        }
    }

    // Trả về đối tượng kết nối PDO (mới tạo hoặc đã tạo sẵn từ trước)
    return $pdo;
}