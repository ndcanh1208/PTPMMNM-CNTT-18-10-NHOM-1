# KẾ HOẠCH TỔNG THỂ DỰ ÁN (MASTER PLAN)
**Đề tài 20:** Xây dựng hệ thống luyện tập và chấm bài lập trình trực tuyến (Online Judge - ByteFlow)  
**Môn học:** Phát triển phần mềm mã nguồn mở  
**Thư mục gốc dự án:** `C:\xampp\htdocs\BaiTapLon`

---

## 1. HƯỚNG DẪN BẮT BUỘC DÀNH CHO AI AGENT (ANTIGRAVITY)

1. **Đọc và kiểm tra hiện trạng trước khi code:** Trước khi thực hiện bất kỳ mục nào, AI phải kiểm tra các file hiện có trong thư mục dự án (`config/`, `core/`, `database/`, `api/`, `includes/`, `tests/`, `index.php`, `problems.php`, `solve.php`, `submissions.php`, `login.php`, `register.php`) để kế thừa hoặc nâng cấp, tránh tạo file rác trùng lặp.
2. **Tuân thủ tuyệt đối Hệ thống Thiết kế (Design System):**
   - **Phong cách:** Dark Mode kết hợp **Glassmorphism** (`backdrop-blur-xl`, nền bán trong suốt `rgba(18, 22, 31, 0.65)`, viền mảnh `border-white/10`).
   - **Bảng màu:** Nền chuyển động 3 màu **Đen sâu (`#050507`)**, **Xám đậm (`#12161f`)**, và **Xanh Cyan phát sáng (`#00FFFF`)** làm màu nhấn chủ đạo.
   - **Bố cục:** Điều hướng bằng **Left Sidebar** cố định bên trái, có hiệu ứng micro-interaction mượt mà (`transition-all duration-300`, bóng đổ neon `shadow-[0_0_15px_rgba(0,255,255,0.3)]`).
3. **5 Nguyên tắc Kỹ thuật & Bảo mật Sống còn (Theo Rubric chấm điểm):**
   - **Nguyên tắc 1 (Tách tiến trình thực thi):** TUYỆT ĐỐI KHÔNG chạy mã nguồn của người học nộp lên trực tiếp trong tiến trình Web (Apache/PHP request). Web chỉ nhận bài và đẩy vào hàng đợi CSDL; một tiến trình **Worker độc lập chạy nền** (`core/worker.php` hoặc dịch vụ thực thi cách ly) sẽ lấy bài ra chấm.
   - **Nguyên tắc 2 (Idempotency - Chống nộp trùng):** Mỗi yêu cầu nộp bài phải kèm `idempotency_key`. Nếu gửi lại cùng một yêu cầu (do mạng lag hoặc bấm đúp), hệ thống trả về đúng `submission_id` đã tạo, không sinh bản ghi trùng. Nếu người dùng chủ động bấm nộp lần mới thì tạo `submission_id` mới.
   - **Nguyên tắc 3 (Chống ghi đè kết quả cũ về muộn - Stale Run Protection):** Một bài nộp (`submissions`) có thể được chấm nhiều lần (`judging_runs`). Nếu kết quả của lần chấm cũ (`run_id` nhỏ hơn hoặc `started_at` cũ hơn) trả về muộn sau khi đã có lần chấm mới (Rejudge), nó chỉ được lưu vào lịch sử `judging_runs`, TUYỆT ĐỐI KHÔNG được ghi đè trạng thái hiện hành (`latest_run_id` / `verdict`) của bài nộp.
   - **Nguyên tắc 4 (Bảo mật dữ liệu chấm kín & Quyền riêng tư):** API và giao diện tuyệt đối không trả về trình duyệt `input`, `expected_output` hoặc `actual_output` của các testcase ẩn (`is_hidden = 1`). Người học A không được xem mã nguồn của người học B (trừ Giảng viên của lớp hoặc Admin).
   - **Nguyên tắc 5 (Phân biệt lỗi bài làm và lỗi hệ thống):** Phải tách bạch lỗi của người học (`AC`, `WA`, `TLE`, `MLE`, `CE`, `RE`) với lỗi dịch vụ thực thi (`SE` - System Error / Pending Retry). Khi bộ thực thi bị ngắt, không được chấm người học sai.

---

## 2. KIẾN TRÚC CƠ SỞ DỮ LIỆU CHUẨN (MySQL Schema)

AI Agent cần kiểm tra thư mục `database/` và đảm bảo đầy đủ các bảng bắt buộc của đề bài cùng các bảng phân quyền:

