<?php
// dashboard.php
session_start();
require_once __DIR__ . '/config/database.php';

// Dialihkan ke log masuk jika pengguna belum diabsahkan
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$fullname = $_SESSION['fullname'];
$role = $_SESSION['role']; // Boleh jadi 'SuperAdmin', 'Admin', atau 'User'

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

// Warna lencana berdasarkan peranan
$badgeColor = match($role) {
    'SuperAdmin' => 'badge-soft-error',
    'Admin'      => 'badge-soft-warning',
    default      => 'badge-soft-info',
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
    "SELECT COUNT(*) FROM vehicle_bookings WHERE status = 'Pending'"
)->fetchColumn();

$vehicleCounts = $pdo->query(
    "SELECT status, COUNT(*) AS total FROM vehicles GROUP BY status"
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
    "SELECT plate_no, vehicle_name, road_tax_expiry, insurance_expiry
     FROM vehicles
     WHERE (road_tax_expiry IS NOT NULL AND road_tax_expiry <= DATE_ADD(CURRENT_DATE(), INTERVAL 30 DAY))
        OR (insurance_expiry IS NOT NULL AND insurance_expiry <= DATE_ADD(CURRENT_DATE(), INTERVAL 30 DAY))
     ORDER BY LEAST(
        COALESCE(road_tax_expiry, '9999-12-31'),
        COALESCE(insurance_expiry, '9999-12-31')
     ) ASC
     LIMIT 5"
)->fetchAll(PDO::FETCH_ASSOC);

// -- Ketersediaan pemandu --------------------------------------------
$drivers = $pdo->query(
    "SELECT dr.driver_id, dr.license, dr.status, u.fullname
     FROM drivers dr
     JOIN users u ON u.user_id = dr.user_id
     ORDER BY FIELD(dr.status, 'Available', 'Leave', 'Inactive'), u.fullname
     LIMIT 6"
)->fetchAll(PDO::FETCH_ASSOC);

