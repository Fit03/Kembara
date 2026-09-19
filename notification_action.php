<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/config/database.php';

require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !verify_csrf_token($_POST['csrf_token'] ?? '')) {
    http_response_code(403);
    exit('Ralat Keselamatan: Permintaan tidak sah.');
}

$userId = (int)$_SESSION['user_id'];
$action = $_POST['action'] ?? '';

if ($action === 'mark_read') {
    $notificationId = (int)($_POST['notification_id'] ?? 0);
    if ($notificationId > 0) {
        $stmt = $pdo->prepare('UPDATE notifications SET is_read = 1 WHERE notification_id = ? AND user_id = ?');
        $stmt->execute([$notificationId, $userId]);
    }
} elseif ($action === 'mark_all_read') {
    $stmt = $pdo->prepare('UPDATE notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0');
    $stmt->execute([$userId]);
}

$redirect = $_POST['redirect'] ?? 'notifications.php';
if (!is_string($redirect) || !preg_match('/^(?:notifications\.php|view\.php\?id=\d+)/', $redirect)) {
    $redirect = 'notifications.php';
}
header('Location: ' . $redirect);
exit();
