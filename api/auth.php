<?php
/**
 * ByteFlow Online Judge - API Xác thực Chính thức (Đăng nhập, Đăng ký, Đăng xuất & Kiểm tra Phiên)
 * POST /api/auth.php
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../core/Auth.php';
require_once __DIR__ . '/../core/Security.php';

$rawInput = file_get_contents('php://input');
$data = json_decode((string)$rawInput, true);
if (!is_array($data)) {
    $data = $_POST;
}

$action = isset($data['action']) ? trim((string)$data['action']) : (isset($_GET['action']) ? trim((string)$_GET['action']) : 'me');

// 1. Kiểm tra trạng thái phiên hiện tại
if ($action === 'me') {
    if (Auth::check()) {
        echo json_encode([
            'success'   => true,
            'logged_in' => true,
            'user'      => Auth::user()
        ], JSON_UNESCAPED_UNICODE);
    } else {
        echo json_encode([
            'success'   => true,
            'logged_in' => false,
            'user'      => null
        ], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

// 2. Đăng nhập chính thức (Username / Email / Mã sinh viên)
if ($action === 'login') {
    $identifier = trim((string)($data['username'] ?? $data['identifier'] ?? ''));
    $password   = (string)($data['password'] ?? '');

    if ($identifier === '' || $password === '') {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error'   => 'Vui lòng nhập đầy đủ Tên đăng nhập / Email / Mã sinh viên và Mật khẩu.'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if (Auth::attempt($identifier, $password)) {
        echo json_encode([
            'success' => true,
            'message' => 'Đăng nhập thành công.',
            'user'    => Auth::user()
        ], JSON_UNESCAPED_UNICODE);
    } else {
        $isLocked = Auth::getLastErrorCode() === 'locked';
        http_response_code($isLocked ? 403 : 401);
        echo json_encode([
            'success' => false,
            'code'    => Auth::getLastErrorCode() ?? 'invalid_credentials',
            'error'   => Auth::getLastError() ?? 'Tên đăng nhập hoặc mật khẩu không chính xác.'
        ], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

// 3. Đăng ký tài khoản Sinh viên chính thức
if ($action === 'register') {
    $res = Auth::register($data);
    if (!empty($res['success'])) {
        http_response_code(201);
        echo json_encode($res, JSON_UNESCAPED_UNICODE);
    } else {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error'   => $res['error'] ?? 'Không thể đăng ký tài khoản.'
        ], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

// 4. Đăng xuất
if ($action === 'logout') {
    Auth::logout();
    echo json_encode([
        'success' => true,
        'message' => 'Đã đăng xuất thành công.'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

http_response_code(400);
echo json_encode([
    'success' => false,
    'error'   => "Hành động '{$action}' không hợp lệ."
], JSON_UNESCAPED_UNICODE);
