# ByteFlow Online Judge

> **Hệ thống Luyện tập và Chấm bài Lập trình Trực tuyến**  
> Môn học: **Phát triển Phần mềm Mã nguồn mở** | **Đề tài số 20**  
> Nhóm thực hiện: **Nhóm 1 - Lớp CNTT 18-10**  
> Kho mã nguồn GitHub: [https://github.com/ndcanh1208/PTPMMNM-CNTT-18-10-NHOM-1](https://github.com/ndcanh1208/PTPMMNM-CNTT-18-10-NHOM-1)

---

## 1. Giới thiệu Tổng quan

**ByteFlow Online Judge** là nền tảng luyện tập thuật toán và chấm bài lập trình trực tuyến được xây dựng bằng kiến trúc mã nguồn mở hiện đại. Hệ thống được thiết kế hướng tới môi trường giảng dạy và tự học lập trình với giao diện **Dark Glassmorphism** (Neon Cyan `#00FFFF`, Nền đen `#050507`), hỗ trợ đa ngôn ngữ (**Python 3 & C++17**), quy trình xử lý không đồng bộ (Asynchronous Queue) và cơ chế chấm lại đối chiếu phiên bản nâng cao (Side-by-Side Rejudge Comparison).

### Hai tình huống sử dụng cốt lõi:
1. **Tình huống 1 (Người học):** Khám phá thư viện bài tập $\rightarrow$ Mở Workspace IDE $\rightarrow$ Tự động lưu bản nháp (`Save Draft`) $\rightarrow$ Chạy thử với bộ test tự chọn (`Custom Input`) $\rightarrow$ Nộp bài qua cơ chế chống nộp trùng $\rightarrow$ Theo dõi trạng thái chấm thời gian thực (Polling JSON API) $\rightarrow$ Xem kết quả chi tiết từng ca kiểm thử.
2. **Tình huống 2 (Giảng viên / Quản trị viên):** Quản lý đề bài $\rightarrow$ Cập nhật bộ dữ liệu kiểm tra & tạo phiên bản mới (`problem_versions v2, v3...`) $\rightarrow$ Kích hoạt chấm lại (`Rejudge`) $\rightarrow$ Đối chiếu song song hai lần chấm (`Run #1` vs `Run #2`) để đánh giá tác động của bộ test biên mà vẫn bảo lưu 100% lịch sử.

---

## 2. Kiến trúc Kỹ thuật & 5 Nguyên tắc Bắt buộc

ByteFlow tuân thủ nghiêm ngặt **5 Nguyên tắc Kỹ thuật Bắt buộc** trong đặc tả đề tài môn học:

| Nguyên tắc | Hiện thực trong ByteFlow | Tệp tin đảm nhiệm |
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
│   ├── auth.php                     # Đăng nhập, đăng xuất, kiểm tra phiên
│   ├── run_test.php                 # Lưu nháp & chạy thử custom testcase
│   ├── submit.php                   # Nộp bài chấm chính thức (kèm Idempotency Key)
│   ├── submission_status.php        # Truy vấn tiến trình chấm (Ẩn testcase kín)
│   └── version_rejudge.php          # Tạo phiên bản bộ test mới & Chấm lại (Rejudge)
├── assets/
│   └── css/
│       └── style.css                # Bộ định kiểu Dark Glassmorphism, Neon Cyan
├── config/
│   └── database.php                 # Kết nối CSDL MariaDB/MySQL qua PDO Singleton
├── core/                            # Lõi xử lý logic nghiệp vụ và bộ thực thi
│   ├── Auth.php                     # Quản lý phân quyền 3 cấp (Admin, Instructor, Learner)
│   ├── Comparator.php               # So khớp dữ liệu đầu ra chuẩn hóa khoảng trắng/dòng
│   ├── SandboxRunner.php            # Thực thi cách ly Python & G++ MinGW (cờ -static)
│   ├── Security.php                 # Bộ lọc bảo mật, XSS, CSRF, ẩn dữ liệu testcase kín
│   └── worker.php                   # Tiến trình Worker điều phối chấm bài nền độc lập
├── database/
│   ├── schema.sql                   # 10 bảng CSDL chuẩn hóa InnoDB utf8mb4
│   ├── seed.sql                     # Dữ liệu mẫu khởi tạo ban đầu
│   └── seed.php                     # Script PHP sinh CSDL & mật khẩu hash Bcrypt
├── includes/                        # Giao diện dùng chung
│   ├── header.php                   # Header, Navigation bar, FontAwesome, Tailwind
│   ├── sidebar.php                  # Thanh điều hướng cố định bên trái
│   └── footer.php                   # Chân trang & Thông tin bản quyền
├── tests/                           # Kịch bản kiểm thử tự động
│   ├── sample_solutions/            # 10 lời giải chuẩn AC và các mã mẫu lỗi (WA, CE, RE, TLE)
│   ├── verify_phase1.php            # Nghiệm thu Giai đoạn 1 (CSDL & Seed)
│   ├── verify_phase2.php            # Nghiệm thu Giai đoạn 2 (Core Sandbox & Worker)
│   ├── verify_phase3.php            # Nghiệm thu Giai đoạn 3 (Bộ API RESTful)
│   ├── verify_phase4.php            # Nghiệm thu Giai đoạn 4 (Giao diện người dùng)
│   └── run_mandatory_tests.php      # KỊCH BẢN 5 PHÉP THỬ TỰ ĐỘNG BẮT BUỘC (MỤC 6)
├── index.php                        # Trang chủ & Thống kê tổng quan hệ thống
├── login.php                        # Trang đăng nhập & chuyển đổi tài khoản mẫu
├── problems.php                     # Thư viện bài tập (Bộ lọc Chủ đề, Độ khó, Phiên bản)
├── solve.php                        # Workspace làm bài & IDE trực tuyến (Tình huống 1)
├── submissions.php                  # Lịch sử nộp bài & Bảng tiến trình chi tiết
├── manage_problems.php              # Phân hệ quản lý đề & bộ test cho Giảng viên
├── rejudge_compare.php              # Đối chiếu song song hai lần chấm (Tình huống 2)
├── LICENSE                          # Giấy phép MIT & Bản quyền 10 bài tập
├── MASTER_PLAN.md                   # Kế hoạch tổng thể và nhật ký phát triển 5 giai đoạn
└── README.md                        # Tài liệu hướng dẫn cài đặt và vận hành hệ thống
```

---

## 4. Yêu cầu Môi trường Hệ thống

- **Hệ điều hành:** Windows 10/11, Linux (Ubuntu/Debian) hoặc macOS.
- **Web Server:** Apache 2.4+ (Khuyên dùng XAMPP).
- **PHP:** Phiên bản 8.1 trở lên (đã kích hoạt extension `pdo_mysql`, `curl`, `mbstring`).
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
3. Nạp dữ liệu mẫu ban đầu (10 bài tập, 51 testcases và 4 tài khoản):
   ```powershell
   C:\xampp\php\php.exe database/seed.php
   ```

### Bước 3: Danh sách Tài khoản Mẫu để Nghiệm thu
Tất cả tài khoản đều sử dụng mật khẩu chung: `Password123@`

| Tên đăng nhập | Vai trò | Quyền hạn chính |
| :--- | :--- | :--- |
| `admin1` | Quản trị viên (Admin) | Quản trị toàn bộ hệ thống, xem log Worker, cấu hình đề bài |
| `instructor1` | Giảng viên (Instructor) | Quản lý đề bài, tạo phiên bản mới, chấm lại (Rejudge) và đối chiếu |
| `learner1` | Người học (Learner) | Giải bài tập, chạy thử custom input, nộp bài, xem lịch sử cá nhân |
| `learner2` | Người học (Learner) | Dùng để thử nghiệm phân quyền (Người học A không xem được mã của B) |

### Bước 4: Khởi chạy Worker Chấm bài Nền
Mở một cửa sổ Terminal/PowerShell riêng biệt để chạy Worker:
```powershell
C:\xampp\php\php.exe core/worker.php
```
*Worker sẽ liên tục lắng nghe hàng đợi `Queued`, khóa bản ghi an toàn (`Atomic Lock`), chấm bài trong môi trường Sandbox cách ly và cập nhật kết quả tự động.*

### Bước 5: Truy cập Giao diện Web
Mở trình duyệt web và truy cập:
- **Trang chủ:** [http://localhost/BaiTapLon/index.php](http://localhost/BaiTapLon/index.php)
- **Đăng nhập:** [http://localhost/BaiTapLon/login.php](http://localhost/BaiTapLon/login.php)
- **Thư viện bài tập:** [http://localhost/BaiTapLon/problems.php](http://localhost/BaiTapLon/problems.php)
- **Workspace giải bài:** [http://localhost/BaiTapLon/solve.php?slug=two-sum](http://localhost/BaiTapLon/solve.php?slug=two-sum)
- **Quản lý đề bài (Giảng viên):** [http://localhost/BaiTapLon/manage_problems.php](http://localhost/BaiTapLon/manage_problems.php)
- **Chấm lại & Đối chiếu:** [http://localhost/BaiTapLon/rejudge_compare.php](http://localhost/BaiTapLon/rejudge_compare.php)

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

Ngoài ra, có thể chạy các kịch bản nghiệm thu từng giai đoạn:
```powershell
C:\xampp\php\php.exe tests/verify_phase1.php   # Nghiệm thu CSDL & Seed
C:\xampp\php\php.exe tests/verify_phase2.php   # Nghiệm thu Sandbox & Worker
C:\xampp\php\php.exe tests/verify_phase3.php   # Nghiệm thu Bộ REST API
C:\xampp\php\php.exe tests/verify_phase4.php   # Nghiệm thu Giao diện & Phân quyền
```

---

## 7. Giấy phép & Bản quyền (License)

Dự án được phát hành theo giấy phép mã nguồn mở **[MIT License](LICENSE)**.  
Toàn bộ 10 bài tập thuật toán trong hệ thống được trích dẫn nguồn gốc học thuật hợp lệ từ **CSES Problem Set**, **Kattis Open Archive** và **MIT OpenCourseWare 6.006** phục vụ mục đích giảng dạy và nghiên cứu phi thương mại.
