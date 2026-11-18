<?php
/**
 * ============================================================================
 * Nhom1 Online Judge - Quản lý Bài tập & Bộ dữ liệu kiểm tra (Problems & Testcases)
 * ============================================================================
 * MỤC ĐÍCH TRANG NÀY:
 * - Dành riêng cho Giảng viên (Instructor) và Quản trị viên (Admin).
 * - Quản lý toàn bộ vòng đời bài tập: Tạo bài mới kèm bộ test ban đầu, Chỉnh sửa thông tin/đề bài
 *   (có thể phát hành thành phiên bản mới v(N+1) để giữ nguyên lịch sử đề cũ), Quản lý/Sửa bộ test case,
 *   Nhập & thẩm định test case từ file ZIP/JSON (hỗ trợ đối chiếu lời giải chuẩn), và Xóa mềm/Xóa vĩnh viễn/Khôi phục.
 *
 * CẤU TRÚC FILE GỒM 3 PHẦN:
 * - PHẦN 1 (PHP): Phân quyền, xử lý form POST tạo/sửa bài tập (dùng Transaction CSDL), đếm & truy vấn danh sách theo tab.
 * - PHẦN 2 (HTML): Giao diện tổng quan, bảng danh sách bài tập (Hoạt động / Thùng rác) và 5 hộp thoại Modal.
 * - PHẦN 3 (JavaScript): Quản lý đóng/mở Modal, tạo form test case động, gọi API lưu test case, nhập file ZIP/JSON và xóa/khôi phục.
 */

// Bật chế độ kiểm tra kiểu dữ liệu nghiêm ngặt trong PHP
declare(strict_types=1);

// Nạp cấu hình kết nối CSDL, xác thực phân quyền (Auth) và công cụ bảo mật (Security)
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/core/Auth.php';
require_once __DIR__ . '/core/Security.php';

// Chặn sinh viên hoặc khách truy cập: bắt buộc phải là Admin hoặc Giảng viên (instructor)
Auth::requireRoles(['admin', 'instructor']);

// Lấy thông tin tài khoản đang đăng nhập và kết nối PDO tới MySQL
$currentUser = Auth::user();
$pdo = getDbConnection();

// Biến lưu thông báo kết quả (thành công / lỗi) sau khi xử lý form POST
$msgSuccess = '';
$msgError = '';

