<?php
// view.php — Butiran Tempahan (halaman penuh, gantikan modal Lihat Butiran)
require_once __DIR__ . '/includes/auth.php';
require_login();
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/booking_progress.php';


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

$avatarStmt = $pdo->prepare("SELECT profile_picture FROM users WHERE user_id = ?");
$avatarStmt->execute([$currentUserId]);
$profilePicture = $avatarStmt->fetchColumn();
$hasPhoto = $profilePicture && is_file(__DIR__ . '/' . $profilePicture);

$badgeColor = match($role) {
  'SuperAdmin' => 'badge badge-error',
  'Admin'      => 'badge badge-warning',
  default      => 'badge badge-info',
};

$statusBadge = fn(string $s) => match ($s) {
  'Pending'   => 'badge badge-warning',
  'Approved'  => 'badge badge-info',
  'Rejected'  => 'badge badge-error',
  'Cancelled' => 'badge badge-ghost',
  'Completed' => 'badge badge-success',
  default     => 'badge badge-ghost',
};
$statusLabel = fn(string $s) => match ($s) {
    'Pending'   => 'Menunggu',
    'Approved'  => 'Diluluskan',
    'Rejected'  => 'Ditolak',
    'Cancelled' => 'Dibatalkan',
    'Completed' => 'Selesai',
    default     => $s,
};
$tripTypeLabel = fn(string $t) => match ($t) {
    'One Way' => 'Sehala',
    'Return'  => 'Pergi Balik',
    default   => $t,
};

$driverStatusBadge = fn(string $s) => match ($s) {
    'Available' => 'badge badge-xs badge-success',
    'Leave'     => 'badge badge-xs badge-warning',
    'Inactive'  => 'badge badge-xs badge-ghost',
    default     => 'badge badge-xs badge-ghost',
};
$driverStatusLabel = fn(string $s) => match ($s) {
    'Available' => 'Boleh Bertugas',
    'Leave'     => 'Cuti',
    'Inactive'  => 'Tidak Aktif',
    default     => $s,
};

$pendingApprovals = $pdo->query("SELECT COUNT(*) FROM vehicle_bookings WHERE status = 'Pending'")->fetchColumn();

// Pautan "Kembali" — kekalkan penapis/carian/halaman jadual yang asal jika ada
$backUrl = 'bookings.php' . (isset($_GET['from']) && $_GET['from'] !== '' ? '?' . $_GET['from'] : '');

// ==========================================================
// Ambil butiran tempahan
// ==========================================================
$bookingId = (int)($_GET['id'] ?? 0);

if ($bookingId <= 0) {
    $_SESSION['flash'] = ['type' => 'error', 'msg' => 'Tempahan tidak sah.'];
    header("Location: bookings.php");
    exit();
}

$stmt = $pdo->prepare(
    "SELECT vb.*, u.fullname AS requester_name,
            v.plate_no, v.vehicle_name,
            du.fullname AS driver_name, du.user_id AS driver_user_id, du.profile_picture AS driver_photo,
            dr.license AS driver_license, dr.status AS driver_status,
            ap.fullname AS approved_by_name
     FROM vehicle_bookings vb
     JOIN users u ON u.user_id = vb.user_id
     LEFT JOIN vehicles v ON v.vehicle_id = vb.vehicle_id
     LEFT JOIN drivers dr ON dr.driver_id = vb.driver_id
     LEFT JOIN users du ON du.user_id = dr.user_id
     LEFT JOIN users ap ON ap.user_id = vb.approved_by
     WHERE vb.booking_id = ?"
);

$stmt->execute([$bookingId]);
$b = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$b) {
    $_SESSION['flash'] = ['type' => 'error', 'msg' => 'Tempahan tidak dijumpai.'];
    header("Location: bookings.php");
    exit();
}

// Pengguna biasa boleh melihat tempahan sendiri atau tugasan pemandu mereka.
$isAssignedDriver = (int)($b['driver_user_id'] ?? 0) === $currentUserId;
if ($role === 'User' && (int)$b['user_id'] !== $currentUserId && !$isAssignedDriver) {
    $_SESSION['flash'] = ['type' => 'error', 'msg' => 'Anda tidak mempunyai akses kepada tempahan ini.'];
    header("Location: bookings.php");
    exit();
}

