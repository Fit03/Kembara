<?php
// users.php — Pengurusan Pengguna (SuperAdmin sahaja)
session_start();
require_once __DIR__ . '/config/database.php';

// Dialihkan ke log masuk jika pengguna belum diabsahkan
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$fullname   = $_SESSION['fullname'];
$role       = $_SESSION['role']; // 'SuperAdmin', 'Admin', atau 'User'
$currentUserId = (int)$_SESSION['user_id'];

// Halaman ini khusus untuk SuperAdmin sahaja
if ($role !== 'SuperAdmin') {
    header("Location: dashboard.php");
    exit();
}

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

$roleBadge = fn(string $r) => match ($r) {
  'SuperAdmin' => 'badge badge-error',
  'Admin'      => 'badge badge-warning',
  default      => 'badge badge-info',
};

/* ==========================================================
   Tindakan Borang (Tambah / Kemaskini / Padam)
   ========================================================== */
$flash = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'add_user') {
            $fn   = trim($_POST['fullname'] ?? '');
            $em   = trim($_POST['email'] ?? '');
            $ph   = trim($_POST['phone_no'] ?? '') ?: null;
            $dept = $_POST['department_id'] !== '' ? (int)$_POST['department_id'] : null;
            $pw   = $_POST['password'] ?? '';
            $rl   = $_POST['role'] ?? 'User';

            if ($fn === '' || $em === '' || $pw === '') {
                throw new RuntimeException('Nama penuh, e-mel dan kata laluan wajib diisi.');
            }
            if (!in_array($rl, ['SuperAdmin', 'Admin', 'User'], true)) {
                throw new RuntimeException('Peranan tidak sah.');
            }

            $hash = password_hash($pw, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare(
                "INSERT INTO users (fullname, email, phone_no, department_id, password, role)
                 VALUES (?, ?, ?, ?, ?, ?)"
            );
            $stmt->execute([$fn, $em, $ph, $dept, $hash, $rl]);

            $flash = ['type' => 'success', 'msg' => "Pengguna '{$fn}' berjaya didaftarkan."];

        } elseif ($action === 'edit_user') {
            $uid  = (int)($_POST['user_id'] ?? 0);
            $fn   = trim($_POST['fullname'] ?? '');
            $em   = trim($_POST['email'] ?? '');
            $ph   = trim($_POST['phone_no'] ?? '') ?: null;
            $dept = $_POST['department_id'] !== '' ? (int)$_POST['department_id'] : null;
            $rl   = $_POST['role'] ?? 'User';
            $pw   = $_POST['password'] ?? '';

            if ($uid <= 0 || $fn === '' || $em === '') {
                throw new RuntimeException('Data pengguna tidak lengkap.');
            }
            if (!in_array($rl, ['SuperAdmin', 'Admin', 'User'], true)) {
                throw new RuntimeException('Peranan tidak sah.');
            }
            if ($uid === $currentUserId && $rl !== 'SuperAdmin') {
                throw new RuntimeException('Anda tidak boleh menurunkan peranan akaun anda sendiri.');
            }

            if ($pw !== '') {
                $hash = password_hash($pw, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare(
                    "UPDATE users SET fullname=?, email=?, phone_no=?, department_id=?, role=?, password=?
                     WHERE user_id=?"
                );
                $stmt->execute([$fn, $em, $ph, $dept, $rl, $hash, $uid]);
            } else {
                $stmt = $pdo->prepare(
                    "UPDATE users SET fullname=?, email=?, phone_no=?, department_id=?, role=?
                     WHERE user_id=?"
                );
                $stmt->execute([$fn, $em, $ph, $dept, $rl, $uid]);
            }

            $flash = ['type' => 'success', 'msg' => "Maklumat '{$fn}' berjaya dikemaskini."];

        } elseif ($action === 'delete_user') {
            $uid = (int)($_POST['user_id'] ?? 0);

            if ($uid === $currentUserId) {
                throw new RuntimeException('Anda tidak boleh memadam akaun anda sendiri.');
            }

            $stmt = $pdo->prepare("SELECT fullname FROM users WHERE user_id = ?");
            $stmt->execute([$uid]);
            $victimName = $stmt->fetchColumn();

            $del = $pdo->prepare("DELETE FROM users WHERE user_id = ?");
            $del->execute([$uid]);

            $flash = ['type' => 'success', 'msg' => "Pengguna '{$victimName}' berjaya dipadam."];
        }
    } catch (RuntimeException $e) {
        $flash = ['type' => 'error', 'msg' => $e->getMessage()];
    } catch (PDOException $e) {
        // Kebiasaannya disebabkan e-mel pendua atau rekod berkaitan (cth. pemandu, tempahan)
        if ($e->getCode() === '23000') {
            $flash = ['type' => 'error', 'msg' => 'Operasi gagal — e-mel mungkin telah wujud, atau pengguna ini mempunyai rekod berkaitan (cth. pemandu / tempahan).'];
        } else {
            $flash = ['type' => 'error', 'msg' => 'Ralat pangkalan data berlaku. Sila cuba lagi.'];
        }
    }

    $_SESSION['flash'] = $flash;
    header("Location: users.php" . (isset($_GET['q']) ? '?q=' . urlencode($_GET['q']) : ''));
    exit();
}

