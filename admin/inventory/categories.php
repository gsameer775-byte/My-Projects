<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/functions.php';
requirePermission($pdo, 'inventory', 'view');

$error = '';
$success = '';

// Handle add category
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add') {
    requirePermission($pdo, 'inventory', 'edit');
    $name = sanitize($_POST['name'] ?? '');
    $description = sanitize($_POST['description'] ?? '');

    if ($name === '') {
        $error = 'Category name is required.';
    } else {
        $stmt = $pdo->prepare("INSERT INTO categories (name, description) VALUES (:name, :description)");
        $stmt->execute(['name' => $name, 'description' => $description]);
        $success = 'Category added successfully.';
    }
}

// Handle delete category
if (isset($_GET['delete'])) {
    requirePermission($pdo, 'inventory', 'delete');
    $catId = (int)$_GET['delete'];
    // Prevent delete if sarees are linked to this category
    $check = $pdo->prepare("SELECT COUNT(*) AS cnt FROM sarees WHERE category_id = :id");
    $check->execute(['id' => $catId]);
    $inUse = $check->fetch()['cnt'];

    if ($inUse > 0) {
        $error = "Cannot delete: $inUse saree(s) are using this category.";
    } else {
        $del = $pdo->prepare("DELETE FROM categories WHERE category_id = :id");
        $del->execute(['id' => $catId]);
        $success = 'Category deleted.';
    }
}

// Fetch all categories with saree count
$categories = $pdo->query(
    "SELECT c.category_id, c.name, c.description,
            (SELECT COUNT(*) FROM sarees s WHERE s.category_id = c.category_id) AS saree_count
     FROM categories c
     ORDER BY c.name ASC"
)->fetchAll();

include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/sidebar.php';
?>
<main>
    <h1>Saree Categories</h1>

    <?php if ($error): ?>
        <div class="alert alert-error"><?php echo sanitize($error); ?></div>
    <?php endif; ?>
    <?php if ($success): ?>
        <div class="alert alert-success"><?php echo sanitize($success); ?></div>
    <?php endif; ?>

    <div class="card">
        <h2>Add New Category</h2>
        <form method="POST" action="" class="inline-form">
            <input type="hidden" name="action" value="add">
            <input type="text" name="name" placeholder="e.g. Banarasi Silk" required>
            <input type="text" name="description" placeholder="Description (optional)">
            <button type="submit">Add Category</button>
        </form>
    </div>

    <div class="card">
        <h2>Existing Categories</h2>
        <table class="data-table">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Description</th>
                    <th>Sarees Using It</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($categories)): ?>
                    <tr><td colspan="4">No categories yet. Add one above.</td></tr>
                <?php else: ?>
                    <?php foreach ($categories as $cat): ?>
                    <tr>
                        <td><?php echo sanitize($cat['name']); ?></td>
                        <td><?php echo sanitize($cat['description'] ?? ''); ?></td>
                        <td><?php echo (int)$cat['saree_count']; ?></td>
                        <td>
                            <a href="?delete=<?php echo (int)$cat['category_id']; ?>"
                               onclick="return confirm('Delete this category?');">Delete</a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</main>
<?php include __DIR__ . '/../../includes/footer.php'; ?>