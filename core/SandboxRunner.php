<?php
/**
 * Nhom1 Online Judge - Dịch vụ Thực thi Cách ly (SandboxRunner)
 *
 * Mục đích của tệp này:
 * - Cung cấp môi trường thư mục tạm cách ly để biên dịch và thực thi mã nguồn bài nộp của thí sinh
 *   (hỗ trợ 2 ngôn ngữ: Python và C++).
 * - Giám sát chặt chẽ thời gian chạy (Time Limit), kích thước đầu ra (Output Limit 2MB) và mã thoát tiến trình.
 * - Tiêu diệt triệt để toàn bộ cây tiến trình (process tree) khi vượt thời gian hoặc tràn đầu ra,
 *   tránh tiến trình con chạy ngầm (zombie/infinite loop) chiếm dụng CPU máy chủ.
 * - Phân loại chính xác các mã kết quả (Verdict):
 *   + AC  (Accepted - ở tầng Sandbox nghĩa là chạy thoát êm đẹp với exit code = 0, chưa so khớp đáp án)
 *   + CE  (Compile Error): Lỗi cú pháp / lỗi biên dịch mã nguồn
 *   + RE  (Runtime Error): Lỗi khi chạy (chia cho 0, truy cập ngoài mảng, ngoại lệ chưa bắt, Output > 2MB, exit code != 0)
 *   + TLE (Time Limit Exceeded): Chạy quá giới hạn thời gian quy định
 *   + SE  (System Error): Lỗi hạ tầng hệ thống (thiếu trình biên dịch, không ghi được tệp, không mở được tiến trình)
 */

// Bật kiểm tra kiểu dữ liệu nghiêm ngặt
declare(strict_types=1);

// Nạp Comparator (bộ so sánh đầu ra) và Security (lọc đường dẫn nhạy cảm khỏi thông báo lỗi)
require_once __DIR__ . '/Comparator.php';
require_once __DIR__ . '/Security.php';

/**
 * Lớp SandboxRunner
 *
 * Quản lý việc phát hiện trình biên dịch, tạo/xóa thư mục cách ly theo từng lượt chấm,
 * biên dịch/kiểm tra cú pháp và thực thi từng test case bằng `proc_open`.
 */
class SandboxRunner
{
    /**
     * Đường dẫn thư mục gốc chứa các thư mục chạy tạm của từng lượt chấm (mặc định: temp/runs).
     */
    private string $runsBaseDir;

    /**
     * Đường dẫn tới tệp thực thi trình thông dịch Python (ví dụ: python.exe hoặc python3).
     * Bằng null nếu máy chủ chưa cài đặt hoặc không tìm thấy Python.
     */
    private ?string $pythonPath = null;

    /**
     * Đường dẫn tới trình biên dịch C++ G++ (ví dụ: g++.exe của MinGW/WinLibs hoặc g++ trên Linux).
     * Bằng null nếu máy chủ chưa cài đặt hoặc không tìm thấy G++.
     */
    private ?string $gppPath = null;

    /**
     * Hàm khởi tạo (Constructor) của SandboxRunner.
     *
     * @param string|null $runsBaseDir Thư mục gốc lưu các thư mục chạy tạm (nếu truyền null sẽ dùng mặc định ../temp/runs).
     */
    public function __construct(?string $runsBaseDir = null)
    {
        // Ưu tiên thư mục do caller truyền vào; nếu không có thì chuẩn hóa đường dẫn ../temp/runs
        $this->runsBaseDir = $runsBaseDir ?: realpath(__DIR__ . '/../temp/runs') ?: (__DIR__ . '/../temp/runs');
        // Tự động tạo thư mục gốc nếu chưa tồn tại trên ổ đĩa
        if (!is_dir($this->runsBaseDir)) {
            mkdir($this->runsBaseDir, 0777, true);
        }
        // Dò tìm sẵn đường dẫn Python và G++ một lần ngay khi khởi tạo đối tượng
        $this->detectCompilers();
    }

