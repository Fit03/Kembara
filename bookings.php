<?php
// bookings.php — Pengurusan Tempahan Kenderaan
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

function generateBookingNo(PDO $pdo): string {
    do {
        $no = 'VB' . date('ymd') . strtoupper(substr(bin2hex(random_bytes(3)), 0, 5));
        $check = $pdo->prepare("SELECT 1 FROM vehicle_bookings WHERE booking_no = ?");
        $check->execute([$no]);
    } while ($check->fetchColumn());
    return $no;
}

/* ==========================================================
   Tindakan Borang
   ========================================================== */
$flash = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        http_response_code(403);
        exit('Ralat Keselamatan: Token CSRF tidak sah atau telah tamat tempoh.');
    }
    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'add_booking') {
            $departRaw = trim($_POST['depart_datetime'] ?? '');
            if ($departRaw === '' || !DateTime::createFromFormat('Y-m-d\TH:i', $departRaw)) {
                throw new RuntimeException('Sila masukkan tarikh & masa berangkat yang sah.');
            }
            $returnRaw = trim($_POST['return_datetime'] ?? '');
            if ($returnRaw !== '' && !DateTime::createFromFormat('Y-m-d\TH:i', $returnRaw)) {
                throw new RuntimeException('Sila masukkan tarikh & masa pulang yang sah.');
            }
            $tripType  = $_POST['trip_type'] ?? 'One Way';
            $origin    = trim($_POST['origin'] ?? '');
            if ($origin === '' || strlen($origin) > 100) {
                throw new RuntimeException('Sila nyatakan lokasi asal yang sah (maksimum 100 aksara).');
            }
            $dest      = trim($_POST['destination'] ?? '');
            if ($dest === '' || strlen($dest) > 100) {
                throw new RuntimeException('Sila nyatakan destinasi yang sah (maksimum 100 aksara).');
            }
            $pax       = (int)($_POST['passenger_total'] ?? 0);
            $purpose   = trim($_POST['purpose'] ?? '');
            if ($purpose === '' || strlen($purpose) > 500) {
                throw new RuntimeException('Sila nyatakan tujuan tempahan yang sah (maksimum 500 aksara).');
            }

            // --- NEW: Process PDF Upload ---
            $passenger_memo_path = null;
            if (isset($_FILES['passenger_memo']) && $_FILES['passenger_memo']['error'] === UPLOAD_ERR_OK) {
                $fileTmpPath = $_FILES['passenger_memo']['tmp_name'];
                $fileSize = $_FILES['passenger_memo']['size'];
                $fileType = mime_content_type($fileTmpPath);

                if ($fileType === 'application/pdf' && $fileSize <= 2000000) {
                    $uploadDir = 'assets/uploads/memos/';
                    if (!is_dir($uploadDir)) {
                        mkdir($uploadDir, 0755, true);
                    }
                    $newFileName = uniqid('memo_') . '.pdf';
                    $destPath = $uploadDir . $newFileName;

                    if (move_uploaded_file($fileTmpPath, $destPath)) {
                        $passenger_memo_path = $destPath;
                    } else {
                        throw new RuntimeException('Ralat semasa menyimpan fail memo.');
                    }
                } else {
                    throw new RuntimeException('Sila muat naik format fail PDF sahaja (Maksimum 2MB).');
                }
            }

            // Nama penumpang dihantar sebagai array (passenger_names[]) daripada
            // senarai butang Tambah/Buang Penumpang pada borang tempahan
            $passengerNamesArr = array_values(array_filter(array_map(
                'trim',
                $_POST['passenger_names'] ?? []
            ), fn($n) => $n !== ''));
            $passengerNamesJson = !empty($passengerNamesArr)
                ? json_encode($passengerNamesArr, JSON_UNESCAPED_UNICODE)
                : null;
            // Bilangan penumpang diselaraskan dengan jumlah nama yang benar-benar diisi
            if (!empty($passengerNamesArr)) {
                $pax = count($passengerNamesArr);
            }

            if (empty($passengerNamesArr) && !$passenger_memo_path) {
                throw new RuntimeException('Sila masukkan sekurang-kurangnya nama seorang penumpang ATAU muat naik memo senarai (PDF).');
            }
            if (!in_array($tripType, ['One Way', 'Return', 'Both'], true)) {
                throw new RuntimeException('Jenis perjalanan tidak sah.');
            }
            if ($tripType !== 'One Way') {
                if ($returnRaw === '') {
                    throw new RuntimeException('Sila nyatakan tarikh & masa pulang.');
                }
                if (strtotime($returnRaw) <= strtotime($departRaw)) {
                    throw new RuntimeException('Tarikh pulang mestilah selepas tarikh berangkat.');
                }
            }

            $bookingNo = generateBookingNo($pdo);

            // Pemandu & kenderaan belum ditetapkan — ditugaskan oleh Admin/SuperAdmin semasa kelulusan
            $stmt = $pdo->prepare(
                "INSERT INTO vehicle_bookings
                 (booking_no, user_id, depart_datetime, return_datetime, trip_type, origin, destination,
                  passenger_total, passenger_names, passenger_memo_path, purpose, status)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Pending')"
            );
            $stmt->execute([
                $bookingNo, $currentUserId, $departRaw, $returnRaw !== '' ? $returnRaw : null,
                $tripType, $origin, $dest, $pax > 0 ? $pax : null, $passengerNamesJson, $passenger_memo_path, $purpose,
            ]);
            $newId = (int)$pdo->lastInsertId();

            $hist = $pdo->prepare("INSERT INTO booking_history (booking_id, action, remarks, action_by) VALUES (?, 'Dicipta', ?, ?)");
            $hist->execute([$newId, "Tempahan {$bookingNo} dicipta.", $currentUserId]);

            $flash = ['type' => 'success', 'msg' => "Tempahan {$bookingNo} berjaya dihantar dan menunggu kelulusan."];

        } elseif (in_array($action, ['approve_booking', 'reject_booking', 'cancel_booking', 'complete_booking'], true)) {
            $bid = (int)($_POST['booking_id'] ?? 0);
            if ($bid <= 0) {
                throw new RuntimeException('Tempahan tidak sah.');
            }

            $pdo->beginTransaction();

            $bstmt = $pdo->prepare("SELECT * FROM vehicle_bookings WHERE booking_id = ? FOR UPDATE");
            $bstmt->execute([$bid]);
            $booking = $bstmt->fetch(PDO::FETCH_ASSOC);
            if (!$booking) {
                throw new RuntimeException('Tempahan tidak dijumpai.');
            }

            if ($action === 'approve_booking') {
                if (!$canManage) throw new RuntimeException('Anda tidak mempunyai kebenaran untuk meluluskan tempahan.');
                if ($booking['status'] !== 'Pending') throw new RuntimeException('Hanya tempahan berstatus Menunggu boleh diluluskan.');

                $driverId = (int)($_POST['driver_id'] ?? 0);
                if ($driverId <= 0) {
                    throw new RuntimeException('Sila pilih pemandu untuk ditugaskan.');
                }

                // Setiap pemandu mempunyai kenderaan khusus mereka sendiri (vehicles.driver_id)
                $dstmt = $pdo->prepare(
                    "SELECT dr.driver_id, dr.status AS driver_status, v.vehicle_id
                     FROM drivers dr LEFT JOIN vehicles v ON v.driver_id = dr.driver_id
                     WHERE dr.driver_id = ? FOR UPDATE"
                );
                $dstmt->execute([$driverId]);
                $drv = $dstmt->fetch(PDO::FETCH_ASSOC);

                if (!$drv || $drv['driver_status'] !== 'Available') {
                    throw new RuntimeException('Pemandu tidak sah atau tidak lagi tersedia.');
                }
                if (!$drv['vehicle_id']) {
                    throw new RuntimeException('Pemandu ini tiada kenderaan ditugaskan kepadanya.');
                }

                $upd = $pdo->prepare(
                    "UPDATE vehicle_bookings SET status='Approved', driver_id=?, vehicle_id=?, approved_by=?, approved_at=NOW()
                     WHERE booking_id=?"
                );
                $upd->execute([$driverId, $drv['vehicle_id'], $currentUserId, $bid]);
                $pdo->prepare("UPDATE vehicles SET status='Booked' WHERE vehicle_id=?")->execute([$drv['vehicle_id']]);
                $pdo->prepare("INSERT INTO booking_history (booking_id, action, remarks, action_by) VALUES (?, 'Diluluskan', 'Tempahan diluluskan, pemandu & kenderaan ditugaskan.', ?)")->execute([$bid, $currentUserId]);

                $flash = ['type' => 'success', 'msg' => "Tempahan {$booking['booking_no']} telah diluluskan."];

            } elseif ($action === 'reject_booking') {
                if (!$canManage) throw new RuntimeException('Anda tidak mempunyai kebenaran untuk menolak tempahan.');
                if ($booking['status'] !== 'Pending') throw new RuntimeException('Hanya tempahan berstatus Menunggu boleh ditolak.');

                $pdo->prepare("UPDATE vehicle_bookings SET status='Rejected', approved_by=?, approved_at=NOW() WHERE booking_id=?")->execute([$currentUserId, $bid]);
                $pdo->prepare("INSERT INTO booking_history (booking_id, action, remarks, action_by) VALUES (?, 'Ditolak', 'Tempahan ditolak.', ?)")->execute([$bid, $currentUserId]);

                $flash = ['type' => 'success', 'msg' => "Tempahan {$booking['booking_no']} telah ditolak."];

            } elseif ($action === 'cancel_booking') {
                $isOwner = ((int)$booking['user_id'] === $currentUserId);
                if (!$isOwner && !$canManage) throw new RuntimeException('Anda tidak mempunyai kebenaran untuk membatalkan tempahan ini.');
                if (!in_array($booking['status'], ['Pending', 'Approved'], true)) throw new RuntimeException('Tempahan ini tidak boleh dibatalkan.');

                $pdo->prepare("UPDATE vehicle_bookings SET status='Cancelled' WHERE booking_id=?")->execute([$bid]);
                if ($booking['status'] === 'Approved') {
                    $pdo->prepare("UPDATE vehicles SET status='Available' WHERE vehicle_id=?")->execute([$booking['vehicle_id']]);
                }
                $pdo->prepare("INSERT INTO booking_history (booking_id, action, remarks, action_by) VALUES (?, 'Dibatalkan', 'Tempahan dibatalkan.', ?)")->execute([$bid, $currentUserId]);

                $flash = ['type' => 'success', 'msg' => "Tempahan {$booking['booking_no']} telah dibatalkan."];

            } elseif ($action === 'complete_booking') {
                if (!$canManage) throw new RuntimeException('Anda tidak mempunyai kebenaran untuk menamatkan tempahan.');
                if ($booking['status'] !== 'Approved') throw new RuntimeException('Hanya tempahan berstatus Diluluskan boleh ditamatkan.');

                $pdo->prepare("UPDATE vehicle_bookings SET status='Completed' WHERE booking_id=?")->execute([$bid]);
                $pdo->prepare("UPDATE vehicles SET status='Available' WHERE vehicle_id=?")->execute([$booking['vehicle_id']]);
                $pdo->prepare("INSERT INTO booking_history (booking_id, action, remarks, action_by) VALUES (?, 'Selesai', 'Perjalanan selesai.', ?)")->execute([$bid, $currentUserId]);

                $flash = ['type' => 'success', 'msg' => "Tempahan {$booking['booking_no']} ditandakan selesai."];
            }
            $pdo->commit();
        }
    } catch (RuntimeException $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        $flash = ['type' => 'error', 'msg' => $e->getMessage()];
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        $flash = ['type' => 'error', 'msg' => 'Ralat pangkalan data berlaku. Sila cuba lagi.'];
    }

    $_SESSION['flash'] = $flash;
    $qs = [];
    if (isset($_GET['q']) && $_GET['q'] !== '') $qs['q'] = $_GET['q'];
    if (isset($_GET['status']) && $_GET['status'] !== '') $qs['status'] = $_GET['status'];
    header("Location: bookings.php" . ($qs ? '?' . http_build_query($qs) : ''));
    exit();
}

