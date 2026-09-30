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
    "SELECT r.start_date, r.end_date, r.monthly_rent, r.rental_status, r.payment_status,
            s.shelf_code, s.shelf_name, s.building_name, s.floor, s.location, s.size, s.status AS shelf_status
     FROM rentals r
     INNER JOIN shelves s ON s.shelf_id = r.shelf_id
     WHERE r.vendor_id = :vendor_id AND r.rental_status = 'Active'
     ORDER BY r.created_at DESC LIMIT 1"
);
$stmt->execute(['vendor_id' => $vendorId]);
$rental = $stmt->fetch();

$pageTitle  = 'My Shelf';
$activePage = 'shelf';
require_once __DIR__ . '/../includes/vendor_header.php';
?>

    <?php if (!$rental): ?>
        <div class="profile-card">
            <p class="chart-empty">You don't currently have an active shelf rental. Contact the admin to get one assigned.</p>
        </div>
    <?php else: ?>
        <div class="profile-header">
            <div>
                <h2><?= e($rental['shelf_code']) ?> — <?= e($rental['shelf_name']) ?></h2>
                <span class="badge badge-success">Active Rental</span>
            </div>
        </div>

        <div class="profile-grid">
            <div class="profile-card">
                <h3>Location</h3>
                <dl class="detail-list">
                    <dt>Building</dt>
                    <dd><?= e($rental['building_name']) ?></dd>
                    <dt>Floor</dt>
                    <dd><?= e($rental['floor']) ?></dd>
                    <dt>Location</dt>
                    <dd><?= e($rental['location']) ?></dd>
                    <dt>Size</dt>
                    <dd><?= e($rental['size']) ?></dd>
                </dl>
            </div>

            <div class="profile-card">
                <h3>Rental Terms</h3>
                <dl class="detail-list">
                    <dt>Start Date</dt>
                    <dd><?= e(date('d M Y', strtotime($rental['start_date']))) ?></dd>
                    <dt>End Date</dt>
                    <dd><?= e(date('d M Y', strtotime($rental['end_date']))) ?></dd>
                    <dt>Monthly Rent</dt>
                    <dd>KES <?= number_format((float) $rental['monthly_rent'], 2) ?></dd>
                    <dt>Payment Status</dt>
                    <dd><span class="badge <?= $rental['payment_status'] === 'Paid' ? 'badge-success' : ($rental['payment_status'] === 'Failed' ? 'badge-danger' : 'badge-warning') ?>"><?= e($rental['payment_status']) ?></span></dd>
                </dl>
            </div>
        </div>
    <?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>