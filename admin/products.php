<?php

require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

requireRole('admin');

$search = cleanInput($_GET['q'] ?? '');
$vendorFilter = (int) ($_GET['vendor'] ?? 0);
$categoryFilter = (int) ($_GET['category'] ?? 0);
$statusFilter = $_GET['status'] ?? 'All';
if (!in_array($statusFilter, ['All', 'Available', 'Low Stock', 'Out of Stock'], true)) {
    $statusFilter = 'All';
}

$flashSuccess = $_GET['success'] ?? '';
$flashError   = $_GET['error'] ?? '';

$vendors = $pdo->query("SELECT vendor_id, business_name FROM vendors ORDER BY business_name ASC")->fetchAll();
$categories = $pdo->query("SELECT category_id, category_name FROM categories ORDER BY category_name ASC")->fetchAll();

$whereSql = "WHERE 1 = 1";
$params = [];

if ($search !== '') {
    $whereSql .= " AND p.product_name LIKE :search";
    $params['search'] = '%' . $search . '%';
}
if ($vendorFilter > 0) {
    $whereSql .= " AND p.vendor_id = :vendor";
    $params['vendor'] = $vendorFilter;
}
if ($categoryFilter > 0) {
    $whereSql .= " AND p.category_id = :category";
    $params['category'] = $categoryFilter;
}
if ($statusFilter !== 'All') {
    $whereSql .= " AND p.status = :status";
    $params['status'] = $statusFilter;
}

$countStmt = $pdo->prepare(
    "SELECT COUNT(*) FROM products p
     INNER JOIN vendors v ON v.vendor_id = p.vendor_id
     LEFT JOIN categories c ON c.category_id = p.category_id
     $whereSql"
);
$countStmt->execute($params);
$totalProducts = (int) $countStmt->fetchColumn();

$perPage = 20;
$page = currentPage();
$totalPages = max(1, (int) ceil($totalProducts / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

$sql = "SELECT p.*, v.business_name, c.category_name
        FROM products p
        INNER JOIN vendors v ON v.vendor_id = p.vendor_id
        LEFT JOIN categories c ON c.category_id = p.category_id
        $whereSql
        ORDER BY p.product_name ASC
        LIMIT $perPage OFFSET $offset";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$products = $stmt->fetchAll();

$pageTitle    = 'Products';
$activePage   = 'inventory';
$inventoryTab = 'products';
require_once __DIR__ . '/../includes/header.php';
require __DIR__ . '/../includes/inventory_tabs.php';

function productBadgeClass(string $status): string
{
    return match ($status) {
        'Available'    => 'badge-success',
        'Low Stock'    => 'badge-warning',
        'Out of Stock' => 'badge-danger',
        default        => 'badge-muted',
    };
}
?>

    <?php if ($flashSuccess !== ''): ?>
        <div class="alert alert-success"><?= e($flashSuccess) ?></div>
    <?php endif; ?>
    <?php if ($flashError !== ''): ?>
        <div class="alert alert-error"><ul><li><?= e($flashError) ?></li></ul></div>
    <?php endif; ?>

    <div class="page-toolbar">
        <form method="GET" class="search-form">
            <input type="text" name="q" value="<?= e($search) ?>" placeholder="Search product name..." class="search-input">
            <select name="vendor" class="status-select">
                <option value="0">All Vendors</option>
                <?php foreach ($vendors as $v): ?>
                    <option value="<?= (int) $v['vendor_id'] ?>" <?= $vendorFilter === (int) $v['vendor_id'] ? 'selected' : '' ?>><?= e($v['business_name']) ?></option>
                <?php endforeach; ?>
            </select>
            <select name="category" class="status-select">
                <option value="0">All Categories</option>
                <?php foreach ($categories as $c): ?>
                    <option value="<?= (int) $c['category_id'] ?>" <?= $categoryFilter === (int) $c['category_id'] ? 'selected' : '' ?>><?= e($c['category_name']) ?></option>
                <?php endforeach; ?>
            </select>
            <select name="status" class="status-select">
                <option value="All" <?= $statusFilter === 'All' ? 'selected' : '' ?>>All Statuses</option>
                <option value="Available" <?= $statusFilter === 'Available' ? 'selected' : '' ?>>Available</option>
                <option value="Low Stock" <?= $statusFilter === 'Low Stock' ? 'selected' : '' ?>>Low Stock</option>
                <option value="Out of Stock" <?= $statusFilter === 'Out of Stock' ? 'selected' : '' ?>>Out of Stock</option>
            </select>
            <button type="submit" class="btn btn-secondary">Filter</button>
            <?php if ($search !== '' || $vendorFilter > 0 || $categoryFilter > 0 || $statusFilter !== 'All'): ?>
                <a href="products.php" class="btn btn-link">Clear</a>
            <?php endif; ?>
        </form>

        <a href="product_form.php" class="btn btn-primary">+ Add Product</a>
    </div>

    <div class="table-card">
        <?php if (empty($products)): ?>
            <p class="chart-empty">No products found.</p>
        <?php else: ?>
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Product</th>
                        <th>Vendor</th>
                        <th>Category</th>
                        <th>Qty</th>
                        <th>Min. Stock</th>
                        <th>Buying Price</th>
                        <th>Selling Price</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($products as $p): ?>
                        <tr>
                            <td><?= e($p['product_name']) ?></td>
                            <td><?= e($p['business_name']) ?></td>
                            <td><?= e($p['category_name'] ?? '—') ?></td>
                            <td><?= (int) $p['quantity'] ?></td>
                            <td><?= (int) $p['minimum_stock'] ?></td>
                            <td>KES <?= number_format((float) $p['buying_price'], 2) ?></td>
                            <td>KES <?= number_format((float) $p['selling_price'], 2) ?></td>
                            <td><span class="badge <?= productBadgeClass($p['status']) ?>"><?= e($p['status']) ?></span></td>
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

    <?= renderPagination('products.php', $page, $totalPages) ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>