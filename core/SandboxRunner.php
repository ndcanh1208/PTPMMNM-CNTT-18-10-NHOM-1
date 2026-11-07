<?php
/**
 * ByteFlow Online Judge - Dịch vụ Thực thi Cách ly (SandboxRunner)
 * Thực thi mã nguồn Python và C++ trong thư mục tạm cách ly,
 * kiểm soát thời gian chạy (Time Limit), bộ nhớ và bắt chính xác mã lỗi:
 * - CE (Compile Error): Lỗi cú pháp / biên dịch
 * - RE (Runtime Error): Chia cho 0, ngoại lệ, exit code != 0
 * - TLE (Time Limit Exceeded): Chạy quá giới hạn thời gian quy định
 * - SE (System Error): Lỗi dịch vụ thực thi, thiếu trình biên dịch, ngắt đột ngột
 */

declare(strict_types=1);

require_once __DIR__ . '/Comparator.php';
require_once __DIR__ . '/Security.php';

class SandboxRunner
{
    private string $runsBaseDir;
    private ?string $pythonPath = null;
    private ?string $gppPath = null;

    public function __construct(?string $runsBaseDir = null)
    {
        $this->runsBaseDir = $runsBaseDir ?: realpath(__DIR__ . '/../temp/runs') ?: (__DIR__ . '/../temp/runs');
        if (!is_dir($this->runsBaseDir)) {
            mkdir($this->runsBaseDir, 0777, true);
        }
        $this->detectCompilers();
    }

    /**
     * Tự động phát hiện đường dẫn Python và G++ trên hệ điều hành
     */
    private function detectCompilers(): void
    {
        // 1. Phát hiện Python
        $candidatesPython = [
            'C:\\Users\\Administrator\\AppData\\Local\\Programs\\Python\\Python311\\python.exe',
            'python',
            'python3'
        ];
        foreach ($candidatesPython as $p) {
            $cmd = PHP_OS_FAMILY === 'Windows' ? "where.exe \"{$p}\" 2>NUL" : "which {$p} 2>/dev/null";
            if (file_exists($p) || @shell_exec($cmd)) {
                $this->pythonPath = $p;
                break;
            }
        }

        // 2. Phát hiện G++
        $candidatesGpp = [
            'g++',
            'C:\\MinGW\\bin\\g++.exe',
            'C:\\msys64\\ucrt64\\bin\\g++.exe',
            'C:\\msys64\\mingw64\\bin\\g++.exe',
            'C:\\ProgramData\\chocolatey\\bin\\g++.exe'
        ];

        // Tìm thêm trong thư mục LocalAppData WinGet packages nếu có
        $localAppData = getenv('LOCALAPPDATA');
        if ($localAppData) {
            $wingetMatches = glob($localAppData . '/Microsoft/WinGet/Packages/*mingw*/bin/g++.exe');
            if ($wingetMatches) {
                $candidatesGpp = array_merge($wingetMatches, $candidatesGpp);
            }
        }

        foreach ($candidatesGpp as $g) {
            $cmd = PHP_OS_FAMILY === 'Windows' ? "where.exe \"{$g}\" 2>NUL" : "which {$g} 2>/dev/null";
            if (file_exists($g) || @shell_exec($cmd)) {
                $this->gppPath = $g;
                break;
            }
        }
    }

    public function getPythonPath(): ?string
    {
        return $this->pythonPath;
    }

    public function getGppPath(): ?string
    {
        return $this->gppPath;
    }

    /**
     * Tạo thư mục làm việc cách ly cho một lượt chấm (Run ID)
     */
    public function prepareRunDir(string $runIdentifier): string
    {
        $dir = $this->runsBaseDir . DIRECTORY_SEPARATOR . preg_replace('/[^a-zA-Z0-9_\-]/', '_', $runIdentifier);
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        return $dir;
    }

    /**
     * Dọn dẹp thư mục tạm sau khi chấm xong
     */
    public function cleanupRunDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = scandir($dir);
        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $item;
            if (is_file($path)) {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }

