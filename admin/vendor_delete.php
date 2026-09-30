<?php

require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

requireRole('admin');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirectTo('vendors.php');
}

if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) {
    redirectTo('vendors.php?error=' . urlencode('Your session expired. Please try again.'));
}

$vendorId = (int) ($_POST['vendor_id'] ?? 0);

if ($vendorId <= 0) {
    redirectTo('vendors.php?error=' . urlencode('Invalid vendor.'));
}

$stmt = $pdo->prepare("SELECT user_id, business_name FROM vendors WHERE vendor_id = :id LIMIT 1");
$stmt->execute(['id' => $vendorId]);
$vendor = $stmt->fetch();

if (!$vendor) {
    redirectTo('vendors.php?error=' . urlencode('Vendor not found.'));
}

try {
    $pdo->beginTransaction();

    $deleteVendor = $pdo->prepare("DELETE FROM vendors WHERE vendor_id = :id");
    $deleteVendor->execute(['id' => $vendorId]);

    $deleteUser = $pdo->prepare("DELETE FROM users WHERE user_id = :id");
    $deleteUser->execute(['id' => $vendor['user_id']]);

    $pdo->commit();

    logActivity($pdo, currentUserId(), 'Deleted vendor: ' . $vendor['business_name']);
    redirectTo('vendors.php?success=' . urlencode('Vendor deleted successfully.'));

} catch (PDOException $e) {
    $pdo->rollBack();

    // SQLSTATE 23000 = integrity constraint violation (e.g. vendor still
    // has rentals, products, or payments referencing it).
    if ($e->getCode() === '23000') {
        redirectTo('vendors.php?error=' . urlencode(
            'This vendor has related records (shelves, products, or payments) and cannot be deleted. Deactivate them instead.'
        ));
    }

    redirectTo('vendors.php?error=' . urlencode('Something went wrong while deleting. Please try again.'));
}