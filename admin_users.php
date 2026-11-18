<?php
/**
 * Nhom1 Online Judge - Phân hệ Admin: Quản lý Người dùng & Tài khoản
 * - Quản lý danh sách sinh viên, mã sinh viên, lớp và mức độ tham gia luyện tập
 * - Cấp quyền hoặc thu hồi quyền Admin / Giảng viên
 * - Khóa / mở khóa tài khoản vi phạm quy chế hoặc gian lận
 */

declare(strict_types=1);

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/core/Auth.php';
require_once __DIR__ . '/core/Security.php';

Auth::requireRoles(['admin']);

$pdo = getDbConnection();
$currentAdminId = (int)Auth::id();

// Bộ lọc tìm kiếm
$q          = trim((string)($_GET['q'] ?? ''));
$roleFilter = trim((string)($_GET['role'] ?? ''));
$lockFilter = trim((string)($_GET['status'] ?? ''));

$where = ['1=1'];
$params = [];

if ($q !== '') {
    $where[] = "(u.username LIKE ? OR u.full_name LIKE ? OR u.email LIKE ? OR u.student_code LIKE ? OR u.class_name LIKE ?)";
    $like = "%{$q}%";
    array_push($params, $like, $like, $like, $like, $like);
}
if (in_array($roleFilter, ['learner', 'instructor', 'admin'], true)) {
    $where[] = "u.role = ?";
    $params[] = $roleFilter;
}
if ($lockFilter === 'locked') {
    $where[] = "u.is_locked = 1";
} elseif ($lockFilter === 'active') {
    $where[] = "u.is_locked = 0";
}

$whereSql = implode(' AND ', $where);

$totalActiveProblems = max(1, (int)$pdo->query("SELECT COUNT(*) FROM problems WHERE deleted_at IS NULL")->fetchColumn());

$stmt = $pdo->prepare("
    SELECT 
        u.id, u.username, u.email, u.full_name, u.student_code, u.class_name,
        u.role, u.is_locked, u.lock_reason, u.last_login_at, u.created_at,
        (SELECT COUNT(*) FROM submissions s WHERE s.user_id = u.id) AS total_submissions,
        (SELECT COUNT(*) FROM submissions s WHERE s.user_id = u.id AND s.final_verdict = 'AC') AS ac_submissions,
        (SELECT COUNT(DISTINCT s.problem_id) FROM submissions s WHERE s.user_id = u.id AND s.final_verdict = 'AC') AS solved_problems,
        (SELECT MAX(s.submitted_at) FROM submissions s WHERE s.user_id = u.id) AS last_submitted_at
    FROM users u
    WHERE {$whereSql}
    ORDER BY FIELD(u.role, 'admin', 'instructor', 'learner'), u.id ASC
");
$stmt->execute($params);
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);