    /**
     * Biên dịch mã nguồn (Dành cho C++) hoặc kiểm tra cú pháp (Python)
     *
     * @param string $language 'python' hoặc 'cpp'
     * @param string $sourceCode Mã nguồn bài làm
     * @param string $runDir Thư mục thực thi cách ly
     * @return array ['success' => bool, 'verdict' => string, 'message' => string, 'executable' => ?string]
     */
    public function compile(string $language, string $sourceCode, string $runDir): array
    {
        if ($language === 'cpp') {
            if (!$this->gppPath) {
                return [
                    'success'    => false,
                    'verdict'    => 'SE', // Lỗi hệ thống: Chưa có trình biên dịch g++
                    'message'    => 'Hệ thống chưa cấu hình hoặc không tìm thấy trình biên dịch C++ (g++).',
                    'executable' => null
                ];
            }

            $sourceFile = $runDir . DIRECTORY_SEPARATOR . 'solution.cpp';
            $exeFile    = $runDir . DIRECTORY_SEPARATOR . (PHP_OS_FAMILY === 'Windows' ? 'solution.exe' : 'solution.out');

            if (file_put_contents($sourceFile, $sourceCode) === false) {
                return [
                    'success'    => false,
                    'verdict'    => 'SE',
                    'message'    => 'Không thể ghi tệp mã nguồn vào thư mục cách ly.',
                    'executable' => null
                ];
            }

            // Biên dịch với g++ -O2 -std=c++17
            $compileCmd = sprintf(
                '"%s" -O2 -std=c++17 "%s" -o "%s" 2>&1',
                $this->gppPath,
                $sourceFile,
                $exeFile
            );

            $output = [];
            $exitCode = 0;
            exec($compileCmd, $output, $exitCode);
            $outputStr = implode("\n", $output);

            if ($exitCode !== 0 || !file_exists($exeFile)) {
                return [
                    'success'    => false,
                    'verdict'    => 'CE', // Compilation Error
                    'message'    => Security::sanitizeErrorMessage($outputStr),
                    'executable' => null
                ];
            }

            return [
                'success'    => true,
                'verdict'    => 'OK',
                'message'    => '',
                'executable' => $exeFile
            ];
        }

        if ($language === 'python') {
            if (!$this->pythonPath) {
                return [
                    'success'    => false,
                    'verdict'    => 'SE',
                    'message'    => 'Hệ thống chưa cấu hình hoặc không tìm thấy môi trường Python.',
                    'executable' => null
                ];
            }

            $sourceFile = $runDir . DIRECTORY_SEPARATOR . 'solution.py';
            if (file_put_contents($sourceFile, $sourceCode) === false) {
                return [
                    'success'    => false,
                    'verdict'    => 'SE',
                    'message'    => 'Không thể ghi tệp mã nguồn vào thư mục cách ly.',
                    'executable' => null
                ];
            }

            // Kiểm tra cú pháp sớm bằng py_compile
            $checkCmd = sprintf(
                '"%s" -m py_compile "%s" 2>&1',
                $this->pythonPath,
                $sourceFile
            );
            $output = [];
            $exitCode = 0;
            exec($checkCmd, $output, $exitCode);
            $outputStr = implode("\n", $output);

            if ($exitCode !== 0) {
                return [
                    'success'    => false,
                    'verdict'    => 'CE', // Syntax / Compile Error
                    'message'    => Security::sanitizeErrorMessage($outputStr),
                    'executable' => null
                ];
            }

            return [
                'success'    => true,
                'verdict'    => 'OK',
                'message'    => '',
                'executable' => $sourceFile
            ];
        }

        return [
            'success'    => false,
            'verdict'    => 'SE',
            'message'    => "Ngôn ngữ lập trình '{$language}' không được hỗ trợ.",
            'executable' => null
        ];
    }

    /**
     * Thực thi một testcase trong môi trường cách ly có giám sát thời gian
     *
     * @param string $language 'python' hoặc 'cpp'
     * @param string $executable Đường dẫn file thực thi (solution.exe hoặc solution.py)
     * @param string $inputData Dữ liệu đầu vào stdin
     * @param int $timeLimitMs Giới hạn thời gian (ms)
     * @param int $memoryLimitKb Giới hạn bộ nhớ (KB)
     * @return array [
     *    'verdict'           => string ('AC', 'WA', 'TLE', 'RE', 'SE'),
     *    'execution_time_ms' => int,
     *    'memory_used_kb'    => int,
     *    'actual_output'     => string,
     *    'error_message'     => string
     * ]
     */
    public function runTestCase(
        string $language,
        string $executable,
        string $inputData,
        int $timeLimitMs = 1000,
        int $memoryLimitKb = 262144
    ): array {
        if (!file_exists($executable)) {
            return [
                'verdict'           => 'SE',
                'execution_time_ms' => 0,
                'memory_used_kb'    => 0,
                'actual_output'     => '',
                'error_message'     => 'Không tìm thấy tệp thực thi trong thư mục cách ly.'
            ];
        }

        $workDir = dirname($executable);
        $command = '';
        if ($language === 'python') {
            $command = sprintf('"%s" -u "%s"', $this->pythonPath, $executable);
        } else {
            $command = sprintf('"%s"', $executable);
        }

        // Trên Windows, chuyển hướng stdout & stderr ra file tạm để tránh hoàn toàn
        // hiện tượng deadlock / blocking của stream pipe trong proc_open
        $stdoutFile = $workDir . DIRECTORY_SEPARATOR . 'stdout_' . uniqid() . '.tmp';
        $stderrFile = $workDir . DIRECTORY_SEPARATOR . 'stderr_' . uniqid() . '.tmp';

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['file', $stdoutFile, 'w'],
            2 => ['file', $stderrFile, 'w']
        ];

