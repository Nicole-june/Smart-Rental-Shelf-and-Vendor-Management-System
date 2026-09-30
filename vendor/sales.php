<?php

require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

requireRole('vendor');

$vendorId = currentVendorId($pdo);
if ($vendorId === null) {
    logoutUser();
    redirectTo('../login.php');
}

$dateFrom = cleanInput($_GET['date_from'] ?? '');
$dateTo   = cleanInput($_GET['date_to'] ?? '');

$sql = "SELECT s.sale_id, s.quantity_sold, s.total_amount, s.sale_date, p.product_name
        FROM sales s
        INNER JOIN products p ON p.product_id = s.product_id
        WHERE s.vendor_id = :vendor_id";
$params = ['vendor_id' => $vendorId];

if ($dateFrom !== '') {
    $sql .= " AND s.sale_date >= :date_from";
    $params['date_from'] = $dateFrom;
}
if ($dateTo !== '') {
    $sql .= " AND s.sale_date <= :date_to";
    $params['date_to'] = $dateTo . ' 23:59:59';
}

$sql .= " ORDER BY s.sale_date DESC LIMIT 200";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$sales = $stmt->fetchAll();

$totalRevenue = array_sum(array_column($sales, 'total_amount'));
$totalUnits = array_sum(array_column($sales, 'quantity_sold'));

$pageTitle  = 'My Sales';
$activePage = 'sales';
require_once __DIR__ . '/../includes/vendor_header.php';
?>

    <div class="page-toolbar">
        <form method="GET" class="search-form">
            <label class="text-muted" style="align-self:center;">From</label>
            <input type="date" name="date_from" value="<?= e($dateFrom) ?>" class="status-select">
            <label class="text-muted" style="align-self:center;">To</label>
            <input type="date" name="date_to" value="<?= e($dateTo) ?>" class="status-select">
            <button type="submit" class="btn btn-secondary">Filter</button>
            <?php if ($dateFrom !== '' || $dateTo !== ''): ?>
                <a href="sales.php" class="btn btn-link">Clear</a>
            <?php endif; ?>
        </form>
    </div>

    <div class="shelf-code-display">
        <?= count($sales) ?> sale(s) — <?= (int) $totalUnits ?> units sold — Total revenue: KES <?= number_format((float) $totalRevenue, 2) ?>
    </div>

    <div class="table-card">
        <?php if (empty($sales)): ?>
            <p class="chart-empty">No sales recorded yet.</p>
        <?php else: ?>
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Product</th>
                        <th>Qty Sold</th>
                        <th>Total Amount</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($sales as $s): ?>
                        <tr>
                            <td class="text-muted"><?= e(date('d M Y, g:i A', strtotime($s['sale_date']))) ?></td>
                            <td><?= e($s['product_name']) ?></td>
                            <td><?= (int) $s['quantity_sold'] ?></td>
                            <td>KES <?= number_format((float) $s['total_amount'], 2) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>