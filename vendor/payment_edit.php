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

$paymentId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$errors = [];

$stmt = $pdo->prepare(
    "SELECT p.*, s.shelf_code
     FROM payments p
     INNER JOIN rentals r ON r.rental_id = p.rental_id
     INNER JOIN shelves s ON s.shelf_id = r.shelf_id
     WHERE p.payment_id = :id AND p.vendor_id = :vendor_id
     LIMIT 1"
);
$stmt->execute(['id' => $paymentId, 'vendor_id' => $vendorId]);
$payment = $stmt->fetch();

if (!$payment) {
    redirectTo('payments.php?error=' . urlencode('Payment not found.'));
}

if ($payment['payment_status'] !== 'Pending') {
    redirectTo('payments.php?error=' . urlencode('This payment has already been ' . strtolower($payment['payment_status']) . ' by the admin and can no longer be edited.'));
}

$form = [
    'amount'            => $payment['amount'],
    'payment_method'    => $payment['payment_method'],
    'payment_date'      => $payment['payment_date'],
    'confirmation_code' => $payment['confirmation_code'],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Your session expired. Please try again.';
    }

    $form['amount']            = cleanInput($_POST['amount'] ?? '');
    $form['payment_method']    = $_POST['payment_method'] ?? 'M-Pesa';
    $form['payment_date']      = cleanInput($_POST['payment_date'] ?? '');
    $form['confirmation_code'] = cleanInput($_POST['confirmation_code'] ?? '');

    if (!in_array($form['payment_method'], ['Cash', 'M-Pesa', 'Bank'], true)) {
        $form['payment_method'] = 'M-Pesa';
    }
    if (!is_numeric($form['amount']) || (float) $form['amount'] <= 0) {
        $errors[] = 'Amount must be a positive number.';
    }
    if ($form['payment_date'] === '') {
        $errors[] = 'Payment date is required.';
    }
    if ($form['confirmation_code'] === '') {
        $errors[] = 'Please enter the transaction/confirmation code.';
    }

    if (empty($errors)) {
        try {
            // Re-check it's still Pending right before writing, in case the
            // admin processed it in the moment between page load and submit.
            $lockStmt = $pdo->prepare(
                "SELECT payment_status FROM payments WHERE payment_id = :id AND vendor_id = :vendor_id LIMIT 1"
            );
            $lockStmt->execute(['id' => $paymentId, 'vendor_id' => $vendorId]);
            $current = $lockStmt->fetch();

            if (!$current || $current['payment_status'] !== 'Pending') {
                throw new RuntimeException('This payment was just processed by the admin and can no longer be edited.');
            }

            $updateStmt = $pdo->prepare(
                "UPDATE payments SET amount = :amount, payment_method = :payment_method,
                        payment_date = :payment_date, confirmation_code = :confirmation_code
                 WHERE payment_id = :id AND vendor_id = :vendor_id AND payment_status = 'Pending'"
            );
            $updateStmt->execute([
                'amount'            => $form['amount'],
                'payment_method'    => $form['payment_method'],
                'payment_date'      => $form['payment_date'],
                'confirmation_code' => $form['confirmation_code'],
                'id'                => $paymentId,
                'vendor_id'         => $vendorId,
            ]);

            logActivity($pdo, currentUserId(), 'Vendor edited payment ' . $payment['payment_code']);
            redirectTo('payments.php?success=' . urlencode('Payment updated.'));

        } catch (RuntimeException $e) {
            $errors[] = $e->getMessage();
        } catch (PDOException $e) {
            $errors[] = 'Something went wrong while saving. Please try again.';
        }
    }
}

$pageTitle  = 'Edit Payment';
$activePage = 'payments';
require_once __DIR__ . '/../includes/vendor_header.php';
?>

    <div class="form-card">
        <a href="payments.php" class="back-link">&larr; Back to Payments</a>

        <?php if (!empty($errors)): ?>
            <div class="alert alert-error">
                <ul><?php foreach ($errors as $error): ?><li><?= e($error) ?></li><?php endforeach; ?></ul>
            </div>
        <?php endif; ?>

        <div class="shelf-code-display">
            Payment <strong><?= e($payment['payment_code']) ?></strong> for shelf <strong><?= e($payment['shelf_code']) ?></strong>
            <span class="text-muted">— still Pending, safe to edit</span>
        </div>

        <form method="POST" action="payment_edit.php?id=<?= $paymentId ?>" class="stacked-form" novalidate>
            <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">

            <div class="form-row">
                <div class="form-group">
                    <label for="amount">Amount Paid (KES)</label>
                    <input type="number" step="0.01" min="0" id="amount" name="amount" value="<?= e((string) $form['amount']) ?>" required>
                </div>
                <div class="form-group">
                    <label for="payment_method">Payment Method</label>
                    <select id="payment_method" name="payment_method">
                        <option value="M-Pesa" <?= $form['payment_method'] === 'M-Pesa' ? 'selected' : '' ?>>M-Pesa</option>
                        <option value="Bank" <?= $form['payment_method'] === 'Bank' ? 'selected' : '' ?>>Bank</option>
                        <option value="Cash" <?= $form['payment_method'] === 'Cash' ? 'selected' : '' ?>>Cash</option>
                    </select>
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label for="payment_date">Date You Paid</label>
                    <input type="date" id="payment_date" name="payment_date" value="<?= e(date('Y-m-d', strtotime($form['payment_date']))) ?>" required>
                </div>
                <div class="form-group">
                    <label for="confirmation_code">Transaction / Confirmation Code</label>
                    <input type="text" id="confirmation_code" name="confirmation_code" value="<?= e($form['confirmation_code']) ?>" required>
                </div>
            </div>

            <button type="submit" class="btn btn-primary">Save Changes</button>
        </form>
    </div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>