<?php
/**
 * ByteFlow Online Judge - Trang Đăng ký Tài khoản Chính thức
 */

declare(strict_types=1);

require_once __DIR__ . '/core/Auth.php';
require_once __DIR__ . '/core/Security.php';

Auth::startSession();

if (Auth::check()) {
    header('Location: /BaiTapLon/index.php');
    exit;
}

$error = null;
$form = [
    'full_name'    => '',
    'username'     => '',
    'student_code' => '',
    'class_name'   => '',
    'email'        => ''
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $form['full_name']    = trim((string)($_POST['full_name'] ?? ''));
    $form['username']     = trim((string)($_POST['username'] ?? ''));
    $form['student_code'] = strtoupper(trim((string)($_POST['student_code'] ?? '')));
    $form['class_name']   = trim((string)($_POST['class_name'] ?? ''));
    $form['email']        = strtolower(trim((string)($_POST['email'] ?? '')));

    $res = Auth::register([
        'full_name'        => $form['full_name'],
        'username'         => $form['username'],
        'student_code'     => $form['student_code'],
        'class_name'       => $form['class_name'],
        'email'            => $form['email'],
        'password'         => (string)($_POST['password'] ?? ''),
        'password_confirm' => (string)($_POST['password_confirm'] ?? '')
    ]);

    if (!empty($res['success'])) {
        header('Location: /BaiTapLon/login.php?registered=1');
        exit;
    } else {
        $error = $res['error'] ?? 'Đăng ký thất bại, vui lòng thử lại.';
    }
}

$pageTitle = 'Đăng ký tài khoản';
require_once __DIR__ . '/includes/header.php';
?>

