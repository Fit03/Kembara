<?php
// vehicles.php — Pengurusan Kenderaan
session_start();
require_once __DIR__ . '/config/database.php';

// Dialihkan ke log masuk jika pengguna belum diabsahkan
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$fullname      = $_SESSION['fullname'];
$role          = $_SESSION['role']; // 'SuperAdmin', 'Admin', atau 'User'
$currentUserId = (int)$_SESSION['user_id'];
$canManage     = in_array($role, ['SuperAdmin', 'Admin'], true);

$email = $_SESSION['email'] ?? null;
if (!$email) {
    $stmt = $pdo->prepare("SELECT email FROM users WHERE user_id = ?");
    $stmt->execute([$currentUserId]);
    $email = $stmt->fetchColumn() ?: 'tiada-emel@selangor.gov.my';
}

$badgeColor = match($role) {
  'SuperAdmin' => 'badge badge-error',
  'Admin'      => 'badge badge-warning',
  default      => 'badge badge-info',
};

$vehicleStatusBadge = fn(string $s) => match ($s) {
  'Available'   => 'badge badge-success',
  'Booked'      => 'badge badge-info',
  'Maintenance' => 'badge badge-warning',
  'Inactive'    => 'badge badge-ghost',
  default       => 'badge badge-ghost',
};
$vehicleStatusLabel = fn(string $s) => match ($s) {
    'Available'   => 'Sedia Ada',
    'Booked'      => 'Sedang Digunakan',
    'Maintenance' => 'Penyelenggaraan',
    'Inactive'    => 'Tidak Aktif',
    default       => $s,
};

/* ==========================================================
   Tindakan Borang (Tambah / Kemaskini / Padam)
   ========================================================== */
$flash = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$canManage) {
        $_SESSION['flash'] = ['type' => 'error', 'msg' => 'Anda tidak mempunyai kebenaran untuk tindakan ini.'];
        header("Location: vehicles.php");
        exit();
    }

    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'add_vehicle' || $action === 'edit_vehicle') {
            $plateNo   = trim($_POST['plate_no'] ?? '');
            $vName     = trim($_POST['vehicle_name'] ?? '') ?: null;
            $vType     = trim($_POST['vehicle_type'] ?? '') ?: null;
            $model     = trim($_POST['model'] ?? '') ?: null;
            $color     = trim($_POST['color'] ?? '') ?: null;
            $capacity  = $_POST['capacity'] !== '' ? (int)$_POST['capacity'] : null;
            $roadTax   = $_POST['road_tax_expiry'] !== '' ? $_POST['road_tax_expiry'] : null;
            $insurance = $_POST['insurance_expiry'] !== '' ? $_POST['insurance_expiry'] : null;
            $status    = $_POST['status'] ?? 'Available';
            $desc      = trim($_POST['description'] ?? '') ?: null;
            $driverId  = $_POST['driver_id'] !== '' ? (int)$_POST['driver_id'] : null;

            if ($plateNo === '') {
                throw new RuntimeException('Nombor plat kenderaan wajib diisi.');
            }
            if (!in_array($status, ['Available', 'Booked', 'Maintenance', 'Inactive'], true)) {
                throw new RuntimeException('Status tidak sah.');
            }

            if ($action === 'add_vehicle') {
                $stmt = $pdo->prepare(
                    "INSERT INTO vehicles
                        (plate_no, vehicle_name, vehicle_type, model, color, capacity,
                         road_tax_expiry, insurance_expiry, status, description, driver_id)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
                );
                $stmt->execute([$plateNo, $vName, $vType, $model, $color, $capacity,
                    $roadTax, $insurance, $status, $desc, $driverId]);

                $flash = ['type' => 'success', 'msg' => "Kenderaan '{$plateNo}' berjaya didaftarkan."];
            } else {
                $vid = (int)($_POST['vehicle_id'] ?? 0);
                if ($vid <= 0) {
                    throw new RuntimeException('Kenderaan tidak sah.');
                }

                $stmt = $pdo->prepare(
                    "UPDATE vehicles SET
                        plate_no = ?, vehicle_name = ?, vehicle_type = ?, model = ?, color = ?,
                        capacity = ?, road_tax_expiry = ?, insurance_expiry = ?, status = ?,
                        description = ?, driver_id = ?
                     WHERE vehicle_id = ?"
                );
                $stmt->execute([$plateNo, $vName, $vType, $model, $color, $capacity,
                    $roadTax, $insurance, $status, $desc, $driverId, $vid]);

                $flash = ['type' => 'success', 'msg' => "Maklumat '{$plateNo}' berjaya dikemaskini."];
            }

        } elseif ($action === 'delete_vehicle') {
            $vid = (int)($_POST['vehicle_id'] ?? 0);
            if ($vid <= 0) {
                throw new RuntimeException('Kenderaan tidak sah.');
            }

            $stmt = $pdo->prepare("SELECT plate_no FROM vehicles WHERE vehicle_id = ?");
            $stmt->execute([$vid]);
            $plateNo = $stmt->fetchColumn();

            $del = $pdo->prepare("DELETE FROM vehicles WHERE vehicle_id = ?");
            $del->execute([$vid]);

            $flash = ['type' => 'success', 'msg' => "Kenderaan '{$plateNo}' berjaya dipadam."];
        }
    } catch (RuntimeException $e) {
        $flash = ['type' => 'error', 'msg' => $e->getMessage()];
    } catch (PDOException $e) {
        if ($e->getCode() === '23000') {
            $flash = ['type' => 'error', 'msg' => 'Operasi gagal — nombor plat mungkin telah wujud, atau kenderaan ini masih mempunyai rekod tempahan berkaitan.'];
        } else {
            $flash = ['type' => 'error', 'msg' => 'Ralat pangkalan data berlaku. Sila cuba lagi.'];
        }
    }

    $_SESSION['flash'] = $flash;
    header("Location: vehicles.php" . (isset($_GET['q']) && $_GET['q'] !== '' ? '?q=' . urlencode($_GET['q']) : ''));
    exit();
}

