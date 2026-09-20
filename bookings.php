<?php
// bookings.php — Pengurusan Tempahan Kenderaan
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/signature.php';

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

$sigStmt = $pdo->prepare("SELECT signature_path FROM users WHERE user_id = ?");
$sigStmt->execute([$currentUserId]);
$currentUserSignature = $sigStmt->fetchColumn() ?: null;
$hasSavedSignature = $currentUserSignature && is_file(__DIR__ . '/' . $currentUserSignature);

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
  $bookingStatusBadge = static function (array $booking) use ($statusBadge): string {
    return match ($booking['workflow_stage'] ?? null) {
      'DriverAssigned' => 'badge badge-warning',
      'ReassignmentRequired' => 'badge badge-error',
      'DriverAccepted' => 'badge badge-info',
      default => $statusBadge($booking['status']),
    };
  };
  $bookingStatusLabel = static function (array $booking) use ($statusLabel, $currentUserId): string {
    if (($booking['workflow_stage'] ?? null) === 'DriverAssigned'
      && (int)($booking['driver_user_id'] ?? 0) === $currentUserId) {
      return 'Menunggu Respons Anda';
    }
    return match ($booking['workflow_stage'] ?? null) {
      'DriverAssigned' => 'Menunggu Pemandu Terima',
      'ReassignmentRequired' => 'Perlu Tugasan Semula',
      'DriverAccepted' => 'Menunggu Kelulusan',
      default => $statusLabel($booking['status']),
    };
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
$redirectAfterPost = null;

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

              $requesterSignaturePath = null;
              $signatureMode = $_POST['signature_mode'] ?? 'new';
              if ($signatureMode === 'saved') {
                if (!$hasSavedSignature) {
                  throw new RuntimeException('Tiada tandatangan tersimpan. Sila sediakan tandatangan baharu.');
                }
                $requesterSignaturePath = $currentUserSignature;
              } else {
                if (!empty($_FILES['signature_file']['name']) && $_FILES['signature_file']['error'] === UPLOAD_ERR_OK) {
                  $requesterSignaturePath = save_signature_upload($_FILES['signature_file'], $currentUserId);
                  $pdo->prepare("UPDATE users SET signature_path = ? WHERE user_id = ?")->execute([$requesterSignaturePath, $currentUserId]);
                } elseif (!empty($_POST['signature_data'])) {
                  $requesterSignaturePath = save_signature_dataurl($_POST['signature_data'], $currentUserId);
                  $pdo->prepare("UPDATE users SET signature_path = ? WHERE user_id = ?")->execute([$requesterSignaturePath, $currentUserId]);
                } else {
                  throw new RuntimeException('Sila sediakan tandatangan sebelum menghantar tempahan.');
                }
              }

            $bookingNo = generateBookingNo($pdo);

            // Pemandu & kenderaan belum ditetapkan — ditugaskan oleh Admin/SuperAdmin semasa kelulusan
            $stmt = $pdo->prepare(
                "INSERT INTO vehicle_bookings
                 (booking_no, user_id, depart_datetime, return_datetime, trip_type, origin, destination,
                  passenger_total, passenger_names, passenger_memo_path, purpose, requester_signature_path, status)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Pending')"
            );
            $stmt->execute([
                $bookingNo, $currentUserId, $departRaw, $returnRaw !== '' ? $returnRaw : null,
                $tripType, $origin, $dest, $pax > 0 ? $pax : null, $passengerNamesJson, $passenger_memo_path, $purpose, $requesterSignaturePath,
            ]);
            $newId = (int)$pdo->lastInsertId();

            $hist = $pdo->prepare("INSERT INTO booking_history (booking_id, action, remarks, action_by) VALUES (?, 'Dicipta', ?, ?)");
            $hist->execute([$newId, "Tempahan {$bookingNo} dicipta.", $currentUserId]);

            $flash = ['type' => 'success', 'msg' => "Tempahan {$bookingNo} berjaya dihantar dan menunggu kelulusan."];
            $redirectAfterPost = 'view.php?id=' . $newId;

        } elseif (in_array($action, ['assign_driver', 'approve_booking', 'driver_accept', 'driver_reject', 'reject_booking', 'cancel_booking', 'complete_booking', 'delete_booking'], true)) {
            $bid = (int)($_POST['booking_id'] ?? 0);
            if ($bid <= 0) {
                throw new RuntimeException('Tempahan tidak sah.');
            }

            $pdo->beginTransaction();

            $bstmt = $pdo->prepare(
              "SELECT vb.*, dr.user_id AS assigned_driver_user_id
               FROM vehicle_bookings vb
               LEFT JOIN drivers dr ON dr.driver_id = vb.driver_id
               WHERE vb.booking_id = ? FOR UPDATE"
            );
            $bstmt->execute([$bid]);
            $booking = $bstmt->fetch(PDO::FETCH_ASSOC);
            if (!$booking) {
                throw new RuntimeException('Tempahan tidak dijumpai.');
            }

            if ($action === 'delete_booking') {
              if (!$canManage) throw new RuntimeException('Anda tidak mempunyai kebenaran untuk memadam tempahan.');

              if (!empty($booking['vehicle_id'])) {
                $pdo->prepare("UPDATE vehicles SET status='Available' WHERE vehicle_id=?")->execute([$booking['vehicle_id']]);
              }
              $pdo->prepare("DELETE FROM booking_history WHERE booking_id=?")->execute([$bid]);
              $pdo->prepare("DELETE FROM vehicle_bookings WHERE booking_id=?")->execute([$bid]);

              $flash = ['type' => 'success', 'msg' => "Tempahan {$booking['booking_no']} telah dipadam."];

            } elseif ($action === 'assign_driver') {
              if (!$canManage) throw new RuntimeException('Anda tidak mempunyai kebenaran untuk menetapkan pemandu.');
              if ($booking['status'] !== 'Pending' || !in_array($booking['workflow_stage'], ['Submitted', 'ReassignmentRequired'], true)) {
                throw new RuntimeException('Hanya tempahan yang menunggu penetapan pemandu boleh diproses.');
              }

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

                $signatureMode = $_POST['signature_mode'] ?? 'new';
                if ($signatureMode === 'saved') {
                  if (!$hasSavedSignature) {
                    throw new RuntimeException('Tiada tandatangan tersimpan. Sila sediakan tandatangan baharu.');
                  }
                  $approverSignaturePath = $currentUserSignature;
                } elseif (!empty($_FILES['signature_file']['name']) && $_FILES['signature_file']['error'] === UPLOAD_ERR_OK) {
                  $approverSignaturePath = save_signature_upload($_FILES['signature_file'], $currentUserId, $currentUserSignature);
                  $pdo->prepare("UPDATE users SET signature_path = ? WHERE user_id = ?")->execute([$approverSignaturePath, $currentUserId]);
                } elseif (!empty($_POST['signature_data'])) {
                  $approverSignaturePath = save_signature_dataurl($_POST['signature_data'], $currentUserId, $currentUserSignature);
                  $pdo->prepare("UPDATE users SET signature_path = ? WHERE user_id = ?")->execute([$approverSignaturePath, $currentUserId]);
                } else {
                  throw new RuntimeException('Sila sediakan tandatangan sebelum menetapkan pemandu.');
                }

                $upd = $pdo->prepare(
                  "UPDATE vehicle_bookings SET status='Pending', workflow_stage='DriverAssigned', driver_id=?, vehicle_id=?, approved_by=?, approved_at=NULL, approver_signature_path=?
                     WHERE booking_id=?"
                );
                $upd->execute([$driverId, $drv['vehicle_id'], $currentUserId, $approverSignaturePath, $bid]);
                $pdo->prepare("UPDATE vehicles SET status='Booked' WHERE vehicle_id=?")->execute([$drv['vehicle_id']]);
                $pdo->prepare("INSERT INTO booking_history (booking_id, action, remarks, action_by) VALUES (?, 'Driver Assigned', 'Pemandu telah ditugaskan dan menunggu pengesahan.', ?)")->execute([$bid, $currentUserId]);

                $flash = ['type' => 'success', 'msg' => "Pemandu telah ditugaskan untuk tempahan {$booking['booking_no']}."];

              } elseif ($action === 'approve_booking') {
                if (!$canManage) throw new RuntimeException('Anda tidak mempunyai kebenaran untuk meluluskan tempahan.');
                if ($booking['status'] !== 'Pending' || $booking['workflow_stage'] !== 'DriverAccepted') {
                  throw new RuntimeException('Tempahan hanya boleh diluluskan selepas pemandu menerima tugasan.');
                }

                $signatureMode = $_POST['signature_mode'] ?? 'new';
                if ($signatureMode === 'saved') {
                  if (!$hasSavedSignature) {
                    throw new RuntimeException('Tiada tandatangan tersimpan. Sila sediakan tandatangan baharu.');
                  }
                  $approverSignaturePath = $currentUserSignature;
                } else {
                  if (!empty($_FILES['signature_file']['name']) && $_FILES['signature_file']['error'] === UPLOAD_ERR_OK) {
                    $approverSignaturePath = save_signature_upload($_FILES['signature_file'], $currentUserId, $currentUserSignature);
                  } elseif (!empty($_POST['signature_data'])) {
                    $approverSignaturePath = save_signature_dataurl($_POST['signature_data'], $currentUserId, $currentUserSignature);
                  } else {
                    throw new RuntimeException('Sila sediakan tandatangan sebelum meluluskan.');
                  }
                  $pdo->prepare("UPDATE users SET signature_path = ? WHERE user_id = ?")->execute([$approverSignaturePath, $currentUserId]);
                }

                $pdo->prepare(
                  "UPDATE vehicle_bookings
                   SET status='Approved', approved_by=?, approved_at=NOW(), approver_signature_path=?
                   WHERE booking_id=?"
                )->execute([$currentUserId, $approverSignaturePath, $bid]);
                $pdo->prepare("UPDATE vehicles SET status='Booked' WHERE vehicle_id=?")->execute([$booking['vehicle_id']]);
                $pdo->prepare("INSERT INTO booking_history (booking_id, action, remarks, action_by) VALUES (?, 'Diluluskan', 'Admin meluluskan tempahan selepas pemandu menerima tugasan.', ?)")->execute([$bid, $currentUserId]);
                $flash = ['type' => 'success', 'msg' => "Tempahan {$booking['booking_no']} telah diluluskan."];

              } elseif ($action === 'driver_accept' || $action === 'driver_reject') {
                if ((int)($booking['assigned_driver_user_id'] ?? 0) !== $currentUserId) {
                  throw new RuntimeException('Hanya pemandu yang ditugaskan boleh memberi respons kepada tugasan ini.');
                }
                if ($booking['status'] !== 'Pending' || $booking['workflow_stage'] !== 'DriverAssigned') {
                  throw new RuntimeException('Tugasan ini tidak lagi menunggu respons pemandu.');
                }

                if ($action === 'driver_accept') {
                  $pdo->prepare(
                    "UPDATE vehicle_bookings
                    SET status='Approved', workflow_stage='AdminApproved', approved_at=NOW()
                    WHERE booking_id=?"
                  )->execute([$bid]);
                  $pdo->prepare("UPDATE vehicles SET status='Booked' WHERE vehicle_id=?")->execute([$booking['vehicle_id']]);
                  $pdo->prepare("INSERT INTO booking_history (booking_id, action, remarks, action_by) VALUES (?, 'Driver Accepted', 'Pemandu menerima tugasan. Tempahan diluluskan.', ?)")->execute([$bid, $currentUserId]);
                  $flash = ['type' => 'success', 'msg' => "Tugasan diterima. Tempahan {$booking['booking_no']} telah diluluskan."];
                } else {
                  $pdo->prepare(
                    "UPDATE vehicle_bookings
                     SET status='Pending', workflow_stage='ReassignmentRequired', driver_id=NULL, vehicle_id=NULL, approved_by=NULL, approved_at=NULL, approver_signature_path=NULL
                     WHERE booking_id=?"
                  )->execute([$bid]);
                  $pdo->prepare("UPDATE vehicles SET status='Available' WHERE vehicle_id=?")->execute([$booking['vehicle_id']]);
                  $pdo->prepare("INSERT INTO booking_history (booking_id, action, remarks, action_by) VALUES (?, 'Driver Rejected', 'Pemandu menolak tugasan. Menunggu penetapan pemandu baharu.', ?)")->execute([$bid, $currentUserId]);
                  $flash = ['type' => 'success', 'msg' => "Tugasan ditolak. Admin perlu menetapkan pemandu baharu untuk {$booking['booking_no']}."];
                }

            } elseif ($action === 'reject_booking') {
                if (!$canManage) throw new RuntimeException('Anda tidak mempunyai kebenaran untuk menolak tempahan.');
                if ($booking['status'] !== 'Pending' || $booking['workflow_stage'] === 'DriverAssigned') throw new RuntimeException('Tempahan ini sedang menunggu respons pemandu dan tidak boleh ditolak oleh admin pada masa ini.');

                $pdo->prepare("UPDATE vehicle_bookings SET status='Rejected', workflow_stage='AdminRejected', approved_by=?, approved_at=NOW() WHERE booking_id=?")->execute([$currentUserId, $bid]);
                $pdo->prepare("INSERT INTO booking_history (booking_id, action, remarks, action_by) VALUES (?, 'Ditolak', 'Tempahan ditolak.', ?)")->execute([$bid, $currentUserId]);

                $flash = ['type' => 'success', 'msg' => "Tempahan {$booking['booking_no']} telah ditolak."];

            } elseif ($action === 'cancel_booking') {
                $isOwner = ((int)$booking['user_id'] === $currentUserId);
                if (!$isOwner && !$canManage) throw new RuntimeException('Anda tidak mempunyai kebenaran untuk membatalkan tempahan ini.');
                if (!in_array($booking['status'], ['Pending', 'Approved'], true)) throw new RuntimeException('Tempahan ini tidak boleh dibatalkan.');

                $pdo->prepare("UPDATE vehicle_bookings SET status='Cancelled', workflow_stage='Cancelled' WHERE booking_id=?")->execute([$bid]);
                if (!empty($booking['vehicle_id'])) {
                    $pdo->prepare("UPDATE vehicles SET status='Available' WHERE vehicle_id=?")->execute([$booking['vehicle_id']]);
                }
                $pdo->prepare("INSERT INTO booking_history (booking_id, action, remarks, action_by) VALUES (?, 'Dibatalkan', 'Tempahan dibatalkan.', ?)")->execute([$bid, $currentUserId]);

                $flash = ['type' => 'success', 'msg' => "Tempahan {$booking['booking_no']} telah dibatalkan."];

            } elseif ($action === 'complete_booking') {
                if (!$canManage) throw new RuntimeException('Anda tidak mempunyai kebenaran untuk menamatkan tempahan.');
                if ($booking['status'] !== 'Approved') throw new RuntimeException('Hanya tempahan berstatus Diluluskan boleh ditamatkan.');

                $pdo->prepare("UPDATE vehicle_bookings SET status='Completed', workflow_stage='Completed' WHERE booking_id=?")->execute([$bid]);
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
    if ($redirectAfterPost !== null) {
      header("Location: {$redirectAfterPost}");
      exit();
    }
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
  $where[] = '(vb.user_id = :uid OR EXISTS (
    SELECT 1
    FROM drivers assigned_driver
    WHERE assigned_driver.driver_id = vb.driver_id
      AND assigned_driver.user_id = :driver_uid
  ))';
    $params[':uid'] = $currentUserId;
  $params[':driver_uid'] = $currentUserId;
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
          dr.user_id AS driver_user_id,
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
          document.getElementById("approve-booking-action").value = "assign_driver";
          document.getElementById("approve-modal-title").textContent = "Tugaskan Pemandu";
          document.getElementById("approve-modal-submit").textContent = "Tugaskan Pemandu";
          document.getElementById("approve-driver-fields").classList.remove("hidden");
            document.getElementById("approve-signature-fields").classList.remove("hidden");
            document.getElementById("approve-booking-id").value = id;
            document.getElementById("approve-booking-no").textContent = bookingNo;
            const select = document.getElementById("approve-driver-select");
            if (select) select.value = "";
            const preview = document.getElementById("approve-vehicle-preview");
            if (preview) preview.textContent = "—";

            const savedRadio = document.querySelector(\'input[name="signature_mode"][value="saved"]\');
            if (savedRadio) savedRadio.checked = true;
            toggleApproveSignatureMode();

            const fileInput = document.getElementById("approve-signature-file");
            if (fileInput) fileInput.value = "";
            const dataInput = document.getElementById("approve-signature-data");
            if (dataInput) dataInput.value = "";
            const sigPreview = document.getElementById("approve-signature-preview");
            if (sigPreview) sigPreview.textContent = "Tiada tandatangan dipilih.";

            document.getElementById("modal-approve").showModal();
        }

          function openFinalApproveModal(id, bookingNo) {
            document.getElementById("approve-booking-action").value = "approve_booking";
            document.getElementById("approve-modal-title").textContent = "Luluskan Tempahan";
            document.getElementById("approve-modal-submit").textContent = "Luluskan Tempahan";
            document.getElementById("approve-driver-fields").classList.add("hidden");
            document.getElementById("approve-signature-fields").classList.remove("hidden");
            document.getElementById("approve-booking-id").value = id;
            document.getElementById("approve-booking-no").textContent = bookingNo;

            const savedRadio = document.querySelector(\'input[name="signature_mode"][value="saved"]\');
            if (savedRadio) savedRadio.checked = true;
            toggleApproveSignatureMode();
            const fileInput = document.getElementById("approve-signature-file");
            if (fileInput) fileInput.value = "";
            const dataInput = document.getElementById("approve-signature-data");
            if (dataInput) dataInput.value = "";
            const sigPreview = document.getElementById("approve-signature-preview");
            if (sigPreview) sigPreview.textContent = "Tiada tandatangan dipilih.";
            document.getElementById("modal-approve").showModal();
          }

        function updateApproveVehiclePreview() {
            const select = document.getElementById("approve-driver-select");
            const opt = select.options[select.selectedIndex];
            document.getElementById("approve-vehicle-preview").textContent = (opt && opt.dataset.vehicle) ? opt.dataset.vehicle : "—";
        }

        function toggleApproveSignatureMode() {
            const selected = document.querySelector(\'input[name="signature_mode"]:checked\');
            const fields = document.getElementById("approve-signature-new-fields");
            if (!fields) return;
            fields.classList.toggle("hidden", selected && selected.value === "saved");
        }

        function previewApproveSignatureFile(input) {
            const preview = document.getElementById("approve-signature-preview");
            const dataInput = document.getElementById("approve-signature-data");
            if (dataInput) dataInput.value = "";
            if (preview) preview.textContent = (input.files && input.files[0]) ? input.files[0].name : "Tiada tandatangan dipilih.";
        }

        let apprSigCtx, apprSigDrawing = false, apprSigHasStroke = false;

        function openApproveSignaturePad() {
            document.getElementById("modal-approve-signature-pad").showModal();
            requestAnimationFrame(initApproveSignaturePad);
        }

        function initApproveSignaturePad() {
            const canvas = document.getElementById("approve-signature-pad-canvas");
            if (!canvas) return;
            const rect = canvas.getBoundingClientRect();
            const ratio = window.devicePixelRatio || 1;
            canvas.width = rect.width * ratio;
            canvas.height = 220 * ratio;
            apprSigCtx = canvas.getContext("2d");
            apprSigCtx.scale(ratio, ratio);
            apprSigCtx.lineWidth = 2.2;
            apprSigCtx.lineCap = "round";
            apprSigCtx.strokeStyle = "#1e293b";
            apprSigCtx.fillStyle = "#ffffff";
            apprSigCtx.fillRect(0, 0, rect.width, 220);
            apprSigHasStroke = false;

            function pos(e) {
                const r = canvas.getBoundingClientRect();
                const t = e.touches ? e.touches[0] : e;
                return { x: t.clientX - r.left, y: t.clientY - r.top };
            }
            function start(e) {
                e.preventDefault();
                apprSigDrawing = true;
                const p = pos(e);
                apprSigCtx.beginPath();
                apprSigCtx.moveTo(p.x, p.y);
            }
            function move(e) {
                if (!apprSigDrawing) return;
                e.preventDefault();
                const p = pos(e);
                apprSigCtx.lineTo(p.x, p.y);
                apprSigCtx.stroke();
                apprSigHasStroke = true;
            }
            function end() { apprSigDrawing = false; }

            canvas.onmousedown = start;
            canvas.onmousemove = move;
            canvas.onmouseup = end;
            canvas.onmouseleave = end;
            canvas.ontouchstart = start;
            canvas.ontouchmove = move;
            canvas.ontouchend = end;
        }

        function clearApproveSignaturePad() {
            const canvas = document.getElementById("approve-signature-pad-canvas");
            if (!canvas || !apprSigCtx) return;
            const rect = canvas.getBoundingClientRect();
            apprSigCtx.fillStyle = "#ffffff";
            apprSigCtx.fillRect(0, 0, rect.width, 220);
            apprSigHasStroke = false;
        }

        function saveApproveSignaturePad() {
            if (!apprSigHasStroke) {
                alert("Sila tandatangan dahulu.");
                return;
            }
            const canvas = document.getElementById("approve-signature-pad-canvas");
            document.getElementById("approve-signature-data").value = canvas.toDataURL("image/png");
            const fileInput = document.getElementById("approve-signature-file");
            if (fileInput) fileInput.value = "";
            const preview = document.getElementById("approve-signature-preview");
            if (preview) preview.textContent = "Tandatangan dilukis sedia untuk dihantar.";
            document.getElementById("modal-approve-signature-pad").close();
        }

        function validateApproveForm() {
            const selected = document.querySelector(\'input[name="signature_mode"]:checked\');
            const mode = selected ? selected.value : "new";
            if (mode === "saved") return true;

            const fileInput = document.getElementById("approve-signature-file");
            const dataInput = document.getElementById("approve-signature-data");
            const hasFile = fileInput && fileInput.files && fileInput.files.length > 0;
            const hasDrawn = dataInput && dataInput.value !== "";
            if (!hasFile && !hasDrawn) {
                alert("Sila muat naik atau lukis tandatangan sebelum meluluskan.");
                return false;
            }
            return true;
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
                  <tr class="hover:bg-slate-50/70 transition-colors" data-href="view.php?id=<?= (int)$b['booking_id'] ?>&amp;from=<?= urlencode($_SERVER['QUERY_STRING'] ?? '') ?>">
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
                      <span class="ta-badge <?= $bookingStatusBadge($b) ?>"><?= htmlspecialchars($bookingStatusLabel($b)) ?></span>
                    </td>
                    <td class="px-3 py-3 text-sm border-b text-center whitespace-nowrap" style="border-color:var(--ta-border)">
                      <a href="view.php?id=<?= (int)$b['booking_id'] ?><?= ($qs = $_SERVER['QUERY_STRING'] ?? '') !== '' ? '&from=' . urlencode($qs) : '' ?>" class="btn btn-ghost btn-xs" title="Lihat Butiran">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 010-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178z" /><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" /></svg>
                      </a>

                      <?php if ($role === 'User' && (int)($b['driver_user_id'] ?? 0) === $currentUserId && $b['status'] === 'Pending' && $b['workflow_stage'] === 'DriverAssigned'): ?>
                        <button type="button" class="btn btn-ghost btn-xs text-success" title="Terima Tugasan"
                          onclick="openActionModal(<?= (int)$b['booking_id'] ?>, 'driver_accept', 'Terima tugasan untuk tempahan <?= htmlspecialchars(addslashes($b['booking_no'])) ?>?', 'Terima', false)">
                          <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75L9 17.25 19.5 6.75" /></svg>
                        </button>
                        <button type="button" class="btn btn-ghost btn-xs text-error" title="Tolak Tugasan"
                          onclick="openActionModal(<?= (int)$b['booking_id'] ?>, 'driver_reject', 'Tolak tugasan untuk tempahan <?= htmlspecialchars(addslashes($b['booking_no'])) ?>?', 'Tolak', true)">
                          <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" /></svg>
                        </button>
                      <?php endif; ?>
                      <?php if ($canManage && $b['status'] === 'Pending' && in_array($b['workflow_stage'], ['Submitted', 'ReassignmentRequired'], true)): ?>
                        <button type="button" class="btn btn-ghost btn-xs text-success" title="Tugaskan Pemandu"
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
                      <?php if ($canManage): ?>
                        <button type="button" class="btn btn-ghost btn-xs text-error" title="Padam"
                          onclick="openActionModal(<?= (int)$b['booking_id'] ?>, 'delete_booking', 'Padam tempahan <?= htmlspecialchars(addslashes($b['booking_no'])) ?>? Tindakan ini tidak boleh dibuat asal.', 'Padam', true)">
                          <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M14.74 9l-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 01-2.244 2.077H8.084a2.25 2.25 0 01-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 00-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 013.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 00-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 00-7.5 0" /></svg>
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

        <!-- Modal: Tugaskan Pemandu -->
        <dialog id="modal-approve" class="modal">
          <div class="modal-box card max-w-md">
            <form method="dialog"><button class="btn btn-sm btn-circle btn-ghost absolute right-3 top-3">✕</button></form>
            <h3 id="approve-modal-title" class="font-bold text-lg mb-1">Tugaskan Pemandu</h3>
            <p class="text-sm text-slate-400 mb-4">No. Tempahan: <span id="approve-booking-no" class="font-semibold"></span></p>
            <form action="bookings.php<?= $search !== '' || $statusFilter !== 'All' ? '?' . http_build_query(array_filter(['q' => $search !== '' ? $search : null, 'status' => $statusFilter !== 'All' ? $statusFilter : null])) : '' ?>" method="POST" enctype="multipart/form-data" class="flex flex-col gap-3">
              <input type="hidden" name="action" id="approve-booking-action" value="assign_driver" />
              <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>" />
              <input type="hidden" name="booking_id" id="approve-booking-id" />
              <div id="approve-driver-fields">
                <?php if (empty($assignableDrivers)): ?>
                  <p class="text-sm text-slate-400 mb-2">Tiada pemandu yang tersedia buat masa ini.</p>
                <?php endif; ?>
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

              <div id="approve-signature-fields" class="border-t pt-3" style="border-color:var(--ta-border)">
                <label class="text-xs font-medium block mb-2">Tandatangan Pelulus <span class="text-error">*</span></label>
                <?php if ($hasSavedSignature): ?>
                  <label class="flex items-center gap-2 mb-2 p-2 rounded-lg border cursor-pointer" style="border-color:var(--ta-border)">
                    <input type="radio" name="signature_mode" value="saved" class="radio radio-sm" checked onchange="toggleApproveSignatureMode()" />
                    <img src="<?= htmlspecialchars($currentUserSignature) ?>?v=<?= time() ?>" alt="Tandatangan Tersimpan" class="h-8 object-contain" />
                    <span class="text-xs">Guna tandatangan tersimpan</span>
                  </label>
                  <label class="flex items-center gap-2 mb-2 text-xs cursor-pointer">
                    <input type="radio" name="signature_mode" value="new" class="radio radio-sm" onchange="toggleApproveSignatureMode()" />
                    Tandatangan baharu
                  </label>
                <?php else: ?>
                  <input type="hidden" name="signature_mode" value="new" />
                  <p class="text-xs text-slate-400 mb-2">Sila lukis atau muat naik tandatangan.</p>
                <?php endif; ?>
                <div id="approve-signature-new-fields" class="<?= $hasSavedSignature ? 'hidden' : '' ?> flex flex-col gap-2">
                  <div class="flex gap-2">
                    <label for="approve-signature-file" class="btn btn-sm btn-outline gap-1.5 flex-1 cursor-pointer">Muat Naik</label>
                    <button type="button" class="btn btn-sm btn-outline gap-1.5 flex-1" onclick="openApproveSignaturePad()">Lukis</button>
                  </div>
                  <input type="file" name="signature_file" id="approve-signature-file" accept=".jpg,.jpeg,.png" class="hidden" onchange="previewApproveSignatureFile(this)" />
                  <input type="hidden" name="signature_data" id="approve-signature-data" />
                  <div id="approve-signature-preview" class="text-xs text-slate-400">Tiada tandatangan dipilih.</div>
                </div>
              </div>
              <div class="rounded-lg p-3 text-xs flex items-center gap-2" style="background:var(--ta-canvas)">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 shrink-0" style="color:var(--ta-muted)" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 18.75a1.5 1.5 0 01-3 0m3 0a1.5 1.5 0 00-3 0m3 0h6m-9 0H3.375a1.125 1.125 0 01-1.125-1.125V14.25m17.25 4.5a1.5 1.5 0 01-3 0m3 0a1.5 1.5 0 00-3 0m3 0h1.125c.621 0 1.129-.504 1.09-1.124a17.902 17.902 0 00-3.213-9.193 2.056 2.056 0 00-1.58-.86H14.25M16.5 18.75h-2.25m0-11.177v-.958c0-.568-.422-1.048-.987-1.106a48.554 48.554 0 00-10.026 0 1.106 1.106 0 00-.987 1.106v7.635m12-6.677v6.677m0 0h-12" /></svg>
                <span>Kenderaan ditugaskan: <span id="approve-vehicle-preview" class="font-semibold" style="color:var(--ta-ink)">—</span></span>
              </div>

              <div class="modal-action mt-2">
                <button type="button" class="btn btn-ghost" onclick="document.getElementById('modal-approve').close()">Batal</button>
                <button type="submit" id="approve-modal-submit" class="btn text-white border-0" style="background:var(--ta-brand)" onclick="return validateApproveForm()">Tugaskan Pemandu</button>
              </div>
            </form>
          </div>
          <form method="dialog" class="modal-backdrop"><button>close</button></form>
        </dialog>

        <!-- Modal: Lukis Tandatangan Pelulus -->
        <dialog id="modal-approve-signature-pad" class="modal">
          <div class="modal-box card max-w-lg">
            <form method="dialog"><button class="btn btn-sm btn-circle btn-ghost absolute right-3 top-3">✕</button></form>
            <h3 class="font-bold text-lg mb-1">Lukis Tandatangan</h3>
            <p class="text-sm text-slate-400 mb-3">Gunakan tetikus atau jari untuk menandatangani di ruang bawah.</p>
            <div class="rounded-xl border" style="border-color:var(--ta-border); background:#fff;">
              <canvas id="approve-signature-pad-canvas" style="width:100%; height:220px; display:block; touch-action:none; cursor:crosshair;"></canvas>
            </div>
            <div class="modal-action mt-3 justify-between">
              <button type="button" class="btn btn-ghost btn-sm" onclick="clearApproveSignaturePad()">Padam</button>
              <div class="flex gap-2">
                <button type="button" class="btn btn-ghost" onclick="document.getElementById('modal-approve-signature-pad').close()">Batal</button>
                <button type="button" class="btn text-white border-0" style="background:var(--ta-brand)" onclick="saveApproveSignaturePad()">Guna Tandatangan Ini</button>
              </div>
            </div>
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