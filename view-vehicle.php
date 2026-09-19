<?php
// view-vehicle.php - Add, view and edit vehicle details
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/vehicle_documents.php';

require_login();

$fullname = $_SESSION['fullname'] ?? '';
$role = $_SESSION['role'] ?? 'User';
$currentUserId = (int)($_SESSION['user_id'] ?? 0);
$canManage = in_array($role, ['SuperAdmin', 'Admin'], true);
$mode = $_GET['mode'] ?? 'view';
if (!in_array($mode, ['add', 'view', 'edit'], true)) {
    $mode = 'view';
}

if (in_array($mode, ['add', 'edit'], true) && !$canManage) {
    $_SESSION['flash'] = ['type' => 'error', 'msg' => 'Anda tidak mempunyai kebenaran untuk menambah kenderaan.'];
    header('Location: vehicles.php');
    exit();
}

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
$vehicleStatusBadge = fn(string $status) => match ($status) {
    'Available' => 'badge badge-success',
    'Booked' => 'badge badge-info',
    'Maintenance' => 'badge badge-warning',
    'Inactive' => 'badge badge-ghost',
    default => 'badge badge-ghost',
};
$vehicleStatusLabel = fn(string $status) => match ($status) {
    'Available' => 'Sedia Ada',
    'Booked' => 'Sedang Digunakan',
    'Maintenance' => 'Penyelenggaraan',
    'Inactive' => 'Tidak Aktif',
    default => $status,
};

