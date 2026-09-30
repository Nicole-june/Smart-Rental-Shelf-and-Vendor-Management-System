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

$shelfId   = (int) ($_POST['shelf_id'] ?? 0);
$newStatus = $_POST['new_status'] ?? '';

if (!in_array($newStatus, ['Available', 'Occupied', 'Maintenance'], true)) {
    redirectTo('shelves.php?error=' . urlencode('Invalid status.'));
}

if ($shelfId <= 0) {
    redirectTo('shelves.php?error=' . urlencode('Invalid shelf.'));
}

$stmt = $pdo->prepare("SELECT shelf_code FROM shelves WHERE shelf_id = :id LIMIT 1");
$stmt->execute(['id' => $shelfId]);
$shelf = $stmt->fetch();

if (!$shelf) {
    redirectTo('shelves.php?error=' . urlencode('Shelf not found.'));
}

$updateStmt = $pdo->prepare("UPDATE shelves SET status = :status WHERE shelf_id = :id");
$updateStmt->execute(['status' => $newStatus, 'id' => $shelfId]);

logActivity($pdo, currentUserId(), 'Changed shelf ' . $shelf['shelf_code'] . ' status to ' . $newStatus);

redirectTo('shelves.php?success=' . urlencode('Shelf ' . $shelf['shelf_code'] . ' marked as ' . $newStatus . '.'));