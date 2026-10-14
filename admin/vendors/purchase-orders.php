<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/functions.php';
requirePermission($pdo, 'vendors', 'view');

// ============================================
// Vendors - Purchase Orders (Create + List)
// - Multi-item PO builder, saree search reused from POS pattern (client-side filter)
// - Server NEVER trusts client-sent totals - unit costs/quantities are recalculated
//   server-side from the submitted line items before saving
// - PO number auto-generated: PO-YYYYMMDD-XXXX
// - Receiving stock (partial or full) happens on receive-po.php
// ============================================

$error = '';
$success = '';

// Sarees for the item picker (client-side search, same approach as POS)
$sareeRows = $pdo->query(
    "SELECT saree_id, sku, name, cost_price FROM sarees WHERE is_active = 1 ORDER BY name ASC"
)->fetchAll();

$vendors = $pdo->query("SELECT vendor_id, name FROM vendors WHERE is_active = 1 ORDER BY name ASC")->fetchAll();

$preselectedVendorId = (int)($_GET['vendor_id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create_po') {
    requirePermission($pdo, 'vendors', 'edit');
    $vendorId = (int)($_POST['vendor_id'] ?? 0);
    $orderDate = $_POST['order_date'] ?? date('Y-m-d');
    $expectedDate = $_POST['expected_date'] ?? '';
    $notes = sanitize($_POST['notes'] ?? '');
    $itemsJson = $_POST['items_data'] ?? '[]';
    $items = json_decode($itemsJson, true);

    if ($vendorId <= 0) {
        $error = 'Vendor select karo.';
    } elseif (empty($items) || !is_array($items)) {
        $error = 'Kam se kam ek item add karo.';
    } else {
        try {
            $pdo->beginTransaction();

            // Validate + recompute every line server-side (never trust client price/total)
            $validatedItems = [];
            $totalAmount = 0;

            foreach ($items as $item) {
                $sareeId = (int)($item['saree_id'] ?? 0);
                $qty = (int)($item['qty'] ?? 0);
                $unitCost = (float)($item['unit_cost'] ?? 0);

                if ($sareeId <= 0 || $qty <= 0 || $unitCost < 0) continue;

                $sareeCheck = $pdo->prepare("SELECT saree_id FROM sarees WHERE saree_id = :id AND is_active = 1");
                $sareeCheck->execute(['id' => $sareeId]);
                if (!$sareeCheck->fetch()) continue;

                $lineTotal = $qty * $unitCost;
                $totalAmount += $lineTotal;
                $validatedItems[] = ['saree_id' => $sareeId, 'qty' => $qty, 'unit_cost' => $unitCost, 'line_total' => $lineTotal];
            }

            if (empty($validatedItems)) {
                throw new Exception('Koi valid item nahi mila. Dobara try karo.');
            }

            $poNumber = 'PO-' . date('Ymd') . '-' . str_pad((string)rand(1, 9999), 4, '0', STR_PAD_LEFT);

            $poStmt = $pdo->prepare(
                "INSERT INTO purchase_orders (po_number, vendor_id, status, total_amount, order_date, expected_date, notes, created_by)
                 VALUES (:po_number, :vendor_id, 'pending', :total, :order_date, :expected_date, :notes, :created_by)"
            );
            $poStmt->execute([
                'po_number' => $poNumber,
                'vendor_id' => $vendorId,
                'total' => $totalAmount,
                'order_date' => $orderDate,
                'expected_date' => $expectedDate ?: null,
                'notes' => $notes ?: null,
                'created_by' => $_SESSION['staff_id'],
            ]);
            $poId = $pdo->lastInsertId();

            $itemStmt = $pdo->prepare(
                "INSERT INTO purchase_order_items (po_id, saree_id, quantity_ordered, quantity_received, unit_cost, line_total)
                 VALUES (:po_id, :saree_id, :qty, 0, :unit_cost, :line_total)"
            );
            foreach ($validatedItems as $vi) {
                $itemStmt->execute([
                    'po_id' => $poId, 'saree_id' => $vi['saree_id'], 'qty' => $vi['qty'],
                    'unit_cost' => $vi['unit_cost'], 'line_total' => $vi['line_total'],
                ]);
            }

            $pdo->prepare("INSERT INTO audit_log (staff_id, action, module_name, record_id, description, ip_address) VALUES (:sid,'create','purchase_orders',:rid,:desc,:ip)")
                ->execute(['sid' => $_SESSION['staff_id'], 'rid' => $poId, 'desc' => "PO $poNumber created (₹$totalAmount)", 'ip' => $_SERVER['REMOTE_ADDR'] ?? null]);

            $pdo->commit();
            redirect('admin/vendors/purchase-orders.php?created=' . $poId);
        } catch (Exception $e) {
            $pdo->rollBack();
            $error = 'PO create nahi ho payi: ' . $e->getMessage();
        }
    }
}

if (($_GET['action'] ?? '') === 'cancel' && !empty($_GET['id'])) {
    requirePermission($pdo, 'vendors', 'edit');
    // Only allow cancelling if nothing has been received yet
    $checkStmt = $pdo->prepare("SELECT COALESCE(SUM(quantity_received),0) AS recvd FROM purchase_order_items WHERE po_id = :id");
    $checkStmt->execute(['id' => (int)$_GET['id']]);
    if ((int)$checkStmt->fetchColumn() === 0) {
        $pdo->prepare("UPDATE purchase_orders SET status = 'cancelled' WHERE po_id = :id AND status = 'pending'")
            ->execute(['id' => (int)$_GET['id']]);
        $success = 'PO cancel ho gaya.';
    } else {
        $error = 'Ye PO cancel nahi ho sakta - kuch items already receive ho chuke hain.';
    }
}

$pos = $pdo->query(
    "SELECT po.*, v.name AS vendor_name,
            (SELECT COALESCE(SUM(quantity_ordered),0) FROM purchase_order_items WHERE po_id = po.po_id) AS total_ordered_qty,
            (SELECT COALESCE(SUM(quantity_received),0) FROM purchase_order_items WHERE po_id = po.po_id) AS total_received_qty
     FROM purchase_orders po
     JOIN vendors v ON v.vendor_id = po.vendor_id
     ORDER BY po.created_at DESC"
)->fetchAll();

include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/sidebar.php';
?>
<main>
    <h1>Purchase Orders</h1>

    <?php if ($error): ?><div class="alert alert-error"><?php echo sanitize($error); ?></div><?php endif; ?>
    <?php if ($success): ?><div class="alert alert-success"><?php echo sanitize($success); ?></div><?php endif; ?>
    <?php if (!empty($_GET['created'])): ?><div class="alert alert-success">PO successfully create ho gaya.</div><?php endif; ?>

    <div class="card">
        <h2>Create New Purchase Order</h2>
        <form method="POST" action="" id="poForm">
            <input type="hidden" name="action" value="create_po">
            <input type="hidden" name="items_data" id="itemsData">

            <div class="form-grid">
                <div>
                    <label>Vendor *</label>
                    <select name="vendor_id" required>
                        <option value="">-- Select Vendor --</option>
                        <?php foreach ($vendors as $v): ?>
                            <option value="<?php echo (int)$v['vendor_id']; ?>" <?php echo $preselectedVendorId === (int)$v['vendor_id'] ? 'selected' : ''; ?>>
                                <?php echo sanitize($v['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label>Order Date</label>
                    <input type="date" name="order_date" value="<?php echo date('Y-m-d'); ?>">
                </div>
                <div>
                    <label>Expected Delivery Date</label>
                    <input type="date" name="expected_date">
                </div>
                <div>
                    <label>Notes</label>
                    <input type="text" name="notes" placeholder="optional">
                </div>
            </div>

            <h3 style="margin:16px 0 8px;">Items</h3>
            <input type="text" id="sareeSearch" placeholder="SKU ya naam se saree search karo...">
            <div class="saree-results" id="sareeResults"></div>

            <table class="data-table" id="itemsTable" style="margin-top:12px;">
                <thead>
                    <tr><th>Saree</th><th>Qty</th><th>Unit Cost (₹)</th><th>Line Total (₹)</th><th></th></tr>
                </thead>
                <tbody id="itemsBody"></tbody>
            </table>

            <div class="totals-box">
                <p><strong>Grand Total: ₹<span id="grandTotal">0.00</span></strong></p>
            </div>

            <button type="submit" id="submitPoBtn" disabled style="margin-top:12px;">Create Purchase Order</button>
        </form>
    </div>

    <div class="card">
        <h2>All Purchase Orders</h2>
        <table class="data-table">
            <thead>
                <tr><th>PO Number</th><th>Vendor</th><th>Status</th><th>Qty Received</th><th>Total (₹)</th><th>Order Date</th><th>Action</th></tr>
            </thead>
            <tbody>
                <?php if (empty($pos)): ?>
                    <tr><td colspan="7">Koi PO nahi bana abhi tak.</td></tr>
                <?php else: ?>
                    <?php foreach ($pos as $po): ?>
                    <tr>
                        <td><?php echo sanitize($po['po_number']); ?></td>
                        <td><?php echo sanitize($po['vendor_name']); ?></td>
                        <td><?php echo sanitize(ucwords(str_replace('_', ' ', $po['status']))); ?></td>
                        <td><?php echo (int)$po['total_received_qty']; ?> / <?php echo (int)$po['total_ordered_qty']; ?></td>
                        <td>₹<?php echo number_format($po['total_amount'], 2); ?></td>
                        <td><?php echo sanitize($po['order_date']); ?></td>
                        <td>
                            <?php if (in_array($po['status'], ['pending', 'partially_received'], true)): ?>
                                <a href="receive-po.php?id=<?php echo (int)$po['po_id']; ?>">Receive</a>
                            <?php endif; ?>
                            <?php if ($po['status'] === 'pending' && (int)$po['total_received_qty'] === 0): ?>
                                | <a href="purchase-orders.php?action=cancel&id=<?php echo (int)$po['po_id']; ?>" onclick="return confirm('Ye PO cancel karna hai?');">Cancel</a>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</main>
<script>
const sarees = <?php echo json_encode($sareeRows); ?>;
const cart = [];

const searchInput = document.getElementById('sareeSearch');
const resultsBox = document.getElementById('sareeResults');
const itemsBody = document.getElementById('itemsBody');
const grandTotalEl = document.getElementById('grandTotal');
const itemsDataField = document.getElementById('itemsData');
const submitBtn = document.getElementById('submitPoBtn');

searchInput.addEventListener('input', function () {
    const q = this.value.trim().toLowerCase();
    resultsBox.innerHTML = '';
    if (q.length < 1) return;

    const matches = sarees.filter(s => s.name.toLowerCase().includes(q) || s.sku.toLowerCase().includes(q)).slice(0, 15);
    matches.forEach(s => {
        const div = document.createElement('div');
        div.className = 'saree-result-item';
        div.textContent = s.sku + ' - ' + s.name + ' (cost ₹' + parseFloat(s.cost_price).toFixed(2) + ')';
        div.addEventListener('click', () => addItem(s));
        resultsBox.appendChild(div);
    });
});

function addItem(saree) {
    cart.push({ saree_id: saree.saree_id, name: saree.name, sku: saree.sku, qty: 1, unit_cost: parseFloat(saree.cost_price) });
    searchInput.value = '';
    resultsBox.innerHTML = '';
    renderCart();
}

function removeItem(idx) {
    cart.splice(idx, 1);
    renderCart();
}

function renderCart() {
    itemsBody.innerHTML = '';
    let grandTotal = 0;

    cart.forEach((item, idx) => {
        const lineTotal = item.qty * item.unit_cost;
        grandTotal += lineTotal;

        const tr = document.createElement('tr');
        tr.innerHTML = `
            <td>${item.sku} - ${item.name}</td>
            <td><input type="number" min="1" value="${item.qty}" style="width:70px;" data-idx="${idx}" class="qty-input"></td>
            <td><input type="number" min="0" step="0.01" value="${item.unit_cost}" style="width:90px;" data-idx="${idx}" class="cost-input"></td>
            <td>₹${lineTotal.toFixed(2)}</td>
            <td><button type="button" data-idx="${idx}" class="remove-btn" style="background:#b71c1c;">X</button></td>
        `;
        itemsBody.appendChild(tr);
    });

    grandTotalEl.textContent = grandTotal.toFixed(2);
    submitBtn.disabled = cart.length === 0;
    itemsDataField.value = JSON.stringify(cart.map(c => ({ saree_id: c.saree_id, qty: c.qty, unit_cost: c.unit_cost })));

    itemsBody.querySelectorAll('.qty-input').forEach(inp => {
        inp.addEventListener('input', function () {
            cart[this.dataset.idx].qty = Math.max(1, parseInt(this.value) || 1);
            renderCart();
        });
    });
    itemsBody.querySelectorAll('.cost-input').forEach(inp => {
        inp.addEventListener('input', function () {
            cart[this.dataset.idx].unit_cost = Math.max(0, parseFloat(this.value) || 0);
            renderCart();
        });
    });
    itemsBody.querySelectorAll('.remove-btn').forEach(btn => {
        btn.addEventListener('click', function () { removeItem(parseInt(this.dataset.idx)); });
    });
}
</script>
<?php include __DIR__ . '/../../includes/footer.php'; ?>