// Ambil mesej flash daripada sesi (selepas redirect)
if (isset($_SESSION['flash'])) {
    $flash = $_SESSION['flash'];
    unset($_SESSION['flash']);
}

/* ==========================================================
   Data Halaman
   ========================================================== */
$departments = $pdo->query("SELECT department_id, department_name FROM departments ORDER BY department_name")
    ->fetchAll(PDO::FETCH_ASSOC);

$search = trim($_GET['q'] ?? '');
$perPage = 10;
$page    = max(1, (int)($_GET['page'] ?? 1));
$offset  = ($page - 1) * $perPage;

function buildUsersPageUrl(int $p, string $search): string {
    $params = ['page' => $p];
    if ($search !== '') {
        $params['q'] = $search;
    }
    return 'users.php?' . http_build_query($params);
}

if ($search !== '') {
    $like = "%{$search}%";

    $countStmt = $pdo->prepare(
        "SELECT COUNT(*) FROM users u
         LEFT JOIN departments d ON d.department_id = u.department_id
         WHERE u.fullname LIKE :like1 OR u.email LIKE :like2 OR d.department_name LIKE :like3"
    );
    $countStmt->execute([':like1' => $like, ':like2' => $like, ':like3' => $like]);
    $totalRows = (int)$countStmt->fetchColumn();

    $stmt = $pdo->prepare(
        "SELECT u.*, d.department_name
         FROM users u
         LEFT JOIN departments d ON d.department_id = u.department_id
         WHERE u.fullname LIKE :like1 OR u.email LIKE :like2 OR d.department_name LIKE :like3
         ORDER BY u.created_at DESC
         LIMIT :limit OFFSET :offset"
    );
    $stmt->bindValue(':like1', $like);
    $stmt->bindValue(':like2', $like);
    $stmt->bindValue(':like3', $like);
    $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
} else {
    $totalRows = (int)$pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();

    $stmt = $pdo->prepare(
        "SELECT u.*, d.department_name
         FROM users u
         LEFT JOIN departments d ON d.department_id = u.department_id
         ORDER BY u.created_at DESC
         LIMIT :limit OFFSET :offset"
    );
    $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
}
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);

$totalPages = max(1, (int)ceil($totalRows / $perPage));
if ($page > $totalPages) {
    $page = $totalPages;
}

$roleCounts = $pdo->query("SELECT role, COUNT(*) AS total FROM users GROUP BY role")
    ->fetchAll(PDO::FETCH_KEY_PAIR);
