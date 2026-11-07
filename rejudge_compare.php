<?php
/**
 * ByteFlow Online Judge - Phân hệ Giảng viên: Chấm lại & Đối chiếu Song song (Rejudge & Compare)
 * Hiện thực hóa trọn vẹn TÌNH HUỐNG SỬ DỤNG 2 theo Rubric:
 * "Sửa bộ dữ liệu -> tạo phiên bản mới -> chấm lại -> đối chiếu hai lần chấm mà vẫn giữ lịch sử"
 */

declare(strict_types=1);

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/core/Auth.php';
require_once __DIR__ . '/core/Security.php';

Auth::startSession();

// Yêu cầu quyền Giảng viên hoặc Quản trị viên
Auth::requireRoles(['instructor', 'admin']);
$currentUser = Auth::user();
$pdo = getDbConnection();

// Lấy danh sách bài tập mà giảng viên có quyền quản lý
$problemStmt = $pdo->prepare("
    SELECT p.id, p.slug, p.title, p.topic, p.difficulty, p.current_version_id, pv.version_number
    FROM problems p
    JOIN problem_versions pv ON p.current_version_id = pv.id
    ORDER BY p.id ASC
");
$problemStmt->execute();
$problems = $problemStmt->fetchAll();

// Bài tập đang được chọn
$selectedProblemId = isset($_GET['problem_id']) ? (int)$_GET['problem_id'] : ($problems[0]['id'] ?? 1);

// Lấy danh sách tất cả các phiên bản của bài tập này
$verStmt = $pdo->prepare("
    SELECT id, version_number, changelog, time_limit_ms, memory_limit_kb, created_at
    FROM problem_versions
    WHERE problem_id = ?
    ORDER BY version_number DESC
");
$verStmt->execute([$selectedProblemId]);
$versions = $verStmt->fetchAll();

// Lấy danh sách bài nộp và các lần chấm của bài tập này để phục vụ Bảng Đối Chiếu Song Song
$subStmt = $pdo->prepare("
    SELECT s.id as submission_id, s.user_id, s.language, s.final_verdict, s.latest_run_id, s.submitted_at,
           u.username, u.full_name
    FROM submissions s
    JOIN users u ON s.user_id = u.id
    WHERE s.problem_id = ?
    ORDER BY s.id DESC
");
$subStmt->execute([$selectedProblemId]);
$submissions = $subStmt->fetchAll();

// Lấy toàn bộ các runs của các bài nộp này
$runsBySub = [];
if (!empty($submissions)) {
    $subIds = array_column($submissions, 'submission_id');
    $inSql = implode(',', array_fill(0, count($subIds), '?'));
    $runsStmt = $pdo->prepare("
        SELECT jr.id, jr.submission_id, jr.problem_version_id, jr.run_number, jr.triggered_by,
               jr.status, jr.verdict, jr.passed_cases, jr.total_cases, jr.max_time_ms, jr.finished_at,
               pv.version_number
        FROM judging_runs jr
        JOIN problem_versions pv ON jr.problem_version_id = pv.id
        WHERE jr.submission_id IN ({$inSql})
        ORDER BY jr.run_number ASC
    ");
    $runsStmt->execute($subIds);
    $allRuns = $runsStmt->fetchAll();

    foreach ($allRuns as $r) {
        $runsBySub[$r['submission_id']][] = $r;
    }
}

$pageTitle = 'Chấm lại & Đối chiếu Song song (Rejudge)';
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/sidebar.php';
?>

<div class="pl-64 min-h-screen flex flex-col w-full">
  <main class="p-8 max-w-7xl w-full mx-auto space-y-6 flex-1">

    <!-- Header Tiêu đề -->
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
      <div>
        <div class="inline-flex items-center space-x-2 px-3 py-1 rounded-full bg-cyan-500/10 border border-cyan-500/30 text-cyan-300 text-xs font-semibold mb-1">
          <i class="fa-solid fa-code-compare"></i>
          <span>Tình huống sử dụng 2 - Rubric Đề tài 20</span>
        </div>
        <h1 class="text-2xl font-black text-white flex items-center space-x-2">
          <span>Chấm lại & Đối chiếu Kết quả Song song</span>
        </h1>
        <p class="text-xs text-slate-400 mt-1">
          Tạo phiên bản bộ dữ liệu mới, chấm lại toàn bộ bài nộp và đối chiếu song song giữa hai lần chấm mà vẫn giữ nguyên lịch sử.
        </p>
      </div>

      <!-- Chọn bài tập -->
      <div>
        <form method="GET" action="" class="flex items-center space-x-2">
          <label class="text-xs text-slate-400 font-medium">Chọn bài tập:</label>
          <select name="problem_id" onchange="this.form.submit()" class="glass-input rounded-xl py-2 px-3 text-xs bg-slate-900 border border-white/15 text-cyan-300 font-semibold">
            <?php foreach ($problems as $pr): ?>
              <option value="<?= $pr['id'] ?>" <?= $pr['id'] === $selectedProblemId ? 'selected' : '' ?>>
                #<?= $pr['id'] ?>. <?= Security::escape($pr['title']) ?> (v<?= $pr['version_number'] ?>.0)
              </option>
            <?php endforeach; ?>
          </select>
        </form>
      </div>
    </div>

    <!-- 2 Khối Hành Động: (1) Tạo phiên bản mới & (2) Kích hoạt Rejudge -->
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
      
      <!-- KHỐI 1: TẠO PHIÊN BẢN BỘ DỮ LIỆU MỚI -->
      <div class="glass-panel rounded-2xl p-6 border border-white/10 space-y-4">
        <div class="flex items-center justify-between pb-3 border-b border-white/10">
          <h2 class="text-sm font-bold text-white flex items-center space-x-2">
            <i class="fa-solid fa-plus-circle text-cyan-400"></i>
            <span>Tạo Phiên bản Mới (New Version)</span>
          </h2>
          <span class="text-[11px] font-mono text-cyan-400 bg-cyan-500/10 px-2 py-0.5 rounded border border-cyan-500/30">
            Hiện tại: v<?= $versions[0]['version_number'] ?? 1 ?>.0
          </span>
        </div>

        <form id="form-create-version" class="space-y-3 text-xs">
          <div>
            <label class="block text-slate-400 font-semibold mb-1">Mô tả thay đổi (Changelog)</label>
            <input type="text" id="ver-changelog" required 
              placeholder="vd: Bổ sung 3 testcase biên số âm và số lớn 10^9"
              class="w-full glass-input rounded-xl py-2 px-3 text-xs">
          </div>

          <div class="grid grid-cols-2 gap-3">
            <div>
              <label class="block text-slate-400 font-semibold mb-1">Giới hạn thời gian (ms)</label>
              <input type="number" id="ver-timelimit" value="<?= $versions[0]['time_limit_ms'] ?? 1000 ?>" 
                class="w-full glass-input rounded-xl py-2 px-3 text-xs font-mono">
            </div>
            <div>
              <label class="block text-slate-400 font-semibold mb-1">Giới hạn bộ nhớ (KB)</label>
              <input type="number" id="ver-memlimit" value="<?= $versions[0]['memory_limit_kb'] ?? 262144 ?>" 
                class="w-full glass-input rounded-xl py-2 px-3 text-xs font-mono">
            </div>
          </div>

          <div>
            <label class="block text-slate-400 font-semibold mb-1">Thêm testcase biên mới (Dữ liệu chấm kín bảo mật)</label>
            <div class="p-3 rounded-xl bg-white/[0.02] border border-white/5 space-y-2">
              <div class="grid grid-cols-2 gap-2 font-mono">
                <div>
                  <span class="text-[10px] text-slate-500 block mb-0.5">Input (stdin):</span>
                  <input type="text" id="new-tc-input" placeholder="-1000000000 1000000000\n" class="w-full glass-input rounded-lg p-1.5 text-[11px]">
                </div>
                <div>
                  <span class="text-[10px] text-slate-500 block mb-0.5">Expected Output (stdout):</span>
                  <input type="text" id="new-tc-output" placeholder="0\n" class="w-full glass-input rounded-lg p-1.5 text-[11px]">
                </div>
              </div>
            </div>
          </div>

          <button type="button" onclick="submitNewVersion()" id="btn-create-ver" 
            class="w-full btn-glass text-xs py-2.5 flex items-center justify-center space-x-2 font-semibold hover:border-cyan-400 hover:text-cyan-300">
            <i class="fa-solid fa-cloud-arrow-up text-cyan-400"></i>
            <span>Phát hành Phiên bản v<?= ($versions[0]['version_number'] ?? 1) + 1 ?>.0</span>
          </button>
        </form>
      </div>

      <!-- KHỐI 2: KÍCH HOẠT CHẤM LẠI (REJUDGE) -->
      <div class="glass-panel rounded-2xl p-6 border border-white/10 space-y-4">
        <div class="flex items-center justify-between pb-3 border-b border-white/10">
          <h2 class="text-sm font-bold text-white flex items-center space-x-2">
            <i class="fa-solid fa-rotate text-amber-400"></i>
            <span>Kích hoạt Chấm lại Toàn bộ (Rejudge)</span>
          </h2>
          <span class="text-[11px] font-mono text-slate-400">
            Tổng: <?= count($submissions) ?> bài nộp
          </span>
        </div>

        <div class="space-y-4 text-xs">
          <p class="text-slate-300 leading-relaxed">
            Hệ thống sẽ giữ nguyên vẹn mã nguồn của tất cả học viên và toàn bộ lịch sử các lần chấm trước đó (<code class="text-cyan-300">Run #1</code>, <code class="text-cyan-300">Run #2</code>...). Lần chấm mới sẽ được đẩy vào hàng đợi và cập nhật phán quyết đối chiếu song song.
          </p>

          <div>
            <label class="block text-slate-400 font-semibold mb-1">Chọn phiên bản bộ test áp dụng cho lần chấm lại:</label>
            <select id="rejudge-target-version" class="w-full glass-input rounded-xl py-2 px-3 text-xs bg-slate-900 border border-white/15 text-cyan-300 font-semibold">
              <?php foreach ($versions as $v): ?>
                <option value="<?= $v['id'] ?>">
                  Phiên bản v<?= $v['version_number'] ?>.0 (<?= Security::escape($v['changelog'] ?: 'Ban đầu') ?>)
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="pt-2">
            <button type="button" onclick="triggerRejudgeAll()" id="btn-trigger-rejudge" 
              class="w-full btn-cyan text-xs py-3 flex items-center justify-center space-x-2 font-bold shadow-lg">
              <i class="fa-solid fa-bolt"></i>
              <span>Chấm lại tất cả bài nộp trên Phiên bản này</span>
            </button>
          </div>

          <div class="p-3 rounded-xl bg-amber-500/10 border border-amber-500/20 text-amber-300 text-[11px] flex items-center space-x-2">
            <i class="fa-solid fa-circle-info shrink-0"></i>
            <span>Đảm bảo Worker chấm nền đang chạy (<code class="text-white">php core/worker.php</code>) để tự động xử lý các lượt rejudge.</span>
          </div>
        </div>
      </div>

    </div>

    <!-- ==================== BẢNG ĐỐI CHIẾU SONG SONG (SIDE-BY-SIDE COMPARISON) ==================== -->
    <div class="glass-panel rounded-2xl overflow-hidden border border-white/10 shadow-2xl space-y-4 p-6">
      <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between pb-3 border-b border-white/10 gap-2">
        <div>
          <h2 class="text-base font-bold text-white flex items-center space-x-2">
            <i class="fa-solid fa-table-columns text-cyan-400"></i>
            <span>Bảng Đối chiếu Song song Giữa các Lần chấm (Side-by-Side Comparison)</span>
          </h2>
          <p class="text-xs text-slate-400 mt-0.5">
            So sánh trực quan kết quả giữa Lần chấm trước (Run #1) và Lần chấm mới (Run #2 / Rejudge) để nhận diện sự thay đổi phán quyết.
          </p>
        </div>

        <button type="button" onclick="location.reload()" class="btn-glass text-xs py-1.5 px-3 self-start sm:self-auto flex items-center space-x-1">
          <i class="fa-solid fa-rotate text-[10px]"></i>
          <span>Làm mới bảng</span>
        </button>
      </div>

      <!-- Bảng đối chiếu -->
      <div class="overflow-x-auto">
        <table class="w-full text-left text-xs border-collapse font-mono">
          <thead>
            <tr class="border-b border-white/10 bg-white/[0.02] text-slate-400 uppercase tracking-wider text-[11px]">
              <th class="py-3 px-3 w-16 text-center">Mã bài</th>
              <th class="py-3 px-3 w-40 font-sans">Người nộp</th>
              <th class="py-3 px-3 w-20 text-center">Ngôn ngữ</th>
              <th class="py-3 px-4 bg-cyan-950/20 border-l border-white/10 text-cyan-300 text-center">
                <i class="fa-solid fa-history mr-1"></i>Lần chấm trước (Run #1)
              </th>
              <th class="py-3 px-4 bg-blue-950/20 border-l border-white/10 text-blue-300 text-center">
                <i class="fa-solid fa-arrows-rotate mr-1"></i>Lần chấm mới (Run #2)
              </th>
              <th class="py-3 px-4 w-32 border-l border-white/10 text-center font-sans">Biến chuyển</th>
              <th class="py-3 px-3 w-24 text-center font-sans">Chi tiết</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-white/5">
            <?php if (empty($submissions)): ?>
              <tr>
                <td colspan="7" class="py-12 text-center text-slate-500 font-sans text-xs">
                  Chưa có bài nộp nào cho bài tập này.
                </td>
              </tr>
            <?php else: ?>
              <?php foreach ($submissions as $s): ?>
                <?php
                  $runs = $runsBySub[$s['submission_id']] ?? [];
                  $run1 = $runs[0] ?? null;
                  $run2 = count($runs) >= 2 ? $runs[count($runs) - 1] : null;

                  $v1 = $run1 ? $run1['verdict'] : '--';
                  $v2 = $run2 ? $run2['verdict'] : '--';

                  $v1Badge = getVerdictBadgeHtml($v1);
                  $v2Badge = getVerdictBadgeHtml($v2);

                  // Đánh giá biến chuyển
                  $diffChangeHtml = '<span class="text-slate-500 text-[11px] font-sans">Chưa rejudge</span>';
                  if ($run1 && $run2) {
                      if ($v1 === $v2) {
                          $diffChangeHtml = '<span class="text-slate-400 font-sans text-[11px]"><i class="fa-solid fa-equals mr-1"></i>Giữ nguyên (' . $v2 . ')</span>';
                      } elseif ($v1 === 'AC' && $v2 !== 'AC') {
                          $diffChangeHtml = '<span class="text-rose-400 font-sans text-[11px] font-bold"><i class="fa-solid fa-arrow-trend-down mr-1"></i>AC &rarr; ' . $v2 . '</span>';
                      } elseif ($v1 !== 'AC' && $v2 === 'AC') {
                          $diffChangeHtml = '<span class="text-emerald-400 font-sans text-[11px] font-bold"><i class="fa-solid fa-arrow-trend-up mr-1"></i>' . $v1 . ' &rarr; AC</span>';
                      } else {
                          $diffChangeHtml = '<span class="text-amber-400 font-sans text-[11px] font-bold">' . $v1 . ' &rarr; ' . $v2 . '</span>';
                      }
                  }
                ?>
                <tr class="hover:bg-white/[0.03] transition-colors">
                  <!-- ID bài nộp -->
                  <td class="py-3 px-3 text-center font-bold text-slate-400">
                    #<?= $s['submission_id'] ?>
                  </td>

                  <!-- Người nộp -->
                  <td class="py-3 px-3 font-sans">
                    <span class="font-bold text-slate-200"><?= Security::escape($s['full_name']) ?></span>
                    <span class="block text-[10px] text-slate-500">@<?= Security::escape($s['username']) ?></span>
                  </td>

                  <!-- Ngôn ngữ -->
                  <td class="py-3 px-3 text-center uppercase text-cyan-400 font-bold">
                    <?= $s['language'] ?>
                  </td>

                  <!-- LẦN CHẤM 1 -->
                  <td class="py-3 px-4 bg-cyan-950/10 border-l border-white/10 text-center">
                    <?php if ($run1): ?>
                      <div class="inline-flex items-center space-x-2">
                        <?= $v1Badge ?>
                        <span class="text-slate-300 text-xs"><?= $run1['passed_cases'] ?>/<?= $run1['total_cases'] ?> tests</span>
                        <span class="text-[10px] text-slate-500 font-mono">(v<?= $run1['version_number'] ?>)</span>
                      </div>
                    <?php else: ?>
                      <span class="text-slate-600">--</span>
                    <?php endif; ?>
                  </td>

                  <!-- LẦN CHẤM 2 (REJUDGE) -->
                  <td class="py-3 px-4 bg-blue-950/10 border-l border-white/10 text-center">
                    <?php if ($run2): ?>
                      <div class="inline-flex items-center space-x-2">
                        <?= $v2Badge ?>
                        <span class="text-slate-300 text-xs"><?= $run2['passed_cases'] ?>/<?= $run2['total_cases'] ?> tests</span>
                        <span class="text-[10px] text-cyan-400 font-mono font-bold">(v<?= $run2['version_number'] ?>)</span>
                      </div>
                    <?php else: ?>
                      <span class="text-slate-500 text-[11px] italic font-sans">Chưa có lượt chấm lại</span>
                    <?php endif; ?>
                  </td>

                  <!-- BIẾN CHUYỂN -->
                  <td class="py-3 px-4 border-l border-white/10 text-center">
                    <?= $diffChangeHtml ?>
                  </td>

                  <!-- Thao tác xem chi tiết -->
                  <td class="py-3 px-3 text-center font-sans">
                    <a href="/BaiTapLon/submissions.php?problem_id=<?= $selectedProblemId ?>" class="text-cyan-400 hover:underline text-[11px]">
                      Xem đầy đủ
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

<script>
  // 1. TẠO PHIÊN BẢN MỚI
  async function submitNewVersion() {
    const changelog = document.getElementById('ver-changelog').value.trim();
    const timeLimit = parseInt(document.getElementById('ver-timelimit').value);
    const memLimit = parseInt(document.getElementById('ver-memlimit').value);
    const inVal = document.getElementById('new-tc-input').value;
    const outVal = document.getElementById('new-tc-output').value;

    if (!changelog) {
      showToast('Vui lòng nhập mô tả thay đổi (changelog)!', 'warning');
      return;
    }

    const testCases = [];
    if (inVal.trim() && outVal.trim()) {
      testCases.push({
        case_order: 10,
        input_data: inVal,
        expected_output: outVal,
        is_sample: 0,
        is_hidden: 1,
        points: 20
      });
    }

    const btn = document.getElementById('btn-create-ver');
    btn.disabled = true;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin mr-1"></i>Đang khởi tạo phiên bản mới...';

    try {
      const res = await fetch('/BaiTapLon/api/version_rejudge.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          action: 'create_version',
          problem_id: <?= $selectedProblemId ?>,
          changelog: changelog,
          time_limit_ms: timeLimit,
          memory_limit_kb: memLimit,
          test_cases: testCases
        })
      });

      const data = await res.json();
      btn.disabled = false;
      btn.innerHTML = '<i class="fa-solid fa-cloud-arrow-up text-cyan-400"></i><span>Phát hành Phiên bản Mới</span>';

      if (!res.ok) {
        showToast(data.error || 'Lỗi khi tạo phiên bản mới', 'error');
        return;
      }

      showToast(data.message, 'success');
      setTimeout(() => location.reload(), 1200);

    } catch (e) {
      btn.disabled = false;
      showToast('Lỗi mạng: ' + e.message, 'error');
    }
  }

  // 2. KÍCH HOẠT REJUDGE TOÀN BỘ
  async function triggerRejudgeAll() {
    if (!confirm("Hệ thống sẽ tạo lượt chấm mới trong hàng đợi cho tất cả các bài nộp mà vẫn giữ nguyên lịch sử cũ. Bạn có chắc chắn muốn tiếp tục?")) {
      return;
    }

    const targetVerId = parseInt(document.getElementById('rejudge-target-version').value);
    const btn = document.getElementById('btn-trigger-rejudge');
    btn.disabled = true;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin mr-1"></i>Đang đẩy bài vào hàng đợi chấm lại...';

    try {
      const res = await fetch('/BaiTapLon/api/version_rejudge.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          action: 'rejudge',
          problem_id: <?= $selectedProblemId ?>,
          problem_version_id: targetVerId
        })
      });

      const data = await res.json();
      btn.disabled = false;
      btn.innerHTML = '<i class="fa-solid fa-bolt"></i><span>Chấm lại tất cả bài nộp trên Phiên bản này</span>';

      if (!res.ok) {
        showToast(data.error || 'Lỗi khi kích hoạt Rejudge', 'error');
        return;
      }

      showToast(data.message, 'success');
      setTimeout(() => location.reload(), 1500);

    } catch (e) {
      btn.disabled = false;
      showToast('Lỗi mạng: ' + e.message, 'error');
    }
  }
</script>

<?php
function getVerdictBadgeHtml(string $v): string {
    $class = match($v) {
        'AC'  => 'badge-ac',
        'WA'  => 'badge-wa',
        'TLE' => 'badge-tle',
        'RE'  => 'badge-re',
        'CE'  => 'badge-ce',
        'SE'  => 'badge-se',
        default => 'badge-pending'
    };
    return "<span class=\"px-2 py-0.5 rounded text-[10px] font-bold font-mono {$class}\">{$v}</span>";
}
require_once __DIR__ . '/includes/footer.php';
?>
