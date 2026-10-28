-- ==============================================================
-- DỮ LIỆU MẪU KHỞI TẠO (SEED DATA)
-- Đề tài 20: 10 bài tập, mỗi bài ít nhất 5 test case, người dùng & lớp học
-- ==============================================================

USE `db_baitaplon`;

SET FOREIGN_KEY_CHECKS = 0;

-- --------------------------------------------------------------
-- 1. TÀI KHOẢN MẪU
-- Passwords:
-- admin / admin123
-- gv_nguyenvana / teacher123
-- sv_nguyenhoangnam / student123
-- sv_lethiminh / student123
-- --------------------------------------------------------------
INSERT INTO `users` (`id`, `username`, `password_hash`, `full_name`, `email`, `role`) VALUES
(1, 'admin', '$2y$10$rzZQBoRhq1.FoWrDfpvoTONpfNv46TqNpt8arOJ94lLkbxPdZQSju', 'Quản Trị Viên Hệ Thống', 'admin@judge.edu.vn', 'ADMIN'),
(2, 'gv_nguyenvana', '$2y$10$aCYHFAnIV1GB25gUBK4nueTUAWhyP4RYqVMpZmAxR255lUqxEzY7C', 'TS. Nguyễn Văn A', 'nguyenvana@judge.edu.vn', 'LECTURER'),
(3, 'sv_nguyenhoangnam', '$2y$10$vxRkrIwNvvCVQutjIUn5yuj/bU9DUtDSGQEVP/g90vtNrGEjCPic6', 'Nguyễn Hoàng Nam (B20DCCN001)', 'namnh@student.edu.vn', 'STUDENT'),
(4, 'sv_lethiminh', '$2y$10$vxRkrIwNvvCVQutjIUn5yuj/bU9DUtDSGQEVP/g90vtNrGEjCPic6', 'Lê Thị Minh (B20DCCN002)', 'minhlt@student.edu.vn', 'STUDENT')
ON DUPLICATE KEY UPDATE `full_name` = VALUES(`full_name`);

-- --------------------------------------------------------------
-- 2. CẤU HÌNH NGÔN NGỮ LẬP TRÌNH (Python & C++)
-- --------------------------------------------------------------
INSERT INTO `languages` (`id`, `name`, `source_ext`, `compile_cmd`, `run_cmd`, `is_active`) VALUES
('python3', 'Python 3', '.py', NULL, 'python "{source}"', 1),
('cpp', 'C++ 17 (GCC)', '.cpp', 'g++ -O2 "{source}" -o "{output}"', '"{output}"', 1)
ON DUPLICATE KEY UPDATE `name` = VALUES(`name`);

-- --------------------------------------------------------------
-- 3. LỚP HỌC MẪU
-- --------------------------------------------------------------
INSERT INTO `classes` (`id`, `class_code`, `name`, `description`, `lecturer_id`) VALUES
(1, 'IT001_K15', 'Nhập môn Lập trình - K15', 'Lớp cơ sở lập trình Python và C++ căn bản', 2),
(2, 'IT002_K15', 'Cấu trúc dữ liệu và Giải thuật', 'Lớp thuật toán nâng cao và rèn luyện tư duy', 2)
ON DUPLICATE KEY UPDATE `name` = VALUES(`name`);

-- --------------------------------------------------------------
-- 4. THÀNH VIÊN LỚP HỌC
-- --------------------------------------------------------------
INSERT INTO `class_members` (`class_id`, `student_id`) VALUES
(1, 3),
(1, 4),
(2, 3)
ON DUPLICATE KEY UPDATE `class_id` = VALUES(`class_id`);

-- --------------------------------------------------------------
-- 5. DANH SÁCH 10 BÀI TẬP & PHIÊN BẢN (PROBLEMS & PROBLEM_VERSIONS)
-- --------------------------------------------------------------

-- Bài 1: SUM_TWO_NUMBERS
INSERT INTO `problems` (`id`, `code`, `title`, `topic`, `difficulty`, `author_id`, `current_version_id`) 
VALUES (1, 'SUM_TWO_NUMBERS', 'Tính tổng hai số nguyên', 'Cơ bản', 'EASY', 2, 1);

