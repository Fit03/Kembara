<?php
// dashboard.php
require_once __DIR__ . '/includes/auth.php';
require_login();
require_once __DIR__ . '/config/database.php';


$fullname = $_SESSION['fullname'];
$role = $_SESSION['role']; // Boleh jadi 'SuperAdmin', 'Admin', atau 'User'

// -- Tarikh & ucapan selamat mengikut masa hari --
$dayNamesMY = ['Ahad', 'Isnin', 'Selasa', 'Rabu', 'Khamis', 'Jumaat', 'Sabtu'];
$monthNamesMY = ['Januari', 'Februari', 'Mac', 'April', 'Mei', 'Jun', 'Julai', 'Ogos', 'September', 'Oktober', 'November', 'Disember'];
$now = new DateTime();
$todayLabel = strtoupper($dayNamesMY[(int)$now->format('w')] . ', ' . $now->format('j') . ' ' . $monthNamesMY[(int)$now->format('n') - 1]);

$hour = (int) $now->format('G');
if ($hour < 12) {
    $greeting = 'Selamat Pagi';
} elseif ($hour < 15) {
    $greeting = 'Selamat Tengah Hari';
} elseif ($hour < 19) {
    $greeting = 'Selamat Petang';
} else {
    $greeting = 'Selamat Malam';
}
$firstName = trim(explode(' ', $fullname)[0]);

// Semak sama ada e-mel sudah disimpan dalam sesi daripada log masuk
$email = $_SESSION['email'] ?? null;

