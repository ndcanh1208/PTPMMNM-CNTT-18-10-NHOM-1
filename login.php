<?php
/**
 * ByteFlow Online Judge - Trang Đăng nhập & Chuyển đổi Tài khoản Nhanh
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
$usernameVal = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $identifier = trim((string)($_POST['identifier'] ?? ''));
    $password   = (string)($_POST['password'] ?? '');
    $usernameVal = $identifier;

    if ($identifier === '' || $password === '') {
        $error = 'Vui lòng nhập đầy đủ Tên đăng nhập và Mật khẩu.';
    } else {
        if (Auth::attempt($identifier, $password)) {
            $redirect = $_GET['redirect'] ?? '/BaiTapLon/index.php';
            header("Location: {$redirect}");
            exit;
        } else {
            $error = 'Tên đăng nhập hoặc mật khẩu không chính xác.';
        }
    }
}

$pageTitle = 'Đăng nhập';
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

    <?php if ($error): ?>
      <div class="mb-5 p-3 rounded-xl bg-rose-500/10 border border-rose-500/30 text-rose-300 text-xs flex items-center space-x-2">
        <i class="fa-solid fa-triangle-exclamation"></i>
        <span><?= Security::escape($error) ?></span>
      </div>
    <?php endif; ?>

    <!-- Form Đăng nhập -->
    <form action="" method="POST" class="space-y-4">
      <div>
        <label class="block text-xs font-semibold text-slate-300 uppercase tracking-wider mb-1.5">Tên đăng nhập / Email</label>
        <div class="relative">
          <span class="absolute inset-y-0 left-0 flex items-center pl-3.5 text-slate-500">
            <i class="fa-solid fa-user text-sm"></i>
          </span>
          <input type="text" name="identifier" id="login-identifier" required
            value="<?= Security::escape($usernameVal) ?>"
            placeholder="vd: learner1, instructor1, admin1"
            class="w-full glass-input rounded-xl py-2.5 pl-10 pr-4 text-sm placeholder-slate-500">
        </div>
      </div>

      <div>
        <label class="block text-xs font-semibold text-slate-300 uppercase tracking-wider mb-1.5">Mật khẩu</label>
        <div class="relative">
          <span class="absolute inset-y-0 left-0 flex items-center pl-3.5 text-slate-500">
            <i class="fa-solid fa-lock text-sm"></i>
          </span>
          <input type="password" name="password" id="login-password" required
            placeholder="••••••••••••"
            class="w-full glass-input rounded-xl py-2.5 pl-10 pr-4 text-sm placeholder-slate-500">
        </div>
      </div>

      <button type="submit" class="w-full btn-cyan py-3 text-sm mt-2 flex items-center justify-center space-x-2 font-semibold">
        <span>Đăng nhập</span>
        <i class="fa-solid fa-arrow-right text-xs"></i>
      </button>
    </form>

    <!-- Nút Đăng nhập Nhanh Mẫu (Cho Giảng viên & Hội đồng chấm thi) -->
    <div class="mt-8 pt-6 border-t border-white/10">
      <div class="text-[11px] font-semibold text-slate-400 uppercase tracking-wider text-center mb-3">
        Tài khoản mẫu thử nghiệm (Mật khẩu: Password123@)
      </div>
      <div class="grid grid-cols-2 gap-2 text-xs">
        <button type="button" onclick="quickLogin('learner1', 'Password123@')" 
          class="p-2.5 rounded-xl bg-white/5 hover:bg-cyan-500/10 border border-white/10 hover:border-cyan-500/40 text-left transition-all group">
          <div class="font-bold text-slate-200 group-hover:text-cyan-300">learner1</div>
          <div class="text-[10px] text-slate-400">Học viên 1</div>
        </button>

        <button type="button" onclick="quickLogin('learner2', 'Password123@')" 
          class="p-2.5 rounded-xl bg-white/5 hover:bg-cyan-500/10 border border-white/10 hover:border-cyan-500/40 text-left transition-all group">
          <div class="font-bold text-slate-200 group-hover:text-cyan-300">learner2</div>
          <div class="text-[10px] text-slate-400">Học viên 2</div>
        </button>

        <button type="button" onclick="quickLogin('instructor1', 'Password123@')" 
          class="p-2.5 rounded-xl bg-white/5 hover:bg-blue-500/10 border border-white/10 hover:border-blue-500/40 text-left transition-all group">
          <div class="font-bold text-slate-200 group-hover:text-blue-300">instructor1</div>
          <div class="text-[10px] text-slate-400">Giảng viên tạo đề</div>
        </button>

        <button type="button" onclick="quickLogin('admin1', 'Password123@')" 
          class="p-2.5 rounded-xl bg-white/5 hover:bg-purple-500/10 border border-white/10 hover:border-purple-500/40 text-left transition-all group">
          <div class="font-bold text-slate-200 group-hover:text-purple-300">admin1</div>
          <div class="text-[10px] text-slate-400">Quản trị viên</div>
        </button>
      </div>
    </div>
  </div>
</div>

<script>
  function quickLogin(username, password) {
    document.getElementById('login-identifier').value = username;
    document.getElementById('login-password').value = password;
    document.querySelector('form').submit();
  }
</script>

</body>
</html>
