-- ==============================================================================
-- BYTEFLOW ONLINE JUDGE - DỮ LIỆU KHỞI TẠO MẪU (seed.sql)
-- Khởi tạo:
-- 1. 4 Tài khoản mẫu: admin1, instructor1, learner1, learner2 (Password: Password123@)
-- 2. 1 Lớp học mẫu và danh sách thành viên
-- 3. 10 Bài tập lập trình chuẩn kèm phiên bản (v1) và bộ testcase (ít nhất 5 test/bài)
-- ==============================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ------------------------------------------------------------------------------
-- 1. KHỞI TẠO TÀI KHOẢN MẪU (Mật khẩu chung: Password123@)
-- Hash Bcrypt: $2y$10$NwxgSH.Jx1/WncQ8f0ZelucdKhR/Sx6qo45ULnVhZtYc1UtmOac26
-- ------------------------------------------------------------------------------
TRUNCATE TABLE `classroom_members`;
TRUNCATE TABLE `classrooms`;
TRUNCATE TABLE `case_results`;
TRUNCATE TABLE `judging_runs`;
TRUNCATE TABLE `submissions`;
TRUNCATE TABLE `code_drafts`;
TRUNCATE TABLE `test_cases`;
TRUNCATE TABLE `problem_versions`;
TRUNCATE TABLE `problems`;
TRUNCATE TABLE `users`;

INSERT INTO `users` (`id`, `username`, `email`, `password_hash`, `full_name`, `role`, `created_at`) VALUES
(1, 'admin1', 'admin1@byteflow.local', '$2y$10$NwxgSH.Jx1/WncQ8f0ZelucdKhR/Sx6qo45ULnVhZtYc1UtmOac26', 'Quản trị viên Hệ thống', 'admin', NOW()),
(2, 'instructor1', 'instructor1@byteflow.local', '$2y$10$NwxgSH.Jx1/WncQ8f0ZelucdKhR/Sx6qo45ULnVhZtYc1UtmOac26', 'TS. Nguyễn Văn Giảng', 'instructor', NOW()),
(3, 'learner1', 'learner1@byteflow.local', '$2y$10$NwxgSH.Jx1/WncQ8f0ZelucdKhR/Sx6qo45ULnVhZtYc1UtmOac26', 'Trần Văn Học', 'learner', NOW()),
(4, 'learner2', 'learner2@byteflow.local', '$2y$10$NwxgSH.Jx1/WncQ8f0ZelucdKhR/Sx6qo45ULnVhZtYc1UtmOac26', 'Lê Thị Minh', 'learner', NOW());

-- ------------------------------------------------------------------------------
-- 2. KHỞI TẠO LỚP HỌC MẪU
-- ------------------------------------------------------------------------------
INSERT INTO `classrooms` (`id`, `name`, `code`, `instructor_id`, `created_at`) VALUES
(1, 'Nhập môn Lập trình & Cấu trúc Dữ liệu - K2026', 'CS101-2026', 2, NOW());

INSERT INTO `classroom_members` (`id`, `classroom_id`, `user_id`, `joined_at`) VALUES
(1, 1, 3, NOW()),
(2, 1, 4, NOW());

-- ------------------------------------------------------------------------------
-- 3. KHỞI TẠO 10 BÀI TẬP LẬP TRÌNH & PHIÊN BẢN v1
-- ------------------------------------------------------------------------------

-- Bài 1: two-sum
INSERT INTO `problems` (`id`, `slug`, `title`, `topic`, `difficulty`, `current_version_id`, `source_attribution`, `is_public`, `created_by`, `created_at`) VALUES
(1, 'two-sum', 'Tính Tổng Hai Số Nguyên', 'Cơ bản', 'Easy', NULL, 'CSES Problem Set (Introductory Problems) - Giấy phép CC-BY-SA 4.0', 1, 2, NOW());