### 2.1. Các bảng Quản lý Người dùng, Lớp học & Nháp
- **`users`**: `id`, `username`, `email`, `password_hash`, `full_name`, `role` (`learner`, `instructor`, `admin`), `created_at`.
- **`classrooms`**: `id`, `name`, `code`, `instructor_id`, `created_at`.
- **`classroom_members`**: `id`, `classroom_id`, `user_id`, `joined_at`.
- **`code_drafts`**: `id`, `user_id`, `problem_id`, `language` (`python`, `cpp`), `source_code`, `updated_at` (Hỗ trợ lưu bản nháp trên trình duyệt).

### 2.2. 6 Bảng Dữ liệu Cốt lõi Bắt buộc trong Đề bài
1. **`problems`**:
   - `id`, `slug`, `title`, `topic` (Chủ đề), `difficulty` (`Easy`, `Medium`, `Hard`), `current_version_id`, `source_attribution` (Nguồn sử dụng hợp lệ & giấy phép), `is_public`, `created_by`, `created_at`.
2. **`problem_versions`** (Quản lý phiên bản đề và bộ dữ liệu chấm):
   - `id`, `problem_id`, `version_number` (1, 2, 3...), `description`, `input_description`, `output_description`, `constraints`, `time_limit_ms` (VD: 1000), `memory_limit_kb` (VD: 262144), `changelog`, `created_by`, `created_at`.
3. **`test_cases`** (Gắn chặt với `problem_version_id`):
   - `id`, `problem_version_id`, `case_order`, `input_data`, `expected_output`, `is_sample` (Ví dụ công khai = 1), `is_hidden` (Dữ liệu chấm kín = 1), `points`, `created_at`.
4. **`submissions`** (Bài nộp của người học):
   - `id`, `user_id`, `classroom_id` (nullable), `problem_id`, `problem_version_id` (Gắn cứng phiên bản lúc nộp), `language` (`python`, `cpp`), `source_code`, `idempotency_key` (UNIQUE theo user), `latest_run_id` (Trỏ tới lần chấm mới nhất), `final_verdict` (`Pending`, `Running`, `AC`, `WA`, `TLE`, `MLE`, `RE`, `CE`, `SE`), `pass_count`, `total_cases`, `execution_time_ms`, `memory_used_kb`, `submitted_at`.
5. **`judging_runs`** (Lịch sử từng lần thực thi / chấm lại):
   - `id`, `submission_id`, `problem_version_id` (Phiên bản bộ dữ liệu dùng ở lần chấm này), `run_number` (Lần chấm 1, 2...), `triggered_by` (`submit`, `rejudge`, `retry`), `worker_id`, `status` (`Queued`, `Running`, `Completed`, `SystemError`, `Superseded`), `verdict` (`AC`, `WA`, `TLE`, `MLE`, `RE`, `CE`, `SE`), `passed_cases`, `total_cases`, `max_time_ms`, `max_memory_kb`, `compiler_message`, `system_error_log` (Chỉ Admin xem), `started_at`, `finished_at`.
6. **`case_results`** (Kết quả chi tiết từng ca kiểm thử của một lần chấm):
   - `id`, `judging_run_id`, `test_case_id`, `verdict` (`AC`, `WA`, `TLE`, `MLE`, `RE`, `SE`), `execution_time_ms`, `memory_used_kb`, `actual_output` (CHỈ lưu hoặc chỉ trả về cho sample case; ẩn hoàn toàn nếu `test_cases.is_hidden = 1`), `error_message` (stderr đã lọc đường dẫn nhạy cảm).

---

## 3. LỘ TRÌNH THỰC HIỆN CHI TIẾT (THEO TỪNG GIAI ĐOẠN)

