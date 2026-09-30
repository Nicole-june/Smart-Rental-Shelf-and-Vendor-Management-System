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

// products.shelf_id is a required foreign key — derive it from this
// vendor's current active rental. No active rental means no shelf to
// attach a product to, so we block adding products until they have one.
$activeShelfId = getVendorActiveShelfId($pdo, $vendorId);

$productId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$isEdit = $productId > 0;

$errors = [];
$form = [
    'category_id'   => '',
    'product_name'  => '',
    'buying_price'  => '',
    'selling_price' => '',
    'quantity'      => '',
    'minimum_stock' => '',
];

if ($isEdit) {
    $stmt = $pdo->prepare("SELECT * FROM products WHERE product_id = :id AND vendor_id = :vendor_id LIMIT 1");
    $stmt->execute(['id' => $productId, 'vendor_id' => $vendorId]);
    $existing = $stmt->fetch();

    if (!$existing) {
        redirectTo('products.php?error=' . urlencode('Product not found.'));
    }

    $form = [
        'category_id'   => $existing['category_id'],
        'product_name'  => $existing['product_name'],
        'buying_price'  => $existing['buying_price'],
        'selling_price' => $existing['selling_price'],
        'quantity'      => $existing['quantity'],
        'minimum_stock' => $existing['minimum_stock'],
    ];
}

$categories = $pdo->query("SELECT category_id, category_name FROM categories ORDER BY category_name ASC")->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Your session expired. Please try again.';
    }

    $form['category_id']   = (int) ($_POST['category_id'] ?? 0);
    $form['product_name']  = cleanInput($_POST['product_name'] ?? '');
    $form['buying_price']  = cleanInput($_POST['buying_price'] ?? '');
    $form['selling_price'] = cleanInput($_POST['selling_price'] ?? '');
    $form['quantity']      = cleanInput($_POST['quantity'] ?? '');
    $form['minimum_stock'] = cleanInput($_POST['minimum_stock'] ?? '');

    if ($form['product_name'] === '') {
        $errors[] = 'Product name is required.';
    }
    if ($form['category_id'] <= 0) {
        $errors[] = 'Please select a category.';
    }
    if (!is_numeric($form['buying_price']) || (float) $form['buying_price'] < 0) {
        $errors[] = 'Buying price must be a non-negative number.';
    }
    if (!is_numeric($form['selling_price']) || (float) $form['selling_price'] < 0) {
        $errors[] = 'Selling price must be a non-negative number.';
    }
    if (!ctype_digit((string) $form['quantity'])) {
        $errors[] = 'Quantity must be a whole number.';
    }
    if (!ctype_digit((string) $form['minimum_stock'])) {
        $errors[] = 'Minimum stock must be a whole number.';
    }

    if (empty($errors) && !$isEdit && $activeShelfId === null) {
        $errors[] = 'You need an active shelf rental before you can add products. Contact the admin to get one assigned.';
    }

    if (empty($errors)) {
        $status = computeProductStatus((int) $form['quantity'], (int) $form['minimum_stock']);

        try {
            if ($isEdit) {
                // shelf_id is intentionally NOT updated here — a product
                // stays tied to the shelf it was created for, even if the
                // vendor's active rental later changes.
                $stmt = $pdo->prepare(
                    "UPDATE products SET category_id = :category_id, product_name = :product_name,
                            buying_price = :buying_price, selling_price = :selling_price, quantity = :quantity,
                            minimum_stock = :minimum_stock, status = :status
                     WHERE product_id = :id AND vendor_id = :vendor_id"
                );
                $stmt->execute([
                    'category_id'   => $form['category_id'],
                    'product_name'  => $form['product_name'],
                    'buying_price'  => $form['buying_price'],
                    'selling_price' => $form['selling_price'],
                    'quantity'      => $form['quantity'],
                    'minimum_stock' => $form['minimum_stock'],
                    'status'        => $status,
                    'id'            => $productId,
                    'vendor_id'     => $vendorId,
                ]);
                logActivity($pdo, currentUserId(), 'Updated own product: ' . $form['product_name']);

                if ($status !== $existing['status'] && $status !== 'Available') {
                    createNotification(
                        $pdo,
                        $vendorId,
                        $form['product_name'] . ' is now ' . $status . ' (' . (int) $form['quantity'] . ' remaining).',
                        'Low Stock'
                    );
                }

                redirectTo('products.php?success=' . urlencode('Product updated.'));
            } else {
                $stmt = $pdo->prepare(
                    "INSERT INTO products (vendor_id, shelf_id, category_id, product_name, buying_price, selling_price, quantity, minimum_stock, status, created_at)
                     VALUES (:vendor_id, :shelf_id, :category_id, :product_name, :buying_price, :selling_price, :quantity, :minimum_stock, :status, NOW())"
                );
                $stmt->execute([
                    'vendor_id'     => $vendorId,
                    'shelf_id'      => $activeShelfId,
                    'category_id'   => $form['category_id'],
                    'product_name'  => $form['product_name'],
                    'buying_price'  => $form['buying_price'],
                    'selling_price' => $form['selling_price'],
                    'quantity'      => $form['quantity'],
                    'minimum_stock' => $form['minimum_stock'],
                    'status'        => $status,
                ]);
                $newProductId = (int) $pdo->lastInsertId();

                if ((int) $form['quantity'] > 0) {
                    $txStmt = $pdo->prepare(
                        "INSERT INTO inventory_transactions (product_id, transaction_type, quantity, transaction_date, remarks)
                         VALUES (:product_id, 'Stock In', :quantity, NOW(), 'Initial stock on product creation')"
                    );
                    $txStmt->execute(['product_id' => $newProductId, 'quantity' => $form['quantity']]);
                }

                logActivity($pdo, currentUserId(), 'Added own product: ' . $form['product_name']);
                redirectTo('products.php?success=' . urlencode('Product added.'));
            }
        } catch (PDOException $e) {
            $errors[] = 'Something went wrong while saving. Please try again.';
        }
    }
}