// ============================================================================
// PHẦN 1A: XỬ LÝ FORM POST TẠO BÀI TẬP MỚI VÀ CHỈNH SỬA BÀI TẬP
// ============================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];

    // --- NHÁNH 1: TẠO BÀI TẬP MỚI (create_problem) ---
    if ($action === 'create_problem') {
        // Chuẩn hóa mã slug: chuyển chữ thường, đổi mọi ký tự không phải chữ/số/gạch ngang thành '-', cắt '-' thừa hai đầu
        $slug = strtolower(trim((string)($_POST['slug'] ?? '')));
        $slug = preg_replace('/[^a-z0-9\-]/', '-', $slug);
        $slug = trim($slug, '-');
        $title = trim((string)($_POST['title'] ?? ''));
        $topic = trim((string)($_POST['topic'] ?? 'Cơ bản'));
        // Kiểm tra giá trị độ khó và chế độ hiển thị theo danh sách hợp lệ (whitelist)
        $difficulty = in_array($_POST['difficulty'] ?? '', ['Easy', 'Medium', 'Hard'], true) ? $_POST['difficulty'] : 'Easy';
        $visibility = in_array($_POST['visibility'] ?? '', ['public', 'hidden', 'exam_only'], true) ? $_POST['visibility'] : 'public';
        $isPublic = ($visibility === 'public') ? 1 : 0;
        // Ép giới hạn thời gian nằm trong [100ms, 10000ms] và bộ nhớ nằm trong [16MB, 1024MB] (lưu theo KB)
        $timeLimitMs = max(100, min(10000, (int)($_POST['time_limit_ms'] ?? 1000)));
        $memoryLimitKb = isset($_POST['memory_limit_kb']) && (int)$_POST['memory_limit_kb'] >= 16384
            ? min(1048576, (int)$_POST['memory_limit_kb'])
            : max(16384, min(1048576, (int)($_POST['memory_limit_mb'] ?? 256) * 1024));
        $description = trim((string)($_POST['description'] ?? ''));
        $inputDesc = trim((string)($_POST['input_description'] ?? ''));
        $outputDesc = trim((string)($_POST['output_description'] ?? ''));
        $constraints = trim((string)($_POST['constraints'] ?? ''));
        $sourceAttribution = trim((string)($_POST['source_attribution'] ?? 'Nhom1 Internal'));

        if ($slug === '' || $title === '') {
            $msgError = 'Vui lòng nhập đầy đủ mã định danh (slug) và tiêu đề bài tập.';
        } else {
            // Kiểm tra xem mã slug đã được bài tập nào dùng chưa (slug là duy nhất)
            $checkStmt = $pdo->prepare("SELECT id FROM problems WHERE slug = ?");
            $checkStmt->execute([$slug]);
            if ($checkStmt->fetch()) {
                $msgError = "Mã slug '{$slug}' đã tồn tại trong hệ thống. Vui lòng chọn slug khác.";
            } else {
                try {
                    // Mở Transaction (giao dịch CSDL): đảm bảo 4 bước (thêm bài -> thêm v1 -> gắn v1 -> thêm test)
                    // hoặc thành công hết, hoặc hoàn tác sạch (rollBack) nếu có lỗi giữa chừng
                    $pdo->beginTransaction();

                    // Bước 1: Thêm bản ghi gốc vào bảng problems (kèm phân quyền hiển thị visibility)
                    $insertProb = $pdo->prepare("
                        INSERT INTO problems (slug, title, topic, difficulty, source_attribution, is_public, visibility, created_by)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    $insertProb->execute([$slug, $title, $topic, $difficulty, $sourceAttribution, $isPublic, $visibility, $currentUser['id']]);
                    $problemId = (int)$pdo->lastInsertId();

                    // Bước 2: Tạo phiên bản nội dung đề đầu tiên (version_number = 1) trong bảng problem_versions
                    $insertVer = $pdo->prepare("
                        INSERT INTO problem_versions (problem_id, version_number, description, input_description, output_description, constraints, time_limit_ms, memory_limit_kb, changelog, created_by)
                        VALUES (?, 1, ?, ?, ?, ?, ?, ?, 'Khởi tạo bài tập phiên bản ban đầu', ?)
                    ");
                    $insertVer->execute([
                        $problemId, $description, $inputDesc, $outputDesc, $constraints,
                        $timeLimitMs, $memoryLimitKb, $currentUser['id']
                    ]);
                    $versionId = (int)$pdo->lastInsertId();

                    // Bước 3: Trỏ cột current_version_id của bài tập vừa tạo tới phiên bản v1 này
                    $updateProb = $pdo->prepare("UPDATE problems SET current_version_id = ? WHERE id = ?");
                    $updateProb->execute([$versionId, $problemId]);

                    // Bước 4: Thu thập, chuẩn hóa xuống dòng (\r\n -> \n, xóa ký tự BOM UTF-8 ẩn) và lọc các test case hợp lệ
                    $rawTestCases = $_POST['test_cases'] ?? [];
                    $validTestCases = [];

                    if (is_array($rawTestCases) && !empty($rawTestCases)) {
                        foreach ($rawTestCases as $tc) {
                            $in = str_replace(["\r\n", "\r"], "\n", preg_replace('/^\xEF\xBB\xBF/', '', (string)($tc['input_data'] ?? '')));
                            $out = str_replace(["\r\n", "\r"], "\n", preg_replace('/^\xEF\xBB\xBF/', '', (string)($tc['expected_output'] ?? '')));
                            if ($in !== '' || $out !== '') {
                                // Phân loại test công khai (sample: hiển thị ở đề) hay test chấm kín (hidden: ẩn đáp án)
                                $isSample = 0;
                                $isHidden = 1;
                                if (isset($tc['type'])) {
                                    if ($tc['type'] === 'sample') {
                                        $isSample = 1;
                                        $isHidden = 0;
                                    } else {
                                        $isSample = 0;
                                        $isHidden = 1;
                                    }
                                } else {
                                    $isSample = !empty($tc['is_sample']) ? 1 : 0;
                                    $isHidden = $isSample ? 0 : 1;
                                }

                                $validTestCases[] = [
                                    'input'     => $in,
                                    'output'    => $out,
                                    'is_sample' => $isSample,
                                    'is_hidden' => $isHidden,
                                    'points'    => max(1, (int)($tc['points'] ?? 10))
                                ];
                            }
                        }
                    }

                    // Tương thích ngược: kiểm tra trường sample_input / sample_output cũ nếu mảng test_cases trống
                    if (empty($validTestCases)) {
                        $sampleInput = (string)($_POST['sample_input'] ?? '');
                        $sampleOutput = (string)($_POST['sample_output'] ?? '');
                        if ($sampleInput !== '' || $sampleOutput !== '') {
                            $validTestCases[] = [
                                'input'     => $sampleInput,
                                'output'    => $sampleOutput,
                                'is_sample' => 1,
                                'is_hidden' => 0,
                                'points'    => 10
                            ];
                        }
                    }

                    // Ghi lần lượt từng test case hợp lệ vào bảng test_cases kèm thứ tự case_order (1, 2, 3...)
                    if (!empty($validTestCases)) {
                        $insertTc = $pdo->prepare("
                            INSERT INTO test_cases (problem_version_id, case_order, input_data, expected_output, is_sample, is_hidden, points, created_at)
                            VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
                        ");
                        foreach ($validTestCases as $idx => $vtc) {
                            $insertTc->execute([
                                $versionId,
                                $idx + 1,
                                $vtc['input'],
                                $vtc['output'],
                                $vtc['is_sample'],
                                $vtc['is_hidden'],
                                $vtc['points']
                            ]);
                        }
                    }

                    // Xác nhận hoàn tất giao dịch lưu vào CSDL
                    $pdo->commit();
                    $tcCountMsg = count($validTestCases) > 0 ? " kèm " . count($validTestCases) . " test case" : "";
                    $msgSuccess = "Tạo bài tập '{$slug} - {$title}' thành công{$tcCountMsg}!";
                } catch (Exception $e) {
                    // Nếu có bất kỳ ngoại lệ nào -> hủy bỏ toàn bộ thay đổi chưa commit
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    $msgError = "Lỗi tạo bài tập: " . $e->getMessage();
                }
            }
        }
    // --- NHÁNH 2: CHỈNH SỬA BÀI TẬP HIỆN CÓ (edit_problem) ---
    } elseif ($action === 'edit_problem') {
        $problemId = (int)($_POST['problem_id'] ?? 0);
        $title = trim((string)($_POST['title'] ?? ''));
        $topic = trim((string)($_POST['topic'] ?? 'Cơ bản'));
        $difficulty = in_array($_POST['difficulty'] ?? '', ['Easy', 'Medium', 'Hard'], true) ? $_POST['difficulty'] : 'Easy';
        $visibility = in_array($_POST['visibility'] ?? '', ['public', 'hidden', 'exam_only'], true) ? $_POST['visibility'] : 'public';
        $isPublic = ($visibility === 'public') ? 1 : 0;
        $timeLimitMs = max(100, min(10000, (int)($_POST['time_limit_ms'] ?? 1000)));
        $memoryLimitKb = isset($_POST['memory_limit_kb']) && (int)$_POST['memory_limit_kb'] >= 16384
            ? min(1048576, (int)$_POST['memory_limit_kb'])
            : max(16384, min(1048576, (int)($_POST['memory_limit_mb'] ?? 256) * 1024));
        $description = trim((string)($_POST['description'] ?? ''));
        $inputDesc = trim((string)($_POST['input_description'] ?? ''));
        $outputDesc = trim((string)($_POST['output_description'] ?? ''));
        $constraints = trim((string)($_POST['constraints'] ?? ''));
        // Nếu người dùng tích chọn ô "Phát hành thành phiên bản đề mới" -> tạo bản ghi v(N+1) thay vì sửa đè
        $createNewVersion = !empty($_POST['create_new_version']);
        $changelog = trim((string)($_POST['changelog'] ?? ''));

        // Kiểm tra bài tập có tồn tại và người dùng có quyền quản lý bài này không (Admin hoặc đúng Giảng viên đã tạo)
        $probStmt = $pdo->prepare("SELECT created_by, current_version_id FROM problems WHERE id = ?");
        $probStmt->execute([$problemId]);
        $prob = $probStmt->fetch();

        if (!$prob || !Auth::canManageProblem((int)$prob['created_by'])) {
            $msgError = "Bạn không có quyền chỉnh sửa bài tập này.";
        } else {
            try {
                $pdo->beginTransaction();
                // Cập nhật các thông tin chung của bài tập trong bảng problems
                $updProb = $pdo->prepare("UPDATE problems SET title = ?, topic = ?, difficulty = ?, visibility = ?, is_public = ? WHERE id = ?");
                $updProb->execute([$title, $topic, $difficulty, $visibility, $isPublic, $problemId]);

                if ($createNewVersion) {
                    // Chế độ 1: Phát hành phiên bản đề mới v(N+1), bảo toàn 100% lịch sử phiên bản cũ
                    $maxVerStmt = $pdo->prepare("SELECT COALESCE(MAX(version_number), 0) FROM problem_versions WHERE problem_id = ?");
                    $maxVerStmt->execute([$problemId]);
                    $newVerNum = (int)$maxVerStmt->fetchColumn() + 1;

                    $note = $changelog !== '' ? $changelog : "Cập nhật nội dung đề / giới hạn (v{$newVerNum})";
                    $insVer = $pdo->prepare("
                        INSERT INTO problem_versions (problem_id, version_number, description, input_description, output_description, constraints, time_limit_ms, memory_limit_kb, changelog, created_by)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    $insVer->execute([
                        $problemId, $newVerNum, $description, $inputDesc, $outputDesc, $constraints,
                        $timeLimitMs, $memoryLimitKb, $note, $currentUser['id']
                    ]);
                    $newVerId = (int)$pdo->lastInsertId();

                    // Tự động nhân bản (INSERT ... SELECT) toàn bộ test cases của phiên bản cũ sang phiên bản mới
                    if (!empty($prob['current_version_id'])) {
                        $copyTc = $pdo->prepare("
                            INSERT INTO test_cases (problem_version_id, case_order, input_data, expected_output, is_sample, is_hidden, points, created_at)
                            SELECT ?, case_order, input_data, expected_output, is_sample, is_hidden, points, NOW()
                            FROM test_cases
                            WHERE problem_version_id = ?
                            ORDER BY case_order ASC
                        ");
                        $copyTc->execute([$newVerId, $prob['current_version_id']]);
                    }

                    // Cập nhật bài tập sử dụng phiên bản mới vừa phát hành
                    $pdo->prepare("UPDATE problems SET current_version_id = ? WHERE id = ?")->execute([$newVerId, $problemId]);
                    $msgSuccess = "Đã phát hành phiên bản đề mới v{$newVerNum} (giữ nguyên toàn bộ lịch sử đề cũ)!";
                } elseif (!empty($prob['current_version_id'])) {
                    // Chế độ 2: Cập nhật trực tiếp (In-place) nội dung vào phiên bản đề hiện hành
                    $updVer = $pdo->prepare("
                        UPDATE problem_versions 
                        SET description = ?, input_description = ?, output_description = ?, constraints = ?, time_limit_ms = ?, memory_limit_kb = ?
                        WHERE id = ?
                    ");
                    $updVer->execute([$description, $inputDesc, $outputDesc, $constraints, $timeLimitMs, $memoryLimitKb, $prob['current_version_id']]);
                    $msgSuccess = "Đã cập nhật thông tin bài tập thành công!";
                } else {
                    $msgSuccess = "Đã cập nhật thông tin bài tập thành công!";
                }

                $pdo->commit();
            } catch (Exception $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $msgError = "Lỗi cập nhật: " . $e->getMessage();
            }
        }
    }
}

// ============================================================================
// PHẦN 1B: TRUY VẤN SỐ LƯỢNG & DANH SÁCH BÀI TẬP THEO TAB (HOẠT ĐỘNG / THÙNG RÁC)
// ============================================================================

// Xác định tab đang xem từ URL (?tab=active hoặc ?tab=trash)
$currentTab = ($_GET['tab'] ?? 'active') === 'trash' ? 'trash' : 'active';

// Nếu không phải Admin (tức là Giảng viên) -> chỉ đếm và chỉ hiển thị các bài tập do chính Giảng viên đó tạo
$countParams = [];
$creatorCondition = "";
if (!Auth::isAdmin()) {
    $creatorCondition = " AND p.created_by = ?";
    $countParams[] = $currentUser['id'];
}

// Đếm số bài đang hoạt động (deleted_at IS NULL) và số bài đã xóa mềm trong thùng rác (deleted_at IS NOT NULL)
$activeCountStmt = $pdo->prepare("SELECT COUNT(*) FROM problems p WHERE p.deleted_at IS NULL {$creatorCondition}");
$activeCountStmt->execute($countParams);
$activeCount = (int)$activeCountStmt->fetchColumn();

$trashCountStmt = $pdo->prepare("SELECT COUNT(*) FROM problems p WHERE p.deleted_at IS NOT NULL {$creatorCondition}");
$trashCountStmt->execute($countParams);
$trashCount = (int)$trashCountStmt->fetchColumn();

// Xây dựng câu điều kiện WHERE tương ứng với tab hiện tại và quyền của người dùng
$params = [];
$whereClause = $currentTab === 'trash' ? "WHERE p.deleted_at IS NOT NULL" : "WHERE p.deleted_at IS NULL";
if (!Auth::isAdmin()) {
    $whereClause .= " AND p.created_by = ?";
    $params[] = $currentUser['id'];
}

// Truy vấn chi tiết danh sách bài tập kèm:
// - Thông tin người tạo (LEFT JOIN users)
// - Nội dung phiên bản hiện hành (LEFT JOIN problem_versions)
// - 3 truy vấn con (Subquery) đếm số test công khai, số test chấm kín và tổng số lượt nộp bài
$query = "
    SELECT 
        p.*,
        u.username AS creator_username,
        u.full_name AS creator_name,
        pv.version_number AS current_version_number,
        pv.time_limit_ms,
        pv.memory_limit_kb,
        pv.description,
        pv.input_description,
        pv.output_description,
        pv.constraints,
        (SELECT COUNT(*) FROM test_cases tc WHERE tc.problem_version_id = p.current_version_id AND tc.is_hidden = 0) AS public_test_count,
        (SELECT COUNT(*) FROM test_cases tc WHERE tc.problem_version_id = p.current_version_id AND tc.is_hidden = 1) AS hidden_test_count,
        (SELECT COUNT(*) FROM submissions s WHERE s.problem_id = p.id) AS total_submissions
    FROM problems p
    LEFT JOIN users u ON p.created_by = u.id
    LEFT JOIN problem_versions pv ON p.current_version_id = pv.id
    {$whereClause}
    ORDER BY " . ($currentTab === 'trash' ? "p.deleted_at DESC, p.id DESC" : "p.id ASC") . "
";
$stmt = $pdo->prepare($query);
$stmt->execute($params);
$problems = $stmt->fetchAll();

// Đặt tiêu đề trang và nạp khung header + sidebar chung
$pageTitle = "Quản lý Đề & Bộ Test";
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/sidebar.php';
?>

<!-- ========================================================================= -->
<!-- PHẦN 2: GIAO DIỆN HTML (THỐNG KÊ, BẢNG DANH SÁCH BÀI TẬP & 5 MODAL)       -->
<!-- ========================================================================= -->
<div class="pl-64 min-h-screen flex flex-col w-full">
  <main class="p-8 max-w-7xl w-full mx-auto space-y-8 flex-1">
  <!-- Khối tiêu đề trang & Nút mở Modal 1 ("Tạo bài tập mới") -->
  <div class="flex flex-col md:flex-row md:items-center justify-between gap-4">
    <div>
      <h1 class="text-3xl font-black tracking-tight text-white flex items-center gap-3">
        <i class="fa-solid fa-sliders text-cyan-400"></i>
        <span>Quản lý Đề thi & Bộ Test</span>
      </h1>
      <p class="text-slate-400 text-sm mt-1">
        Khởi tạo bài tập, cấu hình giới hạn thời gian/bộ nhớ và quản lý phiên bản bộ dữ liệu kiểm tra.
      </p>
    </div>
    <div>
      <button type="button" onclick="openModal('createModal')" class="btn-cyan px-5 py-2.5 rounded-xl text-black font-black text-sm flex items-center space-x-2 shadow-[0_0_20px_rgba(0,255,255,0.45)] hover:scale-105 transition-all cursor-pointer">
        <i class="fa-solid fa-plus text-black font-bold"></i>
        <span>Tạo bài tập mới</span>
      </button>
    </div>
  </div>

  <!-- Khối hiển thị thông báo kết quả sau khi submit form POST (tạo/sửa bài tập) -->
  <?php if ($msgSuccess !== ''): ?>
    <div class="p-4 rounded-xl bg-emerald-500/10 border border-emerald-500/30 text-emerald-400 flex items-center space-x-3">
      <i class="fa-solid fa-circle-check text-lg"></i>
      <span><?= htmlspecialchars($msgSuccess) ?></span>
    </div>
  <?php endif; ?>

  <?php if ($msgError !== ''): ?>
    <div class="p-4 rounded-xl bg-rose-500/10 border border-rose-500/30 text-rose-400 flex items-center space-x-3">
      <i class="fa-solid fa-triangle-exclamation text-lg"></i>
      <span><?= htmlspecialchars($msgError) ?></span>
    </div>
  <?php endif; ?>

  <!-- Khối 3 thẻ thống kê nhanh: Số bài tập đang hiển thị, Tổng số Test Cases và Tổng lượt nộp tích lũy -->
  <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
    <div class="glass-panel p-6 rounded-2xl flex items-center space-x-4 border border-white/5">
      <div class="w-12 h-12 rounded-xl bg-cyan-500/10 border border-cyan-500/30 text-cyan-400 flex items-center justify-center text-xl">
        <i class="fa-solid fa-code"></i>
      </div>
      <div>
        <div class="text-2xl font-black text-white"><?= count($problems) ?></div>
        <div class="text-xs text-slate-400 uppercase tracking-wider font-semibold">Bài tập đang quản lý</div>
      </div>
    </div>
    <div class="glass-panel p-6 rounded-2xl flex items-center space-x-4 border border-white/5">
      <div class="w-12 h-12 rounded-xl bg-amber-500/10 border border-amber-500/30 text-amber-400 flex items-center justify-center text-xl">
        <i class="fa-solid fa-vial"></i>
      </div>
      <div>
        <div class="text-2xl font-black text-white">
          <?= array_sum(array_map(fn($p) => (int)$p['public_test_count'] + (int)$p['hidden_test_count'], $problems)) ?>
        </div>
        <div class="text-xs text-slate-400 uppercase tracking-wider font-semibold">Tổng số Test Cases</div>
      </div>
    </div>
    <div class="glass-panel p-6 rounded-2xl flex items-center space-x-4 border border-white/5">
      <div class="w-12 h-12 rounded-xl bg-purple-500/10 border border-purple-500/30 text-purple-400 flex items-center justify-center text-xl">
        <i class="fa-solid fa-paper-plane"></i>
      </div>
      <div>
        <div class="text-2xl font-black text-white">
          <?= array_sum(array_map(fn($p) => (int)$p['total_submissions'], $problems)) ?>
        </div>
        <div class="text-xs text-slate-400 uppercase tracking-wider font-semibold">Lượt nộp bài tích lũy</div>
      </div>
    </div>
  </div>

  <!-- Bảng danh sách bài tập kèm 2 tab chuyển đổi: Đang hoạt động (?tab=active) và Thùng rác (?tab=trash) -->
  <div class="glass-panel rounded-2xl border border-white/10 overflow-hidden shadow-2xl">
    <!-- Thanh chuyển Tab: Đang hoạt động vs Thùng rác / Đã xóa -->
    <div class="px-6 py-4 border-b border-white/10 flex flex-col sm:flex-row sm:items-center justify-between gap-3 bg-white/[0.02]">
      <div class="flex items-center space-x-2">
        <a href="?tab=active" class="px-4 py-2 rounded-xl text-xs font-bold transition-all flex items-center space-x-2 <?= $currentTab === 'active' ? 'bg-cyan-500/20 text-cyan-300 border border-cyan-500/40 shadow-sm' : 'bg-white/5 hover:bg-white/10 text-slate-400 hover:text-white border border-transparent' ?>">
          <i class="fa-solid fa-list-check"></i>
          <span>Đang hoạt động</span>
          <span class="ml-1 px-1.5 py-0.5 rounded-full text-[10px] <?= $currentTab === 'active' ? 'bg-cyan-400/20 text-cyan-200' : 'bg-white/10 text-slate-400' ?>"><?= $activeCount ?></span>
        </a>
        <a href="?tab=trash" class="px-4 py-2 rounded-xl text-xs font-bold transition-all flex items-center space-x-2 <?= $currentTab === 'trash' ? 'bg-rose-500/20 text-rose-300 border border-rose-500/40 shadow-sm' : 'bg-white/5 hover:bg-white/10 text-slate-400 hover:text-white border border-transparent' ?>">
          <i class="fa-solid fa-trash-can"></i>
          <span>Thùng rác / Đã xóa</span>
          <span class="ml-1 px-1.5 py-0.5 rounded-full text-[10px] <?= $currentTab === 'trash' ? 'bg-rose-400/20 text-rose-200' : 'bg-white/10 text-slate-400' ?>"><?= $trashCount ?></span>
        </a>
      </div>
      <span class="text-xs text-slate-400">
        <?= $currentTab === 'trash' ? 'Hiển thị ' . count($problems) . ' bài tập trong thùng rác (có thể khôi phục)' : 'Hiển thị ' . count($problems) . ' bài tập đang hoạt động' ?>
      </span>
    </div>

    <div class="overflow-x-auto">
      <table class="w-full text-left border-collapse">
        <thead>
          <tr class="bg-white/5 text-[11px] uppercase tracking-wider text-slate-400 font-bold border-b border-white/10">
            <th class="py-4 px-6">Slug</th>
            <th class="py-4 px-6">Tiêu đề & Giới hạn</th>
            <?php if (Auth::isAdmin()): ?>
              <th class="py-4 px-6">Người tạo</th>
            <?php endif; ?>
            <th class="py-4 px-6 text-center">Chủ đề</th>
            <th class="py-4 px-6 text-center">Độ khó</th>
            <th class="py-4 px-6 text-center">Phiên bản</th>
            <th class="py-4 px-6 text-center">Bộ Test</th>
            <th class="py-4 px-6 text-center">Lượt nộp</th>
            <th class="py-4 px-6 text-right">Thao tác</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-white/5 text-sm">
          <?php if (empty($problems)): ?>
            <tr>
              <td colspan="<?= Auth::isAdmin() ? 9 : 8 ?>" class="py-12 text-center text-slate-500">
                <?php if ($currentTab === 'trash'): ?>
                  <i class="fa-solid fa-trash-can text-4xl mb-3 block text-slate-600"></i>
                  Thùng rác trống! Không có bài tập nào bị xóa.
                <?php else: ?>
                  <i class="fa-solid fa-box-open text-4xl mb-3 block"></i>
                  Chưa có bài tập nào. Hãy nhấn "Tạo bài tập mới" để bắt đầu!
                <?php endif; ?>
              </td>
            </tr>
          <?php else: ?>
            <?php foreach ($problems as $p): ?>
              <tr class="hover:bg-white/[0.02] transition-colors group" id="problem-row-<?= (int)$p['id'] ?>">
                <!-- Cột Slug (nếu ở tab hoạt động thì bấm vào mở ngay trang làm bài solve.php) -->
                <td class="py-4 px-6 font-mono font-bold <?= $currentTab === 'trash' ? 'text-slate-400 line-through' : 'text-cyan-400' ?>">
                  <?php if ($currentTab === 'trash'): ?>
                    <span><?= htmlspecialchars($p['slug']) ?></span>
                  <?php else: ?>
                    <a href="/BaiTapLon/solve.php?slug=<?= urlencode($p['slug']) ?>" class="hover:underline">
                      <?= htmlspecialchars($p['slug']) ?>
                    </a>
                  <?php endif; ?>
                </td>
                <!-- Cột Tiêu đề, huy hiệu phân quyền hiển thị (Công khai / Ẩn / Phòng thi) & giới hạn tài nguyên -->
                <td class="py-4 px-6">
                  <div class="flex items-center space-x-2 flex-wrap gap-y-1">
                    <span class="font-semibold <?= $currentTab === 'trash' ? 'text-slate-300' : 'text-white group-hover:text-cyan-300' ?> transition-colors">
                      <?= htmlspecialchars($p['title']) ?>
                    </span>
                    <?php
                      // Hiển thị nhãn phân quyền tương ứng với cột visibility
                      $vis = $p['visibility'] ?? ($p['is_public'] ? 'public' : 'hidden');
                      if ($vis === 'hidden'):
                    ?>
                      <span class="px-1.5 py-0.5 rounded text-[10px] font-bold bg-slate-500/20 text-slate-300 border border-slate-500/30" title="Bài tập đang ẩn để bảo trì">
                        <i class="fa-regular fa-eye-slash mr-0.5"></i>Ẩn bảo trì
                      </span>
                    <?php elseif ($vis === 'exam_only'): ?>
                      <span class="px-1.5 py-0.5 rounded text-[10px] font-bold bg-purple-500/20 text-purple-300 border border-purple-500/30" title="Chỉ dùng cho kỳ thi / phòng thi riêng">
                        <i class="fa-solid fa-user-lock mr-0.5"></i>Phòng thi
                      </span>
                    <?php else: ?>
                      <span class="px-1.5 py-0.5 rounded text-[10px] font-bold bg-emerald-500/10 text-emerald-400 border border-emerald-500/20" title="Công khai cho sinh viên luyện tập">
                        <i class="fa-solid fa-globe mr-0.5"></i>Công khai
                      </span>
                    <?php endif; ?>
                  </div>
                  <div class="text-xs text-slate-400 flex items-center space-x-3 mt-1">
                    <?php if ($currentTab === 'trash' && !empty($p['deleted_at'])): ?>
                      <span class="text-rose-400"><i class="fa-regular fa-calendar-xmark mr-1"></i>Đã xóa: <?= date('d/m/Y H:i', strtotime($p['deleted_at'])) ?></span>
                    <?php else: ?>
                      <span><i class="fa-regular fa-clock text-slate-500 mr-1"></i><?= $p['time_limit_ms'] ?? 1000 ?> ms</span>
                      <span><i class="fa-solid fa-memory text-slate-500 mr-1"></i><?= number_format((int)($p['memory_limit_kb'] ?? 262144)) ?> KB (<?= round(($p['memory_limit_kb'] ?? 262144) / 1024) ?> MB)</span>
                    <?php endif; ?>
                  </div>
                </td>
                <!-- Cột Người tạo (chỉ hiện với tài khoản Admin vì Admin xem bài của tất cả Giảng viên) -->
                <?php if (Auth::isAdmin()): ?>
                  <td class="py-4 px-6">
                    <div class="flex items-center space-x-2.5">
                      <div class="w-8 h-8 rounded-xl bg-gradient-to-tr from-cyan-600/20 to-blue-500/20 border border-cyan-500/30 text-cyan-300 font-bold flex items-center justify-center text-xs shrink-0 shadow-sm">
                        <?= strtoupper(substr($p['creator_username'] ?? 'U', 0, 1)) ?>
                      </div>
                      <div class="truncate max-w-[150px]">
                        <div class="text-xs font-semibold text-slate-200 truncate">
                          <?= htmlspecialchars($p['creator_name'] ?: ($p['creator_username'] ?: 'Quản trị viên')) ?>
                        </div>
                        <div class="text-[10px] text-cyan-400 font-mono">
                          @<?= htmlspecialchars($p['creator_username'] ?: 'admin') ?>
                        </div>
                      </div>
                    </div>
                  </td>
                <?php endif; ?>
                <td class="py-4 px-6 text-center text-slate-300 text-xs">
                  <?= htmlspecialchars($p['topic']) ?>
                </td>
                <td class="py-4 px-6 text-center">
                  <?php
                    $diffBadge = match($p['difficulty']) {
                        'Easy'   => 'bg-emerald-500/20 text-emerald-400 border-emerald-500/30',
                        'Medium' => 'bg-amber-500/20 text-amber-400 border-amber-500/30',
                        'Hard'   => 'bg-rose-500/20 text-rose-400 border-rose-500/30',
                        default  => 'bg-slate-500/20 text-slate-400 border-slate-500/30'
                    };
                  ?>
                  <span class="inline-block px-2.5 py-1 rounded-full text-xs font-semibold border <?= $diffBadge ?>">
                    <?= htmlspecialchars($p['difficulty']) ?>
                  </span>
                </td>
                <td class="py-4 px-6 text-center">
                  <span class="px-2.5 py-1 rounded-lg bg-cyan-500/10 text-cyan-300 border border-cyan-500/30 text-xs font-mono font-bold">
                    v<?= $p['current_version_number'] ?? '1' ?>
                  </span>
                </td>
                <td class="py-4 px-6 text-center text-xs">
                  <div class="flex items-center justify-center space-x-2">
                    <span class="px-2 py-0.5 rounded bg-emerald-500/10 text-emerald-400 border border-emerald-500/20" title="Công khai">
                      <i class="fa-regular fa-eye mr-1"></i><?= $p['public_test_count'] ?>
                    </span>
                    <span class="px-2 py-0.5 rounded bg-purple-500/10 text-purple-400 border border-purple-500/20" title="Chấm kín">
                      <i class="fa-solid fa-shield-halved mr-1"></i><?= $p['hidden_test_count'] ?>
                    </span>
                  </div>
                </td>
                <td class="py-4 px-6 text-center font-mono text-slate-300">
                  <?= $p['total_submissions'] ?>
                </td>
                <!-- Cột Thao tác: ở Thùng rác có Khôi phục / Xóa vĩnh viễn; ở tab Hoạt động có Sửa, Test Cases, Đối chiếu Rejudge, Giải thử, Xóa -->
                <td class="py-4 px-6 text-right space-x-1.5 whitespace-nowrap">
                  <?php if ($currentTab === 'trash'): ?>
                    <!-- Khôi phục bài tập từ Thùng rác -->
                    <button 
                      type="button"
                      onclick='restoreProblem(<?= (int)$p['id'] ?>, <?= htmlspecialchars(json_encode($p['title']), ENT_QUOTES, 'UTF-8') ?>)'
                      class="px-3 py-1.5 rounded-lg bg-emerald-500/10 hover:bg-emerald-500/20 text-emerald-400 hover:text-emerald-300 border border-emerald-500/30 text-xs font-semibold inline-flex items-center space-x-1.5 transition-colors cursor-pointer"
                      title="Khôi phục bài tập trở lại danh sách hoạt động"
                    >
                      <i class="fa-solid fa-rotate-left"></i>
                      <span>Khôi phục</span>
                    </button>

                    <?php if ((int)$p['total_submissions'] === 0): ?>
                      <!-- Xóa vĩnh viễn (Chỉ hiện khi 0 lượt nộp) -->
                      <button 
                        type="button"
                        onclick='openDeleteModal(<?= (int)$p['id'] ?>, <?= htmlspecialchars(json_encode($p['title']), ENT_QUOTES, 'UTF-8') ?>, <?= htmlspecialchars(json_encode($p['slug']), ENT_QUOTES, 'UTF-8') ?>, 0, true)'
                        class="px-3 py-1.5 rounded-lg bg-rose-500/10 hover:bg-rose-500/20 text-rose-400 hover:text-rose-300 border border-rose-500/30 text-xs font-semibold inline-flex items-center space-x-1.5 transition-colors cursor-pointer"
                        title="Xóa vĩnh viễn khỏi cơ sở dữ liệu (0 lượt nộp)"
                      >
                        <i class="fa-solid fa-fire"></i>
                        <span>Xóa vĩnh viễn</span>
                      </button>
                    <?php else: ?>
                      <span class="px-2.5 py-1.5 rounded-lg bg-white/5 text-slate-500 border border-white/10 text-[11px] inline-flex items-center space-x-1 cursor-not-allowed" title="Bài tập có <?= (int)$p['total_submissions'] ?> bài nộp của học viên nên không cho phép xóa vĩnh viễn để bảo vệ dữ liệu học tập">
                        <i class="fa-solid fa-lock text-[10px]"></i>
                        <span>Bảo lưu dữ liệu</span>
                      </span>
                    <?php endif; ?>
                  <?php else: ?>
                    <!-- Sửa thông tin cơ bản (mở Modal 2: editModal) -->
                    <button 
                      onclick='openEditModal(<?= json_encode($p, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>)'
                      class="p-2 rounded-lg bg-white/5 hover:bg-white/10 text-slate-300 hover:text-white transition-colors cursor-pointer"
                      title="Chỉnh sửa thông tin bài"
                    >
                      <i class="fa-solid fa-pen-to-square"></i>
                    </button>

                    <!-- Sửa & Quản lý Test Cases (mở Modal 3: testcasesModal) -->
                    <button 
                      type="button"
                      onclick='openTestcasesModal(<?= $p['id'] ?>, <?= htmlspecialchars(json_encode($p['title']), ENT_QUOTES, 'UTF-8') ?>, <?= (int)$p['total_submissions'] ?>, <?= (int)($p['current_version_number'] ?? 1) ?>)'
                      class="p-2 rounded-lg bg-amber-500/10 hover:bg-amber-500/20 text-amber-400 hover:text-amber-300 border border-amber-500/30 transition-colors inline-block cursor-pointer"
                      title="Xem, Thêm & Sửa Bộ Test Cases"
                    >
                      <i class="fa-solid fa-vial"></i>
                    </button>

                    <!-- Chuyển tới trang Quản lý phiên bản & Đối chiếu Rejudge -->
                    <a 
                      href="/BaiTapLon/rejudge_compare.php?problem_id=<?= $p['id'] ?>"
                      class="p-2 rounded-lg bg-cyan-500/10 hover:bg-cyan-500/20 text-cyan-400 hover:text-cyan-300 border border-cyan-500/30 transition-colors inline-block"
                      title="Tạo phiên bản mới & Chấm lại đối chiếu"
                    >
                      <i class="fa-solid fa-code-compare"></i>
                    </a>

                    <!-- Mở không gian giải bài (solve.php) để Giảng viên/Admin tự kiểm tra -->
                    <a 
                      href="/BaiTapLon/solve.php?id=<?= $p['id'] ?>"
                      class="p-2 rounded-lg bg-emerald-500/10 hover:bg-emerald-500/20 text-emerald-400 hover:text-emerald-300 border border-emerald-500/30 transition-colors inline-block"
                      title="Mở Workspace giải bài"
                    >
                      <i class="fa-solid fa-play"></i>
                    </a>

                    <!-- Xóa bài tập (mở Modal 5: deleteProblemModal hỗ trợ xóa mềm / xóa vĩnh viễn) -->
                    <button 
                      type="button"
                      onclick='openDeleteModal(<?= (int)$p['id'] ?>, <?= htmlspecialchars(json_encode($p['title']), ENT_QUOTES, 'UTF-8') ?>, <?= htmlspecialchars(json_encode($p['slug']), ENT_QUOTES, 'UTF-8') ?>, <?= (int)$p['total_submissions'] ?>, false)'
                      class="p-2 rounded-lg bg-rose-500/10 hover:bg-rose-500/20 text-rose-400 hover:text-rose-300 border border-rose-500/30 transition-colors inline-block cursor-pointer"
                      title="Xóa bài tập"
                    >
                      <i class="fa-solid fa-trash-can"></i>
                    </button>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- ========================================================================= -->
<!-- MODAL 1: TẠO BÀI TẬP MỚI (Form POST action="create_problem")              -->
<!-- Chứa các trường thông tin bài toán, giới hạn chạy/bộ nhớ, phân quyền      -->
<!-- hiển thị và vùng thêm nhiều Test Cases khởi tạo (hoặc nhập từ ZIP/JSON)   -->
<!-- ========================================================================= -->
<div id="createModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/80 backdrop-blur-sm hidden p-4 overflow-y-auto">
  <div class="glass-panel w-full max-w-3xl rounded-2xl border border-white/20 p-6 my-8 space-y-5 shadow-2xl flex flex-col max-h-[92vh]">
    <div class="flex items-center justify-between border-b border-white/10 pb-4 shrink-0">
      <h3 class="text-xl font-bold text-white flex items-center space-x-2">
        <i class="fa-solid fa-plus-circle text-cyan-400"></i>
        <span>Tạo Bài Tập Mới</span>
      </h3>
      <button onclick="closeModal('createModal')" class="text-slate-400 hover:text-white text-xl">
        <i class="fa-solid fa-xmark"></i>
      </button>
    </div>

    <form method="POST" action="" class="space-y-4 overflow-y-auto pr-1 flex-1">
      <input type="hidden" name="action" value="create_problem">

      <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
        <div>
          <label class="block text-xs uppercase font-bold text-slate-300 mb-1">Mã định danh (Slug) <span class="text-rose-400">*</span></label>
          <input type="text" name="slug" required placeholder="VD: two-sum-2" class="w-full px-4 py-2.5 rounded-xl bg-black/40 border border-white/15 text-white font-mono text-sm focus:outline-none focus:border-cyan-400">
        </div>
        <div>
          <label class="block text-xs uppercase font-bold text-slate-300 mb-1">Tiêu đề bài tập <span class="text-rose-400">*</span></label>
          <input type="text" name="title" required placeholder="VD: Tổng hai số mở rộng" class="w-full px-4 py-2.5 rounded-xl bg-black/40 border border-white/15 text-white text-sm focus:outline-none focus:border-cyan-400">
        </div>
      </div>

      <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
        <div>
          <label class="block text-xs uppercase font-bold text-slate-300 mb-1">Chủ đề (Topic)</label>
          <input type="text" name="topic" value="Cơ bản" class="w-full px-4 py-2.5 rounded-xl bg-black/40 border border-white/15 text-white text-sm focus:outline-none focus:border-cyan-400">
        </div>
        <div>
          <label class="block text-xs uppercase font-bold text-slate-300 mb-1">Độ khó</label>
          <select name="difficulty" class="w-full px-4 py-2.5 rounded-xl bg-black/40 border border-white/15 text-white text-sm focus:outline-none focus:border-cyan-400">
            <option value="Easy">Easy (Dễ)</option>
            <option value="Medium">Medium (Trung bình)</option>
            <option value="Hard">Hard (Khó)</option>
          </select>
        </div>
        <div>
          <label class="block text-xs uppercase font-bold text-slate-300 mb-1">Thời gian (ms)</label>
          <input type="number" name="time_limit_ms" value="1000" min="100" max="10000" class="w-full px-4 py-2.5 rounded-xl bg-black/40 border border-white/15 text-white text-sm focus:outline-none focus:border-cyan-400">
        </div>
      </div>

      <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
        <div>
          <label class="block text-xs uppercase font-bold text-slate-300 mb-1">Bộ nhớ tối đa (MB)</label>
          <input type="number" name="memory_limit_mb" value="256" min="16" max="1024" class="w-full px-4 py-2.5 rounded-xl bg-black/40 border border-white/15 text-white text-sm focus:outline-none focus:border-cyan-400">
        </div>
        <div>
          <label class="block text-xs uppercase font-bold text-slate-300 mb-1">Phân quyền hiển thị</label>
          <select name="visibility" class="w-full px-4 py-2.5 rounded-xl bg-black/40 border border-white/15 text-white text-sm focus:outline-none focus:border-cyan-400">
            <option value="public">🌐 Công khai (Sinh viên làm tự do)</option>
            <option value="hidden">👁️‍🗨️ Ẩn bảo trì (Chỉ Admin/GV thấy)</option>
            <option value="exam_only">🔒 Phòng thi riêng (Kỳ thi / Lớp học)</option>
          </select>
        </div>
        <div>
          <label class="block text-xs uppercase font-bold text-slate-300 mb-1">Nguồn bài (Attribution)</label>
          <input type="text" name="source_attribution" value="CSES Problem Set" class="w-full px-4 py-2.5 rounded-xl bg-black/40 border border-white/15 text-white text-sm focus:outline-none focus:border-cyan-400">
        </div>
      </div>

      <div>
        <label class="block text-xs uppercase font-bold text-slate-300 mb-1">Mô tả đề bài</label>
        <textarea name="description" rows="4" placeholder="Mô tả bài toán..." class="w-full p-4 rounded-xl bg-black/40 border border-white/15 text-white text-sm focus:outline-none focus:border-cyan-400 font-mono"></textarea>
      </div>

      <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
        <div>
          <label class="block text-xs uppercase font-bold text-slate-300 mb-1">Quy cách Dữ liệu vào (Input)</label>
          <textarea name="input_description" rows="2" placeholder="Dòng đầu gồm..." class="w-full p-2.5 rounded-xl bg-black/40 border border-white/15 text-white text-xs font-mono"></textarea>
        </div>
        <div>
          <label class="block text-xs uppercase font-bold text-slate-300 mb-1">Quy cách Dữ liệu ra (Output)</label>
          <textarea name="output_description" rows="2" placeholder="In ra kết quả..." class="w-full p-2.5 rounded-xl bg-black/40 border border-white/15 text-white text-xs font-mono"></textarea>
        </div>
      </div>

      <div>
        <label class="block text-xs uppercase font-bold text-slate-300 mb-1">Ràng buộc & Giới hạn (Constraints)</label>
        <textarea name="constraints" rows="2" placeholder="VD: 1 <= N <= 10^5, -10^9 <= A[i] <= 10^9..." class="w-full p-2.5 rounded-xl bg-black/40 border border-white/15 text-white text-xs font-mono"></textarea>
      </div>

      <!-- BỘ TEST CASES BAN ĐẦU (HỖ TRỢ NHIỀU TEST CASE) -->
      <div class="p-4 rounded-xl bg-cyan-500/5 border border-cyan-500/20 space-y-3">
        <div class="flex items-center justify-between">
          <div>
            <h4 class="text-xs uppercase font-bold text-cyan-400 flex items-center gap-2">
              <i class="fa-solid fa-vial"></i>
              <span>Bộ Test Cases Khởi Tạo</span>
            </h4>
            <p class="text-[11px] text-slate-400 mt-0.5">Thêm các test case công khai (mẫu) và chấm kín (bảo mật khi chấm).</p>
          </div>
          <div class="flex items-center space-x-2">
            <button type="button" onclick="openImportModal('create')" class="px-3 py-1.5 rounded-lg bg-purple-500/10 hover:bg-purple-500/20 text-purple-300 hover:text-purple-200 border border-purple-500/30 text-xs font-semibold flex items-center space-x-1.5 transition-colors cursor-pointer" title="Nhập test case từ file ZIP hoặc JSON">
              <i class="fa-solid fa-file-import text-[11px]"></i>
              <span>Nhập từ File (ZIP/JSON)</span>
            </button>
            <button type="button" onclick="addCreateTestcaseRow()" class="px-3 py-1.5 rounded-lg bg-cyan-500/10 hover:bg-cyan-500/20 text-cyan-400 hover:text-cyan-300 border border-cyan-500/30 text-xs font-semibold flex items-center space-x-1.5 transition-colors cursor-pointer">
              <i class="fa-solid fa-plus text-[10px]"></i>
              <span>Thêm Test Case</span>
            </button>
          </div>
        </div>

        <div id="create-tc-list" class="space-y-3">
          <!-- Các test case được tạo tự động bởi JS -->
        </div>
      </div>

      <div class="flex items-center justify-end space-x-3 pt-3 border-t border-white/10 shrink-0">
        <button type="button" onclick="closeModal('createModal')" class="px-5 py-2.5 rounded-xl bg-white/5 hover:bg-white/10 text-slate-300 text-sm font-semibold transition-colors">
          Hủy bỏ
        </button>
        <button type="submit" class="btn-cyan px-6 py-2.5 rounded-xl text-black font-bold text-sm cursor-pointer shadow-[0_0_15px_rgba(0,255,255,0.4)]">
          Lưu & Khởi tạo v1
        </button>
      </div>
    </form>
  </div>
</div>

<!-- ========================================================================= -->
<!-- MODAL 2: CHỈNH SỬA BÀI TẬP (Form POST action="edit_problem")              -->
<!-- Dữ liệu hiện tại được nạp sẵn bởi hàm JS openEditModal().                 -->
<!-- Hỗ trợ 2 chế độ: Sửa đè phiên bản hiện tại hoặc tích chọn phát hành v(N+1)-->
<!-- ========================================================================= -->
<div id="editModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/80 backdrop-blur-sm hidden p-4 overflow-y-auto">
  <div class="glass-panel w-full max-w-2xl rounded-2xl border border-white/20 p-6 my-8 space-y-6 shadow-2xl">
    <div class="flex items-center justify-between border-b border-white/10 pb-4">
      <h3 class="text-xl font-bold text-white flex items-center space-x-2">
        <i class="fa-solid fa-pen-to-square text-cyan-400"></i>
        <span>Chỉnh Sửa Bài Tập</span>
      </h3>
      <button onclick="closeModal('editModal')" class="text-slate-400 hover:text-white text-xl">
        <i class="fa-solid fa-xmark"></i>
      </button>
    </div>

    <form method="POST" action="" class="space-y-4">
      <input type="hidden" name="action" value="edit_problem">
      <input type="hidden" name="problem_id" id="edit_problem_id">

      <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
        <div>
          <label class="block text-xs uppercase font-bold text-slate-300 mb-1">Mã slug (Không thể đổi)</label>
          <input type="text" id="edit_slug" disabled class="w-full px-4 py-2.5 rounded-xl bg-white/5 border border-white/10 text-slate-400 font-mono text-sm cursor-not-allowed">
        </div>
        <div>
          <label class="block text-xs uppercase font-bold text-slate-300 mb-1">Tiêu đề bài tập <span class="text-rose-400">*</span></label>
          <input type="text" name="title" id="edit_title" required class="w-full px-4 py-2.5 rounded-xl bg-black/40 border border-white/15 text-white text-sm focus:outline-none focus:border-cyan-400">
        </div>
      </div>

      <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
        <div>
          <label class="block text-xs uppercase font-bold text-slate-300 mb-1">Chủ đề (Topic)</label>
          <input type="text" name="topic" id="edit_topic" class="w-full px-4 py-2.5 rounded-xl bg-black/40 border border-white/15 text-white text-sm focus:outline-none focus:border-cyan-400">
        </div>
        <div>
          <label class="block text-xs uppercase font-bold text-slate-300 mb-1">Độ khó</label>
          <select name="difficulty" id="edit_difficulty" class="w-full px-4 py-2.5 rounded-xl bg-black/40 border border-white/15 text-white text-sm focus:outline-none focus:border-cyan-400">
            <option value="Easy">Easy (Dễ)</option>
            <option value="Medium">Medium (Trung bình)</option>
            <option value="Hard">Hard (Khó)</option>
          </select>
        </div>
        <div>
          <label class="block text-xs uppercase font-bold text-slate-300 mb-1">Thời gian (ms)</label>
          <input type="number" name="time_limit_ms" id="edit_time_limit_ms" min="100" max="10000" class="w-full px-4 py-2.5 rounded-xl bg-black/40 border border-white/15 text-white text-sm focus:outline-none focus:border-cyan-400">
        </div>
      </div>

      <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
        <div>
          <label class="block text-xs uppercase font-bold text-slate-300 mb-1">Bộ nhớ tối đa (MB)</label>
          <input type="number" name="memory_limit_mb" id="edit_memory_limit_mb" min="16" max="1024" class="w-full px-4 py-2.5 rounded-xl bg-black/40 border border-white/15 text-white text-sm focus:outline-none focus:border-cyan-400">
        </div>
        <div>
          <label class="block text-xs uppercase font-bold text-slate-300 mb-1">Phân quyền hiển thị</label>
          <select name="visibility" id="edit_visibility" class="w-full px-4 py-2.5 rounded-xl bg-black/40 border border-white/15 text-white text-sm focus:outline-none focus:border-cyan-400">
            <option value="public">🌐 Công khai (Sinh viên làm tự do)</option>
            <option value="hidden">👁️‍🗨️ Ẩn bảo trì (Chỉ Admin/GV thấy)</option>
            <option value="exam_only">🔒 Phòng thi riêng (Kỳ thi / Lớp học)</option>
          </select>
        </div>
      </div>

      <div>
        <label class="block text-xs uppercase font-bold text-slate-300 mb-1">Mô tả bài toán</label>
        <textarea name="description" id="edit_description" rows="4" class="w-full p-4 rounded-xl bg-black/40 border border-white/15 text-white text-sm focus:outline-none focus:border-cyan-400 font-mono"></textarea>
      </div>

      <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
        <div>
          <label class="block text-xs uppercase font-bold text-slate-300 mb-1">Dữ liệu vào</label>
          <textarea name="input_description" id="edit_input_description" rows="2" class="w-full p-2.5 rounded-xl bg-black/40 border border-white/15 text-white text-xs font-mono"></textarea>
        </div>
        <div>
          <label class="block text-xs uppercase font-bold text-slate-300 mb-1">Dữ liệu ra</label>
          <textarea name="output_description" id="edit_output_description" rows="2" class="w-full p-2.5 rounded-xl bg-black/40 border border-white/15 text-white text-xs font-mono"></textarea>
        </div>
      </div>

      <div>
        <label class="block text-xs uppercase font-bold text-slate-300 mb-1">Ràng buộc & Giới hạn (Constraints)</label>
        <textarea name="constraints" id="edit_constraints" rows="2" class="w-full p-2.5 rounded-xl bg-black/40 border border-white/15 text-white text-xs font-mono"></textarea>
      </div>

      <!-- Tùy chọn Quản lý phiên bản đề (problem_versions) -->
      <div class="p-3.5 rounded-xl bg-cyan-500/5 border border-cyan-500/20 space-y-2.5">
        <label class="flex items-start space-x-2.5 cursor-pointer">
          <input type="checkbox" name="create_new_version" id="edit_create_new_version" value="1"
            onchange="document.getElementById('edit-changelog-wrap').classList.toggle('hidden', !this.checked)"
            class="mt-0.5 accent-cyan-400">
          <div class="text-xs">
            <span class="font-bold text-cyan-300">Phát hành thành phiên bản đề mới (v(N+1))</span>
            <p class="text-[11px] text-slate-400 mt-0.5">
              Lưu các thay đổi về đề bài, ràng buộc và giới hạn thời gian/bộ nhớ thành một phiên bản mới, bảo toàn 100% lịch sử các đề cũ.
            </p>
          </div>
        </label>
        <div id="edit-changelog-wrap" class="hidden">
          <input type="text" name="changelog" id="edit_changelog" placeholder="Ghi chú thay đổi phiên bản (VD: Tăng time limit lên 2000ms, làm rõ ràng buộc N <= 10^5)..."
            class="w-full px-3 py-2 rounded-lg bg-black/50 border border-white/15 text-white text-xs focus:outline-none focus:border-cyan-400">
        </div>
      </div>

      <div class="flex items-center justify-end space-x-3 pt-4 border-t border-white/10">
        <button type="button" onclick="closeModal('editModal')" class="px-5 py-2.5 rounded-xl bg-white/5 hover:bg-white/10 text-slate-300 text-sm font-semibold transition-colors">
          Hủy bỏ
        </button>
        <button type="submit" class="btn-cyan px-6 py-2.5 rounded-xl text-black font-bold text-sm cursor-pointer shadow-[0_0_15px_rgba(0,255,255,0.4)]">
          Cập nhật thông tin
        </button>
      </div>
    </form>
  </div>
</div>

<!-- ========================================================================= -->
<!-- MODAL 3: QUẢN LÝ & SỬA BỘ TEST CASES (Tương tác AJAX với version_rejudge) -->
<!-- Tự động phân biệt 2 chế độ theo số bài nộp của bài tập:                   -->
<!--   + 0 bài nộp: Sửa trực tiếp vào phiên bản hiện hành (In-place Update)    -->
<!--   + >0 bài nộp: Tự động sinh phiên bản v(N+1) và đẩy Rejudge toàn bộ bài  -->
<!-- ========================================================================= -->
<div id="testcasesModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/80 backdrop-blur-sm hidden p-4 overflow-y-auto">
  <div class="glass-panel w-full max-w-4xl rounded-2xl border border-white/20 p-6 my-8 space-y-6 shadow-2xl flex flex-col max-h-[92vh]">
    <!-- Header -->
    <div class="flex items-center justify-between border-b border-white/10 pb-4 shrink-0">
      <div class="space-y-1">
        <h3 class="text-xl font-bold text-white flex items-center space-x-2">
          <i class="fa-solid fa-vial text-amber-400"></i>
          <span>Quản lý & Sửa Test Cases</span>
        </h3>
        <p class="text-xs text-slate-400 flex items-center gap-2">
          <span id="tc-modal-title" class="font-semibold text-cyan-300">Đang tải...</span>
          <span>•</span>
          <span id="tc-modal-version-badge" class="px-2 py-0.5 rounded bg-cyan-500/10 text-cyan-400 border border-cyan-500/30 text-[11px] font-mono">v1</span>
          <span>•</span>
          <span id="tc-modal-sub-badge" class="px-2 py-0.5 rounded bg-purple-500/10 text-purple-400 border border-purple-500/30 text-[11px] font-mono">0 bài nộp</span>
        </p>
      </div>
      <button onclick="closeModal('testcasesModal')" class="text-slate-400 hover:text-white text-xl">
        <i class="fa-solid fa-xmark"></i>
      </button>
    </div>

    <!-- Alert / Banner thông báo chế độ hoạt động -->
    <div id="tc-mode-banner" class="shrink-0"></div>

    <!-- Ô nhập Changelog (Chỉ hiện khi bài tập đã có bài nộp) -->
    <div id="tc-changelog-container" class="shrink-0 hidden">
      <label class="block text-xs uppercase font-bold text-amber-300 mb-1">
        <i class="fa-solid fa-pen-nib mr-1"></i>Ghi chú phiên bản mới (Changelog)
      </label>
      <input type="text" id="tc-changelog-input" value="Sửa và cập nhật bộ test case" placeholder="VD: Sửa test case #2 do nhầm kết quả mong đợi" class="w-full px-4 py-2.5 rounded-xl bg-black/40 border border-amber-500/30 text-white text-sm focus:outline-none focus:border-amber-400 font-sans">
      <p class="text-[11px] text-slate-400 mt-1">Ghi chú này sẽ được lưu vào lịch sử phiên bản để học sinh và giảng viên tiện theo dõi.</p>
    </div>

    <!-- Danh sách Test Cases (Cuộn) -->
    <div class="flex-1 overflow-y-auto space-y-4 pr-1 min-h-[220px]" id="tc-list-container">
      <!-- Dynamic testcase cards loaded here -->
    </div>

    <!-- Nút thêm test case & Nhập từ file -->
    <div class="shrink-0 flex items-center justify-between pt-2 border-t border-white/10">
      <div class="flex items-center space-x-2">
        <button type="button" onclick="addTestcaseRow()" class="px-4 py-2 rounded-xl bg-white/5 hover:bg-white/10 text-cyan-400 hover:text-cyan-300 border border-cyan-500/30 text-xs font-bold flex items-center space-x-2 transition-colors cursor-pointer">
          <i class="fa-solid fa-plus"></i>
          <span>Thêm Test Case Mới</span>
        </button>
        <button type="button" onclick="openImportModal('edit')" class="px-4 py-2 rounded-xl bg-purple-500/10 hover:bg-purple-500/20 text-purple-300 hover:text-purple-200 border border-purple-500/30 text-xs font-bold flex items-center space-x-2 transition-colors cursor-pointer" title="Nhập test case từ file ZIP hoặc JSON">
          <i class="fa-solid fa-file-import"></i>
          <span>Nhập từ File (ZIP/JSON)</span>
        </button>
      </div>
      <span class="text-xs text-slate-400" id="tc-total-count-label">Tổng cộng: 0 test case</span>
    </div>

    <!-- Footer actions -->
    <div class="flex items-center justify-between pt-4 border-t border-white/10 shrink-0">
      <div id="tc-save-status" class="text-xs"></div>
      <div class="flex items-center space-x-3">
        <button type="button" onclick="closeModal('testcasesModal')" class="px-5 py-2.5 rounded-xl bg-white/5 hover:bg-white/10 text-slate-300 text-sm font-semibold transition-colors">
          Hủy bỏ
        </button>
        <button type="button" id="btn-save-testcases" onclick="saveTestcases()" class="btn-cyan px-6 py-2.5 rounded-xl text-black font-bold text-sm cursor-pointer shadow-[0_0_15px_rgba(0,255,255,0.4)] flex items-center space-x-2">
          <i class="fa-solid fa-floppy-disk"></i>
          <span>Lưu Bộ Test Cases</span>
        </button>
      </div>
    </div>
  </div>
</div>

<!-- ========================================================================= -->
<!-- MODAL 4: NHẬP VÀ THẨM ĐỊNH BỘ TEST CASES TỪ TỆP ZIP/JSON (2 GIAI ĐOẠN)    -->
<!--   + Giai đoạn 1: Chọn file .ZIP/.JSON và (tùy chọn) dán lời giải chuẩn    -->
<!--     để máy chủ tự chạy đối chiếu đáp án (Thẩm định Cấp độ 3).             -->
<!--   + Giai đoạn 2: Xem bảng báo cáo ghép cặp, cảnh báo file mồ côi và áp    -->
<!--     dụng (Thay thế hoặc Nối tiếp) vào Modal Tạo bài hoặc Modal Sửa test.  -->
<!-- ========================================================================= -->
<div id="importTestcasesModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/80 backdrop-blur-sm hidden p-4 overflow-y-auto">
  <div class="glass-panel w-full max-w-4xl rounded-2xl border border-white/20 p-6 my-8 space-y-5 shadow-2xl flex flex-col max-h-[92vh]">
    <!-- Header -->
    <div class="flex items-center justify-between border-b border-white/10 pb-4 shrink-0">
      <div class="space-y-1">
        <h3 class="text-xl font-bold text-white flex items-center space-x-2">
          <i class="fa-solid fa-file-import text-purple-400"></i>
          <span>Nhập & Thẩm Định Bộ Test Case Từ File</span>
        </h3>
        <p class="text-xs text-slate-400">
          Tự động ghép cặp các file <code class="text-cyan-300 font-mono">.in</code> / <code class="text-cyan-300 font-mono">.out</code> trong tệp ZIP hoặc tệp JSON, kiểm tra cấu trúc và đối chiếu tính đúng đắn.
        </p>
      </div>
      <button onclick="closeModal('importTestcasesModal')" class="text-slate-400 hover:text-white text-xl">
        <i class="fa-solid fa-xmark"></i>
      </button>
    </div>

    <!-- Body -->
    <div class="overflow-y-auto pr-1 flex-1 space-y-4" id="import-body-stage">
      <!-- GIAI ĐOẠN 1: CHỌN TỆP & CẤU HÌNH ĐỐI SOÁT -->
      <div id="import-stage-upload" class="space-y-4">
        <!-- Vùng kéo thả tệp -->
        <div class="p-6 rounded-2xl bg-white/[0.02] border-2 border-dashed border-white/20 hover:border-purple-400/50 transition-all text-center space-y-3 cursor-pointer group" onclick="document.getElementById('import-file-input').click()">
          <input type="file" id="import-file-input" accept=".zip,.json" class="hidden" onchange="handleImportFileSelected(this)">
          <div class="w-14 h-14 rounded-2xl bg-purple-500/10 border border-purple-500/30 text-purple-400 flex items-center justify-center text-2xl mx-auto group-hover:scale-110 transition-transform">
            <i class="fa-solid fa-cloud-arrow-up"></i>
          </div>
          <div>
            <div class="text-sm font-bold text-white group-hover:text-purple-300 transition-colors" id="import-file-name-label">
              Nhấp hoặc kéo thả tệp .ZIP hoặc .JSON vào đây
            </div>
            <div class="text-xs text-slate-400 mt-1">
              Hỗ trợ tệp nén ZIP (tối đa 25MB) chứa các cặp file văn bản (.in/.out) hoặc tệp danh sách .JSON
            </div>
          </div>
        </div>

        <!-- Hướng dẫn quy cách đặt tên file -->
        <div class="p-4 rounded-xl bg-cyan-500/5 border border-cyan-500/20 text-xs text-slate-300 space-y-1.5">
          <div class="font-bold text-cyan-300 flex items-center space-x-1.5">
            <i class="fa-solid fa-circle-info"></i>
            <span>Quy cách cấu trúc tệp ZIP được hỗ trợ tự động:</span>
          </div>
          <ul class="list-disc list-inside space-y-1 text-slate-400 ml-1">
            <li>Theo đuôi mở rộng: <code class="text-cyan-300">1.in</code> & <code class="text-cyan-300">1.out</code> (hoặc <code class="text-cyan-300">.inp</code> & <code class="text-cyan-300">.ans</code>)</li>
            <li>Theo tiền tố: <code class="text-cyan-300">input01.txt</code> & <code class="text-cyan-300">output01.txt</code></li>
            <li>Theo thư mục con: <code class="text-cyan-300">test01/input.txt</code> & <code class="text-cyan-300">test01/output.txt</code></li>
          </ul>
        </div>

        <!-- Tùy chọn Cấp độ 3: Đối soát với Lời giải chuẩn -->
        <div class="p-4 rounded-xl bg-white/[0.02] border border-white/10 space-y-3">
          <div class="flex items-center justify-between">
            <label class="flex items-center space-x-2 text-xs font-bold text-slate-200 cursor-pointer select-none">
              <input type="checkbox" id="enable-model-verify" class="rounded bg-black/50 border-white/20 text-purple-400 focus:ring-0" onchange="toggleModelVerifyBox(this.checked)">
              <span>Kích hoạt Thẩm định Cấp độ 3: Đối soát với Lời giải chuẩn (Model Solution)</span>
            </label>
            <span class="text-[11px] text-amber-400 font-semibold">Tự động phát hiện test case có đáp án sai</span>
          </div>

          <div id="model-verify-box" class="space-y-3 hidden pt-2 border-t border-white/5">
            <div class="flex items-center space-x-4">
              <span class="text-xs text-slate-300 font-semibold">Ngôn ngữ Lời giải chuẩn:</span>
              <label class="inline-flex items-center space-x-1.5 text-xs text-slate-300 cursor-pointer">
                <input type="radio" name="model_lang" value="python" checked class="accent-cyan-400">
                <span>Python 3</span>
              </label>
              <label class="inline-flex items-center space-x-1.5 text-xs text-slate-300 cursor-pointer">
                <input type="radio" name="model_lang" value="cpp" class="accent-cyan-400">
                <span>C++ (G++ 17)</span>
              </label>
            </div>
            <div>
              <label class="block text-[11px] font-mono text-slate-400 mb-1">Mã nguồn Lời giải chuẩn (Reference Solution):</label>
              <textarea id="model-solution-code" rows="4" placeholder="Dán mã nguồn lời giải chuẩn đã kiểm chứng vào đây..." class="w-full p-2.5 rounded-lg bg-black/60 border border-white/15 text-white font-mono text-xs focus:outline-none focus:border-purple-400"></textarea>
              <p class="text-[11px] text-slate-400 mt-1">Hệ thống sẽ biên dịch và chạy lời giải này trên từng file input, so khớp kết quả sinh ra với output tải lên.</p>
            </div>
          </div>
        </div>
      </div>

      <!-- GIAI ĐOẠN 2: BẢNG BÁO CÁO KẾT QUẢ THẨM ĐỊNH (PREVIEW TABLE) -->
      <div id="import-stage-preview" class="space-y-4 hidden">
        <!-- Thống kê tổng hợp -->
        <div class="grid grid-cols-1 sm:grid-cols-4 gap-3" id="import-stats-cards">
          <!-- Populated by JS -->
        </div>

        <!-- Cảnh báo nếu có file mồ côi -->
        <div id="import-orphans-alert" class="hidden"></div>

        <!-- Bảng danh sách test cases đã thẩm định -->
        <div class="glass-panel rounded-xl border border-white/10 overflow-hidden">
          <div class="max-h-[300px] overflow-y-auto">
            <table class="w-full text-left text-xs border-collapse">
              <thead>
                <tr class="bg-white/5 text-[11px] uppercase tracking-wider text-slate-400 font-bold border-b border-white/10">
                  <th class="py-2.5 px-3 text-center w-12">STT</th>
                  <th class="py-2.5 px-3">Cặp File Tải Lên</th>
                  <th class="py-2.5 px-3">Xem trước Dữ liệu</th>
                  <th class="py-2.5 px-3 text-center">Trạng thái thẩm định</th>
                  <th class="py-2.5 px-3 text-center">Phân loại</th>
                  <th class="py-2.5 px-3 text-center w-20">Điểm</th>
                </tr>
              </thead>
              <tbody id="import-preview-table-body" class="divide-y divide-white/5 font-mono text-xs">
                <!-- Populated by JS -->
              </tbody>
            </table>
          </div>
        </div>

        <!-- Tùy chọn ghi đè hay thêm nối tiếp -->
        <div class="p-3.5 rounded-xl bg-white/[0.02] border border-white/10 flex items-center justify-between">
          <div class="text-xs text-slate-300 font-semibold">Chế độ áp dụng vào bài tập:</div>
          <div class="flex items-center space-x-4 text-xs">
            <label class="inline-flex items-center space-x-1.5 cursor-pointer text-slate-200">
              <input type="radio" name="import_apply_mode" value="replace" checked class="accent-cyan-400">
              <span>Thay thế toàn bộ test hiện tại</span>
            </label>
            <label class="inline-flex items-center space-x-1.5 cursor-pointer text-slate-200">
              <input type="radio" name="import_apply_mode" value="append" class="accent-cyan-400">
              <span>Thêm nối tiếp vào cuối danh sách</span>
            </label>
          </div>
        </div>
      </div>
    </div>

    <!-- Footer actions -->
    <div class="flex items-center justify-between pt-3 border-t border-white/10 shrink-0">
      <div id="import-status-msg" class="text-xs"></div>
      <div class="flex items-center space-x-3">
        <button type="button" onclick="closeModal('importTestcasesModal')" class="px-5 py-2.5 rounded-xl bg-white/5 hover:bg-white/10 text-slate-300 text-sm font-semibold transition-colors">
          Hủy bỏ
        </button>
        <button type="button" id="btn-back-import-upload" onclick="resetImportUploadStage()" class="px-4 py-2.5 rounded-xl bg-white/5 hover:bg-white/10 text-slate-300 text-sm font-semibold transition-colors hidden">
          <i class="fa-solid fa-arrow-left mr-1"></i>Chọn lại tệp
        </button>
        <!-- Nút ở giai đoạn 1 -->
        <button type="button" id="btn-start-import-validate" onclick="uploadAndValidateTestcases()" class="btn-cyan px-6 py-2.5 rounded-xl text-black font-bold text-sm cursor-pointer shadow-[0_0_15px_rgba(0,255,255,0.4)] flex items-center space-x-2">
          <i class="fa-solid fa-magnifying-glass"></i>
          <span>Tải Lên & Thẩm Định</span>
        </button>
        <!-- Nút ở giai đoạn 2 -->
        <button type="button" id="btn-apply-import" onclick="applyImportedTestcases()" class="btn-cyan px-6 py-2.5 rounded-xl text-black font-bold text-sm cursor-pointer shadow-[0_0_15px_rgba(0,255,255,0.4)] hidden flex items-center space-x-2">
          <i class="fa-solid fa-check"></i>
          <span>Xác Nhận Nhập Vào Bài Tập</span>
        </button>
      </div>
    </div>
  </div>
</div>

<!-- ========================================================================= -->
<!-- MODAL 5: XÁC NHẬN XÓA BÀI TẬP (SMART DELETE: XÓA MỀM & XÓA VĨNH VIỄN)     -->
<!--   + Nếu bài đã có lượt nộp (>0): Chỉ cho phép Xóa mềm (đưa vào Thùng rác) -->
<!--     để bảo lưu mã nguồn và điểm số học viên.                              -->
<!--   + Nếu bài có 0 lượt nộp: Cho chọn Xóa mềm hoặc Xóa vĩnh viễn khỏi CSDL. -->
<!-- ========================================================================= -->
<div id="deleteProblemModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/80 backdrop-blur-sm hidden p-4 overflow-y-auto">
  <div class="glass-panel w-full max-w-lg rounded-2xl border border-white/20 p-6 my-8 space-y-5 shadow-2xl flex flex-col">
    <!-- Header -->
    <div class="flex items-center justify-between border-b border-white/10 pb-4 shrink-0">
      <div class="flex items-center space-x-3">
        <div id="del-modal-icon" class="w-10 h-10 rounded-xl bg-rose-500/20 border border-rose-500/40 text-rose-400 flex items-center justify-center text-lg shadow-sm">
          <i class="fa-solid fa-trash-can"></i>
        </div>
        <div>
          <h3 id="del-modal-title" class="text-lg font-bold text-white">Xác Nhận Xóa Bài Tập</h3>
          <p id="del-modal-subtitle" class="text-xs text-slate-400">Thao tác an toàn theo quy chuẩn Online Judge</p>
        </div>
      </div>
      <button onclick="closeModal('deleteProblemModal')" class="text-slate-400 hover:text-white text-xl">
        <i class="fa-solid fa-xmark"></i>
      </button>
    </div>

    <!-- Thông tin bài tập -->
    <div class="p-3.5 rounded-xl bg-white/5 border border-white/10 space-y-1">
      <div class="flex items-center justify-between">
        <span class="text-xs text-slate-400">Bài tập:</span>
        <span id="del-problem-id" class="text-xs font-mono text-cyan-400">#0</span>
      </div>
      <div id="del-problem-title" class="text-sm font-bold text-white truncate">Tiêu đề bài</div>
      <div id="del-problem-slug" class="text-xs font-mono text-slate-400 truncate">slug-bai-tap</div>
    </div>

    <!-- Khối Cảnh báo & Tùy chọn -->
    <div id="del-modal-body" class="space-y-3">
      <!-- Sẽ được fill động qua JS -->
    </div>

    <!-- Trạng thái báo lỗi / đang xử lý -->
    <div id="del-status-msg" class="text-xs min-h-[1.25rem]"></div>

    <!-- Footer Hành động -->
    <div class="flex items-center justify-end space-x-3 pt-4 border-t border-white/10">
      <button type="button" onclick="closeModal('deleteProblemModal')" class="px-4 py-2 rounded-xl bg-white/5 hover:bg-white/10 text-slate-300 text-xs font-semibold transition-colors cursor-pointer">
        Hủy bỏ
      </button>
      <button type="button" id="btn-confirm-delete" onclick="executeDeleteProblem()" class="px-5 py-2 rounded-xl bg-rose-500 hover:bg-rose-600 text-white font-bold text-xs flex items-center space-x-2 shadow-[0_0_15px_rgba(244,63,94,0.4)] transition-all cursor-pointer">
        <i class="fa-solid fa-trash-can"></i>
        <span id="btn-confirm-delete-text">Xác Nhận Xóa</span>
      </button>
    </div>
  </div>
</div>

<!-- ========================================================================= -->
<!-- PHẦN 3: JAVASCRIPT QUẢN LÝ MODAL, FORM TEST CASE ĐỘNG & TƯƠNG TÁC API     -->
<!-- ========================================================================= -->
<script>
// Hàm mở một hộp thoại Modal theo ID bằng cách gỡ bỏ lớp 'hidden'
// Riêng khi mở Modal Tạo bài tập (createModal) lần đầu, tự động thêm sẵn 2 dòng test case (1 mẫu, 1 kín)
function openModal(modalId) {
  document.getElementById(modalId).classList.remove('hidden');
  if (modalId === 'createModal') {
    const list = document.getElementById('create-tc-list');
    if (list && list.children.length === 0) {
      addCreateTestcaseRow({ is_sample: 1, is_hidden: 0, points: 10, input: '', output: '' });
      addCreateTestcaseRow({ is_sample: 0, is_hidden: 1, points: 10, input: '', output: '' });
    }
  }
}

// Hàm đóng một hộp thoại Modal theo ID bằng cách thêm lại lớp 'hidden'
function closeModal(modalId) {
  document.getElementById(modalId).classList.add('hidden');
}

// Lắng nghe sự kiện click ra vùng nền mờ bên ngoài khung Modal để tự động đóng Modal tương ứng
window.addEventListener('click', function(e) {
  const createModal = document.getElementById('createModal');
  const editModal = document.getElementById('editModal');
  const testcasesModal = document.getElementById('testcasesModal');
  const importModal = document.getElementById('importTestcasesModal');
  const deleteModal = document.getElementById('deleteProblemModal');
  if (e.target === createModal) closeModal('createModal');
  if (e.target === editModal) closeModal('editModal');
  if (e.target === testcasesModal) closeModal('testcasesModal');
  if (e.target === importModal) closeModal('importTestcasesModal');
  if (e.target === deleteModal) closeModal('deleteProblemModal');
});

// Hàm mở Modal Chỉnh sửa bài tập (editModal) và nạp sẵn toàn bộ dữ liệu hiện tại của bài vào các ô nhập liệu
function openEditModal(problem) {
  document.getElementById('edit_problem_id').value = problem.id;
  document.getElementById('edit_slug').value = problem.slug;
  document.getElementById('edit_title').value = problem.title;
  document.getElementById('edit_topic').value = problem.topic || 'Cơ bản';
  document.getElementById('edit_difficulty').value = problem.difficulty;
  document.getElementById('edit_time_limit_ms').value = problem.time_limit_ms || 1000;
  // Quy đổi giới hạn bộ nhớ từ KB trong CSDL sang MB để người dùng dễ nhập
  document.getElementById('edit_memory_limit_mb').value = Math.round((problem.memory_limit_kb || 262144) / 1024);
  document.getElementById('edit_visibility').value = problem.visibility || (problem.is_public ? 'public' : 'hidden');
  document.getElementById('edit_description').value = problem.description || '';
  document.getElementById('edit_input_description').value = problem.input_description || '';
  document.getElementById('edit_output_description').value = problem.output_description || '';
  document.getElementById('edit_constraints').value = problem.constraints || '';
  // Đặt lại ô tích "Phát hành thành phiên bản đề mới" về trạng thái chưa chọn và ẩn ô nhập changelog
  const chkVer = document.getElementById('edit_create_new_version');
  if (chkVer) chkVer.checked = false;
  const wrapVer = document.getElementById('edit-changelog-wrap');
  if (wrapVer) wrapVer.classList.add('hidden');
  const chgInput = document.getElementById('edit_changelog');
  if (chgInput) chgInput.value = '';
  openModal('editModal');
}

// -------------------------------------------------------------
// NHÓM HÀM 1: QUẢN LÝ TEST CASES ĐỘNG TRONG MODAL TẠO BÀI (createModal)
// -------------------------------------------------------------
// Biến đếm tăng dần giúp tên input dạng test_cases[0], test_cases[1]... không bao giờ bị trùng kể cả sau khi xóa dòng giữa
let createTcCounter = 0;

// Hàm thêm 1 thẻ nhập Test Case mới vào form tạo bài tập (hỗ trợ điền sẵn dữ liệu khi nhập từ file ZIP/JSON)
function addCreateTestcaseRow(data = null) {
  const container = document.getElementById('create-tc-list');
  if (!container) return;
  const count = container.querySelectorAll('.create-tc-card').length;
  const idx = createTcCounter++;

  // Mặc định test case đầu tiên là Công khai (Sample), các test case tiếp theo là Chấm kín (Hidden)
  const isSampleChecked = data ? (data.is_sample == 1 ? 'checked' : '') : (count === 0 ? 'checked' : '');
  const isHiddenChecked = isSampleChecked ? '' : 'checked';
  const points = data && data.points !== undefined ? data.points : 10;
  const inputVal = data ? escapeHtml(data.input || '') : '';
  const outputVal = data ? escapeHtml(data.output || '') : '';

  const card = document.createElement('div');
  card.className = 'create-tc-card p-3.5 rounded-xl bg-black/40 border border-white/10 space-y-2.5 hover:border-white/20 transition-all';
  card.innerHTML = `
    <div class="flex flex-col sm:flex-row sm:items-center justify-between border-b border-white/5 pb-2 gap-2">
      <div class="flex items-center space-x-3 flex-wrap gap-y-1">
        <span class="font-mono font-bold text-xs text-amber-400 create-tc-number">Test Case #${count + 1}</span>
        <label class="flex items-center space-x-1.5 text-xs text-slate-300 cursor-pointer select-none">
          <input type="radio" name="test_cases[${idx}][type]" value="sample" class="accent-cyan-400 focus:ring-0 cursor-pointer" ${isSampleChecked}>
          <span class="text-cyan-300 font-medium">Công khai (Sample)</span>
        </label>
        <label class="flex items-center space-x-1.5 text-xs text-slate-300 cursor-pointer select-none">
          <input type="radio" name="test_cases[${idx}][type]" value="hidden" class="accent-purple-400 focus:ring-0 cursor-pointer" ${isHiddenChecked}>
          <span class="text-purple-300 font-medium">Chấm kín (Hidden)</span>
        </label>
      </div>
      <div class="flex items-center space-x-2.5 self-end sm:self-auto">
        <div class="flex items-center space-x-1.5 text-xs">
          <span class="text-slate-400">Điểm:</span>
          <input type="number" min="1" max="100" name="test_cases[${idx}][points]" value="${points}" class="w-14 px-2 py-0.5 rounded bg-black/60 border border-white/15 text-white font-mono text-xs text-center focus:outline-none focus:border-cyan-400">
        </div>
        <button type="button" onclick="removeCreateTestcaseRow(this)" class="p-1.5 rounded-lg bg-rose-500/10 hover:bg-rose-500/20 text-rose-400 text-xs transition-colors cursor-pointer" title="Xóa test case">
          <i class="fa-solid fa-trash-can"></i>
        </button>
      </div>
    </div>
    <div class="grid grid-cols-1 md:grid-cols-2 gap-2.5">
      <div>
        <label class="block text-[10px] font-mono text-slate-400 mb-1">Dữ liệu vào (Input)</label>
        <textarea name="test_cases[${idx}][input_data]" rows="2" placeholder="Ví dụ: 2 3" class="w-full p-2 rounded-lg bg-black/50 border border-white/10 text-white font-mono text-xs focus:outline-none focus:border-cyan-400">${inputVal}</textarea>
      </div>
      <div>
        <label class="block text-[10px] font-mono text-slate-400 mb-1">Dữ liệu ra mong đợi (Expected Output)</label>
        <textarea name="test_cases[${idx}][expected_output]" rows="2" placeholder="Ví dụ: 5" class="w-full p-2 rounded-lg bg-black/50 border border-white/10 text-white font-mono text-xs focus:outline-none focus:border-cyan-400">${outputVal}</textarea>
      </div>
    </div>
  `;
  container.appendChild(card);
  updateCreateTestcaseNumbers();
}

// Hàm xóa 1 thẻ test case trong Modal Tạo bài (luôn giữ lại tối thiểu 1 test case)
function removeCreateTestcaseRow(btn) {
  const container = document.getElementById('create-tc-list');
  if (container.querySelectorAll('.create-tc-card').length <= 1) {
    alert('Cần có ít nhất 1 test case.');
    return;
  }
  btn.closest('.create-tc-card').remove();
  updateCreateTestcaseNumbers();
}

// Hàm đánh lại số thứ tự hiển thị (Test Case #1, #2, #3...) sau mỗi lần thêm hoặc xóa dòng
function updateCreateTestcaseNumbers() {
  const container = document.getElementById('create-tc-list');
  if (!container) return;
  const cards = container.querySelectorAll('.create-tc-card');
  cards.forEach((card, i) => {
    const lbl = card.querySelector('.create-tc-number');
    if (lbl) lbl.textContent = `Test Case #${i + 1}`;
  });
}

// Khi trang vừa tải xong DOM -> tạo sẵn 2 dòng test case mẫu trong Modal Tạo bài để sẵn sàng sử dụng
document.addEventListener('DOMContentLoaded', function() {
  const container = document.getElementById('create-tc-list');
  if (container && container.children.length === 0) {
    addCreateTestcaseRow({ is_sample: 1, is_hidden: 0, points: 10, input: '', output: '' });
    addCreateTestcaseRow({ is_sample: 0, is_hidden: 1, points: 10, input: '', output: '' });
  }
});

// -------------------------------------------------------------
// NHÓM HÀM 2: QUẢN LÝ & SỬA BỘ TEST CASES QUA API (testcasesModal)
// -------------------------------------------------------------
// Biến trạng thái lưu bài tập đang được mở trong Modal Sửa Test Cases
let currentTcProblemId = 0;
let currentTcSubmissionsCount = 0;
let currentTcVersionNumber = 1;

// Hàm mã hóa ký tự HTML đặc biệt (&, <, >, ", ') nhằm ngăn lỗi hiển thị và phòng chống tấn công XSS khi ghép chuỗi innerHTML
function escapeHtml(text) {
  if (text === null || text === undefined) return '';
  return String(text)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#039;');
}

// Hàm mở Modal Quản lý Test Cases:
// 1. Hiển thị cảnh báo chế độ hoạt động (0 bài nộp -> sửa trực tiếp; >0 bài nộp -> tạo v(N+1) và tự động Rejudge)
// 2. Gọi API GET /BaiTapLon/api/version_rejudge.php?action=get_testcases để tải danh sách test case hiện có
async function openTestcasesModal(problemId, problemTitle, totalSubmissions, currentVersionNumber) {
  currentTcProblemId = problemId;
  currentTcSubmissionsCount = parseInt(totalSubmissions) || 0;
  currentTcVersionNumber = parseInt(currentVersionNumber) || 1;

  document.getElementById('tc-modal-title').textContent = problemTitle;
  document.getElementById('tc-modal-version-badge').textContent = 'v' + currentTcVersionNumber;
  document.getElementById('tc-modal-sub-badge').textContent = currentTcSubmissionsCount + ' bài nộp';
  document.getElementById('tc-save-status').innerHTML = '';

  const modeBanner = document.getElementById('tc-mode-banner');
  const changelogContainer = document.getElementById('tc-changelog-container');

  // Quyết định banner giải thích chế độ lưu theo số lượt nộp hiện có
  if (currentTcSubmissionsCount === 0) {
    modeBanner.innerHTML = `
      <div class="p-4 rounded-xl bg-emerald-500/10 border border-emerald-500/30 text-emerald-300 text-xs flex items-start space-x-3">
        <i class="fa-solid fa-circle-check text-base mt-0.5 text-emerald-400 shrink-0"></i>
        <div class="space-y-1">
          <div class="font-bold text-emerald-300">Chế độ Sửa Trực Tiếp (In-place Update)</div>
          <p class="text-slate-300">Bài tập này <strong>chưa có bài nộp nào</strong> từ học sinh. Bạn có thể tự do thêm, sửa dữ liệu vào/ra hoặc xóa test case. Thay đổi sẽ được cập nhật trực tiếp vào phiên bản v${currentTcVersionNumber} hiện tại mà không tạo phiên bản mới.</p>
        </div>
      </div>
    `;
    changelogContainer.classList.add('hidden');
  } else {
    modeBanner.innerHTML = `
      <div class="p-4 rounded-xl bg-amber-500/10 border border-amber-500/30 text-amber-300 text-xs flex items-start space-x-3">
        <i class="fa-solid fa-triangle-exclamation text-base mt-0.5 text-amber-400 shrink-0"></i>
        <div class="space-y-1">
          <div class="font-bold text-amber-300">Chế độ Bảo Toàn Lịch Sử & Tự Động Chấm Lại (Versioned Rejudge)</div>
          <p class="text-slate-300">Bài tập đã có <strong>${currentTcSubmissionsCount} bài nộp</strong> từ học sinh. Nhằm bảo vệ tính toàn vẹn của các lần nộp cũ và đối chiếu công bằng, hệ thống sẽ tự động:</p>
          <ul class="list-disc list-inside space-y-0.5 text-slate-300 ml-1 mt-1">
            <li>Tạo phiên bản mới <strong>v${currentTcVersionNumber + 1}</strong> chứa bộ test case đã sửa</li>
            <li>Đặt <strong>v${currentTcVersionNumber + 1}</strong> làm phiên bản có hiệu lực tiếp theo</li>
            <li>Tự động đưa <strong>toàn bộ ${currentTcSubmissionsCount} bài nộp cũ vào hàng đợi chấm lại</strong> (Rejudge) trên phiên bản mới</li>
          </ul>
        </div>
      </div>
    `;
    changelogContainer.classList.remove('hidden');
    document.getElementById('tc-changelog-input').value = `Sửa bộ test case cho phiên bản v${currentTcVersionNumber + 1}`;
  }

  // Hiện biểu tượng đang tải trong lúc chờ API trả về danh sách test case
  const listContainer = document.getElementById('tc-list-container');
  listContainer.innerHTML = `
    <div class="py-12 text-center text-slate-400">
      <i class="fa-solid fa-spinner fa-spin text-2xl mb-2 text-cyan-400"></i>
      <p class="text-sm">Đang tải danh sách test cases...</p>
    </div>
  `;

  openModal('testcasesModal');

  try {
    const res = await fetch(`/BaiTapLon/api/version_rejudge.php?action=get_testcases&problem_id=${problemId}`);
    const data = await res.json();

    if (!res.ok || !data.success) {
      listContainer.innerHTML = `
        <div class="p-4 rounded-xl bg-rose-500/10 border border-rose-500/30 text-rose-400 text-sm">
          <i class="fa-solid fa-circle-xmark mr-2"></i>${escapeHtml(data.error || 'Không thể tải test cases.')}
        </div>
      `;
      return;
    }

    // Vẽ lại danh sách các thẻ test case từ dữ liệu JSON trả về
    listContainer.innerHTML = '';
    const testCases = data.test_cases || [];

    if (testCases.length === 0) {
      addTestcaseRow();
    } else {
      testCases.forEach((tc, idx) => {
        listContainer.appendChild(createTestcaseCardElement(tc, idx));
      });
    }

    updateTestcaseLabels();

  } catch (err) {
    listContainer.innerHTML = `
      <div class="p-4 rounded-xl bg-rose-500/10 border border-rose-500/30 text-rose-400 text-sm">
        <i class="fa-solid fa-circle-xmark mr-2"></i>Lỗi kết nối khi tải test cases: ${escapeHtml(err.message)}
      </div>
    `;
  }
}

// Bộ đếm tên nhóm radio button riêng biệt cho từng thẻ test case trong Modal Sửa Test Cases
let tcModalCardCounter = 0;

// Hàm dựng phần tử DOM (thẻ <div>) cho một test case gồm: radio Sample/Hidden, ô điểm, nút xóa và 2 ô Input/Expected Output
function createTestcaseCardElement(tc, idx) {
  const card = document.createElement('div');
  card.className = 'testcase-card p-4 rounded-xl bg-white/[0.03] border border-white/10 space-y-3 hover:border-white/20 transition-all';
  
  const isSampleChecked = (tc.is_sample == 1) ? 'checked' : '';
  const isHiddenChecked = isSampleChecked ? '' : 'checked';
  const points = tc.points !== undefined ? tc.points : 10;
  const groupName = 'tc_modal_vis_' + (tcModalCardCounter++);

  card.innerHTML = `
    <div class="flex flex-col sm:flex-row sm:items-center justify-between border-b border-white/5 pb-2.5 gap-2">
      <div class="flex items-center space-x-3 flex-wrap gap-y-1">
        <span class="font-mono font-bold text-xs text-amber-400 tc-number-label">Test Case #${idx + 1}</span>
        <label class="flex items-center space-x-1.5 text-xs text-slate-300 cursor-pointer select-none">
          <input type="radio" name="${groupName}" value="sample" class="tc-sample-radio accent-cyan-400 focus:ring-0 cursor-pointer" ${isSampleChecked}>
          <span class="text-cyan-300 font-medium">Công khai (Sample)</span>
        </label>
        <label class="flex items-center space-x-1.5 text-xs text-slate-300 cursor-pointer select-none">
          <input type="radio" name="${groupName}" value="hidden" class="tc-hidden-radio accent-purple-400 focus:ring-0 cursor-pointer" ${isHiddenChecked}>
          <span class="text-purple-300 font-medium">Chấm kín (Hidden)</span>
        </label>
      </div>
      <div class="flex items-center space-x-3 self-end sm:self-auto">
        <div class="flex items-center space-x-1.5 text-xs">
          <span class="text-slate-400">Điểm:</span>
          <input type="number" min="1" max="100" class="tc-points-input w-16 px-2 py-1 rounded bg-black/50 border border-white/15 text-white font-mono text-xs text-center focus:outline-none focus:border-cyan-400" value="${points}">
        </div>
        <button type="button" onclick="removeTestcaseRow(this)" class="p-1.5 rounded-lg bg-rose-500/10 hover:bg-rose-500/20 text-rose-400 text-xs transition-colors cursor-pointer" title="Xóa test case này">
          <i class="fa-solid fa-trash-can"></i>
        </button>
      </div>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
      <div>
        <label class="block text-[11px] font-semibold text-slate-400 mb-1 font-mono">Dữ liệu vào (Input)</label>
        <textarea rows="3" class="tc-input-data w-full p-2.5 rounded-lg bg-black/50 border border-white/15 text-white font-mono text-xs focus:outline-none focus:border-cyan-400" placeholder="Nhập dữ liệu vào...">${escapeHtml(tc.input_data || '')}</textarea>
      </div>
      <div>
        <label class="block text-[11px] font-semibold text-slate-400 mb-1 font-mono">Dữ liệu ra mong đợi (Expected Output)</label>
        <textarea rows="3" class="tc-expected-output w-full p-2.5 rounded-lg bg-black/50 border border-white/15 text-white font-mono text-xs focus:outline-none focus:border-cyan-400" placeholder="Nhập kết quả mong đợi...">${escapeHtml(tc.expected_output || '')}</textarea>
      </div>
    </div>
  `;

  return card;
}

// Hàm thêm 1 dòng test case trống mới vào cuối danh sách trong testcasesModal và tự cuộn xuống cuối
function addTestcaseRow() {
  const container = document.getElementById('tc-list-container');
  const count = container.querySelectorAll('.testcase-card').length;
  
  const newTc = {
    input_data: '',
    expected_output: '',
    is_sample: count === 0 ? 1 : 0,
    is_hidden: count === 0 ? 0 : 1,
    points: 10
  };

  container.appendChild(createTestcaseCardElement(newTc, count));
  updateTestcaseLabels();

  // Scroll xuống dưới cùng
  container.scrollTop = container.scrollHeight;
}

// Hàm xóa 1 thẻ test case trong testcasesModal (không cho phép xóa nếu chỉ còn 1 test case duy nhất)
function removeTestcaseRow(button) {
  const container = document.getElementById('tc-list-container');
  const cards = container.querySelectorAll('.testcase-card');
  if (cards.length <= 1) {
    alert('Bài tập cần có ít nhất 1 test case. Không thể xóa hết!');
    return;
  }
  button.closest('.testcase-card').remove();
  updateTestcaseLabels();
}

// Hàm đánh lại số thứ tự (#1, #2, ...) và cập nhật nhãn tổng số test case ở cuối Modal
function updateTestcaseLabels() {
  const container = document.getElementById('tc-list-container');
  const cards = container.querySelectorAll('.testcase-card');
  cards.forEach((card, idx) => {
    const label = card.querySelector('.tc-number-label');
    if (label) label.textContent = `Test Case #${idx + 1}`;
  });
  document.getElementById('tc-total-count-label').textContent = `Tổng cộng: ${cards.length} test case`;
}

// Hàm gom toàn bộ các thẻ test case hiện có trên giao diện và gửi POST JSON lên API /BaiTapLon/api/version_rejudge.php
// Nếu bài tập đã có lượt nộp, API sẽ tạo phiên bản mới và hỏi người dùng có muốn mở ngay trang đối chiếu Rejudge không
async function saveTestcases() {
  const container = document.getElementById('tc-list-container');
  const cards = container.querySelectorAll('.testcase-card');
  if (cards.length === 0) {
    alert('Cần có ít nhất 1 test case.');
    return;
  }

  // Duyệt từng thẻ test case trên DOM để đóng gói thành mảng đối tượng JSON
  const testCasesList = [];
  for (let i = 0; i < cards.length; i++) {
    const card = cards[i];
    const inputData = card.querySelector('.tc-input-data').value;
    const expectedOutput = card.querySelector('.tc-expected-output').value;
    const isSample = card.querySelector('.tc-sample-radio').checked ? 1 : 0;
    const isHidden = isSample ? 0 : 1;
    const points = parseInt(card.querySelector('.tc-points-input').value) || 10;

    testCasesList.push({
      input_data: inputData,
      expected_output: expectedOutput,
      is_sample: isSample,
      is_hidden: isHidden,
      points: points
    });
  }

  const changelog = document.getElementById('tc-changelog-input').value.trim();
  const saveBtn = document.getElementById('btn-save-testcases');
  const statusEl = document.getElementById('tc-save-status');

  // Khóa nút Lưu trong lúc đang gửi yêu cầu
  saveBtn.disabled = true;
  saveBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin mr-1"></i>Đang lưu test cases...';
  statusEl.innerHTML = '';

  try {
    const res = await fetch('/BaiTapLon/api/version_rejudge.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        action: 'save_testcases',
        problem_id: currentTcProblemId,
        changelog: changelog,
        test_cases: testCasesList
      })
    });

    const data = await res.json();
    saveBtn.disabled = false;
    saveBtn.innerHTML = '<i class="fa-solid fa-floppy-disk mr-1"></i><span>Lưu Bộ Test Cases</span>';

    if (!res.ok || !data.success) {
      statusEl.innerHTML = `<span class="text-rose-400 font-semibold"><i class="fa-solid fa-triangle-exclamation mr-1"></i>${escapeHtml(data.error || 'Lỗi khi lưu test case.')}</span>`;
      return;
    }

    // Lưu thành công: hiển thị thông báo và điều hướng nếu có kích hoạt Rejudge hàng loạt
    statusEl.innerHTML = `<span class="text-emerald-400 font-semibold"><i class="fa-solid fa-circle-check mr-1"></i>${escapeHtml(data.message)}</span>`;

    if (data.mode === 'new_version_rejudge' && data.rejudged_count > 0) {
      setTimeout(() => {
        if (confirm(`Đã phát hành phiên bản mới v${data.version_number} và xếp hàng chấm lại ${data.rejudged_count} bài nộp!\n\nBạn có muốn mở ngay trang Bảng Đối Chiếu (Side-by-Side Comparison) để theo dõi kết quả chấm lại không?`)) {
          window.location.href = `/BaiTapLon/rejudge_compare.php?problem_id=${currentTcProblemId}`;
        } else {
          location.reload();
        }
      }, 800);
    } else {
      setTimeout(() => {
        location.reload();
      }, 1000);
    }

  } catch (err) {
    saveBtn.disabled = false;
    saveBtn.innerHTML = '<i class="fa-solid fa-floppy-disk mr-1"></i><span>Lưu Bộ Test Cases</span>';
    statusEl.innerHTML = `<span class="text-rose-400 font-semibold"><i class="fa-solid fa-triangle-exclamation mr-1"></i>Lỗi kết nối: ${escapeHtml(err.message)}</span>`;
  }
}

// -------------------------------------------------------------
// NHÓM HÀM 3: NHẬP TEST CASES TỪ TỆP ZIP/JSON & THẨM ĐỊNH ĐỐI SOÁT (importTestcasesModal)
// -------------------------------------------------------------
// currentImportTarget: xác định nơi đổ dữ liệu sau khi nhập xong ('create' = Modal tạo bài, 'edit' = Modal sửa test)
// validatedImportCases: mảng lưu danh sách test case hợp lệ nhận về từ API import_testcases.php
let currentImportTarget = 'create';
let validatedImportCases = [];

// Hàm mở Modal Nhập file và đưa giao diện về Giai đoạn 1 (chọn tệp)
function openImportModal(target) {
  currentImportTarget = target;
  resetImportUploadStage();
  openModal('importTestcasesModal');
}

// Hàm đặt lại Modal Nhập file về trạng thái ban đầu (ẩn bảng xem trước, xóa file cũ và lời giải cũ)
function resetImportUploadStage() {
  document.getElementById('import-stage-upload').classList.remove('hidden');
  document.getElementById('import-stage-preview').classList.add('hidden');
  document.getElementById('btn-start-import-validate').classList.remove('hidden');
  document.getElementById('btn-apply-import').classList.add('hidden');
  document.getElementById('btn-back-import-upload').classList.add('hidden');
  document.getElementById('import-file-input').value = '';
  document.getElementById('import-file-name-label').textContent = 'Nhấp hoặc kéo thả tệp .ZIP hoặc .JSON vào đây';
  document.getElementById('import-status-msg').innerHTML = '';
  document.getElementById('enable-model-verify').checked = false;
  document.getElementById('model-verify-box').classList.add('hidden');
  document.getElementById('model-solution-code').value = '';
  validatedImportCases = [];
}

// Hàm hiển thị tên file và dung lượng (KB) ngay khi người dùng chọn tệp .ZIP/.JSON từ máy tính
function handleImportFileSelected(input) {
  if (input.files && input.files[0]) {
    const f = input.files[0];
    document.getElementById('import-file-name-label').innerHTML = `
      <span class="text-purple-300 font-bold flex items-center justify-center gap-2">
        <i class="fa-solid fa-file-zipper text-lg"></i>
        <span>${escapeHtml(f.name)}</span>
        <span class="text-xs text-slate-400 font-normal">(${Math.round(f.size / 1024)} KB)</span>
      </span>
    `;
  }
}

// Hàm bật/tắt khung dán mã nguồn Lời giải chuẩn (Thẩm định Cấp độ 3)
function toggleModelVerifyBox(checked) {
  const box = document.getElementById('model-verify-box');
  if (checked) {
    box.classList.remove('hidden');
  } else {
    box.classList.add('hidden');
  }
}

// Hàm tải file ZIP/JSON (dùng FormData) kèm mã nguồn lời giải chuẩn (nếu có) lên API /BaiTapLon/api/import_testcases.php
// Sau khi máy chủ giải nén, ghép cặp .in/.out và chạy đối chiếu -> hiển thị báo cáo thẩm định ở Giai đoạn 2
async function uploadAndValidateTestcases() {
  const fileInput = document.getElementById('import-file-input');
  if (!fileInput.files || !fileInput.files[0]) {
    alert('Vui lòng chọn tệp ZIP hoặc JSON chứa bộ test case.');
    return;
  }

  // Dùng FormData để gửi được cả file nhị phân (.zip/.json) và các trường văn bản lên PHP ($_FILES & $_POST)
  const formData = new FormData();
  formData.append('testcase_file', fileInput.files[0]);

  const enableVerify = document.getElementById('enable-model-verify').checked;
  if (enableVerify) {
    const code = document.getElementById('model-solution-code').value.trim();
    const lang = document.querySelector('input[name="model_lang"]:checked')?.value || 'python';
    if (code !== '') {
      formData.append('model_solution', code);
      formData.append('solution_lang', lang);
    }
  }

  const btn = document.getElementById('btn-start-import-validate');
  const statusMsg = document.getElementById('import-status-msg');
  btn.disabled = true;
  btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin mr-1"></i>Đang giải nén & thẩm định...';
  statusMsg.innerHTML = '';

  try {
    const res = await fetch('/BaiTapLon/api/import_testcases.php', {
      method: 'POST',
      body: formData
    });
    const data = await res.json();
    btn.disabled = false;
    btn.innerHTML = '<i class="fa-solid fa-magnifying-glass mr-1"></i><span>Tải Lên & Thẩm Định</span>';

    if (!res.ok || !data.success) {
      statusMsg.innerHTML = `<span class="text-rose-400 font-semibold"><i class="fa-solid fa-triangle-exclamation mr-1"></i>${escapeHtml(data.error || 'Lỗi thẩm định tệp.')}</span>`;
      return;
    }

    validatedImportCases = data.test_cases || [];

    // Vẽ 4 thẻ thống kê kết quả thẩm định: Tổng tìm thấy, Hợp lệ, File mồ côi (thiếu cặp) và Kết quả đối soát lời giải
    const statsContainer = document.getElementById('import-stats-cards');
    const modelBadge = data.model_solution_ran
      ? (validatedImportCases.some(t => t.status === 'MISMATCH')
          ? '<span class="text-amber-400 font-bold">⚠️ Có test lệch đáp án</span>'
          : '<span class="text-emerald-400 font-bold">✅ 100% Khớp lời giải</span>')
      : '<span class="text-slate-400">Không kích hoạt</span>';

    statsContainer.innerHTML = `
      <div class="p-3 rounded-xl bg-white/[0.03] border border-white/10">
        <div class="text-[11px] text-slate-400 uppercase font-semibold">Tổng tìm thấy</div>
        <div class="text-lg font-black text-white font-mono mt-0.5">${data.total_found}</div>
      </div>
      <div class="p-3 rounded-xl bg-emerald-500/10 border border-emerald-500/30">
        <div class="text-[11px] text-emerald-400 uppercase font-semibold">Hợp lệ (Đủ cặp)</div>
        <div class="text-lg font-black text-emerald-300 font-mono mt-0.5">${data.valid_count}</div>
      </div>
      <div class="p-3 rounded-xl ${data.orphan_count > 0 ? 'bg-rose-500/10 border border-rose-500/30' : 'bg-white/[0.03] border border-white/10'}">
        <div class="text-[11px] ${data.orphan_count > 0 ? 'text-rose-400 font-bold' : 'text-slate-400'} uppercase font-semibold">File mồ côi (Thiếu cặp)</div>
        <div class="text-lg font-black ${data.orphan_count > 0 ? 'text-rose-300' : 'text-slate-400'} font-mono mt-0.5">${data.orphan_count}</div>
      </div>
      <div class="p-3 rounded-xl bg-purple-500/10 border border-purple-500/30">
        <div class="text-[11px] text-purple-300 uppercase font-semibold">Đối soát Lời giải</div>
        <div class="text-xs font-semibold mt-1">${modelBadge}</div>
      </div>
    `;

    // Hiển thị hộp cảnh báo đỏ liệt kê các file không đủ cặp .in/.out (nếu có)
    const orphanBox = document.getElementById('import-orphans-alert');
    if (data.orphan_count > 0) {
      orphanBox.classList.remove('hidden');
      orphanBox.innerHTML = `
        <div class="p-3.5 rounded-xl bg-rose-500/10 border border-rose-500/30 text-rose-300 text-xs space-y-1">
          <div class="font-bold flex items-center gap-1.5 text-rose-400">
            <i class="fa-solid fa-triangle-exclamation"></i>
            <span>Phát hiện ${data.orphan_count} file không đủ cặp .in/.out (sẽ tự động bỏ qua):</span>
          </div>
          <ul class="list-disc list-inside space-y-0.5 text-slate-300 ml-1">
            ${data.orphans.map(o => `<li><code class="text-rose-300">${escapeHtml(o.file)}</code>: ${escapeHtml(o.message)}</li>`).join('')}
          </ul>
        </div>
      `;
    } else {
      orphanBox.classList.add('hidden');
    }

    // Vẽ từng dòng của bảng xem trước để Giảng viên rà soát, chọn lại Mẫu/Kín và điểm số trước khi áp dụng
    const tbody = document.getElementById('import-preview-table-body');
    tbody.innerHTML = '';

    validatedImportCases.forEach((tc, idx) => {
      const isSampleChecked = (tc.is_sample == 1) ? 'checked' : '';
      const isHiddenChecked = isSampleChecked ? '' : 'checked';
      const statusBadge = tc.status === 'VERIFIED'
        ? '<span class="px-2 py-0.5 rounded bg-emerald-500/20 text-emerald-400 border border-emerald-500/30 text-[11px] font-sans font-bold">✅ Khớp lời giải</span>'
        : (tc.status === 'MISMATCH'
            ? '<span class="px-2 py-0.5 rounded bg-amber-500/20 text-amber-400 border border-amber-500/30 text-[11px] font-sans font-bold" title="Lệch kết quả với Lời giải chuẩn">⚠️ Lệch đáp án</span>'
            : '<span class="px-2 py-0.5 rounded bg-cyan-500/20 text-cyan-400 border border-cyan-500/30 text-[11px] font-sans font-bold">Hợp lệ</span>');

      const tr = document.createElement('tr');
      tr.className = 'hover:bg-white/[0.02] transition-colors import-preview-row';
      tr.innerHTML = `
        <td class="py-2.5 px-3 text-center text-slate-400 font-bold">#${idx + 1}</td>
        <td class="py-2.5 px-3">
          <div class="text-white font-bold">${escapeHtml(tc.input_name)}</div>
          <div class="text-slate-400 text-[11px]">${escapeHtml(tc.output_name)}</div>
        </td>
        <td class="py-2.5 px-3 max-w-[220px]">
          <div class="truncate text-slate-300 text-[11px]" title="${escapeHtml(tc.input_preview)}">
            <span class="text-slate-500">In:</span> ${escapeHtml(tc.input_preview || '(Trống)')}
          </div>
          <div class="truncate text-cyan-300 text-[11px]" title="${escapeHtml(tc.output_preview)}">
            <span class="text-slate-500">Out:</span> ${escapeHtml(tc.output_preview || '(Trống)')}
          </div>
        </td>
        <td class="py-2.5 px-3 text-center">${statusBadge}</td>
        <td class="py-2.5 px-3 text-center">
          <div class="inline-flex items-center space-x-3 text-[11px]">
            <label class="inline-flex items-center space-x-1 cursor-pointer">
              <input type="radio" name="preview_vis_${idx}" value="sample" class="accent-cyan-400 import-preview-vis-sample" ${isSampleChecked}>
              <span class="text-cyan-400">Mẫu</span>
            </label>
            <label class="inline-flex items-center space-x-1 cursor-pointer">
              <input type="radio" name="preview_vis_${idx}" value="hidden" class="accent-purple-400 import-preview-vis-hidden" ${isHiddenChecked}>
              <span class="text-purple-400">Kín</span>
            </label>
          </div>
        </td>
        <td class="py-2.5 px-3 text-center">
          <input type="number" min="1" max="100" class="import-preview-points w-14 px-2 py-0.5 rounded bg-black/60 border border-white/15 text-white font-mono text-xs text-center focus:outline-none focus:border-cyan-400" value="${tc.points || 10}">
        </td>
      `;
      tbody.appendChild(tr);
    });

    // Chuyển giao diện Modal từ Giai đoạn 1 (Chọn file) sang Giai đoạn 2 (Xem trước & Xác nhận nhập)
    document.getElementById('import-stage-upload').classList.add('hidden');
    document.getElementById('import-stage-preview').classList.remove('hidden');
    document.getElementById('btn-start-import-validate').classList.add('hidden');
    document.getElementById('btn-apply-import').classList.remove('hidden');
    document.getElementById('btn-back-import-upload').classList.remove('hidden');

  } catch (err) {
    btn.disabled = false;
    btn.innerHTML = '<i class="fa-solid fa-magnifying-glass mr-1"></i><span>Tải Lên & Thẩm Định</span>';
    statusMsg.innerHTML = `<span class="text-rose-400 font-semibold"><i class="fa-solid fa-triangle-exclamation mr-1"></i>Lỗi kết nối: ${escapeHtml(err.message)}</span>`;
  }
}

// Hàm đổ danh sách test case đã thẩm định vào đúng Modal đích ('create' hoặc 'edit') theo chế độ Thay thế (replace) hay Nối tiếp (append)
function applyImportedTestcases() {
  if (!validatedImportCases || validatedImportCases.length === 0) {
    alert('Không có test case nào để áp dụng.');
    return;
  }

  // Đọc lại các lựa chọn phân loại (Mẫu/Kín) và điểm số mà Giảng viên vừa điều chỉnh trong bảng xem trước
  const rows = document.querySelectorAll('.import-preview-row');
  rows.forEach((row, i) => {
    if (validatedImportCases[i]) {
      const isSample = row.querySelector('.import-preview-vis-sample').checked ? 1 : 0;
      const points = parseInt(row.querySelector('.import-preview-points').value) || 10;
      validatedImportCases[i].is_sample = isSample;
      validatedImportCases[i].is_hidden = isSample ? 0 : 1;
      validatedImportCases[i].points = points;
    }
  });

  const applyMode = document.querySelector('input[name="import_apply_mode"]:checked')?.value || 'replace';

  // Đổ dữ liệu sang Modal Tạo bài mới (createModal) hoặc Modal Quản lý Test Cases (testcasesModal)
  if (currentImportTarget === 'create') {
    const container = document.getElementById('create-tc-list');
    if (applyMode === 'replace') {
      container.innerHTML = '';
      createTcCounter = 0;
    }
    validatedImportCases.forEach(tc => {
      addCreateTestcaseRow({
        is_sample: tc.is_sample,
        is_hidden: tc.is_hidden,
        points: tc.points,
        input: tc.input_data,
        output: tc.expected_output
      });
    });
    closeModal('importTestcasesModal');
  } else if (currentImportTarget === 'edit') {
    const container = document.getElementById('tc-list-container');
    if (applyMode === 'replace') {
      container.innerHTML = '';
    }
    validatedImportCases.forEach((tc, idx) => {
      container.appendChild(createTestcaseCardElement(tc, idx));
    });
    updateTestcaseLabels();
    closeModal('importTestcasesModal');
    const statusEl = document.getElementById('tc-save-status');
    if (statusEl) {
      statusEl.innerHTML = `<span class="text-amber-400 font-semibold"><i class="fa-solid fa-circle-info mr-1"></i>Đã nhập ${validatedImportCases.length} test case. Hãy bấm "Lưu Bộ Test Cases" để hoàn tất!</span>`;
    }
  }
}

// =========================================================================
// NHÓM HÀM 4: QUẢN LÝ XÓA & KHÔI PHỤC BÀI TẬP (SOFT DELETE / HARD DELETE / RESTORE)
// =========================================================================
// Đối tượng lưu thông tin bài tập đang chuẩn bị xóa để dùng khi bấm nút Xác nhận
let currentDeleteTarget = {
  problemId: 0,
  title: '',
  slug: '',
  totalSubmissions: 0,
  isHardDeleteOnly: false
};

// Hàm mở Modal Xác nhận Xóa và tự động tùy biến nội dung cảnh báo theo 3 tình huống:
// 1. isHardDeleteOnly = true (bấm từ Thùng rác, 0 lượt nộp) -> Xóa vĩnh viễn
// 2. totalSubmissions > 0 -> Bắt buộc Xóa mềm (đưa vào Thùng rác, bảo lưu dữ liệu bài nộp học viên)
// 3. totalSubmissions === 0 (từ tab Hoạt động) -> Cho chọn giữa Xóa mềm và Xóa vĩnh viễn
function openDeleteModal(problemId, title, slug, totalSubmissions, isHardDeleteOnly = false) {
  currentDeleteTarget = { problemId, title, slug, totalSubmissions, isHardDeleteOnly };

  document.getElementById('del-problem-id').textContent = '#' + problemId;
  document.getElementById('del-problem-title').textContent = title;
  document.getElementById('del-problem-slug').textContent = slug;
  document.getElementById('del-status-msg').innerHTML = '';

  const btn = document.getElementById('btn-confirm-delete');
  btn.disabled = false;

  const bodyEl = document.getElementById('del-modal-body');
  const modalTitle = document.getElementById('del-modal-title');
  const modalSubtitle = document.getElementById('del-modal-subtitle');
  const modalIcon = document.getElementById('del-modal-icon');

  if (isHardDeleteOnly) {
    modalTitle.textContent = 'Xóa Vĩnh Viễn Khỏi Hệ Thống';
    modalSubtitle.textContent = 'Bài tập chưa có lượt nộp nào, có thể dọn sạch hoàn toàn';
    modalIcon.innerHTML = '<i class="fa-solid fa-fire text-rose-500"></i>';
    btn.innerHTML = '<i class="fa-solid fa-fire"></i><span>Xác Nhận Xóa Vĩnh Viễn</span>';

    bodyEl.innerHTML = `
      <div class="p-3.5 rounded-xl bg-rose-500/10 border border-rose-500/30 text-xs text-rose-200 space-y-1.5">
        <div class="font-bold flex items-center space-x-1.5 text-rose-400">
          <i class="fa-solid fa-triangle-exclamation"></i>
          <span>Hành động này không thể hoàn tác!</span>
        </div>
        <p>Hệ thống sẽ xóa vĩnh viễn bài tập này cùng toàn bộ các phiên bản và bộ test case đi kèm khỏi cơ sở dữ liệu.</p>
      </div>
    `;
  } else if (totalSubmissions > 0) {
    modalTitle.textContent = 'Xác Nhận Xóa Mềm Bài Tập';
    modalSubtitle.textContent = 'Đưa vào Thùng rác và bảo lưu dữ liệu học tập';
    modalIcon.innerHTML = '<i class="fa-solid fa-box-archive text-amber-400"></i>';
    btn.innerHTML = '<i class="fa-solid fa-box-archive"></i><span>Đưa Vào Thùng Rác</span>';

    bodyEl.innerHTML = `
      <div class="p-3.5 rounded-xl bg-amber-500/10 border border-amber-500/30 text-xs text-amber-200 space-y-2">
        <div class="font-bold flex items-center space-x-2 text-amber-400">
          <i class="fa-solid fa-shield-halved"></i>
          <span>Bài tập đã có ${totalSubmissions} lượt nộp bài của học viên</span>
        </div>
        <p class="leading-relaxed">
          Theo tiêu chuẩn Online Judge, hệ thống áp dụng <strong>Xóa mềm (Soft Delete)</strong>:
        </p>
        <ul class="list-disc pl-4 space-y-1 text-slate-300">
          <li>Bài tập được chuyển vào <strong>Thùng rác</strong> và ẩn khỏi thư viện luyện tập của học sinh.</li>
          <li>Học sinh không thể vào giải hay nộp bài mới; slug gốc được tự động giải phóng.</li>
          <li><strong>Bảo toàn 100%:</strong> Mã nguồn, điểm số và lịch sử nộp bài của học viên giữ nguyên vẹn.</li>
          <li>Giảng viên/Admin có thể <strong>Khôi phục</strong> lại bài tập bất kỳ lúc nào trong Thùng rác.</li>
        </ul>
      </div>
    `;
  } else {
    modalTitle.textContent = 'Xác Nhận Xóa Bài Tập';
    modalSubtitle.textContent = 'Bài tập chưa có lượt nộp bài nào';
    modalIcon.innerHTML = '<i class="fa-solid fa-trash-can text-rose-400"></i>';
    btn.innerHTML = '<i class="fa-solid fa-trash-can"></i><span>Xác Nhận Xóa</span>';

    bodyEl.innerHTML = `
      <div class="space-y-2.5 text-xs">
        <p class="text-slate-300">Bài tập này chưa có học viên nào nộp bài. Bạn muốn xử lý theo cách nào?</p>
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-2.5">
          <label class="flex flex-col p-3 rounded-xl bg-white/5 border border-white/10 hover:border-cyan-500/50 cursor-pointer transition-colors">
            <div class="flex items-center space-x-2">
              <input type="radio" name="del_mode_radio" value="soft" checked class="accent-cyan-400">
              <span class="font-bold text-white">Xóa mềm (Khuyên dùng)</span>
            </div>
            <span class="text-[11px] text-slate-400 mt-1">Đưa vào Thùng rác, có thể khôi phục lại bất cứ lúc nào</span>
          </label>
          <label class="flex flex-col p-3 rounded-xl bg-white/5 border border-white/10 hover:border-rose-500/50 cursor-pointer transition-colors">
            <div class="flex items-center space-x-2">
              <input type="radio" name="del_mode_radio" value="hard" class="accent-rose-500">
              <span class="font-bold text-rose-400">Xóa vĩnh viễn</span>
            </div>
            <span class="text-[11px] text-slate-400 mt-1">Xóa sạch khỏi CSDL, không thể hoàn tác</span>
          </label>
        </div>
      </div>
    `;
  }

  openModal('deleteProblemModal');
}

// Hàm gửi yêu cầu xóa (action = 'soft_delete' hoặc 'hard_delete') lên API /BaiTapLon/api/delete_problem.php
async function executeDeleteProblem() {
  const btn = document.getElementById('btn-confirm-delete');
  const statusMsg = document.getElementById('del-status-msg');

  // Xác định chế độ xóa cuối cùng dựa trên trạng thái bài tập và radio button người dùng chọn
  let mode = 'soft';
  if (currentDeleteTarget.isHardDeleteOnly) {
    mode = 'hard';
  } else if (currentDeleteTarget.totalSubmissions > 0) {
    mode = 'soft';
  } else {
    mode = document.querySelector('input[name="del_mode_radio"]:checked')?.value || 'soft';
  }

  const action = mode === 'hard' ? 'hard_delete' : 'soft_delete';

  btn.disabled = true;
  btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i><span>Đang xử lý...</span>';
  statusMsg.innerHTML = '<span class="text-cyan-400">Đang gửi yêu cầu...</span>';

  try {
    const res = await fetch('/BaiTapLon/api/delete_problem.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        action: action,
        problem_id: currentDeleteTarget.problemId
      })
    });

    const data = await res.json();
    if (!res.ok || !data.success) {
      throw new Error(data.error || 'Có lỗi xảy ra khi xóa bài tập.');
    }

    statusMsg.innerHTML = `<span class="text-emerald-400 font-semibold"><i class="fa-solid fa-circle-check mr-1"></i>${escapeHtml(data.message)}</span>`;

    // Đợi 0.6 giây rồi đóng Modal và tải lại danh sách bài tập
    setTimeout(() => {
      closeModal('deleteProblemModal');
      window.location.reload();
    }, 600);

  } catch (err) {
    btn.disabled = false;
    btn.innerHTML = '<i class="fa-solid fa-trash-can"></i><span>Thử Lại</span>';
    statusMsg.innerHTML = `<span class="text-rose-400 font-semibold"><i class="fa-solid fa-triangle-exclamation mr-1"></i>${escapeHtml(err.message)}</span>`;
  }
}

// Hàm khôi phục một bài tập từ Thùng rác về lại danh sách Đang hoạt động qua API delete_problem.php (action = 'restore')
async function restoreProblem(problemId, title) {
  if (!confirm(`Bạn có chắc chắn muốn khôi phục bài tập "${title}" trở lại danh sách hoạt động?`)) {
    return;
  }

  try {
    const res = await fetch('/BaiTapLon/api/delete_problem.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        action: 'restore',
        problem_id: problemId
      })
    });

    const data = await res.json();
    if (!res.ok || !data.success) {
      throw new Error(data.error || 'Không thể khôi phục bài tập.');
    }

    alert(data.message);
    window.location.reload();
  } catch (err) {
    alert('Lỗi: ' + err.message);
  }
}
</script>

  </main>
<?php
// Nạp phần chân trang chung của hệ thống
require_once __DIR__ . '/includes/footer.php';
?>
