<?php
/**
 * Nhom1 Online Judge - Trang Chủ & Bảng điều khiển Tổng quan (Dashboard)
 */

// ============================================================================
// PHẦN 1: XỬ LÝ PHP & TRUY VẤN CƠ SỞ DỮ LIỆU (CHẠY TRÊN MÁY CHỦ TRƯỚC KHI VẼ HTML)
// ============================================================================

// Bật chế độ kiểm tra kiểu dữ liệu nghiêm ngặt (strict_types) giúp hạn chế lỗi ép kiểu ngầm trong PHP
declare(strict_types=1);

// Nạp các tệp cấu hình và lớp tiện ích lõi (dùng require_once để đảm bảo mỗi tệp chỉ được nạp 1 lần)
require_once __DIR__ . '/config/database.php'; // Cung cấp hàm getDbConnection() kết nối MySQL qua PDO
require_once __DIR__ . '/core/Auth.php';       // Quản lý phiên đăng nhập (session) và kiểm tra vai trò người dùng
require_once __DIR__ . '/core/Security.php';   // Cung cấp hàm Security::escape() chống tấn công XSS khi in dữ liệu ra HTML

// Khởi động phiên làm việc (session) để đọc thông tin người dùng đang đăng nhập
Auth::startSession();
// Lấy mảng thông tin tài khoản hiện tại (trả về null nếu là khách chưa đăng nhập)
$currentUser = Auth::user();
// Mở kết nối đến cơ sở dữ liệu MySQL thông qua đối tượng PDO
$pdo = getDbConnection();

// 1. Thống kê tổng quan toàn hệ thống (dùng fetchColumn() để lấy giá trị duy nhất ở cột đầu tiên và ép sang kiểu số nguyên int)
// - Đếm tổng số bài tập đang được công khai (is_public = 1) và chưa bị xóa mềm (deleted_at IS NULL)
$totalProblems = (int)$pdo->query("SELECT COUNT(*) FROM problems WHERE is_public = 1 AND deleted_at IS NULL")->fetchColumn();
// - Đếm tổng số lượt nộp bài trên toàn hệ thống trong bảng submissions
$totalSubmissions = (int)$pdo->query("SELECT COUNT(*) FROM submissions")->fetchColumn();
// - Đếm số lượt chấm đang nằm trong hàng đợi chờ Worker xử lý (status = 'Queued')
$queueCount = (int)$pdo->query("SELECT COUNT(*) FROM judging_runs WHERE status = 'Queued'")->fetchColumn();

