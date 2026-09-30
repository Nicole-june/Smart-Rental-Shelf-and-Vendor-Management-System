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

$productsStmt = $pdo->prepare("SELECT product_id, product_name, quantity FROM products WHERE vendor_id = :vendor_id ORDER BY product_name ASC");
$productsStmt->execute(['vendor_id' => $vendorId]);
$products = $productsStmt->fetchAll();

$form = ['product_id' => $preselectedProduct, 'quantity' => '', 'remarks' => ''];

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
        // Confirm ownership before accepting the request.
        $ownStmt = $pdo->prepare("SELECT product_id FROM products WHERE product_id = :id AND vendor_id = :vendor_id LIMIT 1");
        $ownStmt->execute(['id' => $form['product_id'], 'vendor_id' => $vendorId]);
        if (!$ownStmt->fetch()) {
            $errors[] = 'Product not found.';
        }
    }

    if (empty($errors)) {
        try {
            submitStockInRequest(
                $pdo,
                $form['product_id'],
                (int) $form['quantity'],
                $form['remarks'] !== '' ? $form['remarks'] : null,
                currentUserId()
            );

            logActivity($pdo, currentUserId(), 'Submitted stock-in request: +' . $form['quantity'] . ' units (awaiting admin approval)');

            redirectTo('products.php?success=' . urlencode('Stock-in request submitted. It will apply once the admin approves it.'));

        } catch (PDOException $e) {
            $errors[] = 'Something went wrong while submitting. Please try again.';
        }
    }
}

$pageTitle  = 'Stock In';
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
            <p class="chart-empty">You have no products yet. Add one first.</p>
        <?php else: ?>
            <div class="shelf-code-display">
                Submitting this sends a request to the admin — your stock count won't change until it's approved.
            </div>

            <form method="POST" action="stock_in.php" class="stacked-form" novalidate>
                <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">

                <div class="form-row">
                    <div class="form-group form-group-full">
                        <label for="product_id">Product</label>
                        <select id="product_id" name="product_id" required>
                            <option value="">Select a product...</option>
                            <?php foreach ($products as $p): ?>
                                <option value="<?= (int) $p['product_id'] ?>" <?= $form['product_id'] === (int) $p['product_id'] ? 'selected' : '' ?>>
                                    <?= e($p['product_name']) ?> — currently <?= (int) $p['quantity'] ?> in stock
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

                <button type="submit" class="btn btn-primary">Submit Stock-In Request</button>
            </form>
        <?php endif; ?>
    </div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>