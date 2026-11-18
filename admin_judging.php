<?php
/**
 * Nhom1 Online Judge - Phân hệ Admin: Quản lý Chấm bài & Vận hành Hệ thống
 * - Giám sát hàng đợi chấm bài cô lập (Sandbox Queues) cho Python & C++
 * - Kích hoạt Rejudge cho toàn bộ bài tập hoặc sinh viên cụ thể
 * - Giám sát & xử lý lỗi hệ thống (SE, CE, tràn/treo hàng đợi)
 */

declare(strict_types=1);

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/core/Auth.php';
require_once __DIR__ . '/core/Security.php';
require_once __DIR__ . '/core/SandboxRunner.php';

Auth::requireRoles(['admin']);

$pdo = getDbConnection();
$sandbox = new SandboxRunner();

$pyPath  = $sandbox->getPythonPath();
$gppPath = $sandbox->getGppPath();
$pyVer   = $pyPath ? trim((string)@shell_exec("\"{$pyPath}\" --version 2>&1")) : null;
$gppRaw  = $gppPath ? trim((string)@shell_exec("\"{$gppPath}\" --version 2>&1")) : null;
$gppVer  = $gppRaw ? trim(explode("\n", $gppRaw)[0]) : null;

// Thống kê hàng đợi theo ngôn ngữ
$queueRows = $pdo->query("
    SELECT s.language, jr.status, COUNT(*) AS cnt
    FROM judging_runs jr
    JOIN submissions s ON jr.submission_id = s.id
    GROUP BY s.language, jr.status
")->fetchAll(PDO::FETCH_ASSOC);

$langStats = [
    'python' => ['Queued' => 0, 'Running' => 0, 'Completed' => 0, 'SystemError' => 0, 'Superseded' => 0],
    'cpp'    => ['Queued' => 0, 'Running' => 0, 'Completed' => 0, 'SystemError' => 0, 'Superseded' => 0]
];
foreach ($queueRows as $r) {
    if (isset($langStats[$r['language']][$r['status']])) {
        $langStats[$r['language']][$r['status']] = (int)$r['cnt'];
    }
}

$totalQueued  = $langStats['python']['Queued'] + $langStats['cpp']['Queued'];
$totalRunning = $langStats['python']['Running'] + $langStats['cpp']['Running'];
$stalledCount = (int)$pdo->query("
    SELECT COUNT(*) FROM judging_runs
    WHERE status = 'Running' AND started_at IS NOT NULL AND started_at < DATE_SUB(NOW(), INTERVAL 60 SECOND)
")->fetchColumn();
$seCount = (int)$pdo->query("SELECT COUNT(*) FROM judging_runs WHERE status = 'SystemError' OR verdict = 'SE'")->fetchColumn();
$ceCount = (int)$pdo->query("SELECT COUNT(*) FROM judging_runs WHERE verdict = 'CE'")->fetchColumn();

// Danh sách bài tập và sinh viên cho form Rejudge
$problemsList = $pdo->query("SELECT id, slug, title, deleted_at FROM problems ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
$studentsList = $pdo->query("SELECT id, username, full_name, student_code, class_name FROM users ORDER BY role ASC, username ASC")->fetchAll(PDO::FETCH_ASSOC);

// Bộ lọc nhật ký judging_runs
$filter = trim((string)($_GET['filter'] ?? 'all'));
$whereRun = "1=1";
if ($filter === 'queued') {
    $whereRun = "jr.status IN ('Queued', 'Running')";
} elseif ($filter === 'se') {
    $whereRun = "(jr.status = 'SystemError' OR jr.verdict = 'SE')";
} elseif ($filter === 'ce') {
    $whereRun = "jr.verdict = 'CE'";
} elseif ($filter === 'rejudge') {
    $whereRun = "jr.triggered_by IN ('rejudge', 'retry')";
}

$runsStmt = $pdo->query("
    SELECT jr.*, s.language, s.problem_id, s.user_id, s.final_verdict,
           u.username, u.full_name, u.student_code,
           p.title AS problem_title, p.slug AS problem_slug,
           pv.version_number
    FROM judging_runs jr
    JOIN submissions s ON jr.submission_id = s.id
    JOIN users u ON s.user_id = u.id
    JOIN problems p ON s.problem_id = p.id
    JOIN problem_versions pv ON jr.problem_version_id = pv.id
    WHERE {$whereRun}
    ORDER BY jr.id DESC
    LIMIT 60
");
$runs = $runsStmt->fetchAll(PDO::FETCH_ASSOC);

$pageTitle = 'Vận hành Chấm bài & Rejudge';
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/sidebar.php';
?>

<div class="pl-64 min-h-screen flex flex-col w-full">
  <main class="p-8 max-w-7xl w-full mx-auto space-y-6 flex-1">
    <!-- Header -->
    <div class="flex flex-col md:flex-row md:items-center justify-between gap-4">
      <div>
        <h1 class="text-2xl font-black text-white flex items-center space-x-3">
          <i class="fa-solid fa-server text-cyan-400"></i>
          <span>Quản lý Chấm bài & Vận hành Hệ thống</span>
        </h1>
        <p class="text-xs text-slate-400 mt-1">
          Giám sát hàng đợi thực thi cô lập (Python & C++ Sandbox), điều khiển Rejudge và xử lý sự cố hệ thống.
        </p>
      </div>
      <div class="flex items-center space-x-2">
        <button type="button" onclick="retrySystemErrors('retry_se')" class="px-3.5 py-2 rounded-xl bg-amber-500/10 hover:bg-amber-500/20 text-amber-300 border border-amber-500/30 text-xs font-bold flex items-center space-x-1.5 cursor-pointer">
          <i class="fa-solid fa-rotate"></i>
          <span>Chấm lại lỗi SE (<?= $seCount ?>)</span>
        </button>
        <button type="button" onclick="retrySystemErrors('release_stalled')" class="px-3.5 py-2 rounded-xl bg-rose-500/10 hover:bg-rose-500/20 text-rose-300 border border-rose-500/30 text-xs font-bold flex items-center space-x-1.5 cursor-pointer">
          <i class="fa-solid fa-broom"></i>
          <span>Giải phóng treo (<?= $stalledCount ?>)</span>
        </button>
      </div>
    </div>

    <!-- Thông báo phản hồi AJAX -->
    <div id="judging-alert" class="hidden p-4 rounded-xl text-xs font-semibold"></div>

    <!-- 1. GIÁM SÁT SANDBOX & HÀNG ĐỢI THEO NGÔN NGỮ -->
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-5">
      <!-- Trạng thái Trình biên dịch Sandbox -->
      <div class="glass-panel rounded-2xl p-5 border border-white/10 space-y-4">
        <h2 class="text-sm font-bold text-white flex items-center space-x-2">
          <i class="fa-solid fa-microchip text-cyan-400"></i>
          <span>Trạng thái Môi trường Sandbox</span>
        </h2>

        <!-- Python Sandbox -->
        <div class="p-3.5 rounded-xl bg-black/40 border <?= $pyPath ? 'border-emerald-500/30' : 'border-rose-500/40' ?> space-y-1">
          <div class="flex items-center justify-between">
            <span class="text-xs font-bold text-white flex items-center space-x-2">
              <i class="fa-brands fa-python text-amber-400"></i>
              <span>Python Sandbox</span>
            </span>
            <span class="px-2 py-0.5 rounded-full text-[10px] font-bold <?= $pyPath ? 'bg-emerald-500/20 text-emerald-400' : 'bg-rose-500/20 text-rose-400' ?>">
              <?= $pyPath ? 'Sẵn sàng' : 'Mất kết nối' ?>
            </span>
          </div>
          <div class="text-[11px] font-mono text-slate-400 truncate" title="<?= Security::escape((string)$pyPath) ?>">
            <?= Security::escape($pyVer ?: 'Không tìm thấy trình thông dịch Python') ?>
          </div>
        </div>

        <!-- C++ Sandbox -->
        <div class="p-3.5 rounded-xl bg-black/40 border <?= $gppPath ? 'border-emerald-500/30' : 'border-rose-500/40' ?> space-y-1">
          <div class="flex items-center justify-between">
            <span class="text-xs font-bold text-white flex items-center space-x-2">
              <i class="fa-solid fa-code text-blue-400"></i>
              <span>C++ (G++ MinGW) Sandbox</span>
            </span>
            <span class="px-2 py-0.5 rounded-full text-[10px] font-bold <?= $gppPath ? 'bg-emerald-500/20 text-emerald-400' : 'bg-rose-500/20 text-rose-400' ?>">
              <?= $gppPath ? 'Sẵn sàng' : 'Mất kết nối' ?>
            </span>
          </div>
          <div class="text-[11px] font-mono text-slate-400 truncate" title="<?= Security::escape((string)$gppPath) ?>">
            <?= Security::escape($gppVer ?: 'Không tìm thấy trình biên dịch g++') ?>
          </div>
        </div>

        <div class="grid grid-cols-3 gap-2 pt-1 text-center text-xs">
          <div class="p-2 rounded-lg bg-white/5 border border-white/10">
            <div class="text-lg font-black <?= $totalQueued >= 50 ? 'text-rose-400' : 'text-amber-400' ?>"><?= $totalQueued ?></div>
            <div class="text-[10px] text-slate-400">Đang chờ</div>
          </div>
          <div class="p-2 rounded-lg bg-white/5 border border-white/10">
            <div class="text-lg font-black text-cyan-400"><?= $totalRunning ?></div>
            <div class="text-[10px] text-slate-400">Đang chấm</div>
          </div>
          <div class="p-2 rounded-lg bg-white/5 border border-white/10">
            <div class="text-lg font-black <?= $seCount > 0 ? 'text-rose-400' : 'text-emerald-400' ?>"><?= $seCount ?></div>
            <div class="text-[10px] text-slate-400">Lỗi SE</div>
          </div>
        </div>
      </div>

      <!-- Bảng Hàng đợi theo Ngôn ngữ -->
      <div class="glass-panel rounded-2xl p-5 border border-white/10 space-y-4">
        <h2 class="text-sm font-bold text-white flex items-center space-x-2">
          <i class="fa-solid fa-layer-group text-purple-400"></i>
          <span>Hàng đợi Thực thi (Python vs C++)</span>
        </h2>

        <div class="overflow-x-auto">
          <table class="w-full text-xs text-left">
            <thead>
              <tr class="text-[10px] uppercase text-slate-400 border-b border-white/10">
                <th class="py-2">Ngôn ngữ</th>
                <th class="py-2 text-center">Queued</th>
                <th class="py-2 text-center">Running</th>
                <th class="py-2 text-center">Completed</th>
                <th class="py-2 text-center">SE</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-white/5 font-mono">
              <tr>
                <td class="py-3 font-sans font-bold text-amber-300"><i class="fa-brands fa-python mr-1.5"></i>Python</td>
                <td class="py-3 text-center text-amber-400 font-bold"><?= $langStats['python']['Queued'] ?></td>
                <td class="py-3 text-center text-cyan-400 font-bold"><?= $langStats['python']['Running'] ?></td>
                <td class="py-3 text-center text-emerald-400"><?= $langStats['python']['Completed'] ?></td>
                <td class="py-3 text-center text-rose-400"><?= $langStats['python']['SystemError'] ?></td>
              </tr>
              <tr>
                <td class="py-3 font-sans font-bold text-blue-300"><i class="fa-solid fa-c mr-1.5"></i>C++</td>
                <td class="py-3 text-center text-amber-400 font-bold"><?= $langStats['cpp']['Queued'] ?></td>
                <td class="py-3 text-center text-cyan-400 font-bold"><?= $langStats['cpp']['Running'] ?></td>
                <td class="py-3 text-center text-emerald-400"><?= $langStats['cpp']['Completed'] ?></td>
                <td class="py-3 text-center text-rose-400"><?= $langStats['cpp']['SystemError'] ?></td>
              </tr>
            </tbody>
          </table>
        </div>

        <div class="p-3 rounded-xl bg-white/5 border border-white/10 text-[11px] text-slate-400 space-y-1">
          <div class="flex justify-between">
            <span>Lỗi biên dịch (CE):</span>
            <span class="font-mono font-bold text-amber-300"><?= $ceCount ?> lượt</span>
          </div>
          <div class="flex justify-between">
            <span>Lượt bị thay thế (Superseded):</span>
            <span class="font-mono font-bold text-slate-300"><?= $langStats['python']['Superseded'] + $langStats['cpp']['Superseded'] ?> lượt</span>
          </div>
          <div class="flex justify-between">
            <span>Cảnh báo tràn hàng đợi (&ge;50):</span>
            <span class="font-bold <?= $totalQueued >= 50 ? 'text-rose-400' : 'text-emerald-400' ?>"><?= $totalQueued >= 50 ? 'CẢNH BÁO TRÀN' : 'Ổn định' ?></span>
          </div>
        </div>
      </div>

      <!-- 2. ĐIỀU KHIỂN REJUDGE (CHẤM LẠI) -->
      <div class="glass-panel rounded-2xl p-5 border border-cyan-500/20 space-y-4">
        <h2 class="text-sm font-bold text-white flex items-center space-x-2">
          <i class="fa-solid fa-bolt text-cyan-400"></i>
          <span>Kích hoạt Rejudge (Chấm lại)</span>
        </h2>
        <p class="text-[11px] text-slate-400 leading-relaxed">
          Chấm lại toàn bộ bài nộp của một bài tập, một sinh viên cụ thể, hoặc kết hợp cả hai. Hệ thống tự tạo bản ghi <code class="text-cyan-300">judging_runs</code> mới và bảo toàn lịch sử cũ.
        </p>

        <form id="rejudge-form" onsubmit="submitRejudge(event)" class="space-y-3 text-xs">
          <div>
            <label class="block text-[11px] uppercase font-bold text-slate-300 mb-1">Theo Bài tập</label>
            <select id="rj-problem-id" class="w-full glass-input rounded-xl py-2 px-3 bg-slate-900 text-xs">
              <option value="">-- Tất cả Bài tập (nếu chọn Sinh viên) --</option>
              <?php foreach ($problemsList as $pr): ?>
                <option value="<?= (int)$pr['id'] ?>">
                  #<?= (int)$pr['id'] ?>. <?= Security::escape($pr['title']) ?><?= !empty($pr['deleted_at']) ? ' [Đã xóa]' : '' ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div>
            <label class="block text-[11px] uppercase font-bold text-slate-300 mb-1">Theo Sinh viên cụ thể (Tùy chọn)</label>
            <select id="rj-user-id" class="w-full glass-input rounded-xl py-2 px-3 bg-slate-900 text-xs">
              <option value="">-- Toàn bộ Sinh viên --</option>
              <?php foreach ($studentsList as $st): ?>
                <option value="<?= (int)$st['id'] ?>">
                  <?= !empty($st['student_code']) ? '[' . Security::escape($st['student_code']) . '] ' : '' ?><?= Security::escape($st['full_name']) ?> (@<?= Security::escape($st['username']) ?>)
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <button type="submit" id="btn-trigger-rejudge" class="w-full btn-cyan py-2.5 text-xs font-bold flex items-center justify-center space-x-2 cursor-pointer">
            <i class="fa-solid fa-play"></i>
            <span>Kích Hoạt Chấm Lại (Rejudge)</span>
          </button>
        </form>
      </div>
    </div>

    <!-- 3. NHẬT KÝ HÀNG ĐỢI & XỬ LÝ LỖI HỆ THỐNG -->
    <div class="glass-panel rounded-2xl border border-white/10 overflow-hidden shadow-2xl">
      <div class="px-6 py-4 border-b border-white/10 flex flex-col sm:flex-row sm:items-center justify-between gap-3 bg-white/[0.02]">
        <div class="flex items-center space-x-2 flex-wrap gap-y-2">
          <?php
            $tabs = [
              'all'     => ['label' => 'Tất cả lượt chấm', 'icon' => 'fa-list'],
              'queued'  => ['label' => 'Đang chờ / Chạy', 'icon' => 'fa-hourglass-half'],
              'se'      => ['label' => 'Lỗi hệ thống (SE)', 'icon' => 'fa-bug'],
              'ce'      => ['label' => 'Lỗi biên dịch (CE)', 'icon' => 'fa-triangle-exclamation'],
              'rejudge' => ['label' => 'Lượt Rejudge / Retry', 'icon' => 'fa-rotate']
            ];
            foreach ($tabs as $k => $t):
          ?>
            <a href="?filter=<?= $k ?>" class="px-3 py-1.5 rounded-xl text-xs font-bold transition-all flex items-center space-x-1.5 <?= $filter === $k ? 'bg-cyan-500/20 text-cyan-300 border border-cyan-500/40' : 'bg-white/5 hover:bg-white/10 text-slate-400 hover:text-white border border-transparent' ?>">
              <i class="fa-solid <?= $t['icon'] ?>"></i>
              <span><?= $t['label'] ?></span>
            </a>
          <?php endforeach; ?>
        </div>
        <span class="text-xs text-slate-400 font-mono">Hiển thị tối đa 60 bản ghi mới nhất</span>
      </div>

      <div class="overflow-x-auto">
        <table class="w-full text-left border-collapse text-xs">
          <thead>
            <tr class="bg-white/5 text-[10px] uppercase tracking-wider text-slate-400 font-bold border-b border-white/10">
              <th class="py-3 px-4">Run / Sub</th>
              <th class="py-3 px-4">Sinh viên</th>
              <th class="py-3 px-4">Bài tập (Ver)</th>
              <th class="py-3 px-4 text-center">Ngôn ngữ</th>
              <th class="py-3 px-4 text-center">Nguồn</th>
              <th class="py-3 px-4 text-center">Trạng thái</th>
              <th class="py-3 px-4 text-center">Phán quyết</th>
              <th class="py-3 px-4">Chi tiết lỗi (CE / SE Log)</th>
              <th class="py-3 px-4 text-right">Thao tác</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-white/5">
            <?php if (empty($runs)): ?>
              <tr>
                <td colspan="9" class="py-10 text-center text-slate-500">
                  Không có bản ghi lượt chấm nào trong mục này.
                </td>
              </tr>
            <?php else: ?>
              <?php foreach ($runs as $r): ?>
                <?php
                  $v = $r['verdict'];
                  $vClass = match($v) {
                    'AC' => 'badge-ac', 'WA' => 'badge-wa', 'TLE' => 'badge-tle',
                    'RE' => 'badge-re', 'CE' => 'badge-ce', 'SE' => 'badge-se',
                    default => 'badge-pending'
                  };
                  $errLog = trim((string)($r['system_error_log'] ?: $r['compiler_message'] ?: ''));
                ?>
                <tr class="hover:bg-white/[0.02] transition-colors">
                  <td class="py-3 px-4 font-mono">
                    <div class="font-bold text-white">Run #<?= (int)$r['id'] ?> (Lần <?= (int)$r['run_number'] ?>)</div>
                    <div class="text-[10px] text-slate-400">Sub #<?= (int)$r['submission_id'] ?></div>
                  </td>
                  <td class="py-3 px-4">
                    <div class="font-semibold text-slate-200"><?= Security::escape($r['full_name']) ?></div>
                    <div class="text-[10px] text-slate-400 font-mono">
                      <?= !empty($r['student_code']) ? Security::escape($r['student_code']) . ' · ' : '' ?>@<?= Security::escape($r['username']) ?>
                    </div>
                  </td>
                  <td class="py-3 px-4">
                    <div class="font-semibold text-white truncate max-w-[180px]"><?= Security::escape($r['problem_title']) ?></div>
                    <span class="text-[10px] font-mono text-cyan-400">v<?= (int)$r['version_number'] ?></span>
                  </td>
                  <td class="py-3 px-4 text-center font-mono uppercase font-bold <?= $r['language'] === 'python' ? 'text-amber-300' : 'text-blue-300' ?>">
                    <?= Security::escape($r['language']) ?>
                  </td>
                  <td class="py-3 px-4 text-center">
                    <span class="px-2 py-0.5 rounded text-[10px] font-mono <?= $r['triggered_by'] === 'submit' ? 'bg-white/5 text-slate-300' : 'bg-purple-500/20 text-purple-300 border border-purple-500/30' ?>">
                      <?= Security::escape($r['triggered_by']) ?>
                    </span>
                  </td>
                  <td class="py-3 px-4 text-center font-mono text-[11px] <?= $r['status'] === 'SystemError' ? 'text-rose-400 font-bold' : ($r['status'] === 'Queued' ? 'text-amber-300' : 'text-slate-300') ?>">
                    <?= Security::escape($r['status']) ?>
                  </td>
                  <td class="py-3 px-4 text-center">
                    <span class="px-2 py-0.5 rounded text-[10px] font-bold <?= $vClass ?>"><?= Security::escape($v) ?></span>
                    <div class="text-[10px] text-slate-400 font-mono mt-0.5"><?= (int)$r['passed_cases'] ?>/<?= (int)$r['total_cases'] ?> · <?= (int)$r['max_time_ms'] ?>ms</div>
                  </td>
                  <td class="py-3 px-4 max-w-[240px]">
                    <?php if ($errLog !== ''): ?>
                      <div class="font-mono text-[10px] text-rose-300 bg-rose-500/10 border border-rose-500/20 rounded p-1.5 truncate" title="<?= Security::escape($errLog) ?>">
                        <?= Security::escape(mb_substr($errLog, 0, 110)) ?>
                      </div>
                    <?php else: ?>
                      <span class="text-slate-600 text-[11px]">—</span>
                    <?php endif; ?>
                  </td>
                  <td class="py-3 px-4 text-right">
                    <button type="button" onclick="rejudgeSingle(<?= (int)$r['submission_id'] ?>)" class="px-2.5 py-1 rounded-lg bg-cyan-500/10 hover:bg-cyan-500/20 text-cyan-300 border border-cyan-500/30 text-[11px] font-semibold cursor-pointer" title="Chấm lại bài nộp này">
                      <i class="fa-solid fa-rotate-right mr-1"></i>Rejudge
                    </button>
                  </td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </main>
</div>

<script>
function showJudgingAlert(msg, isSuccess = true) {
  const el = document.getElementById('judging-alert');
  el.classList.remove('hidden', 'bg-emerald-500/10', 'border-emerald-500/30', 'text-emerald-300', 'bg-rose-500/10', 'border-rose-500/30', 'text-rose-300');
  if (isSuccess) {
    el.classList.add('bg-emerald-500/10', 'border', 'border-emerald-500/30', 'text-emerald-300');
  } else {
    el.classList.add('bg-rose-500/10', 'border', 'border-rose-500/30', 'text-rose-300');
  }
  el.innerHTML = `<i class="fa-solid ${isSuccess ? 'fa-circle-check' : 'fa-triangle-exclamation'} mr-2"></i>${msg}`;
}

async function submitRejudge(e) {
  e.preventDefault();
  const problemId = document.getElementById('rj-problem-id').value;
  const userId = document.getElementById('rj-user-id').value;
  if (!problemId && !userId) {
    showJudgingAlert('Vui lòng chọn ít nhất một Bài tập hoặc một Sinh viên cụ thể.', false);
    return;
  }

  const btn = document.getElementById('btn-trigger-rejudge');
  btn.disabled = true;
  btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i><span>Đang tạo hàng đợi Rejudge...</span>';

  try {
    const res = await fetch('/BaiTapLon/api/admin_judging.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ action: 'rejudge', problem_id: problemId, user_id: userId })
    });
    const data = await res.json();
    if (!res.ok || !data.success) throw new Error(data.error || 'Lỗi kích hoạt Rejudge.');
    showJudgingAlert(data.message, true);
    setTimeout(() => window.location.reload(), 900);
  } catch (err) {
    showJudgingAlert(err.message, false);
    btn.disabled = false;
    btn.innerHTML = '<i class="fa-solid fa-play"></i><span>Kích Hoạt Chấm Lại (Rejudge)</span>';
  }
}

async function rejudgeSingle(submissionId) {
  if (!confirm(`Kích hoạt chấm lại (Rejudge) cho bài nộp #${submissionId}?`)) return;
  try {
    const res = await fetch('/BaiTapLon/api/admin_judging.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ action: 'rejudge', submission_id: submissionId })
    });
    const data = await res.json();
    if (!res.ok || !data.success) throw new Error(data.error || 'Không thể Rejudge bài nộp.');
    showJudgingAlert(data.message, true);
    setTimeout(() => window.location.reload(), 800);
  } catch (err) {
    showJudgingAlert(err.message, false);
  }
}

async function retrySystemErrors(actionType) {
  const label = actionType === 'release_stalled' ? 'giải phóng các tiến trình treo (>60s)' : 'chấm lại toàn bộ các bài bị lỗi hệ thống (SE)';
  if (!confirm(`Bạn có chắc muốn ${label}?`)) return;
  try {
    const res = await fetch('/BaiTapLon/api/admin_judging.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ action: actionType })
    });
    const data = await res.json();
    if (!res.ok || !data.success) throw new Error(data.error || 'Lỗi xử lý.');
    showJudgingAlert(data.message, true);
    setTimeout(() => window.location.reload(), 800);
  } catch (err) {
    showJudgingAlert(err.message, false);
  }
}
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
