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

$flashSuccess = $_GET['success'] ?? '';
$flashError   = $_GET['error'] ?? '';

$stmt = $pdo->prepare(
    "SELECT p.*, r.rental_id, s.shelf_code
     FROM payments p
     INNER JOIN rentals r ON r.rental_id = p.rental_id
     INNER JOIN shelves s ON s.shelf_id = r.shelf_id
     WHERE p.vendor_id = :vendor_id
     ORDER BY p.created_at DESC"
);
$stmt->execute(['vendor_id' => $vendorId]);
$payments = $stmt->fetchAll();

function vPaymentBadge(string $status): string
{
    return match ($status) {
        'Confirmed' => 'badge-success',
        'Pending'   => 'badge-warning',
        'Rejected'  => 'badge-danger',
        default     => 'badge-muted',
    };
}

$pageTitle  = 'Payments';
$activePage = 'payments';
require_once __DIR__ . '/../includes/vendor_header.php';
?>

    <?php if ($flashSuccess !== ''): ?>
        <div class="alert alert-success"><?= e($flashSuccess) ?></div>
    <?php endif; ?>
    <?php if ($flashError !== ''): ?>
        <div class="alert alert-error"><ul><li><?= e($flashError) ?></li></ul></div>
    <?php endif; ?>

    <div class="page-toolbar">
        <p class="text-muted" style="margin: 0;">Already paid your rent? Submit the transaction code below for the admin to confirm.</p>
        <a href="payment_form.php" class="btn btn-primary">+ Submit Payment</a>
    </div>

    <div class="table-card">
        <?php if (empty($payments)): ?>
            <p class="chart-empty">You have no payment history yet.</p>
        <?php else: ?>
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Payment Code</th>
                        <th>Shelf</th>
                        <th>Amount</th>
                        <th>Method</th>
                        <th>Payment Date</th>
                        <th>Confirmation Code</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($payments as $p): ?>
                        <tr>
                            <td><strong><?= e($p['payment_code']) ?></strong></td>
                            <td><?= e($p['shelf_code']) ?></td>
                            <td>KES <?= number_format((float) $p['amount'], 2) ?></td>
                            <td><?= e($p['payment_method']) ?></td>
                            <td><?= $p['payment_date'] ? e(date('d M Y', strtotime($p['payment_date']))) : '—' ?></td>
                            <td class="text-muted"><?= e($p['confirmation_code'] ?: '—') ?></td>
                            <td><span class="badge <?= vPaymentBadge($p['payment_status']) ?>"><?= e($p['payment_status']) ?></span></td>
                            <td class="actions-cell">
                                <?php if ($p['payment_status'] === 'Pending'): ?>
                                    <a href="payment_edit.php?id=<?= (int) $p['payment_id'] ?>" class="action-link">Edit</a>
                                    <form method="POST" action="payment_cancel.php" class="inline-form"
                                          onsubmit="return confirm('Cancel this payment submission?');">
                                        <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                                        <input type="hidden" name="payment_id" value="<?= (int) $p['payment_id'] ?>">
                                        <button type="submit" class="action-link action-link-danger">Cancel</button>
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