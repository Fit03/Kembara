<?php
// activity_log.php — Log Aktiviti Sistem (SuperAdmin & Admin sahaja)
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

// Halaman ini khusus untuk SuperAdmin & Admin sahaja
if (!$canManage) {
    header("Location: dashboard.php");
    exit();
}

$email = $_SESSION['email'] ?? null;
if (!$email) {
    $stmt = $pdo->prepare("SELECT email FROM users WHERE user_id = ?");
    $stmt->execute([$currentUserId]);
    $email = $stmt->fetchColumn() ?: 'tiada-emel@selangor.gov.my';
}

$avatarStmt = $pdo->prepare("SELECT profile_picture FROM users WHERE user_id = ?");
$avatarStmt->execute([$currentUserId]);
$profilePicture = $avatarStmt->fetchColumn();
$hasPhoto = $profilePicture && is_file(__DIR__ . '/' . $profilePicture);

$badgeColor = match($role) {
  'SuperAdmin' => 'badge badge-error',
  'Admin'      => 'badge badge-warning',
  default      => 'badge badge-info',
};

$pendingApprovals = $pdo->query(
    "SELECT COUNT(*) FROM vehicle_bookings WHERE status = 'Pending'"
)->fetchColumn();

$flash = null;
if (isset($_SESSION['flash'])) {
    $flash = $_SESSION['flash'];
    unset($_SESSION['flash']);
}

/* ==========================================================
   Data Halaman — gabungan activity_log & booking_history
   sebagai satu suapan aktiviti sistem yang bersatu
   ========================================================== */
$search  = trim($_GET['q'] ?? '');
$module  = $_GET['module'] ?? '';
$range   = $_GET['range'] ?? 'all'; // today, 7d, 30d, all
$perPage = 15;
$page    = max(1, (int)($_GET['page'] ?? 1));

$validModules = ['Log Masuk', 'Log Keluar', 'Pengguna', 'Kenderaan', 'Pemandu', 'Tempahan'];
if (!in_array($module, $validModules, true)) {
    $module = '';
}
if (!in_array($range, ['today', '7d', '30d', 'all'], true)) {
    $range = 'all';
}

$unionSql = "
    SELECT al.module AS module, al.action AS action, al.description AS description,
           COALESCE(u1.fullname, 'Pengguna Dipadam') AS actor_name,
           u1.profile_picture AS actor_photo, al.role_at_time AS role_at_time,
           al.user_agent AS user_agent, al.created_at AS created_at
    FROM activity_log al
    LEFT JOIN users u1 ON u1.user_id = al.user_id

    UNION ALL

    SELECT 'Tempahan' AS module, bh.action AS action, bh.remarks AS description,
           COALESCE(u2.fullname, 'Pengguna Dipadam') AS actor_name,
           u2.profile_picture AS actor_photo, NULL AS role_at_time,
           NULL AS user_agent, bh.action_datetime AS created_at
    FROM booking_history bh
    LEFT JOIN users u2 ON u2.user_id = bh.action_by
";

$where  = [];
$params = [];

if ($search !== '') {
    $like = "%{$search}%";
    $where[] = "(actor_name LIKE :search1 OR description LIKE :search2 OR action LIKE :search3)";
    $params[':search1'] = $like;
    $params[':search2'] = $like;
    $params[':search3'] = $like;
}
if ($module !== '') {
    $where[] = "module = :module";
    $params[':module'] = $module;
}
if ($range === 'today') {
    $where[] = "created_at >= CURDATE()";
} elseif ($range === '7d') {
    $where[] = "created_at >= (NOW() - INTERVAL 7 DAY)";
} elseif ($range === '30d') {
    $where[] = "created_at >= (NOW() - INTERVAL 30 DAY)";
}

$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

function buildLogPageUrl(int $p, string $search, string $module, string $range): string {
    $q = ['page' => $p];
    if ($search !== '') $q['q'] = $search;
    if ($module !== '') $q['module'] = $module;
    if ($range !== 'all') $q['range'] = $range;
    return 'activity_log.php?' . http_build_query($q);
}

$countStmt = $pdo->prepare("SELECT COUNT(*) FROM ({$unionSql}) AS activity {$whereSql}");
foreach ($params as $k => $v) {
    $countStmt->bindValue($k, $v);
}
$countStmt->execute();
$totalRows  = (int)$countStmt->fetchColumn();
$totalPages = max(1, (int)ceil($totalRows / $perPage));
if ($page > $totalPages) {
    $page = $totalPages;
}
$offset = ($page - 1) * $perPage;

