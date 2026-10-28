<?php
$pageTitle = 'Luyện Tập & Soạn Thảo Mã Nguồn';

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/db.php';

$problemId = (int)($_GET['id'] ?? 1);

$problem = null;
$samples = [];
$languages = [];
$draftCode = '';

try {
    $pdo = Database::getConnection();

    // 1. Lấy thông tin bài tập và phiên bản hiện tại
    $stmtProb = $pdo->prepare("
        SELECT p.*, pv.version_number, pv.description, pv.time_limit_ms, pv.memory_limit_kb, pv.change_log
        FROM problems p
        JOIN problem_versions pv ON p.current_version_id = pv.id
        WHERE p.id = ?
    ");
    $stmtProb->execute([$problemId]);
    $problem = $stmtProb->fetch();

    if ($problem) {
        // 2. Lấy các test case mẫu công khai (is_sample = 1)
        $stmtSamples = $pdo->prepare("
            SELECT order_index, input_data, expected_output, score_weight
            FROM test_cases
            WHERE problem_version_id = ? AND is_sample = 1
            ORDER BY order_index ASC
        ");
        $stmtSamples->execute([(int)$problem['current_version_id']]);
        $samples = $stmtSamples->fetchAll();

        // 3. Lấy danh sách ngôn ngữ kích hoạt
        $stmtLangs = $pdo->query("SELECT * FROM languages WHERE is_active = 1 ORDER BY id DESC");
        $languages = $stmtLangs->fetchAll();

        // 4. Lấy bản nháp đã lưu gần nhất của người dùng
        $activeUserId = $_SESSION['user']['id'] ?? 3;
        $stmtDraft = $pdo->prepare("
            SELECT draft_code, language_id 
            FROM code_drafts 
            WHERE user_id = ? AND problem_id = ?
        ");
        $stmtDraft->execute([$activeUserId, $problemId]);
        $draft = $stmtDraft->fetch();
        if ($draft) {
            $draftCode = $draft['draft_code'];
        }
    }
} catch (Exception $e) {
    $errorMsg = $e->getMessage();
}

if (!$problem) {
    die("Không tìm thấy bài tập hoặc cơ sở dữ liệu chưa được nạp. Hãy truy cập <a href='database/install.php'>database/install.php</a>.");
}

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/navbar.php';
?>

<div class="container-fluid px-lg-4 py-3">
    <!-- Header thông tin bài tập -->
    <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-3">
        <div class="d-flex align-items-center gap-2">
            <a href="problems.php" class="btn btn-outline-secondary btn-sm rounded-circle me-1" title="Quay lại">
                <i class="fas fa-arrow-left"></i>
            </a>
            <h4 class="fw-bold text-white mb-0">
                <?php echo htmlspecialchars($problem['title']); ?>
            </h4>
            <code class="text-secondary ms-1 fs-6"><?php echo htmlspecialchars($problem['code']); ?></code>
            <span class="badge <?php echo ($problem['difficulty'] === 'EASY') ? 'badge-easy' : (($problem['difficulty'] === 'MEDIUM') ? 'badge-medium' : 'badge-hard'); ?> ms-1">
                <?php echo $problem['difficulty']; ?>
            </span>
            <span class="badge bg-dark border border-secondary text-secondary">
                Phiên bản v<?php echo $problem['version_number']; ?>
            </span>
        </div>

        <div class="d-flex align-items-center gap-3 text-secondary small">
            <div><i class="fas fa-stopwatch text-warning me-1"></i><?php echo $problem['time_limit_ms']; ?>ms</div>
            <div><i class="fas fa-memory text-info me-1"></i><?php echo round($problem['memory_limit_kb'] / 1024); ?>MB</div>
            <div id="saveDraftStatus" class="text-secondary small">
                <i class="fas fa-cloud text-secondary me-1"></i><span id="saveDraftText">Tự động lưu nháp</span>
            </div>
        </div>
    </div>

    <!-- Bố cục 2 cột (Trái: Đề bài - Phải: Monaco Editor & Console) -->
    <div class="row g-3">
        <!-- CỘT TRÁI: Mô tả đề bài & Test case ví dụ -->
        <div class="col-lg-5">
            <div class="custom-card p-4 h-100 d-flex flex-column overflow-y-auto" style="max-height: 82vh;">
                <h5 class="fw-bold text-light border-bottom border-secondary pb-2 mb-3">
                    <i class="fas fa-file-lines text-primary me-2"></i>Mô Tả Bài Toán
                </h5>

                <div class="problem-description text-light mb-4" style="line-height: 1.7; white-space: pre-line;">
                    <?php echo htmlspecialchars($problem['description']); ?>
                </div>

                <h6 class="fw-bold text-light mb-3">
                    <i class="fas fa-vial text-success me-2"></i>Ví Dụ Công Khai (Sample Test Cases)
                </h6>

                <?php if (empty($samples)): ?>
                    <p class="text-secondary small">Chưa có test ví dụ công khai.</p>
                <?php else: ?>
                    <?php foreach ($samples as $idx => $s): ?>
                        <div class="bg-black bg-opacity-50 border border-secondary rounded-3 p-3 mb-3">
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <span class="badge bg-secondary text-light">Ví dụ #<?php echo $idx + 1; ?></span>
                                <button type="button" class="btn btn-outline-primary btn-sm py-0 px-2 fs-7 btn-copy-sample"
                                        data-input="<?php echo htmlspecialchars($s['input_data']); ?>">
                                    <i class="fas fa-copy me-1"></i>Thử test này
                                </button>
                            </div>
                            <div class="mb-2">
                                <div class="text-secondary small fw-semibold">Đầu vào (Input):</div>
                                <pre class="bg-dark p-2 rounded text-info mb-0 small"><?php echo htmlspecialchars($s['input_data']); ?></pre>
                            </div>
                            <div>
                                <div class="text-secondary small fw-semibold">Đầu ra mong đợi (Expected Output):</div>
                                <pre class="bg-dark p-2 rounded text-success mb-0 small"><?php echo htmlspecialchars($s['expected_output']); ?></pre>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- CỘT PHẢI: Trình soạn thảo Monaco & Bảng điều khiển Thực thi -->
        <div class="col-lg-7">
            <div class="custom-card p-3 d-flex flex-column" style="height: 82vh;">
                <!-- Thanh công cụ của Editor -->
                <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 pb-2 border-bottom border-secondary mb-2">
                    <div class="d-flex align-items-center gap-2">
                        <label class="text-secondary small fw-bold">Ngôn ngữ:</label>
                        <select id="selectLanguage" class="form-select form-select-sm bg-dark border-secondary text-white" style="width: 160px;">
                            <option value="python3" selected>Python 3</option>
                            <option value="cpp">C++ 17 (GCC)</option>
                        </select>
                        <span id="editorModeBadge" class="badge bg-dark border border-secondary text-secondary small">Đang nạp editor...</span>
                    </div>

                    <div class="d-flex align-items-center gap-2">
                        <button type="button" class="btn btn-outline-secondary btn-sm" id="btnResetCode" title="Đặt lại code mặc định">
                            <i class="fas fa-rotate-left"></i>
                        </button>
                        <button type="button" class="btn btn-secondary btn-sm px-3" id="btnRunDraft">
                            <i class="fas fa-play me-1 text-warning"></i> Chạy Thử
                        </button>
                        <button type="button" class="btn btn-primary btn-sm px-4 fw-bold" id="btnSubmit">
                            <i class="fas fa-paper-plane me-1"></i> Nộp Bài
                        </button>
                    </div>
                </div>

                <!-- Khung Soạn Thảo (Hỗ trợ Monaco Editor + Native Editor fallback an toàn 100%) -->
                <div id="editor-wrapper" style="flex: 1; min-height: 320px; position: relative;" class="border border-secondary rounded overflow-hidden">
                    <div id="monaco-container" style="width: 100%; height: 100%; display: none;"></div>
                    <textarea id="codeFallback" class="form-control bg-dark text-white font-monospace border-0 p-3" 
                              style="width: 100%; height: 100%; resize: none; font-size: 14px; line-height: 1.6; outline: none;" 
                              spellcheck="false" placeholder="Nhập mã nguồn giải bài tại đây..."></textarea>
                </div>

                <!-- Khung Console / Tabs phía dưới Editor -->
                <div class="mt-3 border-top border-secondary pt-2" style="height: 250px; display: flex; flex-direction: column;">
                    <ul class="nav nav-pills nav-fill mb-2" id="consoleTabs" role="tablist">
                        <li class="nav-item">
                            <button class="nav-link active py-1 small" id="tab-input-btn" type="button">
                                <i class="fas fa-keyboard me-1"></i> Dữ Liệu Tự Nhập (Input)
                            </button>
                        </li>
                        <li class="nav-item">
                            <button class="nav-link py-1 small" id="tab-output-btn" type="button">
                                <i class="fas fa-terminal me-1"></i> Kết Quả Chạy Thử
                            </button>
                        </li>
                        <li class="nav-item">
                            <button class="nav-link py-1 small" id="tab-verdict-btn" type="button">
                                <i class="fas fa-award me-1"></i> Kết Quả Chấm (Verdict)
                            </button>
                        </li>
                    </ul>

                    <div class="tab-content flex-grow-1 overflow-auto bg-black bg-opacity-40 p-2 rounded border border-secondary" id="consoleContent">
                        <!-- Tab 1: Custom Input -->
                        <div class="tab-pane fade show active h-100" id="tab-input">
                            <textarea id="customInput" class="form-control bg-transparent border-0 text-white font-monospace h-100" 
                                      style="resize: none;" placeholder="Nhập dữ liệu stdin cho chương trình chạy thử tại đây..."></textarea>
                        </div>

                        <!-- Tab 2: Console Run Output -->
                        <div class="tab-pane fade h-100" id="tab-output">
                            <div id="runOutputLoading" class="text-center py-4 text-secondary d-none">
                                <div class="spinner-border spinner-border-sm text-primary me-2"></div>
                                Đang chuyển mã sang dịch vụ thực thi cách ly Piston...
                            </div>
                            <div id="runOutputResult" class="font-monospace small">
                                <span class="text-secondary">Chưa có kết quả chạy thử. Hãy nhấn nút "Chạy Thử" ở trên.</span>
                            </div>
                        </div>

                        <!-- Tab 3: Official Judging Verdict -->
                        <div class="tab-pane fade h-100" id="tab-verdict">
                            <div id="verdictLoading" class="text-center py-4 text-secondary d-none">
                                <div class="spinner-border spinner-border-sm text-success me-2"></div>
                                Đang đưa bài vào hàng đợi &amp; thực hiện điều phối chấm...
                            </div>
                            <div id="verdictResult">
                                <span class="text-secondary small">Chưa có bài nộp nào. Hãy nhấn "Nộp Bài" để hệ thống chấm toàn bộ test case.</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Nạp Monaco Editor qua CDN (Microsoft Monaco) -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/monaco-editor/0.45.0/min/vs/loader.min.js"></script>

<script>
// Mẫu mã nguồn mặc định
const starterCode = {
    python3: `# Viết mã giải bài bằng Python 3 tại đây
import sys

def main():
    lines = sys.stdin.read().split()
    if not lines:
        return
    # Viết logic thuật toán tại đây...
    if len(lines) >= 2:
        print(int(lines[0]) + int(lines[1]))
    else:
        print(lines[0])

if __name__ == '__main__':
    main()
`,
    cpp: `// Viết mã giải bài bằng C++ 17 tại đây
#include <iostream>
#include <vector>
#include <string>

using namespace std;

int main() {
    ios_base::sync_with_stdio(false);
    cin.tie(NULL);
    
    long long a, b;
    if (cin >> a >> b) {
        cout << a + b << "\\n";
    }
    
    return 0;
}
`
};

let editor = null;
const problemId = <?php echo (int)$problem['id']; ?>;
let savedDraftCode = <?php echo json_encode($draftCode); ?>;
const langSelect = document.getElementById('selectLanguage');
const fallbackTextarea = document.getElementById('codeFallback');
const modeBadge = document.getElementById('editorModeBadge');

// Thiết lập mã khởi tạo vào textarea fallback
const initialLang = langSelect.value;
const initialCode = savedDraftCode || starterCode[initialLang];
fallbackTextarea.value = initialCode;
modeBadge.textContent = 'Native Editor';

// Hỗ trợ phím Tab trong textarea fallback (Tab chèn 4 khoảng trắng)
fallbackTextarea.addEventListener('keydown', function(e) {
    if (e.key === 'Tab') {
        e.preventDefault();
        const start = this.selectionStart;
        const end = this.selectionEnd;
        this.value = this.value.substring(0, start) + "    " + this.value.substring(end);
        this.selectionStart = this.selectionEnd = start + 4;
    }
});

// Hàm lấy mã nguồn hiện tại từ bất kỳ editor nào đang kích hoạt
function getCode() {
    if (editor && typeof editor.getValue === 'function') {
        return editor.getValue();
    }
    return fallbackTextarea.value;
}

// Hàm đặt mã nguồn
function setCode(val) {
    fallbackTextarea.value = val;
    if (editor && typeof editor.setValue === 'function') {
        editor.setValue(val);
    }
}

// Thử tải và khởi tạo Monaco Editor nếu CDN sẵn sàng
try {
    if (typeof require !== 'undefined' && require.config) {
        require.config({ paths: { 'vs': 'https://cdnjs.cloudflare.com/ajax/libs/monaco-editor/0.45.0/min/vs' }});
        require(['vs/editor/editor.main'], function() {
            try {
                const monacoLang = initialLang === 'python3' ? 'python' : 'cpp';
                fallbackTextarea.style.display = 'none';
                document.getElementById('monaco-container').style.display = 'block';

                editor = monaco.editor.create(document.getElementById('monaco-container'), {
                    value: getCode(),
                    language: monacoLang,
                    theme: 'vs-dark',
                    automaticLayout: true,
                    fontSize: 14,
                    fontFamily: "'JetBrains Mono', Consolas, monospace",
                    minimap: { enabled: false },
                    scrollBeyondLastLine: false,
                    padding: { top: 12, bottom: 12 },
                    tabSize: 4,
                    insertSpaces: true
                });

                modeBadge.textContent = 'Monaco (VS Code)';
                modeBadge.className = 'badge bg-primary-subtle text-primary border border-primary-subtle small';

                // Đồng bộ từ Monaco về fallback
                editor.onDidChangeModelContent(() => {
                    fallbackTextarea.value = editor.getValue();
                    triggerAutoSave();
                });
            } catch (err) {
                console.warn("Monaco init failed, using Native Editor:", err);
                fallbackTextarea.style.display = 'block';
                modeBadge.textContent = 'Native Editor';
            }
        });
    }
} catch (e) {
    console.warn("Require failed, using Native Editor:", e);
}

// Đổi ngôn ngữ
langSelect.addEventListener('change', (e) => {
    const lang = e.target.value;
    const monacoLang = lang === 'python3' ? 'python' : 'cpp';
    if (editor && typeof editor.getModel === 'function') {
        monaco.editor.setModelLanguage(editor.getModel(), monacoLang);
    }
    if (!savedDraftCode) {
        setCode(starterCode[lang]);
    }
});

// Tự động lưu nháp
let autoSaveTimer = null;
function triggerAutoSave() {
    clearTimeout(autoSaveTimer);
    document.getElementById('saveDraftText').textContent = 'Đang có thay đổi...';
    autoSaveTimer = setTimeout(saveDraft, 2000);
}

fallbackTextarea.addEventListener('input', triggerAutoSave);

function saveDraft() {
    const code = getCode();
    const lang = langSelect.value;

    fetch('api/save_draft.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            problem_id: problemId,
            language_id: lang,
            draft_code: code
        })
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            document.getElementById('saveDraftText').textContent = `Đã lưu nháp (${data.saved_time})`;
        }
    })
    .catch(() => {});
}