// Thống kê riêng cho người học đã đăng nhập: đếm số bài tập khác nhau đã giải đúng (AC)
$solvedCount = 0;
if ($currentUser) {
    // Dùng Prepared Statement (dấu ? làm tham số giữ chỗ) để truyền user_id an toàn, tránh lỗi bảo mật SQL Injection
    // COUNT(DISTINCT s.problem_id) đảm bảo nếu 1 bài nộp đúng nhiều lần thì vẫn chỉ tính là 1 bài đã hoàn thành
    $solvedStmt = $pdo->prepare("
        SELECT COUNT(DISTINCT s.problem_id) 
        FROM submissions s
        JOIN problems p ON s.problem_id = p.id
        WHERE s.user_id = ? AND s.final_verdict = 'AC' AND p.deleted_at IS NULL
    ");
    // Thực thi câu truy vấn với ID của người dùng đang đăng nhập
    $solvedStmt->execute([$currentUser['id']]);
    // Lấy kết quả đếm được và ép sang kiểu số nguyên
    $solvedCount = (int)$solvedStmt->fetchColumn();
}

// 2. Lấy danh sách 6 bài tập nổi bật kèm thông tin phiên bản bộ test hiện tại
// Dùng JOIN giữa bảng problems (p) và problem_versions (pv) thông qua khóa current_version_id
// Chỉ lấy bài công khai, chưa xóa, sắp xếp theo ID tăng dần và giới hạn tối đa 6 bài (LIMIT 6)
$probStmt = $pdo->query("
    SELECT p.id, p.slug, p.title, p.topic, p.difficulty, p.source_attribution,
           pv.version_number, pv.time_limit_ms, pv.memory_limit_kb
    FROM problems p
    JOIN problem_versions pv ON p.current_version_id = pv.id
    WHERE p.is_public = 1 AND p.deleted_at IS NULL
    ORDER BY p.id ASC
    LIMIT 6
");
// Lấy toàn bộ các dòng kết quả dưới dạng mảng để dùng trong vòng lặp HTML bên dưới
$featuredProblems = $probStmt->fetchAll();

// 3. Lấy 5 lượt nộp bài gần đây nhất trên toàn hệ thống
// JOIN bảng submissions (s) với problems (p) để lấy tên bài tập, và bảng users (u) để lấy tên người nộp
// Sắp xếp theo s.id DESC để bài mới nộp nhất hiện lên đầu tiên
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

// Thiết lập tiêu đề hiển thị trên tab trình duyệt (được dùng bên trong includes/header.php)
$pageTitle = 'Tổng quan hệ thống';
// Nạp phần khung đầu HTML (<head>, CSS, mở <body>) và thanh điều hướng bên trái (Sidebar)
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/sidebar.php';
?>

<!-- ========================================================================= -->
<!-- PHẦN 2: GIAO DIỆN HTML (HIỂN THỊ TRÊN TRÌNH DUYỆT NGƯỜI DÙNG)              -->
<!-- ========================================================================= -->

<!-- MAIN CONTENT WRAPPER (Cách lề trái pl-64 = 256px để không bị che bởi Sidebar cố định bên trái) -->
<div class="pl-64 min-h-screen flex flex-col w-full">
  <main class="p-8 max-w-7xl w-full mx-auto space-y-8 flex-1">
    
    <!-- Khối tiêu đề trang Tổng quan và nhóm nút điều hướng nhanh bên phải -->
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
      <div>
        <h1 class="text-2xl font-black tracking-tight text-white flex items-center space-x-2.5">
          <i class="fa-solid fa-gauge-high text-cyan-400"></i>
          <span>Tổng quan Hệ thống</span>
        </h1>
        <p class="text-xs text-slate-400 mt-1">
          Nền tảng luyện tập và chấm bài lập trình trực tuyến (Python 3 &amp; C++17).
        </p>
      </div>
      <!-- Nhóm nút hành động nhanh: hiển thị linh hoạt tùy theo vai trò và trạng thái đăng nhập -->
      <div class="flex flex-wrap items-center gap-2.5">
        <!-- Nút chuyển sang trang Thư viện bài tập -->
        <a href="/BaiTapLon/problems.php" class="btn-cyan flex items-center space-x-2 text-xs py-2.5 px-4 font-bold">
          <i class="fa-solid fa-play text-[10px]"></i>
          <span>Bắt đầu Luyện tập</span>
        </a>
        <!-- Chỉ hiển thị nút "Tạo đề bài mới" nếu người dùng là Giảng viên hoặc Quản trị viên -->
        <?php if (Auth::isInstructor() || Auth::isAdmin()): ?>
          <a href="/BaiTapLon/manage_problems.php" class="btn-glass flex items-center space-x-2 text-xs py-2.5 px-4 font-semibold text-cyan-300 border-cyan-500/30">
            <i class="fa-solid fa-plus text-[10px]"></i>
            <span>Tạo đề bài mới</span>
          </a>
        <?php endif; ?>
        <!-- Nếu chưa đăng nhập thì hiện nút Đăng nhập; ngược lại hiện nút xem lịch sử Bài nộp của tôi -->
        <?php if (!$currentUser): ?>
          <a href="/BaiTapLon/login.php" class="btn-glass flex items-center space-x-2 text-xs py-2.5 px-4 font-semibold">
            <i class="fa-solid fa-arrow-right-to-bracket text-[10px]"></i>
            <span>Đăng nhập</span>
          </a>
        <?php else: ?>
          <a href="/BaiTapLon/submissions.php" class="btn-glass flex items-center space-x-2 text-xs py-2.5 px-4 font-semibold">
            <i class="fa-solid fa-clock-rotate-left text-[10px]"></i>
            <span>Bài nộp của tôi</span>
          </a>
        <?php endif; ?>
      </div>
    </div>

    <!-- Khối 4 Thẻ Thống kê Chính (chia 1 cột trên di động, 2 cột trên tablet, 4 cột trên màn hình lớn) -->
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-5">
      <!-- Thẻ 1: Hiển thị tổng số bài tập công khai có sẵn -->
      <div class="glass-card rounded-2xl p-5 border border-white/10 flex items-center space-x-4">
        <div class="w-12 h-12 rounded-xl bg-cyan-500/15 border border-cyan-500/30 text-cyan-400 flex items-center justify-center text-xl shrink-0">
          <i class="fa-solid fa-code"></i>
        </div>
        <div>
          <div class="text-2xl font-black text-white"><?= $totalProblems ?></div>
          <div class="text-xs text-slate-400 font-medium">Bài tập có sẵn</div>
        </div>
      </div>

      <!-- Thẻ 2: Hiển thị tiến độ số bài đã giải đúng (AC) của cá nhân trên tổng số bài -->
      <div class="glass-card rounded-2xl p-5 border border-white/10 flex items-center space-x-4">
        <div class="w-12 h-12 rounded-xl bg-emerald-500/15 border border-emerald-500/30 text-emerald-400 flex items-center justify-center text-xl shrink-0">
          <i class="fa-solid fa-circle-check"></i>
        </div>
        <div>
          <div class="text-2xl font-black text-white"><?= $solvedCount ?> / <?= $totalProblems ?></div>
          <div class="text-xs text-slate-400 font-medium"><?= $currentUser ? 'Bài đã hoàn thành' : 'Đăng nhập để xem' ?></div>
        </div>
      </div>

      <!-- Thẻ 3: Hiển thị tổng lượt nộp bài từ trước tới nay của toàn bộ người dùng -->
      <div class="glass-card rounded-2xl p-5 border border-white/10 flex items-center space-x-4">
        <div class="w-12 h-12 rounded-xl bg-blue-500/15 border border-blue-500/30 text-blue-400 flex items-center justify-center text-xl shrink-0">
          <i class="fa-solid fa-paper-plane"></i>
        </div>
        <div>
          <div class="text-2xl font-black text-white"><?= $totalSubmissions ?></div>
          <div class="text-xs text-slate-400 font-medium">Tổng lượt nộp toàn sàn</div>
        </div>
      </div>

      <!-- Thẻ 4: Hiển thị trạng thái máy chấm (Worker) và số lượng bài đang chờ trong hàng đợi -->
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

    <!-- Lưới 2 Cột (tỷ lệ 2:1 trên màn hình lớn): Cột trái là Bài tập nổi bật, Cột phải là Bài nộp gần đây & Kỹ thuật -->
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
      
      <!-- Cột trái (chiếm 2/3 chiều rộng): Danh sách Bài tập Nổi bật -->
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

        <!-- Vòng lặp PHP in ra từng thẻ bài tập từ mảng $featuredProblems -->
        <div class="space-y-3">
          <?php foreach ($featuredProblems as $prob): ?>
            <?php
              // Bảng ánh xạ độ khó (Easy/Medium/Hard) sang bộ class màu nền, chữ và viền của Tailwind CSS
              $diffColors = [
                'Easy'   => 'bg-emerald-500/15 text-emerald-400 border-emerald-500/30',
                'Medium' => 'bg-amber-500/15 text-amber-400 border-amber-500/30',
                'Hard'   => 'bg-rose-500/15 text-rose-400 border-rose-500/30'
              ];
            ?>
            <!-- Thẻ hiển thị 1 bài tập nổi bật -->
            <div class="glass-card rounded-2xl p-5 border border-white/10 flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4">
              <div class="space-y-1.5 flex-1">
                <!-- Hàng nhãn phụ: Độ khó, Phiên bản đề bài và Chủ đề -->
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
                <!-- Tiêu đề bài tập kèm liên kết sang trang giải bài solve.php?id=... -->
                <h3 class="text-base font-bold text-white hover:text-cyan-400 transition-colors">
                  <a href="/BaiTapLon/solve.php?id=<?= $prob['id'] ?>">
                    #<?= $prob['id'] ?>. <?= Security::escape($prob['title']) ?>
                  </a>
                </h3>
                <!-- Ghi chú nguồn gốc / bản quyền của đề bài (được escape để tránh XSS) -->
                <div class="text-[11px] text-slate-400 truncate">
                  <i class="fa-regular fa-copyright mr-1"></i><?= Security::escape($prob['source_attribution']) ?>
                </div>
              </div>

              <!-- Nút dẫn trực tiếp sang trang viết code và nộp bài cho bài tập này -->
              <a href="/BaiTapLon/solve.php?id=<?= $prob['id'] ?>" class="btn-cyan text-xs py-2 px-4 shrink-0 flex items-center space-x-1.5">
                <span>Giải bài</span>
                <i class="fa-solid fa-arrow-right text-[10px]"></i>
              </a>
            </div>
          <?php endforeach; ?>
        </div>
      </div>

      <!-- Cột phải (chiếm 1/3 chiều rộng): Hoạt động nộp bài gần đây & Đặc tính kỹ thuật hệ thống -->
      <div class="space-y-6">
        
        <!-- Khối 1 cột phải: Danh sách 5 lượt nộp bài gần đây nhất -->
        <div class="glass-panel rounded-2xl p-5 border border-white/10 space-y-4">
          <h2 class="text-base font-bold text-white flex items-center space-x-2">
            <i class="fa-solid fa-bolt text-cyan-400 text-sm"></i>
            <span>Nộp bài gần đây</span>
          </h2>

          <!-- Kiểm tra nếu chưa có lượt nộp nào thì hiển thị thông báo trống -->
          <?php if (empty($recentSubmissions)): ?>
            <div class="text-xs text-slate-500 py-4 text-center">Chưa có lượt nộp bài nào.</div>
          <?php else: ?>
            <div class="space-y-3">
              <?php foreach ($recentSubmissions as $sub): ?>
                <?php
                  // Lấy kết quả chấm cuối cùng (AC, WA, TLE, RE, CE...) và chọn class CSS tô màu nhãn tương ứng bằng biểu thức match
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
                <!-- Một dòng tóm tắt lượt nộp: tên bài, người nộp, ngôn ngữ lập trình và nhãn kết quả -->
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

        <!-- Khối 2 cột phải: Giới thiệu các nguyên tắc kỹ thuật & bảo mật cốt lõi của hệ thống chấm bài -->
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
<!-- Nạp phần chân trang chung (đóng thẻ layout và nhúng các hàm tiện ích JavaScript dùng chung) -->
<?php require_once __DIR__ . '/includes/footer.php'; ?>
