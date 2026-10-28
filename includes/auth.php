<?php
/**
 * TIỆN ÍCH QUẢN LÝ PHIÊN ĐĂNG NHẬP VÀ PHÂN QUYỀN (AUTH HELPER)
 * Đề tài 20: Phân biệt Sinh viên (STUDENT), Giảng viên (LECTURER), Quản trị viên (ADMIN)
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/**
 * Kiểm tra xem người dùng đã đăng nhập chưa
 */
function isLoggedIn(): bool {
    return isset($_SESSION['user']) && !empty($_SESSION['user']['id']);
}

/**
 * Lấy thông tin người dùng đang đăng nhập
 */
function getCurrentUser(): ?array {
    return $_SESSION['user'] ?? null;
}

/**
 * Kiểm tra vai trò cụ thể
 */
function hasRole(string $role): bool {
    if (!isLoggedIn()) return false;
    return strtoupper($_SESSION['user']['role']) === strtoupper($role);
}

function isAdmin(): bool {
    return hasRole('ADMIN');
}

function isLecturer(): bool {
    return hasRole('LECTURER') || hasRole('ADMIN');
}

function isStudent(): bool {
    return hasRole('STUDENT');
}

/**
 * Bắt buộc đăng nhập, nếu chưa thì chuyển hướng về trang login
 */
function requireLogin(): void {
    if (!isLoggedIn()) {
        header('Location: login.php?msg=need_login');
        exit;
    }
}

/**
 * Bắt buộc có quyền tương ứng
 */
function requireRole(string $role): void {
    requireLogin();
    if (!hasRole($role)) {
        header('Location: index.php?msg=access_denied');
        exit;
    }
}
