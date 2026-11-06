<?php
/**
 * ByteFlow Online Judge - Kịch bản kiểm thử nghiệm thu Giai đoạn 1
 * Chạy qua CLI: php tests/verify_phase1.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

echo "====================================================================\n";
echo "   BYTEFLOW ONLINE JUDGE - KIỂM THỬ NGHIỆM THU GIAI ĐOẠN 1          \n";
echo "====================================================================\n";

$pdo = getDbConnection();

// 1. Kiểm tra 10 bảng CSDL bắt buộc
echo "\n[BƯỚC 1] Kiểm tra 10 Bảng cơ sở dữ liệu bắt buộc theo MASTER_PLAN.md:\n";
$requiredTables = [
    'users', 'classrooms', 'classroom_members', 'problems', 'code_drafts',
    'problem_versions', 'test_cases', 'submissions', 'judging_runs', 'case_results'
];

$existingTables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
$missingTables = array_diff($requiredTables, $existingTables);

if (!empty($missingTables)) {
    echo "  [FAIL] Thiếu các bảng: " . implode(', ', $missingTables) . "\n";
    exit(1);
}
echo "  [PASS] Đầy đủ 10/10 bảng cơ sở dữ liệu cốt lõi.\n";

// 2. Kiểm tra tài khoản mẫu
echo "\n[BƯỚC 2] Kiểm tra 4 tài khoản mẫu và phân quyền:\n";
$users = $pdo->query("SELECT id, username, email, role, password_hash FROM users ORDER BY id ASC")->fetchAll();
$rolesFound = array_column($users, 'role', 'username');

foreach (['admin1' => 'admin', 'instructor1' => 'instructor', 'learner1' => 'learner', 'learner2' => 'learner'] as $u => $r) {
    if (!isset($rolesFound[$u]) || $rolesFound[$u] !== $r) {
        echo "  [FAIL] Tài khoản {$u} không đúng vai trò {$r}\n";
        exit(1);
    }
    // Kiểm tra verify mật khẩu mẫu Password123@
    $userRow = array_values(array_filter($users, fn($item) => $item['username'] === $u))[0];
    if (!password_verify('Password123@', $userRow['password_hash'])) {
        echo "  [FAIL] Mật khẩu mẫu của {$u} không khớp Password123@\n";
        exit(1);
    }
}
echo "  [PASS] 4/4 tài khoản mẫu hợp lệ và mật khẩu Bcrypt xác thực chính xác.\n";

// 3. Kiểm tra 10 bài tập & testcase
echo "\n[BƯỚC 3] Kiểm tra 10 bài tập và tiêu chuẩn testcase (>= 2 sample, >= 3 hidden):\n";
$problems = $pdo->query("
    SELECT p.id, p.slug, p.title, p.difficulty, p.source_attribution, pv.id as version_id
    FROM problems p
    JOIN problem_versions pv ON p.current_version_id = pv.id
    ORDER BY p.id ASC
")->fetchAll();

if (count($problems) !== 10) {
    echo "  [FAIL] Số lượng bài tập hiện có: " . count($problems) . " (Yêu cầu: 10 bài)\n";
    exit(1);
}

foreach ($problems as $prob) {
    $tests = $pdo->prepare("
        SELECT id, case_order, input_data, expected_output, is_sample, is_hidden
        FROM test_cases
        WHERE problem_version_id = ?
        ORDER BY case_order ASC
    ");
    $tests->execute([$prob['version_id']]);
    $testRows = $tests->fetchAll();

    $sampleCount = count(array_filter($testRows, fn($t) => $t['is_sample'] == 1 && $t['is_hidden'] == 0));
    $hiddenCount = count(array_filter($testRows, fn($t) => $t['is_hidden'] == 1));

    if (count($testRows) < 5 || $sampleCount < 2 || $hiddenCount < 3) {
        echo "  [FAIL] Bài '{$prob['slug']}' không đạt chuẩn: Tổng=" . count($testRows) . ", Sample={$sampleCount}, Hidden={$hiddenCount}\n";
        exit(1);
    }

    echo "  [PASS] Bài #{$prob['id']} ({$prob['slug']}) - [{$prob['difficulty']}]: " . count($testRows) . " testcases ({$sampleCount} sample, {$hiddenCount} hidden). Nguồn: {$prob['source_attribution']}\n";
}

// 4. Kiểm tra các file mã nguồn mẫu trong tests/sample_solutions
echo "\n[BƯỚC 4] Kiểm tra các bộ mã nguồn mẫu (AC, WA, CE, RE, TLE):\n";
$expectedFiles = [
    'tests/sample_solutions/python/ac_solution.py',
    'tests/sample_solutions/python/wa_solution.py',
    'tests/sample_solutions/python/ce_solution.py',
    'tests/sample_solutions/python/re_solution.py',
    'tests/sample_solutions/python/tle_solution.py',
    'tests/sample_solutions/cpp/ac_solution.cpp',
    'tests/sample_solutions/cpp/wa_solution.cpp',
    'tests/sample_solutions/cpp/ce_solution.cpp',
    'tests/sample_solutions/cpp/re_solution.cpp',
    'tests/sample_solutions/cpp/tle_solution.cpp',
];

foreach ($expectedFiles as $relPath) {
    $fullPath = __DIR__ . '/../' . $relPath;
    if (!file_exists($fullPath) || filesize($fullPath) === 0) {
        echo "  [FAIL] File {$relPath} không tồn tại hoặc rỗng.\n";
        exit(1);
    }
}
echo "  [PASS] Đầy đủ 10/10 file mã nguồn mẫu đối chiếu (Python & C++).\n";

// 5. Kiểm thử đối chiếu trực tiếp: Chạy giải thuật chuẩn Python trên toàn bộ 51 ca kiểm thử
echo "\n[BƯỚC 5] Thực thi đối chiếu Python AC Solutions trên toàn bộ 51 ca kiểm thử:\n";
$pythonExe = 'C:\\Users\\Administrator\\AppData\\Local\\Programs\\Python\\Python311\\python.exe';
if (!file_exists($pythonExe)) {
    $pythonExe = 'python';
}

$problemScriptMap = [
    1 => 'p01_two_sum.py',
    2 => 'p02_prime_check.py',
    3 => 'p03_reverse_string.py',
    4 => 'p04_factorial.py',
    5 => 'p05_second_largest.py',
    6 => 'p06_valid_parentheses.py',
    7 => 'p07_gcd_lcm.py',
    8 => 'p08_binary_search.py',
    9 => 'p09_word_count.py',
    10 => 'p10_lis.py',
];

$totalVerified = 0;
foreach ($problems as $prob) {
    $script = __DIR__ . '/sample_solutions/all_problems_ac/' . $problemScriptMap[$prob['id']];
    if (!file_exists($script)) {
        echo "  [FAIL] Thiếu script AC: {$script}\n";
        exit(1);
    }

    $tests = $pdo->prepare("SELECT id, case_order, input_data, expected_output FROM test_cases WHERE problem_version_id = ? ORDER BY case_order ASC");
    $tests->execute([$prob['version_id']]);
    $testRows = $tests->fetchAll();

    foreach ($testRows as $t) {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w']
        ];
        $process = proc_open("\"{$pythonExe}\" \"{$script}\"", $descriptors, $pipes);
        if (!is_resource($process)) {
            echo "  [FAIL] Không thể chạy python process.\n";
            exit(1);
        }

        fwrite($pipes[0], $t['input_data']);
        fclose($pipes[0]);

        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);

        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);

        $cleanActual = trim(str_replace("\r\n", "\n", $stdout));
        $cleanExpected = trim(str_replace("\r\n", "\n", $t['expected_output']));

        if ($cleanActual !== $cleanExpected || $exitCode !== 0) {
            echo "  [FAIL] Bài {$prob['slug']} - Test Case #{$t['case_order']} không khớp!\n";
            echo "         Expected: [{$cleanExpected}]\n";
            echo "         Actual:   [{$cleanActual}]\n";
            echo "         Stderr:   [{$stderr}]\n";
            exit(1);
        }
        $totalVerified++;
    }
}
echo "  [PASS] 100% {$totalVerified}/51 ca kiểm thử đối chiếu đã cho kết quả Đúng (AC) chuẩn xác!\n";

echo "\n====================================================================\n";
echo "   KẾT QUẢ: TẤT CẢ CÁC MỤC GIAI ĐOẠN 1 ĐÃ HOÀN TẤT XUẤT SẮC!         \n";
echo "====================================================================\n";
