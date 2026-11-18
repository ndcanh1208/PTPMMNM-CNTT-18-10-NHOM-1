# Nhom1 Online Judge

> **Hệ thống Luyện tập và Chấm bài Lập trình Trực tuyến**  
> Môn học: **Phát triển Phần mềm Mã nguồn mở** | **Đề tài số 20**  
> Nhóm thực hiện: **Nhóm 1 - Lớp CNTT 18-10**  
> Kho mã nguồn GitHub: [https://github.com/ndcanh1208/PTPMMNM-CNTT-18-10-NHOM-1](https://github.com/ndcanh1208/PTPMMNM-CNTT-18-10-NHOM-1)

---

## 1. Giới thiệu Tổng quan

**Nhom1 Online Judge** là nền tảng luyện tập thuật toán và chấm bài lập trình trực tuyến xây dựng bằng kiến trúc mã nguồn mở thuần PHP/MySQL. Hệ thống sử dụng giao diện **Dark Glassmorphism** (Neon Cyan `#00FFFF`, nền `#050507`), tích hợp trình soạn thảo **CodeMirror**, hỗ trợ đa ngôn ngữ (**Python 3 & C++17**), cơ chế chấm bài bất đồng bộ qua hàng đợi (Asynchronous Queue), xác thực chính thức kèm khôi phục mật khẩu bằng **mã OTP 6 chữ số gửi qua Email (SMTP TLS/SSL)** và bộ công cụ quản trị toàn diện.

### Các nhóm chức năng chính:
1. **Xác thực & Tài khoản chính thức:**
   - Đăng ký tài khoản sinh viên (Họ tên, Tên đăng nhập, Mã sinh viên, Lớp, Email, Mật khẩu — kiểm tra chống trùng lặp 2 lớp cho cả Username, Email và MSSV).
   - Đăng nhập chính thức bằng **Tên đăng nhập, Email hoặc Mã sinh viên**.
   - **Quên mật khẩu qua Email OTP:** Gửi mã OTP 6 chữ số (lưu dạng Bcrypt hash, hiệu lực 10 phút, chống spam 60 giây, tối đa 5 lần nhập sai, dùng 1 lần duy nhất) về Email qua SMTP Gmail.
2. **Sinh viên (Người học):**
   - Khám phá thư viện bài tập (lọc theo Chủ đề, Độ khó, Từ khóa, Trạng thái giải) → Mở Workspace IDE (CodeMirror) → Tự động lưu bản nháp (`Save Draft`) → Chạy thử với đầu vào tùy chọn (`Custom Input`) → Nộp bài chính thức qua cơ chế chống nộp trùng (`Idempotency Key`) → Theo dõi tiến trình chấm thời gian thực và xem lịch sử bài nộp.
3. **Quản trị viên (Admin) & Giảng viên:**
   - **Quản lý Bài tập & Phiên bản đề:** Thêm, sửa, xóa mềm/khôi phục (Thùng rác + tự giải phóng slug)/xóa vĩnh viễn (chỉ khi 0 bài nộp), phát hành phiên bản đề mới (`problem_versions v1, v2...`) bảo toàn lịch sử đề cũ, phân quyền hiển thị bài tập (`public` Công khai / `hidden` Ẩn bảo trì / `exam_only` Phòng thi riêng).
   - **Quản lý Bộ Test Cases:** Nhập nhiều test case khi tạo/sửa bài hoặc tải lên hàng loạt từ file `.zip` (`.inp`/`.out`, `.in`/`.out`, `input*.txt`/`output*.txt`) và `.json`; thẩm định 3 cấp độ (ghép cặp & bắt file mồ côi → chuẩn hóa dòng Windows/Linux & gỡ BOM → đối soát lời giải chuẩn Python/C++); phân loại loại trừ lẫn nhau giữa test mẫu (`is_sample = 1`) và test ẩn (`is_hidden = 1`).
   - **Vận hành Chấm bài & Rejudge:** Giám sát sức khỏe trình biên dịch Python & C++ và hàng đợi thực thi cô lập tách theo ngôn ngữ, kích hoạt **Rejudge** cho toàn bộ bài tập hoặc sinh viên cụ thể, đối chiếu song song 2 lần chấm (`Run #1` vs `Run #2`), xử lý lỗi hệ thống (`SE`, `CE`, giải phóng tiến trình treo).
   - **Quản lý Người dùng & Tài khoản:** Theo dõi danh sách sinh viên (MSSV, Lớp, tiến độ luyện tập), cấp/thu hồi quyền Admin & Giảng viên, khóa/mở khóa tài khoản vi phạm (hiệu lực tức thì).
   - **Thống kê & Báo cáo Học tập:** Tổng quan số bài tập, bài nộp, biểu đồ tỷ lệ `AC` / `WA` / `TLE` / `RE` / `CE` / `SE`, hiệu suất Python vs C++, xuất **Bảng điểm Tổng hợp Sinh viên** và **Lịch sử Nộp bài** ra định dạng **CSV (UTF-8 BOM cho Excel)** và **JSON**.

---

## 2. Kiến trúc Kỹ thuật & 5 Nguyên tắc Bắt buộc

Nhom1 tuân thủ nghiêm ngặt **5 Nguyên tắc Kỹ thuật Bắt buộc** của đề tài:

| Nguyên tắc | Hiện thực trong Nhom1 | Tệp tin đảm nhiệm |
| :--- | :--- | :--- |
| **1. Tách tiến trình cách ly** | Web Apache chỉ nhận bài và đẩy vào hàng đợi CSDL (`Queued`). Tiến trình chấm độc lập chạy qua CLI Worker (`core/worker.php`), sandbox dùng file I/O tạm và diệt cây tiến trình con để chống treo Apache. | `core/worker.php`<br>`core/SandboxRunner.php` |
| **2. Chống nộp trùng (Idempotency)** | Mọi lượt nộp bài đều yêu cầu `idempotency_key` (UUIDv4). Nếu nhận cùng 1 key, hệ thống chỉ tạo 1 bản ghi duy nhất trong `submissions` và trả về kết quả cũ. | `api/submit.php`<br>`core/Security.php` |
| **3. Stale Run Protection** | Khi có nhiều lượt chấm (`Run #1`, `Run #2`), nếu kết quả cũ về muộn hơn lượt chấm mới, hệ thống đánh dấu `Superseded`, tuyệt đối không ghi đè vào bảng `submissions`. | `core/worker.php`<br>`api/version_rejudge.php` |
| **4. Quyền riêng tư & Bảo vệ dữ liệu kín** | Người học A bị chặn (HTTP 403) khi xem mã của Người học B. Toàn bộ testcase kín (`is_hidden = 1`) được ẩn 100% thành `[DỮ LIỆU ĐƯỢC BẢO VỆ]`. | `core/Auth.php`<br>`core/Security.php` |
| **5. Phân loại lỗi chính xác** | Phân biệt rõ lỗi bài làm (`AC`, `WA`, `TLE`, `MLE`, `RE`, `CE`) với lỗi hạ tầng/môi trường (`SE`). Nếu sandbox bị crash/kill, hệ thống ghi nhận `SE`, không đổ lỗi cho học viên. | `core/Comparator.php`<br>`core/worker.php` |

---

## 3. Cấu trúc Thư mục Dự án

```text
BaiTapLon/
├── api/                                # Bộ REST API tương tác với giao diện Web
│   ├── auth.php                        # Đăng nhập, đăng ký, đăng xuất, kiểm tra phiên
│   ├── forgot_password.php             # Quên mật khẩu: sinh OTP, xác thực OTP & đặt mật khẩu mới
│   ├── run_test.php                    # Lưu nháp & chạy thử custom testcase
│   ├── submit.php                      # Nộp bài chấm chính thức (kèm Idempotency Key)
│   ├── submission_status.php           # Truy vấn tiến trình chấm (Ẩn testcase kín)
│   ├── version_rejudge.php             # Tạo phiên bản bộ test mới, quản lý test & Chấm lại
│   ├── import_testcases.php            # Nhập & thẩm định 3 cấp độ bộ test từ ZIP/JSON
│   ├── delete_problem.php              # Xóa mềm, khôi phục & xóa vĩnh viễn bài tập
│   ├── admin_judging.php               # Giám sát hàng đợi Python/C++, Rejudge & xử lý SE
│   ├── admin_users.php                 # Cấp/thu hồi quyền, khóa/mở khóa & cập nhật hồ sơ
│   └── export_report.php               # Xuất báo cáo điểm & lịch sử nộp (CSV/Excel, JSON)
├── assets/
│   ├── css/style.css                   # Bộ định kiểu Dark Glassmorphism, Neon Cyan
│   └── vendor/codemirror/              # Thư viện trình soạn thảo mã nguồn CodeMirror (Python/C++)
├── config/
│   ├── database.php                    # Kết nối CSDL MariaDB/MySQL qua PDO Singleton
│   ├── mail.php                        # Cấu hình dịch vụ gửi Email SMTP / Local Log
│   └── mail.local.php                  # (Tự tạo trên máy, nằm trong .gitignore) Chứa Gmail App Password
├── core/                               # Lõi xử lý nghiệp vụ và bộ thực thi
│   ├── Auth.php                        # Xác thực chính thức, đăng ký, đồng bộ khóa & phân quyền
│   ├── Comparator.php                  # So khớp dữ liệu đầu ra chuẩn hóa khoảng trắng/dòng
│   ├── Mailer.php                      # Dịch vụ gửi Email SMTP thuần PHP (TLS/SSL & Local Log)
│   ├── SandboxRunner.php               # Thực thi cách ly Python & G++ MinGW (cờ -static)
│   ├── Security.php                    # Bộ lọc bảo mật, XSS, CSRF, ẩn dữ liệu testcase kín
│   ├── WorkerLauncher.php              # Tự động kích hoạt Worker chạy ngầm khi có bài nộp/Rejudge
│   └── worker.php                      # Tiến trình Worker điều phối chấm bài nền độc lập (--drain / daemon)
├── database/
│   ├── schema.sql                      # 11 bảng CSDL chuẩn hóa InnoDB utf8mb4
│   ├── seed.sql                        # Dữ liệu khởi tạo ban đầu (10 bài tập, 51 testcases, admin & GV)
│   └── seed.php                        # Script PHP tự động tạo DB, bảng và nạp dữ liệu mẫu
├── examples/
│   ├── testcases_tim_so_lon_nhat.json  # File JSON mẫu để thử nghiệm nhập nhiều test case
│   └── testcases_tim_so_lon_nhat.zip   # File ZIP mẫu (.in/.out) để thử nghiệm thẩm định 3 cấp độ
├── includes/                           # Giao diện dùng chung
│   ├── header.php                      # Header, Tailwind CSS, FontAwesome
│   ├── sidebar.php                     # Thanh điều hướng cố định bên trái (phân quyền động)
│   └── footer.php                      # Chân trang & Thông tin bản quyền
├── temp/runs/                          # Thư mục tạm cho Sandbox chấm bài (tự dọn dẹp)
├── tests/                              # Bộ kịch bản kiểm thử tự động toàn hệ thống
│   ├── sample_solutions/               # 10 lời giải chuẩn AC (Python/C++) & mã mẫu lỗi (WA, CE, RE, TLE)
│   ├── run_mandatory_tests.php         # 5 phép thử tự động bắt buộc (Rubric Mục 6)
│   ├── test_admin_full_suite.php       # Kiểm thử xác thực chính thức & 5 phân hệ Admin
│   ├── test_forgot_password_otp.php    # Kiểm thử quên mật khẩu bằng mã OTP qua Email
│   ├── test_delete_problem.php         # Kiểm thử xóa mềm, khôi phục & xóa vĩnh viễn bài tập
│   ├── test_import_testcases.php       # Kiểm thử nhập & thẩm định 3 cấp độ file ZIP/JSON
│   ├── test_testcase_manager.php       # Kiểm thử sửa test case thông minh (In-place / Auto v2 Rejudge)
│   ├── test_mutual_exclusive_testcases.php # Kiểm thử loại trừ test công khai / chấm kín
│   ├── test_create_multiple_testcases.php  # Kiểm thử tạo bài tập với nhiều test cases ban đầu
│   ├── test_all_pages_http.php         # Kiểm thử HTTP & phân quyền toàn bộ tuyến trang web
│   └── verify_phase1..4.php            # Các kịch bản nghiệm thu từng giai đoạn
├── index.php                           # Trang tổng quan hệ thống
├── login.php                           # Trang đăng nhập chính thức
├── register.php                        # Trang đăng ký tài khoản sinh viên
├── forgot_password.php                 # Trang quên mật khẩu & xác thực mã OTP 3 bước
├── problems.php                        # Thư viện bài tập (Bộ lọc Chủ đề, Độ khó, Trạng thái)
├── solve.php                           # Workspace làm bài & IDE trực tuyến
├── submissions.php                     # Lịch sử nộp bài & Bảng tiến trình chi tiết
├── manage_problems.php                 # Quản lý bài tập, phiên bản đề, thùng rác & bộ test
├── rejudge_compare.php                 # Đối chiếu song song hai lần chấm
├── admin_judging.php                   # Quản lý chấm bài, hàng đợi Sandbox & Rejudge (Admin)
├── admin_users.php                     # Quản lý sinh viên, phân quyền & khóa tài khoản (Admin)
├── admin_analytics.php                 # Thống kê tổng quan & xuất báo cáo kết quả học tập (Admin)
├── LICENSE                             # Giấy phép MIT & Bản quyền 10 bài tập
└── README.md                           # Tài liệu hướng dẫn cài đặt và vận hành hệ thống
```

---

## 4. Yêu cầu Môi trường Hệ thống

- **Hệ điều hành:** Windows 10/11, Linux (Ubuntu/Debian) hoặc macOS.
- **Web Server:** Apache 2.4+ (Khuyên dùng XAMPP).
- **PHP:** Phiên bản 8.1 trở lên (đã kích hoạt các extension: `pdo_mysql`, `curl`, `mbstring`, `zip`, `openssl`).
- **Cơ sở dữ liệu:** MariaDB 10.4+ hoặc MySQL 8.0+.
- **Trình biên dịch & thông dịch:**
  - Python: Phiên bản 3.9+ (đã có trong PATH).
  - C++: GCC / G++ (MinGW-w64 trên Windows hoặc `g++` trên Linux, hỗ trợ cờ `-static` và chuẩn `-std=c++17`).

---

## 5. Hướng dẫn Cài đặt & Khởi chạy Từng bước

### Bước 1: Tải mã nguồn về máy
```bash
git clone https://github.com/ndcanh1208/PTPMMNM-CNTT-18-10-NHOM-1.git BaiTapLon
```
Đặt thư mục `BaiTapLon` tại đường dẫn Web Server:
- **Windows (XAMPP):** `C:\xampp\htdocs\BaiTapLon`
- **Linux:** `/var/www/html/BaiTapLon`

### Bước 2: Bật các Extension PHP cần thiết (XAMPP)
Mở `C:\xampp\php\php.ini`, đảm bảo đã bỏ dấu `;` ở các dòng sau rồi khởi động lại Apache & MySQL:
```ini
extension=zip
extension=openssl
extension=pdo_mysql
extension=curl
extension=mbstring
```

### Bước 3: Khởi tạo Cơ sở Dữ liệu
Chạy lệnh sau để tự động tạo cơ sở dữ liệu `db_baitaplon`, tạo đủ 11 bảng từ `database/schema.sql` và nạp dữ liệu khởi tạo ban đầu (10 bài tập chuẩn, 51 testcases, tài khoản Quản trị viên `admin1` & Giảng viên `instructor1`):
```powershell
cd C:\xampp\htdocs\BaiTapLon
C:\xampp\php\php.exe database/seed.php
```
*(Tài khoản sinh viên đăng ký trực tiếp tại trang `register.php`. Quản trị viên có thể cấp/thu hồi quyền Giảng viên/Admin hoặc khóa/mở khóa tài khoản tại `admin_users.php`.)*

### Bước 4: Cấu hình Gửi Email OTP qua Gmail SMTP (Tùy chọn)
- Nếu chưa cấu hình SMTP, hệ thống tự hoạt động ở chế độ **Local Log** và lưu email OTP vào `temp/mail_logs/last_otp.html` để thử nghiệm nội bộ.
- Để gửi mã OTP về hộp thư Gmail thật, tạo tệp `config/mail.local.php` *(được bảo vệ trong `.gitignore`, không đẩy lên GitHub)*:
  ```php
  <?php
  define('MAIL_HOST', 'smtp.gmail.com');
  define('MAIL_PORT', 587);
  define('MAIL_ENCRYPTION', 'tls');
  define('MAIL_USERNAME', 'email_gui_cua_ban@gmail.com');
  define('MAIL_PASSWORD', 'xxxx xxxx xxxx xxxx'); // Mật khẩu ứng dụng Gmail (App Password 16 ký tự)
  define('MAIL_FROM_EMAIL', 'email_gui_cua_ban@gmail.com');
  define('MAIL_FROM_NAME', 'Nhom1 Online Judge');
  ```

### Bước 5: Worker Chấm bài Nền (Tự động kích hoạt — Không cần mở terminal)
Hệ thống đã tích hợp **`core/WorkerLauncher.php`**: ngay khi sinh viên bấm **Nộp bài** hoặc Admin bấm **Rejudge**, tiến trình `core/worker.php --drain` sẽ **tự động được kích hoạt chạy ngầm tách biệt khỏi Apache**, chấm sạch toàn bộ hàng đợi rồi tự thoát an toàn.

*(Tùy chọn: Nếu muốn xem nhật ký chấm trực tiếp theo thời gian thực trên màn hình dòng lệnh, bạn vẫn có thể tự mở terminal chạy `C:\xampp\php\php.exe core/worker.php`.)*

### Bước 6: Truy cập Giao diện Web
Mở trình duyệt web và truy cập:
- **Tổng quan:** [http://localhost/BaiTapLon/index.php](http://localhost/BaiTapLon/index.php)
- **Đăng nhập:** [http://localhost/BaiTapLon/login.php](http://localhost/BaiTapLon/login.php)
- **Đăng ký tài khoản:** [http://localhost/BaiTapLon/register.php](http://localhost/BaiTapLon/register.php)
- **Quên mật khẩu (OTP):** [http://localhost/BaiTapLon/forgot_password.php](http://localhost/BaiTapLon/forgot_password.php)
- **Thư viện bài tập:** [http://localhost/BaiTapLon/problems.php](http://localhost/BaiTapLon/problems.php)
- **Quản lý đề & bộ test:** [http://localhost/BaiTapLon/manage_problems.php](http://localhost/BaiTapLon/manage_problems.php)
- **Vận hành chấm & Rejudge (Admin):** [http://localhost/BaiTapLon/admin_judging.php](http://localhost/BaiTapLon/admin_judging.php)
- **Quản lý người dùng (Admin):** [http://localhost/BaiTapLon/admin_users.php](http://localhost/BaiTapLon/admin_users.php)
- **Thống kê & Báo cáo (Admin):** [http://localhost/BaiTapLon/admin_analytics.php](http://localhost/BaiTapLon/admin_analytics.php)

---

## 6. Chạy Kiểm thử Tự động Toàn hệ thống

### 5 Phép thử Bắt buộc (Rubric Mục 6):
```powershell
C:\xampp\php\php.exe tests/run_mandatory_tests.php
```

```text
========================================================================================
   TỔNG KẾT BÁO CÁO ĐÁNH GIÁ 5 PHÉP THỬ BẮT BUỘC (MỤC 6 RUBRIC BÀI TẬP LỚN)
========================================================================================
   1. Phép thử 1 (Phân loại 5 kết quả AC, WA, CE, RE, TLE) : ĐẠT (PASS 100%)
   2. Phép thử 2 (Ngắt bộ thực thi / Lỗi hệ thống ghi SE)   : ĐẠT (PASS 100%)
   3. Phép thử 3 (Chống nộp trùng bằng Idempotency Key)     : ĐẠT (PASS 100%)
   4. Phép thử 4 (Chống ghi đè kết quả cũ về muộn)          : ĐẠT (PASS 100%)
   5. Phép thử 5 (Quyền riêng tư & Bảo vệ testcase kín)     : ĐẠT (PASS 100%)
----------------------------------------------------------------------------------------
   KẾT QUẢ CHUNG CUỘC: 5/5 PHÉP THỬ ĐẠT CHUẨN XUẤT SẮC (100%)
========================================================================================
```

### Các bộ kiểm thử chức năng mở rộng:
```powershell
C:\xampp\php\php.exe tests/test_admin_full_suite.php      # Xác thực chính thức & 5 phân hệ Admin
C:\xampp\php\php.exe tests/test_forgot_password_otp.php   # Quên mật khẩu & xác thực OTP qua Email
C:\xampp\php\php.exe tests/test_delete_problem.php        # Xóa mềm, khôi phục & xóa vĩnh viễn bài tập
C:\xampp\php\php.exe tests/test_import_testcases.php      # Nhập & thẩm định 3 cấp độ bộ test ZIP/JSON
C:\xampp\php\php.exe tests/test_testcase_manager.php      # Sửa test case thông minh & tự động Rejudge
```

---

## 7. Giấy phép & Bản quyền (License)

Dự án được phát hành theo giấy phép mã nguồn mở **[MIT License](LICENSE)**.  
Toàn bộ 10 bài tập thuật toán trong hệ thống được trích dẫn nguồn gốc học thuật hợp lệ từ **CSES Problem Set**, **Kattis Open Archive** và **MIT OpenCourseWare 6.006** phục vụ mục đích giảng dạy và nghiên cứu phi thương mại.
