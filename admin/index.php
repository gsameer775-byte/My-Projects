<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/functions.php';
requireLogin();

// ============================================
// Dashboard
// - Today's sales summary
// - Low stock alerts
// - Today's attendance summary
// - Outstanding dues (customers owe us / we owe vendors)
// - Recent sales activity
// ============================================

$today = date('Y-m-d');
$thisMonth = date('Y-m');

// ---- Today's Sales ----
$todaySales = $pdo->prepare(
    "SELECT COUNT(*) AS total_orders, COALESCE(SUM(total_amount),0) AS total_revenue
     FROM sales WHERE DATE(created_at) = :today AND sale_status != 'returned'"
);
$todaySales->execute(['today' => $today]);
$todayStats = $todaySales->fetch();

// ---- This Month's Sales ----
$monthSales = $pdo->prepare(
    "SELECT COUNT(*) AS total_orders, COALESCE(SUM(total_amount),0) AS total_revenue
     FROM sales WHERE DATE_FORMAT(created_at, '%Y-%m') = :month AND sale_status != 'returned'"
);
$monthSales->execute(['month' => $thisMonth]);
$monthStats = $monthSales->fetch();

// ---- Low Stock Items ----
$lowStock = $pdo->query(
    "SELECT s.name, s.sku, COALESCE(st.quantity,0) AS quantity, s.reorder_level
     FROM sarees s
     LEFT JOIN stock st ON s.saree_id = st.saree_id
     WHERE s.is_active = 1 AND COALESCE(st.quantity,0) <= s.reorder_level
     ORDER BY st.quantity ASC
     LIMIT 10"
)->fetchAll();

$lowStockCount = $pdo->query(
    "SELECT COUNT(*) AS cnt FROM sarees s
     LEFT JOIN stock st ON s.saree_id = st.saree_id
     WHERE s.is_active = 1 AND COALESCE(st.quantity,0) <= s.reorder_level"
)->fetch()['cnt'];

// ---- Today's Attendance ----
$totalStaff = $pdo->query("SELECT COUNT(*) AS cnt FROM staff WHERE is_active = 1")->fetch()['cnt'];
$presentToday = $pdo->prepare(
    "SELECT COUNT(*) AS cnt FROM attendance WHERE attendance_date = :today AND status = 'present'"
);
$presentToday->execute(['today' => $today]);
$presentCount = $presentToday->fetch()['cnt'];

// ---- Customer Credit Due (money customers owe us) ----
$customerDue = $pdo->query(
    "SELECT COALESCE(SUM(balance_after), 0) AS total FROM (
        SELECT customer_id, balance_after,
               ROW_NUMBER() OVER (PARTITION BY customer_id ORDER BY ledger_id DESC) AS rn
        FROM credit_ledger
    ) t WHERE rn = 1 AND balance_after > 0"
)->fetch();
$totalCustomerDue = (float)($customerDue['total'] ?? 0);

// ---- Vendor Payable (money we owe vendors) ----
$vendorDue = $pdo->query(
    "SELECT
        COALESCE((SELECT SUM(total_amount) FROM purchase_orders WHERE status != 'cancelled'), 0) AS ordered,
        COALESCE((SELECT SUM(amount) FROM vendor_payments), 0) AS paid"
)->fetch();
$totalVendorDue = (float)$vendorDue['ordered'] - (float)$vendorDue['paid'];

// ---- This Month's Expenses ----
$monthExpenses = $pdo->prepare(
    "SELECT COALESCE(SUM(amount), 0) AS total FROM expenses WHERE DATE_FORMAT(expense_date, '%Y-%m') = :month"
);
$monthExpenses->execute(['month' => $thisMonth]);
$totalMonthExpenses = (float)$monthExpenses->fetch()['total'];

// ---- Recent Sales (last 5) ----
$recentSales = $pdo->query(
    "SELECT s.sale_id, s.invoice_no, s.total_amount, s.created_at, c.name AS customer_name
     FROM sales s
     LEFT JOIN customers c ON s.customer_id = c.customer_id
     ORDER BY s.created_at DESC LIMIT 5"
)->fetchAll();

// ---- Total Inventory Value (at cost) ----
$inventoryValue = $pdo->query(
    "SELECT COALESCE(SUM(s.cost_price * COALESCE(st.quantity,0)), 0) AS total
     FROM sarees s LEFT JOIN stock st ON s.saree_id = st.saree_id
     WHERE s.is_active = 1"
)->fetch()['total'];

