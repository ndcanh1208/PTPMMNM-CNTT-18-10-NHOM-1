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
        $problemId = (int)($_POST['problem_id'] ?? 0);
        
        $stmtP = $pdo->prepare("SELECT id, code, title, topic FROM problems WHERE id = ?");
        $stmtP->execute([$problemId]);
        $problem = $stmtP->fetch();

        if (!$problem) {
            $errorMsg = 'Không tìm thấy bài tập được chọn!';
        } else {
            // Danh sách các ca kiểm thử biên thông minh tự động
            $generatedTests = [];
            $code = strtoupper(trim($problem['code']));

            if ($code === 'SUM_TWO_NUMBERS') {
                $generatedTests = [
                    ['input' => "10 25", 'output' => "35", 'is_sample' => 1, 'weight' => 20],
                    ['input' => "0 0", 'output' => "0", 'is_sample' => 0, 'weight' => 20],
                    ['input' => "-450 -550", 'output' => "-1000", 'is_sample' => 0, 'weight' => 20],
                    ['input' => "1000000 -1000000", 'output' => "0", 'is_sample' => 0, 'weight' => 20],
                    ['input' => "9876543210 1234567890", 'output' => "11111111100", 'is_sample' => 0, 'weight' => 20]
                ];
            } elseif ($code === 'PRIME_CHECK') {
                $generatedTests = [
                    ['input' => "7", 'output' => "YES", 'is_sample' => 1, 'weight' => 20],
                    ['input' => "1", 'output' => "NO", 'is_sample' => 0, 'weight' => 20],
                    ['input' => "2", 'output' => "YES", 'is_sample' => 0, 'weight' => 20],
                    ['input' => "999999", 'output' => "NO", 'is_sample' => 0, 'weight' => 20],
                    ['input' => "1000003", 'output' => "YES", 'is_sample' => 0, 'weight' => 20]
                ];
            } elseif ($code === 'REVERSE_ARRAY') {
                $generatedTests = [
                    ['input' => "5\n1 2 3 4 5", 'output' => "5 4 3 2 1", 'is_sample' => 1, 'weight' => 20],
                    ['input' => "1\n999", 'output' => "999", 'is_sample' => 0, 'weight' => 20],
                    ['input' => "4\n-10 0 0 10", 'output' => "10 0 0 -10", 'is_sample' => 0, 'weight' => 20],
                    ['input' => "5\n7 8 9 8 7", 'output' => "7 8 9 8 7", 'is_sample' => 0, 'weight' => 20],
                    ['input' => "8\n15 42 -9 0 108 77 3 21", 'output' => "21 3 77 108 0 -9 42 15", 'is_sample' => 0, 'weight' => 20]
                ];
            } elseif ($code === 'PALINDROME_STRING') {
                $generatedTests = [
                    ['input' => "racecar", 'output' => "YES", 'is_sample' => 1, 'weight' => 20],
                    ['input' => "z", 'output' => "YES", 'is_sample' => 0, 'weight' => 20],
                    ['input' => "ab", 'output' => "NO", 'is_sample' => 0, 'weight' => 20],
                    ['input' => "noon", 'output' => "YES", 'is_sample' => 0, 'weight' => 20],
                    ['input' => "123454321", 'output' => "YES", 'is_sample' => 0, 'weight' => 20]
                ];
            } elseif ($code === 'SECOND_LARGEST') {
                $generatedTests = [
                    ['input' => "5\n10 20 4 45 99", 'output' => "45", 'is_sample' => 1, 'weight' => 20],
                    ['input' => "2\n100 200", 'output' => "100", 'is_sample' => 0, 'weight' => 20],
                    ['input' => "5\n99 99 99 50 10", 'output' => "50", 'is_sample' => 0, 'weight' => 20],
                    ['input' => "4\n-50 -10 -20 -5", 'output' => "-10", 'is_sample' => 0, 'weight' => 20],
                    ['input' => "6\n12 85 73 99 99 64", 'output' => "85", 'is_sample' => 0, 'weight' => 20]
                ];
            } elseif ($code === 'FIBONACCI_N') {
                $generatedTests = [
                    ['input' => "5", 'output' => "5", 'is_sample' => 1, 'weight' => 20],
                    ['input' => "0", 'output' => "0", 'is_sample' => 0, 'weight' => 20],
                    ['input' => "1", 'output' => "1", 'is_sample' => 0, 'weight' => 20],
                    ['input' => "15", 'output' => "610", 'is_sample' => 0, 'weight' => 20],
                    ['input' => "30", 'output' => "832040", 'is_sample' => 0, 'weight' => 20]
                ];
            } elseif ($code === 'FACTORIAL_CALC') {
                $generatedTests = [
                    ['input' => "4", 'output' => "24", 'is_sample' => 1, 'weight' => 20],
                    ['input' => "0", 'output' => "1", 'is_sample' => 0, 'weight' => 20],
                    ['input' => "1", 'output' => "1", 'is_sample' => 0, 'weight' => 20],
                    ['input' => "7", 'output' => "5040", 'is_sample' => 0, 'weight' => 20],
                    ['input' => "12", 'output' => "479001600", 'is_sample' => 0, 'weight' => 20]
                ];
            } else {
                $r1 = rand(10, 50);
                $r2 = rand(100, 500);
                $generatedTests = [
                    ['input' => "{$r1}", 'output' => "OK", 'is_sample' => 1, 'weight' => 20],
                    ['input' => "0", 'output' => "0", 'is_sample' => 0, 'weight' => 20],
                    ['input' => "-1", 'output' => "-1", 'is_sample' => 0, 'weight' => 20],
                    ['input' => "{$r2}", 'output' => "OK", 'is_sample' => 0, 'weight' => 20],
                    ['input' => "1000000", 'output' => "OK", 'is_sample' => 0, 'weight' => 20],
                ];
            }

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
                $changeLog = "⚡ Tự động sinh bộ 5 ca kiểm thử biên (Boundary & Stress Tests) v{$newVerNum}.0";

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
                $successMsg = "⚡ Đã tự động sinh thành công 5 ca kiểm thử biên (1 Sample công khai + 4 Hidden kín) cho bài tập [{$problem['code']}] và nâng cấp lên phiên bản v{$newVerNum}.0!";
            } catch (Exception $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $errorMsg = 'Lỗi tự động sinh test case: ' . $e->getMessage();
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
                                        <td><span class="text-light fw-bold"><?php echo $p['test_count']; ?></span> tests</td>
                                        <td><span class="text-secondary"><?php echo $p['sub_count']; ?> bài</span></td>
                                        <td class="pe-4 text-end text-nowrap">
                                            <button type="button" class="btn btn-warning btn-sm py-1 px-2 btn-edit-prob-lecturer" 
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
                                                <i class="fas fa-eye me-1"></i>Làm thử
                                            </a>
                                            <button type="button" class="btn btn-outline-success btn-sm py-1 px-2 ms-1 btn-quick-autogen" data-problem-id="<?php echo $p['id']; ?>" data-problem-title="<?php echo htmlspecialchars($p['title']); ?>" title="Tự động sinh 5 testcases biên">
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
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content bg-dark text-white border border-secondary shadow-lg">
            <div class="modal-header border-secondary">
                <h5 class="modal-title fw-bold text-info">
                    <i class="fas fa-bolt text-warning me-2"></i>Tự Động Sinh Bộ Ca Kiểm Thử Biên (Auto-Gen Testcases)
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="auto_generate_tests">
                <div class="modal-body p-4">
                    <div class="alert alert-info bg-info bg-opacity-10 border-info border-opacity-25 text-light small mb-3">
                        <i class="fas fa-circle-info text-info me-2"></i>
                        Hệ thống tự động phân tích dạng bài toán để sinh <strong>5 ca kiểm thử biên chuẩn</strong> bao gồm:
                        giá trị cực tiểu (số 0, số âm), số lớn ($10^9$, 64-bit), mảng 1 phần tử / đối xứng, và chuỗi đặc biệt nhằm kiểm tra toàn diện bài nộp của học viên.
                    </div>

                    <div class="mb-3">
                        <label class="form-label small fw-bold text-secondary">Chọn bài tập cần sinh bộ test:</label>
                        <select name="problem_id" id="selectAutoGenProblem" class="form-select bg-black text-white border-secondary" required>
                            <?php foreach ($allProblems as $p): ?>
                                <option value="<?php echo $p['id']; ?>">
                                    [<?php echo $p['code']; ?>] <?php echo htmlspecialchars($p['title']); ?> (Hiện tại: v<?php echo $p['version_number'] ?? 1; ?>.0 - <?php echo $p['test_count']; ?> tests)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="p-3 rounded bg-black border border-secondary border-opacity-50 mb-3">
                        <div class="fw-bold text-warning small mb-2"><i class="fas fa-wand-magic-sparkles me-1"></i> Quy chuẩn sinh ca kiểm thử theo Đề tài 20:</div>
                        <ul class="text-secondary small mb-0 ps-3">
                            <li class="mb-1"><strong class="text-light">1 Test mẫu công khai (Sample test)</strong>: Trọng số 20 điểm - hiển thị trong bảng mô tả đề cho người học tham khảo.</li>
                            <li class="mb-1"><strong class="text-light">4 Test kín bảo mật (Hidden tests)</strong>: Trọng số 80 điểm (20 điểm/test) - giấu input/output để bảo đảm tính khách quan.</li>
                            <li><strong class="text-light">Tự động nâng phiên bản đề bài</strong>: Tạo bản ghi phiên bản mới (v2.0, v3.0...) kèm nhật ký thay đổi theo đúng Phép thử 4 & Tình huống 2.</li>
                        </ul>
                    </div>
                </div>
                <div class="modal-footer border-secondary">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Hủy</button>
                    <button type="submit" class="btn btn-info btn-sm px-4 fw-semibold text-dark">
                        <i class="fas fa-bolt me-1"></i> Bắt Đầu Sinh &amp; Nâng Cấp Phiên Bản Mới
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ============================================================== -->
<!-- MODAL 4: CHỈNH SỬA ĐỀ BÀI (DÀNH CHO GIẢNG VIÊN) -->
<!-- ============================================================== -->
<div class="modal fade" id="modalEditProblemLecturer" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content bg-dark text-white border border-secondary shadow-lg">
            <div class="modal-header border-secondary">
                <h5 class="modal-title fw-bold text-warning">
                    <i class="fas fa-pen-to-square me-2"></i>Chỉnh Sửa Thông Tin &amp; Đề Bài
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="edit_problem">
                <input type="hidden" name="problem_id" id="editProblemId">
                <div class="modal-body p-4">
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
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
