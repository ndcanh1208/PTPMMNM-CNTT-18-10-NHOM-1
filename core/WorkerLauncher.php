<?php
/**
 * Nhom1 Online Judge - Bộ Kích Hoạt Worker Chạy Ngầm Tự Động (WorkerLauncher)
 *
 * Mục đích của tệp này:
 * - Giúp hệ thống tự động khởi chạy tiến trình chấm bài độc lập (`core/worker.php --drain`)
 *   ngay khi người dùng bấm Nộp bài hoặc Chấm lại mà KHÔNG cần quản trị viên mở terminal chạy tay.
 * - Tuân thủ 100% Nguyên tắc 1 (Kiến trúc tách biệt Web & Worker):
 *   + Tiến trình Web (Apache/PHP) KHÔNG tự chạy biên dịch hay thực thi mã nguồn thí sinh.
 *   + Gọi khởi chạy Worker hoàn toàn bất đồng bộ (non-blocking), trả phản hồi HTTP về trình duyệt ngay lập tức.
 *   + Sử dụng cơ chế khóa tệp mức hệ điều hành (`flock`) trên tệp `temp/worker.lock` để chống sinh trùng
 *     nhiều tiến trình Worker chạy song song lãng phí CPU/RAM.
 */

// Bật kiểm tra kiểu dữ liệu nghiêm ngặt
declare(strict_types=1);

/**
 * Lớp WorkerLauncher
 *
 * Quản lý việc kiểm tra trạng thái, tìm đường dẫn PHP CLI và kích hoạt ngầm tiến trình Worker chấm bài.
 */
class WorkerLauncher
{
    /**
     * Lấy đường dẫn tuyệt đối đến tệp khóa dùng chung (`temp/worker.lock`).
     *
     * Tệp khóa này là điểm đồng bộ giữa Web (WorkerLauncher) và Worker (JudgingWorker):
     * - Khi Worker đang chạy, nó giữ khóa độc quyền (LOCK_EX) trên tệp này.
     * - Khi Worker kết thúc hoặc bị crash đột ngột, hệ điều hành sẽ tự động giải phóng khóa này.
     *
     * @return string Đường dẫn đầy đủ đến tệp `worker.lock`.
     */
    public static function getLockFilePath(): string
    {
        // Xác định thư mục tạm temp/ nằm ở thư mục gốc dự án
        $tempDir = __DIR__ . '/../temp';
        // Tạo thư mục temp/ nếu chưa tồn tại (cờ true cho phép tạo đệ quy thư mục cha)
        if (!is_dir($tempDir)) {
            @mkdir($tempDir, 0777, true);
        }
        // DIRECTORY_SEPARATOR tự động chọn '\' trên Windows và '/' trên Linux/macOS
        return $tempDir . DIRECTORY_SEPARATOR . 'worker.lock';
    }

    /**
     * Kiểm tra xem hiện tại đã có tiến trình Worker nào đang hoạt động hay chưa.
     *
     * Cơ chế hoạt động của flock (File Locking):
     * 1. Mở tệp `worker.lock` ở chế độ 'c+' (đọc/ghi, tự tạo nếu chưa có, không xóa nội dung cũ).
     * 2. Thử xin khóa độc quyền không chặn: `flock($fp, LOCK_EX | LOCK_NB)`:
     *    - `LOCK_EX` (Exclusive Lock): khóa độc quyền, chỉ 1 tiến trình được giữ tại một thời điểm.
     *    - `LOCK_NB` (Non-Blocking): nếu tệp đang bị tiến trình khác khóa thì trả về false ngay lập tức
     *      chứ KHÔNG đứng chờ (tránh làm treo yêu cầu web).
     * 3. Nếu xin khóa THÀNH CÔNG (`$gotLock === true`): nghĩa là KHÔNG có Worker nào đang chạy
     *    -> nhả khóa ngay (`LOCK_UN`), đóng tệp và trả về false.
     * 4. Nếu xin khóa THẤT BẠI (`$gotLock === false`): nghĩa là đã có một Worker khác đang giữ khóa
     *    -> đóng tệp và trả về true.
     *
     * @return bool true nếu đã có Worker đang chạy; false nếu chưa có Worker nào chạy.
     */
    public static function isWorkerRunning(): bool
    {
        $lockPath = self::getLockFilePath();
        $fp = @fopen($lockPath, 'c+');
        if (!$fp) {
            // Không mở được tệp khóa (lỗi quyền ghi...), coi như chưa xác định được Worker đang chạy
            return false;
        }
        // Thử chiếm khóa độc quyền ở chế độ không chờ (non-blocking)
        $gotLock = flock($fp, LOCK_EX | LOCK_NB);
        if ($gotLock) {
            // Chiếm được khóa => chứng tỏ chưa có Worker nào đang giữ nó
            flock($fp, LOCK_UN);
            fclose($fp);
            return false;
        }
        // Không chiếm được khóa => đã có tiến trình Worker đang giữ khóa và đang hoạt động
        fclose($fp);
        return true;
    }

