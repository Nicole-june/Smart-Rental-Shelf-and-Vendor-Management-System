<?php

require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

requireRole('admin');

$vendorId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$isEdit = $vendorId > 0;

$errors = [];

// Default form values
$form = [
    'full_name'    => '',
    'email'        => '',
    'business_name'=> '',
    'phone'        => '',
    'national_id'  => '',
    'address'      => '',
    'status'       => 'Active',
];

// ---------------------------------------------------------------------
// Edit mode: load the existing vendor + user record
// ---------------------------------------------------------------------
if ($isEdit) {
    $stmt = $pdo->prepare(
        "SELECT v.vendor_id, v.user_id, v.business_name, v.phone, v.national_id,
                v.address, v.status, u.full_name, u.email
         FROM vendors v
         INNER JOIN users u ON u.user_id = v.user_id
         WHERE v.vendor_id = :id
         LIMIT 1"
    );
    $stmt->execute(['id' => $vendorId]);
    $existing = $stmt->fetch();

    if (!$existing) {
        redirectTo('vendors.php?error=' . urlencode('Vendor not found.'));
    }

    $form = [
        'full_name'     => $existing['full_name'],
        'email'         => $existing['email'],
        'business_name' => $existing['business_name'],
        'phone'         => $existing['phone'],
        'national_id'   => $existing['national_id'],
        'address'       => $existing['address'],
        'status'        => $existing['status'],
    ];
}

