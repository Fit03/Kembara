<?php
// profile.php — Profil Saya
session_start();
require_once __DIR__ . '/config/database.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$currentUserId = (int)$_SESSION['user_id'];
$role           = $_SESSION['role'];

$badgeColor = match($role) {
  'SuperAdmin' => 'badge badge-error',
  'Admin'      => 'badge badge-warning',
  default      => 'badge badge-info',
};

$UPLOAD_DIR = __DIR__ . '/assets/uploads/avatars';
$UPLOAD_URL = 'assets/uploads/avatars';

/* ==========================================================
   Tindakan Borang
   ========================================================== */
$flash = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'update_profile') {
            $fn = trim($_POST['fullname'] ?? '');
            $em = trim($_POST['email'] ?? '');
            $ph = trim($_POST['phone_no'] ?? '') ?: null;

            if ($fn === '' || $em === '') {
                throw new RuntimeException('Nama penuh dan e-mel wajib diisi.');
            }
            if (!filter_var($em, FILTER_VALIDATE_EMAIL)) {
                throw new RuntimeException('Format e-mel tidak sah.');
            }

            $stmt = $pdo->prepare("UPDATE users SET fullname = ?, email = ?, phone_no = ? WHERE user_id = ?");
            $stmt->execute([$fn, $em, $ph, $currentUserId]);

            $_SESSION['fullname'] = $fn;
            $_SESSION['email']    = $em;

            $flash = ['type' => 'success', 'msg' => 'Maklumat peribadi berjaya dikemaskini.'];

        } elseif ($action === 'change_password') {
            $current = $_POST['current_password'] ?? '';
            $new     = $_POST['new_password'] ?? '';
            $confirm = $_POST['confirm_password'] ?? '';

            if ($current === '' || $new === '' || $confirm === '') {
                throw new RuntimeException('Sila lengkapkan semua medan kata laluan.');
            }
            if (strlen($new) < 6) {
                throw new RuntimeException('Kata laluan baharu mestilah sekurang-kurangnya 6 aksara.');
            }
            if ($new !== $confirm) {
                throw new RuntimeException('Pengesahan kata laluan baharu tidak sepadan.');
            }

            $stmt = $pdo->prepare("SELECT password FROM users WHERE user_id = ?");
            $stmt->execute([$currentUserId]);
            $hash = $stmt->fetchColumn();

            if (!$hash || !password_verify($current, $hash)) {
                throw new RuntimeException('Kata laluan semasa tidak tepat.');
            }

            $newHash = password_hash($new, PASSWORD_DEFAULT);
            $pdo->prepare("UPDATE users SET password = ? WHERE user_id = ?")->execute([$newHash, $currentUserId]);

            $flash = ['type' => 'success', 'msg' => 'Kata laluan berjaya dikemaskini.'];

        } elseif ($action === 'upload_photo') {
            if (empty($_FILES['photo']) || $_FILES['photo']['error'] !== UPLOAD_ERR_OK) {
                throw new RuntimeException('Sila pilih fail imej yang sah untuk draculauat naik.');
            }

            $file = $_FILES['photo'];
            $allowedExt = ['jpg', 'jpeg', 'png', 'webp'];
            $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

            if (!in_array($ext, $allowedExt, true)) {
                throw new RuntimeException('Format fail tidak disokong. Sila muat naik JPG, PNG atau WEBP.');
            }
            if ($file['size'] > 2 * 1024 * 1024) {
                throw new RuntimeException('Saiz fail melebihi had 2MB.');
            }

            $imgInfo = @getimagesize($file['tmp_name']);
            if ($imgInfo === false) {
                throw new RuntimeException('Fail yang draculauat naik bukan imej yang sah.');
            }

            if (!is_dir($UPLOAD_DIR)) {
                @mkdir($UPLOAD_DIR, 0755, true);
            }

            // Buang foto lama jika wujud
            $stmt = $pdo->prepare("SELECT profile_picture FROM users WHERE user_id = ?");
            $stmt->execute([$currentUserId]);
            $oldPath = $stmt->fetchColumn();
            if ($oldPath && is_file(__DIR__ . '/' . $oldPath)) {
                @unlink(__DIR__ . '/' . $oldPath);
            }

            $newFilename = 'user_' . $currentUserId . '_' . time() . '.' . $ext;
            $destPath    = $UPLOAD_DIR . '/' . $newFilename;

            if (!move_uploaded_file($file['tmp_name'], $destPath)) {
                throw new RuntimeException('Gagal memuat naik fail. Sila cuba lagi.');
            }

            $relativePath = $UPLOAD_URL . '/' . $newFilename;
            $pdo->prepare("UPDATE users SET profile_picture = ? WHERE user_id = ?")->execute([$relativePath, $currentUserId]);
            $_SESSION['profile_picture'] = $relativePath;

            $flash = ['type' => 'success', 'msg' => 'Foto profil berjaya dikemaskini.'];

        } elseif ($action === 'remove_photo') {
            $stmt = $pdo->prepare("SELECT profile_picture FROM users WHERE user_id = ?");
            $stmt->execute([$currentUserId]);
            $oldPath = $stmt->fetchColumn();
            if ($oldPath && is_file(__DIR__ . '/' . $oldPath)) {
                @unlink(__DIR__ . '/' . $oldPath);
            }
            $pdo->prepare("UPDATE users SET profile_picture = NULL WHERE user_id = ?")->execute([$currentUserId]);
            unset($_SESSION['profile_picture']);

            $flash = ['type' => 'success', 'msg' => 'Foto profil telah dibuang.'];
        }
    } catch (RuntimeException $e) {
        $flash = ['type' => 'error', 'msg' => $e->getMessage()];
    } catch (PDOException $e) {
        $flash = ['type' => 'error', 'msg' => $e->getCode() === '23000'
            ? 'E-mel ini telah digunakan oleh akaun lain.'
            : 'Ralat pangkalan data berlaku. Sila cuba lagi.'];
    }

    $_SESSION['flash'] = $flash;
    header("Location: profile.php");
    exit();
}