$vehicleId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
$newRoadTaxAbsolutePath = null;

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
            http_response_code(403);
            exit('Ralat Keselamatan: Token CSRF tidak sah atau telah tamat tempoh.');
        }
        if (!$canManage) {
            throw new RuntimeException('Anda tidak mempunyai kebenaran untuk tindakan ini.');
        }

        $action = $_POST['action'] ?? '';
        if (!in_array($action, ['add_vehicle', 'edit_vehicle', 'delete_road_tax'], true)) {
            throw new RuntimeException('Tindakan tidak sah.');
        }

        if ($action === 'delete_road_tax') {
            $vehicleId = filter_input(INPUT_POST, 'vehicle_id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
            if ($vehicleId <= 0) {
                throw new RuntimeException('Kenderaan tidak sah.');
            }
            $stmt = $pdo->prepare('SELECT road_tax_document FROM vehicles WHERE vehicle_id = ?');
            $stmt->execute([$vehicleId]);
            $oldPath = $stmt->fetchColumn();
            if ($oldPath === false) {
                throw new RuntimeException('Kenderaan tidak dijumpai.');
            }
            $pdo->prepare('UPDATE vehicles SET road_tax_document = NULL WHERE vehicle_id = ?')->execute([$vehicleId]);
            $oldAbsolutePath = is_string($oldPath) ? roadTaxAbsolutePath($oldPath) : null;
            if ($oldAbsolutePath && is_file($oldAbsolutePath)) {
                if (!unlink($oldAbsolutePath)) {
                  error_log("Gagal memadam fail Road Tax: " . $oldAbsolutePath);
                }
            }
            $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Dokumen telah dibuang.'];
            header('Location: view-vehicle.php?id=' . $vehicleId . '&mode=edit');
            exit();
        }

        $postedVehicleId = filter_input(INPUT_POST, 'vehicle_id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
        if ($action === 'edit_vehicle' && $postedVehicleId <= 0) {
            throw new RuntimeException('Kenderaan tidak sah.');
        }

        $plateNo = trim($_POST['plate_no'] ?? '');
        if ($plateNo === '' || strlen($plateNo) > 20) {
            throw new RuntimeException('Nombor plat kenderaan wajib diisi dan tidak boleh melebihi 20 aksara.');
        }
        $vehicleName = trim($_POST['vehicle_name'] ?? '') ?: null;
        if ($vehicleName && strlen($vehicleName) > 100) {
            throw new RuntimeException('Nama kenderaan tidak boleh melebihi 100 aksara.');
        }
        $vehicleType = trim($_POST['vehicle_type'] ?? '') ?: null;
        if ($vehicleType && strlen($vehicleType) > 100) {
            throw new RuntimeException('Jenis kenderaan tidak boleh melebihi 100 aksara.');
        }
        $capacityRaw = trim($_POST['capacity'] ?? '');
        $capacity = $capacityRaw !== '' ? (int)$capacityRaw : null;
        if ($capacity !== null && $capacity < 1) {
            throw new RuntimeException('Kapasiti penumpang tidak sah.');
        }
        $roadTax = trim($_POST['road_tax_expiry'] ?? '') ?: null;
        if ($roadTax && !DateTime::createFromFormat('Y-m-d', $roadTax)) {
            throw new RuntimeException('Format tarikh cukai jalan tidak sah (Sila guna YYYY-MM-DD).');
        }
        $status = $_POST['status'] ?? 'Available';
        $description = trim($_POST['description'] ?? '') ?: null;
        if ($description && strlen($description) > 500) {
            throw new RuntimeException('Catatan tidak boleh melebihi 500 aksara.');
        }
        $driverRaw = trim($_POST['driver_id'] ?? '');
        $driverId = $driverRaw !== '' ? (int)$driverRaw : null;

        if (!in_array($status, ['Available', 'Booked', 'Maintenance', 'Inactive'], true)) {
            throw new RuntimeException('Status tidak sah.');
        }

        $newRoadTaxPath = null;
        if (isset($_FILES['road_tax_document']) && $_FILES['road_tax_document']['error'] !== UPLOAD_ERR_NO_FILE) {
            [$newFilename, $newRoadTaxAbsolutePath] = validateRoadTaxUpload(
                $_FILES['road_tax_document'], $roadTaxUploadDir, $roadTaxAllowedTypes
            );
            $newRoadTaxPath = $roadTaxUploadUrl . '/' . $newFilename;
        }

        if ($action === 'add_vehicle') {
            $stmt = $pdo->prepare(
                'INSERT INTO vehicles
                    (plate_no, vehicle_name, vehicle_type, capacity, road_tax_expiry,
                     road_tax_document, status, description, driver_id)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([$plateNo, $vehicleName, $vehicleType, $capacity, $roadTax,
                $newRoadTaxPath, $status, $description, $driverId]);
            $vehicleId = (int)$pdo->lastInsertId();
            $_SESSION['flash'] = ['type' => 'success', 'msg' => "Kenderaan '{$plateNo}' berjaya didaftarkan."];
        } else {
            $stmt = $pdo->prepare('SELECT road_tax_document FROM vehicles WHERE vehicle_id = ?');
            $stmt->execute([$postedVehicleId]);
            $oldPath = $stmt->fetchColumn();
            if ($oldPath === false) {
                throw new RuntimeException('Kenderaan tidak dijumpai.');
            }

            $stmt = $pdo->prepare(
                'UPDATE vehicles SET plate_no = ?, vehicle_name = ?, vehicle_type = ?, capacity = ?,
                    road_tax_expiry = ?, road_tax_document = COALESCE(?, road_tax_document),
                    status = ?, description = ?, driver_id = ?
                 WHERE vehicle_id = ?'
            );
            $stmt->execute([$plateNo, $vehicleName, $vehicleType, $capacity, $roadTax,
                $newRoadTaxPath, $status, $description, $driverId, $postedVehicleId]);
            $vehicleId = $postedVehicleId;
            if ($newRoadTaxPath && is_string($oldPath)) {
                $oldAbsolutePath = roadTaxAbsolutePath($oldPath);
                if ($oldAbsolutePath && is_file($oldAbsolutePath)) {
                    if (!unlink($oldAbsolutePath)) {
                      error_log("Gagal memadam fail Road Tax: " . $oldAbsolutePath);
                    }
                }
            }
            $_SESSION['flash'] = ['type' => 'success', 'msg' => "Maklumat '{$plateNo}' berjaya dikemaskini."];
        }

        header('Location: view-vehicle.php?id=' . $vehicleId . '&mode=view');
        exit();
    }

    if ($mode !== 'add') {
        if ($vehicleId <= 0) {
            throw new RuntimeException('Kenderaan tidak sah.');
        }
        $stmt = $pdo->prepare(
            'SELECT v.*, u.fullname AS driver_name, dr.status AS driver_status
             FROM vehicles v
             LEFT JOIN drivers dr ON dr.driver_id = v.driver_id
             LEFT JOIN users u ON u.user_id = dr.user_id
             WHERE v.vehicle_id = ?'
        );
        $stmt->execute([$vehicleId]);
        $vehicle = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$vehicle) {
            throw new RuntimeException('Kenderaan tidak dijumpai.');
        }
    } else {
        $vehicle = [
            'vehicle_id' => 0, 'plate_no' => '', 'vehicle_name' => '', 'vehicle_type' => '',
            'capacity' => '', 'road_tax_expiry' => '', 'road_tax_document' => null,
            'status' => 'Available', 'description' => '', 'driver_id' => null,
            'driver_name' => null, 'driver_status' => null,
        ];
    }
} catch (RuntimeException $e) {
    if ($newRoadTaxAbsolutePath && is_file($newRoadTaxAbsolutePath)) {
        if (!unlink($newRoadTaxAbsolutePath)) {
          error_log("Gagal memadam fail Road Tax (temporary): " . $newRoadTaxAbsolutePath);
        }
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $_SESSION['flash'] = ['type' => 'error', 'msg' => $e->getMessage()];
        $target = $vehicleId > 0 ? 'view-vehicle.php?id=' . $vehicleId . '&mode=edit' : 'view-vehicle.php?mode=add';
        header('Location: ' . $target);
        exit();
    }
    $_SESSION['flash'] = ['type' => 'error', 'msg' => $e->getMessage()];
    header('Location: vehicles.php');
    exit();
} catch (PDOException $e) {
    if ($newRoadTaxAbsolutePath && is_file($newRoadTaxAbsolutePath)) {
        if (!unlink($newRoadTaxAbsolutePath)) {
          error_log("Gagal memadam fail Road Tax (temporary): " . $newRoadTaxAbsolutePath);
        }
    }
    $_SESSION['flash'] = ['type' => 'error', 'msg' => $e->getCode() === '23000'
        ? 'Operasi gagal — nombor plat mungkin telah wujud.'
        : 'Ralat pangkalan data berlaku. Sila cuba lagi.'];
    header('Location: ' . ($vehicleId > 0 ? 'view-vehicle.php?id=' . $vehicleId . '&mode=edit' : 'view-vehicle.php?mode=add'));
    exit();
}

