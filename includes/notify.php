<?php
/**
 * Notification helpers for in-app and queued email notifications.
 */

if (!function_exists('notify_user')) {
    function notify_user(
        PDO $pdo,
        int $userId,
        ?int $bookingId,
        string $type,
        string $title,
        string $message,
        ?string $link = null,
        bool $email = false
    ): void {
        $notificationStmt = $pdo->prepare(
            'INSERT INTO notifications (user_id, booking_id, type, title, message, link, is_read)
             VALUES (?, ?, ?, ?, ?, ?, 0)'
        );
        $notificationStmt->execute([$userId, $bookingId, $type, $title, $message, $link]);

        if (!$email) {
            return;
        }

        $recipientStmt = $pdo->prepare('SELECT email FROM users WHERE user_id = ?');
        $recipientStmt->execute([$userId]);
        $recipientEmail = $recipientStmt->fetchColumn();
        if (!$recipientEmail) {
            return;
        }

        $bookingNo = null;
        if ($bookingId !== null) {
            $bookingStmt = $pdo->prepare('SELECT booking_no FROM vehicle_bookings WHERE booking_id = ?');
            $bookingStmt->execute([$bookingId]);
            $bookingNo = $bookingStmt->fetchColumn() ?: null;
        }

        $emailStmt = $pdo->prepare(
            'INSERT INTO email_notifications
                (booking_no, recipient_email, email_type, subject, body, status, attempts)
             VALUES (?, ?, ?, ?, ?, \'Pending\', 0)'
        );
        $emailStmt->execute([$bookingNo, $recipientEmail, $type, $title, $message]);
    }
}

if (!function_exists('notify_admins')) {
    function notify_admins(
        PDO $pdo,
        ?int $bookingId,
        string $type,
        string $title,
        string $message,
        ?string $link = null,
        bool $email = false
    ): void {
        $adminStmt = $pdo->query("SELECT user_id FROM users WHERE role IN ('Admin', 'SuperAdmin')");
        foreach ($adminStmt->fetchAll(PDO::FETCH_COLUMN) as $adminId) {
            notify_user($pdo, (int)$adminId, $bookingId, $type, $title, $message, $link, $email);
        }
    }
}