if (isset($_SESSION['flash'])) {
    $flash = $_SESSION['flash'];
    unset($_SESSION['flash']);
}

/* ==========================================================
   Data Halaman
   ========================================================== */
$stmt = $pdo->prepare(
    "SELECT u.*, d.department_name
     FROM users u
     LEFT JOIN departments d ON d.department_id = u.department_id
     WHERE u.user_id = ?"
);
$stmt->execute([$currentUserId]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    header("Location: logout.php");
    exit();
}

$fullname = $user['fullname'];
$_SESSION['fullname'] = $fullname;
$_SESSION['email']    = $user['email'];
if ($user['profile_picture']) {
    $_SESSION['profile_picture'] = $user['profile_picture'];
}

$hasPhoto = $user['profile_picture'] && is_file(__DIR__ . '/' . $user['profile_picture']);

$pendingApprovals = $pdo->query("SELECT COUNT(*) FROM vehicle_bookings WHERE status = 'Pending'")->fetchColumn();
?>
<!DOCTYPE html>
<html lang="ms" garden>
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0, shrink-to-fit=no" />
    <title>Kembara - Profil Saya</title>
    <link rel="icon" type="image/png" href="assets/img/favicon.png" />

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700&display=swap" rel="stylesheet" />

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
            <a class="ta-nav-link" href="users.php">
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
              <a href="log-aktiviti.php" class="flex items-center gap-2.5 px-3 py-2.5 rounded-xl text-sm font-medium transition-colors" style="color: var(--ta-text, #1c1c1e)">
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
        else if (currentPath.includes('users') || currentPath.includes('log-aktiviti')) activeItem = document.getElementById('nav-more');

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
                <h6 class="font-bold text-base leading-tight">Profil Saya</h6>
                <p class="text-xs leading-tight" style="color:var(--ta-muted)">
                    <a href="dashboard.php" class="opacity-70 hover:opacity-100 hover:underline">Halaman</a>
                    <span class="mx-1 opacity-40">/</span>
                    <span>Profil Saya</span>
                </p>
            </div>
        </div>

            <div class="flex items-center gap-1.5 sm:gap-2 ml-auto">
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
                                <div class="rounded-full w-8 h-8"><img src="<?= htmlspecialchars($user['profile_picture']) ?>" alt="Avatar" /></div>
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
                            <p class="text-xs text-slate-400 mt-1 truncate"><?= htmlspecialchars($user['email']) ?></p>
                        </li>
                        <li><a href="profile.php" class="py-2.5 text-xs font-medium">Profil Saya</a></li>
                        <div class="divider my-1"></div>
                        <li><a href="logout.php" class="py-2.5 text-xs font-semibold text-red-600">Log Keluar</a></li>
                    </ul>
                </div>
            </div>
      </nav>

      <div class="w-full px-4 sm:px-6 py-6 mx-auto max-w-4xl">

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

        <!-- Kad Foto Profil -->
        <div class="card p-5 sm:p-6 mb-5">
          <div class="flex flex-col sm:flex-row items-center sm:items-start gap-5">
            <form action="profile.php" method="POST" enctype="multipart/form-data" id="photo-form">
              <input type="hidden" name="action" value="upload_photo" />
              <div class="avatar-ring">
                <?php if ($hasPhoto): ?>
                  <img src="<?= htmlspecialchars($user['profile_picture']) ?>?v=<?= time() ?>" alt="Foto Profil" />
                <?php else: ?>
                  <div class="avatar-fallback"><?= htmlspecialchars(substr($fullname, 0, 1)) ?></div>
                <?php endif; ?>
                <label for="photo-input" class="avatar-edit-btn" title="Tukar Foto">
                  <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6.827 6.175A2.31 2.31 0 015.186 7.23c-.38.054-.757.112-1.134.175C2.999 7.58 2.25 8.507 2.25 9.574V18a2.25 2.25 0 002.25 2.25h15A2.25 2.25 0 0021.75 18V9.574c0-1.067-.75-1.994-1.802-2.169a47.865 47.865 0 00-1.134-.175 2.31 2.31 0 01-1.64-1.055l-.822-1.316a2.192 2.192 0 00-1.736-1.039 48.774 48.774 0 00-5.232 0 2.192 2.192 0 00-1.736 1.039l-.821 1.316z" /><path stroke-linecap="round" stroke-linejoin="round" d="M16.5 12.75a4.5 4.5 0 11-9 0 4.5 4.5 0 019 0z" /></svg>
                </label>
                <input type="file" name="photo" id="photo-input" accept=".jpg,.jpeg,.png,.webp" class="hidden" onchange="document.getElementById('photo-form').submit()" />
              </div>
            </form>

            <div class="flex-1 text-center sm:text-left">
              <div class="flex items-center justify-center sm:justify-start gap-2 flex-wrap">
                <h5 class="text-lg font-bold"><?= htmlspecialchars($fullname) ?></h5>
                <span class="ta-badge <?= $badgeColor ?>"><?= htmlspecialchars($role) ?></span>
              </div>
              <p class="text-sm text-slate-400 mt-0.5"><?= htmlspecialchars($user['email']) ?></p>
              <p class="text-xs text-slate-400 mt-1"><?= htmlspecialchars($user['department_name'] ?? 'Tiada jabatan ditetapkan') ?> &middot; Berdaftar sejak <?= htmlspecialchars(date('d M Y', strtotime($user['created_at']))) ?></p>

              <div class="flex items-center justify-center sm:justify-start gap-2 mt-3">
                <label for="photo-input" class="btn btn-sm text-white border-0 gap-1.5" style="background:var(--ta-brand)">
                  <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5m-13.5-9L12 3m0 0l4.5 4.5M12 3v13.5" /></svg>
                  Muat Naik Foto
                </label>
                <?php if ($hasPhoto): ?>
                <form action="profile.php" method="POST" onsubmit="return confirm('Buang foto profil semasa?');">
                  <input type="hidden" name="action" value="remove_photo" />
                  <button type="submit" class="btn btn-sm btn-ghost text-error">Buang Foto</button>
                </form>
                <?php endif; ?>
              </div>
              <p class="text-xs text-slate-400 mt-2">JPG, PNG atau WEBP. Saiz maksimum 2MB.</p>
            </div>
          </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-5">
          <!-- Kad Maklumat Peribadi -->
          <div class="card p-5 sm:p-6">
            <h6 class="font-semibold mb-1">Maklumat Peribadi</h6>
            <p class="text-sm mb-4" style="color:var(--ta-muted)">Kemaskini nama, e-mel dan nombor telefon anda.</p>
            <form action="profile.php" method="POST" class="flex flex-col gap-3">
              <input type="hidden" name="action" value="update_profile" />
              <div>
                <label class="text-xs font-medium block mb-1">Nama Penuh</label>
                <input type="text" name="fullname" required value="<?= htmlspecialchars($user['fullname']) ?>" class="input input-bordered w-full" />
              </div>
              <div>
                <label class="text-xs font-medium block mb-1">E-mel</label>
                <input type="email" name="email" required value="<?= htmlspecialchars($user['email']) ?>" class="input input-bordered w-full" />
              </div>
              <div>
                <label class="text-xs font-medium block mb-1">No. Telefon</label>
                <input type="text" name="phone_no" value="<?= htmlspecialchars($user['phone_no'] ?? '') ?>" class="input input-bordered w-full" />
              </div>

              <div class="grid grid-cols-2 gap-3 mt-1">
                <div>
                  <label class="text-xs font-medium block mb-1">Jabatan</label>
                  <input type="text" disabled value="<?= htmlspecialchars($user['department_name'] ?? '—') ?>" class="input input-bordered w-full opacity-60" />
                </div>
                <div>
                  <label class="text-xs font-medium block mb-1">Peranan</label>
                  <input type="text" disabled value="<?= htmlspecialchars($role) ?>" class="input input-bordered w-full opacity-60" />
                </div>
              </div>
              <p class="text-xs text-slate-400 -mt-1">Jabatan &amp; peranan hanya boleh dikemaskini oleh SuperAdmin.</p>

              <div class="flex justify-end mt-2">
                <button type="submit" class="btn text-white border-0" style="background:var(--ta-brand)">Simpan Perubahan</button>
              </div>
            </form>
          </div>

          <!-- Kad Tukar Kata Laluan -->
          <div class="card p-5 sm:p-6">
            <h6 class="font-semibold mb-1">Tukar Kata Laluan</h6>
            <p class="text-sm mb-4" style="color:var(--ta-muted)">Gunakan kata laluan yang kukuh dan tidak digunakan di tempat lain.</p>
            <form action="profile.php" method="POST" class="flex flex-col gap-3">
              <input type="hidden" name="action" value="change_password" />
              <div>
                <label class="text-xs font-medium block mb-1">Kata Laluan Semasa</label>
                <input type="password" name="current_password" required class="input input-bordered w-full" />
              </div>
              <div>
                <label class="text-xs font-medium block mb-1">Kata Laluan Baharu</label>
                <input type="password" name="new_password" required minlength="6" class="input input-bordered w-full" />
              </div>
              <div>
                <label class="text-xs font-medium block mb-1">Sahkan Kata Laluan Baharu</label>
                <input type="password" name="confirm_password" required minlength="6" class="input input-bordered w-full" />
              </div>
              <p class="text-xs text-slate-400">Sekurang-kurangnya 6 aksara.</p>

              <div class="flex justify-end mt-2">
                <button type="submit" class="btn text-white border-0" style="background:var(--ta-brand)">Kemaskini Kata Laluan</button>
              </div>
            </form>
          </div>
        </div>

        <footer class="pt-6 pb-2">
          <div class="text-sm leading-normal text-center text-slate-400">
            © <?= date('Y') ?> Kembara &middot; Perbendaharaan Negeri Selangor
          </div>
        </footer>
      </div>
    </main>

    <script>
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
</body>
</html>