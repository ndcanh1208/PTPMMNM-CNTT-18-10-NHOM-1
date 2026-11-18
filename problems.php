<?php
/**
 * Nhom1 Online Judge - Thư viện Bài tập (Problems Library)
 * Bộ lọc theo Chủ đề, Độ khó, Phiên bản và Trạng thái giải
 */

// ============================================================================
// PHẦN 1: XỬ LÝ PHP & TRUY VẤN CƠ SỞ DỮ LIỆU (LỌC DANH SÁCH BÀI TẬP)
// ============================================================================

// Bật chế độ kiểm tra kiểu dữ liệu nghiêm ngặt trong PHP
declare(strict_types=1);

// Nạp cấu hình kết nối CSDL và các lớp xử lý xác thực, bảo mật
require_once __DIR__ . '/config/database.php'; // Cung cấp getDbConnection()
require_once __DIR__ . '/core/Auth.php';       // Kiểm tra đăng nhập & phân quyền
require_once __DIR__ . '/core/Security.php';   // Thoát ký tự đặc biệt (escape) chống XSS

// Khởi động session, lấy thông tin người dùng hiện tại và kết nối PDO tới MySQL
Auth::startSession();
$currentUser = Auth::user();
$pdo = getDbConnection();

// Đọc các tham số bộ lọc từ thanh địa chỉ URL (phương thức GET, ví dụ: ?topic=Mang&difficulty=Easy&q=tong&status=solved)
// Dùng ?? '' để gán chuỗi rỗng nếu tham số chưa được truyền, trim() để bỏ khoảng trắng thừa
$topicFilter      = trim((string)($_GET['topic'] ?? ''));
$difficultyFilter = trim((string)($_GET['difficulty'] ?? ''));
$searchKeyword    = trim((string)($_GET['q'] ?? ''));
$statusFilter     = trim((string)($_GET['status'] ?? ''));

// Xây dựng mệnh đề WHERE động (mỗi điều kiện lọc sẽ được thêm vào mảng $whereClauses và giá trị đưa vào $params)
// Mặc định chỉ lấy bài tập công khai (is_public = 1) và chưa bị xóa mềm (deleted_at IS NULL)
$whereClauses = ["p.is_public = 1", "p.deleted_at IS NULL"];
$params = [];

// Nếu người dùng chọn lọc theo Chủ đề -> thêm điều kiện so khớp chính xác cột p.topic
if ($topicFilter !== '') {
    $whereClauses[] = "p.topic = :topic";
    $params['topic'] = $topicFilter;
}

// Nếu người dùng chọn lọc theo Độ khó (Easy / Medium / Hard) -> thêm điều kiện cột p.difficulty
if ($difficultyFilter !== '') {
    $whereClauses[] = "p.difficulty = :diff";
    $params['diff'] = $difficultyFilter;
}

// Nếu người dùng nhập từ khóa tìm kiếm -> tìm gần đúng (LIKE %từ_khóa%) trong tên bài, slug hoặc chủ đề
if ($searchKeyword !== '') {
    $whereClauses[] = "(p.title LIKE :q1 OR p.slug LIKE :q2 OR p.topic LIKE :q3)";
    $params['q1'] = "%{$searchKeyword}%";
    $params['q2'] = "%{$searchKeyword}%";
    $params['q3'] = "%{$searchKeyword}%";
}

// Nối tất cả các điều kiện lại bằng từ khóa ' AND ' để ghép vào câu lệnh SQL
$whereSql = implode(' AND ', $whereClauses);

