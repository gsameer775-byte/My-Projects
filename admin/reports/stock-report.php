<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/functions.php';
requirePermission($pdo, 'reports', 'view');

// ============================================
// Stock Report
// - Current inventory value (cost + potential selling value)
// - Low stock / out-of-stock list
// - Category-wise stock breakdown
// - Stock movement summary (purchase/sale/return/damage/adjustment) for a period
// ============================================

$fromDate = sanitize($_GET['from'] ?? date('Y-m-01'));
$toDate   = sanitize($_GET['to'] ?? date('Y-m-d'));

// ---- Inventory value summary ----
$valueStmt = $pdo->query(
    "SELECT COALESCE(SUM(s.cost_price * COALESCE(st.quantity,0)),0) AS total_cost_value,
            COALESCE(SUM(s.selling_price * COALESCE(st.quantity,0)),0) AS total_selling_value,
            COALESCE(SUM(COALESCE(st.quantity,0)),0) AS total_units
     FROM sarees s LEFT JOIN stock st ON s.saree_id = st.saree_id
     WHERE s.is_active = 1"
);
$valueSummary = $valueStmt->fetch();

// ---- Out of stock ----
$outOfStock = $pdo->query(
    "SELECT s.sku, s.name, c.name AS category_name
     FROM sarees s
     LEFT JOIN categories c ON s.category_id = c.category_id
     LEFT JOIN stock st ON s.saree_id = st.saree_id
     WHERE s.is_active = 1 AND COALESCE(st.quantity,0) = 0
     ORDER BY s.name ASC"
)->fetchAll();

// ---- Low stock (but not zero) ----
$lowStock = $pdo->query(
    "SELECT s.sku, s.name, COALESCE(st.quantity,0) AS quantity, s.reorder_level
     FROM sarees s
     LEFT JOIN stock st ON s.saree_id = st.saree_id
     WHERE s.is_active = 1 AND COALESCE(st.quantity,0) > 0 AND COALESCE(st.quantity,0) <= s.reorder_level
     ORDER BY st.quantity ASC"
)->fetchAll();

// ---- Category-wise breakdown ----
$byCategory = $pdo->query(
    "SELECT c.name AS category_name, COUNT(s.saree_id) AS item_count,
            COALESCE(SUM(COALESCE(st.quantity,0)),0) AS total_units,
            COALESCE(SUM(s.cost_price * COALESCE(st.quantity,0)),0) AS cost_value
     FROM sarees s
     LEFT JOIN categories c ON s.category_id = c.category_id
     LEFT JOIN stock st ON s.saree_id = st.saree_id
     WHERE s.is_active = 1
     GROUP BY s.category_id
     ORDER BY cost_value DESC"
)->fetchAll();

// ---- Stock movements summary (for date range) ----
$movementsStmt = $pdo->prepare(
    "SELECT change_type,
            SUM(CASE WHEN quantity_change > 0 THEN quantity_change ELSE 0 END) AS total_in,
            SUM(CASE WHEN quantity_change < 0 THEN ABS(quantity_change) ELSE 0 END) AS total_out
     FROM stock_movements
     WHERE DATE(created_at) BETWEEN :from AND :to
     GROUP BY change_type"
);
$movementsStmt->execute(['from' => $fromDate, 'to' => $toDate]);
$movements = $movementsStmt->fetchAll();

include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/sidebar.php';
?>
<main>
    <h1>Stock Report</h1>

    <div class="dashboard-grid">
        <div class="card stat-card">
            <p class="stat-label">Total Units in Stock</p>
            <p class="stat-value"><?php echo (int)$valueSummary['total_units']; ?></p>
        </div>
        <div class="card stat-card">
            <p class="stat-label">Inventory Value (Cost)</p>
            <p class="stat-value">₹<?php echo number_format($valueSummary['total_cost_value'], 2); ?></p>
        </div>
        <div class="card stat-card">
            <p class="stat-label">Inventory Value (Selling)</p>
            <p class="stat-value">₹<?php echo number_format($valueSummary['total_selling_value'], 2); ?></p>
        </div>
        <div class="card stat-card">
            <p class="stat-label">Out of Stock Items</p>
            <p class="stat-value" style="color:<?php echo count($outOfStock) > 0 ? '#b71c1c' : '#1e7e34'; ?>"><?php echo count($outOfStock); ?></p>
        </div>
    </div>

    <div class="card">
        <h2>Out of Stock</h2>
        <table class="data-table">
            <thead><tr><th>SKU</th><th>Name</th><th>Category</th></tr></thead>
            <tbody>
                <?php if (empty($outOfStock)): ?>
                    <tr><td colspan="3">Kuch bhi out-of-stock nahi hai. 🎉</td></tr>
                <?php else: ?>
                    <?php foreach ($outOfStock as $o): ?>
                    <tr>
                        <td><?php echo sanitize($o['sku']); ?></td>
                        <td><?php echo sanitize($o['name']); ?></td>
                        <td><?php echo sanitize($o['category_name'] ?? '-'); ?></td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <div class="card">
        <h2>Low Stock (Below Reorder Level)</h2>
        <table class="data-table">
            <thead><tr><th>SKU</th><th>Name</th><th>Current Stock</th><th>Reorder Level</th></tr></thead>
            <tbody>
                <?php if (empty($lowStock)): ?>
                    <tr><td colspan="4">Koi low stock item nahi hai.</td></tr>
                <?php else: ?>
                    <?php foreach ($lowStock as $l): ?>
                    <tr>
                        <td><?php echo sanitize($l['sku']); ?></td>
                        <td><?php echo sanitize($l['name']); ?></td>
                        <td><span class="badge-warning"><?php echo (int)$l['quantity']; ?></span></td>
                        <td><?php echo (int)$l['reorder_level']; ?></td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <div class="card">
        <h2>Category-wise Stock Breakdown</h2>
        <table class="data-table">
            <thead><tr><th>Category</th><th>Item Types</th><th>Total Units</th><th>Cost Value (₹)</th></tr></thead>
            <tbody>
                <?php if (empty($byCategory)): ?>
                    <tr><td colspan="4">Koi data nahi hai.</td></tr>
                <?php else: ?>
                    <?php foreach ($byCategory as $c): ?>
                    <tr>
                        <td><?php echo sanitize($c['category_name'] ?? 'Uncategorized'); ?></td>
                        <td><?php echo (int)$c['item_count']; ?></td>
                        <td><?php echo (int)$c['total_units']; ?></td>
                        <td><?php echo number_format($c['cost_value'], 2); ?></td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <div class="card">
        <h2>Stock Movement Summary</h2>
        <form method="GET" action="" class="inline-form">
            <label>From:</label>
            <input type="date" name="from" value="<?php echo sanitize($fromDate); ?>">
            <label>To:</label>
            <input type="date" name="to" value="<?php echo sanitize($toDate); ?>">
            <button type="submit">Filter</button>
        </form>
        <table class="data-table" style="margin-top:12px;">
            <thead><tr><th>Type</th><th>Stock In (units)</th><th>Stock Out (units)</th></tr></thead>
            <tbody>
                <?php if (empty($movements)): ?>
                    <tr><td colspan="3">Is period me koi stock movement nahi hui.</td></tr>
                <?php else: ?>
                    <?php foreach ($movements as $m): ?>
                    <tr>
                        <td><?php echo sanitize(ucfirst($m['change_type'])); ?></td>
                        <td><?php echo (int)$m['total_in']; ?></td>
                        <td><?php echo (int)$m['total_out']; ?></td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</main>
<?php include __DIR__ . '/../../includes/footer.php'; ?>