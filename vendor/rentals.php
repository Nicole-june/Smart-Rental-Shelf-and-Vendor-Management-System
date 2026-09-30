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

$stmt = $pdo->prepare(
    "SELECT r.*, s.shelf_code, s.shelf_name
     FROM rentals r
     INNER JOIN shelves s ON s.shelf_id = r.shelf_id
     WHERE r.vendor_id = :vendor_id
     ORDER BY r.created_at DESC"
);
$stmt->execute(['vendor_id' => $vendorId]);
$rentals = $stmt->fetchAll();

function vRentalBadge(string $status): string
{
    return match ($status) {
        'Active', 'Paid' => 'badge-success',
        'Expired', 'Pending' => 'badge-muted',
        'Terminated', 'Failed' => 'badge-danger',
        default => 'badge-muted',
    };
}

$pageTitle  = 'My Rentals';
$activePage = 'rentals';
require_once __DIR__ . '/../includes/vendor_header.php';
?>

    <div class="table-card">
        <?php if (empty($rentals)): ?>
            <p class="chart-empty">You have no rental history yet.</p>
        <?php else: ?>
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Shelf</th>
                        <th>Start Date</th>
                        <th>End Date</th>
                        <th>Monthly Rent</th>
                        <th>Rental Status</th>
                        <th>Payment Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rentals as $r): ?>
                        <tr>
                            <td><?= e($r['shelf_code']) ?> — <?= e($r['shelf_name']) ?></td>
                            <td><?= e(date('d M Y', strtotime($r['start_date']))) ?></td>
                            <td><?= e(date('d M Y', strtotime($r['end_date']))) ?></td>
                            <td>KES <?= number_format((float) $r['monthly_rent'], 2) ?></td>
                            <td><span class="badge <?= vRentalBadge($r['rental_status']) ?>"><?= e($r['rental_status']) ?></span></td>
                            <td><span class="badge <?= vRentalBadge($r['payment_status']) ?>"><?= e($r['payment_status']) ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>