INSERT INTO `problem_versions` (`id`, `problem_id`, `version_number`, `description`, `time_limit_ms`, `memory_limit_kb`, `change_log`, `created_by`) VALUES
(1, 1, 1, 'Cho hai số nguyên A và B (-10^9 <= A, B <= 10^9). Hãy tính tổng A + B.\n\nĐầu vào (stdin):\n- Một dòng duy nhất chứa hai số nguyên A và B cách nhau bởi dấu cách.\n\nĐầu ra (stdout):\n- In ra tổng A + B trên một dòng.', 1000, 131072, 'Phiên bản khởi tạo ban đầu', 2);

-- Bài 2: PRIME_CHECK
INSERT INTO `problems` (`id`, `code`, `title`, `topic`, `difficulty`, `author_id`, `current_version_id`) 
VALUES (2, 'PRIME_CHECK', 'Kiểm tra số nguyên tố', 'Toán học', 'EASY', 2, 2);

INSERT INTO `problem_versions` (`id`, `problem_id`, `version_number`, `description`, `time_limit_ms`, `memory_limit_kb`, `change_log`, `created_by`) VALUES
(2, 2, 1, 'Cho số nguyên N (-10^6 <= N <= 10^6). Hãy kiểm tra xem N có phải là số nguyên tố hay không.\n\nĐầu vào (stdin):\n- Một dòng duy nhất chứa số nguyên N.\n\nĐầu ra (stdout):\n- In ra "YES" nếu N là số nguyên tố, ngược lại in "NO".', 1000, 131072, 'Phiên bản khởi tạo ban đầu', 2);

-- Bài 3: REVERSE_ARRAY
INSERT INTO `problems` (`id`, `code`, `title`, `topic`, `difficulty`, `author_id`, `current_version_id`) 
VALUES (3, 'REVERSE_ARRAY', 'Đảo ngược mảng số nguyên', 'Mảng', 'EASY', 2, 3);

INSERT INTO `problem_versions` (`id`, `problem_id`, `version_number`, `description`, `time_limit_ms`, `memory_limit_kb`, `change_log`, `created_by`) VALUES
(3, 3, 1, 'Cho mảng gồm N số nguyên (1 <= N <= 1000). Hãy in ra mảng theo thứ tự đảo ngược.\n\nĐầu vào (stdin):\n- Dòng 1: Số nguyên N.\n- Dòng 2: N số nguyên cách nhau bởi dấu cách.\n\nĐầu ra (stdout):\n- Dãy số đảo ngược, các số cách nhau bởi dấu cách.', 1000, 131072, 'Phiên bản khởi tạo ban đầu', 2);

-- Bài 4: PALINDROME_STRING
INSERT INTO `problems` (`id`, `code`, `title`, `topic`, `difficulty`, `author_id`, `current_version_id`) 
VALUES (4, 'PALINDROME_STRING', 'Kiểm tra chuỗi đối xứng', 'Chuỗi ký tự', 'EASY', 2, 4);

INSERT INTO `problem_versions` (`id`, `problem_id`, `version_number`, `description`, `time_limit_ms`, `memory_limit_kb`, `change_log`, `created_by`) VALUES
(4, 4, 1, 'Cho một xâu ký tự S (độ dài 1 <= |S| <= 10^5) chỉ gồm các chữ cái in thường. Hãy kiểm tra xem xâu S có phải là xâu đối xứng (Palindrome) hay không.\n\nĐầu vào (stdin):\n- Một dòng duy nhất chứa xâu S.\n\nĐầu ra (stdout):\n- In ra "YES" nếu S đối xứng, ngược lại in "NO".', 1000, 131072, 'Phiên bản khởi tạo ban đầu', 2);

-- Bài 5: SECOND_LARGEST
INSERT INTO `problems` (`id`, `code`, `title`, `topic`, `difficulty`, `author_id`, `current_version_id`) 
VALUES (5, 'SECOND_LARGEST', 'Tìm phần tử lớn thứ hai trong mảng', 'Mảng', 'MEDIUM', 2, 5);

