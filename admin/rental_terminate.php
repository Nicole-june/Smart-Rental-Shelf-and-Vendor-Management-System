<?php

require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

requireRole('admin');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirectTo('rentals.php');
}

if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) {
    redirectTo('rentals.php?error=' . urlencode('Your session expired. Please try again.'));
}

$rentalId = (int) ($_POST['rental_id'] ?? 0);

if ($rentalId <= 0) {
    redirectTo('rentals.php?error=' . urlencode('Invalid rental.'));
}

$stmt = $pdo->prepare(
    "SELECT r.shelf_id, r.rental_status, s.shelf_code, v.business_name
     FROM rentals r
     INNER JOIN shelves s ON s.shelf_id = r.shelf_id
     INNER JOIN vendors v ON v.vendor_id = r.vendor_id
     WHERE r.rental_id = :id LIMIT 1"
);
$stmt->execute(['id' => $rentalId]);
$rental = $stmt->fetch();

if (!$rental) {
    redirectTo('rentals.php?error=' . urlencode('Rental not found.'));
}

if ($rental['rental_status'] !== 'Active') {
    redirectTo('rentals.php?error=' . urlencode('Only active rentals can be terminated.'));
}

try {
    $pdo->beginTransaction();

    $updateRental = $pdo->prepare("UPDATE rentals SET rental_status = 'Terminated' WHERE rental_id = :id");
    $updateRental->execute(['id' => $rentalId]);

    $updateShelf = $pdo->prepare(
        "UPDATE shelves SET status = 'Available' WHERE shelf_id = :id AND status != 'Maintenance'"
    );
    $updateShelf->execute(['id' => $rental['shelf_id']]);

    $pdo->commit();

    logActivity(
        $pdo,
        currentUserId(),
        'Terminated rental: ' . $rental['shelf_code'] . ' (' . $rental['business_name'] . ')'
    );

    redirectTo('rentals.php?success=' . urlencode('Rental terminated. Shelf is now available.'));

} catch (PDOException $e) {
    $pdo->rollBack();
    redirectTo('rentals.php?error=' . urlencode('Something went wrong. Please try again.'));
}