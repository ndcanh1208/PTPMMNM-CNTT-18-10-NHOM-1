<?php
/**
 * ============================================================================
 * Nhom1 Online Judge - Phân hệ Admin: Thống kê & Báo cáo Kết quả Học tập
 * ============================================================================
 * MỤC ĐÍCH TRANG NÀY:
 * - Cung cấp bảng điều khiển phân tích tổng quan cho Quản trị viên (Admin):
 *   tổng số bài tập, tổng số bài nộp, tỷ lệ kết quả chấm (AC, WA, TLE, RE, CE, SE).
 * - So sánh hiệu suất thực thi giữa 2 ngôn ngữ lập trình Python và C++.
 * - Lập bảng xếp hạng/điểm số sinh viên (lọc theo lớp, khoảng ngày) và xuất báo cáo CSV/JSON.
 * - Thống kê tỷ lệ hoàn thành chi tiết của từng bài tập.
 *
 * CẤU TRÚC FILE GỒM 2 PHẦN CHÍNH (trang báo cáo thuần server-side, không có JS riêng):
 * - PHẦN 1 (PHP): Kiểm tra quyền Admin, đọc bộ lọc GET và thực thi các truy vấn SQL tổng hợp.
 * - PHẦN 2 (HTML): Hiển thị thẻ KPI, biểu đồ thanh ngang, bảng điểm sinh viên và bảng bài tập.
 */

// Bật chế độ kiểm tra kiểu dữ liệu nghiêm ngặt trong PHP (tránh ép kiểu ngầm gây lỗi)
declare(strict_types=1);

// Nạp các tệp lõi: kết nối CSDL (PDO), quản lý đăng nhập/phân quyền (Auth) và chống XSS (Security)
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/core/Auth.php';
require_once __DIR__ . '/core/Security.php';

// Chỉ cho phép tài khoản có vai trò 'admin' truy cập trang này (nếu không sẽ bị chặn/chuyển hướng)
Auth::requireRoles(['admin']);

// Lấy đối tượng kết nối PDO tới cơ sở dữ liệu MySQL
$pdo = getDbConnection();

// ============================================================================
// PHẦN 1: ĐỌC BỘ LỌC GET VÀ TRUY VẤN CƠ SỞ DỮ LIỆU THỐNG KÊ
// ============================================================================

// Đọc tham số lọc từ thanh địa chỉ URL (?class_name=...&date_from=...&date_to=...)
// trim() giúp cắt bỏ khoảng trắng thừa ở hai đầu chuỗi
$classFilter = trim((string)($_GET['class_name'] ?? ''));
$dateFrom    = trim((string)($_GET['date_from'] ?? ''));
$dateTo      = trim((string)($_GET['date_to'] ?? ''));