INSERT INTO `problem_versions` (`id`, `problem_id`, `version_number`, `description`, `input_description`, `output_description`, `constraints`, `time_limit_ms`, `memory_limit_kb`, `changelog`, `created_by`, `created_at`) VALUES
(1, 1, 1, 
'Cho hai số nguyên a và b. Hãy viết chương trình đọc hai số này từ đầu vào chuẩn (stdin) và in ra giá trị tổng a + b ra đầu ra chuẩn (stdout).',
'Gồm một dòng duy nhất chứa hai số nguyên a và b cách nhau một khoảng trắng.',
'In ra một số nguyên duy nhất là tổng của a và b.',
'-10^9 <= a, b <= 10^9', 1000, 262144, 'Phiên bản ban đầu v1.0', 2, NOW());

UPDATE `problems` SET `current_version_id` = 1 WHERE `id` = 1;

-- Testcases Bài 1 (2 sample, 3 hidden)
INSERT INTO `test_cases` (`problem_version_id`, `case_order`, `input_data`, `expected_output`, `is_sample`, `is_hidden`, `points`, `created_at`) VALUES
(1, 1, '3 5\n', '8\n', 1, 0, 20, NOW()),
(1, 2, '-10 15\n', '5\n', 1, 0, 20, NOW()),
(1, 3, '0 0\n', '0\n', 0, 1, 20, NOW()),
(1, 4, '-1000000000 -1000000000\n', '-2000000000\n', 0, 1, 20, NOW()),
(1, 5, '1000000000 1000000000\n', '2000000000\n', 0, 1, 20, NOW());


-- Bài 2: prime-check
INSERT INTO `problems` (`id`, `slug`, `title`, `topic`, `difficulty`, `current_version_id`, `source_attribution`, `is_public`, `created_by`, `created_at`) VALUES
(2, 'prime-check', 'Kiểm Tra Số Nguyên Tố', 'Toán học', 'Easy', NULL, 'Open Kattis (Prime Check) - Giấy phép MIT', 1, 2, NOW());

INSERT INTO `problem_versions` (`id`, `problem_id`, `version_number`, `description`, `input_description`, `output_description`, `constraints`, `time_limit_ms`, `memory_limit_kb`, `changelog`, `created_by`, `created_at`) VALUES
(2, 2, 1,
'Một số tự nhiên lớn hơn 1 được gọi là số nguyên tố nếu nó chỉ chia hết cho 1 và chính nó. Cho số nguyên n, hãy xác định n có phải là số nguyên tố hay không.',
'Một dòng duy nhất chứa số nguyên n.',
'In ra YES nếu n là số nguyên tố, ngược lại in ra NO.',
'-10^9 <= n <= 10^9', 1000, 262144, 'Phiên bản ban đầu v1.0', 2, NOW());

UPDATE `problems` SET `current_version_id` = 2 WHERE `id` = 2;

-- Testcases Bài 2 (2 sample, 4 hidden)
INSERT INTO `test_cases` (`problem_version_id`, `case_order`, `input_data`, `expected_output`, `is_sample`, `is_hidden`, `points`, `created_at`) VALUES
(2, 1, '7\n', 'YES\n', 1, 0, 20, NOW()),
(2, 2, '10\n', 'NO\n', 1, 0, 20, NOW()),
(2, 3, '1\n', 'NO\n', 0, 1, 15, NOW()),
(2, 4, '-5\n', 'NO\n', 0, 1, 15, NOW()),
(2, 5, '2\n', 'YES\n', 0, 1, 15, NOW()),
(2, 6, '1000000007\n', 'YES\n', 0, 1, 15, NOW());


-- Bài 3: reverse-string
INSERT INTO `problems` (`id`, `slug`, `title`, `topic`, `difficulty`, `current_version_id`, `source_attribution`, `is_public`, `created_by`, `created_at`) VALUES
(3, 'reverse-string', 'Đảo Ngược Chuỗi Ký Tự', 'Chuỗi', 'Easy', NULL, 'Tự biên soạn - Giấy phép MIT', 1, 2, NOW());

