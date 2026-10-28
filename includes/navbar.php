<?php
$currentScript = basename($_SERVER['PHP_SELF']);
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
                <span class="badge bg-success-subtle text-success border border-success-subtle px-3 py-2 rounded-pill">
                    <span class="spinner-grow spinner-grow-sm me-1" role="status" style="width: 8px; height: 8px;"></span>
                    Piston Sandbox Online
                </span>
                <a href="/phpmyadmin/" target="_blank" class="btn btn-outline-secondary btn-sm text-light px-3 py-1">
                    <i class="fas fa-database me-1"></i> phpMyAdmin
                </a>
            </div>
        </div>
    </div>
</nav>
