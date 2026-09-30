<?php

require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

requireRole('admin');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirectTo('inventory_approvals.php');
}
if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) {
    redirectTo('inventory_approvals.php?error=' . urlencode('Your session expired. Please try again.'));
}

$transactionId = (int) ($_POST['transaction_id'] ?? 0);
$action = $_POST['action'] ?? '';

if ($transactionId <= 0 || !in_array($action, ['approve', 'reject'], true)) {
    redirectTo('inventory_approvals.php?error=' . urlencode('Invalid request.'));
}

$stmt = $pdo->prepare(
    "SELECT t.*, p.vendor_id, p.product_name
     FROM inventory_transactions t
     INNER JOIN products p ON p.product_id = t.product_id
     WHERE t.transaction_id = :id LIMIT 1"
);
$stmt->execute(['id' => $transactionId]);
$request = $stmt->fetch();

if (!$request) {
    redirectTo('inventory_approvals.php?error=' . urlencode('Request not found.'));
}
if ($request['status'] !== 'Pending') {
    redirectTo('inventory_approvals.php?error=' . urlencode('This request has already been processed.'));
}

try {
    $pdo->beginTransaction();

    if ($action === 'reject') {
        $updateTx = $pdo->prepare("UPDATE inventory_transactions SET status = 'Rejected' WHERE transaction_id = :id");
        $updateTx->execute(['id' => $transactionId]);

        $pdo->commit();

        logActivity($pdo, currentUserId(), 'Rejected ' . $request['transaction_type'] . ' request for ' . $request['product_name']);
        createNotification(
            $pdo,
            $request['vendor_id'],
            'Your ' . strtolower($request['transaction_type']) . ' request for ' . $request['product_name'] . ' was rejected.',
            'Sale'
        );

        redirectTo('inventory_approvals.php?success=' . urlencode('Request rejected.'));
    }

    // --- Approve ---
    $lockStmt = $pdo->prepare(
        "SELECT quantity, minimum_stock, selling_price FROM products WHERE product_id = :id FOR UPDATE"
    );
    $lockStmt->execute(['id' => $request['product_id']]);
    $productRow = $lockStmt->fetch();

    if (!$productRow) {
        throw new RuntimeException('Product no longer exists.');
    }

    $oldStatus = computeProductStatus((int) $productRow['quantity'], (int) $productRow['minimum_stock']);

    if ($request['transaction_type'] === 'Stock In') {
        $newQuantity = (int) $productRow['quantity'] + (int) $request['quantity'];
        $newStatus = computeProductStatus($newQuantity, (int) $productRow['minimum_stock']);

        $updateProduct = $pdo->prepare("UPDATE products SET quantity = :qty, status = :status WHERE product_id = :id");
        $updateProduct->execute(['qty' => $newQuantity, 'status' => $newStatus, 'id' => $request['product_id']]);

        $updateTx = $pdo->prepare("UPDATE inventory_transactions SET status = 'Approved' WHERE transaction_id = :id");
        $updateTx->execute(['id' => $transactionId]);

        $pdo->commit();

        logActivity($pdo, currentUserId(), 'Approved stock-in: +' . $request['quantity'] . ' units of ' . $request['product_name']);
        createNotification(
            $pdo,
            $request['vendor_id'],
            'Your stock-in request for ' . $request['product_name'] . ' (+' . $request['quantity'] . ') was approved.',
            'Sale'
        );

    } else {
        // Sale — re-check stock is still sufficient right now.
        $qtyRequested = (int) $request['quantity'];
        if ($qtyRequested > (int) $productRow['quantity']) {
            throw new RuntimeException(
                'Cannot approve — only ' . $productRow['quantity'] . ' in stock now (request was for ' . $qtyRequested . '). Reject it instead and ask the vendor to resubmit.'
            );
        }

        $newQuantity = (int) $productRow['quantity'] - $qtyRequested;
        $newStatus = computeProductStatus($newQuantity, (int) $productRow['minimum_stock']);
        $totalAmount = $qtyRequested * (float) $productRow['selling_price'];

        $updateProduct = $pdo->prepare("UPDATE products SET quantity = :qty, status = :status WHERE product_id = :id");
        $updateProduct->execute(['qty' => $newQuantity, 'status' => $newStatus, 'id' => $request['product_id']]);

        $saleStmt = $pdo->prepare(
            "INSERT INTO sales (product_id, vendor_id, quantity_sold, total_amount, sale_date)
             VALUES (:product_id, :vendor_id, :quantity_sold, :total_amount, NOW())"
        );
        $saleStmt->execute([
            'product_id'    => $request['product_id'],
            'vendor_id'     => $request['vendor_id'],
            'quantity_sold' => $qtyRequested,
            'total_amount'  => $totalAmount,
        ]);

        $updateTx = $pdo->prepare("UPDATE inventory_transactions SET status = 'Approved' WHERE transaction_id = :id");
        $updateTx->execute(['id' => $transactionId]);

        $pdo->commit();

        logActivity(
            $pdo,
            currentUserId(),
            'Approved sale: ' . $qtyRequested . ' x ' . $request['product_name'] . ' (KES ' . number_format($totalAmount, 2) . ')'
        );

        createNotification(
            $pdo,
            $request['vendor_id'],
            'Sale approved: ' . $qtyRequested . ' x ' . $request['product_name'] . ' for KES ' . number_format($totalAmount, 2) . '.',
            'Sale'
        );

        if ($newStatus !== $oldStatus && $newStatus !== 'Available') {
            createNotification(
                $pdo,
                $request['vendor_id'],
                $request['product_name'] . ' is now ' . $newStatus . ' (' . $newQuantity . ' remaining).',
                'Low Stock'
            );
        }
    }

    redirectTo('inventory_approvals.php?success=' . urlencode('Request approved.'));

} catch (RuntimeException $e) {
    $pdo->rollBack();
    redirectTo('inventory_approvals.php?error=' . urlencode($e->getMessage()));
} catch (PDOException $e) {
    $pdo->rollBack();
    redirectTo('inventory_approvals.php?error=' . urlencode('Something went wrong. Please try again.'));
}