if (isset($_SESSION['flash'])) {
    $flash = $_SESSION['flash'];
    unset($_SESSION['flash']);
}

/* ==========================================================
   Data Halaman
   ========================================================== */
$search  = trim($_GET['q'] ?? '');
$perPage = 10;
$page    = max(1, (int)($_GET['page'] ?? 1));
$offset  = ($page - 1) * $perPage;

function buildVehiclesPageUrl(int $p, string $search): string {
    $params = ['page' => $p];
    if ($search !== '') {
        $params['q'] = $search;
    }
    return 'vehicles.php?' . http_build_query($params);
}

$baseFrom = "FROM vehicles v
             LEFT JOIN drivers dr ON dr.driver_id = v.driver_id
             LEFT JOIN users u ON u.user_id = dr.user_id";

if ($search !== '') {
    $like = "%{$search}%";

    $countStmt = $pdo->prepare("SELECT COUNT(*) $baseFrom
        WHERE v.plate_no LIKE :like1 OR v.vehicle_name LIKE :like2 OR v.vehicle_type LIKE :like3");
    $countStmt->execute([':like1' => $like, ':like2' => $like, ':like3' => $like]);
    $totalRows = (int)$countStmt->fetchColumn();

    $stmt = $pdo->prepare(
        "SELECT v.*, u.fullname AS driver_name
         $baseFrom
         WHERE v.plate_no LIKE :like1 OR v.vehicle_name LIKE :like2 OR v.vehicle_type LIKE :like3
         ORDER BY FIELD(v.status, 'Available', 'Booked', 'Maintenance', 'Inactive'), v.plate_no
         LIMIT :limit OFFSET :offset"
    );
    $stmt->bindValue(':like1', $like);
    $stmt->bindValue(':like2', $like);
    $stmt->bindValue(':like3', $like);
    $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
} else {
    $totalRows = (int)$pdo->query("SELECT COUNT(*) FROM vehicles")->fetchColumn();

    $stmt = $pdo->prepare(
        "SELECT v.*, u.fullname AS driver_name
         $baseFrom
         ORDER BY FIELD(v.status, 'Available', 'Booked', 'Maintenance', 'Inactive'), v.plate_no
         LIMIT :limit OFFSET :offset"
    );
    $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
}
$vehicles = $stmt->fetchAll(PDO::FETCH_ASSOC);

$totalPages = max(1, (int)ceil($totalRows / $perPage));
if ($page > $totalPages) {
    $page = $totalPages;
}

// Senarai pemandu untuk dropdown penugasan
$driverOptions = $pdo->query(
    "SELECT dr.driver_id, u.fullname, dr.status
     FROM drivers dr
     JOIN users u ON u.user_id = dr.user_id
     ORDER BY u.fullname"
)->fetchAll(PDO::FETCH_ASSOC);

$statusCounts     = $pdo->query("SELECT status, COUNT(*) AS total FROM vehicles GROUP BY status")
    ->fetchAll(PDO::FETCH_KEY_PAIR);
$totalVehicles    = (int)array_sum($statusCounts);
$availableCount   = (int)($statusCounts['Available'] ?? 0);
$bookedCount      = (int)($statusCounts['Booked'] ?? 0);
$maintenanceCount = (int)($statusCounts['Maintenance'] ?? 0);

$pendingApprovals = $pdo->query(
    "SELECT COUNT(*) FROM vehicle_bookings WHERE status = 'Pending'"
)->fetchColumn();
?>
<!DOCTYPE html>
<html lang="ms" garden>
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0, shrink-to-fit=no" />
    <title>Kembara - Kenderaan</title>

    <!-- Font: Outfit -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700&display=swap" rel="stylesheet" />

    <!-- Tailwind CSS CDN & daisyUI Framework (v5) -->
    <link href="https://cdn.jsdelivr.net/npm/daisyui@5" rel="stylesheet" type="text/css" />
    <link href="https://cdn.jsdelivr.net/npm/daisyui@5/themes.css" rel="stylesheet" type="text/css" />
    <script src="https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4"></script>

    <style>
        :root, [data-theme] {
            --ta-canvas: var(--color-base-200);
            --ta-surface: var(--color-base-100);
            --ta-border: var(--color-base-300);
            --ta-ink: var(--color-base-content);
            --ta-muted: color-mix(in oklch, var(--color-base-content) 55%, transparent);
            --ta-brand: var(--color-primary);
            --ta-brand-50: color-mix(in oklch, var(--color-primary) 12%, var(--color-base-100));
        }

        .bg-white { background-color: var(--ta-surface) !important; }
        .text-slate-400, .text-slate-500 { color: var(--ta-muted) !important; }
        .text-slate-700, .text-slate-800 { color: var(--ta-ink) !important; }
        .hover\:bg-slate-50:hover, .hover\:bg-slate-100:hover { background-color: var(--ta-canvas) !important; }
        .hover\:text-slate-700:hover { color: var(--ta-ink) !important; }

        * { font-family: 'Outfit', ui-sans-serif, system-ui, sans-serif; }

        body {
            background: var(--ta-canvas);
            color: var(--ta-ink);
            zoom: 110%;
            transition: background-color 0.2s ease, color 0.2s ease;
        }

        .card, .ta-sidebar, nav {
            background: var(--ta-surface) !important;
            border-color: var(--ta-border) !important;
            color: var(--ta-ink) !important;
        }

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
        .badge-soft-success { background: color-mix(in oklch, var(--color-success) 18%, var(--color-base-100)); color: var(--color-success); }
        .badge-soft-warning { background: color-mix(in oklch, var(--color-warning) 18%, var(--color-base-100)); color: var(--color-warning); }
        .badge-soft-error   { background: color-mix(in oklch, var(--color-error) 18%, var(--color-base-100));   color: var(--color-error); }
        .badge-soft-info    { background: color-mix(in oklch, var(--color-info) 18%, var(--color-base-100));    color: var(--color-info); }
        .badge-soft-neutral { background: color-mix(in oklch, var(--color-neutral) 18%, var(--color-base-100)); color: var(--ta-ink); }

        .ta-divide > * + * { border-top: 1px solid var(--ta-border); }

        ::-webkit-scrollbar { width: 8px; height: 8px; }
        ::-webkit-scrollbar-thumb { background: var(--ta-border); border-radius: 999px; }

        /* Clickable card helpers — clickable only; zoom on hover */
        .card.clickable, .card.clickable { cursor: pointer; transition: transform .14s ease, box-shadow .14s ease; }
        .card.clickable:active, .card.clickable:active { transform: translateY(1px); }
        .card.clickable:hover, .card.clickable:hover { transform: translateY(-4px) scale(1.02); box-shadow: 0 8px 20px rgba(2,6,23,0.06); }

        /* ===== Toast alert — Apple-style spring pop + settle ===== */
        #toast-alert {
            position: fixed;
            top: 1.25rem;
            left: 50%;
            z-index: 300;
            animation: ta-toast-in 0.55s cubic-bezier(0.34, 1.56, 0.64, 1) both;
        }
        #toast-alert.ta-toast-hide {
            animation: ta-toast-out 0.35s cubic-bezier(0.4, 0, 1, 1) forwards;
        }
        @keyframes ta-toast-in {
            0%   { opacity: 0; transform: translate(-50%, -28px) scale(0.85); }
            60%  { opacity: 1; transform: translate(-50%, 6px) scale(1.02); }
            100% { opacity: 1; transform: translate(-50%, 0) scale(1); }
        }
        @keyframes ta-toast-out {
            0%   { opacity: 1; transform: translate(-50%, 0) scale(1); }
            100% { opacity: 0; transform: translate(-50%, -18px) scale(0.92); }
        }

        /* ===== Modals — native <dialog>, animated with @starting-style so
           open/close both get a soft spring scale + backdrop blur fade,
           similar to iOS/macOS sheet & alert transitions. ===== */
        dialog.modal {
            opacity: 0;
            transform: scale(0.92) translateY(12px);
            transition: opacity 0.28s cubic-bezier(0.34, 1.56, 0.64, 1),
                        transform 0.32s cubic-bezier(0.34, 1.56, 0.64, 1),
                        overlay 0.32s allow-discrete,
                        display 0.32s allow-discrete;
        }
        dialog.modal[open] {
            opacity: 1;
            transform: scale(1) translateY(0);
        }
        @starting-style {
            dialog.modal[open] {
                opacity: 0;
                transform: scale(0.92) translateY(12px);
            }
        }
        dialog.modal::backdrop {
            background: rgba(15, 23, 42, 0);
            backdrop-filter: blur(0px);
            transition: background 0.32s ease, backdrop-filter 0.32s ease,
                        overlay 0.32s allow-discrete, display 0.32s allow-discrete;
        }
        dialog.modal[open]::backdrop {
            background: rgba(15, 23, 42, 0.45);
            backdrop-filter: blur(3px);
        }
        @starting-style {
            dialog.modal[open]::backdrop {
                background: rgba(15, 23, 42, 0);
                backdrop-filter: blur(0px);
            }
        }
    </style>
