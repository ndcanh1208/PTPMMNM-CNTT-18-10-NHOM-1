-- ==============================================================
-- DỮ LIỆU MẪU TINH GỌN (COMPACT SEED DATA)
-- Đề tài 20: Hệ thống luyện tập và chấm bài lập trình trực tuyến
-- Bao gồm 4 bài tập tiêu biểu (Số học, Số nguyên tố, Mảng, Chuỗi)
-- ==============================================================

USE `db_baitaplon`;

SET FOREIGN_KEY_CHECKS = 0;

-- Xóa dữ liệu các bảng nộp bài & nháp cũ (Làm sạch 100%)
TRUNCATE TABLE `case_results`;
TRUNCATE TABLE `judging_runs`;
TRUNCATE TABLE `submissions`;
TRUNCATE TABLE `draft_runs`;
TRUNCATE TABLE `code_drafts`;
TRUNCATE TABLE `class_problems`;
TRUNCATE TABLE `test_cases`;
TRUNCATE TABLE `problem_versions`;
TRUNCATE TABLE `problems`;
TRUNCATE TABLE `class_members`;
TRUNCATE TABLE `classes`;
TRUNCATE TABLE `languages`;
TRUNCATE TABLE `users`;

-- --------------------------------------------------------------
-- 1. TÀI KHOẢN MẪU (4 người dùng phân chia theo 3 vai trò)
-- Mật khẩu mặc định:
-- admin           -> admin123     (Vai trò: Quản trị viên)
-- gv_nguyenvana   -> teacher123   (Vai trò: Giảng viên)
-- sv_nguyenhoangnam -> student123 (Vai trò: Sinh viên 1)
-- sv_lethiminh    -> student123   (Vai trò: Sinh viên 2)
-- --------------------------------------------------------------
INSERT INTO `users` (`id`, `username`, `password_hash`, `full_name`, `email`, `role`) VALUES
(1, 'admin', '$2y$10$rzZQBoRhq1.FoWrDfpvoTONpfNv46TqNpt8arOJ94lLkbxPdZQSju', 'Quản Trị Viên Hệ Thống', 'admin@judge.edu.vn', 'ADMIN'),
(2, 'gv_nguyenvana', '$2y$10$aCYHFAnIV1GB25gUBK4nueTUAWhyP4RYqVMpZmAxR255lUqxEzY7C', 'TS. Nguyễn Văn A', 'nguyenvana@judge.edu.vn', 'LECTURER'),
(3, 'sv_nguyenhoangnam', '$2y$10$vxRkrIwNvvCVQutjIUn5yuj/bU9DUtDSGQEVP/g90vtNrGEjCPic6', 'Nguyễn Hoàng Nam (B20DCCN001)', 'namnh@student.edu.vn', 'STUDENT'),
(4, 'sv_lethiminh', '$2y$10$vxRkrIwNvvCVQutjIUn5yuj/bU9DUtDSGQEVP/g90vtNrGEjCPic6', 'Lê Thị Minh (B20DCCN002)', 'minhlt@student.edu.vn', 'STUDENT');

-- --------------------------------------------------------------
-- 2. CẤU HÌNH NGÔN NGỮ LẬP TRÌNH (Python 3 & C++ 17)
-- --------------------------------------------------------------
INSERT INTO `languages` (`id`, `name`, `source_ext`, `compile_cmd`, `run_cmd`, `is_active`) VALUES
('python3', 'Python 3', '.py', NULL, 'python "{source}"', 1),
('cpp', 'C++ 17 (GCC)', '.cpp', 'g++ -O2 "{source}" -o "{output}"', '"{output}"', 1);

-- --------------------------------------------------------------
-- 3. LỚP HỌC MẪU
-- --------------------------------------------------------------
INSERT INTO `classes` (`id`, `class_code`, `name`, `description`, `lecturer_id`) VALUES
(1, 'IT001_K15', 'Lớp Luyện Lập Trình - K15', 'Lớp rèn luyện lập trình và kiểm thử tự động trực tuyến', 2);

-- --------------------------------------------------------------
-- 4. THÀNH VIÊN LỚP HỌC
-- --------------------------------------------------------------
INSERT INTO `class_members` (`class_id`, `student_id`) VALUES
(1, 3),
(1, 4);

-- --------------------------------------------------------------
-- 5. DANH SÁCH 4 BÀI TẬP TIÊU BIỂU (PROBLEMS & VERSIONS)
-- --------------------------------------------------------------

-- BÀI 1: SUM_TWO_NUMBERS
INSERT INTO `problems` (`id`, `code`, `title`, `topic`, `difficulty`, `author_id`, `current_version_id`) 
VALUES (1, 'SUM_TWO_NUMBERS', 'Tính tổng hai số nguyên', 'Cơ bản', 'EASY', 2, 1);

INSERT INTO `problem_versions` (`id`, `problem_id`, `version_number`, `description`, `time_limit_ms`, `memory_limit_kb`, `change_log`, `created_by`) VALUES
(1, 1, 1, 'Cho hai số nguyên A và B (-10^9 <= A, B <= 10^9). Hãy tính tổng A + B.\n\nĐầu vào (stdin):\n- Một dòng duy nhất chứa hai số nguyên A và B cách nhau bởi dấu cách.\n\nĐầu ra (stdout):\n- In ra tổng A + B trên một dòng.', 1000, 131072, 'Phiên bản khởi tạo ban đầu v1.0', 2);

