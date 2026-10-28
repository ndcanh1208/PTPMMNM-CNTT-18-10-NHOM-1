<?php
/**
 * CẤU HÌNH HỆ THỐNG & DỊCH VỤ THỰC THI MÃ NGUỒN CÁCH LY (PISTON API)
 * Đề tài 20: Hệ thống luyện tập và chấm bài trực tuyến
 */

// Thông tin ứng dụng
define('APP_NAME', 'Online Judge System');
define('APP_VERSION', '1.0.0');

// Cấu hình Dịch vụ thực thi mã cách ly Piston API (Docker)
// Lệnh chạy Docker Piston: docker run -d -p 2000:2000 --restart always ghcr.io/engineer-man/piston
define('PISTON_API_URL', 'http://localhost:2000/api/v2/execute');
define('PISTON_TIMEOUT_SEC', 6); // Timeout kết nối HTTP sang Docker container

// Chế độ dự phòng khi chưa bật Docker Piston (Phục vụ kiểm thử giao diện & thuật toán)
// - true: Nếu Docker chưa bật, hệ thống tự động dùng bộ giả lập cách ly nội bộ (Simulation) để thử nghiệm AC/WA/TLE/RE/CE
// - false: Nếu Docker chưa bật, trả về SYSTEM_ERROR (SE) chuẩn theo Phép thử 2
define('ENABLE_LOCAL_SANDBOX_FALLBACK', true);

// Giới hạn mặc định nếu đề bài không chỉ định
define('DEFAULT_TIME_LIMIT_MS', 1500); // 1.5 giây
define('DEFAULT_MEMORY_LIMIT_KB', 131072); // 128 MB

// 7 Mã trạng thái chấm chuẩn hóa (Theo quy chuẩn đề bài)
define('VERDICT_PENDING', 'PENDING'); // Chờ trong hàng đợi
define('VERDICT_RUNNING', 'RUNNING'); // Đang thực thi chấm
define('VERDICT_AC',      'ACCEPTED'); // Accepted (Đúng hoàn toàn)
define('VERDICT_WA',      'WRONG_ANSWER'); // Wrong Answer (Sai kết quả)
define('VERDICT_CE',      'COMPILE_ERROR'); // Compilation Error (Lỗi cú pháp)
define('VERDICT_RE',      'RUNTIME_ERROR'); // Runtime Error (Lỗi khi chạy)
define('VERDICT_TLE',     'TIME_LIMIT_EXCEEDED'); // Time Limit Exceeded (Quá thời gian/Lặp vô hạn)
define('VERDICT_SE',      'SYSTEM_ERROR'); // System Error (Dịch vụ cách ly bị ngắt/sập)

// Múi giờ
date_default_timezone_set('Asia/Ho_Chi_Minh');
