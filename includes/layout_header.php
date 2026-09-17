<?php
/**
 * layout_header.php
 * Centralized head and global navigation (Sidebar & Mobile Dock)
 */
$pageTitle = $pageTitle ?? 'Kembara';
?>
<!DOCTYPE html>
<html lang="ms" data-theme="garden">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0, shrink-to-fit=no" />
    <title>Kembara - <?= htmlspecialchars($pageTitle) ?></title>
    <link rel="icon" type="image/png" href="assets/img/favicon.png" />

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

    <!-- Custom CSS -->
    <link rel="stylesheet" href="assets/css/style.css" />

    <?php if (isset($extraCSS)): ?>
        <?= $extraCSS ?>
    <?php endif; ?>
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
                    <a class="ta-nav-link <?= ($pageTitle === 'Dashboard') ? 'active' : '' ?>" href="dashboard.php">
                        <svg class="ta-nav-icon" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 3v11.25A2.25 2.25 0 006 16.5h2.25M3.75 3h-1.5M3.75 3h16.5M21.75 3v11.25A2.25 2.25 0 0119.5 16.5H17.25m-10.5 0h6m-6 0v3.75A1.5 1.5 0 007.5 21.75h9a1.5 1.5 0 001.5-1.5V16.5m-10.5 0h10.5" /></svg>
                        <span>Dashboard</span>
                    </a>
                </li>
                <li>
                    <a class="ta-nav-link <?= (strpos($pageTitle, 'Tempahan') !== false) ? 'active' : '' ?>" href="bookings.php">
                        <svg class="ta-nav-icon" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 012.25-2.25h13.5A2.25 2.25 0 0121 7.5v11.25m-18 0A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75m-18 0v-7.5A2.25 2.25 0 015.25 9h13.5A2.25 2.25 0 0121 11.25v7.5m-13.5-6h.008v.008h-.008v-.008zm0 3h.008v.008h-.008v-.008zm3-3h.008v.008h-.008v-.008zm0 3h.008v.008h-.008v-.008z" /></svg>
                        <span>Tempahan</span>
                    </a>
                </li>
                <li>
                    <a class="ta-nav-link <?= (strpos($pageTitle, 'Kenderaan') !== false) ? 'active' : '' ?>" href="vehicles.php">
                        <svg class="ta-nav-icon" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 18.75a1.5 1.5 0 01-3 0m3 0a1.5 1.5 0 00-3 0m3 0h6m-9 0H3.375a1.125 1.125 0 01-1.125-1.125V14.25m17.25 4.5a1.5 1.5 0 01-3 0m3 0a1.5 1.5 0 00-3 0m3 0h1.125c.621 0 1.129-.504 1.09-1.124a17.902 17.902 0 00-3.213-9.193 2.056 2.056 0 00-1.58-.86H14.25M16.5 18.75h-2.25m0-11.177v-.958c0-.568-.422-1.048-.987-1.106a48.554 48.554 0 00-10.026 0 1.106 1.106 0 00-.987 1.106v7.635m12-6.677v6.677m0 0h-12" /></svg>
                        <span>Kenderaan</span>
                    </a>
                </li>
                <li>
                    <a class="ta-nav-link <?= (strpos($pageTitle, 'Pemandu') !== false) ? 'active' : '' ?>" href="drivers.php">
                        <svg class="ta-nav-icon" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 8.25h19.5M2.25 9h19.5m-16.5 5.25h6m-6 2.25h3m-3.75 3h15a2.25 2.25 0 002.25-2.25V6.75A2.25 2.25 0 0019.5 4.5h-15a2.25 2.25 0 00-2.25 2.25v10.5A2.25 2.25 0 004.5 19.5z" /></svg>
                        <span>Pemandu</span>
                    </a>
                </li>

                <?php if ($role === 'SuperAdmin'): ?>
                <li>
                    <a class="ta-nav-link <?= ($pageTitle === 'Pengguna') ? 'active' : '' ?>" href="users.php">
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
                    <a class="ta-nav-link <?= ($pageTitle === 'Log Aktiviti') ? 'active' : '' ?>" href="activity_log.php">
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

    <!-- Mobile Dock -->
    <div class="fixed inset-x-0 bottom-0 z-[70] xl:hidden px-4 pb-4 pointer-events-none">
        <div class="relative max-w-md mx-auto pointer-events-auto">
            <!-- Central Floating Action Button (Dashboard) -->
            <a href="dashboard.php"
               id="nav-dashboard"
               class="nav-item absolute left-1/2 -translate-x-1/2 -top-7 z-30 flex flex-col items-center group transition-transform duration-300 ease-[cubic-bezier(0.175,0.885,0.32,2.2)] active:scale-90">
                <span class="relative w-14 h-14 rounded-full flex items-center justify-center transition-all duration-300 group-hover:-translate-y-1"
                      style="background: linear-gradient(135deg, color-mix(in oklch, var(--ta-brand) 85%, white), var(--ta-brand));
                            color:#fff;
                            box-shadow: 0 12px 28px -6px color-mix(in oklch, var(--ta-brand) 50%, transparent),
                                    0 0 0 1px rgba(255,255,255,0.5) inset,
                                    0 0 0 4px var(--ta-canvas, #ffffff);">
                    <span class="absolute inset-0 rounded-full bg-gradient-to-b from-white/45 via-white/10 to-transparent opacity-80 pointer-events-none"></span>
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6 relative z-10" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 3v11.25A2.25 2.25 0 006 16.5h2.25M3.75 3h-1.5M3.75 3h16.5M21.75 3v11.25A2.25 2.25 0 0119.5 16.5H17.25m-10.5 0h6m-6 0v3.75A1.5 1.5 0 007.5 21.75h9a1.5 1.5 0 001.5-1.5V16.5m-10.5 0h10.5" />
                    </svg>
                </span>
                <span class="text-[11px] font-medium tracking-tight mt-1" style="color: var(--ta-muted)">Dashboard</span>
            </a>

            <!-- Liquid Glass Dock Base -->
            <div class="glass-dock flex items-center justify-around px-2 pt-2.5 pb-2 rounded-[32px] overflow-visible">
                <!-- Sliding active pill -->
                <div id="liquid-pill"
                     class="absolute top-1.5 bottom-1.5 rounded-[22px] transition-all duration-300 ease-[cubic-bezier(0.175,0.885,0.32,1.2)] opacity-0 pointer-events-none z-0"
                     style="background: color-mix(in oklch, var(--ta-brand) 14%, rgba(255,255,255,0.55));
                           border: 1px solid rgba(255,255,255,0.6);
                           box-shadow: 0 4px 14px rgba(0,0,0,0.06), inset 0 1px 1px rgba(255,255,255,0.9);">
                </div>

                <a href="bookings.php" id="nav-bookings"
                   class="nav-item flex flex-col items-center gap-1 flex-1 py-1 z-10 transition-transform duration-200 active:scale-90" style="color: var(--ta-muted)">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 012.25-2.25h13.5A2.25 2.25 0 0121 7.5v11.25m-18 0A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75m-18 0v-7.5A2.25 2.25 0 015.25 9h13.5A2.25 2.25 0 0121 11.25v7.5m-13.5-6h.008v.008h-.008v-.008zm0 3h.008v.008h-.008v-.008zm3-3h.008v.008h-.008v-.008zm0 3h.008v.008h-.008v-.008z" /></svg>
                    <span class="text-[11px] font-medium tracking-tight">Tempahan</span>
                </a>

                <a href="vehicles.php" id="nav-vehicles"
                   class="nav-item flex flex-col items-center gap-1 flex-1 py-1 z-10 transition-transform duration-200 active:scale-90" style="color: var(--ta-muted)">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 18.75a1.5 1.5 0 01-3 0m3 0a1.5 1.5 0 00-3 0m3 0h6m-9 0H3.375a1.125 1.125 0 01-1.125-1.125V14.25m17.25 4.5a1.5 1.5 0 01-3 0m3 0a1.5 1.5 0 00-3 0m3 0h1.125c.621 0 1.129-.504 1.09-1.124a17.902 17.902 0 00-3.213-9.193 2.056 2.056 0 00-1.58-.86H14.25M16.5 18.75h-2.25m0-11.177v-.958c0-.568-.422-1.048-.987-1.106a48.554 48.554 0 00-10.026 0 1.106 1.106 0 00-.987 1.106v7.635m12-6.677v6.677m0 0h-12" /></svg>
                    <span class="text-[11px] font-medium tracking-tight">Kenderaan</span>
                </a>

                <div class="flex-1 flex justify-center pointer-events-none"><span class="w-14"></span></div>

                <a href="drivers.php" id="nav-drivers"
                   class="nav-item flex flex-col items-center gap-1 flex-1 py-1 z-10 transition-transform duration-200 active:scale-90" style="color: var(--ta-muted)">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 8.25h19.5M2.25 9h19.5m-16.5 5.25h6m-6 2.25h3m-3.75 3h15a2.25 2.25 0 002.25-2.25V6.75A2.25 2.25 0 0019.5 4.5h-15a2.25 2.25 0 00-2.25 2.25v10.5A2.25 2.25 0 004.5 19.5z" /></svg>
                    <span class="text-[11px] font-medium tracking-tight">Pemandu</span>
                </a>

                <!-- "Lagi" menu popover -->
                <div id="nav-more" role="button" tabindex="0" aria-haspopup="true" aria-expanded="false"
                     class="nav-item relative flex flex-col items-center gap-1 flex-1 py-1 z-10 transition-transform duration-200 active:scale-90" style="color: var(--ta-muted)">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                        <circle cx="5" cy="12" r="1.5" fill="currentColor" stroke="none"/>
                        <circle cx="12" cy="12" r="1.5" fill="currentColor" stroke="none"/>
                        <circle cx="19" cy="12" r="1.5" fill="currentColor" stroke="none"/>
                    </svg>
                    <span class="text-[11px] font-medium tracking-tight">Lagi</span>

                    <!-- Popover menu -->
                    <div id="more-menu"
                         class="more-popover absolute bottom-full right-0 mb-3 w-38 rounded-2xl p-1.5 opacity-0 scale-95 pointer-events-none transition-all duration-200 origin-bottom-right">
                        <?php if ($role === 'SuperAdmin'): ?>
                        <a href="users.php" class="flex items-center gap-2.5 px-3 py-2.5 rounded-xl text-sm font-medium transition-colors" style="color: var(--ta-text, #1c1c1e)">
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19.128a9.38 9.38 0 002.625.372 9.337 9.337 0 004.121-.952 4.125 4.125 0 00-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 018.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0111.964-3.07M12 6.375a3.375 3.375 0 11-6.75 0 3.375 3.375 0 016.75 0zm8.25 2.25a2.625 2.625 0 11-5.25 0 2.625 2.625 0 015.25 0z" /></svg>
                            Pengguna
                        </a>
                        <?php endif; ?>

                        <?php if (in_array($role, ['SuperAdmin', 'Admin'], true)): ?>
                        <a href="activity_log.php" class="flex items-center gap-2.5 px-3 py-2.5 rounded-xl text-sm font-medium transition-colors" style="color: var(--ta-text, #1c1c1e)">
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                            Log Aktiviti
                        </a>
                        <?php endif; ?>

                        <a href="profile.php" class="flex items-center gap-2.5 px-3 py-2.5 rounded-xl text-sm font-medium transition-colors" style="color: var(--ta-text, #1c1c1e)">
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                                <circle cx="12" cy="8" r="4" stroke-linecap="round" stroke-linejoin="round"/>
                                <path stroke-linecap="round" stroke-linejoin="round" d="M4 20c0-4.418 3.582-8 8-8s8 3.582 8 8" />
                            </svg>
                            Profil Saya
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
