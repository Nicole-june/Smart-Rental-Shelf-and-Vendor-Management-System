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
    redirectTo('dashboard.php');
}
if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) {
    redirectTo('dashboard.php');
}

if (($_POST['mark_all'] ?? '') === '1') {
    $stmt = $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE vendor_id = :vendor_id AND is_read = 0");
    $stmt->execute(['vendor_id' => $vendorId]);
}

// Send them back to whichever page they clicked the bell from.
$referer = $_SERVER['HTTP_REFERER'] ?? 'dashboard.php';
redirectTo($referer);