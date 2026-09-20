<?php
// vehicles.php — Pengurusan Kenderaan
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/config/database.php';

require_login();

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

$vehicleStatusBadge = fn(string $s) => match ($s) {
  'Available'   => 'badge badge-success',
  'Booked'      => 'badge badge-info',
  'Maintenance' => 'badge badge-warning',
  'Inactive'    => 'badge badge-ghost',
  default       => 'badge badge-ghost',
};
$vehicleStatusLabel = fn(string $s) => match ($s) {
    'Available'   => 'Sedia Ada',
    'Booked'      => 'Sedang Digunakan',
    'Maintenance' => 'Penyelenggaraan',
    'Inactive'    => 'Tidak Aktif',
    default       => $s,
};
  $activeVehicleStatus = static function (array $vehicle) use ($vehicleStatusBadge, $vehicleStatusLabel): array {
    if (empty($vehicle['active_booking_id'])) {
      return [$vehicleStatusBadge($vehicle['status']), $vehicleStatusLabel($vehicle['status'])];
    }

    $now = time();
    $departed = !empty($vehicle['active_depart_datetime'])
      && strtotime($vehicle['active_depart_datetime']) <= $now;
    $notReturned = empty($vehicle['active_return_datetime'])
      || strtotime($vehicle['active_return_datetime']) >= $now;
    $label = $departed && $notReturned ? 'Sedang Digunakan' : 'Ditempah';

    return ['badge badge-info', $label];
  };

