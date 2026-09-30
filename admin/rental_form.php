<?php

require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

requireRole('admin');

$errors = [];

$form = [
    'vendor_id'    => '',
    'shelf_id'     => '',
    'start_date'   => date('Y-m-d'),
    'end_date'     => '',
    'monthly_rent' => '',
];

// Active vendors and Available shelves — only valid choices are offered.
$vendors = $pdo->query(
    "SELECT vendor_id, business_name FROM vendors WHERE status = 'Active' ORDER BY business_name ASC"
)->fetchAll();

$shelves = $pdo->query(
    "SELECT shelf_id, shelf_code, shelf_name, building_name, floor, monthly_rent
     FROM shelves WHERE status = 'Available' ORDER BY building_name ASC, floor ASC"
)->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Your session expired. Please try again.';
    }

    $form['vendor_id']    = (int) ($_POST['vendor_id'] ?? 0);
    $form['shelf_id']     = (int) ($_POST['shelf_id'] ?? 0);
    $form['start_date']   = cleanInput($_POST['start_date'] ?? '');
    $form['end_date']     = cleanInput($_POST['end_date'] ?? '');
    $form['monthly_rent'] = cleanInput($_POST['monthly_rent'] ?? '');

    if ($form['vendor_id'] <= 0) {
        $errors[] = 'Please select a vendor.';
    }
    if ($form['shelf_id'] <= 0) {
        $errors[] = 'Please select a shelf.';
    }
    if ($form['start_date'] === '' || $form['end_date'] === '') {
        $errors[] = 'Start date and end date are required.';
    } elseif (strtotime($form['start_date']) < strtotime(date('Y-m-d'))) {
        $errors[] = 'Start date cannot be in the past.';
    } elseif (strtotime($form['end_date']) <= strtotime($form['start_date'])) {
        $errors[] = 'End date must be after the start date.';
    }
    if (!is_numeric($form['monthly_rent']) || (float) $form['monthly_rent'] <= 0) {
        $errors[] = 'Monthly rent must be a positive number.';
    }

    if (empty($errors)) {
        try {
            $pdo->beginTransaction();

            // Re-check the shelf is still Available right now (race condition guard)
            $lockStmt = $pdo->prepare("SELECT status, shelf_code FROM shelves WHERE shelf_id = :id FOR UPDATE");
            $lockStmt->execute(['id' => $form['shelf_id']]);
            $shelfRow = $lockStmt->fetch();

            $vendorStmt = $pdo->prepare("SELECT business_name FROM vendors WHERE vendor_id = :id");
            $vendorStmt->execute(['id' => $form['vendor_id']]);
            $vendorRow = $vendorStmt->fetch();

            if (!$shelfRow || $shelfRow['status'] !== 'Available') {
                throw new RuntimeException('shelf_unavailable');
            }
            if (!$vendorRow) {
                throw new RuntimeException('vendor_missing');
            }

            $insertStmt = $pdo->prepare(
                "INSERT INTO rentals (payment_id, vendor_id, shelf_id, start_date, end_date, monthly_rent, rental_status, payment_status, created_at)
                 VALUES (NULL, :vendor_id, :shelf_id, :start_date, :end_date, :monthly_rent, 'Active', 'Pending', NOW())"
            );
            $insertStmt->execute([
                'vendor_id'    => $form['vendor_id'],
                'shelf_id'     => $form['shelf_id'],
                'start_date'   => $form['start_date'],
                'end_date'     => $form['end_date'],
                'monthly_rent' => $form['monthly_rent'],
            ]);

            $updateShelf = $pdo->prepare("UPDATE shelves SET status = 'Occupied' WHERE shelf_id = :id");
            $updateShelf->execute(['id' => $form['shelf_id']]);

            $pdo->commit();

            logActivity(
                $pdo,
                currentUserId(),
                'Created rental: ' . $shelfRow['shelf_code'] . ' assigned to ' . $vendorRow['business_name']
            );

            redirectTo('rentals.php?success=' . urlencode('Rental created successfully.'));

        } catch (RuntimeException $e) {
            $pdo->rollBack();
            if ($e->getMessage() === 'shelf_unavailable') {
                $errors[] = 'That shelf was just taken by another rental. Please choose a different shelf.';
            } else {
                $errors[] = 'Selected vendor no longer exists.';
            }
            // Refresh the available-shelves list since the one just picked may no longer qualify.
            $shelves = $pdo->query(
                "SELECT shelf_id, shelf_code, shelf_name, building_name, floor, monthly_rent
                 FROM shelves WHERE status = 'Available' ORDER BY building_name ASC, floor ASC"
            )->fetchAll();
        } catch (PDOException $e) {
            $pdo->rollBack();
            $errors[] = 'Something went wrong while saving. Please try again.';
        }
    }
}

