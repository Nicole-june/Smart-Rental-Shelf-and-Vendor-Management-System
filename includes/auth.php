<?php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/**
 * How long a session stays valid with no activity. After this many
 * seconds with no page load, the next request logs the user out
 * automatically — standard practice for a system handling payments and
 * business data. 30 minutes.
 */
define('SESSION_TIMEOUT_SECONDS', 1800);

/**
 * Returns true if a user is currently logged in AND their session
 * hasn't timed out from inactivity. Also refreshes the activity
 * timestamp on every call, so any page load resets the 30-minute clock.
 */
function isLoggedIn(): bool
{
    if (!isset($_SESSION['user_id']) || !isset($_SESSION['role'])) {
        return false;
    }

    if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity']) > SESSION_TIMEOUT_SECONDS) {
        logoutUser();
        return false;
    }

    $_SESSION['last_activity'] = time();
    return true;
}

/**
 * Returns the logged-in user's role ('admin' or 'vendor'), or null if
 * nobody is logged in.
 */
function currentRole(): ?string
{
    return $_SESSION['role'] ?? null;
}

/**
 * Returns the logged-in user's user_id, or null if nobody is logged in.
 */
function currentUserId(): ?int
{
    return isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
}

/**
 * Redirects to login.php if nobody is logged in.
 * Call this at the top of ANY page that requires authentication,
 * regardless of role.
 */
function requireLogin(): void
{
    if (!isLoggedIn()) {
        header('Location: /smart-rental-php/login.php?timeout=1');
        exit;
    }
}

/**
 * Redirects to login.php if nobody is logged in, OR redirects to that
 * user's own dashboard if they are logged in but with the WRONG role.
 * This is what admin/*.php and vendor/*.php pages should call.
 *
 * @param string $requiredRole 'admin' or 'vendor'
 */
function requireRole(string $requiredRole): void
{
    requireLogin();

    if (currentRole() !== $requiredRole) {
        // Logged in, but trying to access a page that isn't theirs.
        // Send them back to their own dashboard instead of an error page.
        redirectToDashboard(currentRole());
        exit;
    }
}

/**
 * Sends a logged-in user to the correct dashboard based on role.
 * Used right after login, and as a safety redirect in requireRole().
 */
function redirectToDashboard(?string $role): void
{
    if ($role === 'admin') {
        header('Location: /smart-rental-php/admin/dashboard.php');
    } elseif ($role === 'vendor') {
        header('Location: /smart-rental-php/vendor/dashboard.php');
    } else {
        header('Location: /smart-rental-php/login.php');
    }
    exit;
}

/**
 * Hashes a plain-text password for storage in users.password.
 * Uses PHP's built-in bcrypt-based hashing (safe, salted automatically).
 */
function hashPassword(string $plainPassword): string
{
    return password_hash($plainPassword, PASSWORD_DEFAULT);
}

/**
 * Verifies a plain-text password against a stored hash from users.password.
 */
function verifyPassword(string $plainPassword, string $storedHash): bool
{
    return password_verify($plainPassword, $storedHash);
}

/**
 * Logs a user in by populating the session. Called only after credentials
 * have already been verified against the database.
 *
 * @param array $user Associative array row from the users table
 *                     (must contain user_id, full_name, email, role).
 */
function loginUser(array $user): void
{
    // Regenerate session ID on login to prevent session fixation attacks.
    session_regenerate_id(true);

    $_SESSION['user_id']   = $user['user_id'];
    $_SESSION['full_name'] = $user['full_name'];
    $_SESSION['email']     = $user['email'];
    $_SESSION['role']      = $user['role'];
    $_SESSION['last_activity'] = time();
}

/**
 * Destroys the current session completely (used by logout.php).
 */
function logoutUser(): void
{
    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params['path'],
            $params['domain'],
            $params['secure'],
            $params['httponly']
        );
    }

    session_destroy();
}