// 1. Truy vấn tổng quan số lượng Bài tập đang hoạt động (chưa bị xóa mềm: deleted_at IS NULL)
// Dùng SUM(CASE WHEN ... THEN 1 ELSE 0 END) để đếm có điều kiện theo từng chế độ hiển thị trong 1 câu SQL
$problemCounts = $pdo->query("
    SELECT
        COUNT(*) AS total_active,
        SUM(CASE WHEN visibility = 'public' THEN 1 ELSE 0 END) AS cnt_public,
        SUM(CASE WHEN visibility = 'hidden' THEN 1 ELSE 0 END) AS cnt_hidden,
        SUM(CASE WHEN visibility = 'exam_only' THEN 1 ELSE 0 END) AS cnt_exam
    FROM problems
    WHERE deleted_at IS NULL
")->fetch(PDO::FETCH_ASSOC);

// Dùng max(1, ...) để đảm bảo mẫu số luôn >= 1, tránh lỗi chia cho 0 khi tính điểm thang 10
$totalActiveProblems = max(1, (int)$problemCounts['total_active']);
// Đếm tổng số tài khoản có vai trò sinh viên ('learner')
$totalStudents = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role = 'learner'")->fetchColumn();

// 2. Thống kê phân bố phán quyết chấm bài (AC, WA, TLE, RE, CE, SE) trên toàn hệ thống
// PDO::FETCH_KEY_PAIR trả về mảng dạng ['AC' => 120, 'WA' => 45, ...] (cột 1 làm key, cột 2 làm value)
$verdictRaw = $pdo->query("
    SELECT final_verdict, COUNT(*) AS cnt
    FROM submissions
    GROUP BY final_verdict
")->fetchAll(PDO::FETCH_KEY_PAIR);

// Chuẩn hóa đủ 6 loại phán quyết phổ biến (nếu chưa có bài nộp nào mang trạng thái đó thì gán 0)
$verdicts = [
    'AC'  => (int)($verdictRaw['AC'] ?? 0),
    'WA'  => (int)($verdictRaw['WA'] ?? 0),
    'TLE' => (int)($verdictRaw['TLE'] ?? 0),
    'RE'  => (int)($verdictRaw['RE'] ?? 0),
    'CE'  => (int)($verdictRaw['CE'] ?? 0),
    'SE'  => (int)($verdictRaw['SE'] ?? 0)
];
// Tính tổng toàn bộ lượt nộp bài và tỷ lệ phần trăm của 3 phán quyết quan trọng nhất (AC, WA, TLE)
$totalSubmissions = array_sum(array_map('intval', $verdictRaw));
$acRateGlobal = $totalSubmissions > 0 ? round(($verdicts['AC'] / $totalSubmissions) * 100, 1) : 0;
$waRateGlobal = $totalSubmissions > 0 ? round(($verdicts['WA'] / $totalSubmissions) * 100, 1) : 0;
$tleRateGlobal = $totalSubmissions > 0 ? round(($verdicts['TLE'] / $totalSubmissions) * 100, 1) : 0;

// 3. Thống kê so sánh hiệu suất giữa hai ngôn ngữ Python và C++
// Đếm tổng bài nộp, số bài AC và thời gian chạy trung bình (AVG) theo từng ngôn ngữ
$langRows = $pdo->query("
    SELECT
        language,
        COUNT(*) AS total_cnt,
        SUM(CASE WHEN final_verdict = 'AC' THEN 1 ELSE 0 END) AS ac_cnt,
        ROUND(AVG(execution_time_ms), 1) AS avg_time_ms
    FROM submissions
    GROUP BY language
")->fetchAll(PDO::FETCH_ASSOC);
// Khởi tạo giá trị mặc định bằng 0 phòng trường hợp một ngôn ngữ chưa có lượt nộp nào
$langMap = ['python' => ['total_cnt' => 0, 'ac_cnt' => 0, 'avg_time_ms' => 0], 'cpp' => ['total_cnt' => 0, 'ac_cnt' => 0, 'avg_time_ms' => 0]];
foreach ($langRows as $lr) {
    if (isset($langMap[$lr['language']])) {
        $langMap[$lr['language']] = $lr;
    }
}

// 4. Thống kê chi tiết theo từng bài tập (lấy tối đa 20 bài có nhiều lượt nộp nhất)
// LEFT JOIN giúp vẫn hiển thị các bài tập dù chưa có bài nộp nào (total_subs = 0)
// COUNT(DISTINCT ...) đếm số sinh viên khác nhau đã giải đúng (AC) bài tập đó
$problemStats = $pdo->query("
    SELECT
        p.id, p.slug, p.title, p.difficulty, p.visibility,
        COUNT(s.id) AS total_subs,
        SUM(CASE WHEN s.final_verdict = 'AC' THEN 1 ELSE 0 END) AS ac_subs,
        SUM(CASE WHEN s.final_verdict = 'WA' THEN 1 ELSE 0 END) AS wa_subs,
        SUM(CASE WHEN s.final_verdict = 'TLE' THEN 1 ELSE 0 END) AS tle_subs,
        COUNT(DISTINCT CASE WHEN s.final_verdict = 'AC' THEN s.user_id END) AS unique_ac_users
    FROM problems p
    LEFT JOIN submissions s ON s.problem_id = p.id
    WHERE p.deleted_at IS NULL
    GROUP BY p.id
    ORDER BY total_subs DESC, p.id ASC
    LIMIT 20
")->fetchAll(PDO::FETCH_ASSOC);

// 5. Lấy danh sách các tên lớp duy nhất (DISTINCT) hiện có để đổ vào thẻ <select> lọc theo lớp
$allClasses = $pdo->query("SELECT DISTINCT class_name FROM users WHERE class_name IS NOT NULL AND class_name != '' ORDER BY class_name ASC")->fetchAll(PDO::FETCH_COLUMN);

// 6. Xây dựng truy vấn động cho Bảng tổng hợp điểm số sinh viên (có áp dụng bộ lọc Lớp & Ngày)
// Lưu ý: Điều kiện ngày đặt trong mệnh đề ON của LEFT JOIN để sinh viên chưa nộp bài trong khoảng ngày đó vẫn hiện với 0 lượt nộp
$joinParams = [];
$dateJoin = '';
if ($dateFrom !== '') {
    $dateJoin .= " AND s.submitted_at >= ?";
    $joinParams[] = $dateFrom . ' 00:00:00';
}
if ($dateTo !== '') {
    $dateJoin .= " AND s.submitted_at <= ?";
    $joinParams[] = $dateTo . ' 23:59:59';
}
// Điều kiện lọc người dùng (chỉ lấy sinh viên và lọc theo lớp nếu người dùng có chọn)
$userParams = [];
$whereUserSql = "u.role = 'learner'";
if ($classFilter !== '') {
    $whereUserSql .= " AND u.class_name = ?";
    $userParams[] = $classFilter;
}

// Dùng Prepared Statement với dấu '?' để truyền tham số an toàn, chống tấn công SQL Injection
$studentStmt = $pdo->prepare("
    SELECT
        u.id, u.username, u.full_name, u.student_code, u.class_name, u.email, u.is_locked,
        COUNT(s.id) AS total_submissions,
        SUM(CASE WHEN s.final_verdict = 'AC' THEN 1 ELSE 0 END) AS ac_submissions,
        SUM(CASE WHEN s.final_verdict = 'WA' THEN 1 ELSE 0 END) AS wa_submissions,
        SUM(CASE WHEN s.final_verdict = 'TLE' THEN 1 ELSE 0 END) AS tle_submissions,
        COUNT(DISTINCT CASE WHEN s.final_verdict = 'AC' THEN s.problem_id END) AS solved_problems
    FROM users u
    LEFT JOIN submissions s ON s.user_id = u.id {$dateJoin}
    WHERE {$whereUserSql}
    GROUP BY u.id
    ORDER BY solved_problems DESC, ac_submissions DESC, u.student_code ASC
");
// Ghép mảng tham số đúng thứ tự xuất hiện của dấu '?' trong câu SQL (JOIN trước, WHERE sau)
$studentStmt->execute(array_merge($joinParams, $userParams));
$studentReport = $studentStmt->fetchAll(PDO::FETCH_ASSOC);

// Tạo chuỗi query string giữ nguyên các bộ lọc hiện tại để gắn vào các nút Xuất báo cáo CSV/JSON
$exportQuery = http_build_query([
    'class_name' => $classFilter,
    'date_from'  => $dateFrom,
    'date_to'    => $dateTo
]);

// Thiết lập tiêu đề trang và nạp phần khung giao diện chung (header + thanh menu bên trái sidebar)
$pageTitle = 'Thống kê & Báo cáo Học tập';
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/sidebar.php';
?>

<!-- ========================================================================= -->
<!-- PHẦN 2: GIAO DIỆN HTML (KPI TỔNG QUAN, BIỂU ĐỒ & BẢNG BÁO CÁO)             -->
<!-- Lưu ý: Trang này xử lý lọc bằng form GET và tải file trực tiếp qua API     -->
<!-- export_report.php nên không cần mã JavaScript phức tạp ở cuối trang.       -->
<!-- ========================================================================= -->
<div class="pl-64 min-h-screen flex flex-col w-full">
  <main class="p-8 max-w-7xl w-full mx-auto space-y-6 flex-1">
    <!-- Khối tiêu đề trang và nhóm nút tải về báo cáo CSV / JSON -->
    <div class="flex flex-col md:flex-row md:items-center justify-between gap-4">
      <div>
        <h1 class="text-2xl font-black text-white flex items-center space-x-3">
          <i class="fa-solid fa-chart-pie text-cyan-400"></i>
          <span>Thống kê & Báo cáo Kết quả Học tập</span>
        </h1>
        <p class="text-xs text-slate-400 mt-1">
          Phân tích tỷ lệ Accepted (AC) / Wrong Answer (WA) / Time Limit Exceeded (TLE) và xuất báo cáo đánh giá học phần.
        </p>
      </div>
      <!-- Nhóm nút gọi API /BaiTapLon/api/export_report.php kèm bộ lọc đang chọn ($exportQuery) -->
      <div class="flex flex-wrap items-center gap-2">
        <a href="/BaiTapLon/api/export_report.php?type=student_summary&format=csv&<?= $exportQuery ?>" class="px-3.5 py-2 rounded-xl bg-emerald-500/15 hover:bg-emerald-500/25 text-emerald-300 border border-emerald-500/30 text-xs font-bold inline-flex items-center space-x-1.5">
          <i class="fa-solid fa-file-excel"></i>
          <span>Xuất Bảng điểm (CSV/Excel)</span>
        </a>
        <a href="/BaiTapLon/api/export_report.php?type=submissions&format=csv&<?= $exportQuery ?>" class="px-3.5 py-2 rounded-xl bg-cyan-500/15 hover:bg-cyan-500/25 text-cyan-300 border border-cyan-500/30 text-xs font-bold inline-flex items-center space-x-1.5">
          <i class="fa-solid fa-table-list"></i>
          <span>Xuất Lịch sử Nộp (CSV)</span>
        </a>
        <a href="/BaiTapLon/api/export_report.php?type=student_summary&format=json&<?= $exportQuery ?>" class="px-3.5 py-2 rounded-xl bg-purple-500/15 hover:bg-purple-500/25 text-purple-300 border border-purple-500/30 text-xs font-bold inline-flex items-center space-x-1.5">
          <i class="fa-solid fa-file-code"></i>
          <span>JSON</span>
        </a>
      </div>
    </div>

    <!-- 1. KHỐI 4 THẺ CHỈ SỐ KPI TỔNG QUAN (Bài tập, Bài nộp, Tỷ lệ AC, Tỷ lệ WA/TLE) -->
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
      <!-- Thẻ KPI 1: Tổng số bài tập và phân loại công khai / ẩn / phòng thi -->
      <div class="glass-panel p-5 rounded-2xl border border-white/10">
        <div class="flex items-center justify-between text-slate-400 text-xs mb-2">
          <span class="uppercase font-bold">Tổng số Bài tập</span>
          <i class="fa-solid fa-code text-cyan-400"></i>
        </div>
        <div class="text-3xl font-black text-white"><?= (int)$problemCounts['total_active'] ?></div>
        <div class="text-[11px] text-slate-400 mt-1.5 space-x-2">
          <span class="text-emerald-400"><?= (int)$problemCounts['cnt_public'] ?> Công khai</span>
          <span>·</span>
          <span class="text-slate-300"><?= (int)$problemCounts['cnt_hidden'] ?> Ẩn</span>
          <span>·</span>
          <span class="text-purple-400"><?= (int)$problemCounts['cnt_exam'] ?> Phòng thi</span>
        </div>
      </div>

      <!-- Thẻ KPI 2: Tổng số bài nộp toàn hệ thống và số sinh viên tham gia -->
      <div class="glass-panel p-5 rounded-2xl border border-white/10">
        <div class="flex items-center justify-between text-slate-400 text-xs mb-2">
          <span class="uppercase font-bold">Tổng số Bài nộp</span>
          <i class="fa-solid fa-paper-plane text-blue-400"></i>
        </div>
        <div class="text-3xl font-black text-white"><?= number_format($totalSubmissions) ?></div>
        <div class="text-[11px] text-slate-400 mt-1.5">
          Từ <span class="text-white font-bold"><?= $totalStudents ?></span> sinh viên đăng ký
        </div>
      </div>

      <!-- Thẻ KPI 3: Tỷ lệ bài nộp đạt Accepted (AC) -->
      <div class="glass-panel p-5 rounded-2xl border border-emerald-500/20">
        <div class="flex items-center justify-between text-slate-400 text-xs mb-2">
          <span class="uppercase font-bold">Tỷ lệ Accepted (AC)</span>
          <i class="fa-solid fa-circle-check text-emerald-400"></i>
        </div>
        <div class="text-3xl font-black text-emerald-400"><?= $acRateGlobal ?>%</div>
        <div class="text-[11px] text-slate-400 mt-1.5">
          <?= number_format($verdicts['AC']) ?> lượt đạt AC / <?= number_format($totalSubmissions) ?> tổng nộp
        </div>
      </div>

      <!-- Thẻ KPI 4: Tỷ lệ bài bị Wrong Answer (WA) và quá thời gian (TLE) -->
      <div class="glass-panel p-5 rounded-2xl border border-rose-500/20">
        <div class="flex items-center justify-between text-slate-400 text-xs mb-2">
          <span class="uppercase font-bold">Tỷ lệ WA / TLE</span>
          <i class="fa-solid fa-chart-line text-rose-400"></i>
        </div>
        <div class="text-3xl font-black text-amber-300">
          <?= $waRateGlobal ?>% <span class="text-sm text-slate-500 font-normal">/</span> <span class="text-rose-400"><?= $tleRateGlobal ?>%</span>
        </div>
        <div class="text-[11px] text-slate-400 mt-1.5">
          WA: <?= $verdicts['WA'] ?> · TLE: <?= $verdicts['TLE'] ?> · RE/CE: <?= $verdicts['RE'] + $verdicts['CE'] ?>
        </div>
      </div>
    </div>

    <!-- 2. KHỐI BIỂU ĐỒ PHÂN BỐ PHÁN QUYẾT & SO SÁNH HIỆU SUẤT NGÔN NGỮ -->
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
      <!-- Cột trái (2/3 chiều rộng): Biểu đồ thanh ngang phân bố 6 trạng thái chấm -->
      <div class="lg:col-span-2 glass-panel rounded-2xl p-6 border border-white/10 space-y-4">
        <h2 class="text-sm font-bold text-white flex items-center space-x-2">
          <i class="fa-solid fa-chart-bar text-cyan-400"></i>
          <span>Phân bố Kết quả Chấm bài (AC vs WA vs TLE vs RE vs CE vs SE)</span>
        </h2>

        <?php
          // Bảng cấu hình tên tiếng Việt và màu thanh tiến trình cho từng loại phán quyết
          $verdictMeta = [
            'AC'  => ['name' => 'Accepted (Đạt toàn bộ test)', 'bar' => 'from-emerald-500 to-teal-400', 'text' => 'text-emerald-400'],
            'WA'  => ['name' => 'Wrong Answer (Sai kết quả)', 'bar' => 'from-rose-500 to-red-400', 'text' => 'text-rose-400'],
            'TLE' => ['name' => 'Time Limit Exceeded (Quá thời gian)', 'bar' => 'from-amber-500 to-orange-400', 'text' => 'text-amber-400'],
            'RE'  => ['name' => 'Runtime Error (Lỗi thực thi)', 'bar' => 'from-purple-500 to-fuchsia-400', 'text' => 'text-purple-400'],
            'CE'  => ['name' => 'Compile Error (Lỗi biên dịch)', 'bar' => 'from-yellow-500 to-amber-300', 'text' => 'text-yellow-300'],
            'SE'  => ['name' => 'System Error (Lỗi hạ tầng)', 'bar' => 'from-slate-500 to-slate-400', 'text' => 'text-slate-300'],
          ];
        ?>
        <div class="space-y-3">
          <?php foreach ($verdictMeta as $code => $meta): ?>
            <?php
              // Tính số lượng và phần trăm của từng loại phán quyết để đặt chiều rộng thanh (style="width: ...")
              $cnt = $verdicts[$code];
              $pct = $totalSubmissions > 0 ? round(($cnt / $totalSubmissions) * 100, 1) : 0;
            ?>
            <div>
              <div class="flex items-center justify-between text-xs mb-1">
                <span class="font-semibold text-slate-200">
                  <span class="font-mono font-bold <?= $meta['text'] ?> mr-1.5"><?= $code ?></span>
                  <span class="text-slate-400 text-[11px]"><?= $meta['name'] ?></span>
                </span>
                <span class="font-mono font-bold text-white"><?= $cnt ?> <span class="text-slate-400 font-normal">(<?= $pct ?>%)</span></span>
              </div>
              <div class="w-full h-2.5 bg-white/5 rounded-full overflow-hidden border border-white/5">
                <div class="h-full bg-gradient-to-r <?= $meta['bar'] ?> rounded-full transition-all duration-500" style="width: <?= $pct ?>%"></div>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      </div>

      <!-- Cột phải (1/3 chiều rộng): Thẻ so sánh tổng lượt nộp, tỷ lệ AC và thời gian chạy TB giữa Python và C++ -->
      <div class="glass-panel rounded-2xl p-6 border border-white/10 space-y-4">
        <h2 class="text-sm font-bold text-white flex items-center space-x-2">
          <i class="fa-solid fa-code-branch text-purple-400"></i>
          <span>Hiệu suất theo Ngôn ngữ</span>
        </h2>

        <?php foreach (['python' => ['label' => 'Python 3', 'icon' => 'fa-brands fa-python text-amber-400'], 'cpp' => ['label' => 'C++ (G++)', 'icon' => 'fa-solid fa-code text-blue-400']] as $lk => $lm): ?>
          <?php
            // Tính tỷ lệ AC và lấy thời gian chạy trung bình của từng ngôn ngữ
            $ltot = (int)$langMap[$lk]['total_cnt'];
            $lac  = (int)$langMap[$lk]['ac_cnt'];
            $lrate = $ltot > 0 ? round(($lac / $ltot) * 100, 1) : 0;
            $lavg = $langMap[$lk]['avg_time_ms'] ?? 0;
          ?>
          <div class="p-4 rounded-xl bg-black/40 border border-white/10 space-y-2">
            <div class="flex items-center justify-between">
              <span class="font-bold text-white text-sm flex items-center space-x-2">
                <i class="<?= $lm['icon'] ?>"></i>
                <span><?= $lm['label'] ?></span>
              </span>
              <span class="font-mono text-xs text-cyan-300 font-bold"><?= $ltot ?> lượt</span>
            </div>
            <div class="grid grid-cols-2 gap-2 text-[11px] pt-1">
              <div class="p-2 rounded-lg bg-white/5">
                <div class="text-slate-400">Tỷ lệ AC</div>
                <div class="text-emerald-400 font-bold font-mono text-sm"><?= $lrate ?>%</div>
              </div>
              <div class="p-2 rounded-lg bg-white/5">
                <div class="text-slate-400">Thời gian TB</div>
                <div class="text-white font-bold font-mono text-sm"><?= $lavg ?> ms</div>
              </div>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>

    <!-- 3. BẢNG BÁO CÁO KẾT QUẢ HỌC TẬP SINH VIÊN (KÈM FORM LỌC LỚP & KHOẢNG NGÀY) -->
    <div class="glass-panel rounded-2xl border border-white/10 overflow-hidden shadow-2xl">
      <div class="px-6 py-4 border-b border-white/10 flex flex-col lg:flex-row lg:items-center justify-between gap-4 bg-white/[0.02]">
        <div>
          <h2 class="text-base font-bold text-white flex items-center space-x-2">
            <i class="fa-solid fa-graduation-cap text-emerald-400"></i>
            <span>Bảng Tổng hợp Kết quả Học tập Sinh viên</span>
          </h2>
          <p class="text-[11px] text-slate-400 mt-0.5">Điểm đánh giá (thang 10) được tính dựa trên tỷ lệ bài giải đúng (AC) trên tổng <?= $totalActiveProblems ?> bài tập đang mở.</p>
        </div>

        <!-- Form lọc GET: Khi bấm "Lọc báo cáo", trình duyệt tải lại trang với tham số ?class_name=...&date_from=...&date_to=... -->
        <form method="GET" action="" class="flex flex-wrap items-center gap-2 text-xs">
          <select name="class_name" class="glass-input rounded-xl py-1.5 px-3 bg-slate-900 text-xs">
            <option value="">-- Tất cả Lớp --</option>
            <?php foreach ($allClasses as $cls): ?>
              <option value="<?= Security::escape($cls) ?>" <?= $classFilter === $cls ? 'selected' : '' ?>><?= Security::escape($cls) ?></option>
            <?php endforeach; ?>
          </select>
          <input type="date" name="date_from" value="<?= Security::escape($dateFrom) ?>" class="glass-input rounded-xl py-1.5 px-2.5 text-xs" title="Từ ngày">
          <input type="date" name="date_to" value="<?= Security::escape($dateTo) ?>" class="glass-input rounded-xl py-1.5 px-2.5 text-xs" title="Đến ngày">
          <button type="submit" class="px-3.5 py-1.5 rounded-xl bg-cyan-500/20 hover:bg-cyan-500/30 text-cyan-300 border border-cyan-500/30 font-bold cursor-pointer">Lọc báo cáo</button>
        </form>
      </div>

      <!-- Bảng liệt kê từng sinh viên: hạng, mã SV, họ tên, lớp, số bài AC, điểm thang 10 và tỷ lệ AC -->
      <div class="overflow-x-auto">
        <table class="w-full text-left border-collapse text-xs">
          <thead>
            <tr class="bg-white/5 text-[10px] uppercase tracking-wider text-slate-400 font-bold border-b border-white/10">
              <th class="py-3 px-4 text-center">Hạng</th>
              <th class="py-3 px-4">Mã SV</th>
              <th class="py-3 px-4">Họ và tên</th>
              <th class="py-3 px-4">Lớp</th>
              <th class="py-3 px-4 text-center">Bài giải đúng (AC)</th>
              <th class="py-3 px-4 text-center">Điểm thang 10</th>
              <th class="py-3 px-4 text-center">Tổng nộp</th>
              <th class="py-3 px-4 text-center">AC / WA / TLE</th>
              <th class="py-3 px-4 text-center">Tỷ lệ AC</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-white/5">
            <?php if (empty($studentReport)): ?>
              <tr>
                <td colspan="9" class="py-10 text-center text-slate-500">Chưa có dữ liệu sinh viên nào khớp bộ lọc.</td>
              </tr>
            <?php else: ?>
              <?php foreach ($studentReport as $idx => $st): ?>
                <?php
                  // Tính toán tỷ lệ AC (%) và quy đổi điểm thang 10 theo tỷ lệ bài giải đúng trên tổng bài mở
                  $tot = (int)$st['total_submissions'];
                  $ac  = (int)$st['ac_submissions'];
                  $solved = (int)$st['solved_problems'];
                  $acRate = $tot > 0 ? round(($ac / $tot) * 100, 1) : 0;
                  $score10 = round(($solved / $totalActiveProblems) * 10, 2);
                ?>
                <tr class="hover:bg-white/[0.02] transition-colors">
                  <td class="py-3 px-4 text-center font-mono font-bold text-slate-400">#<?= $idx + 1 ?></td>
                  <td class="py-3 px-4 font-mono font-bold text-cyan-300"><?= Security::escape($st['student_code'] ?: '—') ?></td>
                  <td class="py-3 px-4">
                    <div class="font-bold text-white"><?= Security::escape($st['full_name']) ?></div>
                    <div class="text-[10px] text-slate-500 font-mono">@<?= Security::escape($st['username']) ?></div>
                  </td>
                  <td class="py-3 px-4 text-slate-300"><?= Security::escape($st['class_name'] ?: '—') ?></td>
                  <td class="py-3 px-4 text-center font-mono font-bold text-emerald-400"><?= $solved ?> / <?= $totalActiveProblems ?></td>
                  <td class="py-3 px-4 text-center">
                    <span class="px-2.5 py-0.5 rounded-lg font-mono font-black <?= $score10 >= 8 ? 'bg-emerald-500/20 text-emerald-300 border border-emerald-500/30' : ($score10 >= 5 ? 'bg-amber-500/20 text-amber-300 border border-amber-500/30' : 'bg-white/5 text-slate-300 border border-white/10') ?>">
                      <?= number_format($score10, 2) ?>
                    </span>
                  </td>
                  <td class="py-3 px-4 text-center font-mono text-slate-300"><?= $tot ?></td>
                  <td class="py-3 px-4 text-center font-mono text-[11px]">
                    <span class="text-emerald-400"><?= $ac ?></span> /
                    <span class="text-rose-400"><?= (int)$st['wa_submissions'] ?></span> /
                    <span class="text-amber-400"><?= (int)$st['tle_submissions'] ?></span>
                  </td>
                  <td class="py-3 px-4 text-center font-mono font-bold text-cyan-300"><?= $acRate ?>%</td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

    <!-- 4. BẢNG THỐNG KÊ TỶ LỆ HOÀN THÀNH THEO TỪNG BÀI TẬP (TOP 20 BÀI NỘP NHIỀU NHẤT) -->
    <div class="glass-panel rounded-2xl border border-white/10 overflow-hidden shadow-2xl">
      <div class="px-6 py-4 border-b border-white/10 bg-white/[0.02]">
        <h2 class="text-base font-bold text-white flex items-center space-x-2">
          <i class="fa-solid fa-ranking-star text-amber-400"></i>
          <span>Tỷ lệ Hoàn thành theo Từng Bài tập</span>
        </h2>
      </div>
      <div class="overflow-x-auto">
        <table class="w-full text-left border-collapse text-xs">
          <thead>
            <tr class="bg-white/5 text-[10px] uppercase tracking-wider text-slate-400 font-bold border-b border-white/10">
              <th class="py-3 px-5">Bài tập</th>
              <th class="py-3 px-4 text-center">Độ khó</th>
              <th class="py-3 px-4 text-center">Tổng nộp</th>
              <th class="py-3 px-4 text-center">AC / WA / TLE</th>
              <th class="py-3 px-4 text-center">SV giải được</th>
              <th class="py-3 px-5 text-right">Tỷ lệ AC</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-white/5">
            <?php foreach ($problemStats as $ps): ?>
              <?php
                // Tính tỷ lệ bài nộp đúng (AC) riêng của từng bài tập
                $ptot = (int)$ps['total_subs'];
                $pac  = (int)$ps['ac_subs'];
                $prate = $ptot > 0 ? round(($pac / $ptot) * 100, 1) : 0;
              ?>
              <tr class="hover:bg-white/[0.02]">
                <td class="py-3 px-5">
                  <div class="font-bold text-white"><?= Security::escape($ps['title']) ?></div>
                  <div class="text-[10px] font-mono text-cyan-400"><?= Security::escape($ps['slug']) ?></div>
                </td>
                <td class="py-3 px-4 text-center font-semibold text-slate-300"><?= Security::escape($ps['difficulty']) ?></td>
                <td class="py-3 px-4 text-center font-mono text-white font-bold"><?= $ptot ?></td>
                <td class="py-3 px-4 text-center font-mono">
                  <span class="text-emerald-400"><?= $pac ?></span> /
                  <span class="text-rose-400"><?= (int)$ps['wa_subs'] ?></span> /
                  <span class="text-amber-400"><?= (int)$ps['tle_subs'] ?></span>
                </td>
                <td class="py-3 px-4 text-center font-mono text-cyan-300 font-bold"><?= (int)$ps['unique_ac_users'] ?></td>
                <td class="py-3 px-5 text-right font-mono font-bold <?= $prate >= 50 ? 'text-emerald-400' : 'text-amber-300' ?>"><?= $prate ?>%</td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </main>
</div>

<?php
// Nạp phần chân trang chung (đóng thẻ body/html và các script tiện ích dùng chung)
require_once __DIR__ . '/includes/footer.php';
?>
