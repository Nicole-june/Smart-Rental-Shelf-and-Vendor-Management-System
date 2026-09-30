<?php

$pageTitle  = $pageTitle  ?? 'Dashboard';
$activePage = $activePage ?? 'dashboard';

$navItems = [
    'dashboard'     => ['label' => 'Dashboard',    'href' => 'dashboard.php', 'ready' => true],
    'shelf'         => ['label' => 'My Shelf',     'href' => 'shelf.php',     'ready' => true],
    'products'      => ['label' => 'My Products',  'href' => 'products.php', 'ready' => true],
    'sales'         => ['label' => 'My Sales',     'href' => 'sales.php',    'ready' => true],
    'rentals'       => ['label' => 'My Rentals',   'href' => 'rentals.php',  'ready' => true],
    'payments'      => ['label' => 'Payments',     'href' => 'payments.php', 'ready' => true],
    'notifications' => ['label' => 'Notifications','href' => 'notifications.php', 'ready' => true],
];

// Real unread count + latest 5, for the topnav bell dropdown.
$vendorIdForBell = currentVendorId($pdo);
$unreadNotifCount = 0;
$recentNotifications = [];
if ($vendorIdForBell !== null) {
    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE vendor_id = :v AND is_read = 0");
    $countStmt->execute(['v' => $vendorIdForBell]);
    $unreadNotifCount = (int) $countStmt->fetchColumn();

    $recentStmt = $pdo->prepare(
        "SELECT notification_id, message, notification_type, is_read, created_at
         FROM notifications WHERE vendor_id = :v ORDER BY created_at DESC LIMIT 5"
    );
    $recentStmt->execute(['v' => $vendorIdForBell]);
    $recentNotifications = $recentStmt->fetchAll();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($pageTitle) ?> | Smart Rental</title>
    <link rel="stylesheet" href="../assets/css/style.css">
</head>
<body class="app-body">

<div class="app-shell">

    <aside class="sidebar" id="sidebar">
        <div class="sidebar-brand">
            <h2>Smart Rental</h2>
            <p>Vendor Panel</p>
        </div>

        <nav class="sidebar-nav">
            <?php foreach ($navItems as $key => $item): ?>
                <a
                    href="<?= e($item['href']) ?>"
                    class="nav-link <?= $activePage === $key ? 'active' : '' ?> <?= !$item['ready'] ? 'disabled' : '' ?>"
                    <?= !$item['ready'] ? 'onclick="return false;" title="Coming soon"' : '' ?>
                >
                    <span><?= e($item['label']) ?></span>
                    <?php if (!$item['ready']): ?>
                        <span class="soon-badge">Soon</span>
                    <?php endif; ?>
                </a>
            <?php endforeach; ?>
        </nav>

        <div class="sidebar-footer">
            <a href="../logout.php" class="nav-link logout-link">Logout</a>
        </div>
    </aside>

    <div class="main-column">
        <header class="topnav">
            <button class="sidebar-toggle" id="sidebarToggle" aria-label="Toggle sidebar">&#9776;</button>
            <h1 class="topnav-title"><?= e($pageTitle) ?></h1>
            <div class="topnav-right">
                <div class="notif-wrapper">
                    <button class="notification-bell" onclick="document.getElementById('notifDropdown').classList.toggle('open')" title="Notifications">
                        &#128276;
                        <?php if ($unreadNotifCount > 0): ?>
                            <span class="notif-badge"><?= $unreadNotifCount > 9 ? '9+' : $unreadNotifCount ?></span>
                        <?php endif; ?>
                    </button>
                    <div class="notif-dropdown" id="notifDropdown">
                        <div class="notif-dropdown-header">
                            <span>Notifications</span>
                            <?php if ($unreadNotifCount > 0): ?>
                                <form method="POST" action="notification_mark_read.php" class="inline-form">
                                    <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                                    <input type="hidden" name="mark_all" value="1">
                                    <button type="submit" class="notif-mark-all">Mark all read</button>
                                </form>
                            <?php endif; ?>
                        </div>
                        <?php if (empty($recentNotifications)): ?>
                            <p class="notif-empty">No notifications yet.</p>
                        <?php else: ?>
                            <?php foreach ($recentNotifications as $n): ?>
                                <div class="notif-item <?= !$n['is_read'] ? 'unread' : '' ?>">
                                    <span class="notif-type"><?= e($n['notification_type']) ?></span>
                                    <p><?= e($n['message']) ?></p>
                                    <span class="notif-time"><?= e(date('d M, g:i A', strtotime($n['created_at']))) ?></span>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        <a href="notifications.php" class="notif-view-all">View all notifications</a>
                    </div>
                </div>
                <div class="topnav-user">
                    <span class="user-name"><?= e($_SESSION['full_name'] ?? '') ?></span>
                    <span class="user-role">Vendor</span>
                </div>
            </div>
        </header>

        <main class="content">=