<?php
/**
 * Nhom1 Online Judge - Trang Quên Mật Khẩu & Khôi Phục Bằng Mã OTP qua Email
 */

declare(strict_types=1);

require_once __DIR__ . '/core/Auth.php';
require_once __DIR__ . '/core/Security.php';

Auth::startSession();

if (Auth::check()) {
    header('Location: /BaiTapLon/index.php');
    exit;
}

$pageTitle = 'Quên mật khẩu';
require_once __DIR__ . '/includes/header.php';
?>

<div class="w-full min-h-screen flex items-center justify-center p-4">
  <div class="w-full max-w-md glass-panel rounded-2xl p-8 shadow-2xl relative overflow-hidden border border-white/10">
    <!-- Hiệu ứng ánh sáng nền -->
    <div class="absolute -top-24 -left-24 w-48 h-48 bg-cyan-500/20 rounded-full blur-3xl pointer-events-none"></div>
    <div class="absolute -bottom-24 -right-24 w-48 h-48 bg-purple-600/20 rounded-full blur-3xl pointer-events-none"></div>

    <!-- Header -->
    <div class="text-center mb-6">
      <div class="inline-flex w-14 h-14 rounded-2xl bg-gradient-to-tr from-cyan-400 to-blue-600 items-center justify-center text-black font-black text-2xl shadow-[0_0_20px_rgba(0,255,255,0.4)] mb-3">
        <i class="fa-solid fa-key text-[#050507]"></i>
      </div>
      <h1 class="text-2xl font-black tracking-wide text-white">Quên mật khẩu Nhom<span class="text-cyan-400">1</span></h1>
      <p class="text-xs text-slate-400 mt-1">Xác thực bằng mã OTP 6 chữ số gửi về Email tài khoản</p>
    </div>

    <!-- Thanh tiến trình 3 bước -->
    <div class="flex items-center justify-between mb-6 px-2 text-[11px] font-bold">
      <div id="step-ind-1" class="flex items-center space-x-1.5 text-cyan-400">
        <span class="w-6 h-6 rounded-full bg-cyan-500/20 border border-cyan-400 flex items-center justify-center">1</span>
        <span>Email</span>
      </div>
      <div class="flex-1 h-px bg-white/10 mx-2"></div>
      <div id="step-ind-2" class="flex items-center space-x-1.5 text-slate-500">
        <span class="w-6 h-6 rounded-full bg-white/5 border border-white/15 flex items-center justify-center">2</span>
        <span>Mã OTP</span>
      </div>
      <div class="flex-1 h-px bg-white/10 mx-2"></div>
      <div id="step-ind-3" class="flex items-center space-x-1.5 text-slate-500">
        <span class="w-6 h-6 rounded-full bg-white/5 border border-white/15 flex items-center justify-center">3</span>
        <span>Mật khẩu mới</span>
      </div>
    </div>

    <!-- Hộp Thông báo Trạng thái -->
    <div id="fp-alert" class="hidden mb-5 p-3.5 rounded-xl text-xs leading-relaxed"></div>

    <!-- BƯỚC 1: NHẬP EMAIL / TÊN ĐĂNG NHẬP -->
    <form id="form-step-1" onsubmit="handleRequestOtp(event)" class="space-y-4">
      <div>
        <label class="block text-xs font-semibold text-slate-300 uppercase tracking-wider mb-1.5">
          Email / Tên đăng nhập / Mã sinh viên
        </label>
        <div class="relative">
          <span class="absolute inset-y-0 left-0 flex items-center pl-3.5 text-slate-500">
            <i class="fa-solid fa-envelope text-sm"></i>
          </span>
          <input type="text" id="fp-identifier" required
            placeholder="Nhập email hoặc tên đăng nhập tài khoản..."
            class="w-full glass-input rounded-xl py-2.5 pl-10 pr-4 text-sm placeholder-slate-500">
        </div>
        <p class="text-[11px] text-slate-500 mt-1.5">Hệ thống sẽ gửi mã OTP 6 chữ số (hiệu lực 10 phút) tới email đăng ký của tài khoản này.</p>
      </div>

      <button type="submit" id="btn-step-1" class="w-full btn-cyan py-3 text-sm flex items-center justify-center space-x-2 font-bold cursor-pointer">
        <i class="fa-solid fa-paper-plane text-xs"></i>
        <span>Gửi Mã OTP Về Email</span>
      </button>
    </form>

    <!-- BƯỚC 2: NHẬP MÃ OTP 6 CHỮ SỐ -->
    <form id="form-step-2" onsubmit="handleVerifyOtp(event)" class="space-y-4 hidden">
      <div class="p-3 rounded-xl bg-cyan-500/10 border border-cyan-500/20 text-xs text-cyan-200 flex items-center space-x-2.5">
        <i class="fa-solid fa-envelope-open-text text-cyan-400 text-base"></i>
        <div>
          Mã OTP đã gửi đến: <strong id="fp-masked-email" class="text-white font-mono"></strong>
        </div>
      </div>

      <div>
        <label class="block text-xs font-semibold text-slate-300 uppercase tracking-wider mb-1.5">
          Nhập mã OTP (6 chữ số)
        </label>
        <input type="text" id="fp-otp" required maxlength="6" pattern="\d{6}" inputmode="numeric" autocomplete="one-time-code"
          placeholder="000000"
          class="w-full glass-input rounded-xl py-3 px-4 text-center text-2xl font-mono font-black tracking-[0.5em] text-cyan-300 placeholder-slate-600">
      </div>

      <button type="submit" id="btn-step-2" class="w-full btn-cyan py-3 text-sm flex items-center justify-center space-x-2 font-bold cursor-pointer">
        <i class="fa-solid fa-shield-check text-xs"></i>
        <span>Xác Nhận Mã OTP</span>
      </button>

      <div class="flex items-center justify-between text-xs pt-1">
        <button type="button" onclick="goBackStep1()" class="text-slate-400 hover:text-white transition-colors cursor-pointer">
          <i class="fa-solid fa-arrow-left mr-1"></i>Đổi tài khoản
        </button>
        <button type="button" id="btn-resend-otp" onclick="resendOtp()" disabled class="text-cyan-400 disabled:text-slate-500 hover:underline font-semibold cursor-pointer disabled:cursor-not-allowed">
          Gửi lại mã (<span id="resend-timer">60</span>s)
        </button>
      </div>
    </form>

    <!-- BƯỚC 3: TẠO MẬT KHẨU MỚI -->
    <form id="form-step-3" onsubmit="handleResetPassword(event)" class="space-y-4 hidden">
      <div>
        <label class="block text-xs font-semibold text-slate-300 uppercase tracking-wider mb-1.5">
          Mật khẩu mới <span class="text-rose-400">*</span>
        </label>
        <div class="relative">
          <span class="absolute inset-y-0 left-0 flex items-center pl-3.5 text-slate-500">
            <i class="fa-solid fa-lock text-sm"></i>
          </span>
          <input type="password" id="fp-new-pass" required minlength="6" autocomplete="new-password"
            placeholder="Tối thiểu 6 ký tự"
            class="w-full glass-input rounded-xl py-2.5 pl-10 pr-4 text-sm placeholder-slate-500">
        </div>
      </div>

      <div>
        <label class="block text-xs font-semibold text-slate-300 uppercase tracking-wider mb-1.5">
          Xác nhận mật khẩu mới <span class="text-rose-400">*</span>
        </label>
        <div class="relative">
          <span class="absolute inset-y-0 left-0 flex items-center pl-3.5 text-slate-500">
            <i class="fa-solid fa-shield-halved text-sm"></i>
          </span>
          <input type="password" id="fp-confirm-pass" required minlength="6" autocomplete="new-password"
            placeholder="Nhập lại mật khẩu mới"
            class="w-full glass-input rounded-xl py-2.5 pl-10 pr-4 text-sm placeholder-slate-500">
        </div>
      </div>

      <button type="submit" id="btn-step-3" class="w-full btn-cyan py-3 text-sm flex items-center justify-center space-x-2 font-bold cursor-pointer">
        <i class="fa-solid fa-check text-xs"></i>
        <span>Đặt Lại Mật Khẩu</span>
      </button>
    </form>

    <!-- Footer quay lại đăng nhập -->
    <div class="mt-6 pt-5 border-t border-white/10 text-center">
      <a href="/BaiTapLon/login.php" class="text-xs text-slate-400 hover:text-cyan-400 transition-colors font-semibold">
        <i class="fa-solid fa-arrow-left mr-1"></i>Quay lại trang Đăng nhập
      </a>
    </div>
  </div>
