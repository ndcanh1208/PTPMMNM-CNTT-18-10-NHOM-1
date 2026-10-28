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
                    <div class="dropdown">
                        <button class="btn btn-dark border-secondary btn-sm dropdown-toggle text-white d-flex align-items-center gap-2 px-3 py-1" 
                                type="button" data-bs-toggle="dropdown">
                            <i class="fas fa-user-circle text-primary fs-5"></i>
                            <div class="text-start">
                                <span class="d-block small fw-bold lh-1"><?php echo htmlspecialchars($loggedUser['full_name']); ?></span>
                                <span class="badge <?php echo $roleBadgeClass; ?>" style="font-size: 0.65rem; padding: 2px 6px;">
                                    <?php echo $roleLabel; ?>
                                </span>
                            </div>
                        </button>
                        <ul class="dropdown-menu dropdown-menu-dark dropdown-menu-end border-secondary shadow">
                            <li class="dropdown-header text-secondary small">
                                Tài khoản: <code><?php echo htmlspecialchars($loggedUser['username']); ?></code>
                            </li>
                            <li><hr class="dropdown-divider border-secondary"></li>
                            <li>
                                <a class="dropdown-item small" href="submissions.php">
                                    <i class="fas fa-clock-rotate-left me-2 text-info"></i>Bài nộp của tôi
                                </a>
                            </li>
                            <li>
                                <a class="dropdown-item small" href="/phpmyadmin/" target="_blank">
                                    <i class="fas fa-database me-2 text-warning"></i>phpMyAdmin
                                </a>
                            </li>
                            <li><hr class="dropdown-divider border-secondary"></li>
                            <li>
                                <a class="dropdown-item small text-danger" href="logout.php">
                                    <i class="fas fa-arrow-right-from-bracket me-2"></i>Đăng xuất
                                </a>
                            </li>
                        </ul>
                    </div>
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
