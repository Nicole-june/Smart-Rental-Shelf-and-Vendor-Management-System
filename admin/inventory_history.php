<?php


require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

requireRole('admin');

$productFilter = (int) ($_GET['product'] ?? 0);
$typeFilter = $_GET['type'] ?? 'All';
if (!in_array($typeFilter, ['All', 'Stock In', 'Sale', 'Adjustment'], true)) {
    $typeFilter = 'All';
}

$products = $pdo->query("SELECT product_id, product_name FROM products ORDER BY product_name ASC")->fetchAll();

$sql = "SELECT t.*, p.product_name
        FROM inventory_transactions t
        INNER JOIN products p ON p.product_id = t.product_id
        WHERE 1 = 1";
$params = [];

if ($productFilter > 0) {
    $sql .= " AND t.product_id = :product";
    $params['product'] = $productFilter;
}
if ($typeFilter !== 'All') {
    $sql .= " AND t.transaction_type = :type";
    $params['type'] = $typeFilter;
}

$sql .= " ORDER BY t.transaction_date DESC LIMIT 200";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$transactions = $stmt->fetchAll();

$pageTitle    = 'Inventory History';
$activePage   = 'inventory';
$inventoryTab = 'history';
require_once __DIR__ . '/../includes/header.php';
require __DIR__ . '/../includes/inventory_tabs.php';

function txBadgeClass(string $type): string
{
    return match ($type) {
        'Stock In'    => 'badge-success',
        'Sale'        => 'badge-occupied',
        'Adjustment'  => 'badge-warning',
        default       => 'badge-muted',
    };
}
?>

    <div class="page-toolbar">
        <form method="GET" class="search-form">
            <select name="product" class="status-select">
                <option value="0">All Products</option>
                <?php foreach ($products as $p): ?>
                    <option value="<?= (int) $p['product_id'] ?>" <?= $productFilter === (int) $p['product_id'] ? 'selected' : '' ?>><?= e($p['product_name']) ?></option>
                <?php endforeach; ?>
            </select>
            <select name="type" class="status-select">
                <option value="All" <?= $typeFilter === 'All' ? 'selected' : '' ?>>All Types</option>
                <option value="Stock In" <?= $typeFilter === 'Stock In' ? 'selected' : '' ?>>Stock In</option>
                <option value="Sale" <?= $typeFilter === 'Sale' ? 'selected' : '' ?>>Sale</option>
                <option value="Adjustment" <?= $typeFilter === 'Adjustment' ? 'selected' : '' ?>>Adjustment</option>
            </select>
            <button type="submit" class="btn btn-secondary">Filter</button>
            <?php if ($productFilter > 0 || $typeFilter !== 'All'): ?>
                <a href="inventory_history.php" class="btn btn-link">Clear</a>
            <?php endif; ?>
        </form>
    </div>

    <div class="table-card">
        <?php if (empty($transactions)): ?>
            <p class="chart-empty">No inventory transactions recorded yet.</p>
        <?php else: ?>
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Product</th>
                        <th>Type</th>
                        <th>Quantity</th>
                        <th>Remarks</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($transactions as $t): ?>
                        <tr>
                            <td><?= e(date('d M Y, g:i A', strtotime($t['transaction_date']))) ?></td>
                            <td><?= e($t['product_name']) ?></td>
                            <td><span class="badge <?= txBadgeClass($t['transaction_type']) ?>"><?= e($t['transaction_type']) ?></span></td>
                            <td><?= $t['transaction_type'] === 'Sale' ? '-' : '+' ?><?= (int) $t['quantity'] ?></td>
                            <td class="text-muted"><?= e($t['remarks'] ?: '—') ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php if (count($transactions) === 200): ?>
                <p class="text-muted" style="padding: 10px 0;">Showing the most recent 200 transactions. Use the filters to narrow down further.</p>
            <?php endif; ?>
        <?php endif; ?>
    </div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>