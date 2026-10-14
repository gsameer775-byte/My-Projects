<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/functions.php';
requirePermission($pdo, 'sales_orders', 'view');

// ============================================
// Bulk / Wholesale Sales Orders - List + Create
// - Must have a registered customer (unlike POS walk-in) since these are
//   ongoing B2B relationships that need contact info + credit tracking
// - On create, items get "reserved" in stock (stock.reserved_quantity) so
//   POS can't accidentally sell what's already promised to this order
// - Optional advance payment can be recorded at creation time
// - Actual multi-trip delivery + payment tracking happens on order-detail.php
// ============================================

$error = '';
$success = '';

$customerRows = $pdo->query("SELECT customer_id, name, phone FROM customers WHERE is_active = 1 ORDER BY name ASC")->fetchAll();
$sareeRows = $pdo->query(
    "SELECT s.saree_id, s.sku, s.name, s.selling_price, s.wholesale_price,
            GREATEST(COALESCE(st.quantity,0) - COALESCE(st.reserved_quantity,0), 0) AS available
     FROM sarees s LEFT JOIN stock st ON s.saree_id = st.saree_id
     WHERE s.is_active = 1 ORDER BY s.name ASC"
)->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create_order') {
    requirePermission($pdo, 'sales_orders', 'edit');

    $customerId = (int)($_POST['customer_id'] ?? 0);
    $orderDate = $_POST['order_date'] ?? date('Y-m-d');
    $expectedDate = $_POST['expected_delivery_date'] ?? '';
    $discountAmount = (float)($_POST['discount_amount'] ?? 0);
    $taxPercent = (float)($_POST['tax_percent'] ?? 0);
    $advanceAmount = (float)($_POST['advance_amount'] ?? 0);
    $advanceMode = $_POST['advance_mode'] ?? 'cash';
    $notes = sanitize($_POST['notes'] ?? '');
    $items = json_decode($_POST['items_data'] ?? '[]', true);

    if ($customerId <= 0) {
        $error = 'Customer select karo (bulk order sirf registered customer ke liye ban sakta hai).';
    } elseif (empty($items) || !is_array($items)) {
        $error = 'Kam se kam ek item add karo.';
    } else {
        try {
            $pdo->beginTransaction();

            // Validate + reserve stock for every item (all-or-nothing)
            $validatedItems = [];
            $subtotal = 0;
            $shortfalls = [];

            foreach ($items as $item) {
                $sareeId = (int)($item['saree_id'] ?? 0);
                $qty = (int)($item['qty'] ?? 0);
                $unitPrice = (float)($item['unit_price'] ?? 0);
                if ($sareeId <= 0 || $qty <= 0 || $unitPrice < 0) continue;

                $stockStmt = $pdo->prepare(
                    "SELECT s.name, GREATEST(COALESCE(st.quantity,0) - COALESCE(st.reserved_quantity,0), 0) AS available
                     FROM sarees s LEFT JOIN stock st ON s.saree_id = st.saree_id
                     WHERE s.saree_id = :id FOR UPDATE"
                );
                $stockStmt->execute(['id' => $sareeId]);
                $stockRow = $stockStmt->fetch();

                if (!$stockRow) continue;

                if ($qty > (int)$stockRow['available']) {
                    $shortfalls[] = $stockRow['name'] . ' (available: ' . (int)$stockRow['available'] . ', ordered: ' . $qty . ')';
                    continue;
                }

                $lineTotal = $qty * $unitPrice;
                $subtotal += $lineTotal;
                $validatedItems[] = ['saree_id' => $sareeId, 'qty' => $qty, 'unit_price' => $unitPrice, 'line_total' => $lineTotal];
            }

            if (!empty($shortfalls)) {
                throw new Exception('Itna stock available nahi hai: ' . implode('; ', $shortfalls));
            }
            if (empty($validatedItems)) {
                throw new Exception('Koi valid item nahi mila.');
            }

            $taxAmount = round(($subtotal - $discountAmount) * ($taxPercent / 100), 2);
            $totalAmount = $subtotal - $discountAmount + $taxAmount;

            if ($advanceAmount > $totalAmount) {
                throw new Exception('Advance amount total order value se zyada nahi ho sakta.');
            }

            $orderNumber = 'SO-' . date('Ymd') . '-' . str_pad((string)rand(1, 9999), 4, '0', STR_PAD_LEFT);

            $orderStmt = $pdo->prepare(
                "INSERT INTO sales_orders (order_number, customer_id, order_date, expected_delivery_date, status, subtotal, discount_amount, tax_amount, total_amount, notes, created_by)
                 VALUES (:num, :cid, :odate, :edate, 'pending', :sub, :disc, :tax, :total, :notes, :by)"
            );
            $orderStmt->execute([
                'num' => $orderNumber, 'cid' => $customerId, 'odate' => $orderDate,
                'edate' => $expectedDate ?: null, 'sub' => $subtotal, 'disc' => $discountAmount,
                'tax' => $taxAmount, 'total' => $totalAmount, 'notes' => $notes ?: null, 'by' => $_SESSION['staff_id'],
            ]);
            $orderId = $pdo->lastInsertId();

            $itemStmt = $pdo->prepare(
                "INSERT INTO sales_order_items (order_id, saree_id, quantity_ordered, quantity_delivered, unit_price, line_total)
                 VALUES (:oid, :sid, :qty, 0, :price, :total)"
            );
            foreach ($validatedItems as $vi) {
                $itemStmt->execute(['oid' => $orderId, 'sid' => $vi['saree_id'], 'qty' => $vi['qty'], 'price' => $vi['unit_price'], 'total' => $vi['line_total']]);

                // Reserve the stock - creates a stock row if none exists yet
                $existsStmt = $pdo->prepare("SELECT stock_id FROM stock WHERE saree_id = :id");
                $existsStmt->execute(['id' => $vi['saree_id']]);
                if ($existsStmt->fetch()) {
                    $pdo->prepare("UPDATE stock SET reserved_quantity = reserved_quantity + :qty WHERE saree_id = :id")
                        ->execute(['qty' => $vi['qty'], 'id' => $vi['saree_id']]);
                } else {
                    $pdo->prepare("INSERT INTO stock (saree_id, quantity, reserved_quantity) VALUES (:id, 0, :qty)")
                        ->execute(['id' => $vi['saree_id'], 'qty' => $vi['qty']]);
                }
            }

            if ($advanceAmount > 0) {
                $pdo->prepare(
                    "INSERT INTO sales_order_payments (order_id, amount, payment_mode, payment_type, paid_on)
                     VALUES (:oid, :amt, :mode, 'advance', :date)"
                )->execute(['oid' => $orderId, 'amt' => $advanceAmount, 'mode' => $advanceMode, 'date' => $orderDate]);
            }

            $pdo->prepare("INSERT INTO audit_log (staff_id, action, module_name, record_id, description, ip_address) VALUES (:sid,'create','sales_orders',:rid,:desc,:ip)")
                ->execute(['sid' => $_SESSION['staff_id'], 'rid' => $orderId, 'desc' => "Order $orderNumber created (₹$totalAmount)", 'ip' => $_SERVER['REMOTE_ADDR'] ?? null]);

            $pdo->commit();
            redirect('admin/sales-orders/order-detail.php?id=' . $orderId . '&created=1');
        } catch (Exception $e) {
            $pdo->rollBack();
            $error = 'Order create nahi ho paya: ' . $e->getMessage();
        }
    }
}