if (isset($_SESSION['flash'])) {
    $flash = $_SESSION['flash'];
    unset($_SESSION['flash']);
}

/* ==========================================================
   Data Halaman
   ========================================================== */
$validStatuses = ['Pending', 'Approved', 'Rejected', 'Cancelled', 'Completed'];
$statusFilter  = $_GET['status'] ?? 'All';
if (!in_array($statusFilter, $validStatuses, true)) {
    $statusFilter = 'All';
}

$search  = trim($_GET['q'] ?? '');
$perPage = 10;
$page    = max(1, (int)($_GET['page'] ?? 1));
$offset  = ($page - 1) * $perPage;

function buildBookingsPageUrl(int $p, string $search, string $status): string {
    $params = ['page' => $p];
    if ($search !== '') $params['q'] = $search;
    if ($status !== 'All') $params['status'] = $status;
    return 'bookings.php?' . http_build_query($params);
}
function buildBookingsFilterUrl(string $search, string $status): string {
    $params = [];
    if ($search !== '') $params['q'] = $search;
    if ($status !== 'All') $params['status'] = $status;
    return 'bookings.php' . ($params ? '?' . http_build_query($params) : '');
}

$where  = [];
$params = [];

if ($role === 'User') {
    $where[] = 'vb.user_id = :uid';
    $params[':uid'] = $currentUserId;
}
if ($statusFilter !== 'All') {
    $where[] = 'vb.status = :status';
    $params[':status'] = $statusFilter;
}
if ($search !== '') {
    $like = "%{$search}%";
    $where[] = '(vb.booking_no LIKE :like1 OR vb.origin LIKE :like2 OR vb.destination LIKE :like3 OR u.fullname LIKE :like4)';
    $params[':like1'] = $like;
    $params[':like2'] = $like;
    $params[':like3'] = $like;
    $params[':like4'] = $like;
}
$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$countStmt = $pdo->prepare("SELECT COUNT(*) FROM vehicle_bookings vb JOIN users u ON u.user_id = vb.user_id $whereSql");
$countStmt->execute($params);
$totalRows = (int)$countStmt->fetchColumn();

