<?php
/**
 * File: admin/payment_confirm.php
 * Folder: admin/
 * Purpose: Handles both Confirm and Reject actions from payments.php.
 *          POST-only, CSRF-protected.
 *
 *          Confirm: requires a confirmation_code. Sets payment_status =
 *          'Confirmed', saves the code, and updates the linked rental:
 *          rentals.payment_id = this payment, rentals.payment_status = 'Paid'.
 *
 *          Reject: sets payment_status = 'Rejected'. The rental's
 *          payment_id is left untouched (never set for a rejected
 *          payment) and rentals.payment_status = 'Failed', so the admin
 *          can record a fresh payment attempt for the same rental.
 */

require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

requireRole('admin');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirectTo('payments.php');
}

if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) {
    redirectTo('payments.php?error=' . urlencode('Your session expired. Please try again.'));
}

$paymentId = (int) ($_POST['payment_id'] ?? 0);
$action    = $_POST['action'] ?? '';

if ($paymentId <= 0 || !in_array($action, ['confirm', 'reject'], true)) {
    redirectTo('payments.php?error=' . urlencode('Invalid request.'));
}

$stmt = $pdo->prepare(
    "SELECT payment_id, rental_id, vendor_id, payment_status, payment_code, confirmation_code FROM payments WHERE payment_id = :id LIMIT 1"
);
$stmt->execute(['id' => $paymentId]);
$payment = $stmt->fetch();

if (!$payment) {
    redirectTo('payments.php?error=' . urlencode('Payment not found.'));
}

if ($payment['payment_status'] !== 'Pending') {
    redirectTo('payments.php?error=' . urlencode('This payment has already been processed.'));
}

try {
    $pdo->beginTransaction();

    if ($action === 'confirm') {
        
        $submittedCode = cleanInput($_POST['confirmation_code'] ?? '');
        $confirmationCode = $submittedCode !== ''
            ? $submittedCode
            : ($payment['confirmation_code'] ?? '');

        if ($confirmationCode === '') {
            throw new RuntimeException('A confirmation code is required to confirm a payment.');
        }

        $updatePayment = $pdo->prepare(
            "UPDATE payments SET payment_status = 'Confirmed', confirmation_code = :code WHERE payment_id = :id"
        );
        $updatePayment->execute(['code' => $confirmationCode, 'id' => $paymentId]);

        $updateRental = $pdo->prepare(
            "UPDATE rentals SET payment_id = :payment_id, payment_status = 'Paid' WHERE rental_id = :rental_id"
        );
        $updateRental->execute(['payment_id' => $paymentId, 'rental_id' => $payment['rental_id']]);

        $pdo->commit();
        logActivity($pdo, currentUserId(), 'Confirmed payment ' . $payment['payment_code']);
        createNotification(
            $pdo,
            $payment['vendor_id'],
            'Your payment ' . $payment['payment_code'] . ' has been confirmed. Your rental is now marked Paid.',
            'Payment'
        );
        redirectTo('payments.php?success=' . urlencode('Payment ' . $payment['payment_code'] . ' confirmed. Rental marked as Paid.'));

    } else {
        $updatePayment = $pdo->prepare("UPDATE payments SET payment_status = 'Rejected' WHERE payment_id = :id");
        $updatePayment->execute(['id' => $paymentId]);

        $updateRental = $pdo->prepare("UPDATE rentals SET payment_status = 'Failed' WHERE rental_id = :rental_id");
        $updateRental->execute(['rental_id' => $payment['rental_id']]);

        $pdo->commit();
        logActivity($pdo, currentUserId(), 'Rejected payment ' . $payment['payment_code']);
        createNotification(
            $pdo,
            $payment['vendor_id'],
            'Your payment ' . $payment['payment_code'] . ' was rejected. Please check the details and submit again.',
            'Payment'
        );
        redirectTo('payments.php?success=' . urlencode('Payment ' . $payment['payment_code'] . ' rejected. Rental marked as Failed.'));
    }

} catch (RuntimeException $e) {
    $pdo->rollBack();
    redirectTo('payments.php?error=' . urlencode($e->getMessage()));
} catch (PDOException $e) {
    $pdo->rollBack();
    redirectTo('payments.php?error=' . urlencode('Something went wrong. Please try again.'));
}