<?php
/**
 * ByteFlow Online Judge - Tra cứu Lịch sử Nộp bài & Tiến độ
 * Lọc theo Bài tập, Ngôn ngữ, Kết quả, Lớp học và Modal xem chi tiết từng lần chấm
 */

declare(strict_types=1);

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/core/Auth.php';
require_once __DIR__ . '/core/Security.php';

Auth::startSession();
$currentUser = Auth::user();
$pdo = getDbConnection();

// Lọc tham số
$problemFilter = isset($_GET['problem_id']) ? (int)$_GET['problem_id'] : 0;
$languageFilter = trim((string)($_GET['language'] ?? ''));
$verdictFilter  = trim((string)($_GET['verdict'] ?? ''));
$userFilter     = trim((string)($_GET['username'] ?? ''));

$whereClauses = ["1=1"];
$params = [];

// Phân quyền hiển thị danh sách bài nộp:
// - Học viên: Chỉ xem bài nộp của chính mình
// - Giảng viên: Xem bài nộp của các lớp mình dạy hoặc các bài tập do mình tạo
// - Admin: Xem toàn bộ
if (!Auth::isAdmin()) {
    if (Auth::isInstructor()) {
        $whereClauses[] = "(
            p.created_by = :inst_uid OR 
            s.classroom_id IN (SELECT id FROM classrooms WHERE instructor_id = :inst_uid2) OR
            s.user_id = :inst_uid3
        )";
        $params['inst_uid']  = Auth::id();
        $params['inst_uid2'] = Auth::id();
        $params['inst_uid3'] = Auth::id();
    } elseif ($currentUser) {
        $whereClauses[] = "s.user_id = :learner_uid";
        $params['learner_uid'] = $currentUser['id'];
    } else {
        // Chưa đăng nhập thì không xem được danh sách bài nộp riêng tư
        header('Location: /BaiTapLon/login.php');
        exit;
    }
}

if ($problemFilter > 0) {
    $whereClauses[] = "s.problem_id = :pid";
    $params['pid'] = $problemFilter;
}

if ($languageFilter !== '') {
    $whereClauses[] = "s.language = :lang";
    $params['lang'] = $languageFilter;
}

if ($verdictFilter !== '') {
    $whereClauses[] = "s.final_verdict = :verdict";
    $params['verdict'] = $verdictFilter;
}

if ($userFilter !== '' && (Auth::isAdmin() || Auth::isInstructor())) {
    $whereClauses[] = "u.username LIKE :uname";
    $params['uname'] = "%{$userFilter}%";
}

$whereSql = implode(' AND ', $whereClauses);

