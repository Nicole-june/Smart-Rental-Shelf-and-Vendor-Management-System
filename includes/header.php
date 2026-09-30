<?php

$pageTitle  = $pageTitle  ?? 'Dashboard';
$activePage = $activePage ?? 'dashboard';

// Sidebar nav items. Only 'dashboard' is a live page right now — the rest
// are wired up module by module and show a "Soon" badge until then.
$navItems = [
    'dashboard'     => ['label' => 'Dashboard',       'href' => 'dashboard.php', 'ready' => true],
    'vendors'       => ['label' => 'Vendors',          'href' => 'vendors.php', 'ready' => true],
    'shelves'       => ['label' => 'Shelves',          'href' => 'shelves.php', 'ready' => true],
    'occupancy'     => ['label' => 'Shelf Occupancy',  'href' => 'occupancy.php', 'ready' => true],
    'rentals'       => ['label' => 'Rentals',          'href' => 'rentals.php', 'ready' => true],
    'inventory'     => ['label' => 'Inventory',        'href' => 'products.php', 'ready' => true],
    'payments'      => ['label' => 'Payments',         'href' => 'payments.php', 'ready' => true],
    'notifications' => ['label' => 'Notifications',    'href' => 'notifications.php', 'ready' => true],
    'reports'       => ['label' => 'Reports',          'href' => 'reports.php', 'ready' => true],
];
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

    <!-- ===== Sidebar ===== -->
    <aside class="sidebar" id="sidebar">
        <div class="sidebar-brand">
            <h2>Smart Rental</h2>
            <p>Admin Panel</p>
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

    <!-- ===== Main column ===== -->
    <div class="main-column">

        <!-- ===== Top nav ===== -->
        <header class="topnav">
            <button class="sidebar-toggle" id="sidebarToggle" aria-label="Toggle sidebar">&#9776;</button>

            <h1 class="topnav-title"><?= e($pageTitle) ?></h1>

            <div class="topnav-right">
                <div class="notification-bell" title="Notifications (coming soon)">
                    &#128276;
                </div>
                <div class="topnav-user">
                    <span class="user-name"><?= e($_SESSION['full_name'] ?? '') ?></span>
                    <span class="user-role">Administrator</span>
                </div>
            </div>
        </header>

        <!-- ===== Page content ===== -->
        <main class="content"></main>