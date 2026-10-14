<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/functions.php';
requirePermission($pdo, 'settings', 'view');

// ============================================
// Settings - Active Sessions / Devices
// - Shows every device currently logged in across ALL staff (admin visibility)
// - "Logout This Device" flips login_sessions.is_active to 0 - the next time
//   that device loads any page, validateSession() in functions.php catches
//   it and force-logs them out
// ============================================

$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'logout_session') {
    requirePermission($pdo, 'settings', 'edit');
    $sessionId = (int)($_POST['session_id'] ?? 0);

    $pdo->prepare("UPDATE login_sessions SET is_active = 0 WHERE session_id = :id")->execute(['id' => $sessionId]);

    $pdo->prepare("INSERT INTO audit_log (staff_id, action, module_name, record_id, description, ip_address) VALUES (:sid,'update','login_sessions',:rid,'Force-logged-out a device',:ip)")
        ->execute(['sid' => $_SESSION['staff_id'], 'rid' => $sessionId, 'ip' => $_SERVER['REMOTE_ADDR'] ?? null]);

    $success = 'Device logout ho gayi.';
}

$sessions = $pdo->query(
    "SELECT ls.*, s.name AS staff_name
     FROM login_sessions ls
     JOIN staff s ON s.staff_id = ls.staff_id
     WHERE ls.is_active = 1
     ORDER BY ls.last_active_at DESC"
)->fetchAll();

$currentToken = $_SESSION['session_token'] ?? '';

include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/sidebar.php';
?>
<main>
    <h1>Settings - Active Sessions</h1>

    <div class="toolbar">
        <a href="general.php">General</a>
        <a href="users.php">Users</a>
        <a href="audit-log.php">Audit Log</a>
        <a href="backups.php">Backups</a>
        <a href="sessions.php"><strong>Sessions</strong></a>
        <a href="bank-reconciliation.php">Bank Reconciliation</a>
    </div>

    <?php if ($success): ?><div class="alert alert-success"><?php echo sanitize($success); ?></div><?php endif; ?>

    <div class="card">
        <h2>Currently Logged-In Devices (<?php echo count($sessions); ?>)</h2>
        <p style="font-size:13px; color:#666; margin-bottom:12px;">
            Agar koi device pehchana nahi ja raha, ya kisi staff ka phone kho gaya hai, "Logout" dabake use turant nikaal do.
        </p>
        <table class="data-table">
            <thead><tr><th>Staff</th><th>Device</th><th>IP Address</th><th>Login Time</th><th>Last Active</th><th></th></tr></thead>
            <tbody>
                <?php if (empty($sessions)): ?>
                    <tr><td colspan="6">Koi active session nahi mila.</td></tr>
                <?php else: ?>
                    <?php foreach ($sessions as $s): $isThisDevice = $s['session_token'] === $currentToken; ?>
                    <tr>
                        <td><?php echo sanitize($s['staff_name']); ?></td>
                        <td><?php echo sanitize($s['device_label'] ?? 'Unknown'); ?> <?php if ($isThisDevice): ?><span class="badge-warning">Ye Device</span><?php endif; ?></td>
                        <td><?php echo sanitize($s['ip_address'] ?? '-'); ?></td>
                        <td><?php echo sanitize($s['login_at']); ?></td>
                        <td><?php echo sanitize($s['last_active_at']); ?></td>
                        <td>
                            <?php if (!$isThisDevice): ?>
                                <form method="POST" action="" onsubmit="return confirm('Ye device logout karni hai?');">
                                    <input type="hidden" name="action" value="logout_session">
                                    <input type="hidden" name="session_id" value="<?php echo (int)$s['session_id']; ?>">
                                    <button type="submit" style="background:#b71c1c;">Logout</button>
                                </form>
                            <?php else: ?>
                                <em>Aap yahi ho</em>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</main>
<?php include __DIR__ . '/../../includes/footer.php'; ?>