// Hàm chuyển đổi Tab console an toàn 100%
function switchConsoleTab(tabBtnId, tabPaneId) {
    document.querySelectorAll('#consoleTabs .nav-link').forEach(el => el.classList.remove('active'));
    document.querySelectorAll('#consoleContent .tab-pane').forEach(el => el.classList.remove('show', 'active'));

    const btn = document.getElementById(tabBtnId);
    const pane = document.getElementById(tabPaneId);
    if (btn) btn.classList.add('active');
    if (pane) pane.classList.add('show', 'active');

    try {
        if (window.bootstrap && bootstrap.Tab) {
            const tab = bootstrap.Tab.getOrCreateInstance(btn);
            tab.show();
        }
    } catch(e) {}
}

// Gán sự kiện click cho các nút chuyển tab
document.getElementById('tab-input-btn').addEventListener('click', () => switchConsoleTab('tab-input-btn', 'tab-input'));
document.getElementById('tab-output-btn').addEventListener('click', () => switchConsoleTab('tab-output-btn', 'tab-output'));
document.getElementById('tab-verdict-btn').addEventListener('click', () => switchConsoleTab('tab-verdict-btn', 'tab-verdict'));

// Nút Copy test ví dụ vào ô Chạy Thử
document.querySelectorAll('.btn-copy-sample').forEach(btn => {
    btn.addEventListener('click', () => {
        const inputData = btn.dataset.input;
        document.getElementById('customInput').value = inputData;
        switchConsoleTab('tab-input-btn', 'tab-input');
    });
});