$driverOptions = $pdo->query(
    'SELECT dr.driver_id, u.fullname, dr.status
     FROM drivers dr JOIN users u ON u.user_id = dr.user_id
     ORDER BY u.fullname'
)->fetchAll(PDO::FETCH_ASSOC);
$pendingApprovals = $pdo->query("SELECT COUNT(*) FROM vehicle_bookings WHERE status = 'Pending'")->fetchColumn();
$isView = $mode === 'view';
$pageTitle = $mode === 'add' ? 'Tambah Kenderaan' : ($isView ? 'Butiran Kenderaan' : 'Kemaskini Kenderaan');
$backUrl = 'vehicles.php';
$breadcrumbs = [
    ['url' => 'dashboard.php', 'label' => 'Halaman'],
    ['url' => 'vehicles.php', 'label' => 'Kenderaan'],
    ['url' => '#', 'label' => $pageTitle],
];
$roadTaxDate = !empty($vehicle['road_tax_expiry']) ? new DateTime($vehicle['road_tax_expiry']) : null;
$daysRemaining = $roadTaxDate ? (int)(new DateTime())->diff($roadTaxDate)->format('%r%a') : null;
$roadTaxAbsolutePath = !empty($vehicle['road_tax_document']) ? roadTaxAbsolutePath($vehicle['road_tax_document']) : null;
$showSearch = false;