INSERT INTO `problem_versions` (`id`, `problem_id`, `version_number`, `description`, `time_limit_ms`, `memory_limit_kb`, `change_log`, `created_by`) VALUES
(5, 5, 1, 'Cho mảng A gồm N số nguyên (2 <= N <= 10^5). Hãy tìm giá trị lớn thứ hai (nghiêm ngặt) trong mảng. Nếu mọi phần tử đều bằng nhau, in ra "NOT FOUND".\n\nĐầu vào (stdin):\n- Dòng 1: Số nguyên N.\n- Dòng 2: N số nguyên.\n\nĐầu ra (stdout):\n- Giá trị lớn thứ hai hoặc "NOT FOUND".', 1000, 131072, 'Phiên bản khởi tạo ban đầu', 2);

-- Bài 6: FIBONACCI_N
INSERT INTO `problems` (`id`, `code`, `title`, `topic`, `difficulty`, `author_id`, `current_version_id`) 
VALUES (6, 'FIBONACCI_N', 'Tìm số Fibonacci thứ N', 'Đệ quy & QHD', 'MEDIUM', 2, 6);

INSERT INTO `problem_versions` (`id`, `problem_id`, `version_number`, `description`, `time_limit_ms`, `memory_limit_kb`, `change_log`, `created_by`) VALUES
(6, 6, 1, 'Dãy Fibonacci được định nghĩa: F(0) = 0, F(1) = 1, F(n) = F(n-1) + F(n-2) với n >= 2. Cho số nguyên N (0 <= N <= 45), hãy tìm giá trị F(N).\n\nĐầu vào (stdin):\n- Một dòng duy nhất chứa số nguyên N.\n\nĐầu ra (stdout):\n- Giá trị F(N).', 1000, 131072, 'Phiên bản khởi tạo ban đầu', 2);

-- Bài 7: FACTORIAL_CALC
INSERT INTO `problems` (`id`, `code`, `title`, `topic`, `difficulty`, `author_id`, `current_version_id`) 
VALUES (7, 'FACTORIAL_CALC', 'Tính giai thừa số N', 'Toán học', 'MEDIUM', 2, 7);

INSERT INTO `problem_versions` (`id`, `problem_id`, `version_number`, `description`, `time_limit_ms`, `memory_limit_kb`, `change_log`, `created_by`) VALUES
(7, 7, 1, 'Cho số nguyên N (0 <= N <= 20). Hãy tính giai thừa N! = 1 * 2 * ... * N (với quy ước 0! = 1).\n\nĐầu vào (stdin):\n- Một dòng chứa số nguyên N.\n\nĐầu ra (stdout):\n- Giá trị N!.', 1000, 131072, 'Phiên bản khởi tạo ban đầu', 2);

-- Bài 8: COUNT_WORDS
INSERT INTO `problems` (`id`, `code`, `title`, `topic`, `difficulty`, `author_id`, `current_version_id`) 
VALUES (8, 'COUNT_WORDS', 'Đếm số từ trong chuỗi', 'Chuỗi ký tự', 'MEDIUM', 2, 8);

INSERT INTO `problem_versions` (`id`, `problem_id`, `version_number`, `description`, `time_limit_ms`, `memory_limit_kb`, `change_log`, `created_by`) VALUES
(8, 8, 1, 'Cho một đoạn văn bản gồm các từ phân tách nhau bởi một hoặc nhiều dấu cách. Hãy đếm số từ có trong đoạn văn bản.\n\nĐầu vào (stdin):\n- Một dòng chứa xâu ký tự.\n\nĐầu ra (stdout):\n- Số lượng từ tìm được.', 1000, 131072, 'Phiên bản khởi tạo ban đầu', 2);

-- Bài 9: SORT_INTEGERS
INSERT INTO `problems` (`id`, `code`, `title`, `topic`, `difficulty`, `author_id`, `current_version_id`) 
VALUES (9, 'SORT_INTEGERS', 'Sắp xếp dãy số tăng dần', 'Thuật toán sắp xếp', 'MEDIUM', 2, 9);

