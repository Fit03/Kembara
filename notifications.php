<?php
// notifications.php - Senarai notifikasi pengguna
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/config/database.php';

require_login();

function notificationRelativeTime(string $createdAt): string {
    $seconds = max(0, time() - strtotime($createdAt));
    if ($seconds < 60) return 'sebentar tadi';
    if ($seconds < 3600) return floor($seconds / 60) . ' minit lalu';
    if ($seconds < 86400) return floor($seconds / 3600) . ' jam lalu';
    if ($seconds < 604800) return floor($seconds / 86400) . ' hari lalu';
    return date('d M Y, H:i', strtotime($createdAt));
}

$userId = (int)$_SESSION['user_id'];
$filter = ($_GET['filter'] ?? 'all') === 'unread' ? 'unread' : 'all';
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 15;
$offset = ($page - 1) * $perPage;
$where = $filter === 'unread' ? ' AND is_read = 0' : '';

$countStmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ?{$where}");
$countStmt->execute([$userId]);
$totalRows = (int)$countStmt->fetchColumn();
$totalPages = max(1, (int)ceil($totalRows / $perPage));
if ($page > $totalPages) {
    $page = $totalPages;
    $offset = ($page - 1) * $perPage;
}

$stmt = $pdo->prepare(
    "SELECT notification_id, booking_id, type, title, message, link, is_read, created_at
     FROM notifications
    WHERE user_id = :user_id{$where}
     ORDER BY created_at DESC, notification_id DESC
    LIMIT :limit OFFSET :offset"
);
$stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
$stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);

$unreadStmt = $pdo->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0');
$unreadStmt->execute([$userId]);
$unreadCount = (int)$unreadStmt->fetchColumn();

$fullname = $_SESSION['fullname'] ?? '';
$role = $_SESSION['role'] ?? 'User';
$profilePicture = null;
$avatarStmt = $pdo->prepare('SELECT profile_picture FROM users WHERE user_id = ?');
$avatarStmt->execute([$userId]);
$profilePicture = $avatarStmt->fetchColumn();
$hasPhoto = $profilePicture && is_file(__DIR__ . '/' . $profilePicture);
$pageTitle = 'Notifikasi';
$showSearch = false;
$breadcrumbs = [
    ['url' => 'dashboard.php', 'label' => 'Halaman'],
    ['url' => '#', 'label' => 'Notifikasi'],
];

include 'includes/layout_header.php';
?>
<main class="xl:ml-64 relative min-h-screen pb-24 xl:pb-0">
    <?php include 'includes/top_nav.php'; ?>
    <div class="w-full max-w-4xl px-4 sm:px-6 py-6 mx-auto">
        <div class="flex items-center justify-between gap-3 mb-5 flex-wrap">
            <div>
                <h1 class="text-xl font-bold">Notifikasi</h1>
                <p class="text-sm" style="color:var(--ta-muted)"><?= $unreadCount ?> belum dibaca</p>
            </div>
            <?php if ($unreadCount > 0): ?>
            <form method="POST" action="notification_action.php">
                <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>" />
                <input type="hidden" name="action" value="mark_all_read" />
                <input type="hidden" name="redirect" value="notifications.php?filter=<?= htmlspecialchars($filter) ?>" />
                <button type="submit" class="btn btn-sm btn-outline">Tandakan semua dibaca</button>
            </form>
            <?php endif; ?>
        </div>

        <div class="flex items-center gap-2 mb-4">
            <a href="notifications.php?filter=all" class="ta-tab <?= $filter === 'all' ? 'active' : '' ?>">Semua</a>
            <a href="notifications.php?filter=unread" class="ta-tab <?= $filter === 'unread' ? 'active' : '' ?>">Belum dibaca</a>
        </div>

        <div class="card overflow-hidden">
            <?php if (!$notifications): ?>
                <div class="p-10 text-center text-sm" style="color:var(--ta-muted)">Tiada notifikasi untuk dipaparkan.</div>
            <?php else: ?>
                <div class="divide-y" style="border-color:var(--ta-border)">
                <?php foreach ($notifications as $notification): ?>
                    <?php $notificationLink = $notification['link'] ?: 'notifications.php'; ?>
                    <div class="p-4 sm:p-5 flex items-start gap-3 <?= !$notification['is_read'] ? 'bg-primary/5' : '' ?>">
                        <div class="ta-icon-box shrink-0" style="width:2.5rem;height:2.5rem">
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M14.857 17.082a23.848 23.848 0 005.454-1.31A8.967 8.967 0 0118 9.75v-.7V9A6 6 0 006 9v.75a8.967 8.967 0 01-2.312 6.022c1.733.64 3.56 1.085 5.455 1.31m5.714 0a24.255 24.255 0 01-5.714 0m5.714 0a3 3 0 11-5.714 0" /></svg>
                        </div>
                        <div class="min-w-0 flex-1">
                            <a href="<?= htmlspecialchars($notificationLink) ?>" class="font-semibold text-sm hover:underline"><?= htmlspecialchars($notification['title']) ?></a>
                            <p class="text-sm mt-1" style="color:var(--ta-muted)"><?= htmlspecialchars($notification['message']) ?></p>
                            <p class="text-xs mt-2" style="color:var(--ta-muted)"><?= htmlspecialchars(notificationRelativeTime($notification['created_at'])) ?></p>
                        </div>
                        <?php if (!$notification['is_read']): ?>
                        <form method="POST" action="notification_action.php" class="shrink-0">
                            <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>" />
                            <input type="hidden" name="action" value="mark_read" />
                            <input type="hidden" name="notification_id" value="<?= (int)$notification['notification_id'] ?>" />
                            <input type="hidden" name="redirect" value="notifications.php?filter=<?= htmlspecialchars($filter) ?>&amp;page=<?= $page ?>" />
                            <button type="submit" class="btn btn-ghost btn-xs" title="Tandakan dibaca">Baca</button>
                        </form>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <?php if ($totalPages > 1): ?>
        <div class="flex justify-center gap-2 mt-5">
            <?php if ($page > 1): ?><a class="btn btn-sm btn-outline" href="notifications.php?filter=<?= $filter ?>&amp;page=<?= $page - 1 ?>">Sebelumnya</a><?php endif; ?>
            <span class="btn btn-sm btn-disabled"><?= $page ?> / <?= $totalPages ?></span>
            <?php if ($page < $totalPages): ?><a class="btn btn-sm btn-outline" href="notifications.php?filter=<?= $filter ?>&amp;page=<?= $page + 1 ?>">Seterusnya</a><?php endif; ?>
        </div>
        <?php endif; ?>
    </div>
</main>
<?php include 'includes/layout_footer.php'; ?>