include 'includes/layout_header.php';
?>
<main class="xl:ml-64 relative min-h-screen pb-24 xl:pb-0">
    <?php include 'includes/top_nav.php'; ?>
    <div class="w-full px-4 sm:px-6 py-6 mx-auto max-w-5xl">

      <?php if ($isView): ?>
        <div class="flex flex-col gap-5">

          <!-- Kad: Maklumat Kenderaan -->
          <div class="card p-5 sm:p-6">
            <div class="flex items-start justify-between gap-3 mb-5 pb-5 border-b" style="border-color:var(--ta-border)">
              <div class="flex items-start gap-3">
                <div class="p-2 rounded-lg shrink-0" style="background:var(--ta-brand-50); color:var(--ta-brand)">
                  <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 18.75a1.5 1.5 0 01-3 0m3 0a1.5 1.5 0 00-3 0m3 0h6m-9 0H3.375a1.125 1.125 0 01-1.125-1.125V14.25m17.25 4.5a1.5 1.5 0 01-3 0m3 0a1.5 1.5 0 00-3 0m3 0h1.125c.621 0 1.129-.504 1.09-1.124a17.902 17.902 0 00-3.213-9.193 2.056 2.056 0 00-1.58-.86H14.25M16.5 18.75h-2.25m0-11.177v-.958c0-.568-.422-1.048-.987-1.106a48.554 48.554 0 00-10.026 0 1.106 1.106 0 00-.987 1.106v7.635m12-6.677v6.677m0 0h-12" /></svg>
                </div>
                <div>
                  <h6 class="font-semibold">Maklumat Kenderaan</h6>
                  <p class="text-xs" style="color:var(--ta-muted)">Butiran am dan status kenderaan.</p>
                </div>
              </div>
              <div class="flex items-center gap-2 shrink-0">
                <span class="ta-badge <?= $vehicleStatusBadge($vehicle['status']) ?>"><?= htmlspecialchars($vehicleStatusLabel($vehicle['status'])) ?></span>
                <a href="view-vehicle.php?id=<?= (int)$vehicleId ?>&amp;mode=edit" class="btn btn-sm btn-outline gap-1.5">
                  <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931z" /><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 13.5v6a2.25 2.25 0 01-2.25 2.25H6.75A2.25 2.25 0 014.5 19.5V8.25A2.25 2.25 0 016.75 6h6" /></svg>
                  Kemaskini
                </a>
              </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-5 text-sm">
              <div>
                <p class="text-xs mb-0.5" style="color:var(--ta-muted)">Nombor Plat</p>
                <p class="font-medium"><?= htmlspecialchars($vehicle['plate_no']) ?></p>
              </div>
              <div>
                <p class="text-xs mb-0.5" style="color:var(--ta-muted)">Nama Kenderaan</p>
                <p class="font-medium"><?= htmlspecialchars($vehicle['vehicle_name'] ?: '—') ?></p>
              </div>
              <div>
                <p class="text-xs mb-0.5" style="color:var(--ta-muted)">Jenis Kenderaan</p>
                <p class="font-medium"><?= htmlspecialchars($vehicle['vehicle_type'] ?: '—') ?></p>
              </div>
              <div>
                <p class="text-xs mb-0.5" style="color:var(--ta-muted)">Kapasiti Penumpang</p>
                <p class="font-medium"><?= $vehicle['capacity'] ? (int)$vehicle['capacity'] . ' penumpang' : '—' ?></p>
              </div>
              <div>
                <p class="text-xs mb-0.5" style="color:var(--ta-muted)">Pemandu Ditugaskan</p>
                <p class="font-medium"><?= htmlspecialchars($vehicle['driver_name'] ?: '—') ?></p>
              </div>
              <div class="sm:col-span-2">
                <p class="text-xs mb-0.5" style="color:var(--ta-muted)">Catatan</p>
                <p class="font-medium"><?= nl2br(htmlspecialchars($vehicle['description'] ?: '—')) ?></p>
              </div>
            </div>
          </div>

          <!-- Kad: Cukai Jalan -->
          <div class="card p-5 sm:p-6">
            <div class="flex items-center gap-2 mb-4">
              <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" style="color:var(--ta-brand)" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
              <h6 class="font-semibold">Cukai Jalan</h6>
            </div>

            <div class="flex flex-col gap-4 text-sm">
              <div>
                <p class="text-xs mb-0.5" style="color:var(--ta-muted)">Tamat Tempoh</p>
                <p class="font-medium">
                  <?= $roadTaxDate ? htmlspecialchars($roadTaxDate->format('d M Y')) : '—' ?>
                  <?php if ($daysRemaining !== null && $daysRemaining < 0): ?>
                    <span class="ta-badge badge badge-error ml-1">Tamat</span>
                  <?php elseif ($daysRemaining !== null && $daysRemaining <= 30): ?>
                    <span class="ta-badge badge badge-warning ml-1"><?= $daysRemaining ?> hari lagi</span>
                  <?php endif; ?>
                </p>
              </div>
              <div>
                <p class="text-xs mb-0.5" style="color:var(--ta-muted)">Dokumen</p>
                <?php if ($roadTaxAbsolutePath && is_file($roadTaxAbsolutePath)): ?>
                  <div class="mt-1 p-4 rounded-xl border flex items-center justify-between gap-3" style="border-color: var(--ta-border); background: var(--ta-canvas);">
                      <div class="flex items-center gap-3 min-w-0">
                          <div class="p-2.5 rounded-lg bg-error/10 text-error shrink-0">
                              <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                  <path stroke-linecap="round" stroke-linejoin="round" d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z" />
                              </svg>
                          </div>
                          <div class="min-w-0">
                              <p class="text-xs font-semibold truncate">Dokumen Cukai Jalan</p>
                              <p class="text-[11px] text-slate-400">Sijil cukai jalan kenderaan ini.</p>
                          </div>
                      </div>
                      <div class="flex items-center gap-1.5 shrink-0">
                          <a href="vehicles.php?road_tax=view&amp;vehicle_id=<?= (int)$vehicleId ?>" target="_blank" rel="noopener" class="btn btn-sm btn-outline btn-primary gap-1.5">
                              <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 010-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178z" /><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" /></svg>
                              Papar
                          </a>
                          <a href="vehicles.php?road_tax=download&amp;vehicle_id=<?= (int)$vehicleId ?>" class="btn btn-sm btn-outline gap-1.5">
                              <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5M16.5 12L12 16.5m0 0L7.5 12m4.5 4.5V3" /></svg>
                              Muat Turun
                          </a>
                      </div>
                  </div>
                <?php else: ?>
                  <p class="text-sm text-slate-400 mt-1">Tiada dokumen dimuat naik</p>
                <?php endif; ?>
              </div>
            </div>
          </div>
        </div>
      <?php else: ?>

      <div class="w-full px-4 sm:px-6 py-6 mx-auto max-w-3xl">

        <div class="card p-5 sm:p-7">
          <h6 class="font-semibold text-lg mb-1"><?= $mode === 'add' ? 'Daftar Kenderaan Baharu' : 'Kemaskini Maklumat Kenderaan' ?></h6>
          <p class="text-sm mb-5" style="color:var(--ta-muted)">Lengkapkan maklumat kenderaan di bawah.</p>

          <form method="POST" enctype="multipart/form-data" class="flex flex-col gap-5">
            <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
            <input type="hidden" name="action" value="<?= $mode === 'add' ? 'add_vehicle' : 'edit_vehicle' ?>" />
            <?php if ($mode === 'edit'): ?>
              <input type="hidden" name="vehicle_id" value="<?= (int)$vehicleId ?>" />
            <?php endif; ?>

            <!-- Butiran Kenderaan -->
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
              <div>
                <label class="text-xs font-medium block mb-1">Nombor Plat</label>
                <input type="text" name="plate_no" required autocomplete="off"
                       class="input input-bordered w-full" value="<?= htmlspecialchars($vehicle['plate_no']) ?>" />
              </div>
              <div>
                <label class="text-xs font-medium block mb-1">Nama Kenderaan</label>
                <input type="text" name="vehicle_name" autocomplete="off"
                       class="input input-bordered w-full" value="<?= htmlspecialchars($vehicle['vehicle_name'] ?? '') ?>" />
              </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
              <div>
                <label class="text-xs font-medium block mb-1">Jenis Kenderaan</label>
                <input type="text" name="vehicle_type" autocomplete="off"
                       class="input input-bordered w-full" value="<?= htmlspecialchars($vehicle['vehicle_type'] ?? '') ?>" />
              </div>
              <div>
                <label class="text-xs font-medium block mb-1">Kapasiti Penumpang</label>
                <input type="number" name="capacity" min="1" autocomplete="off"
                       class="input input-bordered w-full" value="<?= htmlspecialchars((string)($vehicle['capacity'] ?? '')) ?>" />
              </div>
            </div>

            <div class="border-t" style="border-color:var(--ta-border)"></div>

            <!-- Status & Pemandu -->
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
              <div>
                <label class="text-xs font-medium block mb-1">Status</label>
                <select name="status" class="select select-bordered w-full">
                  <?php foreach (['Available' => 'Sedia Ada', 'Booked' => 'Sedang Digunakan', 'Maintenance' => 'Penyelenggaraan', 'Inactive' => 'Tidak Aktif'] as $value => $label): ?>
                    <option value="<?= $value ?>" <?= $vehicle['status'] === $value ? 'selected' : '' ?>><?= $label ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div>
                <label class="text-xs font-medium block mb-1">Pemandu Ditugaskan</label>
                <input type="text" id="driver-combobox-input" list="driver-options" autocomplete="off"
                       class="input input-bordered w-full" placeholder="Cari pemandu..."
                       value="<?= htmlspecialchars($vehicle['driver_name'] ?? '') ?>" />
                <datalist id="driver-options">
                  <option data-value="" value="— Tiada —"></option>
                  <?php foreach ($driverOptions as $driver): ?>
                    <option data-value="<?= (int)$driver['driver_id'] ?>" value="<?= htmlspecialchars($driver['fullname']) ?>"></option>
                  <?php endforeach; ?>
                </datalist>
                <input type="hidden" name="driver_id" id="driver-hidden-input" value="<?= htmlspecialchars((string)($vehicle['driver_id'] ?? '')) ?>" />
              </div>
            </div>

            <div class="border-t" style="border-color:var(--ta-border)"></div>

            <!-- Cukai Jalan -->
            <div>
              <label class="text-xs font-medium block mb-1">Tamat Tempoh Cukai Jalan</label>
              <input type="text" name="road_tax_expiry" id="road-tax-date-input" autocomplete="off"
                     class="input input-bordered w-full" placeholder="Pilih tarikh"
                     value="<?= htmlspecialchars($vehicle['road_tax_expiry'] ?? '') ?>" />
            </div>

            <div>
              <label class="text-xs font-medium block mb-1">Dokumen</label>
              <input type="file" name="road_tax_document" accept=".pdf,.jpg,.jpeg,.png"
                     class="file-input w-full" />
              <span class="text-xs text-slate-400">PDF, JPG, JPEG atau PNG (maksimum 5MB)</span>
              <?php if (!empty($vehicle['road_tax_document'])): ?>
                <div class="mt-2 flex flex-wrap items-center gap-2 text-xs">
                    <a class="link link-primary" href="vehicles.php?road_tax=view&amp;vehicle_id=<?= (int)$vehicleId ?>" target="_blank" rel="noopener">Lihat Dokumen</a>
                    <button type="submit" form="remove-road-tax-form" class="link link-error">Padam Dokumen</button>
                </div>
              <?php endif; ?>
            </div>

            <div class="border-t" style="border-color:var(--ta-border)"></div>

            <div>
              <label class="text-xs font-medium block mb-1">Catatan</label>
              <textarea name="description" rows="4" class="textarea textarea-bordered w-full"><?= htmlspecialchars($vehicle['description'] ?? '') ?></textarea>
            </div>

            <div class="flex items-center justify-end gap-2 pt-2">
              <a href="<?= htmlspecialchars($backUrl) ?>" class="btn btn-ghost">Batal</a>
              <button type="submit" class="btn text-white border-0" style="background:var(--ta-brand)"><?= $mode === 'add' ? 'Daftar Kenderaan' : 'Simpan Perubahan' ?></button>
            </div>
          </form>

          <?php if ($mode === 'edit' && !empty($vehicle['road_tax_document'])): ?>
            <form id="remove-road-tax-form" method="POST" class="hidden">
              <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
              <input type="hidden" name="action" value="delete_road_tax" />
              <input type="hidden" name="vehicle_id" value="<?= (int)$vehicleId ?>" />
            </form>
          <?php endif; ?>
        </div>

      <?php endif; ?>
    </div>
