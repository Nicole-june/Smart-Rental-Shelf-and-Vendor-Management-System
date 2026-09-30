<?php

require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

requireRole('admin');

$errors = [];
$flashSuccess = $_GET['success'] ?? '';

$vendors = $pdo->query("SELECT vendor_id, business_name FROM vendors ORDER BY business_name ASC")->fetchAll();

$form = ['vendor_id' => 0, 'notification_type' => 'Rental', 'message' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Your session expired. Please try again.';
    }

    $form['vendor_id']         = (int) ($_POST['vendor_id'] ?? 0);
    $form['notification_type'] = $_POST['notification_type'] ?? 'Rental';
    $form['message']           = cleanInput($_POST['message'] ?? '');

    if (!in_array($form['notification_type'], ['Sale', 'Low Stock', 'Rental', 'Payment'], true)) {
        $form['notification_type'] = 'Rental';
    }
    if ($form['vendor_id'] <= 0) {
        $errors[] = 'Please select a vendor.';
    }
    if ($form['message'] === '') {
        $errors[] = 'Message is required.';
    }

    if (empty($errors)) {
        createNotification($pdo, $form['vendor_id'], $form['message'], $form['notification_type']);

        $vendorNameStmt = $pdo->prepare("SELECT business_name FROM vendors WHERE vendor_id = :id");
        $vendorNameStmt->execute(['id' => $form['vendor_id']]);
        $vendorName = $vendorNameStmt->fetchColumn();

        logActivity($pdo, currentUserId(), 'Sent notification to ' . $vendorName);
        redirectTo('notifications.php?success=' . urlencode('Notification sent to ' . $vendorName . '.'));
    }
}

// Filters for the log below
$typeFilter = $_GET['type'] ?? 'All';
if (!in_array($typeFilter, ['All', 'Sale', 'Low Stock', 'Rental', 'Payment'], true)) {
    $typeFilter = 'All';
}
$vendorFilter = (int) ($_GET['vendor'] ?? 0);

$logSql = "SELECT n.*, v.business_name
           FROM notifications n
           INNER JOIN vendors v ON v.vendor_id = n.vendor_id
           WHERE 1 = 1";
$logParams = [];
if ($typeFilter !== 'All') {
    $logSql .= " AND n.notification_type = :type";
    $logParams['type'] = $typeFilter;
}
if ($vendorFilter > 0) {
    $logSql .= " AND n.vendor_id = :vendor";
    $logParams['vendor'] = $vendorFilter;
}
$logSql .= " ORDER BY n.created_at DESC LIMIT 100";

$logStmt = $pdo->prepare($logSql);
$logStmt->execute($logParams);
$log = $logStmt->fetchAll();

function adminNotifBadge(string $type): string
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
require_once __DIR__ . '/../includes/header.php';
?>

    <?php if ($flashSuccess !== ''): ?>
        <div class="alert alert-success"><?= e($flashSuccess) ?></div>
    <?php endif; ?>
    <?php if (!empty($errors)): ?>
        <div class="alert alert-error"><ul><?php foreach ($errors as $error): ?><li><?= e($error) ?></li><?php endforeach; ?></ul></div>
    <?php endif; ?>

    <div class="split-layout">
        <div class="form-card split-form">
            <h3>Send Notification</h3>
            <form method="POST" action="notifications.php" class="stacked-form" novalidate>
                <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">

                <div class="form-group">
                    <label for="vendor_id">Vendor</label>
                    <select id="vendor_id" name="vendor_id" required>
                        <option value="">Select vendor...</option>
                        <?php foreach ($vendors as $v): ?>
                            <option value="<?= (int) $v['vendor_id'] ?>" <?= $form['vendor_id'] === (int) $v['vendor_id'] ? 'selected' : '' ?>><?= e($v['business_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label for="notification_type">Type</label>
                    <select id="notification_type" name="notification_type">
                        <option value="Rental" <?= $form['notification_type'] === 'Rental' ? 'selected' : '' ?>>Rental</option>
                        <option value="Payment" <?= $form['notification_type'] === 'Payment' ? 'selected' : '' ?>>Payment</option>
                        <option value="Low Stock" <?= $form['notification_type'] === 'Low Stock' ? 'selected' : '' ?>>Low Stock</option>
                        <option value="Sale" <?= $form['notification_type'] === 'Sale' ? 'selected' : '' ?>>Sale</option>
                    </select>
                </div>

                <div class="form-group">
                    <label for="message">Message</label>
                    <textarea id="message" name="message" rows="4" required><?= e($form['message']) ?></textarea>
                </div>

                <button type="submit" class="btn btn-primary">Send Notification</button>
            </form>
        </div>

        <div class="table-card split-table">
            <form method="GET" class="search-form" style="margin-bottom: 14px;">
                <select name="vendor" class="status-select">
                    <option value="0">All Vendors</option>
                    <?php foreach ($vendors as $v): ?>
                        <option value="<?= (int) $v['vendor_id'] ?>" <?= $vendorFilter === (int) $v['vendor_id'] ? 'selected' : '' ?>><?= e($v['business_name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <select name="type" class="status-select">
                    <option value="All" <?= $typeFilter === 'All' ? 'selected' : '' ?>>All Types</option>
                    <option value="Sale" <?= $typeFilter === 'Sale' ? 'selected' : '' ?>>Sale</option>
                    <option value="Low Stock" <?= $typeFilter === 'Low Stock' ? 'selected' : '' ?>>Low Stock</option>
                    <option value="Rental" <?= $typeFilter === 'Rental' ? 'selected' : '' ?>>Rental</option>
                    <option value="Payment" <?= $typeFilter === 'Payment' ? 'selected' : '' ?>>Payment</option>
                </select>
                <button type="submit" class="btn btn-secondary">Filter</button>
            </form>

            <?php if (empty($log)): ?>
                <p class="chart-empty">No notifications sent yet.</p>
            <?php else: ?>
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Vendor</th>
                            <th>Type</th>
                            <th>Message</th>
                            <th>Read?</th>
                            <th>Date</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($log as $n): ?>
                            <tr>
                                <td><?= e($n['business_name']) ?></td>
                                <td><span class="badge <?= adminNotifBadge($n['notification_type']) ?>"><?= e($n['notification_type']) ?></span></td>
                                <td><?= e($n['message']) ?></td>
                                <td><?= $n['is_read'] ? 'Read' : 'Unread' ?></td>
                                <td class="text-muted"><?= e(date('d M Y, g:i A', strtotime($n['created_at']))) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>