<?php

require_once __DIR__ . '/includes/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

if (isLoggedIn()) {
    redirectToDashboard(currentRole());
}

$errors = [];
$success = false;

$form = [
    'full_name'     => '',
    'email'         => '',
    'business_name' => '',
    'phone'         => '',
    'national_id'   => '',
    'address'       => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Your session expired. Please refresh the page and try again.';
    }

    $form['full_name']     = cleanInput($_POST['full_name'] ?? '');
    $form['email']         = cleanInput($_POST['email'] ?? '');
    $form['business_name'] = cleanInput($_POST['business_name'] ?? '');
    $form['phone']         = cleanInput($_POST['phone'] ?? '');
    $form['national_id']   = cleanInput($_POST['national_id'] ?? '');
    $form['address']       = cleanInput($_POST['address'] ?? '');
    $password              = (string) ($_POST['password'] ?? '');
    $passwordConfirm       = (string) ($_POST['password_confirm'] ?? '');

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
    } elseif (!isValidPhone($form['phone'])) {
        $errors[] = 'Please enter a valid phone number (e.g. 0712345678 or +254712345678).';
    }

    // National ID is optional, but if provided it must be well-formed.
    if ($form['national_id'] !== '' && !isValidNationalId($form['national_id'])) {
        $errors[] = 'National ID must be 6 to 8 digits.';
    }

    if (strlen($password) < 6) {
        $errors[] = 'Password must be at least 6 characters.';
    }
    if ($password !== $passwordConfirm) {
        $errors[] = 'Passwords do not match.';
    }

    if (empty($errors)) {
        $emailStmt = $pdo->prepare("SELECT user_id FROM users WHERE email = :email");
        $emailStmt->execute(['email' => $form['email']]);
        if ($emailStmt->fetch()) {
            $errors[] = 'That email address is already registered.';
        }
    }

    if (empty($errors)) {
        try {
            $pdo->beginTransaction();

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

            // Self-registered vendors start Inactive — an admin must
            // review and activate them from Vendor Management before
            // they're treated as a real, operating vendor.
            $vendorStmt = $pdo->prepare(
                "INSERT INTO vendors (user_id, business_name, phone, national_id, address, date_joined, status)
                 VALUES (:user_id, :business_name, :phone, :national_id, :address, CURDATE(), 'Inactive')"
            );
            $vendorStmt->execute([
                'user_id'       => $newUserId,
                'business_name' => $form['business_name'],
                'phone'         => $form['phone'],
                'national_id'   => $form['national_id'],
                'address'       => $form['address'],
            ]);

            $pdo->commit();

            // No admin is logged in to attribute this to — log it against
            // the new vendor's own user_id instead, so it still shows up
            // in Recent Activity for visibility.
            logActivity($pdo, $newUserId, 'New vendor registration: ' . $form['business_name'] . ' (pending approval)');

            $success = true;

        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $errors[] = 'Something went wrong while registering. Please try again.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Register as Vendor | Smart Rental</title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="auth-body">

    <div class="auth-wrapper" style="max-width: 520px;">
        <div class="auth-card">
            <div class="auth-brand">
                <h1>Smart Rental</h1>
                <p>Vendor Registration</p>
            </div>

            <?php if ($success): ?>
                <div class="alert alert-success">
                    Thanks, <?= e($form['full_name']) ?>! Your registration for
                    <strong><?= e($form['business_name']) ?></strong> has been submitted.
                    An administrator will review and activate your account before you can start renting a shelf.
                </div>
                <a href="login.php" class="btn btn-primary btn-block">Go to Login</a>
            <?php else: ?>

                <?php if (!empty($errors)): ?>
                    <div class="alert alert-error">
                        <ul>
                            <?php foreach ($errors as $error): ?>
                                <li><?= e($error) ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>

                <form method="POST" action="register.php" class="auth-form" novalidate>
                    <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">

                    <div class="form-group">
                        <label for="full_name">Full Name</label>
                        <input type="text" id="full_name" name="full_name" value="<?= e($form['full_name']) ?>" required>
                    </div>

                    <div class="form-group">
                        <label for="email">Email Address</label>
                        <input type="email" id="email" name="email" value="<?= e($form['email']) ?>" required>
                    </div>

                    <div class="form-group">
                        <label for="password">Password</label>
                        <div class="password-field-wrapper">
                            <input type="password" id="password" name="password" required>
                            <button type="button" class="password-toggle" onclick="togglePasswordVisibility('password', this)">Show</button>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="password_confirm">Confirm Password</label>
                        <div class="password-field-wrapper">
                            <input type="password" id="password_confirm" name="password_confirm" required>
                            <button type="button" class="password-toggle" onclick="togglePasswordVisibility('password_confirm', this)">Show</button>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="business_name">Business Name</label>
                        <input type="text" id="business_name" name="business_name" value="<?= e($form['business_name']) ?>" required>
                    </div>

                    <div class="form-group">
                        <label for="phone">Phone Number</label>
                        <input type="text" id="phone" name="phone" value="<?= e($form['phone']) ?>" placeholder="0712345678" required>
                    </div>

                    <div class="form-group">
                        <label for="national_id">National ID</label>
                        <input type="text" id="national_id" name="national_id" value="<?= e($form['national_id']) ?>" inputmode="numeric" maxlength="8" placeholder="6 to 8 digits">
                    </div>

                    <div class="form-group">
                        <label for="address">Address</label>
                        <textarea id="address" name="address" rows="3"><?= e($form['address']) ?></textarea>
                    </div>

                    <button type="submit" class="btn btn-primary btn-block">Register</button>

                    <div class="auth-links">
                        <a href="login.php">Already have an account? Login</a>
                    </div>
                </form>
            <?php endif; ?>
        </div>
    </div>

</body>
</html>