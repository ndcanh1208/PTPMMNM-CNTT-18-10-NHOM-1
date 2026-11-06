<?php
/**
 * ByteFlow Online Judge - Cấu hình kết nối Cơ sở dữ liệu
 * Sử dụng PDO với Prepared Statements để chống triệt để SQL Injection
 */

declare(strict_types=1);

// Cấu hình thông số DB XAMPP mặc định
define('DB_HOST', getenv('DB_HOST') ?: 'localhost');
define('DB_PORT', getenv('DB_PORT') ?: '3306');
define('DB_NAME', getenv('DB_NAME') ?: 'db_baitaplon');
define('DB_USER', getenv('DB_USER') ?: 'root');
define('DB_PASS', getenv('DB_PASS') !== false ? getenv('DB_PASS') : '');
define('DB_CHARSET', 'utf8mb4');

/**
 * Lấy đối tượng kết nối PDO (Singleton)
 *
 * @return PDO
 * @throws PDOException
 */
function getDbConnection(): PDO
{
    static $pdo = null;

    if ($pdo === null) {
        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=%s',
            DB_HOST,
            DB_PORT,
            DB_NAME,
            DB_CHARSET
        );

        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci"
        ];

        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        } catch (PDOException $e) {
            // Không lộ mật khẩu hoặc cấu hình nhạy cảm ra ngoài
            error_log('Database Connection Error: ' . $e->getMessage());
            throw new RuntimeException('Không thể kết nối cơ sở dữ liệu. Vui lòng kiểm tra lại dịch vụ MySQL.');
        }
    }

    return $pdo;
}
