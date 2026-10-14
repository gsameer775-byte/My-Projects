<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/functions.php';
requirePermission($pdo, 'vendors', 'view');

// ============================================
// Vendors - Payments
// Global "money we owe our vendors" dashboard, mirrors the Customers
// credit-ledger.php pattern but in reverse (we owe them, not they owe us).
// - Outstanding per vendor = SUM(non-cancelled PO totals) - SUM(payments made)
// - A payment can optionally be tied to a specific PO for cleaner tracking
// - Payment can never exceed what's actually outstanding (system blocks it)
// ============================================

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'record_payment') {
    requirePermission($pdo, 'vendors', 'edit');
    $vendorId = (int)($_POST['vendor_id'] ?? 0);
    $poId     = !empty($_POST['po_id']) ? (int)$_POST['po_id'] : null;
    $amount   = (float)($_POST['amount'] ?? 0);
    $mode     = $_POST['payment_mode'] ?? '';
    $paidOn   = $_POST['paid_on'] ?? date('Y-m-d');
    $notes    = sanitize($_POST['notes'] ?? '');

    $allowedModes = ['cash', 'upi', 'bank_transfer', 'cheque'];

    if ($vendorId <= 0) {
        $error = 'Vendor select karo.';
    } elseif ($amount <= 0) {
        $error = 'Amount 0 se zyada hona chahiye.';
    } elseif (!in_array($mode, $allowedModes, true)) {
        $error = 'Payment mode select karo.';
    } else {
        // Compute this vendor's current outstanding due
        $dueStmt = $pdo->prepare(
            "SELECT
                COALESCE((SELECT SUM(total_amount) FROM purchase_orders WHERE vendor_id = :v1 AND status != 'cancelled'), 0) AS ordered,
                COALESCE((SELECT SUM(amount) FROM vendor_payments WHERE vendor_id = :v2), 0) AS paid"
        );
        $dueStmt->execute(['v1' => $vendorId, 'v2' => $vendorId]);
        $row = $dueStmt->fetch();
        $outstanding = (float)$row['ordered'] - (float)$row['paid'];

        if ($amount > $outstanding + 0.01) {
            $error = 'Itna udhaar hai hi nahi. Is vendor ka outstanding due: ₹' . number_format($outstanding, 2);
        } else {
            $pdo->prepare(
                "INSERT INTO vendor_payments (vendor_id, po_id, amount, payment_mode, paid_on, notes)
                 VALUES (:vid, :po_id, :amount, :mode, :paid_on, :notes)"
            )->execute([
                'vid' => $vendorId, 'po_id' => $poId, 'amount' => $amount,
                'mode' => $mode, 'paid_on' => $paidOn, 'notes' => $notes ?: null,
            ]);

            $pdo->prepare("INSERT INTO audit_log (staff_id, action, module_name, record_id, description, ip_address) VALUES (:sid,'create','vendor_payments',:rid,:desc,:ip)")
                ->execute(['sid' => $_SESSION['staff_id'], 'rid' => $vendorId, 'desc' => "Payment of ₹$amount recorded ($mode)", 'ip' => $_SERVER['REMOTE_ADDR'] ?? null]);

            $success = 'Payment record ho gaya. Naya balance: ₹' . number_format($outstanding - $amount, 2);
        }
    }
}

// ---- Vendors with outstanding balance (for the dropdown + table) ----
$vendorDues = $pdo->query(
    "SELECT v.vendor_id, v.name,
            COALESCE((SELECT SUM(total_amount) FROM purchase_orders WHERE vendor_id = v.vendor_id AND status != 'cancelled'), 0) AS total_ordered,
            COALESCE((SELECT SUM(amount) FROM vendor_payments WHERE vendor_id = v.vendor_id), 0) AS total_paid
     FROM vendors v
     WHERE v.is_active = 1
     ORDER BY v.name ASC"
)->fetchAll();

$vendorDues = array_map(function ($v) {
    $v['due'] = (float)$v['total_ordered'] - (float)$v['total_paid'];
    return $v;
}, $vendorDues);

$vendorsWithDue = array_filter($vendorDues, fn($v) => $v['due'] > 0.01);
$totalOutstanding = array_sum(array_column($vendorsWithDue, 'due'));

// ---- Recent payments log ----
$recentPayments = $pdo->query(
    "SELECT vp.*, v.name AS vendor_name, po.po_number
     FROM vendor_payments vp
     JOIN vendors v ON v.vendor_id = vp.vendor_id
     LEFT JOIN purchase_orders po ON po.po_id = vp.po_id
     ORDER BY vp.paid_on DESC, vp.vendor_payment_id DESC
     LIMIT 30"
)->fetchAll();