        $startTime = microtime(true);
        $process = @proc_open($command, $descriptors, $pipes, $workDir);

        if (!is_resource($process)) {
            @unlink($stdoutFile);
            @unlink($stderrFile);
            return [
                'verdict'           => 'SE',
                'execution_time_ms' => 0,
                'memory_used_kb'    => 0,
                'actual_output'     => '',
                'error_message'     => 'Không thể khởi chạy tiến trình thực thi cách ly.'
            ];
        }

        // Bơm dữ liệu vào stdin và đóng pipe
        if (isset($pipes[0]) && is_resource($pipes[0])) {
            fwrite($pipes[0], $inputData);
            fclose($pipes[0]);
        }

        $timeLimitSec = max(0.1, $timeLimitMs / 1000.0);
        $timedOut = false;
        $outputExceeded = false;
        $maxOutputBytes = 2 * 1024 * 1024; // 2MB

        while (true) {
            $status = proc_get_status($process);
            $elapsed = microtime(true) - $startTime;

            if (!$status['running']) {
                break;
            }

            // Kiểm tra timeout
            if ($elapsed > $timeLimitSec) {
                $timedOut = true;
                $this->killProcessTree($status['pid']);
                break;
            }

            // Kiểm tra Output Limit
            if (file_exists($stdoutFile) && filesize($stdoutFile) > $maxOutputBytes) {
                $outputExceeded = true;
                $this->killProcessTree($status['pid']);
                break;
            }

            // Nghỉ 10ms tránh chiếm dụng CPU
            usleep(10000);
        }

        $endTime = microtime(true);
        $executionTimeMs = (int)round(($endTime - $startTime) * 1000);

        // Đóng tiến trình
        $exitCode = proc_close($process);

        // Đọc stdout và stderr từ file tạm
        $stdout = file_exists($stdoutFile) ? (string)file_get_contents($stdoutFile) : '';
        $stderr = file_exists($stderrFile) ? (string)file_get_contents($stderrFile) : '';

        // Dọn dẹp file tạm
        @unlink($stdoutFile);
        @unlink($stderrFile);

        // Phân loại kết quả
        if ($outputExceeded) {
            return [
                'verdict'           => 'RE',
                'execution_time_ms' => $executionTimeMs,
                'memory_used_kb'    => 0,
                'actual_output'     => substr($stdout, 0, 4000),
                'error_message'     => 'Output Limit Exceeded: Kích thước đầu ra vượt quá giới hạn 2MB.'
            ];
        }

        if ($timedOut) {
            return [
                'verdict'           => 'TLE',
                'execution_time_ms' => max($timeLimitMs, $executionTimeMs),
                'memory_used_kb'    => 0,
                'actual_output'     => '',
                'error_message'     => "Time Limit Exceeded: Thời gian chạy vượt quá {$timeLimitMs}ms."
            ];
        }

        if ($exitCode !== 0) {
            return [
                'verdict'           => 'RE',
                'execution_time_ms' => $executionTimeMs,
                'memory_used_kb'    => 0,
                'actual_output'     => $stdout,
                'error_message'     => Security::sanitizeErrorMessage($stderr ?: "Tiến trình thoát với mã lỗi: {$exitCode}")
            ];
        }

        return [
            'verdict'           => 'AC',
            'execution_time_ms' => $executionTimeMs,
            'memory_used_kb'    => 0,
            'actual_output'     => $stdout,
            'error_message'     => Security::sanitizeErrorMessage($stderr)
        ];
    }

    /**
     * Tiêu diệt toàn bộ cây tiến trình (process tree) để ngăn chặn tiến trình con chạy ngầm
     */
    private function killProcessTree(int $pid): void
    {
        if ($pid <= 0) {
            return;
        }

        if (PHP_OS_FAMILY === 'Windows') {
            @exec("taskkill /F /T /PID {$pid} 2>NUL");
        } else {
            @exec("pkill -P {$pid} 2>/dev/null; kill -9 {$pid} 2>/dev/null");
        }
    }
}
