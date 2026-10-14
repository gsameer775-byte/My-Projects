<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/functions.php';
requirePermission($pdo, 'reports', 'view');

// ============================================
// Sales Report
// - Date range filter (default: this month)
// - Summary totals + daily breakdown + top-selling sarees + sales by staff
// ============================================

$fromDate = sanitize($_GET['from'] ?? date('Y-m-01'));
$toDate   = sanitize($_GET['to'] ?? date('Y-m-d'));

// ---- Summary totals ----
$summaryStmt = $pdo->prepare(
    "SELECT COUNT(*) AS total_orders, COALESCE(SUM(total_amount),0) AS total_revenue,
            COALESCE(SUM(discount_amount),0) AS total_discount, COALESCE(SUM(tax_amount),0) AS total_tax
     FROM sales
     WHERE DATE(created_at) BETWEEN :from AND :to AND sale_status != 'returned'"
);
$summaryStmt->execute(['from' => $fromDate, 'to' => $toDate]);
$summary = $summaryStmt->fetch();

// ---- Daily breakdown ----
$dailyStmt = $pdo->prepare(
    "SELECT DATE(created_at) AS sale_date, COUNT(*) AS orders, SUM(total_amount) AS revenue
     FROM sales
     WHERE DATE(created_at) BETWEEN :from AND :to AND sale_status != 'returned'
     GROUP BY DATE(created_at)
     ORDER BY sale_date DESC"
);
$dailyStmt->execute(['from' => $fromDate, 'to' => $toDate]);
$daily = $dailyStmt->fetchAll();

// ---- Top-selling sarees ----
$topSareesStmt = $pdo->prepare(
    "SELECT sr.name, sr.sku, SUM(si.quantity) AS total_qty, SUM(si.line_total) AS total_revenue
     FROM sale_items si
     JOIN sales s ON si.sale_id = s.sale_id
     JOIN sarees sr ON si.saree_id = sr.saree_id
     WHERE DATE(s.created_at) BETWEEN :from AND :to
     GROUP BY si.saree_id
     ORDER BY total_qty DESC
     LIMIT 10"
);
$topSareesStmt->execute(['from' => $fromDate, 'to' => $toDate]);
$topSarees = $topSareesStmt->fetchAll();

// ---- Sales by staff ----
$byStaffStmt = $pdo->prepare(
    "SELECT st.name AS staff_name, COUNT(*) AS orders, SUM(s.total_amount) AS revenue
     FROM sales s
     JOIN staff st ON s.staff_id = st.staff_id
     WHERE DATE(s.created_at) BETWEEN :from AND :to AND s.sale_status != 'returned'
     GROUP BY s.staff_id
     ORDER BY revenue DESC"
);
$byStaffStmt->execute(['from' => $fromDate, 'to' => $toDate]);
$byStaff = $byStaffStmt->fetchAll();

// ---- Payment mode breakdown ----
$byPaymentStmt = $pdo->prepare(
    "SELECT p.payment_mode, COUNT(*) AS count, SUM(p.amount) AS total
     FROM payments p
     JOIN sales s ON p.sale_id = s.sale_id
     WHERE DATE(s.created_at) BETWEEN :from AND :to
     GROUP BY p.payment_mode
     ORDER BY total DESC"
);
$byPaymentStmt->execute(['from' => $fromDate, 'to' => $toDate]);
$byPayment = $byPaymentStmt->fetchAll();

include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/sidebar.php';
?>
<main>
    <h1>Sales Report</h1>

    <div class="card">
        <form method="GET" action="" class="inline-form">
            <label>From:</label>
            <input type="date" name="from" value="<?php echo sanitize($fromDate); ?>">
            <label>To:</label>
            <input type="date" name="to" value="<?php echo sanitize($toDate); ?>">
            <button type="submit">Filter</button>
        </form>
    </div>

    <div class="dashboard-grid">
        <div class="card stat-card">
            <p class="stat-label">Total Orders</p>
            <p class="stat-value"><?php echo (int)$summary['total_orders']; ?></p>
        </div>
        <div class="card stat-card">
            <p class="stat-label">Total Revenue</p>
            <p class="stat-value">₹<?php echo number_format($summary['total_revenue'], 2); ?></p>
        </div>
        <div class="card stat-card">
            <p class="stat-label">Total Discount Given</p>
            <p class="stat-value">₹<?php echo number_format($summary['total_discount'], 2); ?></p>
        </div>
        <div class="card stat-card">
            <p class="stat-label">Total Tax Collected</p>
            <p class="stat-value">₹<?php echo number_format($summary['total_tax'], 2); ?></p>
        </div>
    </div>

    <div class="card">
        <h2>Daily Breakdown</h2>
        <table class="data-table">
            <thead><tr><th>Date</th><th>Orders</th><th>Revenue (₹)</th></tr></thead>
            <tbody>
                <?php if (empty($daily)): ?>
                    <tr><td colspan="3">Is period me koi sale nahi hui.</td></tr>
                <?php else: ?>
                    <?php foreach ($daily as $d): ?>
                    <tr>
                        <td><?php echo sanitize(date('d M Y', strtotime($d['sale_date']))); ?></td>
                        <td><?php echo (int)$d['orders']; ?></td>
                        <td><?php echo number_format($d['revenue'], 2); ?></td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <div class="card">
        <h2>Top-Selling Sarees</h2>
        <table class="data-table">
            <thead><tr><th>SKU</th><th>Name</th><th>Qty Sold</th><th>Revenue (₹)</th></tr></thead>
            <tbody>
                <?php if (empty($topSarees)): ?>
                    <tr><td colspan="4">Koi data nahi hai.</td></tr>
                <?php else: ?>
                    <?php foreach ($topSarees as $t): ?>
                    <tr>
                        <td><?php echo sanitize($t['sku']); ?></td>
                        <td><?php echo sanitize($t['name']); ?></td>
                        <td><?php echo (int)$t['total_qty']; ?></td>
                        <td><?php echo number_format($t['total_revenue'], 2); ?></td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <div class="card">
        <h2>Sales by Staff</h2>
        <table class="data-table">
            <thead><tr><th>Staff</th><th>Orders</th><th>Revenue (₹)</th></tr></thead>
            <tbody>
                <?php if (empty($byStaff)): ?>
                    <tr><td colspan="3">Koi data nahi hai.</td></tr>
                <?php else: ?>
                    <?php foreach ($byStaff as $s): ?>
                    <tr>
                        <td><?php echo sanitize($s['staff_name']); ?></td>
                        <td><?php echo (int)$s['orders']; ?></td>
                        <td><?php echo number_format($s['revenue'], 2); ?></td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <div class="card">
        <h2>Payment Mode Breakdown</h2>
        <table class="data-table">
            <thead><tr><th>Mode</th><th>Count</th><th>Total (₹)</th></tr></thead>
            <tbody>
                <?php if (empty($byPayment)): ?>
                    <tr><td colspan="3">Koi data nahi hai.</td></tr>
                <?php else: ?>
                    <?php foreach ($byPayment as $p): ?>
                    <tr>
                        <td><?php echo sanitize(ucfirst($p['payment_mode'])); ?></td>
                        <td><?php echo (int)$p['count']; ?></td>
                        <td><?php echo number_format($p['total'], 2); ?></td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</main>
<?php include __DIR__ . '/../../includes/footer.php'; ?>