// ---------------------------------------------------------------------
// Handle submission
// ---------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Your session expired. Please try again.';
    }

    $form['full_name']     = cleanInput($_POST['full_name'] ?? '');
    $form['email']         = cleanInput($_POST['email'] ?? '');
    $form['business_name'] = cleanInput($_POST['business_name'] ?? '');
    $form['phone']         = cleanInput($_POST['phone'] ?? '');
    $form['national_id']   = cleanInput($_POST['national_id'] ?? '');
    $form['address']       = cleanInput($_POST['address'] ?? '');
    $form['status']        = ($_POST['status'] ?? 'Active') === 'Inactive' ? 'Inactive' : 'Active';
    $password               = (string) ($_POST['password'] ?? '');

    // --- Validation ---
    if ($form['full_name'] === '') {
        $errors[] = 'Full name is required.';
    }
    if (!filter_var($form['email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'A valid email address is required.';
    }
    if ($form['business_name'] === '') {
        $errors[] = 'Business name is required.';
    }
    if ($form['phone'] === '') {
        $errors[] = 'Phone number is required.';
    }
    if (!$isEdit && $password === '') {
        $errors[] = 'Password is required when creating a new vendor.';
    }
    if ($password !== '' && strlen($password) < 6) {
        $errors[] = 'Password must be at least 6 characters.';
    }

    // Email uniqueness check (excluding the current user on edit)
    if (empty($errors)) {
        $emailCheckSql = "SELECT user_id FROM users WHERE email = :email";
        $emailParams = ['email' => $form['email']];
        if ($isEdit) {
            $emailCheckSql .= " AND user_id != :self_id";
            $emailParams['self_id'] = $existing['user_id'];
        }
        $emailStmt = $pdo->prepare($emailCheckSql);
        $emailStmt->execute($emailParams);
        if ($emailStmt->fetch()) {
            $errors[] = 'That email address is already in use by another account.';
        }
    }

    if (empty($errors)) {
        try {
            $pdo->beginTransaction();

            if ($isEdit) {
                // --- Update users row ---
                if ($password !== '') {
                    $userStmt = $pdo->prepare(
                        "UPDATE users SET full_name = :full_name, email = :email, password = :password
                         WHERE user_id = :user_id"
                    );
                    $userStmt->execute([
                        'full_name' => $form['full_name'],
                        'email'     => $form['email'],
                        'password'  => hashPassword($password),
                        'user_id'   => $existing['user_id'],
                    ]);
                } else {
                    $userStmt = $pdo->prepare(
                        "UPDATE users SET full_name = :full_name, email = :email
                         WHERE user_id = :user_id"
                    );
                    $userStmt->execute([
                        'full_name' => $form['full_name'],
                        'email'     => $form['email'],
                        'user_id'   => $existing['user_id'],
                    ]);
                }

                // --- Update vendors row ---
                $vendorStmt = $pdo->prepare(
                    "UPDATE vendors SET business_name = :business_name, phone = :phone,
                            national_id = :national_id, address = :address, status = :status
                     WHERE vendor_id = :vendor_id"
                );
                $vendorStmt->execute([
                    'business_name' => $form['business_name'],
                    'phone'         => $form['phone'],
                    'national_id'   => $form['national_id'],
                    'address'       => $form['address'],
                    'status'        => $form['status'],
                    'vendor_id'     => $vendorId,
                ]);

                $pdo->commit();
                logActivity($pdo, currentUserId(), 'Updated vendor: ' . $form['business_name']);
                redirectTo('vendors.php?success=' . urlencode('Vendor updated successfully.'));

            } else {
                // --- Create users row (role=vendor, status=Active) ---
                $userStmt = $pdo->prepare(
                    "INSERT INTO users (full_name, email, password, role, status, created_at)
                     VALUES (:full_name, :email, :password, 'vendor', 'active', NOW())"
                );
                $userStmt->execute([
                    'full_name' => $form['full_name'],
                    'email'     => $form['email'],
                    'password'  => hashPassword($password),
                ]);
                $newUserId = (int) $pdo->lastInsertId();

                // --- Create vendors row ---
                $vendorStmt = $pdo->prepare(
                    "INSERT INTO vendors (user_id, business_name, phone, national_id, address, date_joined, status)
                     VALUES (:user_id, :business_name, :phone, :national_id, :address, CURDATE(), :status)"
                );
                $vendorStmt->execute([
                    'user_id'       => $newUserId,
                    'business_name' => $form['business_name'],
                    'phone'         => $form['phone'],
                    'national_id'   => $form['national_id'],
                    'address'       => $form['address'],
                    'status'        => $form['status'],
                ]);

                $pdo->commit();
                logActivity($pdo, currentUserId(), 'Added new vendor: ' . $form['business_name']);
                redirectTo('vendors.php?success=' . urlencode('Vendor created successfully.'));
            }

        } catch (PDOException $e) {
            $pdo->rollBack();
            $errors[] = 'Something went wrong while saving. Please try again.';
        }
    }
}

$pageTitle  = $isEdit ? 'Edit Vendor' : 'Add Vendor';
$activePage = 'vendors';
require_once __DIR__ . '/../includes/header.php';
?>

    <div class="form-card">
        <a href="vendors.php" class="back-link">&larr; Back to Vendors</a>

        <?php if (!empty($errors)): ?>
            <div class="alert alert-error">
                <ul>
                    <?php foreach ($errors as $error): ?>
                        <li><?= e($error) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <form method="POST" action="vendor_form.php<?= $isEdit ? '?id=' . $vendorId : '' ?>" class="stacked-form" novalidate>
            <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">

            <h3>Login Details</h3>
            <div class="form-row">
                <div class="form-group">
                    <label for="full_name">Full Name</label>
                    <input type="text" id="full_name" name="full_name" value="<?= e($form['full_name']) ?>" required>
                </div>
                <div class="form-group">
                    <label for="email">Email Address</label>
                    <input type="email" id="email" name="email" value="<?= e($form['email']) ?>" required>
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label for="password">
                        Password <?= $isEdit ? '(leave blank to keep current password)' : '' ?>
                    </label>
                    <div class="password-field-wrapper">
                        <input type="password" id="password" name="password" <?= $isEdit ? '' : 'required' ?>>
                        <button type="button" class="password-toggle" onclick="togglePasswordVisibility('password', this)">Show</button>
                    </div>
                </div>
            </div>

            <h3>Business Details</h3>
            <div class="form-row">
                <div class="form-group">
                    <label for="business_name">Business Name</label>
                    <input type="text" id="business_name" name="business_name" value="<?= e($form['business_name']) ?>" required>
                </div>
                <div class="form-group">
                    <label for="phone">Phone Number</label>
                    <input type="text" id="phone" name="phone" value="<?= e($form['phone']) ?>" required>
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label for="national_id">National ID</label>
                    <input type="text" id="national_id" name="national_id" value="<?= e($form['national_id']) ?>">
                </div>
                <div class="form-group">
                    <label for="status">Status</label>
                    <select id="status" name="status">
                        <option value="Active" <?= $form['status'] === 'Active' ? 'selected' : '' ?>>Active</option>
                        <option value="Inactive" <?= $form['status'] === 'Inactive' ? 'selected' : '' ?>>Inactive</option>
                    </select>
                </div>
            </div>
            <div class="form-row">
                <div class="form-group form-group-full">
                    <label for="address">Address</label>
                    <textarea id="address" name="address" rows="3"><?= e($form['address']) ?></textarea>
                </div>
            </div>

            <button type="submit" class="btn btn-primary"><?= $isEdit ? 'Save Changes' : 'Create Vendor' ?></button>
        </form>
    </div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>