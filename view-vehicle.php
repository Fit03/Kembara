<?php
// view-vehicle.php - Add, view and edit vehicle details
session_start();
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/vehicle_documents.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

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
                @unlink($oldAbsolutePath);
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
        $vehicleName = trim($_POST['vehicle_name'] ?? '') ?: null;
        $vehicleType = trim($_POST['vehicle_type'] ?? '') ?: null;
        $capacityRaw = trim($_POST['capacity'] ?? '');
        $capacity = $capacityRaw !== '' ? (int)$capacityRaw : null;
        $roadTax = trim($_POST['road_tax_expiry'] ?? '') ?: null;
        $status = $_POST['status'] ?? 'Available';
        $description = trim($_POST['description'] ?? '') ?: null;
        $driverRaw = trim($_POST['driver_id'] ?? '');
        $driverId = $driverRaw !== '' ? (int)$driverRaw : null;

        if ($plateNo === '') {
            throw new RuntimeException('Nombor plat kenderaan wajib diisi.');
        }
        if ($capacity !== null && $capacity < 1) {
            throw new RuntimeException('Kapasiti penumpang tidak sah.');
        }
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
                    @unlink($oldAbsolutePath);
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
        @unlink($newRoadTaxAbsolutePath);
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
        @unlink($newRoadTaxAbsolutePath);
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
$roadTaxDate = !empty($vehicle['road_tax_expiry']) ? new DateTime($vehicle['road_tax_expiry']) : null;
$daysRemaining = $roadTaxDate ? (int)(new DateTime())->diff($roadTaxDate)->format('%r%a') : null;
$roadTaxAbsolutePath = !empty($vehicle['road_tax_document']) ? roadTaxAbsolutePath($vehicle['road_tax_document']) : null;
?>
<!DOCTYPE html>
<html lang="ms" data-theme="garden">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0, shrink-to-fit=no" />
  <title>Kembara - <?= htmlspecialchars($pageTitle) ?></title>
  <link rel="icon" type="image/png" href="assets/img/favicon.png" />
  <script>(function(){try{const s=localStorage.getItem('theme');const d=window.matchMedia&&window.matchMedia('(prefers-color-scheme: dark)').matches;document.documentElement.setAttribute('data-theme',s||(d?'dracula':'garden'));}catch(e){}})();</script>
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700&display=swap" rel="stylesheet" />
  <link href="https://cdn.jsdelivr.net/npm/daisyui@5" rel="stylesheet" type="text/css" />
  <link href="https://cdn.jsdelivr.net/npm/daisyui@5/themes.css" rel="stylesheet" type="text/css" />
  <script src="https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4"></script>

  <!-- Flatpickr (kalendar) -->
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
  <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>

  <link rel="stylesheet" href="assets/css/style.css" />
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
            <a class="ta-nav-link active" href="vehicles.php">
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
            <a class="ta-nav-link" href="activity_log.php">
              <svg class="ta-nav-icon" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
              <span>Log Aktiviti</span>
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

          <!-- "Lagi" — same look/behaviour as Tempahan, Kenderaan, Pemandu; opens a glass popover instead of the FAB flower -->
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
              <a href="activity_log.php" class="flex items-center gap-2.5 px-3 py-2.5 rounded-xl text-sm font-medium transition-colors" style="color: var(--ta-text, #1c1c1e)">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                Log Aktiviti
              </a>
              <?php endif; ?>
              <?php if ($role === 'User' || $role === 'Admin' || $role === 'SuperAdmin'): ?>
              <a href="profile.php" class="flex items-center gap-2.5 px-3 py-2.5 rounded-xl text-sm font-medium transition-colors" style="color: var(--ta-text, #1c1c1e)">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                  <circle cx="12" cy="8" r="4" stroke-linecap="round" stroke-linejoin="round"/>
                  <path stroke-linecap="round" stroke-linejoin="round" d="M4 20c0-4.418 3.582-8 8-8s8 3.582 8 8" />
                </svg>
                Profil Saya
              </a>
              <?php endif; ?>
            </div>
          </div>
        </div>
      </div>
    </div>

    <script>
      document.addEventListener('DOMContentLoaded', () => {
        const currentPath = window.location.pathname.split('/').pop() || 'dashboard.php';
        let activeItem = null;

        if (currentPath.includes('dashboard')) activeItem = document.getElementById('nav-dashboard');
        else if (currentPath.includes('bookings') || currentPath.includes('book-vehicle')) activeItem = document.getElementById('nav-bookings');
        else if (currentPath.includes('vehicles')) activeItem = document.getElementById('nav-vehicles');
        else if (currentPath.includes('drivers')) activeItem = document.getElementById('nav-drivers');
        else if (currentPath.includes('users') || currentPath.includes('activity_log')) activeItem = document.getElementById('nav-more');

        function setFloatingActive(element) {
          if (!element) return;

          document.querySelectorAll('.nav-item').forEach(el => {
            el.style.color = 'var(--ta-muted)';
            const svg = el.querySelector('svg');
            if (svg) svg.style.transform = 'translateY(0px)';
          });

          if (element.id === 'nav-dashboard') {
            const label = element.querySelector('span:last-child');
            if (label) label.style.color = 'var(--ta-brand, #007AFF)';
            return;
          }

          element.style.color = 'var(--ta-brand, #007AFF)';
          const svg = element.querySelector('svg');
          if (svg) svg.style.transform = 'translateY(-2px)';
        }

        if (activeItem) setFloatingActive(activeItem);
        window.addEventListener('resize', () => { if (activeItem) setFloatingActive(activeItem); });

        // "Lagi" popover — open/close (alternative to the old FAB flower)
        const moreBtn = document.getElementById('nav-more');
        const moreMenu = document.getElementById('more-menu');
        if (moreBtn && moreMenu) {
          const toggleMenu = (open) => {
            const isOpen = open !== undefined ? open : !moreMenu.classList.contains('open');
            moreMenu.classList.toggle('open', isOpen);
            moreBtn.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
          };
          moreBtn.addEventListener('click', (e) => {
            e.stopPropagation();
            toggleMenu();
          });
          moreBtn.addEventListener('keydown', (e) => {
            if (e.key === 'Enter' || e.key === ' ') {
              e.preventDefault();
              toggleMenu();
            } else if (e.key === 'Escape') {
              toggleMenu(false);
            }
          });
          document.addEventListener('click', (e) => {
            if (!moreBtn.contains(e.target)) toggleMenu(false);
          });
        }
      });
    </script>

  <main class="xl:ml-64 relative min-h-screen pb-24 xl:pb-0">
      <nav class="sticky top-0 z-50 flex items-center justify-between gap-4 px-4 sm:px-6 py-3 bg-white border-b" style="border-color:var(--ta-border)">
        <div class="flex items-center gap-3">
            <a href="<?= htmlspecialchars($backUrl) ?>" class="btn btn-ghost btn-circle btn-sm h-9 w-9 min-h-0" title="Kembali">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-4.5 w-4.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M10.5 19.5L3 12m0 0l7.5-7.5M3 12h18" /></svg>
            </a>
            <div>
                <h6 class="font-bold text-base leading-tight">Tambah Kenderaan</h6>
                <p class="text-xs leading-tight" style="color:var(--ta-muted)">
                    <a href="dashboard.php" class="opacity-70 hover:opacity-100 hover:underline">Halaman</a>
                    <span class="mx-1 opacity-40">/</span>
                    <a href="vehicles.php" class="hover:underline" style="color:var(--ta-ink)">Kenderaan</a>
                    <span class="mx-1 opacity-40">/</span>
                    <span>Tambah Kenderaan</span>
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

    <div class="w-full px-4 sm:px-6 py-6 mx-auto max-w-5xl">
      
      <?php if ($isView): ?>
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-5 items-start">

          <!-- ===================== LAJUR KIRI (utama) ===================== -->
          <div class="lg:col-span-2 flex flex-col gap-5">

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
                <span class="ta-badge <?= $vehicleStatusBadge($vehicle['status']) ?>"><?= htmlspecialchars($vehicleStatusLabel($vehicle['status'])) ?></span>
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

          </div>

          <!-- ===================== LAJUR KANAN (sisi) ===================== -->
          <div class="flex flex-col gap-5">

            <!-- Kad: Road Tax -->
            <div class="card p-5 sm:p-6">
              <div class="flex items-center gap-2 mb-4">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" style="color:var(--ta-brand)" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                <h6 class="font-semibold">Road Tax</h6>
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
                    <div class="flex flex-wrap gap-2 mt-1">
                      <a class="btn btn-sm btn-outline" href="vehicles.php?road_tax=view&amp;vehicle_id=<?= (int)$vehicleId ?>" target="_blank" rel="noopener">Lihat Dokumen</a>
                      <a class="btn btn-sm btn-outline" href="vehicles.php?road_tax=download&amp;vehicle_id=<?= (int)$vehicleId ?>">Muat Turun Dokumen</a>
                    </div>
                  <?php else: ?>
                    <p class="text-sm text-slate-400 mt-1">Tiada dokumen dimuat naik</p>
                  <?php endif; ?>
                </div>
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
            <input type="hidden" name="action" value="<?= $mode === 'add' ? 'add_vehicle' : 'edit_vehicle' ?>" />
            <?php if ($mode === 'edit'): ?>
              <input type="hidden" name="vehicle_id" value="<?= (int)$vehicleId ?>" />
            <?php endif; ?>

            <!-- Butiran Kenderaan -->
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
              <div>
                <label class="text-xs font-medium block mb-1">Nombor Plat <span class="text-error">*</span></label>
                <input class="input validator" name="plate_no" required autocomplete="off"
                       class="input input-bordered w-full" value="<?= htmlspecialchars($vehicle['plate_no']) ?>" />
                    <div class="validator-hint">Sila masukkan nombor plat</div>
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
                <label class="text-xs font-medium block mb-1">Kapasiti Penumpang <span class="text-error">*</span></label>
                <input type="number" class="input validator" name="capacity" required min="1" autocomplete="off"
                       class="input input-bordered w-full" value="<?= htmlspecialchars((string)($vehicle['capacity'] ?? '')) ?>"
                       min="1"
                        title="Kapasiti penumpang mestilah lebih dari 1" />
                        <p class="validator-hint">Kapasiti penumpang mestilah lebih dari 1</p>
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

            <!-- Road Tax -->
            <div>
              <label class="text-xs font-medium block mb-1">Tamat Tempoh Cukai Jalan <span class="text-error">*</label>
              <input type="text" name="road_tax_expiry" required id="road-tax-date-input" autocomplete="off"
                     class="input validator w-full" placeholder="Pilih tarikh"
                     value="<?= htmlspecialchars($vehicle['road_tax_expiry'] ?? '') ?>" />
                     <p class="validator-hint">Tarikh tamat tempoh cukai jalan mestilah dipilih</p>
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

    <script>
        const themeToggleBtn = document.getElementById('theme-toggle');
        const themeToggleIcon = document.getElementById('theme-toggle-icon');

        const LIGHT_THEME = 'garden';
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