    /**
     * Tự động phát hiện đường dẫn trình thông dịch Python và trình biên dịch G++ trên hệ điều hành.
     *
     * Cơ chế dò tìm đa nền tảng:
     * - Trên Windows: kiểm tra các đường dẫn cài đặt phổ biến (Python311, WinLibs qua WinGet, MinGW, MSYS2, Chocolatey)
     *   và kết hợp lệnh `where.exe` để tra trong biến môi trường PATH.
     * - Trên Linux/macOS: dùng lệnh `which` để tra trong PATH.
     */
    private function detectCompilers(): void
    {
        // 1. Phát hiện Python theo danh sách ứng viên ưu tiên
        $candidatesPython = [
            'C:\\Users\\Administrator\\AppData\\Local\\Programs\\Python\\Python311\\python.exe',
            'python',
            'python3'
        ];
        foreach ($candidatesPython as $p) {
            // Nếu là đường dẫn tuyệt đối -> kiểm tra bằng file_exists(); nếu là tên lệnh -> tra qua where.exe / which
            $cmd = PHP_OS_FAMILY === 'Windows' ? "where.exe \"{$p}\" 2>NUL" : "which {$p} 2>/dev/null";
            if (file_exists($p) || @shell_exec($cmd)) {
                $this->pythonPath = $p;
                break;
            }
        }

        // 2. Phát hiện trình biên dịch C++ (G++) theo danh sách đường dẫn phổ biến
        $candidatesGpp = [
            'g++',
            'C:\\Users\\Administrator\\AppData\\Local\\Microsoft\\WinGet\\Packages\\BrechtSanders.WinLibs.POSIX.UCRT_Microsoft.Winget.Source_8wekyb3d8bbwe\\mingw64\\bin\\g++.exe',
            'C:\\MinGW\\bin\\g++.exe',
            'C:\\msys64\\ucrt64\\bin\\g++.exe',
            'C:\\msys64\\mingw64\\bin\\g++.exe',
            'C:\\ProgramData\\chocolatey\\bin\\g++.exe'
        ];

        // Mở rộng dò tìm động trong thư mục gói WinGet của người dùng hiện tại (%LOCALAPPDATA%)
        // phòng trường hợp phiên bản WinLibs cài đặt có tên thư mục khác nhau giữa các máy
        $localAppData = getenv('LOCALAPPDATA');
        if ($localAppData) {
            $wingetMatches = glob($localAppData . '/Microsoft/WinGet/Packages/*WinLibs*/mingw64/bin/g++.exe');
            if ($wingetMatches) {
                // Đưa kết quả tìm được lên đầu danh sách ưu tiên
                $candidatesGpp = array_merge($wingetMatches, $candidatesGpp);
            }
            $generalMatches = glob($localAppData . '/Microsoft/WinGet/Packages/*/*/*/g++.exe');
            if ($generalMatches) {
                $candidatesGpp = array_merge($generalMatches, $candidatesGpp);
            }
        }

        // Duyệt danh sách ứng viên G++ và chọn đường dẫn hợp lệ đầu tiên tìm thấy
        foreach ($candidatesGpp as $g) {
            $cmd = PHP_OS_FAMILY === 'Windows' ? "where.exe \"{$g}\" 2>NUL" : "which {$g} 2>/dev/null";
            if (file_exists($g) || @shell_exec($cmd)) {
                $this->gppPath = $g;
                break;
            }
        }
    }

    /**
     * Lấy đường dẫn trình thông dịch Python đã phát hiện được.
     *
     * @return string|null
     */
    public function getPythonPath(): ?string
    {
        return $this->pythonPath;
    }

    /**
     * Lấy đường dẫn trình biên dịch G++ đã phát hiện được.
     *
     * @return string|null
     */
    public function getGppPath(): ?string
    {
        return $this->gppPath;
    }

