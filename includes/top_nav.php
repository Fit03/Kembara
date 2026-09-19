<?php
/**
 * top_nav.php
 * Parameterized top navigation bar
 */
$pageTitle = $pageTitle ?? 'Page';
$showSearch = $showSearch ?? false;
$searchAction = $searchAction ?? '';
$searchPlaceholder = $searchPlaceholder ?? 'Cari...';
$backUrl = $backUrl ?? null;
$breadcrumbs = $breadcrumbs ?? null;

$navUserId = (int)($_SESSION['user_id'] ?? 0);
$navRole = $_SESSION['role'] ?? 'User';
$notificationCount = 0;
$notificationTitle = 'Tiada notifikasi baharu.';
$notificationDescription = '';
$notificationUrl = 'bookings.php';

if (in_array($navRole, ['Admin', 'SuperAdmin'], true)) {
    $notificationCount = (int)$pdo->query(
        "SELECT COUNT(*) FROM vehicle_bookings
         WHERE status='Pending' AND workflow_stage IN ('Submitted', 'ReassignmentRequired')"
    )->fetchColumn();
    $notificationTitle = 'Tempahan Menunggu Tugasan';
    $notificationDescription = 'Tempahan yang memerlukan admin menetapkan atau menetapkan semula pemandu.';
    $notificationUrl = 'bookings.php?status=Pending';
} else {
    $notificationStmt = $pdo->prepare(
        "SELECT COUNT(*) FROM vehicle_bookings vb
         LEFT JOIN drivers assigned_driver ON assigned_driver.driver_id = vb.driver_id
         WHERE (
             vb.user_id = :user_id AND vb.status IN ('Pending', 'Approved')
         ) OR (
             assigned_driver.user_id = :driver_user_id
             AND vb.status = 'Pending'
             AND vb.workflow_stage = 'DriverAssigned'
         )"
    );
    $notificationStmt->execute([
        ':user_id' => $navUserId,
        ':driver_user_id' => $navUserId,
    ]);
    $notificationCount = (int)$notificationStmt->fetchColumn();
    $notificationTitle = 'Kemas Kini Tempahan';
    $notificationDescription = 'Terdapat tempahan anda atau tugasan pemandu yang memerlukan semakan.';
    $notificationUrl = 'bookings.php';
}

// Semak sama ada e-mel sudah disimpan dalam sesi daripada log masuk
$email = $_SESSION['email'] ?? null;

// Jika tiada dalam sesi, ambil terus dari pangkalan data menggunakan $pdo
if (!$email) {
    $stmt = $pdo->prepare("SELECT email FROM users WHERE user_id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $email = $stmt->fetchColumn() ?: 'tiada-emel@selangor.gov.my';
}

// Sahkan gambar profil sedia ada; jangan bergantung sepenuhnya pada halaman pemanggil
if (empty($profilePicture) || !is_file(__DIR__ . '/../' . $profilePicture)) {
    $profilePicture = null;
}
$hasPhoto = $profilePicture !== null;
?>
<nav class="sticky top-0 z-50 flex items-center justify-between gap-4 px-4 sm:px-6 py-3 bg-white border-b" style="border-color:var(--ta-border)">
    <div class="flex items-center gap-3">
        <?php if ($backUrl): ?>
        <a href="<?= htmlspecialchars($backUrl) ?>" class="btn btn-ghost btn-circle btn-sm h-9 w-9 min-h-0" title="Kembali">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-4.5 w-4.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M10.5 19.5L3 12m0 0l7.5-7.5M3 12h18" /></svg>
        </a>
        <?php endif; ?>
        <div>
            <h6 class="font-bold text-base leading-tight"><?= htmlspecialchars($pageTitle) ?></h6>
            <p class="text-xs leading-tight" style="color:var(--ta-muted)">
                <?php if ($breadcrumbs): ?>
                    <?php foreach ($breadcrumbs as $index => $crumb): ?>
                        <a href="<?= htmlspecialchars($crumb['url']) ?>" class="<?= $index === count($breadcrumbs)-1 ? 'pointer-events-none' : 'opacity-70 hover:opacity-100 hover:underline' ?>" style="<?= $index === count($breadcrumbs)-1 ? 'color:var(--ta-ink)' : '' ?>">
                            <?= htmlspecialchars($crumb['label']) ?>
                        </a>
                        <?php if ($index < count($breadcrumbs)-1): ?>
                            <span class="mx-1 opacity-40">/</span>
                        <?php endif; ?>
                    <?php endforeach; ?>
                <?php else: ?>
                    <span class="opacity-70">Halaman</span>
                    <span class="mx-1 opacity-40">/</span>
                    <span><?= htmlspecialchars($pageTitle) ?></span>
                <?php endif; ?>
            </p>
        </div>
    </div>

    <?php if ($showSearch): ?>
    <form action="<?= htmlspecialchars($searchAction) ?>" method="GET" class="hidden md:flex items-center gap-2 rounded-lg px-3 py-2 flex-1 max-w-sm" style="background:var(--ta-canvas); border:1px solid var(--ta-border)">
        <?php if (isset($_GET['status']) && $_GET['status'] !== 'All'): ?>
            <input type="hidden" name="status" value="<?= htmlspecialchars($_GET['status']) ?>" />
        <?php endif; ?>
        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 shrink-0" style="color:var(--ta-muted)" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607z" /></svg>
        <input type="text" name="q" value="<?= htmlspecialchars($_GET['q'] ?? '') ?>" placeholder="<?= htmlspecialchars($searchPlaceholder) ?>" class="bg-transparent border-0 outline-none text-sm w-full placeholder:text-slate-400" />
    </form>
    <?php endif; ?>

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
                <?php if ($notificationCount > 0): ?>
                    <span class="absolute top-2 right-2 flex h-2.5 w-2.5">
                        <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-error opacity-75"></span>
                        <span class="relative inline-flex rounded-full h-2.5 w-2.5 bg-error"></span>
                    </span>
                <?php endif; ?>
            </div>

            <div tabindex="0" class="dropdown-content z-[99] menu p-0 shadow-xl card rounded-2xl w-80 max-w-[90vw] mt-2 border" style="border-color: var(--ta-border);">
                <!-- Header Notifikasi -->
                <div class="px-4 py-3 border-b flex items-center justify-between" style="border-color: var(--ta-border);">
                    <div class="flex items-center gap-2">
                        <span class="font-bold text-sm">Notifikasi</span>
                        <?php if ($notificationCount > 0): ?>
                            <span class="badge badge-error badge-sm text-white font-semibold"><?= $notificationCount ?> baru</span>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Content List -->
                <div class="max-h-64 overflow-y-auto divide-y" style="border-color: var(--ta-border);">
                    <?php if ($notificationCount > 0): ?>
                        <a href="<?= htmlspecialchars($notificationUrl) ?>" class="flex items-start gap-3 p-3.5 hover:bg-slate-50 dark:hover:bg-slate-800 transition-colors">
                            <div class="p-2 rounded-full bg-warning/15 text-warning shrink-0 mt-0.5">
                                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                            </div>
                            <div class="flex-1 min-w-0">
                                <p class="text-xs font-semibold text-slate-800 dark:text-slate-100"><?= htmlspecialchars($notificationTitle) ?></p>
                                <p class="text-[11px] text-slate-400 mt-0.5"><?= htmlspecialchars($notificationDescription) ?></p>
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