</div>

<script>
let fpIdentifier = '';
let fpVerifiedOtp = '';
let resendInterval = null;

function showFpAlert(htmlMsg, type = 'info') {
  const el = document.getElementById('fp-alert');
  el.classList.remove('hidden',
    'bg-emerald-500/10', 'border-emerald-500/30', 'text-emerald-300',
    'bg-rose-500/10', 'border-rose-500/30', 'text-rose-300',
    'bg-amber-500/10', 'border-amber-500/30', 'text-amber-200'
  );
  el.classList.add('border');
  if (type === 'success') {
    el.classList.add('bg-emerald-500/10', 'border-emerald-500/30', 'text-emerald-300');
  } else if (type === 'warning') {
    el.classList.add('bg-amber-500/10', 'border-amber-500/30', 'text-amber-200');
  } else {
    el.classList.add('bg-rose-500/10', 'border-rose-500/30', 'text-rose-300');
  }
  el.innerHTML = htmlMsg;
}

function setActiveStep(step) {
  [1, 2, 3].forEach(s => {
    document.getElementById(`form-step-${s}`).classList.toggle('hidden', s !== step);
    const ind = document.getElementById(`step-ind-${s}`);
    const circle = ind.querySelector('span');
    if (s < step) {
      ind.className = 'flex items-center space-x-1.5 text-emerald-400';
      circle.className = 'w-6 h-6 rounded-full bg-emerald-500/20 border border-emerald-400 flex items-center justify-center';
      circle.innerHTML = '<i class="fa-solid fa-check text-[10px]"></i>';
    } else if (s === step) {
      ind.className = 'flex items-center space-x-1.5 text-cyan-400';
      circle.className = 'w-6 h-6 rounded-full bg-cyan-500/20 border border-cyan-400 flex items-center justify-center';
      circle.textContent = String(s);
    } else {
      ind.className = 'flex items-center space-x-1.5 text-slate-500';
      circle.className = 'w-6 h-6 rounded-full bg-white/5 border border-white/15 flex items-center justify-center';
      circle.textContent = String(s);
    }
  });
}

