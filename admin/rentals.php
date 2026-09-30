<?php

require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

requireRole('admin');

// Auto-expire any rentals whose end_date has passed, before we query.
processExpiredRentals($pdo, currentUserId());

$search = cleanInput($_GET['q'] ?? '');
$rentalStatusFilter = $_GET['rental_status'] ?? 'All';
if (!in_array($rentalStatusFilter, ['All', 'Active', 'Expired', 'Terminated'], true)) {
    $rentalStatusFilter = 'All';
}
$paymentStatusFilter = $_GET['payment_status'] ?? 'All';
if (!in_array($paymentStatusFilter, ['All', 'Pending', 'Paid', 'Failed'], true)) {
    $paymentStatusFilter = 'All';
}

$flashSuccess = $_GET['success'] ?? '';
$flashError   = $_GET['error'] ?? '';

$whereSql = "WHERE 1 = 1";
$params = [];

if ($search !== '') {
    $whereSql .= " AND (v.business_name LIKE :s1 OR s.shelf_code LIKE :s2 OR s.shelf_name LIKE :s3)";
    $like = '%' . $search . '%';
    $params['s1'] = $like;
    $params['s2'] = $like;
    $params['s3'] = $like;
}
if ($rentalStatusFilter !== 'All') {
    $whereSql .= " AND r.rental_status = :rstatus";
    $params['rstatus'] = $rentalStatusFilter;
}
if ($paymentStatusFilter !== 'All') {
    $whereSql .= " AND r.payment_status = :pstatus";
    $params['pstatus'] = $paymentStatusFilter;
}

$countStmt = $pdo->prepare(
    "SELECT COUNT(*) FROM rentals r
     INNER JOIN vendors v ON v.vendor_id = r.vendor_id
     INNER JOIN shelves s ON s.shelf_id = r.shelf_id
     $whereSql"
);
$countStmt->execute($params);
$totalRentals = (int) $countStmt->fetchColumn();

$perPage = 20;
$page = currentPage();
$totalPages = max(1, (int) ceil($totalRentals / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

$sql = "SELECT r.rental_id, r.start_date, r.end_date, r.monthly_rent, r.rental_status,
               r.payment_status, v.vendor_id, v.business_name, s.shelf_id, s.shelf_code, s.shelf_name
        FROM rentals r
        INNER JOIN vendors v ON v.vendor_id = r.vendor_id
        INNER JOIN shelves s ON s.shelf_id = r.shelf_id
        $whereSql
        ORDER BY r.created_at DESC
        LIMIT $perPage OFFSET $offset";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rentals = $stmt->fetchAll();

$pageTitle  = 'Rental Management';
$activePage = 'rentals';
require_once __DIR__ . '/../includes/header.php';

function rentalBadgeClass(string $status): string
{
    return match ($status) {
        'Active'    => 'badge-success',
        'Expired'   => 'badge-muted',
        'Terminated'=> 'badge-danger',
        'Paid'      => 'badge-success',
        'Pending'   => 'badge-warning',
        'Failed'    => 'badge-danger',
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
            <input
                type="text"
                name="q"
                value="<?= e($search) ?>"
                placeholder="Search by vendor or shelf..."
                class="search-input"
            >
            <select name="rental_status" class="status-select">
                <option value="All" <?= $rentalStatusFilter === 'All' ? 'selected' : '' ?>>All Rental Statuses</option>
                <option value="Active" <?= $rentalStatusFilter === 'Active' ? 'selected' : '' ?>>Active</option>
                <option value="Expired" <?= $rentalStatusFilter === 'Expired' ? 'selected' : '' ?>>Expired</option>
                <option value="Terminated" <?= $rentalStatusFilter === 'Terminated' ? 'selected' : '' ?>>Terminated</option>
            </select>
            <select name="payment_status" class="status-select">
                <option value="All" <?= $paymentStatusFilter === 'All' ? 'selected' : '' ?>>All Payment Statuses</option>
                <option value="Pending" <?= $paymentStatusFilter === 'Pending' ? 'selected' : '' ?>>Pending</option>
                <option value="Paid" <?= $paymentStatusFilter === 'Paid' ? 'selected' : '' ?>>Paid</option>
                <option value="Failed" <?= $paymentStatusFilter === 'Failed' ? 'selected' : '' ?>>Failed</option>
            </select>
            <button type="submit" class="btn btn-secondary">Filter</button>
            <?php if ($search !== '' || $rentalStatusFilter !== 'All' || $paymentStatusFilter !== 'All'): ?>
                <a href="rentals.php" class="btn btn-link">Clear</a>
            <?php endif; ?>
        </form>

        <a href="rental_form.php" class="btn btn-primary">+ Create Rental</a>
    </div>

    <div class="table-card">
        <?php if (empty($rentals)): ?>
            <p class="chart-empty">No rentals found<?= $search !== '' ? ' for "' . e($search) . '"' : '' ?>.</p>
        <?php else: ?>
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Vendor</th>
                        <th>Shelf</th>
                        <th>Start Date</th>
                        <th>End Date</th>
                        <th>Monthly Rent</th>
                        <th>Rental Status</th>
                        <th>Payment Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rentals as $rental): ?>
                        <tr>
                            <td><?= e($rental['business_name']) ?></td>
                            <td><?= e($rental['shelf_code']) ?> — <?= e($rental['shelf_name']) ?></td>
                            <td><?= e(date('d M Y', strtotime($rental['start_date']))) ?></td>
                            <td><?= e(date('d M Y', strtotime($rental['end_date']))) ?></td>
                            <td>KES <?= number_format((float) $rental['monthly_rent'], 2) ?></td>
                            <td><span class="badge <?= rentalBadgeClass($rental['rental_status']) ?>"><?= e($rental['rental_status']) ?></span></td>
                            <td><span class="badge <?= rentalBadgeClass($rental['payment_status']) ?>"><?= e($rental['payment_status']) ?></span></td>
                            <td class="actions-cell">
                                <a href="rental_view.php?id=<?= (int) $rental['rental_id'] ?>" class="action-link">View</a>
                                <?php if ($rental['rental_status'] === 'Active'): ?>
                                    <form method="POST" action="rental_terminate.php" class="inline-form"
                                          onsubmit="return confirm('Terminate this rental? The shelf will become Available again.');">
                                        <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                                        <input type="hidden" name="rental_id" value="<?= (int) $rental['rental_id'] ?>">
                                        <button type="submit" class="action-link action-link-danger">Terminate</button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

    <?= renderPagination('rentals.php', $page, $totalPages) ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>