$subStmt = $pdo->prepare("
    SELECT s.id, s.user_id, s.classroom_id, s.problem_id, s.problem_version_id,
           s.language, s.final_verdict, s.pass_count, s.total_cases,
           s.execution_time_ms, s.memory_used_kb, s.submitted_at, s.latest_run_id,
           u.username, u.full_name,
           p.title as problem_title, p.slug as problem_slug, pv.version_number
    FROM submissions s
    JOIN users u ON s.user_id = u.id
    JOIN problems p ON s.problem_id = p.id
    JOIN problem_versions pv ON s.problem_version_id = pv.id
    WHERE {$whereSql}
    ORDER BY s.id DESC
    LIMIT 100
");
$subStmt->execute($params);
$submissions = $subStmt->fetchAll();

// Danh sách bài tập cho bộ lọc
$allProblems = $pdo->query("SELECT id, title FROM problems ORDER BY id ASC")->fetchAll();

$pageTitle = 'Lịch sử Nộp bài';
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/sidebar.php';
?>

<div class="pl-64 flex-1 flex flex-col min-h-screen">
  <main class="p-8 max-w-7xl w-full mx-auto space-y-6">

    <!-- Tiêu đề trang -->
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
      <div>
        <h1 class="text-2xl font-black text-white flex items-center space-x-2">
          <i class="fa-solid fa-clock-rotate-left text-cyan-400"></i>
          <span>Lịch sử Nộp bài & Tiến độ</span>
        </h1>
        <p class="text-xs text-slate-400 mt-1">
          Theo dõi trạng thái từng bài nộp, số ca kiểm thử đạt và lịch sử các lần chấm (Rejudge).
        </p>
      </div>

      <div class="text-xs text-slate-400 font-mono">
        Hiển thị <span class="text-cyan-400 font-bold"><?= count($submissions) ?></span> bài nộp
      </div>
    </div>

    <!-- Thanh Bộ lọc -->
    <form action="" method="GET" class="glass-panel rounded-2xl p-4 border border-white/10 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3 text-xs">
      <!-- Lọc Bài tập -->
      <div>
        <select name="problem_id" onchange="this.form.submit()" class="w-full glass-input rounded-xl py-2 px-3 bg-slate-900">
          <option value="">-- Tất cả Bài tập --</option>
          <?php foreach ($allProblems as $p): ?>
            <option value="<?= $p['id'] ?>" <?= $problemFilter === (int)$p['id'] ? 'selected' : '' ?>>
              #<?= $p['id'] ?>. <?= Security::escape($p['title']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <!-- Lọc Ngôn ngữ -->
      <div>
        <select name="language" onchange="this.form.submit()" class="w-full glass-input rounded-xl py-2 px-3 bg-slate-900">
          <option value="">-- Tất cả Ngôn ngữ --</option>
          <option value="python" <?= $languageFilter === 'python' ? 'selected' : '' ?>>Python</option>
          <option value="cpp" <?= $languageFilter === 'cpp' ? 'selected' : '' ?>>C++</option>
        </select>
      </div>

      <!-- Lọc Phán quyết -->
      <div>
        <select name="verdict" onchange="this.form.submit()" class="w-full glass-input rounded-xl py-2 px-3 bg-slate-900">
          <option value="">-- Tất cả Kết quả --</option>
          <option value="AC" <?= $verdictFilter === 'AC' ? 'selected' : '' ?>>AC (Accepted - Đúng)</option>
          <option value="WA" <?= $verdictFilter === 'WA' ? 'selected' : '' ?>>WA (Wrong Answer - Sai kết quả)</option>
          <option value="TLE" <?= $verdictFilter === 'TLE' ? 'selected' : '' ?>>TLE (Time Limit - Quá giờ)</option>
          <option value="RE" <?= $verdictFilter === 'RE' ? 'selected' : '' ?>>RE (Runtime Error - Lỗi chạy)</option>
          <option value="CE" <?= $verdictFilter === 'CE' ? 'selected' : '' ?>>CE (Compile Error - Lỗi cú pháp)</option>
          <option value="SE" <?= $verdictFilter === 'SE' ? 'selected' : '' ?>>SE (System Error - Lỗi hệ thống)</option>
        </select>
      </div>

      <!-- Nút Đặt lại -->
      <div class="flex items-center space-x-2">
        <?php if (Auth::isAdmin() || Auth::isInstructor()): ?>
          <input type="text" name="username" value="<?= Security::escape($userFilter) ?>" 
            placeholder="Lọc theo username học viên..." 
            class="w-full glass-input rounded-xl py-2 px-3 text-xs placeholder-slate-500">
        <?php endif; ?>
        <a href="/BaiTapLon/submissions.php" class="btn-glass text-xs py-2 px-3 shrink-0" title="Đặt lại bộ lọc">
          <i class="fa-solid fa-rotate-left"></i>
        </a>
      </div>
    </form>

    <!-- Bảng Danh sách Bài nộp -->
    <div class="glass-panel rounded-2xl overflow-hidden border border-white/10 shadow-xl">
      <div class="overflow-x-auto">
        <table class="w-full text-left text-xs border-collapse">
          <thead>
            <tr class="border-b border-white/10 bg-white/[0.02] text-slate-400 uppercase font-semibold tracking-wider text-[11px]">
              <th class="py-3.5 px-4 w-16 text-center">ID</th>
              <th class="py-3.5 px-4">Bài tập</th>
              <th class="py-3.5 px-4 w-36">Người nộp</th>
              <th class="py-3.5 px-4 w-24 text-center">Ngôn ngữ</th>
              <th class="py-3.5 px-4 w-28 text-center">Kết quả</th>
              <th class="py-3.5 px-4 w-28 text-center">Đạt test</th>
              <th class="py-3.5 px-4 w-28 text-center">Thời gian</th>
              <th class="py-3.5 px-4 w-36">Thời điểm nộp</th>
              <th class="py-3.5 px-4 w-24 text-center">Thao tác</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-white/5 font-mono">
            <?php if (empty($submissions)): ?>
              <tr>
                <td colspan="9" class="py-12 text-center text-slate-500 text-xs font-sans">
                  Không tìm thấy bài nộp nào.
                </td>
              </tr>
            <?php else: ?>
              <?php foreach ($submissions as $s): ?>
                <?php
                  $v = $s['final_verdict'];
                  $badgeClass = match($v) {
                    'AC'  => 'badge-ac',
                    'WA'  => 'badge-wa',
                    'TLE' => 'badge-tle',
                    'RE'  => 'badge-re',
                    'CE'  => 'badge-ce',
                    'SE'  => 'badge-se',
                    default => 'badge-pending'
                  };
                ?>
                <tr class="hover:bg-white/[0.03] transition-colors">
                  <!-- ID -->
                  <td class="py-3.5 px-4 text-center text-slate-400 font-bold">
                    #<?= $s['id'] ?>
                  </td>

                  <!-- Bài tập -->
                  <td class="py-3.5 px-4 font-sans">
                    <a href="/BaiTapLon/solve.php?id=<?= $s['problem_id'] ?>" class="font-bold text-white hover:text-cyan-400 transition-colors">
                      <?= Security::escape($s['problem_title']) ?>
                    </a>
                    <span class="ml-1 px-1.5 py-0.2 rounded text-[10px] font-mono bg-white/5 text-cyan-300">
                      v<?= $s['version_number'] ?>.0
                    </span>
                  </td>

                  <!-- Người nộp -->
                  <td class="py-3.5 px-4 font-sans text-slate-300">
                    <span class="font-medium text-slate-200"><?= Security::escape($s['full_name']) ?></span>
                    <span class="block text-[11px] text-slate-500 font-mono">@<?= Security::escape($s['username']) ?></span>
                  </td>

                  <!-- Ngôn ngữ -->
                  <td class="py-3.5 px-4 text-center uppercase text-cyan-400 font-bold">
                    <?= $s['language'] ?>
                  </td>

                  <!-- Kết quả phán quyết -->
                  <td class="py-3.5 px-4 text-center">
                    <span class="px-2.5 py-0.5 rounded text-[11px] font-bold <?= $badgeClass ?>">
                      <?= $v ?>
                    </span>
                  </td>

                  <!-- Đạt test -->
                  <td class="py-3.5 px-4 text-center text-slate-300">
                    <?= $s['pass_count'] ?> / <?= $s['total_cases'] ?>
                  </td>

                  <!-- Thời gian chạy -->
                  <td class="py-3.5 px-4 text-center text-slate-400">
                    <?= $s['execution_time_ms'] ?> ms
                  </td>

                  <!-- Thời điểm nộp -->
                  <td class="py-3.5 px-4 text-slate-500 text-[11px] font-sans">
                    <?= $s['submitted_at'] ?>
                  </td>

                  <!-- Nút xem chi tiết -->
                  <td class="py-3.5 px-4 text-center font-sans">
                    <button type="button" onclick="openSubmissionModal(<?= $s['id'] ?>)" 
                      class="btn-glass text-[11px] py-1 px-2.5 inline-flex items-center space-x-1 hover:border-cyan-400 hover:text-cyan-300">
                      <i class="fa-solid fa-eye text-[10px]"></i>
                      <span>Chi tiết</span>
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
  <?php require_once __DIR__ . '/includes/footer.php'; ?>
</div>

<!-- ==================== MODAL CHI TIẾT BÀI NỘP & LỊCH SỬ CHẤM ==================== -->
<div id="sub-modal" class="fixed inset-0 z-50 bg-black/80 backdrop-blur-md hidden items-center justify-center p-4">
  <div class="glass-panel w-full max-w-4xl max-h-[90vh] rounded-3xl border border-white/15 flex flex-col shadow-2xl overflow-hidden animate-in fade-in zoom-in duration-200">
    
    <!-- Modal Header -->
    <div class="px-6 py-4 border-b border-white/10 flex items-center justify-between bg-white/[0.02]">
      <div class="flex items-center space-x-3">
        <span class="w-8 h-8 rounded-lg bg-cyan-500/20 text-cyan-400 flex items-center justify-center font-black font-mono text-sm">
          #<span id="modal-sub-id">--</span>
        </span>
        <div>
          <h2 class="text-base font-bold text-white" id="modal-sub-title">Chi tiết bài nộp</h2>
          <div class="text-xs text-slate-400 font-mono" id="modal-sub-meta">Đang tải...</div>
        </div>
      </div>

      <button type="button" onclick="closeSubmissionModal()" class="text-slate-400 hover:text-white p-2">
        <i class="fa-solid fa-xmark text-lg"></i>
      </button>
    </div>

    <!-- Modal Body (Scrollable) -->
    <div class="flex-1 overflow-y-auto p-6 space-y-6 text-xs" id="modal-sub-body">
      
      <!-- Mã nguồn bài nộp -->
      <div class="space-y-2">
        <div class="flex items-center justify-between">
          <span class="font-bold text-slate-300 uppercase tracking-wider text-[11px]">Mã nguồn đã nộp</span>
          <button type="button" onclick="copySourceCode()" class="text-[11px] text-cyan-400 hover:underline flex items-center space-x-1">
            <i class="fa-regular fa-copy"></i>
            <span>Sao chép code</span>
          </button>
        </div>
        <pre id="modal-source-code" class="p-4 rounded-xl bg-black/70 border border-white/10 font-mono text-slate-200 overflow-x-auto max-h-56 whitespace-pre-wrap"></pre>
      </div>

      <!-- Lịch sử từng lần chấm (Runs & Rejudge history) -->
      <div class="space-y-3">
        <div class="font-bold text-slate-300 uppercase tracking-wider text-[11px]">
          Lịch sử các lần chấm (Runs & Rejudge History)
        </div>
        <div id="modal-runs-list" class="space-y-4">
          <!-- Điền động qua JavaScript -->
        </div>
      </div>

    </div>

  </div>
</div>

<script>
  let modalCode = "";

  async function openSubmissionModal(subId) {
    const modal = document.getElementById('sub-modal');
    modal.classList.remove('hidden');
    modal.classList.add('flex');

    document.getElementById('modal-sub-id').innerText = subId;
    document.getElementById('modal-sub-title').innerText = 'Đang nạp chi tiết bài nộp...';
    document.getElementById('modal-source-code').innerText = '// Đang tải...';
    document.getElementById('modal-runs-list').innerHTML = '<div class="text-center py-6 text-slate-500"><i class="fa-solid fa-spinner fa-spin mr-1"></i>Đang tải dữ liệu chấm...</div>';

    try {
      const res = await fetch(`/BaiTapLon/api/submission_status.php?id=${subId}`);
      if (!res.ok) {
        document.getElementById('modal-sub-title').innerText = 'Lỗi truy cập';
        document.getElementById('modal-runs-list').innerHTML = `<div class="p-4 rounded-xl bg-rose-500/10 text-rose-300 border border-rose-500/30">Không thể xem chi tiết bài nộp (HTTP ${res.status}).</div>`;
        return;
      }

      const data = await res.json();
      const sub = data.submission;
      const runs = data.runs;

      document.getElementById('modal-sub-title').innerText = sub.problem_title;
      document.getElementById('modal-sub-meta').innerText = `Người nộp: ${sub.author_name} (@${sub.author_username}) • Ngôn ngữ: ${sub.language.toUpperCase()} • Nộp lúc: ${sub.submitted_at}`;
      
      modalCode = sub.source_code;
      document.getElementById('modal-source-code').innerText = sub.source_code;

      // Render từng lần chấm (Run #1, Run #2...)
      const runsList = document.getElementById('modal-runs-list');
      runsList.innerHTML = '';

      runs.forEach(r => {
        const runCard = document.createElement('div');
        runCard.className = 'rounded-2xl border border-white/10 bg-white/[0.02] p-4 space-y-3';

        const badgeClass = getBadgeClass(r.verdict);
        
        let headerHtml = `
          <div class="flex items-center justify-between pb-2 border-b border-white/5 font-mono">
            <div class="flex items-center space-x-2">
              <span class="font-bold text-white text-sm">Lần chấm #${r.run_number}</span>
              <span class="text-slate-500">(Run ID: #${r.id})</span>
              <span class="px-2 py-0.5 rounded text-[10px] font-bold ${badgeClass}">${r.verdict}</span>
              <span class="text-slate-400 text-xs">${r.passed_cases}/${r.total_cases} tests</span>
            </div>
            <div class="text-slate-400 text-[11px]">
              Bộ dữ liệu: v${r.version_number}.0 • ${r.max_time_ms}ms • Kích hoạt: ${r.triggered_by}
            </div>
          </div>
        `;

        // Nếu có lỗi biên dịch
        if (r.compiler_message) {
          headerHtml += `
            <div class="p-3 rounded-xl bg-rose-950/40 border border-rose-500/30 text-rose-300 font-mono text-[11px] whitespace-pre-wrap">
              <strong>[Lỗi biên dịch / cú pháp]:</strong>\n${escapeHtml(r.compiler_message)}
            </div>
          `;
        }

        // Bảng kết quả từng ca kiểm thử
        let casesHtml = '';
        if (r.case_results && r.case_results.length > 0) {
          casesHtml = `
            <div class="overflow-x-auto">
              <table class="w-full text-left text-[11px] font-mono border-collapse">
                <thead>
                  <tr class="text-slate-500 border-b border-white/5 uppercase">
                    <th class="py-1 px-2 w-12 text-center">Case</th>
                    <th class="py-1 px-2 w-16 text-center">Kết quả</th>
                    <th class="py-1 px-2 w-20 text-center">Thời gian</th>
                    <th class="py-1 px-2">Đầu vào (stdin)</th>
                    <th class="py-1 px-2">Kỳ vọng (stdout)</th>
                    <th class="py-1 px-2">Thực tế</th>
                  </tr>
                </thead>
                <tbody class="divide-y divide-white/5 text-slate-300">
          `;

          r.case_results.forEach(c => {
            const cBadge = getBadgeClass(c.verdict);
            casesHtml += `
              <tr class="hover:bg-white/[0.02]">
                <td class="py-1.5 px-2 text-center font-bold">#${c.case_order}</td>
                <td class="py-1.5 px-2 text-center"><span class="px-1.5 py-0.2 rounded text-[10px] font-bold ${cBadge}">${c.verdict}</span></td>
                <td class="py-1.5 px-2 text-center text-slate-400">${c.execution_time_ms}ms</td>
                <td class="py-1.5 px-2 font-mono ${c.is_hidden ? 'text-amber-400/80 italic' : ''}">${escapeHtml(c.input_data || '')}</td>
                <td class="py-1.5 px-2 font-mono ${c.is_hidden ? 'text-amber-400/80 italic' : 'text-emerald-400'}">${escapeHtml(c.expected_output || '')}</td>
                <td class="py-1.5 px-2 font-mono ${c.is_hidden ? 'text-amber-400/80 italic' : ''}">${escapeHtml(c.actual_output || '')}</td>
              </tr>
            `;
          });

          casesHtml += `</tbody></table></div>`;
        }

        runCard.innerHTML = headerHtml + casesHtml;
        runsList.appendChild(runCard);
      });

    } catch (e) {
      document.getElementById('modal-runs-list').innerHTML = `<div class="p-4 rounded-xl bg-rose-500/10 text-rose-300">Lỗi kết nối: ${e.message}</div>`;
    }
  }

  function closeSubmissionModal() {
    const modal = document.getElementById('sub-modal');
    modal.classList.add('hidden');
    modal.classList.remove('flex');
  }

  function copySourceCode() {
    if (modalCode) {
      navigator.clipboard.writeText(modalCode).then(() => {
        showToast('Đã sao chép mã nguồn!', 'success');
      });
    }
  }

  function getBadgeClass(v) {
    switch (v) {
      case 'AC': return 'badge-ac';
      case 'WA': return 'badge-wa';
      case 'TLE': return 'badge-tle';
      case 'RE': return 'badge-re';
      case 'CE': return 'badge-ce';
      case 'SE': return 'badge-se';
      default: return 'badge-pending';
    }
  }

  function escapeHtml(text) {
    if (!text) return '';
    return text.replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;");
  }
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
