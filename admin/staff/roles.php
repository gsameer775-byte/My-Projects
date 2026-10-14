<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/functions.php';
requirePermission($pdo, 'staff', 'view');

$error = '';
$success = '';

// Modules available in the system - permissions are tracked per module
$modules = [
    'inventory'    => 'Inventory',
    'sales'        => 'Sales / POS',
    'sales_orders' => 'Bulk Orders',
    'customers'    => 'Customers',
    'crm'          => 'CRM',
    'staff'        => 'Staff',
    'vendors'      => 'Vendors',
    'expenses'     => 'Expenses',
    'reports'      => 'Reports',
    'settings'     => 'Settings',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requirePermission($pdo, 'staff', 'edit');
    $action = $_POST['action'] ?? '';

    if ($action === 'add_role') {
        $role_name   = sanitize($_POST['role_name'] ?? '');
        $description = sanitize($_POST['description'] ?? '');

        if ($role_name === '') {
            $error = 'Role ka naam zaroori hai.';
        } else {
            $dup = $pdo->prepare("SELECT COUNT(*) AS cnt FROM roles WHERE role_name = :name");
            $dup->execute(['name' => $role_name]);
            if ($dup->fetch()['cnt'] > 0) {
                $error = 'Ye role naam already exist karta hai.';
            } else {
                $stmt = $pdo->prepare("INSERT INTO roles (role_name, description) VALUES (:name, :desc)");
                $stmt->execute(['name' => $role_name, 'desc' => $description ?: null]);
                $success = 'Naya role add ho gaya.';
            }
        }
    } elseif ($action === 'save_permissions') {
        $role_id = (int)($_POST['role_id'] ?? 0);
        if ($role_id === 0) {
            $error = 'Role select karo.';
        } else {
            foreach ($modules as $modKey => $modLabel) {
                $can_view   = isset($_POST['perm'][$modKey]['view']) ? 1 : 0;
                $can_edit   = isset($_POST['perm'][$modKey]['edit']) ? 1 : 0;
                $can_delete = isset($_POST['perm'][$modKey]['delete']) ? 1 : 0;

                $existing = $pdo->prepare(
                    "SELECT permission_id FROM role_permissions WHERE role_id = :role_id AND module_name = :mod"
                );
                $existing->execute(['role_id' => $role_id, 'mod' => $modKey]);
                $row = $existing->fetch();

                if ($row) {
                    $upd = $pdo->prepare(
                        "UPDATE role_permissions SET can_view = :v, can_edit = :e, can_delete = :d
                         WHERE permission_id = :pid"
                    );
                    $upd->execute(['v' => $can_view, 'e' => $can_edit, 'd' => $can_delete, 'pid' => $row['permission_id']]);
                } else {
                    $ins = $pdo->prepare(
                        "INSERT INTO role_permissions (role_id, module_name, can_view, can_edit, can_delete)
                         VALUES (:role_id, :mod, :v, :e, :d)"
                    );
                    $ins->execute(['role_id' => $role_id, 'mod' => $modKey, 'v' => $can_view, 'e' => $can_edit, 'd' => $can_delete]);
                }
            }
            $success = 'Permissions save ho gayi.';
        }
    }
}

$roles = $pdo->query("SELECT role_id, role_name, description FROM roles ORDER BY role_name")->fetchAll();

$selectedRoleId = (int)($_GET['role_id'] ?? ($roles[0]['role_id'] ?? 0));

$currentPermissions = [];
if ($selectedRoleId > 0) {
    $permStmt = $pdo->prepare("SELECT module_name, can_view, can_edit, can_delete FROM role_permissions WHERE role_id = :role_id");
    $permStmt->execute(['role_id' => $selectedRoleId]);
    foreach ($permStmt->fetchAll() as $p) {
        $currentPermissions[$p['module_name']] = $p;
    }
}

include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/sidebar.php';
?>
<main>
    <h1>Roles &amp; Permissions</h1>

    <?php if ($error): ?>
        <div class="alert alert-error"><?php echo sanitize($error); ?></div>
    <?php endif; ?>
    <?php if ($success): ?>
        <div class="alert alert-success"><?php echo sanitize($success); ?></div>
    <?php endif; ?>

    <div class="toolbar">
        <a href="list.php">Back to Staff List</a>
    </div>

    <div class="card">
        <h2>Add New Role</h2>
        <form method="POST" action="" class="inline-form">
            <input type="hidden" name="action" value="add_role">
            <input type="text" name="role_name" placeholder="Role naam (e.g. Cashier)" required>
            <input type="text" name="description" placeholder="Description (optional)">
            <button type="submit">Add Role</button>
        </form>
    </div>

    <div class="card">
        <h2>Existing Roles</h2>
        <table class="data-table">
            <thead>
                <tr><th>Role</th><th>Description</th><th>Action</th></tr>
            </thead>
            <tbody>
                <?php if (empty($roles)): ?>
                    <tr><td colspan="3">Koi role nahi mila.</td></tr>
                <?php else: ?>
                    <?php foreach ($roles as $r): ?>
                    <tr>
                        <td><?php echo sanitize($r['role_name']); ?></td>
                        <td><?php echo sanitize($r['description'] ?? '-'); ?></td>
                        <td><a href="?role_id=<?php echo (int)$r['role_id']; ?>">Edit Permissions</a></td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <?php if ($selectedRoleId > 0): ?>
    <div class="card">
        <h2>Permissions for: <?php
            foreach ($roles as $r) {
                if ((int)$r['role_id'] === $selectedRoleId) {
                    echo sanitize($r['role_name']);
                    break;
                }
            }
        ?></h2>
        <form method="POST" action="">
            <input type="hidden" name="action" value="save_permissions">
            <input type="hidden" name="role_id" value="<?php echo (int)$selectedRoleId; ?>">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Module</th>
                        <th>View</th>
                        <th>Edit</th>
                        <th>Delete</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($modules as $modKey => $modLabel): ?>
                    <?php $p = $currentPermissions[$modKey] ?? ['can_view' => 0, 'can_edit' => 0, 'can_delete' => 0]; ?>
                    <tr>
                        <td><?php echo sanitize($modLabel); ?></td>
                        <td><input type="checkbox" name="perm[<?php echo $modKey; ?>][view]" <?php echo $p['can_view'] ? 'checked' : ''; ?>></td>
                        <td><input type="checkbox" name="perm[<?php echo $modKey; ?>][edit]" <?php echo $p['can_edit'] ? 'checked' : ''; ?>></td>
                        <td><input type="checkbox" name="perm[<?php echo $modKey; ?>][delete]" <?php echo $p['can_delete'] ? 'checked' : ''; ?>></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <br>
            <button type="submit">Save Permissions</button>
        </form>
    </div>
    <?php endif; ?>
</main>
<?php include __DIR__ . '/../../includes/footer.php'; ?>