### GIAI ĐOẠN 1: CƠ SỞ DỮ LIỆU & DỮ LIỆU MẪU HỢP LỆ (10 BÀI TẬP)
- [x] **1.1.** Tạo/cập nhật file `database/schema.sql` chứa toàn bộ các bảng ở Mục 2 với đầy đủ khóa ngoại (Foreign Keys) và chỉ mục (Indexes cho `idempotency_key`, `submission_id`, `problem_version_id`). *(Đã hoàn thành - 10 bảng chuẩn hóa InnoDB utf8mb4)*
- [x] **1.2.** Tạo file `database/seed.sql` (hoặc script `database/seed.php`) khởi tạo: *(Đã hoàn thành)*
  - 3 tài khoản mẫu: Người học (`learner1`, `learner2`), Giảng viên (`instructor1`), Quản trị thực thi (`admin1`) và 1 Lớp học mẫu (`CS101-2026`).
  - **10 bài tập lập trình** có ghi rõ nguồn gốc hợp lệ (CSES CC-BY-SA 4.0, Open Kattis MIT, Tự biên soạn MIT).
  - Mỗi bài tập có ít nhất **5 ca kiểm thử (`test_cases`)** (tổng 51 testcases: 20 ca công khai `is_sample = 1, is_hidden = 0` và 31 ca chấm kín `is_sample = 0, is_hidden = 1` phủ toàn bộ các trường hợp biên).
  - Lưu kèm bộ mã nguồn mẫu (Python & C++) cho các trường hợp: Đúng (`AC`), Sai biên (`WA`), Lỗi biên dịch (`CE`), Lỗi khi chạy (`RE`), Lặp vô hạn (`TLE`) trong thư mục `tests/sample_solutions/` và bộ giải 10 bài chuẩn trong `tests/sample_solutions/all_problems_ac/`. Toàn bộ đã nghiệm thu qua `tests/verify_phase1.php`.

### GIAI ĐOẠN 2: CORE ENGINE - ĐIỀU PHỐI CHẤM, SO SÁNH & CÁCH LY TIẾN TRÌNH
- [x] **2.1.** Cấu hình kết nối DB an toàn (`config/database.php`) và bộ hàm kiểm tra phân quyền, lọc dữ liệu nhạy cảm (`core/Auth.php`, `core/Security.php`). *(Đã hoàn thành - Bảo vệ dữ liệu kín, lọc path nhạy cảm, chống XSS/CSRF, kiểm soát quyền riêng tư)*
- [x] **2.2.** Xây dựng **Bộ so sánh đầu ra (`core/Comparator.php`)**: Chuẩn hóa khoảng trắng cuối dòng (trailing whitespace) và ký tự xuống dòng (`\r\n` vs `\n`), so sánh chính xác đầu ra của chương trình với `expected_output`. *(Đã hoàn thành)*
- [x] **2.3.** Xây dựng **Dịch vụ thực thi cách ly (`core/SandboxRunner.php`)**: *(Đã hoàn thành)*
  - Hỗ trợ **Python** và **C++** (thực thi và biên dịch trong thư mục cách ly `temp/runs/{run_id}/`).
  - Giám sát tiến trình độc lập, triệt tiêu blocking stream trên Windows bằng cơ chế tệp tạm stdout/stderr, giới hạn thời gian (Time Limit) và bộ nhớ/kích thước đầu ra.
  - Phân loại chính xác 100% 5 nhóm kết quả: `AC`, `WA`, `CE`, `RE`, `TLE` cùng lỗi hạ tầng `SE`.
- [x] **2.4.** Xây dựng **Worker Điều phối Hàng đợi Độc lập (`core/worker.php`)**: *(Đã hoàn thành)*
  - Chạy qua CLI (`php core/worker.php`) tách biệt hoàn toàn với tiến trình Web Apache.
  - Quét các bản ghi `judging_runs` có trạng thái `Queued`, khóa bản ghi nguyên tử chuyển sang `Running`.
  - Chạy qua từng `test_cases` của đúng `problem_version_id`, ghi vào `case_results` và tổng hợp kết quả cuối cùng vào `judging_runs`.
  - **Cơ chế chống ghi đè kết quả cũ (Stale Run Protection):** Hoạt động chuẩn xác, tự động gán cờ `Superseded` cho lần chấm cũ về muộn và bảo lưu nguyên vẹn trạng thái bài nộp mới nhất trên `submissions`. Đã nghiệm thu trọn vẹn qua `tests/verify_phase2.php`.

### GIAI ĐOẠN 3: HỆ THỐNG API BACKEND & BẢO VỆ DỮ LIỆU KÍN
- [ ] **3.1.** **`api/run_test.php` (Chạy thử với dữ liệu tự nhập & Lưu nháp):**
  - Cho phép người học lưu bản nháp (`code_drafts`) và đẩy yêu cầu chạy thử với custom input vào hàng đợi ưu tiên (hoặc worker thực thi cách ly), chỉ trả về `stdout`/`stderr` được giới hạn độ dài của dữ liệu tự nhập và sample testcases.
