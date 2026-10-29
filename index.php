<?php
/**
 * TRANG CHỦ HỆ THỐNG LUYỆN TẬP VÀ CHẤM BÀI TRỰC TUYẾN
 * Đề tài 20: Xây dựng hệ thống luyện tập và chấm bài lập trình trực tuyến
 */

$pageTitle = 'Trang Chủ - Hệ Thống Luyện Tập & Chấm Bài Trực Tuyến';

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/auth.php';

// Bắt buộc đăng nhập khi truy cập hệ thống
requireLogin();

$dbInstalled = false;
$stats = [
    'users' => 0,
    'problems' => 0,
    'test_cases' => 0,
    'languages' => 0,
    'submissions' => 0
];
$dbError = null;

try {
    $pdo = Database::getConnection();
    
    $stats['users'] = $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
    $stats['problems'] = $pdo->query("SELECT COUNT(*) FROM problems")->fetchColumn();
    $stats['test_cases'] = $pdo->query("SELECT COUNT(*) FROM test_cases")->fetchColumn();
    $stats['languages'] = $pdo->query("SELECT COUNT(*) FROM languages")->fetchColumn();
    $stats['submissions'] = $pdo->query("SELECT COUNT(*) FROM submissions")->fetchColumn();
    $dbInstalled = ($stats['problems'] > 0);
} catch (Exception $e) {
    $dbError = $e->getMessage();
}

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/navbar.php';
?>

