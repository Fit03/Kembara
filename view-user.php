<?php
// view-user.php - Paparan dan kemaskini pengguna
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/config/database.php';

require_login();
require_role(['SuperAdmin']);

$fullname = $_SESSION['fullname'] ?? '';
$role = $_SESSION['role'] ?? 'User';
$currentUserId = (int)($_SESSION['user_id'] ?? 0);

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
$badgeColor = 'badge badge-error';
$roleBadge = fn(string $userRole) => match ($userRole) {
    'SuperAdmin' => 'badge badge-error',
    'Admin' => 'badge badge-warning',
    default => 'badge badge-info',
};

$userId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
$mode = $_GET['mode'] ?? 'view';
if (!in_array($mode, ['view', 'edit'], true)) {
    $mode = 'view';
}

$userStmt = $pdo->prepare(
    'SELECT u.user_id, u.fullname, u.email, u.phone_no, u.department_id, u.role,
            u.profile_picture, u.created_at, d.department_name
     FROM users u
     LEFT JOIN departments d ON d.department_id = u.department_id
     WHERE u.user_id = ?'
);
$userStmt->execute([$userId]);
$user = $userStmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    $_SESSION['flash'] = ['type' => 'error', 'msg' => 'Pengguna tidak dijumpai.'];
    header('Location: users.php');
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        http_response_code(403);
        exit('Ralat Keselamatan: Token CSRF tidak sah atau telah tamat tempoh.');
    }

    $name = trim($_POST['fullname'] ?? '');
    $userEmail = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone_no'] ?? '') ?: null;
    $departmentId = ($_POST['department_id'] ?? '') !== '' ? (int)$_POST['department_id'] : null;
    $userRole = $_POST['role'] ?? 'User';
    $password = $_POST['password'] ?? '';

    try {
        if ($name === '' || $userEmail === '') {
            throw new RuntimeException('Nama penuh dan e-mel wajib diisi.');
        }
        if (!filter_var($userEmail, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Format e-mel tidak sah.');
        }
        if (!in_array($userRole, ['SuperAdmin', 'Admin', 'User'], true)) {
            throw new RuntimeException('Peranan tidak sah.');
        }
        if ($userId === $currentUserId && $userRole !== 'SuperAdmin') {
            throw new RuntimeException('Anda tidak boleh menurunkan peranan akaun anda sendiri.');
        }

        if ($password !== '') {
            if (strlen($password) < 6) {
                throw new RuntimeException('Kata laluan mestilah sekurang-kurangnya 6 aksara.');
            }
            $updateStmt = $pdo->prepare(
                'UPDATE users SET fullname=?, email=?, phone_no=?, department_id=?, role=?, password=? WHERE user_id=?'
            );
            $updateStmt->execute([$name, $userEmail, $phone, $departmentId, $userRole, password_hash($password, PASSWORD_DEFAULT), $userId]);
        } else {
            $updateStmt = $pdo->prepare(
                'UPDATE users SET fullname=?, email=?, phone_no=?, department_id=?, role=? WHERE user_id=?'
            );
            $updateStmt->execute([$name, $userEmail, $phone, $departmentId, $userRole, $userId]);
        }
        $_SESSION['flash'] = ['type' => 'success', 'msg' => "Maklumat '{$name}' berjaya dikemaskini."];
    } catch (RuntimeException $e) {
        $_SESSION['flash'] = ['type' => 'error', 'msg' => $e->getMessage()];
    } catch (PDOException $e) {
        $_SESSION['flash'] = ['type' => 'error', 'msg' => $e->getCode() === '23000'
            ? 'Operasi gagal — e-mel mungkin telah wujud.'
            : 'Ralat pangkalan data berlaku. Sila cuba lagi.'];
    }
    header('Location: view-user.php?id=' . $userId . '&mode=edit');
    exit();
}

$avatarPath = $user['profile_picture'] ?? null;
$avatarExists = $avatarPath && is_file(__DIR__ . '/' . $avatarPath);
$departments = $pdo->query('SELECT department_id, department_name FROM departments ORDER BY department_name')->fetchAll(PDO::FETCH_ASSOC);
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

$pageTitle = $mode === 'edit' ? 'Kemaskini Pengguna' : 'Butiran Pengguna';
$backUrl = 'users.php';
$breadcrumbs = [
    ['url' => 'dashboard.php', 'label' => 'Halaman'],
    ['url' => 'users.php', 'label' => 'Pengguna'],
    ['url' => '#', 'label' => $user['fullname']],
];
$showSearch = false;

