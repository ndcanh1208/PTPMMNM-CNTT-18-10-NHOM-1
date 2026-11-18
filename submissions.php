<?php
/**
 * Nhom1 Online Judge - Tra cứu Lịch sử Nộp bài & Tiến độ
 * Lọc theo Bài tập, Ngôn ngữ, Kết quả, Lớp học và Modal xem chi tiết từng lần chấm
 */

// ============================================================================
// PHẦN 1: XỬ LÝ PHP, PHÂN QUYỀN XEM DỮ LIỆU & TRUY VẤN LỊCH SỬ BÀI NỘP
// ============================================================================

// Bật chế độ kiểm tra kiểu dữ liệu nghiêm ngặt trong PHP
declare(strict_types=1);

// Nạp tệp kết nối CSDL, lớp xác thực phân quyền và lớp bảo mật chống XSS
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/core/Auth.php';
require_once __DIR__ . '/core/Security.php';

// Khởi động phiên làm việc, lấy tài khoản đang đăng nhập và mở kết nối PDO
Auth::startSession();
$currentUser = Auth::user();
$pdo = getDbConnection();

// Đọc các tham số lọc từ URL (GET): ID bài tập, ngôn ngữ (python/cpp), phán quyết (AC/WA...), username người nộp
$problemFilter = isset($_GET['problem_id']) ? (int)$_GET['problem_id'] : 0;
$languageFilter = trim((string)($_GET['language'] ?? ''));
$verdictFilter  = trim((string)($_GET['verdict'] ?? ''));
$userFilter     = trim((string)($_GET['username'] ?? ''));

// Khởi tạo mảng điều kiện WHERE (bắt đầu bằng "1=1" để dễ dàng nối thêm các điều kiện AND phía sau)
$whereClauses = ["1=1"];
$params = [];

// Phân quyền hiển thị danh sách bài nộp theo vai trò:
// - Học viên: Chỉ được xem bài nộp của chính mình (bảo vệ quyền riêng tư code của người khác)
// - Giảng viên: Xem bài nộp thuộc bài tập do mình tạo, bài nộp trong các lớp mình phụ trách, hoặc bài tự mình nộp
// - Admin: Không thêm điều kiện giới hạn -> xem được toàn bộ bài nộp trên hệ thống
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
        // Chưa đăng nhập thì chuyển hướng bắt buộc sang trang đăng nhập
        header('Location: /BaiTapLon/login.php');
        exit;
    }
}

// Cộng dồn các điều kiện lọc tùy chọn từ form bộ lọc
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

// Chỉ Admin và Giảng viên mới được phép tìm lọc theo username học viên
if ($userFilter !== '' && (Auth::isAdmin() || Auth::isInstructor())) {
    $whereClauses[] = "u.username LIKE :uname";
    $params['uname'] = "%{$userFilter}%";
}

// Ghép tất cả điều kiện lọc thành chuỗi SQL hoàn chỉnh
$whereSql = implode(' AND ', $whereClauses);

