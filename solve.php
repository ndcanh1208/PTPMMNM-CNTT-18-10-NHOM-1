<?php
/**
 * ============================================================================
 * Nhom1 Online Judge - Không gian Làm bài & Chấm Trực tuyến (IDE & Solve Workspace)
 * ============================================================================
 * MỤC ĐÍCH TRANG NÀY:
 * - Cung cấp môi trường lập trình trực tuyến chia 2 cột (trái: đề bài & lịch sử; phải: trình soạn code & console).
 * - Hỗ trợ đầy đủ luồng giải bài: Đọc đề -> Viết mã (Python/C++) -> Lưu nháp -> Chạy thử (Custom Input)
 *   -> Nộp bài chính thức (chống nộp trùng bằng Idempotency Key) -> Theo dõi tiến trình chấm theo thời gian thực.
 *
 * CẤU TRÚC FILE GỒM 3 PHẦN:
 * - PHẦN 1 (PHP): Đọc bài tập theo id/slug, kiểm tra quyền xem, lấy test ví dụ, khôi phục bản nháp và lịch sử nộp.
 * - PHẦN 2 (HTML): Giao diện IDE 2 cột (Cột trái: Đề bài/Lịch sử; Cột phải: CodeMirror Editor + Console 2 tab).
 * - PHẦN 3 (JavaScript): Điều khiển CodeMirror, tô đỏ dòng lỗi, gọi API lưu nháp, chạy thử, nộp bài và Polling kết quả.
 */

// Bật kiểm tra kiểu dữ liệu nghiêm ngặt trong PHP
declare(strict_types=1);

// Nạp cấu hình kết nối CSDL, xác thực phiên đăng nhập (Auth) và hàm chống XSS (Security)
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/core/Auth.php';
require_once __DIR__ . '/core/Security.php';

// Khởi tạo phiên làm việc (Session), lấy thông tin người dùng hiện tại (có thể null nếu là khách) và kết nối PDO
Auth::startSession();
$currentUser = Auth::user();
$pdo = getDbConnection();

// ============================================================================
// PHẦN 1: TRUY VẤN ĐỀ BÀI, KIỂM TRA PHÂN QUYỀN, LẤY TEST MẪU & BẢN NHÁP
// ============================================================================

// Đọc tham số chọn bài từ URL: hỗ trợ ?id=..., ?slug=... hoặc ?code=...
$problemId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$slugParam = trim((string)($_GET['slug'] ?? ($_GET['code'] ?? '')));

