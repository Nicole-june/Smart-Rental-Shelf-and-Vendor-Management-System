<?php
require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

requireRole('admin');

$errors = [];
$flashSuccess = $_GET['success'] ?? '';
$flashError   = $_GET['error'] ?? '';

$editId = isset($_GET['edit']) ? (int) $_GET['edit'] : 0;
$form = ['category_name' => '', 'description' => ''];

if ($editId > 0) {
    $stmt = $pdo->prepare("SELECT * FROM categories WHERE category_id = :id LIMIT 1");
    $stmt->execute(['id' => $editId]);
    $existing = $stmt->fetch();
    if ($existing) {
        $form['category_name'] = $existing['category_name'];
        $form['description']   = $existing['description'];
    } else {
        $editId = 0;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Your session expired. Please try again.';
    }

    $submittedId = (int) ($_POST['category_id'] ?? 0);
    $form['category_name'] = cleanInput($_POST['category_name'] ?? '');
    $form['description']   = cleanInput($_POST['description'] ?? '');

    if ($form['category_name'] === '') {
        $errors[] = 'Category name is required.';
    }

    if (empty($errors)) {
        try {
            if ($submittedId > 0) {
                $stmt = $pdo->prepare(
                    "UPDATE categories SET category_name = :name, description = :description WHERE category_id = :id"
                );
                $stmt->execute([
                    'name'        => $form['category_name'],
                    'description' => $form['description'],
                    'id'          => $submittedId,
                ]);
                logActivity($pdo, currentUserId(), 'Updated category: ' . $form['category_name']);
                redirectTo('categories.php?success=' . urlencode('Category updated.'));
            } else {
                $stmt = $pdo->prepare(
                    "INSERT INTO categories (category_name, description, created_at) VALUES (:name, :description, NOW())"
                );
                $stmt->execute([
                    'name'        => $form['category_name'],
                    'description' => $form['description'],
                ]);
                logActivity($pdo, currentUserId(), 'Added category: ' . $form['category_name']);
                redirectTo('categories.php?success=' . urlencode('Category added.'));
            }
        } catch (PDOException $e) {
            $errors[] = 'Something went wrong while saving. Please try again.';
        }
    }
}

$categories = $pdo->query(
    "SELECT c.*, (SELECT COUNT(*) FROM products p WHERE p.category_id = c.category_id) AS product_count
     FROM categories c ORDER BY c.category_name ASC"
)->fetchAll();

$pageTitle    = 'Categories';
$activePage   = 'inventory';
$inventoryTab = 'categories';
require_once __DIR__ . '/../includes/header.php';
require __DIR__ . '/../includes/inventory_tabs.php';
?>

    <?php if ($flashSuccess !== ''): ?>
        <div class="alert alert-success"><?= e($flashSuccess) ?></div>
    <?php endif; ?>
    <?php if ($flashError !== ''): ?>
        <div class="alert alert-error"><ul><li><?= e($flashError) ?></li></ul></div>
    <?php endif; ?>
    <?php if (!empty($errors)): ?>
        <div class="alert alert-error"><ul><?php foreach ($errors as $error): ?><li><?= e($error) ?></li><?php endforeach; ?></ul></div>
    <?php endif; ?>

    <div class="split-layout">
        <div class="form-card split-form">
            <h3><?= $editId > 0 ? 'Edit Category' : 'Add Category' ?></h3>
            <form method="POST" action="categories.php" class="stacked-form" novalidate>
                <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                <input type="hidden" name="category_id" value="<?= $editId ?>">

                <div class="form-group">
                    <label for="category_name">Category Name</label>
                    <input type="text" id="category_name" name="category_name" value="<?= e($form['category_name']) ?>" required>
                </div>
                <div class="form-group">
                    <label for="description">Description</label>
                    <textarea id="description" name="description" rows="3"><?= e($form['description']) ?></textarea>
                </div>

                <button type="submit" class="btn btn-primary"><?= $editId > 0 ? 'Save Changes' : 'Add Category' ?></button>
                <?php if ($editId > 0): ?>
                    <a href="categories.php" class="btn btn-link">Cancel</a>
                <?php endif; ?>
            </form>
        </div>

        <div class="table-card split-table">
            <?php if (empty($categories)): ?>
                <p class="chart-empty">No categories yet. Add your first one.</p>
            <?php else: ?>
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Description</th>
                            <th>Products</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($categories as $cat): ?>
                            <tr>
                                <td><?= e($cat['category_name']) ?></td>
                                <td class="text-muted"><?= e($cat['description'] ?: '—') ?></td>
                                <td><?= (int) $cat['product_count'] ?></td>
                                <td class="actions-cell">
                                    <a href="categories.php?edit=<?= (int) $cat['category_id'] ?>" class="action-link">Edit</a>
                                    <form method="POST" action="category_delete.php" class="inline-form"
                                          onsubmit="return confirm('Delete this category?');">
                                        <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                                        <input type="hidden" name="category_id" value="<?= (int) $cat['category_id'] ?>">
                                        <button type="submit" class="action-link action-link-danger">Delete</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>