// KPI tổng quan người dùng
$kpi = $pdo->query("
    SELECT 
        COUNT(*) AS total_users,
        SUM(CASE WHEN role = 'learner' THEN 1 ELSE 0 END) AS total_learners,
        SUM(CASE WHEN role IN ('admin', 'instructor') THEN 1 ELSE 0 END) AS total_staff,
        SUM(CASE WHEN is_locked = 1 THEN 1 ELSE 0 END) AS total_locked
    FROM users
")->fetch(PDO::FETCH_ASSOC);

$pageTitle = 'Quản lý Người dùng & Tài khoản';
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/sidebar.php';
?>

<div class="pl-64 min-h-screen flex flex-col w-full">
  <main class="p-8 max-w-7xl w-full mx-auto space-y-6 flex-1">
    <!-- Header -->
    <div class="flex flex-col md:flex-row md:items-center justify-between gap-4">
      <div>
        <h1 class="text-2xl font-black text-white flex items-center space-x-3">
          <i class="fa-solid fa-users-gear text-cyan-400"></i>
          <span>Quản lý Người dùng & Tài khoản</span>
        </h1>
        <p class="text-xs text-slate-400 mt-1">
          Theo dõi danh sách sinh viên, mã sinh viên, mức độ tham gia luyện tập, phân quyền quản trị và khóa/mở khóa tài khoản.
        </p>
      </div>
      <a href="/BaiTapLon/api/export_report.php?type=student_summary&format=csv" class="px-4 py-2.5 rounded-xl bg-emerald-500/10 hover:bg-emerald-500/20 text-emerald-300 border border-emerald-500/30 text-xs font-bold inline-flex items-center space-x-2 transition-colors">
        <i class="fa-solid fa-file-excel"></i>
        <span>Xuất Danh sách & Điểm (CSV)</span>
      </a>
    </div>

    <!-- Thông báo AJAX -->
    <div id="user-alert" class="hidden p-4 rounded-xl text-xs font-semibold"></div>

    <!-- KPI Cards -->
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
      <div class="glass-panel p-5 rounded-2xl border border-white/10 flex items-center space-x-4">
        <div class="w-11 h-11 rounded-xl bg-cyan-500/10 border border-cyan-500/30 text-cyan-400 flex items-center justify-center text-lg">
          <i class="fa-solid fa-users"></i>
        </div>
        <div>
          <div class="text-2xl font-black text-white"><?= (int)$kpi['total_users'] ?></div>
          <div class="text-[11px] text-slate-400 uppercase font-semibold">Tổng tài khoản</div>
        </div>
      </div>

      <div class="glass-panel p-5 rounded-2xl border border-white/10 flex items-center space-x-4">
        <div class="w-11 h-11 rounded-xl bg-blue-500/10 border border-blue-500/30 text-blue-400 flex items-center justify-center text-lg">
          <i class="fa-solid fa-user-graduate"></i>
        </div>
        <div>
          <div class="text-2xl font-black text-white"><?= (int)$kpi['total_learners'] ?></div>
          <div class="text-[11px] text-slate-400 uppercase font-semibold">Sinh viên</div>
        </div>
      </div>

      <div class="glass-panel p-5 rounded-2xl border border-white/10 flex items-center space-x-4">
        <div class="w-11 h-11 rounded-xl bg-purple-500/10 border border-purple-500/30 text-purple-400 flex items-center justify-center text-lg">
          <i class="fa-solid fa-user-shield"></i>
        </div>
        <div>
          <div class="text-2xl font-black text-white"><?= (int)$kpi['total_staff'] ?></div>
          <div class="text-[11px] text-slate-400 uppercase font-semibold">Admin & Giảng viên</div>
        </div>
      </div>

      <div class="glass-panel p-5 rounded-2xl border border-white/10 flex items-center space-x-4">
        <div class="w-11 h-11 rounded-xl bg-rose-500/10 border border-rose-500/30 text-rose-400 flex items-center justify-center text-lg">
          <i class="fa-solid fa-user-lock"></i>
        </div>
        <div>
          <div class="text-2xl font-black <?= (int)$kpi['total_locked'] > 0 ? 'text-rose-400' : 'text-emerald-400' ?>"><?= (int)$kpi['total_locked'] ?></div>
          <div class="text-[11px] text-slate-400 uppercase font-semibold">Đang bị khóa</div>
        </div>
      </div>
    </div>

    <!-- Bộ lọc -->
    <form method="GET" action="" class="glass-panel rounded-2xl p-4 border border-white/10 grid grid-cols-1 md:grid-cols-4 gap-3 text-xs">
      <div class="md:col-span-2 relative">
        <i class="fa-solid fa-magnifying-glass absolute left-3.5 top-1/2 -translate-y-1/2 text-slate-500"></i>
        <input type="text" name="q" value="<?= Security::escape($q) ?>" placeholder="Tìm theo tên, username, email, mã SV (VD: CNTT181001), lớp..."
          class="w-full glass-input rounded-xl py-2 pl-9 pr-3 text-xs">
      </div>
      <div>
        <select name="role" onchange="this.form.submit()" class="w-full glass-input rounded-xl py-2 px-3 bg-slate-900">
          <option value="">-- Tất cả Vai trò --</option>
          <option value="learner" <?= $roleFilter === 'learner' ? 'selected' : '' ?>>Sinh viên (Learner)</option>
          <option value="instructor" <?= $roleFilter === 'instructor' ? 'selected' : '' ?>>Giảng viên / Trợ giảng</option>
          <option value="admin" <?= $roleFilter === 'admin' ? 'selected' : '' ?>>Quản trị viên (Admin)</option>
        </select>
      </div>
      <div class="flex space-x-2">
        <select name="status" onchange="this.form.submit()" class="flex-1 glass-input rounded-xl py-2 px-3 bg-slate-900">
          <option value="">-- Trạng thái --</option>
          <option value="active" <?= $lockFilter === 'active' ? 'selected' : '' ?>>Đang hoạt động</option>
          <option value="locked" <?= $lockFilter === 'locked' ? 'selected' : '' ?>>Đang bị khóa</option>
        </select>
        <button type="submit" class="px-4 py-2 rounded-xl bg-cyan-500/20 hover:bg-cyan-500/30 text-cyan-300 border border-cyan-500/30 font-bold cursor-pointer">Lọc</button>
      </div>
    </form>

    <!-- Bảng Danh sách Tài khoản -->
    <div class="glass-panel rounded-2xl border border-white/10 overflow-hidden shadow-2xl">
      <div class="overflow-x-auto">
        <table class="w-full text-left border-collapse text-xs">
          <thead>
            <tr class="bg-white/5 text-[10px] uppercase tracking-wider text-slate-400 font-bold border-b border-white/10">
              <th class="py-3.5 px-5">Người dùng</th>
              <th class="py-3.5 px-4">Mã SV & Lớp</th>
              <th class="py-3.5 px-4 text-center">Phân quyền</th>
              <th class="py-3.5 px-4">Mức độ luyện tập</th>
              <th class="py-3.5 px-4 text-center">Trạng thái</th>
              <th class="py-3.5 px-5 text-right">Quản trị</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-white/5">
            <?php if (empty($users)): ?>
              <tr>
                <td colspan="6" class="py-10 text-center text-slate-500">Không tìm thấy tài khoản nào phù hợp.</td>
              </tr>
            <?php else: ?>
              <?php foreach ($users as $u): ?>
                <?php
                  $isSelf = ((int)$u['id'] === $currentAdminId);
                  $isLocked = ((int)$u['is_locked'] === 1);
                  $totalSub = (int)$u['total_submissions'];
                  $acSub = (int)$u['ac_submissions'];
                  $solved = (int)$u['solved_problems'];
                  $acRate = $totalSub > 0 ? round(($acSub / $totalSub) * 100, 1) : 0;
                  $progressPct = min(100, round(($solved / $totalActiveProblems) * 100));
                ?>
                <tr class="hover:bg-white/[0.02] transition-colors <?= $isLocked ? 'bg-rose-500/[0.03]' : '' ?>">
                  <td class="py-3.5 px-5">
                    <div class="flex items-center space-x-3">
                      <div class="w-9 h-9 rounded-xl <?= $isLocked ? 'bg-rose-500/20 border-rose-500/40 text-rose-300' : 'bg-cyan-500/15 border-cyan-500/30 text-cyan-300' ?> border font-bold flex items-center justify-center shrink-0">
                        <?= strtoupper(substr($u['username'], 0, 1)) ?>
                      </div>
                      <div>
                        <div class="font-bold text-white flex items-center space-x-1.5">
                          <span><?= Security::escape($u['full_name']) ?></span>
                          <?php if ($isSelf): ?>
                            <span class="px-1.5 py-0.2 rounded text-[9px] bg-cyan-500/20 text-cyan-300 border border-cyan-500/30">Bạn</span>
                          <?php endif; ?>
                        </div>
                        <div class="text-[11px] text-slate-400 font-mono">
                          @<?= Security::escape($u['username']) ?> · <?= Security::escape($u['email']) ?>
                        </div>
                      </div>
                    </div>
                  </td>

                  <td class="py-3.5 px-4 font-mono">
                    <?php if (!empty($u['student_code'])): ?>
                      <div class="font-bold text-cyan-300"><?= Security::escape($u['student_code']) ?></div>
                    <?php else: ?>
                      <div class="text-slate-600">—</div>
                    <?php endif; ?>
                    <div class="text-[10px] text-slate-400 font-sans"><?= Security::escape($u['class_name'] ?: 'Chưa gắn lớp') ?></div>
                  </td>

                  <td class="py-3.5 px-4 text-center">
                    <select onchange="changeUserRole(<?= (int)$u['id'] ?>, this.value, '<?= Security::escape($u['username']) ?>')"
                      <?= $isSelf ? 'disabled title="Không thể tự hạ quyền của chính mình"' : '' ?>
                      class="px-2.5 py-1.5 rounded-lg bg-slate-900 border border-white/15 text-[11px] font-bold focus:outline-none focus:border-cyan-400 <?= $isSelf ? 'opacity-60 cursor-not-allowed' : 'cursor-pointer' ?> <?= $u['role'] === 'admin' ? 'text-purple-300' : ($u['role'] === 'instructor' ? 'text-cyan-300' : 'text-slate-200') ?>">
                      <option value="learner" <?= $u['role'] === 'learner' ? 'selected' : '' ?>>Sinh viên</option>
                      <option value="instructor" <?= $u['role'] === 'instructor' ? 'selected' : '' ?>>Giảng viên / Trợ giảng</option>
                      <option value="admin" <?= $u['role'] === 'admin' ? 'selected' : '' ?>>Quản trị viên (Admin)</option>
                    </select>
                  </td>

                  <td class="py-3.5 px-4 min-w-[180px]">
                    <div class="flex items-center justify-between text-[11px] mb-1">
                      <span class="text-emerald-400 font-bold"><?= $solved ?> bài AC</span>
                      <span class="text-slate-400 font-mono"><?= $totalSub ?> lượt (<?= $acRate ?>% AC)</span>
                    </div>
                    <div class="w-full h-1.5 bg-white/10 rounded-full overflow-hidden">
                      <div class="h-full bg-gradient-to-r from-cyan-400 to-emerald-400 rounded-full" style="width: <?= $progressPct ?>%"></div>
                    </div>
                    <?php if (!empty($u['last_submitted_at'])): ?>
                      <div class="text-[10px] text-slate-500 mt-1">Nộp gần nhất: <?= date('d/m H:i', strtotime($u['last_submitted_at'])) ?></div>
                    <?php endif; ?>
                  </td>

                  <td class="py-3.5 px-4 text-center">
                    <?php if ($isLocked): ?>
                      <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-bold bg-rose-500/20 text-rose-300 border border-rose-500/30" title="<?= Security::escape((string)$u['lock_reason']) ?>">
                        <i class="fa-solid fa-lock mr-1"></i>Đã khóa
                      </span>
                      <?php if (!empty($u['lock_reason'])): ?>
                        <div class="text-[10px] text-rose-400/80 max-w-[140px] truncate mx-auto mt-0.5" title="<?= Security::escape($u['lock_reason']) ?>">
                          <?= Security::escape($u['lock_reason']) ?>
                        </div>
                      <?php endif; ?>
                    <?php else: ?>
                      <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-bold bg-emerald-500/15 text-emerald-400 border border-emerald-500/30">
                        <i class="fa-solid fa-circle-check mr-1"></i>Hoạt động
                      </span>
                    <?php endif; ?>
                  </td>

                  <td class="py-3.5 px-5 text-right space-x-1.5 whitespace-nowrap">
                    <button type="button"
                      onclick='openEditProfileModal(<?= json_encode([
                        "id" => (int)$u["id"],
                        "username" => $u["username"],
                        "full_name" => $u["full_name"],
                        "student_code" => $u["student_code"] ?? "",
                        "class_name" => $u["class_name"] ?? ""
                      ], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>)'
                      class="p-2 rounded-lg bg-white/5 hover:bg-white/10 text-slate-300 hover:text-white transition-colors cursor-pointer"
                      title="Sửa Mã SV, Lớp & Họ tên">
                      <i class="fa-solid fa-id-badge"></i>
                    </button>

                    <?php if (!$isSelf): ?>
                      <?php if ($isLocked): ?>
                        <button type="button" onclick="toggleUserLock(<?= (int)$u['id'] ?>, false, '<?= Security::escape($u['username']) ?>')"
                          class="px-2.5 py-1.5 rounded-lg bg-emerald-500/10 hover:bg-emerald-500/20 text-emerald-300 border border-emerald-500/30 text-[11px] font-bold cursor-pointer">
                          <i class="fa-solid fa-lock-open mr-1"></i>Mở khóa
                        </button>
                      <?php else: ?>
                        <button type="button" onclick="openLockModal(<?= (int)$u['id'] ?>, '<?= Security::escape($u['username']) ?>')"
                          class="px-2.5 py-1.5 rounded-lg bg-rose-500/10 hover:bg-rose-500/20 text-rose-300 border border-rose-500/30 text-[11px] font-bold cursor-pointer">
                          <i class="fa-solid fa-ban mr-1"></i>Khóa
                        </button>
                      <?php endif; ?>
                    <?php endif; ?>
                  </td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </main>