$stmt = $pdo->prepare(
    "SELECT vb.*, u.fullname AS requester_name, v.plate_no, v.vehicle_name,
            du.fullname AS driver_name, ap.fullname AS approved_by_name
     FROM vehicle_bookings vb
    JOIN users u ON u.user_id = vb.user_id
    LEFT JOIN vehicles v ON v.vehicle_id = vb.vehicle_id
     LEFT JOIN drivers dr ON dr.driver_id = vb.driver_id
     LEFT JOIN users du ON du.user_id = dr.user_id
     LEFT JOIN users ap ON ap.user_id = vb.approved_by
     $whereSql
     ORDER BY vb.depart_datetime DESC
     LIMIT :limit OFFSET :offset"
);
foreach ($params as $k => $v) {
    $stmt->bindValue($k, $v);
}
$stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$bookings = $stmt->fetchAll(PDO::FETCH_ASSOC);

$totalPages = max(1, (int)ceil($totalRows / $perPage));
if ($page > $totalPages) {
    $page = $totalPages;
}

// Pemandu yang tersedia untuk ditugaskan semasa kelulusan — setiap pemandu
// mempunyai kenderaan khusus mereka sendiri (vehicles.driver_id)
$assignableDrivers = $pdo->query(
    "SELECT dr.driver_id, u.fullname, dr.license, v.vehicle_id, v.plate_no, v.vehicle_name
     FROM drivers dr
     JOIN users u ON u.user_id = dr.user_id
     LEFT JOIN vehicles v ON v.driver_id = dr.driver_id
     WHERE dr.status = 'Available'
     ORDER BY u.fullname"
)->fetchAll(PDO::FETCH_ASSOC);

