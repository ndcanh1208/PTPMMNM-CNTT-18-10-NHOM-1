<?php
/**
 * ByteFlow Online Judge - Thư viện Bài tập (Problems Library)
 * Bộ lọc theo Chủ đề, Độ khó, Phiên bản và Trạng thái giải
 */

declare(strict_types=1);

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/core/Auth.php';
require_once __DIR__ . '/core/Security.php';

Auth::startSession();
$currentUser = Auth::user();
$pdo = getDbConnection();

// Lấy tham số bộ lọc
$topicFilter      = trim((string)($_GET['topic'] ?? ''));
$difficultyFilter = trim((string)($_GET['difficulty'] ?? ''));
$searchKeyword    = trim((string)($_GET['q'] ?? ''));
$statusFilter     = trim((string)($_GET['status'] ?? ''));

// Xây dựng truy vấn
$whereClauses = ["p.is_public = 1"];
$params = [];

if ($topicFilter !== '') {
    $whereClauses[] = "p.topic = :topic";
    $params['topic'] = $topicFilter;
}

if ($difficultyFilter !== '') {
    $whereClauses[] = "p.difficulty = :diff";
    $params['diff'] = $difficultyFilter;
}

if ($searchKeyword !== '') {
    $whereClauses[] = "(p.title LIKE :q1 OR p.slug LIKE :q2 OR p.topic LIKE :q3)";
    $params['q1'] = "%{$searchKeyword}%";
    $params['q2'] = "%{$searchKeyword}%";
    $params['q3'] = "%{$searchKeyword}%";
}

$whereSql = implode(' AND ', $whereClauses);

