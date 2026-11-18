<?php
/**
 * Nhom1 Online Judge - Bộ Kích Hoạt Worker Chạy Ngầm Tự Động (WorkerLauncher)
 * 
 * Giúp hệ thống tự động khởi chạy tiến trình chấm bài độc lập (core/worker.php --drain)
 * ngay khi người dùng bấm Nộp bài / Chấm lại mà KHÔNG cần mở terminal chạy tay,
 * đồng thời vẫn tuân thủ 100% Nguyên tắc 1 (Tách biệt hoàn toàn khỏi tiến trình Apache,
 * không chặn phản hồi HTTP và dùng flock chống sinh trùng tiến trình).
 */

declare(strict_types=1);

class WorkerLauncher
{
    public static function getLockFilePath(): string
    {
        $tempDir = __DIR__ . '/../temp';
        if (!is_dir($tempDir)) {
            @mkdir($tempDir, 0777, true);
        }
        return $tempDir . DIRECTORY_SEPARATOR . 'worker.lock';
    }

    /**
     * Kiểm tra xem hiện đã có tiến trình Worker nào đang hoạt động hay chưa
     */
    public static function isWorkerRunning(): bool
    {
        $lockPath = self::getLockFilePath();
        $fp = @fopen($lockPath, 'c+');
        if (!$fp) {
            return false;
        }
        $gotLock = flock($fp, LOCK_EX | LOCK_NB);
        if ($gotLock) {
            flock($fp, LOCK_UN);
            fclose($fp);
            return false;
        }
        fclose($fp);
        return true;
    }

    /**
     * Tự động tìm đường dẫn tệp thực thi PHP CLI (tránh nhầm với httpd.exe của Apache trên Windows)
     */
    public static function detectPhpCli(): ?string
    {
        if (PHP_OS_FAMILY === 'Windows') {
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
            $where = trim((string)@shell_exec('where.exe php 2>NUL'));
            if ($where !== '') {
                $firstLine = trim(explode("\n", $where)[0]);
                if (is_file($firstLine)) {
                    return $firstLine;
                }
            }
            return null;
        }

        $candidates = [
            PHP_BINDIR . '/php',
            '/usr/bin/php',
            '/usr/local/bin/php'
        ];
        foreach ($candidates as $path) {
            if ($path && is_file($path) && is_executable($path)) {
                return $path;
            }
        }
        $which = trim((string)@shell_exec('which php 2>/dev/null'));
        return $which !== '' ? $which : 'php';
    }

    /**
     * Kích hoạt ngầm Worker ở chế độ --drain (tự chấm sạch hàng đợi rồi tự thoát).
     * Hoàn toàn bất đồng bộ (non-blocking), trả quyền điều khiển về Apache ngay lập tức.
     *
     * @return bool true nếu vừa kích hoạt tiến trình mới; false nếu Worker đã đang chạy hoặc lỗi tìm PHP CLI
     */
    public static function triggerAsync(): bool
    {
        // Nếu đã có Worker đang chạy (dù là chạy ngầm hay mở tay), không sinh thêm tiến trình trùng
        if (self::isWorkerRunning()) {
            return false;
        }

        $phpCli = self::detectPhpCli();
        $workerScript = realpath(__DIR__ . '/worker.php');
        if (!$phpCli || !$workerScript) {
            return false;
        }

        if (PHP_OS_FAMILY === 'Windows') {
            // Chạy tiến trình tách rời hoàn toàn (detached), chuyển hướng I/O sang NUL để Apache không bị chờ
            $cmd = sprintf('start /B "" "%s" "%s" --drain < NUL > NUL 2>&1', $phpCli, $workerScript);
            $proc = @popen($cmd, 'r');
            if ($proc !== false) {
                @pclose($proc);
                return true;
            }
            return false;
        }

        $cmd = sprintf('"%s" "%s" --drain < /dev/null > /dev/null 2>&1 &', $phpCli, $workerScript);
        @exec($cmd);
        return true;
    }
}
