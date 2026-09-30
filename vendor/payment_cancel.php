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

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirectTo('payments.php');
}
if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) {
    redirectTo('payments.php?error=' . urlencode('Your session expired. Please try again.'));
}

$paymentId = (int) ($_POST['payment_id'] ?? 0);

$stmt = $pdo->prepare(
    "SELECT payment_code, payment_status FROM payments WHERE payment_id = :id AND vendor_id = :vendor_id LIMIT 1"
);
$stmt->execute(['id' => $paymentId, 'vendor_id' => $vendorId]);
$payment = $stmt->fetch();

if (!$payment) {
    redirectTo('payments.php?error=' . urlencode('Payment not found.'));
}

if ($payment['payment_status'] !== 'Pending') {
    redirectTo('payments.php?error=' . urlencode('This payment has already been processed and can no longer be cancelled.'));
}

try {
    $deleteStmt = $pdo->prepare(
        "DELETE FROM payments WHERE payment_id = :id AND vendor_id = :vendor_id AND payment_status = 'Pending'"
    );
    $deleteStmt->execute(['id' => $paymentId, 'vendor_id' => $vendorId]);

    logActivity($pdo, currentUserId(), 'Vendor cancelled payment ' . $payment['payment_code']);
    redirectTo('payments.php?success=' . urlencode('Payment cancelled.'));

} catch (PDOException $e) {
    redirectTo('payments.php?error=' . urlencode('Something went wrong. Please try again.'));
}