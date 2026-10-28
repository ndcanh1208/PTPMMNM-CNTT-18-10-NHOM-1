<?php
$pageTitle = 'Lịch Sử Nộp Bài & Tra Cứu Tiến Độ';

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/auth.php';

$submissions = [];
$errorMsg = null;

try {
    $pdo = Database::getConnection();

    $stmt = $pdo->query("
        SELECT s.id, s.language_id, s.created_at, s.queue_status,
               u.full_name, u.username,
               p.id as problem_id, p.code as problem_code, p.title as problem_title,
               jr.id as run_id, jr.run_number, jr.verdict, jr.total_score, 
               jr.passed_test_count, jr.total_test_count, jr.max_time_ms
        FROM submissions s
        JOIN users u ON s.user_id = u.id
        JOIN problems p ON s.problem_id = p.id
        LEFT JOIN judging_runs jr ON s.id = jr.submission_id AND jr.is_latest = 1
        ORDER BY s.id DESC
        LIMIT 100
    ");
    $submissions = $stmt->fetchAll();
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
                <i class="fas fa-clock-rotate-left text-primary me-2"></i>Lịch Sử Nộp Bài
            </h2>
            <p class="text-secondary mb-0">Theo dõi tiến độ, phân biệt kết quả cũ/mới và bảo vệ an toàn mã nguồn riêng</p>
        </div>
        <div>
            <a href="problems.php" class="btn btn-primary btn-sm px-3">
                <i class="fas fa-plus me-1"></i> Làm bài tập mới
            </a>
        </div>
    </div>

    <?php if ($errorMsg): ?>
        <div class="alert alert-warning custom-card border-warning text-warning p-4">
            <i class="fas fa-exclamation-triangle me-2"></i>
            Chưa có dữ liệu bài nộp hoặc CSDL chưa kết nối.
        </div>
    <?php endif; ?>

    <!-- Bảng danh sách bài nộp -->
    <div class="custom-card overflow-hidden">
        <div class="table-responsive">
            <table class="table table-dark table-hover align-middle mb-0">
                <thead class="text-secondary border-bottom border-secondary">
                    <tr>
                        <th class="ps-4 py-3">Mã Nộp</th>
                        <th class="py-3">Học Viên</th>
                        <th class="py-3">Bài Tập</th>
                        <th class="py-3">Ngôn Ngữ</th>
                        <th class="py-3">Kết Luận (Verdict)</th>
                        <th class="py-3">Điểm Số</th>
                        <th class="py-3">Thời Gian Chạy</th>
                        <th class="py-3">Thời Điểm Nộp</th>
                        <th class="pe-4 py-3 text-end">Chi Tiết</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($submissions)): ?>
                        <tr>
                            <td colspan="9" class="text-center py-5 text-secondary">
                                Chưa có bài nộp nào trong hệ thống. Hãy vào trang bài tập để nộp bài đầu tiên!
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($submissions as $sub): 
                            $v = $sub['verdict'] ?: 'PENDING';
                            $vClass = 'badge bg-secondary';
                            $vLabel = $v;

                            if ($v === 'ACCEPTED') { $vClass = 'badge bg-success'; $vLabel = 'AC'; }
                            elseif ($v === 'WRONG_ANSWER') { $vClass = 'badge bg-danger'; $vLabel = 'WA'; }
                            elseif ($v === 'TIME_LIMIT_EXCEEDED') { $vClass = 'badge bg-warning text-dark'; $vLabel = 'TLE'; }
                            elseif ($v === 'RUNTIME_ERROR') { $vClass = 'badge bg-purple text-white'; $vLabel = 'RE'; }
                            elseif ($v === 'COMPILE_ERROR') { $vClass = 'badge bg-orange text-white'; $vLabel = 'CE'; }
                            elseif ($v === 'SYSTEM_ERROR') { $vClass = 'badge bg-secondary text-white'; $vLabel = 'SE'; }
                        ?>
                            <tr>
                                <td class="ps-4 fw-bold text-secondary">#<?php echo $sub['id']; ?></td>
                                <td>
                                    <div class="text-white fw-semibold"><?php echo htmlspecialchars($sub['full_name']); ?></div>
                                    <code class="text-secondary small"><?php echo htmlspecialchars($sub['username']); ?></code>
                                </td>
                                <td>
                                    <a href="solve.php?id=<?php echo $sub['problem_id']; ?>" class="text-white text-decoration-none fw-semibold">
                                        <?php echo htmlspecialchars($sub['problem_title']); ?>
                                    </a>
                                    <div class="text-secondary small font-monospace"><?php echo htmlspecialchars($sub['problem_code']); ?></div>
                                </td>
                                <td>
                                    <span class="badge bg-dark border border-secondary text-info">
                                        <?php echo htmlspecialchars($sub['language_id']); ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="<?php echo $vClass; ?> px-2 py-1 fs-7 fw-bold">
                                        <?php echo $vLabel; ?>
                                    </span>
                                    <?php if (!empty($sub['run_number']) && $sub['run_number'] > 1): ?>
                                        <span class="badge bg-dark border border-secondary text-secondary ms-1 small" title="Đã được chấm lại">
                                            Lần <?php echo $sub['run_number']; ?>
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="text-light fw-bold"><?php echo $sub['total_score'] ?? 0; ?>đ</span>
                                    <div class="text-secondary small"><?php echo ($sub['passed_test_count'] ?? 0) . '/' . ($sub['total_test_count'] ?? 0); ?> tests</div>
                                </td>
                                <td class="text-secondary small font-monospace">
                                    <?php echo ($sub['max_time_ms'] ?? 0); ?>ms
                                </td>
                                <td class="text-secondary small">
                                    <?php echo date('H:i d/m/Y', strtotime($sub['created_at'])); ?>
                                </td>
                                <td class="pe-4 text-end">
                                    <button class="btn btn-outline-secondary btn-sm px-2 py-1 btn-view-detail" data-sub-id="<?php echo $sub['id']; ?>">
                                        <i class="fas fa-eye me-1"></i>Xem mã
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Modal Xem Chi Tiết Bài Nộp -->
<div class="modal fade" id="modalSubDetail" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content bg-dark text-white border border-secondary">
            <div class="modal-header border-secondary">
                <h5 class="modal-title fw-bold" id="modalTitle">Chi Tiết Bài Nộp</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4" id="modalBody">
                <div class="text-center py-4">
                    <div class="spinner-border text-primary"></div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
document.querySelectorAll('.btn-view-detail').forEach(btn => {
    btn.addEventListener('click', () => {
        const subId = btn.dataset.subId;
        const modal = new bootstrap.Modal(document.getElementById('modalSubDetail'));
        modal.show();

        const modalBody = document.getElementById('modalBody');
        modalBody.innerHTML = '<div class="text-center py-4"><div class="spinner-border text-primary"></div></div>';

        fetch(`api/get_submission.php?id=${subId}`)
        .then(r => r.json())
        .then(res => {
            if (!res.success) {
                modalBody.innerHTML = `<div class="text-danger">${res.message}</div>`;
                return;
            }

            const sub = res.submission;
            const cases = res.cases || [];

            let html = `
                <div class="d-flex justify-content-between align-items-center mb-3 pb-2 border-bottom border-secondary">
                    <div>
                        <h6 class="mb-0 fw-bold">${sub.problem_title}</h6>
                        <small class="text-secondary">Ngôn ngữ: ${sub.language_id} &bull; Nộp lúc: ${sub.created_at}</small>
                    </div>
                    <div>
                        <span class="badge bg-primary px-3 py-2 fs-6">Điểm: ${sub.total_score}đ</span>
                    </div>
                </div>

                <div class="mb-3">
                    <label class="text-secondary small fw-bold mb-1">Mã nguồn đã nộp:</label>
                    <pre class="bg-black p-3 rounded border border-secondary text-light font-monospace small" style="max-height: 250px; overflow-y: auto;">${escapeHtml(sub.source_code)}</pre>
                </div>
            `;

            if (sub.compile_log) {
                html += `
                    <div class="mb-3">
                        <label class="text-danger small fw-bold mb-1">Nhật ký lỗi biên dịch:</label>
                        <pre class="bg-danger bg-opacity-10 border border-danger p-2 rounded text-danger small">${escapeHtml(sub.compile_log)}</pre>
                    </div>
                `;
            }

            if (cases.length > 0) {
                html += `<label class="text-secondary small fw-bold mb-2">Chi tiết từng test case (Đã bảo vệ dữ liệu kín):</label>`;
                html += `<div class="table-responsive"><table class="table table-sm table-dark table-bordered mb-0 small">
                            <thead class="text-secondary">
                                <tr>
                                    <th>Test #</th>
                                    <th>Loại Test</th>
                                    <th>Kết Quả</th>
                                    <th>Thời Gian</th>
                                </tr>
                            </thead>
                            <tbody>`;
                cases.forEach(c => {
                    const isPassed = c.verdict === 'PASSED';
                    const badgeClass = isPassed ? 'text-success' : 'text-danger';
                    const typeLabel = c.is_sample ? '<span class="badge bg-secondary">Mẫu công khai</span>' : '<span class="badge bg-dark border border-secondary">Kín bảo mật</span>';

                    html += `<tr>
                                <td>#${c.order_index}</td>
                                <td>${typeLabel}</td>
                                <td class="fw-bold ${badgeClass}">${c.verdict}</td>
                                <td>${c.execution_time_ms}ms</td>
                             </tr>`;
                });
                html += `</tbody></table></div>`;
            }

            modalBody.innerHTML = html;
        })
        .catch(err => {
            modalBody.innerHTML = `<div class="text-danger">Lỗi tải chi tiết: ${err.message}</div>`;
        });
    });
});

function escapeHtml(text) {
    if (!text) return '';
    return text
        .replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;")
        .replace(/"/g, "&quot;")
        .replace(/'/g, "&#039;");
}
</script>

<style>
.bg-purple { background-color: #8b5cf6 !important; }
.bg-orange { background-color: #ea580c !important; }
</style>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
