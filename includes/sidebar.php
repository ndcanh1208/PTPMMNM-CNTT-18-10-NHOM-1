<?php
/**
 * ==============================================================================
 * Nhom1 Online Judge - Thanh điều hướng bên trái cố định (Left Sidebar)
 * ==============================================================================
 * Phong cách thiết kế: Dark Glassmorphism (Kính mờ nền tối), Neon Cyan Accent, Micro-interaction.
 * Mục đích của file này:
 * - Hiển thị Logo thương hiệu và danh sách liên kết điều hướng (Menu) giữa các trang chính.
 * - Tự động tô sáng (active highlight) mục menu tương ứng với trang hiện tại người dùng đang mở.
 * - Tự động ẩn/hiện các mục quản trị dựa theo vai trò tài khoản (Sinh viên / Giảng viên / Quản trị viên).
 * - Hiển thị trạng thái Worker chấm bài (số lượng bài đang nằm trong hàng đợi) và khu vực tài khoản (đăng nhập/đăng xuất).
 */

// Bật chế độ kiểm tra kiểu dữ liệu nghiêm ngặt trong PHP
declare(strict_types=1);

// Nạp lớp Auth (xác thực & kiểm tra quyền) và hàm kết nối CSDL getDbConnection()
require_once __DIR__ . '/../core/Auth.php';
require_once __DIR__ . '/../config/database.php';

// Lấy thông tin người dùng đang đăng nhập (null nếu là khách)
$currentUser = Auth::user();

// Lấy tên tệp PHP đang chạy hiện tại (VD: 'index.php', 'problems.php', 'solve.php'...)
// Dùng để so sánh và tô sáng màu xanh Neon cho mục menu đang được chọn.
$currentScript = basename($_SERVER['SCRIPT_NAME'] ?? '');

// ==============================================================================
// TRUY VẤN CƠ SỞ DỮ LIỆU: KIỂM TRA HÀNG ĐỢI CHẤM BÀI (WORKER QUEUE)
// ==============================================================================
// Lấy đối tượng kết nối PDO tới MySQL
$pdo = getDbConnection();

// Câu SQL 1: Đếm số lượt chấm trong bảng `judging_runs` đang có trạng thái 'Queued' (đang chờ chấm).
// fetchColumn() lấy giá trị của cột đầu tiên trong dòng kết quả đầu tiên, sau đó ép kiểu sang số nguyên (int).
$queueCount = (int)$pdo->query("SELECT COUNT(*) FROM judging_runs WHERE status = 'Queued'")->fetchColumn();

// Câu SQL 2: Lấy thời điểm bắt đầu chấm gần nhất (MAX(started_at)) từ bảng `judging_runs` để theo dõi hoạt động Worker.
$lastRunTime = $pdo->query("SELECT MAX(started_at) FROM judging_runs")->fetchColumn();

// Cờ đánh giá trạng thái hoạt động của Worker chấm bài (mặc định true = đang sẵn sàng)
$isWorkerActive = true;
?>

<!-- ==============================================================================
     LEFT SIDEBAR CỐ ĐỊNH BÊN TRÁI MÀN HÌNH
     - fixed top-0 left-0: ghim cố định góc trên-trái khi cuộn trang
     - h-screen w-64: cao 100% khung nhìn, rộng 256px (16rem)
     - glass-panel: hiệu ứng kính mờ nền tối định nghĩa trong style.css
     - flex flex-col justify-between: chia dọc làm 2 nhóm (trên: Logo + Menu; dưới: Worker + Tài khoản)
     ============================================================================== -->
