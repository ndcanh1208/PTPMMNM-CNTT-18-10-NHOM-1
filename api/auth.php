<?php
/**
 * Nhom1 Online Judge - API Xác thực Chính thức (Đăng nhập, Đăng ký, Đăng xuất & Kiểm tra Phiên)
 * POST /api/auth.php
 *
 * Chức năng chính của API này:
 * - Cung cấp một điểm truy cập duy nhất (endpoint) xử lý toàn bộ nghiệp vụ tài khoản theo tham số `action`:
 *   1. `me`       : Kiểm tra xem trình duyệt hiện tại đã đăng nhập hay chưa và trả về thông tin người dùng.
 *   2. `login`    : Xác thực tài khoản (bằng Username, Email hoặc Mã sinh viên) và mật khẩu.
 *   3. `register` : Tạo mới tài khoản sinh viên chính thức.
 *   4. `logout`   : Hủy phiên đăng nhập (Session) hiện tại.
 */

// Bật chế độ kiểm tra kiểu dữ liệu nghiêm ngặt (Strict Types) trong PHP để tránh lỗi ép kiểu ngầm
declare(strict_types=1);

// Khai báo với trình duyệt/client rằng dữ liệu API trả về luôn ở định dạng JSON và mã hóa tiếng Việt UTF-8
header('Content-Type: application/json; charset=utf-8');

// Nạp các tệp dùng chung cần thiết: kết nối CSDL, lớp quản lý xác thực (Auth) và lớp tiện ích bảo mật (Security)
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../core/Auth.php';
require_once __DIR__ . '/../core/Security.php';

// =========================================================================
// BƯỚC 0: ĐỌC DỮ LIỆU ĐẦU VÀO (HỖ TRỢ CẢ JSON BODY LẪN FORM POST)
// =========================================================================
// Đọc dữ liệu thô (raw body) mà frontend gửi lên (thường dùng fetch/AJAX với Content-Type: application/json)
$rawInput = file_get_contents('php://input');
// Chuyển chuỗi JSON thành mảng kết hợp (associative array) trong PHP
$data = json_decode((string)$rawInput, true);
// Nếu dữ liệu gửi lên không phải JSON hợp lệ (ví dụ gửi qua FormData hoặc form HTML), tự động dùng mảng $_POST
if (!is_array($data)) {
    $data = $_POST;
}

// Xác định hành động cần thực hiện (`action`): ưu tiên lấy trong body JSON/POST -> nếu không có thì lấy trên URL (?action=...) -> mặc định là 'me'
$action = isset($data['action']) ? trim((string)$data['action']) : (isset($_GET['action']) ? trim((string)$_GET['action']) : 'me');

// =========================================================================
// 1. KIỂM TRA TRẠNG THÁI PHIÊN HIỆN TẠI (action = 'me')
// =========================================================================
// 1. Kiểm tra trạng thái phiên hiện tại
if ($action === 'me') {
    // Auth::check() kiểm tra Session hiện tại có thông tin người dùng hợp lệ hay không
    if (Auth::check()) {
        // Trả về JSON (HTTP 200 OK):
        // - success: true (gọi API thành công)
        // - logged_in: true (người dùng đang đăng nhập)
        // - user: mảng thông tin chi tiết người dùng hiện tại (id, username, role, ...)
        echo json_encode([
            'success'   => true,
            'logged_in' => true,
            'user'      => Auth::user()
        ], JSON_UNESCAPED_UNICODE);
    } else {
        // Trả về JSON (HTTP 200 OK) báo hiệu khách vãng lai (chưa đăng nhập): logged_in = false, user = null
        echo json_encode([
            'success'   => true,
            'logged_in' => false,
            'user'      => null
        ], JSON_UNESCAPED_UNICODE);
    }
    // Dừng thực thi script ngay lập tức sau khi trả kết quả
    exit;
}

