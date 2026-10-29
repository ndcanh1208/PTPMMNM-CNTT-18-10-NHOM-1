<?php
/**
 * TRANG BẢNG ĐIỀU KHIỂN DÀNH CHO GIẢNG VIÊN (LECTURER DASHBOARD)
 * Đề tài 20: 
 * - Chức năng 1: Quản lý bài tập, chủ đề, mức độ, giới hạn chạy, thêm bài mới
 * - Chức năng 4: Tra cứu tiến độ học viên theo lớp học, danh sách sinh viên
 * - Chức năng 5 & Tình huống 2: Tạo phiên bản bộ test mới (v2, v3...), chấm lại (rejudge) và đối chiếu lịch sử
 */

$pageTitle = 'Bảng Điều Khiển Giảng Viên - Quản Lý Đề & Tiến Độ Lớp';

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/core/JudgeCoordinator.php';

// Bắt buộc quyền Giảng viên hoặc Quản trị viên
requireRole(['LECTURER', 'ADMIN']);

$pdo = Database::getConnection();
$currentUser = getCurrentUser();
$lecturerId  = (int)$currentUser['id'];

$successMsg = null;
$errorMsg   = null;

// BẢNG MẪU MÃ NGUỒN GIẢI CHUẨN ĐỂ GỢI Ý CHO GIẢNG VIÊN
$starterSolutionTemplates = [
    'SUM_TWO_NUMBERS' => [
        'python3' => "a, b = map(int, input().split())\nprint(a + b)\n",
        'cpp' => "#include <iostream>\nusing namespace std;\n\nint main() {\n    long long a, b;\n    if (cin >> a >> b) {\n        cout << a + b << endl;\n    }\n    return 0;\n}\n"
    ],
    'PRIME_CHECK' => [
        'python3' => "import math\nn = int(input().strip())\n\ndef is_prime(x):\n    if x < 2: return False\n    for i in range(2, int(math.isqrt(x)) + 1):\n        if x % i == 0: return False\n    return True\n\nprint('YES' if is_prime(n) else 'NO')\n",
        'cpp' => "#include <iostream>\nusing namespace std;\n\nbool isPrime(long long n) {\n    if (n < 2) return false;\n    for (long long i = 2; i * i <= n; i++) {\n        if (n % i == 0) return false;\n    }\n    return true;\n}\n\nint main() {\n    long long n;\n    if (cin >> n) cout << (isPrime(n) ? \"YES\" : \"NO\") << endl;\n    return 0;\n}\n"
    ],
    'REVERSE_ARRAY' => [
        'python3' => "n = int(input().strip())\narr = list(map(int, input().split()))\nprint(*(arr[::-1]))\n",
        'cpp' => "#include <iostream>\n#include <vector>\nusing namespace std;\n\nint main() {\n    int n;\n    if (cin >> n) {\n        vector<long long> a(n);\n        for (int i = 0; i < n; i++) cin >> a[i];\n        for (int i = n - 1; i >= 0; i--) cout << a[i] << (i > 0 ? \" \" : \"\");\n        cout << endl;\n    }\n    return 0;\n}\n"
    ],
    'PALINDROME_STRING' => [
        'python3' => "s = input().strip()\nprint('YES' if s == s[::-1] else 'NO')\n",
        'cpp' => "#include <iostream>\n#include <string>\n#include <algorithm>\nusing namespace std;\n\nint main() {\n    string s;\n    if (cin >> s) {\n        string r = s;\n        reverse(r.begin(), r.end());\n        cout << (s == r ? \"YES\" : \"NO\") << endl;\n    }\n    return 0;\n}\n"
    ]
];

// Hàm sinh danh sách đầu vào (Inputs) đa dạng từ nhỏ, biên, đến cực lớn
function generateProblemInputs(string $probCode, int $count): array {
    $code = strtoupper(trim($probCode));
    $inputs = [];

    if ($code === 'SUM_TWO_NUMBERS') {
        $inputs[] = "10 25";
        $inputs[] = "0 0";
        $inputs[] = "-5 5";
        $inputs[] = "-450 -550";
        $inputs[] = "1000000 -1000000";
        $inputs[] = "9876543210 1234567890";
        $inputs[] = "-9999999999 9999999999";
        for ($i = count($inputs); $i < $count; $i++) {
            $a = random_int(-1000000000, 1000000000);
            $b = random_int(-1000000000, 1000000000);
            $inputs[] = "$a $b";
        }
    } elseif ($code === 'PRIME_CHECK') {
        $inputs[] = "7";
        $inputs[] = "1";
        $inputs[] = "2";
        $inputs[] = "0";
        $inputs[] = "-5";
        $inputs[] = "999999";
        $inputs[] = "1000003";
        $inputs[] = "9999991";
        $knownPrimes = [11, 13, 17, 19, 23, 29, 31, 37, 41, 43, 47, 53, 59, 61, 67, 71, 73, 79, 83, 89, 97, 10007, 10009, 100003, 1000033];
        for ($i = count($inputs); $i < $count; $i++) {
            if ($i % 2 === 0) {
                $inputs[] = (string)($knownPrimes[array_rand($knownPrimes)]);
            } else {
                $comp = random_int(4, 1000000);
                if ($comp % 2 === 1) $comp++;
                $inputs[] = (string)$comp;
            }
        }
    } elseif ($code === 'REVERSE_ARRAY') {
        $inputs[] = "5\n1 2 3 4 5";
        $inputs[] = "1\n999";
        $inputs[] = "4\n-10 0 0 10";
        $inputs[] = "5\n7 8 9 8 7";
        $inputs[] = "8\n15 42 -9 0 108 77 3 21";
        for ($i = count($inputs); $i < $count; $i++) {
            $n = random_int(3, 20);
            $arr = [];
            for ($k = 0; $k < $n; $k++) {
                $arr[] = random_int(-1000, 1000);
            }
            $inputs[] = $n . "\n" . implode(' ', $arr);
        }
    } elseif ($code === 'PALINDROME_STRING') {
        $inputs[] = "racecar";
        $inputs[] = "z";
        $inputs[] = "ab";
        $inputs[] = "noon";
        $inputs[] = "123454321";
        $chars = 'abcdefghijklmnopqrstuvwxyz';
        for ($i = count($inputs); $i < $count; $i++) {
            $len = random_int(3, 12);
            $str = '';
            for ($k = 0; $k < $len; $k++) {
                $str .= $chars[random_int(0, strlen($chars) - 1)];
            }
            if ($i % 2 === 0) {
                $str = $str . strrev($str);
            }
            $inputs[] = $str;
        }
    } else {
        // Tổng quát
        for ($i = 0; $i < $count; $i++) {
            $r1 = random_int(1, 1000);
            $r2 = random_int(1, 1000);
            $inputs[] = "$r1 $r2";
        }
    }

    return array_slice($inputs, 0, $count);
}

