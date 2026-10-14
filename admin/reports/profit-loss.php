<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/functions.php';
requirePermission($pdo, 'reports', 'view');

// ============================================
// Profit & Loss Report
// - Revenue (from sales, net of returns)
// - Cost of Goods Sold (COGS) - computed from sale_items.unit cost at time of sale
//   NOTE: unit_price on sale_items is the selling price, so COGS is derived by
//   joining back to sarees.cost_price (current cost - acceptable approximation
//   since cost_price rarely changes after a saree is added).
// - Operating Expenses (from expenses module)
// - Net Profit = Revenue - COGS - Expenses
// ============================================

$fromDate = sanitize($_GET['from'] ?? date('Y-m-01'));
$toDate   = sanitize($_GET['to'] ?? date('Y-m-d'));

// ---- Revenue (net of full/partial returns via refund_amount) ----
$revenueStmt = $pdo->prepare(
    "SELECT COALESCE(SUM(total_amount),0) AS gross_revenue
     FROM sales
     WHERE DATE(created_at) BETWEEN :from AND :to AND sale_status != 'returned'"
);
$revenueStmt->execute(['from' => $fromDate, 'to' => $toDate]);
$grossRevenue = (float)$revenueStmt->fetch()['gross_revenue'];

$returnsStmt = $pdo->prepare(
    "SELECT COALESCE(SUM(sr.refund_amount),0) AS total_refunds
     FROM sale_returns sr
     JOIN sale_items si ON sr.sale_item_id = si.sale_item_id
     JOIN sales s ON si.sale_id = s.sale_id
     WHERE DATE(sr.created_at) BETWEEN :from AND :to"
);
$returnsStmt->execute(['from' => $fromDate, 'to' => $toDate]);
$totalRefunds = (float)$returnsStmt->fetch()['total_refunds'];

$netRevenue = $grossRevenue - $totalRefunds;

// ---- Cost of Goods Sold ----
$cogsStmt = $pdo->prepare(
    "SELECT COALESCE(SUM(sr.cost_price * si.quantity),0) AS total_cogs
     FROM sale_items si
     JOIN sales s ON si.sale_id = s.sale_id
     JOIN sarees sr ON si.saree_id = sr.saree_id
     WHERE DATE(s.created_at) BETWEEN :from AND :to AND s.sale_status != 'returned'"
);
$cogsStmt->execute(['from' => $fromDate, 'to' => $toDate]);
$totalCOGS = (float)$cogsStmt->fetch()['total_cogs'];

$grossProfit = $netRevenue - $totalCOGS;

// ---- Operating Expenses (breakdown by category) ----
$expensesStmt = $pdo->prepare(
    "SELECT ec.name AS category_name, SUM(e.amount) AS total
     FROM expenses e
     JOIN expense_categories ec ON e.expense_category_id = ec.expense_category_id
     WHERE e.expense_date BETWEEN :from AND :to
     GROUP BY e.expense_category_id
     ORDER BY total DESC"
);
$expensesStmt->execute(['from' => $fromDate, 'to' => $toDate]);
$expensesByCategory = $expensesStmt->fetchAll();
$totalExpenses = array_sum(array_column($expensesByCategory, 'total'));

// ---- Damage/wastage cost (extra insight - loss from damaged stock) ----
$damageStmt = $pdo->prepare(
    "SELECT COALESCE(SUM(sr.cost_price * ABS(sm.quantity_change)),0) AS total_damage_cost
     FROM stock_movements sm
     JOIN sarees sr ON sm.saree_id = sr.saree_id
     WHERE sm.change_type = 'damage' AND DATE(sm.created_at) BETWEEN :from AND :to"
);
$damageStmt->execute(['from' => $fromDate, 'to' => $toDate]);
$totalDamageCost = (float)$damageStmt->fetch()['total_damage_cost'];

$netProfit = $grossProfit - $totalExpenses - $totalDamageCost;

include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/sidebar.php';
?>
<main>
    <h1>Profit &amp; Loss Report</h1>

    <div class="card">
        <form method="GET" action="" class="inline-form">
            <label>From:</label>
            <input type="date" name="from" value="<?php echo sanitize($fromDate); ?>">
            <label>To:</label>
            <input type="date" name="to" value="<?php echo sanitize($toDate); ?>">
            <button type="submit">Filter</button>
        </form>
    </div>

    <div class="card">
        <h2>P&amp;L Statement (<?php echo sanitize($fromDate); ?> to <?php echo sanitize($toDate); ?>)</h2>
        <table class="data-table">
            <tbody>
                <tr><td><strong>Gross Sales Revenue</strong></td><td>₹<?php echo number_format($grossRevenue, 2); ?></td></tr>
                <tr><td>(-) Returns / Refunds</td><td>-₹<?php echo number_format($totalRefunds, 2); ?></td></tr>
                <tr><td><strong>Net Revenue</strong></td><td><strong>₹<?php echo number_format($netRevenue, 2); ?></strong></td></tr>
                <tr><td>(-) Cost of Goods Sold</td><td>-₹<?php echo number_format($totalCOGS, 2); ?></td></tr>
                <tr><td><strong>Gross Profit</strong></td><td><strong>₹<?php echo number_format($grossProfit, 2); ?></strong></td></tr>
                <tr><td>(-) Operating Expenses</td><td>-₹<?php echo number_format($totalExpenses, 2); ?></td></tr>
                <tr><td>(-) Damage / Wastage Cost</td><td>-₹<?php echo number_format($totalDamageCost, 2); ?></td></tr>
                <tr style="border-top: 2px solid #3b0a0a;">
                    <td><strong>Net Profit / Loss</strong></td>
                    <td><strong style="color:<?php echo $netProfit >= 0 ? '#1e7e34' : '#b71c1c'; ?>; font-size:18px;">
                        ₹<?php echo number_format($netProfit, 2); ?>
                    </strong></td>
                </tr>
            </tbody>
        </table>
    </div>

    <div class="card">
        <h2>Expenses Breakdown</h2>
        <table class="data-table">
            <thead><tr><th>Category</th><th>Amount (₹)</th></tr></thead>
            <tbody>
                <?php if (empty($expensesByCategory)): ?>
                    <tr><td colspan="2">Is period me koi expense nahi hai.</td></tr>
                <?php else: ?>
                    <?php foreach ($expensesByCategory as $e): ?>
                    <tr>
                        <td><?php echo sanitize($e['category_name']); ?></td>
                        <td><?php echo number_format($e['total'], 2); ?></td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <p><small>Note: COGS current <code>sarees.cost_price</code> se calculate hota hai (jo saree add/edit karte time set kiya tha) — agar cost price baad me badla ho, toh purani sales ka COGS thoda approximate ho sakta hai.</small></p>
</main>
<?php include __DIR__ . '/../../includes/footer.php'; ?>