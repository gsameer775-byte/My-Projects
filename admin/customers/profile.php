<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/functions.php';
requirePermission($pdo, 'customers', 'view');

$customer_id = (int)($_GET['id'] ?? 0);
if ($customer_id === 0) {
    redirect('admin/customers/list.php');
}

$stmt = $pdo->prepare("SELECT * FROM customers WHERE customer_id = :id");
$stmt->execute(['id' => $customer_id]);
$customer = $stmt->fetch();

if (!$customer) {
    redirect('admin/customers/list.php');
}

// Purchase history (sales)
$salesStmt = $pdo->prepare(
    "SELECT sale_id, invoice_no, total_amount, payment_status, sale_status, created_at
     FROM sales WHERE customer_id = :id ORDER BY created_at DESC LIMIT 50"
);
$salesStmt->execute(['id' => $customer_id]);
$sales = $salesStmt->fetchAll();

// Lifetime stats
$statsStmt = $pdo->prepare(
    "SELECT COUNT(*) AS total_orders, COALESCE(SUM(total_amount), 0) AS lifetime_value
     FROM sales WHERE customer_id = :id AND sale_status != 'returned'"
);
$statsStmt->execute(['id' => $customer_id]);
$stats = $statsStmt->fetch();

// Credit ledger history
$ledgerStmt = $pdo->prepare(
    "SELECT cl.*, s.invoice_no
     FROM credit_ledger cl
     LEFT JOIN sales s ON cl.sale_id = s.sale_id
     WHERE cl.customer_id = :id ORDER BY cl.created_at DESC LIMIT 30"
);
$ledgerStmt->execute(['id' => $customer_id]);
$ledgerEntries = $ledgerStmt->fetchAll();

$currentBalance = !empty($ledgerEntries) ? (float)$ledgerEntries[0]['balance_after'] : 0;

// Loyalty transaction history
$loyaltyStmt = $pdo->prepare(
    "SELECT lt.*, s.invoice_no
     FROM loyalty_transactions lt
     LEFT JOIN sales s ON lt.sale_id = s.sale_id
     WHERE lt.customer_id = :id ORDER BY lt.created_at DESC LIMIT 30"
);
$loyaltyStmt->execute(['id' => $customer_id]);
$loyaltyEntries = $loyaltyStmt->fetchAll();

include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/sidebar.php';
?>
<main>
    <h1><?php echo sanitize($customer['name']); ?> <code class="id-chip" onclick="navigator.clipboard.writeText('<?php echo (int)$customer_id; ?>'); this.title='Copied!';" title="Click to copy">#<?php echo (int)$customer_id; ?></code></h1>

    <div class="toolbar">
        <a href="list.php">Back to Customer List</a>
        <a href="add.php?id=<?php echo (int)$customer_id; ?>">Edit Details</a>
        <a href="<?php echo BASE_URL; ?>admin/sales/new-sale.php">+ New Sale for this Customer</a>
    </div>

    <div class="card">
        <h2>Contact Details</h2>
        <div class="form-grid">
            <p><strong>Phone:</strong> <?php echo sanitize($customer['phone']); ?></p>
            <p><strong>Email:</strong> <?php echo sanitize($customer['email'] ?? '-'); ?></p>
            <p><strong>City:</strong> <?php echo sanitize($customer['city'] ?? '-'); ?></p>
            <p><strong>Address:</strong> <?php echo sanitize($customer['address'] ?? '-'); ?></p>
            <p><strong>Birthday:</strong> <?php echo $customer['birthday'] ? sanitize(date('d M', strtotime($customer['birthday']))) : '-'; ?></p>
            <p><strong>Anniversary:</strong> <?php echo $customer['anniversary'] ? sanitize(date('d M', strtotime($customer['anniversary']))) : '-'; ?></p>
        </div>
    </div>

    <div class="card">
        <h2>Summary</h2>
        <div class="form-grid">
            <p><strong>Total Orders:</strong> <?php echo (int)$stats['total_orders']; ?></p>
            <p><strong>Lifetime Value:</strong> ₹<?php echo number_format($stats['lifetime_value'], 2); ?></p>
            <p><strong>Loyalty Points:</strong> <?php echo (int)$customer['loyalty_points']; ?></p>
            <p><strong>Credit Due:</strong>
                <?php if ($currentBalance > 0): ?>
                    <span class="badge-warning">₹<?php echo number_format($currentBalance, 2); ?></span>
                <?php else: ?>
                    ₹0.00
                <?php endif; ?>
            </p>
        </div>
    </div>

    <div class="card">
        <h2>Purchase History</h2>
        <table class="data-table">
            <thead>
                <tr>
                    <th>Invoice No</th>
                    <th>Date</th>
                    <th>Total (₹)</th>
                    <th>Payment</th>
                    <th>Status</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($sales)): ?>
                    <tr><td colspan="6">Abhi tak koi purchase nahi hui.</td></tr>
                <?php else: ?>
                    <?php foreach ($sales as $s): ?>
                    <tr>
                        <td><?php echo sanitize($s['invoice_no']); ?></td>
                        <td><?php echo sanitize(date('d M Y', strtotime($s['created_at']))); ?></td>
                        <td><?php echo number_format($s['total_amount'], 2); ?></td>
                        <td><?php echo sanitize(ucfirst($s['payment_status'])); ?></td>
                        <td><?php echo sanitize(ucfirst($s['sale_status'])); ?></td>
                        <td><a href="<?php echo BASE_URL; ?>admin/sales/invoice.php?id=<?php echo (int)$s['sale_id']; ?>">View</a></td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <div class="card">
        <h2>Credit Ledger (Udhaar History)</h2>
        <table class="data-table">
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Type</th>
                    <th>Invoice</th>
                    <th>Amount</th>
                    <th>Balance After</th>
                    <th>Notes</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($ledgerEntries)): ?>
                    <tr><td colspan="6">Koi credit history nahi hai.</td></tr>
                <?php else: ?>
                    <?php foreach ($ledgerEntries as $l): ?>
                    <tr>
                        <td><?php echo sanitize(date('d M Y', strtotime($l['created_at']))); ?></td>
                        <td><?php echo sanitize($l['entry_type'] === 'credit_given' ? 'Credit Given' : 'Repayment'); ?></td>
                        <td><?php echo sanitize($l['invoice_no'] ?? '-'); ?></td>
                        <td><?php echo ($l['entry_type'] === 'credit_given' ? '+' : '-') . number_format($l['amount'], 2); ?></td>
                        <td>₹<?php echo number_format($l['balance_after'], 2); ?></td>
                        <td><?php echo sanitize($l['notes'] ?? '-'); ?></td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <div class="card">
        <h2>Loyalty Points History</h2>
        <table class="data-table">
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Type</th>
                    <th>Invoice</th>
                    <th>Points</th>
                    <th>Balance After</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($loyaltyEntries)): ?>
                    <tr><td colspan="5">Koi loyalty history nahi hai.</td></tr>
                <?php else: ?>
                    <?php foreach ($loyaltyEntries as $lt): ?>
                    <tr>
                        <td><?php echo sanitize(date('d M Y', strtotime($lt['created_at']))); ?></td>
                        <td><?php echo sanitize(ucfirst($lt['txn_type'])); ?></td>
                        <td><?php echo sanitize($lt['invoice_no'] ?? '-'); ?></td>
                        <td><?php echo ($lt['points'] > 0 ? '+' : '') . (int)$lt['points']; ?></td>
                        <td><?php echo (int)$lt['balance_after']; ?></td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</main>
<?php include __DIR__ . '/../../includes/footer.php'; ?>