</div>

<!-- MODAL KHÓA TÀI KHOẢN -->
<div id="lockModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/80 backdrop-blur-sm hidden p-4">
  <div class="glass-panel w-full max-w-md rounded-2xl border border-white/20 p-6 space-y-4 shadow-2xl">
    <div class="flex items-center justify-between border-b border-white/10 pb-3">
      <h3 class="text-base font-bold text-white flex items-center space-x-2">
        <i class="fa-solid fa-user-lock text-rose-400"></i>
        <span>Khóa Tài Khoản <span id="lock-username" class="text-cyan-400 font-mono"></span></span>
      </h3>
      <button onclick="document.getElementById('lockModal').classList.add('hidden')" class="text-slate-400 hover:text-white"><i class="fa-solid fa-xmark"></i></button>
    </div>
    <input type="hidden" id="lock-user-id">
    <div>
      <label class="block text-xs font-bold text-slate-300 mb-1.5">Lý do khóa tài khoản</label>
      <textarea id="lock-reason" rows="3" placeholder="VD: Gian lận bài kiểm tra, sao chép mã nguồn hoặc spam hệ thống..."
        class="w-full p-3 rounded-xl bg-black/40 border border-white/15 text-white text-xs focus:outline-none focus:border-rose-400"></textarea>
    </div>
    <div class="flex justify-end space-x-2 pt-2">
      <button type="button" onclick="document.getElementById('lockModal').classList.add('hidden')" class="px-4 py-2 rounded-xl bg-white/5 text-slate-300 text-xs font-semibold">Hủy</button>
      <button type="button" onclick="confirmLockUser()" class="px-4 py-2 rounded-xl bg-rose-500 hover:bg-rose-600 text-white text-xs font-bold cursor-pointer">Xác Nhận Khóa</button>
    </div>
  </div>