// =========================================================================
// 2. ĐĂNG NHẬP CHÍNH THỨC (action = 'login')
// =========================================================================
// 2. Đăng nhập chính thức (Username / Email / Mã sinh viên)
if ($action === 'login') {
    // Lấy định danh đăng nhập (hỗ trợ cả khóa 'username' hoặc 'identifier') và xóa khoảng trắng thừa hai đầu
    $identifier = trim((string)($data['username'] ?? $data['identifier'] ?? ''));
    // Lấy mật khẩu (không trim vì mật khẩu người dùng có thể cố ý chứa khoảng trắng)
    $password   = (string)($data['password'] ?? '');

    // Validate dữ liệu đầu vào: bắt buộc phải có cả tên định danh và mật khẩu
    if ($identifier === '' || $password === '') {
        // HTTP 400 Bad Request: dữ liệu client gửi lên bị thiếu hoặc không hợp lệ
        http_response_code(400);
        // Trả về JSON lỗi với success = false và thông báo cụ thể trong trường error
        echo json_encode([
            'success' => false,
            'error'   => 'Vui lòng nhập đầy đủ Tên đăng nhập / Email / Mã sinh viên và Mật khẩu.'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Gọi Auth::attempt() để truy vấn CSDL, đối chiếu mật khẩu băm (password_verify) và khởi tạo Session nếu khớp
    if (Auth::attempt($identifier, $password)) {
        // Đăng nhập thành công -> trả về JSON (HTTP 200 OK) kèm thông báo và đối tượng user vừa đăng nhập
        echo json_encode([
            'success' => true,
            'message' => 'Đăng nhập thành công.',
            'user'    => Auth::user()
        ], JSON_UNESCAPED_UNICODE);
    } else {
        // Đăng nhập thất bại: phân biệt nguyên nhân do tài khoản bị khóa ('locked') hay sai thông tin đăng nhập
        $isLocked = Auth::getLastErrorCode() === 'locked';
        // Nếu bị khóa -> HTTP 403 Forbidden (bị cấm truy cập); ngược lại -> HTTP 401 Unauthorized (sai thông tin xác thực)
        http_response_code($isLocked ? 403 : 401);
        // Trả về JSON lỗi kèm mã lỗi (`code`) để frontend dễ xử lý giao diện và nội dung chi tiết (`error`)
        echo json_encode([
            'success' => false,
            'code'    => Auth::getLastErrorCode() ?? 'invalid_credentials',
            'error'   => Auth::getLastError() ?? 'Tên đăng nhập hoặc mật khẩu không chính xác.'
        ], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

// =========================================================================
// 3. ĐĂNG KÝ TÀI KHOẢN SINH VIÊN CHÍNH THỨC (action = 'register')
// =========================================================================
// 3. Đăng ký tài khoản Sinh viên chính thức
if ($action === 'register') {
    // Ủy quyền toàn bộ quy trình validate (email, username, mật khẩu, trùng lặp) và INSERT vào bảng users cho Auth::register()
    $res = Auth::register($data);
    if (!empty($res['success'])) {
        // HTTP 201 Created: tài nguyên mới (tài khoản người dùng) đã được tạo thành công trong CSDL
        http_response_code(201);
        // Trả về nguyên cấu trúc JSON kết quả từ Auth::register() (thường gồm success, message, user)
        echo json_encode($res, JSON_UNESCAPED_UNICODE);
    } else {
        // HTTP 400 Bad Request: dữ liệu đăng ký không hợp lệ hoặc bị trùng username/email/MSSV
        http_response_code(400);
        // Trả về JSON thất bại cùng lý do lỗi cụ thể
        echo json_encode([
            'success' => false,
            'error'   => $res['error'] ?? 'Không thể đăng ký tài khoản.'
        ], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

// =========================================================================
// 4. ĐĂNG XUẤT KHỎI HỆ THỐNG (action = 'logout')
// =========================================================================
// 4. Đăng xuất
if ($action === 'logout') {
    // Xóa toàn bộ dữ liệu phiên đăng nhập (Session) và cookie phiên trên trình duyệt
    Auth::logout();
    // Trả về JSON (HTTP 200 OK) xác nhận đã đăng xuất
    echo json_encode([
        'success' => true,
        'message' => 'Đã đăng xuất thành công.'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// =========================================================================
// 5. XỬ LÝ HÀNH ĐỘNG KHÔNG HỢP LỆ (FALLBACK)
// =========================================================================
// Nếu tham số `action` không thuộc 4 giá trị trên, trả về HTTP 400 Bad Request kèm thông báo lỗi JSON
http_response_code(400);
echo json_encode([
    'success' => false,
    'error'   => "Hành động '{$action}' không hợp lệ."
], JSON_UNESCAPED_UNICODE);
