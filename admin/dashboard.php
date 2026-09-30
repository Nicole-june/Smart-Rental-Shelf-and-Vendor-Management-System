<?php

require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

requireRole('admin');

// Auto-expire any rentals whose end_date has passed, so shelf/occupancy
// counts below are always accurate — not just when the admin visits
// the Rentals page directly.
processExpiredRentals($pdo, currentUserId());


// Stat card counts — each is a single prepared/parameterless COUNT query
// against your existing tables. No schema changes, no new tables.

function countAll(PDO $pdo, string $sql): int
{
    return (int) $pdo->query($sql)->fetchColumn();
}

$totalVendors     = countAll($pdo, "SELECT COUNT(*) FROM vendors");
$totalShelves     = countAll($pdo, "SELECT COUNT(*) FROM shelves");
$occupiedShelves  = countAll($pdo, "SELECT COUNT(*) FROM shelves WHERE status = 'Occupied'");
$availableShelves = countAll($pdo, "SELECT COUNT(*) FROM shelves WHERE status = 'Available'");
$maintenanceShelves = countAll($pdo, "SELECT COUNT(*) FROM shelves WHERE status = 'Maintenance'");
$totalProducts    = countAll($pdo, "SELECT COUNT(*) FROM products");
$lowStockProducts = countAll($pdo, "SELECT COUNT(*) FROM products WHERE status = 'Low Stock'");
$outOfStockProducts = countAll($pdo, "SELECT COUNT(*) FROM products WHERE status = 'Out of Stock'");
$availableProducts = countAll($pdo, "SELECT COUNT(*) FROM products WHERE status = 'Available'");
$pendingPayments  = countAll($pdo, "SELECT COUNT(*) FROM payments WHERE payment_status = 'Pending'");
$pendingApprovals = countAll($pdo, "SELECT COUNT(*) FROM inventory_transactions WHERE status = 'Pending' AND requested_by IS NOT NULL");

//chart data
function pct(int $part, int $total): float
{
    return $total > 0 ? round(($part / $total) * 100, 1) : 0.0;
}

$occupancyChart = [
    'Occupied'    => pct($occupiedShelves, $totalShelves),
    'Available'   => pct($availableShelves, $totalShelves),
    'Maintenance' => pct($maintenanceShelves, $totalShelves),
];

$inventoryChart = [
    'Available'    => pct($availableProducts, $totalProducts),
    'Low Stock'    => pct($lowStockProducts, $totalProducts),
    'Out of Stock' => pct($outOfStockProducts, $totalProducts),
];


// Recent activity — activity_logs columns: log_id, user_id, activity,
// log_date. Joined with users so we can show who performed each action.


$activityStmt = $pdo->query(
    "SELECT al.activity, al.log_date, u.full_name
     FROM activity_logs al
     LEFT JOIN users u ON u.user_id = al.user_id
     ORDER BY al.log_date DESC
     LIMIT 8"
);
$recentActivities = $activityStmt->fetchAll();

$pageTitle  = 'Dashboard';
$activePage = 'dashboard';
require_once __DIR__ . '/../includes/header.php';
?>

    <!--Stat Cards  -->
    <section class="cards-grid">
        <div class="stat-card">
            <div class="stat-icon">&#128100;</div>
            <div class="stat-value"><?= e((string) $totalVendors) ?></div>
            <div class="stat-label">Total Vendors</div>
        </div>

        <div class="stat-card">
            <div class="stat-icon">&#128193;</div>
            <div class="stat-value"><?= e((string) $totalShelves) ?></div>
            <div class="stat-label">Total Shelves</div>
        </div>

        <div class="stat-card">
            <div class="stat-icon">&#128274;</div>
            <div class="stat-value"><?= e((string) $occupiedShelves) ?></div>
            <div class="stat-label">Occupied Shelves</div>
        </div>

        <div class="stat-card">
            <div class="stat-icon">&#9989;</div>
            <div class="stat-value"><?= e((string) $availableShelves) ?></div>
            <div class="stat-label">Available Shelves</div>
        </div>

        <div class="stat-card">
            <div class="stat-icon">&#128230;</div>
            <div class="stat-value"><?= e((string) $totalProducts) ?></div>
            <div class="stat-label">Products</div>
        </div>

        <div class="stat-card stat-card-warning">
            <div class="stat-icon">&#9888;</div>
            <div class="stat-value"><?= e((string) $lowStockProducts) ?></div>
            <div class="stat-label">Low Stock</div>
        </div>

        <div class="stat-card stat-card-warning">
            <div class="stat-icon">&#128179;</div>
            <div class="stat-value"><?= e((string) $pendingPayments) ?></div>
            <div class="stat-label">Pending Payments</div>
        </div>

        <a href="inventory_approvals.php" class="stat-card stat-card-warning" style="text-decoration:none; display:block;">
            <div class="stat-icon">&#128203;</div>
            <div class="stat-value"><?= e((string) $pendingApprovals) ?></div>
            <div class="stat-label">Pending Inventory Approvals</div>
        </a>
    </section>

    <!-- ===== Charts (CSS-only) ===== -->
    <section class="charts-grid">

        <div class="chart-card">
            <h3>Shelf Occupancy</h3>
            <?php if ($totalShelves === 0): ?>
                <p class="chart-empty">No shelves recorded yet.</p>
            <?php else: ?>
                <div class="bar-chart">
                    <?php foreach ($occupancyChart as $label => $percent): ?>
                        <div class="bar-row">
                            <span class="bar-label"><?= e($label) ?></span>
                            <div class="bar-track">
                                <div class="bar-fill bar-<?= strtolower(str_replace(' ', '-', $label)) ?>"
                                     style="width: <?= e((string) $percent) ?>%;"></div>
                            </div>
                            <span class="bar-percent"><?= e((string) $percent) ?>%</span>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <div class="chart-card">
            <h3>Inventory Status</h3>
            <?php if ($totalProducts === 0): ?>
                <p class="chart-empty">No products recorded yet.</p>
            <?php else: ?>
                <div class="bar-chart">
                    <?php foreach ($inventoryChart as $label => $percent): ?>
                        <div class="bar-row">
                            <span class="bar-label"><?= e($label) ?></span>
                            <div class="bar-track">
                                <div class="bar-fill bar-<?= strtolower(str_replace(' ', '-', $label)) ?>"
                                     style="width: <?= e((string) $percent) ?>%;"></div>
                            </div>
                            <span class="bar-percent"><?= e((string) $percent) ?>%</span>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

    </section>

    <!-- ===== Recent Activity ===== -->
    <section class="activity-card">
        <h3>Recent Activity</h3>
        <?php if (empty($recentActivities)): ?>
            <p class="chart-empty">No activity has been logged yet.</p>
        <?php else: ?>
            <ul class="activity-list">
                <?php foreach ($recentActivities as $activity): ?>
                    <li>
                        <span class="activity-text"><?= e($activity['activity']) ?></span>
                        <span class="activity-meta">
                            <?= e($activity['full_name'] ?? 'Unknown user') ?>
                            &middot;
                            <?= e(date('d M Y, g:i A', strtotime($activity['log_date']))) ?>
                        </span>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>