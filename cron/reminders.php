<?php
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI sahaja.\n");
}

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/notify.php';

function reminderExists(PDO $pdo, int $userId, ?int $bookingId, string $type, string $since): bool {
    $stmt = $pdo->prepare(
        'SELECT 1 FROM notifications
         WHERE user_id = ? AND booking_id <=> ? AND type = ? AND created_at >= ? LIMIT 1'
    );
    $stmt->execute([$userId, $bookingId, $type, $since]);
    return (bool)$stmt->fetchColumn();
}

function queueReminder(PDO $pdo, int $userId, ?int $bookingId, string $type, string $title, string $message, ?string $link = null, bool $email = false): void {
    if (reminderExists($pdo, $userId, $bookingId, $type, date('Y-m-d H:i:s', strtotime('-7 days')))) {
        return;
    }
    notify_user($pdo, $userId, $bookingId, $type, $title, $message, $link, $email);
}

// Trip tomorrow: requester and accepted driver.
$tomorrowStmt = $pdo->query(
    "SELECT vb.booking_id, vb.booking_no, vb.user_id, vb.driver_id, vb.depart_datetime,
            vb.destination, dr.user_id AS driver_user_id
     FROM vehicle_bookings vb
     LEFT JOIN drivers dr ON dr.driver_id = vb.driver_id
     WHERE vb.status = 'Approved'
       AND vb.depart_datetime >= DATE_ADD(CURRENT_DATE(), INTERVAL 1 DAY)
       AND vb.depart_datetime < DATE_ADD(CURRENT_DATE(), INTERVAL 2 DAY)"
);
foreach ($tomorrowStmt->fetchAll(PDO::FETCH_ASSOC) as $booking) {
    $link = 'view.php?id=' . (int)$booking['booking_id'];
    queueReminder($pdo, (int)$booking['user_id'], (int)$booking['booking_id'], 'trip_tomorrow', 'Perjalanan esok hari', "Tempahan {$booking['booking_no']} ke {$booking['destination']} berlepas esok.", $link, true);
    if (!empty($booking['driver_user_id'])) {
        queueReminder($pdo, (int)$booking['driver_user_id'], (int)$booking['booking_id'], 'driver_trip_tomorrow', 'Tugasan esok hari', "Tugasan {$booking['booking_no']} ke {$booking['destination']} berlepas esok.", $link, true);
    }
}

// Driver reminder two hours before departure.
$twoHourStmt = $pdo->query(
    "SELECT vb.booking_id, vb.booking_no, vb.destination, vb.depart_datetime, dr.user_id AS driver_user_id
     FROM vehicle_bookings vb
     JOIN drivers dr ON dr.driver_id = vb.driver_id
     WHERE vb.status = 'Approved'
       AND vb.depart_datetime BETWEEN DATE_ADD(NOW(), INTERVAL 105 MINUTE) AND DATE_ADD(NOW(), INTERVAL 135 MINUTE)"
);
foreach ($twoHourStmt->fetchAll(PDO::FETCH_ASSOC) as $booking) {
    queueReminder($pdo, (int)$booking['driver_user_id'], (int)$booking['booking_id'], 'driver_two_hour', 'Tugasan berlepas dalam 2 jam', "Tugasan {$booking['booking_no']} ke {$booking['destination']} berlepas dalam kira-kira 2 jam.", 'view.php?id=' . (int)$booking['booking_id'], true);
}

// Admin queue reminders.
$adminIds = $pdo->query("SELECT user_id FROM users WHERE role IN ('Admin', 'SuperAdmin')")->fetchAll(PDO::FETCH_COLUMN);
$adminChecks = [
    ['type' => 'admin_unassigned', 'title' => 'Tempahan belum ditugaskan', 'message' => 'Terdapat tempahan yang masih menunggu pemandu.', 'sql' => "SELECT COUNT(*) FROM vehicle_bookings WHERE status='Pending' AND workflow_stage IN ('Submitted','ReassignmentRequired')"],
    ['type' => 'admin_driver_response', 'title' => 'Respons pemandu tertangguh', 'message' => 'Terdapat tugasan pemandu yang belum menerima respons lebih 4 jam.', 'sql' => "SELECT COUNT(*) FROM vehicle_bookings vb WHERE vb.status='Pending' AND vb.workflow_stage='DriverAssigned' AND EXISTS (SELECT 1 FROM booking_history bh WHERE bh.booking_id=vb.booking_id AND bh.action='Driver Assigned' AND bh.action_datetime <= DATE_SUB(NOW(), INTERVAL 4 HOUR))"],
    ['type' => 'admin_close_trip', 'title' => 'Tempahan sedia ditamatkan', 'message' => 'Terdapat tempahan diluluskan yang telah melepasi masa pulang.', 'sql' => "SELECT COUNT(*) FROM vehicle_bookings WHERE status='Approved' AND return_datetime IS NOT NULL AND return_datetime < NOW()"],
];
foreach ($adminChecks as $check) {
    try {
        if ((int)$pdo->query($check['sql'])->fetchColumn() > 0) {
            foreach ($adminIds as $adminId) {
                queueReminder($pdo, (int)$adminId, null, $check['type'], $check['title'], $check['message'], 'bookings.php?status=Pending');
            }
        }
    } catch (PDOException $exception) {
        fwrite(STDERR, "Reminder diabaikan ({$check['type']}): {$exception->getMessage()}\n");
    }
}

echo "Reminders processed.\n";