$passengerNames = $b['passenger_names']
    ? implode(', ', json_decode($b['passenger_names'], true) ?: [])
    : '—';

$vehicleLabel = $b['plate_no']
    ? trim(($b['vehicle_name'] ?: '') . ' (' . $b['plate_no'] . ')')
    : 'Belum ditugaskan';

$driverPhotoExists = !empty($b['driver_photo']) && is_file(__DIR__ . '/' . $b['driver_photo']);

// Sejarah tindakan penuh untuk garis masa
$historyStmt = $pdo->prepare(
    "SELECT bh.action, bh.remarks, bh.action_datetime, u.fullname AS actor_name
     FROM booking_history bh
     JOIN users u ON u.user_id = bh.action_by
     WHERE bh.booking_id = ?
     ORDER BY bh.action_datetime ASC"
);
$historyStmt->execute([$bookingId]);
$history = $historyStmt->fetchAll(PDO::FETCH_ASSOC);

$flash = null;
if (isset($_SESSION['flash'])) {
    $flash = $_SESSION['flash'];
    unset($_SESSION['flash']);
}

// --- Layout Config ---
$pageTitle = "Butiran Tempahan";
$showSearch = false;
$extraJS = '
    <script>
        function dismissToast() {
            const toast = document.getElementById("toast-alert");
            if (!toast) return;
            toast.classList.add("ta-toast-hide");
            toast.addEventListener("animationend", () => toast.remove(), { once: true });
        }

        (function () {
            if (document.getElementById("toast-alert")) {
                setTimeout(dismissToast, 4000);
            }
        })();
    </script>
';

