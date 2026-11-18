<?php
/**
 * ByteFlow Online Judge - Trang Đăng nhập Chính thức
 */

declare(strict_types=1);

require_once __DIR__ . '/core/Auth.php';
require_once __DIR__ . '/core/Security.php';

Auth::startSession();

// Xử lý đăng xuất
if (isset($_GET['logout'])) {
    Auth::logout();
    header('Location: /BaiTapLon/login.php');
    exit;
}

// Nếu đã đăng nhập thì chuyển hướng sang trang chủ
if (Auth::check()) {
    header('Location: /BaiTapLon/index.php');
    exit;
}

$error = null;
$successMsg = null;
$usernameVal = '';

if (isset($_GET['registered'])) {
    $successMsg = 'Đăng ký tài khoản thành công! Vui lòng đăng nhập bằng tài khoản vừa tạo.';
}
if (isset($_GET['locked'])) {
    $error = 'Phiên làm việc đã kết thúc do tài khoản của bạn đang bị khóa. Vui lòng liên hệ Quản trị viên.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $identifier = trim((string)($_POST['identifier'] ?? $_POST['username'] ?? ''));
    $password   = (string)($_POST['password'] ?? '');
    $usernameVal = $identifier;

    if ($identifier === '' || $password === '') {
        $error = 'Vui lòng nhập đầy đủ Tên đăng nhập / Email / Mã sinh viên và Mật khẩu.';
    } else {
        if (Auth::attempt($identifier, $password)) {
            $redirect = $_GET['redirect'] ?? '/BaiTapLon/index.php';
            // Ngăn chặn Open Redirect: chỉ cho phép đường dẫn nội bộ
            if (!str_starts_with((string)$redirect, '/')) {
                $redirect = '/BaiTapLon/index.php';
            }
            header("Location: {$redirect}");
            exit;
        } else {
            $error = Auth::getLastError() ?? 'Tên đăng nhập hoặc mật khẩu không chính xác.';
        }
    }
}

$pageTitle = 'Đăng nhập hệ thống';
require_once __DIR__ . '/includes/header.php';
?>

<div class="w-full min-h-screen flex items-center justify-center p-4">
  <div class="w-full max-w-md glass-panel rounded-2xl p-8 shadow-2xl relative overflow-hidden border border-white/10">
    <!-- Hiệu ứng vệt sáng neon -->
    <div class="absolute -top-24 -left-24 w-48 h-48 bg-cyan-500/20 rounded-full blur-3xl pointer-events-none"></div>
    <div class="absolute -bottom-24 -right-24 w-48 h-48 bg-blue-600/20 rounded-full blur-3xl pointer-events-none"></div>

    <!-- Header Logo -->
    <div class="text-center mb-8">
      <div class="inline-flex w-14 h-14 rounded-2xl bg-gradient-to-tr from-cyan-400 to-blue-600 items-center justify-center text-black font-black text-2xl shadow-[0_0_20px_rgba(0,255,255,0.4)] mb-3">
        <i class="fa-solid fa-code text-[#050507]"></i>
      </div>
      <h1 class="text-2xl font-black tracking-wide text-white">Đăng nhập Byte<span class="text-cyan-400">Flow</span></h1>
      <p class="text-xs text-slate-400 mt-1">Hệ thống luyện tập & chấm bài lập trình trực tuyến</p>
    </div>

    <?php if ($successMsg): ?>
      <div class="mb-5 p-3.5 rounded-xl bg-emerald-500/10 border border-emerald-500/30 text-emerald-300 text-xs flex items-start space-x-2">
        <i class="fa-solid fa-circle-check mt-0.5 shrink-0"></i>
        <span><?= Security::escape($successMsg) ?></span>
      </div>
    <?php endif; ?>

    <?php if ($error): ?>
      <div class="mb-5 p-3.5 rounded-xl bg-rose-500/10 border border-rose-500/30 text-rose-300 text-xs flex items-start space-x-2">
        <i class="fa-solid fa-triangle-exclamation mt-0.5 shrink-0"></i>
        <span><?= Security::escape($error) ?></span>
      </div>
    <?php endif; ?>

    <!-- Form Đăng nhập Chính thức -->
    <form action="" method="POST" class="space-y-4">
      <div>
        <label class="block text-xs font-semibold text-slate-300 uppercase tracking-wider mb-1.5">
          Tên đăng nhập / Email / Mã sinh viên
        </label>
        <div class="relative">
          <span class="absolute inset-y-0 left-0 flex items-center pl-3.5 text-slate-500">
            <i class="fa-solid fa-user text-sm"></i>
          </span>
          <input type="text" name="identifier" id="login-identifier" required autocomplete="username"
            value="<?= Security::escape($usernameVal) ?>"
            placeholder="Nhập tên đăng nhập, email hoặc MSSV..."
            class="w-full glass-input rounded-xl py-2.5 pl-10 pr-4 text-sm placeholder-slate-500">
        </div>
      </div>

      <div>
        <label class="block text-xs font-semibold text-slate-300 uppercase tracking-wider mb-1.5">Mật khẩu</label>
        <div class="relative">
          <span class="absolute inset-y-0 left-0 flex items-center pl-3.5 text-slate-500">
            <i class="fa-solid fa-lock text-sm"></i>
          </span>
          <input type="password" name="password" id="login-password" required autocomplete="current-password"
            placeholder="••••••••••••"
            class="w-full glass-input rounded-xl py-2.5 pl-10 pr-4 text-sm placeholder-slate-500">
        </div>
      </div>

      <button type="submit" class="w-full btn-cyan py-3 text-sm mt-2 flex items-center justify-center space-x-2 font-bold cursor-pointer">
        <span>Đăng nhập</span>
        <i class="fa-solid fa-arrow-right text-xs"></i>
      </button>
    </form>

    <!-- Liên kết Đăng ký tài khoản mới -->
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

</body>
</html>