$stmt = $pdo->prepare("
    SELECT p.id, p.slug, p.title, p.topic, p.difficulty, p.source_attribution,
           pv.id as version_id, pv.version_number, pv.time_limit_ms, pv.memory_limit_kb
    FROM problems p
    JOIN problem_versions pv ON p.current_version_id = pv.id
    WHERE {$whereSql}
    ORDER BY p.id ASC
");
$stmt->execute($params);
$problems = $stmt->fetchAll();

// Lấy danh sách các topic duy nhất để làm bộ lọc
$allTopics = $pdo->query("SELECT DISTINCT topic FROM problems WHERE is_public = 1 ORDER BY topic ASC")->fetchAll(PDO::FETCH_COLUMN);

// Nếu user đã đăng nhập, lấy danh sách các problem_id đã AC
$solvedMap = [];
if ($currentUser) {
    $userSolved = $pdo->prepare("
        SELECT DISTINCT problem_id 
        FROM submissions 
        WHERE user_id = ? AND final_verdict = 'AC'
    ");
    $userSolved->execute([$currentUser['id']]);
    $solvedIds = $userSolved->fetchAll(PDO::FETCH_COLUMN);
    $solvedMap = array_flip($solvedIds);
}

// Lọc theo trạng thái AC nếu có chọn
if ($statusFilter === 'solved') {
    $problems = array_filter($problems, fn($p) => isset($solvedMap[$p['id']]));
} elseif ($statusFilter === 'unsolved') {
    $problems = array_filter($problems, fn($p) => !isset($solvedMap[$p['id']]));
}

$pageTitle = 'Thư viện Bài tập';
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/sidebar.php';
?>

<div class="pl-64 flex-1 flex flex-col min-h-screen">
  <main class="p-8 max-w-7xl w-full mx-auto space-y-6">

    <!-- Tiêu đề trang -->
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
      <div>
        <h1 class="text-2xl font-black text-white flex items-center space-x-2">
          <i class="fa-solid fa-book-bookmark text-cyan-400"></i>
          <span>Thư viện Bài tập Lập trình</span>
        </h1>
        <p class="text-xs text-slate-400 mt-1">
          Hệ thống 10 bài tập chuẩn hóa, hỗ trợ kiểm thử biên và phân loại tự động.
        </p>
      </div>

      <div class="text-xs text-slate-400 font-mono">
        Tìm thấy <span class="text-cyan-400 font-bold"><?= count($problems) ?></span> bài tập
      </div>
    </div>

    <!-- Thanh Tìm kiếm & Bộ lọc -->
    <form action="" method="GET" class="glass-panel rounded-2xl p-4 border border-white/10 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3">
      <!-- Tìm kiếm -->
      <div class="relative">
        <span class="absolute inset-y-0 left-0 flex items-center pl-3 text-slate-500">
          <i class="fa-solid fa-magnifying-glass text-xs"></i>
        </span>
        <input type="text" name="q" value="<?= Security::escape($searchKeyword) ?>" 
          placeholder="Tìm theo tên hoặc từ khóa..."
          class="w-full glass-input rounded-xl py-2 pl-9 pr-3 text-xs placeholder-slate-500">
      </div>

      <!-- Lọc Chủ đề -->
      <div>
        <select name="topic" onchange="this.form.submit()" class="w-full glass-input rounded-xl py-2 px-3 text-xs bg-slate-900">
          <option value="">-- Tất cả Chủ đề --</option>
          <?php foreach ($allTopics as $t): ?>
            <option value="<?= Security::escape($t) ?>" <?= $topicFilter === $t ? 'selected' : '' ?>>
              <?= Security::escape($t) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <!-- Lọc Độ khó -->
      <div>
        <select name="difficulty" onchange="this.form.submit()" class="w-full glass-input rounded-xl py-2 px-3 text-xs bg-slate-900">
          <option value="">-- Tất cả Độ khó --</option>
          <option value="Easy" <?= $difficultyFilter === 'Easy' ? 'selected' : '' ?>>Easy (Dễ)</option>
          <option value="Medium" <?= $difficultyFilter === 'Medium' ? 'selected' : '' ?>>Medium (Trung bình)</option>
          <option value="Hard" <?= $difficultyFilter === 'Hard' ? 'selected' : '' ?>>Hard (Khó)</option>
        </select>
      </div>

      <!-- Lọc Trạng thái (nếu đã đăng nhập) -->
      <div class="flex items-center space-x-2">
        <select name="status" onchange="this.form.submit()" class="w-full glass-input rounded-xl py-2 px-3 text-xs bg-slate-900">
          <option value="">-- Tất cả Trạng thái --</option>
          <option value="solved" <?= $statusFilter === 'solved' ? 'selected' : '' ?>>Đã giải quyết (AC)</option>
          <option value="unsolved" <?= $statusFilter === 'unsolved' ? 'selected' : '' ?>>Chưa giải</option>
        </select>
        
        <?php if ($searchKeyword !== '' || $topicFilter !== '' || $difficultyFilter !== '' || $statusFilter !== ''): ?>
          <a href="/BaiTapLon/problems.php" class="p-2 rounded-xl bg-white/5 hover:bg-white/10 text-slate-400 hover:text-white text-xs shrink-0" title="Đặt lại bộ lọc">
            <i class="fa-solid fa-xmark"></i>
          </a>
        <?php endif; ?>
      </div>
    </form>

    <!-- Bảng Danh sách Bài tập -->
    <div class="glass-panel rounded-2xl overflow-hidden border border-white/10 shadow-xl">
      <div class="overflow-x-auto">
        <table class="w-full text-left text-xs border-collapse">
          <thead>
            <tr class="border-b border-white/10 bg-white/[0.02] text-slate-400 uppercase font-semibold tracking-wider text-[11px]">
              <th class="py-3.5 px-4 w-16 text-center">Trạng thái</th>
              <th class="py-3.5 px-4 w-12 text-center">ID</th>
              <th class="py-3.5 px-4">Tên Bài tập</th>
              <th class="py-3.5 px-4 w-36">Chủ đề</th>
              <th class="py-3.5 px-4 w-28 text-center">Độ khó</th>
              <th class="py-3.5 px-4 w-28 text-center">Phiên bản</th>
              <th class="py-3.5 px-4 w-44">Nguồn gốc & Bản quyền</th>
              <th class="py-3.5 px-4 w-28 text-center">Thao tác</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-white/5">
            <?php if (empty($problems)): ?>
              <tr>
                <td colspan="8" class="py-12 text-center text-slate-500 text-xs">
                  Không tìm thấy bài tập nào phù hợp với bộ lọc hiện tại.
                </td>
              </tr>
            <?php else: ?>
              <?php foreach ($problems as $p): ?>
                <?php
                  $isSolved = isset($solvedMap[$p['id']]);
                  $diffBadge = match($p['difficulty']) {
                    'Easy'   => 'bg-emerald-500/15 text-emerald-400 border-emerald-500/30',
                    'Medium' => 'bg-amber-500/15 text-amber-400 border-amber-500/30',
                    'Hard'   => 'bg-rose-500/15 text-rose-400 border-rose-500/30',
                    default  => 'bg-slate-500/15 text-slate-400'
                  };
                ?>
                <tr class="hover:bg-white/[0.03] transition-colors group">
                  <!-- Trạng thái AC -->
                  <td class="py-3.5 px-4 text-center">
                    <?php if ($isSolved): ?>
                      <span title="Đã hoàn thành AC" class="text-emerald-400 text-sm">
                        <i class="fa-solid fa-circle-check"></i>
                      </span>
                    <?php else: ?>
                      <span class="text-slate-600 text-xs">•</span>
                    <?php endif; ?>
                  </td>

                  <!-- ID -->
                  <td class="py-3.5 px-4 text-center font-mono text-slate-400">
                    #<?= $p['id'] ?>
                  </td>

                  <!-- Tên bài -->
                  <td class="py-3.5 px-4">
                    <a href="/BaiTapLon/solve.php?id=<?= $p['id'] ?>" class="font-bold text-white group-hover:text-cyan-400 transition-colors text-sm">
                      <?= Security::escape($p['title']) ?>
                    </a>
                    <div class="text-[11px] text-slate-500 font-mono mt-0.5">
                      <?= Security::escape($p['slug']) ?>
                    </div>
                  </td>

                  <!-- Chủ đề -->
                  <td class="py-3.5 px-4 text-slate-300">
                    <?= Security::escape($p['topic']) ?>
                  </td>

                  <!-- Độ khó -->
                  <td class="py-3.5 px-4 text-center">
                    <span class="px-2 py-0.5 rounded text-[11px] font-bold border <?= $diffBadge ?>">
                      <?= $p['difficulty'] ?>
                    </span>
                  </td>

                  <!-- Phiên bản -->
                  <td class="py-3.5 px-4 text-center">
                    <span class="px-2 py-0.5 rounded text-[11px] font-mono bg-white/5 border border-white/10 text-cyan-300">
                      v<?= $p['version_number'] ?>.0
                    </span>
                  </td>

                  <!-- Nguồn gốc hợp lệ -->
                  <td class="py-3.5 px-4 text-slate-400 text-[11px]">
                    <div class="truncate max-w-[200px]" title="<?= Security::escape($p['source_attribution']) ?>">
                      <i class="fa-regular fa-copyright mr-1"></i><?= Security::escape($p['source_attribution']) ?>
                    </div>
                  </td>

                  <!-- Nút Giải bài -->
                  <td class="py-3.5 px-4 text-center">
                    <a href="/BaiTapLon/solve.php?id=<?= $p['id'] ?>" class="btn-cyan text-xs py-1.5 px-3 inline-flex items-center space-x-1">
                      <span>Làm bài</span>
                      <i class="fa-solid fa-chevron-right text-[9px]"></i>
                    </a>
                  </td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

  </main>
  <?php require_once __DIR__ . '/includes/footer.php'; ?>
</div>
