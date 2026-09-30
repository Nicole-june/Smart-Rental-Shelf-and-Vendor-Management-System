<?php

require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

requireRole('admin');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirectTo('products.php');
}

if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) {
    redirectTo('products.php?error=' . urlencode('Your session expired. Please try again.'));
}

$productId = (int) ($_POST['product_id'] ?? 0);

if ($productId <= 0) {
    redirectTo('products.php?error=' . urlencode('Invalid product.'));
}

$stmt = $pdo->prepare("SELECT product_name FROM products WHERE product_id = :id LIMIT 1");
$stmt->execute(['id' => $productId]);
$product = $stmt->fetch();

if (!$product) {
    redirectTo('products.php?error=' . urlencode('Product not found.'));
}

try {
    $deleteStmt = $pdo->prepare("DELETE FROM products WHERE product_id = :id");
    $deleteStmt->execute(['id' => $productId]);

    logActivity($pdo, currentUserId(), 'Deleted product: ' . $product['product_name']);
    redirectTo('products.php?success=' . urlencode('Product deleted.'));

} catch (PDOException $e) {
    if ($e->getCode() === '23000') {
        redirectTo('products.php?error=' . urlencode(
            'This product has sales or stock history and cannot be deleted.'
        ));
    }
    redirectTo('products.php?error=' . urlencode('Something went wrong while deleting.'));
}