// Nút Đặt lại code mặc định
document.getElementById('btnResetCode').addEventListener('click', () => {
    if (confirm('Bạn có chắc muốn đặt lại mã nguồn mặc định?')) {
        const lang = langSelect.value;
        setCode(starterCode[lang]);
    }
});

// ==============================================================
// XỬ LÝ CHẠY THỬ (RUN DRAFT CODE)
// ==============================================================
document.getElementById('btnRunDraft').addEventListener('click', () => {
    const code = getCode();
    const lang = langSelect.value;
    const customIn = document.getElementById('customInput').value;

    if (!code.trim()) {
        alert('Vui lòng nhập mã nguồn trước khi chạy thử!');
        return;
    }

    const btn = document.getElementById('btnRunDraft');
    const originalHtml = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Đang chạy...';

    // Chuyển ngay sang tab Kết quả chạy thử
    switchConsoleTab('tab-output-btn', 'tab-output');

    const loadingEl = document.getElementById('runOutputLoading');
    const resultEl  = document.getElementById('runOutputResult');

    loadingEl.classList.remove('d-none');
    resultEl.innerHTML = '';

    fetch('api/run_draft.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            source_code: code,
            language_id: lang,
            custom_input: customIn
        })
    })
    .then(res => res.json())
    .then(data => {
        loadingEl.classList.add('d-none');
        if (!data.success) {
            resultEl.innerHTML = `<div class="text-danger p-2"><i class="fas fa-times-circle me-1"></i>${data.message}</div>`;
            return;
        }

        let html = '';
        const statusBadge = (data.status === 'SUCCESS') 
            ? '<span class="badge bg-success">Chạy thành công</span>' 
            : `<span class="badge bg-danger">${data.status}</span>`;

        html += `<div class="d-flex justify-content-between align-items-center mb-2 pb-1 border-bottom border-secondary">
                    <div>Trạng thái: ${statusBadge}</div>
                    <div class="text-secondary small">Thời gian: ${data.time_ms}ms</div>
                 </div>`;

        if (data.stdout) {
            html += `<div class="mb-2"><span class="text-secondary small fw-bold">Kết quả stdout:</span>
                     <pre class="bg-dark p-2 rounded text-light mb-0">${escapeHtml(data.stdout)}</pre></div>`;
        }

        if (data.stderr) {
            html += `<div class="mb-2"><span class="text-danger small fw-bold">Thông báo lỗi / stderr:</span>
                     <pre class="bg-danger bg-opacity-10 border border-danger p-2 rounded text-danger mb-0">${escapeHtml(data.stderr)}</pre></div>`;
        }

        if (!data.stdout && !data.stderr) {
            html += `<div class="text-secondary italic">Chương trình không in ra gì (stdout rỗng).</div>`;
        }

        resultEl.innerHTML = html;
    })
    .catch(err => {
        loadingEl.classList.add('d-none');
        resultEl.innerHTML = `<div class="text-danger p-2"><i class="fas fa-triangle-exclamation me-1"></i>Lỗi kết nối máy chủ: ${err.message}</div>`;
    })
    .finally(() => {
        btn.disabled = false;
        btn.innerHTML = originalHtml;
    });
});

