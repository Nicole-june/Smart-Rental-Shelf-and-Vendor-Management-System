<?php

require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

requireRole('admin');

$errors = [];
$preselectedProduct = (int) ($_GET['product'] ?? 0);

$products = $pdo->query(
    "SELECT p.product_id, p.product_name, p.quantity, v.business_name
     FROM products p INNER JOIN vendors v ON v.vendor_id = p.vendor_id
     ORDER BY p.product_name ASC"
)->fetchAll();

$form = [
    'product_id' => $preselectedProduct,
    'quantity'   => '',
    'remarks'    => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Your session expired. Please try again.';
    }

    $form['product_id'] = (int) ($_POST['product_id'] ?? 0);
    $form['quantity']   = cleanInput($_POST['quantity'] ?? '');
    $form['remarks']    = cleanInput($_POST['remarks'] ?? '');

    if ($form['product_id'] <= 0) {
        $errors[] = 'Please select a product.';
    }
    if (!ctype_digit((string) $form['quantity']) || (int) $form['quantity'] <= 0) {
        $errors[] = 'Quantity must be a whole number greater than zero.';
    }

    if (empty($errors)) {
        try {
            $pdo->beginTransaction();

            $lockStmt = $pdo->prepare("SELECT quantity, minimum_stock, product_name FROM products WHERE product_id = :id FOR UPDATE");
            $lockStmt->execute(['id' => $form['product_id']]);
            $productRow = $lockStmt->fetch();

            if (!$productRow) {
                throw new RuntimeException('Product not found.');
            }

            $newQuantity = (int) $productRow['quantity'] + (int) $form['quantity'];
            $newStatus = computeProductStatus($newQuantity, (int) $productRow['minimum_stock']);

            $updateStmt = $pdo->prepare("UPDATE products SET quantity = :qty, status = :status WHERE product_id = :id");
            $updateStmt->execute(['qty' => $newQuantity, 'status' => $newStatus, 'id' => $form['product_id']]);

            $txStmt = $pdo->prepare(
                "INSERT INTO inventory_transactions (product_id, transaction_type, quantity, transaction_date, remarks)
                 VALUES (:product_id, 'Stock In', :quantity, NOW(), :remarks)"
            );
            $txStmt->execute([
                'product_id' => $form['product_id'],
                'quantity'   => $form['quantity'],
                'remarks'    => $form['remarks'] !== '' ? $form['remarks'] : null,
            ]);

            $pdo->commit();

            logActivity(
                $pdo,
                currentUserId(),
                'Stock In: +' . $form['quantity'] . ' units of ' . $productRow['product_name']
            );

            redirectTo('products.php?success=' . urlencode('Stock added successfully.'));

        } catch (RuntimeException $e) {
            $pdo->rollBack();
            $errors[] = $e->getMessage();
        } catch (PDOException $e) {
            $pdo->rollBack();
            $errors[] = 'Something went wrong while saving. Please try again.';
        }
    }
}

$pageTitle    = 'Stock In';
$activePage   = 'inventory';
$inventoryTab = 'stock_in';
require_once __DIR__ . '/../includes/header.php';
require __DIR__ . '/../includes/inventory_tabs.php';
?>

    <div class="form-card">
        <?php if (!empty($errors)): ?>
            <div class="alert alert-error">
                <ul><?php foreach ($errors as $error): ?><li><?= e($error) ?></li><?php endforeach; ?></ul>
            </div>
        <?php endif; ?>

        <?php if (empty($products)): ?>
            <p class="chart-empty">No products yet. Add a product first.</p>
        <?php else: ?>
            <form method="POST" action="stock_in.php" class="stacked-form" novalidate>
                <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">

                <div class="form-row">
                    <div class="form-group form-group-full">
                        <label for="product_id">Product</label>
                        <select id="product_id" name="product_id" required>
                            <option value="">Select a product...</option>
                            <?php foreach ($products as $p): ?>
                                <option value="<?= (int) $p['product_id'] ?>" <?= $form['product_id'] === (int) $p['product_id'] ? 'selected' : '' ?>>
                                    <?= e($p['product_name']) ?> (<?= e($p['business_name']) ?>) — currently <?= (int) $p['quantity'] ?> in stock
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="quantity">Quantity Received</label>
                        <input type="number" min="1" step="1" id="quantity" name="quantity" value="<?= e((string) $form['quantity']) ?>" required>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group form-group-full">
                        <label for="remarks">Remarks (optional)</label>
                        <textarea id="remarks" name="remarks" rows="2"><?= e($form['remarks']) ?></textarea>
                    </div>
                </div>

                <button type="submit" class="btn btn-primary">Record Stock In</button>
            </form>
        <?php endif; ?>
    </div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>