- [ ] **3.2.** **`api/submit.php` (Nhận bài nộp & Xử lý Idempotency):**
  - Kiểm tra `idempotency_key` từ client gửi lên. Nếu đã tồn tại trong `submissions` của user đó, trả về ngay `submission_id` cũ (HTTP 200) mà không tạo bản nộp mới.
  - Nếu là lần nộp mới hợp lệ: Lưu `submissions` gắn cứng với `current_version_id` của bài tập và ngôn ngữ đã chọn, đồng thời tạo bản ghi đầu tiên trong `judging_runs` (`status = 'Queued'`) để Worker xử lý.
- [ ] **3.3.** **`api/submission_status.php` (Theo dõi chấm thời gian thực & Bảo mật dữ liệu kín):**
  - Kiểm tra quyền truy cập: Người học chỉ xem được chi tiết mã nguồn và các lần chấm của chính mình; Giảng viên xem được của học viên trong lớp; Admin xem được toàn bộ.
  - **Tuyệt đối lọc bỏ:** Không bao giờ trả về `input_data`, `expected_output`, hay `actual_output` của các `test_cases` có `is_hidden = 1`. Đối với testcase kín, chỉ trả về số thứ tự ca kiểm thử, trạng thái (`AC`/`WA`/`TLE`/`RE`) và thời gian/bộ nhớ tiêu thụ.
- [ ] **3.4.** **`api/version_rejudge.php` (Tạo phiên bản bộ dữ liệu mới & Chấm lại):**
  - Dành cho Giảng viên/Admin: Khi sửa bộ dữ liệu kiểm tra hoặc giới hạn chạy, hệ thống tạo một bản ghi `problem_versions` mới (`version_number + 1`) cùng bộ `test_cases` mới, giữ nguyên vẹn phiên bản cũ.
  - Kích hoạt **Chấm lại (Rejudge)**: Tạo thêm một bản ghi `judging_runs` mới cho các `submissions` được chọn (có thể chọn chấm lại trên phiên bản mới hoặc phiên bản gốc), giữ nguyên mã nguồn đã nộp và toàn bộ lịch sử `judging_runs` trước đó để đối chiếu.

### GIAI ĐOẠN 4: GIAO DIỆN NGƯỜI DÙNG (UI/UX DARK GLASSMORPHISM)
Toàn bộ các trang sử dụng chung Left Sidebar (`includes/sidebar.php`) và hiệu ứng nền chuyển động Đen - Xám đậm - Cyan (`assets/css/style.css`):
- [ ] **4.1.** **Trang Chủ & Tổng quan (`index.php`)**: Thống kê nhanh tiến độ người học, trạng thái dịch vụ thực thi (Worker Online/Offline), danh sách bài tập nổi bật.
- [ ] **4.2.** **Thư viện Bài tập (`problems.php`)**: Bộ lọc theo Chủ đề, Độ khó (`Easy`, `Medium`, `Hard`), trạng thái đã giải, hiển thị phiên bản đề hiện tại (`v1`, `v2`...) và nguồn gốc bài tập.
- [ ] **4.3.** **Không gian Làm bài & Chấm trực tuyến (`solve.php`)** *(Tình huống sử dụng 1)*:
  - Cột trái: Đề bài, mô tả Input/Output, giới hạn thời gian/bộ nhớ, nhãn phiên bản bộ dữ liệu (`Dataset v1.0`), các ví dụ công khai có nút Copy, tab xem Lịch sử nộp của bài hiện tại.
  - Cột phải trên: Trình soạn thảo mã nguồn (hỗ trợ Python & C++), tự động lưu/khôi phục bản nháp (`Save Draft`), nút `Run Code` và `Submit` (kèm cơ chế sinh `idempotency_key` tự động).
  - Cột phải dưới (Console): Tab nhập dữ liệu chạy thử tùy chỉnh (`Custom Testcases`) và Tab theo dõi tiến trình chấm trực tiếp (`Execution & Judging Queue`) hiển thị trạng thái từng testcase theo thời gian thực.
- [ ] **4.4.** **Tra cứu Lịch sử Nộp bài & Tiến độ (`submissions.php`)**:
  - Danh sách bài nộp có bộ lọc theo bài tập, ngôn ngữ, kết quả, lớp học.
  - Modal/Trang chi tiết bài nộp: Xem lại mã nguồn (nếu đủ quyền), xem danh sách tất cả các lần chấm (`Run #1`, `Run #2`...) và bảng kết quả từng ca kiểm thử (ẩn dữ liệu của ca chấm kín).