// ==============================================================
// XỬ LÝ CÁC THAO TÁC CỦA GIẢNG VIÊN (POST ACTIONS)
// ==============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // 1. THÊM BÀI TẬP MỚI (CHỨC NĂNG 1)
    if ($action === 'create_problem') {
        $code        = strtoupper(trim($_POST['code'] ?? ''));
        $title       = trim($_POST['title'] ?? '');
        $topic       = trim($_POST['topic'] ?? 'Cơ bản');
        $difficulty  = $_POST['difficulty'] ?? 'EASY';
        $timeLimit   = (int)($_POST['time_limit_ms'] ?? 1000);
        $memoryLimit = (int)($_POST['memory_limit_kb'] ?? 131072);
        $desc        = trim($_POST['description'] ?? '');
        $sampleIn    = trim($_POST['sample_input'] ?? '');
        $sampleOut   = trim($_POST['sample_output'] ?? '');
        $hiddenIn    = trim($_POST['hidden_input'] ?? '');
        $hiddenOut   = trim($_POST['hidden_output'] ?? '');

        if (empty($code) || empty($title) || empty($desc)) {
            $errorMsg = 'Vui lòng nhập đầy đủ mã bài, tiêu đề và mô tả đề bài.';
        } else {
            try {
                $pdo->beginTransaction();

                // Tạo bài tập trong bảng problems
                $stmt = $pdo->prepare("
                    INSERT INTO problems (code, title, topic, difficulty, author_id, is_published, created_at)
                    VALUES (?, ?, ?, ?, ?, 1, NOW())
                ");
                $stmt->execute([$code, $title, $topic, $difficulty, $lecturerId]);
                $problemId = (int)$pdo->lastInsertId();

                // Tạo phiên bản đầu tiên v1.0 trong problem_versions
                $stmtVer = $pdo->prepare("
                    INSERT INTO problem_versions (problem_id, version_number, description, time_limit_ms, memory_limit_kb, change_log, created_by, created_at)
                    VALUES (?, 1, ?, ?, ?, 'Khởi tạo đề bài và bộ dữ liệu kiểm tra ban đầu v1.0', ?, NOW())
                ");
                $stmtVer->execute([$problemId, $desc, $timeLimit, $memoryLimit, $lecturerId]);
                $versionId = (int)$pdo->lastInsertId();

                // Cập nhật current_version_id cho problem
                $pdo->prepare("UPDATE problems SET current_version_id = ? WHERE id = ?")->execute([$versionId, $problemId]);

                // Thêm test case mẫu (is_sample = 1)
                if (!empty($sampleIn) || !empty($sampleOut)) {
                    $pdo->prepare("
                        INSERT INTO test_cases (problem_version_id, input_data, expected_output, is_sample, score_weight, order_index)
                        VALUES (?, ?, ?, 1, 30, 1)
                    ")->execute([$versionId, $sampleIn, $sampleOut]);
                }

                // Thêm test case kín (is_sample = 0)
                if (!empty($hiddenIn) || !empty($hiddenOut)) {
                    $pdo->prepare("
                        INSERT INTO test_cases (problem_version_id, input_data, expected_output, is_sample, score_weight, order_index)
                        VALUES (?, ?, ?, 0, 70, 2)
                    ")->execute([$versionId, $hiddenIn, $hiddenOut]);
                }

                // Tự động gán vào lớp mặc định
                $pdo->prepare("INSERT IGNORE INTO class_problems (class_id, problem_id) VALUES (1, ?)")->execute([$problemId]);

                $pdo->commit();
                $successMsg = "Đã thêm thành công bài tập mới: [{$code}] {$title}!";
            } catch (Exception $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $errorMsg = 'Lỗi thêm bài tập: ' . $e->getMessage();
            }
        }
    }

    // 2. TẠO PHIÊN BẢN BỘ TEST MỚI (CHỨC NĂNG 5 & TÌNH HUỐNG 2)
    elseif ($action === 'create_version') {
        $problemId = (int)($_POST['problem_id'] ?? 0);
        $changeLog = trim($_POST['change_log'] ?? 'Cập nhật bộ dữ liệu kiểm tra mới');
        $newInput  = trim($_POST['new_test_input'] ?? '');
        $newOutput = trim($_POST['new_test_output'] ?? '');
        $isSample  = isset($_POST['is_sample']) ? 1 : 0;
        $weight    = (int)($_POST['score_weight'] ?? 20);

        if ($problemId <= 0 || empty($newInput) || empty($newOutput)) {
            $errorMsg = 'Vui lòng chọn bài tập và nhập đầy đủ dữ liệu ca kiểm thử mới.';
        } else {
            try {
                $pdo->beginTransaction();

                // Lấy thông tin phiên bản hiện tại
                $stmtCur = $pdo->prepare("SELECT * FROM problem_versions WHERE problem_id = ? ORDER BY version_number DESC LIMIT 1");
                $stmtCur->execute([$problemId]);
                $curVer = $stmtCur->fetch();

                $newVerNum = $curVer ? ((int)$curVer['version_number'] + 1) : 1;
                $desc      = $curVer ? $curVer['description'] : '';
                $timeLimit = $curVer ? (int)$curVer['time_limit_ms'] : 1000;
                $ramLimit  = $curVer ? (int)$curVer['memory_limit_kb'] : 131072;

                // Tạo bản ghi problem_versions mới
                $stmtNewVer = $pdo->prepare("
                    INSERT INTO problem_versions (problem_id, version_number, description, time_limit_ms, memory_limit_kb, change_log, created_by, created_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
                ");
                $stmtNewVer->execute([$problemId, $newVerNum, $desc, $timeLimit, $ramLimit, $changeLog, $lecturerId]);
                $newVerId = (int)$pdo->lastInsertId();

                // Sao chép toàn bộ test cases từ phiên bản cũ sang
                if ($curVer) {
                    $oldTests = $pdo->prepare("SELECT input_data, expected_output, is_sample, score_weight, order_index FROM test_cases WHERE problem_version_id = ?");
                    $oldTests->execute([(int)$curVer['id']]);
                    $tests = $oldTests->fetchAll();

                    $stmtInsertTest = $pdo->prepare("
                        INSERT INTO test_cases (problem_version_id, input_data, expected_output, is_sample, score_weight, order_index)
                        VALUES (?, ?, ?, ?, ?, ?)
                    ");
                    foreach ($tests as $t) {
                        $stmtInsertTest->execute([$newVerId, $t['input_data'], $t['expected_output'], $t['is_sample'], $t['score_weight'], $t['order_index']]);
                    }
                }

                // Thêm test case biên mới vào phiên bản này
                $nextOrder = (int)$pdo->query("SELECT COALESCE(MAX(order_index), 0) + 1 FROM test_cases WHERE problem_version_id = {$newVerId}")->fetchColumn();
                $pdo->prepare("
                    INSERT INTO test_cases (problem_version_id, input_data, expected_output, is_sample, score_weight, order_index)
                    VALUES (?, ?, ?, ?, ?, ?)
                ")->execute([$newVerId, $newInput, $newOutput, $isSample, $weight, $nextOrder]);

                // Đặt làm phiên bản chính thức
                $pdo->prepare("UPDATE problems SET current_version_id = ? WHERE id = ?")->execute([$newVerId, $problemId]);

                $pdo->commit();
                $successMsg = "Đã tạo thành công phiên bản v{$newVerNum}.0 kèm ca kiểm thử mới cho bài tập #{$problemId}!";
            } catch (Exception $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $errorMsg = 'Lỗi tạo phiên bản mới: ' . $e->getMessage();
            }
        }
    }

    // 3. TỰ ĐỘNG SINH BỘ TESTCASES BIÊN & NGẪU NHIÊN (AUTO-GENERATE TESTCASES)
    elseif ($action === 'auto_generate_tests') {
        $problemId    = (int)($_POST['problem_id'] ?? 0);
        $numTests     = max(3, min(100, (int)($_POST['num_tests'] ?? 10)));
        $solutionLang = trim($_POST['solution_lang'] ?? 'python3');
        $solutionCode = trim($_POST['solution_code'] ?? '');
        $customInText = trim($_POST['custom_inputs'] ?? '');
        
        $stmtP = $pdo->prepare("SELECT id, code, title, topic FROM problems WHERE id = ?");
        $stmtP->execute([$problemId]);
        $problem = $stmtP->fetch();

        if (!$problem) {
            $errorMsg = 'Không tìm thấy bài tập được chọn!';
        } else {
            $code = strtoupper(trim($problem['code']));

            // 1. Thu thập danh sách dữ liệu đầu vào (Inputs)
            $inputsList = [];
            if (!empty($customInText)) {
                $rawBlocks = preg_split('/(?:\r?\n){2,}|(?:\r?\n)===(?:\r?\n)/', $customInText);
                foreach ($rawBlocks as $b) {
                    $b = trim($b);
                    if (!empty($b)) $inputsList[] = $b;
                }
            }

            if (empty($inputsList)) {
                $inputsList = generateProblemInputs($code, $numTests);
            }

            $finalNumTests = count($inputsList);
            $generatedTests = [];
            $runError = null;

            // 2. Chạy mã giải mẫu (Reference Solution) để tính toán tự động đáp án chuẩn
            if (!empty($solutionCode)) {
                // Chạy thử với test đầu tiên để kiểm tra lỗi cú pháp (CE) hoặc runtime (RE)
                $firstCheck = ExecutionService::execute($solutionCode, $solutionLang, $inputsList[0], 4000);
                if ($firstCheck['status'] === VERDICT_CE) {
                    $runError = "Mã nguồn giải mẫu bị lỗi biên dịch / cú pháp: " . ($firstCheck['stderr'] ?: $firstCheck['message']);
                } elseif ($firstCheck['status'] === VERDICT_RE) {
                    $runError = "Mã nguồn giải mẫu bị lỗi khi chạy (Runtime Error): " . ($firstCheck['stderr'] ?: $firstCheck['message']) . " với input: [{$inputsList[0]}]";
                } elseif ($firstCheck['status'] === VERDICT_TLE) {
                    $runError = "Mã nguồn giải mẫu chạy quá thời gian (TLE) với input: [{$inputsList[0]}]";
                } elseif (empty(trim($firstCheck['stdout']))) {
                    $runError = "Mã nguồn giải mẫu không in ra kết quả gì (stdout rỗng) với input: [{$inputsList[0]}]. Hãy kiểm tra lại lệnh print() hoặc cout << !";
                }

                if (!$runError) {
                    $weightEach = round(100 / $finalNumTests, 2);
                    $sampleCount = max(1, min(2, (int)floor($finalNumTests * 0.2)));

                    foreach ($inputsList as $idx => $inp) {
                        $exec = ExecutionService::execute($solutionCode, $solutionLang, $inp, 4000);
                        if ($exec['status'] !== VERDICT_AC && $exec['status'] !== 'SUCCESS') {
                            $runError = "Lỗi khi chạy mã giải mẫu tại test #" . ($idx + 1) . ": " . ($exec['stderr'] ?: $exec['message']);
                            break;
                        }
                        $generatedTests[] = [
                            'input'     => $inp,
                            'output'    => trim($exec['stdout']),
                            'is_sample' => ($idx < $sampleCount) ? 1 : 0,
                            'weight'    => $weightEach
                        ];
                    }
                }
            } else {
                // Dự phòng: Nếu không nhập code mẫu, tự động tính bằng logic bài toán có sẵn
                $weightEach = round(100 / $finalNumTests, 2);
                $sampleCount = max(1, min(2, (int)floor($finalNumTests * 0.2)));

                foreach ($inputsList as $idx => $inp) {
                    $out = '';
                    if ($code === 'SUM_TWO_NUMBERS') {
                        $parts = preg_split('/\s+/', trim($inp));
                        $out = (string)((int)($parts[0] ?? 0) + (int)($parts[1] ?? 0));
                    } elseif ($code === 'PRIME_CHECK') {
                        $n = (int)trim($inp);
                        $isPrime = true;
                        if ($n < 2) $isPrime = false;
                        else {
                            for ($k = 2; $k * $k <= $n; $k++) {
                                if ($n % $k === 0) { $isPrime = false; break; }
                            }
                        }
                        $out = $isPrime ? 'YES' : 'NO';
                    } elseif ($code === 'REVERSE_ARRAY') {
                        $lines = explode("\n", trim($inp));
                        $arr = preg_split('/\s+/', trim($lines[1] ?? ''));
                        $out = implode(' ', array_reverse($arr));
                    } elseif ($code === 'PALINDROME_STRING') {
                        $s = trim($inp);
                        $out = ($s === strrev($s)) ? 'YES' : 'NO';
                    } else {
                        $out = 'OK';
                    }

                    $generatedTests[] = [
                        'input'     => $inp,
                        'output'    => $out,
                        'is_sample' => ($idx < $sampleCount) ? 1 : 0,
                        'weight'    => $weightEach
                    ];
                }
            }

            if ($runError) {
                $errorMsg = $runError;
            } else {
                try {
                    $pdo->beginTransaction();

                    // Lấy thông tin phiên bản gần nhất
                    $stmtCur = $pdo->prepare("SELECT * FROM problem_versions WHERE problem_id = ? ORDER BY version_number DESC LIMIT 1");
                    $stmtCur->execute([$problemId]);
                    $curVer = $stmtCur->fetch();

                    $newVerNum = $curVer ? ((int)$curVer['version_number'] + 1) : 1;
                    $desc      = $curVer ? $curVer['description'] : 'Mô tả bài tập';
                    $timeLimit = $curVer ? (int)$curVer['time_limit_ms'] : 1000;
                    $ramLimit  = $curVer ? (int)$curVer['memory_limit_kb'] : 131072;
                    $methodNote = !empty($solutionCode) ? "mã nguồn giải mẫu ({$solutionLang})" : "bộ sinh thuật toán tự động";
                    $changeLog = "⚡ Tự động sinh {$finalNumTests} ca kiểm thử bằng {$methodNote} v{$newVerNum}.0";

                    $stmtNewVer = $pdo->prepare("
                        INSERT INTO problem_versions (problem_id, version_number, description, time_limit_ms, memory_limit_kb, change_log, created_by, created_at)
                        VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
                    ");
                    $stmtNewVer->execute([$problemId, $newVerNum, $desc, $timeLimit, $ramLimit, $changeLog, $lecturerId]);
                    $newVerId = (int)$pdo->lastInsertId();

                    $stmtInsertTest = $pdo->prepare("
                        INSERT INTO test_cases (problem_version_id, input_data, expected_output, is_sample, score_weight, order_index)
                        VALUES (?, ?, ?, ?, ?, ?)
                    ");

                    $orderIdx = 1;
                    foreach ($generatedTests as $t) {
                        $stmtInsertTest->execute([
                            $newVerId,
                            $t['input'],
                            $t['output'],
                            $t['is_sample'],
                            $t['weight'],
                            $orderIdx++
                        ]);
                    }

                    // Cập nhật current_version_id
                    $pdo->prepare("UPDATE problems SET current_version_id = ? WHERE id = ?")->execute([$newVerId, $problemId]);

                    $pdo->commit();
                    $successMsg = "⚡ Đã chạy thành công mã nguồn giải mẫu ({$solutionLang}) qua {$finalNumTests} ca kiểm thử và nâng cấp bài tập [{$problem['code']}] lên phiên bản v{$newVerNum}.0!";
                } catch (Exception $e) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    $errorMsg = 'Lỗi lưu ca kiểm thử: ' . $e->getMessage();
                }
            }
        }
    }

    // 4. CHỈNH SỬA ĐỀ BÀI VÀ THÔNG SỐ BÀI TẬP
    elseif ($action === 'edit_problem') {
        $problemId      = (int)($_POST['problem_id'] ?? 0);
        $newTitle       = trim($_POST['title'] ?? '');
        $newTopic       = trim($_POST['topic'] ?? 'Cơ bản');
        $newDifficulty  = $_POST['difficulty'] ?? 'EASY';
        $newTimeLimit   = max(100, (int)($_POST['time_limit_ms'] ?? 1000));
        $newMemoryMb    = max(16, (int)($_POST['memory_limit_mb'] ?? 128));
        $newMemoryKb    = $newMemoryMb * 1024;
        $newDescription = trim($_POST['description'] ?? '');

        if ($problemId <= 0 || empty($newTitle) || empty($newDescription)) {
            $errorMsg = 'Vui lòng điền đầy đủ tiêu đề và nội dung mô tả đề bài!';
        } else {
            try {
                $pdo->beginTransaction();
                $pdo->prepare("UPDATE problems SET title = ?, topic = ?, difficulty = ? WHERE id = ?")
                    ->execute([$newTitle, $newTopic, $newDifficulty, $problemId]);

                $curVerId = (int)$pdo->query("SELECT current_version_id FROM problems WHERE id = {$problemId}")->fetchColumn();
                if ($curVerId > 0) {
                    $pdo->prepare("UPDATE problem_versions SET description = ?, time_limit_ms = ?, memory_limit_kb = ? WHERE id = ?")
                        ->execute([$newDescription, $newTimeLimit, $newMemoryKb, $curVerId]);
                }

                $pdo->commit();
                $successMsg = "Đã cập nhật thành công thông tin và nội dung đề bài [{$problemId}] {$newTitle}!";
            } catch (Exception $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $errorMsg = 'Lỗi cập nhật đề bài: ' . $e->getMessage();
            }
        }
    }
}

// ==============================================================
// TRUY VẤN DỮ LIỆU HIỂN THỊ
// ==============================================================
// 1. Danh sách bài tập
$stmtProblems = $pdo->query("
    SELECT p.id, p.code, p.title, p.topic, p.difficulty, p.created_at,
           pv.version_number, pv.time_limit_ms, pv.memory_limit_kb, pv.description,
           (SELECT COUNT(*) FROM test_cases tc WHERE tc.problem_version_id = pv.id) as test_count,
           (SELECT COUNT(*) FROM submissions s WHERE s.problem_id = p.id) as sub_count
    FROM problems p
    LEFT JOIN problem_versions pv ON p.current_version_id = pv.id
    ORDER BY p.id ASC
");
$allProblems = $stmtProblems->fetchAll();

// 2. Danh sách lớp học và tiến độ học viên
$classes = $pdo->query("SELECT * FROM classes ORDER BY id ASC")->fetchAll();

$selectedClassId = (int)($_GET['class_id'] ?? ($classes[0]['id'] ?? 1));

// Lấy danh sách học viên và thống kê tiến độ
$stmtStudents = $pdo->prepare("
    SELECT u.id, u.username, u.full_name, u.email,
           COUNT(DISTINCT s.id) as total_submitted,
           COUNT(DISTINCT CASE WHEN jr.verdict = 'ACCEPTED' THEN s.problem_id END) as passed_problems,
           (SELECT COUNT(*) FROM problems WHERE is_published = 1) as total_problems
    FROM class_members cm
    JOIN users u ON cm.student_id = u.id
    LEFT JOIN submissions s ON u.id = s.user_id
    LEFT JOIN judging_runs jr ON s.id = jr.submission_id AND jr.is_latest = 1
    WHERE cm.class_id = ?
    GROUP BY u.id, u.username, u.full_name, u.email
    ORDER BY u.id ASC
");
$stmtStudents->execute([$selectedClassId]);
$studentsProgress = $stmtStudents->fetchAll();

// 3. Danh sách bài nộp gần đây để chấm lại
$recentSubs = $pdo->query("
    SELECT s.id, s.problem_id, s.language_id, s.created_at,
           u.full_name, u.username,
           p.title as problem_title, p.code as problem_code,
           jr.id as run_id, jr.run_number, jr.verdict, jr.total_score, jr.passed_test_count, jr.total_test_count
    FROM submissions s
    JOIN users u ON s.user_id = u.id
    JOIN problems p ON s.problem_id = p.id
    LEFT JOIN judging_runs jr ON s.id = jr.submission_id AND jr.is_latest = 1
    ORDER BY s.id DESC
    LIMIT 20
")->fetchAll();

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/navbar.php';
?>

<div class="container-fluid px-lg-4 my-4">
    <!-- Tiêu đề & Thông tin Giảng viên -->
    <div class="d-flex align-items-center justify-content-between flex-wrap gap-3 mb-4 pb-3 border-bottom border-secondary border-opacity-25">
        <div>
            <div class="badge bg-warning bg-opacity-10 text-warning border border-warning border-opacity-25 px-3 py-1 rounded-pill mb-2">
                <i class="fas fa-chalkboard-user me-1"></i> Không Gian Quản Trị Giảng Viên
            </div>
            <h2 class="fw-bold text-white mb-1">Bảng Điều Khiển Giảng Viên</h2>
            <p class="text-secondary mb-0 small">
                Quản lý ngân hàng đề bài &bull; Cập nhật phiên bản bộ dữ liệu kiểm tra &bull; Điều phối chấm lại &bull; Theo dõi tiến độ lớp học
            </p>
        </div>
        <div class="d-flex gap-2 flex-wrap">
            <button type="button" class="btn btn-outline-info btn-sm px-3 fw-semibold" data-bs-toggle="modal" data-bs-target="#modalAutoGenerateTests">
                <i class="fas fa-bolt me-1 text-warning"></i> Tự Động Sinh Test Cases
            </button>
            <button type="button" class="btn btn-primary btn-sm px-3 fw-semibold" data-bs-toggle="modal" data-bs-target="#modalCreateProblem">
                <i class="fas fa-plus me-1"></i> Thêm Bài Tập Mới
            </button>
            <button type="button" class="btn btn-outline-warning btn-sm px-3 fw-semibold" data-bs-toggle="modal" data-bs-target="#modalCreateVersion">
                <i class="fas fa-code-branch me-1"></i> Tạo Phiên Bản Test Mới
            </button>
        </div>
    </div>

    <?php if ($successMsg): ?>
        <div class="alert alert-success bg-success bg-opacity-10 border-success text-success alert-dismissible fade show mb-4">
            <i class="fas fa-check-circle me-2"></i><?php echo htmlspecialchars($successMsg); ?>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if ($errorMsg): ?>
        <div class="alert alert-danger bg-danger bg-opacity-10 border-danger text-danger alert-dismissible fade show mb-4">
            <i class="fas fa-triangle-exclamation me-2"></i><?php echo htmlspecialchars($errorMsg); ?>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- 4 Tab Chức Năng Của Giảng Viên -->
    <ul class="nav nav-tabs border-secondary border-opacity-25 mb-4" id="lecturerTabs">
        <li class="nav-item">
            <a class="nav-link active text-white fw-semibold" data-bs-toggle="tab" href="#tabProblems">
                <i class="fas fa-book-bookmark me-1 text-primary"></i> Quản Lý Bài Tập (<?php echo count($allProblems); ?>)
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link text-white fw-semibold" data-bs-toggle="tab" href="#tabProgress">
                <i class="fas fa-chart-pie me-1 text-info"></i> Tiến Độ Lớp Học (Chức năng 4)
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link text-white fw-semibold" data-bs-toggle="tab" href="#tabRejudge">
                <i class="fas fa-rotate me-1 text-warning"></i> Chấm Lại &amp; Đối Chiếu (Chức năng 5)
            </a>
        </li>
    </ul>

    <div class="tab-content">
        <!-- ============================================================== -->
        <!-- TAB 1: QUẢN LÝ BÀI TẬP (CHỨC NĂNG 1) -->
        <!-- ============================================================== -->
        <div class="tab-pane fade show active" id="tabProblems">
            <div class="card bg-dark border-secondary border-opacity-25 shadow-sm">
                <div class="card-header bg-transparent border-secondary border-opacity-25 d-flex justify-content-between align-items-center py-3">
                    <span class="text-white fw-bold">
                        <i class="fas fa-list-check me-2 text-primary"></i>Danh Sách Đề Bài Trong Hệ Thống
                    </span>
                    <button type="button" class="btn btn-outline-primary btn-sm px-3" data-bs-toggle="modal" data-bs-target="#modalCreateProblem">
                        <i class="fas fa-plus me-1"></i> Soạn Đề Mới
                    </button>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-dark table-hover align-middle mb-0">
                            <thead class="text-secondary small border-bottom border-secondary border-opacity-25">
                                <tr>
                                    <th class="ps-4 py-3">Mã Bài</th>
                                    <th>Tiêu Đề</th>
                                    <th>Chủ Đề</th>
                                    <th>Độ Khó</th>
                                    <th>Phiên Bản Hiện Tại</th>
                                    <th>Số Test Cases</th>
                                    <th>Lượt Nộp</th>
                                    <th class="pe-4 text-end">Thao Tác</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($allProblems as $p): 
                                    $diffBadge = ($p['difficulty'] === 'EASY') ? 'bg-success' : (($p['difficulty'] === 'MEDIUM') ? 'bg-warning text-dark' : 'bg-danger');
                                ?>
                                    <tr>
                                        <td class="ps-4 font-monospace fw-bold text-info">#<?php echo $p['id']; ?> [<?php echo htmlspecialchars($p['code']); ?>]</td>
                                        <td class="text-white fw-semibold">
                                            <a href="solve.php?id=<?php echo $p['id']; ?>" class="text-white text-decoration-none hover-primary">
                                                <?php echo htmlspecialchars($p['title']); ?>
                                            </a>
                                        </td>
                                        <td><span class="badge bg-dark border border-secondary text-secondary"><?php echo htmlspecialchars($p['topic']); ?></span></td>
                                        <td><span class="badge <?php echo $diffBadge; ?>"><?php echo $p['difficulty']; ?></span></td>
                                        <td><span class="badge bg-primary bg-opacity-20 text-primary border border-primary border-opacity-25">v<?php echo $p['version_number'] ?? 1; ?>.0</span></td>
                                        <td>
                                            <button type="button" class="btn btn-outline-info btn-sm py-0 px-2 fw-semibold btn-view-testcases" 
                                                    data-problem-id="<?php echo $p['id']; ?>" 
                                                    data-problem-code="<?php echo htmlspecialchars($p['code']); ?>" 
                                                    data-problem-title="<?php echo htmlspecialchars($p['title']); ?>"
                                                    title="Bấm để xem danh sách toàn bộ <?php echo $p['test_count']; ?> ca kiểm thử">
                                                <i class="fas fa-list-check me-1"></i><span class="fw-bold"><?php echo $p['test_count']; ?></span> tests
                                            </button>
                                        </td>
                                        <td><span class="text-secondary"><?php echo $p['sub_count']; ?> bài</span></td>
                                        <td class="pe-4 text-end text-nowrap">
                                            <button type="button" class="btn btn-info btn-sm py-1 px-2 text-dark fw-bold btn-view-testcases" 
                                                    data-problem-id="<?php echo $p['id']; ?>" 
                                                    data-problem-code="<?php echo htmlspecialchars($p['code']); ?>" 
                                                    data-problem-title="<?php echo htmlspecialchars($p['title']); ?>" 
                                                    title="Xem toàn bộ ca kiểm thử (công khai & kín)">
                                                <i class="fas fa-eye me-1"></i>Xem test
                                            </button>
                                            <button type="button" class="btn btn-warning btn-sm py-1 px-2 ms-1 btn-edit-prob-lecturer" 
                                                    data-id="<?php echo $p['id']; ?>" 
                                                    data-code="<?php echo htmlspecialchars($p['code']); ?>" 
                                                    data-title="<?php echo htmlspecialchars($p['title']); ?>" 
                                                    data-topic="<?php echo htmlspecialchars($p['topic']); ?>" 
                                                    data-diff="<?php echo $p['difficulty']; ?>" 
                                                    data-time="<?php echo $p['time_limit_ms']; ?>" 
                                                    data-ram="<?php echo round(($p['memory_limit_kb'] ?? 131072) / 1024); ?>" 
                                                    data-desc="<?php echo htmlspecialchars($p['description'] ?? ''); ?>" 
                                                    title="Sửa đề bài">
                                                <i class="fas fa-edit me-1"></i>Sửa đề
                                            </button>
                                            <a href="solve.php?id=<?php echo $p['id']; ?>" class="btn btn-outline-info btn-sm py-1 px-2 ms-1" title="Xem đề và làm thử">
                                                <i class="fas fa-play me-1"></i>Làm thử
                                            </a>
                                            <button type="button" class="btn btn-outline-success btn-sm py-1 px-2 ms-1 btn-quick-autogen" data-problem-id="<?php echo $p['id']; ?>" data-problem-title="<?php echo htmlspecialchars($p['title']); ?>" title="Tự động sinh test cases từ code mẫu">
                                                <i class="fas fa-bolt me-1"></i>Sinh test
                                            </button>
                                            <button type="button" class="btn btn-outline-secondary btn-sm py-1 px-2 ms-1 btn-quick-newver" data-problem-id="<?php echo $p['id']; ?>" data-problem-title="<?php echo htmlspecialchars($p['title']); ?>" title="Tạo phiên bản mới thủ công">
                                                <i class="fas fa-code-branch me-1"></i>Tạo bản v+
                                            </button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- ============================================================== -->
        <!-- TAB 2: TIẾN ĐỘ HỌC VIÊN THEO LỚP (CHỨC NĂNG 4) -->
        <!-- ============================================================== -->
        <div class="tab-pane fade" id="tabProgress">
            <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-3">
                <div class="d-flex align-items-center gap-2">
                    <label class="text-secondary small fw-bold">Chọn lớp phụ trách:</label>
                    <select class="form-select form-select-sm bg-dark text-white border-secondary" style="width: auto;" onchange="location.href='lecturer.php?class_id=' + this.value">
                        <?php foreach ($classes as $c): ?>
                            <option value="<?php echo $c['id']; ?>" <?php echo ($c['id'] == $selectedClassId) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($c['class_code'] . ' - ' . $c['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="text-secondary small">
                    Tổng số sinh viên trong lớp: <strong class="text-white"><?php echo count($studentsProgress); ?></strong> bạn
                </div>
            </div>

            <div class="card bg-dark border-secondary border-opacity-25 shadow-sm">
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-dark table-hover align-middle mb-0">
                            <thead class="text-secondary small border-bottom border-secondary border-opacity-25">
                                <tr>
                                    <th class="ps-4 py-3">Mã SV</th>
                                    <th>Họ và Tên</th>
                                    <th>Email</th>
                                    <th>Số Bài Nộp</th>
                                    <th>Số Bài Đã Giải Đúng (AC)</th>
                                    <th>Tiến Độ Hoàn Thành</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($studentsProgress)): ?>
                                    <tr>
                                        <td colspan="6" class="text-center py-4 text-secondary">Chưa có sinh viên nào trong lớp này.</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($studentsProgress as $sp): 
                                        $totalProbs = max(1, (int)$sp['total_problems']);
                                        $passed = (int)$sp['passed_problems'];
                                        $percent = min(100, round(($passed / $totalProbs) * 100));
                                    ?>
                                        <tr>
                                            <td class="ps-4 font-monospace text-info"><?php echo htmlspecialchars($sp['username']); ?></td>
                                            <td class="text-white fw-semibold"><?php echo htmlspecialchars($sp['full_name']); ?></td>
                                            <td class="text-secondary small"><?php echo htmlspecialchars($sp['email']); ?></td>
                                            <td><span class="badge bg-secondary"><?php echo $sp['total_submitted']; ?> lượt</span></td>
                                            <td>
                                                <span class="badge bg-success bg-opacity-20 text-success border border-success border-opacity-25 px-2 py-1">
                                                    <i class="fas fa-check-circle me-1"></i><?php echo $passed; ?> / <?php echo $totalProbs; ?> bài
                                                </span>
                                            </td>
                                            <td style="width: 250px;">
                                                <div class="d-flex align-items-center gap-2">
                                                    <div class="progress flex-grow-1 bg-black" style="height: 8px;">
                                                        <div class="progress-bar bg-success" style="width: <?php echo $percent; ?>%;"></div>
                                                    </div>
                                                    <span class="small fw-bold text-white"><?php echo $percent; ?>%</span>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- ============================================================== -->
        <!-- TAB 3: CHẤM LẠI & ĐỐI CHIẾU (CHỨC NĂNG 5 & TÌNH HUỐNG 2) -->
        <!-- ============================================================== -->
        <div class="tab-pane fade" id="tabRejudge">
            <div class="alert alert-dark border-secondary p-3 mb-3 text-secondary small">
                <i class="fas fa-circle-info text-info me-2"></i>
                <strong>Quy chuẩn Rubric:</strong> Khi giảng viên sửa hoặc bổ sung ca kiểm thử (Tạo phiên bản mới), hệ thống hỗ trợ <strong>chấm lại (Rejudge)</strong> toàn bộ bài nộp trên bộ dữ liệu mới. Lịch sử lần chấm cũ (Run 1) và mã nguồn gốc được bảo tồn nguyên vẹn để đối chiếu.
            </div>

            <div class="card bg-dark border-secondary border-opacity-25 shadow-sm">
                <div class="card-header bg-transparent border-secondary border-opacity-25 py-3 text-white fw-bold">
                    <i class="fas fa-clock-rotate-left me-2 text-warning"></i>Danh Sách Bài Nộp Gần Đây (Có Thể Chấm Lại)
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-dark table-hover align-middle mb-0">
                            <thead class="text-secondary small border-bottom border-secondary border-opacity-25">
                                <tr>
                                    <th class="ps-4 py-3">Mã Nộp</th>
                                    <th>Học Viên</th>
                                    <th>Bài Tập</th>
                                    <th>Ngôn Ngữ</th>
                                    <th>Lượt Chấm</th>
                                    <th>Kết Luận</th>
                                    <th>Điểm Số</th>
                                    <th>Nộp Lúc</th>
                                    <th class="pe-4 text-end">Chấm Lại</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($recentSubs as $rs): 
                                    $v = $rs['verdict'] ?? 'PENDING';
                                    $vClass = ($v === 'ACCEPTED') ? 'bg-success' : 'bg-danger';
                                ?>
                                    <tr>
                                        <td class="ps-4 fw-bold text-secondary">#<?php echo $rs['id']; ?></td>
                                        <td class="text-white"><?php echo htmlspecialchars($rs['full_name']); ?></td>
                                        <td>
                                            <span class="text-light fw-semibold"><?php echo htmlspecialchars($rs['problem_title']); ?></span>
                                            <code class="text-secondary small ms-1">[<?php echo $rs['problem_code']; ?>]</code>
                                        </td>
                                        <td><span class="badge bg-dark border border-secondary text-info"><?php echo $rs['language_id']; ?></span></td>
                                        <td>
                                            <span class="badge bg-secondary">
                                                Lần <?php echo $rs['run_number'] ?? 1; ?>
                                            </span>
                                        </td>
                                        <td><span class="badge <?php echo $vClass; ?>"><?php echo $v; ?></span></td>
                                        <td><span class="text-warning fw-bold"><?php echo $rs['total_score'] ?? 0; ?>đ</span></td>
                                        <td class="text-secondary small"><?php echo date('H:i d/m', strtotime($rs['created_at'])); ?></td>
                                        <td class="pe-4 text-end">
                                            <button type="button" class="btn btn-outline-warning btn-sm px-3 py-1 btn-trigger-rejudge" data-sub-id="<?php echo $rs['id']; ?>">
                                                <i class="fas fa-rotate me-1"></i>Chấm lại
                                            </button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ============================================================== -->
<!-- MODAL 1: THÊM BÀI TẬP MỚI (CHỨC NĂNG 1) -->
<!-- ============================================================== -->
<div class="modal fade" id="modalCreateProblem" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content bg-dark text-white border border-secondary">
            <div class="modal-header border-secondary">
                <h5 class="modal-title fw-bold text-primary">
                    <i class="fas fa-plus-circle me-2"></i>Soạn Thảo Bài Tập Mới
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="create_problem">
                <div class="modal-body p-4">
                    <div class="row g-3 mb-3">
                        <div class="col-md-4">
                            <label class="form-label small fw-bold text-secondary">Mã bài viết tắt (Unique):</label>
                            <input type="text" name="code" class="form-control bg-black text-white border-secondary" placeholder="VD: SUM_N, FIBO_REC" required>
                        </div>
                        <div class="col-md-8">
                            <label class="form-label small fw-bold text-secondary">Tiêu đề bài tập:</label>
                            <input type="text" name="title" class="form-control bg-black text-white border-secondary" placeholder="VD: Tính tổng N số nguyên đầu tiên" required>
                        </div>
                    </div>

                    <div class="row g-3 mb-3">
                        <div class="col-md-4">
                            <label class="form-label small fw-bold text-secondary">Chủ đề:</label>
                            <input type="text" name="topic" class="form-control bg-black text-white border-secondary" placeholder="Toán học, Mảng, Chuỗi..." value="Cơ bản">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small fw-bold text-secondary">Độ khó:</label>
                            <select name="difficulty" class="form-select bg-black text-white border-secondary">
                                <option value="EASY">Dễ (EASY)</option>
                                <option value="MEDIUM">Trung bình (MEDIUM)</option>
                                <option value="HARD">Khó (HARD)</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small fw-bold text-secondary">Giới hạn thời gian (ms):</label>
                            <input type="number" name="time_limit_ms" class="form-control bg-black text-white border-secondary" value="1000">
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label small fw-bold text-secondary">Mô tả đề bài &amp; Quy cách I/O:</label>
                        <textarea name="description" class="form-control bg-black text-white border-secondary" rows="4" placeholder="Nhập mô tả bài toán, định dạng dữ liệu đầu vào (stdin) và đầu ra (stdout)..." required></textarea>
                    </div>

                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label class="form-label small fw-bold text-info">Ca kiểm thử mẫu công khai (Sample Input):</label>
                            <textarea name="sample_input" class="form-control bg-black text-white border-secondary font-monospace small" rows="2" placeholder="VD: 5"></textarea>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-bold text-info">Đầu ra mẫu mong đợi (Sample Output):</label>
                            <textarea name="sample_output" class="form-control bg-black text-white border-secondary font-monospace small" rows="2" placeholder="VD: 15"></textarea>
                        </div>
                    </div>

                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label small fw-bold text-warning">Ca kiểm thử chấm kín (Hidden Input):</label>
                            <textarea name="hidden_input" class="form-control bg-black text-white border-secondary font-monospace small" rows="2" placeholder="VD: 1000"></textarea>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-bold text-warning">Đầu ra kín mong đợi (Hidden Output):</label>
                            <textarea name="hidden_output" class="form-control bg-black text-white border-secondary font-monospace small" rows="2" placeholder="VD: 500500"></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer border-secondary">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Hủy</button>
                    <button type="submit" class="btn btn-primary btn-sm px-4 fw-semibold">
                        <i class="fas fa-save me-1"></i> Lưu Bài Tập
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ============================================================== -->
<!-- MODAL 2: TẠO PHIÊN BẢN TEST MỚI (CHỨC NĂNG 5 & TÌNH HUỐNG 2) -->
<!-- ============================================================== -->
<div class="modal fade" id="modalCreateVersion" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content bg-dark text-white border border-secondary">
            <div class="modal-header border-secondary">
                <h5 class="modal-title fw-bold text-warning">
                    <i class="fas fa-code-branch me-2"></i>Tạo Phiên Bản Bộ Dữ Liệu Kiểm Tra Mới
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="create_version">
                <div class="modal-body p-4">
                    <div class="mb-3">
                        <label class="form-label small fw-bold text-secondary">Chọn bài tập cần nâng cấp phiên bản:</label>
                        <select name="problem_id" id="selectVersionProblem" class="form-select bg-black text-white border-secondary" required>
                            <?php foreach ($allProblems as $p): ?>
                                <option value="<?php echo $p['id']; ?>">
                                    [<?php echo $p['code']; ?>] <?php echo htmlspecialchars($p['title']); ?> (Hiện tại: v<?php echo $p['version_number'] ?? 1; ?>.0)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label small fw-bold text-secondary">Ghi chú lý do cập nhật (Changelog):</label>
                        <input type="text" name="change_log" class="form-control bg-black text-white border-secondary" placeholder="VD: Bổ sung ca kiểm thử biên số âm và cực hạn 10^15" required>
                    </div>

                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label class="form-label small fw-bold text-light">Dữ liệu đầu vào ca kiểm thử biên mới (stdin):</label>
                            <textarea name="new_test_input" class="form-control bg-black text-white border-secondary font-monospace" rows="3" placeholder="Nhập input của test case mới..." required></textarea>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-bold text-light">Đầu ra chuẩn mong đợi (stdout):</label>
                            <textarea name="new_test_output" class="form-control bg-black text-white border-secondary font-monospace" rows="3" placeholder="Nhập đáp án chuẩn..." required></textarea>
                        </div>
                    </div>

                    <div class="d-flex align-items-center justify-content-between p-3 rounded bg-black border border-secondary border-opacity-50">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="is_sample" id="checkIsSample">
                            <label class="form-check-label text-light small fw-bold" for="checkIsSample">
                                Đây là test mẫu công khai (hiển thị cho sinh viên thấy)
                            </label>
                            <div class="text-secondary small">Mặc định không tích: Test kín bảo mật (che input/output trước sinh viên).</div>
                        </div>
                        <div class="d-flex align-items-center gap-2">
                            <label class="text-secondary small text-nowrap">Điểm test:</label>
                            <input type="number" name="score_weight" class="form-control form-control-sm bg-dark text-white border-secondary" style="width: 80px;" value="20">
                        </div>
                    </div>
                </div>
                <div class="modal-footer border-secondary">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Hủy</button>
                    <button type="submit" class="btn btn-warning btn-sm px-4 fw-semibold text-dark">
                        <i class="fas fa-check me-1"></i> Tạo Phiên Bản &amp; Kích Hoạt
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
<!-- ============================================================== -->
<!-- MODAL 3: TỰ ĐỘNG SINH TESTCASES BIÊN & RANDOM (CHỨC NĂNG NÂNG CAO) -->
<!-- ============================================================== -->
<div class="modal fade" id="modalAutoGenerateTests" tabindex="-1">
    <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
        <form method="POST" id="formAutoGenTests" class="modal-content bg-dark text-white border border-secondary shadow-lg">
            <input type="hidden" name="action" value="auto_generate_tests">
            <div class="modal-header border-secondary">
                <h5 class="modal-title fw-bold text-info">
                    <i class="fas fa-wand-magic-sparkles text-warning me-2"></i>Tự Động Sinh 5 - 100 Ca Kiểm Thử Từ Mã Nguồn Mẫu (Reference Solution)
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4" style="max-height: calc(85vh - 130px); overflow-y: auto;">
                <div class="alert alert-info bg-info bg-opacity-10 border-info border-opacity-25 text-light small mb-3">
                    <div class="d-flex gap-2">
                        <i class="fas fa-robot text-info fa-lg mt-1"></i>
                        <div>
                            <strong>Cơ chế tự động sinh đáp án chuẩn (Oracle/Reference Solution):</strong>
                            Giảng viên chỉ cần dán lời giải chuẩn (Python hoặc C++). Hệ thống sẽ tự động sinh các tập Input đa dạng (giá trị cực tiểu, số âm, test biên, số cực lớn $10^9$, mảng lớn...) và điều phối máy chấm thực thi mã mẫu để sinh ra <strong>expected_output chính xác tuyệt đối</strong>, sau đó đóng gói thành phiên bản đề mới.
                        </div>
                    </div>
                </div>

                <div class="row g-3 mb-3">
                    <div class="col-md-5">
                        <label class="form-label small fw-bold text-secondary">Chọn bài tập cần sinh test <span class="text-danger">*</span>:</label>
                        <select name="problem_id" id="selectAutoGenProblem" class="form-select bg-black text-white border-secondary" required>
                            <?php foreach ($allProblems as $p): ?>
                                <option value="<?php echo $p['id']; ?>" data-code="<?php echo htmlspecialchars($p['code']); ?>">
                                    [<?php echo $p['code']; ?>] <?php echo htmlspecialchars($p['title']); ?> (Hiện tại: v<?php echo $p['version_number'] ?? 1; ?>.0 - <?php echo $p['test_count']; ?> tests)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small fw-bold text-secondary">Số lượng ca kiểm thử cần sinh:</label>
                        <select name="num_tests" id="selectNumTests" class="form-select bg-black text-white border-secondary">
                            <option value="5">5 Test cases (Mặc định)</option>
                            <option value="10" selected>10 Test cases (Khuyên dùng)</option>
                            <option value="20">20 Test cases (Kiểm thử mở rộng)</option>
                            <option value="50">50 Test cases (Tải lớn)</option>
                            <option value="100">100 Test cases (Stress test cực đại)</option>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small fw-bold text-secondary">Ngôn ngữ của mã giải mẫu:</label>
                        <select name="solution_lang" id="selectSolutionLang" class="form-select bg-black text-white border-secondary">
                            <option value="python3" selected>Python 3 (Nhanh &amp; Tiện lợi)</option>
                            <option value="cpp">C++ 17 (Tối ưu tốc độ cao)</option>
                        </select>
                    </div>
                </div>

                <div class="mb-3">
                    <div class="d-flex justify-content-between align-items-center mb-1">
                        <label class="form-label small fw-bold text-secondary mb-0">
                            Mã nguồn giải mẫu chuẩn (Reference Solution) <span class="text-danger">*</span>:
                        </label>
                        <button type="button" class="btn btn-outline-info btn-xs py-0 px-2 small" id="btnFillStarterCode">
                            <i class="fas fa-code me-1"></i> Tải mã giải gợi ý cho bài này
                        </button>
                    </div>
                    <textarea name="solution_code" id="autogenSolutionCode" class="form-control bg-black text-success font-monospace border-secondary" rows="9" style="font-size: 13.5px; line-height: 1.5;" placeholder="Dán mã nguồn Python 3 hoặc C++ giải đúng bài toán vào đây... Hệ thống sẽ chạy mã này trên máy chấm để tự động lấy expected_output cho từng test case." required></textarea>
                </div>

                <div class="accordion mb-3" id="accordionCustomInput">
                    <div class="accordion-item bg-black border-secondary border-opacity-50">
                        <h2 class="accordion-header">
                            <button class="accordion-button collapsed bg-transparent text-secondary py-2 small" type="button" data-bs-toggle="collapse" data-bs-target="#collapseCustomInput">
                                <i class="fas fa-sliders me-2 text-warning"></i> (Tùy chọn) Tự nhập danh sách bộ Input tùy chỉnh của Giảng viên
                            </button>
                        </h2>
                        <div id="collapseCustomInput" class="accordion-collapse collapse" data-bs-parent="#accordionCustomInput">
                            <div class="accordion-body p-3">
                                <p class="text-secondary small mb-2">
                                    Nếu bỏ trống trường này, hệ thống sẽ <strong>tự động sinh ngẫu nhiên và bao phủ các trường hợp biên</strong> tương ứng theo số lượng test đã chọn. Nếu bạn muốn tự định nghĩa input, hãy phân tách mỗi test case bằng 2 dấu xuống dòng hoặc dòng <code>===</code>:
                                </p>
                                <textarea name="custom_inputs" class="form-control bg-dark text-white font-monospace border-secondary small" rows="5" placeholder="Ví dụ Input 1:&#10;10 20&#10;&#10;Ví dụ Input 2:&#10;-100 500&#10;&#10;Ví dụ Input 3:&#10;9999999999 1"></textarea>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="p-3 rounded bg-black border border-secondary border-opacity-50">
                    <div class="fw-bold text-warning small mb-2"><i class="fas fa-shield-halved me-1"></i> Quy chuẩn an toàn &amp; phân quyền Đề tài 20:</div>
                    <div class="row text-secondary small g-2">
                        <div class="col-md-4">
                            <i class="fas fa-check text-success me-1"></i> <strong>Tự kiểm tra CE/RE</strong>: Chạy thử trước test 1 để đảm bảo code mẫu không lỗi cú pháp.
                        </div>
                        <div class="col-md-4">
                            <i class="fas fa-eye me-1 text-info"></i> <strong>Test công khai &amp; Test kín</strong>: 1-2 test đầu tiên làm mẫu (is_sample=1), còn lại là test kín (is_sample=0).
                        </div>
                        <div class="col-md-4">
                            <i class="fas fa-code-branch me-1 text-warning"></i> <strong>Tạo phiên bản mới</strong>: Tự động nâng phiên bản đề bài (vX.0) để Giảng viên có thể Chấm lại (Rejudge).
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer border-secondary">
                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Đóng</button>
                <button type="submit" class="btn btn-info btn-sm px-4 fw-bold text-dark" id="btnSubmitAutoGen">
                    <i class="fas fa-bolt me-1"></i> Thực Thi &amp; Tạo Phiên Bản Test Mới
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ============================================================== -->
<!-- MODAL 4: CHỈNH SỬA ĐỀ BÀI (DÀNH CHO GIẢNG VIÊN) -->
<!-- ============================================================== -->
<div class="modal fade" id="modalEditProblemLecturer" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <form method="POST" class="modal-content bg-dark text-white border border-secondary shadow-lg">
            <input type="hidden" name="action" value="edit_problem">
            <input type="hidden" name="problem_id" id="editProblemId">
            <div class="modal-header border-secondary">
                <h5 class="modal-title fw-bold text-warning">
                    <i class="fas fa-pen-to-square me-2"></i>Chỉnh Sửa Thông Tin &amp; Đề Bài
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4" style="max-height: calc(85vh - 130px); overflow-y: auto;">
                <div class="row g-3 mb-3">
                    <div class="col-md-4">
                        <label class="form-label small fw-bold text-secondary">Mã bài:</label>
                        <input type="text" id="editProblemCode" class="form-control bg-black text-info font-monospace border-secondary" readonly>
                    </div>
                    <div class="col-md-8">
                        <label class="form-label small fw-bold text-secondary">Tiêu đề bài tập <span class="text-danger">*</span>:</label>
                        <input type="text" name="title" id="editProblemTitle" class="form-control bg-black text-white border-secondary" required>
                    </div>
                </div>

                <div class="row g-3 mb-3">
                    <div class="col-md-4">
                        <label class="form-label small fw-bold text-secondary">Chủ đề:</label>
                        <input type="text" name="topic" id="editProblemTopic" class="form-control bg-black text-white border-secondary">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small fw-bold text-secondary">Độ khó:</label>
                        <select name="difficulty" id="editProblemDifficulty" class="form-select bg-black text-white border-secondary">
                            <option value="EASY">Dễ (EASY)</option>
                            <option value="MEDIUM">Trung bình (MEDIUM)</option>
                            <option value="HARD">Khó (HARD)</option>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small fw-bold text-secondary">Giới hạn thời gian (ms):</label>
                        <input type="number" name="time_limit_ms" id="editProblemTimeLimit" class="form-control bg-black text-white border-secondary" min="100" max="10000" step="100" required>
                    </div>
                </div>

                <div class="mb-3">
                    <label class="form-label small fw-bold text-secondary">Giới hạn bộ nhớ (MB):</label>
                    <input type="number" name="memory_limit_mb" id="editProblemMemoryLimit" class="form-control bg-black text-white border-secondary" min="16" max="1024" step="16" required>
                </div>

                <div class="mb-3">
                    <label class="form-label small fw-bold text-secondary">Nội Dung Đề Bài Chi Tiết <span class="text-danger">*</span>:</label>
                    <textarea name="description" id="editProblemDescription" class="form-control bg-black text-white font-monospace border-secondary" rows="8" style="font-size: 13.5px; line-height: 1.6;" required></textarea>
                </div>
            </div>
            <div class="modal-footer border-secondary">
                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Hủy</button>
                <button type="submit" class="btn btn-warning btn-sm px-4 fw-bold">
                    <i class="fas fa-floppy-disk me-1"></i> Lưu Cập Nhật Đề Bài
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ============================================================== -->
<!-- MODAL 5: XEM CHI TIẾT TOÀN BỘ TEST CASES (DÀNH CHO GIẢNG VIÊN) -->
<!-- ============================================================== -->
<div class="modal fade" id="modalViewTestCases" tabindex="-1">
    <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content bg-dark text-white border border-secondary shadow-lg">
            <div class="modal-header border-secondary py-3">
                <div class="d-flex align-items-center gap-2 flex-wrap">
                    <span class="badge bg-info bg-opacity-20 text-info border border-info border-opacity-25 px-2 py-1">
                        <i class="fas fa-database me-1"></i> Ngân Hàng Test Cases
                    </span>
                    <h5 class="modal-title fw-bold text-white mb-0" id="vtProblemTitle">
                        Chi Tiết Ca Kiểm Thử
                    </h5>
                </div>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>

            <!-- Thanh điều khiển: Chọn phiên bản, Thống kê & Bộ lọc -->
            <div class="bg-black border-bottom border-secondary border-opacity-50 px-4 py-3">
                <div class="d-flex align-items-center justify-content-between flex-wrap gap-3">
                    <div class="d-flex align-items-center gap-2 flex-wrap">
                        <label class="text-secondary small fw-bold text-nowrap"><i class="fas fa-code-branch me-1 text-warning"></i>Phiên bản:</label>
                        <select id="vtSelectVersion" class="form-select form-select-sm bg-dark text-info font-monospace border-secondary" style="width: auto; min-width: 140px;">
                            <option value="">Đang tải...</option>
                        </select>

                        <div class="d-flex gap-2 align-items-center ms-lg-2 flex-wrap" id="vtStatsBadges">
                            <span class="badge bg-secondary" id="vtBadgeTotal">0 tests</span>
                            <span class="badge bg-success bg-opacity-20 text-success border border-success border-opacity-25" id="vtBadgeSample">0 công khai</span>
                            <span class="badge bg-warning bg-opacity-20 text-warning border border-warning border-opacity-25" id="vtBadgeHidden">0 test kín</span>
                            <span class="badge bg-info bg-opacity-20 text-info border border-info border-opacity-25" id="vtBadgeScore">100đ</span>
                        </div>
                    </div>

                    <div class="d-flex align-items-center gap-2 flex-wrap">
                        <!-- Bộ lọc loại test -->
                        <div class="btn-group btn-group-sm" role="group">
                            <button type="button" class="btn btn-outline-secondary active btn-filter-test" data-filter="all">Tất cả</button>
                            <button type="button" class="btn btn-outline-success btn-filter-test" data-filter="sample">Test Mẫu (Public)</button>
                            <button type="button" class="btn btn-outline-warning btn-filter-test" data-filter="hidden">Test Kín (Hidden)</button>
                        </div>
                        <!-- Tìm kiếm text -->
                        <div class="input-group input-group-sm" style="width: 180px;">
                            <span class="input-group-text bg-dark border-secondary text-secondary"><i class="fas fa-search"></i></span>
                            <input type="text" id="vtSearchInput" class="form-control bg-dark text-white border-secondary" placeholder="Tìm test...">
                        </div>
                    </div>
                </div>
            </div>

            <!-- Danh sách các test cases -->
            <div class="modal-body p-4" id="vtModalBody" style="max-height: calc(85vh - 180px); overflow-y: auto;">
                <div class="text-center py-5 text-secondary" id="vtLoadingSpinner">
                    <div class="spinner-border text-info mb-2" role="status"></div>
                    <div>Đang tải danh sách ca kiểm thử từ cơ sở dữ liệu...</div>
                </div>

                <div id="vtTestList" class="d-flex flex-column gap-3" style="display: none;"></div>

                <div id="vtEmptyAlert" class="alert alert-warning bg-warning bg-opacity-10 border-warning border-opacity-25 text-warning text-center py-4 my-3" style="display: none;">
                    <i class="fas fa-folder-open fa-2x mb-2 d-block"></i>
                    Không tìm thấy ca kiểm thử nào phù hợp với bộ lọc hiện tại.
                </div>
            </div>

            <!-- Footer modal -->
            <div class="modal-footer border-secondary justify-content-between flex-wrap gap-2 py-2">
                <div class="text-secondary small">
                    <i class="fas fa-shield-halved text-success me-1"></i> Quyền truy cập: <strong>Giảng viên / Quản trị viên</strong> (Học viên chỉ thấy các test mẫu)
                </div>
                <div class="d-flex gap-2">
                    <button type="button" class="btn btn-outline-success btn-sm px-3" id="vtBtnOpenAutoGen">
                        <i class="fas fa-bolt me-1"></i> Sinh thêm test tự động
                    </button>
                    <button type="button" class="btn btn-outline-warning btn-sm px-3" id="vtBtnOpenNewVer">
                        <i class="fas fa-plus me-1"></i> Thêm test thủ công
                    </button>
                    <button type="button" class="btn btn-secondary btn-sm px-3" data-bs-dismiss="modal">Đóng</button>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
// Nút sửa đề bài từ bảng bài tập
document.querySelectorAll('.btn-edit-prob-lecturer').forEach(btn => {
    btn.addEventListener('click', () => {
        document.getElementById('editProblemId').value = btn.dataset.id;
        document.getElementById('editProblemCode').value = btn.dataset.code;
        document.getElementById('editProblemTitle').value = btn.dataset.title;
        document.getElementById('editProblemTopic').value = btn.dataset.topic;
        document.getElementById('editProblemDifficulty').value = btn.dataset.diff;
        document.getElementById('editProblemTimeLimit').value = btn.dataset.time;
        document.getElementById('editProblemMemoryLimit').value = btn.dataset.ram;
        document.getElementById('editProblemDescription').value = btn.dataset.desc;

        const modal = new bootstrap.Modal(document.getElementById('modalEditProblemLecturer'));
        modal.show();
    });
});

// Nút sinh test nhanh từ bảng bài tập
document.querySelectorAll('.btn-quick-autogen').forEach(btn => {
    btn.addEventListener('click', () => {
        const pId = btn.dataset.problemId;
        const sel = document.getElementById('selectAutoGenProblem');
        if (sel) sel.value = pId;
        const modal = new bootstrap.Modal(document.getElementById('modalAutoGenerateTests'));
        modal.show();
    });
});

// Nút thêm test nhanh từ bảng bài tập
document.querySelectorAll('.btn-quick-newver').forEach(btn => {
    btn.addEventListener('click', () => {
        const pId = btn.dataset.problemId;
        const sel = document.getElementById('selectVersionProblem');
        if (sel) sel.value = pId;
        const modal = new bootstrap.Modal(document.getElementById('modalCreateVersion'));
        modal.show();
    });
});

// ==============================================================
// XỬ LÝ MODAL XEM CHI TIẾT TEST CASES DÀNH CHO GIẢNG VIÊN
// ==============================================================
let currentViewingProblemId = 0;
let currentLoadedData = null;
let currentFilterType = 'all';

function escapeHtml(text) {
    if (!text) return '';
    return String(text)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

function copyToClipboard(text, btnElement) {
    if (!navigator.clipboard) {
        const t = document.createElement("textarea");
        t.value = text;
        document.body.appendChild(t);
        t.select();
        document.execCommand('copy');
        document.body.removeChild(t);
    } else {
        navigator.clipboard.writeText(text);
    }
    if (btnElement) {
        const originalHtml = btnElement.innerHTML;
        btnElement.innerHTML = '<i class="fas fa-check text-success me-1"></i>Đã chép!';
        setTimeout(() => { btnElement.innerHTML = originalHtml; }, 1500);
    }
}

function renderTestCasesList() {
    const listEl = document.getElementById('vtTestList');
    const emptyEl = document.getElementById('vtEmptyAlert');
    if (!currentLoadedData || !currentLoadedData.test_cases) {
        listEl.style.display = 'none';
        emptyEl.style.display = 'block';
        return;
    }

    const searchKeyword = (document.getElementById('vtSearchInput')?.value || '').toLowerCase().trim();
    const tests = currentLoadedData.test_cases;

    let filtered = tests.filter(tc => {
        if (currentFilterType === 'sample' && !tc.is_sample) return false;
        if (currentFilterType === 'hidden' && tc.is_sample) return false;
        if (searchKeyword) {
            const inMatch = (tc.input_data || '').toLowerCase().includes(searchKeyword);
            const outMatch = (tc.expected_output || '').toLowerCase().includes(searchKeyword);
            const orderMatch = String(tc.order_index).includes(searchKeyword);
            if (!inMatch && !outMatch && !orderMatch) return false;
        }
        return true;
    });

    if (filtered.length === 0) {
        listEl.innerHTML = '';
        listEl.style.display = 'none';
        emptyEl.style.display = 'block';
        return;
    }

    emptyEl.style.display = 'none';
    listEl.style.display = 'flex';

    let html = '';
    filtered.forEach((tc, idx) => {
        const isSample = Boolean(Number(tc.is_sample));
        const badgeClass = isSample ? 'bg-success bg-opacity-20 text-success border border-success border-opacity-25' : 'bg-warning bg-opacity-20 text-warning border border-warning border-opacity-25';
        const badgeLabel = isSample ? '<i class="fas fa-eye me-1"></i>Test Mẫu Công Khai (Sample)' : '<i class="fas fa-lock me-1"></i>Test Kín Bảo Mật (Hidden)';
        const weight = tc.score_weight || 0;

        html += `
        <div class="card bg-black border border-secondary border-opacity-50 shadow-sm rounded-3 overflow-hidden">
            <div class="card-header bg-dark bg-opacity-75 border-secondary border-opacity-25 d-flex justify-content-between align-items-center py-2 px-3 flex-wrap gap-2">
                <div class="d-flex align-items-center gap-2">
                    <span class="badge bg-secondary font-monospace fw-bold px-2 py-1">#${tc.order_index}</span>
                    <span class="badge ${badgeClass} px-2 py-1">${badgeLabel}</span>
                    <span class="text-secondary small fw-semibold">ID: ${tc.id}</span>
                </div>
                <div class="d-flex align-items-center gap-3">
                    <span class="text-info small fw-bold"><i class="fas fa-star me-1 text-warning"></i>${weight} Điểm</span>
                </div>
            </div>
            <div class="card-body p-3">
                <div class="row g-3">
                    <div class="col-md-6">
                        <div class="d-flex justify-content-between align-items-center mb-1">
                            <label class="form-label small fw-bold text-info mb-0">
                                <i class="fas fa-arrow-right-to-bracket me-1"></i>Đầu vào (stdin):
                            </label>
                            <button type="button" class="btn btn-outline-secondary btn-xs py-0 px-2 small btn-copy-raw" data-content="${escapeHtml(tc.input_data)}">
                                <i class="fas fa-copy me-1"></i>Sao chép
                            </button>
                        </div>
                        <pre class="bg-dark text-info p-2 rounded border border-secondary border-opacity-25 mb-0 font-monospace small" style="max-height: 140px; overflow-y: auto; white-space: pre-wrap; word-break: break-all;">${escapeHtml(tc.input_data) || '<span class="text-secondary italic">(Đầu vào rỗng)</span>'}</pre>
                    </div>
                    <div class="col-md-6">
                        <div class="d-flex justify-content-between align-items-center mb-1">
                            <label class="form-label small fw-bold text-success mb-0">
                                <i class="fas fa-arrow-right-from-bracket me-1"></i>Đầu ra mong đợi (expected stdout):
                            </label>
                            <button type="button" class="btn btn-outline-secondary btn-xs py-0 px-2 small btn-copy-raw" data-content="${escapeHtml(tc.expected_output)}">
                                <i class="fas fa-copy me-1"></i>Sao chép
                            </button>
                        </div>
                        <pre class="bg-dark text-success p-2 rounded border border-secondary border-opacity-25 mb-0 font-monospace small" style="max-height: 140px; overflow-y: auto; white-space: pre-wrap; word-break: break-all;">${escapeHtml(tc.expected_output) || '<span class="text-secondary italic">(Đầu ra rỗng)</span>'}</pre>
                    </div>
                </div>
            </div>
        </div>
        `;
    });

    listEl.innerHTML = html;

    // Gán sự kiện cho các nút copy
    listEl.querySelectorAll('.btn-copy-raw').forEach(btn => {
        btn.addEventListener('click', () => {
            copyToClipboard(btn.dataset.content, btn);
        });
    });
}

function loadProblemTestCases(problemId, versionId = 0) {
    currentViewingProblemId = problemId;
    const spinner = document.getElementById('vtLoadingSpinner');
    const listEl = document.getElementById('vtTestList');
    const emptyEl = document.getElementById('vtEmptyAlert');

    spinner.style.display = 'block';
    listEl.style.display = 'none';
    emptyEl.style.display = 'none';

    let url = `api/get_test_cases.php?problem_id=${problemId}`;
    if (versionId > 0) url += `&version_id=${versionId}`;

    fetch(url)
        .then(r => r.json())
        .then(res => {
            spinner.style.display = 'none';
            if (!res.success) {
                alert('Không thể tải test cases: ' + res.message);
                return;
            }

            currentLoadedData = res;

            // Cập nhật tiêu đề modal
            const prob = res.problem;
            document.getElementById('vtProblemTitle').innerHTML = `
                <span class="text-info font-monospace">[${escapeHtml(prob.code)}]</span> ${escapeHtml(prob.title)}
            `;

            // Cập nhật dropdown phiên bản
            const selVer = document.getElementById('vtSelectVersion');
            let optHtml = '';
            (res.versions_list || []).forEach(v => {
                const isSelected = (res.current_version && res.current_version.id == v.id) ? 'selected' : '';
                optHtml += `<option value="${v.id}" ${isSelected}>v${v.version_number}.0 (${v.test_count} tests)</option>`;
            });
            selVer.innerHTML = optHtml;

            // Cập nhật huy hiệu thống kê
            const stats = res.stats || {};
            document.getElementById('vtBadgeTotal').textContent = `${stats.total_tests || 0} tests`;
            document.getElementById('vtBadgeSample').textContent = `${stats.sample_count || 0} công khai`;
            document.getElementById('vtBadgeHidden').textContent = `${stats.hidden_count || 0} test kín`;
            document.getElementById('vtBadgeScore').textContent = `${stats.total_weight || 0}đ`;

            renderTestCasesList();
        })
        .catch(err => {
            spinner.style.display = 'none';
            alert('Lỗi kết nối tải test cases: ' + err.message);
        });
}

// Bắt sự kiện click nút xem test
document.querySelectorAll('.btn-view-testcases').forEach(btn => {
    btn.addEventListener('click', () => {
        const pId = btn.dataset.problemId;
        const modalEl = document.getElementById('modalViewTestCases');
        const modal = new bootstrap.Modal(modalEl);
        modal.show();
        loadProblemTestCases(pId);
    });
});

// Thay đổi phiên bản cần xem
document.getElementById('vtSelectVersion')?.addEventListener('change', function() {
    loadProblemTestCases(currentViewingProblemId, this.value);
});

// Lọc loại test (All / Sample / Hidden)
document.querySelectorAll('.btn-filter-test').forEach(btn => {
    btn.addEventListener('click', () => {
        document.querySelectorAll('.btn-filter-test').forEach(b => b.classList.remove('active'));
        btn.classList.add('active');
        currentFilterType = btn.dataset.filter;
        renderTestCasesList();
    });
});

// Tìm kiếm từ khóa test
document.getElementById('vtSearchInput')?.addEventListener('input', () => {
    renderTestCasesList();
});

// Nút chuyển nhanh sang Sinh Test từ modal xem test
document.getElementById('vtBtnOpenAutoGen')?.addEventListener('click', () => {
    const viewModal = bootstrap.Modal.getInstance(document.getElementById('modalViewTestCases'));
    if (viewModal) viewModal.hide();

    const sel = document.getElementById('selectAutoGenProblem');
    if (sel && currentViewingProblemId) sel.value = currentViewingProblemId;

    setTimeout(() => {
        const autoModal = new bootstrap.Modal(document.getElementById('modalAutoGenerateTests'));
        autoModal.show();
        if (typeof updateStarterSolution === 'function') updateStarterSolution(false);
    }, 400);
});

// Nút chuyển nhanh sang Thêm Test Thủ Công (bản v+)
document.getElementById('vtBtnOpenNewVer')?.addEventListener('click', () => {
    const viewModal = bootstrap.Modal.getInstance(document.getElementById('modalViewTestCases'));
    if (viewModal) viewModal.hide();

    const sel = document.getElementById('selectVersionProblem');
    if (sel && currentViewingProblemId) sel.value = currentViewingProblemId;

    setTimeout(() => {
        const verModal = new bootstrap.Modal(document.getElementById('modalCreateVersion'));
        verModal.show();
    }, 400);
});

// Nút kích hoạt chấm lại
document.querySelectorAll('.btn-trigger-rejudge').forEach(btn => {
    btn.addEventListener('click', () => {
        const subId = btn.dataset.subId;
        if (!confirm(`Bạn có chắc muốn chấm lại bài nộp #${subId}? Hệ thống sẽ giữ nguyên Run 1 và tạo Run 2.`)) {
            return;
        }

        const originalText = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Đang chấm...';

        fetch('api/rejudge.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ submission_id: subId })
        })
        .then(r => r.json())
        .then(res => {
            if (res.success) {
                alert(`Chấm lại thành công bài nộp #${subId}!\nKết quả mới: ${res.result.verdict} (${res.result.total_score}đ)`);
                location.reload();
            } else {
                alert('Lỗi chấm lại: ' + res.message);
                btn.disabled = false;
                btn.innerHTML = originalText;
            }
        })
        .catch(err => {
            alert('Lỗi: ' + err.message);
            btn.disabled = false;
            btn.innerHTML = originalText;
        });
    });
});

// Tự động gợi ý mã nguồn giải mẫu cho tính năng Auto-Generate Tests
const starterSolutionTemplates = <?php echo json_encode($starterSolutionTemplates, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;

function updateStarterSolution(force = false) {
    const selProblem = document.getElementById('selectAutoGenProblem');
    const selLang = document.getElementById('selectSolutionLang');
    const txtCode = document.getElementById('autogenSolutionCode');
    if (!selProblem || !selLang || !txtCode) return;

    const opt = selProblem.options[selProblem.selectedIndex];
    const code = opt ? (opt.dataset.code || '').trim().toUpperCase() : '';
    const lang = selLang.value;

    if (force || txtCode.value.trim() === '') {
        if (starterSolutionTemplates[code] && starterSolutionTemplates[code][lang]) {
            txtCode.value = starterSolutionTemplates[code][lang];
        } else {
            if (lang === 'python3') {
                txtCode.value = "# Dán mã giải Python 3 chuẩn vào đây\nimport sys\n\ndef solve():\n    pass\n\nif __name__ == '__main__':\n    solve()\n";
            } else {
                txtCode.value = "// Dán mã giải C++ chuẩn vào đây\n#include <iostream>\nusing namespace std;\n\nint main() {\n    // Code giai bai...\n    return 0;\n}\n";
            }
        }
    }
}

document.getElementById('selectAutoGenProblem')?.addEventListener('change', () => updateStarterSolution(false));
document.getElementById('selectSolutionLang')?.addEventListener('change', () => updateStarterSolution(true));
document.getElementById('btnFillStarterCode')?.addEventListener('click', () => updateStarterSolution(true));

// Tự điền lần đầu khi trang load
document.addEventListener('DOMContentLoaded', () => {
    updateStarterSolution(false);
});

// Hiệu ứng khi nộp form Auto-Gen
document.getElementById('formAutoGenTests')?.addEventListener('submit', function() {
    const btn = document.getElementById('btnSubmitAutoGen');
    if (btn) {
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span> Đang chạy mã mẫu trên Máy chấm &amp; Sinh Test...';
    }
});
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
