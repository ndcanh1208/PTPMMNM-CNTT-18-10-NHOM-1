<?php
$pageTitle = 'Đăng Nhập Hệ Thống';

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/auth.php';

// Nếu đã đăng nhập rồi thì chuyển sang trang bài tập
if (isLoggedIn()) {
    header('Location: problems.php');
    exit;
}

$error = null;
$successMsg = null;

if (isset($_GET['msg']) && $_GET['msg'] === 'registered') {
    $successMsg = 'Đăng ký tài khoản thành công! Vui lòng đăng nhập.';
} elseif (isset($_GET['msg']) && $_GET['msg'] === 'need_login') {
    $error = 'Bạn cần đăng nhập để truy cập tính năng này.';
}

// Xử lý gửi form đăng nhập
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $account  = trim($_POST['account'] ?? '');
    $password = $_POST['password'] ?? '';

    if (empty($account) || empty($password)) {
        $error = 'Vui lòng nhập đầy đủ tên đăng nhập và mật khẩu.';
    } else {
        try {
            $pdo = Database::getConnection();
            $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ? OR email = ? LIMIT 1");
            $stmt->execute([$account, $account]);
            $user = $stmt->fetch();

            if ($user && password_verify($password, $user['password_hash'])) {
                // Đăng nhập thành công, lưu session
                $_SESSION['user'] = [
                    'id'        => (int)$user['id'],
                    'username'  => $user['username'],
                    'full_name' => $user['full_name'],
                    'email'     => $user['email'],
                    'role'      => $user['role']
                ];

                // Chuyển hướng theo vai trò hoặc sang trang bài tập
                header('Location: problems.php');
                exit;
            } else {
                $error = 'Tên đăng nhập hoặc mật khẩu không chính xác.';
            }
        } catch (Exception $e) {
            $error = 'Lỗi kết nối CSDL: ' . $e->getMessage();
        }
    }
}

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/navbar.php';
?>

<div class="container my-5">
    <div class="row justify-content-center">
        <div class="col-md-6 col-lg-5">
            <div class="custom-card p-4 p-md-5">
                <div class="text-center mb-4">
                    <div class="brand-badge mx-auto mb-3" style="width: 48px; height: 48px; font-size: 1.4rem;">
                        <i class="fas fa-lock"></i>
                    </div>
                    <h3 class="fw-bold text-white mb-1">Đăng Nhập</h3>
                    <p class="text-secondary small">Hệ Thống Luyện Tập &amp; Chấm Bài Trực Tuyến</p>
                </div>

                <?php if ($error): ?>
                    <div class="alert alert-danger custom-card border-danger text-danger py-2 px-3 mb-3 small d-flex align-items-center gap-2">
                        <i class="fas fa-circle-exclamation"></i>
                        <div><?php echo htmlspecialchars($error); ?></div>
                    </div>
                <?php endif; ?>

                <?php if ($successMsg): ?>
                    <div class="alert alert-success custom-card border-success text-success py-2 px-3 mb-3 small d-flex align-items-center gap-2">
                        <i class="fas fa-circle-check"></i>
                        <div><?php echo htmlspecialchars($successMsg); ?></div>
                    </div>
                <?php endif; ?>

                <form method="POST" action="login.php">
                    <div class="mb-3">
                        <label class="form-label text-secondary small fw-semibold">Tên đăng nhập hoặc Email</label>
                        <div class="input-group">
                            <span class="input-group-text bg-dark border-secondary text-secondary">
                                <i class="fas fa-user"></i>
                            </span>
                            <input type="text" name="account" id="inputAccount" class="form-control bg-dark border-secondary text-white" 
                                   placeholder="Nhập username hoặc email..." required value="<?php echo htmlspecialchars($_POST['account'] ?? ''); ?>">
                        </div>
                    </div>

                    <div class="mb-4">
                        <label class="form-label text-secondary small fw-semibold">Mật khẩu</label>
                        <div class="input-group">
                            <span class="input-group-text bg-dark border-secondary text-secondary">
                                <i class="fas fa-key"></i>
                            </span>
                            <input type="password" name="password" id="inputPassword" class="form-control bg-dark border-secondary text-white" 
                                   placeholder="Nhập mật khẩu..." required>
                        </div>
                    </div>

                    <button type="submit" class="btn btn-primary w-100 py-2 fw-bold mb-3">
                        <i class="fas fa-right-to-bracket me-1"></i> Đăng Nhập
                    </button>
                </form>

                <!-- Nút Đăng Nhập Nhanh Để Test / Chấm Thi -->
                <div class="border-top border-secondary pt-3 mt-3">
                    <div class="text-secondary small text-center mb-2 fw-semibold">
                        <i class="fas fa-bolt text-warning me-1"></i>Đăng nhập nhanh (Tài khoản mẫu):
                    </div>
                    <div class="d-grid gap-2">
                        <button type="button" class="btn btn-sm btn-outline-success text-start px-3 py-2 btn-quick-login"
                                data-acc="sv_nguyenhoangnam" data-pwd="student123">
                            <i class="fas fa-user-graduate me-2"></i><strong>Sinh viên</strong>: sv_nguyenhoangnam
                        </button>
                        <button type="button" class="btn btn-sm btn-outline-primary text-start px-3 py-2 btn-quick-login"
                                data-acc="gv_nguyenvana" data-pwd="teacher123">
                            <i class="fas fa-chalkboard-user me-2"></i><strong>Giảng viên</strong>: gv_nguyenvana
                        </button>
                        <button type="button" class="btn btn-sm btn-outline-danger text-start px-3 py-2 btn-quick-login"
                                data-acc="admin" data-pwd="admin123">
                            <i class="fas fa-shield-halved me-2"></i><strong>Quản trị viên</strong>: admin
                        </button>
                    </div>
                </div>

                <div class="text-center mt-4 text-secondary small">
                    Chưa có tài khoản? <a href="register.php" class="text-primary text-decoration-none fw-semibold">Đăng ký ngay</a>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
document.querySelectorAll('.btn-quick-login').forEach(btn => {
    btn.addEventListener('click', () => {
        document.getElementById('inputAccount').value = btn.dataset.acc;
        document.getElementById('inputPassword').value = btn.dataset.pwd;
    });
});
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