include 'includes/layout_header.php';
?>
<main class="xl:ml-64 relative min-h-screen pb-24 xl:pb-0">
    <?php include 'includes/top_nav.php'; ?>
    <div class="w-full px-4 sm:px-6 py-6 mx-auto max-w-4xl">

      <?php render_booking_progress($b); ?>

        <?php if ($flash): ?>
          <div id="toast-alert" class="card shadow-2xl px-4 py-3.5 rounded-2xl flex items-center gap-3 border mb-5" style="border-color: var(--color-<?= $flash['type'] === 'success' ? 'success' : 'error' ?>); max-width: 26rem; backdrop-filter: blur(16px);">
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

        <!-- Header: Nombor Tempahan & Status -->
        <div class="card p-5 sm:p-6 mb-5">
          <div class="flex items-start justify-between gap-3 flex-wrap">
            <div>
              <div class="flex items-center gap-2.5 flex-wrap">
                <h5 class="text-xl font-bold"><?= htmlspecialchars($b['booking_no']) ?></h5>
                <span class="badge <?= $statusBadge($b['status']) ?>"><?= htmlspecialchars($statusLabel($b['status'])) ?></span>
              </div>
              <p class="text-sm mt-1" style="color:var(--ta-muted)">
                Ditempah oleh <span class="font-medium"><?= htmlspecialchars($b['requester_name']) ?></span>
              </p>
            </div>
            <a href="print.php?id=<?= (int)$bookingId ?>" class="btn btn-info btn-sm gap-2" target="_blank" rel="noopener" title="Cetak tempahan">
              <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M6.75 9V3.75h10.5V9m-12 9h13.5a2.25 2.25 0 002.25-2.25v-4.5A2.25 2.25 0 0018.75 9H5.25A2.25 2.25 0 003 11.25v4.5A2.25 2.25 0 005.25 18zm2.25-3h9v5.25h-9V15z" /></svg>
              <span>Cetak</span>
            </a>
          </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-5 items-start">

          <!-- ===================== LAJUR KIRI (utama) ===================== -->
          <div class="lg:col-span-2 flex flex-col gap-5">

            <!-- Kad: Maklumat Umum -->
            <div class="card p-5 sm:p-6">
              <div class="flex items-start gap-3 mb-5 pb-5 border-b" style="border-color:var(--ta-border)">
                <div class="p-2 rounded-lg shrink-0" style="background:var(--ta-brand-50); color:var(--ta-brand)">
                  <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M11.25 11.25l.041-.02a.75.75 0 011.063.853l-.708 2.836a.75.75 0 001.063.853l.041-.021M21 12a9 9 0 11-18 0 9 9 0 0118 0zm-9-3.75h.008v.008H12V8.25z" /></svg>
                </div>
                <div>
                  <h6 class="font-semibold">Maklumat Umum</h6>
                  <p class="text-xs" style="color:var(--ta-muted)">Maklumat asas tempahan dan penumpang.</p>
                </div>
              </div>

              <div class="grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-5 text-sm">
                <div>
                  <p class="text-xs mb-0.5" style="color:var(--ta-muted)">Jenis Perjalanan</p>
                  <p class="font-medium"><?= htmlspecialchars($tripTypeLabel($b['trip_type'])) ?></p>
                </div>
                <div>
                  <p class="text-xs mb-0.5" style="color:var(--ta-muted)">Bilangan Penumpang</p>
                  <p class="font-medium"><?= htmlspecialchars((string)($b['passenger_total'] ?: '—')) ?></p>
                </div>
                <div class="sm:col-span-2">
                  <p class="text-xs mb-0.5" style="color:var(--ta-muted)">Nama Penumpang</p>
                  <p class="font-medium"><?= htmlspecialchars($passengerNames) ?></p>

                  <?php if (!empty($b['passenger_memo_path'])): ?>
                      <div class="mt-3 p-4 rounded-xl border flex items-center justify-between" style="border-color: var(--ta-border); background: var(--ta-canvas);">
                          <div class="flex items-center gap-3">
                              <div class="p-2.5 rounded-lg bg-error/10 text-error">
                                  <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                      <path stroke-linecap="round" stroke-linejoin="round" d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z" />
                                  </svg>
                              </div>
                              <div>
                                  <p class="text-xs font-semibold">Memo Senarai Penumpang (PDF)</p>
                                  <p class="text-[11px] text-slate-400">Dimuat naik oleh pemohon untuk semakan kenderaan/bas.</p>
                              </div>
                          </div>
                          <a href="<?= htmlspecialchars($b['passenger_memo_path']) ?>" target="_blank" class="btn btn-sm btn-outline btn-primary gap-1.5">
                              <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 010-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178z" /><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" /></svg>
                              Papar
                          </a>
                      </div>
                  <?php endif; ?>
                </div>

                <?php if ($b['approved_by_name']): ?>
                <div class="sm:col-span-2">
                  <p class="text-xs mb-0.5" style="color:var(--ta-muted)">Diluluskan Oleh</p>
                  <p class="font-medium"><?= htmlspecialchars($b['approved_by_name']) ?></p>
                </div>
                <?php endif; ?>
              </div>
            </div>

            <!-- Kad: Maklumat Kenderaan -->
            <div class="card p-5 sm:p-6">
              <div class="flex items-start gap-3 mb-5 pb-5 border-b" style="border-color:var(--ta-border)">
                <div class="p-2 rounded-lg shrink-0" style="background:var(--ta-brand-50); color:var(--ta-brand)">
                  <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 18.75a1.5 1.5 0 01-3 0m3 0a1.5 1.5 0 00-3 0m3 0h6m-9 0H3.375a1.125 1.125 0 01-1.125-1.125V14.25m17.25 4.5a1.5 1.5 0 01-3 0m3 0a1.5 1.5 0 00-3 0m3 0h1.125c.621 0 1.129-.504 1.09-1.124a17.902 17.902 0 00-3.213-9.193 2.056 2.056 0 00-1.58-.86H14.25M16.5 18.75h-2.25m0-11.177v-.958c0-.568-.422-1.048-.987-1.106a48.554 48.554 0 00-10.026 0 1.106 1.106 0 00-.987 1.106v7.635m12-6.677v6.677m0 0h-12" /></svg>
                </div>
                <div>
                  <h6 class="font-semibold">Maklumat Kenderaan</h6>
                  <p class="text-xs" style="color:var(--ta-muted)">Kenderaan dan pemandu yang ditugaskan.</p>
                </div>
              </div>

              <!-- Kotak kenderaan -->
              <div class="rounded-xl p-3.5 mb-4 bg-neutral text-neutral-content">
                <p class="text-[10px] uppercase tracking-wide opacity-70 mb-1">Kenderaan</p>
                <p class="text-sm font-semibold leading-snug"><?= htmlspecialchars($vehicleLabel) ?></p>
              </div>

              <!-- Kad Pemandu (gaya kad lesen) -->
              <?php if ($b['driver_name']): ?>
              <div class="rounded-2xl overflow-hidden" bg-primary text-primary-content>
                <div class="px-4 py-2 flex items-center justify-between" style="background:var(--ta-brand); color:#fff;">
                  <span class="text-[10px] font-bold uppercase tracking-widest">Kad Pemandu</span>
                  <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 opacity-80" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0zM4.501 20.118a7.5 7.5 0 0114.998 0A17.933 17.933 0 0112 21.75c-2.676 0-5.216-.584-7.499-1.632z" /></svg>
                </div>
                <div class="flex items-center gap-4 p-4" style="background:var(--ta-canvas)">
                  <div class="w-16 h-16 sm:w-20 sm:h-20 rounded-xl overflow-hidden shrink-0 border-2" style="border-color:var(--ta-border)">
                    <?php if ($driverPhotoExists): ?>
                      <img src="<?= htmlspecialchars($b['driver_photo']) ?>?v=<?= time() ?>" alt="Pemandu" class="w-full h-full object-cover" />
                    <?php else: ?>
                      <div class="w-full h-full flex items-center justify-center font-bold text-lg uppercase text-white" style="background:var(--ta-brand)">
                        <?= htmlspecialchars(substr($b['driver_name'], 0, 1)) ?>
                      </div>
                    <?php endif; ?>
                  </div>
                  <div class="min-w-0 flex-1">
                    <p class="font-semibold truncate"><?= htmlspecialchars($b['driver_name']) ?></p>
                    <p class="text-xs mt-0.5" style="color:var(--ta-muted)">No. Lesen: <span class="font-medium"><?= htmlspecialchars($b['driver_license'] ?: '—') ?></span></p>
                    <?php if ($b['driver_status']): ?>
                      <span class="badge <?= $driverStatusBadge($b['driver_status']) ?> mt-2"><?= htmlspecialchars($driverStatusLabel($b['driver_status'])) ?></span>
                    <?php endif; ?>
                  </div>
                </div>
              </div>
              <?php if ((int)($b['driver_user_id'] ?? 0) === $currentUserId && $b['status'] === 'Pending' && $b['workflow_stage'] === 'DriverAssigned'): ?>
              <div class="mt-4 p-4 rounded-xl border" style="border-color:var(--ta-border); background:var(--ta-canvas)">
                <p class="text-sm font-semibold mb-1">Pengesahan Tugasan Pemandu</p>
                <p class="text-xs mb-3" style="color:var(--ta-muted)">Sila terima atau tolak tugasan ini. Jika diterima, tempahan akan diluluskan.</p>
                <div class="flex flex-wrap gap-2">
                  <form action="bookings.php" method="POST">
                    <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>" />
                    <input type="hidden" name="action" value="driver_accept" />
                    <input type="hidden" name="booking_id" value="<?= (int)$b['booking_id'] ?>" />
                    <button type="submit" class="btn btn-success btn-sm text-white">Terima Tugasan</button>
                  </form>
                  <form action="bookings.php" method="POST">
                    <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>" />
                    <input type="hidden" name="action" value="driver_reject" />
                    <input type="hidden" name="booking_id" value="<?= (int)$b['booking_id'] ?>" />
                    <button type="submit" class="btn btn-error btn-sm text-white">Tolak Tugasan</button>
                  </form>
                </div>
              </div>
              <?php endif; ?>
              <?php else: ?>
              <div class="rounded-xl border border-dashed p-4 flex items-center gap-3" style="border-color:var(--ta-border); color:var(--ta-muted)">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0zM4.501 20.118a7.5 7.5 0 0114.998 0A17.933 17.933 0 0112 21.75c-2.676 0-5.216-.584-7.499-1.632z" /></svg>
                <p class="text-sm">Tiada pemandu ditugaskan buat masa ini.</p>
              </div>
              <?php endif; ?>
            </div>

          </div>

          <!-- ===================== LAJUR KANAN (sisi) ===================== -->
          <div class="flex flex-col gap-5">

            <!-- Kad: Maklumat Perjalanan -->
            <div class="card p-5 sm:p-6">
              <div class="flex items-center gap-2 mb-4">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" style="color:var(--ta-brand)" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M15 10.5a3 3 0 11-6 0 3 3 0 016 0z" /><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 10.5c0 7.142-7.5 11.25-7.5 11.25S4.5 17.642 4.5 10.5a7.5 7.5 0 1115 0z" /></svg>
                <h6 class="font-semibold">Maklumat Perjalanan</h6>
              </div>

              <div class="flex flex-col gap-4 text-sm">
                <div>
                  <p class="text-xs mb-0.5" style="color:var(--ta-muted)">Asal</p>
                  <p class="font-medium"><?= htmlspecialchars($b['origin']) ?></p>
                </div>
                <div>
                  <p class="text-xs mb-0.5" style="color:var(--ta-muted)">Destinasi</p>
                  <p class="font-medium"><?= htmlspecialchars($b['destination']) ?></p>
                </div>
                <div>
                  <p class="text-xs mb-0.5" style="color:var(--ta-muted)">Berangkat</p>
                  <p class="font-medium"><?= htmlspecialchars(date('d M Y, h:i A', strtotime($b['depart_datetime']))) ?></p>
                </div>
                <div>
                  <p class="text-xs mb-0.5" style="color:var(--ta-muted)">Pulang</p>
                  <p class="font-medium"><?= $b['return_datetime'] ? htmlspecialchars(date('d M Y, h:i A', strtotime($b['return_datetime']))) : '—' ?></p>
                </div>
                <div>
                  <p class="text-xs mb-0.5" style="color:var(--ta-muted)">Tujuan</p>
                  <p class="font-medium"><?= nl2br(htmlspecialchars($b['purpose'])) ?></p>
                </div>
              </div>
            </div>

            <!-- Kad: Sejarah Tindakan -->
            <?php if (!empty($history)): ?>
            <div class="card p-5 sm:p-6">
              <div class="flex items-center gap-2 mb-4">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" style="color:var(--ta-brand)" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                <h6 class="font-semibold">Sejarah Tindakan</h6>
              </div>

              <div class="flex flex-col">
                <?php foreach ($history as $i => $h): ?>
                  <div class="flex gap-3 <?= $i < count($history) - 1 ? 'pb-4' : '' ?>">
                    <div class="flex flex-col items-center">
                      <span class="w-2.5 h-2.5 rounded-full shrink-0 mt-1" style="background:var(--ta-brand)"></span>
                      <?php if ($i < count($history) - 1): ?>
                        <span class="w-px flex-1 mt-1" style="background:var(--ta-border)"></span>
                      <?php endif; ?>
                    </div>
                    <div class="pb-1">
                      <p class="text-sm font-medium"><?= htmlspecialchars($h['action']) ?> — <span style="color:var(--ta-muted)"><?= htmlspecialchars($h['actor_name']) ?></span></p>
                      <?php if (!empty($h['remarks'])): ?>
                        <p class="text-sm mt-0.5" style="color:var(--ta-muted)"><?= htmlspecialchars($h['remarks']) ?></p>
                      <?php endif; ?>
                      <p class="text-xs mt-0.5" style="color:var(--ta-muted)"><?= htmlspecialchars(date('d M Y, h:i A', strtotime($h['action_datetime']))) ?></p>
                    </div>
                  </div>
                <?php endforeach; ?>
              </div>
            </div>
            <?php endif; ?>

          </div>
        </div>

    </div>
</main>

<?php
include 'includes/layout_footer.php';
?>
