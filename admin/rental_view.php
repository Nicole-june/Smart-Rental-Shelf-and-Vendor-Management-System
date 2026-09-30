<?php

require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

requireRole('admin');

$rentalId = isset($_GET['id']) ? (int) $_GET['id'] : 0;


$stmt = $pdo->prepare(
    "SELECT r.*, v.business_name, v.vendor_id, v.phone AS vendor_phone,
            s.shelf_code, s.shelf_name, s.building_name, s.floor, s.location
     FROM rentals r
     INNER JOIN vendors v ON v.vendor_id = r.vendor_id
     INNER JOIN shelves s ON s.shelf_id = r.shelf_id
     WHERE r.rental_id = :id LIMIT 1"
);
$stmt->execute(['id' => $rentalId]);
$rental = $stmt->fetch();

if (!$rental) {
    redirectTo('rentals.php?error=' . urlencode('Rental not found.'));
}

function rvBadgeClass(string $status): string
{
    return match ($status) {
        'Active', 'Paid' => 'badge-success',
        'Expired', 'Pending' => 'badge-muted',
        'Terminated', 'Failed' => 'badge-danger',
        default => 'badge-muted',
    };
}

$pageTitle  = 'Rental #' . $rental['rental_id'];
$activePage = 'rentals';
require_once __DIR__ . '/../includes/header.php';
?>

    <a href="rentals.php" class="back-link">&larr; Back to Rentals</a>

    <div class="profile-header">
        <div>
            <h2>Rental #<?= (int) $rental['rental_id'] ?></h2>
            <span class="badge <?= rvBadgeClass($rental['rental_status']) ?>"><?= e($rental['rental_status']) ?></span>
            <span class="badge <?= rvBadgeClass($rental['payment_status']) ?>"><?= e($rental['payment_status']) ?> Payment</span>
        </div>
        <?php if ($rental['rental_status'] === 'Active'): ?>
            <form method="POST" action="rental_terminate.php"
                  onsubmit="return confirm('Terminate this rental? The shelf will become Available again.');">
                <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                <input type="hidden" name="rental_id" value="<?= (int) $rental['rental_id'] ?>">
                <button type="submit" class="btn btn-secondary">Terminate Rental</button>
            </form>
        <?php endif; ?>
    </div>

    <div class="profile-grid">
        <div class="profile-card">
            <h3>Vendor</h3>
            <dl class="detail-list">
                <dt>Business Name</dt>
                <dd><a href="vendor_view.php?id=<?= (int) $rental['vendor_id'] ?>"><?= e($rental['business_name']) ?></a></dd>
                <dt>Phone</dt>
                <dd><?= e($rental['vendor_phone']) ?></dd>
            </dl>
        </div>

        <div class="profile-card">
            <h3>Shelf</h3>
            <dl class="detail-list">
                <dt>Shelf Code</dt>
                <dd><?= e($rental['shelf_code']) ?></dd>
                <dt>Name</dt>
                <dd><?= e($rental['shelf_name']) ?></dd>
                <dt>Building</dt>
                <dd><?= e($rental['building_name']) ?></dd>
                <dt>Floor</dt>
                <dd><?= e($rental['floor']) ?></dd>
                <dt>Location</dt>
                <dd><?= e($rental['location']) ?></dd>
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
                <dt>Created</dt>
                <dd><?= e(date('d M Y, g:i A', strtotime($rental['created_at']))) ?></dd>
            </dl>
        </div>
    </div>

    <div class="profile-card">
        <h3>Payment</h3>
        <?php if ($rental['payment_id']): ?>
            <?php
            $paymentStmt = $pdo->prepare("SELECT * FROM payments WHERE payment_id = :id LIMIT 1");
            $paymentStmt->execute(['id' => $rental['payment_id']]);
            $payment = $paymentStmt->fetch();
            ?>
            <?php if ($payment): ?>
                <dl class="detail-list">
                    <dt>Payment Code</dt>
                    <dd><?= e($payment['payment_code']) ?></dd>
                    <dt>Amount</dt>
                    <dd>KES <?= number_format((float) $payment['amount'], 2) ?></dd>
                    <dt>Method</dt>
                    <dd><?= e($payment['payment_method']) ?></dd>
                    <dt>Confirmation Code</dt>
                    <dd><?= e($payment['confirmation_code'] ?: '—') ?></dd>
                    <dt>Payment Date</dt>
                    <dd><?= $payment['payment_date'] ? e(date('d M Y', strtotime($payment['payment_date']))) : '—' ?></dd>
                    <dt>Status</dt>
                    <dd><span class="badge badge-success"><?= e($payment['payment_status']) ?></span></dd>
                </dl>
            <?php endif; ?>
        <?php else: ?>
            <p class="chart-empty">No confirmed payment on record for this rental yet.</p>
            <a href="payment_form.php" class="btn btn-secondary">Record a Payment</a>
        <?php endif; ?>
    </div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>