INSERT INTO `problem_versions` (`id`, `problem_id`, `version_number`, `description`, `time_limit_ms`, `memory_limit_kb`, `change_log`, `created_by`) VALUES
(9, 9, 1, 'Cho dãy số gồm N phần tử (1 <= N <= 10^5). Hãy sắp xếp dãy số theo thứ tự không giảm (tăng dần).\n\nĐầu vào (stdin):\n- Dòng 1: Số nguyên N.\n- Dòng 2: N số nguyên cách nhau bởi dấu cách.\n\nĐầu ra (stdout):\n- Dãy số sau khi sắp xếp, cách nhau dấu cách.', 2000, 131072, 'Phiên bản khởi tạo ban đầu', 2);

-- Bài 10: MAX_SUBARRAY_SUM
INSERT INTO `problems` (`id`, `code`, `title`, `topic`, `difficulty`, `author_id`, `current_version_id`) 
VALUES (10, 'MAX_SUBARRAY_SUM', 'Tổng dãy con lớn nhất (Kadane)', 'Quy hoạch động', 'HARD', 2, 10);

INSERT INTO `problem_versions` (`id`, `problem_id`, `version_number`, `description`, `time_limit_ms`, `memory_limit_kb`, `change_log`, `created_by`) VALUES
(10, 10, 1, 'Cho mảng A gồm N số nguyên (-10^9 <= A[i] <= 10^9, 1 <= N <= 10^5). Hãy tìm đoạn con liên tiếp có ít nhất một phần tử sao cho tổng các phần tử là lớn nhất.\n\nĐầu vào (stdin):\n- Dòng 1: Số nguyên N.\n- Dòng 2: N số nguyên.\n\nĐầu ra (stdout):\n- Giá trị tổng lớn nhất tìm được.', 1500, 131072, 'Phiên bản khởi tạo ban đầu', 2);

-- Gán bài tập vào lớp mẫu
INSERT INTO `class_problems` (`class_id`, `problem_id`) VALUES
(1, 1), (1, 2), (1, 3), (1, 4),
(2, 5), (2, 6), (2, 7), (2, 8), (2, 9), (2, 10)
ON DUPLICATE KEY UPDATE `class_id` = VALUES(`class_id`);

-- --------------------------------------------------------------
-- 6. BỘ DỮ LIỆU TEST CASES (ÍT NHẤT 5 CA / BÀI, CÓ TEST MẪU VÀ TEST KÍN)
-- --------------------------------------------------------------

-- Test cases cho Bài 1: SUM_TWO_NUMBERS (version 1)
INSERT INTO `test_cases` (`problem_version_id`, `input_data`, `expected_output`, `is_sample`, `score_weight`, `order_index`) VALUES
(1, "3 5", "8", 1, 20, 1),
(1, "10 20", "30", 1, 20, 2),
(1, "-5 8", "3", 0, 20, 3),
(1, "-10 -15", "-25", 0, 20, 4),
(1, "1000000000 1000000000", "2000000000", 0, 20, 5);

-- Test cases cho Bài 2: PRIME_CHECK (version 2)
INSERT INTO `test_cases` (`problem_version_id`, `input_data`, `expected_output`, `is_sample`, `score_weight`, `order_index`) VALUES
(2, "7", "YES", 1, 20, 1),
(2, "4", "NO", 1, 20, 2),
(2, "1", "NO", 0, 20, 3),
(2, "0", "NO", 0, 20, 4),
(2, "97", "YES", 0, 20, 5),
(2, "-13", "NO", 0, 20, 6);

-- Test cases cho Bài 3: REVERSE_ARRAY (version 3)
INSERT INTO `test_cases` (`problem_version_id`, `input_data`, `expected_output`, `is_sample`, `score_weight`, `order_index`) VALUES
(3, "5\n1 2 3 4 5", "5 4 3 2 1", 1, 20, 1),
(3, "3\n10 20 30", "30 20 10", 1, 20, 2),
(3, "1\n99", "99", 0, 20, 3),
(3, "4\n-1 -2 -3 -4", "-4 -3 -2 -1", 0, 20, 4),
(3, "6\n5 0 2 0 1 8", "8 1 0 2 0 5", 0, 20, 5);

