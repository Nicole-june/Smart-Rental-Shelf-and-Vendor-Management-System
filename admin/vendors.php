<?php

require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

requireRole('admin');

// Read filters from the query string

$search = cleanInput($_GET['q'] ?? '');
$statusFilter = $_GET['status'] ?? 'All';
if (!in_array($statusFilter, ['All', 'Active', 'Inactive'], true)) {
    $statusFilter = 'All';
}

// Flash messages passed via query string after a redirect

$flashSuccess = $_GET['success'] ?? '';
$flashError   = $_GET['error'] ?? '';

// Build the search + filter query (all values bound, never concatenated)

$whereSql = "WHERE 1 = 1";
$params = [];

if ($search !== '') {
    $whereSql .= " AND (v.business_name LIKE :search
                   OR u.full_name LIKE :search2
                   OR u.email LIKE :search3
                   OR v.phone LIKE :search4)";
    $like = '%' . $search . '%';
    $params['search']  = $like;
    $params['search2'] = $like;
    $params['search3'] = $like;
    $params['search4'] = $like;
}

if ($statusFilter !== 'All') {
    $whereSql .= " AND v.status = :status";
    $params['status'] = $statusFilter;
}

// Total count for pagination (same filters, no LIMIT)
$countStmt = $pdo->prepare(
    "SELECT COUNT(*) FROM vendors v INNER JOIN users u ON u.user_id = v.user_id $whereSql"
);
$countStmt->execute($params);
$totalVendors = (int) $countStmt->fetchColumn();

$perPage = 20;
$page = currentPage();
$totalPages = max(1, (int) ceil($totalVendors / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

$sql = "SELECT v.vendor_id, v.business_name, v.phone, v.national_id, v.address,
               v.date_joined, v.status, u.full_name, u.email
        FROM vendors v
        INNER JOIN users u ON u.user_id = v.user_id
        $whereSql
        ORDER BY v.date_joined DESC
        LIMIT $perPage OFFSET $offset";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$vendors = $stmt->fetchAll();

$pageTitle  = 'Vendor Management';
$activePage = 'vendors';
require_once __DIR__ . '/../includes/header.php';
?>

    <?php if ($flashSuccess !== ''): ?>
        <div class="alert alert-success"><?= e($flashSuccess) ?></div>
    <?php endif; ?>

    <?php if ($flashError !== ''): ?>
        <div class="alert alert-error"><ul><li><?= e($flashError) ?></li></ul></div>
    <?php endif; ?>

    <div class="page-toolbar">
        <form method="GET" class="search-form">
            <input
                type="text"
                name="q"
                value="<?= e($search) ?>"
                placeholder="Search by business name, contact name, email, or phone..."
                class="search-input"
            >
            <select name="status" class="status-select">
                <option value="All" <?= $statusFilter === 'All' ? 'selected' : '' ?>>All Statuses</option>
                <option value="Active" <?= $statusFilter === 'Active' ? 'selected' : '' ?>>Active</option>
                <option value="Inactive" <?= $statusFilter === 'Inactive' ? 'selected' : '' ?>>Inactive</option>
            </select>
            <button type="submit" class="btn btn-secondary">Filter</button>
            <?php if ($search !== '' || $statusFilter !== 'All'): ?>
                <a href="vendors.php" class="btn btn-link">Clear</a>
            <?php endif; ?>
        </form>

        <a href="vendor_form.php" class="btn btn-primary">+ Add Vendor</a>
    </div>

    <div class="table-card">
        <?php if (empty($vendors)): ?>
            <p class="chart-empty">No vendors found<?= $search !== '' ? ' for "' . e($search) . '"' : '' ?>.</p>
        <?php else: ?>
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Business Name</th>
                        <th>Contact</th>
                        <th>Phone</th>
                        <th>Date Joined</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($vendors as $vendor): ?>
                        <tr>
                            <td><?= e($vendor['business_name']) ?></td>
                            <td>
                                <?= e($vendor['full_name']) ?><br>
                                <span class="text-muted"><?= e($vendor['email']) ?></span>
                            </td>
                            <td><?= e($vendor['phone']) ?></td>
                            <td><?= e(date('d M Y', strtotime($vendor['date_joined']))) ?></td>
                            <td>
                                <span class="badge badge-<?= $vendor['status'] === 'Active' ? 'success' : 'muted' ?>">
                                    <?= e($vendor['status']) ?>
                                </span>
                            </td>
                            <td class="actions-cell">
                                <a href="vendor_view.php?id=<?= (int) $vendor['vendor_id'] ?>" class="action-link">View</a>
                                <a href="vendor_form.php?id=<?= (int) $vendor['vendor_id'] ?>" class="action-link">Edit</a>

                                <form method="POST" action="vendor_status.php" class="inline-form">
                                    <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                                    <input type="hidden" name="vendor_id" value="<?= (int) $vendor['vendor_id'] ?>">
                                    <input type="hidden" name="new_status" value="<?= $vendor['status'] === 'Active' ? 'Inactive' : 'Active' ?>">
                                    <button type="submit" class="action-link action-link-btn">
                                        <?= $vendor['status'] === 'Active' ? 'Deactivate' : 'Activate' ?>
                                    </button>
                                </form>

                                <form method="POST" action="vendor_delete.php" class="inline-form"
                                      onsubmit="return confirm('Delete this vendor permanently? This cannot be undone.');">
                                    <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                                    <input type="hidden" name="vendor_id" value="<?= (int) $vendor['vendor_id'] ?>">
                                    <button type="submit" class="action-link action-link-danger">Delete</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

    <?= renderPagination('vendors.php', $page, $totalPages) ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>