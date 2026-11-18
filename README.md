# Nhom1 Online Judge

> **Hệ thống Luyện tập và Chấm bài Lập trình Trực tuyến**  
> Môn học: **Phát triển Phần mềm Mã nguồn mở** | **Đề tài số 20**  
> Nhóm thực hiện: **Nhóm 1 - Lớp CNTT 18-10**  
> Kho mã nguồn GitHub: [https://github.com/ndcanh1208/PTPMMNM-CNTT-18-10-NHOM-1](https://github.com/ndcanh1208/PTPMMNM-CNTT-18-10-NHOM-1)

---

## 1. Giới thiệu Tổng quan

**Nhom1 Online Judge** là nền tảng luyện tập thuật toán và chấm bài lập trình trực tuyến được xây dựng bằng kiến trúc mã nguồn mở hiện đại. Hệ thống được thiết kế hướng tới môi trường giảng dạy và tự học lập trình với giao diện **Dark Glassmorphism** (Neon Cyan `#00FFFF`, Nền đen `#050507`), hỗ trợ đa ngôn ngữ (**Python 3 & C++17**), quy trình xử lý không đồng bộ (Asynchronous Queue), thẩm định bộ test từ file ZIP/JSON 3 cấp độ và hệ thống quản trị phân quyền chính thức.

### Các nhóm chức năng chính:
1. **Sinh viên (Người học):** Đăng ký & đăng nhập chính thức (Username / Email / MSSV) $\rightarrow$ Khám phá thư viện bài tập $\rightarrow$ Mở Workspace IDE $\rightarrow$ Tự động lưu bản nháp (`Save Draft`) $\rightarrow$ Chạy thử (`Custom Input`) $\rightarrow$ Nộp bài qua cơ chế chống nộp trùng $\rightarrow$ Theo dõi trạng thái chấm thời gian thực và xem lịch sử bài nộp.
2. **Quản trị viên (Admin) & Giảng viên:**
   - **Quản lý Bài tập & Phiên bản đề:** Tạo, sửa, xóa mềm/khôi phục/xóa vĩnh viễn bài tập, phát hành phiên bản đề mới (`problem_versions v1, v2...`), phân quyền hiển thị (`public` / `hidden` / `exam_only`).
   - **Quản lý Bộ Test Cases:** Nhập hàng loạt từ file nén `.zip` (`.inp`/`.out`, `.in`/`.out`) hoặc `.json`, phân loại test mẫu công khai / test chấm kín, tự động chuẩn hóa ký tự xuống dòng Windows/Linux và đối soát lời giải chuẩn.
   - **Vận hành Chấm bài & Rejudge:** Giám sát hàng đợi thực thi cô lập Python & C++, kích hoạt Rejudge theo bài tập hoặc sinh viên cụ thể, đối chiếu song song các lần chấm và xử lý lỗi hệ thống (`SE`, `CE`, tiến trình treo).
   - **Quản lý Người dùng & Tài khoản:** Theo dõi danh sách sinh viên (MSSV, Lớp, tiến độ luyện tập), cấp/thu hồi quyền Admin & Giảng viên, khóa/mở khóa tài khoản vi phạm.
   - **Thống kê & Báo cáo Học tập:** Biểu đồ tỷ lệ `AC` / `WA` / `TLE` / `RE` / `CE` / `SE`, hiệu suất Python vs C++, xuất bảng điểm và lịch sử nộp bài ra **CSV (Excel UTF-8 BOM)** và **JSON**.

---

## 2. Kiến trúc Kỹ thuật & 5 Nguyên tắc Bắt buộc

Nhom1 tuân thủ nghiêm ngặt **5 Nguyên tắc Kỹ thuật Bắt buộc** trong đặc tả đề tài môn học:

| Nguyên tắc | Hiện thực trong Nhom1 | Tệp tin đảm nhiệm |
| :--- | :--- | :--- |
| **1. Tách tiến trình cách ly** | Web Apache chỉ nhận bài và đẩy vào hàng đợi CSDL (`Queued`). Tiến trình chấm độc lập chạy qua CLI Worker (`core/worker.php`), sandbox dùng file I/O tạm và diệt tiến trình con để chống treo Apache. | `core/worker.php`<br>`core/SandboxRunner.php` |
| **2. Chống nộp trùng (Idempotency)** | Mọi lượt nộp bài đều yêu cầu `idempotency_key` (UUIDv4). Nếu nhận cùng 1 key, hệ thống chỉ tạo 1 bản ghi duy nhất trong `submissions` và trả về kết quả cũ. | `api/submit.php`<br>`core/Security.php` |
| **3. Stale Run Protection** | Khi có nhiều lượt chấm (`Run #1`, `Run #2`), nếu kết quả cũ về muộn hơn lượt chấm mới, hệ thống đánh dấu `Superseded`, tuyệt đối không ghi đè vào bảng `submissions`. | `core/worker.php`<br>`api/version_rejudge.php` |
| **4. Quyền riêng tư & Bảo vệ dữ liệu kín** | Người học A tuyệt đối bị chặn (HTTP 403) khi xem mã của Người học B. Toàn bộ testcase kín (`is_hidden = 1`) được ẩn 100% thành `[DỮ LIỆU ĐƯỢC BẢO VỆ]`. | `core/Auth.php`<br>`core/Security.php` |
| **5. Phân loại lỗi chính xác** | Phân biệt rõ rệt lỗi bài làm của học viên (`AC`, `WA`, `TLE`, `MLE`, `RE`, `CE`) với lỗi sự cố môi trường (`SE`). Nếu sandbox bị crash/kill, hệ thống ghi nhận `SE`, không đổ lỗi cho học viên. | `core/Comparator.php`<br>`core/worker.php` |

---

## 3. Cấu trúc Thư mục Dự án

```text
BaiTapLon/
├── api/                             # Bộ REST API tương tác với giao diện Web
│   ├── auth.php                     # Đăng nhập, đăng ký, đăng xuất, kiểm tra phiên
│   ├── run_test.php                 # Lưu nháp & chạy thử custom testcase
│   ├── submit.php                   # Nộp bài chấm chính thức (kèm Idempotency Key)
│   ├── submission_status.php        # Truy vấn tiến trình chấm (Ẩn testcase kín)
│   ├── version_rejudge.php          # Tạo phiên bản bộ test mới & Chấm lại (Rejudge)
│   ├── import_testcases.php         # Nhập & thẩm định 3 cấp độ bộ test từ ZIP/JSON
│   ├── delete_problem.php           # Xóa mềm, khôi phục & xóa vĩnh viễn bài tập
│   ├── admin_judging.php            # Giám sát hàng đợi Python/C++, Rejudge & xử lý SE
│   ├── admin_users.php              # Cấp/thu hồi quyền, khóa/mở khóa & cập nhật hồ sơ
│   └── export_report.php            # Xuất báo cáo điểm & lịch sử nộp (CSV/Excel, JSON)
├── assets/
│   └── css/
│       └── style.css                # Bộ định kiểu Dark Glassmorphism, Neon Cyan
├── config/
│   └── database.php                 # Kết nối CSDL MariaDB/MySQL qua PDO Singleton
├── core/                            # Lõi xử lý logic nghiệp vụ và bộ thực thi
│   ├── Auth.php                     # Xác thực chính thức, đăng ký, đồng bộ khóa & vai trò
│   ├── Comparator.php               # So khớp dữ liệu đầu ra chuẩn hóa khoảng trắng/dòng
│   ├── SandboxRunner.php            # Thực thi cách ly Python & G++ MinGW (cờ -static)
│   ├── Security.php                 # Bộ lọc bảo mật, XSS, CSRF, ẩn dữ liệu testcase kín
│   └── worker.php                   # Tiến trình Worker điều phối chấm bài nền độc lập
├── database/
│   ├── schema.sql                   # 10 bảng CSDL chuẩn hóa InnoDB utf8mb4
│   ├── seed.sql                     # Dữ liệu khởi tạo ban đầu
│   └── seed.php                     # Script PHP khởi tạo CSDL tự động
├── examples/                        # Tệp bộ test mẫu ZIP & JSON để thử nghiệm nhập file
├── includes/                        # Giao diện dùng chung
│   ├── header.php                   # Header, Tailwind CSS, FontAwesome
│   ├── sidebar.php                  # Thanh điều hướng cố định bên trái
│   └── footer.php                   # Chân trang & Thông tin bản quyền
├── tests/                           # Kịch bản kiểm thử tự động toàn hệ thống
│   ├── sample_solutions/            # 10 lời giải chuẩn AC và các mã mẫu lỗi (WA, CE, RE, TLE)
│   ├── run_mandatory_tests.php      # Kịch bản 5 phép thử tự động bắt buộc (Mục 6)
│   ├── test_admin_full_suite.php    # Kiểm thử xác thực chính thức & 5 phân hệ Admin
│   ├── test_delete_problem.php      # Kiểm thử xóa mềm, khôi phục & xóa vĩnh viễn bài tập
│   └── test_import_testcases.php    # Kiểm thử nhập & thẩm định 3 cấp độ file ZIP/JSON
├── index.php                        # Trang tổng quan hệ thống
├── login.php                        # Trang đăng nhập chính thức
├── register.php                     # Trang đăng ký tài khoản sinh viên
├── problems.php                     # Thư viện bài tập (Bộ lọc Chủ đề, Độ khó, Trạng thái)
├── solve.php                        # Workspace làm bài & IDE trực tuyến
├── submissions.php                  # Lịch sử nộp bài & Bảng tiến trình chi tiết
├── manage_problems.php              # Quản lý bài tập, phiên bản đề & bộ test
├── rejudge_compare.php              # Đối chiếu song song hai lần chấm
├── admin_judging.php                # Quản lý chấm bài, hàng đợi Sandbox & Rejudge
├── admin_users.php                  # Quản lý sinh viên, phân quyền & khóa tài khoản
├── admin_analytics.php              # Thống kê tổng quan & xuất báo cáo kết quả học tập
├── LICENSE                          # Giấy phép MIT & Bản quyền 10 bài tập
└── README.md                        # Tài liệu hướng dẫn cài đặt và vận hành hệ thống
```

---

## 4. Yêu cầu Môi trường Hệ thống

- **Hệ điều hành:** Windows 10/11, Linux (Ubuntu/Debian) hoặc macOS.
- **Web Server:** Apache 2.4+ (Khuyên dùng XAMPP).
- **PHP:** Phiên bản 8.1 trở lên (đã kích hoạt extension `pdo_mysql`, `curl`, `mbstring`, `zip`).
- **Cơ sở dữ liệu:** MariaDB 10.4+ hoặc MySQL 8.0+.
- **Trình biên dịch & thông dịch:**
  - Python: Phiên bản 3.9+ (đã có trong PATH).
  - C++: GCC / G++ (MinGW-w64 trên Windows hoặc `g++` trên Linux, hỗ trợ cờ `-static` và chuẩn `-std=c++17`).

---

## 5. Hướng dẫn Cài đặt & Khởi chạy Từng bước

### Bước 1: Tải mã nguồn về máy
```bash
git clone https://github.com/ndcanh1208/PTPMMNM-CNTT-18-10-NHOM-1.git
```
Đặt thư mục dự án tại đường dẫn Web Server:
- **Windows (XAMPP):** `C:\xampp\htdocs\BaiTapLon`
- **Linux:** `/var/www/html/BaiTapLon`

### Bước 2: Thiết lập Cơ sở Dữ liệu
1. Khởi động dịch vụ **Apache** và **MySQL** trong **XAMPP Control Panel**.
2. Mở terminal / PowerShell và chạy lệnh tạo bảng:
   ```powershell
   cd C:\xampp\htdocs\BaiTapLon
   mysql -u root -e "CREATE DATABASE IF NOT EXISTS db_baitaplon CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
   Get-Content database/schema.sql -Raw -Encoding UTF8 | mysql -u root db_baitaplon
   ```
3. Nạp dữ liệu khởi tạo ban đầu (10 bài tập, 51 testcases, tài khoản quản trị & giảng viên gốc):
   ```powershell
   C:\xampp\php\php.exe database/seed.php
   ```
   *(Tài khoản sinh viên đăng ký trực tiếp tại trang `register.php`; Admin có thể nâng quyền giảng viên/admin và khóa/mở khóa tài khoản tại `admin_users.php`.)*

### Bước 3: Khởi chạy Worker Chấm bài Nền
Mở một cửa sổ Terminal/PowerShell riêng biệt để chạy Worker:
```powershell
C:\xampp\php\php.exe core/worker.php
```
*Worker sẽ liên tục lắng nghe hàng đợi `Queued`, khóa bản ghi an toàn (`Atomic Lock`), chấm bài trong môi trường Sandbox cách ly và cập nhật kết quả tự động.*

### Bước 4: Truy cập Giao diện Web
Mở trình duyệt web và truy cập:
- **Tổng quan:** [http://localhost/BaiTapLon/index.php](http://localhost/BaiTapLon/index.php)
- **Đăng nhập:** [http://localhost/BaiTapLon/login.php](http://localhost/BaiTapLon/login.php)
- **Đăng ký tài khoản:** [http://localhost/BaiTapLon/register.php](http://localhost/BaiTapLon/register.php)
- **Thư viện bài tập:** [http://localhost/BaiTapLon/problems.php](http://localhost/BaiTapLon/problems.php)
- **Quản lý đề & bộ test:** [http://localhost/BaiTapLon/manage_problems.php](http://localhost/BaiTapLon/manage_problems.php)
- **Vận hành chấm & Rejudge (Admin):** [http://localhost/BaiTapLon/admin_judging.php](http://localhost/BaiTapLon/admin_judging.php)
- **Quản lý người dùng (Admin):** [http://localhost/BaiTapLon/admin_users.php](http://localhost/BaiTapLon/admin_users.php)
- **Thống kê & Báo cáo (Admin):** [http://localhost/BaiTapLon/admin_analytics.php](http://localhost/BaiTapLon/admin_analytics.php)

---

## 6. Chạy Kiểm thử Tự động Nghiệm thu (5 Phép thử Bắt buộc)

Hệ thống tích hợp sẵn kịch bản kiểm thử độc lập bao phủ toàn bộ **5 phép thử bắt buộc tại Mục 6 của Đề bài**:

```powershell
C:\xampp\php\php.exe tests/run_mandatory_tests.php
```

### Bảng kết quả nghiệm thu 5 Phép thử:
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

---

## 7. Giấy phép & Bản quyền (License)

Dự án được phát hành theo giấy phép mã nguồn mở **[MIT License](LICENSE)**.  
Toàn bộ 10 bài tập thuật toán trong hệ thống được trích dẫn nguồn gốc học thuật hợp lệ từ **CSES Problem Set**, **Kattis Open Archive** và **MIT OpenCourseWare 6.006** phục vụ mục đích giảng dạy và nghiên cứu phi thương mại.