</main>

<?php if (!$isView): ?>
<!-- Kalendar Cukai Jalan & Combobox Status/Pemandu -->
<script>
    document.addEventListener('DOMContentLoaded', () => {
        const roadTaxDateInput = document.getElementById('road-tax-date-input');
        if (roadTaxDateInput) {
            flatpickr(roadTaxDateInput, {
                dateFormat: 'Y-m-d',
                allowInput: true,
            });
        }

        // Combobox: sinkron input teks (label) dengan medan hidden (nilai sebenar)
        function initCombobox(inputId, hiddenId, datalistId) {
            const input = document.getElementById(inputId);
            const hidden = document.getElementById(hiddenId);
            const datalist = document.getElementById(datalistId);
            if (!input || !hidden || !datalist) return;

            function syncFromLabel() {
                const match = Array.from(datalist.options).find(
                    (opt) => opt.value.trim().toLowerCase() === input.value.trim().toLowerCase()
                );
                if (match) {
                    hidden.value = match.dataset.value ?? '';
                    input.classList.remove('input-error');
                } else if (input.value.trim() === '') {
                    hidden.value = '';
                    input.classList.remove('input-error');
                } else {
                    // Tiada padanan tepat lagi (mungkin masih menaip)
                    input.classList.add('input-error');
                }
            }

            input.addEventListener('input', syncFromLabel);
            input.addEventListener('change', syncFromLabel);
            input.addEventListener('blur', () => {
                // Jika tiada padanan selepas selesai menaip, kembalikan ke label terakhir yang sah
                const match = Array.from(datalist.options).find(
                    (opt) => opt.dataset.value === hidden.value
                );
                if (match) input.value = match.value;
                input.classList.remove('input-error');
            });
        }

        initCombobox('driver-combobox-input', 'driver-hidden-input', 'driver-options');
    });
</script>
<?php endif; ?>

<?php
include 'includes/layout_footer.php';
?>
