<?php
// logout.php
session_start();
require_once __DIR__ . '/config/database.php';

if (isset($_SESSION['user_id'])) {
    log_activity($pdo, (int)$_SESSION['user_id'], 'Log Keluar', 'Log Keluar', ($_SESSION['fullname'] ?? '') . ' log keluar daripada sistem.');
}

session_unset();
session_destroy();
header("Location: landing.php");
exit();