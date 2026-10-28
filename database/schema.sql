-- ==============================================================
-- HỆ THỐNG LUYỆN TẬP VÀ CHẤM BÀI LẬP TRÌNH TRỰC TUYẾN (ONLINE JUDGE)
-- Đề tài 20 - Cơ sở dữ liệu chuẩn hóa MySQL / MariaDB (InnoDB)
-- ==============================================================

CREATE DATABASE IF NOT EXISTS `db_baitaplon` 
DEFAULT CHARACTER SET utf8mb4 
COLLATE utf8mb4_unicode_ci;

USE `db_baitaplon`;

-- Tắt kiểm tra khóa ngoại tạm thời để tránh xung đột khi tạo bảng
SET FOREIGN_KEY_CHECKS = 0;

-- --------------------------------------------------------------
-- 1. BẢNG NGƯỜI DÙNG & PHÂN QUYỀN (users)
-- --------------------------------------------------------------
DROP TABLE IF EXISTS `users`;
CREATE TABLE `users` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `username` VARCHAR(50) NOT NULL UNIQUE COMMENT 'Tên đăng nhập / Mã SV / Mã GV',
    `password_hash` VARCHAR(255) NOT NULL COMMENT 'Mật khẩu băm an toàn Bcrypt',
    `full_name` VARCHAR(100) NOT NULL COMMENT 'Họ và tên người dùng',
    `email` VARCHAR(100) NOT NULL UNIQUE COMMENT 'Địa chỉ email',
    `role` ENUM('ADMIN', 'LECTURER', 'STUDENT') NOT NULL DEFAULT 'STUDENT' COMMENT 'Vai trò hệ thống',
    `avatar_url` VARCHAR(255) DEFAULT NULL COMMENT 'Ảnh đại diện',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_users_role` (`role`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Bảng người dùng và phân quyền';

-- --------------------------------------------------------------
-- 2. BẢNG LỚP HỌC (classes)
-- --------------------------------------------------------------
DROP TABLE IF EXISTS `classes`;
CREATE TABLE `classes` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `class_code` VARCHAR(30) NOT NULL UNIQUE COMMENT 'Mã lớp / Mã học phần (vd: IT001_2026)',
    `name` VARCHAR(150) NOT NULL COMMENT 'Tên lớp học',
    `description` TEXT DEFAULT NULL COMMENT 'Mô tả lớp học',
    `lecturer_id` INT NOT NULL COMMENT 'Giảng viên phụ trách',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_classes_lecturer` (`lecturer_id`),
    CONSTRAINT `fk_classes_lecturer` FOREIGN KEY (`lecturer_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Bảng quản lý lớp học';

-- --------------------------------------------------------------
-- 3. BẢNG THÀNH VIÊN LỚP HỌC (class_members)
-- --------------------------------------------------------------
DROP TABLE IF EXISTS `class_members`;
CREATE TABLE `class_members` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `class_id` INT NOT NULL,
    `student_id` INT NOT NULL,
    `joined_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uq_class_student` (`class_id`, `student_id`),
    INDEX `idx_cm_student` (`student_id`),
    CONSTRAINT `fk_cm_class` FOREIGN KEY (`class_id`) REFERENCES `classes` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_cm_student` FOREIGN KEY (`student_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Bảng sinh viên tham gia lớp học';

-- --------------------------------------------------------------
-- 4. BẢNG BÀI TẬP GỐC (problems)
-- --------------------------------------------------------------
DROP TABLE IF EXISTS `problems`;
CREATE TABLE `problems` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `code` VARCHAR(50) NOT NULL UNIQUE COMMENT 'Mã bài viết tắt (vd: SUM_ARRAY, PRIME_CHECK)',
    `title` VARCHAR(255) NOT NULL COMMENT 'Tiêu đề bài tập',
    `topic` VARCHAR(100) NOT NULL COMMENT 'Chủ đề: Mảng, Chuỗi, Đồ thị, Đệ quy...',
    `difficulty` ENUM('EASY', 'MEDIUM', 'HARD') NOT NULL DEFAULT 'EASY' COMMENT 'Độ khó',
    `author_id` INT NOT NULL COMMENT 'Giảng viên tạo bài',
    `current_version_id` INT DEFAULT NULL COMMENT 'Trỏ tới phiên bản đang sử dụng',
    `is_published` TINYINT(1) NOT NULL DEFAULT 1 COMMENT '1 = Công khai; 0 = Ẩn',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_problems_difficulty` (`difficulty`),
    INDEX `idx_problems_topic` (`topic`),
    INDEX `idx_problems_author` (`author_id`),
    CONSTRAINT `fk_problems_author` FOREIGN KEY (`author_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Bảng bài tập lập trình gốc';

-- --------------------------------------------------------------
-- 5. BẢNG GIAO BÀI TẬP CHO LỚP (class_problems)
-- --------------------------------------------------------------
DROP TABLE IF EXISTS `class_problems`;
CREATE TABLE `class_problems` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `class_id` INT NOT NULL,
    `problem_id` INT NOT NULL,
    `assigned_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uq_class_problem` (`class_id`, `problem_id`),
    CONSTRAINT `fk_cp_class` FOREIGN KEY (`class_id`) REFERENCES `classes` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_cp_problem` FOREIGN KEY (`problem_id`) REFERENCES `problems` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Bảng phân phối bài tập theo lớp';

-- --------------------------------------------------------------
-- 6. BẢNG PHIÊN BẢN BÀI TẬP & CẤU HÌNH (problem_versions)
-- --------------------------------------------------------------
DROP TABLE IF EXISTS `problem_versions`;
CREATE TABLE `problem_versions` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `problem_id` INT NOT NULL COMMENT 'Thuộc bài tập nào',
    `version_number` INT NOT NULL DEFAULT 1 COMMENT 'Phiên bản 1, 2, 3...',
    `description` LONGTEXT NOT NULL COMMENT 'Nội dung đề bài, quy cách input/output',
    `time_limit_ms` INT NOT NULL DEFAULT 1000 COMMENT 'Giới hạn thời gian chạy (mili-giây)',
    `memory_limit_kb` INT NOT NULL DEFAULT 131072 COMMENT 'Giới hạn RAM (KB, mặc định 128MB)',
    `change_log` VARCHAR(255) DEFAULT NULL COMMENT 'Ghi chú lý do cập nhật phiên bản',
    `created_by` INT NOT NULL COMMENT 'Người tạo phiên bản này',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uq_problem_version` (`problem_id`, `version_number`),
    INDEX `idx_pv_problem` (`problem_id`),
    CONSTRAINT `fk_pv_problem` FOREIGN KEY (`problem_id`) REFERENCES `problems` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_pv_creator` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Bảng lưu lịch sử phiên bản đề và cấu hình chấm';

-- Tạo ràng buộc khóa ngoại vòng cho problems.current_version_id
ALTER TABLE `problems` 
ADD CONSTRAINT `fk_problems_current_ver` 
FOREIGN KEY (`current_version_id`) REFERENCES `problem_versions` (`id`) ON DELETE SET NULL;

-- --------------------------------------------------------------
-- 7. BẢNG BỘ DỮ LIỆU KIỂM TRA (test_cases)
-- --------------------------------------------------------------
DROP TABLE IF EXISTS `test_cases`;
CREATE TABLE `test_cases` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `problem_version_id` INT NOT NULL COMMENT 'Thuộc phiên bản đề nào',
    `input_data` MEDIUMTEXT NOT NULL COMMENT 'Dữ liệu đầu vào stdin',
    `expected_output` MEDIUMTEXT NOT NULL COMMENT 'Đầu ra chuẩn stdout',
    `is_sample` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 = Test ví dụ công khai; 0 = Test kín bảo mật',
    `score_weight` INT NOT NULL DEFAULT 10 COMMENT 'Điểm số của test case này',
    `order_index` INT NOT NULL DEFAULT 1 COMMENT 'Thứ tự chạy test',
    INDEX `idx_tc_version` (`problem_version_id`),
    CONSTRAINT `fk_tc_version` FOREIGN KEY (`problem_version_id`) REFERENCES `problem_versions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Bảng dữ liệu kiểm tra test case';

-- --------------------------------------------------------------
-- 8. BẢNG CẤU HÌNH NGÔN NGỮ LẬP TRÌNH (languages)
-- --------------------------------------------------------------
DROP TABLE IF EXISTS `languages`;
CREATE TABLE `languages` (
    `id` VARCHAR(20) PRIMARY KEY COMMENT 'Mã định danh (python3, cpp)',
    `name` VARCHAR(50) NOT NULL COMMENT 'Tên hiển thị',
    `source_ext` VARCHAR(10) NOT NULL COMMENT 'Đuôi mở rộng (.py, .cpp)',
    `compile_cmd` VARCHAR(255) DEFAULT NULL COMMENT 'Lệnh biên dịch (C++ có, Python để NULL)',
    `run_cmd` VARCHAR(255) NOT NULL COMMENT 'Lệnh chạy',
    `is_active` TINYINT(1) NOT NULL DEFAULT 1 COMMENT '1 = Hoạt động; 0 = Vô hiệu'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Bảng cấu hình ngôn ngữ thực thi';

-- --------------------------------------------------------------
-- 9. BẢNG LƯU BẢN NHÁP SOẠN THẢO (code_drafts)
-- --------------------------------------------------------------
DROP TABLE IF EXISTS `code_drafts`;
CREATE TABLE `code_drafts` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT NOT NULL,
    `problem_id` INT NOT NULL,
    `language_id` VARCHAR(20) NOT NULL,
    `draft_code` LONGTEXT NOT NULL COMMENT 'Mã nguồn nháp lưu tự động',
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `uq_user_problem_lang` (`user_id`, `problem_id`, `language_id`),
    CONSTRAINT `fk_draft_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_draft_problem` FOREIGN KEY (`problem_id`) REFERENCES `problems` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_draft_lang` FOREIGN KEY (`language_id`) REFERENCES `languages` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Bảng lưu bản nháp của người học';

-- --------------------------------------------------------------
-- 10. BẢNG LỊCH SỬ CHẠY THỬ VỚI INPUT TỰ NHẬP (draft_runs)
-- --------------------------------------------------------------
DROP TABLE IF EXISTS `draft_runs`;
CREATE TABLE `draft_runs` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT NOT NULL,
    `problem_id` INT NOT NULL,
    `language_id` VARCHAR(20) NOT NULL,
    `source_code` LONGTEXT NOT NULL COMMENT 'Mã nguồn đưa vào chạy thử',
    `custom_input` MEDIUMTEXT DEFAULT NULL COMMENT 'Dữ liệu đầu vào tự nhập',
    `actual_output` MEDIUMTEXT DEFAULT NULL COMMENT 'Kết quả chương trình in ra',
    `error_output` TEXT DEFAULT NULL COMMENT 'Thông báo lỗi nếu có',
    `status` ENUM('PENDING', 'RUNNING', 'COMPLETED', 'TIME_LIMIT', 'ERROR') NOT NULL DEFAULT 'PENDING',
    `execution_time_ms` INT DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_dr_user` (`user_id`),
    CONSTRAINT `fk_dr_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_dr_problem` FOREIGN KEY (`problem_id`) REFERENCES `problems` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_dr_lang` FOREIGN KEY (`language_id`) REFERENCES `languages` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Bảng lưu kết quả chạy thử tách riêng';

-- --------------------------------------------------------------
-- 11. BẢNG BÀI NỘP CHÍNH THỨC & HÀNG ĐỢI (submissions)
-- --------------------------------------------------------------
DROP TABLE IF EXISTS `submissions`;
CREATE TABLE `submissions` (
    `id` INT AUTO_INCREMENT PRIMARY KEY COMMENT 'Mã bài nộp',
    `idempotency_key` VARCHAR(64) NOT NULL UNIQUE COMMENT 'Khóa chống nộp trùng từ client',
    `user_id` INT NOT NULL COMMENT 'Học viên nộp bài',
    `problem_id` INT NOT NULL COMMENT 'Bài tập được nộp',
    `problem_version_id` INT NOT NULL COMMENT 'Gắn chặt với phiên bản đề lúc nộp',
    `language_id` VARCHAR(20) NOT NULL COMMENT 'Ngôn ngữ lập trình',
    `source_code` LONGTEXT NOT NULL COMMENT 'Toàn bộ mã nguồn bài nộp',
    `code_hash` VARCHAR(64) NOT NULL COMMENT 'Mã băm SHA256 chống nộp lặp lại mã cũ',
    `queue_status` ENUM('PENDING', 'PROCESSING', 'COMPLETED', 'FAILED') NOT NULL DEFAULT 'PENDING' COMMENT 'Trạng thái hàng đợi',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_sub_user` (`user_id`),
    INDEX `idx_sub_problem` (`problem_id`),
    INDEX `idx_sub_queue` (`queue_status`),
    CONSTRAINT `fk_sub_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_sub_problem` FOREIGN KEY (`problem_id`) REFERENCES `problems` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_sub_version` FOREIGN KEY (`problem_version_id`) REFERENCES `problem_versions` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_sub_lang` FOREIGN KEY (`language_id`) REFERENCES `languages` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Bảng nhận bài nộp chính thức';

-- --------------------------------------------------------------
-- 12. BẢNG LỊCH SỬ LƯỢT CHẤM BÀI (judging_runs)
-- --------------------------------------------------------------
DROP TABLE IF EXISTS `judging_runs`;
CREATE TABLE `judging_runs` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `submission_id` INT NOT NULL COMMENT 'Thuộc bài nộp nào',
    `problem_version_id` INT NOT NULL COMMENT 'Phiên bản test case dùng để chấm',
    `run_number` INT NOT NULL DEFAULT 1 COMMENT 'Lần chấm thứ 1, 2, 3...',
    `verdict` ENUM(
        'PENDING', 
        'ACCEPTED', 
        'WRONG_ANSWER', 
        'TIME_LIMIT_EXCEEDED', 
        'MEMORY_LIMIT_EXCEEDED', 
        'RUNTIME_ERROR', 
        'COMPILE_ERROR', 
        'SYSTEM_ERROR'
    ) NOT NULL DEFAULT 'PENDING' COMMENT 'Kết luận chung',
    `total_score` DECIMAL(5,2) NOT NULL DEFAULT 0.00 COMMENT 'Tổng điểm đạt được',
    `passed_test_count` INT NOT NULL DEFAULT 0 COMMENT 'Số test vượt qua',
    `total_test_count` INT NOT NULL DEFAULT 0 COMMENT 'Tổng số test trong bộ dữ liệu',
    `max_time_ms` INT NOT NULL DEFAULT 0 COMMENT 'Thời gian chạy lớn nhất',
    `max_memory_kb` INT NOT NULL DEFAULT 0 COMMENT 'RAM tiêu thụ lớn nhất',
    `compile_log` TEXT DEFAULT NULL COMMENT 'Log lỗi biên dịch',
    `system_log` TEXT DEFAULT NULL COMMENT 'Log lỗi hệ thống nếu ngắt worker',
    `is_latest` TINYINT(1) NOT NULL DEFAULT 1 COMMENT '1 = Kết quả có hiệu lực cao nhất; 0 = Kết quả cũ',
    `started_at` DATETIME DEFAULT NULL,
    `finished_at` DATETIME DEFAULT NULL,
    INDEX `idx_jr_sub_latest` (`submission_id`, `is_latest`),
    CONSTRAINT `fk_jr_submission` FOREIGN KEY (`submission_id`) REFERENCES `submissions` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_jr_version` FOREIGN KEY (`problem_version_id`) REFERENCES `problem_versions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Bảng lịch sử các lượt chấm bài';

-- --------------------------------------------------------------
-- 13. BẢNG KẾT QUẢ CHI TIẾT TỪNG TEST CASE (case_results)
-- --------------------------------------------------------------
DROP TABLE IF EXISTS `case_results`;
CREATE TABLE `case_results` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `judging_run_id` INT NOT NULL COMMENT 'Thuộc lượt chấm nào',
    `test_case_id` INT NOT NULL COMMENT 'Đối chiếu với test case nào',
    `verdict` ENUM('PASSED', 'FAILED', 'TIME_LIMIT_EXCEEDED', 'RUNTIME_ERROR') NOT NULL,
    `execution_time_ms` INT NOT NULL DEFAULT 0,
    `memory_used_kb` INT NOT NULL DEFAULT 0,
    `actual_output` MEDIUMTEXT DEFAULT NULL COMMENT 'Đầu ra thực tế (chỉ lưu cho test FAILED và bảo mật)',
    `error_message` VARCHAR(255) DEFAULT NULL,
    INDEX `idx_cr_run` (`judging_run_id`),
    CONSTRAINT `fk_cr_run` FOREIGN KEY (`judging_run_id`) REFERENCES `judging_runs` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_cr_testcase` FOREIGN KEY (`test_case_id`) REFERENCES `test_cases` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Bảng chi tiết kết quả từng test case';

-- Bật lại kiểm tra khóa ngoại
SET FOREIGN_KEY_CHECKS = 1;