INSERT INTO `problem_versions` (`id`, `problem_id`, `version_number`, `description`, `input_description`, `output_description`, `constraints`, `time_limit_ms`, `memory_limit_kb`, `changelog`, `created_by`, `created_at`) VALUES
(3, 3, 1,
'Cho một chuỗi ký tự S. Hãy in ra chuỗi đảo ngược của chuỗi S.',
'Một dòng duy nhất chứa chuỗi ký tự S (có thể chứa ký tự dấu cách).',
'In ra chuỗi ký tự sau khi đảo ngược.',
'1 <= len(S) <= 1000', 1000, 262144, 'Phiên bản ban đầu v1.0', 2, NOW());

UPDATE `problems` SET `current_version_id` = 3 WHERE `id` = 3;

-- Testcases Bài 3 (2 sample, 3 hidden)
INSERT INTO `test_cases` (`problem_version_id`, `case_order`, `input_data`, `expected_output`, `is_sample`, `is_hidden`, `points`, `created_at`) VALUES
(3, 1, 'hello\n', 'olleh\n', 1, 0, 20, NOW()),
(3, 2, 'Open Source\n', 'ecruoS nepO\n', 1, 0, 20, NOW()),
(3, 3, 'a\n', 'a\n', 0, 1, 20, NOW()),
(3, 4, 'racecar\n', 'racecar\n', 0, 1, 20, NOW()),
(3, 5, '12345!@#$% ABC\n', 'CBA %$#@!54321\n', 0, 1, 20, NOW());


-- Bài 4: factorial
INSERT INTO `problems` (`id`, `slug`, `title`, `topic`, `difficulty`, `current_version_id`, `source_attribution`, `is_public`, `created_by`, `created_at`) VALUES
(4, 'factorial', 'Tính Giai Thừa', 'Toán học', 'Easy', NULL, 'CSES Problem Set - Giấy phép CC-BY-SA 4.0', 1, 2, NOW());

INSERT INTO `problem_versions` (`id`, `problem_id`, `version_number`, `description`, `input_description`, `output_description`, `constraints`, `time_limit_ms`, `memory_limit_kb`, `changelog`, `created_by`, `created_at`) VALUES
(4, 4, 1,
'Giai thừa của một số tự nhiên n (ký hiệu n!) là tích của tất cả các số nguyên dương từ 1 đến n. Quy ước 0! = 1. Cho số tự nhiên n, hãy tính n!.',
'Một dòng duy nhất chứa số tự nhiên n.',
'In ra giá trị của n!.',
'0 <= n <= 20', 1000, 262144, 'Phiên bản ban đầu v1.0', 2, NOW());

UPDATE `problems` SET `current_version_id` = 4 WHERE `id` = 4;

-- Testcases Bài 4 (2 sample, 3 hidden)
INSERT INTO `test_cases` (`problem_version_id`, `case_order`, `input_data`, `expected_output`, `is_sample`, `is_hidden`, `points`, `created_at`) VALUES
(4, 1, '5\n', '120\n', 1, 0, 20, NOW()),
(4, 2, '0\n', '1\n', 1, 0, 20, NOW()),
(4, 3, '1\n', '1\n', 0, 1, 20, NOW()),
(4, 4, '10\n', '3628800\n', 0, 1, 20, NOW()),
(4, 5, '20\n', '2432902008176640000\n', 0, 1, 20, NOW());


-- Bài 5: second-largest
INSERT INTO `problems` (`id`, `slug`, `title`, `topic`, `difficulty`, `current_version_id`, `source_attribution`, `is_public`, `created_by`, `created_at`) VALUES
(5, 'second-largest', 'Phần Tử Lớn Thứ Hai', 'Mảng & Dãy số', 'Medium', NULL, 'Open Kattis - Giấy phép MIT', 1, 2, NOW());

INSERT INTO `problem_versions` (`id`, `problem_id`, `version_number`, `description`, `input_description`, `output_description`, `constraints`, `time_limit_ms`, `memory_limit_kb`, `changelog`, `created_by`, `created_at`) VALUES
(5, 5, 1,
'Cho một dãy gồm n số nguyên. Hãy tìm giá trị lớn thứ hai nghiêm ngặt trong dãy (giá trị lớn nhất trong các số nhỏ hơn phần tử lớn nhất). Nếu toàn bộ các phần tử trong dãy đều bằng nhau, in ra NONE.',
'Dòng đầu tiên chứa số nguyên n (2 <= n <= 10^5).\nDòng thứ hai chứa n số nguyên a1, a2, ..., an cách nhau bởi dấu cách.',
'In ra giá trị lớn thứ hai, hoặc NONE nếu không tồn tại.',
'2 <= n <= 10^5; -10^9 <= ai <= 10^9', 1000, 262144, 'Phiên bản ban đầu v1.0', 2, NOW());