</div>

<!-- MODAL CẬP NHẬT HỒ SƠ SINH VIÊN -->
<div id="profileModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/80 backdrop-blur-sm hidden p-4">
  <div class="glass-panel w-full max-w-md rounded-2xl border border-white/20 p-6 space-y-4 shadow-2xl">
    <div class="flex items-center justify-between border-b border-white/10 pb-3">
      <h3 class="text-base font-bold text-white flex items-center space-x-2">
        <i class="fa-solid fa-id-badge text-cyan-400"></i>
        <span>Cập nhật Hồ sơ <span id="prof-username" class="text-cyan-400 font-mono"></span></span>
      </h3>
      <button onclick="document.getElementById('profileModal').classList.add('hidden')" class="text-slate-400 hover:text-white"><i class="fa-solid fa-xmark"></i></button>
    </div>
    <input type="hidden" id="prof-user-id">
    <div class="space-y-3 text-xs">
      <div>
        <label class="block font-bold text-slate-300 mb-1">Họ và tên</label>
        <input type="text" id="prof-full-name" class="w-full px-3 py-2 rounded-xl bg-black/40 border border-white/15 text-white">
      </div>
      <div>
        <label class="block font-bold text-slate-300 mb-1">Mã sinh viên (MSSV)</label>
        <input type="text" id="prof-student-code" placeholder="VD: CNTT181001" class="w-full px-3 py-2 rounded-xl bg-black/40 border border-white/15 text-white font-mono uppercase">
      </div>
      <div>
        <label class="block font-bold text-slate-300 mb-1">Lớp học phần / Lớp hành chính</label>
        <input type="text" id="prof-class-name" placeholder="VD: CNTT-18-10" class="w-full px-3 py-2 rounded-xl bg-black/40 border border-white/15 text-white">
      </div>
    </div>
    <div class="flex justify-end space-x-2 pt-2">
      <button type="button" onclick="document.getElementById('profileModal').classList.add('hidden')" class="px-4 py-2 rounded-xl bg-white/5 text-slate-300 text-xs font-semibold">Hủy</button>
      <button type="button" onclick="saveUserProfile()" class="btn-cyan px-4 py-2 rounded-xl text-black text-xs font-bold cursor-pointer">Lưu Thay Đổi</button>
    </div>
  </div>