// ==============================================================
// XỬ LÝ NỘP BÀI CHÍNH THỨC (SUBMIT CODE)
// ==============================================================
document.getElementById('btnSubmit').addEventListener('click', () => {
    const code = getCode();
    const lang = langSelect.value;

    if (!code.trim()) {
        alert('Vui lòng viết mã nguồn trước khi nộp bài!');
        return;
    }

    const btn = document.getElementById('btnSubmit');
    const originalHtml = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Đang nộp...';

    // Sinh khóa chống nộp trùng Idempotency Key (UUID ngẫu nhiên)
    const idempotencyKey = 'submit_' + Date.now() + '_' + Math.random().toString(36).substr(2, 9);

    switchConsoleTab('tab-verdict-btn', 'tab-verdict');

    const loadingEl = document.getElementById('verdictLoading');
    const resultEl  = document.getElementById('verdictResult');

    loadingEl.classList.remove('d-none');
    resultEl.innerHTML = '';

    fetch('api/submit.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            problem_id: problemId,
            language_id: lang,
            source_code: code,
            idempotency_key: idempotencyKey
        })
    })
    .then(res => res.json())
    .then(data => {
        loadingEl.classList.add('d-none');
        if (!data.success) {
            resultEl.innerHTML = `<div class="text-danger p-3"><i class="fas fa-exclamation-triangle me-1"></i>${data.message}</div>`;
            return;
        }

        loadSubmissionDetails(data.submission_id);
    })
    .catch(err => {
        loadingEl.classList.add('d-none');
        resultEl.innerHTML = `<div class="text-danger p-3">Lỗi gửi bài: ${err.message}</div>`;
    })
    .finally(() => {
        btn.disabled = false;
        btn.innerHTML = originalHtml;
    });
});