UPDATE `problems` SET `current_version_id` = 5 WHERE `id` = 5;

-- Testcases Bài 5 (2 sample, 3 hidden)
INSERT INTO `test_cases` (`problem_version_id`, `case_order`, `input_data`, `expected_output`, `is_sample`, `is_hidden`, `points`, `created_at`) VALUES
(5, 1, '5\n1 4 2 5 3\n', '4\n', 1, 0, 20, NOW()),
(5, 2, '3\n7 7 7\n', 'NONE\n', 1, 0, 20, NOW()),
(5, 3, '4\n10 10 9 8\n', '9\n', 0, 1, 20, NOW()),
(5, 4, '4\n-10 -5 -2 -20\n', '-5\n', 0, 1, 20, NOW()),
(5, 5, '2\n-100 100\n', '-100\n', 0, 1, 20, NOW());


-- Bài 6: valid-parentheses
INSERT INTO `problems` (`id`, `slug`, `title`, `topic`, `difficulty`, `current_version_id`, `source_attribution`, `is_public`, `created_by`, `created_at`) VALUES
(6, 'valid-parentheses', 'Dãy Ngoặc Hợp Lệ', 'Ngăn xếp & Chuỗi', 'Medium', NULL, 'Tự biên soạn - Giấy phép MIT', 1, 2, NOW());

INSERT INTO `problem_versions` (`id`, `problem_id`, `version_number`, `description`, `input_description`, `output_description`, `constraints`, `time_limit_ms`, `memory_limit_kb`, `changelog`, `created_by`, `created_at`) VALUES
(6, 6, 1,
'Cho một chuỗi ký tự chỉ gồm các loại ngoặc: tròn (), vuông [], và nhọn {}. Chuỗi hợp lệ khi mọi ngoặc mở đều được đóng đúng cặp và theo đúng thứ tự lồng nhau.',
'Một dòng duy nhất chứa chuỗi ngoặc S.',
'In ra YES nếu chuỗi hợp lệ, ngược lại in ra NO.',
'1 <= len(S) <= 10^5', 1000, 262144, 'Phiên bản ban đầu v1.0', 2, NOW());

UPDATE `problems` SET `current_version_id` = 6 WHERE `id` = 6;

-- Testcases Bài 6 (2 sample, 3 hidden)
INSERT INTO `test_cases` (`problem_version_id`, `case_order`, `input_data`, `expected_output`, `is_sample`, `is_hidden`, `points`, `created_at`) VALUES
(6, 1, '()[]{}\n', 'YES\n', 1, 0, 20, NOW()),
(6, 2, '(]\n', 'NO\n', 1, 0, 20, NOW()),
(6, 3, '{[()]}\n', 'YES\n', 0, 1, 20, NOW()),
(6, 4, '(((\n', 'NO\n', 0, 1, 20, NOW()),
(6, 5, '][\n', 'NO\n', 0, 1, 20, NOW());


-- Bài 7: gcd-lcm
INSERT INTO `problems` (`id`, `slug`, `title`, `topic`, `difficulty`, `current_version_id`, `source_attribution`, `is_public`, `created_by`, `created_at`) VALUES
(7, 'gcd-lcm', 'Ước Chung Lớn Nhất và Bội Chung Nhỏ Nhất', 'Toán học', 'Easy', NULL, 'CSES Problem Set (Mathematics) - Giấy phép CC-BY-SA 4.0', 1, 2, NOW());