include 'includes/layout_header.php';
?>
<main class="xl:ml-64 relative min-h-screen pb-24 xl:pb-0">
    <?php include 'includes/top_nav.php'; ?>
    <div class="w-full max-w-4xl px-4 sm:px-6 py-6 mx-auto">
        <?php if ($flash): ?>
        <div class="alert <?= $flash['type'] === 'success' ? 'alert-success' : 'alert-error' ?> mb-5 text-sm"><?= htmlspecialchars($flash['msg']) ?></div>
        <?php endif; ?>

        <div class="card overflow-hidden mb-5">
            <div class="p-6 sm:p-8" style="background:linear-gradient(135deg, var(--ta-brand-50), var(--ta-surface))">
                <div class="flex items-center justify-between gap-4 flex-wrap">
                    <div class="flex items-center gap-4 min-w-0">
                        <?php if ($avatarExists): ?>
                            <div class="rounded-full w-16 h-16 overflow-hidden shrink-0 border-2" style="border-color:var(--ta-brand)"><img src="<?= htmlspecialchars($avatarPath) ?>?v=<?= time() ?>" alt="Avatar <?= htmlspecialchars($user['fullname']) ?>" class="w-full h-full object-cover" /></div>
                        <?php else: ?>
                            <div class="rounded-full w-16 h-16 flex items-center justify-center font-bold text-2xl uppercase text-white shrink-0" style="background:var(--ta-brand)"><?= htmlspecialchars(substr($user['fullname'], 0, 1)) ?></div>
                        <?php endif; ?>
                        <div class="min-w-0">
                            <p class="text-xs uppercase tracking-widest mb-1" style="color:var(--ta-muted)">Profil Pengguna</p>
                            <h1 class="text-2xl sm:text-3xl font-bold truncate"><?= htmlspecialchars($user['fullname']) ?></h1>
                            <p class="text-sm mt-1" style="color:var(--ta-muted)"><?= htmlspecialchars($user['email']) ?></p>
                        </div>
                    </div>
                    <div class="flex items-center gap-2 shrink-0">
                        <span class="ta-badge <?= $roleBadge($user['role']) ?>"><?= htmlspecialchars($user['role']) ?></span>
                        <?php if ($mode === 'view'): ?><a href="view-user.php?id=<?= $userId ?>&amp;mode=edit" class="btn btn-sm btn-outline">Kemaskini</a><?php else: ?><a href="view-user.php?id=<?= $userId ?>" class="btn btn-sm btn-outline">Lihat Profil</a><?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <?php if ($mode === 'edit'): ?>
        <div class="card p-6 sm:p-8">
            <p class="text-xs uppercase tracking-widest mb-1" style="color:var(--ta-muted)">Maklumat Akaun</p>
            <h2 class="text-xl font-semibold mb-1">Kemaskini pengguna</h2>
            <p class="text-sm mb-5" style="color:var(--ta-muted)">Ubah maklumat akaun, jabatan, peranan atau kata laluan.</p>
            <form method="POST" class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>" />
                <div><label class="text-xs font-medium block mb-1">Nama Penuh</label><input type="text" name="fullname" required class="input input-bordered w-full" value="<?= htmlspecialchars($user['fullname']) ?>" /></div>
                <div><label class="text-xs font-medium block mb-1">E-mel</label><input type="email" name="email" required class="input input-bordered w-full" value="<?= htmlspecialchars($user['email']) ?>" /></div>
                <div><label class="text-xs font-medium block mb-1">No. Telefon</label><input type="text" name="phone_no" class="input input-bordered w-full" value="<?= htmlspecialchars($user['phone_no'] ?? '') ?>" /></div>
                <div><label class="text-xs font-medium block mb-1">Jabatan</label><select name="department_id" class="select select-bordered w-full"><option value="">— Tiada —</option><?php foreach ($departments as $department): ?><option value="<?= (int)$department['department_id'] ?>" <?= (int)$user['department_id'] === (int)$department['department_id'] ? 'selected' : '' ?>><?= htmlspecialchars($department['department_name']) ?></option><?php endforeach; ?></select></div>
                <div><label class="text-xs font-medium block mb-1">Peranan</label><select name="role" class="select select-bordered w-full"><option value="User" <?= $user['role'] === 'User' ? 'selected' : '' ?>>User</option><option value="Admin" <?= $user['role'] === 'Admin' ? 'selected' : '' ?>>Admin</option><option value="SuperAdmin" <?= $user['role'] === 'SuperAdmin' ? 'selected' : '' ?>>SuperAdmin</option></select></div>
                <div><label class="text-xs font-medium block mb-1">Kata Laluan Baharu</label><input type="password" name="password" minlength="6" class="input input-bordered w-full" placeholder="Biarkan kosong untuk kekal" /></div>
                <div class="sm:col-span-2 flex justify-end gap-2 mt-2"><a href="view-user.php?id=<?= $userId ?>" class="btn btn-ghost">Batal</a><button type="submit" class="btn text-white border-0" style="background:var(--ta-brand)">Simpan Perubahan</button></div>
            </form>
        </div>
        <?php else: ?>
        <div class="card p-6 sm:p-8">
            <h2 class="text-lg font-semibold mb-5">Maklumat Pengguna</h2>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-5 text-sm">
                <div><p class="text-xs mb-1" style="color:var(--ta-muted)">Jabatan</p><p class="font-medium"><?= htmlspecialchars($user['department_name'] ?: '—') ?></p></div>
                <div><p class="text-xs mb-1" style="color:var(--ta-muted)">No. Telefon</p><p class="font-medium"><?= htmlspecialchars($user['phone_no'] ?: '—') ?></p></div>
                <div><p class="text-xs mb-1" style="color:var(--ta-muted)">Peranan</p><p class="font-medium"><?= htmlspecialchars($user['role']) ?></p></div>
                <div><p class="text-xs mb-1" style="color:var(--ta-muted)">Tarikh Didaftar</p><p class="font-medium"><?= htmlspecialchars(date('d M Y', strtotime($user['created_at']))) ?></p></div>
            </div>
        </div>
        <?php endif; ?>
    </div>
</main>
<?php include 'includes/layout_footer.php'; ?>