-- BÀI 2: PRIME_CHECK
INSERT INTO `problems` (`id`, `code`, `title`, `topic`, `difficulty`, `author_id`, `current_version_id`) 
VALUES (2, 'PRIME_CHECK', 'Kiểm tra số nguyên tố', 'Toán học', 'EASY', 2, 2);

INSERT INTO `problem_versions` (`id`, `problem_id`, `version_number`, `description`, `time_limit_ms`, `memory_limit_kb`, `change_log`, `created_by`) VALUES
(2, 2, 1, 'Cho số nguyên N (-10^6 <= N <= 10^6). Hãy kiểm tra xem N có phải là số nguyên tố hay không.\n\nĐầu vào (stdin):\n- Một dòng duy nhất chứa số nguyên N.\n\nĐầu ra (stdout):\n- In ra "YES" nếu N là số nguyên tố, ngược lại in "NO".', 1000, 131072, 'Phiên bản khởi tạo ban đầu v1.0', 2);

-- BÀI 3: REVERSE_ARRAY
INSERT INTO `problems` (`id`, `code`, `title`, `topic`, `difficulty`, `author_id`, `current_version_id`) 
VALUES (3, 'REVERSE_ARRAY', 'Đảo ngược mảng số nguyên', 'Mảng', 'EASY', 2, 3);

INSERT INTO `problem_versions` (`id`, `problem_id`, `version_number`, `description`, `time_limit_ms`, `memory_limit_kb`, `change_log`, `created_by`) VALUES
(3, 3, 1, 'Cho mảng gồm N số nguyên (1 <= N <= 1000). Hãy in ra mảng theo thứ tự đảo ngược.\n\nĐầu vào (stdin):\n- Dòng 1: Số nguyên N.\n- Dòng 2: N số nguyên cách nhau bởi dấu cách.\n\nĐầu ra (stdout):\n- Dãy số đảo ngược, các số cách nhau bởi dấu cách.', 1000, 131072, 'Phiên bản khởi tạo ban đầu v1.0', 2);

-- BÀI 4: PALINDROME_STRING
INSERT INTO `problems` (`id`, `code`, `title`, `topic`, `difficulty`, `author_id`, `current_version_id`) 
VALUES (4, 'PALINDROME_STRING', 'Kiểm tra chuỗi đối xứng', 'Chuỗi ký tự', 'EASY', 2, 4);

INSERT INTO `problem_versions` (`id`, `problem_id`, `version_number`, `description`, `time_limit_ms`, `memory_limit_kb`, `change_log`, `created_by`) VALUES
(4, 4, 1, 'Cho một xâu ký tự S (độ dài 1 <= |S| <= 10^5) chỉ gồm các chữ cái in thường. Hãy kiểm tra xem xâu S có phải là xâu đối xứng (Palindrome) hay không.\n\nĐầu vào (stdin):\n- Một dòng duy nhất chứa xâu S.\n\nĐầu ra (stdout):\n- In ra "YES" nếu S đối xứng, ngược lại in "NO".', 1000, 131072, 'Phiên bản khởi tạo ban đầu v1.0', 2);

-- Gán bài tập vào lớp học mẫu
INSERT INTO `class_problems` (`class_id`, `problem_id`) VALUES
(1, 1), (1, 2), (1, 3), (1, 4);

-- --------------------------------------------------------------
-- 6. BỘ CA KIỂM THỬ (TEST CASES) - 5 TEST CHO MỖI BÀI
-- --------------------------------------------------------------

-- Test cases Bài 1: SUM_TWO_NUMBERS (version 1)
INSERT INTO `test_cases` (`problem_version_id`, `input_data`, `expected_output`, `is_sample`, `score_weight`, `order_index`) VALUES
(1, "10 25", "35", 1, 20, 1),
(1, "0 0", "0", 1, 20, 2),
(1, "-5 5", "0", 0, 20, 3),
(1, "1000000000 1000000000", "2000000000", 0, 20, 4),
(1, "-450 -550", "-1000", 0, 20, 5);

-- Test cases Bài 2: PRIME_CHECK (version 2)
INSERT INTO `test_cases` (`problem_version_id`, `input_data`, `expected_output`, `is_sample`, `score_weight`, `order_index`) VALUES
(2, "7", "YES", 1, 20, 1),
(2, "4", "NO", 1, 20, 2),
(2, "1", "NO", 0, 20, 3),
(2, "2", "YES", 0, 20, 4),
(2, "97", "YES", 0, 20, 5);

-- Test cases Bài 3: REVERSE_ARRAY (version 3)
INSERT INTO `test_cases` (`problem_version_id`, `input_data`, `expected_output`, `is_sample`, `score_weight`, `order_index`) VALUES
(3, "5\n1 2 3 4 5", "5 4 3 2 1", 1, 20, 1),
(3, "3\n10 20 30", "30 20 10", 1, 20, 2),
(3, "1\n99", "99", 0, 20, 3),
(3, "4\n-1 -2 -3 -4", "-4 -3 -2 -1", 0, 20, 4),
(3, "5\n7 8 9 8 7", "7 8 9 8 7", 0, 20, 5);

-- Test cases Bài 4: PALINDROME_STRING (version 4)
INSERT INTO `test_cases` (`problem_version_id`, `input_data`, `expected_output`, `is_sample`, `score_weight`, `order_index`) VALUES
(4, "racecar", "YES", 1, 20, 1),
(4, "hello", "NO", 1, 20, 2),
(4, "a", "YES", 0, 20, 3),
(4, "abba", "YES", 0, 20, 4),
(4, "noon", "YES", 0, 20, 5);

SET FOREIGN_KEY_CHECKS = 1;