$pageTitle  = 'Create Rental';
$activePage = 'rentals';
require_once __DIR__ . '/../includes/header.php';
?>

    <div class="form-card">
        <a href="rentals.php" class="back-link">&larr; Back to Rentals</a>

        <?php if (!empty($errors)): ?>
            <div class="alert alert-error">
                <ul>
                    <?php foreach ($errors as $error): ?>
                        <li><?= e($error) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <?php if (empty($vendors)): ?>
            <p class="chart-empty">No active vendors available. Add or activate a vendor first.</p>
        <?php elseif (empty($shelves)): ?>
            <p class="chart-empty">No shelves are currently Available. Free up or add a shelf first.</p>
        <?php else: ?>
            <form method="POST" action="rental_form.php" class="stacked-form" novalidate>
                <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">

                <div class="form-row">
                    <div class="form-group">
                        <label for="vendor_id">Vendor</label>
                        <select id="vendor_id" name="vendor_id" required>
                            <option value="">Select a vendor...</option>
                            <?php foreach ($vendors as $vendor): ?>
                                <option value="<?= (int) $vendor['vendor_id'] ?>" <?= (int) $form['vendor_id'] === (int) $vendor['vendor_id'] ? 'selected' : '' ?>>
                                    <?= e($vendor['business_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="shelf_id">Shelf</label>
                        <select id="shelf_id" name="shelf_id" required onchange="fillRentFromShelf(this)">
                            <option value="">Select a shelf...</option>
                            <?php foreach ($shelves as $shelf): ?>
                                <option
                                    value="<?= (int) $shelf['shelf_id'] ?>"
                                    data-rent="<?= e((string) $shelf['monthly_rent']) ?>"
                                    <?= (int) $form['shelf_id'] === (int) $shelf['shelf_id'] ? 'selected' : '' ?>
                                >
                                    <?= e($shelf['shelf_code']) ?> — <?= e($shelf['shelf_name']) ?>
                                    (<?= e($shelf['building_name']) ?>, Floor <?= e($shelf['floor']) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="start_date">Start Date</label>
                        <input type="date" id="start_date" name="start_date" value="<?= e($form['start_date']) ?>" min="<?= date('Y-m-d') ?>" required>
                    </div>
                    <div class="form-group">
                        <label for="end_date">End Date</label>
                        <input type="date" id="end_date" name="end_date" value="<?= e($form['end_date']) ?>" required>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="monthly_rent">Monthly Rent (KES)</label>
                        <input type="number" step="0.01" min="0" id="monthly_rent" name="monthly_rent" value="<?= e((string) $form['monthly_rent']) ?>" required>
                        <span class="text-muted">Auto-fills from the shelf's listed rent — adjust if you're offering a different rate.</span>
                    </div>
                </div>

                <button type="submit" class="btn btn-primary">Create Rental</button>
            </form>

            <script>
                function fillRentFromShelf(selectEl) {
                    var option = selectEl.options[selectEl.selectedIndex];
                    var rent = option.getAttribute('data-rent');
                    if (rent) {
                        document.getElementById('monthly_rent').value = rent;
                    }
                }
            </script>
        <?php endif; ?>
    </div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>