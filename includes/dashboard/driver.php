<?php
// Paparan dashboard khusus pemandu.
$currentUserId = (int)($_SESSION['user_id'] ?? 0);
$driverStmt = $pdo->prepare(
    "SELECT dr.driver_id, dr.license, dr.status, v.vehicle_id, v.plate_no, v.vehicle_name,
            v.status AS vehicle_status, v.road_tax_expiry
     FROM drivers dr
     LEFT JOIN vehicles v ON v.driver_id = dr.driver_id
     WHERE dr.user_id = ?"
);
$driverStmt->execute([$currentUserId]);
$driver = $driverStmt->fetch(PDO::FETCH_ASSOC);
$driverId = (int)($driver['driver_id'] ?? 0);

$pendingStmt = $pdo->prepare(
    "SELECT vb.booking_id, vb.booking_no, vb.origin, vb.destination, vb.depart_datetime,
            vb.return_datetime, vb.passenger_total, u.fullname AS requester_name, u.phone_no
     FROM vehicle_bookings vb
     JOIN users u ON u.user_id = vb.user_id
     WHERE vb.driver_id = ? AND vb.status = 'Pending' AND vb.workflow_stage = 'DriverAssigned'
     ORDER BY vb.depart_datetime ASC"
);
$pendingStmt->execute([$driverId]);
$pendingTrips = $pendingStmt->fetchAll(PDO::FETCH_ASSOC);

$upcomingStmt = $pdo->prepare(
    "SELECT vb.booking_id, vb.booking_no, vb.origin, vb.destination, vb.depart_datetime,
            vb.return_datetime, vb.passenger_total, u.fullname AS requester_name, u.phone_no
     FROM vehicle_bookings vb
     JOIN users u ON u.user_id = vb.user_id
     WHERE vb.driver_id = ? AND vb.status = 'Approved' AND vb.workflow_stage = 'AdminApproved'
     ORDER BY vb.depart_datetime ASC
     LIMIT 6"
);
$upcomingStmt->execute([$driverId]);
$upcomingTrips = $upcomingStmt->fetchAll(PDO::FETCH_ASSOC);

$todayStmt = $pdo->prepare(
    "SELECT COUNT(*) FROM vehicle_bookings
     WHERE driver_id = ? AND status = 'Approved' AND DATE(depart_datetime) = CURRENT_DATE()"
);
$todayStmt->execute([$driverId]);
$tripsToday = (int)$todayStmt->fetchColumn();

$weekStmt = $pdo->prepare(
    "SELECT COUNT(*) FROM vehicle_bookings
     WHERE driver_id = ? AND status = 'Approved'
       AND depart_datetime >= CURRENT_DATE()
       AND depart_datetime < DATE_ADD(CURRENT_DATE(), INTERVAL 7 DAY)"
);
$weekStmt->execute([$driverId]);
$tripsThisWeek = (int)$weekStmt->fetchColumn();

$completedStmt = $pdo->prepare(
    "SELECT COUNT(*) FROM vehicle_bookings
     WHERE driver_id = ? AND status = 'Completed'
       AND MONTH(depart_datetime) = MONTH(CURRENT_DATE())
       AND YEAR(depart_datetime) = YEAR(CURRENT_DATE())"
);
$completedStmt->execute([$driverId]);
$completedThisMonth = (int)$completedStmt->fetchColumn();

