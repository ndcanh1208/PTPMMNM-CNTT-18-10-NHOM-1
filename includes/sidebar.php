<?php
/**
 * ByteFlow Online Judge - Left Sidebar Điều hướng Cố định
 * Phong cách Dark Glassmorphism, Neon Cyan Accent, Micro-interaction
 */

declare(strict_types=1);

require_once __DIR__ . '/../core/Auth.php';
require_once __DIR__ . '/../config/database.php';

$currentUser = Auth::user();
$currentScript = basename($_SERVER['SCRIPT_NAME'] ?? '');

// Kiểm tra số lượng bài nộp đang chờ trong hàng đợi
$pdo = getDbConnection();
$queueCount = (int)$pdo->query("SELECT COUNT(*) FROM judging_runs WHERE status = 'Queued'")->fetchColumn();
$lastRunTime = $pdo->query("SELECT MAX(started_at) FROM judging_runs")->fetchColumn();

// Đánh giá trạng thái Worker
$isWorkerActive = true;
?>

<!-- LEFT SIDEBAR CỐ ĐỊNH -->
<aside class="fixed top-0 left-0 h-screen w-64 glass-panel border-r border-white/10 flex flex-col justify-between z-30 transition-all duration-300">
  <div>
    <!-- Logo & Thương hiệu -->
    <div class="h-20 flex items-center px-6 border-b border-white/10">
      <a href="/BaiTapLon/index.php" class="flex items-center space-x-3 group">
        <div class="w-10 h-10 rounded-xl bg-gradient-to-tr from-cyan-500 to-blue-600 flex items-center justify-center text-black font-black text-xl shadow-[0_0_15px_rgba(0,255,255,0.4)] group-hover:scale-105 transition-transform duration-300">
          <i class="fa-solid fa-code text-[#050507]"></i>
        </div>
        <div>
          <span class="text-xl font-black tracking-wider text-white">Byte<span class="text-cyan-400">Flow</span></span>
          <span class="block text-[10px] uppercase tracking-widest text-slate-400 font-semibold">Online Judge</span>
        </div>
      </a>
    </div>

    <!-- Danh sách Điều hướng (Navigation Links) -->
    <nav class="p-4 space-y-1.5">
      <!-- Trang chủ -->
      <a href="/BaiTapLon/index.php" class="flex items-center space-x-3 px-4 py-3 rounded-xl text-sm font-medium transition-all duration-200 <?= $currentScript === 'index.php' ? 'bg-cyan-500/10 text-cyan-300 border border-cyan-500/30 shadow-[0_0_12px_rgba(0,255,255,0.2)]' : 'text-slate-400 hover:text-slate-100 hover:bg-white/5' ?>">
        <i class="fa-solid fa-gauge-high w-5 text-center text-base"></i>
        <span>Tổng quan</span>
      </a>

      <!-- Thư viện bài tập -->
      <a href="/BaiTapLon/problems.php" class="flex items-center space-x-3 px-4 py-3 rounded-xl text-sm font-medium transition-all duration-200 <?= $currentScript === 'problems.php' || $currentScript === 'solve.php' ? 'bg-cyan-500/10 text-cyan-300 border border-cyan-500/30 shadow-[0_0_12px_rgba(0,255,255,0.2)]' : 'text-slate-400 hover:text-slate-100 hover:bg-white/5' ?>">
        <i class="fa-solid fa-book-bookmark w-5 text-center text-base"></i>
        <span>Thư viện Bài tập</span>
      </a>

      <!-- Lịch sử nộp bài -->
      <a href="/BaiTapLon/submissions.php" class="flex items-center space-x-3 px-4 py-3 rounded-xl text-sm font-medium transition-all duration-200 <?= $currentScript === 'submissions.php' ? 'bg-cyan-500/10 text-cyan-300 border border-cyan-500/30 shadow-[0_0_12px_rgba(0,255,255,0.2)]' : 'text-slate-400 hover:text-slate-100 hover:bg-white/5' ?>">
        <i class="fa-solid fa-clock-rotate-left w-5 text-center text-base"></i>
        <span>Lịch sử Nộp bài</span>
      </a>

      <?php if (Auth::isInstructor() || Auth::isAdmin()): ?>
        <div class="pt-4 pb-2 px-4 text-[11px] font-bold uppercase tracking-wider text-slate-500">
          Phân hệ Giảng viên
        </div>

        <!-- Quản lý bài tập & Phiên bản -->
        <a href="/BaiTapLon/manage_problems.php" class="flex items-center space-x-3 px-4 py-3 rounded-xl text-sm font-medium transition-all duration-200 <?= $currentScript === 'manage_problems.php' ? 'bg-cyan-500/10 text-cyan-300 border border-cyan-500/30 shadow-[0_0_12px_rgba(0,255,255,0.2)]' : 'text-slate-400 hover:text-slate-100 hover:bg-white/5' ?>">
          <i class="fa-solid fa-sliders w-5 text-center text-base"></i>
          <span>Quản lý Đề & Bộ Test</span>
        </a>

        <!-- Chấm lại & Đối chiếu (Tình huống 2) -->
        <a href="/BaiTapLon/rejudge_compare.php" class="flex items-center space-x-3 px-4 py-3 rounded-xl text-sm font-medium transition-all duration-200 <?= $currentScript === 'rejudge_compare.php' ? 'bg-cyan-500/10 text-cyan-300 border border-cyan-500/30 shadow-[0_0_12px_rgba(0,255,255,0.2)]' : 'text-slate-400 hover:text-slate-100 hover:bg-white/5' ?>">
          <i class="fa-solid fa-code-compare w-5 text-center text-base"></i>
          <span>Chấm lại & Đối chiếu</span>
        </a>
      <?php endif; ?>
    </nav>
  </div>

  <!-- Phần chân Sidebar: Trạng thái Worker & Thông tin Tài khoản -->
  <div class="p-4 border-t border-white/10 space-y-3">
    <!-- Trạng thái dịch vụ thực thi (Worker Status) -->
    <div class="px-3 py-2 rounded-lg bg-black/40 border border-white/5 flex items-center justify-between text-xs">
      <div class="flex items-center space-x-2">
        <span class="relative flex h-2.5 w-2.5">
          <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-cyan-400 opacity-75"></span>
          <span class="relative inline-flex rounded-full h-2.5 w-2.5 bg-cyan-400"></span>
        </span>
        <span class="font-medium text-slate-300">Worker Chấm</span>
      </div>
      <span class="px-1.5 py-0.5 rounded text-[11px] font-mono <?= $queueCount > 0 ? 'bg-amber-500/20 text-amber-300 border border-amber-500/30' : 'bg-emerald-500/20 text-emerald-300 border border-emerald-500/30' ?>">
        <?= $queueCount > 0 ? "Hàng đợi: {$queueCount}" : "Sẵn sàng" ?>
      </span>
    </div>

    <!-- Thông tin người dùng -->
    <?php if ($currentUser): ?>
      <div class="flex items-center justify-between p-2 rounded-xl bg-white/5 border border-white/10">
        <div class="flex items-center space-x-3 overflow-hidden">
          <div class="w-9 h-9 rounded-lg bg-gradient-to-tr from-cyan-600 to-blue-500 flex items-center justify-center font-bold text-white shadow-md text-sm shrink-0">
            <?= strtoupper(substr($currentUser['username'], 0, 1)) ?>
          </div>
          <div class="truncate">
            <div class="text-xs font-semibold text-white truncate"><?= Security::escape($currentUser['full_name']) ?></div>
            <div class="text-[10px] text-cyan-400 font-mono capitalize"><?= Security::escape($currentUser['role']) ?></div>
          </div>
        </div>
        <a href="/BaiTapLon/login.php?logout=1" title="Đăng xuất" class="p-1.5 text-slate-400 hover:text-rose-400 transition-colors">
          <i class="fa-solid fa-right-from-bracket"></i>
        </a>
      </div>
    <?php else: ?>
      <a href="/BaiTapLon/login.php" class="w-full btn-cyan flex items-center justify-center space-x-2 text-xs py-2.5">
        <i class="fa-solid fa-arrow-right-to-bracket"></i>
        <span>Đăng nhập hệ thống</span>
      </a>
    <?php endif; ?>
  </div>
</aside>