function startResendCooldown(seconds = 60) {
  if (resendInterval) clearInterval(resendInterval);
  let left = seconds;
  const btn = document.getElementById('btn-resend-otp');
  btn.disabled = true;
  btn.innerHTML = `Gửi lại mã (<span id="resend-timer">${left}</span>s)`;

  resendInterval = setInterval(() => {
    left--;
    if (left <= 0) {
      clearInterval(resendInterval);
      btn.disabled = false;
      btn.textContent = 'Gửi lại mã OTP';
    } else {
      const span = document.getElementById('resend-timer');
      if (span) span.textContent = String(left);
    }
  }, 1000);
}

function goBackStep1() {
  if (resendInterval) clearInterval(resendInterval);
  document.getElementById('fp-alert').classList.add('hidden');
  setActiveStep(1);
}

async function handleRequestOtp(e) {
  e.preventDefault();
  fpIdentifier = document.getElementById('fp-identifier').value.trim();
  const btn = document.getElementById('btn-step-1');
  btn.disabled = true;
  btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i><span>Đang tạo & gửi mã OTP...</span>';

  try {
    const res = await fetch('/BaiTapLon/api/forgot_password.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ action: 'request_otp', identifier: fpIdentifier })
    });
    const data = await res.json();
    if (!res.ok || !data.success) throw new Error(data.error || 'Không thể gửi mã OTP.');

    document.getElementById('fp-masked-email').textContent = data.masked_email;
    let extraLink = '';
    if (data.delivery_mode === 'log' && data.log_path) {
      extraLink = `<div class="mt-2"><a href="/BaiTapLon/${data.log_path}" target="_blank" class="underline font-bold text-cyan-300">Mở xem Email OTP (Local Log) <i class="fa-solid fa-arrow-up-right-from-square text-[10px]"></i></a></div>`;
    }
    showFpAlert(`<i class="fa-solid fa-circle-check mr-1.5"></i>${data.message}${extraLink}`, data.delivery_mode === 'log' ? 'warning' : 'success');

    setActiveStep(2);
    startResendCooldown(data.cooldown_seconds || 60);
    document.getElementById('fp-otp').focus();
  } catch (err) {
    showFpAlert(`<i class="fa-solid fa-triangle-exclamation mr-1.5"></i>${err.message}`, 'error');
  } finally {
    btn.disabled = false;
    btn.innerHTML = '<i class="fa-solid fa-paper-plane text-xs"></i><span>Gửi Mã OTP Về Email</span>';
  }
}