// Hàm hiển thị kết quả bài nộp sau khi chấm
function loadSubmissionDetails(subId) {
    const resultEl = document.getElementById('verdictResult');

    fetch(`api/get_submission.php?id=${subId}`)
    .then(r => r.json())
    .then(res => {
        if (!res.success) {
            resultEl.innerHTML = `<div class="text-danger">${res.message}</div>`;
            return;
        }

        const sub = res.submission;
        const cases = res.cases || [];

        const verdictMap = {
            'ACCEPTED': { label: 'AC (Accepted)', class: 'bg-success', desc: 'Đúng hoàn toàn các test case!' },
            'WRONG_ANSWER': { label: 'WA (Wrong Answer)', class: 'bg-danger', desc: 'Kết quả đầu ra không khớp với đáp án chuẩn.' },
            'TIME_LIMIT_EXCEEDED': { label: 'TLE (Time Limit Exceeded)', class: 'bg-warning text-dark', desc: 'Quá giới hạn thời gian (thuật toán lặp vô hạn hoặc quá chậm).' },
            'RUNTIME_ERROR': { label: 'RE (Runtime Error)', class: 'bg-purple text-white', desc: 'Chương trình bị văng lỗi khi đang chạy.' },
            'COMPILE_ERROR': { label: 'CE (Compilation Error)', class: 'bg-orange text-white', desc: 'Lỗi cú pháp / biên dịch.' },
            'SYSTEM_ERROR': { label: 'SE (System Error)', class: 'bg-secondary text-white', desc: 'Dịch vụ thực thi gặp sự cố. Bài làm không bị đánh trượt.' }
        };

        const vInfo = verdictMap[sub.verdict] || { label: sub.verdict, class: 'bg-secondary', desc: '' };

        let html = `
            <div class="p-3 border-bottom border-secondary mb-3">
                <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
                    <div>
                        <span class="badge ${vInfo.class} fs-6 px-3 py-2 fw-bold">${vInfo.label}</span>
                        <span class="ms-2 text-white fw-bold">Điểm số: ${sub.total_score}đ</span>
                    </div>
                    <div class="text-secondary small">
                        Vượt qua: <strong class="text-light">${sub.passed_test_count}/${sub.total_test_count}</strong> tests
                        &bull; Thời gian chạy max: <strong>${sub.max_time_ms}ms</strong>
                    </div>
                </div>
                <div class="text-secondary small mt-2">${vInfo.desc}</div>
            </div>
        `;

        if (sub.compile_log) {
            html += `
                <div class="mb-3">
                    <span class="text-warning small fw-bold">Nhật ký lỗi biên dịch (Compile Error):</span>
                    <pre class="bg-dark p-2 rounded text-danger small mt-1">${escapeHtml(sub.compile_log)}</pre>
                </div>
            `;
        }

        if (cases.length > 0) {
            html += `<h6 class="text-secondary small fw-bold mb-2">Chi tiết từng test case:</h6>`;
            html += `<div class="d-flex flex-wrap gap-2 mb-3">`;
            cases.forEach(c => {
                const isPassed = c.verdict === 'PASSED';
                const badgeColor = isPassed ? 'btn-outline-success text-success' : 'btn-outline-danger text-danger';
                const icon = isPassed ? 'fa-check' : 'fa-times';
                const label = c.is_sample ? `Test #${c.order_index} (Mẫu)` : `Test #${c.order_index} (Kín)`;

                html += `
                    <div class="btn btn-sm ${badgeColor} py-1 px-2 small">
                        <i class="fas ${icon} me-1"></i>${label} [${c.execution_time_ms}ms]
                    </div>
                `;
            });
            html += `</div>`;
        }

        html += `
            <div class="text-center mt-3">
                <a href="submissions.php" class="btn btn-outline-secondary btn-sm">
                    <i class="fas fa-list me-1"></i> Xem lịch sử tất cả bài nộp
                </a>
            </div>
        `;

        resultEl.innerHTML = html;
    });
}

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
