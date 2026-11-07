<?php
/**
 * ByteFlow Online Judge - Không gian Làm bài & Chấm Trực tuyến (IDE & Solve Workspace)
 * Tình huống sử dụng 1: Chọn bài -> Viết mã -> Chạy thử -> Nộp bài -> Theo dõi chấm -> Xem kết quả
 */

declare(strict_types=1);

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/core/Auth.php';
require_once __DIR__ . '/core/Security.php';

Auth::startSession();
$currentUser = Auth::user();
$pdo = getDbConnection();

$problemId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$slugParam = trim((string)($_GET['slug'] ?? ($_GET['code'] ?? '')));

// 1. Lấy thông tin bài tập và phiên bản hiện hành
if ($problemId > 0) {
    $stmt = $pdo->prepare("
        SELECT p.id, p.slug, p.title, p.topic, p.difficulty, p.source_attribution,
               pv.id as version_id, pv.version_number, pv.description, pv.input_description,
               pv.output_description, pv.constraints, pv.time_limit_ms, pv.memory_limit_kb
        FROM problems p
        JOIN problem_versions pv ON p.current_version_id = pv.id
        WHERE p.id = :id AND p.is_public = 1
    ");
    $stmt->execute(['id' => $problemId]);
} elseif ($slugParam !== '') {
    $stmt = $pdo->prepare("
        SELECT p.id, p.slug, p.title, p.topic, p.difficulty, p.source_attribution,
               pv.id as version_id, pv.version_number, pv.description, pv.input_description,
               pv.output_description, pv.constraints, pv.time_limit_ms, pv.memory_limit_kb
        FROM problems p
        JOIN problem_versions pv ON p.current_version_id = pv.id
        WHERE (p.slug = :slug OR LOWER(p.slug) = LOWER(:slug2)) AND p.is_public = 1
    ");
    $stmt->execute(['slug' => $slugParam, 'slug2' => strtolower(str_replace('_', '-', $slugParam))]);
} else {
    $stmt = $pdo->prepare("
        SELECT p.id, p.slug, p.title, p.topic, p.difficulty, p.source_attribution,
               pv.id as version_id, pv.version_number, pv.description, pv.input_description,
               pv.output_description, pv.constraints, pv.time_limit_ms, pv.memory_limit_kb
        FROM problems p
        JOIN problem_versions pv ON p.current_version_id = pv.id
        WHERE p.is_public = 1
        ORDER BY p.id ASC
        LIMIT 1
    ");
    $stmt->execute();
}
$problem = $stmt->fetch();

if (!$problem) {
    header('Location: /BaiTapLon/problems.php');
    exit;
}

// 2. Lấy các ca kiểm thử ví dụ công khai (is_sample = 1)
$sampleStmt = $pdo->prepare("
    SELECT case_order, input_data, expected_output
    FROM test_cases
    WHERE problem_version_id = :vid AND is_sample = 1
    ORDER BY case_order ASC
");
$sampleStmt->execute(['vid' => $problem['version_id']]);
$samples = $sampleStmt->fetchAll();

// 3. Khôi phục bản nháp nếu đã lưu trước đó
$defaultLang = 'python';
$initialCode = "";
$starterTemplates = [
    'python' => "# Viết mã nguồn Python 3 tại đây\nimport sys\n\ndef main():\n    # Đọc dữ liệu từ stdin\n    # In kết quả ra stdout\n    pass\n\nif __name__ == '__main__':\n    main()\n",
    'cpp'    => "// Viết mã nguồn C++ tại đây\n#include <iostream>\n\nusing namespace std;\n\nint main() {\n    ios_base::sync_with_stdio(false);\n    cin.tie(NULL);\n    \n    // Đọc stdin và in stdout\n    \n    return 0;\n}\n"
];

if ($currentUser) {
    $draftStmt = $pdo->prepare("
        SELECT language, source_code 
        FROM code_drafts 
        WHERE user_id = :uid AND problem_id = :pid 
        ORDER BY updated_at DESC 
        LIMIT 1
    ");
    $draftStmt->execute(['uid' => $currentUser['id'], 'pid' => $problemId]);
    $draft = $draftStmt->fetch();
    if ($draft) {
        $defaultLang = $draft['language'];
        $initialCode = $draft['source_code'];
    } else {
        $initialCode = $starterTemplates[$defaultLang];
    }
} else {
    $initialCode = $starterTemplates[$defaultLang];
}

// 4. Lịch sử bài nộp của user đối với bài tập này
$userSubmissions = [];
if ($currentUser) {
    $subHistStmt = $pdo->prepare("
        SELECT id, language, final_verdict, pass_count, total_cases, execution_time_ms, submitted_at
        FROM submissions
        WHERE user_id = :uid AND problem_id = :pid
        ORDER BY id DESC
        LIMIT 10
    ");
    $subHistStmt->execute(['uid' => $currentUser['id'], 'pid' => $problemId]);
    $userSubmissions = $subHistStmt->fetchAll();
}

$pageTitle = 'Giải bài: ' . $problem['title'];
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/sidebar.php';
?>

<div class="pl-64 flex-1 flex flex-col h-screen overflow-hidden">
  
  <!-- Không gian làm bài chia đôi 2 cột -->
  <div class="flex-1 flex overflow-hidden">
    
    <!-- ==================== CỘT TRÁI (45%): ĐỀ BÀI & MÔ TẢ ==================== -->
    <div class="w-[45%] h-full flex flex-col border-r border-white/10 glass-panel overflow-hidden">
      
      <!-- Header đề bài & Tabs -->
      <div class="p-6 border-b border-white/10 space-y-3 shrink-0 bg-white/[0.01]">
        <div class="flex items-center space-x-2 text-xs">
          <?php
            $diffBadge = match($problem['difficulty']) {
              'Easy'   => 'bg-emerald-500/15 text-emerald-400 border-emerald-500/30',
              'Medium' => 'bg-amber-500/15 text-amber-400 border-amber-500/30',
              'Hard'   => 'bg-rose-500/15 text-rose-400 border-rose-500/30',
              default  => 'bg-slate-500/15 text-slate-400'
            };
          ?>
          <span class="px-2.5 py-0.5 rounded text-[11px] font-bold border <?= $diffBadge ?>">
            <?= $problem['difficulty'] ?>
          </span>
          <span class="px-2.5 py-0.5 rounded text-[11px] font-mono bg-cyan-500/10 text-cyan-300 border border-cyan-500/30 font-semibold">
            Dataset v<?= $problem['version_number'] ?>.0
          </span>
          <span class="text-slate-400 font-medium">
            <?= Security::escape($problem['topic']) ?>
          </span>
        </div>

        <h1 class="text-xl font-black text-white">
          #<?= $problem['id'] ?>. <?= Security::escape($problem['title']) ?>
        </h1>

        <!-- Giới hạn chạy & Bộ nhớ -->
        <div class="flex items-center space-x-4 text-xs font-mono text-slate-400 pt-1">
          <div class="flex items-center space-x-1.5" title="Giới hạn thời gian">
            <i class="fa-regular fa-clock text-cyan-400"></i>
            <span><?= $problem['time_limit_ms'] ?> ms</span>
          </div>
          <div class="flex items-center space-x-1.5" title="Giới hạn bộ nhớ">
            <i class="fa-solid fa-memory text-cyan-400"></i>
            <span><?= round($problem['memory_limit_kb'] / 1024) ?> MB</span>
          </div>
          <div class="truncate text-[11px] text-slate-500" title="<?= Security::escape($problem['source_attribution']) ?>">
            <i class="fa-regular fa-copyright mr-1"></i><?= Security::escape($problem['source_attribution']) ?>
          </div>
        </div>

        <!-- Tab chuyển đổi Đề bài vs Lịch sử nộp -->
        <div class="flex space-x-2 pt-2 border-t border-white/5">
          <button type="button" onclick="switchLeftTab('statement')" id="tab-btn-statement" 
            class="px-3 py-1.5 rounded-lg text-xs font-semibold bg-cyan-500/10 text-cyan-300 border border-cyan-500/30">
            <i class="fa-solid fa-file-lines mr-1.5"></i>Mô tả Đề bài
          </button>
          <button type="button" onclick="switchLeftTab('history')" id="tab-btn-history" 
            class="px-3 py-1.5 rounded-lg text-xs font-semibold text-slate-400 hover:text-white hover:bg-white/5 transition-colors">
            <i class="fa-solid fa-clock-rotate-left mr-1.5"></i>Lịch sử Nộp bài (<?= count($userSubmissions) ?>)
          </button>
        </div>
      </div>

      <!-- Nội dung Cột trái (Scrollable) -->
      <div class="flex-1 overflow-y-auto p-6 space-y-6 text-sm text-slate-300 leading-relaxed">
        
        <!-- PANEL 1: MÔ TẢ ĐỀ BÀI -->
        <div id="panel-statement" class="space-y-6">
          <!-- Mô tả bài toán -->
          <div>
            <h3 class="text-xs font-bold uppercase tracking-wider text-slate-400 mb-2">Đề bài</h3>
            <p class="whitespace-pre-line text-slate-200"><?= Security::escape($problem['description']) ?></p>
          </div>

          <!-- Quy cách Đầu vào -->
          <div>
            <h3 class="text-xs font-bold uppercase tracking-wider text-slate-400 mb-2">Đầu vào (Input)</h3>
            <div class="p-3 rounded-xl bg-white/[0.02] border border-white/5 text-slate-300 whitespace-pre-line text-xs font-mono">
              <?= Security::escape($problem['input_description']) ?>
            </div>
          </div>

          <!-- Quy cách Đầu ra -->
          <div>
            <h3 class="text-xs font-bold uppercase tracking-wider text-slate-400 mb-2">Đầu ra (Output)</h3>
            <div class="p-3 rounded-xl bg-white/[0.02] border border-white/5 text-slate-300 whitespace-pre-line text-xs font-mono">
              <?= Security::escape($problem['output_description']) ?>
            </div>
          </div>

          <!-- Ràng buộc -->
          <div>
            <h3 class="text-xs font-bold uppercase tracking-wider text-slate-400 mb-2">Giới hạn & Ràng buộc</h3>
            <div class="p-3 rounded-xl bg-white/[0.02] border border-white/5 text-amber-300 text-xs font-mono">
              <?= Security::escape($problem['constraints']) ?>
            </div>
          </div>

          <!-- Ca kiểm thử ví dụ công khai (Sample testcases) -->
          <div class="space-y-4">
            <h3 class="text-xs font-bold uppercase tracking-wider text-slate-400">Ví dụ minh họa công khai</h3>
            <?php foreach ($samples as $s): ?>
              <div class="rounded-xl border border-white/10 bg-black/40 overflow-hidden">
                <div class="px-3.5 py-2 bg-white/5 border-b border-white/5 flex items-center justify-between text-xs font-mono">
                  <span class="font-bold text-cyan-300">Ví dụ #<?= $s['case_order'] ?></span>
                  <button type="button" onclick="copyText(<?= htmlspecialchars(json_encode($s['input_data'])) ?>)" 
                    class="text-[11px] text-slate-400 hover:text-cyan-400 flex items-center space-x-1">
                    <i class="fa-regular fa-copy"></i>
                    <span>Copy Input</span>
                  </button>
                </div>
                <div class="grid grid-cols-2 divide-x divide-white/5 text-xs font-mono">
                  <div class="p-3">
                    <div class="text-[10px] uppercase text-slate-500 font-bold mb-1">stdin (đầu vào)</div>
                    <pre class="text-slate-200 overflow-x-auto whitespace-pre-wrap"><?= Security::escape($s['input_data']) ?></pre>
                  </div>
                  <div class="p-3">
                    <div class="text-[10px] uppercase text-slate-500 font-bold mb-1">stdout (kết quả)</div>
                    <pre class="text-emerald-400 overflow-x-auto whitespace-pre-wrap"><?= Security::escape($s['expected_output']) ?></pre>
                  </div>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        </div>

        <!-- PANEL 2: LỊCH SỬ NỘP BÀI CỦA USER -->
        <div id="panel-history" class="space-y-3 hidden">
          <?php if (empty($userSubmissions)): ?>
            <div class="text-center py-12 text-slate-500 text-xs">
              <i class="fa-solid fa-inbox text-2xl mb-2 block"></i>
              Bạn chưa có bài nộp nào cho bài tập này.
            </div>
          <?php else: ?>
            <?php foreach ($userSubmissions as $sub): ?>
              <?php
                $v = $sub['final_verdict'];
                $badgeClass = match($v) {
                  'AC' => 'badge-ac',
                  'WA' => 'badge-wa',
                  'TLE' => 'badge-tle',
                  'RE' => 'badge-re',
                  'CE' => 'badge-ce',
                  default => 'badge-pending'
                };
              ?>
              <div class="p-3.5 rounded-xl bg-white/5 border border-white/5 flex items-center justify-between hover:border-cyan-500/40 transition-colors">
                <div class="space-y-1">
                  <div class="flex items-center space-x-2">
                    <span class="px-2 py-0.5 rounded text-[10px] font-bold font-mono <?= $badgeClass ?>">
                      <?= $v ?>
                    </span>
                    <span class="text-xs font-mono text-slate-300">
                      <?= $sub['pass_count'] ?>/<?= $sub['total_cases'] ?> tests
                    </span>
                    <span class="text-xs text-slate-500">•</span>
                    <span class="text-xs font-mono uppercase text-cyan-400"><?= $sub['language'] ?></span>
                  </div>
                  <div class="text-[11px] text-slate-500">
                    Nộp lúc <?= $sub['submitted_at'] ?> (<?= $sub['execution_time_ms'] ?>ms)
                  </div>
                </div>

                <button type="button" onclick="loadSubmissionResult(<?= $sub['id'] ?>)" 
                  class="btn-glass text-[11px] py-1 px-2.5 flex items-center space-x-1">
                  <i class="fa-solid fa-eye text-[10px]"></i>
                  <span>Chi tiết</span>
                </button>
              </div>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>

      </div>
    </div>


    <!-- ==================== CỘT PHẢI (55%): TRÌNH SOẠN THẢO & CONSOLE ==================== -->
    <div class="w-[55%] h-full flex flex-col overflow-hidden bg-[#0A0D14]/80">
      
      <!-- Top Action Bar -->
      <div class="h-16 px-6 border-b border-white/10 flex items-center justify-between bg-black/40 shrink-0">
        <!-- Chọn ngôn ngữ & Trạng thái lưu nháp -->
        <div class="flex items-center space-x-3">
          <select id="code-language" onchange="changeLanguage(this.value)" 
            class="glass-input rounded-lg py-1.5 px-3 text-xs font-mono font-semibold text-cyan-300 bg-slate-900 border border-white/15 focus:border-cyan-400">
            <option value="python" <?= $defaultLang === 'python' ? 'selected' : '' ?>>Python (3.11)</option>
            <option value="cpp" <?= $defaultLang === 'cpp' ? 'selected' : '' ?>>C++ (17 MinGW)</option>
          </select>

          <span id="draft-status" class="text-[11px] text-slate-500 italic flex items-center space-x-1">
            <i class="fa-regular fa-floppy-disk text-[10px]"></i>
            <span id="draft-text">Sẵn sàng</span>
          </span>
        </div>

        <!-- Nút hành động: Lưu nháp, Chạy thử, Nộp bài -->
        <div class="flex items-center space-x-2">
          <button type="button" onclick="saveDraft()" title="Lưu nháp trên trình duyệt" 
            class="btn-glass text-xs py-2 px-3 flex items-center space-x-1.5">
            <i class="fa-solid fa-bookmark text-[11px] text-cyan-400"></i>
            <span>Lưu nháp</span>
          </button>

          <button type="button" onclick="runCustomTest()" id="btn-run-code" 
            class="btn-glass text-xs py-2 px-3.5 flex items-center space-x-1.5 hover:border-amber-400 hover:text-amber-300">
            <i class="fa-solid fa-play text-[10px] text-amber-400"></i>
            <span>Chạy thử</span>
          </button>

          <button type="button" onclick="submitSolution()" id="btn-submit" 
            class="btn-cyan text-xs py-2 px-4 flex items-center space-x-1.5">
            <i class="fa-solid fa-paper-plane text-[10px]"></i>
            <span>Nộp bài</span>
          </button>
        </div>
      </div>

      <!-- Vùng soạn thảo mã nguồn (Code Editor) -->
      <div class="flex-1 relative overflow-hidden flex flex-col">
        <textarea id="code-editor" spellcheck="false" 
          class="w-full h-full bg-[#070A0F] text-slate-100 font-mono text-sm p-4 resize-none outline-none leading-relaxed border-none focus:ring-0"
          placeholder="Viết mã giải bài tập tại đây..."><?= Security::escape($initialCode) ?></textarea>
      </div>

      <!-- ==================== CỘT DƯỚI: TABBED CONSOLE (40% chiều cao) ==================== -->
      <div class="h-64 border-t border-white/10 flex flex-col bg-black/60 shrink-0">
        
        <!-- Console Tabs Header -->
        <div class="h-10 px-4 border-b border-white/10 flex items-center justify-between bg-white/[0.02]">
          <div class="flex space-x-1">
            <button type="button" onclick="switchConsoleTab('custom')" id="console-tab-custom" 
              class="px-3 py-1 rounded text-xs font-semibold text-cyan-300 bg-cyan-500/10 border border-cyan-500/30 flex items-center space-x-1.5">
              <i class="fa-solid fa-keyboard text-[10px]"></i>
              <span>Dữ liệu chạy thử (Custom Testcase)</span>
            </button>
            <button type="button" onclick="switchConsoleTab('judging')" id="console-tab-judging" 
              class="px-3 py-1 rounded text-xs font-semibold text-slate-400 hover:text-white flex items-center space-x-1.5 transition-colors">
              <i class="fa-solid fa-list-check text-[10px]"></i>
              <span id="judging-tab-title">Tiến trình Chấm bài</span>
              <span id="judging-badge" class="hidden px-1.5 py-0.2 rounded-full text-[9px] bg-cyan-400 text-black font-bold">1</span>
            </button>
          </div>

          <div class="text-[11px] text-slate-500 font-mono" id="console-meta">
            Console Sẵn sàng
          </div>
        </div>

        <!-- Console Content -->
        <div class="flex-1 overflow-hidden p-3 relative">
          
          <!-- TAB 1: CUSTOM TESTCASE -->
          <div id="console-panel-custom" class="h-full flex space-x-3">
            <div class="w-1/2 flex flex-col">
              <span class="text-[10px] uppercase font-bold text-slate-500 mb-1">stdin tự nhập</span>
              <textarea id="custom-input" class="w-full flex-1 glass-input rounded-lg p-2 font-mono text-xs resize-none" 
                placeholder="Nhập dữ liệu kiểm tra tùy ý vào đây..."><?= !empty($samples[0]['input_data']) ? Security::escape($samples[0]['input_data']) : '' ?></textarea>
            </div>
            <div class="w-1/2 flex flex-col">
              <span class="text-[10px] uppercase font-bold text-slate-500 mb-1">stdout / stderr thực tế</span>
              <div id="custom-output" class="w-full flex-1 p-2 rounded-lg bg-black/50 border border-white/5 font-mono text-xs overflow-y-auto text-slate-300 whitespace-pre-wrap">
                (Bấm "Chạy thử" để xem kết quả xuất ra màn hình)
              </div>
            </div>
          </div>

          <!-- TAB 2: JUDGING QUEUE & REALTIME EXECUTION -->
          <div id="console-panel-judging" class="h-full flex flex-col space-y-2 overflow-y-auto hidden">
            <!-- Header kết quả chung -->
            <div id="judging-summary" class="p-3 rounded-xl bg-white/5 border border-white/10 flex items-center justify-between text-xs">
              <div class="flex items-center space-x-3">
                <span id="verdict-banner" class="px-2.5 py-1 rounded text-xs font-black font-mono badge-pending">
                  PENDING
                </span>
                <span id="verdict-progress" class="font-mono text-slate-300">
                  Đang gửi bài nộp vào hàng đợi...
                </span>
              </div>
              <div id="verdict-metrics" class="text-slate-400 font-mono text-[11px]">
                Run ID: #--
              </div>
            </div>

            <!-- Danh sách từng ca kiểm thử (Test Cases Grid) -->
            <div id="testcases-grid" class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-5 gap-2 pt-1">
              <!-- Sẽ được điền tự động qua JavaScript khi có kết quả -->
            </div>
          </div>

        </div>

      </div>

    </div>

  </div>

</div>

<!-- JavaScript Điều khiển Trình Soạn Thảo & Tương Tác API -->
<script>
  const PROBLEM_ID = <?= $problemId ?>;
  const STARTER_TEMPLATES = <?= json_encode($starterTemplates) ?>;
  let currentSubmissionId = null;
  let pollingInterval = null;

  // Hỗ trợ phím Tab trong textarea
  const editor = document.getElementById('code-editor');
  editor.addEventListener('keydown', function(e) {
    if (e.key === 'Tab') {
      e.preventDefault();
      const start = this.selectionStart;
      const end = this.selectionEnd;
      this.value = this.value.substring(0, start) + "    " + this.value.substring(end);
      this.selectionStart = this.selectionEnd = start + 4;
    }
  });

  // Tự động sinh Idempotency Key duy nhất cho mỗi lượt bấm nộp mới
  function generateIdempotencyKey() {
    return 'sub_' + Date.now() + '_' + Math.random().toString(36).substring(2, 10);
  }

  let activeIdempotencyKey = generateIdempotencyKey();

  // Chuyển đổi tab bên trái (Đề bài / Lịch sử)
  function switchLeftTab(tab) {
    const btnStatement = document.getElementById('tab-btn-statement');
    const btnHistory = document.getElementById('tab-btn-history');
    const panelStatement = document.getElementById('panel-statement');
    const panelHistory = document.getElementById('panel-history');

    if (tab === 'statement') {
      btnStatement.className = 'px-3 py-1.5 rounded-lg text-xs font-semibold bg-cyan-500/10 text-cyan-300 border border-cyan-500/30';
      btnHistory.className = 'px-3 py-1.5 rounded-lg text-xs font-semibold text-slate-400 hover:text-white hover:bg-white/5 transition-colors';
      panelStatement.classList.remove('hidden');
      panelHistory.classList.add('hidden');
    } else {
      btnHistory.className = 'px-3 py-1.5 rounded-lg text-xs font-semibold bg-cyan-500/10 text-cyan-300 border border-cyan-500/30';
      btnStatement.className = 'px-3 py-1.5 rounded-lg text-xs font-semibold text-slate-400 hover:text-white hover:bg-white/5 transition-colors';
      panelHistory.classList.remove('hidden');
      panelStatement.classList.add('hidden');
    }
  }

  // Chuyển đổi tab Console (Custom testcase / Judging)
  function switchConsoleTab(tab) {
    const tabCustom = document.getElementById('console-tab-custom');
    const tabJudging = document.getElementById('console-tab-judging');
    const panelCustom = document.getElementById('console-panel-custom');
    const panelJudging = document.getElementById('console-panel-judging');

    if (tab === 'custom') {
      tabCustom.className = 'px-3 py-1 rounded text-xs font-semibold text-cyan-300 bg-cyan-500/10 border border-cyan-500/30 flex items-center space-x-1.5';
      tabJudging.className = 'px-3 py-1 rounded text-xs font-semibold text-slate-400 hover:text-white flex items-center space-x-1.5 transition-colors';
      panelCustom.classList.remove('hidden');
      panelJudging.classList.add('hidden');
    } else {
      tabJudging.className = 'px-3 py-1 rounded text-xs font-semibold text-cyan-300 bg-cyan-500/10 border border-cyan-500/30 flex items-center space-x-1.5';
      tabCustom.className = 'px-3 py-1 rounded text-xs font-semibold text-slate-400 hover:text-white flex items-center space-x-1.5 transition-colors';
      panelJudging.classList.remove('hidden');
      panelCustom.classList.add('hidden');
    }
  }

  // Thay đổi ngôn ngữ
  function changeLanguage(lang) {
    if (confirm("Chuyển ngôn ngữ sẽ nạp mã mẫu mặc định nếu bạn chưa lưu nháp. Bạn có muốn tiếp tục?")) {
      editor.value = STARTER_TEMPLATES[lang] || '';
      showToast(`Đã chuyển sang ${lang.toUpperCase()}`, 'info');
    }
  }

  // Sao chép văn bản
  function copyText(text) {
    navigator.clipboard.writeText(text).then(() => {
      showToast('Đã sao chép vào bộ nhớ tạm!', 'success');
    });
  }

  // 1. LƯU BẢN NHÁP
  async function saveDraft() {
    const code = editor.value;
    const lang = document.getElementById('code-language').value;

    const res = await fetch('/BaiTapLon/api/run_test.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        problem_id: PROBLEM_ID,
        language: lang,
        source_code: code,
        action: 'draft'
      })
    });

    const data = await res.json();
    if (res.ok && data.success) {
      const now = new Date().toLocaleTimeString();
      document.getElementById('draft-text').innerText = `Đã lưu nháp (${now})`;
      showToast('Bản nháp đã được lưu an toàn!', 'success');
    } else {
      showToast(data.error || 'Lỗi khi lưu nháp', 'error');
    }
  }

  // 2. CHẠY THỬ VỚI CUSTOM INPUT
  async function runCustomTest() {
    const code = editor.value;
    const lang = document.getElementById('code-language').value;
    const input = document.getElementById('custom-input').value;
    const outBox = document.getElementById('custom-output');
    const meta = document.getElementById('console-meta');

    if (!code.trim()) {
      showToast('Vui lòng nhập mã nguồn trước khi chạy thử!', 'warning');
      return;
    }

    switchConsoleTab('custom');
    outBox.innerHTML = '<span class="text-cyan-400"><i class="fa-solid fa-spinner fa-spin mr-1"></i>Đang thực thi mã nguồn trong môi trường cách ly...</span>';
    meta.innerText = 'Đang chạy thử...';

    try {
      const res = await fetch('/BaiTapLon/api/run_test.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          problem_id: PROBLEM_ID,
          language: lang,
          source_code: code,
          custom_input: input,
          action: 'both'
        })
      });

      const data = await res.json();
      if (!res.ok) {
        outBox.innerHTML = `<span class="text-rose-400 font-bold">[Lỗi]:</span> ${data.error || 'Lỗi thực thi'}`;
        meta.innerText = 'Thất bại';
        return;
      }

      const exec = data.execution;
      meta.innerText = `Hoàn tất (${exec.execution_time_ms}ms) - Verdict: ${exec.verdict}`;

      let outputHtml = '';
      if (exec.stdout) {
        outputHtml += `<span class="text-emerald-400 font-bold">[stdout]:</span>\n${escapeHtml(exec.stdout)}\n`;
      }
      if (exec.stderr) {
        outputHtml += `<span class="text-rose-400 font-bold">[stderr / Lỗi]:</span>\n${escapeHtml(exec.stderr)}`;
      }
      if (!exec.stdout && !exec.stderr) {
        outputHtml = '<span class="text-slate-500">(Chương trình kết thúc thành công nhưng không in gì ra màn hình)</span>';
      }

      outBox.innerHTML = outputHtml;
      showToast(`Chạy thử hoàn tất (${exec.execution_time_ms}ms)`, 'info');

    } catch (err) {
      outBox.innerHTML = `<span class="text-rose-400">[Lỗi kết nối máy chủ]: ${err.message}</span>`;
      meta.innerText = 'Lỗi kết nối';
    }
  }

  // 3. NỘP BÀI CHÍNH THỨC (IDEMPOTENCY & CHẤM THỜI GIAN THỰC)
  async function submitSolution() {
    const code = editor.value;
    const lang = document.getElementById('code-language').value;

    if (!code.trim()) {
      showToast('Vui lòng nhập mã nguồn trước khi nộp bài!', 'warning');
      return;
    }

    const btnSubmit = document.getElementById('btn-submit');
    btnSubmit.disabled = true;
    btnSubmit.innerHTML = '<i class="fa-solid fa-spinner fa-spin mr-1"></i>Đang nộp...';

    switchConsoleTab('judging');
    document.getElementById('judging-badge').classList.remove('hidden');

    try {
      const res = await fetch('/BaiTapLon/api/submit.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          problem_id: PROBLEM_ID,
          language: lang,
          source_code: code,
          idempotency_key: activeIdempotencyKey
        })
      });

      const data = await res.json();
      btnSubmit.disabled = false;
      btnSubmit.innerHTML = '<i class="fa-solid fa-paper-plane text-[10px]"></i><span>Nộp bài</span>';

      if (!res.ok) {
        showToast(data.error || 'Nộp bài thất bại', 'error');
        return;
      }

      currentSubmissionId = data.submission_id;
      showToast(data.is_duplicate ? 'Yêu cầu nộp trùng (Idempotent) - Đang theo dõi bài nộp!' : 'Đã nộp bài thành công!', 'success');

      // Sinh key mới cho lần nộp tiếp theo (nếu người dùng bấm nộp lần nữa có chủ ý)
      activeIdempotencyKey = generateIdempotencyKey();

      // Bắt đầu Polling theo dõi trạng thái chấm
      startJudgingPolling(currentSubmissionId);

    } catch (err) {
      btnSubmit.disabled = false;
      btnSubmit.innerHTML = '<i class="fa-solid fa-paper-plane text-[10px]"></i><span>Nộp bài</span>';
      showToast('Lỗi mạng khi nộp bài: ' + err.message, 'error');
    }
  }

  // Polling lấy kết quả chấm
  function startJudgingPolling(subId) {
    if (pollingInterval) clearInterval(pollingInterval);

    const banner = document.getElementById('verdict-banner');
    const progress = document.getElementById('verdict-progress');
    const metrics = document.getElementById('verdict-metrics');
    const grid = document.getElementById('testcases-grid');

    banner.className = 'px-2.5 py-1 rounded text-xs font-black font-mono badge-pending';
    banner.innerText = 'CHỜ CHẤM...';
    progress.innerText = `Bài nộp #${subId} đang nằm trong hàng đợi worker...`;
    metrics.innerText = 'Trạng thái: Queued';
    grid.innerHTML = '';

    const checkStatus = async () => {
      try {
        const res = await fetch(`/BaiTapLon/api/submission_status.php?id=${subId}`);
        if (!res.ok) return;

        const data = await res.json();
        const sub = data.submission;
        const runs = data.runs;

        if (runs.length > 0) {
          const latestRun = runs[runs.length - 1];
          metrics.innerText = `Lần chấm #${latestRun.run_number} (Run ID: #${latestRun.id}) • ${latestRun.max_time_ms}ms`;

          // Cập nhật banner verdict
          const v = sub.final_verdict;
          banner.innerText = v;
          banner.className = `px-2.5 py-1 rounded text-xs font-black font-mono ${getBadgeClass(v)}`;
          progress.innerText = `Đã vượt qua: ${sub.pass_count}/${sub.total_cases} ca kiểm thử`;

          // Render Grid từng testcase
          if (latestRun.case_results) {
            renderTestcasesGrid(latestRun.case_results);
          }

          // Dừng polling khi đã có kết quả cuối cùng
          if (v !== 'Pending' && v !== 'Running') {
            clearInterval(pollingInterval);
            document.getElementById('console-meta').innerText = `Kết quả: ${v}`;
            showToast(`Chấm bài hoàn tất: ${v} (${sub.pass_count}/${sub.total_cases})`, v === 'AC' ? 'success' : 'error');
          }
        }
      } catch (e) {
        console.error('Polling error', e);
      }
    };

    // Kiểm tra ngay lập tức và sau đó mỗi 1.2 giây
    checkStatus();
    pollingInterval = setInterval(checkStatus, 1200);
  }

  // Render các ca kiểm thử
  function renderTestcasesGrid(caseResults) {
    const grid = document.getElementById('testcases-grid');
    grid.innerHTML = '';

    caseResults.forEach(c => {
      const chip = document.createElement('div');
      chip.className = `p-2 rounded-lg border text-xs flex flex-col justify-between ${getBadgeClass(c.verdict)}`;
      
      chip.innerHTML = `
        <div class="flex items-center justify-between font-mono font-bold">
          <span>#${c.case_order}</span>
          <span class="text-[10px]">${c.verdict}</span>
        </div>
        <div class="flex items-center justify-between text-[10px] opacity-80 mt-1 font-mono">
          <span>${c.execution_time_ms}ms</span>
          <span>${c.is_hidden ? '<i class="fa-solid fa-lock text-[9px]" title="Test kín bảo mật"></i>' : '<i class="fa-regular fa-eye text-[9px]" title="Test ví dụ"></i>'}</span>
        </div>
      `;
      grid.appendChild(chip);
    });
  }

  function loadSubmissionResult(subId) {
    switchConsoleTab('judging');
    startJudgingPolling(subId);
  }

  function getBadgeClass(v) {
    switch (v) {
      case 'AC': return 'badge-ac';
      case 'WA': return 'badge-wa';
      case 'TLE': return 'badge-tle';
      case 'RE': return 'badge-re';
      case 'CE': return 'badge-ce';
      case 'SE': return 'badge-se';
      default: return 'badge-pending';
    }
  }

  function escapeHtml(text) {
    if (!text) return '';
    return text.replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;");
  }
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
