<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/functions.php';
requirePermission($pdo, 'settings', 'view');
require_once __DIR__ . '/../../cron/backup.php';

// ============================================
// Settings - Backups
// - "Run Backup Now" for on-demand backups (same function the scheduled
//   Task Scheduler job calls)
// - Secure download: files are NOT directly web-accessible (backups/.htaccess
//   blocks that) - this page streams the file only after checking login +
//   permission
// - Delete old backups manually if needed
// ============================================

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requirePermission($pdo, 'settings', 'edit');
    $action = $_POST['action'] ?? '';

    if ($action === 'run_backup') {
        $result = runBackup($pdo);
        if ($result['success']) {
            $success = 'Backup ban gaya: ' . $result['file'] . ' (' . round($result['size'] / 1024, 1) . ' KB).';
            if ($result['deleted_old'] > 0) {
                $success .= ' ' . $result['deleted_old'] . ' purane backups (30 din se zyada purane) delete ho gaye.';
            }
        } else {
            $error = $result['message'];
        }
    }

    if ($action === 'delete_backup') {
        $fileName = basename($_POST['file'] ?? ''); // basename() prevents path traversal
        $filePath = BACKUP_DIR . $fileName;
        if (preg_match('/^backup_[\d_-]+\.sql$/', $fileName) && file_exists($filePath)) {
            unlink($filePath);
            $success = 'Backup delete ho gaya.';
        } else {
            $error = 'File nahi mili.';
        }
    }
}

// ---- Secure download (GET, separate from POST actions above) ----
if (($_GET['download'] ?? '') !== '') {
    requirePermission($pdo, 'settings', 'view');
    $fileName = basename($_GET['download']);
    $filePath = BACKUP_DIR . $fileName;
    if (preg_match('/^backup_[\d_-]+\.sql$/', $fileName) && file_exists($filePath)) {
        header('Content-Type: application/sql');
        header('Content-Disposition: attachment; filename="' . $fileName . '"');
        header('Content-Length: ' . filesize($filePath));
        readfile($filePath);
        exit;
    } else {
        redirect('admin/settings/backups.php');
    }
}

$backupFiles = [];
if (is_dir(BACKUP_DIR)) {
    foreach (glob(BACKUP_DIR . 'backup_*.sql') as $f) {
        $backupFiles[] = ['name' => basename($f), 'size' => filesize($f), 'time' => filemtime($f)];
    }
    usort($backupFiles, fn($a, $b) => $b['time'] - $a['time']);
}

include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/sidebar.php';
?>
<main>
    <h1>Settings - Backups</h1>

    <div class="toolbar">
        <a href="general.php">General</a>
        <a href="users.php">Users</a>
        <a href="audit-log.php">Audit Log</a>
        <a href="backups.php"><strong>Backups</strong></a>
        <a href="sessions.php">Sessions</a>
        <a href="bank-reconciliation.php">Bank Reconciliation</a>
    </div>

    <?php if ($error): ?><div class="alert alert-error"><?php echo sanitize($error); ?></div><?php endif; ?>
    <?php if ($success): ?><div class="alert alert-success"><?php echo sanitize($success); ?></div><?php endif; ?>

    <div class="card">
        <h2>Automatic Backups</h2>
        <p style="margin-bottom:10px; font-size:13px; color:#666;">
            Ye poore database (sab tables, sab data) ka snapshot banata hai. Daily automatic chalane ke liye Windows Task Scheduler me ye set karo:
        </p>
        <code style="display:block; background:#f5f0e6; padding:10px; border-radius:6px; font-size:12px; margin-bottom:10px;">
            C:\xampp\php\php.exe C:\xampp\htdocs\hikayat_banaras\cron\backup.php
        </code>
        <p style="font-size:12px; color:#666; margin-bottom:14px;">
            30 din se purane backups automatically delete ho jaate hain, taaki disk bharey nahi.
        </p>
        <form method="POST" action="">
            <input type="hidden" name="action" value="run_backup">
            <button type="submit">Run Backup Now</button>
        </form>
    </div>

    <div class="card">
        <h2>Existing Backups (<?php echo count($backupFiles); ?>)</h2>
        <table class="data-table">
            <thead><tr><th>File</th><th>Size</th><th>Created</th><th>Action</th></tr></thead>
            <tbody>
                <?php if (empty($backupFiles)): ?>
                    <tr><td colspan="4">Koi backup nahi mila. Upar se "Run Backup Now" karo.</td></tr>
                <?php else: ?>
                    <?php foreach ($backupFiles as $b): ?>
                    <tr>
                        <td><?php echo sanitize($b['name']); ?></td>
                        <td><?php echo round($b['size'] / 1024, 1); ?> KB</td>
                        <td><?php echo date('d M Y, h:i A', $b['time']); ?></td>
                        <td>
                            <a href="backups.php?download=<?php echo urlencode($b['name']); ?>">Download</a>
                            <form method="POST" action="" style="display:inline;" onsubmit="return confirm('Ye backup delete karna hai?');">
                                <input type="hidden" name="action" value="delete_backup">
                                <input type="hidden" name="file" value="<?php echo sanitize($b['name']); ?>">
                                <button type="submit" style="background:#b71c1c;">Delete</button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</main>
<?php include __DIR__ . '/../../includes/footer.php'; ?>