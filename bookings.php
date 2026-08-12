<?php
// bookings.php — Pengurusan Permohonan Tempahan Kenderaan
session_start();
require_once __DIR__ . '/config/database.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$fullname      = $_SESSION['fullname'];
$role          = $_SESSION['role'];
$currentUserId = (int)$_SESSION['user_id'];

// Ambil emel pengguna jika belum ada dalam sesi
$email = $_SESSION['email'] ?? null;
if (!$email) {
    $stmt = $pdo->prepare("SELECT email FROM users WHERE user_id = ?");
    $stmt->execute([$currentUserId]);
    $email = $stmt->fetchColumn() ?: 'tiada-emel@selangor.gov.my';
}

$badgeColor = match($role) {
    'SuperAdmin' => 'badge-soft-error',
    'Admin'      => 'badge-soft-warning',
    default      => 'badge-soft-info',
};

// --- PROSES KEMASKINI STATUS (DILAKUKAN OLEH ADMIN / SUPERADMIN / PEMOHON) ---
$actionMsg = null;
$actionErr = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_type'])) {
    $actionType = $_POST['action_type'];
    $bookingId  = (int)($_POST['booking_id'] ?? 0);
    $remarks    = trim($_POST['remarks'] ?? '');

    try {
        $stmtB = $pdo->prepare("SELECT * FROM vehicle_bookings WHERE booking_id = ?");
        $stmtB->execute([$bookingId]);
        $booking = $stmtB->fetch(PDO::FETCH_ASSOC);

        if (!$booking) {
            throw new RuntimeException("Rekod tempahan tidak dijumpai.");
        }

        // Semakan Hak Akses
        if ($actionType === 'cancel') {
            if ($booking['user_id'] != $currentUserId && !in_array($role, ['Admin', 'SuperAdmin'], true)) {
                throw new RuntimeException("Anda tidak mempunyai kebenaran untuk membatalkan tempahan ini.");
            }
            $newStatus = 'Cancelled';
            $logAction = 'Dibatalkan';
        } elseif (in_array($actionType, ['approve', 'reject'], true)) {
            if (!in_array($role, ['Admin', 'SuperAdmin'], true)) {
                throw new RuntimeException("Hanya Pentadbir yang boleh meluluskan atau menolak tempahan.");
            }
            $newStatus = ($actionType === 'approve') ? 'Approved' : 'Rejected';
            $logAction = ($actionType === 'approve') ? 'Diluluskan' : 'Ditolak';
        } else {
            throw new RuntimeException("Tindakan tidak sah.");
        }

        // Kemaskini Status Tempahan
        if ($newStatus === 'Approved') {
            $uStmt = $pdo->prepare("UPDATE vehicle_bookings SET status = ?, approved_by = ?, approved_at = NOW() WHERE booking_id = ?");
            $uStmt->execute([$newStatus, $currentUserId, $bookingId]);
        } else {
            $uStmt = $pdo->prepare("UPDATE vehicle_bookings SET status = ? WHERE booking_id = ?");
            $uStmt->execute([$newStatus, $bookingId]);
        }

        // Catat Log Sejarah (Sesuai skema: module, action, remarks, action_by)
        $hStmt = $pdo->prepare("INSERT INTO booking_history (booking_id, module, action, remarks, action_by) VALUES (?, 'Vehicle', ?, ?, ?)");
        $hStmt->execute([$bookingId, $logAction, $remarks ?: "Tindakan {$logAction} diambil.", $currentUserId]);

        $_SESSION['flash'] = ['type' => 'success', 'msg' => "Status tempahan #{$booking['booking_no']} berjaya dikemaskini kepada '{$newStatus}'."];
        header("Location: bookings.php");
        exit();

    } catch (Exception $e) {
        $actionErr = $e->getMessage();
    }
}

// --- PENGAMBILAN DATA TEMPAHAN (DISESUAIKAN DENGAN SKEMA PANGKALAN DATA) ---
$search = trim($_GET['search'] ?? '');
$filter = trim($_GET['status'] ?? '');

