<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/functions.php';
requirePermission($pdo, 'customers', 'view');

$error = '';
$success = '';

// ---- Handle Repayment Submission ----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requirePermission($pdo, 'customers', 'edit');
    $customer_id = (int)($_POST['customer_id'] ?? 0);
    $amount = (float)($_POST['amount'] ?? 0);
    $notes = sanitize($_POST['notes'] ?? '');

    if ($customer_id === 0 || $amount <= 0) {
        $error = 'Customer aur valid amount select karo.';
    } else {
        // Get current balance
        $lastLedger = $pdo->prepare(
            "SELECT balance_after FROM credit_ledger WHERE customer_id = :id ORDER BY ledger_id DESC LIMIT 1"
        );
        $lastLedger->execute(['id' => $customer_id]);
        $lastRow = $lastLedger->fetch();
        $currentBalance = $lastRow ? (float)$lastRow['balance_after'] : 0;

        if ($amount > $currentBalance) {
            $error = "Payment amount (₹$amount) current due (₹$currentBalance) se zyada nahi ho sakta.";
        } else {
            $newBalance = $currentBalance - $amount;

            $pdo->prepare(
                "INSERT INTO credit_ledger (customer_id, entry_type, amount, balance_after, notes)
                 VALUES (:cid, 'repayment', :amount, :balance, :notes)"
            )->execute([
                'cid' => $customer_id,
                'amount' => $amount,
                'balance' => $newBalance,
                'notes' => $notes ?: 'Payment received',
            ]);

            $success = 'Payment record ho gayi. Naya balance: ₹' . number_format($newBalance, 2);
        }
    }
}

// ---- Fetch all customers with outstanding balance > 0 ----
$customersWithDue = $pdo->query(
    "SELECT c.customer_id, c.name, c.phone,
            (SELECT balance_after FROM credit_ledger cl
             WHERE cl.customer_id = c.customer_id
             ORDER BY cl.ledger_id DESC LIMIT 1) AS balance
     FROM customers c
     HAVING balance > 0
     ORDER BY balance DESC"
)->fetchAll();

$totalOutstanding = array_sum(array_column($customersWithDue, 'balance'));

include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/sidebar.php';
?>
<main>
    <h1>Credit Ledger (Udhaar Overview)</h1>

    <?php if ($error): ?>
        <div class="alert alert-error"><?php echo sanitize($error); ?></div>
    <?php endif; ?>
    <?php if ($success): ?>
        <div class="alert alert-success"><?php echo sanitize($success); ?></div>
    <?php endif; ?>

    <div class="toolbar">
        <a href="list.php">Back to Customer List</a>
    </div>

    <div class="card">
        <h2>Total Outstanding: ₹<?php echo number_format($totalOutstanding, 2); ?></h2>
        <p><small><?php echo count($customersWithDue); ?> customer(s) ka udhaar bakaya hai.</small></p>
    </div>

    <div class="card">
        <h2>Record a Payment Received</h2>
        <form method="POST" action="" class="form-grid">
            <div>
                <label>Customer *</label>
                <select name="customer_id" required>
                    <option value="">-- Select Customer --</option>
                    <?php foreach ($customersWithDue as $c): ?>
                        <option value="<?php echo (int)$c['customer_id']; ?>">
                            <?php echo sanitize($c['name']) . ' (' . sanitize($c['phone']) . ') - Due: ₹' . number_format($c['balance'], 2); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label>Amount Received (₹) *</label>
                <input type="number" step="0.01" name="amount" required>
            </div>
            <div style="grid-column: span 2;">
                <label>Notes</label>
                <input type="text" name="notes" placeholder="e.g. Cash received, UPI payment">
            </div>
            <div>
                <button type="submit">Record Payment</button>
            </div>
        </form>
    </div>

    <div class="card">
        <h2>Customers with Outstanding Balance</h2>
        <table class="data-table">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Phone</th>
                    <th>Amount Due (₹)</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($customersWithDue)): ?>
                    <tr><td colspan="4">Kisi ka bhi udhaar bakaya nahi hai. 🎉</td></tr>
                <?php else: ?>
                    <?php foreach ($customersWithDue as $c): ?>
                    <tr>
                        <td><?php echo sanitize($c['name']); ?></td>
                        <td><?php echo sanitize($c['phone']); ?></td>
                        <td><span class="badge-warning">₹<?php echo number_format($c['balance'], 2); ?></span></td>
                        <td><a href="profile.php?id=<?php echo (int)$c['customer_id']; ?>">View Full Ledger</a></td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</main>
<?php include __DIR__ . '/../../includes/footer.php'; ?>