require_once __DIR__ . '/includes/vehicle_documents.php';

  if (isset($_GET['road_tax']) && isset($_GET['vehicle_id'])) {
    $documentVehicleId = (int)$_GET['vehicle_id'];
    $documentStmt = $pdo->prepare("SELECT road_tax_document FROM vehicles WHERE vehicle_id = ?");
    $documentStmt->execute([$documentVehicleId]);
    $documentPath = $documentStmt->fetchColumn();
    $documentAbsolutePath = is_string($documentPath) ? roadTaxAbsolutePath($documentPath) : null;

    if (!$documentAbsolutePath || !is_file($documentAbsolutePath)) {
      http_response_code(404);
      exit('Dokumen Road Tax tidak dijumpai.');
    }

    $documentMime = (new finfo(FILEINFO_MIME_TYPE))->file($documentAbsolutePath);
    if (!in_array($documentMime, ['application/pdf', 'image/jpeg', 'image/png'], true)) {
      http_response_code(403);
      exit('Dokumen tidak sah.');
    }

    $download = ($_GET['road_tax'] ?? '') === 'download';
    header('Content-Type: ' . $documentMime);
    header('Content-Length: ' . (string)filesize($documentAbsolutePath));
    header('X-Content-Type-Options: nosniff');
    header('Content-Disposition: ' . ($download ? 'attachment' : 'inline') . '; filename="road-tax-document.' . pathinfo($documentAbsolutePath, PATHINFO_EXTENSION) . '"');
    readfile($documentAbsolutePath);
    exit();
  }

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
        header("Location: vehicles.php");
        exit();
    }

    $action = $_POST['action'] ?? '';
    $newRoadTaxAbsolutePath = null;

    try {
        if ($action === 'add_vehicle' || $action === 'edit_vehicle') {
            $plateNo   = trim($_POST['plate_no'] ?? '');
            if ($plateNo === '' || strlen($plateNo) > 20) {
                throw new RuntimeException('Nombor plat kenderaan wajib diisi dan tidak boleh melebihi 20 aksara.');
            }
            $vName     = trim($_POST['vehicle_name'] ?? '') ?: null;
            if ($vName && strlen($vName) > 100) {
                throw new RuntimeException('Nama kenderaan tidak boleh melebihi 100 aksara.');
            }
            $vType     = trim($_POST['vehicle_type'] ?? '') ?: null;
            if ($vType && strlen($vType) > 100) {
                throw new RuntimeException('Jenis kenderaan tidak boleh melebihi 100 aksara.');
            }
            $capacity  = $_POST['capacity'] !== '' ? (int)$_POST['capacity'] : null;
            if ($capacity !== null && $capacity < 1) {
                throw new RuntimeException('Kapasiti penumpang tidak sah.');
            }
            $roadTax   = $_POST['road_tax_expiry'] !== '' ? $_POST['road_tax_expiry'] : null;
            if ($roadTax && !DateTime::createFromFormat('Y-m-d', $roadTax)) {
                throw new RuntimeException('Format tarikh cukai jalan tidak sah (Sila guna YYYY-MM-DD).');
            }
            $status    = $_POST['status'] ?? 'Available';
            $desc      = trim($_POST['description'] ?? '') ?: null;
            if ($desc && strlen($desc) > 500) {
                throw new RuntimeException('Catatan tidak boleh melebihi 500 aksara.');
            }
            $driverId  = $_POST['driver_id'] !== '' ? (int)$_POST['driver_id'] : null;

            if (!in_array($status, ['Available', 'Booked', 'Maintenance', 'Inactive'], true)) {
                throw new RuntimeException('Status tidak sah.');
            }

            $newRoadTaxPath = null;
            if (isset($_FILES['road_tax_document']) && $_FILES['road_tax_document']['error'] !== UPLOAD_ERR_NO_FILE) {
              [$newRoadTaxFilename, $newRoadTaxAbsolutePath] = validateRoadTaxUpload(
                $_FILES['road_tax_document'], $roadTaxUploadDir, $roadTaxAllowedTypes
              );
              $newRoadTaxPath = $roadTaxUploadUrl . '/' . $newRoadTaxFilename;
            }

            if ($action === 'add_vehicle') {
                $stmt = $pdo->prepare(
                    "INSERT INTO vehicles
                        (plate_no, vehicle_name, vehicle_type, capacity,
                         road_tax_expiry, road_tax_document, status, description, driver_id)
                       VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
                );
                $stmt->execute([$plateNo, $vName, $vType, $capacity,
                      $roadTax, $newRoadTaxPath, $status, $desc, $driverId]);

                $flash = ['type' => 'success', 'msg' => "Kenderaan '{$plateNo}' berjaya didaftarkan."];
            } else {
                $vid = (int)($_POST['vehicle_id'] ?? 0);
                if ($vid <= 0) {
                    throw new RuntimeException('Kenderaan tidak sah.');
                }

                $oldRoadTaxStmt = $pdo->prepare("SELECT road_tax_document FROM vehicles WHERE vehicle_id = ?");
                $oldRoadTaxStmt->execute([$vid]);
                $oldRoadTaxPath = $oldRoadTaxStmt->fetchColumn();
                if ($oldRoadTaxPath === false) {
                  throw new RuntimeException('Kenderaan tidak dijumpai.');
                }

                $stmt = $pdo->prepare(
                    "UPDATE vehicles SET
                        plate_no = ?, vehicle_name = ?, vehicle_type = ?,
                    capacity = ?, road_tax_expiry = ?,
                    road_tax_document = COALESCE(?, road_tax_document), status = ?,
                    description = ?, driver_id = ?
                     WHERE vehicle_id = ?"
                );
                $stmt->execute([$plateNo, $vName, $vType, $capacity,
                  $roadTax, $newRoadTaxPath, $status, $desc, $driverId, $vid]);

                if ($newRoadTaxPath && is_string($oldRoadTaxPath)) {
                  $oldRoadTaxAbsolutePath = roadTaxAbsolutePath($oldRoadTaxPath);
                  if ($oldRoadTaxAbsolutePath && is_file($oldRoadTaxAbsolutePath)) {
                    if (!unlink($oldRoadTaxAbsolutePath)) {
                      error_log("Gagal memadam fail Road Tax: " . $oldRoadTaxAbsolutePath);
                    }
                  }
                }

                $flash = ['type' => 'success', 'msg' => "Maklumat '{$plateNo}' berjaya dikemaskini."];
            }

            } elseif ($action === 'delete_road_tax') {
              $vid = (int)($_POST['vehicle_id'] ?? 0);
              if ($vid <= 0) {
                throw new RuntimeException('Kenderaan tidak sah.');
              }

              $stmt = $pdo->prepare("SELECT road_tax_document FROM vehicles WHERE vehicle_id = ?");
              $stmt->execute([$vid]);
              $oldRoadTaxPath = $stmt->fetchColumn();
              if ($oldRoadTaxPath === false) {
                throw new RuntimeException('Kenderaan tidak dijumpai.');
              }

              $pdo->prepare("UPDATE vehicles SET road_tax_document = NULL WHERE vehicle_id = ?")->execute([$vid]);
              $oldRoadTaxAbsolutePath = is_string($oldRoadTaxPath) ? roadTaxAbsolutePath($oldRoadTaxPath) : null;
              if ($oldRoadTaxAbsolutePath && is_file($oldRoadTaxAbsolutePath)) {
                if (!unlink($oldRoadTaxAbsolutePath)) {
                  error_log("Gagal memadam fail Road Tax: " . $oldRoadTaxAbsolutePath);
                }
              }
              $flash = ['type' => 'success', 'msg' => 'Dokumen Road Tax telah dibuang.'];

        } elseif ($action === 'delete_vehicle') {
            $vid = (int)($_POST['vehicle_id'] ?? 0);
            if ($vid <= 0) {
                throw new RuntimeException('Kenderaan tidak sah.');
            }

            $stmt = $pdo->prepare("SELECT plate_no, road_tax_document FROM vehicles WHERE vehicle_id = ?");
            $stmt->execute([$vid]);
            $vehicle = $stmt->fetch();
            $plateNo = $vehicle['plate_no'] ?? null;
            if ($plateNo === null) {
              throw new RuntimeException('Kenderaan tidak dijumpai.');
            }

            $del = $pdo->prepare("DELETE FROM vehicles WHERE vehicle_id = ?");
            $del->execute([$vid]);

            $oldRoadTaxAbsolutePath = is_string($vehicle['road_tax_document'])
              ? roadTaxAbsolutePath($vehicle['road_tax_document']) : null;
            if ($oldRoadTaxAbsolutePath && is_file($oldRoadTaxAbsolutePath)) {
              if (!unlink($oldRoadTaxAbsolutePath)) {
                error_log("Gagal memadam fail Road Tax: " . $oldRoadTaxAbsolutePath);
              }
            }

            $flash = ['type' => 'success', 'msg' => "Kenderaan '{$plateNo}' berjaya dipadam."];
        }
    } catch (RuntimeException $e) {
      if ($newRoadTaxAbsolutePath && is_file($newRoadTaxAbsolutePath)) {
        if (!unlink($newRoadTaxAbsolutePath)) {
          error_log("Gagal memadam fail Road Tax (temporary): " . $newRoadTaxAbsolutePath);
        }
      }
        $flash = ['type' => 'error', 'msg' => $e->getMessage()];
    } catch (PDOException $e) {
      if ($newRoadTaxAbsolutePath && is_file($newRoadTaxAbsolutePath)) {
        if (!unlink($newRoadTaxAbsolutePath)) {
          error_log("Gagal memadam fail Road Tax (temporary): " . $newRoadTaxAbsolutePath);
        }
      }
        if ($e->getCode() === '23000') {
            $flash = ['type' => 'error', 'msg' => 'Operasi gagal — nombor plat mungkin telah wujud, atau kenderaan ini masih mempunyai rekod tempahan berkaitan.'];
        } else {
            $flash = ['type' => 'error', 'msg' => 'Ralat pangkalan data berlaku. Sila cuba lagi.'];
        }
    }

    $_SESSION['flash'] = $flash;
    header("Location: vehicles.php" . (isset($_GET['q']) && $_GET['q'] !== '' ? '?q=' . urlencode($_GET['q']) : ''));
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
$perPage = 10;
$page    = max(1, (int)($_GET['page'] ?? 1));
$offset  = ($page - 1) * $perPage;