</head>
<body class="min-h-screen">

    <!-- Menu Sisi (Sidebar) -->
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
            <a class="ta-nav-link" href="dashboard.php">
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
            <a class="ta-nav-link active" href="vehicles.php">
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

    <!-- Navbar Bawah (mobile) -->
    <div class="fixed inset-x-0 bottom-0 z-[70] xl:hidden">
      <div class="relative">
        <a href="dashboard.php" class="absolute left-1/2 -translate-x-1/2 -top-7 z-10 flex flex-col items-center gap-1">
          <span class="w-16 h-16 rounded-full flex items-center justify-center shadow-lg" style="background:var(--ta-brand); color:#fff; box-shadow:0 6px 16px -4px color-mix(in oklch, var(--ta-brand) 60%, transparent), 0 0 0 6px var(--ta-canvas)">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-7 w-7" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 3v11.25A2.25 2.25 0 006 16.5h2.25M3.75 3h-1.5M3.75 3h16.5M21.75 3v11.25A2.25 2.25 0 0119.5 16.5H17.25m-10.5 0h6m-6 0v3.75A1.5 1.5 0 007.5 21.75h9a1.5 1.5 0 001.5-1.5V16.5m-10.5 0h10.5" /></svg>
          </span>
          <span class="text-[11px] font-semibold" style="color:var(--ta-brand)">Dashboard</span>
        </a>

        <div class="flex items-center justify-around px-2 pt-2" style="background:var(--ta-surface); border-top:1px solid var(--ta-border); height:4.25rem; padding-bottom:env(safe-area-inset-bottom)">
          <a href="bookings.php" class="flex flex-col items-center gap-1 flex-1 py-1" style="color:var(--ta-muted)">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 012.25-2.25h13.5A2.25 2.25 0 0121 7.5v11.25m-18 0A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75m-18 0v-7.5A2.25 2.25 0 015.25 9h13.5A2.25 2.25 0 0121 11.25v7.5m-13.5-6h.008v.008h-.008v-.008zm0 3h.008v.008h-.008v-.008zm3-3h.008v.008h-.008v-.008zm0 3h.008v.008h-.008v-.008z" /></svg>
            <span class="text-[11px] font-medium">Tempahan</span>
          </a>
          <a href="vehicles.php" class="flex flex-col items-center gap-1 flex-1 py-1" style="color:var(--ta-brand)">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 18.75a1.5 1.5 0 01-3 0m3 0a1.5 1.5 0 00-3 0m3 0h6m-9 0H3.375a1.125 1.125 0 01-1.125-1.125V14.25m17.25 4.5a1.5 1.5 0 01-3 0m3 0a1.5 1.5 0 00-3 0m3 0h1.125c.621 0 1.129-.504 1.09-1.124a17.902 17.902 0 00-3.213-9.193 2.056 2.056 0 00-1.58-.86H14.25M16.5 18.75h-2.25m0-11.177v-.958c0-.568-.422-1.048-.987-1.106a48.554 48.554 0 00-10.026 0 1.106 1.106 0 00-.987 1.106v7.635m12-6.677v6.677m0 0h-12" /></svg>
            <span class="text-[11px] font-medium">Kenderaan</span>
          </a>
          <div class="flex-1 flex justify-center"><span class="w-16"></span></div>
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
                <h6 class="font-bold text-base leading-tight">Kenderaan</h6>
                <p class="text-xs leading-tight" style="color:var(--ta-muted)">
                    <span class="opacity-70">Halaman</span>
                    <span class="mx-1 opacity-40">/</span>
                    <span>Kenderaan</span>
                </p>
            </div>
        </div>

        <form action="vehicles.php" method="GET" class="hidden md:flex items-center gap-2 rounded-lg px-3 py-2 flex-1 max-w-sm" style="background:var(--ta-canvas); border:1px solid var(--ta-border)">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 shrink-0" style="color:var(--ta-muted)" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607z" /></svg>
            <input type="text" name="q" value="<?= htmlspecialchars($search) ?>" placeholder="Cari plat, nama atau jenis kenderaan..." class="bg-transparent border-0 outline-none text-sm w-full placeholder:text-slate-400" />
        </form>

            <div class="flex items-center gap-1.5 sm:gap-2">
                <button type="button" id="theme-toggle" class="btn btn-ghost btn-circle text-slate-500 hover:bg-slate-100 border-0 h-10 w-10 min-h-0">
                    <span id="theme-toggle-icon"></span>
                </button>

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
                        <div class="px-4 py-3 border-b flex items-center justify-between" style="border-color: var(--ta-border);">
                            <span class="font-bold text-sm">Notifikasi</span>
                        </div>
                        <div class="max-h-64 overflow-y-auto">
                            <?php if ($pendingApprovals > 0): ?>
                                <a href="bookings.php?status=Pending" class="flex items-start gap-3 p-3.5 hover:bg-slate-50 transition-colors">
                                    <div class="p-2 rounded-full bg-warning/15 text-warning shrink-0 mt-0.5">
                                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                                    </div>
                                    <div class="flex-1 min-w-0">
                                        <p class="text-xs font-semibold">Tempahan Menunggu Kelulusan</p>
                                        <p class="text-[11px] text-slate-400 mt-0.5">Terdapat <?= $pendingApprovals ?> tempahan yang memerlukan tindakan anda.</p>
                                    </div>
                                </a>
                            <?php else: ?>
                                <div class="p-6 text-center text-slate-400 text-xs">Tiada notifikasi baharu.</div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <div class="dropdown dropdown-end">
                    <div tabindex="0" role="button" class="btn btn-ghost rounded-full pl-1 pr-2 py-1 flex items-center gap-2 h-auto min-h-0">
                        <div class="avatar placeholder">
                            <div class="rounded-full w-8 h-8 flex items-center justify-center font-bold text-xs uppercase text-white" style="background:var(--ta-brand)">
                                <?= htmlspecialchars(substr($fullname, 0, 1)) ?>
                            </div>
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

        <?php if ($flash): ?>
          <div id="toast-alert" class="card shadow-2xl px-4 py-3.5 rounded-2xl flex items-center gap-3 border" style="border-color: var(--color-<?= $flash['type'] === 'success' ? 'success' : 'error' ?>); max-width: 26rem; backdrop-filter: blur(16px);">
            <div class="p-1.5 rounded-full shrink-0 <?= $flash['type'] === 'success' ? 'bg-success/15 text-success' : 'bg-error/15 text-error' ?>">
              <?php if ($flash['type'] === 'success'): ?>
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" /></svg>
              <?php else: ?>
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z" /></svg>
              <?php endif; ?>
            </div>
            <p class="text-sm font-medium flex-1"><?= htmlspecialchars($flash['msg']) ?></p>
            <button type="button" onclick="dismissToast()" class="text-slate-400 hover:text-slate-600 shrink-0">
              <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" /></svg>
            </button>
          </div>
        <?php endif; ?>

        <!-- Baris 1: Kad Statistik -->
        <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-5">
          <div class="card p-5" data-href="vehicles.php">
            <div class="ta-icon-box mb-4">
              <svg xmlns="http://www.w3.org/2000/svg" class="h-5.5 w-5.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 18.75a1.5 1.5 0 01-3 0m3 0a1.5 1.5 0 00-3 0m3 0h6m-9 0H3.375a1.125 1.125 0 01-1.125-1.125V14.25m17.25 4.5a1.5 1.5 0 01-3 0m3 0a1.5 1.5 0 00-3 0m3 0h1.125c.621 0 1.129-.504 1.09-1.124a17.902 17.902 0 00-3.213-9.193 2.056 2.056 0 00-1.58-.86H14.25M16.5 18.75h-2.25m0-11.177v-.958c0-.568-.422-1.048-.987-1.106a48.554 48.554 0 00-10.026 0 1.106 1.106 0 00-.987 1.106v7.635m12-6.677v6.677m0 0h-12" /></svg>
            </div>
            <p class="text-sm mb-1" style="color:var(--ta-muted)">Jumlah Kenderaan</p>
            <h5 class="text-2xl font-bold"><?= $totalVehicles ?></h5>
          </div>

          <div class="card p-5" data-href="vehicles.php?status=Available">
            <div class="ta-icon-box mb-4">
              <svg xmlns="http://www.w3.org/2000/svg" class="h-5.5 w-5.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
            </div>
            <p class="text-sm mb-1" style="color:var(--ta-muted)">Sedia Ada</p>
            <h5 class="text-2xl font-bold"><?= $availableCount ?></h5>
          </div>

          <div class="card p-5" data-href="vehicles.php?status=Booked">
            <div class="ta-icon-box mb-4">
              <svg xmlns="http://www.w3.org/2000/svg" class="h-5.5 w-5.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6"><path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 012.25-2.25h13.5A2.25 2.25 0 0121 7.5v11.25m-18 0A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75m-18 0v-7.5A2.25 2.25 0 015.25 9h13.5A2.25 2.25 0 0121 11.25v7.5" /></svg>
            </div>
            <p class="text-sm mb-1" style="color:var(--ta-muted)">Sedang Digunakan</p>
            <h5 class="text-2xl font-bold"><?= $bookedCount ?></h5>
          </div>

          <div class="card p-5" data-href="vehicles.php?status=Maintenance">
            <div class="ta-icon-box mb-4">
              <svg xmlns="http://www.w3.org/2000/svg" class="h-5.5 w-5.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6"><path stroke-linecap="round" stroke-linejoin="round" d="M11.42 15.17L17.25 21A2.652 2.652 0 0021 17.25l-5.877-5.877M11.42 15.17l2.496-3.03c.317-.384.74-.626 1.208-.766M11.42 15.17l-4.655 5.653a2.548 2.548 0 11-3.586-3.586l6.837-5.63m5.108-.233c.55-.164 1.163-.188 1.743-.14a4.5 4.5 0 004.486-6.336l-3.276 3.277a3.004 3.004 0 01-2.25-2.25l3.276-3.276a4.5 4.5 0 00-6.336 4.486c.091 1.076-.071 2.264-.904 2.95l-.102.085m-1.745 1.437L5.909 7.5H4.5L2.25 3.75l1.5-1.5L7.5 4.5v1.409l4.26 4.26m-1.745 1.437l1.745-1.437m6.615 8.206L15.75 15.75M4.867 19.125h.008v.008h-.008v-.008z" /></svg>
            </div>
            <p class="text-sm mb-1" style="color:var(--ta-muted)">Penyelenggaraan</p>
            <h5 class="text-2xl font-bold"><?= $maintenanceCount ?></h5>
          </div>
        </div>

        <!-- Baris 2: Jadual Kenderaan -->
        <div class="card p-5 mt-5">
          <div class="flex items-center justify-between gap-3 mb-4 flex-wrap">
            <div>
              <h6 class="font-semibold">Senarai Kenderaan</h6>
              <p class="mb-0 text-sm" style="color:var(--ta-muted)">Urus fleet, penugasan pemandu &amp; dokumen kenderaan</p>
            </div>
            <?php if ($canManage): ?>
            <button type="button" class="btn btn-sm text-white border-0 gap-1.5" style="background:var(--ta-brand)" onclick="openAddModal()">
              <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" /></svg>
              Tambah Kenderaan
            </button>
            <?php endif; ?>
          </div>

          <div class="overflow-x-auto -mx-1">
            <table class="items-center w-full mb-0 align-top">
              <thead>
                <tr class="border-b" style="border-color:var(--ta-border)">
                  <th class="px-3 py-2 text-xs font-semibold text-left uppercase text-slate-400">Kenderaan</th>
                  <th class="px-3 py-2 text-xs font-semibold text-left uppercase text-slate-400">Jenis / Muatan</th>
                  <th class="px-3 py-2 text-xs font-semibold text-left uppercase text-slate-400">Pemandu Ditugaskan</th>
                  <th class="px-3 py-2 text-xs font-semibold text-left uppercase text-slate-400">Dokumen</th>
                  <th class="px-3 py-2 text-xs font-semibold text-center uppercase text-slate-400">Status</th>
                  <?php if ($canManage): ?>
                  <th class="px-3 py-2 text-xs font-semibold text-center uppercase text-slate-400">Tindakan</th>
                  <?php endif; ?>
                </tr>
              </thead>
              <tbody>
                <?php if (empty($vehicles)): ?>
                  <tr><td colspan="<?= $canManage ? 6 : 5 ?>" class="px-3 py-6 text-sm text-center text-slate-400">Tiada kenderaan dijumpai.</td></tr>
                <?php endif; ?>
                <?php foreach ($vehicles as $v): ?>
                  <?php
                    $soon = false;
                    $today = new DateTime();
                    foreach ([$v['road_tax_expiry'], $v['insurance_expiry']] as $exp) {
                        if ($exp && (new DateTime($exp))->diff($today)->days <= 30 && strtotime($exp) >= strtotime('today')) {
                            $soon = true;
                        }
                    }
                  ?>
                  <tr class="hover:bg-slate-50/70 transition-colors">
                    <td class="px-3 py-3 text-sm border-b whitespace-nowrap" style="border-color:var(--ta-border)">
                      <p class="mb-0 font-medium"><?= htmlspecialchars($v['plate_no']) ?></p>
                      <p class="mb-0 text-xs text-slate-400"><?= htmlspecialchars(trim(($v['vehicle_name'] ?? '') . ' ' . ($v['model'] ?? ''))) ?: '—' ?></p>
                    </td>
                    <td class="px-3 py-3 text-sm border-b whitespace-nowrap" style="border-color:var(--ta-border)">
                      <?= htmlspecialchars($v['vehicle_type'] ?? '—') ?>
                      <?php if ($v['capacity']): ?><span class="text-slate-400">&middot; <?= (int)$v['capacity'] ?> penumpang</span><?php endif; ?>
                    </td>
                    <td class="px-3 py-3 text-sm border-b whitespace-nowrap" style="border-color:var(--ta-border)"><?= htmlspecialchars($v['driver_name'] ?? '—') ?></td>
                    <td class="px-3 py-3 text-sm border-b whitespace-nowrap" style="border-color:var(--ta-border)">
                      <?php if ($soon): ?>
                        <span class="ta-badge badge badge-warning">Tamat Tempoh Segera</span>
                      <?php else: ?>
                        <span class="text-slate-400 text-xs">Terkini</span>
                      <?php endif; ?>
                    </td>
                    <td class="px-3 py-3 text-sm border-b text-center whitespace-nowrap" style="border-color:var(--ta-border)">
                      <span class="ta-badge <?= $vehicleStatusBadge($v['status']) ?>"><?= htmlspecialchars($vehicleStatusLabel($v['status'])) ?></span>
                    </td>
                    <?php if ($canManage): ?>
                    <td class="px-3 py-3 text-sm border-b text-center whitespace-nowrap" style="border-color:var(--ta-border)">
                      <button type="button" class="btn btn-ghost btn-xs" title="Kemaskini"
                        onclick='openEditModal(<?= json_encode([
                            "vehicle_id"       => (int)$v["vehicle_id"],
                            "plate_no"         => $v["plate_no"],
                            "vehicle_name"     => $v["vehicle_name"],
                            "vehicle_type"     => $v["vehicle_type"],
                            "model"            => $v["model"],
                            "color"            => $v["color"],
                            "capacity"         => $v["capacity"],
                            "road_tax_expiry"  => $v["road_tax_expiry"],
                            "insurance_expiry" => $v["insurance_expiry"],
                            "status"           => $v["status"],
                            "description"      => $v["description"],
                            "driver_id"        => $v["driver_id"],
                        ], JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931z" /></svg>
                      </button>
                      <button type="button" class="btn btn-ghost btn-xs text-error" title="Padam"
                        onclick="openDeleteModal(<?= (int)$v['vehicle_id'] ?>, '<?= htmlspecialchars(addslashes($v['plate_no'])) ?>')">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M14.74 9l-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 01-2.244 2.077H8.084a2.25 2.25 0 01-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 00-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 013.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 00-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 00-7.5 0" /></svg>
                      </button>
                    </td>
                    <?php endif; ?>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>

          <?php if ($totalRows > 0): ?>
          <div class="flex items-center justify-between gap-3 flex-wrap mt-4 pt-4 border-t" style="border-color:var(--ta-border)">
            <p class="text-xs text-slate-400">
              Menunjukkan <?= $offset + 1 ?>–<?= min($offset + $perPage, $totalRows) ?> daripada <?= $totalRows ?> kenderaan
            </p>
            <div class="join">
              <a href="<?= $page > 1 ? htmlspecialchars(buildVehiclesPageUrl($page - 1, $search)) : '#' ?>"
                 class="join-item btn btn-sm <?= $page <= 1 ? 'btn-disabled opacity-40' : '' ?>">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.2"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5L8.25 12l7.5-7.5" /></svg>
              </a>
              <span class="join-item btn btn-sm btn-disabled !bg-transparent !border-none font-semibold" style="color:var(--ta-ink)">
                <?= $page ?> / <?= $totalPages ?>
              </span>
              <a href="<?= $page < $totalPages ? htmlspecialchars(buildVehiclesPageUrl($page + 1, $search)) : '#' ?>"
                 class="join-item btn btn-sm <?= $page >= $totalPages ? 'btn-disabled opacity-40' : '' ?>">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.2"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5" /></svg>
              </a>
            </div>
          </div>
          <?php endif; ?>
        </div>

        <footer class="pt-6 pb-2">
          <div class="text-sm leading-normal text-center text-slate-400">
            © <?= date('Y') ?> Kembara &middot; Perbendaharaan Negeri Selangor
          </div>
        </footer>
      </div>
    </main>

    <?php if ($canManage): ?>
    <!-- Modal: Tambah Kenderaan -->
    <dialog id="modal-add" class="modal">
      <div class="modal-box card max-w-lg">
        <form method="dialog"><button class="btn btn-sm btn-circle btn-ghost absolute right-3 top-3">✕</button></form>
        <h3 class="font-bold text-lg mb-4">Tambah Kenderaan Baharu</h3>
        <form action="vehicles.php<?= $search !== '' ? '?q=' . urlencode($search) : '' ?>" method="POST" class="flex flex-col gap-3">
          <input type="hidden" name="action" value="add_vehicle" />
          <div class="grid grid-cols-2 gap-3">
            <div>
              <label class="text-xs font-medium block mb-1">Nombor Plat</label>
              <input type="text" name="plate_no" required class="input input-bordered w-full" />
            </div>
            <div>
              <label class="text-xs font-medium block mb-1">Nama Kenderaan</label>
              <input type="text" name="vehicle_name" class="input input-bordered w-full" />
            </div>
            <div>
              <label class="text-xs font-medium block mb-1">Jenis Kenderaan</label>
              <input type="text" name="vehicle_type" placeholder="MPV, Van, Sedan..." class="input input-bordered w-full" />
            </div>
            <div>
              <label class="text-xs font-medium block mb-1">Model</label>
              <input type="text" name="model" class="input input-bordered w-full" />
            </div>
            <div>
              <label class="text-xs font-medium block mb-1">Warna</label>
              <input type="text" name="color" class="input input-bordered w-full" />
            </div>
            <div>
              <label class="text-xs font-medium block mb-1">Kapasiti Penumpang</label>
              <input type="number" name="capacity" min="1" class="input input-bordered w-full" />
            </div>
            <div>
              <label class="text-xs font-medium block mb-1">Tamat Tempoh Cukai Jalan</label>
              <input type="date" name="road_tax_expiry" class="input input-bordered w-full" />
            </div>
            <div>
              <label class="text-xs font-medium block mb-1">Tamat Tempoh Insurans</label>
              <input type="date" name="insurance_expiry" class="input input-bordered w-full" />
            </div>
            <div>
              <label class="text-xs font-medium block mb-1">Status</label>
              <select name="status" class="select select-bordered w-full">
                <option value="Available">Sedia Ada</option>
                <option value="Booked">Sedang Digunakan</option>
                <option value="Maintenance">Penyelenggaraan</option>
                <option value="Inactive">Tidak Aktif</option>
              </select>
            </div>
            <div>
              <label class="text-xs font-medium block mb-1">Pemandu Ditugaskan</label>
              <select name="driver_id" class="select select-bordered w-full">
                <option value="">— Tiada —</option>
                <?php foreach ($driverOptions as $do): ?>
                  <option value="<?= (int)$do['driver_id'] ?>"><?= htmlspecialchars($do['fullname']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>
          <div>
            <label class="text-xs font-medium block mb-1">Catatan</label>
            <textarea name="description" rows="2" class="textarea textarea-bordered w-full"></textarea>
          </div>
          <div class="modal-action mt-2">
            <button type="button" class="btn btn-ghost" onclick="document.getElementById('modal-add').close()">Batal</button>
            <button type="submit" class="btn text-white border-0" style="background:var(--ta-brand)">Daftar Kenderaan</button>
          </div>
        </form>
      </div>
      <form method="dialog" class="modal-backdrop"><button>close</button></form>
    </dialog>

    <!-- Modal: Kemaskini Kenderaan -->
    <dialog id="modal-edit" class="modal">
      <div class="modal-box card max-w-lg">
        <form method="dialog"><button class="btn btn-sm btn-circle btn-ghost absolute right-3 top-3">✕</button></form>
        <h3 class="font-bold text-lg mb-4">Kemaskini Kenderaan</h3>
        <form action="vehicles.php<?= $search !== '' ? '?q=' . urlencode($search) : '' ?>" method="POST" class="flex flex-col gap-3">
          <input type="hidden" name="action" value="edit_vehicle" />
          <input type="hidden" name="vehicle_id" id="edit-vehicle-id" />
          <div class="grid grid-cols-2 gap-3">
            <div>
              <label class="text-xs font-medium block mb-1">Nombor Plat</label>
              <input type="text" name="plate_no" id="edit-plate-no" required class="input input-bordered w-full" />
            </div>
            <div>
              <label class="text-xs font-medium block mb-1">Nama Kenderaan</label>
              <input type="text" name="vehicle_name" id="edit-vehicle-name" class="input input-bordered w-full" />
            </div>
            <div>
              <label class="text-xs font-medium block mb-1">Jenis Kenderaan</label>
              <input type="text" name="vehicle_type" id="edit-vehicle-type" class="input input-bordered w-full" />
            </div>
            <div>
              <label class="text-xs font-medium block mb-1">Model</label>
              <input type="text" name="model" id="edit-model" class="input input-bordered w-full" />
            </div>
            <div>
              <label class="text-xs font-medium block mb-1">Warna</label>
              <input type="text" name="color" id="edit-color" class="input input-bordered w-full" />
            </div>
            <div>
              <label class="text-xs font-medium block mb-1">Kapasiti Penumpang</label>
              <input type="number" name="capacity" id="edit-capacity" min="1" class="input input-bordered w-full" />
            </div>
            <div>
              <label class="text-xs font-medium block mb-1">Tamat Tempoh Cukai Jalan</label>
              <input type="date" name="road_tax_expiry" id="edit-road-tax" class="input input-bordered w-full" />
            </div>
            <div>
              <label class="text-xs font-medium block mb-1">Tamat Tempoh Insurans</label>
              <input type="date" name="insurance_expiry" id="edit-insurance" class="input input-bordered w-full" />
            </div>
            <div>
              <label class="text-xs font-medium block mb-1">Status</label>
              <select name="status" id="edit-status" class="select select-bordered w-full">
                <option value="Available">Sedia Ada</option>
                <option value="Booked">Sedang Digunakan</option>
                <option value="Maintenance">Penyelenggaraan</option>
                <option value="Inactive">Tidak Aktif</option>
              </select>
            </div>
            <div>
              <label class="text-xs font-medium block mb-1">Pemandu Ditugaskan</label>
              <select name="driver_id" id="edit-driver" class="select select-bordered w-full">
                <option value="">— Tiada —</option>
                <?php foreach ($driverOptions as $do): ?>
                  <option value="<?= (int)$do['driver_id'] ?>"><?= htmlspecialchars($do['fullname']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>
          <div>
            <label class="text-xs font-medium block mb-1">Catatan</label>
            <textarea name="description" id="edit-description" rows="2" class="textarea textarea-bordered w-full"></textarea>
          </div>
          <div class="modal-action mt-2">
            <button type="button" class="btn btn-ghost" onclick="document.getElementById('modal-edit').close()">Batal</button>
            <button type="submit" class="btn text-white border-0" style="background:var(--ta-brand)">Simpan Perubahan</button>
          </div>
        </form>
      </div>
      <form method="dialog" class="modal-backdrop"><button>close</button></form>
    </dialog>

    <!-- Modal: Sahkan Padam -->
    <dialog id="modal-delete" class="modal">
      <div class="modal-box card max-w-sm">
        <h3 class="font-bold text-lg mb-2">Padam Kenderaan?</h3>
        <p class="text-sm text-slate-400 mb-4">Anda pasti mahu memadam kenderaan <span id="delete-vehicle-name" class="font-semibold text-slate-600"></span>? Tindakan ini tidak boleh diundur.</p>
        <form action="vehicles.php<?= $search !== '' ? '?q=' . urlencode($search) : '' ?>" method="POST" class="flex justify-end gap-2">
          <input type="hidden" name="action" value="delete_vehicle" />
          <input type="hidden" name="vehicle_id" id="delete-vehicle-id" />
          <button type="button" class="btn btn-ghost" onclick="document.getElementById('modal-delete').close()">Batal</button>
          <button type="submit" class="btn btn-error text-white border-0">Padam</button>
        </form>
      </div>
      <form method="dialog" class="modal-backdrop"><button>close</button></form>
    </dialog>

    <script>
        function openAddModal() {
            document.getElementById('modal-add').showModal();
        }

        function openEditModal(v) {
            document.getElementById('edit-vehicle-id').value    = v.vehicle_id;
            document.getElementById('edit-plate-no').value      = v.plate_no;
            document.getElementById('edit-vehicle-name').value  = v.vehicle_name || '';
            document.getElementById('edit-vehicle-type').value  = v.vehicle_type || '';
            document.getElementById('edit-model').value         = v.model || '';
            document.getElementById('edit-color').value         = v.color || '';
            document.getElementById('edit-capacity').value      = v.capacity || '';
            document.getElementById('edit-road-tax').value      = v.road_tax_expiry || '';
            document.getElementById('edit-insurance').value     = v.insurance_expiry || '';
            document.getElementById('edit-status').value        = v.status;
            document.getElementById('edit-driver').value        = v.driver_id || '';
            document.getElementById('edit-description').value   = v.description || '';
            document.getElementById('modal-edit').showModal();
        }

        function openDeleteModal(id, name) {
            document.getElementById('delete-vehicle-id').value = id;
            document.getElementById('delete-vehicle-name').textContent = name;
            document.getElementById('modal-delete').showModal();
        }
    </script>
    <?php endif; ?>

    <!-- Skrip Notifikasi Toast (sentiasa dimuatkan, tanpa mengira peranan) -->
    <script>
        function dismissToast() {
            const toast = document.getElementById('toast-alert');
            if (!toast) return;
            toast.classList.add('ta-toast-hide');
            toast.addEventListener('animationend', () => toast.remove(), { once: true });
        }

        (function () {
            if (document.getElementById('toast-alert')) {
                setTimeout(dismissToast, 4000);
            }
        })();
    </script>

    <!-- Skrip Tukar Mod Tema Terang/Gelap -->
    <script>
        const themeToggleBtn = document.getElementById('theme-toggle');
        const themeToggleIcon = document.getElementById('theme-toggle-icon');

        const LIGHT_THEME = 'garden';
        const DARK_THEME  = 'dracula';

        const moonIcon = `<svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M21.752 15.002A9.72 9.72 0 0118 15.75c-5.385 0-9.75-4.365-9.75-9.75 0-1.33.266-2.597.748-3.752A9.753 9.753 0 003 11.25C3 16.635 7.365 21 12.75 21a9.753 9.753 0 009.002-5.998z" /></svg>`;
        const sunIcon = `<svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 text-amber-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M12 3v2.25m6.364.386l-1.591 1.591M21 12h-2.25m-.386 6.364l-1.591-1.591M12 18.75V21m-4.773-4.227l-1.591 1.591M5.25 12H3m4.227-4.773L5.636 5.636M15.75 12a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0z" /></svg>`;

        function applyTheme(theme) {
            document.documentElement.setAttribute('data-theme', theme);
            localStorage.setItem('theme', theme);
            themeToggleIcon.innerHTML = theme === DARK_THEME ? sunIcon : moonIcon;
        }

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
    <script>
    (function(){
      document.querySelectorAll('.card, .card').forEach(function(card){
        var href = card.dataset.href;
        if (href) {
          card.classList.add('clickable');
          card.addEventListener('click', function(e){
            if (e.target.closest('a, button, input, select, textarea')) return;
            window.location.href = href;
          });
          card.setAttribute('tabindex','0');
          card.addEventListener('keypress', function(e){ if (e.key === 'Enter') card.click(); });
        }
        // no automatic warning styling — cards are clickable only
      });
    })();
    </script>
</body>
</html>