$totalUsers      = array_sum($roleCounts);
$totalSuperAdmin = (int)($roleCounts['SuperAdmin'] ?? 0);
$totalAdmin      = (int)($roleCounts['Admin'] ?? 0);
$totalStaff      = (int)($roleCounts['User'] ?? 0);

$pendingApprovals = $pdo->query(
    "SELECT COUNT(*) FROM vehicle_bookings WHERE status = 'Pending'"
)->fetchColumn();
?>
<!DOCTYPE html>
<html lang="ms" garden>
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0, shrink-to-fit=no" />
    <title>Kembara - Pengguna</title>
    <link rel="icon" type="image/png" href="assets/img/favicon.png" />

    <!-- Font: Outfit -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700&display=swap" rel="stylesheet" />

    <!-- Tailwind CSS CDN & daisyUI Framework (v5) -->
    <link href="https://cdn.jsdelivr.net/npm/daisyui@5" rel="stylesheet" type="text/css" />
    <link href="https://cdn.jsdelivr.net/npm/daisyui@5/themes.css" rel="stylesheet" type="text/css" />
    <script src="https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4"></script>

    <!-- Custom CSS -->
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
            <a class="ta-nav-link active" href="users.php">
              <svg class="ta-nav-icon" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19.128a9.38 9.38 0 002.625.372 9.337 9.337 0 004.121-.952 4.125 4.125 0 00-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 018.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0111.964-3.07M12 6.375a3.375 3.375 0 11-6.75 0 3.375 3.375 0 016.75 0zm8.25 2.25a2.625 2.625 0 11-5.25 0 2.625 2.625 0 015.25 0z" /></svg>
              <span>Pengguna</span>
            </a>
          </li>
          <?php endif; ?>
        </ul>

        <p class="mt-6 px-3 mb-2 text-[0.65rem] font-semibold uppercase tracking-widest text-slate-400">Log</p>
        <ul class="flex flex-col gap-1">
          <?php if (in_array($role, ['SuperAdmin', 'Admin'], true)): ?>
          <li>
            <a class="ta-nav-link" href="activity-log.php">
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
                style="background: linear-gradient(135deg, color-mix(in oklch, var(--ta-brand, #007AFF) 85%, white), var(--ta-brand, #007AFF));
                      color:#fff;
                      box-shadow: 0 12px 28px -6px color-mix(in oklch, var(--ta-brand, #007AFF) 50%, transparent),
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
              style="background: color-mix(in oklch, var(--ta-brand, #007AFF) 14%, rgba(255,255,255,0.55));
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
      <!-- Navigasi Atas -->
      <nav class="sticky top-0 z-50 flex items-center justify-between gap-4 px-4 sm:px-6 py-3 bg-white border-b" style="border-color:var(--ta-border)">
        <div class="flex items-center gap-3">
            <div>
                <h6 class="font-bold text-base leading-tight">Pengguna</h6>
                <p class="text-xs leading-tight" style="color:var(--ta-muted)">
                    <span class="opacity-70">Halaman</span>
                    <span class="mx-1 opacity-40">/</span>
                    <span>Pengguna</span>
                </p>
            </div>
        </div>

        <form action="users.php" method="GET" class="hidden md:flex items-center gap-2 rounded-lg px-3 py-2 flex-1 max-w-sm" style="background:var(--ta-canvas); border:1px solid var(--ta-border)">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 shrink-0" style="color:var(--ta-muted)" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607z" /></svg>
            <input type="text" name="q" value="<?= htmlspecialchars($search) ?>" placeholder="Cari nama, e-mel, jabatan..." class="bg-transparent border-0 outline-none text-sm w-full placeholder:text-slate-400" />
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
          <div class="card p-5" data-href="users.php">
            <div class="ta-icon-box mb-4">
              <svg xmlns="http://www.w3.org/2000/svg" class="h-5.5 w-5.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19.128a9.38 9.38 0 002.625.372 9.337 9.337 0 004.121-.952 4.125 4.125 0 00-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 018.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0111.964-3.07M12 6.375a3.375 3.375 0 11-6.75 0 3.375 3.375 0 016.75 0zm8.25 2.25a2.625 2.625 0 11-5.25 0 2.625 2.625 0 015.25 0z" /></svg>
            </div>
            <p class="text-sm mb-1" style="color:var(--ta-muted)">Jumlah Pengguna</p>
            <h5 class="text-2xl font-bold"><?= (int)$totalUsers ?></h5>
          </div>

          <div class="card p-5" data-href="users.php?q=SuperAdmin">
            <div class="ta-icon-box mb-4">
              <svg xmlns="http://www.w3.org/2000/svg" class="h-5.5 w-5.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75m5.25 2.25a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
            </div>
            <p class="text-sm mb-1" style="color:var(--ta-muted)">SuperAdmin</p>
            <h5 class="text-2xl font-bold"><?= $totalSuperAdmin ?></h5>
          </div>

          <div class="card p-5" data-href="users.php?q=Admin">
            <div class="ta-icon-box mb-4">
              <svg xmlns="http://www.w3.org/2000/svg" class="h-5.5 w-5.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6"><path stroke-linecap="round" stroke-linejoin="round" d="M9.75 3.104v5.714a2.25 2.25 0 01-.659 1.591L5 14.5M9.75 3.104c-.251.023-.501.05-.75.082m.75-.082a24.301 24.301 0 014.5 0m0 0v5.714c0 .597.237 1.17.659 1.591L19.8 15.3M14.25 3.104c.251.023.501.05.75.082M19.8 15.3l-1.57.393A9.065 9.065 0 0112 15a9.065 9.065 0 00-6.23-.693L5 14.5m14.8.8l1.402 1.402c1.232 1.232.65 3.318-1.067 3.611A48.309 48.309 0 0112 21c-2.773 0-5.491-.235-8.135-.687-1.718-.293-2.3-2.379-1.067-3.61L5 14.5" /></svg>
            </div>
            <p class="text-sm mb-1" style="color:var(--ta-muted)">Admin</p>
            <h5 class="text-2xl font-bold"><?= $totalAdmin ?></h5>
          </div>

          <div class="card p-5" data-href="users.php?q=User">
            <div class="ta-icon-box mb-4">
              <svg xmlns="http://www.w3.org/2000/svg" class="h-5.5 w-5.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6"><path stroke-linecap="round" stroke-linejoin="round" d="M17.982 18.725A7.488 7.488 0 0012 15.75a7.488 7.488 0 00-5.982 2.975m11.963 0a9 9 0 10-11.963 0m11.963 0A8.966 8.966 0 0112 21a8.966 8.966 0 01-5.982-2.275M15 9.75a3 3 0 11-6 0 3 3 0 016 0z" /></svg>
            </div>
            <p class="text-sm mb-1" style="color:var(--ta-muted)">Pengguna Biasa</p>
            <h5 class="text-2xl font-bold"><?= $totalStaff ?></h5>
          </div>
        </div>

        <!-- Baris 2: Jadual Pengguna -->
        <div class="card p-5 mt-5">
          <div class="flex items-center justify-between gap-3 mb-4 flex-wrap">
            <div>
              <h6 class="font-semibold">Senarai Pengguna</h6>
              <p class="mb-0 text-sm" style="color:var(--ta-muted)">Urus akaun kakitangan yang mempunyai akses ke sistem</p>
            </div>
            <button type="button" class="btn btn-sm text-white border-0 gap-1.5" style="background:var(--ta-brand)" onclick="openAddModal()">
              <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" /></svg>
              Tambah Pengguna
            </button>
          </div>

          <div class="overflow-x-auto -mx-1">
            <table class="items-center w-full mb-0 align-top">
              <thead>
                <tr class="border-b" style="border-color:var(--ta-border)">
                  <th class="px-3 py-2 text-xs font-semibold text-left uppercase text-slate-400">Pengguna</th>
                  <th class="px-3 py-2 text-xs font-semibold text-left uppercase text-slate-400">Jabatan</th>
                  <th class="px-3 py-2 text-xs font-semibold text-left uppercase text-slate-400">Telefon</th>
                  <th class="px-3 py-2 text-xs font-semibold text-center uppercase text-slate-400">Peranan</th>
                  <th class="px-3 py-2 text-xs font-semibold text-left uppercase text-slate-400">Didaftar</th>
                  <th class="px-3 py-2 text-xs font-semibold text-center uppercase text-slate-400">Tindakan</th>
                </tr>
              </thead>
              <tbody>
                <?php if (empty($users)): ?>
                  <tr><td colspan="6" class="px-3 py-6 text-sm text-center text-slate-400">Tiada pengguna dijumpai.</td></tr>
                <?php endif; ?>
                <?php foreach ($users as $u): ?>
                  <tr class="hover:bg-slate-50/70 transition-colors">
                    <td class="px-3 py-3 text-sm border-b whitespace-nowrap" style="border-color:var(--ta-border)">
                      <div class="flex items-center gap-2.5">
                          <?php
                            $avatarPath = $u['profile_picture'] ?? null;
                            $avatarExists = $avatarPath && is_file(__DIR__ . '/' . $avatarPath);
                          ?>
                          <?php if ($avatarExists): ?>
                            <div class="rounded-full w-8 h-8 overflow-hidden shrink-0">
                              <img src="<?= htmlspecialchars($avatarPath) ?>?v=<?= time() ?>" alt="Avatar" class="w-full h-full object-cover" />
                            </div>
                          <?php else: ?>
                            <div class="rounded-full w-8 h-8 flex items-center justify-center font-bold text-xs uppercase text-white shrink-0" style="background:var(--ta-brand)">
                              <?= htmlspecialchars(substr($u['fullname'], 0, 1)) ?>
                            </div>
                          <?php endif; ?>
                        <div class="min-w-0">
                          <p class="mb-0 font-medium truncate"><?= htmlspecialchars($u['fullname']) ?></p>
                          <p class="mb-0 text-xs text-slate-400 truncate"><?= htmlspecialchars($u['email']) ?></p>
                        </div>
                      </div>
                    </td>
                    <td class="px-3 py-3 text-sm border-b whitespace-nowrap" style="border-color:var(--ta-border)"><?= htmlspecialchars($u['department_name'] ?? '—') ?></td>
                    <td class="px-3 py-3 text-sm border-b whitespace-nowrap" style="border-color:var(--ta-border)"><?= htmlspecialchars($u['phone_no'] ?? '—') ?></td>
                    <td class="px-3 py-3 text-sm border-b text-center whitespace-nowrap" style="border-color:var(--ta-border)">
                      <span class="ta-badge <?= $roleBadge($u['role']) ?>"><?= htmlspecialchars($u['role']) ?></span>
                    </td>
                    <td class="px-3 py-3 text-sm border-b whitespace-nowrap" style="border-color:var(--ta-border)"><?= htmlspecialchars(date('d M Y', strtotime($u['created_at']))) ?></td>
                    <td class="px-3 py-3 text-sm border-b text-center whitespace-nowrap" style="border-color:var(--ta-border)">
                      <button type="button" class="btn btn-ghost btn-xs" title="Kemaskini"
                        onclick='openEditModal(<?= json_encode([
                            "user_id"       => (int)$u["user_id"],
                            "fullname"      => $u["fullname"],
                            "email"         => $u["email"],
                            "phone_no"      => $u["phone_no"],
                            "department_id" => $u["department_id"],
                            "role"          => $u["role"],
                        ], JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931z" /></svg>
                      </button>
                      <?php if ((int)$u['user_id'] !== $currentUserId): ?>
                        <button type="button" class="btn btn-ghost btn-xs text-error" title="Padam"
                          onclick="openDeleteModal(<?= (int)$u['user_id'] ?>, '<?= htmlspecialchars(addslashes($u['fullname'])) ?>')">
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
              Menunjukkan <?= $offset + 1 ?>–<?= min($offset + $perPage, $totalRows) ?> daripada <?= $totalRows ?> pengguna
            </p>
            <div class="join">
              <a href="<?= $page > 1 ? htmlspecialchars(buildUsersPageUrl($page - 1, $search)) : '#' ?>"
                 class="join-item btn btn-sm <?= $page <= 1 ? 'btn-disabled opacity-40' : '' ?>">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.2"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5L8.25 12l7.5-7.5" /></svg>
              </a>
              <span class="join-item btn btn-sm btn-disabled !bg-transparent !border-none font-semibold" style="color:var(--ta-ink)">
                <?= $page ?> / <?= $totalPages ?>
              </span>
              <a href="<?= $page < $totalPages ? htmlspecialchars(buildUsersPageUrl($page + 1, $search)) : '#' ?>"
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

    <!-- Modal: Tambah Pengguna -->
    <dialog id="modal-add" class="modal">
      <div class="modal-box card max-w-md">
        <form method="dialog"><button class="btn btn-sm btn-circle btn-ghost absolute right-3 top-3">✕</button></form>
        <h3 class="font-bold text-lg mb-4">Tambah Pengguna Baharu</h3>
        <form action="users.php<?= $search !== '' ? '?q=' . urlencode($search) : '' ?>" method="POST" class="flex flex-col gap-3">
          <input type="hidden" name="action" value="add_user" />
          <div>
            <label class="text-xs font-medium block mb-1">Nama Penuh</label>
            <input type="text" name="fullname" required class="input input-bordered w-full" />
          </div>
          <div>
            <label class="text-xs font-medium block mb-1">E-mel</label>
            <input type="email" name="email" required class="input input-bordered w-full" />
          </div>
          <div>
            <label class="text-xs font-medium block mb-1">No. Telefon</label>
            <input type="text" name="phone_no" class="input input-bordered w-full" />
          </div>
          <div>
            <label class="text-xs font-medium block mb-1">Jabatan</label>
            <select name="department_id" class="select select-bordered w-full">
              <option value="">— Tiada —</option>
              <?php foreach ($departments as $d): ?>
                <option value="<?= (int)$d['department_id'] ?>"><?= htmlspecialchars($d['department_name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div>
            <label class="text-xs font-medium block mb-1">Peranan</label>
            <select name="role" class="select select-bordered w-full">
              <option value="User">User</option>
              <option value="Admin">Admin</option>
              <option value="SuperAdmin">SuperAdmin</option>
            </select>
          </div>
          <div>
            <label class="text-xs font-medium block mb-1">Kata Laluan</label>
            <input type="password" name="password" required minlength="6" class="input input-bordered w-full" />
          </div>
          <div class="modal-action mt-2">
            <button type="button" class="btn btn-ghost" onclick="document.getElementById('modal-add').close()">Batal</button>
            <button type="submit" class="btn text-white border-0" style="background:var(--ta-brand)">Daftar Pengguna</button>
          </div>
        </form>
      </div>
      <form method="dialog" class="modal-backdrop"><button>close</button></form>
    </dialog>

    <!-- Modal: Kemaskini Pengguna -->
    <dialog id="modal-edit" class="modal">
      <div class="modal-box card max-w-md">
        <form method="dialog"><button class="btn btn-sm btn-circle btn-ghost absolute right-3 top-3">✕</button></form>
        <h3 class="font-bold text-lg mb-4">Kemaskini Pengguna</h3>
        <form action="users.php<?= $search !== '' ? '?q=' . urlencode($search) : '' ?>" method="POST" class="flex flex-col gap-3">
          <input type="hidden" name="action" value="edit_user" />
          <input type="hidden" name="user_id" id="edit-user-id" />
          <div>
            <label class="text-xs font-medium block mb-1">Nama Penuh</label>
            <input type="text" name="fullname" id="edit-fullname" required class="input input-bordered w-full" />
          </div>
          <div>
            <label class="text-xs font-medium block mb-1">E-mel</label>
            <input type="email" name="email" id="edit-email" required class="input input-bordered w-full" />
          </div>
          <div>
            <label class="text-xs font-medium block mb-1">No. Telefon</label>
            <input type="text" name="phone_no" id="edit-phone" class="input input-bordered w-full" />
          </div>
          <div>
            <label class="text-xs font-medium block mb-1">Jabatan</label>
            <select name="department_id" id="edit-department" class="select select-bordered w-full">
              <option value="">— Tiada —</option>
              <?php foreach ($departments as $d): ?>
                <option value="<?= (int)$d['department_id'] ?>"><?= htmlspecialchars($d['department_name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div>
            <label class="text-xs font-medium block mb-1">Peranan</label>
            <select name="role" id="edit-role" class="select select-bordered w-full">
              <option value="User">User</option>
              <option value="Admin">Admin</option>
              <option value="SuperAdmin">SuperAdmin</option>
            </select>
          </div>
          <div>
            <label class="text-xs font-medium block mb-1">Kata Laluan Baharu <span class="text-slate-400 font-normal">(kosongkan jika tiada perubahan)</span></label>
            <input type="password" name="password" minlength="6" class="input input-bordered w-full" />
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
        <h3 class="font-bold text-lg mb-2">Padam Pengguna?</h3>
        <p class="text-sm text-slate-400 mb-4">Anda pasti mahu memadam <span id="delete-user-name" class="font-semibold text-slate-600"></span>? Tindakan ini tidak boleh diundur.</p>
        <form action="users.php<?= $search !== '' ? '?q=' . urlencode($search) : '' ?>" method="POST" class="flex justify-end gap-2">
          <input type="hidden" name="action" value="delete_user" />
          <input type="hidden" name="user_id" id="delete-user-id" />
          <button type="button" class="btn btn-ghost" onclick="document.getElementById('modal-delete').close()">Batal</button>
          <button type="submit" class="btn btn-error text-white border-0">Padam</button>
        </form>
      </div>
      <form method="dialog" class="modal-backdrop"><button>close</button></form>
    </dialog>

    <script>
        function openAddModal() {
            document.getElementById('modal-add').showModal();
        }

        function openEditModal(u) {
            document.getElementById('edit-user-id').value    = u.user_id;
            document.getElementById('edit-fullname').value   = u.fullname;
            document.getElementById('edit-email').value      = u.email;
            document.getElementById('edit-phone').value      = u.phone_no || '';
            document.getElementById('edit-department').value = u.department_id || '';
            document.getElementById('edit-role').value       = u.role;
            document.getElementById('modal-edit').showModal();
        }

        function openDeleteModal(id, name) {
            document.getElementById('delete-user-id').value = id;
            document.getElementById('delete-user-name').textContent = name;
            document.getElementById('modal-delete').showModal();
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
    (function(){
      document.querySelectorAll('.card, .card').forEach(function(card){
        var href = card.dataset.href;
        if (href) {
          card.classList.add('clickable');
          card.addEventListener('click', function(e){
            if (e.target.closest('a, button, input, select, textarea')) return;
            window.location.href = href;
          });
          card.setAttribute('tabindex','0');
          card.addEventListener('keypress', function(e){ if (e.key === 'Enter') card.click(); });
        }
        if (card.dataset.priority === 'warning' || card.querySelector('.badge-warning') || /Menunggu|Pending/i.test(card.textContent)) {
          card.classList.add('flash-warning');
        }
      });
    })();
    </script>
</body>
</html>