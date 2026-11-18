<?php
/**
 * ==============================================================================
 * Nhom1 Online Judge - Phần đầu trang chung (Header Layout)
 * ==============================================================================
 * Mục đích của file này:
 * - Được nhúng (require/include) ở đầu mọi trang web để dùng chung phần <head>, CSS, thư viện icon và thanh thông báo nhanh (Toast).
 * - Khởi tạo phiên đăng nhập (Session) và lấy thông tin người dùng hiện tại ($currentUser).
 */

// Bật chế độ kiểm tra kiểu dữ liệu nghiêm ngặt trong PHP
declare(strict_types=1);

// Nạp lớp Auth (quản lý đăng nhập/phân quyền) và Security (chống tấn công XSS, CSRF...)
require_once __DIR__ . '/../core/Auth.php';
require_once __DIR__ . '/../core/Security.php';

// Khởi động phiên làm việc (PHP Session) một cách an toàn nếu chưa được mở
Auth::startSession();

// Lấy mảng thông tin người dùng đang đăng nhập (trả về null nếu là khách chưa đăng nhập)
$currentUser = Auth::user();
?>
<!-- Khai báo chuẩn tài liệu HTML5 -->
<!DOCTYPE html>
<!-- Thẻ gốc HTML: ngôn ngữ tiếng Việt (vi), gắn sẵn class="dark" để kích hoạt giao diện nền tối của Tailwind CSS -->
<html lang="vi" class="dark">
<head>
  <!-- Bộ mã ký tự UTF-8 giúp hiển thị đúng tiếng Việt có dấu -->
  <meta charset="UTF-8">
  <!-- Cấu hình hiển thị tương thích trên mọi kích thước màn hình (Responsive: máy tính, tablet, điện thoại) -->
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <!--
    Tiêu đề hiển thị trên tab trình duyệt:
    - Nếu trang hiện tại có đặt biến $pageTitle thì hiển thị "$pageTitle | Nhom1".
    - Dùng Security::escape() để mã hóa ký tự đặc biệt HTML, ngăn chặn tấn công XSS.
    - Nếu không có $pageTitle thì dùng tiêu đề mặc định của hệ thống.
  -->
  <title><?= isset($pageTitle) ? Security::escape($pageTitle) . ' | Nhom1' : 'Nhom1 - Hệ thống Chấm bài Lập trình Trực tuyến' ?></title>
  
  <!-- Nạp thư viện Tailwind CSS qua CDN (hỗ trợ viết giao diện nhanh bằng các class tiện ích) -->
  <script src="https://cdn.tailwindcss.com"></script>
  <!-- Cấu hình mở rộng cho Tailwind CSS: bật chế độ tối theo class và bổ sung bảng màu thương hiệu riêng -->
  <script>
    tailwind.config = {
      darkMode: 'class', // Chế độ tối được bật khi thẻ <html> có class="dark"
      theme: {
        extend: {
          colors: {
            deep: '#050507',       // Màu nền đen sâu chủ đạo
            slateDark: '#12161f',  // Màu nền xám đậm cho thẻ (card) và khung chứa
            cyanAccent: '#00FFFF', // Màu xanh ngọc Neon làm điểm nhấn nổi bật
          }
        }
      }
    }
  </script>

  <!-- Nạp bộ biểu tượng (Icons) FontAwesome phiên bản 6.4.0 qua CDN -->
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <!-- Nạp file CSS tùy chỉnh riêng của dự án (hiệu ứng kính mờ Glassmorphism, phát sáng Neon, CodeMirror...) -->
  <link rel="stylesheet" href="/BaiTapLon/assets/css/style.css">
</head>
<!-- Thân trang: nền đen sâu (#050507), chữ xám sáng (text-slate-200), làm mịn font (antialiased), chiều cao tối thiểu bằng 100% màn hình -->
<body class="bg-[#050507] text-slate-200 antialiased min-h-screen">
  <!-- Lớp nền trang trí cố định phía sau toàn trang (tạo hiệu ứng ánh sáng loang Cyan/Xanh, định nghĩa trong style.css) -->
  <div class="animated-bg"></div>

  <!-- Khung chứa các thông báo nổi (Toast Notification) cố định ở góc trên bên phải màn hình -->
  <!-- pointer-events-none giúp vùng trống của khung không chặn thao tác click chuột vào nội dung bên dưới -->
  <div id="toast-container" class="fixed top-5 right-5 z-50 flex flex-col space-y-2 pointer-events-none"></div>

  <!-- Script tiện ích dùng chung cho toàn website -->
  <script>
    /**
     * Hàm hiển thị Toast thông báo nhanh (tự động trượt hiện ra và biến mất sau 3.5 giây)
     * @param {string} message - Nội dung chữ muốn thông báo cho người dùng
     * @param {string} type    - Loại thông báo: 'success' (thành công), 'error' (lỗi), 'info' (thông tin), 'warning' (cảnh báo)
     */
    function showToast(message, type = 'info') {
      // Tìm phần tử khung chứa thông báo trên trang
      const container = document.getElementById('toast-container');
      // Tạo mới một thẻ <div> đại diện cho 1 hộp thông báo
      const toast = document.createElement('div');
      
      // Bảng ánh xạ màu viền, màu nền và màu chữ theo từng loại thông báo
      const colors = {
        success: 'border-emerald-500/40 bg-emerald-950/80 text-emerald-300',
        error: 'border-rose-500/40 bg-rose-950/80 text-rose-300',
        info: 'border-cyan-500/40 bg-slate-900/90 text-cyan-300',
        warning: 'border-amber-500/40 bg-amber-950/80 text-amber-300'
      };
      
      // Bảng ánh xạ tên biểu tượng FontAwesome tương ứng với từng loại thông báo
      const icons = {
        success: 'fa-check-circle',
        error: 'fa-exclamation-circle',
        info: 'fa-info-circle',
        warning: 'fa-triangle-exclamation'
      };

      // Gán danh sách class Tailwind cho hộp thông báo:
      // Ban đầu đặt trạng thái ẩn (opacity-0) và dịch nhẹ xuống dưới (translate-y-2) để chuẩn bị cho hiệu ứng trượt vào
      toast.className = `flex items-center space-x-3 px-4 py-3 rounded-lg border backdrop-blur-md shadow-lg pointer-events-auto transition-all duration-300 transform translate-y-2 opacity-0 ${colors[type] || colors.info}`;
      // Chèn biểu tượng (thẻ <i>) và nội dung chữ (thẻ <span>) vào bên trong hộp thông báo
      toast.innerHTML = `<i class="fa-solid ${icons[type] || icons.info}"></i><span class="text-sm font-medium">${message}</span>`;
      
      // Gắn hộp thông báo mới tạo vào cuối khung #toast-container trên giao diện
      container.appendChild(toast);
      
      // Đợi trình duyệt vẽ khung hình tiếp theo rồi gỡ class ẩn -> kích hoạt hiệu ứng chuyển động mượt (fade-in & trượt lên)
      requestAnimationFrame(() => {
        toast.classList.remove('translate-y-2', 'opacity-0');
      });

      // Hẹn giờ sau 3500ms (3.5 giây) sẽ tự động làm mờ và xóa thông báo khỏi DOM
      setTimeout(() => {
        // Bắt đầu hiệu ứng mờ dần và trượt nhẹ lên trên
        toast.classList.add('opacity-0', '-translate-y-2');
        // Chờ thêm 300ms (bằng thời lượng transition duration-300) cho hiệu ứng chạy xong rồi mới xóa hẳn thẻ HTML
        setTimeout(() => toast.remove(), 300);
      }, 3500);
    }
  </script>