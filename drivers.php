<?php
// drivers.php — Pengurusan Pemandu
require_once __DIR__ . '/includes/auth.php';
require_login();
require_once __DIR__ . '/config/database.php';


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

$driverStatusBadge = fn(string $s) => match ($s) {
    'Available' => 'badge badge-success',
    'Leave'     => 'badge badge-warning',
    'Inactive'  => 'badge badge-ghost',
    default     => 'badge badge-ghost',
};
$driverStatusLabel = fn(string $s) => match ($s) {
    'Available' => 'Boleh Bertugas',
    'Leave'     => 'Cuti',
    'Inactive'  => 'Tidak Aktif',
    default     => $s,
};

/* ==========================================================
   Tindakan Borang (Tambah / Kemaskini / Padam)
   ========================================================== */
$flash = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        http_response_code(403);
        exit('Ralat Keselamatan: Token CSRF tidak sah atau telah tamat tempoh.');
    }
    if (!$canManage) {
        $_SESSION['flash'] = ['type' => 'error', 'msg' => 'Anda tidak mempunyai kebenaran untuk tindakan ini.'];
        header("Location: drivers.php");
        exit();
    }

    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'add_driver') {
            $uid     = (int)($_POST['user_id'] ?? 0);
            $license = trim($_POST['license'] ?? '');
            if ($license === '' || strlen($license) > 50) {
                throw new RuntimeException('Sila isikan nombor lesen yang sah (maksimum 50 aksara).');
            }
            $status  = $_POST['status'] ?? 'Available';

            if ($uid <= 0) {
                throw new RuntimeException('Sila pilih pengguna yang sah.');
            }
            if (!in_array($status, ['Available', 'Leave', 'Inactive'], true)) {
                throw new RuntimeException('Status tidak sah.');
            }

            $stmt = $pdo->prepare("INSERT INTO drivers (user_id, license, status) VALUES (?, ?, ?)");
            $stmt->execute([$uid, $license, $status]);

            $flash = ['type' => 'success', 'msg' => 'Pemandu baharu berjaya didaftarkan.'];

        } elseif ($action === 'edit_driver') {
            $did     = (int)($_POST['driver_id'] ?? 0);
            $license = trim($_POST['license'] ?? '');
            if ($license === '' || strlen($license) > 50) {
                throw new RuntimeException('Sila isikan nombor lesen yang sah (maksimum 50 aksara).');
            }
            $status  = $_POST['status'] ?? 'Available';

            if ($did <= 0) {
                throw new RuntimeException('Pemandu tidak sah.');
            }
            if (!in_array($status, ['Available', 'Leave', 'Inactive'], true)) {
                throw new RuntimeException('Status tidak sah.');
            }

            $stmt = $pdo->prepare("UPDATE drivers SET license = ?, status = ? WHERE driver_id = ?");
            $stmt->execute([$license, $status, $did]);

            $flash = ['type' => 'success', 'msg' => 'Maklumat pemandu berjaya dikemaskini.'];

        } elseif ($action === 'delete_driver') {
            $did = (int)($_POST['driver_id'] ?? 0);
            if ($did <= 0) {
                throw new RuntimeException('Pemandu tidak sah.');
            }

            $del = $pdo->prepare("DELETE FROM drivers WHERE driver_id = ?");
            $del->execute([$did]);

            $flash = ['type' => 'success', 'msg' => 'Rekod pemandu berjaya dipadam.'];
        }
    } catch (RuntimeException $e) {
        $flash = ['type' => 'error', 'msg' => $e->getMessage()];
    } catch (PDOException $e) {
        if ($e->getCode() === '23000') {
            $flash = ['type' => 'error', 'msg' => 'Operasi gagal — nombor lesen mungkin telah wujud, pengguna ini sudah menjadi pemandu, atau rekod ini masih ditugaskan kepada kenderaan / tempahan.'];
        } else {
            $flash = ['type' => 'error', 'msg' => 'Ralat pangkalan data berlaku. Sila cuba lagi.'];
        }
    }

    $_SESSION['flash'] = $flash;
    header("Location: drivers.php" . (isset($_GET['q']) && $_GET['q'] !== '' ? '?q=' . urlencode($_GET['q']) : ''));
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
$statusFilter = $_GET['status'] ?? '';
$vehicleFilter = max(0, (int)($_GET['vehicle_id'] ?? 0));
if (!in_array($statusFilter, ['Available', 'Leave', 'Inactive'], true)) $statusFilter = '';
$perPage = 10;
$page    = max(1, (int)($_GET['page'] ?? 1));
$offset  = ($page - 1) * $perPage;

