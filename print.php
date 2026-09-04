<?php
session_start();
require_once __DIR__ . '/config/database.php';

if (!isset($_SESSION['user_id'])) {
		header('Location: login.php');
		exit();
}

$currentUserId = (int)$_SESSION['user_id'];
$role = $_SESSION['role'] ?? 'User';
$bookingId = (int)($_GET['id'] ?? 0);

if ($bookingId <= 0) {
		http_response_code(400);
		exit('Tempahan tidak sah.');
}

$stmt = $pdo->prepare(
		"SELECT vb.*, u.fullname AS requester_name, u.phone_no,
					d.department_name,
					v.plate_no, v.vehicle_name,
					du.fullname AS driver_name,
					ap.fullname AS approved_by_name
		 FROM vehicle_bookings vb
		 JOIN users u ON u.user_id = vb.user_id
		 LEFT JOIN departments d ON d.department_id = u.department_id
		 LEFT JOIN vehicles v ON v.vehicle_id = vb.vehicle_id
		 LEFT JOIN drivers dr ON dr.driver_id = vb.driver_id
		 LEFT JOIN users du ON du.user_id = dr.user_id
		 LEFT JOIN users ap ON ap.user_id = vb.approved_by
		 WHERE vb.booking_id = ?"
);
$stmt->execute([$bookingId]);
$booking = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$booking || ($role === 'User' && (int)$booking['user_id'] !== $currentUserId)) {
		http_response_code(404);
		exit('Tempahan tidak dijumpai.');
}

$passengerNames = $booking['passenger_names']
		? implode(', ', json_decode($booking['passenger_names'], true) ?: [])
		: '-';
$departDate = date('d/m/Y', strtotime($booking['depart_datetime']));
$departTime = date('g.i', strtotime($booking['depart_datetime']));
$departMeridiem = date('A', strtotime($booking['depart_datetime'])) === 'AM' ? 'pagi' : 'petang';
$returnDate = $booking['return_datetime'] ? date('d/m/Y', strtotime($booking['return_datetime'])) : '';
$returnTime = $booking['return_datetime'] ? date('g.i', strtotime($booking['return_datetime'])) : '';
$returnMeridiem = $booking['return_datetime'] && date('A', strtotime($booking['return_datetime'])) === 'AM' ? 'pagi' : 'petang';
$passengerNames = $booking['passenger_names']
		? implode(', ', json_decode($booking['passenger_names'], true) ?: [])
		: '';