// Kiraan statistik (skop mengikut peranan)
$statCountStmt = $pdo->prepare(
    "SELECT status, COUNT(*) AS total FROM vehicle_bookings vb"
    . ($role === 'User' ? " WHERE vb.user_id = :uid" : "")
    . " GROUP BY status"
);
$statCountStmt->execute($role === 'User' ? [':uid' => $currentUserId] : []);
$statusCounts   = $statCountStmt->fetchAll(PDO::FETCH_KEY_PAIR);
$totalBookings  = (int)array_sum($statusCounts);
$pendingCount   = (int)($statusCounts['Pending'] ?? 0);
$approvedCount  = (int)($statusCounts['Approved'] ?? 0);
$completedCount = (int)($statusCounts['Completed'] ?? 0);

$pendingApprovals = $pdo->query("SELECT COUNT(*) FROM vehicle_bookings WHERE status = 'Pending'")->fetchColumn();

$tabs = ['All' => 'Semua', 'Pending' => 'Menunggu', 'Approved' => 'Diluluskan', 'Rejected' => 'Ditolak', 'Cancelled' => 'Dibatalkan', 'Completed' => 'Selesai'];

// --- Layout Config ---
$pageTitle = "Tempahan";
$showSearch = true;
$searchAction = "bookings.php";
$searchPlaceholder = "Cari no. tempahan, destinasi...";
$extraJS = '
    <script>
        function openApproveModal(id, bookingNo) {
            document.getElementById("approve-booking-id").value = id;
            document.getElementById("approve-booking-no").textContent = bookingNo;
            const select = document.getElementById("approve-driver-select");
            if (select) select.value = "";
            const preview = document.getElementById("approve-vehicle-preview");
            if (preview) preview.textContent = "—";
            document.getElementById("modal-approve").showModal();
        }

        function updateApproveVehiclePreview() {
            const select = document.getElementById("approve-driver-select");
            const opt = select.options[select.selectedIndex];
            document.getElementById("approve-vehicle-preview").textContent = (opt && opt.dataset.vehicle) ? opt.dataset.vehicle : "—";
        }

        function openActionModal(id, action, text, buttonLabel, isDanger) {
            document.getElementById("action-modal-id").value     = id;
            document.getElementById("action-modal-action").value = action;
            document.getElementById("action-modal-text").textContent = text;
            const submitBtn = document.getElementById("action-modal-submit");
            submitBtn.textContent = buttonLabel;
            submitBtn.style.background = isDanger ? "var(--color-error)" : "var(--ta-brand)";
            document.getElementById("modal-action").showModal();
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
          <div class="card p-5" data-href="bookings.php">
            <div class="ta-icon-box mb-4">
              <svg xmlns="http://www.w3.org/2000/svg" class="h-5.5 w-5.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6"><path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 012.25-2.25h13.5A2.25 2.25 0 0121 7.5v11.25m-18 0A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75m-18 0v-7.5A2.25 2.25 0 015.25 9h13.5A2.25 2.25 0 0121 11.25v7.5" /></svg>
            </div>
            <p class="text-sm mb-1" style="color:var(--ta-muted)">Jumlah Tempahan</p>
            <h5 class="text-2xl font-bold"><?= $totalBookings ?></h5>
          </div>

          <?php if ($pendingApprovals > 0): ?>
          <div class="aura aura-dual text-yellow-600 bg-orange-200 duration-3000">
          <?php endif; ?>
            <div class="card p-5" data-href="bookings.php?status=Pending" data-priority="warning">
              <div class="ta-icon-box mb-4">
              <svg xmlns="http://www.w3.org/2000/svg" class="h-5.5 w-5.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6"><path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
            </div>
            <p class="text-sm mb-1" style="color:var(--ta-muted)">Menunggu Kelulusan</p>
            <h5 class="text-2xl font-bold"><?= $pendingCount ?></h5>
          </div>
          <?php if ($pendingApprovals > 0): ?>
          </div>
          <?php endif; ?>

          <div class="card p-5" data-href="bookings.php?status=Approved">
            <div class="ta-icon-box mb-4">
              <svg xmlns="http://www.w3.org/2000/svg" class="h-5.5 w-5.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
            </div>
            <p class="text-sm mb-1" style="color:var(--ta-muted)">Diluluskan</p>
            <h5 class="text-2xl font-bold"><?= $approvedCount ?></h5>
          </div>

          <div class="card p-5" data-href="bookings.php?status=Completed">
            <div class="ta-icon-box mb-4">
              <svg xmlns="http://www.w3.org/2000/svg" class="h-5.5 w-5.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" /></svg>
            </div>
            <p class="text-sm mb-1" style="color:var(--ta-muted)">Selesai</p>
            <h5 class="text-2xl font-bold"><?= $completedCount ?></h5>
          </div>
        </div>

        <!-- Baris 2: Jadual Tempahan -->
        <div class="card p-5 mt-5">
          <div class="flex items-center justify-between gap-3 mb-4 flex-wrap">
            <div>
              <h6 class="font-semibold">Senarai Tempahan</h6>
              <p class="mb-0 text-sm" style="color:var(--ta-muted)"><?= $role === 'User' ? 'Tempahan kenderaan anda' : 'Semua tempahan kenderaan dalam sistem' ?></p>
            </div>
            <a href="book-vehicle.php" class="btn btn-sm text-white border-0 gap-1.5" style="background:var(--ta-brand)">
              <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" /></svg>
              Tempah Kenderaan
            </a>
          </div>

          <div class="flex items-center gap-2 mb-4 overflow-x-auto pb-1">
            <?php foreach ($tabs as $key => $label): ?>
              <a href="<?= htmlspecialchars(buildBookingsFilterUrl($search, $key)) ?>" class="ta-tab <?= $statusFilter === $key ? 'active' : '' ?>"><?= $label ?></a>
            <?php endforeach; ?>
          </div>

          <div class="overflow-x-auto -mx-1">
            <table class="items-center w-full mb-0 align-top">
              <thead>
                <tr class="border-b" style="border-color:var(--ta-border)">
                  <th class="px-3 py-2 text-xs font-semibold text-left uppercase text-slate-400">No. Tempahan</th>
                  <?php if ($canManage): ?><th class="px-3 py-2 text-xs font-semibold text-left uppercase text-slate-400">Pemohon</th><?php endif; ?>
                  <th class="px-3 py-2 text-xs font-semibold text-left uppercase text-slate-400">Perjalanan</th>
                  <th class="px-3 py-2 text-xs font-semibold text-left uppercase text-slate-400">Tarikh Berangkat</th>
                  <th class="px-3 py-2 text-xs font-semibold text-left uppercase text-slate-400">Kenderaan</th>
                  <th class="px-3 py-2 text-xs font-semibold text-center uppercase text-slate-400">Status</th>
                  <th class="px-3 py-2 text-xs font-semibold text-center uppercase text-slate-400">Tindakan</th>
                </tr>
              </thead>
              <tbody>
                <?php if (empty($bookings)): ?>
                  <tr><td colspan="<?= $canManage ? 7 : 6 ?>" class="px-3 py-6 text-sm text-center text-slate-400">Tiada tempahan dijumpai.</td></tr>
                <?php endif; ?>
                <?php foreach ($bookings as $b): ?>
                  <tr class="hover:bg-slate-50/70 transition-colors">
                    <td class="px-3 py-3 text-sm border-b whitespace-nowrap font-semibold" style="border-color:var(--ta-border)"><?= htmlspecialchars($b['booking_no']) ?></td>
                    <?php if ($canManage): ?>
                    <td class="px-3 py-3 text-sm border-b whitespace-nowrap" style="border-color:var(--ta-border)"><?= htmlspecialchars($b['requester_name']) ?></td>
                    <?php endif; ?>
                    <td class="px-3 py-3 text-sm border-b" style="border-color:var(--ta-border)">
                      <p class="mb-0 truncate max-w-[14rem]"><?= htmlspecialchars($b['origin']) ?> → <?= htmlspecialchars($b['destination']) ?></p>
                      <p class="mb-0 text-xs text-slate-400"><?= htmlspecialchars($tripTypeLabel($b['trip_type'])) ?></p>
                    </td>
                    <td class="px-3 py-3 text-sm border-b whitespace-nowrap" style="border-color:var(--ta-border)"><?= htmlspecialchars(date('d M Y, h:i A', strtotime($b['depart_datetime']))) ?></td>
                    <td class="px-3 py-3 text-sm border-b whitespace-nowrap" style="border-color:var(--ta-border)">
                      <?php if ($b['plate_no']): ?>
                        <?= htmlspecialchars($b['vehicle_name'] ?: '') ?>
                        <p class="mb-0 text-xs text-slate-400"><?= htmlspecialchars($b['plate_no']) ?></p>
                      <?php else: ?>
                        <span class="text-xs text-slate-400 italic">Belum ditugaskan</span>
                      <?php endif; ?>
                    </td>
                    <td class="px-3 py-3 text-sm border-b text-center whitespace-nowrap" style="border-color:var(--ta-border)">
                      <span class="ta-badge <?= $statusBadge($b['status']) ?>"><?= htmlspecialchars($statusLabel($b['status'])) ?></span>
                    </td>
                    <td class="px-3 py-3 text-sm border-b text-center whitespace-nowrap" style="border-color:var(--ta-border)">
                      <a href="view.php?id=<?= (int)$b['booking_id'] ?><?= ($qs = $_SERVER['QUERY_STRING'] ?? '') !== '' ? '&from=' . urlencode($qs) : '' ?>" class="btn btn-ghost btn-xs" title="Lihat Butiran">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 010-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178z" /><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" /></svg>
                      </a>

                      <?php if ($canManage && $b['status'] === 'Pending'): ?>
                        <button type="button" class="btn btn-ghost btn-xs text-success" title="Luluskan"
                          onclick="openApproveModal(<?= (int)$b['booking_id'] ?>, '<?= htmlspecialchars(addslashes($b['booking_no'])) ?>')">
                          <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" /></svg>
                        </button>
                        <button type="button" class="btn btn-ghost btn-xs text-error" title="Tolak"
                          onclick="openActionModal(<?= (int)$b['booking_id'] ?>, 'reject_booking', 'Tolak tempahan <?= htmlspecialchars(addslashes($b['booking_no'])) ?>?', 'Tolak', true)">
                          <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" /></svg>
                        </button>
                      <?php endif; ?>

                      <?php if ($canManage && $b['status'] === 'Approved'): ?>
                        <button type="button" class="btn btn-ghost btn-xs text-info" title="Tandakan Selesai"
                          onclick="openActionModal(<?= (int)$b['booking_id'] ?>, 'complete_booking', 'Tandakan tempahan <?= htmlspecialchars(addslashes($b['booking_no'])) ?> sebagai selesai?', 'Selesai', false)">
                          <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                        </button>
                      <?php endif; ?>

                      <?php if (in_array($b['status'], ['Pending', 'Approved'], true) && ((int)$b['user_id'] === $currentUserId || $canManage)): ?>
                        <button type="button" class="btn btn-ghost btn-xs text-slate-500" title="Batalkan"
                          onclick="openActionModal(<?= (int)$b['booking_id'] ?>, 'cancel_booking', 'Batalkan tempahan <?= htmlspecialchars(addslashes($b['booking_no'])) ?>?', 'Batalkan', true)">
                          <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M18.364 18.364A9 9 0 105.636 5.636a9 9 0 0012.728 12.728z" /></svg>
                        </button>
                      <?php endif; ?>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>

          <?php if ($totalRows > 0): ?>
          <div class="flex items-center justify-between gap-3 flex-wrap mt-4 pt-4 border-t" style="border-color:var(--ta-border)">
            <p class="text-xs text-slate-400">
              Menunjukkan <?= $offset + 1 ?>–<?= min($offset + $perPage, $totalRows) ?> daripada <?= $totalRows ?> tempahan
            </p>
            <div class="join">
              <a href="<?= $page > 1 ? htmlspecialchars(buildBookingsPageUrl($page - 1, $search, $statusFilter)) : '#' ?>"
                 class="join-item btn btn-sm <?= $page <= 1 ? 'btn-disabled opacity-40' : '' ?>">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.2"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5L8.25 12l7.5-7.5" /></svg>
              </a>
              <span class="join-item btn btn-sm btn-disabled !bg-transparent !border-none font-semibold" style="color:var(--ta-ink)">
                <?= $page ?> / <?= $totalPages ?>
              </span>
              <a href="<?= $page < $totalPages ? htmlspecialchars(buildBookingsPageUrl($page + 1, $search, $statusFilter)) : '#' ?>"
                 class="join-item btn btn-sm <?= $page >= $totalPages ? 'btn-disabled opacity-40' : '' ?>">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.2"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5" /></svg>
              </a>
            </div>
          </div>
          <?php endif; ?>
        </div>

        <!-- Modal: Luluskan & Tugaskan Pemandu -->
        <dialog id="modal-approve" class="modal">
          <div class="modal-box card max-w-md">
            <form method="dialog"><button class="btn btn-sm btn-circle btn-ghost absolute right-3 top-3">✕</button></form>
            <h3 class="font-bold text-lg mb-1">Luluskan Tempahan</h3>
            <p class="text-sm text-slate-400 mb-4">No. Tempahan: <span id="approve-booking-no" class="font-semibold"></span></p>
            <?php if (empty($assignableDrivers)): ?>
              <p class="text-sm text-slate-400">Tiada pemandu yang tersedia buat masa ini. Sila kemaskini status pemandu di halaman Pemandu terlebih dahulu.</p>
              <div class="modal-action mt-2">
                <button type="button" class="btn btn-ghost" onclick="document.getElementById('modal-approve').close()">Tutup</button>
              </div>
            <?php else: ?>
            <form action="bookings.php<?= $search !== '' || $statusFilter !== 'All' ? '?' . http_build_query(array_filter(['q' => $search !== '' ? $search : null, 'status' => $statusFilter !== 'All' ? $statusFilter : null])) : '' ?>" method="POST" class="flex flex-col gap-3">
              <input type="hidden" name="action" value="approve_booking" />
              <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>" />
              <input type="hidden" name="booking_id" id="approve-booking-id" />
              <div>
                <label class="text-xs font-medium block mb-1">Tugaskan Pemandu</label>
                <select name="driver_id" id="approve-driver-select" required class="select select-bordered w-full" onchange="updateApproveVehiclePreview()">
                  <option value="">— Pilih Pemandu —</option>
                  <?php foreach ($assignableDrivers as $ad): ?>
                    <?php
                      $vehicleLabel = $ad['vehicle_id']
                          ? trim(($ad['vehicle_name'] ?: '') . ' — ' . $ad['plate_no'])
                          : 'Tiada kenderaan ditugaskan';
                    ?>
                    <option value="<?= (int)$ad['driver_id'] ?>" data-vehicle="<?= htmlspecialchars($vehicleLabel) ?>" <?= !$ad['vehicle_id'] ? 'disabled' : '' ?>>
                      <?= htmlspecialchars($ad['fullname']) ?> — <?= htmlspecialchars($ad['vehicle_name'] ?: 'Jenis Kenderaan Tidak Diketahui') ?><?= !$ad['vehicle_id'] ? ' (tiada kenderaan)' : '' ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="rounded-lg p-3 text-xs flex items-center gap-2" style="background:var(--ta-canvas)">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 shrink-0" style="color:var(--ta-muted)" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 18.75a1.5 1.5 0 01-3 0m3 0a1.5 1.5 0 00-3 0m3 0h6m-9 0H3.375a1.125 1.125 0 01-1.125-1.125V14.25m17.25 4.5a1.5 1.5 0 01-3 0m3 0a1.5 1.5 0 00-3 0m3 0h1.125c.621 0 1.129-.504 1.09-1.124a17.902 17.902 0 00-3.213-9.193 2.056 2.056 0 00-1.58-.86H14.25M16.5 18.75h-2.25m0-11.177v-.958c0-.568-.422-1.048-.987-1.106a48.554 48.554 0 00-10.026 0 1.106 1.106 0 00-.987 1.106v7.635m12-6.677v6.677m0 0h-12" /></svg>
                <span>Kenderaan ditugaskan: <span id="approve-vehicle-preview" class="font-semibold" style="color:var(--ta-ink)">—</span></span>
              </div>
              <div class="modal-action mt-2">
                <button type="button" class="btn btn-ghost" onclick="document.getElementById('modal-approve').close()">Batal</button>
                <button type="submit" class="btn text-white border-0" style="background:var(--ta-brand)">Luluskan Tempahan</button>
              </div>
            </form>
            <?php endif; ?>
          </div>
          <form method="dialog" class="modal-backdrop"><button>close</button></form>
        </dialog>

        <!-- Modal: Sahkan Tindakan Status -->
        <dialog id="modal-action" class="modal">
          <div class="modal-box card max-w-sm">
            <h3 class="font-bold text-lg mb-2" id="action-modal-title">Sahkan Tindakan</h3>
            <p class="text-sm text-slate-400 mb-4" id="action-modal-text"></p>
            <form action="bookings.php<?= $search !== '' || $statusFilter !== 'All' ? '?' . http_build_query(array_filter(['q' => $search !== '' ? $search : null, 'status' => $statusFilter !== 'All' ? $statusFilter : null])) : '' ?>" method="POST" class="flex justify-end gap-2">
              <input type="hidden" name="action" id="action-modal-action" />
              <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>" />
              <input type="hidden" name="booking_id" id="action-modal-id" />
              <button type="button" class="btn btn-ghost" onclick="document.getElementById('modal-action').close()">Batal</button>
              <button type="submit" id="action-modal-submit" class="btn text-white border-0" style="background:var(--ta-brand)">Sahkan</button>
            </form>
          </div>
          <form method="dialog" class="modal-backdrop"><button>close</button></form>
        </dialog>

    </div>
</main>

<?php include 'includes/layout_footer.php'; ?>
