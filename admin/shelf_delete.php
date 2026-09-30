<?php

require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

requireRole('admin');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirectTo('shelves.php');
}

if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) {
    redirectTo('shelves.php?error=' . urlencode('Your session expired. Please try again.'));
}

$shelfId = (int) ($_POST['shelf_id'] ?? 0);

if ($shelfId <= 0) {
    redirectTo('shelves.php?error=' . urlencode('Invalid shelf.'));
}

$stmt = $pdo->prepare("SELECT shelf_code FROM shelves WHERE shelf_id = :id LIMIT 1");
$stmt->execute(['id' => $shelfId]);
$shelf = $stmt->fetch();

if (!$shelf) {
    redirectTo('shelves.php?error=' . urlencode('Shelf not found.'));
}

try {
    $deleteStmt = $pdo->prepare("DELETE FROM shelves WHERE shelf_id = :id");
    $deleteStmt->execute(['id' => $shelfId]);

    logActivity($pdo, currentUserId(), 'Deleted shelf: ' . $shelf['shelf_code']);
    redirectTo('shelves.php?success=' . urlencode('Shelf deleted successfully.'));

} catch (PDOException $e) {
    if ($e->getCode() === '23000') {
        redirectTo('shelves.php?error=' . urlencode(
            'This shelf has rental history and cannot be deleted. Set it to Maintenance instead.'
        ));
    }
    redirectTo('shelves.php?error=' . urlencode('Something went wrong while deleting. Please try again.'));
}