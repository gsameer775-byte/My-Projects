<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/functions.php';
requirePermission($pdo, 'sales', 'view');

$search = sanitize($_GET['search'] ?? '');

$sql = "SELECT s.sale_id, s.invoice_no, s.total_amount, s.payment_status, s.sale_status,
               s.created_at, c.name AS customer_name, st.name AS staff_name
        FROM sales s
        LEFT JOIN customers c ON s.customer_id = c.customer_id
        LEFT JOIN staff st ON s.staff_id = st.staff_id";

$params = [];
if ($search !== '') {
    $sql .= " WHERE s.invoice_no LIKE :search1 OR c.name LIKE :search2";
    $params['search1'] = "%$search%";
    $params['search2'] = "%$search%";
}
$sql .= " ORDER BY s.created_at DESC LIMIT 100";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$sales = $stmt->fetchAll();

include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/sidebar.php';
?>
<main>
    <h1>Sales History</h1>

    <div class="card">
        <div class="toolbar">
            <form method="GET" action="">
                <input type="text" name="search" placeholder="Invoice no. ya customer naam"
                       value="<?php echo sanitize($search); ?>">
                <button type="submit">Search</button>
            </form>
            <a href="new-sale.php" class="btn-primary">+ New Sale</a>
            <a href="finishing.php">Order Finishing</a>
        </div>

        <table class="data-table">
            <thead>
                <tr>
                    <th>Invoice No</th>
                    <th>Date</th>
                    <th>Customer</th>
                    <th>Staff</th>
                    <th>Total (₹)</th>
                    <th>Payment</th>
                    <th>Status</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($sales)): ?>
                    <tr><td colspan="8">Koi sale nahi hui abhi tak.</td></tr>
                <?php else: ?>
                    <?php foreach ($sales as $s): ?>
                    <tr>
                        <td><?php echo sanitize($s['invoice_no']); ?></td>
                        <td><?php echo sanitize(date('d M Y, h:i A', strtotime($s['created_at']))); ?></td>
                        <td><?php echo sanitize($s['customer_name'] ?? 'Walk-in'); ?></td>
                        <td><?php echo sanitize($s['staff_name'] ?? '-'); ?></td>
                        <td><?php echo number_format($s['total_amount'], 2); ?></td>
                        <td>
                            <?php echo sanitize(ucfirst($s['payment_status'])); ?>
                            <?php if ($s['payment_status'] === 'credit' || $s['payment_status'] === 'partial'): ?>
                                <span class="badge-warning">Due</span>
                            <?php endif; ?>
                        </td>
                        <td><?php echo sanitize(ucfirst($s['sale_status'])); ?></td>
                        <td>
                            <a href="invoice.php?id=<?php echo (int)$s['sale_id']; ?>">View Invoice</a> |
                            <a href="returns.php?sale_id=<?php echo (int)$s['sale_id']; ?>">Return</a> |
                            <a href="finishing.php?search=<?php echo urlencode($s['invoice_no']); ?>">Finishing</a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
        <p><small>Last 100 sales dikha raha hai. Older records ke liye Reports module use karo.</small></p>
    </div>
</main>
<?php include __DIR__ . '/../../includes/footer.php'; ?>