-- Test cases cho Bài 4: PALINDROME_STRING (version 4)
INSERT INTO `test_cases` (`problem_version_id`, `input_data`, `expected_output`, `is_sample`, `score_weight`, `order_index`) VALUES
(4, "radar", "YES", 1, 20, 1),
(4, "hello", "NO", 1, 20, 2),
(4, "a", "YES", 0, 20, 3),
(4, "abba", "YES", 0, 20, 4),
(4, "racecar", "YES", 0, 20, 5),
(4, "python", "NO", 0, 20, 6);

-- Test cases cho Bài 5: SECOND_LARGEST (version 5)
INSERT INTO `test_cases` (`problem_version_id`, `input_data`, `expected_output`, `is_sample`, `score_weight`, `order_index`) VALUES
(5, "5\n1 2 3 4 5", "4", 1, 20, 1),
(5, "3\n5 5 5", "NOT FOUND", 1, 20, 2),
(5, "4\n10 20 20 10", "10", 0, 20, 3),
(5, "5\n-5 -2 -1 -10 -2", "-2", 0, 20, 4),
(5, "2\n100 50", "50", 0, 20, 5);

-- Test cases cho Bài 6: FIBONACCI_N (version 6)
INSERT INTO `test_cases` (`problem_version_id`, `input_data`, `expected_output`, `is_sample`, `score_weight`, `order_index`) VALUES
(6, "0", "0", 1, 20, 1),
(6, "1", "1", 1, 20, 2),
(6, "5", "5", 0, 20, 3),
(6, "10", "55", 0, 20, 4),
(6, "20", "6765", 0, 20, 5),
(6, "35", "9227465", 0, 20, 6);

-- Test cases cho Bài 7: FACTORIAL_CALC (version 7)
INSERT INTO `test_cases` (`problem_version_id`, `input_data`, `expected_output`, `is_sample`, `score_weight`, `order_index`) VALUES
(7, "0", "1", 1, 20, 1),
(7, "5", "120", 1, 20, 2),
(7, "1", "1", 0, 20, 3),
(7, "7", "5040", 0, 20, 4),
(7, "12", "479001600", 0, 20, 5);

-- Test cases cho Bài 8: COUNT_WORDS (version 8)
INSERT INTO `test_cases` (`problem_version_id`, `input_data`, `expected_output`, `is_sample`, `score_weight`, `order_index`) VALUES
(8, "Xin chao tat ca cac ban", "6", 1, 20, 1),
(8, "   lap trinh    python  va   cpp   ", "5", 1, 20, 2),
(8, "SingleWord", "1", 0, 20, 3),
(8, "A B C D E F", "6", 0, 20, 4),
(8, "  Hello   World  ", "2", 0, 20, 5);

-- Test cases cho Bài 9: SORT_INTEGERS (version 9)
INSERT INTO `test_cases` (`problem_version_id`, `input_data`, `expected_output`, `is_sample`, `score_weight`, `order_index`) VALUES
(9, "5\n4 2 1 5 3", "1 2 3 4 5", 1, 20, 1),
(9, "3\n10 -5 0", "-5 0 10", 1, 20, 2),
(9, "4\n1 1 1 1", "1 1 1 1", 0, 20, 3),
(9, "5\n-10 -20 -3 -50 -1", "-50 -20 -10 -3 -1", 0, 20, 4),
(9, "6\n9 8 7 6 5 4", "4 5 6 7 8 9", 0, 20, 5);

-- Test cases cho Bài 10: MAX_SUBARRAY_SUM (version 10)
INSERT INTO `test_cases` (`problem_version_id`, `input_data`, `expected_output`, `is_sample`, `score_weight`, `order_index`) VALUES
(10, "8\n-2 -3 4 -1 -2 1 5 -3", "7", 1, 20, 1),
(10, "4\n-1 -2 -3 -4", "-1", 1, 20, 2),
(10, "5\n1 2 3 4 5", "15", 0, 20, 3),
(10, "1\n100", "100", 0, 20, 4),
(10, "6\n-2 1 -3 4 -1 2", "5", 0, 20, 5);

SET FOREIGN_KEY_CHECKS = 1;
