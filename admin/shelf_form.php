<?php
/**
 * File: admin/shelf_form.php
 * Folder: admin/
 * Purpose: Add/Edit shelf form.
 *            - Add mode: shelf_code is auto-generated server-side from
 *              building_name + floor (see generateShelfCode() in
 *              includes/functions.php) — the admin never types it.
 *            - Edit mode: shelf_code is shown but NOT editable, since
 *              changing it after the fact would be confusing (it's used
 *              to identify a physical location elsewhere in the system).
 */

require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

requireRole('admin');

$shelfId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$isEdit = $shelfId > 0;

$errors = [];

$form = [
    'shelf_name'    => '',
    'building_name' => '',
    'floor'         => '',
    'location'      => '',
    'size'          => '',
    'monthly_rent'  => '',
    'status'        => 'Available',
];
$shelfCode = null;

if ($isEdit) {
    $stmt = $pdo->prepare("SELECT * FROM shelves WHERE shelf_id = :id LIMIT 1");
    $stmt->execute(['id' => $shelfId]);
    $existing = $stmt->fetch();

    if (!$existing) {
        redirectTo('shelves.php?error=' . urlencode('Shelf not found.'));
    }

    $form = [
        'shelf_name'    => $existing['shelf_name'],
        'building_name' => $existing['building_name'],
        'floor'         => $existing['floor'],
        'location'      => $existing['location'],
        'size'          => $existing['size'],
        'monthly_rent'  => $existing['monthly_rent'],
        'status'        => $existing['status'],
    ];
    $shelfCode = $existing['shelf_code'];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Your session expired. Please try again.';
    }

    $form['shelf_name']    = cleanInput($_POST['shelf_name'] ?? '');
    $form['building_name'] = cleanInput($_POST['building_name'] ?? '');
    $form['floor']         = cleanInput($_POST['floor'] ?? '');
    $form['location']      = cleanInput($_POST['location'] ?? '');
    $form['size']          = cleanInput($_POST['size'] ?? '');
    $form['monthly_rent']  = cleanInput($_POST['monthly_rent'] ?? '');
    $form['status']        = $_POST['status'] ?? 'Available';

    if (!in_array($form['status'], ['Available', 'Occupied', 'Maintenance'], true)) {
        $form['status'] = 'Available';
    }

    if ($form['shelf_name'] === '') {
        $errors[] = 'Shelf name is required.';
    }
    if ($form['building_name'] === '') {
        $errors[] = 'Building name is required.';
    }
    if ($form['floor'] === '') {
        $errors[] = 'Floor is required.';
    }
    if ($form['location'] === '') {
        $errors[] = 'Location is required.';
    }
    if (!is_numeric($form['monthly_rent']) || (float) $form['monthly_rent'] <= 0) {
        $errors[] = 'Monthly rent must be a positive number.';
    }

    if (empty($errors)) {
        try {
            if ($isEdit) {
                $updateStmt = $pdo->prepare(
                    "UPDATE shelves SET shelf_name = :shelf_name, building_name = :building_name,
                            floor = :floor, location = :location, size = :size,
                            monthly_rent = :monthly_rent, status = :status
                     WHERE shelf_id = :shelf_id"
                );
                $updateStmt->execute([
                    'shelf_name'    => $form['shelf_name'],
                    'building_name' => $form['building_name'],
                    'floor'         => $form['floor'],
                    'location'      => $form['location'],
                    'size'          => $form['size'],
                    'monthly_rent'  => $form['monthly_rent'],
                    'status'        => $form['status'],
                    'shelf_id'      => $shelfId,
                ]);

                logActivity($pdo, currentUserId(), 'Updated shelf: ' . $shelfCode);
                redirectTo('shelves.php?success=' . urlencode('Shelf updated successfully.'));

            } else {
                $newCode = generateShelfCode($pdo, $form['building_name'], $form['floor']);

                $insertStmt = $pdo->prepare(
                    "INSERT INTO shelves (shelf_code, shelf_name, building_name, floor, location, size, monthly_rent, status)
                     VALUES (:shelf_code, :shelf_name, :building_name, :floor, :location, :size, :monthly_rent, :status)"
                );
                $insertStmt->execute([
                    'shelf_code'    => $newCode,
                    'shelf_name'    => $form['shelf_name'],
                    'building_name' => $form['building_name'],
                    'floor'         => $form['floor'],
                    'location'      => $form['location'],
                    'size'          => $form['size'],
                    'monthly_rent'  => $form['monthly_rent'],
                    'status'        => $form['status'],
                ]);

                logActivity($pdo, currentUserId(), 'Added new shelf: ' . $newCode);
                redirectTo('shelves.php?success=' . urlencode('Shelf ' . $newCode . ' created successfully.'));
            }
        } catch (PDOException $e) {
            $errors[] = 'Something went wrong while saving. Please try again.';
        }
    }
}

$pageTitle  = $isEdit ? 'Edit Shelf' : 'Add Shelf';
$activePage = 'shelves';
require_once __DIR__ . '/../includes/header.php';
?>

    <div class="form-card">
        <a href="shelves.php" class="back-link">&larr; Back to Shelves</a>

        <?php if (!empty($errors)): ?>
            <div class="alert alert-error">
                <ul>
                    <?php foreach ($errors as $error): ?>
                        <li><?= e($error) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <?php if ($isEdit): ?>
            <div class="shelf-code-display">
                Shelf Code: <strong><?= e($shelfCode) ?></strong>
                <span class="text-muted">(auto-generated, cannot be changed)</span>
            </div>
        <?php else: ?>
            <div class="shelf-code-display">
                <span class="text-muted">Shelf code will be auto-generated from building + floor when you save.</span>
            </div>
        <?php endif; ?>

        <form method="POST" action="shelf_form.php<?= $isEdit ? '?id=' . $shelfId : '' ?>" class="stacked-form" novalidate>
            <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">

            <div class="form-row">
                <div class="form-group">
                    <label for="shelf_name">Shelf Name</label>
                    <input type="text" id="shelf_name" name="shelf_name" value="<?= e($form['shelf_name']) ?>" required>
                </div>
                <div class="form-group">
                    <label for="building_name">Building Name</label>
                    <input type="text" id="building_name" name="building_name" value="<?= e($form['building_name']) ?>" required>
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label for="floor">Floor</label>
                    <input type="text" id="floor" name="floor" value="<?= e($form['floor']) ?>" required>
                </div>
                <div class="form-group">
                    <label for="location">Location (e.g. near entrance, aisle 3)</label>
                    <input type="text" id="location" name="location" value="<?= e($form['location']) ?>" required>
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label for="size">Size (e.g. 2m x 1m)</label>
                    <input type="text" id="size" name="size" value="<?= e($form['size']) ?>">
                </div>
                <div class="form-group">
                    <label for="monthly_rent">Monthly Rent (KES)</label>
                    <input type="number" step="0.01" min="0" id="monthly_rent" name="monthly_rent" value="<?= e((string) $form['monthly_rent']) ?>" required>
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label for="status">Status</label>
                    <select id="status" name="status">
                        <option value="Available" <?= $form['status'] === 'Available' ? 'selected' : '' ?>>Available</option>
                        <option value="Occupied" <?= $form['status'] === 'Occupied' ? 'selected' : '' ?>>Occupied</option>
                        <option value="Maintenance" <?= $form['status'] === 'Maintenance' ? 'selected' : '' ?>>Maintenance</option>
                    </select>
                </div>
            </div>

            <button type="submit" class="btn btn-primary"><?= $isEdit ? 'Save Changes' : 'Create Shelf' ?></button>
        </form>
    </div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>