$orders = $pdo->query(
    "SELECT so.*, c.name AS customer_name, c.phone AS customer_phone,
            (SELECT COALESCE(SUM(sop.amount),0) FROM sales_order_payments sop WHERE sop.order_id = so.order_id) AS total_paid,
            (SELECT COALESCE(SUM(soi.quantity_ordered),0) FROM sales_order_items soi WHERE soi.order_id = so.order_id) AS total_qty,
            (SELECT COALESCE(SUM(soi.quantity_delivered),0) FROM sales_order_items soi WHERE soi.order_id = so.order_id) AS delivered_qty
     FROM sales_orders so
     JOIN customers c ON c.customer_id = so.customer_id
     ORDER BY so.created_at DESC"
)->fetchAll();

$today = date('Y-m-d');

include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/sidebar.php';
?>
<main>
    <h1>Bulk Orders</h1>

    <?php if ($error): ?><div class="alert alert-error"><?php echo sanitize($error); ?></div><?php endif; ?>

    <div class="card">
        <h2>Create New Bulk Order</h2>
        <form method="POST" action="" id="soForm">
            <input type="hidden" name="action" value="create_order">
            <input type="hidden" name="items_data" id="itemsData">

            <div class="form-grid">
                <div>
                    <label>Customer *</label>
                    <select name="customer_id" required>
                        <option value="">-- Select Customer --</option>
                        <?php foreach ($customerRows as $c): ?>
                            <option value="<?php echo (int)$c['customer_id']; ?>"><?php echo sanitize($c['name']) . ' (' . sanitize($c['phone']) . ')'; ?></option>
                        <?php endforeach; ?>
                    </select>
                    <small>Sirf registered customer. Naya customer pehle <a href="<?php echo BASE_URL; ?>admin/customers/add.php" target="_blank">yahan se</a> add karo.</small>
                </div>
                <div>
                    <label>Order Date</label>
                    <input type="date" name="order_date" value="<?php echo date('Y-m-d'); ?>">
                </div>
                <div>
                    <label>Expected Delivery Date</label>
                    <input type="date" name="expected_delivery_date">
                </div>
                <div>
                    <label>Discount (₹)</label>
                    <input type="number" step="0.01" min="0" name="discount_amount" id="discountInput" value="0">
                </div>
                <div>
                    <label>Tax (%)</label>
                    <input type="number" step="0.01" min="0" name="tax_percent" id="taxInput" value="0">
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
                <thead><tr><th>Saree</th><th>Qty</th><th>Unit Price (₹)</th><th>Line Total (₹)</th><th></th></tr></thead>
                <tbody id="itemsBody"></tbody>
            </table>

            <div class="totals-box" style="margin-top:12px;">
                <p>Subtotal: ₹<span id="subtotalDisplay">0.00</span></p>
                <p><strong>Grand Total: ₹<span id="grandTotal">0.00</span></strong></p>
            </div>

            <h3 style="margin:16px 0 8px;">Advance Payment (optional)</h3>
            <div class="form-grid">
                <div>
                    <label>Advance Amount (₹)</label>
                    <input type="number" step="0.01" min="0" name="advance_amount">
                </div>
                <div>
                    <label>Payment Mode</label>
                    <select name="advance_mode">
                        <option value="cash">Cash</option>
                        <option value="upi">UPI</option>
                        <option value="bank_transfer">Bank Transfer</option>
                        <option value="cheque">Cheque</option>
                    </select>
                </div>
            </div>

            <button type="submit" id="submitBtn" disabled style="margin-top:16px;">Create Order</button>
        </form>
    </div>

    <div class="card">
        <h2>All Bulk Orders</h2>
        <table class="data-table">
            <thead>
                <tr><th>Order #</th><th>Customer</th><th>Status</th><th>Delivered</th><th>Total (₹)</th><th>Paid (₹)</th><th>Due (₹)</th><th>Expected</th><th></th></tr>
            </thead>
            <tbody>
                <?php if (empty($orders)): ?>
                    <tr><td colspan="9">Koi bulk order nahi bana abhi tak.</td></tr>
                <?php else: ?>
                    <?php foreach ($orders as $o):
                        $due = (float)$o['total_amount'] - (float)$o['total_paid'];
                        $isOverdue = $o['expected_delivery_date'] && $o['expected_delivery_date'] < $today && !in_array($o['status'], ['delivered', 'cancelled'], true);
                    ?>
                    <tr>
                        <td><?php echo sanitize($o['order_number']); ?></td>
                        <td><?php echo sanitize($o['customer_name']); ?></td>
                        <td>
                            <?php echo sanitize(ucwords(str_replace('_', ' ', $o['status']))); ?>
                            <?php if ($isOverdue): ?><span class="badge-warning" style="background:#fdecea;color:#b71c1c;">Overdue</span><?php endif; ?>
                        </td>
                        <td><?php echo (int)$o['delivered_qty']; ?> / <?php echo (int)$o['total_qty']; ?></td>
                        <td>₹<?php echo number_format($o['total_amount'], 2); ?></td>
                        <td>₹<?php echo number_format($o['total_paid'], 2); ?></td>
                        <td><?php echo $due > 0 ? '<span class="badge-warning">₹' . number_format($due, 2) . '</span>' : '-'; ?></td>
                        <td><?php echo sanitize($o['expected_delivery_date'] ?? '-'); ?></td>
                        <td><a href="order-detail.php?id=<?php echo (int)$o['order_id']; ?>">Open</a></td>
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
const subtotalDisplay = document.getElementById('subtotalDisplay');
const grandTotalEl = document.getElementById('grandTotal');
const itemsDataField = document.getElementById('itemsData');
const submitBtn = document.getElementById('submitBtn');
const discountInput = document.getElementById('discountInput');
const taxInput = document.getElementById('taxInput');

