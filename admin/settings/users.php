<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/functions.php';
requirePermission($pdo, 'settings', 'view');

// ============================================
// Settings - Users
// - Lists all staff login accounts (username, role, active status)
// - Password reset (Staff module's add/edit handles profile info;
//   this page focuses specifically on login/security-related actions)
// - New user creation redirects to Staff -> Add (single source of truth
//   for staff records, avoids duplicate creation logic)
// ============================================

$error = '';
$success = '';

// ---- Handle Password Reset ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'reset_password') {
    requirePermission($pdo, 'settings', 'edit');
    $staffId = (int)($_POST['staff_id'] ?? 0);
    $newPassword = $_POST['new_password'] ?? '';

    if ($staffId <= 0 || strlen($newPassword) < 4) {
        $error = 'Valid staff select karo aur password kam se kam 4 characters ka rakho.';
    } else {
        $hash = password_hash($newPassword, PASSWORD_DEFAULT);
        $pdo->prepare("UPDATE staff SET password_hash = :hash WHERE staff_id = :id")
            ->execute(['hash' => $hash, 'id' => $staffId]);

        $pdo->prepare(
            "INSERT INTO audit_log (staff_id, action, module_name, record_id, description, ip_address)
             VALUES (:sid, 'update', 'users', :rid, 'Password reset', :ip)"
        )->execute([
            'sid' => $_SESSION['staff_id'],
            'rid' => $staffId,
            'ip' => $_SERVER['REMOTE_ADDR'] ?? null,
        ]);

        $success = 'Password reset ho gaya.';
    }
}

// ---- Handle Toggle Active/Inactive ----
if (isset($_GET['toggle'])) {
    requirePermission($pdo, 'settings', 'edit');
    $toggleId = (int)$_GET['toggle'];
    if ($toggleId === (int)$_SESSION['staff_id']) {
        $error = 'Aap apna khud ka account deactivate nahi kar sakte.';
    } else {
        $pdo->prepare("UPDATE staff SET is_active = NOT is_active WHERE staff_id = :id")
            ->execute(['id' => $toggleId]);
        $success = 'Status update ho gaya.';
    }
}

$users = $pdo->query(
    "SELECT st.staff_id, st.name, st.username, st.phone, st.is_active, r.role_name
     FROM staff st
     LEFT JOIN roles r ON st.role_id = r.role_id
     ORDER BY st.name ASC"
)->fetchAll();

include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/sidebar.php';
?>
<main>
    <h1>User Accounts</h1>

    <div class="toolbar">
        <a href="general.php">General</a>
        <a href="users.php"><strong>Users</strong></a>
        <a href="audit-log.php">Audit Log</a>
        <a href="backups.php">Backups</a>
        <a href="sessions.php">Sessions</a>
        <a href="bank-reconciliation.php">Bank Reconciliation</a>
    </div>

    <?php if ($error): ?>
        <div class="alert alert-error"><?php echo sanitize($error); ?></div>
    <?php endif; ?>
    <?php if ($success): ?>
        <div class="alert alert-success"><?php echo sanitize($success); ?></div>
    <?php endif; ?>

    <div class="toolbar">
        <a href="<?php echo BASE_URL; ?>admin/staff/add.php" class="btn-primary">+ Add New Staff/User</a>
    </div>

    <div class="card">
        <h2>All Login Accounts</h2>
        <table class="data-table">
            <thead>
                <tr><th>Name</th><th>Username</th><th>Phone</th><th>Role</th><th>Status</th><th>Action</th></tr>
            </thead>
            <tbody>
                <?php if (empty($users)): ?>
                    <tr><td colspan="6">Koi user nahi mila.</td></tr>
                <?php else: ?>
                    <?php foreach ($users as $u): ?>
                    <tr>
                        <td><?php echo sanitize($u['name']); ?></td>
                        <td><?php echo sanitize($u['username']); ?></td>
                        <td><?php echo sanitize($u['phone']); ?></td>
                        <td><?php echo sanitize($u['role_name'] ?? '-'); ?></td>
                        <td><?php echo $u['is_active'] ? 'Active' : 'Inactive'; ?></td>
                        <td>
                            <?php if ((int)$u['staff_id'] !== (int)$_SESSION['staff_id']): ?>
                                <a href="?toggle=<?php echo (int)$u['staff_id']; ?>"
                                   onclick="return confirm('Status badalna hai?');">
                                    <?php echo $u['is_active'] ? 'Deactivate' : 'Activate'; ?>
                                </a>
                            <?php else: ?>
                                <small>(Aap)</small>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <div class="card">
        <h2>Reset Password</h2>
        <form method="POST" action="" class="form-grid">
            <input type="hidden" name="action" value="reset_password">
            <div>
                <label>Select User *</label>
                <select name="staff_id" required>
                    <option value="">-- Select --</option>
                    <?php foreach ($users as $u): ?>
                        <option value="<?php echo (int)$u['staff_id']; ?>">
                            <?php echo sanitize($u['name']) . ' (' . sanitize($u['username']) . ')'; ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label>New Password *</label>
                <input type="password" name="new_password" required minlength="4">
            </div>
            <div>
                <button type="submit">Reset Password</button>
            </div>
        </form>
    </div>
</main>
<?php include __DIR__ . '/../../includes/footer.php'; ?>