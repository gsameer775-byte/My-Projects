<?php
// ============================================
// cron/expire-loyalty-points.php
// Run this daily via server crontab, e.g.:
//   0 2 * * * /usr/bin/php /path/to/hikayat_banaras/cron/expire-loyalty-points.php >> /path/to/hikayat_banaras/cron/expiry.log 2>&1
//
// Logic: if expiry_days = 0 in system_settings, nothing happens (feature off).
// Otherwise, for every customer whose most recent "earning" activity is older
// than expiry_days, their current point balance is zeroed out and logged as
// a single 'expired' loyalty_transactions entry (not deleted - kept for audit).
// ============================================

require_once __DIR__ . '/../config/config.php';

$stmt = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'loyalty_expiry_days'");
$stmt->execute();
$row = $stmt->fetch();
$expiryDays = $row ? (int)$row['setting_value'] : 0;

if ($expiryDays <= 0) {
    echo date('Y-m-d H:i:s') . " - Loyalty expiry is OFF (expiry_days=0). Nothing to do.\n";
    exit;
}

// Find customers with points > 0 whose last earned/adjusted/redeemed activity
// is older than the expiry window (i.e. genuinely inactive accounts)
$sql = "SELECT c.customer_id, c.name, c.loyalty_points,
               (SELECT MAX(lt.created_at) FROM loyalty_transactions lt WHERE lt.customer_id = c.customer_id) AS last_activity
        FROM customers c
        WHERE c.loyalty_points > 0";
$candidates = $pdo->query($sql)->fetchAll();

$expiredCount = 0;
$expiredPoints = 0;

foreach ($candidates as $cust) {
    $lastActivity = $cust['last_activity'] ? strtotime($cust['last_activity']) : 0;
    $daysSince = (time() - $lastActivity) / 86400;

    if ($daysSince >= $expiryDays) {
        $pdo->beginTransaction();
        try {
            $pointsToExpire = (int)$cust['loyalty_points'];

            $pdo->prepare("UPDATE customers SET loyalty_points = 0 WHERE customer_id = :id")
                ->execute(['id' => $cust['customer_id']]);

            $pdo->prepare(
                "INSERT INTO loyalty_transactions (customer_id, sale_id, txn_type, points, balance_after)
                 VALUES (:cid, NULL, 'expired', :pts, 0)"
            )->execute(['cid' => $cust['customer_id'], 'pts' => -$pointsToExpire]);

            $pdo->prepare(
                "INSERT INTO audit_log (staff_id, action, module_name, record_id, description, ip_address)
                 VALUES (NULL, 'expire_points', 'loyalty', :rid, :desc, 'cron')"
            )->execute([
                'rid' => $cust['customer_id'],
                'desc' => $pointsToExpire . ' points expired (inactive ' . round($daysSince) . ' days)',
            ]);

            $pdo->commit();
            $expiredCount++;
            $expiredPoints += $pointsToExpire;
        } catch (Exception $e) {
            $pdo->rollBack();
            echo date('Y-m-d H:i:s') . " - FAILED for customer {$cust['customer_id']}: " . $e->getMessage() . "\n";
        }
    }
}

echo date('Y-m-d H:i:s') . " - Expired {$expiredPoints} points across {$expiredCount} customers.\n";