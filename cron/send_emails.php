<?php
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI sahaja.\n");
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../vendor/autoload.php';

use PHPMailer\PHPMailer\Exception;
use PHPMailer\PHPMailer\PHPMailer;

$mailConfigPath = __DIR__ . '/../config/mail.php';
if (!is_file($mailConfigPath)) {
    fwrite(STDERR, "Konfigurasi config/mail.php tidak dijumpai. Salin config/mail.example.php.\n");
    exit(1);
}
$mailConfig = require $mailConfigPath;

$stmt = $pdo->query(
    "SELECT email_id, booking_no, recipient_email, email_type, subject, body, attempts
     FROM email_notifications
     WHERE status = 'Pending' AND attempts < 3
     ORDER BY email_id ASC
     LIMIT 25"
);
$emails = $stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($emails as $email) {
    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host = $mailConfig['host'];
        $mail->Port = (int)$mailConfig['port'];
        $mail->SMTPAuth = true;
        $mail->Username = $mailConfig['username'];
        $mail->Password = $mailConfig['password'];
        $mail->SMTPSecure = $mailConfig['encryption'] ?? PHPMailer::ENCRYPTION_STARTTLS;
        $mail->CharSet = 'UTF-8';
        $mail->setFrom($mailConfig['from_email'], $mailConfig['from_name'] ?? 'Kembara');
        $mail->addAddress($email['recipient_email']);
        $mail->Subject = $email['subject'];
        $mail->Body = $email['body'];
        $mail->isHTML(false);
        $mail->send();

        $update = $pdo->prepare("UPDATE email_notifications SET status='Sent', sent_at=NOW(), last_error=NULL WHERE email_id=?");
        $update->execute([$email['email_id']]);
        echo "Sent #{$email['email_id']}\n";
    } catch (Exception $exception) {
        $attempts = (int)$email['attempts'] + 1;
        $status = $attempts >= 3 ? 'Failed' : 'Pending';
        $update = $pdo->prepare('UPDATE email_notifications SET status=?, attempts=?, last_error=? WHERE email_id=?');
        $update->execute([$status, $attempts, mb_substr($exception->getMessage(), 0, 255), $email['email_id']]);
        fwrite(STDERR, "Failed #{$email['email_id']}: {$exception->getMessage()}\n");
    }
}