$listStmt = $pdo->prepare(
    "SELECT * FROM ({$unionSql}) AS activity {$whereSql} ORDER BY created_at DESC LIMIT :limit OFFSET :offset"
);
foreach ($params as $k => $v) {
    $listStmt->bindValue($k, $v);
}
$listStmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$listStmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$listStmt->execute();
$activities = $listStmt->fetchAll(PDO::FETCH_ASSOC);

/* -- Statistik ringkas (tidak terjejas oleh penapis semasa) ---------- */
$todayCount = (int)$pdo->query("SELECT COUNT(*) FROM ({$unionSql}) AS activity WHERE created_at >= CURDATE()")->fetchColumn();
$weekCount  = (int)$pdo->query("SELECT COUNT(*) FROM ({$unionSql}) AS activity WHERE created_at >= (NOW() - INTERVAL 7 DAY)")->fetchColumn();
$totalCount = (int)$pdo->query("SELECT COUNT(*) FROM ({$unionSql}) AS activity")->fetchColumn();
$activeToday = (int)$pdo->query("SELECT COUNT(DISTINCT actor_name) FROM ({$unionSql}) AS activity WHERE created_at >= CURDATE()")->fetchColumn();

$moduleBadge = fn(string $m) => match ($m) {
    'Log Masuk'  => 'badge-soft-info',
    'Log Keluar' => 'badge-soft-neutral',
    'Pengguna'   => 'badge-soft-error',
    'Kenderaan'  => 'badge-soft-warning',
    'Pemandu'    => 'badge-soft-success',
    'Tempahan'   => 'badge-soft-info',
    default      => 'badge-soft-neutral',
};

$actionBadge = fn(string $a) => match (true) {
    in_array($a, ['Ditolak', 'Padam', 'Dibatalkan'], true) => 'badge-soft-error',
    in_array($a, ['Diluluskan', 'Tambah', 'Dicipta'], true) => 'badge-soft-success',
    $a === 'Kemaskini' => 'badge-soft-warning',
    default => 'badge-soft-info',
};

$moduleIcon = function (string $m): string {
    return match ($m) {
        'Log Masuk'  => '<path stroke-linecap="round" stroke-linejoin="round" d="M15.75 9V5.25A2.25 2.25 0 0013.5 3h-6a2.25 2.25 0 00-2.25 2.25v13.5A2.25 2.25 0 007.5 21h6a2.25 2.25 0 002.25-2.25V15M12 9l-3 3m0 0l3 3m-3-3h12.75" />',
        'Log Keluar' => '<path stroke-linecap="round" stroke-linejoin="round" d="M8.25 9V5.25A2.25 2.25 0 0110.5 3h6a2.25 2.25 0 012.25 2.25v13.5A2.25 2.25 0 0116.5 21h-6a2.25 2.25 0 01-2.25-2.25V15M12 9l3 3m0 0l-3 3m3-3H2.25" />',
        'Pengguna'   => '<path stroke-linecap="round" stroke-linejoin="round" d="M15 19.128a9.38 9.38 0 002.625.372 9.337 9.337 0 004.121-.952 4.125 4.125 0 00-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 018.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0111.964-3.07M12 6.375a3.375 3.375 0 11-6.75 0 3.375 3.375 0 016.75 0zm8.25 2.25a2.625 2.625 0 11-5.25 0 2.625 2.625 0 015.25 0z" />',
        'Kenderaan'  => '<path stroke-linecap="round" stroke-linejoin="round" d="M8.25 18.75a1.5 1.5 0 01-3 0m3 0a1.5 1.5 0 00-3 0m3 0h6m-9 0H3.375a1.125 1.125 0 01-1.125-1.125V14.25m17.25 4.5a1.5 1.5 0 01-3 0m3 0a1.5 1.5 0 00-3 0m3 0h1.125c.621 0 1.129-.504 1.09-1.124a17.902 17.902 0 00-3.213-9.193 2.056 2.056 0 00-1.58-.86H14.25M16.5 18.75h-2.25m0-11.177v-.958c0-.568-.422-1.048-.987-1.106a48.554 48.554 0 00-10.026 0 1.106 1.106 0 00-.987 1.106v7.635m12-6.677v6.677m0 0h-12" />',
        'Pemandu'    => '<path stroke-linecap="round" stroke-linejoin="round" d="M2.25 8.25h19.5M2.25 9h19.5m-16.5 5.25h6m-6 2.25h3m-3.75 3h15a2.25 2.25 0 002.25-2.25V6.75A2.25 2.25 0 0019.5 4.5h-15a2.25 2.25 0 00-2.25 2.25v10.5A2.25 2.25 0 004.5 19.5z" />',
        'Tempahan'   => '<path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 012.25-2.25h13.5A2.25 2.25 0 0121 7.5v11.25m-18 0A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75m-18 0v-7.5A2.25 2.25 0 015.25 9h13.5A2.25 2.25 0 0121 11.25v7.5m-13.5-6h.008v.008h-.008v-.008zm0 3h.008v.008h-.008v-.008zm3-3h.008v.008h-.008v-.008zm0 3h.008v.008h-.008v-.008z" />',
        default      => '<path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />',
    };
};

