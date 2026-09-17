<?php
// profile.php — Profil Saya
require_once __DIR__ . '/includes/auth.php';
require_login();
require_once __DIR__ . '/config/database.php';


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
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        http_response_code(403);
        exit('Ralat Keselamatan: Token CSRF tidak sah atau telah tamat tempoh.');
    }
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
                throw new RuntimeException('Sila pilih fail imej yang sah untuk muat naik.');
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

            $imgInfo = getimagesize($file['tmp_name']);
            if ($imgInfo === false) {
                throw new RuntimeException('Fail yang dimuat naik bukan imej yang sah.');
            }

            if (!is_dir($UPLOAD_DIR)) {
                if (!mkdir($UPLOAD_DIR, 0755, true)) {
                    throw new RuntimeException('Gagal mencipta direktori muat naik.');
                }
            }

            // Buang foto lama jika wujud
            $stmt = $pdo->prepare("SELECT profile_picture FROM users WHERE user_id = ?");
            $stmt->execute([$currentUserId]);
            $oldPath = $stmt->fetchColumn();
            if ($oldPath && is_file(__DIR__ . '/' . $oldPath)) {
                if (!unlink(__DIR__ . '/' . $oldPath)) {
                    error_log("Gagal memadam fail foto profil lama: " . $oldPath);
                }
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
                if (!unlink(__DIR__ . '/' . $oldPath)) {
                    error_log("Gagal memadam fail foto profil lama: " . $oldPath);
                }
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

// --- Layout Config ---
$pageTitle = "Profil Saya";
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
              <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>" />
              <div class="avatar-ring">
                <?php if ($hasPhoto): ?>
                  <img src="<?= htmlspecialchars($user['profile_picture']) ?>?v=<?= time() ?>" alt="Foto Profil" />
                <?php else: ?>
                  <div class="avatar-fallback"><?= htmlspecialchars(substr($fullname, 0, 1)) ?></div>
                <?php endif; ?>
                <label for="photo-input" class="avatar-edit-btn" title="Tukar Foto">
                  <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6.827 6.175A2.31 2.31 0 015.186 7.23c-.38.054-.757.112-1.134.175C2.999 7.58 2.25 8.507 2.25 9.574V18a2.25 2.25 0 002.25 2.25h15A2.25 2.25 0 0021.75 18V9.574c0-1.067-.75-1.994-1.802-2.169a47.865 47.865 0 00-1.134-.175a2.31 2.31 0 01-1.64-1.055l-.822-1.316a2.192 2.192 0 00-1.736-1.039 48.774 48.774 0 00-5.232 0 2.192 2.192 0 00-1.736 1.039l-.821 1.316z" /><path stroke-linecap="round" stroke-linejoin="round" d="M16.5 12.75a4.5 4.5 0 11-9 0 4.5 4.5 0 019 0z" /></svg>
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
                  <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>" />
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
              <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>" />
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
              <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>" />
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

    </div>
</main>

<?php
include 'includes/layout_footer.php';
?>