function buildVehiclesPageUrl(int $p, string $search): string {
    $params = ['page' => $p];
    if ($search !== '') {
        $params['q'] = $search;
    }
    return 'vehicles.php?' . http_build_query($params);
}

$baseFrom = "FROM vehicles v
             LEFT JOIN drivers dr ON dr.driver_id = v.driver_id
             LEFT JOIN users u ON u.user_id = dr.user_id
             LEFT JOIN vehicle_bookings current_booking
               ON current_booking.booking_id = (
                 SELECT next_booking.booking_id
                 FROM vehicle_bookings next_booking
                 WHERE next_booking.vehicle_id = v.vehicle_id
                   AND next_booking.status IN ('Pending', 'Approved')
                   AND next_booking.workflow_stage IN ('DriverAssigned', 'DriverAccepted', 'AdminApproved')
                 ORDER BY next_booking.depart_datetime ASC, next_booking.booking_id ASC
                 LIMIT 1
               )";

if ($search !== '') {
    $like = "%{$search}%";

    $countStmt = $pdo->prepare("SELECT COUNT(*) $baseFrom
        WHERE v.plate_no LIKE :like1 OR v.vehicle_name LIKE :like2 OR v.vehicle_type LIKE :like3");
    $countStmt->execute([':like1' => $like, ':like2' => $like, ':like3' => $like]);
    $totalRows = (int)$countStmt->fetchColumn();

    $stmt = $pdo->prepare(
        "SELECT v.*, u.fullname AS driver_name,
          current_booking.booking_id AS active_booking_id,
          current_booking.booking_no AS active_booking_no,
                current_booking.workflow_stage AS active_booking_stage,
                current_booking.depart_datetime AS active_depart_datetime,
                current_booking.return_datetime AS active_return_datetime
         $baseFrom
         WHERE v.plate_no LIKE :like1 OR v.vehicle_name LIKE :like2 OR v.vehicle_type LIKE :like3
         ORDER BY FIELD(v.status, 'Available', 'Booked', 'Maintenance', 'Inactive'), v.plate_no
         LIMIT :limit OFFSET :offset"
    );
    $stmt->bindValue(':like1', $like);
    $stmt->bindValue(':like2', $like);
    $stmt->bindValue(':like3', $like);
    $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
} else {
    $totalRows = (int)$pdo->query("SELECT COUNT(*) FROM vehicles")->fetchColumn();

    $stmt = $pdo->prepare(
        "SELECT v.*, u.fullname AS driver_name,
          current_booking.booking_id AS active_booking_id,
          current_booking.booking_no AS active_booking_no,
                current_booking.workflow_stage AS active_booking_stage,
                current_booking.depart_datetime AS active_depart_datetime,
                current_booking.return_datetime AS active_return_datetime
         $baseFrom
         ORDER BY FIELD(v.status, 'Available', 'Booked', 'Maintenance', 'Inactive'), v.plate_no
         LIMIT :limit OFFSET :offset"
    );
    $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
}
$vehicles = $stmt->fetchAll(PDO::FETCH_ASSOC);

$totalPages = max(1, (int)ceil($totalRows / $perPage));
if ($page > $totalPages) {
    $page = $totalPages;
}

// Senarai pemandu untuk dropdown penugasan
$driverOptions = $pdo->query(
    "SELECT dr.driver_id, u.fullname, dr.status
     FROM drivers dr
     JOIN users u ON u.user_id = dr.user_id
     ORDER BY u.fullname"
)->fetchAll(PDO::FETCH_ASSOC);

$statusCounts     = $pdo->query(
    "SELECT effective_status AS status, COUNT(*) AS total
     FROM (
       SELECT v.vehicle_id,
              CASE
                WHEN EXISTS (
                  SELECT 1
                  FROM vehicle_bookings vb
                  WHERE vb.vehicle_id = v.vehicle_id
                    AND vb.status IN ('Pending', 'Approved')
                    AND vb.workflow_stage IN ('DriverAssigned', 'DriverAccepted', 'AdminApproved')
                ) THEN 'Booked'
                ELSE v.status
              END AS effective_status
       FROM vehicles v
     ) effective_vehicles
     GROUP BY effective_status"
)->fetchAll(PDO::FETCH_KEY_PAIR);
$totalVehicles    = (int)array_sum($statusCounts);
$availableCount   = (int)($statusCounts['Available'] ?? 0);
$bookedCount      = (int)($statusCounts['Booked'] ?? 0);
$maintenanceCount = (int)($statusCounts['Maintenance'] ?? 0);

$pendingApprovals = $pdo->query(
    "SELECT COUNT(*) FROM vehicle_bookings WHERE status = 'Pending'"
)->fetchColumn();

// --- Layout Config ---
$pageTitle = "Kenderaan";
$showSearch = true;
$searchAction = "vehicles.php";
$searchPlaceholder = "Cari plat, nama atau jenis kenderaan...";
$extraJS = '
    <script>
        function openAddModal() {
            document.getElementById("modal-add").showModal();
        }

        function openEditModal(v) {
            document.getElementById("edit-vehicle-id").value    = v.vehicle_id;
            document.getElementById("edit-plate-no").value      = v.plate_no;
            document.getElementById("edit-vehicle-name").value  = v.vehicle_name || "";
            document.getElementById("edit-vehicle-type").value  = v.vehicle_type || "";
            document.getElementById("edit-capacity").value      = v.capacity || "";
            document.getElementById("edit-road-tax").value      = v.road_tax_expiry || "";
            document.getElementById("remove-road-tax-vehicle-id").value = v.vehicle_id;
            const currentDocument = document.getElementById("edit-road-tax-current");
            const removeDocumentForm = document.getElementById("remove-road-tax-form");
            if (v.road_tax_document) {
              currentDocument.innerHTML = \'<a class="link link-primary" target="_blank" rel="noopener" href="vehicles.php?road_tax=view&vehicle_id=\' + encodeURIComponent(v.vehicle_id) + \'">Lihat Dokumen</a> · <a class="link link-secondary" href="vehicles.php?road_tax=download&vehicle_id=\' + encodeURIComponent(v.vehicle_id) + \'">Muat Turun Dokumen</a>\';
              removeDocumentForm.classList.remove("hidden");
            } else {
              currentDocument.textContent = "Tiada dokumen dimuat naik";
              removeDocumentForm.classList.add("hidden");
            }
            document.getElementById("edit-status").value        = v.status;
            setComboboxValue(document.querySelector("#edit-driver-combobox"), v.driver_id || "");
            document.getElementById("edit-description").value   = v.description || "";
            document.getElementById("modal-edit").showModal();
        }

        function openDeleteModal(id, name) {
            document.getElementById("delete-vehicle-id").value = id;
            document.getElementById("delete-vehicle-name").textContent = name;
            document.getElementById("modal-delete").showModal();
        }

        function setComboboxValue(combobox, value) {
            if (!combobox) return;
            const hiddenInput = combobox.querySelector("[data-combobox-value]");
            const textInput = combobox.querySelector("[data-combobox-input]");
            const option = [...combobox.querySelectorAll(".ta-combobox-option")]
                .find(item => item.dataset.value === String(value ?? ""));

            hiddenInput.value = option ? option.dataset.value : "";
            textInput.value = option ? option.textContent.trim() : "";
            combobox.querySelectorAll(".ta-combobox-option").forEach(item => {
                item.classList.toggle("selected", item === option);
            });
        }

        document.querySelectorAll("[data-combobox]").forEach(combobox => {
            const input = combobox.querySelector("[data-combobox-input]");
            const hiddenInput = combobox.querySelector("[data-combobox-value]");
            const options = [...combobox.querySelectorAll(".ta-combobox-option")];

            const closeOptions = () => {
                combobox.classList.remove("open");
                input.setAttribute("aria-expanded", "false");
            };

            const openOptions = () => {
                combobox.classList.add("open");
                input.setAttribute("aria-expanded", "true");
            };

            const filterOptions = () => {
                const query = input.value.trim().toLowerCase();
                options.forEach(option => {
                    const label = option.textContent.toLowerCase();
                    option.hidden = query !== "" && !label.includes(query);
                });
            };

            input.addEventListener("focus", () => {
                filterOptions();
                openOptions();
            });

            input.addEventListener("input", () => {
                hiddenInput.value = "";
                options.forEach(option => option.classList.remove("selected"));
                filterOptions();
                openOptions();
            });

            input.addEventListener("keydown", event => {
                if (event.key === "Escape") {
                    closeOptions();
                } else if (event.key === "ArrowDown") {
                    event.preventDefault();
                    openOptions();
                    combobox.querySelector(".ta-combobox-option:not([hidden])")?.focus();
                }
            });

            options.forEach(option => {
                option.addEventListener("click", () => {
                    setComboboxValue(combobox, option.dataset.value);
                    closeOptions();
                });
            });

            combobox.addEventListener("focusout", event => {
                if (!combobox.contains(event.relatedTarget)) {
                    closeOptions();
                }
            });
        });

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
          <div class="card p-5" data-href="vehicles.php">
            <div class="ta-icon-box ta-icon-box-blue mb-4">
              <svg xmlns="http://www.w3.org/2000/svg" class="h-5.5 w-5.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 18.75a1.5 1.5 0 01-3 0m3 0a1.5 1.5 0 00-3 0m3 0h6m-9 0H3.375a1.125 1.125 0 01-1.125-1.125V14.25m17.25 4.5a1.5 1.5 0 01-3 0m3 0a1.5 1.5 0 00-3 0m3 0h1.125c.621 0 1.129-.504 1.09-1.124a17.902 17.902 0 00-3.213-9.193 2.056 2.056 0 00-1.58-.86H14.25M16.5 18.75h-2.25m0-11.177v-.958c0-.568-.422-1.048-.987-1.106a48.554 48.554 0 00-10.026 0 1.106 1.106 0 00-.987 1.106v7.635m12-6.677v6.677m0 0h-12" /></svg>
            </div>
            <p class="text-sm mb-1" style="color:var(--ta-muted)">Jumlah Kenderaan</p>
            <h5 class="text-2xl font-bold"><?= $totalVehicles ?></h5>
          </div>

          <div class="card p-5" data-href="vehicles.php?status=Available">
            <div class="ta-icon-box ta-icon-box-green mb-4">
              <svg xmlns="http://www.w3.org/2000/svg" class="h-5.5 w-5.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
            </div>
            <p class="text-sm mb-1" style="color:var(--ta-muted)">Sedia Ada</p>
            <h5 class="text-2xl font-bold"><?= $availableCount ?></h5>
          </div>

          <div class="card p-5" data-href="vehicles.php?status=Booked">
            <div class="ta-icon-box ta-icon-box-purple mb-4">
              <svg xmlns="http://www.w3.org/2000/svg" class="h-5.5 w-5.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6"><path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 012.25-2.25h13.5A2.25 2.25 0 0121 7.5v11.25m-18 0A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75m-18 0v-7.5A2.25 2.25 0 015.25 9h13.5A2.25 2.25 0 0121 11.25v7.5" /></svg>
            </div>
            <p class="text-sm mb-1" style="color:var(--ta-muted)">Sedang Digunakan</p>
            <h5 class="text-2xl font-bold"><?= $bookedCount ?></h5>
          </div>

          <div class="card p-5" data-href="vehicles.php?status=Maintenance">
            <div class="ta-icon-box ta-icon-box-orange mb-4">
              <svg xmlns="http://www.w3.org/2000/svg" class="h-5.5 w-5.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6"><path stroke-linecap="round" stroke-linejoin="round" d="M11.42 15.17L17.25 21A2.652 2.652 0 0021 17.25l-5.877-5.877M11.42 15.17l2.496-3.03c.317-.384.74-.626 1.208-.766M11.42 15.17l-4.655 5.653a2.548 2.548 0 11-3.586-3.586l6.837-5.63m5.108-.233c.55-.164 1.163-.188 1.743-.14a4.5 4.5 0 004.486-6.336l-3.276 3.277a3.004 3.004 0 01-2.25-2.25l3.276-3.276a4.5 4.5 0 00-6.336 4.486c.091 1.076-.071 2.264-.904 2.95l-.102.085m-1.745 1.437L5.909 7.5H4.5L2.25 3.75l1.5-1.5L7.5 4.5v1.409l4.26 4.26m-1.745 1.437l1.745-1.437m6.615 8.206L15.75 15.75M4.867 19.125h.008v.008h-.008v-.008z" /></svg>
            </div>
            <p class="text-sm mb-1" style="color:var(--ta-muted)">Penyelenggaraan</p>
            <h5 class="text-2xl font-bold"><?= $maintenanceCount ?></h5>
          </div>
        </div>

        <!-- Baris 2: Jadual Kenderaan -->
        <div class="card p-5 mt-5">
          <div class="flex items-center justify-between gap-3 mb-4 flex-wrap">
            <div>
              <h6 class="font-semibold">Senarai Kenderaan</h6>
              <p class="mb-0 text-sm" style="color:var(--ta-muted)">Urus fleet, penugasan pemandu &amp; dokumen kenderaan</p>
            </div>
            <?php if ($canManage): ?>
            <a href="view-vehicle.php?mode=add" class="btn btn-sm text-white border-0 gap-1.5" style="background:var(--ta-brand)">
              <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" /></svg>
              Tambah Kenderaan
            </a>
            <?php endif; ?>
          </div>

          <div class="overflow-x-auto -mx-1">
            <table class="items-center w-full mb-0 align-top">
              <thead>
                <tr class="border-b" style="border-color:var(--ta-border)">
                  <th class="px-3 py-2 text-xs font-semibold text-left uppercase text-slate-400">Kenderaan</th>
                  <th class="px-3 py-2 text-xs font-semibold text-left uppercase text-slate-400">Jenis / Muatan</th>
                  <th class="px-3 py-2 text-xs font-semibold text-left uppercase text-slate-400">Pemandu Ditugaskan</th>
                  <th class="px-3 py-2 text-xs font-semibold text-left uppercase text-slate-400">Dokumen (Tamat Tempoh)</th>
                  <th class="px-3 py-2 text-xs font-semibold text-center uppercase text-slate-400">Status</th>
                  <?php if ($canManage): ?>
                  <th class="px-3 py-2 text-xs font-semibold text-center uppercase text-slate-400">Tindakan</th>
                  <?php endif; ?>
                </tr>
              </thead>
              <tbody>
                <?php if (empty($vehicles)): ?>
                  <tr><td colspan="<?= $canManage ? 6 : 5 ?>" class="px-3 py-6 text-sm text-center text-slate-400">Tiada kenderaan dijumpai.</td></tr>
                <?php endif; ?>
                <?php foreach ($vehicles as $v): ?>
                  <?php
                    $today = new DateTime();
                    $roadTaxDate = !empty($v['road_tax_expiry']) ? new DateTime($v['road_tax_expiry']) : null;
                    $nearestDate = $roadTaxDate;

                    $daysRemaining = $nearestDate ? (int)$today->diff($nearestDate)->format('%r%a') : null;
                    $expired = $daysRemaining !== null && $daysRemaining < 0;
                    $soon    = $daysRemaining !== null && $daysRemaining >= 0 && $daysRemaining <= 30;
                  ?>
                  <tr class="hover:bg-slate-50/70 transition-colors cursor-pointer"
                      data-row-href="view-vehicle.php?id=<?= (int)$v['vehicle_id'] ?>&amp;mode=view">
                    <td class="px-3 py-3 text-sm border-b whitespace-nowrap" style="border-color:var(--ta-border)">
                      <p class="mb-0 font-medium"><a href="view-vehicle.php?id=<?= (int)$v['vehicle_id'] ?>&amp;mode=view" class="link link-hover"><?= htmlspecialchars($v['plate_no']) ?></a></p>
                      <p class="mb-0 text-xs text-slate-400"><?= htmlspecialchars($v['vehicle_name'] ?? '') ?: '—' ?></p>
                    </td>
                    <td class="px-3 py-3 text-sm border-b whitespace-nowrap" style="border-color:var(--ta-border)">
                      <?= htmlspecialchars($v['vehicle_type'] ?? '—') ?>
                      <?php if ($v['capacity']): ?><span class="text-slate-400">&middot; <?= (int)$v['capacity'] ?> penumpang</span><?php endif; ?>
                    </td>
                    <td class="px-3 py-3 text-sm border-b whitespace-nowrap" style="border-color:var(--ta-border)"><?= htmlspecialchars($v['driver_name'] ?? '—') ?></td>
                    <td class="px-3 py-3 text-sm border-b whitespace-nowrap" style="border-color:var(--ta-border)">
                      <?php if ($expired): ?>
                        <span class="ta-badge badge badge-error">Telah Tamat (<?= abs($daysRemaining) ?> hari lalu)</span>
                      <?php elseif ($soon): ?>
                        <span class="ta-badge badge badge-warning"><?= $daysRemaining ?> hari lagi</span>
                      <?php else: ?>
                        <span class="text-slate-400 text-xs">Terkini</span>
                      <?php endif; ?>
                      <div class="mt-1 flex items-center gap-2 text-xs">
                        <?php if (!empty($v['road_tax_document'])): ?>
                          <a class="link link-primary" href="vehicles.php?road_tax=view&amp;vehicle_id=<?= (int)$v['vehicle_id'] ?>" target="_blank" rel="noopener">Lihat Dokumen</a>
                          <a class="link link-secondary" href="vehicles.php?road_tax=download&amp;vehicle_id=<?= (int)$v['vehicle_id'] ?>">Muat Turun Dokumen</a>
                        <?php else: ?>
                          <span class="text-slate-400">Tiada dokumen dimuat naik</span>
                        <?php endif; ?>
                      </div>
                    </td>
                    <td class="px-3 py-3 text-sm border-b text-center whitespace-nowrap" style="border-color:var(--ta-border)">
                      <?php [$effectiveBadge, $effectiveLabel] = $activeVehicleStatus($v); ?>
                      <?php $hasActiveBooking = !empty($v['active_booking_id']); ?>
                      <span class="ta-badge <?= $effectiveBadge ?>"><?= htmlspecialchars($effectiveLabel) ?></span>
                      <?php if ($hasActiveBooking): ?>
                        <a href="view.php?id=<?= (int)$v['active_booking_id'] ?>" class="block text-xs text-primary hover:underline mt-1">
                          <?= htmlspecialchars($v['active_booking_no']) ?>
                          <?php if (!empty($v['active_depart_datetime'])): ?>
                            · <?= htmlspecialchars(date('d/m/Y', strtotime($v['active_depart_datetime']))) ?>
                          <?php endif; ?>
                        </a>
                      <?php endif; ?>
                    </td>
                    <?php if ($canManage): ?>
                    <td class="px-3 py-3 text-sm border-b text-center whitespace-nowrap" style="border-color:var(--ta-border)">
                      <a href="view-vehicle.php?id=<?= (int)$v['vehicle_id'] ?>&amp;mode=edit" class="btn btn-ghost btn-xs" title="Kemaskini">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931z" /></svg>
                      </a>
                      <button type="button" class="btn btn-ghost btn-xs text-error" title="Padam"
                        onclick="openDeleteModal(<?= (int)$v['vehicle_id'] ?>, '<?= htmlspecialchars(addslashes($v['plate_no'])) ?>')">
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
              Menunjukkan <?= $offset + 1 ?>–<?= min($offset + $perPage, $totalRows) ?> daripada <?= $totalRows ?> kenderaan
            </p>
            <div class="join">
              <a href="<?= $page > 1 ? htmlspecialchars(buildVehiclesPageUrl($page - 1, $search)) : '#' ?>"
                 class="join-item btn btn-sm <?= $page <= 1 ? 'btn-disabled opacity-40' : '' ?>">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.2"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5L8.25 12l7.5-7.5" /></svg>
              </a>
              <span class="join-item btn btn-sm btn-disabled !bg-transparent !border-none font-semibold" style="color:var(--ta-ink)">
                <?= $page ?> / <?= $totalPages ?>
              </span>
              <a href="<?= $page < $totalPages ? htmlspecialchars(buildVehiclesPageUrl($page + 1, $search)) : '#' ?>"
                 class="join-item btn btn-sm <?= $page >= $totalPages ? 'btn-disabled opacity-40' : '' ?>">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.2"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5" /></svg>
              </a>
            </div>
          </div>
          <?php endif; ?>
        </div>

        <?php if ($canManage): ?>
        <!-- Modal: Tambah Kenderaan -->
        <dialog id="modal-add" class="modal" hidden inert>
          <div class="modal-box card max-w-lg">
            <form method="dialog"><button class="btn btn-sm btn-circle btn-ghost absolute right-3 top-3">✕</button></form>
            <h3 class="font-bold text-lg mb-4">Tambah Kenderaan Baharu</h3>
            <form action="vehicles.php<?= $search !== '' ? '?q=' . urlencode($search) : '' ?>" method="POST" enctype="multipart/form-data" class="flex flex-col gap-3">
              <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>" />
              <input type="hidden" name="action" value="add_vehicle" />
              <div class="grid grid-cols-2 gap-3">
                <div>
                  <label class="text-xs font-medium block mb-1">Nombor Plat</label>
                  <input type="text" name="plate_no" required class="input input-bordered w-full" />
                </div>
                <div>
                  <label class="text-xs font-medium block mb-1">Nama Kenderaan</label>
                  <input type="text" name="vehicle_name" class="input input-bordered w-full" />
                </div>
                <div>
                  <label class="text-xs font-medium block mb-1">Jenis Kenderaan</label>
                  <input type="text" name="vehicle_type" placeholder="MPV, Van, Sedan..." class="input input-bordered w-full" />
                </div>
                <div>
                  <label class="text-xs font-medium block mb-1">Kapasiti Penumpang</label>
                  <input type="number" name="capacity" min="1" class="input input-bordered w-full" />
                </div>
                <div>
                  <label class="text-xs font-medium block mb-1">Tamat Tempoh Cukai Jalan</label>
                  <input type="date" name="road_tax_expiry" class="input input-bordered w-full" />
                </div>
                <div>
                  <label class="text-xs font-medium block mb-1">Dokumen</label>
                  <input type="file" name="road_tax_document" accept=".pdf,.jpg,.jpeg,.png" class="file-input file-input-bordered w-full" />
                  <span class="text-xs text-slate-400">PDF, JPG, JPEG atau PNG (maksimum 5MB)</span>
                </div>
                <div>
                  <label class="text-xs font-medium block mb-1">Status</label>
                  <select name="status" class="select select-bordered w-full">
                    <option value="Available">Sedia Ada</option>
                    <option value="Booked">Sedang Digunakan</option>
                    <option value="Maintenance">Penyelenggaraan</option>
                    <option value="Inactive">Tidak Aktif</option>
                  </select>
                </div>
                <div>
                  <label class="text-xs font-medium block mb-1">Pemandu Ditugaskan</label>
                  <div class="ta-combobox" data-combobox>
                    <input type="hidden" name="driver_id" data-combobox-value />
                    <input type="text" class="input input-bordered w-full" placeholder="Cari pemandu..." autocomplete="off"
                      role="combobox" aria-expanded="false" aria-autocomplete="list" data-combobox-input />
                    <div class="ta-combobox-options" role="listbox" data-combobox-options>
                      <button type="button" class="ta-combobox-option" data-value="">— Tiada —</button>
                      <?php foreach ($driverOptions as $do): ?>
                        <button type="button" class="ta-combobox-option" data-value="<?= (int)$do['driver_id'] ?>"><?= htmlspecialchars($do['fullname']) ?></button>
                      <?php endforeach; ?>
                    </div>
                  </div>
                </div>
              </div>
              <div>
                <label class="text-xs font-medium block mb-1">Catatan</label>
                <textarea name="description" rows="2" class="textarea textarea-bordered w-full"></textarea>
              </div>
              <div class="modal-action mt-2">
                <button type="button" class="btn btn-ghost" onclick="document.getElementById('modal-add').close()">Batal</button>
                <button type="submit" class="btn text-white border-0" style="background:var(--ta-brand)">Daftar Kenderaan</button>
              </div>
            </form>
          </div>
          <form method="dialog" class="modal-backdrop"><button>close</button></form>
        </dialog>

        <!-- Modal: Kemaskini Kenderaan -->
        <dialog id="modal-edit" class="modal" hidden inert>
          <div class="modal-box card max-w-lg">
            <form method="dialog"><button class="btn btn-sm btn-circle btn-ghost absolute right-3 top-3">✕</button></form>
            <h3 class="font-bold text-lg mb-4">Kemaskini Kenderaan</h3>
            <form action="vehicles.php<?= $search !== '' ? '?q=' . urlencode($search) : '' ?>" method="POST" enctype="multipart/form-data" class="flex flex-col gap-3">
              <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>" />
              <input type="hidden" name="action" value="edit_vehicle" />
              <input type="hidden" name="vehicle_id" id="edit-vehicle-id" />
              <div class="grid grid-cols-2 gap-3">
                <div>
                  <label class="text-xs font-medium block mb-1">Nombor Plat</label>
                  <input type="text" name="plate_no" id="edit-plate-no" required class="input input-bordered w-full" />
                </div>
                <div>
                  <label class="text-xs font-medium block mb-1">Nama Kenderaan</label>
                  <input type="text" name="vehicle_name" id="edit-vehicle-name" class="input input-bordered w-full" />
                </div>
                <div>
                  <label class="text-xs font-medium block mb-1">Jenis Kenderaan</label>
                  <input type="text" name="vehicle_type" id="edit-vehicle-type" class="input input-bordered w-full" />
                </div>
                <div>
                  <label class="text-xs font-medium block mb-1">Kapasiti Penumpang</label>
                  <input type="number" name="capacity" id="edit-capacity" min="1" class="input input-bordered w-full" />
                </div>
                <div>
                  <label class="text-xs font-medium block mb-1">Tamat Tempoh Cukai Jalan</label>
                  <input type="date" name="road_tax_expiry" id="edit-road-tax" class="input input-bordered w-full" />
                </div>
                <div>
                  <label class="text-xs font-medium block mb-1">Dokumen Road Tax</label>
                  <input type="file" name="road_tax_document" accept=".pdf,.jpg,.jpeg,.png" class="file-input file-input-bordered w-full" />
                  <span id="edit-road-tax-current" class="text-xs text-slate-400 block mt-1">No document uploaded</span>
                </div>
                <div>
                  <label class="text-xs font-medium block mb-1">Status</label>
                  <select name="status" id="edit-status" class="select select-bordered w-full">
                    <option value="Available">Sedia Ada</option>
                    <option value="Booked">Sedang Digunakan</option>
                    <option value="Maintenance">Penyelenggaraan</option>
                    <option value="Inactive">Tidak Aktif</option>
                  </select>
                </div>
                <div>
                  <label class="text-xs font-medium block mb-1">Pemandu Ditugaskan</label>
                  <div class="ta-combobox" id="edit-driver-combobox" data-combobox>
                    <input type="hidden" name="driver_id" data-combobox-value />
                    <input type="text" class="input input-bordered w-full" placeholder="Cari pemandu..." autocomplete="off"
                      role="combobox" aria-expanded="false" aria-autocomplete="list" data-combobox-input />
                    <div class="ta-combobox-options" role="listbox" data-combobox-options>
                      <button type="button" class="ta-combobox-option" data-value="">— Tiada —</button>
                      <?php foreach ($driverOptions as $do): ?>
                        <button type="button" class="ta-combobox-option" data-value="<?= (int)$do['driver_id'] ?>"><?= htmlspecialchars($do['fullname']) ?></button>
                      <?php endforeach; ?>
                    </div>
                  </div>
                </div>
              </div>
              <div>
                <label class="text-xs font-medium block mb-1">Catatan</label>
                <textarea name="description" id="edit-description" rows="2" class="textarea textarea-bordered w-full"></textarea>
              </div>
              <div class="modal-action mt-2">
                <button type="button" class="btn btn-ghost" onclick="document.getElementById('modal-edit').close()">Batal</button>
                <button type="submit" class="btn text-white border-0" style="background:var(--ta-brand)">Simpan Perubahan</button>
              </div>
            </form>
            <form action="vehicles.php<?= $search !== '' ? '?q=' . urlencode($search) : '' ?>" method="POST" id="remove-road-tax-form" class="mt-2 hidden">
              <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>" />
              <input type="hidden" name="action" value="delete_road_tax" />
              <input type="hidden" name="vehicle_id" id="remove-road-tax-vehicle-id" />
              <button type="submit" class="btn btn-sm btn-error btn-outline">Remove Road Tax Document</button>
            </form>
          </div>
          <form method="dialog" class="modal-backdrop"><button>close</button></form>
        </dialog>

        <!-- Modal: Sahkan Padam -->
        <dialog id="modal-delete" class="modal">
          <div class="modal-box card max-w-sm">
            <h3 class="font-bold text-lg mb-2">Padam Kenderaan?</h3>
            <p class="text-sm text-slate-400 mb-4">Anda pasti mahu memadam kenderaan <span id="delete-vehicle-name" class="font-semibold text-slate-600"></span>? Tindakan ini tidak boleh diundur.</p>
            <form action="vehicles.php<?= $search !== '' ? '?q=' . urlencode($search) : '' ?>" method="POST" class="flex justify-end gap-2">
              <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>" />
              <input type="hidden" name="action" value="delete_vehicle" />
              <input type="hidden" name="vehicle_id" id="delete-vehicle-id" />
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

        function openEditModal(v) {
            document.getElementById("edit-vehicle-id").value    = v.vehicle_id;
            document.getElementById("edit-plate-no").value      = v.plate_no;
            document.getElementById("edit-vehicle-name").value  = v.vehicle_name || "";
            document.getElementById("edit-vehicle-type").value  = v.vehicle_type || "";
            document.getElementById("edit-capacity").value      = v.capacity || "";
            document.getElementById("edit-road-tax").value      = v.road_tax_expiry || "";
            document.getElementById("remove-road-tax-vehicle-id").value = v.vehicle_id;
            const currentDocument = document.getElementById("edit-road-tax-current");
            const removeDocumentForm = document.getElementById("remove-road-tax-form");
            if (v.road_tax_document) {
              currentDocument.innerHTML = \'<a class="link link-primary" target="_blank" rel="noopener" href="vehicles.php?road_tax=view&vehicle_id=\' + encodeURIComponent(v.vehicle_id) + \'">Lihat Dokumen</a> · <a class="link link-secondary" href="vehicles.php?road_tax=download&vehicle_id=\' + encodeURIComponent(v.vehicle_id) + \'">Muat Turun Dokumen</a>\';
              removeDocumentForm.classList.remove("hidden");
            } else {
              currentDocument.textContent = "Tiada dokumen dimuat naik";
              removeDocumentForm.classList.add("hidden");
            }
            document.getElementById("edit-status").value        = v.status;
            setComboboxValue(document.querySelector("#edit-driver-combobox"), v.driver_id || "");
            document.getElementById("edit-description").value   = v.description || "";
            document.getElementById("modal-edit").showModal();
        }

        function openDeleteModal(id, name) {
            document.getElementById("delete-vehicle-id").value = id;
            document.getElementById("delete-vehicle-name").textContent = name;
            document.getElementById("modal-delete").showModal();
        }

        function setComboboxValue(combobox, value) {
            if (!combobox) return;
            const hiddenInput = combobox.querySelector("[data-combobox-value]");
            const textInput = combobox.querySelector("[data-combobox-input]");
            const option = [...combobox.querySelectorAll(".ta-combobox-option")]
                .find(item => item.dataset.value === String(value ?? ""));

            hiddenInput.value = option ? option.dataset.value : "";
            textInput.value = option ? option.textContent.trim() : "";
            combobox.querySelectorAll(".ta-combobox-option").forEach(item => {
                item.classList.toggle("selected", item === option);
            });
        }

        document.querySelectorAll("[data-combobox]").forEach(combobox => {
            const input = combobox.querySelector("[data-combobox-input]");
            const hiddenInput = combobox.querySelector("[data-combobox-value]");
            const options = [...combobox.querySelectorAll(".ta-combobox-option")];

            const closeOptions = () => {
                combobox.classList.remove("open");
                input.setAttribute("aria-expanded", "false");
            };

            const openOptions = () => {
                combobox.classList.add("open");
                input.setAttribute("aria-expanded", "true");
            };

            const filterOptions = () => {
                const query = input.value.trim().toLowerCase();
                options.forEach(option => {
                    const label = option.textContent.toLowerCase();
                    option.hidden = query !== "" && !label.includes(query);
                });
            };

            input.addEventListener("focus", () => {
                filterOptions();
                openOptions();
            });

            input.addEventListener("input", () => {
                hiddenInput.value = "";
                options.forEach(option => option.classList.remove("selected"));
                filterOptions();
                openOptions();
            });

            input.addEventListener("keydown", event => {
                if (event.key === "Escape") {
                    closeOptions();
                } else if (event.key === "ArrowDown") {
                    event.preventDefault();
                    openOptions();
                    combobox.querySelector(".ta-combobox-option:not([hidden])")?.focus();
                }
            });

            options.forEach(option => {
                option.addEventListener("click", () => {
                    setComboboxValue(combobox, option.dataset.value);
                    closeOptions();
                });
            });

            combobox.addEventListener("focusout", event => {
                if (!combobox.contains(event.relatedTarget)) {
                    closeOptions();
                }
            });
        });

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