// 1. Lấy thông tin bài tập kết hợp (JOIN) với phiên bản đề hiện hành (current_version_id)
// Chỉ lấy bài tập chưa bị xóa mềm (p.deleted_at IS NULL)
if ($problemId > 0) {
    // Trường hợp 1: Người dùng truy cập bằng ID bài tập (?id=1)
    $stmt = $pdo->prepare("
        SELECT p.id, p.slug, p.title, p.topic, p.difficulty, p.source_attribution, p.visibility, p.is_public, p.created_by,
               pv.id as version_id, pv.version_number, pv.description, pv.input_description,
               pv.output_description, pv.constraints, pv.time_limit_ms, pv.memory_limit_kb
        FROM problems p
        JOIN problem_versions pv ON p.current_version_id = pv.id
        WHERE p.id = :id AND p.deleted_at IS NULL
    ");
    $stmt->execute(['id' => $problemId]);
} elseif ($slugParam !== '') {
    // Trường hợp 2: Người dùng truy cập bằng mã slug thân thiện (?slug=two-sum), hỗ trợ không phân biệt hoa/thường và dấu '_'
    $stmt = $pdo->prepare("
        SELECT p.id, p.slug, p.title, p.topic, p.difficulty, p.source_attribution, p.visibility, p.is_public, p.created_by,
               pv.id as version_id, pv.version_number, pv.description, pv.input_description,
               pv.output_description, pv.constraints, pv.time_limit_ms, pv.memory_limit_kb
        FROM problems p
        JOIN problem_versions pv ON p.current_version_id = pv.id
        WHERE (p.slug = :slug OR LOWER(p.slug) = LOWER(:slug2)) AND p.deleted_at IS NULL
    ");
    $stmt->execute(['slug' => $slugParam, 'slug2' => strtolower(str_replace('_', '-', $slugParam))]);
} else {
    // Trường hợp 3: Không truyền tham số -> tự động mở bài tập công khai đầu tiên trong hệ thống
    $stmt = $pdo->prepare("
        SELECT p.id, p.slug, p.title, p.topic, p.difficulty, p.source_attribution, p.visibility, p.is_public, p.created_by,
               pv.id as version_id, pv.version_number, pv.description, pv.input_description,
               pv.output_description, pv.constraints, pv.time_limit_ms, pv.memory_limit_kb
        FROM problems p
        JOIN problem_versions pv ON p.current_version_id = pv.id
        WHERE p.is_public = 1 AND p.visibility = 'public' AND p.deleted_at IS NULL
        ORDER BY p.id ASC
        LIMIT 1
    ");
    $stmt->execute();
}
$problem = $stmt->fetch();

// Nếu không tìm thấy bài tập hợp lệ thì chuyển hướng về trang danh sách bài tập
if (!$problem) {
    header('Location: /BaiTapLon/problems.php');
    exit;
}

// Kiểm tra quyền hiển thị của bài tập theo 3 chế độ:
// - 'public': ai cũng xem được
// - 'hidden': chỉ Admin hoặc Giảng viên tạo ra bài đó mới xem được
// - 'exam_only': chỉ Admin/GV tạo bài hoặc sinh viên đã tham gia ít nhất 1 lớp học/phòng thi
$vis = $problem['visibility'] ?? ($problem['is_public'] ? 'public' : 'hidden');
$isStaff = Auth::isAdmin() || (Auth::isInstructor() && (int)$problem['created_by'] === Auth::id());
if ($vis === 'hidden' && !$isStaff) {
    header('Location: /BaiTapLon/problems.php');
    exit;
}
if ($vis === 'exam_only' && !$isStaff) {
    // Kiểm tra trong bảng classroom_members xem sinh viên có thuộc lớp/phòng thi nào không
    $allowedExam = false;
    if ($currentUser) {
        $cmStmt = $pdo->prepare("SELECT COUNT(*) FROM classroom_members WHERE user_id = ?");
        $cmStmt->execute([$currentUser['id']]);
        $allowedExam = ((int)$cmStmt->fetchColumn() > 0);
    }
    if (!$allowedExam) {
        header('Location: /BaiTapLon/problems.php');
        exit;
    }
}

// 2. Lấy danh sách các ca kiểm thử ví dụ công khai (is_sample = 1) của phiên bản hiện tại để hiển thị trên đề bài
// Lưu ý: tuyệt đối không truy vấn test chấm kín (is_hidden = 1) ở trang này để bảo mật đáp án
$sampleStmt = $pdo->prepare("
    SELECT case_order, input_data, expected_output
    FROM test_cases
    WHERE problem_version_id = :vid AND is_sample = 1
    ORDER BY case_order ASC
");
$sampleStmt->execute(['vid' => $problem['version_id']]);
$samples = $sampleStmt->fetchAll();

// 3. Khởi tạo mã nguồn mẫu (starter templates) cho Python/C++ và khôi phục bản nháp gần nhất (nếu người dùng đã đăng nhập)
$defaultLang = 'python';
$initialCode = "";
$starterTemplates = [
    'python' => "# Viết mã nguồn Python 3 tại đây\nimport sys\n\ndef main():\n    # Đọc dữ liệu từ stdin\n    # In kết quả ra stdout\n    pass\n\nif __name__ == '__main__':\n    main()\n",
    'cpp'    => "// Viết mã nguồn C++ tại đây\n#include <iostream>\n\nusing namespace std;\n\nint main() {\n    ios_base::sync_with_stdio(false);\n    cin.tie(NULL);\n    \n    // Đọc stdin và in stdout\n    \n    return 0;\n}\n"
];

if ($currentUser) {
    // Tìm bản nháp được cập nhật gần nhất trong bảng code_drafts cho (user_id, problem_id)
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
        // Nếu có bản nháp -> khôi phục lại đúng ngôn ngữ và mã nguồn đã lưu
        $defaultLang = $draft['language'];
        $initialCode = $draft['source_code'];
    } else {
        // Nếu chưa có bản nháp -> nạp khung code mẫu mặc định
        $initialCode = $starterTemplates[$defaultLang];
    }
} else {
    $initialCode = $starterTemplates[$defaultLang];
}

// 4. Lấy tối đa 10 lần nộp bài gần nhất của người dùng hiện tại cho bài tập này (hiển thị ở tab "Lịch sử Nộp bài")
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

// Thiết lập tiêu đề trang và nạp khung header + sidebar chung
$pageTitle = 'Giải bài: ' . $problem['title'];
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/sidebar.php';
?>

<!-- ========================================================================= -->
<!-- PHẦN 2: GIAO DIỆN IDE TRỰC TUYẾN 2 CỘT (ĐỀ BÀI | TRÌNH SOẠN CODE & CONSOLE) -->
<!-- ========================================================================= -->

<!-- Nạp thư viện CodeMirror 5 lưu nội bộ (chạy offline không cần Internet): giao diện Dracula, tô màu cú pháp Python/C++, đóng mở ngoặc tự động -->
<link rel="stylesheet" href="/BaiTapLon/assets/vendor/codemirror/codemirror.min.css">
<link rel="stylesheet" href="/BaiTapLon/assets/vendor/codemirror/dracula.min.css">
<script src="/BaiTapLon/assets/vendor/codemirror/codemirror.min.js"></script>
<script src="/BaiTapLon/assets/vendor/codemirror/python.min.js"></script>
<script src="/BaiTapLon/assets/vendor/codemirror/clike.min.js"></script>
<script src="/BaiTapLon/assets/vendor/codemirror/matchbrackets.min.js"></script>
<script src="/BaiTapLon/assets/vendor/codemirror/closebrackets.min.js"></script>

<!-- Khung toàn màn hình (chừa lề trái 64 cho Sidebar, chia thành vùng làm việc chính + thanh trạng thái dưới đáy) -->
<div class="pl-64 w-full h-screen flex flex-col overflow-hidden">
  
  <!-- Không gian làm bài chia đôi 2 cột ngang -->
  <div class="flex-1 flex overflow-hidden w-full">
    
    <!-- ==================== CỘT TRÁI (42%): ĐỀ BÀI & LỊCH SỬ NỘP BÀI ==================== -->
    <div class="w-[42%] h-full flex flex-col border-r border-white/10 glass-panel overflow-hidden shrink-0">
      
      <!-- Phần đầu cố định: Huy hiệu độ khó, phiên bản bộ test, tiêu đề, giới hạn tài nguyên & nút chuyển Tab -->
      <div class="p-6 border-b border-white/10 space-y-3 shrink-0 bg-white/[0.01]">
        <div class="flex items-center space-x-2 text-xs">
          <?php
            // Chọn màu sắc huy hiệu tương ứng với mức độ khó (Easy = xanh, Medium = vàng, Hard = đỏ)
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

        <!-- Hiển thị giới hạn thời gian chạy (ms), bộ nhớ tối đa (quy đổi từ KB sang MB) và nguồn bài -->
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

        <!-- 2 nút chuyển đổi nội dung Cột trái: Mô tả Đề bài vs Lịch sử Nộp bài (gọi hàm JS switchLeftTab) -->
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

      <!-- Vùng nội dung có thanh cuộn dọc độc lập của Cột trái -->
      <div class="flex-1 overflow-y-auto p-6 space-y-6 text-sm text-slate-300 leading-relaxed">
        
        <!-- PANEL 1: CHI TIẾT MÔ TẢ ĐỀ BÀI, INPUT/OUTPUT, RÀNG BUỘC & TEST VÍ DỤ -->
        <div id="panel-statement" class="space-y-6">
          <!-- Mô tả bài toán (dùng Security::escape chống mã độc HTML/JS XSS) -->
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

          <!-- Ràng buộc dữ liệu -->
          <div>
            <h3 class="text-xs font-bold uppercase tracking-wider text-slate-400 mb-2">Giới hạn & Ràng buộc</h3>
            <div class="p-3 rounded-xl bg-white/[0.02] border border-white/5 text-amber-300 text-xs font-mono">
              <?= Security::escape($problem['constraints']) ?>
            </div>
          </div>

          <!-- Danh sách các ca kiểm thử ví dụ công khai (Sample testcases) kèm nút Copy Input -->
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

        <!-- PANEL 2: LỊCH SỬ 10 BÀI NỘP GẦN NHẤT CỦA NGƯỜI DÙNG (Ẩn mặc định bằng class hidden) -->
        <div id="panel-history" class="space-y-3 hidden">
          <?php if (empty($userSubmissions)): ?>
            <div class="text-center py-12 text-slate-500 text-xs">
              <i class="fa-solid fa-inbox text-2xl mb-2 block"></i>
              Bạn chưa có bài nộp nào cho bài tập này.
            </div>
          <?php else: ?>
            <?php foreach ($userSubmissions as $sub): ?>
              <?php
                // Ánh xạ phán quyết sang class CSS màu huy hiệu (AC, WA, TLE, RE, CE, Pending)
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

                <!-- Nút tải lại chi tiết từng test case của bài nộp cũ vào khung Console bên phải -->
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


    <!-- ==================== CỘT PHẢI (58%): TRÌNH SOẠN THẢO CODE & CONSOLE ==================== -->
    <div class="flex-1 min-w-0 h-full flex flex-col overflow-hidden bg-[#0A0D14]/80">
      
      <!-- Thanh công cụ phía trên (Top Action Bar): Chọn ngôn ngữ + Lưu nháp / Chạy thử / Nộp bài -->
      <div class="h-16 px-6 border-b border-white/10 flex items-center justify-between bg-black/40 shrink-0">
        <!-- Chọn ngôn ngữ lập trình (Python / C++) & chỉ báo thời điểm lưu nháp -->
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

        <!-- 3 nút chức năng chính: Lưu nháp (saveDraft), Chạy thử (runCustomTest), Nộp bài (submitSolution) -->
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

      <!-- Vùng soạn thảo mã nguồn: thẻ <textarea id="code-editor"> sẽ được thư viện CodeMirror biến hóa thành trình soạn code chuyên nghiệp -->
      <div class="flex-1 min-h-0 relative flex flex-col bg-[#070A0F] overflow-hidden">
        <textarea id="code-editor" spellcheck="false" 
          class="w-full h-full bg-[#070A0F] text-slate-100 font-mono text-sm p-4 resize-none outline-none leading-relaxed border-none focus:ring-0"><?= Security::escape($initialCode) ?></textarea>
      </div>

      <!-- ==================== KHỐI DƯỚI: CONSOLE 2 TAB (CHẠY THỬ & CHẤM BÀI) ==================== -->
      <div class="h-64 border-t border-white/10 flex flex-col bg-black/60 shrink-0">
        
        <!-- Thanh tiêu đề 2 tab Console và trạng thái tổng quan bên phải (#console-meta) -->
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

        <!-- Vùng hiển thị nội dung của 2 tab Console -->
        <div class="flex-1 overflow-hidden p-3 relative">
          
          <!-- TAB 1: CHẠY THỬ VỚI DỮ LIỆU TỰ NHẬP (Chia đôi: trái nhập stdin, phải xem stdout/stderr) -->
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

          <!-- TAB 2: THEO DÕI TIẾN TRÌNH CHẤM BÀI THỜI GIAN THỰC (Hiển thị phán quyết tổng & lưới từng test case) -->
          <div id="console-panel-judging" class="h-full flex flex-col space-y-2 overflow-y-auto hidden">
            <!-- Thanh tóm tắt kết quả chấm tổng thể (Verdict, số test pass, Run ID, thời gian) -->
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

            <!-- Lưới hiển thị kết quả từng ca kiểm thử (được JS tạo động trong hàm renderTestcasesGrid) -->
            <div id="testcases-grid" class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-5 gap-2 pt-1">
              <!-- Sẽ được điền tự động qua JavaScript khi có kết quả -->
            </div>
          </div>

        </div>

      </div>

    </div>

  </div>

  <!-- Thanh trạng thái IDE dưới đáy màn hình (gợi ý phím tắt Ctrl+Enter nộp bài & thông tin phiên bản) -->
  <div class="h-7 px-4 border-t border-white/10 bg-[#07090E] flex items-center justify-between text-[11px] text-slate-500 shrink-0">
    <div class="flex items-center space-x-4">
      <span class="flex items-center space-x-1.5"><i class="fa-solid fa-code-branch text-cyan-400"></i><span class="text-slate-400">Nhom1 IDE</span></span>
      <span>UTF-8</span>
      <span>Spaces: 4</span>
      <span class="text-slate-400 font-mono"><i class="fa-solid fa-keyboard text-[10px] mr-1"></i>Ctrl+Enter: Nộp bài</span>
    </div>
    <div class="flex items-center space-x-3 text-slate-400">
      <span>Đề tài 20: Phát triển phần mềm mã nguồn mở</span>
      <span class="text-white/20">|</span>
      <span class="text-cyan-400 font-mono">Nhom1 v2.0</span>
    </div>
  </div>

</div>

<!-- ========================================================================= -->
<!-- PHẦN 3: JAVASCRIPT ĐIỀU KHIỂN CODEMIRROR, TÔ LỖI & GỌI API CHẤM BÀI        -->
<!-- ========================================================================= -->
<script>
  // Truyền các hằng số từ PHP sang JavaScript: ID bài tập và bộ mã mẫu (starter templates) của Python/C++
  const PROBLEM_ID = <?= $problemId ?>;
  const STARTER_TEMPLATES = <?= json_encode($starterTemplates) ?>;
  // Lưu ID bài nộp hiện đang theo dõi và bộ đếm hẹn giờ Polling (setInterval)
  let currentSubmissionId = null;
  let pollingInterval = null;

  // Khởi tạo trình soạn thảo CodeMirror từ thẻ <textarea id="code-editor">
  // Hỗ trợ tô màu cú pháp, đánh số dòng, tự động đóng ngoặc, thụt lề 4 dấu cách và phím tắt Ctrl+Enter
  const codeTextarea = document.getElementById('code-editor');
  const initialLang = document.getElementById('code-language').value;
  let cmEditor = null;

  if (typeof CodeMirror !== 'undefined') {
    cmEditor = CodeMirror.fromTextArea(codeTextarea, {
      mode: initialLang === 'cpp' ? 'text/x-c++src' : 'python',
      theme: 'dracula',
      lineNumbers: true,
      matchBrackets: true,       // Bắt lỗi và đối chiếu cặp ngoặc
      autoCloseBrackets: true,   // Tự động đóng ngoặc (), [], {}, '', ""
      indentUnit: 4,
      tabSize: 4,
      indentWithTabs: false,
      smartIndent: true,         // Thụt lề ngay lập tức sau dấu : hoặc {
      lineWrapping: false,
      extraKeys: {
        // Phím Tab: thụt lề khối đang chọn hoặc chèn 4 khoảng trắng
        "Tab": function(cm) {
          if (cm.somethingSelected()) {
            cm.indentSelection("add");
          } else {
            cm.replaceSelection("    ", "end");
          }
        },
        // Phím Shift+Tab: giảm thụt lề khối đang chọn
        "Shift-Tab": function(cm) {
          cm.indentSelection("subtract");
        },
        // Phím tắt Ctrl+Enter: gọi nộp bài nhanh ngay trong trình soạn thảo
        "Ctrl-Enter": function() {
          submitSolution();
        }
      }
    });

    // Căn chỉnh CodeMirror phủ kín vùng soạn thảo và tự vẽ lại khi người dùng đổi kích thước cửa sổ
    cmEditor.setSize('100%', '100%');
    setTimeout(() => cmEditor.refresh(), 50);
    window.addEventListener('resize', () => { if (cmEditor) cmEditor.refresh(); });
  } else {
    // Chế độ dự phòng (Fallback) nếu thư viện CodeMirror không nạp được: vẫn hỗ trợ nhấn Tab chèn 4 dấu cách trên textarea thường
    codeTextarea.addEventListener('keydown', function(e) {
      if (e.key === 'Tab') {
        e.preventDefault();
        const start = this.selectionStart;
        const end = this.selectionEnd;
        this.value = this.value.substring(0, start) + "    " + this.value.substring(end);
        this.selectionStart = this.selectionEnd = start + 4;
      }
    });
  }

  // Hàm đọc toàn bộ mã nguồn hiện có trong trình soạn thảo (tương thích cả CodeMirror lẫn textarea dự phòng)
  function getEditorValue() {
    return cmEditor ? cmEditor.getValue() : codeTextarea.value;
  }

  // Hàm ghi đè nội dung mã nguồn vào trình soạn thảo (dùng khi đổi ngôn ngữ lập trình)
  function setEditorValue(val) {
    if (cmEditor) {
      cmEditor.setValue(val);
    } else {
      codeTextarea.value = val;
    }
  }

  // Biến lưu tham chiếu dòng đang được tô nền đỏ (cm-error-line) khi code báo lỗi cú pháp/thực thi
  let currentErrorLineHandle = null;

  // Hàm xóa vệt báo đỏ dòng lỗi trước đó trên trình soạn thảo CodeMirror
  function clearErrorHighlight() {
    if (cmEditor && currentErrorLineHandle !== null) {
      cmEditor.removeLineClass(currentErrorLineHandle, 'background', 'cm-error-line');
      currentErrorLineHandle = null;
    }
  }

  // Lắng nghe sự kiện gõ phím: ngay khi người dùng sửa code thì tự động tắt vệt báo lỗi cũ
  if (cmEditor) {
    cmEditor.on('change', function() {
      clearErrorHighlight();
    });
  } else {
    codeTextarea.addEventListener('input', clearErrorHighlight);
  }

  // Hàm phân tích chuỗi log lỗi (stderr / compiler_message) bằng biểu thức chính quy (Regex)
  // để tìm số dòng bị lỗi, tô nền đỏ dòng đó và cuộn con trỏ tới đúng vị trí lỗi
  function highlightErrorFromLog(logText) {
    if (!logText || !cmEditor) return;
    clearErrorHighlight();

    let errorLine = null;

    // Mẫu nhận diện lỗi Python (VD: File "<stdin>", line 3)
    const pyMatch = logText.match(/line (\d+)/i);
    if (pyMatch && pyMatch[1]) {
      errorLine = parseInt(pyMatch[1], 10);
    }

    // Mẫu nhận diện lỗi biên dịch C++ G++ (VD: solution.cpp:14:5: error: ...)
    const cppMatch = logText.match(/:(\d+):(?:\d+:)?\s*(?:fatal )?error/i);
    if (cppMatch && cppMatch[1]) {
      errorLine = parseInt(cppMatch[1], 10);
    }

    // Nếu trích xuất được số dòng hợp lệ -> tô đỏ nền dòng (chỉ số CodeMirror đếm từ 0 nên trừ 1) và cuộn tới đó
    if (errorLine !== null && errorLine > 0 && errorLine <= cmEditor.lineCount()) {
      const lineIdx = errorLine - 1;
      currentErrorLineHandle = cmEditor.addLineClass(lineIdx, 'background', 'cm-error-line');
      cmEditor.scrollIntoView({ line: lineIdx, ch: 0 }, 100);
      cmEditor.setCursor({ line: lineIdx, ch: 0 });
      showToast(`Phát hiện lỗi tại dòng ${errorLine}!`, 'error');
    }
  }

  // Hàm sinh khóa Idempotency Key ngẫu nhiên duy nhất cho mỗi lượt nộp bài
  // Tác dụng: nếu người dùng bấm đúp nút "Nộp bài" hoặc mạng chập chờn gửi lại request, máy chủ dựa vào khóa này để không tạo 2 bài nộp trùng nhau
  function generateIdempotencyKey() {
    return 'sub_' + Date.now() + '_' + Math.random().toString(36).substring(2, 10);
  }

  let activeIdempotencyKey = generateIdempotencyKey();

  // Hàm chuyển đổi giữa 2 tab bên Cột trái: 'statement' (Mô tả Đề bài) và 'history' (Lịch sử Nộp bài)
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

  // Hàm chuyển đổi giữa 2 tab ở khung Console bên dưới: 'custom' (Chạy thử) và 'judging' (Tiến trình chấm bài)
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

  // Hàm xử lý khi người dùng đổi ngôn ngữ lập trình (Python <-> C++): xác nhận, nạp mã mẫu và đổi chế độ tô màu cú pháp CodeMirror
  function changeLanguage(lang) {
    if (confirm("Chuyển ngôn ngữ sẽ nạp mã mẫu mặc định nếu bạn chưa lưu nháp. Bạn có muốn tiếp tục?")) {
      const template = STARTER_TEMPLATES[lang] || '';
      setEditorValue(template);
      if (cmEditor) {
        cmEditor.setOption('mode', lang === 'cpp' ? 'text/x-c++src' : 'python');
      }
      clearErrorHighlight();
      showToast(`Đã chuyển sang ${lang.toUpperCase()}`, 'info');
    }
  }

  // Hàm sao chép nhanh dữ liệu ví dụ (stdin) vào khay nhớ tạm (Clipboard) của máy tính
  function copyText(text) {
    navigator.clipboard.writeText(text).then(() => {
      showToast('Đã sao chép vào bộ nhớ tạm!', 'success');
    });
  }

  // 1. HÀM LƯU BẢN NHÁP MÃ NGUỒN: gửi mã hiện tại lên API /BaiTapLon/api/run_test.php (action = 'draft') để lưu vào bảng code_drafts
  async function saveDraft() {
    const code = getEditorValue();
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
      // Cập nhật nhãn thời gian lưu nháp trên thanh công cụ
      const now = new Date().toLocaleTimeString();
      document.getElementById('draft-text').innerText = `Đã lưu nháp (${now})`;
      showToast('Bản nháp đã được lưu an toàn!', 'success');
    } else {
      showToast(data.error || 'Lỗi khi lưu nháp', 'error');
    }
  }

  // 2. HÀM CHẠY THỬ VỚI CUSTOM INPUT: gửi mã nguồn + stdin tự nhập lên API run_test.php (action = 'both': vừa lưu nháp vừa chạy thử trong Sandbox)
  async function runCustomTest() {
    const code = getEditorValue();
    const lang = document.getElementById('code-language').value;
    const input = document.getElementById('custom-input').value;
    const outBox = document.getElementById('custom-output');
    const meta = document.getElementById('console-meta');

    if (!code.trim()) {
      showToast('Vui lòng nhập mã nguồn trước khi chạy thử!', 'warning');
      return;
    }

    // Tự động chuyển Console sang tab "Dữ liệu chạy thử" và hiện trạng thái đang thực thi
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

      // Hiển thị thời gian chạy, kết quả stdout và lỗi stderr (escapeHtml để không bị hiểu nhầm thẻ HTML trong output)
      const exec = data.execution;
      meta.innerText = `Hoàn tất (${exec.execution_time_ms}ms) - Verdict: ${exec.verdict}`;

      let outputHtml = '';
      if (exec.stdout) {
        outputHtml += `<span class="text-emerald-400 font-bold">[stdout]:</span>\n${escapeHtml(exec.stdout)}\n`;
      }
      if (exec.stderr) {
        outputHtml += `<span class="text-rose-400 font-bold">[stderr / Lỗi]:</span>\n${escapeHtml(exec.stderr)}`;
        // BÁO ĐỎ DÒNG LỖI TRÊN PHẦN LÀM BÀI
        highlightErrorFromLog(exec.stderr);
      } else if (exec.verdict !== 'AC') {
        highlightErrorFromLog(exec.stdout || '');
      } else {
        clearErrorHighlight();
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

  // 3. HÀM NỘP BÀI CHÍNH THỨC: gửi mã nguồn kèm idempotency_key tới /BaiTapLon/api/submit.php, sau đó bật cơ chế Polling theo dõi tiến trình chấm
  async function submitSolution() {
    const code = getEditorValue();
    const lang = document.getElementById('code-language').value;

    if (!code.trim()) {
      showToast('Vui lòng nhập mã nguồn trước khi nộp bài!', 'warning');
      return;
    }

    // Khóa tạm nút "Nộp bài" để tránh người dùng bấm liên tiếp trong lúc gửi
    const btnSubmit = document.getElementById('btn-submit');
    btnSubmit.disabled = true;
    btnSubmit.innerHTML = '<i class="fa-solid fa-spinner fa-spin mr-1"></i>Đang nộp...';

    // Mở tab "Tiến trình Chấm bài" ở Console bên dưới
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

      // Bắt đầu Polling định kỳ gọi API kiểm tra trạng thái chấm của submission vừa tạo
      startJudgingPolling(currentSubmissionId);

    } catch (err) {
      btnSubmit.disabled = false;
      btnSubmit.innerHTML = '<i class="fa-solid fa-paper-plane text-[10px]"></i><span>Nộp bài</span>';
      showToast('Lỗi mạng khi nộp bài: ' + err.message, 'error');
    }
  }

  // Hàm Polling (hỏi thăm máy chủ định kỳ 1.2 giây/lần) tới /BaiTapLon/api/submission_status.php
  // để cập nhật phán quyết tổng và lưới từng test case cho đến khi chấm xong
  function startJudgingPolling(subId) {
    // Hủy bộ đếm Polling cũ (nếu đang chạy) để tránh chồng chéo nhiều vòng lặp cùng lúc
    if (pollingInterval) clearInterval(pollingInterval);

    const banner = document.getElementById('verdict-banner');
    const progress = document.getElementById('verdict-progress');
    const metrics = document.getElementById('verdict-metrics');
    const grid = document.getElementById('testcases-grid');

    // Đặt giao diện Console về trạng thái chờ chấm ban đầu
    banner.className = 'px-2.5 py-1 rounded text-xs font-black font-mono badge-pending';
    banner.innerText = 'CHỜ CHẤM...';
    progress.innerText = `Bài nộp #${subId} đang nằm trong hàng đợi worker...`;
    metrics.innerText = 'Trạng thái: Queued';
    grid.innerHTML = '';

    // Hàm bất đồng bộ gọi API lấy trạng thái mới nhất của bài nộp
    const checkStatus = async () => {
      try {
        const res = await fetch(`/BaiTapLon/api/submission_status.php?id=${subId}`);
        if (!res.ok) return;

        const data = await res.json();
        const sub = data.submission;
        const runs = data.runs;

        if (runs.length > 0) {
          // Lấy lượt chấm mới nhất (phần tử cuối cùng trong mảng runs)
          const latestRun = runs[runs.length - 1];
          metrics.innerText = `Lần chấm #${latestRun.run_number} (Run ID: #${latestRun.id}) • ${latestRun.max_time_ms}ms`;

          // Cập nhật banner phán quyết (AC/WA/TLE/RE/CE...) và tiến độ số test đã vượt qua
          const v = sub.final_verdict;
          banner.innerText = v;
          banner.className = `px-2.5 py-1 rounded text-xs font-black font-mono ${getBadgeClass(v)}`;
          progress.innerText = `Đã vượt qua: ${sub.pass_count}/${sub.total_cases} ca kiểm thử`;

          // Vẽ lưới kết quả chi tiết của từng ca kiểm thử
          if (latestRun.case_results) {
            renderTestcasesGrid(latestRun.case_results);
          }

          // Khi không còn ở trạng thái Pending/Running -> dừng vòng lặp Polling và thông báo kết quả cuối
          if (v !== 'Pending' && v !== 'Running') {
            clearInterval(pollingInterval);
            document.getElementById('console-meta').innerText = `Kết quả: ${v}`;
            showToast(`Chấm bài hoàn tất: ${v} (${sub.pass_count}/${sub.total_cases})`, v === 'AC' ? 'success' : 'error');

            // Báo đỏ dòng lỗi nếu gặp Compilation Error (CE) hoặc Runtime Error (RE)
            if (v === 'CE' || v === 'RE') {
              highlightErrorFromLog(latestRun.compiler_message || latestRun.system_error_log || '');
            } else {
              clearErrorHighlight();
            }
          }
        }
      } catch (e) {
        console.error('Polling error', e);
      }
    };

    // Gọi kiểm tra ngay lập tức lần đầu, sau đó lặp lại mỗi 1200ms (1.2 giây)
    checkStatus();
    pollingInterval = setInterval(checkStatus, 1200);
  }

  // Hàm vẽ lưới các ô kết quả test case (#1, #2, ...) kèm phán quyết, thời gian chạy (ms) và biểu tượng mắt/ổ khóa (test mẫu vs test kín)
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

  // Hàm xem lại chi tiết kết quả chấm của một bài nộp cũ trong tab "Lịch sử Nộp bài"
  function loadSubmissionResult(subId) {
    switchConsoleTab('judging');
    startJudgingPolling(subId);
  }

  // Hàm tiện ích ánh xạ mã phán quyết (AC, WA, TLE, RE, CE, SE) sang class CSS màu sắc tương ứng
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

  // Hàm mã hóa ký tự đặc biệt (&, <, >) thành thực thể HTML an toàn trước khi chèn stdout/stderr vào innerHTML (chống XSS)
  function escapeHtml(text) {
    if (!text) return '';
    return text.replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;");
  }
</script>

</body>
</html>
