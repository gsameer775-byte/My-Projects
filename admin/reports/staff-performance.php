<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/functions.php';
requirePermission($pdo, 'reports', 'view');

// ============================================
// Staff Performance Report
// - Sales generated per staff member (for a date range)
// - Attendance summary per staff (for the same range)
// - Commission earned estimate (based on staff.commission_rate)
// ============================================

$fromDate = sanitize($_GET['from'] ?? date('Y-m-01'));
$toDate   = sanitize($_GET['to'] ?? date('Y-m-d'));

$performanceStmt = $pdo->prepare(
    "SELECT st.staff_id, st.name, r.role_name, st.commission_rate,
            COALESCE(sales_data.orders, 0) AS orders,
            COALESCE(sales_data.revenue, 0) AS revenue,
            COALESCE(att_data.present_days, 0) AS present_days,
            COALESCE(att_data.absent_days, 0) AS absent_days,
            COALESCE(att_data.leave_days, 0) AS leave_days
     FROM staff st
     LEFT JOIN roles r ON st.role_id = r.role_id
     LEFT JOIN (
         SELECT staff_id, COUNT(*) AS orders, SUM(total_amount) AS revenue
         FROM sales
         WHERE DATE(created_at) BETWEEN :from1 AND :to1 AND sale_status != 'returned'
         GROUP BY staff_id
     ) sales_data ON sales_data.staff_id = st.staff_id
     LEFT JOIN (
         SELECT staff_id,
                SUM(CASE WHEN status = 'present' THEN 1 ELSE 0 END) AS present_days,
                SUM(CASE WHEN status = 'absent' THEN 1 ELSE 0 END) AS absent_days,
                SUM(CASE WHEN status = 'leave' THEN 1 ELSE 0 END) AS leave_days
         FROM attendance
         WHERE attendance_date BETWEEN :from2 AND :to2
         GROUP BY staff_id
     ) att_data ON att_data.staff_id = st.staff_id
     WHERE st.is_active = 1
     ORDER BY revenue DESC"
);
$performanceStmt->execute([
    'from1' => $fromDate, 'to1' => $toDate,
    'from2' => $fromDate, 'to2' => $toDate,
]);
$performance = $performanceStmt->fetchAll();

include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/sidebar.php';
?>
<main>
    <h1>Staff Performance Report</h1>

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
        <table class="data-table">
            <thead>
                <tr>
                    <th>Staff</th>
                    <th>Role</th>
                    <th>Orders</th>
                    <th>Revenue (₹)</th>
                    <th>Est. Commission (₹)</th>
                    <th>Present</th>
                    <th>Absent</th>
                    <th>Leave</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($performance)): ?>
                    <tr><td colspan="8">Koi active staff nahi hai.</td></tr>
                <?php else: ?>
                    <?php foreach ($performance as $p): ?>
                    <?php $estCommission = $p['commission_rate'] ? ($p['revenue'] * $p['commission_rate'] / 100) : 0; ?>
                    <tr>
                        <td><?php echo sanitize($p['name']); ?></td>
                        <td><?php echo sanitize($p['role_name'] ?? '-'); ?></td>
                        <td><?php echo (int)$p['orders']; ?></td>
                        <td><?php echo number_format($p['revenue'], 2); ?></td>
                        <td><?php echo $p['commission_rate'] ? number_format($estCommission, 2) : '-'; ?></td>
                        <td><?php echo (int)$p['present_days']; ?></td>
                        <td><?php echo (int)$p['absent_days']; ?></td>
                        <td><?php echo (int)$p['leave_days']; ?></td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
        <p><small>Commission estimate <code>staff.commission_rate</code> se calculate hota hai — actual payout Staff → Salary/Commission page se confirm karo.</small></p>
    </div>
</main>
<?php include __DIR__ . '/../../includes/footer.php'; ?>