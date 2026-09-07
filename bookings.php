<?php
// bookings.php — Pengurusan Tempahan Kenderaan
session_start();
require_once __DIR__ . '/config/database.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

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
    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'add_booking') {
            $departRaw = trim($_POST['depart_datetime'] ?? '');
            $returnRaw = trim($_POST['return_datetime'] ?? '');
            $tripType  = $_POST['trip_type'] ?? 'One Way';
            $origin    = trim($_POST['origin'] ?? '');
            $dest      = trim($_POST['destination'] ?? '');
            $pax       = (int)($_POST['passenger_total'] ?? 0);
            $purpose   = trim($_POST['purpose'] ?? '');

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

            if ($departRaw === '' || $origin === '' || $dest === '' || $purpose === '') {
                throw new RuntimeException('Sila lengkapkan semua maklumat wajib tempahan.');
            }
            // NEW: Ensure they either typed names or uploaded a memo
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

            $bstmt = $pdo->prepare("SELECT * FROM vehicle_bookings WHERE booking_id = ?");
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
                     WHERE dr.driver_id = ?"
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
        }
    } catch (RuntimeException $e) {
        $flash = ['type' => 'error', 'msg' => $e->getMessage()];
    } catch (PDOException $e) {
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
?>
<!DOCTYPE html>
<html lang="ms" data-theme="garden">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0, shrink-to-fit=no" />
    <title>Kembara - Tempahan</title>
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

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700&display=swap" rel="stylesheet" />

    <link href="https://cdn.jsdelivr.net/npm/daisyui@5" rel="stylesheet" type="text/css" />
    <link href="https://cdn.jsdelivr.net/npm/daisyui@5/themes.css" rel="stylesheet" type="text/css" />
    <script src="https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4"></script>

    <link rel="stylesheet" href="assets/css/style.css" />

    <!-- remove the whole inline <style> ... </style> block from here -->
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
            <a class="ta-nav-link active" href="bookings.php">
              <svg class="ta-nav-icon" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 012.25-2.25h13.5A2.25 2.25 0 0121 7.5v11.25m-18 0A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75m-18 0v-7.5A2.25 2.25 0 015.25 9h13.5A2.25 2.25 0 0121 11.25v7.5m-13.5-6h.008v.008h-.008v-.008zm0 3h.008v.008h-.008v-.008zm3-3h.008v.008h-.008v-.008zm0 3h.008v.008h-.008v-.008z" /></svg>
              <span>Tempahan</span>
            </a>
          </li>
          <li>
            <a class="ta-nav-link" href="vehicles.php">
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

    <main class="xl:ml-64 relative min-h-screen pb-24 xl:pb-0">
      <!-- Navigasi Atas -->
      <nav class="sticky top-0 z-50 flex items-center justify-between gap-4 px-4 sm:px-6 py-3 bg-white border-b" style="border-color:var(--ta-border)">
        <div class="flex items-center gap-3">
            <div>
                <h6 class="font-bold text-base leading-tight">Tempahan</h6>
                <p class="text-xs leading-tight" style="color:var(--ta-muted)">
                    <span class="opacity-70">Halaman</span>
                    <span class="mx-1 opacity-40">/</span>
                    <span>Tempahan</span>
                </p>
            </div>
        </div>

        <form action="bookings.php" method="GET" class="hidden md:flex items-center gap-2 rounded-lg px-3 py-2 flex-1 max-w-sm" style="background:var(--ta-canvas); border:1px solid var(--ta-border)">
            <?php if ($statusFilter !== 'All'): ?><input type="hidden" name="status" value="<?= htmlspecialchars($statusFilter) ?>" /><?php endif; ?>
            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 shrink-0" style="color:var(--ta-muted)" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607z" /></svg>
            <input type="text" name="q" value="<?= htmlspecialchars($search) ?>" placeholder="Cari no. tempahan, destinasi..." class="bg-transparent border-0 outline-none text-sm w-full placeholder:text-slate-400" />
        </form>

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

        <footer class="pt-6 pb-2">
          <div class="text-sm leading-normal text-center text-slate-400">
            © <?= date('Y') ?> Kembara &middot; Perbendaharaan Negeri Selangor
          </div>
        </footer>
      </div>
    </main>

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
          <input type="hidden" name="booking_id" id="action-modal-id" />
          <button type="button" class="btn btn-ghost" onclick="document.getElementById('modal-action').close()">Batal</button>
          <button type="submit" id="action-modal-submit" class="btn text-white border-0" style="background:var(--ta-brand)">Sahkan</button>
        </form>
      </div>
      <form method="dialog" class="modal-backdrop"><button>close</button></form>
    </dialog>

    <script>
        function openApproveModal(id, bookingNo) {
            document.getElementById('approve-booking-id').value = id;
            document.getElementById('approve-booking-no').textContent = bookingNo;
            const select = document.getElementById('approve-driver-select');
            if (select) select.value = '';
            const preview = document.getElementById('approve-vehicle-preview');
            if (preview) preview.textContent = '—';
            document.getElementById('modal-approve').showModal();
        }

        function updateApproveVehiclePreview() {
            const select = document.getElementById('approve-driver-select');
            const opt = select.options[select.selectedIndex];
            document.getElementById('approve-vehicle-preview').textContent = (opt && opt.dataset.vehicle) ? opt.dataset.vehicle : '—';
        }

        function openActionModal(id, action, text, buttonLabel, isDanger) {
            document.getElementById('action-modal-id').value     = id;
            document.getElementById('action-modal-action').value = action;
            document.getElementById('action-modal-text').textContent = text;
            const submitBtn = document.getElementById('action-modal-submit');
            submitBtn.textContent = buttonLabel;
            submitBtn.style.background = isDanger ? 'var(--color-error)' : 'var(--ta-brand)';
            document.getElementById('modal-action').showModal();
        }

        function dismissToast() {
            const toast = document.getElementById('toast-alert');
            if (!toast) return;
            toast.classList.add('ta-toast-hide');
            toast.addEventListener('animationend', () => toast.remove(), { once: true });
        }

        (function () {
            if (document.getElementById('toast-alert')) {
                setTimeout(dismissToast, 4000);
            }
        })();

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

    <!-- Skrip Tukar Mod Tema Terang/Gelap -->
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

    <script src="./assets/js/plugins/perfect-scrollbar.min.js" async></script>
    <script>
    (function () {
        document.querySelectorAll('.card[data-href]').forEach(function (card) {
            card.classList.add('clickable');
            card.addEventListener('click', function (event) {
                if (event.target.closest('a, button, input, select, textarea')) return;
                window.location.href = card.dataset.href;
            });
            card.setAttribute('tabindex', '0');
            card.addEventListener('keypress', function (event) {
                if (event.key === 'Enter') {
                    card.click();
                }
            });
        });
    })();
    </script>
</body>
</html>