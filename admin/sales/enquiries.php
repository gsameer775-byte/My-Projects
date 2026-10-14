<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/functions.php';
requirePermission($pdo, 'sales', 'view');

// ============================================
// Enquiries Report - demand analysis + follow-up tracker
// ============================================

$fromDate = $_GET['from'] ?? date('Y-m-01');
$toDate = $_GET['to'] ?? date('Y-m-d');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'mark_followup_done') {
    requirePermission($pdo, 'sales', 'edit');
    $pdo->prepare("UPDATE enquiries SET followup_done = 1 WHERE enquiry_id = :id")
        ->execute(['id' => (int)($_POST['enquiry_id'] ?? 0)]);
    redirect('admin/sales/enquiries.php?from=' . urlencode($fromDate) . '&to=' . urlencode($toDate));
}

$reasonLabels = [
    'out_of_stock' => 'Stock Khatam Tha',
    'not_carried' => "Hum Ye Item Rakhte Hi Nahi",
    'too_expensive' => 'Price Zyada Laga',
    'other' => 'Koi Aur Wajah',
];
$sourceLabels = [
    'walk_in' => 'Walk-in', 'phone_call' => 'Phone Call', 'whatsapp' => 'WhatsApp',
    'instagram' => 'Instagram', 'referral' => 'Referral', 'other' => 'Other',
];

// ---- Top requested EXISTING sarees ----
$topExisting = $pdo->prepare(
    "SELECT s.sku, s.name, COUNT(*) AS enquiry_count,
            SUM(CASE WHEN e.outcome = 'lost' THEN 1 ELSE 0 END) AS lost_count,
            SUM(CASE WHEN e.outcome = 'purchased' THEN 1 ELSE 0 END) AS purchased_count
     FROM enquiries e JOIN sarees s ON s.saree_id = e.saree_id
     WHERE e.item_type = 'existing_saree' AND e.enquiry_date BETWEEN :from AND :to
     GROUP BY e.saree_id ORDER BY enquiry_count DESC LIMIT 15"
);
$topExisting->execute(['from' => $fromDate, 'to' => $toDate]);
$topExisting = $topExisting->fetchAll();

// ---- Top requested CUSTOM items ----
$topCustom = $pdo->prepare(
    "SELECT custom_description, COUNT(*) AS enquiry_count
     FROM enquiries WHERE item_type = 'custom' AND enquiry_date BETWEEN :from AND :to
     GROUP BY custom_description ORDER BY enquiry_count DESC LIMIT 15"
);
$topCustom->execute(['from' => $fromDate, 'to' => $toDate]);
$topCustom = $topCustom->fetchAll();

// ---- Lost-reason breakdown ----
$lostReasons = $pdo->prepare(
    "SELECT lost_reason, COUNT(*) AS cnt FROM enquiries WHERE outcome = 'lost' AND enquiry_date BETWEEN :from AND :to GROUP BY lost_reason"
);
$lostReasons->execute(['from' => $fromDate, 'to' => $toDate]);
$lostReasons = $lostReasons->fetchAll();

// ---- Lead source breakdown (with conversion rate) ----
$sourceBreakdown = $pdo->prepare(
    "SELECT lead_source, COUNT(*) AS total, SUM(CASE WHEN outcome='purchased' THEN 1 ELSE 0 END) AS purchased
     FROM enquiries WHERE enquiry_date BETWEEN :from AND :to GROUP BY lead_source ORDER BY total DESC"
);
$sourceBreakdown->execute(['from' => $fromDate, 'to' => $toDate]);
$sourceBreakdown = $sourceBreakdown->fetchAll();

// ---- Occasion breakdown ----
$occasionBreakdown = $pdo->prepare(
    "SELECT occasion, COUNT(*) AS cnt FROM enquiries WHERE occasion IS NOT NULL AND occasion != '' AND enquiry_date BETWEEN :from AND :to GROUP BY occasion ORDER BY cnt DESC LIMIT 10"
);
$occasionBreakdown->execute(['from' => $fromDate, 'to' => $toDate]);
$occasionBreakdown = $occasionBreakdown->fetchAll();

// ---- Budget distribution ----
$budgetStats = $pdo->prepare(
    "SELECT AVG(budget_max) AS avg_budget, MIN(budget_min) AS min_budget, MAX(budget_max) AS max_budget
     FROM enquiries WHERE budget_max IS NOT NULL AND enquiry_date BETWEEN :from AND :to"
);
$budgetStats->execute(['from' => $fromDate, 'to' => $toDate]);
$budgetStats = $budgetStats->fetch();

// ---- Overall stats ----
$statsStmt = $pdo->prepare(
    "SELECT COUNT(*) AS total,
            SUM(CASE WHEN outcome = 'purchased' THEN 1 ELSE 0 END) AS purchased,
            SUM(CASE WHEN outcome = 'lost' THEN 1 ELSE 0 END) AS lost,
            SUM(CASE WHEN outcome = 'pending' THEN 1 ELSE 0 END) AS pending
     FROM enquiries WHERE enquiry_date BETWEEN :from AND :to"
);
$statsStmt->execute(['from' => $fromDate, 'to' => $toDate]);
$stats = $statsStmt->fetch();

