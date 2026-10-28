# HỆ THỐNG LUYỆN TẬP VÀ CHẤM BÀI LẬP TRÌNH TRỰC TUYẾN (ONLINE JUDGE PLATFORM)

> **Môn học**: Phát triển phần mềm mã nguồn mở (PTPMMNM)  
> **Lớp**: CNTT 18-10 &bull; **Nhóm**: Nhóm 1  
> **Đề tài số 20**: Xây dựng hệ thống luyện tập và chấm bài lập trình trực tuyến  
> **Mã nguồn GitHub**: [https://github.com/ndcanh1208/PTPMMNM-CNTT-18-10-NHOM-1](https://github.com/ndcanh1208/PTPMMNM-CNTT-18-10-NHOM-1)  
> **Giấy phép bản quyền**: [MIT License](LICENSE)

---

## 📖 1. Giới Thiệu Đề Tài

Hệ thống cho phép người học chọn bài tập thuật toán, viết mã trực tiếp trên trình duyệt, chạy thử với dữ liệu tự nhập và nộp bài để hệ thống tự động chấm điểm. Giảng viên có thể quản lý đề bài, cập nhật các phiên bản bộ dữ liệu kiểm tra (test cases) và thực hiện chấm lại (rejudge) đối chiếu kết quả mà vẫn giữ nguyên lịch sử.

Hệ thống tuân thủ nghiêm ngặt các yêu cầu kỹ thuật:
* **Tách bộ thực thi khỏi tiến trình Web**: Tuyệt đối không chạy mã người học trong máy chủ Web/PHP.
* **Tự viết bộ điều phối chấm điểm**: So sánh đầu ra 3 bước chuẩn hóa và tổng hợp điểm số.
* **Chuẩn hóa 7 mã trạng thái**: `PENDING`, `RUNNING`, `AC`, `WA`, `CE`, `RE`, `TLE`, `SE`.
* **Cơ chế chống nộp trùng**: Nhận diện qua khóa định danh `idempotency_key` (Client UUID).
* **Bảo vệ dữ liệu chấm kín**: API tuyệt đối không trả dữ liệu test kín, đáp án hoặc đầu ra thực tế về trình duyệt học viên.

---

## 📜 2. Nguồn Sử Dụng & Giấy Phép Thư Viện Mã Nguồn Mở (Third-Party Licenses)

Dự án tuân thủ đầy đủ các quy định về sử dụng mã nguồn mở và ghi nhận bản quyền của tác giả:

| Tên Thư Viện / Dịch Vụ | Mục Đích Sử Dụng | Giấy Phép (License) | Nguồn / Trang Chủ |
| :--- | :--- | :--- | :--- |
| **Monaco Editor** | Trình soạn thảo mã nguồn trên trình duyệt (VS Code engine), hỗ trợ Syntax Highlight Python/C++ | **MIT License** | [github.com/microsoft/monaco-editor](https://github.com/microsoft/monaco-editor) |
| **CodeMirror 5** | Trình soạn thảo mã nguồn siêu tốc nạp offline nội bộ (2ms), Syntax Highlight Python/C++, giao diện Dracula | **MIT License** | [codemirror.net/5](https://codemirror.net/5/) |
| **Piston API** | Dịch vụ thực thi mã cách ly trong môi trường Sandbox bảo mật | **MIT License** | [github.com/engineer-man/piston](https://github.com/engineer-man/piston) |
| **Bootstrap 5** | Khung giao diện Responsive, lưới layout, Modal và Tabs | **MIT License** | [getbootstrap.com](https://getbootstrap.com/) |
| **Font Awesome 6** | Bộ biểu tượng giao diện trực quan | **Icons: CC BY 4.0, Fonts: SIL OFL 1.1** | [fontawesome.com](https://fontawesome.com/) |
| **Plus Jakarta Sans** | Phông chữ hiện đại giao diện người dùng | **SIL Open Font License 1.1** | [Google Fonts](https://fonts.google.com/specimen/Plus+Jakarta+Sans) |
| **JetBrains Mono** | Phông chữ đơn cách (Monospace) cho khung soạn thảo code | **SIL Open Font License 1.1** | [Google Fonts](https://fonts.google.com/specimen/JetBrains+Mono) |

---

## 🏛 3. Kiến Trúc Kỹ Thuật Hệ Thống

```text
BaiTapLon/
├── api/                        # Cổng API Backend
│   ├── run_draft.php           # Chạy thử code với input tự nhập (tách biệt)
│   ├── submit.php              # Nhận bài nộp, kiểm tra idempotency_key và chấm
│   ├── get_submission.php      # Lấy kết quả chấm (Bảo vệ dữ liệu test kín)
│   └── save_draft.php          # Tự động lưu bản nháp của học viên
├── config/                     # Cấu hình hệ thống
│   ├── config.php              # Khai báo endpoint Piston, 7 mã trạng thái, timeouts
│   └── db.php                  # Kết nối PDO MySQL Singleton
├── core/                       # Bộ lõi điều phối & chấm điểm tự viết
│   ├── OutputComparator.php    # Lớp tự viết so sánh đầu ra 3 bước chuẩn xác
│   ├── ExecutionService.php    # Kết nối thực thi Piston Docker & phân loại 7 trạng thái
│   └── JudgeCoordinator.php    # Tự điều phối quy trình chấm, lưu lịch sử lượt chấm
├── database/                   # Cơ sở dữ liệu Database First
│   ├── schema.sql              # Cấu trúc 13 bảng InnoDB chuẩn hóa 3NF
│   ├── seed_data.sql           # 10 bài tập, 50+ test cases, tài khoản Admin/GV/SV
│   ├── install.php             # Trình cài đặt tự động 1-Click
│   └── sample_solutions/       # Mã nguồn mẫu đối chiếu (AC, WA, TLE, RE, CE)
├── includes/                   # Các thành phần giao diện tái sử dụng
│   ├── header.php              # Nạp CDN CSS & Bootstrap JS
│   ├── navbar.php              # Thanh điều hướng và chỉ báo trạng thái
│   └── footer.php              # Chân trang bản quyền
├── tests/                      # Kịch bản kiểm thử tự động (Unit / Integration Tests)
│   ├── test_comparator.php     # Kiểm thử tự động thuật toán so sánh 3 bước
│   ├── test_execution.php      # Kiểm thử kết nối bộ thực thi cách ly
│   └── test_run_draft.php      # Kiểm thử chạy thử code tự nhập
├── index.php                   # Trang chủ tổng quan hệ thống
├── problems.php                # Danh sách bài tập, bộ lọc độ khó/chủ đề
├── solve.php                   # Giao diện luyện tập tích hợp Monaco Editor
├── submissions.php             # Lịch sử bài nộp & tra cứu tiến độ học viên
├── .gitignore                  # Bỏ qua tệp rác / IDE
├── LICENSE                     # Giấy phép MIT License
└── README.md                   # Tài liệu hướng dẫn toàn diện
```

---

## ⚖️ 4. Quy Chuẩn Kỹ Thuật Bắt Buộc

### 1. Quy tắc so sánh đầu ra 3 bước (`OutputComparator`)
1. **Bước 1**: Đổi toàn bộ ký tự xuống dòng `\r\n` (Windows) thành `\n` (Linux).
2. **Bước 2**: Tách từng dòng và cắt bỏ khoảng trắng thừa (`trailing spaces`) ở cuối mỗi dòng bằng `rtrim($line, " \t")`.
3. **Bước 3**: Cắt bỏ các dòng trống thừa ở cuối cùng của toàn bộ chuỗi (`rtrim`), sau đó so sánh bằng tuyệt đối (`===`).

### 2. Chuẩn hóa 7 mã trạng thái chấm điểm
| Mã | Tên Đầy Đủ | Ý Nghĩa Kỹ Thuật |
| :---: | :--- | :--- |
| **PENDING** | Pending | Bài nộp đang nằm trong hàng đợi chờ chấm |
| **RUNNING** | Running | Bộ điều phối đang thực thi chấm các test cases |
| **AC** | Accepted | Chương trình chạy đúng 100% tất cả các test case |
| **WA** | Wrong Answer | Kết quả đầu ra không khớp sau khi so sánh 3 bước |
| **CE** | Compilation Error | Lỗi cú pháp / biên dịch (bắt lỗi từ `g++` hoặc Python syntax) |
| **RE** | Runtime Error | Chương trình bị văng ngoại lệ lúc chạy (chia cho 0, segfault...) |
| **TLE** | Time Limit Exceeded | Chạy quá giới hạn thời gian (xử lý vòng lặp vô hạn) |
| **SE** | System Error | Dịch vụ thực thi cách ly bị ngắt kết nối hoặc sập máy chủ. **Tuyệt đối không đánh trượt học sinh** |

### 3. Cơ chế chống nộp trùng (Idempotency Key)
Client tự động sinh chuỗi UUID duy nhất (`idempotency_key`) gắn vào mỗi yêu cầu nộp:
* Nếu bấm gửi lại (mạng retry hoặc double click): Server nhận diện trùng khóa $\rightarrow$ **trả về ngay ID bài nộp cũ**, không tạo bản ghi mới.
* Một yêu cầu nộp mới có chủ ý sẽ tạo UUID mới $\rightarrow$ tạo bài nộp mới.

---

## 🚀 5. Hướng Dẫn Cài Đặt & Chạy Hệ Thống

### Yêu Cầu Môi Trường
* **XAMPP**: Phiên bản hỗ trợ PHP 8.1+ và MySQL/MariaDB.
* **Trình duyệt**: Chrome, Edge, Firefox, Brave,...
* *(Tùy chọn)* **Docker Desktop**: Nếu muốn chạy Piston container cục bộ.

### Các Bước Cài Đặt (5 Phút)

#### Bước 1: Tải mã nguồn về máy
Đặt thư mục dự án vào thư mục `htdocs` của XAMPP:
```bash
cd C:\xampp\htdocs
git clone https://github.com/ndcanh1208/PTPMMNM-CNTT-18-10-NHOM-1.git BaiTapLon
```

#### Bước 2: Bật dịch vụ trong XAMPP Control Panel
* Nhấn nút **Start** ở dòng **Apache**.
* Nhấn nút **Start** ở dòng **MySQL**.

#### Bước 3: Khởi tạo Cơ sở dữ liệu tự động (1-Click)
Mở trình duyệt web và truy cập địa chỉ:
👉 **`http://localhost/BaiTapLon/database/install.php`**

Hệ thống sẽ tự động:
1. Tạo CSDL `db_baitaplon` (`utf8mb4_unicode_ci`).
2. Khởi tạo 13 bảng chuẩn hóa.
3. Nạp sẵn 10 bài tập thuật toán mẫu cùng hơn 50 bộ test cases và tài khoản mẫu.

#### Bước 4: (Tùy chọn) Chạy Piston Docker Container độc lập
Nếu máy bạn có cài Docker, hãy mở terminal chạy lệnh sau:
```bash
docker run -d -p 2000:2000 --name piston-sandbox --restart always ghcr.io/engineer-man/piston
```
*(Nếu chưa cài Docker, hệ thống đã tích hợp sẵn chế độ Mock Sandbox dự phòng thông minh trong `config/config.php` để phục vụ demo đồ án ngay trên XAMPP mà không lo gián đoạn).*

#### Bước 5: Trải nghiệm hệ thống
* **Trang chủ**: `http://localhost/BaiTapLon/`
* **Danh sách bài tập**: `http://localhost/BaiTapLon/problems.php`
* **Soạn code & Chạy thử**: `http://localhost/BaiTapLon/solve.php?id=1`
* **Tra cứu lịch sử nộp**: `http://localhost/BaiTapLon/submissions.php`

---

## 👥 6. Tài Khoản Mẫu Để Đăng Nhập & Chấm Thi

| Tên Đăng Nhập | Mật Khẩu | Vai Trò (Role) | Chức Năng Chính |
| :--- | :--- | :--- | :--- |
| **`admin`** | `admin123` | Quản trị viên (ADMIN) | Quản lý toàn bộ hệ thống, cấu hình ngôn ngữ |
| **`gv_nguyenvana`** | `teacher123` | Giảng viên (LECTURER) | Quản lý bài tập, tạo phiên bản đề mới, chấm lại |
| **`sv_nguyenhoangnam`** | `student123` | Sinh viên (STUDENT) | Làm bài tập, chạy thử dữ liệu tự nhập, nộp bài |
| **`sv_lethiminh`** | `student123` | Sinh viên (STUDENT) | Làm bài tập, tra cứu tiến độ cá nhân |

---

## 📋 7. Quản Lý Phát Triển Theo 5 Chức Năng Đề Bài (GitHub Issues)

Dự án phân chia các Issue tương ứng với 5 chức năng trong mục 2 của đề bài:

1. **Issue #1**: Quản lý bài tập, chủ đề, mức độ, giới hạn chạy và phiên bản bộ dữ liệu (`problems`, `problem_versions`, `test_cases`).
2. **Issue #2**: Khung soạn mã Monaco Editor trên trình duyệt, lưu bản nháp tự động và tính năng chạy thử dữ liệu tự nhập (`code_drafts`, `draft_runs`).
3. **Issue #3**: Nhận bài nộp, hàng đợi chấm, bộ điều phối tự viết và so sánh đầu ra 3 bước (`submissions`, `judging_runs`, `case_results`).
4. **Issue #4**: Tra cứu mã đã nộp, lịch sử kết quả, tiến độ học viên và phân quyền theo lớp học (`classes`, `class_members`).
5. **Issue #5**: Quản lý phiên bản đề bài, chấm lại (Rejudge) khi sửa test và đối chiếu lịch sử 2 lần chấm.
6. **Issue #6**: Tách bộ thực thi mã nguồn cách ly (Piston API Sandbox) và phát hiện lỗi hệ thống (`SYSTEM_ERROR`).
7. **Issue #7**: Kiểm thử tự động đối chiếu các phép thử bắt buộc (AC, WA, TLE, RE, CE, SE).
