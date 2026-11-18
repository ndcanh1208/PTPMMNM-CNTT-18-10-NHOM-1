<?php
/**
 * Nhom1 Online Judge - Trang Đăng nhập Chính thức
 */

// ============================================================================
// PHẦN 1: XỬ LÝ PHP (ĐĂNG XUẤT, KIỂM TRA PHIÊN & XÁC THỰC FORM ĐĂNG NHẬP)
// ============================================================================

// Bật chế độ kiểm tra kiểu dữ liệu nghiêm ngặt trong PHP
declare(strict_types=1);

// Nạp lớp quản lý đăng nhập (Auth) và lớp bảo mật chống XSS (Security)
require_once __DIR__ . '/core/Auth.php';
require_once __DIR__ . '/core/Security.php';

// Khởi động phiên làm việc (session)
Auth::startSession();

// Xử lý đăng xuất khi URL có tham số ?logout (ví dụ: login.php?logout=1)
if (isset($_GET['logout'])) {
    // Xóa toàn bộ dữ liệu phiên đăng nhập hiện tại
    Auth::logout();
    // Chuyển hướng về lại trang đăng nhập sạch (bỏ tham số ?logout trên URL)
    header('Location: /BaiTapLon/login.php');
    exit;
}

// Nếu người dùng đã đăng nhập sẵn rồi thì chuyển thẳng về trang chủ, không cần hiện lại form đăng nhập
if (Auth::check()) {
    header('Location: /BaiTapLon/index.php');
    exit;
}

// Khởi tạo các biến lưu thông báo lỗi, thông báo thành công và giữ lại giá trị ô tên đăng nhập khi gửi form sai
$error = null;
$successMsg = null;
$usernameVal = '';

// Kiểm tra các tham số trạng thái trên URL (được chuyển hướng tới từ trang đăng ký, quên mật khẩu hoặc bị khóa tài khoản)
if (isset($_GET['registered'])) {
    $successMsg = 'Đăng ký tài khoản thành công! Vui lòng đăng nhập bằng tài khoản vừa tạo.';
} elseif (isset($_GET['reset'])) {
    $successMsg = 'Đặt lại mật khẩu thành công! Vui lòng đăng nhập bằng mật khẩu mới.';
}
if (isset($_GET['locked'])) {
    $error = 'Phiên làm việc đã kết thúc do tài khoản của bạn đang bị khóa. Vui lòng liên hệ Quản trị viên.';
}

// Chỉ chạy khối xác thực khi người dùng bấm nút gửi form (phương thức HTTP POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Lấy thông tin định danh (Tên đăng nhập / Email / Mã sinh viên) và cắt khoảng trắng thừa 2 đầu
    $identifier = trim((string)($_POST['identifier'] ?? $_POST['username'] ?? ''));
    // Lấy mật khẩu (không trim để giữ nguyên mọi ký tự người dùng đã gõ)
    $password   = (string)($_POST['password'] ?? '');
    // Lưu lại giá trị vừa nhập để điền sẵn lại vào ô input nếu đăng nhập thất bại
    $usernameVal = $identifier;

    // Kiểm tra dữ liệu đầu vào cơ bản: không được để trống tài khoản hoặc mật khẩu
    if ($identifier === '' || $password === '') {
        $error = 'Vui lòng nhập đầy đủ Tên đăng nhập / Email / Mã sinh viên và Mật khẩu.';
    } else {
        // Gọi Auth::attempt() để đối chiếu tài khoản trong CSDL và kiểm tra mật khẩu băm (password_verify)
        if (Auth::attempt($identifier, $password)) {
            // Nếu URL có tham số ?redirect=... thì quay lại trang người dùng định truy cập trước đó, mặc định về trang chủ
            $redirect = $_GET['redirect'] ?? '/BaiTapLon/index.php';
            // Ngăn chặn lỗ hổng Open Redirect: chỉ cho phép chuyển hướng tới đường dẫn nội bộ bắt đầu bằng dấu '/'
            if (!str_starts_with((string)$redirect, '/')) {
                $redirect = '/BaiTapLon/index.php';
            }
            header("Location: {$redirect}");
            exit;
        } else {
            // Lấy thông báo lỗi cụ thể từ lớp Auth (ví dụ: tài khoản bị khóa) hoặc dùng thông báo mặc định
            $error = Auth::getLastError() ?? 'Tên đăng nhập hoặc mật khẩu không chính xác.';
        }
    }
}

// Thiết lập tiêu đề tab trình duyệt và nạp phần khung đầu HTML (trang đăng nhập không nạp sidebar)
$pageTitle = 'Đăng nhập hệ thống';
require_once __DIR__ . '/includes/header.php';
?>

<!-- ========================================================================= -->
<!-- PHẦN 2: GIAO DIỆN HTML TRANG ĐĂNG NHẬP (CĂN GIỮA TOÀN MÀN HÌNH)           -->
<!-- ========================================================================= -->

