<?php
// users.php — Pengurusan Pengguna (SuperAdmin sahaja)
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/config/database.php';

require_login();
require_role(['SuperAdmin']);

$fullname      = $_SESSION['fullname'];
$role          = $_SESSION['role'];
$currentUserId = (int)$_SESSION['user_id'];

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
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        http_response_code(403);
        exit('Ralat Keselamatan: Token CSRF tidak sah atau telah tamat tempoh.');
    }
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

            log_activity($pdo, $currentUserId, 'Pengguna', 'Padam', "Pengguna '{$victimName}' telah dipadam.");

            $flash = ['type' => 'success', 'msg' => "Pengguna '{$victimName}' berjaya dipadam."];
        }
    } catch (RuntimeException $e) {
        $flash = ['type' => 'error', 'msg' => $e->getMessage()];
    } catch (PDOException $e) {
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
$roleFilter = $_GET['role'] ?? '';
$departmentFilter = max(0, (int)($_GET['department_id'] ?? 0));
$registeredDate = trim($_GET['registered_date'] ?? '');
if (!in_array($roleFilter, ['SuperAdmin', 'Admin', 'User'], true)) $roleFilter = '';
if ($registeredDate !== '' && !DateTime::createFromFormat('Y-m-d', $registeredDate)) $registeredDate = '';
$perPage = 10;
$page    = max(1, (int)($_GET['page'] ?? 1));
$offset  = ($page - 1) * $perPage;

function buildUsersPageUrl(int $p, string $search): string {
    $params = ['page' => $p];
    if ($search !== '') {
        $params['q'] = $search;
    }
    foreach (['role' => $GLOBALS['roleFilter'], 'department_id' => $GLOBALS['departmentFilter'], 'registered_date' => $GLOBALS['registeredDate']] as $key => $value) {
      if ($value !== '' && $value !== 0) $params[$key] = $value;
    }
    return 'users.php?' . http_build_query($params);
}

  $where = [];
  $params = [];
  if ($search !== '') { $where[] = '(u.fullname LIKE :like1 OR u.email LIKE :like2 OR d.department_name LIKE :like3)'; $like = "%{$search}%"; $params[':like1'] = $like; $params[':like2'] = $like; $params[':like3'] = $like; }
  if ($roleFilter !== '') { $where[] = 'u.role = :role'; $params[':role'] = $roleFilter; }
  if ($departmentFilter > 0) { $where[] = 'u.department_id = :department_id'; $params[':department_id'] = $departmentFilter; }
  if ($registeredDate !== '') { $where[] = 'DATE(u.created_at) = :registered_date'; $params[':registered_date'] = $registeredDate; }
  $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';
  $countStmt = $pdo->prepare("SELECT COUNT(*) FROM users u LEFT JOIN departments d ON d.department_id = u.department_id $whereSql");
  foreach ($params as $key => $value) $countStmt->bindValue($key, $value);
  $countStmt->execute();
  $totalRows = (int)$countStmt->fetchColumn();
  $stmt = $pdo->prepare(
    "SELECT u.*, d.department_name
     FROM users u
     LEFT JOIN departments d ON d.department_id = u.department_id
     $whereSql
     ORDER BY u.created_at DESC
     LIMIT :limit OFFSET :offset"
  );
  foreach ($params as $key => $value) $stmt->bindValue($key, $value);
  $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
  $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
  $stmt->execute();
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

// --- Layout Config ---
$pageTitle = "Pengguna";
$showSearch = true;
$searchAction = "users.php";
$searchPlaceholder = "Cari nama, e-mel, jabatan...";
$extraJS = '
    <script>
        function openAddModal() {
            document.getElementById("modal-add").showModal();
        }

        function openEditModal(u) {
            document.getElementById("edit-user-id").value    = u.user_id;
            document.getElementById("edit-fullname").value   = u.fullname;
            document.getElementById("edit-email").value      = u.email;
            document.getElementById("edit-phone").value      = u.phone_no || "";
            setComboboxValue(document.querySelector("#edit-department-combobox"), u.department_id || "");
            document.getElementById("edit-role").value       = u.role;
            document.getElementById("modal-edit").showModal();
        }

        function openDeleteModal(id, name) {
            document.getElementById("delete-user-id").value = id;
            document.getElementById("delete-user-name").textContent = name;
            document.getElementById("modal-delete").showModal();
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
              option.hidden = query !== "" && !option.textContent.toLowerCase().includes(query);
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
          options.forEach(option => option.addEventListener("click", () => {
            setComboboxValue(combobox, option.dataset.value);
            closeOptions();
          }));
          combobox.addEventListener("focusout", event => {
            if (!combobox.contains(event.relatedTarget)) closeOptions();
          });
        });
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
          <div class="card p-5" data-href="users.php">
            <div class="ta-icon-box ta-icon-box-blue mb-4">
              <svg xmlns="http://www.w3.org/2000/svg" class="h-5.5 w-5.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19.128a9.38 9.38 0 002.625.372 9.337 9.337 0 004.121-.952 4.125 4.125 0 00-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 018.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0111.964-3.07M12 6.375a3.375 3.375 0 11-6.75 0 3.375 3.375 0 016.75 0zm8.25 2.25a2.625 2.625 0 11-5.25 0 2.625 2.625 0 015.25 0z" /></svg>
            </div>
            <p class="text-sm mb-1" style="color:var(--ta-muted)">Jumlah Pengguna</p>
            <h5 class="text-2xl font-bold"><?= (int)$totalUsers ?></h5>
          </div>

          <div class="card p-5" data-href="users.php?q=SuperAdmin">
            <div class="ta-icon-box ta-icon-box-green mb-4">
              <svg xmlns="http://www.w3.org/2000/svg" class="h-5.5 w-5.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75m5.25 2.25a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
            </div>
            <p class="text-sm mb-1" style="color:var(--ta-muted)">SuperAdmin</p>
            <h5 class="text-2xl font-bold"><?= $totalSuperAdmin ?></h5>
          </div>

          <div class="card p-5" data-href="users.php?q=Admin">
            <div class="ta-icon-box ta-icon-box-purple mb-4">
              <svg xmlns="http://www.w3.org/2000/svg" class="h-5.5 w-5.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6"><path stroke-linecap="round" stroke-linejoin="round" d="M9.75 3.104v5.714a2.25 2.25 0 01-.659 1.591L5 14.5M9.75 3.104c-.251.023-.501.05-.75.082m.75-.082a24.301 24.301 0 014.5 0m0 0v5.714c0 .597.237 1.17.659 1.591L19.8 15.3M14.25 3.104c.251.023.501.05.75.082M19.8 15.3l-1.57.393A9.065 9.065 0 0112 15a9.065 9.065 0 00-6.23-.693L5 14.5m14.8.8l1.402 1.402c1.232 1.232.65 3.318-1.067 3.611A48.309 48.309 0 0112 21c-2.773 0-5.491-.235-8.135-.687-1.718-.293-2.3-2.379-1.067-3.61L5 14.5" /></svg>
            </div>
            <p class="text-sm mb-1" style="color:var(--ta-muted)">Admin</p>
            <h5 class="text-2xl font-bold"><?= $totalAdmin ?></h5>
          </div>

          <div class="card p-5" data-href="users.php?q=User">
            <div class="ta-icon-box ta-icon-box-orange mb-4">
              <svg xmlns="http://www.w3.org/2000/svg" class="h-5.5 w-5.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6"><path stroke-linecap="round" stroke-linejoin="round" d="M17.982 18.725A7.488 7.488 0 0012 15.75a7.488 7.488 0 00-5.982 2.975m11.963 0a9 9 0 10-11.963 0m11.963 0A8.966 8.966 0 0112 21a8.966 8.966 0 01-5.982-2.275M15 9.75a3 3 0 11-6 0 3 3 0 016 0z" /></svg>
            </div>
            <p class="text-sm mb-1" style="color:var(--ta-muted)">Pengguna Biasa</p>
            <h5 class="text-2xl font-bold"><?= $totalStaff ?></h5>
          </div>
        </div>

        <?php $hasUserFilters = $roleFilter !== '' || $departmentFilter > 0 || $registeredDate !== ''; ?>
        <div class="card p-5 mt-5">
          <details class="rounded-xl border" style="border-color:var(--ta-border)" <?= $hasUserFilters ? 'open' : '' ?>>
            <summary class="cursor-pointer list-none px-4 py-3 text-sm font-semibold flex items-center justify-between gap-3"><span class="flex items-center gap-2"><svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M6.75 12h10.5m-7.5 5.25h4.5" /></svg>Penapis Lanjutan<?= $hasUserFilters ? ' <span class="ta-badge badge badge-info">Aktif</span>' : '' ?></span><span class="text-xs text-slate-400">Peranan, jabatan &amp; tarikh daftar</span></summary>
            <form action="users.php" method="GET" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3 px-4 pb-4">
              <input type="hidden" name="q" value="<?= htmlspecialchars($search) ?>" />
              <div><label class="text-xs font-medium block mb-1">Peranan</label><select name="role" class="select select-bordered select-sm w-full"><option value="">Semua peranan</option><?php foreach (['SuperAdmin', 'Admin', 'User'] as $filterRole): ?><option value="<?= $filterRole ?>" <?= $roleFilter === $filterRole ? 'selected' : '' ?>><?= $filterRole === 'User' ? 'Pengguna Biasa' : $filterRole ?></option><?php endforeach; ?></select></div>
              <div><label class="text-xs font-medium block mb-1">Jabatan</label><select name="department_id" class="select select-bordered select-sm w-full"><option value="0">Semua jabatan</option><?php foreach ($departments as $filterDepartment): ?><option value="<?= (int)$filterDepartment['department_id'] ?>" <?= $departmentFilter === (int)$filterDepartment['department_id'] ? 'selected' : '' ?>><?= htmlspecialchars($filterDepartment['department_name']) ?></option><?php endforeach; ?></select></div>
              <div><label class="text-xs font-medium block mb-1">Didaftar pada</label><input type="date" name="registered_date" value="<?= htmlspecialchars($registeredDate) ?>" class="input input-bordered input-sm w-full" /></div>
              <div class="flex items-end gap-2"><button type="submit" class="btn btn-sm text-white border-0" style="background:var(--ta-brand)">Tapis</button><?php if ($hasUserFilters): ?><a href="users.php<?= $search !== '' ? '?q=' . urlencode($search) : '' ?>" class="btn btn-sm btn-ghost">Set Semula</a><?php endif; ?></div>
            </form>
          </details>
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
                  <tr class="hover:bg-slate-50/70 transition-colors" data-href="view-user.php?id=<?= (int)$u['user_id'] ?>">
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
                      <a href="view-user.php?id=<?= (int)$u['user_id'] ?>&amp;mode=edit" class="btn btn-ghost btn-xs" title="Kemaskini">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931z" /></svg>
                      </a>
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

        <!-- Modal: Tambah Pengguna -->
        <dialog id="modal-add" class="modal">
          <div class="modal-box card max-w-md">
            <form method="dialog"><button class="btn btn-sm btn-circle btn-ghost absolute right-3 top-3">✕</button></form>
            <h3 class="font-bold text-lg mb-4">Tambah Pengguna Baharu</h3>
            <form action="users.php<?= $search !== '' ? '?q=' . urlencode($search) : '' ?>" method="POST" class="flex flex-col gap-3">
              <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>" />
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
                <div class="ta-combobox" data-combobox>
                  <input type="hidden" name="department_id" data-combobox-value />
                  <input type="text" class="input input-bordered w-full" placeholder="Cari jabatan..." autocomplete="off"
                    role="combobox" aria-expanded="false" aria-autocomplete="list" data-combobox-input />
                  <div class="ta-combobox-options" role="listbox" data-combobox-options>
                    <button type="button" class="ta-combobox-option" data-value="">— Tiada —</button>
                    <?php foreach ($departments as $d): ?>
                      <button type="button" class="ta-combobox-option" data-value="<?= (int)$d['department_id'] ?>"><?= htmlspecialchars($d['department_name']) ?></button>
                    <?php endforeach; ?>
                  </div>
                </div>
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
              <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>" />
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
                <div class="ta-combobox" id="edit-department-combobox" data-combobox>
                  <input type="hidden" name="department_id" data-combobox-value />
                  <input type="text" class="input input-bordered w-full" placeholder="Cari jabatan..." autocomplete="off"
                    role="combobox" aria-expanded="false" aria-autocomplete="list" data-combobox-input />
                  <div class="ta-combobox-options" role="listbox" data-combobox-options>
                    <button type="button" class="ta-combobox-option" data-value="">— Tiada —</button>
                    <?php foreach ($departments as $d): ?>
                      <button type="button" class="ta-combobox-option" data-value="<?= (int)$d['department_id'] ?>"><?= htmlspecialchars($d['department_name']) ?></button>
                    <?php endforeach; ?>
                  </div>
                </div>
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
              <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>" />
              <input type="hidden" name="action" value="delete_user" />
              <input type="hidden" name="user_id" id="delete-user-id" />
              <button type="button" class="btn btn-ghost" onclick="document.getElementById('modal-delete').close()">Batal</button>
              <button type="submit" class="btn btn-error text-white border-0">Padam</button>
            </form>
          </div>
          <form method="dialog" class="modal-backdrop"><button>close</button></form>
        </dialog>

    </div>
</main>

<?php
include 'includes/layout_footer.php';
?>