<div class="container my-5">
    <!-- Hero Banner -->
    <div class="text-center mb-5">
        <div class="badge bg-primary-subtle text-primary border border-primary-subtle px-3 py-2 rounded-pill mb-3">
            <i class="fas fa-layer-group me-1"></i> Đề tài 20 &bull; Giai đoạn 1 Hoàn Thành
        </div>
        <h1 class="display-5 fw-bold text-white mb-3">
            Hệ Thống Luyện Tập &amp; <span class="text-primary" style="background: var(--primary-grad); -webkit-background-clip: text; -webkit-text-fill-color: transparent;">Chấm Bài Trực Tuyến</span>
        </h1>
        <p class="text-secondary fs-5 mx-auto" style="max-width: 720px;">
            Soạn thảo mã nguồn trên trình duyệt với <strong>Monaco Editor</strong>, thực thi cách ly qua <strong>Piston API</strong>, tự điều phối chấm và chuẩn hóa phân loại <strong>7 mã trạng thái</strong>.
        </p>
        
        <div class="d-flex justify-content-center gap-3 mt-4">
            <a href="problems.php" class="btn btn-primary px-4 py-2 fw-semibold">
                <i class="fas fa-play me-1"></i> Vào Luyện Tập Ngay
            </a>
            <a href="submissions.php" class="btn btn-outline-secondary px-4 py-2 fw-semibold text-light">
                <i class="fas fa-clock-rotate-left me-1"></i> Xem Lịch Sử Nộp Bài
            </a>
        </div>
    </div>

    <?php if (!$dbInstalled): ?>
        <!-- Thông báo nếu chưa khởi tạo CSDL -->
        <div class="custom-card p-4 border-warning mb-5">
            <div class="d-flex align-items-center gap-3">
                <i class="fas fa-exclamation-triangle text-warning fs-2"></i>
                <div class="flex-grow-1">
                    <h5 class="text-white fw-bold mb-1">Cơ sở dữ liệu chưa được nạp vào MySQL</h5>
                    <p class="text-secondary mb-0 small">
                        <?php echo $dbError ? htmlspecialchars($dbError) : 'Hãy đảm bảo dịch vụ MySQL trong XAMPP Control Panel đang ở trạng thái Running.'; ?>
                    </p>
                </div>
                <div>
                    <a href="database/install.php" class="btn btn-warning fw-bold px-3 py-2">
                        <i class="fas fa-bolt me-1"></i> 1-Click Khởi Tạo CSDL
                    </a>
                </div>
            </div>
        </div>
    <?php else: ?>
        <!-- Thống kê hệ thống -->
        <div class="row g-3 mb-5">
            <div class="col-md-3 col-6">
                <div class="custom-card p-3 text-center">
                    <div class="fs-2 fw-bold text-primary"><?php echo $stats['problems']; ?></div>
                    <div class="text-secondary small fw-semibold text-uppercase">Bài Tập Thuật Toán</div>
                </div>
            </div>
            <div class="col-md-3 col-6">
                <div class="custom-card p-3 text-center">
                    <div class="fs-2 fw-bold text-success"><?php echo $stats['test_cases']; ?></div>
                    <div class="text-secondary small fw-semibold text-uppercase">Bộ Test Cases</div>
                </div>
            </div>
            <div class="col-md-3 col-6">
                <div class="custom-card p-3 text-center">
                    <div class="fs-2 fw-bold text-warning"><?php echo $stats['languages']; ?></div>
                    <div class="text-secondary small fw-semibold text-uppercase">Ngôn Ngữ (Py &amp; C++)</div>
                </div>
            </div>
            <div class="col-md-3 col-6">
                <div class="custom-card p-3 text-center">
                    <div class="fs-2 fw-bold text-info"><?php echo $stats['submissions']; ?></div>
                    <div class="text-secondary small fw-semibold text-uppercase">Lượt Nộp Bài</div>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <!-- 4 Khối Tính Năng Nổi Bật của Giai Đoạn 1 -->
    <div class="row g-4 mb-5">
        <div class="col-md-6">
            <div class="custom-card p-4 h-100">
                <div class="d-flex align-items-center gap-3 mb-3">
                    <div class="brand-badge m-0" style="background: rgba(99, 102, 241, 0.2); color: #818cf8;">
                        <i class="fas fa-code"></i>
                    </div>
                    <h5 class="fw-bold text-white mb-0">Khung Soạn Thảo Monaco Editor</h5>
                </div>
                <p class="text-secondary small">
                    Nhúng Monaco Editor (trái tim của VS Code) trực tiếp trên trình duyệt, hỗ trợ Syntax Highlight, phím tắt, đổi linh hoạt giữa Python 3 và C++ 17, kèm cơ chế tự động lưu nháp (Auto-save) sau mỗi lần gõ phím.
                </p>
            </div>
        </div>

        <div class="col-md-6">
            <div class="custom-card p-4 h-100">
                <div class="d-flex align-items-center gap-3 mb-3">
                    <div class="brand-badge m-0" style="background: rgba(16, 185, 129, 0.2); color: #34d399;">
                        <i class="fas fa-shield-halved"></i>
                    </div>
                    <h5 class="fw-bold text-white mb-0">Thực Thi Cách Ly (Piston API Sandbox)</h5>
                </div>
                <p class="text-secondary small">
                    Tuân thủ 100% yêu cầu đề bài: Tuyệt đối không chạy mã trong tiến trình Web Apache/PHP. Mã nguồn được đẩy sang dịch vụ thực thi độc lập (Piston API) chạy trong Sandbox bảo mật, giới hạn CPU và RAM.
                </p>
            </div>
        </div>

        <div class="col-md-6">
            <div class="custom-card p-4 h-100">
                <div class="d-flex align-items-center gap-3 mb-3">
                    <div class="brand-badge m-0" style="background: rgba(245, 158, 11, 0.2); color: #fbbf24;">
                        <i class="fas fa-scale-balanced"></i>
                    </div>
                    <h5 class="fw-bold text-white mb-0">Tự Viết So Sánh Đầu Ra 3 Bước</h5>
                </div>
                <p class="text-secondary small">
                    Lớp <code>OutputComparator</code> tự code: 1. Đổi \r\n thành \n; 2. Cắt bỏ khoảng trắng thừa ở cuối mỗi dòng; 3. Cắt bỏ dòng trống thừa ở cuối chuỗi và so sánh bằng tuyệt đối (===).
                </p>
            </div>
        </div>

        <div class="col-md-6">
            <div class="custom-card p-4 h-100">
                <div class="d-flex align-items-center gap-3 mb-3">
                    <div class="brand-badge m-0" style="background: rgba(239, 68, 68, 0.2); color: #f87171;">
                        <i class="fas fa-tags"></i>
                    </div>
                    <h5 class="fw-bold text-white mb-0">Chuẩn Hóa 7 Mã Trạng Thái Chấm</h5>
                </div>
                <p class="text-secondary small">
                    Phân định rạch ròi 7 trạng thái: <code>PENDING</code>, <code>RUNNING</code>, <code>AC</code> (Đúng), <code>WA</code> (Sai), <code>CE</code> (Lỗi cú pháp), <code>RE</code> (Lỗi lúc chạy), <code>TLE</code> (Quá thời gian/lặp vô hạn) và <code>SE</code> (Lỗi hệ thống).
                </p>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
