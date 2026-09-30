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

$vendorId  = (int) ($_POST['vendor_id'] ?? 0);
$newStatus = ($_POST['new_status'] ?? '') === 'Active' ? 'Active' : 'Inactive';

if ($vendorId <= 0) {
    redirectTo('vendors.php?error=' . urlencode('Invalid vendor.'));
}

$stmt = $pdo->prepare("SELECT business_name FROM vendors WHERE vendor_id = :id LIMIT 1");
$stmt->execute(['id' => $vendorId]);
$vendor = $stmt->fetch();

if (!$vendor) {
    redirectTo('vendors.php?error=' . urlencode('Vendor not found.'));
}

$updateStmt = $pdo->prepare("UPDATE vendors SET status = :status WHERE vendor_id = :id");
$updateStmt->execute(['status' => $newStatus, 'id' => $vendorId]);

logActivity(
    $pdo,
    currentUserId(),
    ($newStatus === 'Active' ? 'Activated' : 'Deactivated') . ' vendor: ' . $vendor['business_name']
);

redirectTo('vendors.php?success=' . urlencode('Vendor marked as ' . $newStatus . '.'));