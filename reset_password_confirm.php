<?php

require_once __DIR__ . '/includes/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

$token = $_GET['token'] ?? $_POST['token'] ?? '';
$errors = [];
$success = false;

if ($token === '') {
    redirectTo('reset_password.php');
}

$tokenStmt = $pdo->prepare(
    "SELECT pr.reset_id, pr.user_id, pr.expires_at, pr.used, u.full_name
     FROM password_resets pr
     INNER JOIN users u ON u.user_id = pr.user_id
     WHERE pr.token = :token
     LIMIT 1"
);
$tokenStmt->execute(['token' => $token]);
$resetRow = $tokenStmt->fetch();

$tokenValid = $resetRow
    && (int) $resetRow['used'] === 0
    && strtotime($resetRow['expires_at']) > time();

if (!$resetRow) {
    $errors[] = 'This reset link is invalid.';
} elseif ((int) $resetRow['used'] === 1) {
    $errors[] = 'This reset link has already been used. Please request a new one.';
} elseif (strtotime($resetRow['expires_at']) <= time()) {
    $errors[] = 'This reset link has expired. Please request a new one.';
}

if ($tokenValid && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Your session expired. Please try again.';
    }

    $password = (string) ($_POST['password'] ?? '');
    $passwordConfirm = (string) ($_POST['password_confirm'] ?? '');

    if (strlen($password) < 6) {
        $errors[] = 'Password must be at least 6 characters.';
    }
    if ($password !== $passwordConfirm) {
        $errors[] = 'Passwords do not match.';
    }

    if (empty($errors)) {
        try {
            $pdo->beginTransaction();

            $updateUser = $pdo->prepare("UPDATE users SET password = :password WHERE user_id = :user_id");
            $updateUser->execute([
                'password' => hashPassword($password),
                'user_id'  => $resetRow['user_id'],
            ]);

            $markUsed = $pdo->prepare("UPDATE password_resets SET used = 1 WHERE reset_id = :id");
            $markUsed->execute(['id' => $resetRow['reset_id']]);

            $pdo->commit();

            logActivity($pdo, (int) $resetRow['user_id'], 'Password reset via email link');

            $success = true;

        } catch (PDOException $e) {
            $pdo->rollBack();
            $errors[] = 'Something went wrong while resetting your password. Please try again.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Set New Password | Smart Rental</title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="auth-body">

    <div class="auth-wrapper">
        <div class="auth-card">
            <div class="auth-brand">
                <h1>Smart Rental</h1>
                <p>Set a New Password</p>
            </div>

            <?php if ($success): ?>
                <div class="alert alert-success">
                    Your password has been reset. You can now log in with your new password.
                </div>
                <a href="login.php" class="btn btn-primary btn-block">Go to Login</a>

            <?php elseif (!$tokenValid): ?>
                <div class="alert alert-error">
                    <ul><?php foreach ($errors as $error): ?><li><?= e($error) ?></li><?php endforeach; ?></ul>
                </div>
                <a href="reset_password.php" class="btn btn-primary btn-block">Request a New Link</a>

            <?php else: ?>

                <?php if (!empty($errors)): ?>
                    <div class="alert alert-error">
                        <ul><?php foreach ($errors as $error): ?><li><?= e($error) ?></li><?php endforeach; ?></ul>
                    </div>
                <?php endif; ?>

                <p class="text-muted" style="margin-bottom: 16px;">Hi <?= e($resetRow['full_name']) ?>, choose a new password below.</p>

                <form method="POST" action="reset_password_confirm.php" class="auth-form" novalidate>
                    <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                    <input type="hidden" name="token" value="<?= e($token) ?>">

                    <div class="form-group">
                        <label for="password">New Password</label>
                        <div class="password-field-wrapper">
                            <input type="password" id="password" name="password" required autofocus>
                            <button type="button" class="password-toggle" onclick="togglePasswordVisibility('password', this)">Show</button>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="password_confirm">Confirm New Password</label>
                        <div class="password-field-wrapper">
                            <input type="password" id="password_confirm" name="password_confirm" required>
                            <button type="button" class="password-toggle" onclick="togglePasswordVisibility('password_confirm', this)">Show</button>
                        </div>
                    </div>

                    <button type="submit" class="btn btn-primary btn-block">Reset Password</button>
                </form>
            <?php endif; ?>
        </div>
    </div>

</body>
</html>