<div class="w-full min-h-screen flex items-center justify-center p-4 py-10">
  <div class="w-full max-w-lg glass-panel rounded-2xl p-8 shadow-2xl relative overflow-hidden border border-white/10">
    <!-- Hiệu ứng ánh sáng nền -->
    <div class="absolute -top-24 -left-24 w-56 h-56 bg-cyan-500/20 rounded-full blur-3xl pointer-events-none"></div>
    <div class="absolute -bottom-24 -right-24 w-56 h-56 bg-purple-600/20 rounded-full blur-3xl pointer-events-none"></div>

    <!-- Header -->
    <div class="text-center mb-6">
      <div class="inline-flex w-14 h-14 rounded-2xl bg-gradient-to-tr from-cyan-400 to-blue-600 items-center justify-center text-black font-black text-2xl shadow-[0_0_20px_rgba(0,255,255,0.4)] mb-3">
        <i class="fa-solid fa-user-plus text-[#050507]"></i>
      </div>
      <h1 class="text-2xl font-black tracking-wide text-white">Đăng ký Byte<span class="text-cyan-400">Flow</span></h1>
      <p class="text-xs text-slate-400 mt-1">Tạo tài khoản sinh viên chính thức để tham gia luyện tập & kiểm tra</p>
    </div>

    <?php if ($error): ?>
      <div class="mb-5 p-3.5 rounded-xl bg-rose-500/10 border border-rose-500/30 text-rose-300 text-xs flex items-start space-x-2">
        <i class="fa-solid fa-triangle-exclamation mt-0.5 shrink-0"></i>
        <span><?= Security::escape($error) ?></span>
      </div>
    <?php endif; ?>

    <!-- Form Đăng ký -->
    <form action="" method="POST" class="space-y-4">
      <div>
        <label class="block text-xs font-semibold text-slate-300 uppercase tracking-wider mb-1.5">
          Họ và tên <span class="text-rose-400">*</span>
        </label>
        <div class="relative">
          <span class="absolute inset-y-0 left-0 flex items-center pl-3.5 text-slate-500">
            <i class="fa-solid fa-id-card text-sm"></i>
          </span>
          <input type="text" name="full_name" required
            value="<?= Security::escape($form['full_name']) ?>"
            placeholder="Nguyễn Văn An"
            class="w-full glass-input rounded-xl py-2.5 pl-10 pr-4 text-sm placeholder-slate-500">
        </div>
      </div>

      <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
        <div>
          <label class="block text-xs font-semibold text-slate-300 uppercase tracking-wider mb-1.5">
            Tên đăng nhập <span class="text-rose-400">*</span>
          </label>
          <div class="relative">
            <span class="absolute inset-y-0 left-0 flex items-center pl-3.5 text-slate-500">
              <i class="fa-solid fa-at text-sm"></i>
            </span>
            <input type="text" name="username" required pattern="[a-zA-Z0-9_]{3,30}"
              value="<?= Security::escape($form['username']) ?>"
              placeholder="nguyenvanan"
              class="w-full glass-input rounded-xl py-2.5 pl-10 pr-4 text-sm font-mono placeholder-slate-500">
          </div>
          <p class="text-[10px] text-slate-500 mt-1">3–30 ký tự, không dấu, không khoảng trắng.</p>
        </div>

        <div>
          <label class="block text-xs font-semibold text-slate-300 uppercase tracking-wider mb-1.5">
            Mã sinh viên
          </label>
          <div class="relative">
            <span class="absolute inset-y-0 left-0 flex items-center pl-3.5 text-slate-500">
              <i class="fa-solid fa-graduation-cap text-sm"></i>
            </span>
            <input type="text" name="student_code"
              value="<?= Security::escape($form['student_code']) ?>"
              placeholder="VD: CNTT181005"
              class="w-full glass-input rounded-xl py-2.5 pl-10 pr-4 text-sm font-mono uppercase placeholder-slate-500">
          </div>
          <p class="text-[10px] text-slate-500 mt-1">Dùng để tra cứu & xuất bảng điểm môn học.</p>
        </div>
      </div>

      <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
        <div>
          <label class="block text-xs font-semibold text-slate-300 uppercase tracking-wider mb-1.5">
            Email <span class="text-rose-400">*</span>
          </label>
          <div class="relative">
            <span class="absolute inset-y-0 left-0 flex items-center pl-3.5 text-slate-500">
              <i class="fa-solid fa-envelope text-sm"></i>
            </span>
            <input type="email" name="email" required
              value="<?= Security::escape($form['email']) ?>"
              placeholder="sv@cntt.edu.vn"
              class="w-full glass-input rounded-xl py-2.5 pl-10 pr-4 text-sm placeholder-slate-500">
          </div>
        </div>

        <div>
          <label class="block text-xs font-semibold text-slate-300 uppercase tracking-wider mb-1.5">
            Lớp học phần / Lớp hành chính
          </label>
          <div class="relative">
            <span class="absolute inset-y-0 left-0 flex items-center pl-3.5 text-slate-500">
              <i class="fa-solid fa-users text-sm"></i>
            </span>
            <input type="text" name="class_name"
              value="<?= Security::escape($form['class_name']) ?>"
              placeholder="VD: CNTT-18-10"
              class="w-full glass-input rounded-xl py-2.5 pl-10 pr-4 text-sm placeholder-slate-500">
          </div>
        </div>
      </div>

      <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
        <div>
          <label class="block text-xs font-semibold text-slate-300 uppercase tracking-wider mb-1.5">
            Mật khẩu <span class="text-rose-400">*</span>
          </label>
          <div class="relative">
            <span class="absolute inset-y-0 left-0 flex items-center pl-3.5 text-slate-500">
              <i class="fa-solid fa-lock text-sm"></i>
            </span>
            <input type="password" name="password" required minlength="6" autocomplete="new-password"
              placeholder="Tối thiểu 6 ký tự"
              class="w-full glass-input rounded-xl py-2.5 pl-10 pr-4 text-sm placeholder-slate-500">
          </div>
        </div>

        <div>
          <label class="block text-xs font-semibold text-slate-300 uppercase tracking-wider mb-1.5">
            Xác nhận mật khẩu <span class="text-rose-400">*</span>
          </label>
          <div class="relative">
            <span class="absolute inset-y-0 left-0 flex items-center pl-3.5 text-slate-500">
              <i class="fa-solid fa-shield-halved text-sm"></i>
            </span>
            <input type="password" name="password_confirm" required minlength="6" autocomplete="new-password"
              placeholder="Nhập lại mật khẩu"
              class="w-full glass-input rounded-xl py-2.5 pl-10 pr-4 text-sm placeholder-slate-500">
          </div>
        </div>
      </div>

      <button type="submit" class="w-full btn-cyan py-3 text-sm mt-2 flex items-center justify-center space-x-2 font-bold cursor-pointer">
        <i class="fa-solid fa-user-check text-xs"></i>
        <span>Tạo Tài Khoản</span>
      </button>
    </form>

    <div class="mt-6 pt-5 border-t border-white/10 text-center space-y-2">
      <p class="text-xs text-slate-400">
        Đã có tài khoản rồi?
        <a href="/BaiTapLon/login.php" class="text-cyan-400 hover:text-cyan-300 font-bold hover:underline ml-1">
          Đăng nhập tại đây
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