$formPurpose = trim($booking['purpose'] ?? '');
?>
<!DOCTYPE html>
<html lang="ms">
<head>
	<meta charset="UTF-8" />
	<meta name="viewport" content="width=device-width, initial-scale=1.0" />
	<title>Borang Permohonan Kenderaan - <?= htmlspecialchars($booking['booking_no']) ?></title>
	<style>
		@page { size: A4; margin: 0; }
		* { box-sizing: border-box; }
		body { margin: 0; background: #e5e7eb; color: #111; font-family: Arial, Helvetica, sans-serif; font-size: 12px; }
		.page { width: 210mm; min-height: 297mm; margin: 12px auto; padding: 22mm 24mm 18mm; background: #fff; }
		.heading { text-align: center; font-size: 13px; font-weight: 700; line-height: 1.15; }
		.intro { margin: 24px 0 22px; line-height: 1.2; text-align: justify; }
		.form-row { display: flex; align-items: end; min-height: 25px; }
		.label { width: 178px; flex: 0 0 178px; }
		.colon { width: 18px; flex: 0 0 18px; text-align: center; }
		.line { min-width: 0; flex: 1; min-height: 18px; padding: 0 4px 2px; border-bottom: 1px dotted #111; }
		.line.short { flex: 0 0 132px; }
		.line.time { flex: 0 0 74px; }
		.time-label { margin-left: 12px; white-space: nowrap; }
		.purpose { min-height: 52px; align-items: start; }
		.purpose .line { min-height: 52px; line-height: 18px; }
		.passengers { min-height: 100px; align-items: start; }
		.passengers .line { min-height: 100px; line-height: 21px; }
		.declaration { margin-top: 22px; line-height: 1.15; text-align: justify; }
		.signatures { display: grid; grid-template-columns: 1fr 1fr; gap: 28mm; margin-top: 64px; }
		.signature-line { height: 1px; border-bottom: 1px dotted #111; }
		.signature-label { text-align: center; margin-top: 3px; line-height: 1.1; }
		.signature-date { margin-top: 40px; }
		.actions { display: flex; gap: 8px; margin: 20px auto; width: 210mm; }
		button, .back-link { padding: 8px 14px; border: 1px solid #888; background: #fff; color: #111; text-decoration: none; cursor: pointer; }
		@media print { body { background: #fff; } .page { margin: 0; } .actions { display: none; } }
		@media screen and (max-width: 800px) { .page, .actions { width: 100%; } .page { min-height: 0; padding: 28px 24px; } .label { width: 145px; flex-basis: 145px; } .line.short, .line.time { flex-basis: auto; } .time-label { margin-left: 6px; } }
	</style>
</head>
<body>
	<main class="page">
		<div class="heading">PERMOHONAN PENGGUNAAN KENDERAAN JABATAN<br />PERBENDAHARAAN NEGERI SELANGOR</div>
		<p class="intro">Adalah saya bernama seperti di bawah memohon menggunakan Kenderaan Perbendaharaan Negeri Selangor. Butir-butir permohonan adalah seperti berikut:</p>

		<div class="form-row"><span class="label">NAMA PEMOHON</span><span class="colon">:</span><span class="line"><?= htmlspecialchars($booking['requester_name']) ?></span></div>
		<div class="form-row"><span class="label">BAHAGIAN/UNIT</span><span class="colon">:</span><span class="line"><?= htmlspecialchars($booking['department_name'] ?: '') ?></span></div>
		<div class="form-row"><span class="label">NO. TELEFON/SAMBUNGAN</span><span class="colon">:</span><span class="line"><?= htmlspecialchars($booking['phone_no'] ?: '') ?></span></div>
		<div class="form-row"><span class="label">TARIKH PERGI</span><span class="colon">:</span><span class="line short"><?= htmlspecialchars($departDate) ?></span><span class="time-label">MASA:</span><span class="line time"><?= htmlspecialchars($departTime) ?></span><span class="time-label"><?= htmlspecialchars($departMeridiem) ?></span></div>
		<div class="form-row"><span class="label">TARIKH BALIK</span><span class="colon">:</span><span class="line short"><?= htmlspecialchars($returnDate) ?></span><span class="time-label">MASA:</span><span class="line time"><?= htmlspecialchars($returnTime) ?></span><span class="time-label"><?= htmlspecialchars($returnMeridiem) ?></span></div>
		<div class="form-row"><span class="label">DESTINASI</span><span class="colon">:</span><span class="line"><?= htmlspecialchars($booking['destination']) ?></span></div>
		<div class="form-row purpose"><span class="label">TUJUAN</span><span class="colon">:</span><span class="line"><?= nl2br(htmlspecialchars($formPurpose)) ?></span></div>
		<div class="form-row passengers"><span class="label">BILANGAN PENGGUNA<br />(NAMA PENGGUNA)</span><span class="colon">:</span><span class="line"><?= htmlspecialchars((string)($booking['passenger_total'] ?: '')) ?><?= $passengerNames ? '<br />' . htmlspecialchars($passengerNames) : '' ?></span></div>

		<p class="declaration">Disahkan bahawa permohonan ini adalah untuk urusan rasmi. Saya berjanji akan memastikan kenderaan tersebut digunakan dengan cermat untuk tujuan yang dinyatakan dan dijaga dengan selamat. Saya juga akan memastikan kenderaan berkenaan dikembalikan kepada Perbendaharaan Negeri Selangor pada tarikh dan masa yang dinyatakan dalam keadaan yang baik dan bersih serta selamat digunakan.</p>

		<div class="signatures">
			<div><div class="signature-line"></div><div class="signature-label">(Tandatangan/Cop Pemohon)</div><div class="signature-date">Tarikh:</div></div>
			<div><div class="signature-line"></div><div class="signature-label">(Tandatangan/Cop Ketua Bahagian/Unit)</div><div class="signature-date">Tarikh:</div></div>
		</div>
	</main>
	<div class="actions"><button type="button" onclick="window.print()">Cetak</button><a class="back-link" href="view.php?id=<?= (int)$bookingId ?>">Kembali</a></div>
	<script>window.addEventListener('load', function () { window.print(); });</script>
</body>
</html>
