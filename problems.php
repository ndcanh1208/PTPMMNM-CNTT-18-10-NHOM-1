<?php
$pageTitle = 'Danh Sách Bài Tập Thuật Toán';

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/auth.php';

$problems = [];
$errorMsg = null;

try {
    $pdo = Database::getConnection();
    $stmt = $pdo->query("
        SELECT p.id, p.code, p.title, p.topic, p.difficulty, 
               pv.time_limit_ms, pv.memory_limit_kb,
               (SELECT COUNT(*) FROM test_cases tc WHERE tc.problem_version_id = pv.id) as test_count
        FROM problems p
        JOIN problem_versions pv ON p.current_version_id = pv.id
        WHERE p.is_published = 1
        ORDER BY p.id ASC
    ");
    $problems = $stmt->fetchAll();
} catch (Exception $e) {
    $errorMsg = $e->getMessage();
}

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/navbar.php';
?>

<div class="container my-5">
    <div class="d-flex align-items-center justify-content-between flex-wrap gap-3 mb-4">
        <div>
            <h2 class="fw-bold text-white mb-1">
                <i class="fas fa-cubes text-primary me-2"></i>Kho Bài Tập Thuật Toán
            </h2>
            <p class="text-secondary mb-0">Hỗ trợ đầy đủ ngôn ngữ Python 3 và C++ 17 với môi trường thực thi cách ly</p>
        </div>
        <div class="d-flex align-items-center gap-2">
            <span class="badge bg-primary-subtle text-primary border border-primary-subtle px-3 py-2 fs-6">
                Tổng cộng: <?php echo count($problems); ?> bài tập
            </span>
        </div>
    </div>

    <?php if ($errorMsg): ?>
        <div class="alert alert-warning custom-card border-warning text-warning p-4">
            <i class="fas fa-exclamation-triangle me-2"></i>
            Chưa kết nối được CSDL hoặc CSDL chưa có dữ liệu. Vui lòng mở <a href="database/install.php" class="text-warning fw-bold text-decoration-underline">database/install.php</a> để khởi tạo.
        </div>
    <?php endif; ?>

    <!-- Bộ lọc & Tìm kiếm -->
    <div class="custom-card p-3 mb-4">
        <div class="row g-3 align-items-center">
            <div class="col-md-5">
                <div class="input-group">
                    <span class="input-group-text bg-dark border-secondary text-secondary">
                        <i class="fas fa-search"></i>
                    </span>
                    <input type="text" id="filterSearch" class="form-control bg-dark border-secondary text-white" placeholder="Tìm kiếm bài tập theo tên hoặc mã bài...">
                </div>
            </div>
            <div class="col-md-3">
                <select id="filterDifficulty" class="form-select bg-dark border-secondary text-white">
                    <option value="">Tất cả độ khó</option>
                    <option value="EASY">Dễ (Easy)</option>
                    <option value="MEDIUM">Trung bình (Medium)</option>
                    <option value="HARD">Khó (Hard)</option>
                </select>
            </div>
            <div class="col-md-4">
                <select id="filterTopic" class="form-select bg-dark border-secondary text-white">
                    <option value="">Tất cả chủ đề</option>
                    <option value="Cơ bản">Cơ bản</option>
                    <option value="Toán học">Toán học</option>
                    <option value="Mảng">Mảng</option>
                    <option value="Chuỗi ký tự">Chuỗi ký tự</option>
                    <option value="Đệ quy & QHD">Đệ quy & QHD</option>
                    <option value="Thuật toán sắp xếp">Thuật toán sắp xếp</option>
                    <option value="Quy hoạch động">Quy hoạch động</option>
                </select>
            </div>
        </div>
    </div>

    <!-- Bảng danh sách bài tập -->
    <div class="custom-card overflow-hidden">
        <div class="table-responsive">
            <table class="table table-dark table-hover align-middle mb-0" id="problemTable">
                <thead class="text-secondary border-bottom border-secondary">
                    <tr>
                        <th class="ps-4 py-3" style="width: 80px;">STT</th>
                        <th class="py-3">Mã &amp; Tên Bài Tập</th>
                        <th class="py-3">Chủ Đề</th>
                        <th class="py-3">Độ Khó</th>
                        <th class="py-3">Giới Hạn</th>
                        <th class="py-3 text-center">Bộ Test</th>
                        <th class="pe-4 py-3 text-end" style="width: 140px;">Hành Động</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($problems)): ?>
                        <tr>
                            <td colspan="7" class="text-center py-5 text-secondary">
                                Không có bài tập nào được tìm thấy.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($problems as $idx => $p): 
                            $diffClass = ($p['difficulty'] === 'EASY') ? 'badge-easy' : (($p['difficulty'] === 'MEDIUM') ? 'badge-medium' : 'badge-hard');
                            $diffLabel = ($p['difficulty'] === 'EASY') ? 'Dễ' : (($p['difficulty'] === 'MEDIUM') ? 'Trung bình' : 'Khó');
                        ?>
                            <tr class="problem-row" 
                                data-title="<?php echo htmlspecialchars(mb_strtolower($p['title'])); ?>"
                                data-code="<?php echo htmlspecialchars(mb_strtolower($p['code'])); ?>"
                                data-difficulty="<?php echo $p['difficulty']; ?>"
                                data-topic="<?php echo htmlspecialchars($p['topic']); ?>">
                                <td class="ps-4 fw-bold text-secondary"><?php echo $idx + 1; ?></td>
                                <td>
                                    <a href="solve.php?id=<?php echo $p['id']; ?>" class="text-decoration-none text-white fw-bold d-block">
                                        <?php echo htmlspecialchars($p['title']); ?>
                                    </a>
                                    <code class="text-secondary small"><?php echo htmlspecialchars($p['code']); ?></code>
                                </td>
                                <td>
                                    <span class="badge bg-dark border border-secondary text-light">
                                        <?php echo htmlspecialchars($p['topic']); ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="badge <?php echo $diffClass; ?> px-2 py-1">
                                        <?php echo $diffLabel; ?>
                                    </span>
                                </td>
                                <td class="small text-secondary">
                                    <div><i class="fas fa-stopwatch me-1"></i><?php echo $p['time_limit_ms']; ?>ms</div>
                                    <div><i class="fas fa-memory me-1"></i><?php echo round($p['memory_limit_kb'] / 1024); ?>MB</div>
                                </td>
                                <td class="text-center">
                                    <span class="badge bg-secondary-subtle text-light rounded-pill px-2">
                                        <?php echo $p['test_count']; ?> tests
                                    </span>
                                </td>
                                <td class="pe-4 text-end">
                                    <a href="solve.php?id=<?php echo $p['id']; ?>" class="btn btn-primary btn-sm px-3 rounded-pill">
                                        <span>Luyện tập</span>
                                        <i class="fas fa-chevron-right ms-1 small"></i>
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
    const searchInput = document.getElementById('filterSearch');
    const diffSelect  = document.getElementById('filterDifficulty');
    const topicSelect = document.getElementById('filterTopic');
    const rows        = document.querySelectorAll('.problem-row');

    function applyFilter() {
        const query = searchInput.value.toLowerCase().trim();
        const diff  = diffSelect.value;
        const topic = topicSelect.value;

        rows.forEach(row => {
            const rowTitle = row.dataset.title;
            const rowCode  = row.dataset.code;
            const rowDiff  = row.dataset.difficulty;
            const rowTopic = row.dataset.topic;

            const matchQuery = !query || rowTitle.includes(query) || rowCode.includes(query);
            const matchDiff  = !diff || rowDiff === diff;
            const matchTopic = !topic || rowTopic === topic;

            if (matchQuery && matchDiff && matchTopic) {
                row.style.display = '';
            } else {
                row.style.display = 'none';
            }
        });
    }

    searchInput.addEventListener('input', applyFilter);
    diffSelect.addEventListener('change', applyFilter);
    topicSelect.addEventListener('change', applyFilter);
});
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
