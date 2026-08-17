<?php
// book-vehicle.php — Borang Tempahan Kenderaan Baharu (dengan pemilih lokasi peta)
session_start();
require_once __DIR__ . '/config/database.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$fullname      = $_SESSION['fullname'];
$role          = $_SESSION['role'];
$currentUserId = (int)$_SESSION['user_id'];

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

$pendingApprovals = $pdo->query("SELECT COUNT(*) FROM vehicle_bookings WHERE status = 'Pending'")->fetchColumn();
?>
<!DOCTYPE html>
<html lang="ms" garden>
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0, shrink-to-fit=no" />
    <title>Kembara - Tempah Kenderaan</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700&display=swap" rel="stylesheet" />

    <link href="https://cdn.jsdelivr.net/npm/daisyui@5" rel="stylesheet" type="text/css" />
    <link href="https://cdn.jsdelivr.net/npm/daisyui@5/themes.css" rel="stylesheet" type="text/css" />
    <script src="https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4"></script>

    <!-- Leaflet (peta sumber terbuka, tiada kunci API diperlukan) -->
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>

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
        .badge-soft-warning { background: color-mix(in oklch, var(--color-warning) 18%, var(--color-base-100)); color: var(--color-warning); }
        .badge-soft-error   { background: color-mix(in oklch, var(--color-error) 18%, var(--color-base-100));   color: var(--color-error); }
        .badge-soft-info    { background: color-mix(in oklch, var(--color-info) 18%, var(--color-base-100));    color: var(--color-info); }

        .ta-tab {
            display: inline-flex;
            align-items: center;
            white-space: nowrap;
            padding: 0.4rem 0.9rem;
            border-radius: 9999px;
            font-size: 0.8rem;
            font-weight: 600;
            color: var(--ta-muted);
            border: 1px solid var(--ta-border);
            transition: all .15s ease;
            cursor: pointer;
            background: var(--ta-surface);
        }
        .ta-tab:hover { color: var(--ta-ink); background: var(--ta-canvas); }
        .ta-tab.active { background: var(--ta-brand); color: #fff; border-color: var(--ta-brand); }

        #map { z-index: 0; }
        .leaflet-control-attribution { font-size: 10px !important; }

        ::-webkit-scrollbar { width: 8px; height: 8px; }
        ::-webkit-scrollbar-thumb { background: var(--ta-border); border-radius: 999px; }
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
          <a href="bookings.php" class="flex flex-col items-center gap-1 flex-1 py-1" style="color:var(--ta-brand)">
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
                <h6 class="font-bold text-base leading-tight">Tempah Kenderaan</h6>
                <p class="text-xs leading-tight" style="color:var(--ta-muted)">
                    <a href="dashboard.php" class="opacity-70 hover:opacity-100 hover:underline">Halaman</a>
                    <span class="mx-1 opacity-40">/</span>
                    <a href="bookings.php" class="hover:underline" style="color:var(--ta-ink)">Tempahan</a>
                    <span class="mx-1 opacity-40">/</span>
                    <span>Tempah Kenderaan</span>
                </p>
            </div>
        </div>

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

      <div class="w-full px-4 sm:px-6 py-6 mx-auto max-w-3xl">

        <div class="card p-5 sm:p-7">
          <h6 class="font-semibold text-lg mb-1">Maklumat Perjalanan</h6>
          <p class="text-sm mb-5" style="color:var(--ta-muted)">Lengkapkan butiran tempahan kenderaan anda. Pemandu &amp; kenderaan akan ditugaskan oleh pentadbir semasa kelulusan.</p>

          <form action="bookings.php" method="POST" class="flex flex-col gap-5" id="booking-form">
            <input type="hidden" name="action" value="add_booking" />

            <!-- Pemilih Lokasi Peta -->
            <div>
              <div class="flex items-center justify-between flex-wrap gap-2 mb-3">
                <label class="text-sm font-semibold">Lokasi Perjalanan</label>
                <div class="flex items-center gap-2 flex-wrap">
                  <button type="button" id="mode-origin-btn" class="ta-tab active" onclick="setPinMode('origin')">
                    <span class="inline-block w-2 h-2 rounded-full mr-1.5" style="background:#16a34a"></span>Asal
                  </button>
                  <button type="button" id="mode-dest-btn" class="ta-tab" onclick="setPinMode('destination')">
                    <span class="inline-block w-2 h-2 rounded-full mr-1.5" style="background:#dc2626"></span>Destinasi
                  </button>
                  <button type="button" class="ta-tab" onclick="useCurrentLocation()">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-3.5 w-3.5 inline -mt-0.5 mr-1" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15 10.5a3 3 0 11-6 0 3 3 0 016 0z" /><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 10.5c0 7.142-7.5 11.25-7.5 11.25S4.5 17.642 4.5 10.5a7.5 7.5 0 1115 0z" /></svg>
                    Lokasi Semasa
                  </button>
                </div>
              </div>

              <div id="map" class="rounded-2xl overflow-hidden border" style="height:360px; border-color:var(--ta-border)"></div>
              <p class="text-xs text-slate-400 mt-2">Klik pada peta untuk menetapkan lokasi <span id="pin-mode-label" class="font-semibold">Asal</span>. Alamat akan diisi secara automatik, dan boleh diedit jika perlu.</p>

              <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 mt-3">
                <div>
                  <label class="text-xs font-medium block mb-1">Asal (Origin)</label>
                  <input type="text" name="origin" id="origin-input" required class="input input-bordered w-full" placeholder="Klik pada peta atau taip manual" />
                </div>
                <div>
                  <label class="text-xs font-medium block mb-1">Destinasi</label>
                  <input type="text" name="destination" id="destination-input" required class="input input-bordered w-full" placeholder="Klik pada peta atau taip manual" />
                </div>
              </div>
            </div>

            <div class="border-t" style="border-color:var(--ta-border)"></div>

            <!-- Butiran Perjalanan -->
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
              <div>
                <label class="text-xs font-medium block mb-1">Tarikh &amp; Masa Berangkat</label>
                <input type="datetime-local" name="depart_datetime" required class="input input-bordered w-full" />
              </div>
              <div>
                <label class="text-xs font-medium block mb-1">Jenis Perjalanan</label>
                <select name="trip_type" id="trip-type" class="select select-bordered w-full" onchange="toggleReturnField()">
                  <option value="One Way">Sehala</option>
                  <option value="Return">Pergi Balik</option>
                  <option value="Both">Kedua-dua</option>
                </select>
              </div>
            </div>

            <div id="return-field-wrap" class="hidden">
              <label class="text-xs font-medium block mb-1">Tarikh &amp; Masa Pulang</label>
              <input type="datetime-local" name="return_datetime" class="input input-bordered w-full" />
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
              <div>
                <label class="text-xs font-medium block mb-1">Bilangan Penumpang</label>
                <input type="number" name="passenger_total" min="1" class="input input-bordered w-full" placeholder="cth. 4" />
              </div>
            </div>

            <div>
              <label class="text-xs font-medium block mb-1">Tujuan Perjalanan</label>
              <textarea name="purpose" required rows="3" class="textarea textarea-bordered w-full" placeholder="Nyatakan tujuan rasmi perjalanan"></textarea>
            </div>

            <div class="rounded-xl p-3.5 text-xs flex items-start gap-2" style="background:var(--ta-brand-50); color:var(--ta-brand)">
              <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M11.25 11.25l.041-.02a.75.75 0 011.063.852l-.708 2.836a.75.75 0 001.063.853l.041-.021M21 12a9 9 0 11-18 0 9 9 0 0118 0zm-9-3.75h.008v.008H12V8.25z" /></svg>
              <span>Pemandu dan kenderaan akan ditugaskan secara automatik oleh pentadbir semasa proses kelulusan tempahan ini.</span>
            </div>

            <div class="flex items-center justify-end gap-2 pt-2">
              <a href="bookings.php" class="btn btn-ghost">Batal</a>
              <button type="submit" class="btn text-white border-0" style="background:var(--ta-brand)">Hantar Tempahan</button>
            </div>
          </form>
        </div>

        <footer class="pt-6 pb-2">
          <div class="text-sm leading-normal text-center text-slate-400">
            © <?= date('Y') ?> Kembara &middot; Perbendaharaan Negeri Selangor
          </div>
        </footer>
      </div>
    </main>

    <script>
        let map, originMarker, destMarker, routeLine;
        let pinMode = 'origin';

        function setPinMode(mode) {
            pinMode = mode;
            document.getElementById('mode-origin-btn').classList.toggle('active', mode === 'origin');
            document.getElementById('mode-dest-btn').classList.toggle('active', mode === 'destination');
            document.getElementById('pin-mode-label').textContent = mode === 'origin' ? 'Asal' : 'Destinasi';
        }

        function makeDot(color) {
            return L.divIcon({
                className: '',
                html: `<div style="width:16px;height:16px;border-radius:50%;background:${color};border:3px solid white;box-shadow:0 2px 6px rgba(0,0,0,.35)"></div>`,
                iconSize: [16, 16],
                iconAnchor: [8, 8],
            });
        }

        function placePin(lat, lng, mode) {
            if (mode === 'origin') {
                if (originMarker) map.removeLayer(originMarker);
                originMarker = L.marker([lat, lng], { icon: makeDot('#16a34a') }).addTo(map);
                reverseGeocode(lat, lng, 'origin-input');
            } else {
                if (destMarker) map.removeLayer(destMarker);
                destMarker = L.marker([lat, lng], { icon: makeDot('#dc2626') }).addTo(map);
                reverseGeocode(lat, lng, 'destination-input');
            }
            updateRouteLine();
        }

        function updateRouteLine() {
            if (routeLine) {
                map.removeLayer(routeLine);
                routeLine = null;
            }
            if (originMarker && destMarker) {
                routeLine = L.polyline([originMarker.getLatLng(), destMarker.getLatLng()], {
                    color: '#6366f1', weight: 3, dashArray: '6,6',
                }).addTo(map);
                map.fitBounds(routeLine.getBounds(), { padding: [50, 50] });
            }
        }

        async function reverseGeocode(lat, lng, inputId) {
            const input = document.getElementById(inputId);
            const originalPlaceholder = input.placeholder;
            input.placeholder = 'Mencari alamat...';
            try {
                const res = await fetch(`https://nominatim.openstreetmap.org/reverse?format=json&lat=${lat}&lon=${lng}&zoom=18&addressdetails=1`);
                const data = await res.json();
                input.value = data && data.display_name
                    ? data.display_name.split(',').slice(0, 4).join(',').trim()
                    : `${lat.toFixed(5)}, ${lng.toFixed(5)}`;
            } catch (e) {
                input.value = `${lat.toFixed(5)}, ${lng.toFixed(5)}`;
            } finally {
                input.placeholder = originalPlaceholder;
            }
        }

        function useCurrentLocation() {
            if (!navigator.geolocation) {
                alert('Pelayar anda tidak menyokong pengesanan lokasi.');
                return;
            }
            navigator.geolocation.getCurrentPosition(function (pos) {
                const { latitude, longitude } = pos.coords;
                map.setView([latitude, longitude], 15);
                setPinMode('origin');
                placePin(latitude, longitude, 'origin');
            }, function () {
                alert('Tidak dapat mengesan lokasi semasa anda. Sila benarkan akses lokasi atau tandakan secara manual pada peta.');
            });
        }

        function toggleReturnField() {
            const tripType = document.getElementById('trip-type').value;
            document.getElementById('return-field-wrap').classList.toggle('hidden', tripType === 'One Way');
        }

        function initMap() {
            // Pusat lalai: Selangor, Malaysia
            map = L.map('map').setView([3.0738, 101.5183], 11);
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
                maxZoom: 19,
            }).addTo(map);

            map.on('click', function (e) {
                placePin(e.latlng.lat, e.latlng.lng, pinMode);
            });
        }

        document.addEventListener('DOMContentLoaded', initMap);
    </script>

    <!-- Skrip Tukar Mod Tema Terang/Gelap -->
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

    <script src="./assets/js/plugins/perfect-scrollbar.min.js" async></script>
</body>
</html>