INSERT INTO `problem_versions` (`id`, `problem_id`, `version_number`, `description`, `input_description`, `output_description`, `constraints`, `time_limit_ms`, `memory_limit_kb`, `changelog`, `created_by`, `created_at`) VALUES
(7, 7, 1,
'Cho hai số nguyên dương a và b. Hãy tìm ước chung lớn nhất (GCD) và bội chung nhỏ nhất (LCM) của hai số đó.',
'Gồm một dòng chứa hai số nguyên dương a và b cách nhau một khoảng trắng.',
'In ra hai số nguyên cách nhau một khoảng trắng: số đầu là GCD, số sau là LCM.',
'1 <= a, b <= 10^9', 1000, 262144, 'Phiên bản ban đầu v1.0', 2, NOW());

UPDATE `problems` SET `current_version_id` = 7 WHERE `id` = 7;

-- Testcases Bài 7 (2 sample, 3 hidden)
INSERT INTO `test_cases` (`problem_version_id`, `case_order`, `input_data`, `expected_output`, `is_sample`, `is_hidden`, `points`, `created_at`) VALUES
(7, 1, '12 18\n', '6 36\n', 1, 0, 20, NOW()),
(7, 2, '7 13\n', '1 91\n', 1, 0, 20, NOW()),
(7, 3, '100 100\n', '100 100\n', 0, 1, 20, NOW()),
(7, 4, '5 25\n', '5 25\n', 0, 1, 20, NOW()),
(7, 5, '1000000 999999\n', '1 999999000000\n', 0, 1, 20, NOW());


-- Bài 8: binary-search
INSERT INTO `problems` (`id`, `slug`, `title`, `topic`, `difficulty`, `current_version_id`, `source_attribution`, `is_public`, `created_by`, `created_at`) VALUES
(8, 'binary-search', 'Tìm Kiếm Nhị Phân', 'Tìm kiếm & Sắp xếp', 'Medium', NULL, 'Open Kattis - Giấy phép MIT', 1, 2, NOW());

INSERT INTO `problem_versions` (`id`, `problem_id`, `version_number`, `description`, `input_description`, `output_description`, `constraints`, `time_limit_ms`, `memory_limit_kb`, `changelog`, `created_by`, `created_at`) VALUES
(8, 8, 1,
'Cho một mảng gồm n số nguyên đã được sắp xếp tăng dần và một giá trị x cần tìm. Hãy xác định vị trí (chỉ số 1-based) xuất hiện đầu tiên của x trong dãy. Nếu x không có trong mảng, in ra -1.',
'Dòng đầu tiên chứa hai số nguyên n và x (1 <= n <= 10^5, -10^9 <= x <= 10^9).\nDòng thứ hai chứa n số nguyên đã sắp xếp tăng dần.',
'In ra chỉ số đầu tiên (bắt đầu từ 1) của x trong dãy, hoặc -1 nếu không tìm thấy.',
'1 <= n <= 10^5; -10^9 <= ai, x <= 10^9', 1000, 262144, 'Phiên bản ban đầu v1.0', 2, NOW());

UPDATE `problems` SET `current_version_id` = 8 WHERE `id` = 8;

-- Testcases Bài 8 (2 sample, 3 hidden)
INSERT INTO `test_cases` (`problem_version_id`, `case_order`, `input_data`, `expected_output`, `is_sample`, `is_hidden`, `points`, `created_at`) VALUES
(8, 1, '5 3\n1 2 3 4 5\n', '3\n', 1, 0, 20, NOW()),
(8, 2, '5 6\n1 2 3 4 5\n', '-1\n', 1, 0, 20, NOW()),
(8, 3, '4 10\n10 20 30 40\n', '1\n', 0, 1, 20, NOW()),
(8, 4, '4 40\n10 20 30 40\n', '4\n', 0, 1, 20, NOW()),
(8, 5, '6 7\n1 3 7 7 7 9\n', '3\n', 0, 1, 20, NOW());


-- Bài 9: word-count
INSERT INTO `problems` (`id`, `slug`, `title`, `topic`, `difficulty`, `current_version_id`, `source_attribution`, `is_public`, `created_by`, `created_at`) VALUES
(9, 'word-count', 'Đếm Số Từ Trong Văn Bản', 'Chuỗi', 'Easy', NULL, 'Tự biên soạn - Giấy phép MIT', 1, 2, NOW());