<!-- Khung bao ngoài căn giữa hộp đăng nhập theo cả chiều ngang và chiều dọc -->
<div class="w-full min-h-screen flex items-center justify-center p-4">
  <!-- Thẻ glass-panel tạo hiệu ứng kính mờ chứa toàn bộ nội dung form -->
  <div class="w-full max-w-md glass-panel rounded-2xl p-8 shadow-2xl relative overflow-hidden border border-white/10">
    <!-- Hiệu ứng 2 quầng sáng neon trang trí ở góc trên-trái và dưới-phải (không cản click nhờ pointer-events-none) -->
    <div class="absolute -top-24 -left-24 w-48 h-48 bg-cyan-500/20 rounded-full blur-3xl pointer-events-none"></div>
    <div class="absolute -bottom-24 -right-24 w-48 h-48 bg-blue-600/20 rounded-full blur-3xl pointer-events-none"></div>

    <!-- Khu vực Logo và Tiêu đề hệ thống -->
    <div class="text-center mb-8">
      <div class="inline-flex w-14 h-14 rounded-2xl bg-gradient-to-tr from-cyan-400 to-blue-600 items-center justify-center text-black font-black text-2xl shadow-[0_0_20px_rgba(0,255,255,0.4)] mb-3">
        <i class="fa-solid fa-code text-[#050507]"></i>
      </div>
      <h1 class="text-2xl font-black tracking-wide text-white">Đăng nhập Nhom<span class="text-cyan-400">1</span></h1>
      <p class="text-xs text-slate-400 mt-1">Hệ thống luyện tập & chấm bài lập trình trực tuyến</p>
    </div>

    <!-- Hộp thông báo màu xanh lá khi vừa đăng ký xong hoặc vừa đặt lại mật khẩu thành công -->
    <?php if ($successMsg): ?>
      <div class="mb-5 p-3.5 rounded-xl bg-emerald-500/10 border border-emerald-500/30 text-emerald-300 text-xs flex items-start space-x-2">
        <i class="fa-solid fa-circle-check mt-0.5 shrink-0"></i>
        <span><?= Security::escape($successMsg) ?></span>
      </div>
    <?php endif; ?>

    <!-- Hộp thông báo lỗi màu đỏ khi nhập sai tài khoản/mật khẩu hoặc tài khoản bị khóa -->
    <?php if ($error): ?>
      <div class="mb-5 p-3.5 rounded-xl bg-rose-500/10 border border-rose-500/30 text-rose-300 text-xs flex items-start space-x-2">
        <i class="fa-solid fa-triangle-exclamation mt-0.5 shrink-0"></i>
        <span><?= Security::escape($error) ?></span>
      </div>
    <?php endif; ?>

    <!-- Form Đăng nhập chính: gửi dữ liệu bằng phương thức POST về chính trang hiện tại (action="") -->
    <form action="" method="POST" class="space-y-4">
      <!-- Trường nhập 1: Tên đăng nhập / Email / Mã sinh viên -->
      <div>
        <label class="block text-xs font-semibold text-slate-300 uppercase tracking-wider mb-1.5">
          Tên đăng nhập / Email / Mã sinh viên
        </label>
        <div class="relative">
          <span class="absolute inset-y-0 left-0 flex items-center pl-3.5 text-slate-500">
            <i class="fa-solid fa-user text-sm"></i>
          </span>
          <!-- Dùng Security::escape($usernameVal) để giữ lại giá trị đã gõ an toàn sau khi gửi form -->
          <input type="text" name="identifier" id="login-identifier" required autocomplete="username"
            value="<?= Security::escape($usernameVal) ?>"
            placeholder="Nhập tên đăng nhập, email hoặc MSSV..."
            class="w-full glass-input rounded-xl py-2.5 pl-10 pr-4 text-sm placeholder-slate-500">
        </div>
      </div>

      <!-- Trường nhập 2: Mật khẩu + liên kết sang trang Quên mật khẩu -->
      <div>
        <div class="flex items-center justify-between mb-1.5">
          <label class="block text-xs font-semibold text-slate-300 uppercase tracking-wider">Mật khẩu</label>
          <a href="/BaiTapLon/forgot_password.php" class="text-[11px] text-cyan-400 hover:text-cyan-300 font-semibold hover:underline">
            Quên mật khẩu?
          </a>
        </div>
        <div class="relative">
          <span class="absolute inset-y-0 left-0 flex items-center pl-3.5 text-slate-500">
            <i class="fa-solid fa-lock text-sm"></i>
          </span>
          <input type="password" name="password" id="login-password" required autocomplete="current-password"
            placeholder="••••••••••••"
            class="w-full glass-input rounded-xl py-2.5 pl-10 pr-4 text-sm placeholder-slate-500">
        </div>
      </div>

      <!-- Nút gửi form đăng nhập -->
      <button type="submit" class="w-full btn-cyan py-3 text-sm mt-2 flex items-center justify-center space-x-2 font-bold cursor-pointer">
        <span>Đăng nhập</span>
        <i class="fa-solid fa-arrow-right text-xs"></i>
      </button>
    </form>

    <!-- Khu vực chân thẻ: Liên kết sang trang Đăng ký tài khoản mới và nút quay về Trang chủ -->
    <div class="mt-6 pt-5 border-t border-white/10 text-center space-y-2">
      <p class="text-xs text-slate-400">
        Chưa có tài khoản sinh viên?
        <a href="/BaiTapLon/register.php" class="text-cyan-400 hover:text-cyan-300 font-bold hover:underline ml-1">
          Đăng ký ngay <i class="fa-solid fa-user-plus text-[10px] ml-0.5"></i>
        </a>
      </p>
      <a href="/BaiTapLon/index.php" class="inline-block text-[11px] text-slate-500 hover:text-slate-300 transition-colors">
        <i class="fa-solid fa-arrow-left mr-1"></i>Quay lại Trang chủ
      </a>
    </div>
  </div>
</div>

<!-- Đóng thẻ body và html (vì trang này không nhúng includes/footer.php) -->
</body>
</html>
