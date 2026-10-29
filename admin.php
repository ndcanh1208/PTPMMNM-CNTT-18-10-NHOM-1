<?php
/**
 * TRANG BẢNG ĐIỀU KHIỂN DÀNH CHO QUẢN TRỊ VIÊN DỊCH VỤ THỰC THI (ADMIN DASHBOARD)
 * Đề tài 20: 
 * - Mục 1: Người sử dụng: Quản trị viên dịch vụ thực thi
 * - Giám sát dịch vụ thực thi cách ly (Piston API Sandbox), cấu hình ngôn ngữ, phân quyền tài khoản
 */

$pageTitle = 'Bảng Điều Khiển Quản Trị Viên Dịch Vụ Thực Thi';

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/core/ExecutionService.php';

// Bắt buộc quyền Quản trị viên
requireRole('ADMIN');

$pdo = Database::getConnection();
$currentUser = getCurrentUser();

$successMsg = null;
$errorMsg   = null;

// ==============================================================
// XỬ LÝ CÁC THAO TÁC QUẢN TRỊ (POST ACTIONS)
// ==============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // 1. ĐỔI VAI TRÒ NGƯỜI DÙNG (PHÂN QUYỀN)
    if ($action === 'change_role') {
        $userId  = (int)($_POST['user_id'] ?? 0);
        $newRole = strtoupper($_POST['new_role'] ?? 'STUDENT');

        if ($userId > 0 && in_array($newRole, ['ADMIN', 'LECTURER', 'STUDENT'])) {
            $pdo->prepare("UPDATE users SET role = ? WHERE id = ?")->execute([$newRole, $userId]);
            $successMsg = "Đã cập nhật vai trò người dùng #{$userId} thành: {$newRole}!";
        }
    }

    // 2. BẬT / TẮT NGÔN NGỮ LẬP TRÌNH
    elseif ($action === 'toggle_language') {
        $langId = trim($_POST['lang_id'] ?? '');
        $status = (int)($_POST['status'] ?? 1);
        if (!empty($langId)) {
            $pdo->prepare("UPDATE languages SET is_active = ? WHERE id = ?")->execute([$status, $langId]);
            $successMsg = "Đã cập nhật trạng thái ngôn ngữ {$langId} thành " . ($status ? 'Kích hoạt' : 'Tạm tắt') . "!";
        }
    }
}

// ==============================================================
// KIỂM TRA SỨC KHỎE DỊCH VỤ THỰC THI CÁCH LY (HEALTH CHECK)
// ==============================================================
$startTime = microtime(true);
$sandboxHealthy = false;
$sandboxLatency = 0;
$sandboxMsg = '';

// Kiểm tra kết nối tới Piston API
$ch = curl_init(PISTON_API_URL);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 2);
curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 1);
$pistonRes = curl_exec($ch);
$httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$sandboxLatency = (int)round((microtime(true) - $startTime) * 1000);
if ($httpCode >= 200 && $httpCode < 300) {
    $sandboxHealthy = true;
    $sandboxMsg = 'Dịch vụ Piston API Sandbox đang hoạt động bình thường trên cổng 2000.';
} else {
    $sandboxHealthy = false;
    $sandboxMsg = 'Không thể kết nối tới Piston Docker (Cổng 2000). Hệ thống đang kích hoạt chế độ Sandbox Fallback tự động an toàn.';
}

