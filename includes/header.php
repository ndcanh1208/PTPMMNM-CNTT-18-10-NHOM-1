<?php
/**
 * ByteFlow Online Judge - Phần đầu trang chung (Header)
 */

declare(strict_types=1);

require_once __DIR__ . '/../core/Auth.php';
require_once __DIR__ . '/../core/Security.php';

Auth::startSession();
$currentUser = Auth::user();
?>
<!DOCTYPE html>
<html lang="vi" class="dark">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= isset($pageTitle) ? Security::escape($pageTitle) . ' | ByteFlow Judge' : 'ByteFlow - Hệ thống Chấm bài Lập trình Trực tuyến' ?></title>
  
  <!-- Tailwind CSS CDN -->
  <script src="https://cdn.tailwindcss.com"></script>
  <script>
    tailwind.config = {
      darkMode: 'class',
      theme: {
        extend: {
          colors: {
            deep: '#050507',
            slateDark: '#12161f',
            cyanAccent: '#00FFFF',
          }
        }
      }
    }
  </script>

  <!-- FontAwesome Icons & Google Fonts -->
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <link rel="stylesheet" href="/BaiTapLon/assets/css/style.css">
</head>
<body class="bg-[#050507] text-slate-200 antialiased min-h-screen flex">
  <div class="animated-bg"></div>

  <!-- Toast Notification Container -->
  <div id="toast-container" class="fixed top-5 right-5 z-50 flex flex-col space-y-2 pointer-events-none"></div>

  <script>
    // Hàm hiển thị Toast thông báo nhanh
    function showToast(message, type = 'info') {
      const container = document.getElementById('toast-container');
      const toast = document.createElement('div');
      
      const colors = {
        success: 'border-emerald-500/40 bg-emerald-950/80 text-emerald-300',
        error: 'border-rose-500/40 bg-rose-950/80 text-rose-300',
        info: 'border-cyan-500/40 bg-slate-900/90 text-cyan-300',
        warning: 'border-amber-500/40 bg-amber-950/80 text-amber-300'
      };
      
      const icons = {
        success: 'fa-check-circle',
        error: 'fa-exclamation-circle',
        info: 'fa-info-circle',
        warning: 'fa-triangle-exclamation'
      };

      toast.className = `flex items-center space-x-3 px-4 py-3 rounded-lg border backdrop-blur-md shadow-lg pointer-events-auto transition-all duration-300 transform translate-y-2 opacity-0 ${colors[type] || colors.info}`;
      toast.innerHTML = `<i class="fa-solid ${icons[type] || icons.info}"></i><span class="text-sm font-medium">${message}</span>`;
      
      container.appendChild(toast);
      
      requestAnimationFrame(() => {
        toast.classList.remove('translate-y-2', 'opacity-0');
      });

      setTimeout(() => {
        toast.classList.add('opacity-0', '-translate-y-2');
        setTimeout(() => toast.remove(), 300);
      }, 3500);
    }
  </script>
