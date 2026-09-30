<?php


require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

requireRole('admin');

$productId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$isEdit = $productId > 0;

$errors = [];

$form = [
    'vendor_id'     => '',
    'category_id'   => '',
    'product_name'  => '',
    'buying_price'  => '',
    'selling_price' => '',
    'quantity'      => '',
    'minimum_stock' => '',
];

if ($isEdit) {
    $stmt = $pdo->prepare("SELECT * FROM products WHERE product_id = :id LIMIT 1");
    $stmt->execute(['id' => $productId]);
    $existing = $stmt->fetch();

    if (!$existing) {
        redirectTo('products.php?error=' . urlencode('Product not found.'));
    }

    $form = [
        'vendor_id'     => $existing['vendor_id'],
        'category_id'   => $existing['category_id'],
        'product_name'  => $existing['product_name'],
        'buying_price'  => $existing['buying_price'],
        'selling_price' => $existing['selling_price'],
        'quantity'      => $existing['quantity'],
        'minimum_stock' => $existing['minimum_stock'],
    ];
}


$vendors = $pdo->query(
    "SELECT v.vendor_id, v.business_name, r.shelf_id, s.shelf_code
     FROM vendors v
     INNER JOIN rentals r ON r.vendor_id = v.vendor_id AND r.rental_status = 'Active'
     INNER JOIN shelves s ON s.shelf_id = r.shelf_id
     WHERE v.status = 'Active'
     ORDER BY v.business_name ASC"
)->fetchAll();
$categories = $pdo->query("SELECT category_id, category_name FROM categories ORDER BY category_name ASC")->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Your session expired. Please try again.';
    }

    $form['vendor_id']     = (int) ($_POST['vendor_id'] ?? 0);
    $form['category_id']   = (int) ($_POST['category_id'] ?? 0);
    $form['product_name']  = cleanInput($_POST['product_name'] ?? '');
    $form['buying_price']  = cleanInput($_POST['buying_price'] ?? '');
    $form['selling_price'] = cleanInput($_POST['selling_price'] ?? '');
    $form['quantity']      = cleanInput($_POST['quantity'] ?? '');
    $form['minimum_stock'] = cleanInput($_POST['minimum_stock'] ?? '');

    if ($form['vendor_id'] <= 0) {
        $errors[] = 'Please select a vendor.';
    }
    if ($form['category_id'] <= 0) {
        $errors[] = 'Please select a category.';
    }
    if ($form['product_name'] === '') {
        $errors[] = 'Product name is required.';
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

    if (empty($errors)) {
        
        $shelfId = getVendorActiveShelfId($pdo, $form['vendor_id']);
        if ($shelfId === null) {
            $errors[] = 'The selected vendor has no active shelf rental, so a product can\'t be attached to them right now.';
        }
    }

    if (empty($errors)) {
        $status = computeProductStatus((int) $form['quantity'], (int) $form['minimum_stock']);

        try {
            if ($isEdit) {
                $stmt = $pdo->prepare(
                    "UPDATE products SET vendor_id = :vendor_id, shelf_id = :shelf_id, category_id = :category_id, product_name = :product_name,
                            buying_price = :buying_price, selling_price = :selling_price, quantity = :quantity,
                            minimum_stock = :minimum_stock, status = :status
                     WHERE product_id = :id"
                );
                $stmt->execute([
                    'vendor_id'     => $form['vendor_id'],
                    'shelf_id'      => $shelfId,
                    'category_id'   => $form['category_id'],
                    'product_name'  => $form['product_name'],
                    'buying_price'  => $form['buying_price'],
                    'selling_price' => $form['selling_price'],
                    'quantity'      => $form['quantity'],
                    'minimum_stock' => $form['minimum_stock'],
                    'status'        => $status,
                    'id'            => $productId,
                ]);
                logActivity($pdo, currentUserId(), 'Updated product: ' . $form['product_name']);

                if ($status !== $existing['status'] && $status !== 'Available') {
                    createNotification(
                        $pdo,
                        $form['vendor_id'],
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
                    'vendor_id'     => $form['vendor_id'],
                    'shelf_id'      => $shelfId,
                    'category_id'   => $form['category_id'],
                    'product_name'  => $form['product_name'],
                    'buying_price'  => $form['buying_price'],
                    'selling_price' => $form['selling_price'],
                    'quantity'      => $form['quantity'],
                    'minimum_stock' => $form['minimum_stock'],
                    'status'        => $status,
                ]);
                $newProductId = (int) $pdo->lastInsertId();

                // If the product starts with stock on hand, log it as an
                // initial Stock In transaction so inventory history is complete.
                if ((int) $form['quantity'] > 0) {
                    $txStmt = $pdo->prepare(
                        "INSERT INTO inventory_transactions (product_id, transaction_type, quantity, transaction_date, remarks)
                         VALUES (:product_id, 'Stock In', :quantity, NOW(), 'Initial stock on product creation')"
                    );
                    $txStmt->execute(['product_id' => $newProductId, 'quantity' => $form['quantity']]);
                }

                logActivity($pdo, currentUserId(), 'Added product: ' . $form['product_name']);
                redirectTo('products.php?success=' . urlencode('Product added.'));
            }
        } catch (PDOException $e) {
            $errors[] = 'Something went wrong while saving. Please try again.';
        }
    }
}

$pageTitle    = $isEdit ? 'Edit Product' : 'Add Product';
$activePage   = 'inventory';
$inventoryTab = 'products';
require_once __DIR__ . '/../includes/header.php';
?>

    <div class="form-card">
        <a href="products.php" class="back-link">&larr; Back to Products</a>

        <?php if (!empty($errors)): ?>
            <div class="alert alert-error">
                <ul><?php foreach ($errors as $error): ?><li><?= e($error) ?></li><?php endforeach; ?></ul>
            </div>
        <?php endif; ?>

        <?php if (empty($vendors)): ?>
            <p class="chart-empty">No vendors currently have an active shelf rental. A vendor needs a shelf (Module 6) before products can be added for them.</p>
        <?php else: ?>
        <form method="POST" action="product_form.php<?= $isEdit ? '?id=' . $productId : '' ?>" class="stacked-form" novalidate>
            <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">

            <div class="form-row">
                <div class="form-group">
                    <label for="vendor_id">Vendor</label>
                    <select id="vendor_id" name="vendor_id" required>
                        <option value="">Select vendor...</option>
                        <?php foreach ($vendors as $v): ?>
                            <option value="<?= (int) $v['vendor_id'] ?>" <?= (int) $form['vendor_id'] === (int) $v['vendor_id'] ? 'selected' : '' ?>><?= e($v['business_name']) ?> (Shelf <?= e($v['shelf_code']) ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
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
                <div class="form-group form-group-full">
                    <label for="product_name">Product Name</label>
                    <input type="text" id="product_name" name="product_name" value="<?= e($form['product_name']) ?>" required>
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
                    <label for="quantity"><?= $isEdit ? 'Quantity (adjust here for corrections)' : 'Starting Quantity' ?></label>
                    <input type="number" min="0" step="1" id="quantity" name="quantity" value="<?= e((string) $form['quantity']) ?>" required>
                    <?php if ($isEdit): ?>
                        <span class="text-muted">For normal stock changes, use Stock In or Record Sale instead — this field is for manual corrections only.</span>
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