    /**
     * Tạo thư mục làm việc cách ly riêng biệt cho một lượt chấm (ví dụ: `temp/runs/run_42`).
     *
     * Bảo mật:
     * - Dùng `preg_replace('/[^a-zA-Z0-9_\-]/', '_', $runIdentifier)` để loại bỏ mọi ký tự đặc biệt
     *   như `/`, `\`, `..`, chống lỗi Path Traversal (vượt thoát ra khỏi thư mục temp/runs).
     *
     * @param string $runIdentifier Tên định danh duy nhất cho lượt chấm (ví dụ: "run_15").
     * @return string Đường dẫn đầy đủ của thư mục cách ly vừa tạo.
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
     * Dọn dẹp sạch sẽ toàn bộ tệp tin tạm và xóa thư mục cách ly sau khi chấm xong lượt chấm.
     *
     * Mục đích: Tránh đầy ổ cứng do tích tụ tệp mã nguồn, tệp thực thi `.exe` và các tệp tạm sinh ra.
     *
     * @param string $dir Đường dẫn thư mục cách ly cần xóa.
     */
    public function cleanupRunDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        // Liệt kê mọi mục bên trong thư mục tạm
        $items = scandir($dir);
        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            // Bỏ qua 2 mục đặc biệt của hệ thống tệp: '.' (thư mục hiện tại) và '..' (thư mục cha)
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $item;
            // Xóa từng tệp con (dùng @ để tránh cảnh báo nếu tệp vừa bị khóa/xóa trước đó)
            if (is_file($path)) {
                @unlink($path);
            }
        }
        // Xóa thư mục rỗng sau khi đã dọn sạch các tệp bên trong
        @rmdir($dir);
    }

    /**
     * Biên dịch mã nguồn (đối với C++) hoặc kiểm tra cú pháp sớm (đối với Python).
     *
     * Cơ chế phân biệt lỗi hệ thống (SE) và lỗi thí sinh (CE):
     * - Không tìm thấy trình biên dịch hoặc không ghi được file -> trả về 'SE' (System Error).
     * - Trình biên dịch báo lỗi cú pháp/liên kết (exitCode != 0) -> trả về 'CE' (Compile Error)
     *   kèm thông báo lỗi đã được làm sạch đường dẫn máy chủ qua `Security::sanitizeErrorMessage()`.
     *
     * @param string $language   Ngôn ngữ lập trình: 'python' hoặc 'cpp'.
     * @param string $sourceCode Toàn bộ mã nguồn bài làm của thí sinh.
     * @param string $runDir     Đường dẫn thư mục thực thi cách ly của lượt chấm hiện tại.
     * @return array Mảng kết quả: ['success' => bool, 'verdict' => string, 'message' => string, 'executable' => ?string]
     */
    public function compile(string $language, string $sourceCode, string $runDir): array
    {
        // ==================== NHÁNH 1: NGÔN NGỮ C++ ====================
        if ($language === 'cpp') {
            // Nếu máy chủ chưa cài g++, đây là lỗi hạ tầng (SE) chứ không phải lỗi bài làm của thí sinh
            if (!$this->gppPath) {
                return [
                    'success'    => false,
                    'verdict'    => 'SE', // Lỗi hệ thống: Chưa có trình biên dịch g++
                    'message'    => 'Hệ thống chưa cấu hình hoặc không tìm thấy trình biên dịch C++ (g++).',
                    'executable' => null
                ];
            }

            // Đặt tên tệp nguồn solution.cpp và tệp thực thi đầu ra (.exe trên Windows, .out trên Linux)
            $sourceFile = $runDir . DIRECTORY_SEPARATOR . 'solution.cpp';
            $exeFile    = $runDir . DIRECTORY_SEPARATOR . (PHP_OS_FAMILY === 'Windows' ? 'solution.exe' : 'solution.out');

            // Ghi mã nguồn thí sinh ra tệp trong thư mục cách ly
            if (file_put_contents($sourceFile, $sourceCode) === false) {
                return [
                    'success'    => false,
                    'verdict'    => 'SE',
                    'message'    => 'Không thể ghi tệp mã nguồn vào thư mục cách ly.',
                    'executable' => null
                ];
            }

            // Biên dịch mã nguồn C++ với các cờ chuẩn Online Judge:
            // - `-O2`: bật tối ưu hóa hiệu năng mức 2 (chuẩn thi đấu lập trình).
            // - `-static`: liên kết tĩnh thư viện C++ vào thẳng tệp .exe để chạy độc lập,
            //   tránh lỗi thiếu file DLL (như libstdc++-6.dll) khi chạy trên Windows không có MinGW trong PATH.
            // - `-std=c++17`: sử dụng chuẩn ngôn ngữ C++17 hiện đại.
            // - `2>&1`: gộp luồng lỗi (stderr) vào luồng đầu ra (stdout) để hàm exec() thu được trọn vẹn thông báo lỗi biên dịch.
            $compileCmd = sprintf(
                '"%s" -O2 -static -std=c++17 "%s" -o "%s" 2>&1',
                $this->gppPath,
                $sourceFile,
                $exeFile
            );

            $output = [];
            $exitCode = 0;
            exec($compileCmd, $output, $exitCode);
            $outputStr = implode("\n", $output);

            // Nếu mã thoát khác 0 hoặc không sinh ra tệp thực thi -> Lỗi biên dịch (CE)
            if ($exitCode !== 0 || !file_exists($exeFile)) {
                return [
                    'success'    => false,
                    'verdict'    => 'CE', // Compilation Error
                    // Che giấu đường dẫn tuyệt đối trên máy chủ trước khi lưu/hiển thị lỗi biên dịch
                    'message'    => Security::sanitizeErrorMessage($outputStr),
                    'executable' => null
                ];
            }

            // Biên dịch thành công, trả về đường dẫn tệp thực thi để chạy các test case
            return [
                'success'    => true,
                'verdict'    => 'OK',
                'message'    => '',
                'executable' => $exeFile
            ];
        }

        // ==================== NHÁNH 2: NGÔN NGỮ PYTHON ====================
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

            // Python là ngôn ngữ thông dịch nhưng ta chạy `python -m py_compile` trước để biên dịch sang bytecode (.pyc).
            // Lợi ích: bắt sớm mọi lỗi cú pháp (SyntaxError, IndentationError) thành verdict 'CE' ngay lập tức
            // mà không cần chạy lặp lại lỗi đó trên từng test case.
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

            // Kiểm tra cú pháp đạt; tệp chạy sẽ chính là đường dẫn tới solution.py
            return [
                'success'    => true,
                'verdict'    => 'OK',
                'message'    => '',
                'executable' => $sourceFile
            ];
        }

        // Ngôn ngữ không nằm trong danh sách hỗ trợ ('cpp', 'python') -> Lỗi hệ thống (SE)
        return [
            'success'    => false,
            'verdict'    => 'SE',
            'message'    => "Ngôn ngữ lập trình '{$language}' không được hỗ trợ.",
            'executable' => null
        ];
    }

    /**
     * Thực thi MỘT ca kiểm thử (test case) trong môi trường cách ly bằng `proc_open` có giám sát thời gian & đầu ra.
     *
     * Cơ chế kỹ thuật quan trọng bên trong hàm này:
     * 1. Chống Deadlock Pipe trên Windows:
     *    - Khi dùng `proc_open`, nếu mở stdout/stderr dạng `['pipe', 'w']`, bộ đệm pipe trên Windows rất nhỏ (~4KB).
     *    - Nếu chương trình thí sinh in > 4KB trong khi PHP đang đứng trong vòng lặp chờ, tiến trình con sẽ bị treo
     *      (block) chờ PHP đọc pipe -> gây kẹt cứng (deadlock) rồi bị phạt oan TLE!
     *    - Giải pháp: Chuyển hướng thẳng stdout (kênh 1) và stderr (kênh 2) ra 2 tệp tạm (`stdout_*.tmp`, `stderr_*.tmp`).
     * 2. Bơm dữ liệu stdin và gửi tín hiệu EOF:
     *    - Ghi `$inputData` vào `$pipes[0]` rồi lập tức gọi `fclose($pipes[0])` để báo hết dữ liệu đầu vào (EOF),
     *      giúp các lệnh đọc đến hết file (`while (cin >> x)` hoặc `sys.stdin.read()`) kết thúc đúng lúc.
     * 3. Vòng lặp giám sát chủ động (Polling Watchdog mỗi 10ms):
     *    - Kiểm tra nếu thời gian thực tế `$elapsed > $timeLimitSec` -> phạt TLE và gọi `killProcessTree()`.
     *    - Kiểm tra nếu dung lượng `stdoutFile > 2MB` -> phạt Output Limit Exceeded (RE) và gọi `killProcessTree()`.
     *
     * @param string $language      Ngôn ngữ: 'python' hoặc 'cpp'.
     * @param string $executable    Đường dẫn tệp thực thi (`solution.exe` hoặc `solution.py`).
     * @param string $inputData     Chuỗi dữ liệu đầu vào bơm qua luồng nhập chuẩn (stdin).
     * @param int    $timeLimitMs   Giới hạn thời gian tối đa tính bằng mili-giây (mặc định 1000ms = 1 giây).
     * @param int    $memoryLimitKb Giới hạn bộ nhớ tính bằng KB (mặc định 262144 KB = 256MB).
     * @return array Mảng kết quả thực thi:
     *               - 'verdict'           (string): 'AC' (chạy không lỗi), 'TLE', 'RE', hoặc 'SE'.
     *               - 'execution_time_ms' (int)   : Thời gian chạy đo được (ms).
     *               - 'memory_used_kb'    (int)   : Bộ nhớ tiêu thụ (KB).
     *               - 'actual_output'     (string): Nội dung stdout chương trình đã in ra.
     *               - 'error_message'     (string): Nội dung stderr hoặc thông báo lỗi đã được lọc an toàn.
     */
    public function runTestCase(
        string $language,
        string $executable,
        string $inputData,
        int $timeLimitMs = 1000,
        int $memoryLimitKb = 262144
    ): array {
        // Đảm bảo tệp thực thi vẫn tồn tại trong thư mục cách ly trước khi chạy
        if (!file_exists($executable)) {
            return [
                'verdict'           => 'SE',
                'execution_time_ms' => 0,
                'memory_used_kb'    => 0,
                'actual_output'     => '',
                'error_message'     => 'Không tìm thấy tệp thực thi trong thư mục cách ly.'
            ];
        }

        // Đặt thư mục làm việc hiện tại (working directory) của tiến trình con chính là thư mục chạy cách ly
        $workDir = dirname($executable);
        $command = '';
        if ($language === 'python') {
            // Cờ `-u` (unbuffered): ép Python xả (flush) ngay stdout/stderr không qua bộ đệm,
            // giúp ghi nhận kịp thời kích thước đầu ra và thông báo lỗi
            $command = sprintf('"%s" -u "%s"', $this->pythonPath, $executable);
        } else {
            // Với C++, chạy trực tiếp tệp nhị phân đã biên dịch
            $command = sprintf('"%s"', $executable);
        }

        // Trên Windows, chuyển hướng stdout & stderr ra file tạm riêng biệt (gắn uniqid tránh trùng tên)
        // để loại bỏ hoàn toàn hiện tượng deadlock / blocking của stream pipe trong proc_open
        $stdoutFile = $workDir . DIRECTORY_SEPARATOR . 'stdout_' . uniqid() . '.tmp';
        $stderrFile = $workDir . DIRECTORY_SEPARATOR . 'stderr_' . uniqid() . '.tmp';

        // Bảng mô tả 3 luồng chuẩn (Standard File Descriptors) cấp cho tiến trình con:
        // - 0 (stdin) : dạng 'pipe' chiều đọc ('r') để PHP ghi $inputData vào cho tiến trình con đọc.
        // - 1 (stdout): ghi thẳng ra tệp tạm $stdoutFile.
        // - 2 (stderr): ghi thẳng ra tệp tạm $stderrFile.
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['file', $stdoutFile, 'w'],
            2 => ['file', $stderrFile, 'w']
        ];

        // Bắt đầu bấm giờ chính xác tới micro-giây và khởi chạy tiến trình con bằng proc_open()
        $startTime = microtime(true);
        $process = @proc_open($command, $descriptors, $pipes, $workDir);

        // Nếu hệ điều hành không khởi chạy được tiến trình -> dọn tệp tạm và báo lỗi hệ thống (SE)
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

        // Bơm toàn bộ dữ liệu đầu vào (input của test case) vào luồng stdin và đóng ngay pipe 0
        // Việc gọi fclose($pipes[0]) cực kỳ quan trọng để gửi tín hiệu EOF (End-Of-File) cho chương trình con
        if (isset($pipes[0]) && is_resource($pipes[0])) {
            fwrite($pipes[0], $inputData);
            fclose($pipes[0]);
        }

        // Quy đổi giới hạn thời gian sang giây (tối thiểu 0.1 giây để tránh giá trị âm hoặc 0)
        $timeLimitSec = max(0.1, $timeLimitMs / 1000.0);
        $timedOut = false;
        $outputExceeded = false;
        // Giới hạn dung lượng đầu ra tối đa 2MB (chống vòng lặp vô tận in ra hàng GB làm đầy ổ đĩa)
        $maxOutputBytes = 2 * 1024 * 1024; // 2MB

        // Vòng lặp giám sát tiến trình (Watchdog loop) cho tới khi chương trình kết thúc hoặc vi phạm giới hạn
        while (true) {
            // Lấy trạng thái sống/chết và mã PID hiện tại của tiến trình con
            $status = proc_get_status($process);
            $elapsed = microtime(true) - $startTime;

            // Nếu tiến trình đã tự kết thúc bình thường (hoặc crash) -> thoát vòng lặp giám sát
            if (!$status['running']) {
                break;
            }

            // Kiểm tra vượt giới hạn thời gian (TLE - Time Limit Exceeded)
            if ($elapsed > $timeLimitSec) {
                $timedOut = true;
                // Tiêu diệt ngay toàn bộ cây tiến trình để tránh tiến trình tiếp tục chạy ngầm đốt CPU
                $this->killProcessTree($status['pid']);
                break;
            }

            // Kiểm tra vượt giới hạn dung lượng đầu ra (Output Limit Exceeded > 2MB)
            if (file_exists($stdoutFile) && filesize($stdoutFile) > $maxOutputBytes) {
                $outputExceeded = true;
                $this->killProcessTree($status['pid']);
                break;
            }

            // Ngủ 10 mili-giây (10,000 micro-giây) giữa mỗi lần kiểm tra để vòng lặp giám sát không ngốn 100% CPU
            usleep(10000);
        }

        // Dừng đồng hồ và tính tổng thời gian thực thi theo đơn vị mili-giây (làm tròn số nguyên)
        $endTime = microtime(true);
        $executionTimeMs = (int)round(($endTime - $startTime) * 1000);

        // Đóng tài nguyên tiến trình và thu về mã thoát (exit code: 0 là thành công, khác 0 là có lỗi runtime)
        $exitCode = proc_close($process);

        // Đọc toàn bộ kết quả in ra (stdout) và thông báo lỗi (stderr) từ 2 tệp tạm
        $stdout = file_exists($stdoutFile) ? (string)file_get_contents($stdoutFile) : '';
        $stderr = file_exists($stderrFile) ? (string)file_get_contents($stderrFile) : '';

        // Xóa ngay 2 tệp tạm sau khi đã đọc xong vào bộ nhớ
        @unlink($stdoutFile);
        @unlink($stderrFile);

        // Phân loại kết quả thực thi theo thứ tự ưu tiên từ lỗi nghiêm trọng nhất:
        // Ưu tiên 1: Vượt quá giới hạn kích thước đầu ra (Output > 2MB) -> Xếp vào RE
        if ($outputExceeded) {
            return [
                'verdict'           => 'RE',
                'execution_time_ms' => $executionTimeMs,
                'memory_used_kb'    => 0,
                // Chỉ giữ lại 4000 byte đầu để tránh tràn bộ nhớ khi lưu DB hoặc trả JSON
                'actual_output'     => substr($stdout, 0, 4000),
                'error_message'     => 'Output Limit Exceeded: Kích thước đầu ra vượt quá giới hạn 2MB.'
            ];
        }

        // Ưu tiên 2: Vượt quá giới hạn thời gian chạy -> TLE (Time Limit Exceeded)
        if ($timedOut) {
            return [
                'verdict'           => 'TLE',
                // Đảm bảo thời gian hiển thị ít nhất bằng ngưỡng timeLimitMs
                'execution_time_ms' => max($timeLimitMs, $executionTimeMs),
                'memory_used_kb'    => 0,
                'actual_output'     => '',
                'error_message'     => "Time Limit Exceeded: Thời gian chạy vượt quá {$timeLimitMs}ms."
            ];
        }

        // Ưu tiên 3: Chương trình kết thúc với mã thoát khác 0 (ngoại lệ, chia cho 0, segfault...) -> RE (Runtime Error)
        if ($exitCode !== 0) {
            return [
                'verdict'           => 'RE',
                'execution_time_ms' => $executionTimeMs,
                'memory_used_kb'    => 0,
                'actual_output'     => $stdout,
                // Lọc bỏ đường dẫn máy chủ khỏi stderr (nếu stderr rỗng thì báo mã exitCode)
                'error_message'     => Security::sanitizeErrorMessage($stderr ?: "Tiến trình thoát với mã lỗi: {$exitCode}")
            ];
        }

        // Ưu tiên 4: Tiến trình chạy hoàn tất với exitCode = 0 và trong thời gian cho phép.
        // Lưu ý: Verdict 'AC' ở đây chỉ xác nhận "thực thi thành công không lỗi";
        // bước tiếp theo Worker sẽ dùng Comparator::compare() so sánh actual_output với đáp án để kết luận AC hay WA.
        return [
            'verdict'           => 'AC',
            'execution_time_ms' => $executionTimeMs,
            'memory_used_kb'    => 0,
            'actual_output'     => $stdout,
            'error_message'     => Security::sanitizeErrorMessage($stderr)
        ];
    }

    /**
     * Tiêu diệt toàn bộ cây tiến trình (process tree) bắt đầu từ tiến trình cha `$pid`.
     *
     * Tại sao phải diệt cả CÂY tiến trình thay vì dùng `proc_terminate()` thông thường?
     * - Trên Windows, `proc_open()` thường gọi qua shell trung gian `cmd.exe`, rồi `cmd.exe` mới sinh ra
     *   tiến trình con `python.exe` hoặc `solution.exe` (hoặc bài làm cố tình gọi `fork`/`subprocess`).
     * - Nếu chỉ diệt tiến trình cha (`cmd.exe`), tiến trình con bên dưới vẫn sống sót và tiếp tục chạy vòng lặp
     *   vô tận ngầm trên máy chủ!
     * - Lệnh `taskkill /F /T /PID <pid>` trên Windows:
     *   + `/F` (Force): cưỡng chế kết thúc ngay lập tức không chờ phản hồi.
     *   + `/T` (Tree): tiêu diệt cả tiến trình mục tiêu LẪN toàn bộ các tiến trình con/cháu do nó sinh ra.
     *   + `/PID`: chỉ định mã định danh tiến trình mục tiêu.
     * - Trên Linux/macOS: dùng `pkill -P <pid>` diệt mọi tiến trình con có Parent PID là `$pid`,
     *   sau đó dùng `kill -9 <pid>` (tín hiệu SIGKILL) diệt cưỡng chế tiến trình cha.
     *
     * @param int $pid Mã định danh tiến trình (Process ID) lấy từ `proc_get_status()`.
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
