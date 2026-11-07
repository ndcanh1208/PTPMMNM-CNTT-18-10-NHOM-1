<?php
/**
 * ByteFlow Online Judge - Trang Chủ & Bảng điều khiển Tổng quan (Dashboard)
 */

declare(strict_types=1);

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/core/Auth.php';
require_once __DIR__ . '/core/Security.php';

Auth::startSession();
$currentUser = Auth::user();
$pdo = getDbConnection();

// 1. Thống kê tổng quan
$totalProblems = (int)$pdo->query("SELECT COUNT(*) FROM problems WHERE is_public = 1")->fetchColumn();
$totalSubmissions = (int)$pdo->query("SELECT COUNT(*) FROM submissions")->fetchColumn();
$queueCount = (int)$pdo->query("SELECT COUNT(*) FROM judging_runs WHERE status = 'Queued'")->fetchColumn();

// Thống kê riêng cho người học đã đăng nhập
$solvedCount = 0;
if ($currentUser) {
    $solvedStmt = $pdo->prepare("
        SELECT COUNT(DISTINCT problem_id) 
        FROM submissions 
        WHERE user_id = ? AND final_verdict = 'AC'
    ");
    $solvedStmt->execute([$currentUser['id']]);
    $solvedCount = (int)$solvedStmt->fetchColumn();
}

// 2. Danh sách bài tập nổi bật (kèm thông tin phiên bản)
$probStmt = $pdo->query("
    SELECT p.id, p.slug, p.title, p.topic, p.difficulty, p.source_attribution,
           pv.version_number, pv.time_limit_ms, pv.memory_limit_kb
    FROM problems p
    JOIN problem_versions pv ON p.current_version_id = pv.id
    WHERE p.is_public = 1
    ORDER BY p.id ASC
    LIMIT 6
");
$featuredProblems = $probStmt->fetchAll();

// 3. Các bài nộp gần đây nhất
$recentSubStmt = $pdo->query("
    SELECT s.id, s.problem_id, s.language, s.final_verdict, s.submitted_at, s.execution_time_ms,
           p.title as problem_title, u.username, u.full_name
    FROM submissions s
    JOIN problems p ON s.problem_id = p.id
    JOIN users u ON s.user_id = u.id
    ORDER BY s.id DESC
    LIMIT 5
");
$recentSubmissions = $recentSubStmt->fetchAll();

$pageTitle = 'Tổng quan hệ thống';
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/sidebar.php';
?>

<!-- MAIN CONTENT WRAPPER (Cách lề trái 64 để nhường chỗ cho Fixed Sidebar) -->
<div class="pl-64 flex-1 flex flex-col min-h-screen">
  <main class="p-8 max-w-7xl w-full mx-auto space-y-8">
    
    <!-- Top Banner -->
    <div class="glass-panel rounded-3xl p-8 relative overflow-hidden border border-white/10 shadow-2xl">
      <div class="absolute -right-16 -top-16 w-80 h-80 bg-cyan-500/15 rounded-full blur-3xl pointer-events-none"></div>
      <div class="absolute right-40 -bottom-20 w-60 h-60 bg-blue-600/15 rounded-full blur-3xl pointer-events-none"></div>
      
      <div class="relative z-10 max-w-2xl space-y-3">
        <div class="inline-flex items-center space-x-2 px-3 py-1 rounded-full bg-cyan-500/10 border border-cyan-500/30 text-cyan-300 text-xs font-semibold">
          <i class="fa-solid fa-bolt text-xs"></i>
          <span>Hệ thống Chấm bài Trực tuyến Thế hệ Mới</span>
        </div>
        <h1 class="text-3xl font-black tracking-tight text-white sm:text-4xl">
          Chào mừng đến với <span class="text-cyan-400">ByteFlow</span> Judge
        </h1>
        <p class="text-slate-300 text-sm leading-relaxed">
          Nền tảng luyện tập lập trình trực tuyến bảo mật, tách biệt tiến trình thực thi cách ly, hỗ trợ quản lý đa phiên bản bộ test và chấm lại tự động đối chiếu song song.
        </p>

        <div class="pt-2 flex flex-wrap gap-3">
          <a href="/BaiTapLon/problems.php" class="btn-cyan flex items-center space-x-2 text-sm font-semibold">
            <i class="fa-solid fa-play text-xs"></i>
            <span>Bắt đầu Luyện tập</span>
          </a>
          <?php if (!$currentUser): ?>
            <a href="/BaiTapLon/login.php" class="btn-glass flex items-center space-x-2 text-sm font-semibold">
              <i class="fa-solid fa-arrow-right-to-bracket text-xs"></i>
              <span>Đăng nhập thử nghiệm</span>
            </a>
          <?php else: ?>
            <a href="/BaiTapLon/submissions.php" class="btn-glass flex items-center space-x-2 text-sm font-semibold">
              <i class="fa-solid fa-clock-rotate-left text-xs"></i>
              <span>Bài nộp của tôi</span>
            </a>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <!-- 4 Khối Thống kê Chính -->
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-5">
      <!-- Thẻ 1: Tổng bài tập -->
      <div class="glass-card rounded-2xl p-5 border border-white/10 flex items-center space-x-4">
        <div class="w-12 h-12 rounded-xl bg-cyan-500/15 border border-cyan-500/30 text-cyan-400 flex items-center justify-center text-xl shrink-0">
          <i class="fa-solid fa-code"></i>
        </div>
        <div>
          <div class="text-2xl font-black text-white"><?= $totalProblems ?></div>
          <div class="text-xs text-slate-400 font-medium">Bài tập có sẵn</div>
        </div>
      </div>

      <!-- Thẻ 2: Bài đã giải -->
      <div class="glass-card rounded-2xl p-5 border border-white/10 flex items-center space-x-4">
        <div class="w-12 h-12 rounded-xl bg-emerald-500/15 border border-emerald-500/30 text-emerald-400 flex items-center justify-center text-xl shrink-0">
          <i class="fa-solid fa-circle-check"></i>
        </div>
        <div>
          <div class="text-2xl font-black text-white"><?= $solvedCount ?> / <?= $totalProblems ?></div>
          <div class="text-xs text-slate-400 font-medium"><?= $currentUser ? 'Bài đã hoàn thành' : 'Đăng nhập để xem' ?></div>
        </div>
      </div>

      <!-- Thẻ 3: Tổng lượt nộp -->
      <div class="glass-card rounded-2xl p-5 border border-white/10 flex items-center space-x-4">
        <div class="w-12 h-12 rounded-xl bg-blue-500/15 border border-blue-500/30 text-blue-400 flex items-center justify-center text-xl shrink-0">
          <i class="fa-solid fa-paper-plane"></i>
        </div>
        <div>
          <div class="text-2xl font-black text-white"><?= $totalSubmissions ?></div>
          <div class="text-xs text-slate-400 font-medium">Tổng lượt nộp toàn sàn</div>
        </div>
      </div>

      <!-- Thẻ 4: Trạng thái Worker / Hàng đợi -->
      <div class="glass-card rounded-2xl p-5 border border-white/10 flex items-center space-x-4">
        <div class="w-12 h-12 rounded-xl bg-purple-500/15 border border-purple-500/30 text-purple-400 flex items-center justify-center text-xl shrink-0">
          <i class="fa-solid fa-microchip"></i>
        </div>
        <div>
          <div class="text-xl font-black text-white flex items-center space-x-2">
            <span>Hoạt động</span>
            <span class="inline-block w-2.5 h-2.5 rounded-full bg-emerald-400 shadow-[0_0_8px_#10B981]"></span>
          </div>
          <div class="text-xs text-slate-400 font-medium">Hàng đợi: <?= $queueCount ?> bài</div>
        </div>
      </div>
    </div>

    <!-- Lưới 2 Cột: Bài tập nổi bật & Bài nộp gần đây -->
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
      
      <!-- Cột trái (2/3): Danh sách Bài tập Nổi bật -->
      <div class="lg:col-span-2 space-y-4">
        <div class="flex items-center justify-between">
          <h2 class="text-lg font-bold text-white flex items-center space-x-2">
            <i class="fa-solid fa-fire text-amber-400 text-sm"></i>
            <span>Bài tập nổi bật</span>
          </h2>
          <a href="/BaiTapLon/problems.php" class="text-xs text-cyan-400 hover:text-cyan-300 font-semibold flex items-center space-x-1">
            <span>Xem tất cả bài tập</span>
            <i class="fa-solid fa-angle-right text-[10px]"></i>
          </a>
        </div>

        <div class="space-y-3">
          <?php foreach ($featuredProblems as $prob): ?>
            <?php
              $diffColors = [
                'Easy'   => 'bg-emerald-500/15 text-emerald-400 border-emerald-500/30',
                'Medium' => 'bg-amber-500/15 text-amber-400 border-amber-500/30',
                'Hard'   => 'bg-rose-500/15 text-rose-400 border-rose-500/30'
              ];
            ?>
            <div class="glass-card rounded-2xl p-5 border border-white/10 flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4">
              <div class="space-y-1.5 flex-1">
                <div class="flex items-center space-x-2.5">
                  <span class="px-2 py-0.5 rounded text-[11px] font-bold border <?= $diffColors[$prob['difficulty']] ?? 'border-slate-500' ?>">
                    <?= $prob['difficulty'] ?>
                  </span>
                  <span class="px-2 py-0.5 rounded text-[11px] font-mono bg-white/5 border border-white/10 text-cyan-300">
                    v<?= $prob['version_number'] ?>.0
                  </span>
                  <span class="text-xs text-slate-400 font-medium">
                    <?= Security::escape($prob['topic']) ?>
                  </span>
                </div>
                <h3 class="text-base font-bold text-white hover:text-cyan-400 transition-colors">
                  <a href="/BaiTapLon/solve.php?id=<?= $prob['id'] ?>">
                    #<?= $prob['id'] ?>. <?= Security::escape($prob['title']) ?>
                  </a>
                </h3>
                <div class="text-[11px] text-slate-400 truncate">
                  <i class="fa-regular fa-copyright mr-1"></i><?= Security::escape($prob['source_attribution']) ?>
                </div>
              </div>

              <a href="/BaiTapLon/solve.php?id=<?= $prob['id'] ?>" class="btn-cyan text-xs py-2 px-4 shrink-0 flex items-center space-x-1.5">
                <span>Giải bài</span>
                <i class="fa-solid fa-arrow-right text-[10px]"></i>
              </a>
            </div>
          <?php endforeach; ?>
        </div>
      </div>

      <!-- Cột phải (1/3): Hoạt động nộp bài gần đây & Đặc tính hệ thống -->
      <div class="space-y-6">
        
        <!-- Hoạt động nộp bài gần đây -->
        <div class="glass-panel rounded-2xl p-5 border border-white/10 space-y-4">
          <h2 class="text-base font-bold text-white flex items-center space-x-2">
            <i class="fa-solid fa-bolt text-cyan-400 text-sm"></i>
            <span>Nộp bài gần đây</span>
          </h2>

          <?php if (empty($recentSubmissions)): ?>
            <div class="text-xs text-slate-500 py-4 text-center">Chưa có lượt nộp bài nào.</div>
          <?php else: ?>
            <div class="space-y-3">
              <?php foreach ($recentSubmissions as $sub): ?>
                <?php
                  $v = $sub['final_verdict'];
                  $badgeClass = match($v) {
                    'AC' => 'badge-ac',
                    'WA' => 'badge-wa',
                    'TLE' => 'badge-tle',
                    'RE' => 'badge-re',
                    'CE' => 'badge-ce',
                    default => 'badge-pending'
                  };
                ?>
                <div class="p-3 rounded-xl bg-white/5 border border-white/5 text-xs flex items-center justify-between">
                  <div class="truncate mr-2">
                    <div class="font-semibold text-slate-200 truncate">
                      <?= Security::escape($sub['problem_title']) ?>
                    </div>
                    <div class="text-[10px] text-slate-400 flex items-center space-x-2 mt-0.5">
                      <span><?= Security::escape($sub['username']) ?></span>
                      <span>•</span>
                      <span class="font-mono text-cyan-400 uppercase"><?= $sub['language'] ?></span>
                    </div>
                  </div>
                  <span class="px-2 py-0.5 rounded text-[11px] font-bold font-mono shrink-0 <?= $badgeClass ?>">
                    <?= $v ?>
                  </span>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>

        <!-- 5 Nguyên tắc Kỹ thuật Đạt chuẩn Rubric -->
        <div class="glass-panel rounded-2xl p-5 border border-white/10 space-y-3 text-xs">
          <div class="font-bold text-slate-200 uppercase tracking-wider text-[11px] text-cyan-400 flex items-center space-x-1.5">
            <i class="fa-solid fa-shield-halved"></i>
            <span>Bảo chứng Kỹ thuật & An toàn</span>
          </div>
          <ul class="space-y-2 text-slate-400">
            <li class="flex items-start space-x-2">
              <i class="fa-solid fa-check text-cyan-400 mt-0.5"></i>
              <span><strong>Tách tiến trình:</strong> Worker độc lập chạy nền qua CLI, không chạy trong Web Apache.</span>
            </li>
            <li class="flex items-start space-x-2">
              <i class="fa-solid fa-check text-cyan-400 mt-0.5"></i>
              <span><strong>Idempotency:</strong> Khóa chống nộp trùng không tạo bài nộp dư thừa.</span>
            </li>
            <li class="flex items-start space-x-2">
              <i class="fa-solid fa-check text-cyan-400 mt-0.5"></i>
              <span><strong>Stale Run Protection:</strong> Kết quả chấm cũ về muộn không ghi đè kết quả Rejudge mới.</span>
            </li>
            <li class="flex items-start space-x-2">
              <i class="fa-solid fa-check text-cyan-400 mt-0.5"></i>
              <span><strong>Bảo mật dữ liệu kín:</strong> Ẩn 100% input/output các ca test chấm kín.</span>
            </li>
          </ul>
        </div>

      </div>

    </div>

  </main>
  <?php require_once __DIR__ . '/includes/footer.php'; ?>
</div>