</div>

<script>
function showUserAlert(msg, ok = true) {
  const el = document.getElementById('user-alert');
  el.classList.remove('hidden', 'bg-emerald-500/10', 'border-emerald-500/30', 'text-emerald-300', 'bg-rose-500/10', 'border-rose-500/30', 'text-rose-300');
  el.classList.add('border', ...(ok ? ['bg-emerald-500/10', 'border-emerald-500/30', 'text-emerald-300'] : ['bg-rose-500/10', 'border-rose-500/30', 'text-rose-300']));
  el.innerHTML = `<i class="fa-solid ${ok ? 'fa-circle-check' : 'fa-triangle-exclamation'} mr-2"></i>${msg}`;
}

async function changeUserRole(userId, newRole, username) {
  if (!confirm(`Bạn có chắc muốn đổi quyền tài khoản @${username} thành "${newRole}"?`)) {
    window.location.reload();
    return;
  }
  try {
    const res = await fetch('/BaiTapLon/api/admin_users.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ action: 'update_role', user_id: userId, role: newRole })
    });
    const data = await res.json();
    if (!res.ok || !data.success) throw new Error(data.error || 'Lỗi cập nhật quyền.');
    showUserAlert(data.message, true);
    setTimeout(() => window.location.reload(), 700);
  } catch (err) {
    showUserAlert(err.message, false);
    setTimeout(() => window.location.reload(), 1200);
  }
}

