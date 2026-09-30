<?php

require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

requireRole('admin');

$buildingFilter = cleanInput($_GET['building'] ?? 'All');
$floorFilter    = cleanInput($_GET['floor'] ?? 'All');
$statusFilter   = $_GET['status'] ?? 'All';
if (!in_array($statusFilter, ['All', 'Available', 'Occupied', 'Maintenance'], true)) {
    $statusFilter = 'All';
}

// Distinct building/floor lists for the filter dropdowns
$buildings = $pdo->query("SELECT DISTINCT building_name FROM shelves ORDER BY building_name ASC")
    ->fetchAll(PDO::FETCH_COLUMN);
$floors = $pdo->query("SELECT DISTINCT floor FROM shelves ORDER BY floor ASC")
    ->fetchAll(PDO::FETCH_COLUMN);

// Main query: shelf + (if Occupied) the vendor from its active rental
$sql = "SELECT s.shelf_id, s.shelf_code, s.shelf_name, s.building_name, s.floor,
               s.location, s.size, s.monthly_rent, s.status,
               v.vendor_id, v.business_name
        FROM shelves s
        LEFT JOIN rentals r ON r.shelf_id = s.shelf_id AND r.rental_status = 'Active'
        LEFT JOIN vendors v ON v.vendor_id = r.vendor_id
        WHERE 1 = 1";
$params = [];

if ($buildingFilter !== 'All' && $buildingFilter !== '') {
    $sql .= " AND s.building_name = :building";
    $params['building'] = $buildingFilter;
}
if ($floorFilter !== 'All' && $floorFilter !== '') {
    $sql .= " AND s.floor = :floor";
    $params['floor'] = $floorFilter;
}
if ($statusFilter !== 'All') {
    $sql .= " AND s.status = :status";
    $params['status'] = $statusFilter;
}

$sql .= " ORDER BY s.building_name ASC, s.floor ASC, s.shelf_code ASC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$shelves = $stmt->fetchAll();

$pageTitle  = 'Shelf Occupancy';
$activePage = 'occupancy';
require_once __DIR__ . '/../includes/header.php';

function occupancyStatusClass(string $status): string
{
    return match ($status) {
        'Available'   => 'occ-available',
        'Occupied'    => 'occ-occupied',
        'Maintenance' => 'occ-maintenance',
        default       => '',
    };
}
?>

    <div class="page-toolbar">
        <form method="GET" class="search-form">
            <select name="building" class="status-select">
                <option value="All">All Buildings</option>
                <?php foreach ($buildings as $b): ?>
                    <option value="<?= e($b) ?>" <?= $buildingFilter === $b ? 'selected' : '' ?>><?= e($b) ?></option>
                <?php endforeach; ?>
            </select>
            <select name="floor" class="status-select">
                <option value="All">All Floors</option>
                <?php foreach ($floors as $f): ?>
                    <option value="<?= e($f) ?>" <?= $floorFilter === $f ? 'selected' : '' ?>>Floor <?= e($f) ?></option>
                <?php endforeach; ?>
            </select>
            <select name="status" class="status-select">
                <option value="All" <?= $statusFilter === 'All' ? 'selected' : '' ?>>All Statuses</option>
                <option value="Available" <?= $statusFilter === 'Available' ? 'selected' : '' ?>>Available</option>
                <option value="Occupied" <?= $statusFilter === 'Occupied' ? 'selected' : '' ?>>Occupied</option>
                <option value="Maintenance" <?= $statusFilter === 'Maintenance' ? 'selected' : '' ?>>Maintenance</option>
            </select>
            <button type="submit" class="btn btn-secondary">Filter</button>
            <?php if ($buildingFilter !== 'All' || $floorFilter !== 'All' || $statusFilter !== 'All'): ?>
                <a href="occupancy.php" class="btn btn-link">Clear</a>
            <?php endif; ?>
        </form>
    </div>

    <div class="occupancy-legend">
        <span><span class="legend-dot occ-available"></span> Available</span>
        <span><span class="legend-dot occ-occupied"></span> Occupied</span>
        <span><span class="legend-dot occ-maintenance"></span> Maintenance</span>
    </div>

    <?php if (empty($shelves)): ?>
        <p class="chart-empty">No shelves match these filters.</p>
    <?php else: ?>
        <div class="occupancy-grid">
            <?php foreach ($shelves as $shelf): ?>
                <div class="occupancy-card <?= occupancyStatusClass($shelf['status']) ?>">
                    <div class="occupancy-card-top">
                        <span class="occupancy-code"><?= e($shelf['shelf_code']) ?></span>
                        <span class="badge <?= shelfBadgeClassOcc($shelf['status']) ?>"><?= e($shelf['status']) ?></span>
                    </div>
                    <h4 class="occupancy-name"><?= e($shelf['shelf_name']) ?></h4>
                    <dl class="occupancy-details">
                        <dt>Building</dt>
                        <dd><?= e($shelf['building_name']) ?></dd>
                        <dt>Floor</dt>
                        <dd><?= e($shelf['floor']) ?></dd>
                        <dt>Location</dt>
                        <dd><?= e($shelf['location']) ?></dd>
                    </dl>
                    <div class="occupancy-vendor">
                        <?php if ($shelf['status'] === 'Occupied' && $shelf['business_name']): ?>
                            <span class="text-muted">Occupied by</span><br>
                            <a href="vendor_view.php?id=<?= (int) $shelf['vendor_id'] ?>"><?= e($shelf['business_name']) ?></a>
                        <?php elseif ($shelf['status'] === 'Occupied'): ?>
                            <span class="text-muted">Occupied (no active rental on record)</span>
                        <?php else: ?>
                            <span class="text-muted">—</span>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

<?php
/**
 * Local helper (kept in this file, not functions.php, since the badge
 * class list is specific to this page's visual treatment).
 */
function shelfBadgeClassOcc(string $status): string
{
    return match ($status) {
        'Available'   => 'badge-success',
        'Occupied'    => 'badge-occupied',
        'Maintenance' => 'badge-warning',
        default       => 'badge-muted',
    };
}

require_once __DIR__ . '/../includes/footer.php';
?>