    /**
     * Tự động tìm đường dẫn tệp thực thi PHP dòng lệnh (PHP CLI: php.exe hoặc php).
     *
     * Tại sao không dùng thẳng hằng số PHP_BINARY?
     * - Khi mã PHP này được gọi từ máy chủ Web Apache (mod_php trên XAMPP Windows),
     *   hằng số `PHP_BINARY` có thể trỏ tới `C:\xampp\apache\bin\httpd.exe` thay vì `php.exe`.
     * - Nếu dùng nhầm `httpd.exe` để chạy `core/worker.php` sẽ thất bại!
     * - Do đó hàm này dò lần lượt các vị trí chuẩn của PHP CLI trên Windows và Linux/macOS.
     *
     * @return string|null Đường dẫn tệp thực thi PHP CLI nếu tìm thấy, hoặc null nếu không tìm thấy.
     */
    public static function detectPhpCli(): ?string
    {
        // Nhánh 1: Hệ điều hành Windows
        if (PHP_OS_FAMILY === 'Windows') {
            // Danh sách các vị trí thường gặp của php.exe trên Windows (XAMPP ổ C/D, thư mục PHP_BINDIR...)
            $candidates = [
                'C:\\xampp\\php\\php.exe',
                PHP_BINDIR . '\\php.exe',
                dirname(PHP_BINARY) . '\\php.exe',
                'D:\\xampp\\php\\php.exe'
            ];
            foreach ($candidates as $path) {
                if ($path && is_file($path)) {
                    return $path;
                }
            }
            // Nếu không thấy ở các đường dẫn cố định, dùng lệnh `where.exe php` để hỏi biến môi trường PATH của Windows
            // `2>NUL` dùng để ẩn thông báo lỗi nếu lệnh không tìm thấy
            $where = trim((string)@shell_exec('where.exe php 2>NUL'));
            if ($where !== '') {
                // Nếu máy cài nhiều bản PHP, `where.exe` trả về nhiều dòng -> lấy dòng đầu tiên
                $firstLine = trim(explode("\n", $where)[0]);
                if (is_file($firstLine)) {
                    return $firstLine;
                }
            }
            return null;
        }

        // Nhánh 2: Hệ điều hành Linux / macOS
        $candidates = [
            PHP_BINDIR . '/php',
            '/usr/bin/php',
            '/usr/local/bin/php'
        ];
        foreach ($candidates as $path) {
            // Kiểm tra tệp tồn tại và có quyền thực thi (is_executable)
            if ($path && is_file($path) && is_executable($path)) {
                return $path;
            }
        }
        // Dò dự phòng bằng lệnh `which php` trên Unix/Linux
        $which = trim((string)@shell_exec('which php 2>/dev/null'));
        return $which !== '' ? $which : 'php';
    }

    /**
     * Kích hoạt ngầm tiến trình Worker ở chế độ `--drain` (tự chấm sạch hàng đợi rồi tự thoát).
     *
     * Đặc điểm quan trọng:
     * - Hoàn toàn bất đồng bộ (non-blocking): trả quyền điều khiển về Apache ngay lập tức sau vài mili-giây,
     *   người dùng không phải chờ chấm xong mới nhận được phản hồi nộp bài.
     * - Chống chạy trùng: nếu `isWorkerRunning()` trả về true thì dừng ngay, không mở thêm Worker mới.
     *
     * @return bool true nếu vừa kích hoạt thành công tiến trình Worker mới;
     *              false nếu Worker đã đang chạy sẵn hoặc không tìm thấy PHP CLI / tệp worker.php.
     */
    public static function triggerAsync(): bool
    {
        // Bước 1: Kiểm tra khóa tệp; nếu đã có Worker đang chạy (dù là chạy ngầm hay mở tay) thì không sinh trùng
        if (self::isWorkerRunning()) {
            return false;
        }

        // Bước 2: Tìm đường dẫn trình thông dịch PHP CLI và đường dẫn tuyệt đối tới tệp core/worker.php
        $phpCli = self::detectPhpCli();
        $workerScript = realpath(__DIR__ . '/worker.php');
        if (!$phpCli || !$workerScript) {
            return false;
        }

        // Bước 3: Khởi chạy tiến trình ngầm tùy theo hệ điều hành
        if (PHP_OS_FAMILY === 'Windows') {
            // Giải thích lệnh Windows:
            // - `start /B ""`: chạy tiến trình tách rời hoàn toàn ở chế độ nền, không bật cửa sổ CMD mới
            //   (cặp dấu "" là tiêu đề cửa sổ rỗng bắt buộc khi đường dẫn lệnh phía sau có dấu ngoặc kép).
            // - `--drain`: tham số bảo Worker chấm hết tất cả bài đang Queued rồi tự tắt để tiết kiệm tài nguyên.
            // - `< NUL > NUL 2>&1`: đóng/chuyển hướng toàn bộ đầu vào (stdin), đầu ra (stdout) và lỗi (stderr)
            //   sang thiết bị rỗng NUL để tiến trình Apache không bị treo chờ đóng luồng I/O.
            $cmd = sprintf('start /B "" "%s" "%s" --drain < NUL > NUL 2>&1', $phpCli, $workerScript);
            // Dùng popen() rồi pclose() ngay lập tức để kích hoạt lệnh nền mà không chờ Worker chấm xong
            $proc = @popen($cmd, 'r');
            if ($proc !== false) {
                @pclose($proc);
                return true;
            }
            return false;
        }

        // Trên Linux / macOS: chuyển hướng I/O sang /dev/null và thêm dấu `&` ở cuối để chạy nền (background)
        $cmd = sprintf('"%s" "%s" --drain < /dev/null > /dev/null 2>&1 &', $phpCli, $workerScript);
        @exec($cmd);
        return true;
    }
}