function openLockModal(userId, username) {
  document.getElementById('lock-user-id').value = userId;
  document.getElementById('lock-username').textContent = '@' + username;
  document.getElementById('lock-reason').value = '';
  document.getElementById('lockModal').classList.remove('hidden');
}

async function confirmLockUser() {
  const userId = parseInt(document.getElementById('lock-user-id').value);
  const reason = document.getElementById('lock-reason').value.trim();
  await toggleUserLock(userId, true, '', reason);
  document.getElementById('lockModal').classList.add('hidden');
}

async function toggleUserLock(userId, lock, username = '', reason = '') {
  if (!lock && !confirm(`Mở khóa cho tài khoản @${username}?`)) return;
  try {
    const res = await fetch('/BaiTapLon/api/admin_users.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ action: 'toggle_lock', user_id: userId, lock: lock ? 1 : 0, lock_reason: reason })
    });
    const data = await res.json();
    if (!res.ok || !data.success) throw new Error(data.error || 'Lỗi thao tác khóa/mở khóa.');
    showUserAlert(data.message, true);
    setTimeout(() => window.location.reload(), 700);
  } catch (err) {
    showUserAlert(err.message, false);
  }
}

function openEditProfileModal(u) {
  document.getElementById('prof-user-id').value = u.id;
  document.getElementById('prof-username').textContent = '@' + u.username;
  document.getElementById('prof-full-name').value = u.full_name || '';
  document.getElementById('prof-student-code').value = u.student_code || '';
  document.getElementById('prof-class-name').value = u.class_name || '';
  document.getElementById('profileModal').classList.remove('hidden');
}

async function saveUserProfile() {
  try {
    const res = await fetch('/BaiTapLon/api/admin_users.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        action: 'update_profile',
        user_id: parseInt(document.getElementById('prof-user-id').value),
        full_name: document.getElementById('prof-full-name').value.trim(),
        student_code: document.getElementById('prof-student-code').value.trim(),
        class_name: document.getElementById('prof-class-name').value.trim()
      })
    });
    const data = await res.json();
    if (!res.ok || !data.success) throw new Error(data.error || 'Lỗi cập nhật hồ sơ.');
    document.getElementById('profileModal').classList.add('hidden');
    showUserAlert(data.message, true);
    setTimeout(() => window.location.reload(), 700);
  } catch (err) {
    showUserAlert(err.message, false);
  }
}
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