$sql = "
    SELECT 
        b.*,
        u.fullname AS applicant_name,
        u.email AS applicant_email,
        v.plate_no, 
        v.model AS vehicle_model,
        du.fullname AS driver_name, 
        du.phone_no AS driver_phone
    FROM vehicle_bookings b
    JOIN users u ON b.user_id = u.user_id
    LEFT JOIN vehicles v ON b.vehicle_id = v.vehicle_id
    LEFT JOIN drivers d ON b.driver_id = d.driver_id
    LEFT JOIN users du ON d.user_id = du.user_id
    WHERE 1=1
";

$params = [];

// Sekatan Pandangan: User biasa hanya melihat tempahan sendiri
if ($role === 'User') {
    $sql .= " AND b.user_id = ? ";
    $params[] = $currentUserId;
}

if ($search !== '') {
    $sql .= " AND (b.booking_no LIKE ? OR b.origin LIKE ? OR b.destination LIKE ? OR u.fullname LIKE ?) ";
    $sTerm = "%{$search}%";
    array_push($params, $sTerm, $sTerm, $sTerm, $sTerm);
}

if ($filter !== '') {
    $sql .= " AND b.status = ? ";
    $params[] = $filter;
}

$sql .= " ORDER BY b.depart_datetime DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$bookings = $stmt->fetchAll(PDO::FETCH_ASSOC);

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
?>
<!DOCTYPE html>
<html lang="ms" data-theme="light">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0, shrink-to-fit=no" />
    <title>Kembara - Pengurusan Tempahan Kenderaan</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700&display=swap" rel="stylesheet" />

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

        * { font-family: 'Outfit', ui-sans-serif, system-ui, sans-serif; }

        body {
            background: var(--ta-canvas);
            color: var(--ta-ink);
            zoom: 110%;
            transition: background-color 0.2s ease, color 0.2s ease;
        }

        .ta-card, .ta-sidebar, nav {
            background: var(--ta-surface) !important;
            border-color: var(--ta-border) !important;
            color: var(--ta-ink) !important;
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
        .badge-soft-error   { background: color-mix(in oklch, var(--color-error) 18%, var(--color-base-100));   color: var(--color-error); }
        .badge-soft-warning { background: color-mix(in oklch, var(--color-warning) 18%, var(--color-base-100)); color: var(--color-warning); }
        .badge-soft-info    { background: color-mix(in oklch, var(--color-info) 18%, var(--color-base-100));    color: var(--color-info); }
        .badge-soft-success { background: color-mix(in oklch, var(--color-success) 18%, var(--color-base-100)); color: var(--color-success); }
    </style>
</head>
<body class="min-h-screen">

    <!-- Sidebar -->
    <aside id="sidenav-main" class="fixed inset-y-0 left-0 z-[70] w-64 hidden xl:flex xl:flex-col overflow-y-auto ta-sidebar border-r" style="border-color:var(--ta-border)">
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
                    <a class="ta-nav-link active" href="bookings.php">
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
    </aside>

    <main class="xl:ml-64 relative min-h-screen pb-24 xl:pb-0">
        <!-- Navbar Atas -->
        <nav class="sticky top-0 z-50 flex items-center justify-between gap-4 px-4 sm:px-6 py-3 bg-white border-b" style="border-color:var(--ta-border)">
            <div class="flex items-center gap-3">
                <button type="button" class="xl:hidden btn btn-ghost btn-square btn-sm" onclick="document.getElementById('sidenav-main').classList.toggle('hidden')">
                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/></svg>
                </button>
                <div>
                    <h6 class="font-bold text-base leading-tight">Pengurusan Tempahan Kenderaan</h6>
                    <p class="text-xs leading-tight" style="color:var(--ta-muted)">
                        <a href="dashboard.php" class="hover:underline opacity-70">Dashboard</a>
                        <span class="mx-1 opacity-40">/</span>
                        <span class="font-semibold text-primary">Tempahan</span>
                    </p>
                </div>
            </div>

            <div class="flex items-center gap-2">
                <button type="button" id="theme-toggle" class="btn btn-ghost btn-circle text-slate-500 hover:bg-slate-100 border-0 h-10 w-10 min-h-0">
                    <span id="theme-toggle-icon"></span>
                </button>

                <div class="dropdown dropdown-end">
                    <div tabindex="0" role="button" class="btn btn-ghost rounded-full pl-1 pr-2 py-1 flex items-center gap-2 h-auto min-h-0">
                        <div class="avatar placeholder">
                            <div class="rounded-full w-8 h-8 flex items-center justify-center font-bold text-xs uppercase text-white" style="background:var(--ta-brand)">
                                <?= htmlspecialchars(substr($fullname, 0, 1)) ?>
                            </div>
                        </div>
                        <span class="text-sm font-semibold hidden sm:inline-block"><?= htmlspecialchars($fullname) ?></span>
                    </div>
                    <ul tabindex="0" class="dropdown-content z-[99] menu p-3 shadow-lg ta-card rounded-2xl w-64 mt-2 border" style="border-color: var(--ta-border);">
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

            <!-- Mesej Maklum Balas (Flash) -->
            <?php if ($flash): ?>
                <div class="mb-4 p-4 rounded-xl text-sm font-medium border <?= $flash['type'] === 'success' ? 'bg-success/15 text-success border-success/30' : 'bg-error/15 text-error border-error/30' ?>">
                    <?= htmlspecialchars($flash['msg']) ?>
                </div>
            <?php endif; ?>

            <?php if ($actionErr): ?>
                <div class="mb-4 p-4 rounded-xl bg-error/15 text-error text-sm font-medium border border-error/30">
                    <?= htmlspecialchars($actionErr) ?>
                </div>
            <?php endif; ?>

            <!-- Bar Alat Utama: Carian, Penapis & Navigasi Permohonan Baharu -->
            <div class="flex flex-col sm:flex-row items-stretch sm:items-center justify-between gap-3 mb-6">
                <form action="bookings.php" method="GET" class="flex flex-wrap sm:flex-nowrap items-center gap-2 flex-1 max-w-2xl">
                    <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="Cari No. Tempahan, Pemohon, Lokasi..." class="input input-sm input-bordered w-full sm:w-64 text-xs" />
                    
                    <select name="status" class="select select-sm select-bordered text-xs" onchange="this.form.submit()">
                        <option value="">Semua Status</option>
                        <option value="Pending" <?= $filter === 'Pending' ? 'selected' : '' ?>>Menunggu (Pending)</option>
                        <option value="Approved" <?= $filter === 'Approved' ? 'selected' : '' ?>>Diluluskan (Approved)</option>
                        <option value="Rejected" <?= $filter === 'Rejected' ? 'selected' : '' ?>>Ditolak (Rejected)</option>
                        <option value="Cancelled" <?= $filter === 'Cancelled' ? 'selected' : '' ?>>Dibatalkan (Cancelled)</option>
                        <option value="Completed" <?= $filter === 'Completed' ? 'selected' : '' ?>>Selesai (Completed)</option>
                    </select>

                    <button type="submit" class="btn btn-sm btn-ghost">Cari</button>
                    <?php if ($search !== '' || $filter !== ''): ?>
                        <a href="bookings.php" class="btn btn-sm btn-link text-xs">Set Semula</a>
                    <?php endif; ?>
                </form>

                <a href="book_vehicle.php" class="btn btn-sm text-white border-0 gap-1.5" style="background:var(--ta-brand)">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" /></svg>
                    Tempah Kenderaan
                </a>
            </div>

            <!-- Jadual Senarai Tempahan -->
            <div class="ta-card rounded-2xl border overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="table w-full text-xs">
                        <thead class="bg-base-200/50">
                            <tr>
                                <th>No. Tempahan</th>
                                <th>Pemohon</th>
                                <th>Maklumat Perjalanan</th>
                                <th>Kenderaan & Pemandu</th>
                                <th>Status</th>
                                <th class="text-right">Tindakan</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($bookings)): ?>
                                <tr>
                                    <td colspan="6" class="text-center py-8 text-slate-400">Tiada rekod tempahan dijumpai.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($bookings as $b): ?>
                                    <?php
                                        $sBadge = match($b['status']) {
                                            'Approved'  => 'badge-soft-success',
                                            'Completed' => 'badge-soft-success',
                                            'Pending'   => 'badge-soft-warning',
                                            'Rejected'  => 'badge-soft-error',
                                            'Cancelled' => 'badge-soft-info',
                                            default     => 'badge-soft-info',
                                        };
                                    ?>
                                    <tr class="hover:bg-slate-50/50">
                                        <td class="font-bold text-primary"><?= htmlspecialchars($b['booking_no']) ?></td>
                                        <td>
                                            <div class="font-semibold"><?= htmlspecialchars($b['applicant_name']) ?></div>
                                            <div class="text-[10px] text-slate-400"><?= htmlspecialchars($b['applicant_email']) ?></div>
                                        </td>
                                        <td>
                                            <div class="font-medium"><?= htmlspecialchars($b['origin'] ?? 'N/A') ?> &rarr; <?= htmlspecialchars($b['destination'] ?? 'N/A') ?></div>
                                            <div class="text-[10px] text-slate-400">
                                                <?= date('d/m/Y h:i A', strtotime($b['depart_datetime'])) ?>
                                                <?= $b['trip_type'] !== 'One Way' ? ' (Pergi-Balik)' : '' ?>
                                            </div>
                                        </td>
                                        <td>
                                            <?php if ($b['plate_no']): ?>
                                                <div class="font-medium"><?= htmlspecialchars($b['plate_no']) ?> - <?= htmlspecialchars($b['vehicle_model'] ?? '') ?></div>
                                                <div class="text-[10px] text-slate-400">Pemandu: <?= htmlspecialchars($b['driver_name'] ?: 'Belum Ditetapkan') ?></div>
                                            <?php else: ?>
                                                <span class="text-slate-400 italic">Belum Ditetapkan</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="ta-badge <?= $sBadge ?>"><?= htmlspecialchars($b['status']) ?></span>
                                        </td>
                                        <td class="text-right space-x-1">
                                            <button type="button" class="btn btn-xs btn-ghost" onclick='openViewModal(<?= json_encode($b, JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>Butiran</button>
                                            
                                            <?php if (in_array($role, ['Admin', 'SuperAdmin'], true) && $b['status'] === 'Pending'): ?>
                                                <button type="button" class="btn btn-xs btn-primary text-white" onclick='openStatusModal(<?= json_encode($b, JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>Kemaskini</button>
                                            <?php endif; ?>

                                            <?php if ($b['status'] === 'Pending' && $b['user_id'] == $currentUserId && !in_array($role, ['Admin', 'SuperAdmin'], true)): ?>
                                                <button type="button" class="btn btn-xs btn-error text-white" onclick="openCancelModal(<?= $b['booking_id'] ?>, '<?= $b['booking_no'] ?>')">Batal</button>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <footer class="pt-8 pb-2">
                <div class="text-sm leading-normal text-center text-slate-400">
                    © <?= date('Y') ?> Kembara &middot; Perbendaharaan Negeri Selangor
                </div>
            </footer>
        </div>
    </main>

    <!-- Modal Butiran Tempahan (#modal-view) -->
    <dialog id="modal-view" class="modal">
        <div class="modal-box ta-card max-w-lg p-6">
            <h3 class="font-bold text-lg mb-4">Butiran Tempahan <span id="v-booking-no" class="text-primary"></span></h3>
            <div class="space-y-3 text-xs">
                <div><strong class="block text-slate-400">Pemohon:</strong> <span id="v-applicant"></span></div>
                <div><strong class="block text-slate-400">Tujuan:</strong> <span id="v-purpose"></span></div>
                <div class="grid grid-cols-2 gap-2">
                    <div><strong class="block text-slate-400">Asal:</strong> <span id="v-origin"></span></div>
                    <div><strong class="block text-slate-400">Destinasi:</strong> <span id="v-dest"></span></div>
                </div>
                <div class="grid grid-cols-2 gap-2">
                    <div><strong class="block text-slate-400">Tarikh Berangkat:</strong> <span id="v-depart"></span></div>
                    <div><strong class="block text-slate-400">Tarikh Pulang:</strong> <span id="v-return"></span></div>
                </div>
                <div><strong class="block text-slate-400">Kenderaan & Pemandu:</strong> <span id="v-assign"></span></div>
            </div>
            <div class="modal-action mt-6">
                <form method="dialog"><button class="btn btn-sm">Tutup</button></form>
            </div>
        </div>
    </dialog>

    <!-- Modal Kemaskini Status Kelulusan (#modal-status) -->
    <dialog id="modal-status" class="modal">
        <div class="modal-box ta-card max-w-md p-6">
            <h3 class="font-bold text-lg mb-2">Tindakan Kelulusan Tempahan</h3>
            <p class="text-xs text-slate-400 mb-4">Sila pilih untuk meluluskan atau menolak permohonan <span id="st-booking-no" class="font-bold text-primary"></span>.</p>
            
            <form action="bookings.php" method="POST" class="space-y-4">
                <input type="hidden" name="booking_id" id="st-booking-id" />
                <input type="hidden" name="action_type" id="st-action-type" value="approve" />

                <div>
                    <label class="text-xs font-semibold block mb-1">Catatan / Ulasan Pentadbir</label>
                    <textarea name="remarks" class="textarea textarea-bordered w-full text-xs" rows="3" placeholder="Sebab kelulusan atau penolakan..."></textarea>
                </div>

                <div class="flex items-center justify-end gap-2 pt-2 border-t" style="border-color:var(--ta-border)">
                    <button type="button" class="btn btn-sm" onclick="document.getElementById('modal-status').close()">Batal</button>
                    <button type="submit" onclick="document.getElementById('st-action-type').value='reject'" class="btn btn-sm btn-error text-white">Tolak Permohonan</button>
                    <button type="submit" onclick="document.getElementById('st-action-type').value='approve'" class="btn btn-sm btn-success text-white">Luluskan Permohonan</button>
                </div>
            </form>
        </div>
    </dialog>

    <!-- Modal Pengesahan Pembatalan (User) -->
    <dialog id="modal-cancel" class="modal">
        <div class="modal-box ta-card max-w-md p-6">
            <h3 class="font-bold text-lg text-error mb-2">Sahkan Pembatalan</h3>
            <p class="text-xs text-slate-400 mb-4">Adakah anda pasti mahu membatalkan permohonan tempahan <span id="c-booking-no" class="font-bold"></span>?</p>
            
            <form action="bookings.php" method="POST" class="space-y-4">
                <input type="hidden" name="booking_id" id="c-booking-id" />
                <input type="hidden" name="action_type" value="cancel" />

                <div>
                    <label class="text-xs font-semibold block mb-1">Sebab Pembatalan</label>
                    <textarea name="remarks" required class="textarea textarea-bordered w-full text-xs" rows="2" placeholder="Nyatakan sebab pembatalan..."></textarea>
                </div>

                <div class="flex items-center justify-end gap-2 pt-2 border-t" style="border-color:var(--ta-border)">
                    <button type="button" class="btn btn-sm" onclick="document.getElementById('modal-cancel').close()">Kembali</button>
                    <button type="submit" class="btn btn-sm btn-error text-white">Batal Tempahan</button>
                </div>
            </form>
        </div>
    </dialog>

    <script>
        function openViewModal(b) {
            document.getElementById('v-booking-no').innerText = '#' + b.booking_no;
            document.getElementById('v-applicant').innerText = b.applicant_name + ' (' + b.applicant_email + ')';
            document.getElementById('v-purpose').innerText = b.purpose || '-';
            document.getElementById('v-origin').innerText = b.origin || '-';
            document.getElementById('v-dest').innerText = b.destination || '-';
            document.getElementById('v-depart').innerText = b.depart_datetime;
            document.getElementById('v-return').innerText = b.return_datetime || '-';
            
            const assignText = b.plate_no 
                ? (b.plate_no + ' (' + (b.vehicle_model || '') + ') | Pemandu: ' + (b.driver_name || 'Tiada'))
                : 'Belum Diperuntukkan';
            document.getElementById('v-assign').innerText = assignText;

            document.getElementById('modal-view').showModal();
        }

        function openStatusModal(b) {
            document.getElementById('st-booking-id').value = b.booking_id;
            document.getElementById('st-booking-no').innerText = '#' + b.booking_no;
            document.getElementById('modal-status').showModal();
        }

        function openCancelModal(id, no) {
            document.getElementById('c-booking-id').value = id;
            document.getElementById('c-booking-no').innerText = '#' + no;
            document.getElementById('modal-cancel').showModal();
        }
    </script>

    <!-- Skrip Tema Terang/Gelap -->
    <script>
        const themeToggleBtn = document.getElementById('theme-toggle');
        const themeToggleIcon = document.getElementById('theme-toggle-icon');

        const LIGHT_THEME = 'light';
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
</body>
</html>