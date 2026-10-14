<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/functions.php';
requirePermission($pdo, 'crm', 'view');

// ============================================
// CRM - Loyalty Settings
// - Configure earn/redeem rates
// - Live liability overview (points outstanding = real money owed to customers)
// - Manual point adjustment (with audit trail)
// - Point expiry policy (old, unused points auto-expire via cron/expire-loyalty-points.php)
// ============================================

$error = '';
$success = '';

// Ensure exactly one settings row always exists
$settings = $pdo->query("SELECT * FROM loyalty_settings LIMIT 1")->fetch();
if (!$settings) {
    $pdo->exec("INSERT INTO loyalty_settings (points_per_rupee, redeem_value_per_point, min_points_to_redeem) VALUES (0.01, 1.00, 100)");
    $settings = $pdo->query("SELECT * FROM loyalty_settings LIMIT 1")->fetch();
}

// Expiry policy stored in generic system_settings (key/value) - no schema change needed
// getSystemSetting() / setSystemSetting() are now shared helpers in includes/functions.php

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requirePermission($pdo, 'crm', 'edit');
    $action = $_POST['action'] ?? '';

    if ($action === 'save_settings') {
        $ppr    = (float)($_POST['points_per_rupee'] ?? 0);
        $rvp    = (float)($_POST['redeem_value_per_point'] ?? 0);
        $minPts = (int)($_POST['min_points_to_redeem'] ?? 0);
        $expiryDays = (int)($_POST['expiry_days'] ?? 0);

        if ($ppr < 0 || $rvp < 0 || $minPts < 0) {
            $error = 'Values negative nahi ho sakti.';
        } else {
            $stmt = $pdo->prepare(
                "UPDATE loyalty_settings SET points_per_rupee = :ppr, redeem_value_per_point = :rvp, min_points_to_redeem = :min
                 WHERE setting_id = :id"
            );
            $stmt->execute(['ppr' => $ppr, 'rvp' => $rvp, 'min' => $minPts, 'id' => $settings['setting_id']]);
            setSystemSetting($pdo, 'loyalty_expiry_days', $expiryDays, 'Days after which unused loyalty points auto-expire (0 = never)');

            $log = $pdo->prepare("INSERT INTO audit_log (staff_id, action, module_name, description, ip_address) VALUES (:sid,'update','loyalty_settings','Loyalty settings updated', :ip)");
            $log->execute(['sid' => $_SESSION['staff_id'], 'ip' => $_SERVER['REMOTE_ADDR'] ?? null]);

            $success = 'Settings save ho gayi.';
            $settings = $pdo->query("SELECT * FROM loyalty_settings LIMIT 1")->fetch();
        }
    }

    if ($action === 'adjust_points') {
        $customerId = (int)($_POST['customer_id'] ?? 0);
        $points     = (int)($_POST['points'] ?? 0);
        $reason     = trim($_POST['reason'] ?? '');

        if ($customerId <= 0 || $points === 0) {
            $error = 'Customer aur points (non-zero) select karo.';
        } elseif ($reason === '') {
            $error = 'Adjustment ka reason likhna zaroori hai (audit ke liye).';
        } else {
            $custStmt = $pdo->prepare("SELECT loyalty_points, name FROM customers WHERE customer_id = :id");
            $custStmt->execute(['id' => $customerId]);
            $cust = $custStmt->fetch();

            if (!$cust) {
                $error = 'Customer nahi mila.';
            } elseif ($cust['loyalty_points'] + $points < 0) {
                $error = 'Itne points customer ke paas hai hi nahi (current: ' . (int)$cust['loyalty_points'] . '). Negative balance nahi ho sakta.';
            } else {
                $pdo->beginTransaction();
                try {
                    $newBalance = (int)$cust['loyalty_points'] + $points;
                    $pdo->prepare("UPDATE customers SET loyalty_points = :p WHERE customer_id = :id")
                        ->execute(['p' => $newBalance, 'id' => $customerId]);

                    $pdo->prepare(
                        "INSERT INTO loyalty_transactions (customer_id, sale_id, txn_type, points, balance_after)
                         VALUES (:cid, NULL, 'adjusted', :pts, :bal)"
                    )->execute(['cid' => $customerId, 'pts' => $points, 'bal' => $newBalance]);

                    $pdo->prepare(
                        "INSERT INTO audit_log (staff_id, action, module_name, record_id, description, ip_address)
                         VALUES (:sid, 'adjust_points', 'loyalty', :rid, :desc, :ip)"
                    )->execute([
                        'sid' => $_SESSION['staff_id'],
                        'rid' => $customerId,
                        'desc' => 'Manual adjustment: ' . ($points > 0 ? '+' : '') . $points . ' pts - ' . $reason,
                        'ip' => $_SERVER['REMOTE_ADDR'] ?? null,
                    ]);

                    $pdo->commit();
                    $success = sanitize($cust['name']) . ' ke liye ' . ($points > 0 ? '+' : '') . $points . ' points adjust ho gaye. Naya balance: ' . $newBalance;
                } catch (Exception $e) {
                    $pdo->rollBack();
                    $error = 'Adjustment fail ho gaya. Dobara try karo.';
                }
            }
        }
    }
}