// -- Perjalanan akan datang (Menunggu / Diluluskan, paling awal dahulu) -
$upcomingTrips = $pdo->query(
    "SELECT vb.booking_no, u.fullname AS requester, v.plate_no, v.vehicle_name,
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
    'Pending'   => ['class' => 'badge-soft-warning', 'label' => 'Dalam Proses'],
    'Approved'  => ['class' => 'badge-soft-success', 'label' => 'Diluluskan'],
    'Rejected'  => ['class' => 'badge-soft-error',   'label' => 'Ditolak'],
    'Cancelled' => ['class' => 'badge-soft-neutral', 'label' => 'Dibatalkan'],
    'Completed' => ['class' => 'badge-soft-info',    'label' => 'Selesai'],
    default     => ['class' => 'badge-soft-neutral', 'label' => $status],
};
?>
<!DOCTYPE html>
<html lang="ms" data-theme="light">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0, shrink-to-fit=no" />
    <title>Kembara - Dashboard</title>

    <!-- Font: Outfit -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700&display=swap" rel="stylesheet" />

    <!-- Tailwind CSS CDN & daisyUI Framework (v5 — needed for the light & dracula themes) -->
    <link href="https://cdn.jsdelivr.net/npm/daisyui@5" rel="stylesheet" type="text/css" />
    <link href="https://cdn.jsdelivr.net/npm/daisyui@5/themes.css" rel="stylesheet" type="text/css" />
    <script src="https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4"></script>

    <!-- ApexCharts CDN -->
    <script src="https://cdn.jsdelivr.net/npm/apexcharts"></script>

    <style>
        /*
         * These --ta-* variables now simply point at daisyUI's own theme
         * variables, so they automatically follow whichever daisyUI theme
         * is active on <html data-theme="..."> — light (light) or
         * dracula (dark) — instead of us maintaining a separate hand-picked
         * palette per mode.
         */
        :root, [data-theme] {
            --ta-canvas: var(--color-base-200);
            --ta-surface: var(--color-base-100);
            --ta-border: var(--color-base-300);
            --ta-ink: var(--color-base-content);
            --ta-muted: color-mix(in oklch, var(--color-base-content) 55%, transparent);
            --ta-brand: var(--color-primary);
            --ta-brand-50: color-mix(in oklch, var(--color-primary) 12%, var(--color-base-100));
        }

        /*
         * A handful of utility classes in the markup below were hardcoded to
         * Tailwind's slate/white palette (from before daisyUI theming was
         * wired up). Remap them to the active daisyUI theme so every corner
         * of the page (nav, dropdowns, table headers, footer) follows
         * light / dracula too.
         */
        .bg-white { background-color: var(--ta-surface) !important; }
        .text-slate-400, .text-slate-500 { color: var(--ta-muted) !important; }
        .text-slate-700, .text-slate-800 { color: var(--ta-ink) !important; }
        .hover\:bg-slate-50:hover, .hover\:bg-slate-100:hover { background-color: var(--ta-canvas) !important; }
        .hover\:text-slate-700:hover { color: var(--ta-ink) !important; }

        * { font-family: 'Outfit', ui-sans-serif, system-ui, sans-serif; }

        body { 
            background: var(--ta-canvas); 
            color: var(--ta-ink); 
            zoom: 110%; /* Global zoom adjustment */
            transition: background-color 0.2s ease, color 0.2s ease;
        }

        /* Card & Sidenav Dark Mode Overrides */
        .card, .ta-sidebar, nav {
            background: var(--ta-surface) !important;
            border-color: var(--ta-border) !important;
            color: var(--ta-ink) !important;
        }

        /* Table text, borders & hover — theme-aware in both modes now */
        table th { color: var(--ta-muted) !important; }
        table td { border-color: var(--ta-border) !important; }
        table tr:hover { background-color: color-mix(in oklch, var(--color-base-content) 5%, transparent) !important; }

        .ta-icon-box {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 3rem;
            height: 3rem;
            border-radius: 0.75rem;
            background: var(--ta-canvas);
            color: var(--ta-ink);
        }

        .ta-nav-link {
            display: flex;
            align-items: center;
            gap: 0.7rem;
            border-radius: 0.5rem;
            padding: 0.55rem 0.75rem;
            font-size: 0.875rem;
            font-weight: 500;
            color: var(--ta-muted);
            transition: all .15s ease;
        }
        .ta-nav-link:hover { background: var(--ta-canvas); color: var(--ta-ink); }
        .ta-nav-link.active { background: var(--ta-brand-50); color: var(--ta-brand); font-weight: 600; }
        .ta-nav-icon { display: inline-flex; width: 1.25rem; height: 1.25rem; flex-shrink: 0; }

        .ta-badge {
            display: inline-flex;
            align-items: center;
            gap: 0.25rem;
            border-radius: 9999px;
            padding: 0.15rem 0.65rem;
            font-size: 0.7rem;
            font-weight: 600;
        }
        /* Soft badges — derived from daisyUI's semantic theme colors, so one
           rule covers both light and dracula automatically */
        .badge-soft-success { background: color-mix(in oklch, var(--color-success) 18%, var(--color-base-100)); color: var(--color-success); }
        .badge-soft-warning { background: color-mix(in oklch, var(--color-warning) 18%, var(--color-base-100)); color: var(--color-warning); }
        .badge-soft-error   { background: color-mix(in oklch, var(--color-error) 18%, var(--color-base-100));   color: var(--color-error); }
        .badge-soft-info    { background: color-mix(in oklch, var(--color-info) 18%, var(--color-base-100));    color: var(--color-info); }
        .badge-soft-neutral { background: color-mix(in oklch, var(--color-neutral) 18%, var(--color-base-100)); color: var(--ta-ink); }

        .ta-divide > * + * { border-top: 1px solid var(--ta-border); }

        .ta-timeline-item { position: relative; padding-left: 2.25rem; padding-bottom: 1.1rem; }
        .ta-timeline-item::before {
            content: '';
            position: absolute;
            left: 0.6rem;
            top: 1.6rem;
            bottom: -0.3rem;
            width: 1px;
            background: var(--ta-border);
        }
        .ta-timeline-item:last-child::before { display: none; }
        .ta-timeline-dot {
            position: absolute;
            left: 0;
            top: 0.15rem;
            width: 1.25rem;
            height: 1.25rem;
            border-radius: 9999px;
            background: var(--ta-brand-50);
            color: var(--ta-brand);
            display: flex;
            align-items: center;
            justify-content: center;
        }

        ::-webkit-scrollbar { width: 8px; height: 8px; }
        ::-webkit-scrollbar-thumb { background: var(--ta-border); border-radius: 999px; }
    </style>
