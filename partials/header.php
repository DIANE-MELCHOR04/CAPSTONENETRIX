<?php
$title = $title ?? 'Dashboard';
$eyebrow = $eyebrow ?? 'INVENTORY CONTROL';
$user = current_user();
$initial = strtoupper(substr((string) ($user['full_name'] ?? 'U'), 0, 1));
$flash = take_flash();
$unreadNotifications = 0;
$canViewNotifications = $user && role_can((string) ($user['role'] ?? ''), 'view_notifications');
if ($user && isset($pdo) && $pdo instanceof PDO && role_can((string) ($user['role'] ?? ''), 'view_notifications')) {
    require_once __DIR__ . '/../lib/notification_rules.php';
    try {
        $lastRuleRun = (int) ($_SESSION['notification_rules_checked_at'] ?? 0);
        if (time() - $lastRuleRun >= 180) {
            generate_needed_notifications($pdo);
            $_SESSION['notification_rules_checked_at'] = time();
        }
        $unreadNotifications = unread_notification_count($pdo, (int) $user['id']);
    } catch (Throwable $exception) {
        $unreadNotifications = 0;
    }
}
?>
<header class="page-header">
    <div class="header-title-block">
        <button class="mobile-menu-toggle" type="button" data-sidebar-toggle aria-label="Open navigation" aria-expanded="false" aria-controls="app-sidebar" title="Open navigation">
            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 7h16M4 12h16M4 17h16"/></svg>
        </button>
        <nav class="ops-breadcrumbs" aria-label="Breadcrumb"><span>Workspace</span><span aria-hidden="true">/</span><strong><?= e($title) ?></strong></nav>
        <span class="eyebrow"><?= e($eyebrow) ?></span>
        <h1><?= e($title) ?></h1>
    </div>
    <div class="header-tools"<?php if ($canViewNotifications): ?> data-notification-poll-url="notifications_poll.php" data-unread-notifications="<?= (int) $unreadNotifications ?>"<?php endif; ?>>
        <?php if (role_can((string) ($_SESSION['role'] ?? ''), 'use_global_search')): ?><form class="header-search" method="get" action="global_search.php" role="search">
            <span class="header-search-icon" aria-hidden="true"></span>
            <input name="q" placeholder="Search requests, stock, suppliers..." aria-label="Global search" data-command-search autocomplete="off">
            <kbd>Ctrl K</kbd>
        </form><?php endif; ?>
        <?php if ($canViewNotifications): ?>
            <a class="notification-pill" href="notifications.php" aria-label="Notifications" title="Notifications">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 16h12l-2-3V9a4 4 0 00-8 0v4zM10 19h4"/></svg>
                <?php if ($unreadNotifications > 0): ?><b data-notification-count><?= (int) $unreadNotifications ?></b><?php endif; ?>
            </a>
        <?php endif; ?>
        <div class="profile-pill">
            <span class="profile-avatar"><?= e($initial) ?></span>
            <div>
                <strong><?= e($user['full_name'] ?? 'User') ?></strong>
                <small><?= e(role_label($user['role'] ?? '')) ?></small>
            </div>
        </div>
    </div>
</header>
<?php if ($flash): ?>
    <div class="page-content flash-fallback-wrap">
        <div class="alert <?= e($flash['type']) ?>" role="status" aria-live="polite" data-notify="<?= e($flash['type']) ?>" data-notify-fallback><?= e($flash['message']) ?></div>
    </div>
<?php endif; ?>
