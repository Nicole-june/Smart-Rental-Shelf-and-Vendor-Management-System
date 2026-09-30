<?php

function e(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

/**
 * Trims and strips tags from raw form input. Does NOT replace prepared
 * statements — this is just basic input hygiene before validation.
 */
function cleanInput(?string $value): string
{
    return trim(strip_tags($value ?? ''));
}

/**
 * Generates (or reuses) a CSRF token stored in the session, to be embedded
 * as a hidden field in every form (starting with login.php).
 */
function csrfToken(): string
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Verifies a submitted CSRF token against the one stored in session.
 */
function verifyCsrfToken(?string $submittedToken): bool
{
    return isset($_SESSION['csrf_token'])
        && is_string($submittedToken)
        && hash_equals($_SESSION['csrf_token'], $submittedToken);
}

/**
 * Simple redirect + exit helper.
 */

/**
 * Validates a Kenyan phone number in local (07xx / 01xx) or
 * international (+254xxx / 254xxx) format. Spaces, dashes and a
 * leading "+" are stripped before checking, so "0712 345 678",
 * "0712-345-678", "+254712345678" and "254712345678" are all accepted.
 */
function isValidPhone(string $phone): bool
{
    $digits = preg_replace('/\D+/', '', $phone);
    return (bool) preg_match('/^(?:254|0)?[17]\d{8}$/', $digits);
}

/**
 * Validates a Kenyan National ID: 6 to 8 digits, nothing else.
 * Only enforced when a value is actually supplied — national_id is
 * an optional field on both register.php and admin/vendor_form.php.
 */
function isValidNationalId(string $id): bool
{
    return (bool) preg_match('/^\d{6,8}$/', trim($id));
}
function redirectTo(string $path): void
{
    header('Location: ' . $path);
    exit;
}

/**
 * Records an entry in activity_logs (columns: log_id, user_id, activity,
 * log_date). Called whenever an admin performs a significant action
 * (add/edit/delete vendor, confirm payment, etc.) per the project spec
 * requirement that "every important admin action should be recorded."
 */
function logActivity(PDO $pdo, int $userId, string $activity): void
{
    $stmt = $pdo->prepare(
        'INSERT INTO activity_logs (user_id, activity, log_date) VALUES (:user_id, :activity, NOW())'
    );
    $stmt->execute([
        'user_id'  => $userId,
        'activity' => $activity,
    ]);
}


function generateShelfCode(PDO $pdo, string $buildingName, string $floor): string
{
    $words = preg_split('/\s+/', trim($buildingName));
    $initials = '';
    foreach ($words as $word) {
        if ($word !== '') {
            $initials .= strtoupper($word[0]);
        }
    }
    if ($initials === '') {
        $initials = 'GEN';
    }

    $floorPart = 'F' . preg_replace('/[^A-Za-z0-9]/', '', $floor);

    $countStmt = $pdo->prepare(
        'SELECT COUNT(*) FROM shelves WHERE building_name = :building AND floor = :floor'
    );
    $countStmt->execute(['building' => $buildingName, 'floor' => $floor]);
    $sequence = (int) $countStmt->fetchColumn() + 1;

    do {
        $code = $initials . '-' . $floorPart . '-' . str_pad((string) $sequence, 3, '0', STR_PAD_LEFT);

        $checkStmt = $pdo->prepare('SELECT COUNT(*) FROM shelves WHERE shelf_code = :code');
        $checkStmt->execute(['code' => $code]);
        $exists = (int) $checkStmt->fetchColumn() > 0;

        if ($exists) {
            $sequence++;
        }
    } while ($exists);

    return $code;
}

/**
 * Derives a product's status from its current quantity vs minimum_stock,
 * matching the three allowed values: Available, Low Stock, Out of Stock.
 * Called after every stock-in, sale, or manual quantity edit so the
 * status column always reflects reality.
 */
function computeProductStatus(int $quantity, int $minimumStock): string
{
    if ($quantity <= 0) {
        return 'Out of Stock';
    }
    if ($quantity <= $minimumStock) {
        return 'Low Stock';
    }
    return 'Available';
}

/**
 * Generates a payment code from a payment's own primary key, e.g.
 * payment_id 123 -> "PAY-000123". Called right after insert, using
 * lastInsertId(), then saved back with a quick UPDATE — this guarantees
 * uniqueness for free since it's derived from the auto-increment PK.
 */
function generatePaymentCode(int $paymentId): string
{
    return 'PAY-' . str_pad((string) $paymentId, 6, '0', STR_PAD_LEFT);
}

/**
 * Resolves the vendors.vendor_id row for the currently logged-in vendor
 * user. Every vendor-side page uses this to scope queries to "my own
 * data only" — never trusting a vendor_id passed in the URL/POST body
 * without checking it matches this.
 */
function currentVendorId(PDO $pdo): ?int
{
    $userId = currentUserId();
    if ($userId === null) {
        return null;
    }
    $stmt = $pdo->prepare("SELECT vendor_id FROM vendors WHERE user_id = :user_id LIMIT 1");
    $stmt->execute(['user_id' => $userId]);
    $row = $stmt->fetch();
    return $row ? (int) $row['vendor_id'] : null;
}

/**
 * Resolves the shelf_id of a vendor's current ACTIVE rental. Discovered
 * that products.shelf_id is a required (NOT NULL) foreign key to
 * shelves — not in the originally documented schema — so every product
 * must belong to the vendor's currently rented shelf. Returns null if
 * the vendor has no active rental right now (in which case they can't
 * add products yet — that's a real business rule: you need a shelf
 * before you can stock it).
 */
function getVendorActiveShelfId(PDO $pdo, int $vendorId): ?int
{
    $stmt = $pdo->prepare(
        "SELECT shelf_id FROM rentals WHERE vendor_id = :vendor_id AND rental_status = 'Active' ORDER BY created_at DESC LIMIT 1"
    );
    $stmt->execute(['vendor_id' => $vendorId]);
    $row = $stmt->fetch();
    return $row ? (int) $row['shelf_id'] : null;
}

/**
 * Creates a notification for a vendor. notification_type must be one of
 * the schema's four values: 'Sale', 'Low Stock', 'Rental', 'Payment'.
 * Used both by automatic triggers (stock/sale/rental/payment events) and
 * by the admin's manual "Send Notification" tool.
 */
function createNotification(PDO $pdo, int $vendorId, string $message, string $notificationType): void
{
    $stmt = $pdo->prepare(
        "INSERT INTO notifications (vendor_id, message, notification_type, is_read, created_at)
         VALUES (:vendor_id, :message, :type, 0, NOW())"
    );
    $stmt->execute([
        'vendor_id' => $vendorId,
        'message'   => $message,
        'type'      => $notificationType,
    ]);
}

/**
 * Checks whether the vendor linked to a given user_id has
 * vendors.status = 'Active'. Used by login.php to keep self-registered
 * vendors (who start Inactive, pending admin approval) from logging in
 * until an admin activates them.
 */
function vendorIsActive(PDO $pdo, int $userId): bool
{
    $stmt = $pdo->prepare("SELECT status FROM vendors WHERE user_id = :user_id LIMIT 1");
    $stmt->execute(['user_id' => $userId]);
    $row = $stmt->fetch();
    return $row !== false && strtolower(trim($row['status'])) === 'active';
}

/**
 * Reads the current page number from ?page=N, defaulting to 1 and never
 * going below 1. Used by every paginated admin list page.
 */
function currentPage(): int
{
    return max(1, (int) ($_GET['page'] ?? 1));
}

/**
 * Submits a Stock In request as Pending — used by the VENDOR-side
 * stock_in.php. Does NOT touch products.quantity; that only happens
 * when an admin approves it via admin/inventory_approve.php.
 */
function submitStockInRequest(PDO $pdo, int $productId, int $quantity, ?string $remarks, int $requestedByUserId): void
{
    $stmt = $pdo->prepare(
        "INSERT INTO inventory_transactions (product_id, transaction_type, quantity, transaction_date, remarks, status, requested_by)
         VALUES (:product_id, 'Stock In', :quantity, NOW(), :remarks, 'Pending', :requested_by)"
    );
    $stmt->execute([
        'product_id'   => $productId,
        'quantity'     => $quantity,
        'remarks'      => $remarks,
        'requested_by' => $requestedByUserId,
    ]);
}

/**
 * Submits a Sale request as Pending — used by the VENDOR-side
 * record_sale.php. Does NOT touch products.quantity or insert into
 * `sales` yet; both only happen when an admin approves it.
 */
function submitSaleRequest(PDO $pdo, int $productId, int $quantity, int $requestedByUserId): void
{
    $stmt = $pdo->prepare(
        "INSERT INTO inventory_transactions (product_id, transaction_type, quantity, transaction_date, remarks, status, requested_by)
         VALUES (:product_id, 'Sale', :quantity, NOW(), 'Submitted by vendor, awaiting admin approval', 'Pending', :requested_by)"
    );
    $stmt->execute([
        'product_id'   => $productId,
        'quantity'     => $quantity,
        'requested_by' => $requestedByUserId,
    ]);
}

/**
 * Renders a simple Prev/Next + page-number pagination bar as HTML,
 * preserving every existing query string parameter (search, filters)
 * except page itself. $baseUrl should be the page's own filename, e.g.
 * 'vendors.php'.
 */
function renderPagination(string $baseUrl, int $currentPage, int $totalPages): string
{
    if ($totalPages <= 1) {
        return '';
    }

    $queryParams = $_GET;
    unset($queryParams['page']);

    $buildUrl = function (int $page) use ($baseUrl, $queryParams): string {
        $params = $queryParams;
        $params['page'] = $page;
        return $baseUrl . '?' . http_build_query($params);
    };

    $html = '<div class="pagination">';

    if ($currentPage > 1) {
        $html .= '<a href="' . e($buildUrl($currentPage - 1)) . '" class="page-link">&larr; Prev</a>';
    }

    $html .= '<span class="page-status">Page ' . $currentPage . ' of ' . $totalPages . '</span>';

    if ($currentPage < $totalPages) {
        $html .= '<a href="' . e($buildUrl($currentPage + 1)) . '" class="page-link">Next &rarr;</a>';
    }

    $html .= '</div>';
    return $html;
}

/**
 * Auto-expiry check for rentals. Since this project has no cron/scheduled
 * task setup, this runs "lazily" — called at the top of rentals.php (and
 * the dashboard) on every admin page load. Any rental still marked
 * 'Active' whose end_date has passed gets flipped to 'Expired', and its
 * shelf is freed back to 'Available' (unless the shelf was manually set
 * to Maintenance, which we leave alone).
 */
function processExpiredRentals(PDO $pdo, int $adminUserId): void
{
    $stmt = $pdo->prepare(
        "SELECT r.rental_id, r.shelf_id, r.vendor_id, v.business_name, s.shelf_code
         FROM rentals r
         INNER JOIN vendors v ON v.vendor_id = r.vendor_id
         INNER JOIN shelves s ON s.shelf_id = r.shelf_id
         WHERE r.rental_status = 'Active' AND r.end_date < CURDATE()"
    );
    $stmt->execute();
    $expired = $stmt->fetchAll();

    if (empty($expired)) {
        return;
    }

    foreach ($expired as $rental) {
        $updateRental = $pdo->prepare("UPDATE rentals SET rental_status = 'Expired' WHERE rental_id = :id");
        $updateRental->execute(['id' => $rental['rental_id']]);

        $updateShelf = $pdo->prepare(
            "UPDATE shelves SET status = 'Available' WHERE shelf_id = :id AND status != 'Maintenance'"
        );
        $updateShelf->execute(['id' => $rental['shelf_id']]);

        logActivity(
            $pdo,
            $adminUserId,
            'Rental expired: ' . $rental['shelf_code'] . ' (' . $rental['business_name'] . ')'
        );

        createNotification(
            $pdo,
            $rental['vendor_id'],
            'Your rental for shelf ' . $rental['shelf_code'] . ' has expired.',
            'Rental'
        );
    }
   
}