</head>
<body class="min-h-screen">

    <!-- Menu Sisi (Sidebar) — Desktop sahaja; mobile guna dock bawah -->
    <aside id="sidenav-main" class="fixed inset-y-0 left-0 z-[70] w-64 hidden xl:flex xl:flex-col overflow-y-auto ta-sidebar">
        <div class="h-16 flex items-center px-6 border-b" style="border-color:var(--ta-border)">
            <a class="flex items-center gap-2.5" href="dashboard.php">
            <img src="assets/img/logo.png" alt="Logo Kembara" class="h-8 w-auto object-contain shrink-0" />
            <span class="font-bold tracking-tight text-[1.05rem]">Kembara</span>
            </a>
        </div>

      <div class="flex-1 px-4 py-5">
        <p class="px-3 mb-2 text-[0.65rem] font-semibold uppercase tracking-widest text-slate-400">Menu Utama</p>
        <ul class="flex flex-col gap-1">
          <li>
            <a class="ta-nav-link active" href="dashboard.php">
              <svg class="ta-nav-icon" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 3v11.25A2.25 2.25 0 006 16.5h2.25M3.75 3h-1.5M3.75 3h16.5M21.75 3v11.25A2.25 2.25 0 0119.5 16.5H17.25m-10.5 0h6m-6 0v3.75A1.5 1.5 0 007.5 21.75h9a1.5 1.5 0 001.5-1.5V16.5m-10.5 0h10.5" /></svg>
              <span>Dashboard</span>
            </a>
          </li>
          <li>
            <a class="ta-nav-link" href="bookings.php">
              <svg class="ta-nav-icon" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 012.25-2.25h13.5A2.25 2.25 0 0121 7.5v11.25m-18 0A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75m-18 0v-7.5A2.25 2.25 0 015.25 9h13.5A2.25 2.25 0 0121 11.25v7.5m-13.5-6h.008v.008h-.008v-.008zm0 3h.008v.008h-.008v-.008zm3-3h.008v.008h-.008v-.008zm0 3h.008v.008h-.008v-.008z" /></svg>
              <span>Tempahan</span>
            </a>
          </li>
          <li>
            <a class="ta-nav-link" href="vehicles.php">
              <svg class="ta-nav-icon" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 18.75a1.5 1.5 0 01-3 0m3 0a1.5 1.5 0 00-3 0m3 0h6m-9 0H3.375a1.125 1.125 0 01-1.125-1.125V14.25m17.25 4.5a1.5 1.5 0 01-3 0m3 0a1.5 1.5 0 00-3 0m3 0h1.125c.621 0 1.129-.504 1.09-1.124a17.902 17.902 0 00-3.213-9.193 2.056 2.056 0 00-1.58-.86H14.25M16.5 18.75h-2.25m0-11.177v-.958c0-.568-.422-1.048-.987-1.106a48.554 48.554 0 00-10.026 0 1.106 1.106 0 00-.987 1.106v7.635m12-6.677v6.677m0 0h-12" /></svg>
              <span>Kenderaan</span>
            </a>
          </li>
          <li>
            <a class="ta-nav-link" href="drivers.php">
              <svg class="ta-nav-icon" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 8.25h19.5M2.25 9h19.5m-16.5 5.25h6m-6 2.25h3m-3.75 3h15a2.25 2.25 0 002.25-2.25V6.75A2.25 2.25 0 0019.5 4.5h-15a2.25 2.25 0 00-2.25 2.25v10.5A2.25 2.25 0 004.5 19.5z" /></svg>
              <span>Pemandu</span>
            </a>
          </li>

          <?php if ($role === 'SuperAdmin'): ?>
          <li>
            <a class="ta-nav-link" href="users.php">
              <svg class="ta-nav-icon" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19.128a9.38 9.38 0 002.625.372 9.337 9.337 0 004.121-.952 4.125 4.125 0 00-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 018.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0111.964-3.07M12 6.375a3.375 3.375 0 11-6.75 0 3.375 3.375 0 016.75 0zm8.25 2.25a2.625 2.625 0 11-5.25 0 2.625 2.625 0 015.25 0z" /></svg>
              <span>Pengguna</span>
            </a>
          </li>
          <?php endif; ?>
        </ul>
      </div>

      <div class="p-4 border-t" style="border-color:var(--ta-border)">
        <div class="rounded-xl p-3.5" style="background:var(--ta-brand-50)">
          <p class="text-xs font-semibold mb-0.5" style="color:var(--ta-brand)">Perbendaharaan Negeri Selangor</p>
          <p class="text-xs text-slate-500 leading-relaxed">Sistem tempahan kenderaan rasmi Negeri Selangor.</p>
        </div>
      </div>
    </aside>

    <!-- Navbar Bawah — hanya untuk paparan telefon, dengan Dashboard sebagai butang timbul di tengah -->
    <div class="fixed inset-x-0 bottom-0 z-[70] xl:hidden">
      <div class="relative">
        <!-- Butang Dashboard (timbul di tengah) -->
        <a href="dashboard.php" class="absolute left-1/2 -translate-x-1/2 -top-7 z-10 flex flex-col items-center gap-1">
          <span class="w-16 h-16 rounded-full flex items-center justify-center shadow-lg" style="background:var(--ta-brand); color:#fff; box-shadow:0 6px 16px -4px color-mix(in oklch, var(--ta-brand) 60%, transparent), 0 0 0 6px var(--ta-canvas)">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-7 w-7" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 3v11.25A2.25 2.25 0 006 16.5h2.25M3.75 3h-1.5M3.75 3h16.5M21.75 3v11.25A2.25 2.25 0 0119.5 16.5H17.25m-10.5 0h6m-6 0v3.75A1.5 1.5 0 007.5 21.75h9a1.5 1.5 0 001.5-1.5V16.5m-10.5 0h10.5" /></svg>
          </span>
          <span class="text-[11px] font-semibold" style="color:var(--ta-brand)">Dashboard</span>
        </a>

        <!-- Bar -->
        <div class="flex items-center justify-around px-2 pt-2" style="background:var(--ta-surface); border-top:1px solid var(--ta-border); height:4.25rem; padding-bottom:env(safe-area-inset-bottom)">
          <a href="bookings.php" class="flex flex-col items-center gap-1 flex-1 py-1" style="color:var(--ta-muted)">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 012.25-2.25h13.5A2.25 2.25 0 0121 7.5v11.25m-18 0A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75m-18 0v-7.5A2.25 2.25 0 015.25 9h13.5A2.25 2.25 0 0121 11.25v7.5m-13.5-6h.008v.008h-.008v-.008zm0 3h.008v.008h-.008v-.008zm3-3h.008v.008h-.008v-.008zm0 3h.008v.008h-.008v-.008z" /></svg>
            <span class="text-[11px] font-medium">Tempahan</span>
          </a>
          <a href="vehicles.php" class="flex flex-col items-center gap-1 flex-1 py-1" style="color:var(--ta-muted)">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 18.75a1.5 1.5 0 01-3 0m3 0a1.5 1.5 0 00-3 0m3 0h6m-9 0H3.375a1.125 1.125 0 01-1.125-1.125V14.25m17.25 4.5a1.5 1.5 0 01-3 0m3 0a1.5 1.5 0 00-3 0m3 0h1.125c.621 0 1.129-.504 1.09-1.124a17.902 17.902 0 00-3.213-9.193 2.056 2.056 0 00-1.58-.86H14.25M16.5 18.75h-2.25m0-11.177v-.958c0-.568-.422-1.048-.987-1.106a48.554 48.554 0 00-10.026 0 1.106 1.106 0 00-.987 1.106v7.635m12-6.677v6.677m0 0h-12" /></svg>
            <span class="text-[11px] font-medium">Kenderaan</span>
          </a>

          <!-- Ruang kosong di bawah butang timbul -->
          <div class="flex-1 flex justify-center">
            <span class="w-16"></span>
          </div>

          <a href="drivers.php" class="flex flex-col items-center gap-1 flex-1 py-1" style="color:var(--ta-muted)">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 8.25h19.5M2.25 9h19.5m-16.5 5.25h6m-6 2.25h3m-3.75 3h15a2.25 2.25 0 002.25-2.25V6.75A2.25 2.25 0 0019.5 4.5h-15a2.25 2.25 0 00-2.25 2.25v10.5A2.25 2.25 0 004.5 19.5z" /></svg>
            <span class="text-[11px] font-medium">Pemandu</span>
          </a>
          <?php if ($role === 'SuperAdmin'): ?>
          <a href="users.php" class="flex flex-col items-center gap-1 flex-1 py-1" style="color:var(--ta-muted)">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19.128a9.38 9.38 0 002.625.372 9.337 9.337 0 004.121-.952 4.125 4.125 0 00-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 018.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0111.964-3.07M12 6.375a3.375 3.375 0 11-6.75 0 3.375 3.375 0 016.75 0zm8.25 2.25a2.625 2.625 0 11-5.25 0 2.625 2.625 0 015.25 0z" /></svg>
            <span class="text-[11px] font-medium">Pengguna</span>
          </a>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <main class="xl:ml-64 relative min-h-screen pb-24 xl:pb-0">
      <!-- Navigasi Atas -->
      <nav class="sticky top-0 z-50 flex items-center justify-between gap-4 px-4 sm:px-6 py-3 bg-white border-b" style="border-color:var(--ta-border)">
        <div class="flex items-center gap-3">
            <div>
                <h6 class="font-bold text-base leading-tight">Dashboard</h6>
                <p class="text-xs leading-tight" style="color:var(--ta-muted)">
                    <span class="opacity-70">Halaman</span>
                    <span class="mx-1 opacity-40">/</span>
                    <span>Dashboard</span>
                </p>
            </div>
        </div>

        <!-- Carian -->
        <label class="hidden md:flex items-center gap-2 rounded-lg px-3 py-2 flex-1 max-w-sm" style="background:var(--ta-canvas); border:1px solid var(--ta-border)">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 shrink-0" style="color:var(--ta-muted)" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607z" /></svg>
            <input type="text" placeholder="Cari tempahan, kenderaan..." class="bg-transparent border-0 outline-none text-sm w-full placeholder:text-slate-400" />
        </label>

            <div class="flex items-center gap-1.5 sm:gap-2">
                <!-- Theme Toggle Button -->
                <button type="button" id="theme-toggle" class="btn btn-ghost btn-circle text-slate-500 hover:bg-slate-100 border-0 h-10 w-10 min-h-0">
                    <span id="theme-toggle-icon"></span>
                </button>

                <!-- Notification Dropdown -->
                <div class="dropdown dropdown-end">
                    <div tabindex="0" role="button" class="btn btn-ghost btn-circle border-0 h-10 w-10 min-h-0 relative text-slate-500 hover:bg-slate-100">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M14.857 17.082a23.848 23.848 0 005.454-1.31A8.967 8.967 0 0118 9.75v-.7V9A6 6 0 006 9v.75a8.967 8.967 0 01-2.312 6.022c1.733.64 3.56 1.085 5.455 1.31m5.714 0a24.255 24.255 0 01-5.714 0m5.714 0a3 3 0 11-5.714 0" />
                        </svg>
                        <?php if ($pendingApprovals > 0): ?>
                            <span class="absolute top-2 right-2 flex h-2.5 w-2.5">
                                <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-error opacity-75"></span>
                                <span class="relative inline-flex rounded-full h-2.5 w-2.5 bg-error"></span>
                            </span>
                        <?php endif; ?>
                    </div>

                    <div tabindex="0" class="dropdown-content z-[99] menu p-0 shadow-xl card rounded-2xl w-80 mt-2 border" style="border-color: var(--ta-border);">
                        <!-- Header Notifikasi -->
                        <div class="px-4 py-3 border-b flex items-center justify-between" style="border-color: var(--ta-border);">
                            <div class="flex items-center gap-2">
                                <span class="font-bold text-sm">Notifikasi</span>
                                <?php if ($pendingApprovals > 0): ?>
                                    <span class="badge badge-error badge-sm text-white font-semibold"><?= $pendingApprovals ?> baru</span>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Content List -->
                        <div class="max-h-64 overflow-y-auto divide-y" style="border-color: var(--ta-border);">
                            <?php if ($pendingApprovals > 0): ?>
                                <a href="bookings.php?status=Pending" class="flex items-start gap-3 p-3.5 hover:bg-slate-50 dark:hover:bg-slate-800 transition-colors">
                                    <div class="p-2 rounded-full bg-warning/15 text-warning shrink-0 mt-0.5">
                                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                                    </div>
                                    <div class="flex-1 min-w-0">
                                        <p class="text-xs font-semibold text-slate-800 dark:text-slate-100">Tempahan Menunggu Kelulusan</p>
                                        <p class="text-[11px] text-slate-400 mt-0.5">Terdapat <?= $pendingApprovals ?> tempahan kenderaan baharu yang memerlukan tindakan anda.</p>
                                    </div>
                                </a>
                            <?php else: ?>
                                <div class="p-6 text-center text-slate-400 text-xs">
                                    <svg class="w-8 h-8 mx-auto mb-2 opacity-40" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9" /></svg>
                                    Tiada notifikasi baharu.
                                </div>
                            <?php endif; ?>
                        </div>

                        <!-- Footer Link -->
                        <div class="p-2 border-t text-center" style="border-color: var(--ta-border);">
                            <a href="bookings.php" class="text-xs text-primary font-semibold hover:underline block py-1">Lihat Semak Tempahan</a>
                        </div>
                    </div>
                </div>

                <!-- User Profile Dropdown -->
                <div class="dropdown dropdown-end">
                    <div tabindex="0" role="button" class="btn btn-ghost rounded-full pl-1 pr-2 py-1 flex items-center gap-2 h-auto min-h-0">
                        <div class="avatar <?= $hasPhoto ? '' : 'placeholder' ?>">
                            <?php if ($hasPhoto): ?>
                                <div class="rounded-full w-8 h-8"><img src="<?= htmlspecialchars($profilePicture) ?>" alt="Avatar" /></div>
                            <?php else: ?>
                                <div class="rounded-full w-8 h-8 flex items-center justify-center font-bold text-xs uppercase text-white" style="background:var(--ta-brand)">
                                    <?= htmlspecialchars(substr($fullname, 0, 1)) ?>
                                </div>
                            <?php endif; ?>
                        </div>
                        <span class="text-sm font-semibold hidden sm:inline-block"><?= htmlspecialchars($fullname) ?></span>
                    </div>
                    <ul tabindex="0" class="dropdown-content z-[99] menu p-3 shadow-lg card rounded-2xl w-64 mt-2 border" style="border-color: var(--ta-border);">
                        <li class="px-3 py-2 border-b mb-1" style="border-color:var(--ta-border)">
                            <div class="flex items-center justify-between gap-2">
                                <p class="font-bold text-sm truncate"><?= htmlspecialchars($fullname) ?></p>
                                <span class="ta-badge <?= $badgeColor ?>"><?= htmlspecialchars($role) ?></span>
                            </div>
                            <p class="text-xs text-slate-400 mt-1 truncate"><?= htmlspecialchars($email) ?></p>
                        </li>
                        <li><a href="profile.php" class="py-2.5 text-xs font-medium">Profil Saya</a></li>
                        <div class="divider my-1"></div>
                        <li><a href="logout.php" class="py-2.5 text-xs font-semibold text-red-600">Log Keluar</a></li>
                    </ul>
                </div>
            </div>
      </nav>

      <div class="w-full px-4 sm:px-6 py-6 mx-auto">

        <!-- Baris 1: Kad Statistik -->
        <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-5">
          <div class="card p-5">
            <div class="ta-icon-box mb-4">
              <svg xmlns="http://www.w3.org/2000/svg" class="h-5.5 w-5.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6"><path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 012.25-2.25h13.5A2.25 2.25 0 0121 7.5v11.25m-18 0A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75m-18 0v-7.5A2.25 2.25 0 015.25 9h13.5A2.25 2.25 0 0121 11.25v7.5" /></svg>
            </div>
            <p class="text-sm mb-1" style="color:var(--ta-muted)">Tempahan Bulan Ini</p>
            <h5 class="text-2xl font-bold"><?= (int)$totalBookingsThisMonth ?></h5>
          </div>

          <div class="card p-5">
            <div class="ta-icon-box mb-4">
              <svg xmlns="http://www.w3.org/2000/svg" class="h-5.5 w-5.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
            </div>
            <p class="text-sm mb-1" style="color:var(--ta-muted)">Menunggu Kelulusan</p>
            <h5 class="text-2xl font-bold"><?= (int)$pendingApprovals ?></h5>
          </div>

          <div class="card p-5">
            <div class="ta-icon-box mb-4">
              <svg xmlns="http://www.w3.org/2000/svg" class="h-5.5 w-5.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 18.75a1.5 1.5 0 01-3 0m3 0a1.5 1.5 0 00-3 0m3 0h6m-9 0H3.375a1.125 1.125 0 01-1.125-1.125V14.25m17.25 4.5a1.5 1.5 0 01-3 0m3 0a1.5 1.5 0 00-3 0m3 0h1.125c.621 0 1.129-.504 1.09-1.124a17.902 17.902 0 00-3.213-9.193 2.056 2.056 0 00-1.58-.86H14.25M16.5 18.75h-2.25m0-11.177v-.958c0-.568-.422-1.048-.987-1.106a48.554 48.554 0 00-10.026 0 1.106 1.106 0 00-.987 1.106v7.635m12-6.677v6.677m0 0h-12" /></svg>
            </div>
            <p class="text-sm mb-1" style="color:var(--ta-muted)">Kenderaan Sedia Ada</p>
            <h5 class="text-2xl font-bold"><?= (int)$availableVehicles ?> <span class="text-sm font-medium text-slate-400">/ <?= (int)$totalVehicles ?></span></h5>
          </div>

          <div class="card p-5">
            <div class="ta-icon-box mb-4">
              <svg xmlns="http://www.w3.org/2000/svg" class="h-5.5 w-5.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19.128a9.38 9.38 0 002.625.372 9.337 9.337 0 004.121-.952 4.125 4.125 0 00-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 018.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0111.964-3.07M12 6.375a3.375 3.375 0 11-6.75 0 3.375 3.375 0 016.75 0zm8.25 2.25a2.625 2.625 0 11-5.25 0 2.625 2.625 0 015.25 0z" /></svg>
            </div>
            <p class="text-sm mb-1" style="color:var(--ta-muted)">Jumlah Pengguna</p>
            <h5 class="text-2xl font-bold"><?= (int)$totalUsers ?></h5>
          </div>
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

        <!-- Baris 3: Tamat Tempoh Dokumen + Ketersediaan Pemandu -->
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-5 mt-5">
          <div class="card p-5">
            <h6 class="mb-3 font-semibold text-sm flex items-center gap-2">
              <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" style="color:var(--ta-muted)" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 11-18 0 9 9 0 0118 0zm-9 3.75h.008v.008H12v-.008z" /></svg>
              Dokumen Tamat Tempoh Terdekat
            </h6>
            <?php if (empty($expiringDocs)): ?>
              <p class="text-sm text-slate-400">Tiada dokumen yang tamat tempoh dalam masa 30 hari.</p>
            <?php else: ?>
              <ul class="ta-divide">
                <?php foreach ($expiringDocs as $doc): ?>
                  <li class="py-3 flex items-center justify-between gap-3">
                    <div class="min-w-0">
                      <p class="mb-0 text-sm font-semibold truncate"><?= htmlspecialchars($doc['plate_no']) ?> — <?= htmlspecialchars($doc['vehicle_name'] ?? '') ?></p>
                      <p class="mb-0 text-xs text-slate-400">
                        Cukai Jalan: <?= htmlspecialchars($doc['road_tax_expiry'] ?? '—') ?>
                        &middot; Insurans: <?= htmlspecialchars($doc['insurance_expiry'] ?? '—') ?>
                      </p>
                    </div>
                    <span class="ta-badge badge-soft-warning shrink-0">Baharui Segera</span>
                  </li>
                <?php endforeach; ?>
              </ul>
            <?php endif; ?>
          </div>

          <div class="card p-5">
            <h6 class="mb-3 font-semibold text-sm flex items-center gap-2">
              <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" style="color:var(--ta-muted)" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 8.25h19.5M2.25 9h19.5m-16.5 5.25h6m-6 2.25h3m-3.75 3h15a2.25 2.25 0 002.25-2.25V6.75A2.25 2.25 0 0019.5 4.5h-15a2.25 2.25 0 00-2.25 2.25v10.5A2.25 2.25 0 004.5 19.5z" /></svg>
              Status Ketersediaan Pemandu
            </h6>
            <ul class="ta-divide">
              <?php foreach ($drivers as $d): ?>
                <?php 
                  $driverStatusMalay = match($d['status']) {
                    'Available' => 'Boleh Bertugas',
                    'Leave'     => 'Cuti',
                    'Inactive'  => 'Tidak Aktif',
                    default     => $d['status']
                  };
                ?>
                <li class="py-3 flex items-center justify-between gap-3">
                  <div class="min-w-0">
                    <p class="mb-0 text-sm font-semibold truncate"><?= htmlspecialchars($d['fullname']) ?></p>
                    <p class="mb-0 text-xs text-slate-400">Lesen Memandu: <?= htmlspecialchars($d['license']) ?></p>
                  </div>
                  <span class="ta-badge shrink-0 <?= $d['status'] === 'Available' ? 'badge-soft-success' : ($d['status'] === 'Leave' ? 'badge-soft-warning' : 'badge-soft-neutral') ?>">
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
                    <tr class="hover:bg-slate-50/70 transition-colors">
                      <td class="px-3 py-3 text-sm border-b whitespace-nowrap font-medium" style="border-color:var(--ta-border)"><?= htmlspecialchars($t['booking_no']) ?></td>
                      <td class="px-3 py-3 text-sm border-b whitespace-nowrap" style="border-color:var(--ta-border)"><?= htmlspecialchars($t['requester']) ?></td>
                      <td class="px-3 py-3 text-sm border-b whitespace-nowrap" style="border-color:var(--ta-border)"><?= htmlspecialchars($t['plate_no']) ?></td>
                      <td class="px-3 py-3 text-sm border-b whitespace-nowrap" style="border-color:var(--ta-border)"><?= htmlspecialchars($t['destination'] ?? '—') ?></td>
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
            <span class="ta-badge <?= $s === 'Failed' ? 'badge-soft-error' : ($s === 'Pending' ? 'badge-soft-warning' : 'badge-soft-success') ?>">
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

        // Carta Status Tempahan
        var bookingOptions = {
          series: bookingStatusData,
          chart: {
            type: 'donut',
            height: 320,
            fontFamily: "'Outfit', sans-serif"
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
                    formatter: function (w) {
                      return w.globals.seriesTotals.reduce((a, b) => a + b, 0);
                    }
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
            style: { fontSize: '14px', fontWeight: '600', color: '#1D2939' }
          },
          responsive: [{
            breakpoint: 480,
            options: { chart: { width: '100%' } }
          }]
        };

        var bookingChart = new ApexCharts(document.querySelector("#chart-booking-status"), bookingOptions);
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
                    formatter: function (w) {
                      return w.globals.seriesTotals.reduce((a, b) => a + b, 0);
                    }
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
            style: { fontSize: '14px', fontWeight: '600', color: '#1D2939' }
          },
          responsive: [{
            breakpoint: 480,
            options: { chart: { width: '100%' } }
          }]
        };

        var vehicleChart = new ApexCharts(document.querySelector("#chart-vehicle-status"), vehicleOptions);
        vehicleChart.render();
    </script>

    <!-- Skrip Tukar Mod Tema Terang/Gelap -->
    <script>
        const themeToggleBtn = document.getElementById('theme-toggle');
        const themeToggleIcon = document.getElementById('theme-toggle-icon');

        // The two daisyUI themes this dashboard switches between
        const LIGHT_THEME = 'light';
        const DARK_THEME  = 'dracula';

        const moonIcon = `<svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M21.752 15.002A9.72 9.72 0 0118 15.75c-5.385 0-9.75-4.365-9.75-9.75 0-1.33.266-2.597.748-3.752A9.753 9.753 0 003 11.25C3 16.635 7.365 21 12.75 21a9.753 9.753 0 009.002-5.998z" /></svg>`;
        const sunIcon = `<svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 text-amber-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M12 3v2.25m6.364.386l-1.591 1.591M21 12h-2.25m-.386 6.364l-1.591-1.591M12 18.75V21m-4.773-4.227l-1.591 1.591M5.25 12H3m4.227-4.773L5.636 5.636M15.75 12a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0z" /></svg>`;

        function applyTheme(theme) {
            document.documentElement.setAttribute('data-theme', theme);
            localStorage.setItem('theme', theme);
            themeToggleIcon.innerHTML = theme === DARK_THEME ? sunIcon : moonIcon;

            // Dynamic update for ApexCharts title and legend colors — read
            // straight from daisyUI's own theme variable so this keeps
            // working even if the themes change later.
            const textColor = getComputedStyle(document.documentElement)
                .getPropertyValue('--color-base-content').trim();

            if (typeof bookingChart !== 'undefined' && typeof vehicleChart !== 'undefined') {
                bookingChart.updateOptions({
                    title: { style: { color: textColor } },
                    legend: { labels: { colors: textColor } }
                });
                vehicleChart.updateOptions({
                    title: { style: { color: textColor } },
                    legend: { labels: { colors: textColor } }
                });
            }
        }

        // Load saved theme or system preference
        const savedTheme = localStorage.getItem('theme');
        const systemPrefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
        const initialTheme = savedTheme || (systemPrefersDark ? DARK_THEME : LIGHT_THEME);

        applyTheme(initialTheme);

        themeToggleBtn.addEventListener('click', () => {
            const currentTheme = document.documentElement.getAttribute('data-theme');
            const newTheme = currentTheme === DARK_THEME ? LIGHT_THEME : DARK_THEME;
            applyTheme(newTheme);
        });
    </script>

    <script src="./assets/js/plugins/perfect-scrollbar.min.js" async></script>
</body>
</html>