include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/sidebar.php';
?>
<main>
    <h1>Vendor Payments</h1>

    <?php if ($error): ?><div class="alert alert-error"><?php echo sanitize($error); ?></div><?php endif; ?>
    <?php if ($success): ?><div class="alert alert-success"><?php echo sanitize($success); ?></div><?php endif; ?>

    <div class="card">
        <h2>Total Outstanding to Vendors</h2>
        <p style="font-size:24px; font-weight:bold; color:#b71c1c;">₹<?php echo number_format($totalOutstanding, 2); ?></p>
    </div>

    <div class="card">
        <h2>Record a Payment</h2>
        <form method="POST" action="" class="form-grid" id="paymentForm">
            <input type="hidden" name="action" value="record_payment">
            <div>
                <label>Vendor *</label>
                <select name="vendor_id" id="vendorSelect" required>
                    <option value="">-- Select Vendor with Due --</option>
                    <?php foreach ($vendorsWithDue as $v): ?>
                        <option value="<?php echo (int)$v['vendor_id']; ?>" data-due="<?php echo sanitize($v['due']); ?>">
                            <?php echo sanitize($v['name']); ?> (Due: ₹<?php echo number_format($v['due'], 2); ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label>Against PO (optional)</label>
                <select name="po_id" id="poSelect">
                    <option value="">-- General Payment --</option>
                </select>
            </div>
            <div>
                <label>Amount (₹) *</label>
                <input type="number" step="0.01" min="0.01" name="amount" id="amountInput" required>
                <small id="dueHint"></small>
            </div>
            <div>
                <label>Payment Mode *</label>
                <select name="payment_mode" required>
                    <option value="cash">Cash</option>
                    <option value="upi">UPI</option>
                    <option value="bank_transfer">Bank Transfer</option>
                    <option value="cheque">Cheque</option>
                </select>
            </div>
            <div>
                <label>Paid On</label>
                <input type="date" name="paid_on" value="<?php echo date('Y-m-d'); ?>">
            </div>
            <div>
                <label>Notes</label>
                <input type="text" name="notes" placeholder="optional">
            </div>
            <div style="grid-column: span 2;">
                <button type="submit">Record Payment</button>
            </div>
        </form>
    </div>

    <div class="card">
        <h2>Vendors with Outstanding Balance</h2>
        <table class="data-table">
            <thead><tr><th>Vendor</th><th>Total Ordered (₹)</th><th>Total Paid (₹)</th><th>Due (₹)</th></tr></thead>
            <tbody>
                <?php if (empty($vendorsWithDue)): ?>
                    <tr><td colspan="4">Koi vendor due nahi hai - sab clear hai.</td></tr>
                <?php else: ?>
                    <?php foreach ($vendorsWithDue as $v): ?>
                    <tr>
                        <td><?php echo sanitize($v['name']); ?></td>
                        <td>₹<?php echo number_format($v['total_ordered'], 2); ?></td>
                        <td>₹<?php echo number_format($v['total_paid'], 2); ?></td>
                        <td><span class="badge-warning">₹<?php echo number_format($v['due'], 2); ?></span></td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <div class="card">
        <h2>Recent Payments</h2>
        <table class="data-table">
            <thead><tr><th>Date</th><th>Vendor</th><th>PO</th><th>Amount (₹)</th><th>Mode</th><th>Notes</th></tr></thead>
            <tbody>
                <?php if (empty($recentPayments)): ?>
                    <tr><td colspan="6">Koi payment record nahi hai abhi tak.</td></tr>
                <?php else: ?>
                    <?php foreach ($recentPayments as $p): ?>
                    <tr>
                        <td><?php echo sanitize($p['paid_on']); ?></td>
                        <td><?php echo sanitize($p['vendor_name']); ?></td>
                        <td><?php echo sanitize($p['po_number'] ?? '-'); ?></td>
                        <td>₹<?php echo number_format($p['amount'], 2); ?></td>
                        <td><?php echo sanitize(ucwords(str_replace('_', ' ', $p['payment_mode']))); ?></td>
                        <td><?php echo sanitize($p['notes'] ?? '-'); ?></td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</main>
<script>
// PO dropdown loads vendor-specific unpaid POs via a small inline dataset
// (kept simple/dependency-free: filtered client-side from a pre-baked JSON map)
const vendorPOs = <?php
    $poMap = [];
    $poRows = $pdo->query(
        "SELECT po.po_id, po.po_number, po.vendor_id, po.total_amount,
                (SELECT COALESCE(SUM(amount),0) FROM vendor_payments WHERE po_id = po.po_id) AS paid_against_po
         FROM purchase_orders po WHERE po.status != 'cancelled'"
    )->fetchAll();
    foreach ($poRows as $po) {
        $poMap[$po['vendor_id']][] = [
            'po_id' => (int)$po['po_id'],
            'po_number' => $po['po_number'],
            'balance' => (float)$po['total_amount'] - (float)$po['paid_against_po'],
        ];
    }
    echo json_encode($poMap);
?>;

const vendorSelect = document.getElementById('vendorSelect');
const poSelect = document.getElementById('poSelect');
const amountInput = document.getElementById('amountInput');
const dueHint = document.getElementById('dueHint');

vendorSelect.addEventListener('change', function () {
    const selected = this.options[this.selectedIndex];
    const due = selected.dataset.due ? parseFloat(selected.dataset.due) : 0;
    dueHint.textContent = due ? ('Max: ₹' + due.toFixed(2)) : '';
    if (due) amountInput.max = due;

    poSelect.innerHTML = '<option value="">-- General Payment --</option>';
    const vid = this.value;
    if (vendorPOs[vid]) {
        vendorPOs[vid].forEach(po => {
            if (po.balance > 0.01) {
                const opt = document.createElement('option');
                opt.value = po.po_id;
                opt.textContent = po.po_number + ' (Balance: ₹' + po.balance.toFixed(2) + ')';
                poSelect.appendChild(opt);
            }
        });
    }
});
</script>
<?php include __DIR__ . '/../../includes/footer.php'; ?>