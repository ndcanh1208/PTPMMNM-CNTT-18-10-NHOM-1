<?php
require_once __DIR__ . '/auth.php';

$currentScript = basename($_SERVER['PHP_SELF']);
$loggedUser    = getCurrentUser();
?>
<nav class="navbar navbar-expand-lg navbar-custom sticky-top">
    <div class="container-fluid px-lg-4">
        <a class="navbar-brand d-flex align-items-center text-white fw-bold" href="index.php">
            <div class="brand-badge">
                <i class="fas fa-terminal"></i>
            </div>
            <span>OJ Platform <small class="text-secondary fw-normal fs-6">| Đề tài 20</small></span>
        </a>
        
        <button class="navbar-toggler text-white border-0" type="button" data-bs-toggle="collapse" data-bs-target="#navbarMain">
            <i class="fas fa-bars"></i>
        </button>

        <div class="collapse navbar-collapse" id="navbarMain">
            <ul class="navbar-nav me-auto mb-2 mb-lg-0 ms-lg-3">
                <li class="nav-item">
                    <a class="nav-link <?php echo ($currentScript === 'index.php') ? 'active' : ''; ?>" href="index.php">
                        <i class="fas fa-home me-1"></i> Trang Chủ
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?php echo ($currentScript === 'problems.php' || $currentScript === 'solve.php') ? 'active' : ''; ?>" href="problems.php">
                        <i class="fas fa-code me-1"></i> Danh Sách Bài Tập
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?php echo ($currentScript === 'submissions.php') ? 'active' : ''; ?>" href="submissions.php">
                        <i class="fas fa-clock-rotate-left me-1"></i> Lịch Sử Nộp Bài
                    </a>
                </li>
            </ul>

            <div class="d-flex align-items-center gap-2">
                <?php if ($loggedUser): 
                    $role = $loggedUser['role'];
                    $roleBadgeClass = ($role === 'ADMIN') ? 'bg-danger' : (($role === 'LECTURER') ? 'bg-primary' : 'bg-success');
                    $roleLabel = ($role === 'ADMIN') ? 'Quản Trị Viên' : (($role === 'LECTURER') ? 'Giảng Viên' : 'Sinh Viên');
                ?>
                    <!-- Menu Tài Khoản Độc Lập (Hoạt động cả khi click lẫn hover) -->
                    <div class="user-menu-wrapper position-relative">
                        <button type="button" class="btn btn-dark border-secondary btn-sm text-white d-flex align-items-center gap-2 px-3 py-1 user-menu-trigger" 
                                id="userMenuBtn" onclick="toggleUserMenu(event)">
                            <i class="fas fa-circle-user text-primary fs-5"></i>
                            <div class="text-start">
                                <span class="d-block small fw-bold lh-1"><?php echo htmlspecialchars($loggedUser['full_name']); ?></span>
                                <span class="badge <?php echo $roleBadgeClass; ?>" style="font-size: 0.65rem; padding: 2px 6px;">
                                    <?php echo $roleLabel; ?>
                                </span>
                            </div>
                            <i class="fas fa-chevron-down ms-1 text-secondary" style="font-size: 0.7rem;"></i>
                        </button>
                        
                        <div class="user-menu-dropdown shadow-lg" id="userMenuDropdown">
                            <div class="px-3 py-2 border-bottom border-secondary border-opacity-25">
                                <div class="text-secondary small">Đang đăng nhập với:</div>
                                <div class="fw-bold text-white small"><?php echo htmlspecialchars($loggedUser['username']); ?></div>
                                <?php if (!empty($loggedUser['email'])): ?>
                                    <div class="text-muted" style="font-size: 0.75rem;"><?php echo htmlspecialchars($loggedUser['email']); ?></div>
                                <?php endif; ?>
                            </div>
                            <div class="py-1">
                                <a class="user-menu-item" href="problems.php">
                                    <i class="fas fa-code text-primary"></i>
                                    <span>Danh sách bài tập</span>
                                </a>
                                <a class="user-menu-item" href="submissions.php">
                                    <i class="fas fa-clock-rotate-left text-info"></i>
                                    <span>Bài nộp của tôi</span>
                                </a>
                                <a class="user-menu-item" href="/phpmyadmin/" target="_blank">
                                    <i class="fas fa-database text-warning"></i>
                                    <span>phpMyAdmin (CSDL)</span>
                                </a>
                            </div>
                            <div class="border-top border-secondary border-opacity-25 pt-1 mt-1">
                                <a class="user-menu-item logout-item text-danger fw-semibold" href="logout.php">
                                    <i class="fas fa-arrow-right-from-bracket text-danger"></i>
                                    <span>Đăng xuất</span>
                                </a>
                            </div>
                        </div>
                    </div>

                    <!-- Nút Đăng Xuất Trực Tiếp Bên Cạnh Menu (Luôn sẵn sàng, không sợ lỗi dropdown) -->
                    <a href="logout.php" class="btn btn-outline-danger btn-sm px-2 py-1 d-flex align-items-center gap-1" title="Đăng xuất khỏi hệ thống">
                        <i class="fas fa-arrow-right-from-bracket"></i>
                        <span class="d-none d-sm-inline">Đăng xuất</span>
                    </a>

                <?php else: ?>
                    <a href="login.php" class="btn btn-outline-light btn-sm px-3 py-1">
                        <i class="fas fa-right-to-bracket me-1"></i> Đăng Nhập
                    </a>
                    <a href="register.php" class="btn btn-primary btn-sm px-3 py-1">
                        <i class="fas fa-user-plus me-1"></i> Đăng Ký
                    </a>
                <?php endif; ?>
            </div>
        </div>
    </div>
</nav>

<style>
/* CSS cho User Menu Dropdown: Tự động hiện khi Hover và giữ mở khi Click */
.user-menu-wrapper {
    position: relative;
    display: inline-block;
}
.user-menu-dropdown {
    display: none;
    position: absolute;
    right: 0;
    top: calc(100% + 6px);
    min-width: 230px;
    background: #111827;
    border: 1px solid rgba(255, 255, 255, 0.15);
    border-radius: 10px;
    box-shadow: 0 16px 36px rgba(0, 0, 0, 0.65);
    z-index: 99999 !important;
}
.user-menu-dropdown.show {
    display: block !important;
}
/* Hiện khi hover chuột qua nút menu */
.user-menu-wrapper:hover .user-menu-dropdown {
    display: block !important;
}
.user-menu-item {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 8px 16px;
    color: #e5e7eb;
    text-decoration: none;
    font-size: 0.85rem;
    transition: background 0.15s ease, color 0.15s ease;
}
.user-menu-item:hover {
    background: rgba(99, 102, 241, 0.15);
    color: #818cf8;
}
.user-menu-item.logout-item:hover {
    background: rgba(239, 68, 68, 0.15);
    color: #f87171;
}
</style>

<script>
function toggleUserMenu(e) {
    if (e) {
        e.preventDefault();
        e.stopPropagation();
    }
    const menu = document.getElementById('userMenuDropdown');
    if (menu) {
        menu.classList.toggle('show');
    }
}

document.addEventListener('click', function(e) {
    const wrapper = document.querySelector('.user-menu-wrapper');
    const menu = document.getElementById('userMenuDropdown');
    if (wrapper && menu && !wrapper.contains(e.target)) {
        menu.classList.remove('show');
    }
});
</script>