searchInput.addEventListener('input', function () {
    const q = this.value.trim().toLowerCase();
    resultsBox.innerHTML = '';
    if (q.length < 1) return;
    const matches = sarees.filter(s => s.name.toLowerCase().includes(q) || s.sku.toLowerCase().includes(q)).slice(0, 15);
    matches.forEach(s => {
        const div = document.createElement('div');
        div.className = 'saree-result-item';
        div.textContent = s.sku + ' - ' + s.name + ' (available: ' + s.available + ', wholesale ₹' + parseFloat(s.wholesale_price || s.selling_price).toFixed(2) + ')';
        div.addEventListener('click', () => addItem(s));
        resultsBox.appendChild(div);
    });
});

function addItem(saree) {
    cart.push({
        saree_id: saree.saree_id, name: saree.name, sku: saree.sku, available: saree.available,
        qty: 1, unit_price: parseFloat(saree.wholesale_price || saree.selling_price),
    });
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
    let subtotal = 0;

    cart.forEach((item, idx) => {
        const lineTotal = item.qty * item.unit_price;
        subtotal += lineTotal;
        const over = item.qty > item.available;

        const tr = document.createElement('tr');
        tr.innerHTML = `
            <td>${item.sku} - ${item.name}${over ? ' <span style="color:#b71c1c;font-size:11px;">(only ' + item.available + ' available)</span>' : ''}</td>
            <td><input type="number" min="1" value="${item.qty}" style="width:70px;" data-idx="${idx}" class="qty-input"></td>
            <td><input type="number" min="0" step="0.01" value="${item.unit_price}" style="width:90px;" data-idx="${idx}" class="price-input"></td>
            <td>₹${lineTotal.toFixed(2)}</td>
            <td><button type="button" data-idx="${idx}" class="remove-btn" style="background:#b71c1c;">X</button></td>
        `;
        itemsBody.appendChild(tr);
    });

    const discount = parseFloat(discountInput.value) || 0;
    const taxPercent = parseFloat(taxInput.value) || 0;
    const tax = (subtotal - discount) * (taxPercent / 100);
    const grandTotal = subtotal - discount + tax;

    subtotalDisplay.textContent = subtotal.toFixed(2);
    grandTotalEl.textContent = grandTotal.toFixed(2);
    submitBtn.disabled = cart.length === 0;
    itemsDataField.value = JSON.stringify(cart.map(c => ({ saree_id: c.saree_id, qty: c.qty, unit_price: c.unit_price })));

    itemsBody.querySelectorAll('.qty-input').forEach(inp => {
        inp.addEventListener('input', function () { cart[this.dataset.idx].qty = Math.max(1, parseInt(this.value) || 1); renderCart(); });
    });
    itemsBody.querySelectorAll('.price-input').forEach(inp => {
        inp.addEventListener('input', function () { cart[this.dataset.idx].unit_price = Math.max(0, parseFloat(this.value) || 0); renderCart(); });
    });
    itemsBody.querySelectorAll('.remove-btn').forEach(btn => {
        btn.addEventListener('click', function () { removeItem(parseInt(this.dataset.idx)); });
    });
}

discountInput.addEventListener('input', renderCart);
taxInput.addEventListener('input', renderCart);
</script>
<?php include __DIR__ . '/../../includes/footer.php'; ?>