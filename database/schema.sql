-- ==============================================================================
-- BYTEFLOW ONLINE JUDGE - CƠ SỞ DỮ LIỆU CHUẨN (MySQL / MariaDB Schema)
-- Đề tài 20: Xây dựng hệ thống luyện tập và chấm bài lập trình trực tuyến
-- Tuân thủ chặt chẽ MASTER_PLAN.md & Rubric môn học
-- ==============================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ------------------------------------------------------------------------------
-- 1. BẢNG NGƯỜI DÙNG (users)
-- Quản lý tài khoản với 3 vai trò: learner (học viên), instructor (giảng viên), admin (quản trị)
-- ------------------------------------------------------------------------------
DROP TABLE IF EXISTS `users`;
CREATE TABLE `users` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `username` VARCHAR(50) NOT NULL UNIQUE,
  `email` VARCHAR(100) NOT NULL UNIQUE,
  `password_hash` VARCHAR(255) NOT NULL,
  `full_name` VARCHAR(100) NOT NULL,
  `role` ENUM('learner', 'instructor', 'admin') NOT NULL DEFAULT 'learner',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_users_role` (`role`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Bảng người dùng và phân quyền';

-- ------------------------------------------------------------------------------
-- 2. BẢNG LỚP HỌC (classrooms)
-- Giảng viên tạo lớp học và cung cấp mã tham gia (code)
-- ------------------------------------------------------------------------------
DROP TABLE IF EXISTS `classrooms`;
CREATE TABLE `classrooms` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(150) NOT NULL,
  `code` VARCHAR(50) NOT NULL UNIQUE,
  `instructor_id` INT NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_classrooms_instructor` (`instructor_id`),
  CONSTRAINT `fk_classrooms_instructor` FOREIGN KEY (`instructor_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Bảng lớp học';

-- ------------------------------------------------------------------------------
-- 3. BẢNG THÀNH VIÊN LỚP HỌC (classroom_members)
-- Liên kết học viên với lớp học đã tham gia
-- ------------------------------------------------------------------------------
DROP TABLE IF EXISTS `classroom_members`;
CREATE TABLE `classroom_members` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `classroom_id` INT NOT NULL,
  `user_id` INT NOT NULL,
  `joined_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uq_classroom_user` (`classroom_id`, `user_id`),
  INDEX `idx_cm_user` (`user_id`),
  CONSTRAINT `fk_cm_classroom` FOREIGN KEY (`classroom_id`) REFERENCES `classrooms` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_cm_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Bảng thành viên tham gia lớp học';

-- ------------------------------------------------------------------------------
-- 4. BẢNG BÀI TẬP GỐC (problems)
-- Danh mục bài tập lập trình, quản lý định danh, độ khó, nguồn gốc hợp lệ
-- ------------------------------------------------------------------------------
DROP TABLE IF EXISTS `problems`;
CREATE TABLE `problems` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `slug` VARCHAR(100) NOT NULL UNIQUE,
  `title` VARCHAR(200) NOT NULL,
  `topic` VARCHAR(100) NOT NULL,
  `difficulty` ENUM('Easy', 'Medium', 'Hard') NOT NULL DEFAULT 'Easy',
  `current_version_id` INT NULL COMMENT 'Trỏ đến phiên bản bộ dữ liệu đang hiệu lực',
  `source_attribution` TEXT NULL COMMENT 'Nguồn gốc hợp lệ & giấy phép sử dụng (VD: CSES, Kattis, MIT)',
  `is_public` TINYINT(1) NOT NULL DEFAULT 1 COMMENT '1 = Công khai; 0 = Chỉ trong lớp học',
  `created_by` INT NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_problems_difficulty` (`difficulty`),
  INDEX `idx_problems_topic` (`topic`),
  INDEX `idx_problems_creator` (`created_by`),
  CONSTRAINT `fk_problems_creator` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Bảng bài tập lập trình gốc';

-- ------------------------------------------------------------------------------
-- 5. BẢNG BẢN NHÁP MÃ NGUỒN (code_drafts)
-- Hỗ trợ lưu nháp tự động trên trình duyệt cho từng người học, từng bài tập & ngôn ngữ
-- ------------------------------------------------------------------------------
DROP TABLE IF EXISTS `code_drafts`;
CREATE TABLE `code_drafts` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT NOT NULL,
  `problem_id` INT NOT NULL,
  `language` ENUM('python', 'cpp') NOT NULL,
  `source_code` LONGTEXT NOT NULL,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `uq_user_problem_lang` (`user_id`, `problem_id`, `language`),
  CONSTRAINT `fk_drafts_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_drafts_problem` FOREIGN KEY (`problem_id`) REFERENCES `problems` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Bản nháp mã nguồn của người học';

-- ------------------------------------------------------------------------------
-- 6. BẢNG PHIÊN BẢN BÀI TẬP & BỘ DỮ LIỆU CHẤM (problem_versions)
-- Hỗ trợ Yêu cầu kỹ thuật 2 & Tình huống 2: Đề bài và bộ test có thể nâng cấp phiên bản
-- ------------------------------------------------------------------------------
DROP TABLE IF EXISTS `problem_versions`;
CREATE TABLE `problem_versions` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `problem_id` INT NOT NULL,
  `version_number` INT NOT NULL DEFAULT 1,
  `description` LONGTEXT NOT NULL,
  `input_description` TEXT NOT NULL,
  `output_description` TEXT NOT NULL,
  `constraints` TEXT NOT NULL,
  `time_limit_ms` INT NOT NULL DEFAULT 1000 COMMENT 'Giới hạn thời gian (mili-giây)',
  `memory_limit_kb` INT NOT NULL DEFAULT 262144 COMMENT 'Giới hạn bộ nhớ (KB)',
  `changelog` TEXT NULL COMMENT 'Mô tả thay đổi so với phiên bản trước',
  `created_by` INT NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uq_problem_version` (`problem_id`, `version_number`),
  INDEX `idx_pv_problem` (`problem_id`),
  CONSTRAINT `fk_pv_problem` FOREIGN KEY (`problem_id`) REFERENCES `problems` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_pv_creator` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Bảng phiên bản đề và bộ dữ liệu chấm';

-- Liên kết khóa ngoại vòng cho problems.current_version_id
ALTER TABLE `problems`
  ADD CONSTRAINT `fk_problems_current_version`
  FOREIGN KEY (`current_version_id`) REFERENCES `problem_versions` (`id`)
  ON DELETE SET NULL;

-- ------------------------------------------------------------------------------
-- 7. BẢNG CA KIỂM THỬ (test_cases)
-- Thuộc từng phiên bản bài tập cụ thể. Phân biệt rõ ca công khai và ca chấm kín
-- ------------------------------------------------------------------------------
DROP TABLE IF EXISTS `test_cases`;
CREATE TABLE `test_cases` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `problem_version_id` INT NOT NULL,
  `case_order` INT NOT NULL DEFAULT 1,
  `input_data` MEDIUMTEXT NOT NULL,
  `expected_output` MEDIUMTEXT NOT NULL,
  `is_sample` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 = Ca kiểm thử ví dụ công khai; 0 = Không công khai',
  `is_hidden` TINYINT(1) NOT NULL DEFAULT 1 COMMENT '1 = Dữ liệu chấm kín bảo mật; 0 = Được hiển thị',
  `points` INT NOT NULL DEFAULT 10,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_tc_version` (`problem_version_id`),
  INDEX `idx_tc_version_order` (`problem_version_id`, `case_order`),
  CONSTRAINT `fk_tc_version` FOREIGN KEY (`problem_version_id`) REFERENCES `problem_versions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Bảng dữ liệu kiểm tra test case';

-- ------------------------------------------------------------------------------
-- 8. BẢNG BÀI NỘP (submissions)
-- Nhận bài nộp từ người học. Gắn chặt với problem_version_id và chống nộp trùng
-- ------------------------------------------------------------------------------
DROP TABLE IF EXISTS `submissions`;
CREATE TABLE `submissions` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT NOT NULL,
  `classroom_id` INT NULL,
  `problem_id` INT NOT NULL,
  `problem_version_id` INT NOT NULL COMMENT 'Gắn cứng phiên bản lúc nộp',
  `language` ENUM('python', 'cpp') NOT NULL,
  `source_code` LONGTEXT NOT NULL,
  `idempotency_key` VARCHAR(64) NOT NULL COMMENT 'Khóa chống nộp trùng từ client',
  `latest_run_id` INT NULL COMMENT 'Trỏ tới lần chấm mới nhất',
  `final_verdict` ENUM('Pending', 'Running', 'AC', 'WA', 'TLE', 'MLE', 'RE', 'CE', 'SE') NOT NULL DEFAULT 'Pending',
  `pass_count` INT NOT NULL DEFAULT 0,
  `total_cases` INT NOT NULL DEFAULT 0,
  `execution_time_ms` INT NOT NULL DEFAULT 0,
  `memory_used_kb` INT NOT NULL DEFAULT 0,
  `submitted_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uq_user_idempotency` (`user_id`, `idempotency_key`),
  INDEX `idx_sub_user` (`user_id`),
  INDEX `idx_sub_problem` (`problem_id`),
  INDEX `idx_sub_version` (`problem_version_id`),
  INDEX `idx_sub_idempotency` (`idempotency_key`),
  INDEX `idx_sub_verdict` (`final_verdict`),
  CONSTRAINT `fk_sub_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_sub_classroom` FOREIGN KEY (`classroom_id`) REFERENCES `classrooms` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_sub_problem` FOREIGN KEY (`problem_id`) REFERENCES `problems` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_sub_version` FOREIGN KEY (`problem_version_id`) REFERENCES `problem_versions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Bảng lưu trữ bài nộp của người học';

-- ------------------------------------------------------------------------------
-- 9. BẢNG LƯỢT THỰC THI / CHẤM BÀI (judging_runs)
-- Lưu toàn bộ lịch sử từng lần chấm (submit ban đầu, rejudge, retry)
-- Chống stale run: không cho lượt chấm cũ về muộn ghi đè bài nộp
-- ------------------------------------------------------------------------------
DROP TABLE IF EXISTS `judging_runs`;
CREATE TABLE `judging_runs` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `submission_id` INT NOT NULL,
  `problem_version_id` INT NOT NULL COMMENT 'Bộ dữ liệu áp dụng cho lần chấm này',
  `run_number` INT NOT NULL DEFAULT 1 COMMENT 'Lần chấm thứ 1, 2, 3...',
  `triggered_by` ENUM('submit', 'rejudge', 'retry') NOT NULL DEFAULT 'submit',
  `worker_id` VARCHAR(50) NULL COMMENT 'Mã tiến trình worker thực thi',
  `status` ENUM('Queued', 'Running', 'Completed', 'SystemError', 'Superseded') NOT NULL DEFAULT 'Queued',
  `verdict` ENUM('Pending', 'Running', 'AC', 'WA', 'TLE', 'MLE', 'RE', 'CE', 'SE') NOT NULL DEFAULT 'Pending',
  `passed_cases` INT NOT NULL DEFAULT 0,
  `total_cases` INT NOT NULL DEFAULT 0,
  `max_time_ms` INT NOT NULL DEFAULT 0,
  `max_memory_kb` INT NOT NULL DEFAULT 0,
  `compiler_message` TEXT NULL,
  `system_error_log` TEXT NULL COMMENT 'Log lỗi nội bộ hạ tầng (chỉ admin xem)',
  `started_at` DATETIME NULL,
  `finished_at` DATETIME NULL,
  INDEX `idx_run_submission` (`submission_id`),
  INDEX `idx_run_status` (`status`),
  INDEX `idx_run_version` (`problem_version_id`),
  CONSTRAINT `fk_run_submission` FOREIGN KEY (`submission_id`) REFERENCES `submissions` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_run_version` FOREIGN KEY (`problem_version_id`) REFERENCES `problem_versions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Lịch sử từng lần chấm bài / rejudge';

-- Khóa ngoại trỏ ngược latest_run_id trên submissions
ALTER TABLE `submissions`
  ADD CONSTRAINT `fk_submissions_latest_run`
  FOREIGN KEY (`latest_run_id`) REFERENCES `judging_runs` (`id`)
  ON DELETE SET NULL;

-- ------------------------------------------------------------------------------
-- 10. BẢNG CHI TIẾT KẾT QUẢ TỪNG TEST CASE (case_results)
-- Kết quả chi tiết của từng ca kiểm thử trong một lượt chấm
-- Bảo mật: test_cases.is_hidden = 1 thì actual_output tuyệt đối không được trả về client
-- ------------------------------------------------------------------------------
DROP TABLE IF EXISTS `case_results`;
CREATE TABLE `case_results` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `judging_run_id` INT NOT NULL,
  `test_case_id` INT NOT NULL,
  `verdict` ENUM('AC', 'WA', 'TLE', 'MLE', 'RE', 'SE') NOT NULL,
  `execution_time_ms` INT NOT NULL DEFAULT 0,
  `memory_used_kb` INT NOT NULL DEFAULT 0,
  `actual_output` MEDIUMTEXT NULL COMMENT 'Chỉ lưu/trả về cho sample case; ẩn hoàn toàn nếu test_cases.is_hidden = 1',
  `error_message` TEXT NULL COMMENT 'Thông báo lỗi thực thi (stderr an toàn)',
  INDEX `idx_cr_run` (`judging_run_id`),
  INDEX `idx_cr_testcase` (`test_case_id`),
  CONSTRAINT `fk_cr_run` FOREIGN KEY (`judging_run_id`) REFERENCES `judging_runs` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_cr_testcase` FOREIGN KEY (`test_case_id`) REFERENCES `test_cases` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Chi tiết kết quả từng ca kiểm thử của lượt chấm';

SET FOREIGN_KEY_CHECKS = 1;
