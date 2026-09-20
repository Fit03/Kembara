<?php
// view-driver.php - Paparan butiran pemandu
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/config/database.php';

require_login();

$fullname = $_SESSION['fullname'] ?? '';
$role = $_SESSION['role'] ?? 'User';
$currentUserId = (int)($_SESSION['user_id'] ?? 0);
$canManage = in_array($role, ['SuperAdmin', 'Admin'], true);

$email = $_SESSION['email'] ?? null;
if (!$email) {
    $stmt = $pdo->prepare('SELECT email FROM users WHERE user_id = ?');
    $stmt->execute([$currentUserId]);
    $email = $stmt->fetchColumn() ?: 'tiada-emel@selangor.gov.my';
}

$avatarStmt = $pdo->prepare('SELECT profile_picture FROM users WHERE user_id = ?');
$avatarStmt->execute([$currentUserId]);
$profilePicture = $avatarStmt->fetchColumn();
$hasPhoto = $profilePicture && is_file(__DIR__ . '/' . $profilePicture);

$badgeColor = match ($role) {
    'SuperAdmin' => 'badge badge-error',
    'Admin' => 'badge badge-warning',
    default => 'badge badge-info',
};
$driverStatusBadge = fn(string $status) => match ($status) {
    'Available' => 'badge badge-success',
    'Leave' => 'badge badge-warning',
    'Inactive' => 'badge badge-ghost',
    default => 'badge badge-ghost',
};
$driverStatusLabel = fn(string $status) => match ($status) {
    'Available' => 'Boleh Bertugas',
    'Leave' => 'Cuti',
    'Inactive' => 'Tidak Aktif',
    default => $status,
};

$driverId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
$mode = $_GET['mode'] ?? 'view';
if (!in_array($mode, ['view', 'edit'], true)) {
    $mode = 'view';
}
$driverStmt = $pdo->prepare(
    "SELECT dr.driver_id, dr.license, dr.status,
            u.fullname, u.email, u.phone_no, u.profile_picture,
            d.department_name,
            COUNT(DISTINCT v.vehicle_id) AS vehicle_count,
            COUNT(DISTINCT vb.booking_id) AS booking_count,
            GROUP_CONCAT(DISTINCT CONCAT(COALESCE(v.vehicle_name, ''),
                CASE WHEN v.vehicle_name IS NULL OR v.vehicle_name = '' THEN '' ELSE ' — ' END,
                v.plate_no) ORDER BY v.plate_no SEPARATOR '||') AS assigned_vehicles
     FROM drivers dr
     JOIN users u ON u.user_id = dr.user_id
     LEFT JOIN departments d ON d.department_id = u.department_id
     LEFT JOIN vehicles v ON v.driver_id = dr.driver_id
     LEFT JOIN vehicle_bookings vb ON vb.driver_id = dr.driver_id
     WHERE dr.driver_id = ?
     GROUP BY dr.driver_id, dr.license, dr.status, u.fullname, u.email, u.phone_no,
              u.profile_picture, d.department_name"
);
$driverStmt->execute([$driverId]);
$driver = $driverStmt->fetch(PDO::FETCH_ASSOC);

if (!$driver) {
    $_SESSION['flash'] = ['type' => 'error', 'msg' => 'Pemandu tidak dijumpai.'];
    header('Location: drivers.php');
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$canManage) {
        $_SESSION['flash'] = ['type' => 'error', 'msg' => 'Anda tidak mempunyai kebenaran untuk mengemaskini pemandu.'];
        header('Location: view-driver.php?id=' . $driverId);
        exit();
    }
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        http_response_code(403);
        exit('Ralat Keselamatan: Token CSRF tidak sah atau telah tamat tempoh.');
    }

    $license = trim($_POST['license'] ?? '');
    $status = $_POST['status'] ?? 'Available';
    if ($license === '' || strlen($license) > 50) {
        $_SESSION['flash'] = ['type' => 'error', 'msg' => 'Sila isikan nombor lesen yang sah (maksimum 50 aksara).'];
    } elseif (!in_array($status, ['Available', 'Leave', 'Inactive'], true)) {
        $_SESSION['flash'] = ['type' => 'error', 'msg' => 'Status pemandu tidak sah.'];
    } else {
        try {
            $updateStmt = $pdo->prepare('UPDATE drivers SET license = ?, status = ? WHERE driver_id = ?');
            $updateStmt->execute([$license, $status, $driverId]);
            $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Maklumat pemandu berjaya dikemaskini.'];
        } catch (PDOException $e) {
            $_SESSION['flash'] = ['type' => 'error', 'msg' => $e->getCode() === '23000'
                ? 'Operasi gagal — nombor lesen mungkin telah wujud.'
                : 'Ralat pangkalan data berlaku. Sila cuba lagi.'];
        }
    }
    header('Location: view-driver.php?id=' . $driverId . '&mode=edit');
    exit();
}

