<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/functions.php';
requirePermission($pdo, 'settings', 'view');

// ============================================
// Manual match for a single bank transaction - shown when auto-match found
// zero or multiple candidates. Reuses the same candidate-finding logic as
// bank-reconciliation.php (getCandidates), but with a wider date window and
// no amount filter by default so the admin can browse and pick manually.
// ============================================

$bankTxnId = (int)($_GET['id'] ?? 0);
$stmt = $pdo->prepare("SELECT * FROM bank_transactions WHERE bank_txn_id = :id");
$stmt->execute(['id' => $bankTxnId]);
$txn = $stmt->fetch();

if (!$txn) {
    redirect('admin/settings/bank-reconciliation.php');
}

function getCandidates($pdo, $direction, $date = null, $amount = null) {
    $already = $pdo->query("SELECT matched_source, matched_id FROM bank_transactions WHERE match_status = 'matched'")->fetchAll();
    $usedKeys = array_map(fn($r) => $r['matched_source'] . ':' . $r['matched_id'], $already);
    $candidates = [];

    if ($direction === 'credit') {
        foreach ($pdo->query("SELECT sale_id, total_amount, created_at, invoice_no FROM sales WHERE sale_status = 'completed'")->fetchAll() as $r) {
            if (in_array('sale:' . $r['sale_id'], $usedKeys, true)) continue;
            $candidates[] = ['source' => 'sale', 'id' => $r['sale_id'], 'date' => substr($r['created_at'], 0, 10), 'amount' => (float)$r['total_amount'], 'label' => 'Sale ' . $r['invoice_no']];
        }
        foreach ($pdo->query("SELECT payment_id, amount, paid_on, order_id FROM sales_order_payments")->fetchAll() as $r) {
            if (in_array('sales_order_payment:' . $r['payment_id'], $usedKeys, true)) continue;
            $candidates[] = ['source' => 'sales_order_payment', 'id' => $r['payment_id'], 'date' => $r['paid_on'], 'amount' => (float)$r['amount'], 'label' => 'Bulk Order Payment #' . $r['payment_id']];
        }
        foreach ($pdo->query("SELECT ledger_id, amount, created_at FROM credit_ledger WHERE entry_type = 'repayment'")->fetchAll() as $r) {
            if (in_array('credit_repayment:' . $r['ledger_id'], $usedKeys, true)) continue;
            $candidates[] = ['source' => 'credit_repayment', 'id' => $r['ledger_id'], 'date' => substr($r['created_at'], 0, 10), 'amount' => (float)$r['amount'], 'label' => 'Credit Repayment #' . $r['ledger_id']];
        }
    } else {
        foreach ($pdo->query("SELECT vendor_payment_id, amount, paid_on, vendor_id FROM vendor_payments")->fetchAll() as $r) {
            if (in_array('vendor_payment:' . $r['vendor_payment_id'], $usedKeys, true)) continue;
            $candidates[] = ['source' => 'vendor_payment', 'id' => $r['vendor_payment_id'], 'date' => $r['paid_on'], 'amount' => (float)$r['amount'], 'label' => 'Vendor Payment #' . $r['vendor_payment_id']];
        }
        foreach ($pdo->query("SELECT expense_id, amount, expense_date FROM expenses")->fetchAll() as $r) {
            if (in_array('expense:' . $r['expense_id'], $usedKeys, true)) continue;
            $candidates[] = ['source' => 'expense', 'id' => $r['expense_id'], 'date' => $r['expense_date'], 'amount' => (float)$r['amount'], 'label' => 'Expense #' . $r['expense_id']];
        }
    }

    if ($date !== null) {
        $candidates = array_filter($candidates, fn($c) => abs(strtotime($c['date']) - strtotime($date)) <= 30 * 86400);
    }

    usort($candidates, fn($a, $b) => abs($a['amount'] - $amount) <=> abs($b['amount'] - $amount));
    return array_values($candidates);
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'confirm_match') {
    requirePermission($pdo, 'settings', 'edit');
    $source = $_POST['source'] ?? '';
    $sourceId = (int)($_POST['source_id'] ?? 0);

    if ($source === '' || $sourceId <= 0) {
        $error = 'Ek candidate select karo.';
    } else {
        $pdo->prepare("UPDATE bank_transactions SET match_status = 'matched', matched_source = :src, matched_id = :id WHERE bank_txn_id = :txn")
            ->execute(['src' => $source, 'id' => $sourceId, 'txn' => $bankTxnId]);
        redirect('admin/settings/bank-reconciliation.php');
    }
}

$candidates = getCandidates($pdo, $txn['direction'], $txn['transaction_date'], $txn['amount']);

include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/sidebar.php';
?>
<main>
    <h1>Match Bank Transaction</h1>

    <?php if ($error): ?><div class="alert alert-error"><?php echo sanitize($error); ?></div><?php endif; ?>

    <div class="card">
        <h2>Bank Transaction</h2>
        <p><strong>Date:</strong> <?php echo sanitize($txn['transaction_date']); ?></p>
        <p><strong>Description:</strong> <?php echo sanitize($txn['description']); ?></p>
        <p><strong>Amount:</strong> ₹<?php echo number_format($txn['amount'], 2); ?> (<?php echo $txn['direction'] === 'credit' ? 'Money In' : 'Money Out'; ?>)</p>
    </div>

    <div class="card">
        <h2>Possible Matches (closest amount first, ±30 din)</h2>
        <?php if (empty($candidates)): ?>
            <p>Koi candidate nahi mila. Ho sakta hai ye ek aisi transaction ho jo system me record hi nahi hai (jaise bank charges, interest) - is case me "Ignore" use karo bank-reconciliation.php se.</p>
        <?php else: ?>
        <form method="POST" action="">
            <input type="hidden" name="action" value="confirm_match">
            <table class="data-table">
                <thead><tr><th></th><th>Record</th><th>Date</th><th>Amount (₹)</th><th>Difference</th></tr></thead>
                <tbody>
                    <?php foreach ($candidates as $c): $diff = abs($c['amount'] - $txn['amount']); ?>
                    <tr>
                        <td><input type="radio" name="candidate" value="<?php echo sanitize($c['source']); ?>|<?php echo (int)$c['id']; ?>" onclick="document.getElementById('sourceField').value='<?php echo sanitize($c['source']); ?>';document.getElementById('sourceIdField').value='<?php echo (int)$c['id']; ?>';"></td>
                        <td><?php echo sanitize($c['label']); ?></td>
                        <td><?php echo sanitize($c['date']); ?></td>
                        <td>₹<?php echo number_format($c['amount'], 2); ?></td>
                        <td><?php echo $diff < 0.01 ? '<strong style="color:#1e7e34;">Exact</strong>' : '₹' . number_format($diff, 2); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <input type="hidden" name="source" id="sourceField">
            <input type="hidden" name="source_id" id="sourceIdField">
            <button type="submit" style="margin-top:12px;">Confirm Match</button>
        </form>
        <?php endif; ?>
    </div>

    <a href="bank-reconciliation.php">Back to Bank Reconciliation</a>
</main>
<?php include __DIR__ . '/../../includes/footer.php'; ?>