// ---- Follow-ups due (not date-filtered - always show what's pending, regardless of report range) ----
$followupsDue = $pdo->query(
    "SELECT e.*, s.name AS saree_name, s2.name AS assigned_name, c.name AS customer_name
     FROM enquiries e
     LEFT JOIN sarees s ON s.saree_id = e.saree_id
     LEFT JOIN staff s2 ON s2.staff_id = e.assigned_staff_id
     LEFT JOIN customers c ON c.customer_id = e.customer_id
     WHERE e.needs_followup = 1 AND e.followup_done = 0
     ORDER BY e.followup_date ASC"
)->fetchAll();

// ---- Full list (recent) ----
$listStmt = $pdo->prepare(
    "SELECT e.*, s.name AS saree_name, s.sku, c.name AS customer_name
     FROM enquiries e
     LEFT JOIN sarees s ON s.saree_id = e.saree_id
     LEFT JOIN customers c ON c.customer_id = e.customer_id
     WHERE e.enquiry_date BETWEEN :from AND :to
     ORDER BY e.created_at DESC LIMIT 100"
);
$listStmt->execute(['from' => $fromDate, 'to' => $toDate]);
$list = $listStmt->fetchAll();

$today = date('Y-m-d');

include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/sidebar.php';
?>
<main>
    <h1>Enquiries Report</h1>

    <div class="card">
        <form method="GET" action="" class="inline-form">
            <label>From:</label>
            <input type="date" name="from" value="<?php echo sanitize($fromDate); ?>">
            <label>To:</label>
            <input type="date" name="to" value="<?php echo sanitize($toDate); ?>">
            <button type="submit">Filter</button>
        </form>
        <p style="margin-top:10px;"><a href="log-enquiry.php" class="btn-primary">+ Log New Enquiry</a></p>
    </div>

    <div class="stat-grid">
        <div class="stat-box"><span class="stat-label">Total Enquiries</span><span class="stat-value"><?php echo (int)$stats['total']; ?></span></div>
        <div class="stat-box"><span class="stat-label">Purchased</span><span class="stat-value" style="color:#1e7e34;"><?php echo (int)$stats['purchased']; ?></span></div>
        <div class="stat-box"><span class="stat-label">Lost</span><span class="stat-value" style="color:#b71c1c;"><?php echo (int)$stats['lost']; ?></span></div>
        <div class="stat-box"><span class="stat-label">Pending</span><span class="stat-value"><?php echo (int)$stats['pending']; ?></span></div>
        <?php if ($budgetStats['avg_budget']): ?>
        <div class="stat-box"><span class="stat-label">Avg Budget</span><span class="stat-value">₹<?php echo number_format($budgetStats['avg_budget'], 0); ?></span></div>
        <?php endif; ?>
    </div>

    <?php if (!empty($followupsDue)): ?>
    <div class="card" style="background:#fff8e1;">
        <h2>Follow-ups Due (<?php echo count($followupsDue); ?>)</h2>
        <table class="data-table">
            <thead><tr><th>Date</th><th>Customer</th><th>Kya Maanga</th><th>Assigned To</th><th></th></tr></thead>
            <tbody>
                <?php foreach ($followupsDue as $f):
                    $isOverdue = $f['followup_date'] && $f['followup_date'] < $today;
                ?>
                <tr>
                    <td>
                        <?php echo sanitize($f['followup_date'] ?? '-'); ?>
                        <?php if ($isOverdue): ?><span class="badge-warning" style="background:#fdecea;color:#b71c1c;">Overdue</span><?php endif; ?>
                    </td>
                    <td><?php echo sanitize($f['customer_name'] ?? $f['walkin_name'] ?? 'Unknown'); ?> <?php echo $f['walkin_phone'] ? '(' . sanitize($f['walkin_phone']) . ')' : ''; ?></td>
                    <td><?php echo $f['item_type'] === 'existing_saree' ? sanitize($f['saree_name']) : sanitize($f['custom_description']); ?></td>
                    <td><?php echo sanitize($f['assigned_name'] ?? '-'); ?></td>
                    <td>
                        <form method="POST" action="">
                            <input type="hidden" name="action" value="mark_followup_done">
                            <input type="hidden" name="enquiry_id" value="<?php echo (int)$f['enquiry_id']; ?>">
                            <button type="submit">Mark Done</button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>

    <div class="card">
        <h2>Sabse Zyada Maangi Gayi Sarees (jo hum rakhte hain)</h2>
        <table class="data-table">
            <thead><tr><th>SKU</th><th>Naam</th><th>Kitni Baar Maangi</th><th>Purchased</th><th>Lost</th></tr></thead>
            <tbody>
                <?php if (empty($topExisting)): ?>
                    <tr><td colspan="5">Is date range me koi enquiry nahi mili.</td></tr>
                <?php else: ?>
                    <?php foreach ($topExisting as $t): ?>
                    <tr>
                        <td><?php echo sanitize($t['sku']); ?></td>
                        <td><?php echo sanitize($t['name']); ?></td>
                        <td><strong><?php echo (int)$t['enquiry_count']; ?></strong></td>
                        <td><?php echo (int)$t['purchased_count']; ?></td>
                        <td><?php echo $t['lost_count'] > 0 ? '<span class="badge-warning">' . (int)$t['lost_count'] . '</span>' : '0'; ?></td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <div class="card">
        <h2>Sabse Zyada Maangi Gayi Cheezein (jo hum rakhte hi nahi)</h2>
        <table class="data-table">
            <thead><tr><th>Description</th><th>Kitni Baar Maangi</th></tr></thead>
            <tbody>
                <?php if (empty($topCustom)): ?>
                    <tr><td colspan="2">Is date range me koi custom enquiry nahi mili.</td></tr>
                <?php else: ?>
                    <?php foreach ($topCustom as $t): ?>
                    <tr><td><?php echo sanitize($t['custom_description']); ?></td><td><strong><?php echo (int)$t['enquiry_count']; ?></strong></td></tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <div class="card">
        <h2>Sale Kyun Miss Hui (Lost Reasons)</h2>
        <table class="data-table">
            <thead><tr><th>Wajah</th><th>Count</th></tr></thead>
            <tbody>
                <?php if (empty($lostReasons)): ?>
                    <tr><td colspan="2">Is date range me koi lost enquiry nahi hai.</td></tr>
                <?php else: ?>
                    <?php foreach ($lostReasons as $r): ?>
                    <tr><td><?php echo sanitize($reasonLabels[$r['lost_reason']] ?? 'Unknown'); ?></td><td><strong><?php echo (int)$r['cnt']; ?></strong></td></tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <div class="card">
        <h2>Lead Source Breakdown</h2>
        <table class="data-table">
            <thead><tr><th>Source</th><th>Total</th><th>Purchased</th><th>Conversion %</th></tr></thead>
            <tbody>
                <?php if (empty($sourceBreakdown)): ?>
                    <tr><td colspan="4">Koi data nahi.</td></tr>
                <?php else: ?>
                    <?php foreach ($sourceBreakdown as $s): $conv = $s['total'] > 0 ? round(($s['purchased'] / $s['total']) * 100) : 0; ?>
                    <tr>
                        <td><?php echo sanitize($sourceLabels[$s['lead_source']] ?? $s['lead_source']); ?></td>
                        <td><?php echo (int)$s['total']; ?></td>
                        <td><?php echo (int)$s['purchased']; ?></td>
                        <td><?php echo $conv; ?>%</td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <?php if (!empty($occasionBreakdown)): ?>
    <div class="card">
        <h2>Occasion-wise Demand</h2>
        <table class="data-table">
            <thead><tr><th>Occasion</th><th>Count</th></tr></thead>
            <tbody>
                <?php foreach ($occasionBreakdown as $o): ?>
                <tr><td><?php echo sanitize($o['occasion']); ?></td><td><strong><?php echo (int)$o['cnt']; ?></strong></td></tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>

    <div class="card">
        <h2>All Enquiries (recent 100)</h2>
        <table class="data-table">
            <thead><tr><th>Date</th><th>Customer</th><th>Kya Maanga</th><th>Budget</th><th>Source</th><th>Outcome</th><th>Photo</th></tr></thead>
            <tbody>
                <?php if (empty($list)): ?>
                    <tr><td colspan="7">Koi enquiry nahi mili.</td></tr>
                <?php else: ?>
                    <?php foreach ($list as $l): ?>
                    <tr>
                        <td><?php echo sanitize($l['enquiry_date']); ?></td>
                        <td><?php echo sanitize($l['customer_name'] ?? $l['walkin_name'] ?? 'Unknown'); ?></td>
                        <td><?php echo $l['item_type'] === 'existing_saree' ? sanitize($l['sku'] . ' - ' . $l['saree_name']) : sanitize($l['custom_description']); ?></td>
                        <td><?php echo ($l['budget_min'] || $l['budget_max']) ? '₹' . number_format($l['budget_min'] ?? 0) . '-' . number_format($l['budget_max'] ?? 0) : '-'; ?></td>
                        <td><?php echo sanitize($sourceLabels[$l['lead_source']] ?? '-'); ?></td>
                        <td><?php echo sanitize(ucfirst($l['outcome'])); ?></td>
                        <td><?php echo $l['reference_photo'] ? '<a href="' . BASE_URL . sanitize($l['reference_photo']) . '" target="_blank">View</a>' : '-'; ?></td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</main>
<style>
.stat-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 14px; margin-bottom: 20px; }
.stat-box { background: #fff; padding: 16px; border-radius: 8px; box-shadow: 0 1px 4px rgba(0,0,0,0.08); }
.stat-label { display: block; font-size: 12px; color: #888; margin-bottom: 6px; }
.stat-value { display: block; font-size: 22px; font-weight: bold; color: #3b0a0a; }
</style>
<?php include __DIR__ . '/../../includes/footer.php'; ?>