function buildDriversPageUrl(int $p, string $search): string {
    $params = ['page' => $p];
    if ($search !== '') {
        $params['q'] = $search;
    }
    if ($GLOBALS['statusFilter'] !== '') $params['status'] = $GLOBALS['statusFilter'];
    if ($GLOBALS['vehicleFilter'] > 0) $params['vehicle_id'] = $GLOBALS['vehicleFilter'];
    return 'drivers.php?' . http_build_query($params);
}

$baseFrom = "FROM drivers dr
             JOIN users u ON u.user_id = dr.user_id
             LEFT JOIN vehicles v ON v.driver_id = dr.driver_id";

$where = [];
$params = [];
if ($search !== '') { $where[] = '(u.fullname LIKE :like1 OR dr.license LIKE :like2)'; $like = "%{$search}%"; $params[':like1'] = $like; $params[':like2'] = $like; }
if ($statusFilter !== '') { $where[] = 'dr.status = :status'; $params[':status'] = $statusFilter; }
if ($vehicleFilter > 0) { $where[] = 'v.vehicle_id = :vehicle_id'; $params[':vehicle_id'] = $vehicleFilter; }
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';
$countStmt = $pdo->prepare("SELECT COUNT(DISTINCT dr.driver_id) $baseFrom $whereSql");
foreach ($params as $key => $value) $countStmt->bindValue($key, $value);
$countStmt->execute();
$totalRows = (int)$countStmt->fetchColumn();
$stmt = $pdo->prepare(
  "SELECT dr.driver_id, dr.license, dr.status, u.user_id, u.fullname, u.email, u.phone_no, u.profile_picture,
      GROUP_CONCAT(DISTINCT v.plate_no SEPARATOR ', ') AS assigned_vehicles
   $baseFrom $whereSql
   GROUP BY dr.driver_id
   ORDER BY FIELD(dr.status, 'Available', 'Leave', 'Inactive'), u.fullname
   LIMIT :limit OFFSET :offset"
);
foreach ($params as $key => $value) $stmt->bindValue($key, $value);
$stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$drivers = $stmt->fetchAll(PDO::FETCH_ASSOC);

$totalPages = max(1, (int)ceil($totalRows / $perPage));
if ($page > $totalPages) {
    $page = $totalPages;
}

// Pengguna yang layak dijadikan pemandu (belum menjadi pemandu)
$eligibleUsers = $pdo->query(
    "SELECT u.user_id, u.fullname, u.email
     FROM users u
     LEFT JOIN drivers dr ON dr.user_id = u.user_id
     WHERE dr.driver_id IS NULL
     ORDER BY u.fullname"
)->fetchAll(PDO::FETCH_ASSOC);
$filterVehicles = $pdo->query("SELECT vehicle_id, plate_no FROM vehicles ORDER BY plate_no")->fetchAll(PDO::FETCH_ASSOC);

$statusCounts   = $pdo->query("SELECT status, COUNT(*) AS total FROM drivers GROUP BY status")
    ->fetchAll(PDO::FETCH_KEY_PAIR);
$totalDrivers   = (int)array_sum($statusCounts);
$availableCount = (int)($statusCounts['Available'] ?? 0);
$leaveCount     = (int)($statusCounts['Leave'] ?? 0);
$inactiveCount  = (int)($statusCounts['Inactive'] ?? 0);

$pendingApprovals = $pdo->query(
    "SELECT COUNT(*) FROM vehicle_bookings WHERE status = 'Pending'"
)->fetchColumn();

