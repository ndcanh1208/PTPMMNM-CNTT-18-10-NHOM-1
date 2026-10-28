<?php
/**
 * TỆP KẾT NỐI CƠ SỞ DỮ LIỆU MYSQL DÙNG PDO
 * Đề tài 20: Hệ thống luyện tập và chấm bài lập trình trực tuyến
 */

define('DB_HOST', '127.0.0.1');
define('DB_PORT', '3306');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'db_baitaplon');

class Database {
    private static ?PDO $instance = null;

    public static function getConnection(): PDO {
        if (self::$instance === null) {
            $dsn = "mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME . ";charset=utf8mb4";
            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4"
            ];
            try {
                self::$instance = new PDO($dsn, DB_USER, DB_PASS, $options);
            } catch (PDOException $e) {
                die("Lỗi kết nối CSDL: " . $e->getMessage() . "<br>Hãy kiểm tra dịch vụ MySQL trên XAMPP và chắc chắn bạn đã chạy file database/install.php");
            }
        }
        return self::$instance;
    }
}
