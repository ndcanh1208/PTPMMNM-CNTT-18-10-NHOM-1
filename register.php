<?php
$pageTitle = 'Đăng Ký Tài Khoản';

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/auth.php';

if (isLoggedIn()) {
    header('Location: problems.php');
    exit;
}

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username        = trim($_POST['username'] ?? '');
    $fullName        = trim($_POST['full_name'] ?? '');
    $email           = trim($_POST['email'] ?? '');
    $password        = $_POST['password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';
    $role            = trim($_POST['role'] ?? 'STUDENT');

    // Kiểm tra tính hợp lệ
    if (!in_array($role, ['STUDENT', 'LECTURER'])) {
        $role = 'STUDENT';
    }

    if (empty($username) || empty($fullName) || empty($email) || empty($password)) {
        $error = 'Vui lòng điền đầy đủ tất cả các trường thông tin.';
    } elseif (strlen($username) < 3) {
        $error = 'Tên đăng nhập phải có ít nhất 3 ký tự.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Địa chỉ email không đúng định dạng.';
    } elseif (strlen($password) < 6) {
        $error = 'Mật khẩu phải chứa ít nhất 6 ký tự.';
    } elseif ($password !== $confirmPassword) {
        $error = 'Mật khẩu xác nhận không khớp.';
    } else {
        try {
            $pdo = Database::getConnection();

            // Kiểm tra username hoặc email đã tồn tại chưa
            $stmtCheck = $pdo->prepare("SELECT id FROM users WHERE username = ? OR email = ? LIMIT 1");
            $stmtCheck->execute([$username, $email]);
            if ($stmtCheck->fetch()) {
                $error = 'Tên đăng nhập hoặc địa chỉ email đã tồn tại trong hệ thống.';
            } else {
                $hash = password_hash($password, PASSWORD_BCRYPT);
                $stmtInsert = $pdo->prepare("
                    INSERT INTO users (username, password_hash, full_name, email, role, created_at)
                    VALUES (?, ?, ?, ?, ?, NOW())
                ");
                $stmtInsert->execute([$username, $hash, $fullName, $email, $role]);

                // Chuyển sang login
                header('Location: login.php?msg=registered');
                exit;
            }
        } catch (Exception $e) {
            $error = 'Lỗi hệ thống: ' . $e->getMessage();
        }
    }
}

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/navbar.php';
?>

<div class="container my-5">
    <div class="row justify-content-center">
        <div class="col-md-7 col-lg-6">
            <div class="custom-card p-4 p-md-5">
                <div class="text-center mb-4">
                    <div class="brand-badge mx-auto mb-3" style="width: 48px; height: 48px; font-size: 1.4rem;">
                        <i class="fas fa-user-plus"></i>
                    </div>
                    <h3 class="fw-bold text-white mb-1">Đăng Ký Tài Khoản</h3>
                    <p class="text-secondary small">Tham gia hệ thống luyện tập và chấm bài trực tuyến</p>
                </div>

                <?php if ($error): ?>
                    <div class="alert alert-danger custom-card border-danger text-danger py-2 px-3 mb-3 small d-flex align-items-center gap-2">
                        <i class="fas fa-circle-exclamation"></i>
                        <div><?php echo htmlspecialchars($error); ?></div>
                    </div>
                <?php endif; ?>

                <form method="POST" action="register.php">
                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label class="form-label text-secondary small fw-semibold">Tên đăng nhập</label>
                            <input type="text" name="username" class="form-control bg-dark border-secondary text-white" 
                                   placeholder="vd: sv_tranvantuan" required value="<?php echo htmlspecialchars($_POST['username'] ?? ''); ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label text-secondary small fw-semibold">Vai trò (Role)</label>
                            <select name="role" class="form-select bg-dark border-secondary text-white">
                                <option value="STUDENT" <?php echo (($_POST['role'] ?? '') === 'STUDENT') ? 'selected' : ''; ?>>
                                    🎓 Sinh viên / Người học
                                </option>
                                <option value="LECTURER" <?php echo (($_POST['role'] ?? '') === 'LECTURER') ? 'selected' : ''; ?>>
                                    👨‍🏫 Giảng viên / Quản lý đề
                                </option>
                            </select>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label text-secondary small fw-semibold">Họ và tên đầy đủ</label>
                        <input type="text" name="full_name" class="form-control bg-dark border-secondary text-white" 
                               placeholder="vd: Trần Văn Tuấn" required value="<?php echo htmlspecialchars($_POST['full_name'] ?? ''); ?>">
                    </div>

                    <div class="mb-3">
                        <label class="form-label text-secondary small fw-semibold">Địa chỉ Email</label>
                        <input type="email" name="email" class="form-control bg-dark border-secondary text-white" 
                               placeholder="vd: tuantv@student.edu.vn" required value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>">
                    </div>

                    <div class="row g-3 mb-4">
                        <div class="col-md-6">
                            <label class="form-label text-secondary small fw-semibold">Mật khẩu</label>
                            <input type="password" name="password" class="form-control bg-dark border-secondary text-white" 
                                   placeholder="Tối thiểu 6 ký tự" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label text-secondary small fw-semibold">Xác nhận mật khẩu</label>
                            <input type="password" name="confirm_password" class="form-control bg-dark border-secondary text-white" 
                                   placeholder="Nhập lại mật khẩu" required>
                        </div>
                    </div>

                    <button type="submit" class="btn btn-primary w-100 py-2 fw-bold mb-3">
                        <i class="fas fa-check-circle me-1"></i> Hoàn Tất Đăng Ký
                    </button>

                    <div class="text-center text-secondary small">
                        Đã có tài khoản? <a href="login.php" class="text-primary text-decoration-none fw-semibold">Đăng nhập ngay</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
