<?php
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'domain' => '',
        'secure' => false, // Set to true in production with HTTPS
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

function is_logged_in() {
    return isset($_SESSION['user_id']);
}

function require_login() {
    if (!is_logged_in()) {
        header('Location: login.php');
        exit;
    }
}

function require_role($roles) {
    require_login();
    if (!in_array($_SESSION['role'], (array)$roles, true)) {
        header('Location: dashboard.php');
        exit;
    }
}

function current_user() {
    return [
        'user_id'  => $_SESSION['user_id']  ?? null,
        'fullname' => $_SESSION['fullname'] ?? '',
        'role'     => $_SESSION['role']     ?? '',
        'email'    => $_SESSION['email']    ?? '',
    ];
}

function is_admin() {
    return in_array($_SESSION['role'] ?? '', ['Admin', 'SuperAdmin'], true);
}

function is_approver() {
    return in_array($_SESSION['role'] ?? '', ['Admin', 'SuperAdmin'], true);
}

function generate_csrf_token() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verify_csrf_token(string $token): bool {
    $storedToken = $_SESSION['csrf_token'] ?? '';
    return !empty($storedToken) && hash_equals($storedToken, $token);
}