$expiryDays = (int)getSystemSetting($pdo, 'loyalty_expiry_days', 0);

// ---- Liability overview ----
$totalOutstandingPoints = (int)$pdo->query("SELECT COALESCE(SUM(loyalty_points),0) FROM customers")->fetchColumn();
$totalOutstandingValue  = $totalOutstandingPoints * (float)$settings['redeem_value_per_point'];

$earnedThisMonth = (int)$pdo->query(
    "SELECT COALESCE(SUM(points),0) FROM loyalty_transactions
     WHERE txn_type = 'earned' AND MONTH(created_at) = MONTH(CURDATE()) AND YEAR(created_at) = YEAR(CURDATE())"
)->fetchColumn();

$redeemedThisMonth = (int)$pdo->query(
    "SELECT COALESCE(SUM(ABS(points)),0) FROM loyalty_transactions
     WHERE txn_type = 'redeemed' AND MONTH(created_at) = MONTH(CURDATE()) AND YEAR(created_at) = YEAR(CURDATE())"
)->fetchColumn();

$eligibleForRedeem = (int)$pdo->query(
    "SELECT COUNT(*) FROM customers WHERE loyalty_points >= " . (int)$settings['min_points_to_redeem']
)->fetchColumn();

// Recent manual adjustments (for admin visibility)
$recentAdjustments = $pdo->query(
    "SELECT lt.*, c.name FROM loyalty_transactions lt
     JOIN customers c ON c.customer_id = lt.customer_id
     WHERE lt.txn_type = 'adjusted'
     ORDER BY lt.created_at DESC LIMIT 10"
)->fetchAll();

