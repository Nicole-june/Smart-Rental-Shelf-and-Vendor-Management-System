<?php

require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

requireRole('admin');

$flashSuccess = $_GET['success'] ?? '';
$flashError   = $_GET['error'] ?? '';

$statusFilter = $_GET['status'] ?? 'Pending';
if (!in_array($statusFilter, ['Pending', 'Approved', 'Rejected', 'All'], true)) {
    $statusFilter = 'Pending';
}

$sql = "SELECT t.transaction_id, t.product_id, t.transaction_type, t.quantity, t.transaction_date,
               t.remarks, t.status, p.product_name, p.quantity AS current_quantity,
               p.selling_price, v.business_name, u.full_name AS submitted_by_name
        FROM inventory_transactions t
        INNER JOIN products p ON p.product_id = t.product_id
        INNER JOIN vendors v ON v.vendor_id = p.vendor_id
        LEFT JOIN users u ON u.user_id = t.requested_by
        WHERE t.requested_by IS NOT NULL";
$params = [];

if ($statusFilter !== 'All') {
    $sql .= " AND t.status = :status";
    $params['status'] = $statusFilter;
}

$sql .= " ORDER BY t.transaction_date DESC LIMIT 100";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$requests = $stmt->fetchAll();

$pendingCountStmt = $pdo->query("SELECT COUNT(*) FROM inventory_transactions WHERE status = 'Pending' AND requested_by IS NOT NULL");
$pendingCount = (int) $pendingCountStmt->fetchColumn();

$pageTitle    = 'Inventory Approvals';
$activePage   = 'inventory';
$inventoryTab = 'approvals';
require_once __DIR__ . '/../includes/header.php';
require __DIR__ . '/../includes/inventory_tabs.php';

function approvalBadgeClass(string $status): string
{
    return match ($status) {
        'Pending'  => 'badge-warning',
        'Approved' => 'badge-success',
        'Rejected' => 'badge-danger',
        default    => 'badge-muted',
    };
}
?>

    <?php if ($flashSuccess !== ''): ?>
        <div class="alert alert-success"><?= e($flashSuccess) ?></div>
    <?php endif; ?>
    <?php if ($flashError !== ''): ?>
        <div class="alert alert-error"><ul><li><?= e($flashError) ?></li></ul></div>
    <?php endif; ?>

    <div class="page-toolbar">
        <form method="GET" class="search-form">
            <select name="status" class="status-select">
                <option value="Pending" <?= $statusFilter === 'Pending' ? 'selected' : '' ?>>Pending (<?= $pendingCount ?>)</option>
                <option value="Approved" <?= $statusFilter === 'Approved' ? 'selected' : '' ?>>Approved</option>
                <option value="Rejected" <?= $statusFilter === 'Rejected' ? 'selected' : '' ?>>Rejected</option>
                <option value="All" <?= $statusFilter === 'All' ? 'selected' : '' ?>>All</option>
            </select>
            <button type="submit" class="btn btn-secondary">Filter</button>
        </form>
    </div>

    <div class="table-card">
        <?php if (empty($requests)): ?>
            <p class="chart-empty">No <?= $statusFilter !== 'All' ? strtolower($statusFilter) . ' ' : '' ?>requests found.</p>
        <?php else: ?>
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Submitted</th>
                        <th>Vendor</th>
                        <th>Product</th>
                        <th>Type</th>
                        <th>Qty Requested</th>
                        <th>Current Stock</th>
                        <th>Submitted By</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($requests as $r): ?>
                        <tr>
                            <td class="text-muted"><?= e(date('d M Y, g:i A', strtotime($r['transaction_date']))) ?></td>
                            <td><?= e($r['business_name']) ?></td>
                            <td><?= e($r['product_name']) ?></td>
                            <td><?= e($r['transaction_type']) ?></td>
                            <td><?= $r['transaction_type'] === 'Sale' ? '-' : '+' ?><?= (int) $r['quantity'] ?></td>
                            <td><?= (int) $r['current_quantity'] ?></td>
                            <td class="text-muted"><?= e($r['submitted_by_name'] ?? 'Unknown') ?></td>
                            <td><span class="badge <?= approvalBadgeClass($r['status']) ?>"><?= e($r['status']) ?></span></td>
                            <td class="actions-cell">
                                <?php if ($r['status'] === 'Pending'): ?>
                                    <form method="POST" action="inventory_approve.php" class="inline-form">
                                        <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                                        <input type="hidden" name="transaction_id" value="<?= (int) $r['transaction_id'] ?>">
                                        <input type="hidden" name="action" value="approve">
                                        <button type="submit" class="action-link action-link-btn">Approve</button>
                                    </form>
                                    <form method="POST" action="inventory_approve.php" class="inline-form"
                                          onsubmit="return confirm('Reject this request?');">
                                        <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                                        <input type="hidden" name="transaction_id" value="<?= (int) $r['transaction_id'] ?>">
                                        <input type="hidden" name="action" value="reject">
                                        <button type="submit" class="action-link action-link-danger">Reject</button>
                                    </form>
                                <?php else: ?>
                                    <span class="text-muted">—</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>