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

                    // 4. Thêm test case ví dụ mẫu nếu có
                    $sampleInput = (string)($_POST['sample_input'] ?? '');
                    $sampleOutput = (string)($_POST['sample_output'] ?? '');
                    if ($sampleInput !== '' || $sampleOutput !== '') {
                        $insertTc = $pdo->prepare("
                            INSERT INTO test_cases (problem_version_id, case_order, input_data, expected_output, is_sample, is_hidden, points)
                            VALUES (?, 1, ?, ?, 1, 0, 10)
                        ");
                        $insertTc->execute([$versionId, $sampleInput, $sampleOutput]);
                    }

                    $pdo->commit();
                    $msgSuccess = "Tạo bài tập '{$slug} - {$title}' thành công!";
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
      <button onclick="openModal('createModal')" class="neon-button px-5 py-2.5 rounded-xl text-black font-bold text-sm flex items-center space-x-2">
        <i class="fa-solid fa-plus"></i>
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
              <td colspan="8" class="py-12 text-center text-slate-500">
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
  <div class="glass-panel w-full max-w-2xl rounded-2xl border border-white/20 p-6 my-8 space-y-6 shadow-2xl">
    <div class="flex items-center justify-between border-b border-white/10 pb-4">
      <h3 class="text-xl font-bold text-white flex items-center space-x-2">
        <i class="fa-solid fa-plus-circle text-cyan-400"></i>
        <span>Tạo Bài Tập Mới</span>
      </h3>
      <button onclick="closeModal('createModal')" class="text-slate-400 hover:text-white text-xl">
        <i class="fa-solid fa-xmark"></i>
      </button>
    </div>

    <form method="POST" action="" class="space-y-4">
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

      <div class="p-4 rounded-xl bg-cyan-500/5 border border-cyan-500/20 space-y-3">
        <h4 class="text-xs uppercase font-bold text-cyan-400">Test Case Mẫu Ban Đầu (Công khai)</h4>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
          <div>
            <label class="block text-[11px] text-slate-400 mb-1 font-mono">Dữ liệu Vào (Sample Input)</label>
            <textarea name="sample_input" rows="2" placeholder="2 3" class="w-full p-2.5 rounded-lg bg-black/50 border border-white/10 text-white font-mono text-xs focus:outline-none focus:border-cyan-400"></textarea>
          </div>
          <div>
            <label class="block text-[11px] text-slate-400 mb-1 font-mono">Dữ liệu Ra (Sample Output)</label>
            <textarea name="sample_output" rows="2" placeholder="5" class="w-full p-2.5 rounded-lg bg-black/50 border border-white/10 text-white font-mono text-xs focus:outline-none focus:border-cyan-400"></textarea>
          </div>
        </div>
      </div>

      <div class="flex items-center justify-end space-x-3 pt-4 border-t border-white/10">
        <button type="button" onclick="closeModal('createModal')" class="px-5 py-2.5 rounded-xl bg-white/5 hover:bg-white/10 text-slate-300 text-sm font-semibold transition-colors">
          Hủy bỏ
        </button>
        <button type="submit" class="neon-button px-6 py-2.5 rounded-xl text-black font-bold text-sm">
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

      <div class="flex items-center justify-end space-x-3 pt-4 border-t border-white/10">
        <button type="button" onclick="closeModal('editModal')" class="px-5 py-2.5 rounded-xl bg-white/5 hover:bg-white/10 text-slate-300 text-sm font-semibold transition-colors">
          Hủy bỏ
        </button>
        <button type="submit" class="neon-button px-6 py-2.5 rounded-xl text-black font-bold text-sm">
          Cập nhật thông tin
        </button>
      </div>
    </form>
  </div>
</div>

<script>
function openModal(modalId) {
  document.getElementById(modalId).classList.remove('hidden');
}

function closeModal(modalId) {
  document.getElementById(modalId).classList.add('hidden');
}

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
  openModal('editModal');
}
</script>

  </main>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
