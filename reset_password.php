<?php


require_once __DIR__ . '/includes/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/mailer.php';

if (isLoggedIn()) {
    redirectToDashboard(currentRole());
}

$errors = [];
$submitted = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Your session expired. Please refresh and try again.';
    }

    $email = cleanInput($_POST['email'] ?? '');

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Please enter a valid email address.';
    }

    if (empty($errors)) {
        $userStmt = $pdo->prepare("SELECT user_id, full_name FROM users WHERE email = :email LIMIT 1");
        $userStmt->execute(['email' => $email]);
        $user = $userStmt->fetch();

        // Only actually do anything if the user exists — but we show the
        // same success message either way (see docblock above).
        if ($user) {
            $token = bin2hex(random_bytes(32));
            $expiresAt = date('Y-m-d H:i:s', strtotime('+30 minutes'));

            $insertStmt = $pdo->prepare(
                "INSERT INTO password_resets (user_id, token, expires_at, used)
                 VALUES (:user_id, :token, :expires_at, 0)"
            );
            $insertStmt->execute([
                'user_id'    => $user['user_id'],
                'token'      => $token,
                'expires_at' => $expiresAt,
            ]);

            $resetLink = 'http://' . $_SERVER['HTTP_HOST'] . '/smart-rental-php/reset_password_confirm.php?token=' . $token;
            sendPasswordResetEmail($email, $user['full_name'], $resetLink);
        }

        $submitted = true;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forgot Password | Smart Rental</title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="auth-body">

    <div class="auth-wrapper">
        <div class="auth-card">
            <div class="auth-brand">
                <h1>Smart Rental</h1>
                <p>Reset Your Password</p>
            </div>

            <?php if ($submitted): ?>
                <div class="alert alert-success">
                    If that email address is registered, a password reset link has been sent.
                    It's valid for 30 minutes.
                </div>
                <a href="login.php" class="btn btn-primary btn-block">Back to Login</a>
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

                <form method="POST" action="reset_password.php" class="auth-form" novalidate>
                    <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">

                    <div class="form-group">
                        <label for="email">Email Address</label>
                        <input type="email" id="email" name="email" placeholder="you@example.com" required autofocus>
                    </div>

                    <button type="submit" class="btn btn-primary btn-block">Send Reset Link</button>

                    <div class="auth-links">
                        <a href="login.php">Back to Login</a>
                    </div>
                </form>
            <?php endif; ?>
        </div>
    </div>

</body>
</html>