include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/sidebar.php';
?>
<main>
    <h1>Dashboard</h1>
    <p><small>Namaste, <?php echo sanitize($_SESSION['staff_name']); ?>! Aaj ka summary:</small></p>

    <div class="dashboard-grid">
        <div class="card stat-card">
            <p class="stat-label">Today's Sales</p>
            <p class="stat-value">₹<?php echo number_format($todayStats['total_revenue'], 2); ?></p>
            <p class="stat-sub"><?php echo (int)$todayStats['total_orders']; ?> order(s)</p>
        </div>

        <div class="card stat-card">
            <p class="stat-label">This Month's Sales</p>
            <p class="stat-value">₹<?php echo number_format($monthStats['total_revenue'], 2); ?></p>
            <p class="stat-sub"><?php echo (int)$monthStats['total_orders']; ?> order(s)</p>
        </div>

        <div class="card stat-card">
            <p class="stat-label">This Month's Expenses</p>
            <p class="stat-value">₹<?php echo number_format($totalMonthExpenses, 2); ?></p>
            <p class="stat-sub"><a href="expenses/list.php">View details</a></p>
        </div>

        <div class="card stat-card">
            <p class="stat-label">Staff Present Today</p>
            <p class="stat-value"><?php echo (int)$presentCount; ?> / <?php echo (int)$totalStaff; ?></p>
            <p class="stat-sub"><a href="staff/attendance.php">View attendance</a></p>
        </div>

        <div class="card stat-card">
            <p class="stat-label">Customers Owe Us</p>
            <p class="stat-value" style="color:<?php echo $totalCustomerDue > 0 ? '#b71c1c' : '#1e7e34'; ?>">₹<?php echo number_format($totalCustomerDue, 2); ?></p>
            <p class="stat-sub"><a href="customers/credit-ledger.php">View credit ledger</a></p>
        </div>

        <div class="card stat-card">
            <p class="stat-label">We Owe Vendors</p>
            <p class="stat-value" style="color:<?php echo $totalVendorDue > 0 ? '#b71c1c' : '#1e7e34'; ?>">₹<?php echo number_format($totalVendorDue, 2); ?></p>
            <p class="stat-sub"><a href="vendors/payments.php">View vendor payments</a></p>
        </div>

        <div class="card stat-card">
            <p class="stat-label">Inventory Value (at cost)</p>
            <p class="stat-value">₹<?php echo number_format($inventoryValue, 2); ?></p>
            <p class="stat-sub"><a href="inventory/list.php">View inventory</a></p>
        </div>

        <div class="card stat-card">
            <p class="stat-label">Low Stock Items</p>
            <p class="stat-value" style="color:<?php echo $lowStockCount > 0 ? '#b71c1c' : '#1e7e34'; ?>"><?php echo (int)$lowStockCount; ?></p>
            <p class="stat-sub"><a href="inventory/list.php">View inventory</a></p>
        </div>
    </div>

    <div class="card">
        <h2>Low Stock Alerts</h2>
        <table class="data-table">
            <thead>
                <tr><th>SKU</th><th>Name</th><th>Current Stock</th><th>Reorder Level</th></tr>
            </thead>
            <tbody>
                <?php if (empty($lowStock)): ?>
                    <tr><td colspan="4">Sab sarees ka stock theek hai. 🎉</td></tr>
                <?php else: ?>
                    <?php foreach ($lowStock as $item): ?>
                    <tr>
                        <td><?php echo sanitize($item['sku']); ?></td>
                        <td><?php echo sanitize($item['name']); ?></td>
                        <td><span class="badge-warning"><?php echo (int)$item['quantity']; ?></span></td>
                        <td><?php echo (int)$item['reorder_level']; ?></td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
        <?php if ($lowStockCount > 10): ?>
            <p><small>Aur <?php echo $lowStockCount - 10; ?> items ka stock bhi low hai. <a href="inventory/list.php">Poori list dekho</a>.</small></p>
        <?php endif; ?>
    </div>

    <div class="card">
        <h2>Recent Sales</h2>
        <table class="data-table">
            <thead>
                <tr><th>Invoice No</th><th>Customer</th><th>Amount (₹)</th><th>Date</th><th></th></tr>
            </thead>
            <tbody>
                <?php if (empty($recentSales)): ?>
                    <tr><td colspan="5">Abhi tak koi sale nahi hui. <a href="sales/new-sale.php">Pehli sale karo</a>.</td></tr>
                <?php else: ?>
                    <?php foreach ($recentSales as $s): ?>
                    <tr>
                        <td><?php echo sanitize($s['invoice_no']); ?></td>
                        <td><?php echo sanitize($s['customer_name'] ?? 'Walk-in'); ?></td>
                        <td><?php echo number_format($s['total_amount'], 2); ?></td>
                        <td><?php echo sanitize(date('d M Y, h:i A', strtotime($s['created_at']))); ?></td>
                        <td><a href="sales/invoice.php?id=<?php echo (int)$s['sale_id']; ?>">View</a></td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <div class="toolbar">
        <a href="sales/new-sale.php" class="btn-primary">+ New Sale</a>
        <a href="inventory/add.php">+ Add Saree</a>
        <a href="customers/add.php">+ Add Customer</a>
    </div>
</main>
<?php include __DIR__ . '/../includes/footer.php'; ?>