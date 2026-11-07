<?php
/**
 * ByteFlow Online Judge - API Đăng nhập, Đăng xuất & Kiểm tra Phiên
 * POST /api/auth.php
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../core/Auth.php';
require_once __DIR__ . '/../core/Security.php';

$rawInput = file_get_contents('php://input');
$data = json_decode($rawInput, true);
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

// 2. Đăng nhập
if ($action === 'login') {
    $identifier = trim((string)($data['username'] ?? $data['identifier'] ?? ''));
    $password   = (string)($data['password'] ?? '');

    if ($identifier === '' || $password === '') {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error'   => 'Vui lòng nhập tên đăng nhập và mật khẩu.'
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
        http_response_code(401);
        echo json_encode([
            'success' => false,
            'error'   => 'Tên đăng nhập hoặc mật khẩu không chính xác.'
        ], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

// 3. Đăng xuất
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
