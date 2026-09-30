<?php

require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

requireRole('vendor');

$vendorId = currentVendorId($pdo);
if ($vendorId === null) {
    // Their user account isn't linked to a vendor profile — shouldn't
    // normally happen, but fail safely instead of showing broken data.
    logoutUser();
    redirectTo('../login.php');
}

// Current active rental (if any)
$rentalStmt = $pdo->prepare(
    "SELECT r.*, s.shelf_code, s.shelf_name, s.building_name, s.floor, s.location
     FROM rentals r
     INNER JOIN shelves s ON s.shelf_id = r.shelf_id
     WHERE r.vendor_id = :vendor_id AND r.rental_status = 'Active'
     ORDER BY r.created_at DESC LIMIT 1"
);
$rentalStmt->execute(['vendor_id' => $vendorId]);
$activeRental = $rentalStmt->fetch();

// Product stats
$totalProductsStmt = $pdo->prepare("SELECT COUNT(*) FROM products WHERE vendor_id = :v");
$totalProductsStmt->execute(['v' => $vendorId]);
$totalProducts = (int) $totalProductsStmt->fetchColumn();

$lowStockStmt = $pdo->prepare("SELECT COUNT(*) FROM products WHERE vendor_id = :v AND status = 'Low Stock'");
$lowStockStmt->execute(['v' => $vendorId]);
$lowStockCount = (int) $lowStockStmt->fetchColumn();

$outOfStockStmt = $pdo->prepare("SELECT COUNT(*) FROM products WHERE vendor_id = :v AND status = 'Out of Stock'");
$outOfStockStmt->execute(['v' => $vendorId]);
$outOfStockCount = (int) $outOfStockStmt->fetchColumn();

$pendingPaymentStmt = $pdo->prepare(
    "SELECT COUNT(*) FROM rentals WHERE vendor_id = :v AND rental_status = 'Active' AND payment_status IN ('Pending', 'Failed')"
);
$pendingPaymentStmt->execute(['v' => $vendorId]);
$rentalsNeedingPayment = (int) $pendingPaymentStmt->fetchColumn();

// Recent inventory transactions for this vendor's products
$recentTxStmt = $pdo->prepare(
    "SELECT t.*, p.product_name
     FROM inventory_transactions t
     INNER JOIN products p ON p.product_id = t.product_id
     WHERE p.vendor_id = :v
     ORDER BY t.transaction_date DESC LIMIT 6"
);
$recentTxStmt->execute(['v' => $vendorId]);
$recentTransactions = $recentTxStmt->fetchAll();

$pageTitle  = 'Dashboard';
$activePage = 'dashboard';
require_once __DIR__ . '/../includes/vendor_header.php';
?>

    <section class="cards-grid">
        <div class="stat-card">
            <div class="stat-icon">&#128230;</div>
            <div class="stat-value"><?= $totalProducts ?></div>
            <div class="stat-label">My Products</div>
        </div>
        <div class="stat-card stat-card-warning">
            <div class="stat-icon">&#9888;</div>
            <div class="stat-value"><?= $lowStockCount ?></div>
            <div class="stat-label">Low Stock</div>
        </div>
        <div class="stat-card stat-card-warning">
            <div class="stat-icon">&#10060;</div>
            <div class="stat-value"><?= $outOfStockCount ?></div>
            <div class="stat-label">Out of Stock</div>
        </div>
        <div class="stat-card stat-card-warning">
            <div class="stat-icon">&#128179;</div>
            <div class="stat-value"><?= $rentalsNeedingPayment ?></div>
            <div class="stat-label">Rentals Needing Payment</div>
        </div>
    </section>

    <div class="profile-card">
        <h3>My Shelf</h3>
        <?php if ($activeRental): ?>
            <dl class="detail-list">
                <dt>Shelf</dt>
                <dd><?= e($activeRental['shelf_code']) ?> — <?= e($activeRental['shelf_name']) ?></dd>
                <dt>Building</dt>
                <dd><?= e($activeRental['building_name']) ?></dd>
                <dt>Floor</dt>
                <dd><?= e($activeRental['floor']) ?></dd>
                <dt>Location</dt>
                <dd><?= e($activeRental['location']) ?></dd>
                <dt>Rental Ends</dt>
                <dd><?= e(date('d M Y', strtotime($activeRental['end_date']))) ?></dd>
            </dl>
            <a href="shelf.php" class="action-link">View full shelf details &rarr;</a>
        <?php else: ?>
            <p class="chart-empty">You don't have an active shelf rental right now. Contact the admin to get assigned one.</p>
        <?php endif; ?>
    </div>

    <div class="activity-card">
        <h3>Recent Inventory Activity</h3>
        <?php if (empty($recentTransactions)): ?>
            <p class="chart-empty">No stock or sales activity recorded yet.</p>
        <?php else: ?>
            <ul class="activity-list">
                <?php foreach ($recentTransactions as $tx): ?>
                    <li>
                        <span class="activity-text">
                            <?= e($tx['transaction_type']) ?>: <?= $tx['transaction_type'] === 'Sale' ? '-' : '+' ?><?= (int) $tx['quantity'] ?> — <?= e($tx['product_name']) ?>
                        </span>
                        <span class="activity-meta"><?= e(date('d M Y, g:i A', strtotime($tx['transaction_date']))) ?></span>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>