<?php
if (session_status() === PHP_SESSION_NONE) {
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
    return ($_SESSION['role'] ?? '') === 'admin';
}

function is_approver() {
    return in_array($_SESSION['role'] ?? '', ['admin', 'approver'], true);
}