async function resendOtp() {
  const btn = document.getElementById('btn-resend-otp');
  btn.disabled = true;
  btn.textContent = 'Đang gửi lại...';
  try {
    const res = await fetch('/BaiTapLon/api/forgot_password.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ action: 'request_otp', identifier: fpIdentifier })
    });
    const data = await res.json();
    if (!res.ok || !data.success) {
      if (data.wait_seconds) startResendCooldown(data.wait_seconds);
      throw new Error(data.error || 'Không thể gửi lại mã OTP.');
    }
    let extraLink = '';
    if (data.delivery_mode === 'log' && data.log_path) {
      extraLink = `<div class="mt-2"><a href="/BaiTapLon/${data.log_path}" target="_blank" class="underline font-bold text-cyan-300">Mở xem Email OTP mới (Local Log)</a></div>`;
    }
    showFpAlert(`<i class="fa-solid fa-circle-check mr-1.5"></i>${data.message}${extraLink}`, data.delivery_mode === 'log' ? 'warning' : 'success');
    startResendCooldown(data.cooldown_seconds || 60);
  } catch (err) {
    showFpAlert(`<i class="fa-solid fa-triangle-exclamation mr-1.5"></i>${err.message}`, 'error');
  }
}

async function handleVerifyOtp(e) {
  e.preventDefault();
  const otp = document.getElementById('fp-otp').value.trim();
  const btn = document.getElementById('btn-step-2');
  btn.disabled = true;
  btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i><span>Đang kiểm tra OTP...</span>';

  try {
    const res = await fetch('/BaiTapLon/api/forgot_password.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ action: 'verify_otp', identifier: fpIdentifier, otp: otp })
    });
    const data = await res.json();
    if (!res.ok || !data.success) throw new Error(data.error || 'Mã OTP không chính xác.');

    fpVerifiedOtp = otp;
    showFpAlert(`<i class="fa-solid fa-circle-check mr-1.5"></i>${data.message}`, 'success');
    setActiveStep(3);
    document.getElementById('fp-new-pass').focus();
  } catch (err) {
    showFpAlert(`<i class="fa-solid fa-triangle-exclamation mr-1.5"></i>${err.message}`, 'error');
  } finally {
    btn.disabled = false;
    btn.innerHTML = '<i class="fa-solid fa-shield-check text-xs"></i><span>Xác Nhận Mã OTP</span>';
  }
}

async function handleResetPassword(e) {
  e.preventDefault();
  const newPass = document.getElementById('fp-new-pass').value;
  const confirmPass = document.getElementById('fp-confirm-pass').value;
  if (newPass !== confirmPass) {
    showFpAlert('<i class="fa-solid fa-triangle-exclamation mr-1.5"></i>Mật khẩu xác nhận không khớp.', 'error');
    return;
  }

  const btn = document.getElementById('btn-step-3');
  btn.disabled = true;
  btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i><span>Đang cập nhật mật khẩu...</span>';

  try {
    const res = await fetch('/BaiTapLon/api/forgot_password.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        action: 'reset_password',
        identifier: fpIdentifier,
        otp: fpVerifiedOtp,
        new_password: newPass,
        confirm_password: confirmPass
      })
    });
    const data = await res.json();
    if (!res.ok || !data.success) throw new Error(data.error || 'Không thể đặt lại mật khẩu.');

    showFpAlert(`<i class="fa-solid fa-circle-check mr-1.5"></i>${data.message}`, 'success');
    setTimeout(() => {
      window.location.href = '/BaiTapLon/login.php?reset=1';
    }, 1000);
  } catch (err) {
    showFpAlert(`<i class="fa-solid fa-triangle-exclamation mr-1.5"></i>${err.message}`, 'error');
    btn.disabled = false;
    btn.innerHTML = '<i class="fa-solid fa-check text-xs"></i><span>Đặt Lại Mật Khẩu</span>';
  }
}
</script>

</body>
</html>