INSERT INTO `problem_versions` (`id`, `problem_id`, `version_number`, `description`, `input_description`, `output_description`, `constraints`, `time_limit_ms`, `memory_limit_kb`, `changelog`, `created_by`, `created_at`) VALUES
(9, 9, 1,
'Cho một đoạn văn bản gồm một hoặc nhiều dòng. Hãy đếm tổng số từ có trong đoạn văn bản. Một từ được định nghĩa là chuỗi ký tự liên tiếp không chứa ký tự khoảng trắng (khoảng cách, tab, dòng mới).',
'Toàn bộ đoạn văn bản đọc từ stdin cho đến khi kết thúc dữ liệu (EOF).',
'In ra một số nguyên duy nhất là tổng số từ.',
'Tổng độ dài văn bản không vượt quá 5000 ký tự', 1000, 262144, 'Phiên bản ban đầu v1.0', 2, NOW());

UPDATE `problems` SET `current_version_id` = 9 WHERE `id` = 9;

-- Testcases Bài 9 (2 sample, 3 hidden)
INSERT INTO `test_cases` (`problem_version_id`, `case_order`, `input_data`, `expected_output`, `is_sample`, `is_hidden`, `points`, `created_at`) VALUES
(9, 1, 'Hello world, welcome to ByteFlow Online Judge!\n', '7\n', 1, 0, 20, NOW()),
(9, 2, '   Multiple    spaces   between    words   \n', '4\n', 1, 0, 20, NOW()),
(9, 3, '   \n\t  \n', '0\n', 0, 1, 20, NOW()),
(9, 4, 'Antigravity\n', '1\n', 0, 1, 20, NOW()),
(9, 5, 'Dong mot\nDong hai\nDong ba va bon\n', '8\n', 0, 1, 20, NOW());


-- Bài 10: longest-increasing-subsequence
INSERT INTO `problems` (`id`, `slug`, `title`, `topic`, `difficulty`, `current_version_id`, `source_attribution`, `is_public`, `created_by`, `created_at`) VALUES
(10, 'longest-increasing-subsequence', 'Dãy Con Tăng Dài Nhất', 'Quy hoạch động', 'Hard', NULL, 'CSES Problem Set (Dynamic Programming) - Giấy phép CC-BY-SA 4.0', 1, 2, NOW());

INSERT INTO `problem_versions` (`id`, `problem_id`, `version_number`, `description`, `input_description`, `output_description`, `constraints`, `time_limit_ms`, `memory_limit_kb`, `changelog`, `created_by`, `created_at`) VALUES
(10, 10, 1,
'Cho một dãy gồm n số nguyên a1, a2, ..., an. Hãy tìm độ dài của dãy con tăng nghiêm ngặt dài nhất trong dãy.',
'Dòng đầu tiên chứa số nguyên n (1 <= n <= 2500).\nDòng thứ hai chứa n số nguyên a1, a2, ..., an cách nhau bởi dấu cách.',
'In ra một số nguyên duy nhất là độ dài của dãy con tăng dài nhất.',
'1 <= n <= 2500; -10^9 <= ai <= 10^9', 1500, 262144, 'Phiên bản ban đầu v1.0', 2, NOW());

UPDATE `problems` SET `current_version_id` = 10 WHERE `id` = 10;

-- Testcases Bài 10 (2 sample, 3 hidden)
INSERT INTO `test_cases` (`problem_version_id`, `case_order`, `input_data`, `expected_output`, `is_sample`, `is_hidden`, `points`, `created_at`) VALUES
(10, 1, '8\n10 9 2 5 3 7 101 18\n', '4\n', 1, 0, 20, NOW()),
(10, 2, '5\n5 4 3 2 1\n', '1\n', 1, 0, 20, NOW()),
(10, 3, '4\n7 7 7 7\n', '1\n', 0, 1, 20, NOW()),
(10, 4, '5\n1 3 6 7 9\n', '5\n', 0, 1, 20, NOW()),
(10, 5, '8\n10 22 9 33 21 50 41 60\n', '5\n', 0, 1, 20, NOW());

SET FOREIGN_KEY_CHECKS = 1;