/**
 * Terjemah user_agent mentah kepada label pelayar/peranti ringkas
 * untuk dipaparkan (bukan analisis penuh, sekadar bacaan mudah).
 */
$deviceLabel = function (?string $ua): ?string {
    if (!$ua) return null;
    $browser = match (true) {
        str_contains($ua, 'Edg/')     => 'Edge',
        str_contains($ua, 'OPR/')     => 'Opera',
        str_contains($ua, 'Chrome/')  => 'Chrome',
        str_contains($ua, 'Firefox/') => 'Firefox',
        str_contains($ua, 'Safari/')  => 'Safari',
        default                       => 'Pelayar Lain',
    };
    $os = match (true) {
        str_contains($ua, 'Windows')      => 'Windows',
        str_contains($ua, 'Android')      => 'Android',
        str_contains($ua, 'iPhone'),
        str_contains($ua, 'iPad')         => 'iOS',
        str_contains($ua, 'Mac OS')       => 'macOS',
        str_contains($ua, 'Linux')        => 'Linux',
        default                           => null,
    };
    return $os ? "{$browser} · {$os}" : $browser;
};

$roleBadge = fn(?string $r) => match ($r) {
    'SuperAdmin' => 'badge-soft-error',
    'Admin'      => 'badge-soft-warning',
    'User'       => 'badge-soft-info',
    default      => null,
};
?>
<!DOCTYPE html>
<html lang="ms" data-theme="garden">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0, shrink-to-fit=no" />
    <title>Kembara - Log Aktiviti</title>

    <script>
        (function () {
            try {
                const savedTheme = localStorage.getItem('theme');
                const systemPrefersDark = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches;
                const preferredTheme = savedTheme || (systemPrefersDark ? 'dracula' : 'garden');
                document.documentElement.setAttribute('data-theme', preferredTheme);
            } catch (e) {}
        })();
    </script>

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

        <?php if (in_array($role, ['SuperAdmin', 'Admin'], true)): ?>
        <p class="mt-6 px-3 mb-2 text-[0.65rem] font-semibold uppercase tracking-widest text-slate-400">Log</p>
        <ul class="flex flex-col gap-1">
          <li>
            <a class="ta-nav-link active" href="activity_log.php">
              <svg class="ta-nav-icon" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
              <span>Log Aktiviti</span>
            </a>
          </li>
        </ul>
        <?php endif; ?>
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
          <a href="vehicles.php" class="flex flex-col items-center gap-1 flex-1 py-1" style="color:var(--ta-muted)">
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
                <h6 class="font-bold text-base leading-tight">Log Aktiviti</h6>
                <p class="text-xs leading-tight" style="color:var(--ta-muted)">
                    <span class="opacity-70">Halaman</span>
                    <span class="mx-1 opacity-40">/</span>
                    <span>Log Aktiviti</span>
                </p>
            </div>
        </div>

        <form action="activity_log.php" method="GET" class="hidden md:flex items-center gap-2 rounded-lg px-3 py-2 flex-1 max-w-sm" style="background:var(--ta-canvas); border:1px solid var(--ta-border)">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 shrink-0" style="color:var(--ta-muted)" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607z" /></svg>
            <input type="text" name="q" value="<?= htmlspecialchars($search) ?>" placeholder="Cari mengikut pengguna, tindakan atau butiran..." class="bg-transparent border-0 outline-none text-sm w-full placeholder:text-slate-400" />
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
          <div class="card p-5">
            <div class="ta-icon-box mb-4">
              <svg xmlns="http://www.w3.org/2000/svg" class="h-5.5 w-5.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 6.75h12M8.25 12h12m-12 5.25h12M3.75 6.75h.007v.008H3.75V6.75zm.375 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zM3.75 12h.007v.008H3.75V12zm.375 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zm-.375 5.25h.007v.008H3.75v-.008zm.375 0a.375.375 0 11-.75 0 .375.375 0 01.75 0z" /></svg>
            </div>
            <p class="text-sm mb-1" style="color:var(--ta-muted)">Jumlah Aktiviti</p>
            <h5 class="text-2xl font-bold"><?= number_format($totalCount) ?></h5>
          </div>

          <div class="card p-5">
            <div class="ta-icon-box mb-4">
              <svg xmlns="http://www.w3.org/2000/svg" class="h-5.5 w-5.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
            </div>
            <p class="text-sm mb-1" style="color:var(--ta-muted)">Aktiviti Hari Ini</p>
            <h5 class="text-2xl font-bold"><?= number_format($todayCount) ?></h5>
          </div>

          <div class="card p-5">
            <div class="ta-icon-box mb-4">
              <svg xmlns="http://www.w3.org/2000/svg" class="h-5.5 w-5.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6"><path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 012.25-2.25h13.5A2.25 2.25 0 0121 7.5v11.25m-18 0A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75m-18 0v-7.5A2.25 2.25 0 015.25 9h13.5A2.25 2.25 0 0121 11.25v7.5m-13.5-6h.008v.008h-.008v-.008zm0 3h.008v.008h-.008v-.008zm3-3h.008v.008h-.008v-.008zm0 3h.008v.008h-.008v-.008z" /></svg>
            </div>
            <p class="text-sm mb-1" style="color:var(--ta-muted)">7 Hari Lepas</p>
            <h5 class="text-2xl font-bold"><?= number_format($weekCount) ?></h5>
          </div>

          <div class="card p-5">
            <div class="ta-icon-box mb-4">
              <svg xmlns="http://www.w3.org/2000/svg" class="h-5.5 w-5.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19.128a9.38 9.38 0 002.625.372 9.337 9.337 0 004.121-.952 4.125 4.125 0 00-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 018.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0111.964-3.07M12 6.375a3.375 3.375 0 11-6.75 0 3.375 3.375 0 016.75 0zm8.25 2.25a2.625 2.625 0 11-5.25 0 2.625 2.625 0 015.25 0z" /></svg>
            </div>
            <p class="text-sm mb-1" style="color:var(--ta-muted)">Pengguna Aktif Hari Ini</p>
            <h5 class="text-2xl font-bold"><?= number_format($activeToday) ?></h5>
          </div>
        </div>

        <!-- Baris 2: Penapis -->
        <div class="card p-5 mt-5">
          <form action="activity_log.php" method="GET" class="flex flex-wrap items-end gap-3">
            <div class="flex-1 min-w-[14rem]">
              <label class="text-xs font-medium block mb-1" style="color:var(--ta-muted)">Carian</label>
              <input type="text" name="q" value="<?= htmlspecialchars($search) ?>" placeholder="Cari pengguna, tindakan atau butiran..."
                     class="input input-bordered w-full input-sm" />
            </div>
            <div class="w-full sm:w-48">
              <label class="text-xs font-medium block mb-1" style="color:var(--ta-muted)">Modul</label>
              <select name="module" class="select select-bordered w-full select-sm">
                <option value="">Semua Modul</option>
                <?php foreach ($validModules as $m): ?>
                  <option value="<?= htmlspecialchars($m) ?>" <?= $module === $m ? 'selected' : '' ?>><?= htmlspecialchars($m) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="w-full sm:w-44">
              <label class="text-xs font-medium block mb-1" style="color:var(--ta-muted)">Tempoh</label>
              <select name="range" class="select select-bordered w-full select-sm">
                <option value="all" <?= $range === 'all' ? 'selected' : '' ?>>Sepanjang Masa</option>
                <option value="today" <?= $range === 'today' ? 'selected' : '' ?>>Hari Ini</option>
                <option value="7d" <?= $range === '7d' ? 'selected' : '' ?>>7 Hari Lepas</option>
                <option value="30d" <?= $range === '30d' ? 'selected' : '' ?>>30 Hari Lepas</option>
              </select>
            </div>
            <div class="flex items-center gap-2">
              <button type="submit" class="btn btn-sm text-white border-0" style="background:var(--ta-brand)">Tapis</button>
              <?php if ($search !== '' || $module !== '' || $range !== 'all'): ?>
                <a href="activity_log.php" class="btn btn-sm btn-ghost">Set Semula</a>
              <?php endif; ?>
            </div>
          </form>
        </div>

        <!-- Baris 3: Suapan Aktiviti -->
        <div class="card p-5 mt-5">
          <div class="flex items-center justify-between gap-3 mb-4 flex-wrap">
            <div>
              <h6 class="font-semibold">Suapan Aktiviti</h6>
              <p class="mb-0 text-sm" style="color:var(--ta-muted)">Rekod aktiviti merentas seluruh sistem Kembara</p>
            </div>
          </div>

          <?php if (empty($activities)): ?>
            <p class="text-sm text-center py-6" style="color:var(--ta-muted)">Tiada aktiviti dijumpai untuk penapis semasa.</p>
          <?php else: ?>
            <div>
              <?php foreach ($activities as $a): ?>
                <div class="ta-timeline-item">
                  <span class="ta-timeline-dot">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.2"><?= $moduleIcon($a['module']) ?></svg>
                  </span>
                  <div class="flex items-start justify-between gap-3 flex-wrap">
                    <div class="min-w-0">
                      <div class="flex items-center gap-2 flex-wrap">
                        <span class="ta-badge <?= $moduleBadge($a['module']) ?>"><?= htmlspecialchars($a['module']) ?></span>
                        <span class="ta-badge <?= $actionBadge($a['action']) ?>"><?= htmlspecialchars($a['action']) ?></span>
                        <?php if (!empty($a['role_at_time']) && $roleBadge($a['role_at_time'])): ?>
                          <span class="ta-badge <?= $roleBadge($a['role_at_time']) ?>"><?= htmlspecialchars($a['role_at_time']) ?></span>
                        <?php endif; ?>
                      </div>
                      <h6 class="mb-0 mt-1.5 text-sm font-semibold leading-normal">
                        <?= htmlspecialchars($a['actor_name']) ?>
                      </h6>
                      <?php if (!empty($a['description'])): ?>
                        <p class="mt-0.5 mb-0 text-xs leading-tight" style="color:var(--ta-muted)"><?= htmlspecialchars($a['description']) ?></p>
                      <?php endif; ?>
                      <?php $dev = $deviceLabel($a['user_agent'] ?? null); if ($dev): ?>
                        <p class="mt-1 mb-0 text-[11px] flex items-center gap-1" style="color:var(--ta-muted)">
                          <svg xmlns="http://www.w3.org/2000/svg" class="h-3 w-3 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 17.25v1.007a3 3 0 01-.879 2.122L7.5 21h9l-.621-.621A3 3 0 0115 18.257V17.25m6-12V15a2.25 2.25 0 01-2.25 2.25H5.25A2.25 2.25 0 013 15V5.25m18 0A2.25 2.25 0 0018.75 3H5.25A2.25 2.25 0 003 5.25m18 0V12a2.25 2.25 0 01-2.25 2.25H5.25A2.25 2.25 0 013 12V5.25" /></svg>
                          <?= htmlspecialchars($dev) ?>
                        </p>
                      <?php endif; ?>
                    </div>
                    <span class="text-xs shrink-0" style="color:var(--ta-muted)"><?= htmlspecialchars(date('d M Y, H:i', strtotime($a['created_at']))) ?></span>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>

          <?php if ($totalRows > 0): ?>
          <div class="flex items-center justify-between gap-3 flex-wrap mt-4 pt-4 border-t" style="border-color:var(--ta-border)">
            <p class="text-xs" style="color:var(--ta-muted)">
              Menunjukkan <?= $offset + 1 ?>–<?= min($offset + $perPage, $totalRows) ?> daripada <?= $totalRows ?> aktiviti
            </p>
            <div class="join">
              <a href="<?= $page > 1 ? htmlspecialchars(buildLogPageUrl($page - 1, $search, $module, $range)) : '#' ?>"
                 class="join-item btn btn-sm <?= $page <= 1 ? 'btn-disabled opacity-40' : '' ?>">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.2"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5L8.25 12l7.5-7.5" /></svg>
              </a>
              <span class="join-item btn btn-sm btn-disabled !bg-transparent !border-none font-semibold" style="color:var(--ta-ink)">
                <?= $page ?> / <?= $totalPages ?>
              </span>
              <a href="<?= $page < $totalPages ? htmlspecialchars(buildLogPageUrl($page + 1, $search, $module, $range)) : '#' ?>"
                 class="join-item btn btn-sm <?= $page >= $totalPages ? 'btn-disabled opacity-40' : '' ?>">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.2"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5" /></svg>
              </a>
            </div>
          </div>
          <?php endif; ?>
        </div>

        <footer class="pt-6 pb-2">
          <div class="text-sm leading-normal text-center text-slate-400">
            &copy; <?= date('Y') ?> Kembara &middot; Perbendaharaan Negeri Selangor
          </div>
        </footer>
      </div>
    </main>