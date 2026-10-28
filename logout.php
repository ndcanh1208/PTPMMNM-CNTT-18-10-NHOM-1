<?php
/**
 * ĐĂNG XUẤT TÀI KHOẢN (LOGOUT)
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$_SESSION = [];
session_destroy();

header('Location: login.php');
exit;
