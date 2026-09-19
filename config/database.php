<?php
date_default_timezone_set('Asia/Kuala_Lumpur');
ini_set('date.timezone', 'Asia/Kuala_Lumpur');

define('DB_HOST', 'localhost');
define('DB_NAME', 'vehicle_booking');
define('DB_USER', 'root');
define('DB_PASS', '');

try {
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]
    );
} catch (PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}

/**
 * Helper function to record system activity logs.
 */
if (!function_exists('activity_log')) {
    function log_activity(PDO $pdo, ?int $userId, string $module, string $action, ?string $description = null): void {
        $ip        = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? null;
        $roleNow   = $_SESSION['role'] ?? null;

        $stmt = $pdo->prepare(
            "INSERT INTO activity_log (user_id, role_at_time, module, action, description, ip_address, user_agent)
             VALUES (?, ?, ?, ?, ?, ?, ?)"
        );
        $stmt->execute([$userId, $roleNow, $module, $action, $description, $ip, $userAgent]);
    }
}