// --- Layout Config ---
$pageTitle = "Pemandu";
$showSearch = true;
$searchAction = "drivers.php";
$searchPlaceholder = "Cari nama pemandu atau lesen...";
$extraJS = '
    <script>
        function openAddModal() {
            document.getElementById("modal-add").showModal();
        }

        function openEditModal(d) {
            document.getElementById("edit-driver-id").value   = d.driver_id;
            document.getElementById("edit-driver-name").textContent = d.fullname;
            document.getElementById("edit-license").value     = d.license;
            document.getElementById("edit-status").value      = d.status;
            document.getElementById("modal-edit").showModal();
        }

        function openDeleteModal(id, name) {
            document.getElementById("delete-driver-id").value = id;
            document.getElementById("delete-driver-name").textContent = name;
            document.getElementById("modal-delete").showModal();
        }

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
        <div class="grid grid-cols-2 sm:grid-cols-3 xl:grid-cols-4 gap-5">
          <div class="card p-5" data-href="drivers.php">
            <div class="ta-icon-box ta-icon-box-blue mb-4">
              <svg xmlns="http://www.w3.org/2000/svg" class="h-5.5 w-5.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 8.25h19.5M2.25 9h19.5m-16.5 5.25h6m-6 2.25h3m-3.75 3h15a2.25 2.25 0 002.25-2.25V6.75A2.25 2.25 0 0019.5 4.5h-15a2.25 2.25 0 00-2.25 2.25v10.5A2.25 2.25 0 004.5 19.5z" /></svg>
            </div>
            <p class="text-sm mb-1" style="color:var(--ta-muted)">Jumlah Pemandu</p>
            <h5 class="text-2xl font-bold"><?= $totalDrivers ?></h5>
          </div>

          <div class="card p-5" data-href="drivers.php?status=Available">
            <div class="ta-icon-box ta-icon-box-green mb-4">
              <svg xmlns="http://www.w3.org/2000/svg" class="h-5.5 w-5.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
            </div>
            <p class="text-sm mb-1" style="color:var(--ta-muted)">Boleh Bertugas</p>
            <h5 class="text-2xl font-bold"><?= $availableCount ?></h5>
          </div>

          <div class="card p-5" data-href="drivers.php?status=Leave">
            <div class="ta-icon-box ta-icon-box-purple mb-4">
              <svg xmlns="http://www.w3.org/2000/svg" class="h-5.5 w-5.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
            </div>
            <p class="text-sm mb-1" style="color:var(--ta-muted)">Cuti</p>
            <h5 class="text-2xl font-bold"><?= $leaveCount ?></h5>
          </div>

          <div class="card p-5" data-href="drivers.php?status=Inactive">
            <div class="ta-icon-box ta-icon-box-orange mb-4">
              <svg xmlns="http://www.w3.org/2000/svg" class="h-5.5 w-5.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6"><path stroke-linecap="round" stroke-linejoin="round" d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636" /></svg>
            </div>
            <p class="text-sm mb-1" style="color:var(--ta-muted)">Tidak Aktif</p>
            <h5 class="text-2xl font-bold"><?= $inactiveCount ?></h5>
          </div>
        </div>

        <?php $hasDriverFilters = $statusFilter !== '' || $vehicleFilter > 0; ?>
        <div class="card p-5 mt-5">
          <details class="rounded-xl border" style="border-color:var(--ta-border)" <?= $hasDriverFilters ? 'open' : '' ?>>
            <summary class="cursor-pointer list-none px-4 py-3 text-sm font-semibold flex items-center justify-between gap-3"><span class="flex items-center gap-2"><svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M6.75 12h10.5m-7.5 5.25h4.5" /></svg>Penapis Lanjutan<?= $hasDriverFilters ? ' <span class="ta-badge badge badge-info">Aktif</span>' : '' ?></span><span class="text-xs text-slate-400">Status &amp; kenderaan ditugaskan</span></summary>
            <form action="drivers.php" method="GET" class="grid grid-cols-1 sm:grid-cols-3 gap-3 px-4 pb-4">
              <input type="hidden" name="q" value="<?= htmlspecialchars($search) ?>" />
              <div><label class="text-xs font-medium block mb-1">Status</label><select name="status" class="select select-bordered select-sm w-full"><option value="">Semua status</option><option value="Available" <?= $statusFilter === 'Available' ? 'selected' : '' ?>>Boleh Bertugas</option><option value="Leave" <?= $statusFilter === 'Leave' ? 'selected' : '' ?>>Cuti</option><option value="Inactive" <?= $statusFilter === 'Inactive' ? 'selected' : '' ?>>Tidak Aktif</option></select></div>
              <div><label class="text-xs font-medium block mb-1">Kenderaan</label><select name="vehicle_id" class="select select-bordered select-sm w-full"><option value="0">Semua kenderaan</option><?php foreach ($filterVehicles as $filterVehicle): ?><option value="<?= (int)$filterVehicle['vehicle_id'] ?>" <?= $vehicleFilter === (int)$filterVehicle['vehicle_id'] ? 'selected' : '' ?>><?= htmlspecialchars($filterVehicle['plate_no']) ?></option><?php endforeach; ?></select></div>
              <div class="flex items-end gap-2"><button type="submit" class="btn btn-sm text-white border-0" style="background:var(--ta-brand)">Tapis</button><?php if ($hasDriverFilters): ?><a href="drivers.php<?= $search !== '' ? '?q=' . urlencode($search) : '' ?>" class="btn btn-sm btn-ghost">Set Semula</a><?php endif; ?></div>
            </form>
          </details>
        </div>

        <!-- Baris 2: Jadual Pemandu -->
        <div class="card p-5 mt-5">
          <div class="flex items-center justify-between gap-3 mb-4 flex-wrap">
            <div>
              <h6 class="font-semibold">Senarai Pemandu</h6>
              <p class="mb-0 text-sm" style="color:var(--ta-muted)">Urus status ketersediaan &amp; maklumat lesen pemandu</p>
            </div>
            <?php if ($canManage): ?>
            <button type="button" class="btn btn-sm text-white border-0 gap-1.5" style="background:var(--ta-brand)" onclick="openAddModal()">
              <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" /></svg>
              Tambah Pemandu
            </button>
            <?php endif; ?>
          </div>

          <div class="overflow-x-auto -mx-1">
            <table class="items-center w-full mb-0 align-top">
              <thead>
                <tr class="border-b" style="border-color:var(--ta-border)">
                  <th class="px-3 py-2 text-xs font-semibold text-left uppercase text-slate-400">Pemandu</th>
                  <th class="px-3 py-2 text-xs font-semibold text-left uppercase text-slate-400">Lesen Memandu</th>
                  <th class="px-3 py-2 text-xs font-semibold text-left uppercase text-slate-400">Kenderaan Ditugaskan</th>
                  <th class="px-3 py-2 text-xs font-semibold text-center uppercase text-slate-400">Status</th>
                  <?php if ($canManage): ?>
                  <th class="px-3 py-2 text-xs font-semibold text-center uppercase text-slate-400">Tindakan</th>
                  <?php endif; ?>
                </tr>
              </thead>
              <tbody>
                <?php if (empty($drivers)): ?>
                  <tr><td colspan="<?= $canManage ? 5 : 4 ?>" class="px-3 py-6 text-sm text-center text-slate-400">Tiada pemandu dijumpai.</td></tr>
                <?php endif; ?>
                <?php foreach ($drivers as $d): ?>
                  <tr class="hover:bg-slate-50/70 transition-colors" data-href="view-driver.php?id=<?= (int)$d['driver_id'] ?>">
                    <td class="px-3 py-3 text-sm border-b whitespace-nowrap" style="border-color:var(--ta-border)">
                      <div class="flex items-center gap-2.5">
                        <?php
                          $avatarPath = $d['profile_picture'] ?? null;
                          $avatarExists = $avatarPath && is_file(__DIR__ . '/' . $avatarPath);
                        ?>
                        <?php if ($avatarExists): ?>
                          <div class="rounded-full w-8 h-8 overflow-hidden shrink-0">
                            <img src="<?= htmlspecialchars($avatarPath) ?>?v=<?= time() ?>" alt="Avatar" class="w-full h-full object-cover" />
                          </div>
                        <?php else: ?>
                          <div class="rounded-full w-8 h-8 flex items-center justify-center font-bold text-xs uppercase text-white shrink-0" style="background:var(--ta-brand)">
                            <?= htmlspecialchars(substr($d['fullname'], 0, 1)) ?>
                          </div>
                        <?php endif; ?>
                        <div class="min-w-0">
                          <p class="mb-0 font-medium truncate"><?= htmlspecialchars($d['fullname']) ?></p>
                          <p class="mb-0 text-xs text-slate-400 truncate"><?= htmlspecialchars($d['email']) ?></p>
                        </div>
                      </div>
                    </td>
                    <td class="px-3 py-3 text-sm border-b whitespace-nowrap" style="border-color:var(--ta-border)"><?= htmlspecialchars($d['license']) ?></td>
                    <td class="px-3 py-3 text-sm border-b whitespace-nowrap" style="border-color:var(--ta-border)"><?= htmlspecialchars($d['assigned_vehicles'] ?? '') ?: '—' ?></td>
                    <td class="px-3 py-3 text-sm border-b text-center whitespace-nowrap" style="border-color:var(--ta-border)">
                      <span class="ta-badge <?= $driverStatusBadge($d['status']) ?>"><?= htmlspecialchars($driverStatusLabel($d['status'])) ?></span>
                    </td>
                    <?php if ($canManage): ?>
                    <td class="px-3 py-3 text-sm border-b text-center whitespace-nowrap" style="border-color:var(--ta-border)">
                      <a href="view-driver.php?id=<?= (int)$d['driver_id'] ?>&amp;mode=edit" class="btn btn-ghost btn-xs" title="Kemaskini">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931z" /></svg>
                      </a>
                      <button type="button" class="btn btn-ghost btn-xs text-error" title="Padam"
                        onclick="openDeleteModal(<?= (int)$d['driver_id'] ?>, '<?= htmlspecialchars(addslashes($d['fullname'])) ?>')">
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
              Menunjukkan <?= $offset + 1 ?>–<?= min($offset + $perPage, $totalRows) ?> daripada <?= $totalRows ?> pemandu
            </p>
            <div class="join">
              <a href="<?= $page > 1 ? htmlspecialchars(buildDriversPageUrl($page - 1, $search)) : '#' ?>"
                 class="join-item btn btn-sm <?= $page <= 1 ? 'btn-disabled opacity-40' : '' ?>">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.2"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5L8.25 12l7.5-7.5" /></svg>
              </a>
              <span class="join-item btn btn-sm btn-disabled !bg-transparent !border-none font-semibold" style="color:var(--ta-ink)">
                <?= $page ?> / <?= $totalPages ?>
              </span>
              <a href="<?= $page < $totalPages ? htmlspecialchars(buildDriversPageUrl($page + 1, $search)) : '#' ?>"
                 class="join-item btn btn-sm <?= $page >= $totalPages ? 'btn-disabled opacity-40' : '' ?>">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.2"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5" /></svg>
              </a>
            </div>
          </div>
          <?php endif; ?>
        </div>

        <?php if ($canManage): ?>
        <!-- Modal: Tambah Pemandu -->
        <dialog id="modal-add" class="modal">
          <div class="modal-box card max-w-md">
            <form method="dialog"><button class="btn btn-sm btn-circle btn-ghost absolute right-3 top-3">✕</button></form>
            <h3 class="font-bold text-lg mb-4">Tambah Pemandu Baharu</h3>
            <?php if (empty($eligibleUsers)): ?>
              <p class="text-sm text-slate-400">Semua pengguna berdaftar telah menjadi pemandu. Daftarkan pengguna baharu di halaman Pengguna terlebih dahulu.</p>
              <div class="modal-action mt-2">
                <button type="button" class="btn btn-ghost" onclick="document.getElementById('modal-add').close()">Tutup</button>
              </div>
            <?php else: ?>
            <form action="drivers.php<?= $search !== '' ? '?q=' . urlencode($search) : '' ?>" method="POST" class="flex flex-col gap-3">
              <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>" />
              <input type="hidden" name="action" value="add_driver" />
              <div>
                <label class="text-xs font-medium block mb-1">Pengguna</label>
                <select name="user_id" required class="select select-bordered w-full">
                  <option value="">— Pilih Pengguna —</option>
                  <?php foreach ($eligibleUsers as $eu): ?>
                    <option value="<?= (int)$eu['user_id'] ?>"><?= htmlspecialchars($eu['fullname']) ?> — <?= htmlspecialchars($eu['email']) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div>
                <label class="text-xs font-medium block mb-1">Nombor Lesen Memandu</label>
                <input type="text" name="license" required class="input input-bordered w-full" />
              </div>
              <div>
                <label class="text-xs font-medium block mb-1">Status</label>
                <select name="status" class="select select-bordered w-full">
                  <option value="Available">Boleh Bertugas</option>
                  <option value="Leave">Cuti</option>
                  <option value="Inactive">Tidak Aktif</option>
                </select>
              </div>
              <div class="modal-action mt-2">
                <button type="button" class="btn btn-ghost" onclick="document.getElementById('modal-add').close()">Batal</button>
                <button type="submit" class="btn text-white border-0" style="background:var(--ta-brand)">Daftar Pemandu</button>
              </div>
            </form>
            <?php endif; ?>
          </div>
          <form method="dialog" class="modal-backdrop"><button>close</button></form>
        </dialog>

        <!-- Modal: Kemaskini Pemandu -->
        <dialog id="modal-edit" class="modal">
          <div class="modal-box card max-w-md">
            <form method="dialog"><button class="btn btn-sm btn-circle btn-ghost absolute right-3 top-3">✕</button></form>
            <h3 class="font-bold text-lg mb-1">Kemaskini Pemandu</h3>
            <p class="text-sm text-slate-400 mb-4" id="edit-driver-name"></p>
            <form action="drivers.php<?= $search !== '' ? '?q=' . urlencode($search) : '' ?>" method="POST" class="flex flex-col gap-3">
              <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>" />
              <input type="hidden" name="action" value="edit_driver" />
              <input type="hidden" name="driver_id" id="edit-driver-id" />
              <div>
                <label class="text-xs font-medium block mb-1">Nombor Lesen Memandu</label>
                <input type="text" name="license" id="edit-license" required class="input input-bordered w-full" />
              </div>
              <div>
                <label class="text-xs font-medium block mb-1">Status</label>
                <select name="status" id="edit-status" class="select select-bordered w-full">
                  <option value="Available">Boleh Bertugas</option>
                  <option value="Leave">Cuti</option>
                  <option value="Inactive">Tidak Aktif</option>
                </select>
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
            <h3 class="font-bold text-lg mb-2">Padam Pemandu?</h3>
            <p class="text-sm text-slate-400 mb-4">Anda pasti mahu memadam rekod pemandu <span id="delete-driver-name" class="font-semibold text-slate-600"></span>? Tindakan ini tidak boleh diundur.</p>
            <form action="drivers.php<?= $search !== '' ? '?q=' . urlencode($search) : '' ?>" method="POST" class="flex justify-end gap-2">
              <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>" />
              <input type="hidden" name="action" value="delete_driver" />
              <input type="hidden" name="driver_id" id="delete-driver-id" />
              <button type="button" class="btn btn-ghost" onclick="document.getElementById('modal-delete').close()">Batal</button>
              <button type="submit" class="btn btn-error text-white border-0">Padam</button>
            </form>
          </div>
          <form method="dialog" class="modal-backdrop"><button>close</button></form>
        </dialog>
        <?php endif; ?>

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

    </div>
</main>

<?php
$extraJS = '
    <script>
        function openAddModal() {
            document.getElementById("modal-add").showModal();
        }

        function openEditModal(d) {
            document.getElementById("edit-driver-id").value   = d.driver_id;
            document.getElementById("edit-driver-name").textContent = d.fullname;
            document.getElementById("edit-license").value     = d.license;
            document.getElementById("edit-status").value      = d.status;
            document.getElementById("modal-edit").showModal();
        }

        function openDeleteModal(id, name) {
            document.getElementById("delete-driver-id").value = id;
            document.getElementById("delete-driver-name").textContent = name;
            document.getElementById("modal-delete").showModal();
        }

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
include 'includes/layout_footer.php';
?>
