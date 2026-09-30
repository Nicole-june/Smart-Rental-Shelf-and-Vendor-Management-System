<?php

require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

requireRole('admin');

$vendorId = isset($_GET['id']) ? (int) $_GET['id'] : 0;

$stmt = $pdo->prepare(
    "SELECT v.*, u.full_name, u.email, u.created_at AS account_created
     FROM vendors v
     INNER JOIN users u ON u.user_id = v.user_id
     WHERE v.vendor_id = :id
     LIMIT 1"
);
$stmt->execute(['id' => $vendorId]);
$vendor = $stmt->fetch();

if (!$vendor) {
    redirectTo('vendors.php?error=' . urlencode('Vendor not found.'));
}

$pageTitle  = 'Vendor Profile';
$activePage = 'vendors';
require_once __DIR__ . '/../includes/header.php';
?>

    <a href="vendors.php" class="back-link">&larr; Back to Vendors</a>

    <div class="profile-header">
        <div>
            <h2><?= e($vendor['business_name']) ?></h2>
            <span class="badge badge-<?= $vendor['status'] === 'Active' ? 'success' : 'muted' ?>">
                <?= e($vendor['status']) ?>
            </span>
        </div>
        <a href="vendor_form.php?id=<?= (int) $vendor['vendor_id'] ?>" class="btn btn-secondary">Edit Vendor</a>
    </div>

    <div class="profile-grid">
        <div class="profile-card">
            <h3>Contact Details</h3>
            <dl class="detail-list">
                <dt>Contact Name</dt>
                <dd><?= e($vendor['full_name']) ?></dd>

                <dt>Email</dt>
                <dd><?= e($vendor['email']) ?></dd>

                <dt>Phone</dt>
                <dd><?= e($vendor['phone']) ?></dd>

                <dt>National ID</dt>
                <dd><?= e($vendor['national_id'] ?: '—') ?></dd>

                <dt>Address</dt>
                <dd><?= nl2br(e($vendor['address'] ?: '—')) ?></dd>
            </dl>
        </div>

        <div class="profile-card">
            <h3>Account Info</h3>
            <dl class="detail-list">
                <dt>Vendor ID</dt>
                <dd>#<?= (int) $vendor['vendor_id'] ?></dd>

                <dt>Date Joined</dt>
                <dd><?= e(date('d M Y', strtotime($vendor['date_joined']))) ?></dd>

                <dt>Status</dt>
                <dd><?= e($vendor['status']) ?></dd>
            </dl>
        </div>
    </div>

    <div class="profile-card">
        <h3>Shelf & Rental Information</h3>
        <?php
        $rentalStmt = $pdo->prepare(
            "SELECT r.rental_id, r.start_date, r.end_date, r.monthly_rent, r.rental_status, r.payment_status,
                    s.shelf_code, s.shelf_name, s.building_name, s.floor
             FROM rentals r
             INNER JOIN shelves s ON s.shelf_id = r.shelf_id
             WHERE r.vendor_id = :vendor_id
             ORDER BY r.created_at DESC"
        );
        $rentalStmt->execute(['vendor_id' => $vendor['vendor_id']]);
        $vendorRentals = $rentalStmt->fetchAll();
        ?>
        <?php if (empty($vendorRentals)): ?>
            <p class="chart-empty">This vendor has no rental history yet.</p>
        <?php else: ?>
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Shelf</th>
                        <th>Start</th>
                        <th>End</th>
                        <th>Rent</th>
                        <th>Rental Status</th>
                        <th>Payment</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($vendorRentals as $vr): ?>
                        <tr>
                            <td><?= e($vr['shelf_code']) ?> — <?= e($vr['shelf_name']) ?></td>
                            <td><?= e(date('d M Y', strtotime($vr['start_date']))) ?></td>
                            <td><?= e(date('d M Y', strtotime($vr['end_date']))) ?></td>
                            <td>KES <?= number_format((float) $vr['monthly_rent'], 2) ?></td>
                            <td><span class="badge <?= $vr['rental_status'] === 'Active' ? 'badge-success' : ($vr['rental_status'] === 'Terminated' ? 'badge-danger' : 'badge-muted') ?>"><?= e($vr['rental_status']) ?></span></td>
                            <td><span class="badge <?= $vr['payment_status'] === 'Paid' ? 'badge-success' : ($vr['payment_status'] === 'Failed' ? 'badge-danger' : 'badge-warning') ?>"><?= e($vr['payment_status']) ?></span></td>
                            <td><a href="rental_view.php?id=<?= (int) $vr['rental_id'] ?>" class="action-link">View</a></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

    <div class="profile-card">
        <h3>Payment History</h3>
        <?php
        $paymentHistoryStmt = $pdo->prepare(
            "SELECT p.payment_id, p.payment_code, p.amount, p.payment_method, p.payment_date,
                    p.confirmation_code, p.payment_status, s.shelf_code
             FROM payments p
             INNER JOIN rentals r ON r.rental_id = p.rental_id
             INNER JOIN shelves s ON s.shelf_id = r.shelf_id
             WHERE p.vendor_id = :vendor_id
             ORDER BY p.created_at DESC"
        );
        $paymentHistoryStmt->execute(['vendor_id' => $vendor['vendor_id']]);
        $vendorPayments = $paymentHistoryStmt->fetchAll();
        ?>
        <?php if (empty($vendorPayments)): ?>
            <p class="chart-empty">This vendor has no payment history yet.</p>
        <?php else: ?>
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Payment Code</th>
                        <th>Shelf</th>
                        <th>Amount</th>
                        <th>Method</th>
                        <th>Date</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($vendorPayments as $vp): ?>
                        <tr>
                            <td><strong><?= e($vp['payment_code']) ?></strong></td>
                            <td><?= e($vp['shelf_code']) ?></td>
                            <td>KES <?= number_format((float) $vp['amount'], 2) ?></td>
                            <td><?= e($vp['payment_method']) ?></td>
                            <td><?= $vp['payment_date'] ? e(date('d M Y', strtotime($vp['payment_date']))) : '—' ?></td>
                            <td>
                                <span class="badge <?= $vp['payment_status'] === 'Confirmed' ? 'badge-success' : ($vp['payment_status'] === 'Rejected' ? 'badge-danger' : 'badge-warning') ?>">
                                    <?= e($vp['payment_status']) ?>
                                </span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>