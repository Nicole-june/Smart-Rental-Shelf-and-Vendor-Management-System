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

$errors = [];
$preselectedProduct = (int) ($_GET['product'] ?? 0);

$productsStmt = $pdo->prepare(
    "SELECT product_id, product_name, quantity, selling_price
     FROM products WHERE vendor_id = :vendor_id AND quantity > 0
     ORDER BY product_name ASC"
);
$productsStmt->execute(['vendor_id' => $vendorId]);
$products = $productsStmt->fetchAll();

$form = ['product_id' => $preselectedProduct, 'quantity_sold' => ''];

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
        $ownStmt = $pdo->prepare(
            "SELECT quantity FROM products WHERE product_id = :id AND vendor_id = :vendor_id LIMIT 1"
        );
        $ownStmt->execute(['id' => $form['product_id'], 'vendor_id' => $vendorId]);
        $productRow = $ownStmt->fetch();

        if (!$productRow) {
            $errors[] = 'Product not found.';
        } elseif ((int) $form['quantity_sold'] > (int) $productRow['quantity']) {
            // Soft check against current stock. The admin approval step
            // re-checks this again at the time of approval, since stock
            // could shift if other requests are pending too.
            $errors[] = 'You only have ' . $productRow['quantity'] . ' in stock right now.';
        }
    }

    if (empty($errors)) {
        try {
            submitSaleRequest($pdo, $form['product_id'], (int) $form['quantity_sold'], currentUserId());

            logActivity($pdo, currentUserId(), 'Submitted sale request: ' . $form['quantity_sold'] . ' units (awaiting admin approval)');

            redirectTo('products.php?success=' . urlencode('Sale request submitted. Stock will update once the admin approves it.'));

        } catch (PDOException $e) {
            $errors[] = 'Something went wrong while submitting. Please try again.';
        }
    }
}

$pageTitle  = 'Record Sale';
$activePage = 'products';
require_once __DIR__ . '/../includes/vendor_header.php';
?>

    <div class="form-card">
        <a href="products.php" class="back-link">&larr; Back to My Products</a>

        <?php if (!empty($errors)): ?>
            <div class="alert alert-error">
                <ul><?php foreach ($errors as $error): ?><li><?= e($error) ?></li><?php endforeach; ?></ul>
            </div>
        <?php endif; ?>

        <?php if (empty($products)): ?>
            <p class="chart-empty">None of your products currently have stock to sell.</p>
        <?php else: ?>
            <div class="shelf-code-display">
                Submitting this sends a request to the admin — your stock count and the sales record won't update until it's approved.
            </div>

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
                                    <?= e($p['product_name']) ?> — <?= (int) $p['quantity'] ?> in stock @ KES <?= number_format((float) $p['selling_price'], 2) ?>
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

                <p class="text-muted">Total amount will be calculated automatically as Quantity × Selling Price when the admin approves.</p>

                <button type="submit" class="btn btn-primary">Submit Sale Request</button>
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