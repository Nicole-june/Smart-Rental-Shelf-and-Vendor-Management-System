<?php


require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

requireRole('admin');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirectTo('categories.php');
}

if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) {
    redirectTo('categories.php?error=' . urlencode('Your session expired. Please try again.'));
}

$categoryId = (int) ($_POST['category_id'] ?? 0);

if ($categoryId <= 0) {
    redirectTo('categories.php?error=' . urlencode('Invalid category.'));
}

$stmt = $pdo->prepare("SELECT category_name FROM categories WHERE category_id = :id LIMIT 1");
$stmt->execute(['id' => $categoryId]);
$category = $stmt->fetch();

if (!$category) {
    redirectTo('categories.php?error=' . urlencode('Category not found.'));
}

try {
    $deleteStmt = $pdo->prepare("DELETE FROM categories WHERE category_id = :id");
    $deleteStmt->execute(['id' => $categoryId]);

    logActivity($pdo, currentUserId(), 'Deleted category: ' . $category['category_name']);
    redirectTo('categories.php?success=' . urlencode('Category deleted.'));

} catch (PDOException $e) {
    if ($e->getCode() === '23000') {
        redirectTo('categories.php?error=' . urlencode(
            'This category still has products assigned to it and cannot be deleted.'
        ));
    }
    redirectTo('categories.php?error=' . urlencode('Something went wrong while deleting.'));
}