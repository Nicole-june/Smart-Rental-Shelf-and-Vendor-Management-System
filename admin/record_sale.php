<?php

require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

requireRole('admin');

$errors = [];
$preselectedProduct = (int) ($_GET['product'] ?? 0);

// Only products with stock on hand are worth showing here.
$products = $pdo->query(
    "SELECT p.product_id, p.product_name, p.quantity, p.selling_price, p.vendor_id, v.business_name
     FROM products p INNER JOIN vendors v ON v.vendor_id = p.vendor_id
     WHERE p.quantity > 0
     ORDER BY p.product_name ASC"
)->fetchAll();

$form = [
    'product_id'    => $preselectedProduct,
    'quantity_sold' => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Your session expired. Please try again.';
    }

    $form['product_id']    = (int) ($_POST['product_id'] ?? 0);
    $form['quantity_sold'] = cleanInput($_POST['quantity_sold'] ?? '');

    if ($form['product_id'] <= 0) {
        $errors[] = 'Please select a product.';
    }
    if (!ctype_digit((string) $form['quantity_sold']) || (int) $form['quantity_sold'] <= 0) {
        $errors[] = 'Quantity sold must be a whole number greater than zero.';
    }

    if (empty($errors)) {
        try {
            $pdo->beginTransaction();

            $lockStmt = $pdo->prepare(
                "SELECT quantity, minimum_stock, product_name, selling_price, vendor_id
                 FROM products WHERE product_id = :id FOR UPDATE"
            );
            $lockStmt->execute(['id' => $form['product_id']]);
            $productRow = $lockStmt->fetch();

            if (!$productRow) {
                throw new RuntimeException('Product not found.');
            }

            $qtySold = (int) $form['quantity_sold'];

            if ($qtySold > (int) $productRow['quantity']) {
                throw new RuntimeException(
                    'Cannot sell ' . $qtySold . ' units — only ' . $productRow['quantity'] . ' in stock.'
                );
            }

            $newQuantity = (int) $productRow['quantity'] - $qtySold;
            $newStatus = computeProductStatus($newQuantity, (int) $productRow['minimum_stock']);
            $totalAmount = $qtySold * (float) $productRow['selling_price'];

            // 1. Deduct stock automatically
            $updateStmt = $pdo->prepare("UPDATE products SET quantity = :qty, status = :status WHERE product_id = :id");
            $updateStmt->execute(['qty' => $newQuantity, 'status' => $newStatus, 'id' => $form['product_id']]);

            // 2. Record the sale
            $saleStmt = $pdo->prepare(
                "INSERT INTO sales (product_id, vendor_id, quantity_sold, total_amount, sale_date)
                 VALUES (:product_id, :vendor_id, :quantity_sold, :total_amount, NOW())"
            );
            $saleStmt->execute([
                'product_id'    => $form['product_id'],
                'vendor_id'     => $productRow['vendor_id'],
                'quantity_sold' => $qtySold,
                'total_amount'  => $totalAmount,
            ]);

            // 3. Log the inventory transaction
            $txStmt = $pdo->prepare(
                "INSERT INTO inventory_transactions (product_id, transaction_type, quantity, transaction_date, remarks)
                 VALUES (:product_id, 'Sale', :quantity, NOW(), 'Sale recorded by admin')"
            );
            $txStmt->execute(['product_id' => $form['product_id'], 'quantity' => $qtySold]);

            $pdo->commit();

            logActivity(
                $pdo,
                currentUserId(),
                'Sale recorded: ' . $qtySold . ' x ' . $productRow['product_name'] . ' (KES ' . number_format($totalAmount, 2) . ')'
            );

            createNotification(
                $pdo,
                $productRow['vendor_id'],
                'Sale completed: ' . $qtySold . ' x ' . $productRow['product_name'] . ' for KES ' . number_format($totalAmount, 2) . '.',
                'Sale'
            );

            $oldStatus = computeProductStatus((int) $productRow['quantity'], (int) $productRow['minimum_stock']);
            if ($newStatus !== $oldStatus && $newStatus !== 'Available') {
                createNotification(
                    $pdo,
                    $productRow['vendor_id'],
                    $productRow['product_name'] . ' is now ' . $newStatus . ' (' . $newQuantity . ' remaining).',
                    'Low Stock'
                );
            }

            redirectTo('products.php?success=' . urlencode('Sale recorded successfully.'));

        } catch (RuntimeException $e) {
            $pdo->rollBack();
            $errors[] = $e->getMessage();
        } catch (PDOException $e) {
            $pdo->rollBack();
            $errors[] = 'Something went wrong while saving. Please try again.';
        }
    }
}

$pageTitle    = 'Record Sale';
$activePage   = 'inventory';
$inventoryTab = 'record_sale';
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
            <p class="chart-empty">No products currently have stock to sell.</p>
        <?php else: ?>
            <form method="POST" action="record_sale.php" class="stacked-form" novalidate>
                <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">

                <div class="form-row">
                    <div class="form-group form-group-full">
                        <label for="product_id">Product</label>
                        <select id="product_id" name="product_id" required onchange="showSellingPrice(this)">
                            <option value="">Select a product...</option>
                            <?php foreach ($products as $p): ?>
                                <option
                                    value="<?= (int) $p['product_id'] ?>"
                                    data-price="<?= e((string) $p['selling_price']) ?>"
                                    data-stock="<?= (int) $p['quantity'] ?>"
                                    <?= $form['product_id'] === (int) $p['product_id'] ? 'selected' : '' ?>
                                >
                                    <?= e($p['product_name']) ?> (<?= e($p['business_name']) ?>) — <?= (int) $p['quantity'] ?> in stock @ KES <?= number_format((float) $p['selling_price'], 2) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <span class="text-muted" id="priceHint"></span>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="quantity_sold">Quantity Sold</label>
                        <input type="number" min="1" step="1" id="quantity_sold" name="quantity_sold" value="<?= e((string) $form['quantity_sold']) ?>" required>
                    </div>
                </div>

                <p class="text-muted">Total amount is calculated automatically as Quantity × Selling Price.</p>

                <button type="submit" class="btn btn-primary">Record Sale</button>
            </form>

            <script>
                function showSellingPrice(selectEl) {
                    var option = selectEl.options[selectEl.selectedIndex];
                    var price = option.getAttribute('data-price');
                    var stock = option.getAttribute('data-stock');
                    var hint = document.getElementById('priceHint');
                    if (price && stock) {
                        hint.textContent = 'Selling price: KES ' + parseFloat(price).toFixed(2) + ' | In stock: ' + stock;
                    } else {
                        hint.textContent = '';
                    }
                }
            </script>
        <?php endif; ?>
    </div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>