// Truy vấn tối đa 100 bài nộp mới nhất (ORDER BY s.id DESC LIMIT 100)
// JOIN 4 bảng: submissions (bài nộp) + users (người nộp) + problems (đề bài) + problem_versions (phiên bản test lúc nộp)
$subStmt = $pdo->prepare("
    SELECT s.id, s.user_id, s.classroom_id, s.problem_id, s.problem_version_id,
           s.language, s.final_verdict, s.pass_count, s.total_cases,
           s.execution_time_ms, s.memory_used_kb, s.submitted_at, s.latest_run_id,
           u.username, u.full_name,
           p.title as problem_title, p.slug as problem_slug, p.deleted_at as problem_deleted_at, pv.version_number
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

// Lấy toàn bộ danh sách bài tập (bao gồm cả bài đã xóa mềm) để hiển thị trong ô chọn lọc Bài tập
$allProblems = $pdo->query("SELECT id, title, deleted_at FROM problems ORDER BY id ASC")->fetchAll();

// Khai báo tiêu đề trang và nạp khung đầu trang + thanh Sidebar
$pageTitle = 'Lịch sử Nộp bài';
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/sidebar.php';
?>

<!-- ========================================================================= -->
<!-- PHẦN 2: GIAO DIỆN HTML BẢNG LỊCH SỬ NỘP BÀI & HỘP THOẠI CHI TIẾT (MODAL)  -->
<!-- ========================================================================= -->

<div class="pl-64 min-h-screen flex flex-col w-full">
  <main class="p-8 max-w-7xl w-full mx-auto space-y-6 flex-1">

    <!-- Khối tiêu đề trang và số lượng bài nộp đang hiển thị -->
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

    <!-- Thanh Bộ lọc (gửi qua phương thức GET, tự submit khi đổi dropdown) -->
    <form action="" method="GET" class="glass-panel rounded-2xl p-4 border border-white/10 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3 text-xs">
      <!-- Lọc theo Bài tập -->
      <div>
        <select name="problem_id" onchange="this.form.submit()" class="w-full glass-input rounded-xl py-2 px-3 bg-slate-900">
          <option value="">-- Tất cả Bài tập --</option>
          <?php foreach ($allProblems as $p): ?>
            <option value="<?= $p['id'] ?>" <?= $problemFilter === (int)$p['id'] ? 'selected' : '' ?>>
              #<?= $p['id'] ?>. <?= Security::escape($p['title']) ?><?= !empty($p['deleted_at']) ? ' [Đã xóa]' : '' ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <!-- Lọc theo Ngôn ngữ lập trình -->
      <div>
        <select name="language" onchange="this.form.submit()" class="w-full glass-input rounded-xl py-2 px-3 bg-slate-900">
          <option value="">-- Tất cả Ngôn ngữ --</option>
          <option value="python" <?= $languageFilter === 'python' ? 'selected' : '' ?>>Python</option>
          <option value="cpp" <?= $languageFilter === 'cpp' ? 'selected' : '' ?>>C++</option>
        </select>
      </div>

      <!-- Lọc theo Kết quả chấm (Verdict) -->
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

      <!-- Ô lọc theo username (chỉ hiện với Admin/Giảng viên) + Nút Đặt lại toàn bộ bộ lọc -->
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

    <!-- Bảng Danh sách các Bài nộp -->
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
            <!-- Nếu không có bài nộp nào thỏa mãn điều kiện lọc thì báo trống -->
            <?php if (empty($submissions)): ?>
              <tr>
                <td colspan="9" class="py-12 text-center text-slate-500 text-xs font-sans">
                  Không tìm thấy bài nộp nào.
                </td>
              </tr>
            <?php else: ?>
              <?php foreach ($submissions as $s): ?>
                <?php
                  // Ánh xạ phán quyết cuối cùng sang class CSS tô màu nhãn (được định nghĩa trong file CSS chung)
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
                  <!-- Cột 1: Mã số bài nộp -->
                  <td class="py-3.5 px-4 text-center text-slate-400 font-bold">
                    #<?= $s['id'] ?>
                  </td>

                  <!-- Cột 2: Tên bài tập, nhãn "Đã xóa" (nếu bài bị xóa mềm) và phiên bản bộ test đã dùng -->
                  <td class="py-3.5 px-4 font-sans">
                    <a href="/BaiTapLon/solve.php?id=<?= $s['problem_id'] ?>" class="font-bold text-white hover:text-cyan-400 transition-colors">
                      <?= Security::escape($s['problem_title']) ?>
                    </a>
                    <?php if (!empty($s['problem_deleted_at'])): ?>
                      <span class="ml-1 px-1.5 py-0.5 rounded text-[10px] font-sans font-semibold bg-rose-500/10 text-rose-400 border border-rose-500/20" title="Bài tập này đã bị xóa hoặc tạm ẩn">
                        Đã xóa
                      </span>
                    <?php endif; ?>
                    <span class="ml-1 px-1.5 py-0.2 rounded text-[10px] font-mono bg-white/5 text-cyan-300">
                      v<?= $s['version_number'] ?>.0
                    </span>
                  </td>

                  <!-- Cột 3: Họ tên và username người nộp -->
                  <td class="py-3.5 px-4 font-sans text-slate-300">
                    <span class="font-medium text-slate-200"><?= Security::escape($s['full_name']) ?></span>
                    <span class="block text-[11px] text-slate-500 font-mono">@<?= Security::escape($s['username']) ?></span>
                  </td>

                  <!-- Cột 4: Ngôn ngữ lập trình (PYTHON / CPP) -->
                  <td class="py-3.5 px-4 text-center uppercase text-cyan-400 font-bold">
                    <?= $s['language'] ?>
                  </td>

                  <!-- Cột 5: Nhãn kết quả phán quyết -->
                  <td class="py-3.5 px-4 text-center">
                    <span class="px-2.5 py-0.5 rounded text-[11px] font-bold <?= $badgeClass ?>">
                      <?= $v ?>
                    </span>
                  </td>

                  <!-- Cột 6: Tỷ lệ số ca kiểm thử đã vượt qua / tổng số ca -->
                  <td class="py-3.5 px-4 text-center text-slate-300">
                    <?= $s['pass_count'] ?> / <?= $s['total_cases'] ?>
                  </td>

                  <!-- Cột 7: Thời gian thực thi lớn nhất (tính bằng mili-giây) -->
                  <td class="py-3.5 px-4 text-center text-slate-400">
                    <?= $s['execution_time_ms'] ?> ms
                  </td>

                  <!-- Cột 8: Thời điểm nộp bài -->
                  <td class="py-3.5 px-4 text-slate-500 text-[11px] font-sans">
                    <?= $s['submitted_at'] ?>
                  </td>

                  <!-- Cột 9: Nút mở Modal xem mã nguồn và chi tiết từng lần chấm qua JavaScript -->
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
<!-- Lớp phủ toàn màn hình (ẩn mặc định bằng class hidden, bật lên bằng flex khi gọi openSubmissionModal) -->
<div id="sub-modal" class="fixed inset-0 z-50 bg-black/80 backdrop-blur-md hidden items-center justify-center p-4">
  <div class="glass-panel w-full max-w-4xl max-h-[90vh] rounded-3xl border border-white/15 flex flex-col shadow-2xl overflow-hidden animate-in fade-in zoom-in duration-200">
    
    <!-- Phần đầu Modal: hiển thị mã ID bài nộp, tên bài, thông tin tác giả và nút X đóng Modal -->
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

    <!-- Phần thân Modal (có thanh cuộn dọc overflow-y-auto khi nội dung dài) -->
    <div class="flex-1 overflow-y-auto p-6 space-y-6 text-xs" id="modal-sub-body">
      
      <!-- Khu vực 1 trong Modal: Hiển thị mã nguồn đã nộp + nút Sao chép code vào clipboard -->
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

      <!-- Khu vực 2 trong Modal: Danh sách tất cả các lần chấm (Run #1, Run #2...) và bảng kết quả từng test case -->
      <div class="space-y-3">
        <div class="font-bold text-slate-300 uppercase tracking-wider text-[11px]">
          Lịch sử các lần chấm (Runs & Rejudge History)
        </div>
        <div id="modal-runs-list" class="space-y-4">
          <!-- Nội dung được JavaScript tạo động từ dữ liệu API trả về -->
        </div>
      </div>

    </div>

  </div>
</div>

<!-- ========================================================================= -->
<!-- PHẦN 3: JAVASCRIPT XỬ LÝ MODAL XEM CHI TIẾT BÀI NỘP & LỊCH SỬ CHẤM       -->
<!-- ========================================================================= -->
<script>
  // Biến lưu mã nguồn của bài nộp đang mở trong Modal để phục vụ nút "Sao chép code"
  let modalCode = "";

  // Hàm mở Modal và gọi API /api/submission_status.php?id=... để tải mã nguồn + chi tiết từng lần chấm
  async function openSubmissionModal(subId) {
    // Hiển thị Modal (bỏ ẩn 'hidden', thêm 'flex' để căn giữa màn hình)
    const modal = document.getElementById('sub-modal');
    modal.classList.remove('hidden');
    modal.classList.add('flex');

    // Đặt trạng thái đang tải (loading) cho các vùng dữ liệu trong Modal
    document.getElementById('modal-sub-id').innerText = subId;
    document.getElementById('modal-sub-title').innerText = 'Đang nạp chi tiết bài nộp...';
    document.getElementById('modal-source-code').innerText = '// Đang tải...';
    document.getElementById('modal-runs-list').innerHTML = '<div class="text-center py-6 text-slate-500"><i class="fa-solid fa-spinner fa-spin mr-1"></i>Đang tải dữ liệu chấm...</div>';

    try {
      // Gửi yêu cầu GET tới API để lấy dữ liệu JSON chi tiết bài nộp
      const res = await fetch(`/BaiTapLon/api/submission_status.php?id=${subId}`);
      // Kiểm tra nếu máy chủ báo lỗi (ví dụ 403 Không có quyền xem hoặc 404 Không tìm thấy)
      if (!res.ok) {
        document.getElementById('modal-sub-title').innerText = 'Lỗi truy cập';
        document.getElementById('modal-runs-list').innerHTML = `<div class="p-4 rounded-xl bg-rose-500/10 text-rose-300 border border-rose-500/30">Không thể xem chi tiết bài nộp (HTTP ${res.status}).</div>`;
        return;
      }

      // Chuyển đổi phản hồi JSON thành đối tượng JavaScript
      const data = await res.json();
      const sub = data.submission;
      const runs = data.runs;

      // Điền tiêu đề bài tập, thông tin người nộp và mã nguồn (dùng innerText để tự động chống XSS)
      document.getElementById('modal-sub-title').innerText = sub.problem_title;
      document.getElementById('modal-sub-meta').innerText = `Người nộp: ${sub.author_name} (@${sub.author_username}) • Ngôn ngữ: ${sub.language.toUpperCase()} • Nộp lúc: ${sub.submitted_at}`;
      
      modalCode = sub.source_code;
      document.getElementById('modal-source-code').innerText = sub.source_code;

      // Xóa trạng thái loading và bắt đầu dựng HTML cho từng lần chấm (Run #1, Run #2...)
      const runsList = document.getElementById('modal-runs-list');
      runsList.innerHTML = '';

      runs.forEach(r => {
        const runCard = document.createElement('div');
        runCard.className = 'rounded-2xl border border-white/10 bg-white/[0.02] p-4 space-y-3';

        const badgeClass = getBadgeClass(r.verdict);
        
        // Phần đầu thẻ của 1 lần chấm: số thứ tự lần chấm, nhãn kết quả, số test đạt, phiên bản test, thời gian chạy
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

        // Nếu lần chấm có thông báo lỗi biên dịch (CE) hoặc lỗi cú pháp thì hiển thị hộp cảnh báo màu đỏ
        if (r.compiler_message) {
          headerHtml += `
            <div class="p-3 rounded-xl bg-rose-950/40 border border-rose-500/30 text-rose-300 font-mono text-[11px] whitespace-pre-wrap">
              <strong>[Lỗi biên dịch / cú pháp]:</strong>\n${escapeHtml(r.compiler_message)}
            </div>
          `;
        }

        // Dựng bảng chi tiết kết quả từng ca kiểm thử (test case); dữ liệu test kín đã được backend che sẵn trước khi trả về
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

          // Lặp qua từng test case để tạo một dòng <tr> trong bảng
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

        // Gắn toàn bộ HTML của lần chấm vào thẻ runCard rồi thêm vào danh sách hiển thị
        runCard.innerHTML = headerHtml + casesHtml;
        runsList.appendChild(runCard);
      });

    } catch (e) {
      // Báo lỗi nếu mất kết nối mạng hoặc phản hồi không phải JSON hợp lệ
      document.getElementById('modal-runs-list').innerHTML = `<div class="p-4 rounded-xl bg-rose-500/10 text-rose-300">Lỗi kết nối: ${e.message}</div>`;
    }
  }

  // Hàm đóng Modal chi tiết bài nộp
  function closeSubmissionModal() {
    const modal = document.getElementById('sub-modal');
    modal.classList.add('hidden');
    modal.classList.remove('flex');
  }

  // Hàm sao chép toàn bộ mã nguồn bài nộp hiện tại vào bộ nhớ tạm (Clipboard) và hiện thông báo Toast
  function copySourceCode() {
    if (modalCode) {
      navigator.clipboard.writeText(modalCode).then(() => {
        showToast('Đã sao chép mã nguồn!', 'success');
      });
    }
  }

  // Hàm tiện ích: trả về tên class CSS màu sắc tương ứng với từng mã kết quả chấm (AC, WA, TLE, RE, CE, SE)
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

  // Hàm thoát các ký tự đặc biệt HTML (&, <, >) nhằm ngăn trình duyệt thực thi nhầm mã HTML/script (chống XSS)
  function escapeHtml(text) {
    if (!text) return '';
    return text.replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;");
  }
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
