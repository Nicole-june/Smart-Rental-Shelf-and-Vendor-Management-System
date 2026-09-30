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

$typeFilter = $_GET['type'] ?? 'All';
if (!in_array($typeFilter, ['All', 'Sale', 'Low Stock', 'Rental', 'Payment'], true)) {
    $typeFilter = 'All';
}
$readFilter = $_GET['read'] ?? 'All';
if (!in_array($readFilter, ['All', 'Unread', 'Read'], true)) {
    $readFilter = 'All';
}

$sql = "SELECT * FROM notifications WHERE vendor_id = :vendor_id";
$params = ['vendor_id' => $vendorId];

if ($typeFilter !== 'All') {
    $sql .= " AND notification_type = :type";
    $params['type'] = $typeFilter;
}
if ($readFilter === 'Unread') {
    $sql .= " AND is_read = 0";
} elseif ($readFilter === 'Read') {
    $sql .= " AND is_read = 1";
}

$sql .= " ORDER BY created_at DESC LIMIT 100";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$notifications = $stmt->fetchAll();

// Mark everything currently shown as read.
$idsToMark = array_column($notifications, 'notification_id');
if (!empty($idsToMark)) {
    $placeholders = implode(',', array_fill(0, count($idsToMark), '?'));
    $markStmt = $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE notification_id IN ($placeholders)");
    $markStmt->execute($idsToMark);
}

function notifTypeBadge(string $type): string
{
    return match ($type) {
        'Sale'      => 'badge-success',
        'Low Stock' => 'badge-warning',
        'Rental'    => 'badge-occupied',
        'Payment'   => 'badge-muted',
        default     => 'badge-muted',
    };
}

$pageTitle  = 'Notifications';
$activePage = 'notifications';
require_once __DIR__ . '/../includes/vendor_header.php';
?>

    <div class="page-toolbar">
        <form method="GET" class="search-form">
            <select name="type" class="status-select">
                <option value="All" <?= $typeFilter === 'All' ? 'selected' : '' ?>>All Types</option>
                <option value="Sale" <?= $typeFilter === 'Sale' ? 'selected' : '' ?>>Sale</option>
                <option value="Low Stock" <?= $typeFilter === 'Low Stock' ? 'selected' : '' ?>>Low Stock</option>
                <option value="Rental" <?= $typeFilter === 'Rental' ? 'selected' : '' ?>>Rental</option>
                <option value="Payment" <?= $typeFilter === 'Payment' ? 'selected' : '' ?>>Payment</option>
            </select>
            <select name="read" class="status-select">
                <option value="All" <?= $readFilter === 'All' ? 'selected' : '' ?>>All</option>
                <option value="Unread" <?= $readFilter === 'Unread' ? 'selected' : '' ?>>Unread</option>
                <option value="Read" <?= $readFilter === 'Read' ? 'selected' : '' ?>>Read</option>
            </select>
            <button type="submit" class="btn btn-secondary">Filter</button>
        </form>
    </div>

    <div class="table-card">
        <?php if (empty($notifications)): ?>
            <p class="chart-empty">No notifications found.</p>
        <?php else: ?>
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Type</th>
                        <th>Message</th>
                        <th>Date</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($notifications as $n): ?>
                        <tr>
                            <td><span class="badge <?= notifTypeBadge($n['notification_type']) ?>"><?= e($n['notification_type']) ?></span></td>
                            <td><?= e($n['message']) ?></td>
                            <td class="text-muted"><?= e(date('d M Y, g:i A', strtotime($n['created_at']))) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>