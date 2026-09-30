<?php

require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

requireRole('admin');

$search = cleanInput($_GET['q'] ?? '');
$statusFilter = $_GET['status'] ?? 'All';
if (!in_array($statusFilter, ['All', 'Pending', 'Confirmed', 'Rejected'], true)) {
    $statusFilter = 'All';
}
$methodFilter = $_GET['method'] ?? 'All';
if (!in_array($methodFilter, ['All', 'Cash', 'M-Pesa', 'Bank'], true)) {
    $methodFilter = 'All';
}

$flashSuccess = $_GET['success'] ?? '';
$flashError   = $_GET['error'] ?? '';

$whereSql = "WHERE 1 = 1";
$params = [];

if ($search !== '') {
    $whereSql .= " AND (p.payment_code LIKE :s1 OR v.business_name LIKE :s2 OR s.shelf_code LIKE :s3)";
    $like = '%' . $search . '%';
    $params['s1'] = $like;
    $params['s2'] = $like;
    $params['s3'] = $like;
}
if ($statusFilter !== 'All') {
    $whereSql .= " AND p.payment_status = :status";
    $params['status'] = $statusFilter;
}
if ($methodFilter !== 'All') {
    $whereSql .= " AND p.payment_method = :method";
    $params['method'] = $methodFilter;
}

$countStmt = $pdo->prepare(
    "SELECT COUNT(*) FROM payments p
     INNER JOIN vendors v ON v.vendor_id = p.vendor_id
     INNER JOIN rentals r ON r.rental_id = p.rental_id
     INNER JOIN shelves s ON s.shelf_id = r.shelf_id
     $whereSql"
);
$countStmt->execute($params);
$totalPayments = (int) $countStmt->fetchColumn();

$perPage = 20;
$page = currentPage();
$totalPages = max(1, (int) ceil($totalPayments / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

$sql = "SELECT p.*, v.business_name, r.rental_id, s.shelf_code
        FROM payments p
        INNER JOIN vendors v ON v.vendor_id = p.vendor_id
        INNER JOIN rentals r ON r.rental_id = p.rental_id
        INNER JOIN shelves s ON s.shelf_id = r.shelf_id
        $whereSql
        ORDER BY p.created_at DESC
        LIMIT $perPage OFFSET $offset";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$payments = $stmt->fetchAll();

$pageTitle  = 'Payment Management';
$activePage = 'payments';
require_once __DIR__ . '/../includes/header.php';

function paymentBadgeClass(string $status): string
{
    return match ($status) {
        'Confirmed' => 'badge-success',
        'Pending'   => 'badge-warning',
        'Rejected'  => 'badge-danger',
        default     => 'badge-muted',
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
            <input type="text" name="q" value="<?= e($search) ?>" placeholder="Search by payment code, vendor, or shelf..." class="search-input">
            <select name="status" class="status-select">
                <option value="All" <?= $statusFilter === 'All' ? 'selected' : '' ?>>All Statuses</option>
                <option value="Pending" <?= $statusFilter === 'Pending' ? 'selected' : '' ?>>Pending</option>
                <option value="Confirmed" <?= $statusFilter === 'Confirmed' ? 'selected' : '' ?>>Confirmed</option>
                <option value="Rejected" <?= $statusFilter === 'Rejected' ? 'selected' : '' ?>>Rejected</option>
            </select>
            <select name="method" class="status-select">
                <option value="All" <?= $methodFilter === 'All' ? 'selected' : '' ?>>All Methods</option>
                <option value="Cash" <?= $methodFilter === 'Cash' ? 'selected' : '' ?>>Cash</option>
                <option value="M-Pesa" <?= $methodFilter === 'M-Pesa' ? 'selected' : '' ?>>M-Pesa</option>
                <option value="Bank" <?= $methodFilter === 'Bank' ? 'selected' : '' ?>>Bank</option>
            </select>
            <button type="submit" class="btn btn-secondary">Filter</button>
            <?php if ($search !== '' || $statusFilter !== 'All' || $methodFilter !== 'All'): ?>
                <a href="payments.php" class="btn btn-link">Clear</a>
            <?php endif; ?>
        </form>

        <a href="payment_form.php" class="btn btn-primary">+ Record Payment</a>
    </div>

    <div class="table-card">
        <?php if (empty($payments)): ?>
            <p class="chart-empty">No payments found.</p>
        <?php else: ?>
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Payment Code</th>
                        <th>Vendor</th>
                        <th>Shelf</th>
                        <th>Amount</th>
                        <th>Method</th>
                        <th>Payment Date</th>
                        <th>Status</th>
                        <th>Confirmation Code</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($payments as $p): ?>
                        <tr>
                            <td><strong><?= e($p['payment_code']) ?></strong></td>
                            <td><?= e($p['business_name']) ?></td>
                            <td><?= e($p['shelf_code']) ?></td>
                            <td>KES <?= number_format((float) $p['amount'], 2) ?></td>
                            <td><?= e($p['payment_method']) ?></td>
                            <td><?= $p['payment_date'] ? e(date('d M Y', strtotime($p['payment_date']))) : '—' ?></td>
                            <td><span class="badge <?= paymentBadgeClass($p['payment_status']) ?>"><?= e($p['payment_status']) ?></span></td>
                            <td class="text-muted"><?= e($p['confirmation_code'] ?: '—') ?></td>
                            <td class="actions-cell">
                                <?php if ($p['payment_status'] === 'Pending'): ?>
                                    <form method="POST" action="payment_confirm.php" class="inline-form confirm-form">
                                        <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                                        <input type="hidden" name="payment_id" value="<?= (int) $p['payment_id'] ?>">
                                        <input type="hidden" name="action" value="confirm">
                                        <?php if ($p['confirmation_code']): ?>
                                            <input type="text" name="confirmation_code" value="<?= e($p['confirmation_code']) ?>" class="mini-input" required>
                                        <?php else: ?>
                                            <input type="text" name="confirmation_code" placeholder="Confirmation code" class="mini-input" required>
                                        <?php endif; ?>
                                        <button type="submit" class="action-link action-link-btn">Confirm</button>
                                    </form>
                                    <form method="POST" action="payment_confirm.php" class="inline-form"
                                          onsubmit="return confirm('Reject this payment? The rental will be marked as Failed.');">
                                        <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                                        <input type="hidden" name="payment_id" value="<?= (int) $p['payment_id'] ?>">
                                        <input type="hidden" name="action" value="reject">
                                        <button type="submit" class="action-link action-link-danger">Reject</button>
                                    </form>
                                <?php else: ?>
                                    <a href="rental_view.php?id=<?= (int) $p['rental_id'] ?>" class="action-link">View Rental</a>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

    <?= renderPagination('payments.php', $page, $totalPages) ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>