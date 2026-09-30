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

$search = cleanInput($_GET['q'] ?? '');
$statusFilter = $_GET['status'] ?? 'All';
if (!in_array($statusFilter, ['All', 'Available', 'Low Stock', 'Out of Stock'], true)) {
    $statusFilter = 'All';
}

$flashSuccess = $_GET['success'] ?? '';
$flashError   = $_GET['error'] ?? '';

$sql = "SELECT p.*, c.category_name
        FROM products p
        LEFT JOIN categories c ON c.category_id = p.category_id
        WHERE p.vendor_id = :vendor_id";
$params = ['vendor_id' => $vendorId];

if ($search !== '') {
    $sql .= " AND p.product_name LIKE :search";
    $params['search'] = '%' . $search . '%';
}
if ($statusFilter !== 'All') {
    $sql .= " AND p.status = :status";
    $params['status'] = $statusFilter;
}
$sql .= " ORDER BY p.product_name ASC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$products = $stmt->fetchAll();

function vProductBadge(string $status): string
{
    return match ($status) {
        'Available'    => 'badge-success',
        'Low Stock'    => 'badge-warning',
        'Out of Stock' => 'badge-danger',
        default        => 'badge-muted',
    };
}

$pageTitle  = 'My Products';
$activePage = 'products';
require_once __DIR__ . '/../includes/vendor_header.php';
?>

    <?php if ($flashSuccess !== ''): ?>
        <div class="alert alert-success"><?= e($flashSuccess) ?></div>
    <?php endif; ?>
    <?php if ($flashError !== ''): ?>
        <div class="alert alert-error"><ul><li><?= e($flashError) ?></li></ul></div>
    <?php endif; ?>

    <div class="page-toolbar">
        <form method="GET" class="search-form">
            <input type="text" name="q" value="<?= e($search) ?>" placeholder="Search my products..." class="search-input">
            <select name="status" class="status-select">
                <option value="All" <?= $statusFilter === 'All' ? 'selected' : '' ?>>All Statuses</option>
                <option value="Available" <?= $statusFilter === 'Available' ? 'selected' : '' ?>>Available</option>
                <option value="Low Stock" <?= $statusFilter === 'Low Stock' ? 'selected' : '' ?>>Low Stock</option>
                <option value="Out of Stock" <?= $statusFilter === 'Out of Stock' ? 'selected' : '' ?>>Out of Stock</option>
            </select>
            <button type="submit" class="btn btn-secondary">Filter</button>
            <?php if ($search !== '' || $statusFilter !== 'All'): ?>
                <a href="products.php" class="btn btn-link">Clear</a>
            <?php endif; ?>
        </form>
        <div style="display:flex; gap:10px;">
            <a href="request_status.php" class="btn btn-secondary">My Requests</a>
            <a href="product_form.php" class="btn btn-primary">+ Add Product</a>
        </div>
    </div>

    <div class="table-card">
        <?php if (empty($products)): ?>
            <p class="chart-empty">You haven't added any products yet.</p>
        <?php else: ?>
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Product</th>
                        <th>Category</th>
                        <th>Qty</th>
                        <th>Min. Stock</th>
                        <th>Selling Price</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($products as $p): ?>
                        <tr>
                            <td><?= e($p['product_name']) ?></td>
                            <td><?= e($p['category_name'] ?? '—') ?></td>
                            <td><?= (int) $p['quantity'] ?></td>
                            <td><?= (int) $p['minimum_stock'] ?></td>
                            <td>KES <?= number_format((float) $p['selling_price'], 2) ?></td>
                            <td><span class="badge <?= vProductBadge($p['status']) ?>"><?= e($p['status']) ?></span></td>
                            <td class="actions-cell">
                                <a href="product_form.php?id=<?= (int) $p['product_id'] ?>" class="action-link">Edit</a>
                                <a href="stock_in.php?product=<?= (int) $p['product_id'] ?>" class="action-link">Stock In</a>
                                <a href="record_sale.php?product=<?= (int) $p['product_id'] ?>" class="action-link">Sell</a>
                                <form method="POST" action="product_delete.php" class="inline-form"
                                      onsubmit="return confirm('Delete this product permanently?');">
                                    <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                                    <input type="hidden" name="product_id" value="<?= (int) $p['product_id'] ?>">
                                    <button type="submit" class="action-link action-link-danger">Delete</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>