// Thống kê phân bố Verdict toàn hệ thống
$stmtVerdict = $pdo->query("
    SELECT verdict, COUNT(*) as count 
    FROM judging_runs 
    WHERE is_latest = 1 
    GROUP BY verdict
");
$verdictStats = [];
while ($row = $stmtVerdict->fetch()) {
    $verdictStats[$row['verdict']] = (int)$row['count'];
}

// Thống kê tổng số
$totalUsers       = (int)$pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
$totalSubmissions = (int)$pdo->query("SELECT COUNT(*) FROM submissions")->fetchColumn();
$totalRuns        = (int)$pdo->query("SELECT COUNT(*) FROM judging_runs")->fetchColumn();
$totalProblems    = (int)$pdo->query("SELECT COUNT(*) FROM problems")->fetchColumn();

// Danh sách người dùng
$usersList = $pdo->query("SELECT id, username, full_name, email, role, created_at FROM users ORDER BY id ASC")->fetchAll();

// Danh sách ngôn ngữ
$languages = $pdo->query("SELECT * FROM languages ORDER BY id ASC")->fetchAll();

// Danh sách lỗi hệ thống (SYSTEM_ERROR log)
$systemErrors = $pdo->query("
    SELECT jr.id, jr.submission_id, jr.system_log, jr.started_at, s.language_id, u.username
    FROM judging_runs jr
    JOIN submissions s ON jr.submission_id = s.id
    JOIN users u ON s.user_id = u.id
    WHERE jr.verdict = 'SYSTEM_ERROR'
    ORDER BY jr.id DESC
    LIMIT 10
")->fetchAll();

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/navbar.php';
?>

<div class="container-fluid px-lg-4 my-4">
    <!-- Header Quản trị viên -->
    <div class="d-flex align-items-center justify-content-between flex-wrap gap-3 mb-4 pb-3 border-bottom border-secondary border-opacity-25">
        <div>
            <div class="badge bg-danger bg-opacity-10 text-danger border border-danger border-opacity-25 px-3 py-1 rounded-pill mb-2">
                <i class="fas fa-shield-halved me-1"></i> Quản Trị Viên Dịch Vụ Thực Thi (Admin)
            </div>
            <h2 class="fw-bold text-white mb-1">Bảng Điều Khiển Quản Trị Hệ Thống</h2>
            <p class="text-secondary mb-0 small">
                Giám sát dịch vụ thực thi cách ly (Piston Sandbox) &bull; Phân quyền người dùng &bull; Cấu hình ngôn ngữ &bull; Kiểm tra sức khỏe hệ thống
            </p>
        </div>
        <div>
            <a href="lecturer.php" class="btn btn-outline-warning btn-sm px-3 fw-semibold">
                <i class="fas fa-chalkboard-user me-1"></i> Không Gian Giảng Viên
            </a>
        </div>
    </div>

    <?php if ($successMsg): ?>
        <div class="alert alert-success bg-success bg-opacity-10 border-success text-success alert-dismissible fade show mb-4">
            <i class="fas fa-check-circle me-2"></i><?php echo htmlspecialchars($successMsg); ?>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- THẺ TRẠNG THÁI DỊCH VỤ THỰC THI (SANDBOX HEALTH CHECK) -->
    <div class="row g-3 mb-4">
        <div class="col-md-6">
            <div class="card bg-dark border-secondary border-opacity-25 h-100 p-3">
                <div class="d-flex justify-content-between align-items-start mb-2">
                    <span class="text-secondary small fw-bold">DỊCH VỤ THỰC THI CÁCH LY (PISTON API)</span>
                    <?php if ($sandboxHealthy): ?>
                        <span class="badge bg-success"><i class="fas fa-circle-check me-1"></i>Hoạt động (Healthy)</span>
                    <?php else: ?>
                        <span class="badge bg-warning text-dark"><i class="fas fa-shield-cat me-1"></i>Chế độ Dự Phòng Fallback</span>
                    <?php endif; ?>
                </div>
                <h4 class="text-white fw-bold mb-1">
                    <?php echo PISTON_API_URL; ?>
                </h4>
                <div class="text-secondary small mb-2">
                    <?php echo htmlspecialchars($sandboxMsg); ?>
                </div>
                <div class="d-flex gap-3 text-secondary small pt-2 border-top border-secondary border-opacity-25 mt-auto">
                    <span><i class="fas fa-stopwatch me-1 text-info"></i>Độ trễ: <strong><?php echo $sandboxLatency; ?>ms</strong></span>
                    <span><i class="fas fa-box me-1 text-primary"></i>Container: <strong>ghcr.io/engineer-man/piston</strong></span>
                </div>
            </div>
        </div>

        <div class="col-md-6">
            <div class="card bg-dark border-secondary border-opacity-25 h-100 p-3">
                <span class="text-secondary small fw-bold mb-2">PHÂN BỐ VERDICT BÀI NỘP TOÀN HỆ THỐNG</span>
                <div class="row g-2 text-center my-auto">
                    <div class="col-4 col-sm-2">
                        <div class="p-2 rounded bg-black border border-success border-opacity-25">
                            <div class="text-success fw-bold fs-5"><?php echo $verdictStats['ACCEPTED'] ?? 0; ?></div>
                            <div class="text-secondary small" style="font-size: 0.7rem;">AC (Đúng)</div>
                        </div>
                    </div>
                    <div class="col-4 col-sm-2">
                        <div class="p-2 rounded bg-black border border-danger border-opacity-25">
                            <div class="text-danger fw-bold fs-5"><?php echo $verdictStats['WRONG_ANSWER'] ?? 0; ?></div>
                            <div class="text-secondary small" style="font-size: 0.7rem;">WA (Sai)</div>
                        </div>
                    </div>
                    <div class="col-4 col-sm-2">
                        <div class="p-2 rounded bg-black border border-warning border-opacity-25">
                            <div class="text-warning fw-bold fs-5"><?php echo $verdictStats['TIME_LIMIT_EXCEEDED'] ?? 0; ?></div>
                            <div class="text-secondary small" style="font-size: 0.7rem;">TLE (Quá giờ)</div>
                        </div>
                    </div>
                    <div class="col-4 col-sm-2">
                        <div class="p-2 rounded bg-black border border-info border-opacity-25">
                            <div class="text-info fw-bold fs-5"><?php echo $verdictStats['RUNTIME_ERROR'] ?? 0; ?></div>
                            <div class="text-secondary small" style="font-size: 0.7rem;">RE (Lỗi chạy)</div>
                        </div>
                    </div>
                    <div class="col-4 col-sm-2">
                        <div class="p-2 rounded bg-black border border-secondary border-opacity-25">
                            <div class="text-white fw-bold fs-5"><?php echo $verdictStats['COMPILE_ERROR'] ?? 0; ?></div>
                            <div class="text-secondary small" style="font-size: 0.7rem;">CE (Cú pháp)</div>
                        </div>
                    </div>
                    <div class="col-4 col-sm-2">
                        <div class="p-2 rounded bg-black border border-secondary border-opacity-25">
                            <div class="text-secondary fw-bold fs-5"><?php echo $verdictStats['SYSTEM_ERROR'] ?? 0; ?></div>
                            <div class="text-secondary small" style="font-size: 0.7rem;">SE (Hệ thống)</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- TABS QUẢN TRỊ VIÊN -->
    <ul class="nav nav-tabs border-secondary border-opacity-25 mb-4" id="adminTabs">
        <li class="nav-item">
            <a class="nav-link active text-white fw-semibold" data-bs-toggle="tab" href="#tabUsers">
                <i class="fas fa-users-gear me-1 text-primary"></i> Quản Lý Người Dùng &amp; Phân Quyền (<?php echo $totalUsers; ?>)
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link text-white fw-semibold" data-bs-toggle="tab" href="#tabLanguages">
                <i class="fas fa-code me-1 text-info"></i> Cấu Hình Ngôn Ngữ Thực Thi
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link text-white fw-semibold" data-bs-toggle="tab" href="#tabSystemErrors">
                <i class="fas fa-server me-1 text-danger"></i> Nhật Ký Sự Cố Hệ Thống (SE Logs)
            </a>
        </li>
    </ul>

    <div class="tab-content">
        <!-- TAB 1: QUẢN LÝ TÀI KHOẢN & PHÂN QUYỀN -->
        <div class="tab-pane fade show active" id="tabUsers">
            <div class="card bg-dark border-secondary border-opacity-25 shadow-sm">
                <div class="card-header bg-transparent border-secondary border-opacity-25 py-3 text-white fw-bold">
                    <i class="fas fa-user-shield me-2 text-primary"></i>Danh Sách Tài Khoản Trong Hệ Thống
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-dark table-hover align-middle mb-0">
                            <thead class="text-secondary small border-bottom border-secondary border-opacity-25">
                                <tr>
                                    <th class="ps-4 py-3">ID</th>
                                    <th>Tên Đăng Nhập</th>
                                    <th>Họ Và Tên</th>
                                    <th>Email</th>
                                    <th>Vai Trò Hiện Tại</th>
                                    <th>Ngày Tạo</th>
                                    <th class="pe-4 text-end">Đổi Quyền Hạn</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($usersList as $u): 
                                    $roleBadge = ($u['role'] === 'ADMIN') ? 'bg-danger' : (($u['role'] === 'LECTURER') ? 'bg-primary' : 'bg-success');
                                ?>
                                    <tr>
                                        <td class="ps-4 fw-bold text-secondary">#<?php echo $u['id']; ?></td>
                                        <td><code class="text-info"><?php echo htmlspecialchars($u['username']); ?></code></td>
                                        <td class="text-white fw-semibold"><?php echo htmlspecialchars($u['full_name']); ?></td>
                                        <td class="text-secondary small"><?php echo htmlspecialchars($u['email']); ?></td>
                                        <td><span class="badge <?php echo $roleBadge; ?>"><?php echo $u['role']; ?></span></td>
                                        <td class="text-secondary small"><?php echo date('d/m/Y', strtotime($u['created_at'])); ?></td>
                                        <td class="pe-4 text-end">
                                            <form method="POST" class="d-inline-flex gap-1 justify-content-end align-items-center">
                                                <input type="hidden" name="action" value="change_role">
                                                <input type="hidden" name="user_id" value="<?php echo $u['id']; ?>">
                                                <select name="new_role" class="form-select form-select-sm bg-black text-white border-secondary" style="width: auto;">
                                                    <option value="STUDENT" <?php echo ($u['role'] === 'STUDENT') ? 'selected' : ''; ?>>Sinh Viên</option>
                                                    <option value="LECTURER" <?php echo ($u['role'] === 'LECTURER') ? 'selected' : ''; ?>>Giảng Viên</option>
                                                    <option value="ADMIN" <?php echo ($u['role'] === 'ADMIN') ? 'selected' : ''; ?>>Quản Trị Viên</option>
                                                </select>
                                                <button type="submit" class="btn btn-outline-primary btn-sm py-1 px-2">
                                                    Lưu
                                                </button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- TAB 2: CẤU HÌNH NGÔN NGỮ THỰC THI -->
        <div class="tab-pane fade" id="tabLanguages">
            <div class="card bg-dark border-secondary border-opacity-25 shadow-sm">
                <div class="card-header bg-transparent border-secondary border-opacity-25 py-3 text-white fw-bold">
                    <i class="fas fa-terminal me-2 text-info"></i>Các Ngôn Ngữ Được Phép Thực Thi Trong Sandbox
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-dark table-hover align-middle mb-0">
                            <thead class="text-secondary small border-bottom border-secondary border-opacity-25">
                                <tr>
                                    <th class="ps-4 py-3">Mã Ngôn Ngữ</th>
                                    <th>Tên Hiển Thị</th>
                                    <th>Đuôi Tệp</th>
                                    <th>Lệnh Biên Dịch</th>
                                    <th>Trạng Thái</th>
                                    <th class="pe-4 text-end">Thao Tác</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($languages as $lang): ?>
                                    <tr>
                                        <td class="ps-4 font-monospace text-info fw-bold"><?php echo htmlspecialchars($lang['id']); ?></td>
                                        <td class="text-white fw-semibold"><?php echo htmlspecialchars($lang['name']); ?></td>
                                        <td><code><?php echo htmlspecialchars($lang['source_ext']); ?></code></td>
                                        <td class="font-monospace small text-secondary"><?php echo htmlspecialchars($lang['compile_cmd'] ?: '(Thông dịch trực tiếp)'); ?></td>
                                        <td>
                                            <?php if ((int)$lang['is_active'] === 1): ?>
                                                <span class="badge bg-success">Đang bật</span>
                                            <?php else: ?>
                                                <span class="badge bg-secondary">Tạm tắt</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="pe-4 text-end">
                                            <form method="POST" class="d-inline">
                                                <input type="hidden" name="action" value="toggle_language">
                                                <input type="hidden" name="lang_id" value="<?php echo $lang['id']; ?>">
                                                <input type="hidden" name="status" value="<?php echo ((int)$lang['is_active'] === 1) ? 0 : 1; ?>">
                                                <button type="submit" class="btn btn-outline-<?php echo ((int)$lang['is_active'] === 1) ? 'danger' : 'success'; ?> btn-sm py-1 px-3">
                                                    <?php echo ((int)$lang['is_active'] === 1) ? 'Tắt ngôn ngữ' : 'Bật ngôn ngữ'; ?>
                                                </button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- TAB 3: NHẬT KÝ SỰ CỐ HỆ THỐNG -->
        <div class="tab-pane fade" id="tabSystemErrors">
            <div class="card bg-dark border-secondary border-opacity-25 shadow-sm">
                <div class="card-header bg-transparent border-secondary border-opacity-25 py-3 text-white fw-bold">
                    <i class="fas fa-triangle-exclamation me-2 text-danger"></i>Nhật Ký Các Ca Bị Lỗi Hệ Thống (Phép thử 2)
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-dark table-hover align-middle mb-0">
                            <thead class="text-secondary small border-bottom border-secondary border-opacity-25">
                                <tr>
                                    <th class="ps-4 py-3">Run ID</th>
                                    <th>Bài Nộp</th>
                                    <th>Người Nộp</th>
                                    <th>Ngôn Ngữ</th>
                                    <th>Chi Tiết Lỗi Ghi Nhận</th>
                                    <th>Thời Điểm</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($systemErrors)): ?>
                                    <tr>
                                        <td colspan="6" class="text-center py-4 text-secondary">
                                            <i class="fas fa-circle-check text-success me-1"></i>Hệ thống hoạt động ổn định, không có sự cố hạ tầng nào gần đây.
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($systemErrors as $se): ?>
                                        <tr>
                                            <td class="ps-4 font-monospace fw-bold text-danger">#<?php echo $se['id']; ?></td>
                                            <td>Bài nộp #<?php echo $se['submission_id']; ?></td>
                                            <td><?php echo htmlspecialchars($se['username']); ?></td>
                                            <td><span class="badge bg-secondary"><?php echo $se['language_id']; ?></span></td>
                                            <td class="small text-warning"><?php echo htmlspecialchars($se['system_log']); ?></td>
                                            <td class="text-secondary small"><?php echo date('H:i d/m/Y', strtotime($se['started_at'])); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
