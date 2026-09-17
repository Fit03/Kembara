<?php
// activity_log.php — Log Aktiviti Sistem (SuperAdmin & Admin sahaja)
require_once __DIR__ . '/includes/auth.php';
require_role(['SuperAdmin', 'Admin']);
require_once __DIR__ . '/config/database.php';


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

// --- Layout Config ---
$pageTitle = "Log Aktiviti";
$showSearch = true;
$searchAction = "activity_log.php";
$searchPlaceholder = "Cari mengikut pengguna, tindakan atau butiran...";
$extraJS = '';

include 'includes/layout_header.php';
?>
<main class="xl:ml-64 relative min-h-screen pb-24 xl:pb-0">
    <?php include 'includes/top_nav.php'; ?>
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
            <div class="ta-timeline">
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
                          <svg xmlns="http://www.w3.org/2000/svg" class="h-3 w-3 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 17.25v1.007a3 3 0 01-.879 2.122L7.5 21h9l-.621-.621A3 3 0 0115 18.257V17.25m6-12V15a2.25 2.25 0 01-2.25 2.25H5.25A2.25 2.25 0 013 15V5.25m18 0V12a2.25 2.25 0 01-2.25 2.25H5.25A2.25 2.25 0 013 12V5.25" /></svg>
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

    </div>
</main>

<?php
include 'includes/layout_footer.php';
?>
