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

$errors = [];

// This vendor's active rentals that still need a payment, and don't
// already have one sitting Pending.
$rentalsStmt = $pdo->prepare(
    "SELECT r.rental_id, r.monthly_rent, r.payment_status, s.shelf_code
     FROM rentals r
     INNER JOIN shelves s ON s.shelf_id = r.shelf_id
     WHERE r.vendor_id = :vendor_id
       AND r.rental_status = 'Active'
       AND r.payment_status IN ('Pending', 'Failed')
       AND NOT EXISTS (
           SELECT 1 FROM payments p2 WHERE p2.rental_id = r.rental_id AND p2.payment_status = 'Pending'
       )
     ORDER BY r.created_at DESC"
);
$rentalsStmt->execute(['vendor_id' => $vendorId]);
$rentals = $rentalsStmt->fetchAll();

$form = [
    'rental_id'         => 0,
    'amount'            => '',
    'payment_method'    => 'M-Pesa',
    'payment_date'      => date('Y-m-d'),
    'confirmation_code' => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Your session expired. Please try again.';
    }

    $form['rental_id']         = (int) ($_POST['rental_id'] ?? 0);
    $form['amount']            = cleanInput($_POST['amount'] ?? '');
    $form['payment_method']    = $_POST['payment_method'] ?? 'M-Pesa';
    $form['payment_date']      = cleanInput($_POST['payment_date'] ?? '');
    $form['confirmation_code'] = cleanInput($_POST['confirmation_code'] ?? '');

    if (!in_array($form['payment_method'], ['Cash', 'M-Pesa', 'Bank'], true)) {
        $form['payment_method'] = 'M-Pesa';
    }
    if ($form['rental_id'] <= 0) {
        $errors[] = 'Please select a rental.';
    }
    if (!is_numeric($form['amount']) || (float) $form['amount'] <= 0) {
        $errors[] = 'Amount must be a positive number.';
    }
    if ($form['payment_date'] === '') {
        $errors[] = 'Payment date is required.';
    }
    if ($form['confirmation_code'] === '') {
        $errors[] = 'Please enter the transaction/confirmation code you received (e.g. M-Pesa code, bank reference).';
    }

    if (empty($errors)) {
        try {
            $pdo->beginTransaction();

            // Ownership check: rental must belong to this vendor.
            $rentalCheck = $pdo->prepare(
                "SELECT rental_id FROM rentals WHERE rental_id = :id AND vendor_id = :vendor_id LIMIT 1"
            );
            $rentalCheck->execute(['id' => $form['rental_id'], 'vendor_id' => $vendorId]);
            if (!$rentalCheck->fetch()) {
                throw new RuntimeException('Rental not found.');
            }

            $insertStmt = $pdo->prepare(
                "INSERT INTO payments (rental_id, vendor_id, amount, payment_code, payment_method, confirmation_code, payment_date, payment_status, created_at)
                 VALUES (:rental_id, :vendor_id, :amount, NULL, :payment_method, :confirmation_code, :payment_date, 'Pending', NOW())"
            );
            $insertStmt->execute([
                'rental_id'         => $form['rental_id'],
                'vendor_id'         => $vendorId,
                'amount'            => $form['amount'],
                'payment_method'    => $form['payment_method'],
                'confirmation_code' => $form['confirmation_code'],
                'payment_date'      => $form['payment_date'],
            ]);

            $newPaymentId = (int) $pdo->lastInsertId();
            $code = generatePaymentCode($newPaymentId);

            $codeStmt = $pdo->prepare("UPDATE payments SET payment_code = :code WHERE payment_id = :id");
            $codeStmt->execute(['code' => $code, 'id' => $newPaymentId]);

            $pdo->commit();

            logActivity($pdo, currentUserId(), 'Vendor submitted payment ' . $code . ' (awaiting admin confirmation)');
            redirectTo('payments.php?success=' . urlencode('Payment submitted. The admin will confirm it shortly.'));

        } catch (RuntimeException $e) {
            $pdo->rollBack();
            $errors[] = $e->getMessage();
        } catch (PDOException $e) {
            $pdo->rollBack();
            $errors[] = 'Something went wrong while saving. Please try again.';
        }
    }
}

$pageTitle  = 'Submit Payment';
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

        <?php if (empty($rentals)): ?>
            <p class="chart-empty">You have no rentals currently needing a payment.</p>
        <?php else: ?>
            <form method="POST" action="payment_form.php" class="stacked-form" novalidate>
                <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">

                <div class="form-row">
                    <div class="form-group form-group-full">
                        <label for="rental_id">Rental</label>
                        <select id="rental_id" name="rental_id" required onchange="fillAmount(this)">
                            <option value="">Select a rental...</option>
                            <?php foreach ($rentals as $r): ?>
                                <option value="<?= (int) $r['rental_id'] ?>" data-rent="<?= e((string) $r['monthly_rent']) ?>" <?= $form['rental_id'] === (int) $r['rental_id'] ? 'selected' : '' ?>>
                                    <?= e($r['shelf_code']) ?> (Monthly Rent: KES <?= number_format((float) $r['monthly_rent'], 2) ?>)
                                    <?= $r['payment_status'] === 'Failed' ? ' [previous payment was rejected]' : '' ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

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
                        <input type="date" id="payment_date" name="payment_date" value="<?= e($form['payment_date']) ?>" required>
                    </div>
                    <div class="form-group">
                        <label for="confirmation_code">Transaction / Confirmation Code</label>
                        <input type="text" id="confirmation_code" name="confirmation_code" value="<?= e($form['confirmation_code']) ?>" placeholder="e.g. M-Pesa code QK7X..." required>
                    </div>
                </div>

                <p class="text-muted">This will be marked <strong>Pending</strong> until the admin verifies and confirms it.</p>

                <button type="submit" class="btn btn-primary">Submit Payment</button>
            </form>

            <script>
                function fillAmount(selectEl) {
                    var option = selectEl.options[selectEl.selectedIndex];
                    var rent = option.getAttribute('data-rent');
                    if (rent) {
                        document.getElementById('amount').value = rent;
                    }
                }
            </script>
        <?php endif; ?>
    </div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>