$pageTitle  = $isEdit ? 'Edit Product' : 'Add Product';
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

        <?php if (!$isEdit && $activeShelfId === null): ?>
            <p class="chart-empty">You need an active shelf rental before you can add products. Contact the admin to get one assigned.</p>
        <?php else: ?>
        <form method="POST" action="product_form.php<?= $isEdit ? '?id=' . $productId : '' ?>" class="stacked-form" novalidate>
            <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">

            <div class="form-row">
                <div class="form-group form-group-full">
                    <label for="product_name">Product Name</label>
                    <input type="text" id="product_name" name="product_name" value="<?= e($form['product_name']) ?>" required>
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label for="category_id">Category</label>
                    <select id="category_id" name="category_id" required>
                        <option value="">Select a category...</option>
                        <?php foreach ($categories as $c): ?>
                            <option value="<?= (int) $c['category_id'] ?>" <?= (int) $form['category_id'] === (int) $c['category_id'] ? 'selected' : '' ?>><?= e($c['category_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label for="buying_price">Buying Price (KES)</label>
                    <input type="number" step="0.01" min="0" id="buying_price" name="buying_price" value="<?= e((string) $form['buying_price']) ?>" required>
                </div>
                <div class="form-group">
                    <label for="selling_price">Selling Price (KES)</label>
                    <input type="number" step="0.01" min="0" id="selling_price" name="selling_price" value="<?= e((string) $form['selling_price']) ?>" required>
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label for="quantity"><?= $isEdit ? 'Quantity (manual correction only)' : 'Starting Quantity' ?></label>
                    <input type="number" min="0" step="1" id="quantity" name="quantity" value="<?= e((string) $form['quantity']) ?>" required>
                    <?php if ($isEdit): ?>
                        <span class="text-muted">For normal stock changes, use Stock In or Sell instead.</span>
                    <?php endif; ?>
                </div>
                <div class="form-group">
                    <label for="minimum_stock">Minimum Stock (Low Stock threshold)</label>
                    <input type="number" min="0" step="1" id="minimum_stock" name="minimum_stock" value="<?= e((string) $form['minimum_stock']) ?>" required>
                </div>
            </div>

            <button type="submit" class="btn btn-primary"><?= $isEdit ? 'Save Changes' : 'Add Product' ?></button>
        </form>
        <?php endif; ?>
    </div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>