- [ ] **4.5.** **Phân hệ Giảng viên & Quản trị Thực thi (`admin.php` hoặc `manage_problems.php` & `rejudge_compare.php`)** *(Tình huống sử dụng 2)*:
  - Giao diện tạo/sửa bài tập, quản lý các phiên bản bộ dữ liệu (`problem_versions`) và danh sách testcase công khai/ẩn.
  - Giao diện **Chấm lại & Đối chiếu (Rejudge & Compare)**: Cho phép chọn phiên bản bộ dữ liệu mới, bấm "Chấm lại tất cả bài nộp", và hiển thị **Bảng đối chiếu song song (Side-by-Side Comparison)** giữa Lần chấm trước (`Run #1 - Dataset v1`) và Lần chấm mới (`Run #2 - Dataset v2`) để thấy rõ sự thay đổi kết quả (VD: từ `AC` sang `WA` khi thêm testcase biên) mà vẫn giữ nguyên lịch sử.
  - Bảng điều khiển Dịch vụ Thực thi (cho Admin): Theo dõi trạng thái Worker, xem log lỗi hệ thống (`SE`) và nút thử lại các lượt chấm bị gián đoạn.

### GIAI ĐOẠN 5: 5 PHÉP THỬ TỰ ĐỘNG BẮT BUỘC & BÀN GIAO MÃ NGUỒN MỞ
- [ ] **5.1.** Xây dựng bộ kiểm thử tự động trong thư mục `tests/` (`tests/run_mandatory_tests.php`) kiểm tra và in báo cáo Pass/Fail cho đúng **5 phép thử bắt buộc ở Mục 6 của Đề bài**:
  1. **Phép thử 1 (Phân loại kết quả):** Tự động nộp 5 đoạn mã mẫu (Đúng, Sai biên, Lỗi cú pháp, Lỗi chia cho 0 khi chạy, Lặp vô hạn `while(true)`) và xác nhận nhận đúng 5 trạng thái khác nhau (`AC`, `WA`, `CE`, `RE`, `TLE`).
  2. **Phép thử 2 (Ngắt bộ thực thi):** Mô phỏng Worker/Sandbox bị ngắt giữa chừng và xác nhận kết quả trả về là `SE` (Lỗi hệ thống) hoặc `Queued` chờ thử lại, tuyệt đối không chấm người học `WA`/`RE`.
  3. **Phép thử 3 (Chống nộp trùng - Idempotency):** Gửi 2 request nộp bài liên tiếp có cùng `idempotency_key` $\rightarrow$ xác nhận chỉ tạo 1 bản ghi và trả về cùng `submission_id`; gửi request thứ 3 với key mới $\rightarrow$ tạo bản nộp mới.
  4. **Phép thử 4 (Chống ghi đè kết quả cũ về muộn):** Tạo `Run #1` và `Run #2` (Rejudge). Cho `Run #2` hoàn tất trước, sau đó mới cho `Run #1` trả kết quả về $\rightarrow$ xác nhận trạng thái của `submission` vẫn giữ kết quả của `Run #2`, đồng thời cả 2 lần chấm đều còn nguyên trong lịch sử `judging_runs`.
  5. **Phép thử 5 (Kiểm soát truy cập & Chống lộ dữ liệu kín):** Dùng tài khoản Người học A gọi API xem mã nguồn của Người học B $\rightarrow$ xác nhận bị từ chối (HTTP 403); gọi API lấy kết quả chấm $\rightarrow$ xác nhận JSON trả về không chứa bất kỳ chuỗi dữ liệu nào của testcase ẩn (`is_hidden = 1`) hay thông tin kết nối hệ thống.
- [ ] **5.2.** Hoàn thiện hồ sơ bàn giao Mã nguồn mở:
  - File `LICENSE` (MIT License) và ghi rõ nguồn gốc hợp lệ của 10 bài tập.
  - File `README.md` hướng dẫn chi tiết cách cài đặt CSDL, cách bật Web Server XAMPP, cách khởi chạy tiến trình Worker chấm bài độc lập (`php core/worker.php`), và cách chạy kịch bản kiểm thử tự động (`php tests/run_mandatory_tests.php`).

---
**Trạng thái hiện tại:** **Giai đoạn 1 & Giai đoạn 2 đã hoàn thành xuất sắc và vượt qua 100% kiểm thử nghiệm thu (`php tests/verify_phase1.php` & `php tests/verify_phase2.php`).** Sẵn sàng chuyển sang **Giai đoạn 3: HỆ THỐNG API BACKEND & BẢO VỆ DỮ LIỆU KÍN**.