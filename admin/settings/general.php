<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/functions.php';
requirePermission($pdo, 'settings', 'view');

// ============================================
// Settings - General
// - Shop details used on invoices (name, address, GSTIN)
// - Invoice numbering prefix
// - Default tax percentage (used as default in POS)
// Stored as key-value pairs in system_settings table
// ============================================

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requirePermission($pdo, 'settings', 'edit');
    $updates = [
        'shop_name' => sanitize($_POST['shop_name'] ?? ''),
        'shop_address' => sanitize($_POST['shop_address'] ?? ''),
        'shop_gstin' => sanitize($_POST['shop_gstin'] ?? ''),
        'shop_upi_id' => sanitize($_POST['shop_upi_id'] ?? ''),
        'invoice_prefix' => sanitize($_POST['invoice_prefix'] ?? 'HB-'),
        'default_tax_percent' => (string)(float)($_POST['default_tax_percent'] ?? 0),
    ];

    try {
        $pdo->beginTransaction();
        $upsert = $pdo->prepare(
            "INSERT INTO system_settings (setting_key, setting_value)
             VALUES (:key, :value)
             ON DUPLICATE KEY UPDATE setting_value = :value2"
        );
        foreach ($updates as $key => $value) {
            $upsert->execute(['key' => $key, 'value' => $value, 'value2' => $value]);
        }

        $pdo->prepare(
            "INSERT INTO audit_log (staff_id, action, module_name, description, ip_address)
             VALUES (:sid, 'update', 'settings', 'General shop settings updated', :ip)"
        )->execute(['sid' => $_SESSION['staff_id'], 'ip' => $_SERVER['REMOTE_ADDR'] ?? null]);

        $pdo->commit();
        $success = 'Settings update ho gayi.';
    } catch (Exception $e) {
        $pdo->rollBack();
        $error = 'Kuch galat ho gaya: ' . $e->getMessage();
    }
}

$settingsRows = $pdo->query("SELECT setting_key, setting_value FROM system_settings")->fetchAll(PDO::FETCH_KEY_PAIR);

include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/sidebar.php';
?>
<main>
    <h1>General Settings</h1>

    <div class="toolbar">
        <a href="general.php"><strong>General</strong></a>
        <a href="users.php">Users</a>
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

    <div class="card">
        <h2>Shop Details (dikhega invoices pe)</h2>
        <form method="POST" action="" class="form-grid">
            <div>
                <label>Shop Name</label>
                <input type="text" name="shop_name" value="<?php echo sanitize($settingsRows['shop_name'] ?? ''); ?>">
            </div>
            <div>
                <label>GSTIN</label>
                <input type="text" name="shop_gstin" value="<?php echo sanitize($settingsRows['shop_gstin'] ?? ''); ?>">
            </div>
            <div>
                <label>UPI ID (invoice pe QR code ke liye)</label>
                <input type="text" name="shop_upi_id" value="<?php echo sanitize($settingsRows['shop_upi_id'] ?? ''); ?>" placeholder="e.g. shopname@paytm">
                <small>Khaali chhodo agar QR code invoice pe nahi chahiye.</small>
            </div>
            <div style="grid-column: span 2;">
                <label>Shop Address</label>
                <input type="text" name="shop_address" value="<?php echo sanitize($settingsRows['shop_address'] ?? ''); ?>">
            </div>
            <div>
                <label>Invoice Number Prefix</label>
                <input type="text" name="invoice_prefix" value="<?php echo sanitize($settingsRows['invoice_prefix'] ?? 'HB-'); ?>">
                <small>e.g. HB- se invoice HB-20260827-1234 banega</small>
            </div>
            <div>
                <label>Default Tax % (POS me default)</label>
                <input type="number" step="0.01" name="default_tax_percent" value="<?php echo sanitize($settingsRows['default_tax_percent'] ?? '0'); ?>">
            </div>
            <div>
                <button type="submit">Save Settings</button>
            </div>
        </form>
    </div>
</main>
<?php include __DIR__ . '/../../includes/footer.php'; ?>