include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/sidebar.php';
?>
<main>
    <h1>CRM - Loyalty Program</h1>

    <?php if ($error): ?><div class="alert alert-error"><?php echo sanitize($error); ?></div><?php endif; ?>
    <?php if ($success): ?><div class="alert alert-success"><?php echo sanitize($success); ?></div><?php endif; ?>

    <div class="stat-grid">
        <div class="stat-box">
            <span class="stat-label">Total Points Outstanding</span>
            <span class="stat-value"><?php echo number_format($totalOutstandingPoints); ?></span>
        </div>
        <div class="stat-box">
            <span class="stat-label">Liability (₹ value)</span>
            <span class="stat-value">₹<?php echo number_format($totalOutstandingValue, 2); ?></span>
        </div>
        <div class="stat-box">
            <span class="stat-label">Earned This Month</span>
            <span class="stat-value"><?php echo number_format($earnedThisMonth); ?></span>
        </div>
        <div class="stat-box">
            <span class="stat-label">Redeemed This Month</span>
            <span class="stat-value"><?php echo number_format($redeemedThisMonth); ?></span>
        </div>
        <div class="stat-box">
            <span class="stat-label">Customers Eligible to Redeem</span>
            <span class="stat-value"><?php echo number_format($eligibleForRedeem); ?></span>
        </div>
    </div>

    <div class="card">
        <h2>Program Settings</h2>
        <form method="POST" action="" class="form-grid">
            <input type="hidden" name="action" value="save_settings">
            <div>
                <label>Points earned per ₹1 spent</label>
                <input type="number" step="0.01" min="0" name="points_per_rupee" value="<?php echo sanitize($settings['points_per_rupee']); ?>" required>
                <small>Example: 0.01 = 1 point per ₹100 spent</small>
            </div>
            <div>
                <label>Redeem value per point (₹)</label>
                <input type="number" step="0.01" min="0" name="redeem_value_per_point" value="<?php echo sanitize($settings['redeem_value_per_point']); ?>" required>
            </div>
            <div>
                <label>Minimum points required to redeem</label>
                <input type="number" min="0" name="min_points_to_redeem" value="<?php echo sanitize($settings['min_points_to_redeem']); ?>" required>
            </div>
            <div>
                <label>Auto-expire unused points after (days)</label>
                <input type="number" min="0" name="expiry_days" value="<?php echo sanitize($expiryDays); ?>">
                <small>0 = kabhi expire nahi honge. Ye <code>cron/expire-loyalty-points.php</code> daily chalane pe apply hota hai.</small>
            </div>
            <div style="grid-column: span 2;">
                <button type="submit">Save Settings</button>
            </div>
        </form>
    </div>

    <div class="card">
        <h2>Manual Points Adjustment</h2>
        <p style="margin-bottom:12px; font-size:13px; color:#666;">
            Customer complaint resolve karna ho, ya koi galat entry theek karni ho, toh yahan se points add/deduct karo — har adjustment audit log me record hota hai.
        </p>
        <form method="POST" action="" class="inline-form">
            <input type="hidden" name="action" value="adjust_points">
            <input type="text" name="customer_id" placeholder="Customer ID" required style="width:110px;">
            <input type="number" name="points" placeholder="+50 ya -50" required style="width:120px;">
            <input type="text" name="reason" placeholder="Reason (zaroori)" required style="flex:1; min-width:200px;">
            <button type="submit">Adjust</button>
        </form>
        <p style="margin-top:8px;"><small>Customer ID <a href="<?php echo BASE_URL; ?>admin/customers/list.php" target="_blank">yahan se</a> dekh sakte ho (profile page pe ID dikhti hai).</small></p>

        <?php if ($recentAdjustments): ?>
        <table class="data-table" style="margin-top:16px;">
            <thead><tr><th>Date</th><th>Customer</th><th>Points</th><th>New Balance</th></tr></thead>
            <tbody>
                <?php foreach ($recentAdjustments as $adj): ?>
                <tr>
                    <td><?php echo sanitize($adj['created_at']); ?></td>
                    <td><?php echo sanitize($adj['name']); ?></td>
                    <td><?php echo ($adj['points'] > 0 ? '+' : '') . (int)$adj['points']; ?></td>
                    <td><?php echo (int)$adj['balance_after']; ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
</main>
<style>
.stat-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 14px; margin-bottom: 20px; }
.stat-box { background: #fff; padding: 16px; border-radius: 8px; box-shadow: 0 1px 4px rgba(0,0,0,0.08); }
.stat-label { display: block; font-size: 12px; color: #888; margin-bottom: 6px; }
.stat-value { display: block; font-size: 22px; font-weight: bold; color: #3b0a0a; }
</style>
<?php include __DIR__ . '/../../includes/footer.php'; ?>