<aside class="fixed top-0 left-0 h-screen w-64 glass-panel border-r border-white/10 flex flex-col justify-between z-30 transition-all duration-300">
  <!-- NHÓM PHÍA TRÊN: Logo thương hiệu + Thanh menu điều hướng -->
  <div>
    <!-- Khối Logo & Tên thương hiệu (bấm vào sẽ quay về trang chủ index.php) -->
    <div class="h-20 flex items-center px-6 border-b border-white/10">
      <a href="/BaiTapLon/index.php" class="flex items-center space-x-3 group">
        <!-- Hộp biểu tượng dấu code </> có nền chuyển sắc Cyan -> Xanh dương và hiệu ứng phóng nhẹ khi rê chuột (group-hover:scale-105) -->
        <div class="w-10 h-10 rounded-xl bg-gradient-to-tr from-cyan-500 to-blue-600 flex items-center justify-center text-black font-black text-xl shadow-[0_0_15px_rgba(0,255,255,0.4)] group-hover:scale-105 transition-transform duration-300">
          <i class="fa-solid fa-code text-[#050507]"></i>
        </div>
        <!-- Tên hệ thống "Nhom1" và dòng phụ đề "Online Judge" -->
        <div>
          <span class="text-xl font-black tracking-wider text-white">Nhom<span class="text-cyan-400">1</span></span>
          <span class="block text-[10px] uppercase tracking-widest text-slate-400 font-semibold">Online Judge</span>
        </div>
      </a>
    </div>

    <!-- Danh sách Điều hướng chính (Navigation Links) -->
    <nav class="p-4 space-y-1.5">
      <!-- Mục 1: Trang chủ / Tổng quan (tô sáng nền Cyan khi $currentScript là 'index.php') -->
      <a href="/BaiTapLon/index.php" class="flex items-center space-x-3 px-4 py-3 rounded-xl text-sm font-medium transition-all duration-200 <?= $currentScript === 'index.php' ? 'bg-cyan-500/10 text-cyan-300 border border-cyan-500/30 shadow-[0_0_12px_rgba(0,255,255,0.2)]' : 'text-slate-400 hover:text-slate-100 hover:bg-white/5' ?>">
        <i class="fa-solid fa-gauge-high w-5 text-center text-base"></i>
        <span>Tổng quan</span>
      </a>

      <!-- Mục 2: Thư viện bài tập (tô sáng khi đang ở trang danh sách problems.php HOẶC trang giải bài solve.php) -->
      <a href="/BaiTapLon/problems.php" class="flex items-center space-x-3 px-4 py-3 rounded-xl text-sm font-medium transition-all duration-200 <?= $currentScript === 'problems.php' || $currentScript === 'solve.php' ? 'bg-cyan-500/10 text-cyan-300 border border-cyan-500/30 shadow-[0_0_12px_rgba(0,255,255,0.2)]' : 'text-slate-400 hover:text-slate-100 hover:bg-white/5' ?>">
        <i class="fa-solid fa-book-bookmark w-5 text-center text-base"></i>
        <span>Thư viện Bài tập</span>
      </a>

      <!-- Mục 3: Lịch sử nộp bài (xem lại các lần nộp code và kết quả chấm) -->
      <a href="/BaiTapLon/submissions.php" class="flex items-center space-x-3 px-4 py-3 rounded-xl text-sm font-medium transition-all duration-200 <?= $currentScript === 'submissions.php' ? 'bg-cyan-500/10 text-cyan-300 border border-cyan-500/30 shadow-[0_0_12px_rgba(0,255,255,0.2)]' : 'text-slate-400 hover:text-slate-100 hover:bg-white/5' ?>">
        <i class="fa-solid fa-clock-rotate-left w-5 text-center text-base"></i>
        <span>Lịch sử Nộp bài</span>
      </a>

      <!-- ======================================================================
           PHÂN HỆ DÀNH RIÊNG CHO GIẢNG VIÊN (Instructor) VÀ QUẢN TRỊ VIÊN (Admin)
           Khối menu dưới đây chỉ được sinh ra trong HTML nếu người dùng có quyền tương ứng.
           ====================================================================== -->
      <?php if (Auth::isInstructor() || Auth::isAdmin()): ?>
        <!-- Tiêu đề ngăn cách nhóm menu quản lý (đổi chữ tùy theo là Admin hay Giảng viên) -->
        <div class="pt-4 pb-2 px-4 text-[11px] font-bold uppercase tracking-wider text-slate-500">
          <?= Auth::isAdmin() ? 'Phân hệ Quản trị viên' : 'Phân hệ Giảng viên' ?>
        </div>

        <!-- 1 & 2. Quản lý bài tập, Phiên bản đề & Bộ Testcase (cả Giảng viên và Admin đều dùng được) -->
        <a href="/BaiTapLon/manage_problems.php" class="flex items-center space-x-3 px-4 py-2.5 rounded-xl text-sm font-medium transition-all duration-200 <?= $currentScript === 'manage_problems.php' ? 'bg-cyan-500/10 text-cyan-300 border border-cyan-500/30 shadow-[0_0_12px_rgba(0,255,255,0.2)]' : 'text-slate-400 hover:text-slate-100 hover:bg-white/5' ?>">
          <i class="fa-solid fa-sliders w-5 text-center text-base"></i>
          <span>Quản lý Đề & Bộ Test</span>
        </a>

        <!-- Chỉ riêng Quản trị viên (Admin) mới thấy mục Vận hành hệ thống chấm -->
        <?php if (Auth::isAdmin()): ?>
          <!-- 3. Quản lý Chấm bài, Hàng đợi & Vận hành Hệ thống -->
          <a href="/BaiTapLon/admin_judging.php" class="flex items-center space-x-3 px-4 py-2.5 rounded-xl text-sm font-medium transition-all duration-200 <?= $currentScript === 'admin_judging.php' ? 'bg-cyan-500/10 text-cyan-300 border border-cyan-500/30 shadow-[0_0_12px_rgba(0,255,255,0.2)]' : 'text-slate-400 hover:text-slate-100 hover:bg-white/5' ?>">
            <i class="fa-solid fa-server w-5 text-center text-base"></i>
            <span>Vận hành Chấm & Rejudge</span>
          </a>
        <?php endif; ?>

        <!-- Trang Chấm lại (Rejudge) & Đối chiếu kết quả giữa các phiên bản bộ test (Giảng viên & Admin) -->
        <a href="/BaiTapLon/rejudge_compare.php" class="flex items-center space-x-3 px-4 py-2.5 rounded-xl text-sm font-medium transition-all duration-200 <?= $currentScript === 'rejudge_compare.php' ? 'bg-cyan-500/10 text-cyan-300 border border-cyan-500/30 shadow-[0_0_12px_rgba(0,255,255,0.2)]' : 'text-slate-400 hover:text-slate-100 hover:bg-white/5' ?>">
          <i class="fa-solid fa-code-compare w-5 text-center text-base"></i>
          <span>Chấm lại & Đối chiếu</span>
        </a>

        <!-- Các chức năng quản trị cấp cao dành riêng cho Quản trị viên (Admin) -->
        <?php if (Auth::isAdmin()): ?>
          <!-- 4. Quản lý Người dùng, phân quyền & khóa/mở tài khoản -->
          <a href="/BaiTapLon/admin_users.php" class="flex items-center space-x-3 px-4 py-2.5 rounded-xl text-sm font-medium transition-all duration-200 <?= $currentScript === 'admin_users.php' ? 'bg-cyan-500/10 text-cyan-300 border border-cyan-500/30 shadow-[0_0_12px_rgba(0,255,255,0.2)]' : 'text-slate-400 hover:text-slate-100 hover:bg-white/5' ?>">
            <i class="fa-solid fa-users-gear w-5 text-center text-base"></i>
            <span>Quản lý Người dùng</span>
          </a>

          <!-- 5. Thống kê tổng quan & Báo cáo tình hình học tập / tỷ lệ đúng -->
          <a href="/BaiTapLon/admin_analytics.php" class="flex items-center space-x-3 px-4 py-2.5 rounded-xl text-sm font-medium transition-all duration-200 <?= $currentScript === 'admin_analytics.php' ? 'bg-cyan-500/10 text-cyan-300 border border-cyan-500/30 shadow-[0_0_12px_rgba(0,255,255,0.2)]' : 'text-slate-400 hover:text-slate-100 hover:bg-white/5' ?>">
            <i class="fa-solid fa-chart-pie w-5 text-center text-base"></i>
            <span>Thống kê & Báo cáo</span>
          </a>
        <?php endif; ?>
      <?php endif; ?>
    </nav>
  </div>

  <!-- NHÓM PHÍA DƯỚI (CHÂN SIDEBAR): Trạng thái Worker chấm bài & Thông tin Tài khoản -->
  <div class="p-4 border-t border-white/10 space-y-3">
    <!-- Khung hiển thị trạng thái dịch vụ thực thi chấm code (Worker Status) -->
    <div class="px-3 py-2 rounded-lg bg-black/40 border border-white/5 flex items-center justify-between text-xs">
      <div class="flex items-center space-x-2">
        <!-- Chấm tròn xanh phát sóng (hiệu ứng radar animate-ping của Tailwind) báo hiệu Worker đang hoạt động -->
        <span class="relative flex h-2.5 w-2.5">
          <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-cyan-400 opacity-75"></span>
          <span class="relative inline-flex rounded-full h-2.5 w-2.5 bg-cyan-400"></span>
        </span>
        <span class="font-medium text-slate-300">Worker Chấm</span>
      </div>
      <!-- Nhãn số lượng bài đang chờ: nếu $queueCount > 0 hiện màu vàng cam + số bài, ngược lại hiện màu xanh lá "Sẵn sàng" -->
      <span class="px-1.5 py-0.5 rounded text-[11px] font-mono <?= $queueCount > 0 ? 'bg-amber-500/20 text-amber-300 border border-amber-500/30' : 'bg-emerald-500/20 text-emerald-300 border border-emerald-500/30' ?>">
        <?= $queueCount > 0 ? "Hàng đợi: {$queueCount}" : "Sẵn sàng" ?>
      </span>
    </div>

    <!-- Khu vực Thông tin người dùng (nếu đã đăng nhập) hoặc nút Đăng nhập / Đăng ký (nếu là khách) -->
    <?php if ($currentUser): ?>
      <!-- Thẻ hồ sơ nhanh của người dùng đang đăng nhập -->
      <div class="flex items-center justify-between p-2 rounded-xl bg-white/5 border border-white/10">
        <div class="flex items-center space-x-3 overflow-hidden">
          <!-- Ảnh đại diện chữ cái (Avatar): lấy ký tự đầu tiên của username và chuyển thành chữ IN HOA -->
          <div class="w-9 h-9 rounded-lg bg-gradient-to-tr from-cyan-600 to-blue-500 flex items-center justify-center font-bold text-white shadow-md text-sm shrink-0">
            <?= strtoupper(substr($currentUser['username'], 0, 1)) ?>
          </div>
          <!-- Họ tên đầy đủ và vai trò (dùng Security::escape() chống XSS; class truncate cắt dấu '...' nếu tên quá dài) -->
          <div class="truncate">
            <div class="text-xs font-semibold text-white truncate"><?= Security::escape($currentUser['full_name']) ?></div>
            <div class="text-[10px] text-cyan-400 font-mono capitalize"><?= Security::escape($currentUser['role']) ?></div>
          </div>
        </div>
        <!-- Nút biểu tượng Đăng xuất: gửi tham số ?logout=1 tới login.php để hủy phiên đăng nhập -->
        <a href="/BaiTapLon/login.php?logout=1" title="Đăng xuất" class="p-1.5 text-slate-400 hover:text-rose-400 transition-colors">
          <i class="fa-solid fa-right-from-bracket"></i>
        </a>
      </div>
    <?php else: ?>
      <!-- Hiển thị khi chưa đăng nhập: 2 nút dẫn tới trang Đăng nhập và trang Đăng ký tài khoản mới -->
      <div class="space-y-2">
        <a href="/BaiTapLon/login.php" class="w-full btn-cyan flex items-center justify-center space-x-2 text-xs py-2.5 font-bold">
          <i class="fa-solid fa-arrow-right-to-bracket"></i>
          <span>Đăng nhập hệ thống</span>
        </a>
        <a href="/BaiTapLon/register.php" class="w-full flex items-center justify-center space-x-2 text-xs py-2 rounded-xl bg-white/5 hover:bg-white/10 text-slate-300 hover:text-white border border-white/10 transition-colors font-semibold">
          <i class="fa-solid fa-user-plus"></i>
          <span>Đăng ký tài khoản</span>
        </a>
      </div>
    <?php endif; ?>
  </div>
</aside>