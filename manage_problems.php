<?php
/**
 * ByteFlow Online Judge - Quản lý Bài tập & Bộ dữ liệu kiểm tra
 * Dành riêng cho Giảng viên (Instructor) và Quản trị viên (Admin)
 * Phong cách Dark Glassmorphism, Neon Cyan Accent
 */

declare(strict_types=1);

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/core/Auth.php';
require_once __DIR__ . '/core/Security.php';

// Bắt buộc quyền Giảng viên hoặc Quản trị viên
Auth::requireRoles(['admin', 'instructor']);

$currentUser = Auth::user();
$pdo = getDbConnection();

$msgSuccess = '';
$msgError = '';

// Xử lý tạo / sửa bài tập
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];

    if ($action === 'create_problem') {
        $slug = strtolower(trim((string)($_POST['slug'] ?? '')));
        $slug = preg_replace('/[^a-z0-9\-]/', '-', $slug);
        $slug = trim($slug, '-');
        $title = trim((string)($_POST['title'] ?? ''));
        $topic = trim((string)($_POST['topic'] ?? 'Cơ bản'));
        $difficulty = in_array($_POST['difficulty'] ?? '', ['Easy', 'Medium', 'Hard'], true) ? $_POST['difficulty'] : 'Easy';
        $timeLimitMs = max(100, min(10000, (int)($_POST['time_limit_ms'] ?? 1000)));
        $memoryLimitKb = max(16384, min(1048576, (int)($_POST['memory_limit_mb'] ?? 256) * 1024));
        $description = trim((string)($_POST['description'] ?? ''));
        $inputDesc = trim((string)($_POST['input_description'] ?? ''));
        $outputDesc = trim((string)($_POST['output_description'] ?? ''));
        $constraints = trim((string)($_POST['constraints'] ?? ''));
        $sourceAttribution = trim((string)($_POST['source_attribution'] ?? 'ByteFlow Internal'));

        if ($slug === '' || $title === '') {
            $msgError = 'Vui lòng nhập đầy đủ mã định danh (slug) và tiêu đề bài tập.';
        } else {
            // Kiểm tra trùng slug
            $checkStmt = $pdo->prepare("SELECT id FROM problems WHERE slug = ?");
            $checkStmt->execute([$slug]);
            if ($checkStmt->fetch()) {
                $msgError = "Mã slug '{$slug}' đã tồn tại trong hệ thống. Vui lòng chọn slug khác.";
            } else {
                try {
                    $pdo->beginTransaction();

                    // 1. Thêm vào problems
                    $insertProb = $pdo->prepare("
                        INSERT INTO problems (slug, title, topic, difficulty, source_attribution, is_public, created_by)
                        VALUES (?, ?, ?, ?, ?, 1, ?)
                    ");
                    $insertProb->execute([$slug, $title, $topic, $difficulty, $sourceAttribution, $currentUser['id']]);
                    $problemId = (int)$pdo->lastInsertId();

                    // 2. Tạo phiên bản v1 ban đầu
                    $insertVer = $pdo->prepare("
                        INSERT INTO problem_versions (problem_id, version_number, description, input_description, output_description, constraints, time_limit_ms, memory_limit_kb, changelog, created_by)
                        VALUES (?, 1, ?, ?, ?, ?, ?, ?, 'Khởi tạo bài tập phiên bản ban đầu', ?)
                    ");
                    $insertVer->execute([
                        $problemId, $description, $inputDesc, $outputDesc, $constraints,
                        $timeLimitMs, $memoryLimitKb, $currentUser['id']
                    ]);
                    $versionId = (int)$pdo->lastInsertId();

                    // 3. Cập nhật current_version_id
                    $updateProb = $pdo->prepare("UPDATE problems SET current_version_id = ? WHERE id = ?");
                    $updateProb->execute([$versionId, $problemId]);

                    // 4. Thêm các test case ban đầu (Hỗ trợ nhiều test cases)
                    $rawTestCases = $_POST['test_cases'] ?? [];
                    $validTestCases = [];

                    if (is_array($rawTestCases) && !empty($rawTestCases)) {
                        foreach ($rawTestCases as $tc) {
                            $in = (string)($tc['input_data'] ?? '');
                            $out = (string)($tc['expected_output'] ?? '');
                            if ($in !== '' || $out !== '') {
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

                    // Tương thích ngược: kiểm tra sample_input / sample_output nếu mảng test_cases rỗng
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

                    $pdo->commit();
                    $tcCountMsg = count($validTestCases) > 0 ? " kèm " . count($validTestCases) . " test case" : "";
                    $msgSuccess = "Tạo bài tập '{$slug} - {$title}' thành công{$tcCountMsg}!";
                } catch (Exception $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    $msgError = "Lỗi tạo bài tập: " . $e->getMessage();
                }
            }
        }
    } elseif ($action === 'edit_problem') {
        $problemId = (int)($_POST['problem_id'] ?? 0);
        $title = trim((string)($_POST['title'] ?? ''));
        $topic = trim((string)($_POST['topic'] ?? 'Cơ bản'));
        $difficulty = in_array($_POST['difficulty'] ?? '', ['Easy', 'Medium', 'Hard'], true) ? $_POST['difficulty'] : 'Easy';
        $timeLimitMs = max(100, min(10000, (int)($_POST['time_limit_ms'] ?? 1000)));
        $memoryLimitKb = max(16384, min(1048576, (int)($_POST['memory_limit_mb'] ?? 256) * 1024));
        $description = trim((string)($_POST['description'] ?? ''));
        $inputDesc = trim((string)($_POST['input_description'] ?? ''));
        $outputDesc = trim((string)($_POST['output_description'] ?? ''));
        $constraints = trim((string)($_POST['constraints'] ?? ''));

        // Kiểm tra quyền
        $probStmt = $pdo->prepare("SELECT created_by, current_version_id FROM problems WHERE id = ?");
        $probStmt->execute([$problemId]);
        $prob = $probStmt->fetch();

        if (!$prob || !Auth::canManageProblem((int)$prob['created_by'])) {
            $msgError = "Bạn không có quyền chỉnh sửa bài tập này.";
        } else {
            try {
                $pdo->beginTransaction();
                $updProb = $pdo->prepare("UPDATE problems SET title = ?, topic = ?, difficulty = ? WHERE id = ?");
                $updProb->execute([$title, $topic, $difficulty, $problemId]);

                if (!empty($prob['current_version_id'])) {
                    $updVer = $pdo->prepare("
                        UPDATE problem_versions 
                        SET description = ?, input_description = ?, output_description = ?, constraints = ?, time_limit_ms = ?, memory_limit_kb = ?
                        WHERE id = ?
                    ");
                    $updVer->execute([$description, $inputDesc, $outputDesc, $constraints, $timeLimitMs, $memoryLimitKb, $prob['current_version_id']]);
                }
                $pdo->commit();
                $msgSuccess = "Đã cập nhật thông tin bài tập thành công!";
            } catch (Exception $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $msgError = "Lỗi cập nhật: " . $e->getMessage();
            }
        }
    }
}

// Truy vấn danh sách bài tập mà người dùng có quyền quản lý
$params = [];
$whereClause = "";
if (!Auth::isAdmin()) {
    $whereClause = "WHERE p.created_by = ?";
    $params[] = $currentUser['id'];
}

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
    ORDER BY p.id ASC
";
$stmt = $pdo->prepare($query);
$stmt->execute($params);
$problems = $stmt->fetchAll();

$pageTitle = "Quản lý Đề & Bộ Test - ByteFlow Online Judge";
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/sidebar.php';
?>

<div class="pl-64 min-h-screen flex flex-col w-full">
  <main class="p-8 max-w-7xl w-full mx-auto space-y-8 flex-1">
  <!-- Tiêu đề trang & Nút hành động -->
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

  <!-- Thông báo trạng thái -->
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

  <!-- Bảng thống kê sơ bộ -->
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

  <!-- Danh sách bài tập -->
  <div class="glass-panel rounded-2xl border border-white/10 overflow-hidden shadow-2xl">
    <div class="px-6 py-4 border-b border-white/10 flex items-center justify-between">
      <h2 class="text-lg font-bold text-white flex items-center space-x-2">
        <i class="fa-solid fa-list-check text-cyan-400"></i>
        <span>Danh sách Bài tập</span>
      </h2>
      <span class="text-xs text-slate-400">Hiển thị <?= count($problems) ?> bài tập</span>
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
                <i class="fa-solid fa-box-open text-4xl mb-3 block"></i>
                Chưa có bài tập nào. Hãy nhấn "Tạo bài tập mới" để bắt đầu!
              </td>
            </tr>
          <?php else: ?>
            <?php foreach ($problems as $p): ?>
              <tr class="hover:bg-white/[0.02] transition-colors group">
                <td class="py-4 px-6 font-mono font-bold text-cyan-400">
                  <a href="/BaiTapLon/solve.php?slug=<?= urlencode($p['slug']) ?>" class="hover:underline">
                    <?= htmlspecialchars($p['slug']) ?>
                  </a>
                </td>
                <td class="py-4 px-6">
                  <div class="font-semibold text-white group-hover:text-cyan-300 transition-colors">
                    <?= htmlspecialchars($p['title']) ?>
                  </div>
                  <div class="text-xs text-slate-400 flex items-center space-x-3 mt-1">
                    <span><i class="fa-regular fa-clock text-slate-500 mr-1"></i><?= $p['time_limit_ms'] ?? 1000 ?> ms</span>
                    <span><i class="fa-solid fa-memory text-slate-500 mr-1"></i><?= round(($p['memory_limit_kb'] ?? 262144) / 1024) ?> MB</span>
                  </div>
                </td>
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
                <td class="py-4 px-6 text-right space-x-2">
                  <!-- Sửa thông tin cơ bản -->
                  <button 
                    onclick='openEditModal(<?= json_encode($p, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>)'
                    class="p-2 rounded-lg bg-white/5 hover:bg-white/10 text-slate-300 hover:text-white transition-colors"
                    title="Chỉnh sửa thông tin bài"
                  >
                    <i class="fa-solid fa-pen-to-square"></i>
                  </button>

                  <!-- Sửa & Quản lý Test Cases -->
                  <button 
                    type="button"
                    onclick='openTestcasesModal(<?= $p['id'] ?>, <?= htmlspecialchars(json_encode($p['title']), ENT_QUOTES, 'UTF-8') ?>, <?= (int)$p['total_submissions'] ?>, <?= (int)($p['current_version_number'] ?? 1) ?>)'
                    class="p-2 rounded-lg bg-amber-500/10 hover:bg-amber-500/20 text-amber-400 hover:text-amber-300 border border-amber-500/30 transition-colors inline-block cursor-pointer"
                    title="Xem, Thêm & Sửa Bộ Test Cases"
                  >
                    <i class="fa-solid fa-vial"></i>
                  </button>

                  <!-- Tạo phiên bản mới / Quản lý testcase -->
                  <a 
                    href="/BaiTapLon/rejudge_compare.php?problem_id=<?= $p['id'] ?>"
                    class="p-2 rounded-lg bg-cyan-500/10 hover:bg-cyan-500/20 text-cyan-400 hover:text-cyan-300 border border-cyan-500/30 transition-colors inline-block"
                    title="Tạo phiên bản mới & Chấm lại đối chiếu"
                  >
                    <i class="fa-solid fa-code-compare"></i>
                  </a>

                  <!-- Thử giải bài -->
                  <a 
                    href="/BaiTapLon/solve.php?id=<?= $p['id'] ?>"
                    class="p-2 rounded-lg bg-emerald-500/10 hover:bg-emerald-500/20 text-emerald-400 hover:text-emerald-300 border border-emerald-500/30 transition-colors inline-block"
                    title="Mở Workspace giải bài"
                  >
                    <i class="fa-solid fa-play"></i>
                  </a>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- MODAL 1: TẠO BÀI TẬP MỚI -->
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

      <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
        <div>
          <label class="block text-xs uppercase font-bold text-slate-300 mb-1">Bộ nhớ tối đa (MB)</label>
          <input type="number" name="memory_limit_mb" value="256" min="16" max="1024" class="w-full px-4 py-2.5 rounded-xl bg-black/40 border border-white/15 text-white text-sm focus:outline-none focus:border-cyan-400">
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

<!-- MODAL 2: CHỈNH SỬA BÀI TẬP -->
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

      <div>
        <label class="block text-xs uppercase font-bold text-slate-300 mb-1">Bộ nhớ tối đa (MB)</label>
        <input type="number" name="memory_limit_mb" id="edit_memory_limit_mb" min="16" max="1024" class="w-full px-4 py-2.5 rounded-xl bg-black/40 border border-white/15 text-white text-sm focus:outline-none focus:border-cyan-400">
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

<!-- MODAL 3: QUẢN LÝ & SỬA TEST CASES -->
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

<!-- MODAL 4: NHẬP VÀ THẨM ĐỊNH BỘ TEST CASES TỪ TỆP (ZIP/JSON) -->
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

<script>
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

function closeModal(modalId) {
  document.getElementById(modalId).classList.add('hidden');
}

window.addEventListener('click', function(e) {
  const createModal = document.getElementById('createModal');
  const editModal = document.getElementById('editModal');
  const testcasesModal = document.getElementById('testcasesModal');
  const importModal = document.getElementById('importTestcasesModal');
  if (e.target === createModal) closeModal('createModal');
  if (e.target === editModal) closeModal('editModal');
  if (e.target === testcasesModal) closeModal('testcasesModal');
  if (e.target === importModal) closeModal('importTestcasesModal');
});

function openEditModal(problem) {
  document.getElementById('edit_problem_id').value = problem.id;
  document.getElementById('edit_slug').value = problem.slug;
  document.getElementById('edit_title').value = problem.title;
  document.getElementById('edit_topic').value = problem.topic || 'Cơ bản';
  document.getElementById('edit_difficulty').value = problem.difficulty;
  document.getElementById('edit_time_limit_ms').value = problem.time_limit_ms || 1000;
  document.getElementById('edit_memory_limit_mb').value = Math.round((problem.memory_limit_kb || 262144) / 1024);
  document.getElementById('edit_description').value = problem.description || '';
  document.getElementById('edit_input_description').value = problem.input_description || '';
  document.getElementById('edit_output_description').value = problem.output_description || '';
  document.getElementById('edit_constraints').value = problem.constraints || '';
  openModal('editModal');
}

// -------------------------------------------------------------
// QUẢN LÝ TEST CASES KHI TẠO MỚI BÀI TẬP (createModal)
// -------------------------------------------------------------
let createTcCounter = 0;

function addCreateTestcaseRow(data = null) {
  const container = document.getElementById('create-tc-list');
  if (!container) return;
  const count = container.querySelectorAll('.create-tc-card').length;
  const idx = createTcCounter++;

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

function removeCreateTestcaseRow(btn) {
  const container = document.getElementById('create-tc-list');
  if (container.querySelectorAll('.create-tc-card').length <= 1) {
    alert('Cần có ít nhất 1 test case.');
    return;
  }
  btn.closest('.create-tc-card').remove();
  updateCreateTestcaseNumbers();
}

function updateCreateTestcaseNumbers() {
  const container = document.getElementById('create-tc-list');
  if (!container) return;
  const cards = container.querySelectorAll('.create-tc-card');
  cards.forEach((card, i) => {
    const lbl = card.querySelector('.create-tc-number');
    if (lbl) lbl.textContent = `Test Case #${i + 1}`;
  });
}

document.addEventListener('DOMContentLoaded', function() {
  const container = document.getElementById('create-tc-list');
  if (container && container.children.length === 0) {
    addCreateTestcaseRow({ is_sample: 1, is_hidden: 0, points: 10, input: '', output: '' });
    addCreateTestcaseRow({ is_sample: 0, is_hidden: 1, points: 10, input: '', output: '' });
  }
});

// -------------------------------------------------------------
// QUẢN LÝ & SỬA TEST CASES
// -------------------------------------------------------------
let currentTcProblemId = 0;
let currentTcSubmissionsCount = 0;
let currentTcVersionNumber = 1;

function escapeHtml(text) {
  if (text === null || text === undefined) return '';
  return String(text)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#039;');
}

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

let tcModalCardCounter = 0;

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

function updateTestcaseLabels() {
  const container = document.getElementById('tc-list-container');
  const cards = container.querySelectorAll('.testcase-card');
  cards.forEach((card, idx) => {
    const label = card.querySelector('.tc-number-label');
    if (label) label.textContent = `Test Case #${idx + 1}`;
  });
  document.getElementById('tc-total-count-label').textContent = `Tổng cộng: ${cards.length} test case`;
}

async function saveTestcases() {
  const container = document.getElementById('tc-list-container');
  const cards = container.querySelectorAll('.testcase-card');
  if (cards.length === 0) {
    alert('Cần có ít nhất 1 test case.');
    return;
  }

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

    // Thành công
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
// XỬ LÝ NHẬP TEST CASES TỪ TỆP (ZIP/JSON) & THẨM ĐỊNH ĐỐI SOÁT
// -------------------------------------------------------------
let currentImportTarget = 'create';
let validatedImportCases = [];

function openImportModal(target) {
  currentImportTarget = target;
  resetImportUploadStage();
  openModal('importTestcasesModal');
}

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

function toggleModelVerifyBox(checked) {
  const box = document.getElementById('model-verify-box');
  if (checked) {
    box.classList.remove('hidden');
  } else {
    box.classList.add('hidden');
  }
}

async function uploadAndValidateTestcases() {
  const fileInput = document.getElementById('import-file-input');
  if (!fileInput.files || !fileInput.files[0]) {
    alert('Vui lòng chọn tệp ZIP hoặc JSON chứa bộ test case.');
    return;
  }

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

    // Render Thống kê
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

    // Cảnh báo nếu có file mồ côi
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

    // Render Preview Table
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

    // Chuyển sang Stage 2
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

function applyImportedTestcases() {
  if (!validatedImportCases || validatedImportCases.length === 0) {
    alert('Không có test case nào để áp dụng.');
    return;
  }

  // Đọc lại các lựa chọn phân loại và điểm số từ bảng xem trước
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
</script>

  </main>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
