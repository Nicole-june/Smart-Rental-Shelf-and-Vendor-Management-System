<?php

require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

requireRole('admin');

$search = cleanInput($_GET['q'] ?? '');
$statusFilter = $_GET['status'] ?? 'All';
if (!in_array($statusFilter, ['All', 'Available', 'Occupied', 'Maintenance'], true)) {
    $statusFilter = 'All';
}

$flashSuccess = $_GET['success'] ?? '';
$flashError   = $_GET['error'] ?? '';

$sql = "SELECT shelf_id, shelf_code, shelf_name, building_name, floor, location, size, monthly_rent, status
        FROM shelves WHERE 1 = 1";
$params = [];

if ($search !== '') {
    $sql .= " AND (shelf_code LIKE :s1 OR shelf_name LIKE :s2 OR building_name LIKE :s3 OR location LIKE :s4)";
    $like = '%' . $search . '%';
    $params['s1'] = $like;
    $params['s2'] = $like;
    $params['s3'] = $like;
    $params['s4'] = $like;
}

if ($statusFilter !== 'All') {
    $sql .= " AND status = :status";
    $params['status'] = $statusFilter;
}

$sql .= " ORDER BY building_name ASC, floor ASC, shelf_code ASC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$shelves = $stmt->fetchAll();

$pageTitle  = 'Shelf Management';
$activePage = 'shelves';
require_once __DIR__ . '/../includes/header.php';

function shelfBadgeClass(string $status): string
{
    return match ($status) {
        'Available'   => 'badge-success',
        'Occupied'    => 'badge-occupied',
        'Maintenance' => 'badge-warning',
        default       => 'badge-muted',
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
            <input
                type="text"
                name="q"
                value="<?= e($search) ?>"
                placeholder="Search by shelf code, name, building, or location..."
                class="search-input"
            >
            <select name="status" class="status-select">
                <option value="All" <?= $statusFilter === 'All' ? 'selected' : '' ?>>All Statuses</option>
                <option value="Available" <?= $statusFilter === 'Available' ? 'selected' : '' ?>>Available</option>
                <option value="Occupied" <?= $statusFilter === 'Occupied' ? 'selected' : '' ?>>Occupied</option>
                <option value="Maintenance" <?= $statusFilter === 'Maintenance' ? 'selected' : '' ?>>Maintenance</option>
            </select>
            <button type="submit" class="btn btn-secondary">Filter</button>
            <?php if ($search !== '' || $statusFilter !== 'All'): ?>
                <a href="shelves.php" class="btn btn-link">Clear</a>
            <?php endif; ?>
        </form>

        <a href="shelf_form.php" class="btn btn-primary">+ Add Shelf</a>
    </div>

    <div class="table-card">
        <?php if (empty($shelves)): ?>
            <p class="chart-empty">No shelves found<?= $search !== '' ? ' for "' . e($search) . '"' : '' ?>.</p>
        <?php else: ?>
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Shelf Code</th>
                        <th>Name</th>
                        <th>Building</th>
                        <th>Floor</th>
                        <th>Location</th>
                        <th>Size</th>
                        <th>Monthly Rent</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($shelves as $shelf): ?>
                        <tr>
                            <td><strong><?= e($shelf['shelf_code']) ?></strong></td>
                            <td><?= e($shelf['shelf_name']) ?></td>
                            <td><?= e($shelf['building_name']) ?></td>
                            <td><?= e($shelf['floor']) ?></td>
                            <td><?= e($shelf['location']) ?></td>
                            <td><?= e($shelf['size']) ?></td>
                            <td>KES <?= number_format((float) $shelf['monthly_rent'], 2) ?></td>
                            <td>
                                <span class="badge <?= shelfBadgeClass($shelf['status']) ?>">
                                    <?= e($shelf['status']) ?>
                                </span>
                            </td>
                            <td class="actions-cell">
                                <a href="shelf_form.php?id=<?= (int) $shelf['shelf_id'] ?>" class="action-link">Edit</a>

                                <form method="POST" action="shelf_status.php" class="inline-form">
                                    <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                                    <input type="hidden" name="shelf_id" value="<?= (int) $shelf['shelf_id'] ?>">
                                    <select name="new_status" class="mini-select" onchange="this.form.submit()">
                                        <option value="Available" <?= $shelf['status'] === 'Available' ? 'selected' : '' ?>>Available</option>
                                        <option value="Occupied" <?= $shelf['status'] === 'Occupied' ? 'selected' : '' ?>>Occupied</option>
                                        <option value="Maintenance" <?= $shelf['status'] === 'Maintenance' ? 'selected' : '' ?>>Maintenance</option>
                                    </select>
                                    <noscript><button type="submit" class="action-link-btn">Update</button></noscript>
                                </form>

                                <form method="POST" action="shelf_delete.php" class="inline-form"
                                      onsubmit="return confirm('Delete this shelf permanently? This cannot be undone.');">
                                    <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                                    <input type="hidden" name="shelf_id" value="<?= (int) $shelf['shelf_id'] ?>">
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