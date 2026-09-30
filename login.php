<?php

require_once __DIR__ . '/includes/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

// If already logged in, skip straight to the correct dashboard.
if (isLoggedIn()) {
    redirectToDashboard(currentRole());
}

$errors = [];
$emailValue = '';

if (($_GET['timeout'] ?? '') === '1') {
    $errors[] = 'Your session expired due to inactivity. Please log in again.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // --- CSRF check ---
    if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Your session expired. Please try again.';
    }

    $email    = cleanInput($_POST['email'] ?? '');
    $password = (string) ($_POST['password'] ?? '');
    $emailValue = $email;

    // --- Basic validation ---
    if ($email === '' || $password === '') {
        $errors[] = 'Please enter both email and password.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Please enter a valid email address.';
    }

    // --- Look up user and verify password ---
    if (empty($errors)) {
        $stmt = $pdo->prepare(
            'SELECT user_id, full_name, email, password, role, status
             FROM users
             WHERE email = :email
             LIMIT 1'
        );
        $stmt->execute(['email' => $email]);
        $user = $stmt->fetch();

        if (!$user || !verifyPassword($password, $user['password'])) {
            $errors[] = 'Incorrect email or password.';
        } elseif ($user['status'] !== 'active') {
            // Accounts can be disabled by the admin; block login if so.
            $errors[] = 'Your account is inactive. Please contact the administrator.';
        } elseif ($user['role'] === 'vendor' && !vendorIsActive($pdo, (int) $user['user_id'])) {
            // Self-registered vendors start as vendors.status = 'Inactive'
            // (pending admin approval). Their users.status is 'active' —
            // that column controls the login credential itself — but this
            // extra check keeps them out until the admin actually
            // approves the vendor profile via Vendor Management.
            $errors[] = 'Your vendor registration is still pending admin approval. Please check back soon.';
        } else {
            // Success — start session and redirect by role.
            loginUser($user);
            redirectToDashboard($user['role']);
        }
    }
}

$csrfToken = csrfToken();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login | Smart Rental Shelf & Vendor Management System</title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="auth-body">

    <div class="auth-wrapper">
        <div class="auth-card">
            <div class="auth-brand">
                <h1>Smart Rental</h1>
                <p>Shelf & Vendor Management System</p>
            </div>

            <?php if (!empty($errors)): ?>
                <div class="alert alert-error">
                    <ul>
                        <?php foreach ($errors as $error): ?>
                            <li><?= e($error) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <form method="POST" action="login.php" class="auth-form" novalidate>
                <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">

                <div class="form-group">
                    <label for="email">Email Address</label>
                    <input
                        type="email"
                        id="email"
                        name="email"
                        value="<?= e($emailValue) ?>"
                        placeholder="you@example.com"
                        required
                        autofocus
                    >
                </div>

                <div class="form-group">
                    <label for="password">Password</label>
                    <div class="password-field-wrapper">
                        <input
                            type="password"
                            id="password"
                            name="password"
                            placeholder="Enter your password"
                            required
                        >
                        <button type="button" class="password-toggle" onclick="togglePasswordVisibility('password', this)">Show</button>
                    </div>
                </div>

                <button type="submit" class="btn btn-primary btn-block">Login</button>

                <div class="auth-links">
                    <a href="reset_password.php">Forgot password?</a>
                    <span>&middot;</span>
                    <a href="register.php">Register as vendor</a>
                </div>
            </form>
        </div>
    </div>

</body>
</html>