// Truy vấn danh sách bài tập kèm phiên bản hiện tại (JOIN problem_versions) và thông tin người tạo (LEFT JOIN users)
// Dùng Prepared Statement (:topic, :diff, :q1...) để đảm bảo an toàn tuyệt đối trước SQL Injection
$stmt = $pdo->prepare("
    SELECT p.id, p.slug, p.title, p.topic, p.difficulty, p.source_attribution,
           pv.id as version_id, pv.version_number, pv.time_limit_ms, pv.memory_limit_kb,
           u.username as creator_username, u.full_name as creator_name
    FROM problems p
    JOIN problem_versions pv ON p.current_version_id = pv.id
    LEFT JOIN users u ON p.created_by = u.id
    WHERE {$whereSql}
    ORDER BY p.id ASC
");
$stmt->execute($params);
$problems = $stmt->fetchAll();

// Lấy danh sách các chủ đề duy nhất (DISTINCT) hiện có để đổ vào ô chọn <select name="topic">
// PDO::FETCH_COLUMN trả về mảng 1 chiều chứa trực tiếp tên các chủ đề
$allTopics = $pdo->query("SELECT DISTINCT topic FROM problems WHERE is_public = 1 AND deleted_at IS NULL ORDER BY topic ASC")->fetchAll(PDO::FETCH_COLUMN);

// Nếu người dùng đã đăng nhập, lấy danh sách ID các bài tập mà họ đã đạt kết quả đúng (final_verdict = 'AC')
$solvedMap = [];
if ($currentUser) {
    $userSolved = $pdo->prepare("
        SELECT DISTINCT problem_id 
        FROM submissions 
        WHERE user_id = ? AND final_verdict = 'AC'
    ");
    $userSolved->execute([$currentUser['id']]);
    $solvedIds = $userSolved->fetchAll(PDO::FETCH_COLUMN);
    // Dùng array_flip() biến giá trị problem_id thành khóa (key) của mảng để kiểm tra isset($solvedMap[$id]) với tốc độ O(1)
    $solvedMap = array_flip($solvedIds);
}

// Lọc bổ sung bằng PHP theo trạng thái đã giải / chưa giải nếu người dùng chọn ô bộ lọc Trạng thái
if ($statusFilter === 'solved') {
    $problems = array_filter($problems, fn($p) => isset($solvedMap[$p['id']]));
} elseif ($statusFilter === 'unsolved') {
    $problems = array_filter($problems, fn($p) => !isset($solvedMap[$p['id']]));
}

// Khai báo tiêu đề trang và nạp phần khung giao diện dùng chung (Header + Sidebar)
$pageTitle = 'Thư viện Bài tập';
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/sidebar.php';
?>

<!-- ========================================================================= -->
<!-- PHẦN 2: GIAO DIỆN HTML THƯ VIỆN BÀI TẬP & BỘ LỌC TÌM KIẾM                -->
<!-- ========================================================================= -->

<!-- Khung nội dung chính (chừa lề trái 256px cho thanh Sidebar cố định) -->
<div class="pl-64 min-h-screen flex flex-col w-full">
  <main class="p-8 max-w-7xl w-full mx-auto space-y-6 flex-1">

    <!-- Khối tiêu đề trang, tổng số bài tìm thấy và nút Tạo bài tập (dành cho Giảng viên / Admin) -->
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
      <div>
        <h1 class="text-2xl font-black text-white flex items-center space-x-2">
          <i class="fa-solid fa-book-bookmark text-cyan-400"></i>
          <span>Thư viện Bài tập Lập trình</span>
        </h1>
        <p class="text-xs text-slate-400 mt-1">
          Hệ thống 10 bài tập chuẩn hóa, hỗ trợ kiểm thử biên và phân loại tự động.
        </p>
      </div>

      <div class="flex items-center space-x-3">
        <!-- Hiển thị số lượng bài tập khớp với bộ lọc hiện tại -->
        <div class="text-xs text-slate-400 font-mono">
          Tìm thấy <span class="text-cyan-400 font-bold"><?= count($problems) ?></span> bài tập
        </div>
        <!-- Chỉ Giảng viên hoặc Quản trị viên mới nhìn thấy nút sang trang quản lý / tạo đề bài mới -->
        <?php if (Auth::isInstructor() || Auth::isAdmin()): ?>
          <a href="/BaiTapLon/manage_problems.php" class="btn-cyan px-4 py-2 rounded-xl text-black font-bold text-xs flex items-center space-x-1.5 shadow-[0_0_15px_rgba(0,255,255,0.4)] hover:scale-105 transition-all">
            <i class="fa-solid fa-plus text-black"></i>
            <span>Tạo bài tập mới</span>
          </a>
        <?php endif; ?>
      </div>
    </div>

    <!-- Thanh Tìm kiếm & Bộ lọc: gửi dữ liệu qua phương thức GET để các lựa chọn xuất hiện trên URL -->
    <form action="" method="GET" class="glass-panel rounded-2xl p-4 border border-white/10 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3">
      <!-- Ô nhập từ khóa tìm kiếm (nhấn Enter để gửi form) -->
      <div class="relative">
        <span class="absolute inset-y-0 left-0 flex items-center pl-3 text-slate-500">
          <i class="fa-solid fa-magnifying-glass text-xs"></i>
        </span>
        <input type="text" name="q" value="<?= Security::escape($searchKeyword) ?>" 
          placeholder="Tìm theo tên hoặc từ khóa..."
          class="w-full glass-input rounded-xl py-2 pl-9 pr-3 text-xs placeholder-slate-500">
      </div>

      <!-- Ô chọn lọc Chủ đề: thuộc tính onchange="this.form.submit()" tự động gửi form ngay khi người dùng chọn -->
      <div>
        <select name="topic" onchange="this.form.submit()" class="w-full glass-input rounded-xl py-2 px-3 text-xs bg-slate-900">
          <option value="">-- Tất cả Chủ đề --</option>
          <?php foreach ($allTopics as $t): ?>
            <option value="<?= Security::escape($t) ?>" <?= $topicFilter === $t ? 'selected' : '' ?>>
              <?= Security::escape($t) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <!-- Ô chọn lọc Độ khó (Easy / Medium / Hard) -->
      <div>
        <select name="difficulty" onchange="this.form.submit()" class="w-full glass-input rounded-xl py-2 px-3 text-xs bg-slate-900">
          <option value="">-- Tất cả Độ khó --</option>
          <option value="Easy" <?= $difficultyFilter === 'Easy' ? 'selected' : '' ?>>Easy (Dễ)</option>
          <option value="Medium" <?= $difficultyFilter === 'Medium' ? 'selected' : '' ?>>Medium (Trung bình)</option>
          <option value="Hard" <?= $difficultyFilter === 'Hard' ? 'selected' : '' ?>>Hard (Khó)</option>
        </select>
      </div>

      <!-- Ô chọn lọc Trạng thái giải (AC / Chưa giải) kèm nút X đặt lại bộ lọc nếu đang lọc -->
      <div class="flex items-center space-x-2">
        <select name="status" onchange="this.form.submit()" class="w-full glass-input rounded-xl py-2 px-3 text-xs bg-slate-900">
          <option value="">-- Tất cả Trạng thái --</option>
          <option value="solved" <?= $statusFilter === 'solved' ? 'selected' : '' ?>>Đã giải quyết (AC)</option>
          <option value="unsolved" <?= $statusFilter === 'unsolved' ? 'selected' : '' ?>>Chưa giải</option>
        </select>
        
        <!-- Chỉ hiện nút xóa bộ lọc khi ít nhất 1 điều kiện lọc đang được áp dụng -->
        <?php if ($searchKeyword !== '' || $topicFilter !== '' || $difficultyFilter !== '' || $statusFilter !== ''): ?>
          <a href="/BaiTapLon/problems.php" class="p-2 rounded-xl bg-white/5 hover:bg-white/10 text-slate-400 hover:text-white text-xs shrink-0" title="Đặt lại bộ lọc">
            <i class="fa-solid fa-xmark"></i>
          </a>
        <?php endif; ?>
      </div>
    </form>

    <!-- Bảng hiển thị Danh sách Bài tập (hỗ trợ cuộn ngang overflow-x-auto trên màn hình nhỏ) -->
    <div class="glass-panel rounded-2xl overflow-hidden border border-white/10 shadow-xl">
      <div class="overflow-x-auto">
        <table class="w-full text-left text-xs border-collapse">
          <!-- Dòng tiêu đề các cột trong bảng -->
          <thead>
            <tr class="border-b border-white/10 bg-white/[0.02] text-slate-400 uppercase font-semibold tracking-wider text-[11px]">
              <th class="py-3.5 px-4 w-16 text-center">Trạng thái</th>
              <th class="py-3.5 px-4 w-12 text-center">ID</th>
              <th class="py-3.5 px-4">Tên Bài tập</th>
              <th class="py-3.5 px-4 w-36">Chủ đề</th>
              <th class="py-3.5 px-4 w-28 text-center">Độ khó</th>
              <th class="py-3.5 px-4 w-28 text-center">Phiên bản</th>
              <th class="py-3.5 px-4 w-44">Nguồn gốc & Bản quyền</th>
              <th class="py-3.5 px-4 w-28 text-center">Thao tác</th>
            </tr>
          </thead>
          <!-- Thân bảng: hiển thị thông báo trống hoặc lặp qua từng bài tập trong $problems -->
          <tbody class="divide-y divide-white/5">
            <?php if (empty($problems)): ?>
              <tr>
                <td colspan="8" class="py-12 text-center text-slate-500 text-xs">
                  Không tìm thấy bài tập nào phù hợp với bộ lọc hiện tại.
                </td>
              </tr>
            <?php else: ?>
              <?php foreach ($problems as $p): ?>
                <?php
                  // Kiểm tra nhanh bài này đã được người dùng hiện tại giải đúng (AC) hay chưa
                  $isSolved = isset($solvedMap[$p['id']]);
                  // Chọn bộ class màu Tailwind cho nhãn độ khó tương ứng bằng biểu thức match
                  $diffBadge = match($p['difficulty']) {
                    'Easy'   => 'bg-emerald-500/15 text-emerald-400 border-emerald-500/30',
                    'Medium' => 'bg-amber-500/15 text-amber-400 border-amber-500/30',
                    'Hard'   => 'bg-rose-500/15 text-rose-400 border-rose-500/30',
                    default  => 'bg-slate-500/15 text-slate-400'
                  };
                ?>
                <tr class="hover:bg-white/[0.03] transition-colors group">
                  <!-- Cột 1: Biểu tượng tick xanh nếu đã AC, ngược lại hiện dấu chấm mờ -->
                  <td class="py-3.5 px-4 text-center">
                    <?php if ($isSolved): ?>
                      <span title="Đã hoàn thành AC" class="text-emerald-400 text-sm">
                        <i class="fa-solid fa-circle-check"></i>
                      </span>
                    <?php else: ?>
                      <span class="text-slate-600 text-xs">•</span>
                    <?php endif; ?>
                  </td>

                  <!-- Cột 2: Mã ID định danh của bài tập -->
                  <td class="py-3.5 px-4 text-center font-mono text-slate-400">
                    #<?= $p['id'] ?>
                  </td>

                  <!-- Cột 3: Tên bài tập, đường dẫn rút gọn (slug) và nhãn tác giả (chỉ hiển thị cho Admin) -->
                  <td class="py-3.5 px-4">
                    <a href="/BaiTapLon/solve.php?id=<?= $p['id'] ?>" class="font-bold text-white group-hover:text-cyan-400 transition-colors text-sm">
                      <?= Security::escape($p['title']) ?>
                    </a>
                    <div class="text-[11px] text-slate-500 font-mono mt-0.5 flex items-center space-x-2">
                      <span><?= Security::escape($p['slug']) ?></span>
                      <?php if (Auth::isAdmin() && !empty($p['creator_username'])): ?>
                        <span class="inline-flex items-center space-x-1 text-[10px] text-cyan-400 bg-cyan-500/10 px-1.5 py-0.5 rounded border border-cyan-500/20 font-mono" title="Người tạo bài tập">
                          <i class="fa-solid fa-user-pen text-[9px]"></i>
                          <span>@<?= Security::escape($p['creator_username']) ?></span>
                        </span>
                      <?php endif; ?>
                    </div>
                  </td>

                  <!-- Cột 4: Chủ đề thuật toán / kiến thức -->
                  <td class="py-3.5 px-4 text-slate-300">
                    <?= Security::escape($p['topic']) ?>
                  </td>

                  <!-- Cột 5: Nhãn độ khó -->
                  <td class="py-3.5 px-4 text-center">
                    <span class="px-2 py-0.5 rounded text-[11px] font-bold border <?= $diffBadge ?>">
                      <?= $p['difficulty'] ?>
                    </span>
                  </td>

                  <!-- Cột 6: Số hiệu phiên bản bộ dữ liệu test hiện tại -->
                  <td class="py-3.5 px-4 text-center">
                    <span class="px-2 py-0.5 rounded text-[11px] font-mono bg-white/5 border border-white/10 text-cyan-300">
                      v<?= $p['version_number'] ?>.0
                    </span>
                  </td>

                  <!-- Cột 7: Thông tin nguồn gốc & bản quyền đề bài -->
                  <td class="py-3.5 px-4 text-slate-400 text-[11px]">
                    <div class="truncate max-w-[200px]" title="<?= Security::escape($p['source_attribution']) ?>">
                      <i class="fa-regular fa-copyright mr-1"></i><?= Security::escape($p['source_attribution']) ?>
                    </div>
                  </td>

                  <!-- Cột 8: Nút điều hướng sang trang viết code giải bài solve.php -->
                  <td class="py-3.5 px-4 text-center">
                    <a href="/BaiTapLon/solve.php?id=<?= $p['id'] ?>" class="btn-cyan text-xs py-1.5 px-3 inline-flex items-center space-x-1">
                      <span>Làm bài</span>
                      <i class="fa-solid fa-chevron-right text-[9px]"></i>
                    </a>
                  </td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

  </main>
<!-- Nạp phần chân trang chung để đóng layout -->
<?php require_once __DIR__ . '/includes/footer.php'; ?>