// Jika tiada dalam sesi, ambil terus dari pangkalan data menggunakan $pdo
if (!$email) {
    $stmt = $pdo->prepare("SELECT email FROM users WHERE user_id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $email = $stmt->fetchColumn() ?: 'tiada-emel@selangor.gov.my';
}

$avatarStmt = $pdo->prepare("SELECT profile_picture FROM users WHERE user_id = ?");
$avatarStmt->execute([$_SESSION['user_id']]);
$profilePicture = $avatarStmt->fetchColumn();
$hasPhoto = $profilePicture && is_file(__DIR__ . '/' . $profilePicture);

// Warna lencana berdasarkan peranan (daisyUI badges)
$badgeColor = match($role) {
  'SuperAdmin' => 'badge badge-error',
  'Admin'      => 'badge badge-warning',
  default      => 'badge badge-info',
};

/* ==========================================================
   Data Dashboard SuperAdmin
   ========================================================== */

// -- Kad Statistik --------------------------------------------------
$totalBookingsThisMonth = $pdo->query(
    "SELECT COUNT(*) FROM vehicle_bookings
     WHERE MONTH(depart_datetime) = MONTH(CURRENT_DATE())
       AND YEAR(depart_datetime) = YEAR(CURRENT_DATE())"
)->fetchColumn();

$pendingApprovals = $pdo->query(
  "SELECT COUNT(*) FROM vehicle_bookings
   WHERE status = 'Pending' AND workflow_stage IN ('Submitted', 'ReassignmentRequired')"
)->fetchColumn();

$vehicleCounts = $pdo->query(
    "SELECT effective_status AS status, COUNT(*) AS total
     FROM (
       SELECT v.vehicle_id,
              CASE
                WHEN EXISTS (
                  SELECT 1
                  FROM vehicle_bookings vb
                  WHERE vb.vehicle_id = v.vehicle_id
                    AND vb.status IN ('Pending', 'Approved')
                    AND vb.workflow_stage IN ('DriverAssigned', 'DriverAccepted', 'AdminApproved')
                ) THEN 'Booked'
                ELSE v.status
              END AS effective_status
       FROM vehicles v
     ) effective_vehicles
     GROUP BY effective_status"
)->fetchAll(PDO::FETCH_KEY_PAIR);
$totalVehicles     = array_sum($vehicleCounts);
$availableVehicles = $vehicleCounts['Available'] ?? 0;

$totalUsers = $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();

// -- Pecahan status tempahan (untuk carta donat) ------------------
$bookingStatusRows = $pdo->query(
    "SELECT status, COUNT(*) AS total FROM vehicle_bookings GROUP BY status"
)->fetchAll(PDO::FETCH_KEY_PAIR);
$bookingStatusLabels = ['Pending', 'Approved', 'Rejected', 'Cancelled', 'Completed'];
$bookingStatusData   = array_map(fn($s) => (int)($bookingStatusRows[$s] ?? 0), $bookingStatusLabels);

// -- Pecahan status kenderaan --------------------------------------
$vehicleStatusLabels = ['Available', 'Booked', 'Maintenance', 'Inactive'];
$vehicleStatusData   = array_map(fn($s) => (int)($vehicleCounts[$s] ?? 0), $vehicleStatusLabels);

// -- Tempahan mengikut jabatan --------------------------------------
$deptRows = $pdo->query(
    "SELECT d.department_name, COUNT(vb.booking_id) AS total
     FROM departments d
     LEFT JOIN users u ON u.department_id = d.department_id
     LEFT JOIN vehicle_bookings vb ON vb.user_id = u.user_id
     GROUP BY d.department_id, d.department_name
     ORDER BY total DESC"
)->fetchAll(PDO::FETCH_ASSOC);

// -- Dokumen kenderaan tamat tempoh dalam masa 30 hari -------------
$expiringDocs = $pdo->query(
   "SELECT vehicle_id, plate_no, vehicle_name, road_tax_expiry
     FROM vehicles
    WHERE road_tax_expiry IS NOT NULL
     AND road_tax_expiry <= DATE_ADD(CURRENT_DATE(), INTERVAL 30 DAY)
    ORDER BY road_tax_expiry ASC
     LIMIT 5"
)->fetchAll(PDO::FETCH_ASSOC);

// -- Ketersediaan pemandu + kenderaan yang didedikasikan kepada pemandu --
$drivers = $pdo->query(
    "SELECT
        dr.driver_id,
        dr.status,
        u.fullname,
        v.vehicle_name,
        v.vehicle_type,
        v.plate_no,
        v.status AS vehicle_status
     FROM drivers dr
     JOIN users u
        ON u.user_id = dr.user_id
     LEFT JOIN vehicles v
        ON v.driver_id = dr.driver_id
     ORDER BY
        FIELD(dr.status, 'Available', 'Leave', 'Inactive'),
        u.fullname
     LIMIT 6"
)->fetchAll(PDO::FETCH_ASSOC);

// -- Perjalanan akan datang (Menunggu / Diluluskan, paling awal dahulu) -
$upcomingTrips = $pdo->query(
    "SELECT vb.booking_id, vb.booking_no, u.fullname AS requester, v.plate_no, v.vehicle_name,
            vb.destination, vb.depart_datetime, vb.status
     FROM vehicle_bookings vb
     JOIN users u ON u.user_id = vb.user_id
     JOIN vehicles v ON v.vehicle_id = vb.vehicle_id
     WHERE vb.status IN ('Pending', 'Approved')
     ORDER BY vb.depart_datetime ASC
     LIMIT 6"
)->fetchAll(PDO::FETCH_ASSOC);

// -- Aktiviti terkini daripada booking_history ----------------------------
$recentActivity = $pdo->query(
    "SELECT bh.action, bh.remarks, bh.action_datetime, u.fullname AS action_by
     FROM booking_history bh
     JOIN users u ON u.user_id = bh.action_by
     ORDER BY bh.action_datetime DESC
     LIMIT 6"
)->fetchAll(PDO::FETCH_ASSOC);

// -- Status notifikasi e-mel ----------------------------------------
$emailHealth = $pdo->query(
    "SELECT status, COUNT(*) AS total FROM email_notifications GROUP BY status"
)->fetchAll(PDO::FETCH_KEY_PAIR);

// Lencana status dalam Bahasa Melayu
$statusBadge = fn(string $status) => match ($status) {
    'Pending'   => ['class' => 'badge badge-warning', 'label' => 'Dalam Proses'],
    'Approved'  => ['class' => 'badge badge-success', 'label' => 'Diluluskan'],
    'Rejected'  => ['class' => 'badge badge-error',   'label' => 'Ditolak'],
    'Cancelled' => ['class' => 'badge badge-ghost',   'label' => 'Dibatalkan'],
    'Completed' => ['class' => 'badge badge-info',    'label' => 'Selesai'],
    default     => ['class' => 'badge badge-ghost',   'label' => $status],
};

/* ==========================================================
   Data Dashboard Admin (turut dipaparkan kepada SuperAdmin,
   kerana kedua-dua peranan ini boleh meluluskan tempahan)
   ========================================================== */
$isApprover = in_array($role, ['Admin', 'SuperAdmin'], true);

if ($isApprover) {

    // -- Kad statistik operasi harian --
    $tripsDepartingToday = $pdo->query(
        "SELECT COUNT(*) FROM vehicle_bookings
         WHERE status = 'Approved' AND DATE(depart_datetime) = CURRENT_DATE()"
    )->fetchColumn();

    $driversOnLeave = $pdo->query(
        "SELECT COUNT(*) FROM drivers WHERE status = 'Leave'"
    )->fetchColumn();
}

/* ==========================================================
   Data Dashboard User (kandungan peribadi sahaja, bukan
   statistik seluruh organisasi)
   ========================================================== */
if ($role === 'User') {
    $uid = $_SESSION['user_id'];

    $myActiveBookingsCount = (function () use ($pdo, $uid) {
      $s = $pdo->prepare(
        "SELECT COUNT(*)
         FROM vehicle_bookings vb
         LEFT JOIN drivers assigned_driver ON assigned_driver.driver_id = vb.driver_id
         WHERE (vb.user_id = :uid AND vb.status IN ('Pending','Approved'))
          OR (assigned_driver.user_id = :driver_uid AND vb.status IN ('Pending','Approved'))"
      );
      $s->execute([':uid' => $uid, ':driver_uid' => $uid]);
        return (int) $s->fetchColumn();
    })();

    $myPendingCount = (function () use ($pdo, $uid) {
      $s = $pdo->prepare(
        "SELECT COUNT(*)
         FROM vehicle_bookings vb
         LEFT JOIN drivers assigned_driver ON assigned_driver.driver_id = vb.driver_id
         WHERE (vb.user_id = :uid AND vb.status = 'Pending')
          OR (assigned_driver.user_id = :driver_uid
            AND vb.status = 'Pending'
            AND vb.workflow_stage = 'DriverAssigned')"
      );
      $s->execute([':uid' => $uid, ':driver_uid' => $uid]);
        return (int) $s->fetchColumn();
    })();

    $myCompletedThisMonth = (function () use ($pdo, $uid) {
        $s = $pdo->prepare(
            "SELECT COUNT(*) FROM vehicle_bookings
             WHERE user_id = ? AND status = 'Completed'
               AND MONTH(depart_datetime) = MONTH(CURRENT_DATE())
               AND YEAR(depart_datetime) = YEAR(CURRENT_DATE())"
        );
        $s->execute([$uid]);
        return (int) $s->fetchColumn();
    })();

    $myUpcomingStmt = $pdo->prepare(
        "SELECT vb.booking_id, vb.booking_no, v.plate_no, v.vehicle_name, vb.destination, vb.depart_datetime, vb.status
         FROM vehicle_bookings vb
         LEFT JOIN vehicles v ON v.vehicle_id = vb.vehicle_id
         LEFT JOIN drivers assigned_driver ON assigned_driver.driver_id = vb.driver_id
         WHERE ((vb.user_id = :uid) OR (assigned_driver.user_id = :driver_uid))
           AND vb.status IN ('Pending', 'Approved')
         ORDER BY vb.depart_datetime ASC"
    );
    $myUpcomingStmt->execute([':uid' => $uid, ':driver_uid' => $uid]);
    $myUpcomingTrips = $myUpcomingStmt->fetchAll(PDO::FETCH_ASSOC);

    $myNextTrip = $myUpcomingTrips[0] ?? null;
    $daysUntilNextTrip = $myNextTrip
        ? max(0, (int) ceil((strtotime($myNextTrip['depart_datetime']) - time()) / 86400))
        : null;

    $myHistoryStmt = $pdo->prepare(
           "SELECT vb.booking_id, vb.booking_no, v.plate_no, v.vehicle_name, vb.destination, vb.depart_datetime, vb.status
         FROM vehicle_bookings vb
            LEFT JOIN vehicles v ON v.vehicle_id = vb.vehicle_id
            LEFT JOIN drivers assigned_driver ON assigned_driver.driver_id = vb.driver_id
            WHERE vb.user_id = :uid OR assigned_driver.user_id = :driver_uid
         ORDER BY vb.depart_datetime DESC
         LIMIT 8"
    );
          $myHistoryStmt->execute([':uid' => $uid, ':driver_uid' => $uid]);
    $myBookingHistory = $myHistoryStmt->fetchAll(PDO::FETCH_ASSOC);
}

// --- Layout Config ---
$pageTitle = "Dashboard";
$showSearch = true;
$searchAction = "dashboard.php";
$searchPlaceholder = "Cari tempahan, kenderaan...";
$extraCSS = '<!-- ApexCharts CDN --><script src="https://cdn.jsdelivr.net/npm/apexcharts"></script>';

include 'includes/layout_header.php';
?>
<main class="xl:ml-64 relative min-h-screen pb-24 xl:pb-0">
    <?php include 'includes/top_nav.php'; ?>
    <div class="w-full px-4 sm:px-6 py-6 mx-auto">

        <!-- Ucapan & Tarikh -->
        <div class="card p-6 mb-5">
          <p class="text-xs font-semibold tracking-widest mb-2" style="color:var(--ta-muted)"><?= htmlspecialchars($todayLabel) ?></p>
          <h4 class="text-2xl sm:text-3xl font-bold mb-0">
            <?= htmlspecialchars($greeting) ?>, <span style="color:var(--ta-brand)"><?= htmlspecialchars($firstName) ?></span>
          </h4>
        </div>

        <?php if ($role === 'User'): ?>
        <!-- ============ Paparan Peribadi (User) ============ -->

        <!-- Kad Statistik Peribadi -->
        <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-5">
          <div class="card p-5" data-href="bookings.php?mine=1">
            <div class="ta-icon-box mb-4">
              <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6"><path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 012.25-2.25h13.5A2.25 2.25 0 0121 7.5v11.25m-18 0A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75m-18 0v-7.5A2.25 2.25 0 015.25 9h13.5A2.25 2.25 0 0121 11.25v7.5" /></svg>
            </div>
            <p class="text-sm mb-1" style="color:var(--ta-muted)">Tempahan Aktif Saya</p>
            <h5 class="text-2xl font-bold"><?= (int)$myActiveBookingsCount ?></h5>
          </div>

          <?php if ($myPendingCount > 0): ?>
          <div class="aura aura-dual text-yellow-600 bg-orange-200 duration-3000">
          <?php endif; ?>
            <div class="card p-5" data-href="bookings.php?mine=1&status=Pending" data-priority="warning">
              <div class="ta-icon-box ta-icon-box-green mb-4">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
              </div>
              <p class="text-sm mb-1" style="color:var(--ta-muted)">Menunggu Tindakan</p>
              <h5 class="text-2xl font-bold"><?= (int)$myPendingCount ?></h5>
            </div>
          <?php if ($myPendingCount > 0): ?>
          </div>
          <?php endif; ?>

          <div class="card p-5" data-href="bookings.php?mine=1&status=Completed">
            <div class="ta-icon-box mb-4">
              <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" /></svg>
            </div>
            <p class="text-sm mb-1" style="color:var(--ta-muted)">Selesai Bulan Ini</p>
            <h5 class="text-2xl font-bold"><?= (int)$myCompletedThisMonth ?></h5>
          </div>

          <div class="card p-5">
            <div class="ta-icon-box mb-4">
              <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 6.75H15.75M8.25 6.75a2.25 2.25 0 01-2.25-2.25V4.5A2.25 2.25 0 018.25 2.25h7.5A2.25 2.25 0 0118 4.5v.75a2.25 2.25 0 01-2.25 2.25M8.25 6.75v10.5a2.25 2.25 0 002.25 2.25h3a2.25 2.25 0 002.25-2.25V6.75" /></svg>
            </div>
            <p class="text-sm mb-1" style="color:var(--ta-muted)">Hari Ke Perjalanan Seterusnya</p>
            <h5 class="text-2xl font-bold"><?= $daysUntilNextTrip !== null ? (int)$daysUntilNextTrip : '—' ?></h5>
          </div>
        </div>

        <!-- CTA Tempah Kenderaan -->
        <div class="card p-6 mt-5 flex items-center justify-between flex-wrap gap-4" style="background:var(--ta-brand-50)">
          <div>
            <h6 class="font-semibold text-lg mb-1">Perlu menempah kenderaan?</h6>
            <p class="text-sm mb-0" style="color:var(--ta-muted)">Buat tempahan baharu dalam masa beberapa minit sahaja.</p>
          </div>
          <a href="book-vehicle.php" class="btn rounded-full border-none text-white" style="background:var(--ta-brand)">
            Tempah Kenderaan
          </a>
        </div>

        <!-- Perjalanan Akan Datang Saya + Sejarah Tempahan Saya -->
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-5 mt-5">
          <div class="card p-5">
            <h6 class="font-semibold mb-4">Perjalanan Akan Datang Saya</h6>
            <?php if (empty($myUpcomingTrips)): ?>
              <p class="text-sm text-slate-400">Tiada perjalanan akan datang. <a href="book-vehicle.php" class="text-primary hover:underline">Tempah sekarang</a>.</p>
            <?php else: ?>
              <ul class="ta-divide">
                <?php foreach ($myUpcomingTrips as $t): ?>
                  <?php $badgeInfo = $statusBadge($t['status']); ?>
                  <li class="py-3 flex items-center justify-between gap-3" data-href="view.php?id=<?= (int)$t['booking_id'] ?>">
                    <div class="min-w-0">
                      <p class="mb-0 text-sm font-semibold truncate"><?= htmlspecialchars($t['plate_no']) ?> &middot; <?= htmlspecialchars($t['destination'] ?? '—') ?></p>
                      <p class="mb-0 text-xs text-slate-400"><?= htmlspecialchars(date('d M, H:i', strtotime($t['depart_datetime']))) ?></p>
                    </div>
                    <span class="ta-badge shrink-0 <?= $badgeInfo['class'] ?>"><?= htmlspecialchars($badgeInfo['label']) ?></span>
                  </li>
                <?php endforeach; ?>
              </ul>
            <?php endif; ?>
          </div>

          <div class="card p-5">
            <h6 class="font-semibold mb-4">Sejarah Tempahan Saya</h6>
            <?php if (empty($myBookingHistory)): ?>
              <p class="text-sm text-slate-400">Tiada sejarah tempahan lagi.</p>
            <?php else: ?>
              <ul class="ta-divide">
                <?php foreach ($myBookingHistory as $h): ?>
                  <?php $badgeInfo = $statusBadge($h['status']); ?>
                  <li class="py-3 flex items-center justify-between gap-3" data-href="view.php?id=<?= (int)$h['booking_id'] ?>">
                    <div class="min-w-0">
                      <p class="mb-0 text-sm font-semibold truncate"><?= htmlspecialchars($h['plate_no']) ?> &middot; <?= htmlspecialchars($h['destination'] ?? '—') ?></p>
                      <p class="mb-0 text-xs text-slate-400"><?= htmlspecialchars(date('d M, H:i', strtotime($h['depart_datetime']))) ?></p>
                    </div>
                    <span class="ta-badge shrink-0 <?= $badgeInfo['class'] ?>"><?= htmlspecialchars($badgeInfo['label']) ?></span>
                  </li>
                <?php endforeach; ?>
              </ul>
            <?php endif; ?>
          </div>
        </div>

        <?php else: ?>
        <!-- ============ Paparan Organisasi (Admin / SuperAdmin) ============ -->

        <!-- Baris 1: Kad Statistik -->
        <div class="grid grid-cols-2 sm:grid-cols-3 <?= $isApprover ? 'xl:grid-cols-6' : 'lg:grid-cols-4' ?> gap-5">
          <div class="card p-5" data-href="bookings.php">
            <div class="ta-icon-box ta-icon-box-blue mb-4">
              <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6"><path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 012.25-2.25h13.5A2.25 2.25 0 0121 7.5v11.25m-18 0A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75m-18 0v-7.5A2.25 2.25 0 015.25 9h13.5A2.25 2.25 0 0121 11.25v7.5" /></svg>
            </div>
            <p class="text-sm mb-1" style="color:var(--ta-muted)">Tempahan Bulan Ini</p>
            <h5 class="text-2xl font-bold"><?= (int)$totalBookingsThisMonth ?></h5>
          </div>

          <?php if ($pendingApprovals > 0): ?>
          <div class="aura aura-dual text-yellow-600 bg-orange-200 duration-3000">
          <?php endif; ?>
            <div class="card p-5" data-href="bookings.php?status=Pending" data-priority="warning">
              <div class="ta-icon-box mb-4">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
              </div>
              <p class="text-sm mb-1" style="color:var(--ta-muted)">Menunggu Kelulusan</p>
              <h5 class="text-2xl font-bold"><?= (int)$pendingApprovals ?></h5>
            </div>
          <?php if ($pendingApprovals > 0): ?>
          </div>
          <?php endif; ?>

          <div class="card p-5" data-href="vehicles.php">
            <div class="ta-icon-box ta-icon-box-purple mb-4">
              <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 18.75a1.5 1.5 0 01-3 0m3 0a1.5 1.5 0 00-3 0m3 0h6m-9 0H3.375a1.125 1.125 0 01-1.125-1.125V14.25m17.25 4.5a1.5 1.5 0 01-3 0m3 0a1.5 1.5 0 00-3 0m3 0h1.125c.621 0 1.129-.504 1.09-1.124a17.902 17.902 0 00-3.213-9.193 2.056 2.056 0 00-1.58-.86H14.25M16.5 18.75h-2.25m0-11.177v-.958c0-.568-.422-1.048-.987-1.106a48.554 48.554 0 00-10.026 0 1.106 1.106 0 00-.987 1.106v7.635m12-6.677v6.677m0 0h-12" /></svg>
            </div>
            <p class="text-sm mb-1" style="color:var(--ta-muted)">Kenderaan Sedia Ada</p>
            <h5 class="text-2xl font-bold"><?= (int)$availableVehicles ?> <span class="text-sm font-medium text-slate-400">/ <?= (int)$totalVehicles ?></span></h5>
          </div>

          <?php if ($role === 'SuperAdmin'): ?>
          <div class="card p-5" data-href="users.php">
          <?php else: ?>
          <div class="card p-5">
          <?php endif; ?>
            <div class="ta-icon-box ta-icon-box-orange mb-4">
              <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19.128a9.38 9.38 0 002.625.372 9.337 9.337 0 004.121-.952 4.125 4.125 0 00-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 018.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0111.964-3.07M12 6.375a3.375 3.375 0 11-6.75 0 3.375 3.375 0 016.75 0zm8.25 2.25a2.625 2.625 0 11-5.25 0 2.625 2.625 0 015.25 0z" /></svg>
            </div>
            <p class="text-sm mb-1" style="color:var(--ta-muted)">Jumlah Pengguna</p>
            <h5 class="text-2xl font-bold"><?= (int)$totalUsers ?></h5>
          </div>

          <?php if ($isApprover): ?>
          <div class="card p-5" data-href="bookings.php?status=Approved">
            <div class="ta-icon-box ta-icon-box-cyan mb-4">
              <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
            </div>
            <p class="text-sm mb-1" style="color:var(--ta-muted)">Perjalanan Bertolak Hari Ini</p>
            <h5 class="text-2xl font-bold"><?= (int)$tripsDepartingToday ?></h5>
          </div>
          <div class="card p-5" data-href="drivers.php?status=Leave">
            <div class="ta-icon-box ta-icon-box-pink mb-4">
              <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 8.25h19.5M2.25 9h19.5m-16.5 5.25h6m-6 2.25h3m-3.75 3h15a2.25 2.25 0 002.25-2.25V6.75A2.25 2.25 0 0019.5 4.5h-15a2.25 2.25 0 00-2.25 2.25v10.5A2.25 2.25 0 004.5 19.5z" /></svg>
            </div>
            <p class="text-sm mb-1" style="color:var(--ta-muted)">Pemandu Bercuti</p>
            <h5 class="text-2xl font-bold"><?= (int)$driversOnLeave ?></h5>
          </div>
          <?php endif; ?>
        </div>

        <!-- Baris 2: Carta ApexCharts -->
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-5 mt-5">
          <div class="card p-5">
            <div id="chart-booking-status"></div>
          </div>
          <div class="card p-5">
            <div id="chart-vehicle-status"></div>
          </div>
        </div>

        <!-- Baris 3: Dokumen Tamat Tempoh + Ketersediaan Pemandu + Kenderaan -->
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-5 mt-5">

          <!-- ==========================================================
              CARD 1: DOKUMEN TAMAT TEMPOH TERDEKAT
              ========================================================== -->
          <div class="card p-5">
            <h6 class="mb-3 font-semibold text-sm flex items-center gap-2">
              <svg xmlns="http://www.w3.org/2000/svg"
                  class="h-4 w-4"
                  style="color:var(--ta-muted)"
                  fill="none"
                  viewBox="0 0 24 24"
                  stroke="currentColor"
                  stroke-width="1.8">
                <path stroke-linecap="round"
                      stroke-linejoin="round"
                      d="M12 9v3.75m9-.75a9 9 0 11-18 0 9 9 0 0118 0zm-9 3.75h.008v.008H12v-.008z" />
              </svg>

              Dokumen Tamat Tempoh Terdekat
            </h6>

            <?php if (empty($expiringDocs)): ?>

              <p class="text-sm text-slate-400">
                Tiada dokumen yang tamat tempoh dalam masa 30 hari.
              </p>

            <?php else: ?>

              <ul class="ta-divide">

                <?php foreach ($expiringDocs as $doc): ?>

                  <?php
                    $today = new DateTime();

                    $roadTaxDate = !empty($doc['road_tax_expiry'])
                        ? new DateTime($doc['road_tax_expiry'])
                        : null;

                    $nearestDate = $roadTaxDate;

                    // Tentukan status warna
                    $daysRemaining = null;

                    if ($nearestDate) {
                        $daysRemaining = (int)$today->diff($nearestDate)->format('%r%a');
                    }

                    if ($daysRemaining !== null && $daysRemaining < 0) {

                        $expiryBadgeClass = 'badge badge-error';
                        $expiryLabel = 'Telah Tamat';

                    } elseif ($daysRemaining !== null && $daysRemaining <= 7) {

                        $expiryBadgeClass = 'badge badge-error';
                        $expiryLabel = $daysRemaining . ' hari lagi';

                    } else {

                        $expiryBadgeClass = 'badge badge-warning';
                        $expiryLabel = 'Baharui Segera';
                    }
                  ?>

                  <li class="py-3">

                    <a href="view-vehicle.php?id=<?= (int)$doc['vehicle_id'] ?>&amp;mode=view"
                       class="block -mx-2 px-2 rounded-lg transition-colors hover:bg-base-200/60">

                      <!-- Vehicle -->
                      <div class="flex items-start justify-between gap-3">

                        <div class="min-w-0">

                          <p class="mb-1 text-sm font-semibold truncate link link-hover">
                            <?= htmlspecialchars($doc['plate_no']) ?>
                            —
                            <?= htmlspecialchars($doc['vehicle_name'] ?? '') ?>
                          </p>

                        </div>

                        <!-- Expiry badge -->
                        <span class="ta-badge shrink-0 <?= $expiryBadgeClass ?>">
                          <?= htmlspecialchars($expiryLabel) ?>
                        </span>

                      </div>

                      <!-- Nearest expiry -->
                      <?php if ($nearestDate): ?>

                        <div class="mt-2 flex items-center gap-1.5 text-xs">

                          <svg xmlns="http://www.w3.org/2000/svg"
                              class="h-3.5 w-3.5 text-error"
                              fill="none"
                              viewBox="0 0 24 24"
                              stroke="currentColor"
                              stroke-width="2">
                            <path stroke-linecap="round"
                                  stroke-linejoin="round"
                                  d="M12 9v3.75m9-.75a9 9 0 11-18 0 9 9 0 0118 0zm-9 3.75h.008v.008H12v-.008z" />
                          </svg>

                          <span class="text-error font-semibold">
                                Cukai Jalan:
                                <span class="font-semibold text-error"><?= $nearestDate->format('d/m/Y') ?></span>
                          </span>

                        </div>

                      <?php endif; ?>

                    </a>

                  </li>

                <?php endforeach; ?>

              </ul>

            <?php endif; ?>

          </div>

          <!-- ==========================================================
              CARD 2: STATUS KETERSEDIAAN PEMANDU
              ========================================================== -->
          <div class="card p-5">

            <h6 class="mb-3 font-semibold text-sm flex items-center gap-2">

              <svg xmlns="http://www.w3.org/2000/svg"
                  class="h-4 w-4"
                  style="color:var(--ta-muted)"
                  fill="none"
                  viewBox="0 0 24 24"
                  stroke="currentColor"
                  stroke-width="1.8">

                <path stroke-linecap="round"
                      stroke-linejoin="round"
                      d="M2.25 8.25h19.5M2.25 9h19.5m-16.5 5.25h6m-6 2.25h3m-3.75 3h15a2.25 2.25 0 002.25-2.25V6.75A2.25 2.25 0 0019.5 4.5h-15a2.25 2.25 0 00-2.25 2.25v10.5A2.25 2.25 0 004.5 19.5z" />

              </svg>

              Status Ketersediaan Pemandu

            </h6>

            <ul class="ta-divide">

              <?php foreach ($drivers as $d): ?>

                <?php

                  $driverStatusMalay = match($d['status']) {

                    'Available' => 'Boleh Bertugas',

                    'Leave' => 'Cuti',

                    'Inactive' => 'Tidak Aktif',

                    default => $d['status']

                  };

                  $driverBadgeClass =
                      $d['status'] === 'Available'
                        ? 'badge badge-success'
                        : (
                            $d['status'] === 'Leave'
                              ? 'badge badge-warning'
                              : 'badge badge-ghost'
                          );

                ?>

                <li class="py-3 flex items-center justify-between gap-3">

                  <div class="min-w-0">

                    <!-- Driver Name -->
                    <p class="mb-1 text-sm font-semibold truncate">

                      <?= htmlspecialchars($d['fullname']) ?>

                    </p>

                    <!-- Vehicle Type -->
                    <p class="mb-0 text-xs text-slate-400">

                      Jenis Kenderaan:

                      <span class="font-medium">

                        <?= htmlspecialchars($d['vehicle_type'] ?? 'Belum Ditugaskan') ?>

                      </span>

                    </p>

                    <!-- Vehicle -->
                    <?php if (!empty($d['vehicle_name'])): ?>

                      <p class="mb-0 text-xs text-slate-400">

                        <?= htmlspecialchars($d['vehicle_name']) ?>

                        ·

                        <?= htmlspecialchars($d['plate_no']) ?>

                      </p>

                    <?php endif; ?>

                  </div>

                  <!-- Driver Status -->

                  <span class="ta-badge shrink-0 <?= $driverBadgeClass ?>">

                    <?= htmlspecialchars($driverStatusMalay) ?>

                  </span>

                </li>

              <?php endforeach; ?>

            </ul>

          </div>

        </div>

        <!-- Baris 4: Perjalanan Akan Datang + Aktiviti Terkini -->
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-5 mt-5">
          <div class="card p-5 lg:col-span-2">
            <div class="mb-4">
              <h6 class="font-semibold">Perjalanan Akan Datang</h6>
              <p class="mb-0 text-sm" style="color:var(--ta-muted)">Tempahan menunggu &amp; diluluskan mengikut susunan masa</p>
            </div>
            <div class="overflow-x-auto -mx-1">
              <table class="items-center w-full mb-0 align-top">
                <thead>
                  <tr class="border-b" style="border-color:var(--ta-border)">
                    <th class="px-3 py-2 text-xs font-semibold text-left uppercase text-slate-400">No. Tempahan</th>
                    <th class="px-3 py-2 text-xs font-semibold text-left uppercase text-slate-400">Pemohon</th>
                    <th class="px-3 py-2 text-xs font-semibold text-left uppercase text-slate-400">Kenderaan</th>
                    <th class="px-3 py-2 text-xs font-semibold text-left uppercase text-slate-400">Destinasi</th>
                    <th class="px-3 py-2 text-xs font-semibold text-left uppercase text-slate-400">Masa Bertolak</th>
                    <th class="px-3 py-2 text-xs font-semibold text-center uppercase text-slate-400">Status</th>
                  </tr>
                </thead>
                <tbody>
                  <?php if (empty($upcomingTrips)): ?>
                    <tr><td colspan="6" class="px-3 py-6 text-sm text-center text-slate-400">Tiada perjalanan akan datang.</td></tr>
                  <?php endif; ?>
                  <?php foreach ($upcomingTrips as $t): ?>
                    <?php $badgeInfo = $statusBadge($t['status']); ?>
                    <tr class="hover:bg-slate-50/70 transition-colors cursor-pointer"
                        data-href="view.php?id=<?= (int)$t['booking_id'] ?>&amp;from=status%3D<?= urlencode($t['status']) ?>">
                      <td class="px-3 py-3 text-sm border-b whitespace-nowrap font-medium" style="border-color:var(--ta-border)"><?= htmlspecialchars($t['booking_no']) ?></td>
                      <td class="px-3 py-3 text-sm border-b whitespace-nowrap" style="border-color:var(--ta-border)"><?= htmlspecialchars($t['requester']) ?></td>
                      <td class="px-3 py-3 text-sm border-b whitespace-nowrap" style="border-color:var(--ta-border)"><?= htmlspecialchars($t['plate_no']) ?></td>
                      <td class="px-3 py-3 text-sm border-b max-w-[220px] truncate" style="border-color:var(--ta-border)" title="<?= htmlspecialchars($t['destination'] ?? '—') ?>"><?= htmlspecialchars($t['destination'] ?? '—') ?></td>
                      <td class="px-3 py-3 text-sm border-b whitespace-nowrap" style="border-color:var(--ta-border)"><?= htmlspecialchars(date('d M, H:i', strtotime($t['depart_datetime']))) ?></td>
                      <td class="px-3 py-3 text-sm border-b text-center whitespace-nowrap" style="border-color:var(--ta-border)">
                        <span class="ta-badge <?= $badgeInfo['class'] ?>"><?= htmlspecialchars($badgeInfo['label']) ?></span>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          </div>
          <div class="card p-5">
            <h6 class="font-semibold mb-4">Aktiviti Terkini</h6>
            <?php if (empty($recentActivity)): ?>
              <p class="text-sm text-slate-400">Tiada aktiviti direkodkan lagi.</p>
            <?php else: ?>
              <div>
                <?php foreach ($recentActivity as $a): ?>
                  <div class="ta-timeline-item">
                    <span class="ta-timeline-dot">
                      <svg xmlns="http://www.w3.org/2000/svg" class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" /></svg>
                    </span>
                    <h6 class="mb-0 text-sm font-semibold leading-normal"><?= htmlspecialchars($a['action']) ?></h6>
                    <p class="mt-0.5 mb-0 text-xs leading-tight text-slate-400">
                      oleh <?= htmlspecialchars($a['action_by']) ?> &middot; <?= htmlspecialchars(date('d M, H:i', strtotime($a['action_datetime']))) ?>
                    </p>
                  </div>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>
          </div>
        </div>

        <?php endif; // tutup paparan User / Admin-SuperAdmin ?>

        <?php if ($role === 'SuperAdmin' && !empty($emailHealth)): ?>
        <div class="card p-5 mt-5 flex items-center gap-4 flex-wrap">
          <h6 class="mb-0 mr-4 font-semibold text-sm flex items-center gap-2">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" style="color:var(--ta-muted)" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M21.75 6.75v10.5a2.25 2.25 0 01-2.25 2.25h-15a2.25 2.25 0 01-2.25-2.25V6.75m19.5 0A2.25 2.25 0 0019.5 4.5h-15a2.25 2.25 0 00-2.25 2.25m19.5 0v.243a2.25 2.25 0 01-1.07 1.916l-7.5 4.615a2.25 2.25 0 01-2.36 0L3.32 8.91a2.25 2.25 0 01-1.07-1.916V6.75" /></svg>
            Status Penghantaran Notifikasi E-mel
          </h6>
          <?php
            $emailStatusMap = [
              'Pending' => 'Belum Dihantar',
              'Sent'    => 'Berjaya Dihantar',
              'Failed'  => 'Gagal'
            ];
          ?>
          <?php foreach (['Pending', 'Sent', 'Failed'] as $s): ?>
            <span class="ta-badge <?= $s === 'Failed' ? 'badge badge-error' : ($s === 'Pending' ? 'badge badge-warning' : 'badge badge-success') ?>">
              <?= $emailStatusMap[$s] ?>: <?= (int)($emailHealth[$s] ?? 0) ?>
            </span>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <footer class="pt-6 pb-2">
          <div class="text-sm leading-normal text-center text-slate-400">
            © <?= date('Y') ?> Kembara &middot; Perbendaharaan Negeri Selangor
          </div>
        </footer>
      </div>
    </main>

    <!-- Skrip Penyediaan Carta ApexCharts Bahasa Melayu -->
    <script>
        const rawBookingLabels = <?= json_encode($bookingStatusLabels) ?>;
        const bookingStatusData = <?= json_encode($bookingStatusData) ?>;

        const rawVehicleLabels = <?= json_encode($vehicleStatusLabels) ?>;
        const vehicleStatusData = <?= json_encode($vehicleStatusData) ?>;

        // Petaan Bahasa Melayu daripada DB Bahasa Inggeris
        const bookingTranslationMap = {
          'Pending': 'Dalam Proses',
          'Approved': 'Diluluskan',
          'Rejected': 'Ditolak',
          'Cancelled': 'Dibatalkan',
          'Completed': 'Selesai'
        };

        const vehicleTranslationMap = {
          'Available': 'Boleh Bertugas',
          'Booked': 'Sedang Digunakan',
          'Maintenance': 'Penyelenggaraan',
          'Inactive': 'Tidak Aktif'
        };

        const bookingStatusLabels = rawBookingLabels.map(label => bookingTranslationMap[label] || label);
        const vehicleStatusLabels = rawVehicleLabels.map(label => vehicleTranslationMap[label] || label);

        // Carta hanya wujud pada paparan Admin / SuperAdmin (bukan paparan peribadi User)
        var bookingChart, vehicleChart;
        if (document.querySelector("#chart-booking-status") && document.querySelector("#chart-vehicle-status")) {

        // Carta Status Tempahan
        var bookingOptions = {
          series: bookingStatusData,
          chart: {
            type: 'donut',
            height: 320,
            fontFamily: "'Outfit', sans-serif",
            events: {
              dataPointSelection: function(event, chartContext, config) {
                const rawStatus = rawBookingLabels[config.dataPointIndex];
                if (!rawStatus) return;
                window.location.href = 'bookings.php?status=' + encodeURIComponent(rawStatus);
              }
            }
          },
          labels: bookingStatusLabels,
          colors: ['#F79009', '#12B76A', '#F04438', '#98A2B3', '#465FFF'],
          plotOptions: {
            pie: {
              borderRadius: 8,
              spacing: 5,
              donut: {
                size: '68%',
                labels: {
                  show: true,
                  total: {
                    show: true,
                    label: 'Jumlah Tempahan',
                    fontSize: '14px',
                    fontWeight: 600,
                    color: getComputedStyle(document.documentElement).getPropertyValue('--color-base-content').trim(),
                    formatter: function (w) {
                      return w.globals.seriesTotals.reduce((a, b) => a + b, 0);
                    }
                  },
                  value: {
                    fontSize: '18px',
                    fontWeight: 700,
                    color: getComputedStyle(document.documentElement).getPropertyValue('--color-base-content').trim()
                  }
                }
              }
            }
          },
          stroke: { width: 0 },
          dataLabels: { enabled: false },
          legend: { position: 'bottom' },
          title: {
            text: 'Status Tempahan Kenderaan',
            align: 'left',
            style: {
              fontSize: '14px',
              fontWeight: '600',
              color: getComputedStyle(document.documentElement).getPropertyValue('--color-base-content').trim()
            }
          },
          responsive: [{
            breakpoint: 480,
            options: { chart: { width: '100%' } }
          }]
        };

        bookingChart = new ApexCharts(document.querySelector("#chart-booking-status"), bookingOptions);
        bookingChart.render();

        // Carta Status Kenderaan
        var vehicleOptions = {
          series: vehicleStatusData,
          chart: {
            type: 'donut',
            height: 320,
            fontFamily: "'Outfit', sans-serif"
          },
          labels: vehicleStatusLabels,
          colors: ['#12B76A', '#465FFF', '#F79009', '#98A2B3'],
          plotOptions: {
            pie: {
              borderRadius: 8,
              spacing: 5,
              donut: {
                size: '68%',
                labels: {
                  show: true,
                  total: {
                    show: true,
                    label: 'Jumlah Kenderaan',
                    fontSize: '14px',
                    fontWeight: 600,
                    color: getComputedStyle(document.documentElement).getPropertyValue('--color-base-content').trim(),
                    formatter: function (w) {
                      return w.globals.seriesTotals.reduce((a, b) => a + b, 0);
                    }
                  },
                  value: {
                    fontSize: '18px',
                    fontWeight: 700,
                    color: getComputedStyle(document.documentElement).getPropertyValue('--color-base-content').trim()
                  }
                }
              }
            }
          },
          stroke: { width: 0 },
          dataLabels: { enabled: false },
          legend: { position: 'bottom' },
          title: {
            text: 'Status Agihan Kenderaan',
            align: 'left',
            style: {
              fontSize: '14px',
              fontWeight: '600',
              color: getComputedStyle(document.documentElement).getPropertyValue('--color-base-content').trim()
            }
          },
          responsive: [{
            breakpoint: 480,
            options: { chart: { width: '100%' } }
          }]
        };

        vehicleChart = new ApexCharts(document.querySelector("#chart-vehicle-status"), vehicleOptions);
        vehicleChart.render();
        } // tutup semakan wujud carta
    </script>

    <?php include 'includes/layout_footer.php'; ?>