$pageTitle = 'Dashboard';
$showSearch = false;
include __DIR__ . '/../layout_header.php';
?>
<main class="xl:ml-64 relative min-h-screen pb-24 xl:pb-0">
    <?php include __DIR__ . '/../top_nav.php'; ?>
    <div class="w-full px-4 sm:px-6 py-6 mx-auto max-w-6xl">
        <div class="card p-6 mb-5">
            <p class="text-xs uppercase tracking-widest mb-1" style="color:var(--ta-muted)">Dashboard Pemandu</p>
            <h1 class="text-2xl font-bold">Selamat datang, <?= htmlspecialchars($_SESSION['fullname'] ?? '') ?></h1>
            <p class="text-sm mt-1" style="color:var(--ta-muted)">Semak tugasan dan perjalanan anda.</p>
        </div>

        <div class="grid grid-cols-2 sm:grid-cols-4 gap-4 mb-5">
            <div class="card p-5"><p class="text-xs" style="color:var(--ta-muted)">Tugasan Menunggu</p><p class="text-2xl font-bold mt-2"><?= count($pendingTrips) ?></p></div>
            <div class="card p-5"><p class="text-xs" style="color:var(--ta-muted)">Perjalanan Hari Ini</p><p class="text-2xl font-bold mt-2"><?= $tripsToday ?></p></div>
            <div class="card p-5"><p class="text-xs" style="color:var(--ta-muted)">Minggu Ini</p><p class="text-2xl font-bold mt-2"><?= $tripsThisWeek ?></p></div>
            <div class="card p-5"><p class="text-xs" style="color:var(--ta-muted)">Selesai Bulan Ini</p><p class="text-2xl font-bold mt-2"><?= $completedThisMonth ?></p></div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-5">
            <section class="card p-5">
                <h2 class="font-semibold text-lg mb-1">Tugasan Menunggu Respons</h2>
                <p class="text-sm mb-4" style="color:var(--ta-muted)">Terima atau tolak tugasan yang diberikan kepada anda.</p>
                <?php if (!$pendingTrips): ?>
                    <p class="text-sm" style="color:var(--ta-muted)">Tiada tugasan menunggu.</p>
                <?php else: ?>
                    <div class="flex flex-col gap-3">
                    <?php foreach ($pendingTrips as $trip): ?>
                        <div class="rounded-xl border p-4" style="border-color:var(--ta-border)">
                            <a href="view.php?id=<?= (int)$trip['booking_id'] ?>" class="font-semibold hover:underline"><?= htmlspecialchars($trip['booking_no']) ?></a>
                            <p class="text-sm mt-1"><?= htmlspecialchars($trip['origin']) ?> → <?= htmlspecialchars($trip['destination']) ?></p>
                            <p class="text-xs mt-1" style="color:var(--ta-muted)"><?= htmlspecialchars(date('d M Y, H:i', strtotime($trip['depart_datetime']))) ?> · <?= (int)$trip['passenger_total'] ?> penumpang</p>
                            <p class="text-xs mt-1" style="color:var(--ta-muted)">Pemohon: <?= htmlspecialchars($trip['requester_name']) ?> · <?= htmlspecialchars($trip['phone_no'] ?: 'Tiada telefon') ?></p>
                            <div class="flex gap-2 mt-3">
                                <form method="POST" action="bookings.php"><input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>"><input type="hidden" name="action" value="driver_accept"><input type="hidden" name="booking_id" value="<?= (int)$trip['booking_id'] ?>"><button class="btn btn-success btn-sm text-white">Terima</button></form>
                                <a href="view.php?id=<?= (int)$trip['booking_id'] ?>" class="btn btn-error btn-sm text-white">Tolak dengan sebab</a>
                            </div>
                        </div>
                    <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>

            <section class="card p-5">
                <h2 class="font-semibold text-lg mb-1">Perjalanan Seterusnya</h2>
                <p class="text-sm mb-4" style="color:var(--ta-muted)">Perjalanan yang telah anda terima.</p>
                <?php if (!$upcomingTrips): ?>
                    <p class="text-sm" style="color:var(--ta-muted)">Tiada perjalanan akan datang.</p>
                <?php else: ?>
                    <div class="flex flex-col gap-3">
                    <?php foreach ($upcomingTrips as $trip): ?>
                        <a href="view.php?id=<?= (int)$trip['booking_id'] ?>" class="rounded-xl border p-4 block hover:bg-base-200/50" style="border-color:var(--ta-border)">
                            <p class="font-semibold"><?= htmlspecialchars($trip['origin']) ?> → <?= htmlspecialchars($trip['destination']) ?></p>
                            <p class="text-xs mt-1" style="color:var(--ta-muted)"><?= htmlspecialchars(date('d M Y, H:i', strtotime($trip['depart_datetime']))) ?> · Pemohon: <?= htmlspecialchars($trip['requester_name']) ?></p>
                        </a>
                    <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>
        </div>

        <section class="card p-5 mt-5">
            <h2 class="font-semibold text-lg mb-4">Kenderaan Saya</h2>
            <?php if (!empty($driver['vehicle_id'])): ?>
                <div class="flex items-center justify-between gap-4 flex-wrap">
                    <div><p class="font-semibold"><?= htmlspecialchars($driver['vehicle_name'] ?: 'Kenderaan') ?> · <?= htmlspecialchars($driver['plate_no']) ?></p><p class="text-sm mt-1" style="color:var(--ta-muted)">Cukai jalan tamat: <?= htmlspecialchars($driver['road_tax_expiry'] ?: '—') ?></p></div>
                    <span class="ta-badge badge badge-info"><?= htmlspecialchars($driver['vehicle_status']) ?></span>
                </div>
            <?php else: ?><p class="text-sm" style="color:var(--ta-muted)">Tiada kenderaan ditugaskan.</p><?php endif; ?>
        </section>
    </div>
</main>
<?php include __DIR__ . '/../layout_footer.php'; ?>