$avatarPath = $driver['profile_picture'] ?? null;
$avatarExists = $avatarPath && is_file(__DIR__ . '/' . $avatarPath);
$assignedVehicles = !empty($driver['assigned_vehicles']) ? explode('||', $driver['assigned_vehicles']) : [];
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

$pageTitle = $mode === 'edit' ? 'Kemaskini Pemandu' : 'Butiran Pemandu';
$backUrl = 'drivers.php';
$breadcrumbs = [
    ['url' => 'dashboard.php', 'label' => 'Halaman'],
    ['url' => 'drivers.php', 'label' => 'Pemandu'],
    ['url' => '#', 'label' => $driver['fullname']],
];
$showSearch = false;

include 'includes/layout_header.php';
?>
<main class="xl:ml-64 relative min-h-screen pb-24 xl:pb-0">
    <?php include 'includes/top_nav.php'; ?>
    <div class="w-full max-w-5xl px-4 sm:px-6 py-6 mx-auto">
        <?php if ($flash): ?>
        <div class="alert <?= $flash['type'] === 'success' ? 'alert-success' : 'alert-error' ?> mb-5 text-sm">
            <?= htmlspecialchars($flash['msg']) ?>
        </div>
        <?php endif; ?>
        <div class="flex flex-col gap-5">
            <div class="card overflow-hidden">
                <div class="p-6 sm:p-8" style="background:linear-gradient(135deg, var(--ta-brand-50), var(--ta-surface))">
                    <div class="flex items-start justify-between gap-4 flex-wrap">
                        <div class="flex items-center gap-4 min-w-0">
                            <?php if ($avatarExists): ?>
                                <div class="rounded-full w-16 h-16 overflow-hidden shrink-0 border-2" style="border-color:var(--ta-brand)">
                                    <img src="<?= htmlspecialchars($avatarPath) ?>?v=<?= time() ?>" alt="Avatar <?= htmlspecialchars($driver['fullname']) ?>" class="w-full h-full object-cover" />
                                </div>
                            <?php else: ?>
                                <div class="rounded-full w-16 h-16 flex items-center justify-center font-bold text-2xl uppercase text-white shrink-0" style="background:var(--ta-brand)">
                                    <?= htmlspecialchars(substr($driver['fullname'], 0, 1)) ?>
                                </div>
                            <?php endif; ?>
                            <div class="min-w-0">
                                <p class="text-xs uppercase tracking-widest mb-1" style="color:var(--ta-muted)">Profil Pemandu</p>
                                <h1 class="text-2xl sm:text-3xl font-bold truncate"><?= htmlspecialchars($driver['fullname']) ?></h1>
                                <p class="text-sm mt-1" style="color:var(--ta-muted)"><?= htmlspecialchars($driver['email']) ?></p>
                            </div>
                        </div>
                        <div class="flex items-center gap-2 shrink-0">
                            <span class="ta-badge <?= $driverStatusBadge($driver['status']) ?>"><?= htmlspecialchars($driverStatusLabel($driver['status'])) ?></span>
                            <?php if ($canManage && $mode === 'view'): ?>
                            <a href="view-driver.php?id=<?= $driverId ?>&amp;mode=edit" class="btn btn-sm btn-outline">Kemaskini</a>
                            <?php elseif ($mode === 'edit'): ?>
                            <a href="view-driver.php?id=<?= $driverId ?>" class="btn btn-sm btn-outline">Lihat Profil</a>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <?php if ($mode === 'edit'): ?>
            <div class="card p-6 sm:p-8">
                <div class="mb-5">
                    <p class="text-xs uppercase tracking-widest mb-1" style="color:var(--ta-muted)">Maklumat Pemandu</p>
                    <h2 class="text-xl font-semibold">Kemaskini maklumat pemandu</h2>
                    <p class="text-sm mt-1" style="color:var(--ta-muted)">Ubah nombor lesen atau status ketersediaan pemandu.</p>
                </div>
                <form method="POST" class="flex flex-col gap-4">
                    <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>" />
                    <div>
                        <label class="text-xs font-medium block mb-1">Nombor Lesen Memandu</label>
                        <input type="text" name="license" required maxlength="50" class="input input-bordered w-full" value="<?= htmlspecialchars($driver['license']) ?>" />
                    </div>
                    <div>
                        <label class="text-xs font-medium block mb-1">Status</label>
                        <select name="status" class="select select-bordered w-full">
                            <option value="Available" <?= $driver['status'] === 'Available' ? 'selected' : '' ?>>Boleh Bertugas</option>
                            <option value="Leave" <?= $driver['status'] === 'Leave' ? 'selected' : '' ?>>Cuti</option>
                            <option value="Inactive" <?= $driver['status'] === 'Inactive' ? 'selected' : '' ?>>Tidak Aktif</option>
                        </select>
                    </div>
                    <div class="flex justify-end gap-2 mt-2">
                        <a href="view-driver.php?id=<?= $driverId ?>" class="btn btn-ghost">Batal</a>
                        <button type="submit" class="btn text-white border-0" style="background:var(--ta-brand)">Simpan Perubahan</button>
                    </div>
                </form>
            </div>
            <?php else: ?>
            <div class="grid grid-cols-1 lg:grid-cols-[minmax(0,1.15fr)_minmax(0,0.85fr)] gap-5">
                <div class="card p-6 sm:p-8">
                    <div class="flex items-center justify-between gap-3 mb-5">
                        <div>
                            <p class="text-xs uppercase tracking-widest mb-1" style="color:var(--ta-muted)">Les Memandu</p>
                            <h2 class="text-lg font-semibold">Kad Lesen Pemandu</h2>
                        </div>
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-7 w-7" style="color:var(--ta-brand)" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.7"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 7.5h16.5M6 12h4.5m-4.5 3h3m9.75-7.5v9a2.25 2.25 0 01-2.25 2.25H7.5A2.25 2.25 0 015.25 16.5v-9A2.25 2.25 0 017.5 5.25h9A2.25 2.25 0 0119.75 7.5z" /></svg>
                    </div>
                    <div class="rounded-2xl border p-6 sm:p-8" style="border-color:var(--ta-border); background:var(--ta-canvas)">
                        <p class="text-xs uppercase tracking-widest mb-3" style="color:var(--ta-muted)">Nombor Lesen</p>
                        <p class="text-3xl sm:text-4xl font-bold tracking-wide break-all" style="color:var(--ta-brand)"><?= htmlspecialchars($driver['license']) ?></p>
                        <div class="mt-6 pt-4 border-t flex items-center justify-between gap-3" style="border-color:var(--ta-border)">
                            <span class="text-sm" style="color:var(--ta-muted)">Pemegang lesen</span>
                            <span class="text-sm font-semibold text-right"><?= htmlspecialchars($driver['fullname']) ?></span>
                        </div>
                    </div>
                </div>

                <div class="card p-6 sm:p-8">
                    <h2 class="text-lg font-semibold mb-5">Ringkasan</h2>
                    <div class="grid grid-cols-2 gap-3">
                        <div class="rounded-xl p-4" style="background:var(--ta-canvas)">
                            <p class="text-xs mb-1" style="color:var(--ta-muted)">Kenderaan</p>
                            <p class="text-2xl font-bold"><?= (int)$driver['vehicle_count'] ?></p>
                        </div>
                        <div class="rounded-xl p-4" style="background:var(--ta-canvas)">
                            <p class="text-xs mb-1" style="color:var(--ta-muted)">Tempahan</p>
                            <p class="text-2xl font-bold"><?= (int)$driver['booking_count'] ?></p>
                        </div>
                    </div>
                    <div class="mt-5 pt-5 border-t flex flex-col gap-4 text-sm" style="border-color:var(--ta-border)">
                        <div><p class="text-xs mb-1" style="color:var(--ta-muted)">Jabatan</p><p class="font-medium"><?= htmlspecialchars($driver['department_name'] ?: '—') ?></p></div>
                        <div><p class="text-xs mb-1" style="color:var(--ta-muted)">No. Telefon</p><p class="font-medium"><?= htmlspecialchars($driver['phone_no'] ?: '—') ?></p></div>
                    </div>
                </div>
            </div>

            <div class="card p-6 sm:p-8">
                <h2 class="text-lg font-semibold mb-1">Kenderaan Ditugaskan</h2>
                <p class="text-sm mb-5" style="color:var(--ta-muted)">Kenderaan yang sedang dikaitkan dengan pemandu ini.</p>
                <?php if ($assignedVehicles): ?>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <?php foreach ($assignedVehicles as $vehicle): ?>
                            <div class="rounded-xl border px-4 py-3 flex items-center gap-3" style="border-color:var(--ta-border)">
                                <div class="ta-icon-box ta-icon-box-cyan" style="width:2.5rem;height:2.5rem"><svg class="ta-nav-icon" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 18.75a1.5 1.5 0 01-3 0m3 0a1.5 1.5 0 00-3 0m3 0h6m-9 0H3.375a1.125 1.125 0 01-1.125-1.125V14.25m17.25 4.5a1.5 1.5 0 01-3 0m3 0a1.5 1.5 0 00-3 0m3 0h1.125c.621 0 1.129-.504 1.09-1.124a17.902 17.902 0 00-3.213-9.193 2.056 2.056 0 00-1.58-.86H14.25M16.5 18.75h-2.25m0-11.177v-.958c0-.568-.422-1.048-.987-1.106a48.554 48.554 0 00-10.026 0 1.106 1.106 0 00-1.106 1.106v7.635m12-6.677v6.677m0 0h-12" /></svg></div>
                                <p class="font-medium text-sm"><?= htmlspecialchars($vehicle) ?></p>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <p class="text-sm" style="color:var(--ta-muted)">Tiada kenderaan ditugaskan.</p>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>
</main>
<?php include 'includes/layout_footer.php'; ?>
