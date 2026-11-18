<?php
/**
 * ==============================================================================
 * Nhom1 Online Judge - Phần chân trang chung (Footer Layout)
 * ==============================================================================
 * Mục đích của file này:
 * - Hiển thị thông tin bản quyền, tên đề tài và phiên bản ở dưới cùng của mọi trang web.
 * - Đóng thẻ <div> bọc nội dung chính (wrapper có class pl-64 để chừa chỗ cho thanh Sidebar trái cố định rộng 256px)
 *   cùng với thẻ đóng </body> và </html> đã được mở trong file includes/header.php.
 */
?>
  <!-- Thẻ chân trang: mt-auto tự đẩy footer xuống sát đáy màn hình khi nội dung ngắn; shrink-0 tránh bị co méo trong flex container -->
  <footer class="mt-auto py-6 border-t border-white/5 text-center text-xs text-slate-500 shrink-0">
    <!-- Khung căn giữa nội dung footer: xếp dọc trên màn hình nhỏ (flex-col), xếp ngang 2 đầu trên màn hình vừa/lớn (sm:flex-row) -->
    <div class="flex flex-col sm:flex-row items-center justify-between px-8 max-w-7xl mx-auto space-y-2 sm:space-y-0">
      <!-- Cột bên trái: Bản quyền hệ thống (hàm date('Y') tự động lấy năm hiện tại của máy chủ, ví dụ 2026) -->
      <div>
        Nhom1 Online Judge &copy; <?= date('Y') ?> - Hệ thống Luyện tập & Chấm bài Trực tuyến
      </div>
      <!-- Cột bên phải: Tên đề tài môn học và số phiên bản hiển thị bằng font chữ đơn cách (font-mono) màu xanh Cyan -->
      <div class="flex items-center space-x-4 text-slate-400">
        <span>Đề tài 20: Phát triển phần mềm mã nguồn mở</span>
        <span class="text-white/20">|</span>
        <span class="text-cyan-400 font-mono">Phiên bản 2.0</span>
      </div>
    </div>
  </footer>
</div> <!-- Đóng wrapper pl-64 min-h-screen flex flex-col w-full (được mở ở từng trang sau khi nhúng sidebar.php) -->

<!-- Kết thúc phần thân trang và tài liệu HTML -->
</body>
</html>