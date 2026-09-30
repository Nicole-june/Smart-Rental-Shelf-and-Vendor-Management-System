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

$statusFilter = $_GET['status'] ?? 'All';
if (!in_array($statusFilter, ['All', 'Pending', 'Approved', 'Rejected'], true)) {
    $statusFilter = 'All';
}

$sql = "SELECT t.transaction_id, t.transaction_type, t.quantity, t.transaction_date, t.remarks, t.status,
               p.product_name
        FROM inventory_transactions t
        INNER JOIN products p ON p.product_id = t.product_id
        WHERE p.vendor_id = :vendor_id AND t.requested_by IS NOT NULL";
$params = ['vendor_id' => $vendorId];

if ($statusFilter !== 'All') {
    $sql .= " AND t.status = :status";
    $params['status'] = $statusFilter;
}

$sql .= " ORDER BY t.transaction_date DESC LIMIT 100";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$requests = $stmt->fetchAll();

function reqBadgeClass(string $status): string
{
    return match ($status) {
        'Pending'  => 'badge-warning',
        'Approved' => 'badge-success',
        'Rejected' => 'badge-danger',
        default    => 'badge-muted',
    };
}

$pageTitle  = 'My Requests';
$activePage = 'products';
require_once __DIR__ . '/../includes/vendor_header.php';
?>

    <a href="products.php" class="back-link">&larr; Back to My Products</a>

    <div class="page-toolbar">
        <form method="GET" class="search-form">
            <select name="status" class="status-select">
                <option value="All" <?= $statusFilter === 'All' ? 'selected' : '' ?>>All</option>
                <option value="Pending" <?= $statusFilter === 'Pending' ? 'selected' : '' ?>>Pending</option>
                <option value="Approved" <?= $statusFilter === 'Approved' ? 'selected' : '' ?>>Approved</option>
                <option value="Rejected" <?= $statusFilter === 'Rejected' ? 'selected' : '' ?>>Rejected</option>
            </select>
            <button type="submit" class="btn btn-secondary">Filter</button>
        </form>
    </div>

    <div class="table-card">
        <?php if (empty($requests)): ?>
            <p class="chart-empty">No requests found.</p>
        <?php else: ?>
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Product</th>
                        <th>Type</th>
                        <th>Quantity</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($requests as $r): ?>
                        <tr>
                            <td class="text-muted"><?= e(date('d M Y, g:i A', strtotime($r['transaction_date']))) ?></td>
                            <td><?= e($r['product_name']) ?></td>
                            <td><?= e($r['transaction_type']) ?></td>
                            <td><?= $r['transaction_type'] === 'Sale' ? '-' : '+' ?><?= (int) $r['quantity'] ?></td>
                            <td><span class="badge <?= reqBadgeClass($r['status']) ?>"><?= e($r['status']) ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>---