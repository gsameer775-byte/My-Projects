<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/functions.php';
requirePermission($pdo, 'sales', 'view');

$error = '';

// Fetch active sarees with stock (for client-side search/add-to-cart)
// Available-to-sell = quantity - reserved_quantity, so stock already promised
// to a confirmed Bulk Order (see admin/sales-orders) can't be double-sold here
$sareeRows = $pdo->query(
    "SELECT s.saree_id, s.sku, s.name, s.selling_price, s.wholesale_price,
            GREATEST(COALESCE(st.quantity, 0) - COALESCE(st.reserved_quantity, 0), 0) AS quantity
     FROM sarees s
     LEFT JOIN stock st ON s.saree_id = st.saree_id
     WHERE s.is_active = 1
     ORDER BY s.name ASC"
)->fetchAll();

// Fetch customers (for search/select)
$customerRows = $pdo->query(
    "SELECT customer_id, name, phone FROM customers WHERE is_active = 1 ORDER BY name ASC"
)->fetchAll();

$defaultTax = $pdo->query(
    "SELECT setting_value FROM system_settings WHERE setting_key = 'default_tax_percent'"
)->fetch();
$defaultTaxPercent = $defaultTax ? (float)$defaultTax['setting_value'] : 0;

// ---- Handle Sale Submission ----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requirePermission($pdo, 'sales', 'edit');
    $cartJson = $_POST['cart_data'] ?? '[]';
    $cart = json_decode($cartJson, true);

    $customer_id = !empty($_POST['customer_id']) ? (int)$_POST['customer_id'] : null;
    $walkin_name = sanitize($_POST['walkin_name'] ?? '');
    $walkin_phone = sanitize($_POST['walkin_phone'] ?? '');
    $register_as_customer = isset($_POST['register_as_customer']);

    // If a walk-in phone matches an existing customer, auto-link instead of
    // storing a duplicate walk-in name/phone (keeps loyalty/credit history intact)
    if (!$customer_id && $walkin_phone !== '') {
        $matchStmt = $pdo->prepare("SELECT customer_id FROM customers WHERE phone = :phone AND is_active = 1 LIMIT 1");
        $matchStmt->execute(['phone' => $walkin_phone]);
        $matched = $matchStmt->fetch();
        if ($matched) {
            $customer_id = (int)$matched['customer_id'];
            $walkin_name = '';
            $walkin_phone = '';
        }
    }
    $sale_type = ($_POST['sale_type'] ?? 'retail') === 'wholesale' ? 'wholesale' : 'retail';
    $discount_amount = (float)($_POST['discount_amount'] ?? 0);
    $tax_percent = (float)($_POST['tax_percent'] ?? 0);
    $payment_mode = $_POST['payment_mode'] ?? 'cash';
    $amount_paid = (float)($_POST['amount_paid'] ?? 0);

    if (empty($cart) || !is_array($cart)) {
        $error = 'Cart khaali hai. Kam se kam ek saree add karo.';
    } elseif ($payment_mode === 'credit' && !$customer_id && !($register_as_customer && $walkin_name !== '' && $walkin_phone !== '')) {
        $error = 'Credit sale ke liye customer select karo, ya walk-in ko naam+phone bharke "Register as Customer" tick karo.';
    } else {
        try {
            $pdo->beginTransaction();

            // If asked to register this walk-in as a new customer, do it now (inside the transaction)
            if (!$customer_id && $register_as_customer && $walkin_name !== '' && $walkin_phone !== '') {
                $dupStmt = $pdo->prepare("SELECT customer_id FROM customers WHERE phone = :phone");
                $dupStmt->execute(['phone' => $walkin_phone]);
                $dup = $dupStmt->fetch();
                if ($dup) {
                    $customer_id = (int)$dup['customer_id'];
                } else {
                    $newCustStmt = $pdo->prepare("INSERT INTO customers (name, phone) VALUES (:name, :phone)");
                    $newCustStmt->execute(['name' => $walkin_name, 'phone' => $walkin_phone]);
                    $customer_id = (int)$pdo->lastInsertId();
                }
                $walkin_name = '';
                $walkin_phone = '';
            }

            // Calculate subtotal from cart (server-side, don't trust client math)
            $subtotal = 0;
            $validatedItems = [];

            foreach ($cart as $item) {
                $sareeId = (int)$item['saree_id'];
                $qty = (int)$item['qty'];

                if ($qty <= 0) continue;

                // Fetch fresh price + stock from DB (never trust client-sent price)
                $sStmt = $pdo->prepare(
                    "SELECT s.selling_price, s.wholesale_price,
                            GREATEST(COALESCE(st.quantity,0) - COALESCE(st.reserved_quantity,0), 0) AS quantity
                     FROM sarees s LEFT JOIN stock st ON s.saree_id = st.saree_id
                     WHERE s.saree_id = :id FOR UPDATE"
                );
                $sStmt->execute(['id' => $sareeId]);
                $sareeData = $sStmt->fetch();

                if (!$sareeData) {
                    throw new Exception("Saree ID $sareeId nahi mili.");
                }
                if ($qty > $sareeData['quantity']) {
                    throw new Exception("Stock kam hai. Available: {$sareeData['quantity']}, Requested: $qty");
                }

                $unitPrice = ($sale_type === 'wholesale' && $sareeData['wholesale_price'])
                    ? $sareeData['wholesale_price']
                    : $sareeData['selling_price'];

                $lineTotal = $unitPrice * $qty;
                $subtotal += $lineTotal;

                $validatedItems[] = [
                    'saree_id' => $sareeId,
                    'qty' => $qty,
                    'unit_price' => $unitPrice,
                    'line_total' => $lineTotal,
                ];
            }

            if (empty($validatedItems)) {
                throw new Exception('Koi valid item cart me nahi hai.');
            }

            $tax_amount = ($subtotal - $discount_amount) * ($tax_percent / 100);
            $pre_round_total = $subtotal - $discount_amount + $tax_amount;

            // Round the final payable amount to the nearest whole rupee (cash
            // payments in India don't use paise) - the difference is recorded
            // as a transparent "Round Off" line rather than silently discarded,
            // so accounts still tally exactly and a customer who pays the
            // rounded amount isn't incorrectly shown as "Partial" for a few paise.
            $total_amount = round($pre_round_total);
            $round_off_amount = round($total_amount - $pre_round_total, 2);

            // Determine payment status
            if ($payment_mode === 'credit') {
                $payment_status = 'credit';
                $amount_paid = 0;
            } elseif ($amount_paid >= $total_amount) {
                $payment_status = 'paid';
                $amount_paid = $total_amount;
            } else {
                $payment_status = 'partial';
            }

            // Generate invoice number
            $prefixRow = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = 'invoice_prefix'")->fetch();
            $prefix = $prefixRow ? $prefixRow['setting_value'] : 'HB-';
            $invoiceNo = $prefix . date('Ymd') . '-' . str_pad((string)rand(1, 9999), 4, '0', STR_PAD_LEFT);

            // Insert sale header
            $saleStmt = $pdo->prepare(
                "INSERT INTO sales
                    (invoice_no, customer_id, walkin_name, walkin_phone, staff_id, sale_type, subtotal, discount_amount, tax_amount, round_off_amount, total_amount, payment_status)
                 VALUES
                    (:invoice_no, :customer_id, :walkin_name, :walkin_phone, :staff_id, :sale_type, :subtotal, :discount_amount, :tax_amount, :round_off, :total_amount, :payment_status)"
            );
            $saleStmt->execute([
                'invoice_no' => $invoiceNo,
                'customer_id' => $customer_id,
                'walkin_name' => $walkin_name !== '' ? $walkin_name : null,
                'walkin_phone' => $walkin_phone !== '' ? $walkin_phone : null,
                'staff_id' => $_SESSION['staff_id'],
                'sale_type' => $sale_type,
                'subtotal' => $subtotal,
                'discount_amount' => $discount_amount,
                'tax_amount' => $tax_amount,
                'round_off' => $round_off_amount,
                'total_amount' => $total_amount,
                'payment_status' => $payment_status,
            ]);
            $saleId = $pdo->lastInsertId();

            // Insert sale items + reduce stock + log stock_movements
            foreach ($validatedItems as $vi) {
                $itemStmt = $pdo->prepare(
                    "INSERT INTO sale_items (sale_id, saree_id, quantity, unit_price, line_total)
                     VALUES (:sale_id, :saree_id, :qty, :unit_price, :line_total)"
                );
                $itemStmt->execute([
                    'sale_id' => $saleId,
                    'saree_id' => $vi['saree_id'],
                    'qty' => $vi['qty'],
                    'unit_price' => $vi['unit_price'],
                    'line_total' => $vi['line_total'],
                ]);

                $pdo->prepare("UPDATE stock SET quantity = quantity - :qty WHERE saree_id = :id")
                    ->execute(['qty' => $vi['qty'], 'id' => $vi['saree_id']]);

                $pdo->prepare(
                    "INSERT INTO stock_movements (saree_id, change_type, quantity_change, reference_id, notes, created_by)
                     VALUES (:saree_id, 'sale', :qty, :sale_id, :notes, :staff_id)"
                )->execute([
                    'saree_id' => $vi['saree_id'],
                    'qty' => -$vi['qty'],
                    'sale_id' => $saleId,
                    'notes' => "Sale invoice $invoiceNo",
                    'staff_id' => $_SESSION['staff_id'],
                ]);
            }

            // Insert payment record (if any amount paid now)
            if ($amount_paid > 0) {
                $pdo->prepare(
                    "INSERT INTO payments (sale_id, payment_mode, amount) VALUES (:sale_id, :mode, :amount)"
                )->execute([
                    'sale_id' => $saleId,
                    'mode' => ($payment_mode === 'credit') ? 'cash' : $payment_mode,
                    'amount' => $amount_paid,
                ]);
            }

            // Handle credit ledger if payment is credit or partial
            $creditDue = $total_amount - $amount_paid;
            if ($creditDue > 0 && $customer_id) {
                // Get current balance
                $lastLedger = $pdo->prepare(
                    "SELECT balance_after FROM credit_ledger WHERE customer_id = :cid ORDER BY ledger_id DESC LIMIT 1"
                );
                $lastLedger->execute(['cid' => $customer_id]);
                $lastRow = $lastLedger->fetch();
                $prevBalance = $lastRow ? (float)$lastRow['balance_after'] : 0;
                $newBalance = $prevBalance + $creditDue;

                $pdo->prepare(
                    "INSERT INTO credit_ledger (customer_id, sale_id, entry_type, amount, balance_after, notes)
                     VALUES (:cid, :sale_id, 'credit_given', :amount, :balance, :notes)"
                )->execute([
                    'cid' => $customer_id,
                    'sale_id' => $saleId,
                    'amount' => $creditDue,
                    'balance' => $newBalance,
                    'notes' => "Credit from invoice $invoiceNo",
                ]);
            }

            // Award loyalty points if customer selected
            if ($customer_id) {
                $loyaltySettings = $pdo->query("SELECT * FROM loyalty_settings LIMIT 1")->fetch();
                if ($loyaltySettings) {
                    $pointsEarned = (int)floor($total_amount * $loyaltySettings['points_per_rupee']);
                    if ($pointsEarned > 0) {
                        $custStmt = $pdo->prepare("SELECT loyalty_points FROM customers WHERE customer_id = :id");
                        $custStmt->execute(['id' => $customer_id]);
                        $currentPoints = (int)$custStmt->fetch()['loyalty_points'];
                        $newPoints = $currentPoints + $pointsEarned;

                        $pdo->prepare("UPDATE customers SET loyalty_points = :pts WHERE customer_id = :id")
                            ->execute(['pts' => $newPoints, 'id' => $customer_id]);

                        $pdo->prepare(
                            "INSERT INTO loyalty_transactions (customer_id, sale_id, txn_type, points, balance_after)
                             VALUES (:cid, :sale_id, 'earned', :points, :balance)"
                        )->execute([
                            'cid' => $customer_id,
                            'sale_id' => $saleId,
                            'points' => $pointsEarned,
                            'balance' => $newPoints,
                        ]);
                    }
                }
            }

            $pdo->commit();
            redirect('admin/sales/invoice.php?id=' . $saleId);
        } catch (Exception $e) {
            $pdo->rollBack();
            $error = 'Sale save nahi hui: ' . $e->getMessage();
        }
    }
}

include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/sidebar.php';
?>
<main>
    <h1>New Sale (POS)</h1>

    <div class="toolbar">
        <a href="history.php">View Sales History</a>
        <a href="log-enquiry.php">Log Enquiry (Customer bina liye gaya?)</a>
    </div>

    <?php if ($error): ?>
        <div class="alert alert-error"><?php echo sanitize($error); ?></div>
    <?php endif; ?>

    <div class="pos-grid">
        <div class="card">
            <h2>Search & Add Sarees</h2>
            <input type="text" id="sareeSearch" placeholder="SKU ya naam se search karo, ya barcode scan karo...">
            <small>USB barcode scanner is box me seedha scan kar sakta hai — scan hote hi item cart me add ho jayega.</small>            <div id="sareeResults" class="saree-results"></div>
        </div>

        <div class="card">
            <h2>Cart</h2>
            <table class="data-table" id="cartTable">
                <thead>
                    <tr><th>Item</th><th>Qty</th><th>Price</th><th>Total</th><th></th></tr>
                </thead>
                <tbody id="cartBody">
                    <tr id="emptyCartRow"><td colspan="5">Cart khaali hai</td></tr>
                </tbody>
            </table>

            <form method="POST" action="" id="saleForm">
                <input type="hidden" name="cart_data" id="cartDataInput">

                <div class="form-grid" style="margin-top:16px;">
                    <div>
                        <label>Customer (optional)</label>
                        <select name="customer_id" id="customerSelect" onchange="document.getElementById('walkinFields').style.display = this.value === '' ? 'flex' : 'none';">
                            <option value="">-- Walk-in Customer --</option>
                            <?php foreach ($customerRows as $c): ?>
                                <option value="<?php echo (int)$c['customer_id']; ?>">
                                    <?php echo sanitize($c['name']) . ' (' . sanitize($c['phone']) . ')'; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div id="walkinFields" style="display:flex; gap:10px; grid-column: span 2;">
                        <div style="flex:1;">
                            <label>Walk-in Name (optional)</label>
                            <input type="text" name="walkin_name" placeholder="e.g. Ramesh">
                        </div>
                        <div style="flex:1;">
                            <label>Walk-in Phone (optional)</label>
                            <input type="text" name="walkin_phone" placeholder="10-digit mobile">
                            <small>Agar ye number kisi existing customer ka hai, sale automatically unse link ho jayegi.</small>
                        </div>
                        <div style="flex:1; display:flex; align-items:center; gap:6px; margin-top:20px;">
                            <input type="checkbox" name="register_as_customer" id="registerAsCustomer" style="width:auto;">
                            <label for="registerAsCustomer" style="margin:0;">Register as Customer (loyalty points bhi milenge)</label>
                        </div>
                    </div>
                    <div>
                        <label>Sale Type</label>
                        <select name="sale_type" id="saleType">
                            <option value="retail">Retail</option>
                            <option value="wholesale">Wholesale</option>
                        </select>
                    </div>
                    <div>
                        <label>Discount (₹)</label>
                        <input type="number" step="0.01" name="discount_amount" id="discountInput" value="0">
                    </div>
                    <div>
                        <label>Tax (%)</label>
                        <input type="number" step="0.01" name="tax_percent" id="taxInput" value="<?php echo $defaultTaxPercent; ?>">
                    </div>
                    <div>
                        <label>Payment Mode</label>
                        <select name="payment_mode" id="paymentMode">
                            <option value="cash">Cash</option>
                            <option value="upi">UPI</option>
                            <option value="card">Card</option>
                            <option value="credit">Credit (Udhaar)</option>
                        </select>
                    </div>
                    <div>
                        <label>Amount Paid Now (₹)</label>
                        <input type="number" step="0.01" name="amount_paid" id="amountPaidInput" value="0">
                    </div>
                </div>

                <div class="totals-box">
                    <p>Subtotal: ₹<span id="subtotalDisplay">0.00</span></p>
                    <p>Discount: ₹<span id="discountDisplay">0.00</span></p>
                    <p>Tax: ₹<span id="taxDisplay">0.00</span></p>
                    <p>Round Off: ₹<span id="roundOffDisplay">0.00</span></p>
                    <p><strong>Total: ₹<span id="totalDisplay">0.00</span></strong></p>
                </div>

                <button type="submit" id="submitSaleBtn">Complete Sale</button>
            </form>
        </div>
    </div>
</main>

<script>
const sareeData = <?php echo json_encode($sareeRows); ?>;
let cart = [];

const searchInput = document.getElementById('sareeSearch');
const resultsDiv = document.getElementById('sareeResults');

searchInput.addEventListener('input', function() {
    const q = this.value.trim().toLowerCase();
    resultsDiv.innerHTML = '';
    if (q.length < 1) return;

    const matches = sareeData.filter(s =>
        s.sku.toLowerCase().includes(q) || s.name.toLowerCase().includes(q)
    ).slice(0, 10);

    matches.forEach(s => {
        const div = document.createElement('div');
        div.className = 'saree-result-item';
        div.innerHTML = `<strong>${s.name}</strong> (${s.sku}) - Rs.${s.selling_price} - Stock: ${s.quantity}`;
        div.onclick = () => addToCart(s);
        resultsDiv.appendChild(div);
    });
});

// USB barcode scanners work like a keyboard: they type the SKU very fast
// and then send Enter. So if the typed text is an exact SKU match and
// Enter is pressed, scan-and-add straight to cart - no click needed.
searchInput.addEventListener('keydown', function(e) {
    if (e.key !== 'Enter') return;
    e.preventDefault();

    const typed = this.value.trim();
    if (!typed) return;

    const exactMatch = sareeData.find(s => s.sku.toLowerCase() === typed.toLowerCase());
    if (exactMatch) {
        addToCart(exactMatch);
        this.value = '';
        resultsDiv.innerHTML = '';
    } else {
        // No exact SKU match - fall back to showing filtered results (typed by hand)
        const q = typed.toLowerCase();
        const matches = sareeData.filter(s =>
            s.sku.toLowerCase().includes(q) || s.name.toLowerCase().includes(q)
        );
        if (matches.length === 1) {
            addToCart(matches[0]);
            this.value = '';
            resultsDiv.innerHTML = '';
        }
    }
});

function addToCart(saree) {
    if (saree.quantity <= 0) {
        alert('Stock khatam hai.');
        return;
    }
    const existing = cart.find(c => c.saree_id === saree.saree_id);
    if (existing) {
        if (existing.qty + 1 > saree.quantity) {
            alert('Available stock se zyada nahi le sakte.');
            return;
        }
        existing.qty += 1;
    } else {
        cart.push({
            saree_id: saree.saree_id,
            sku: saree.sku,
            name: saree.name,
            price: parseFloat(document.getElementById('saleType').value === 'wholesale' && saree.wholesale_price ? saree.wholesale_price : saree.selling_price),
            max_stock: saree.quantity,
            qty: 1
        });
    }
    renderCart();
    searchInput.value = '';
    resultsDiv.innerHTML = '';
}

function removeFromCart(sareeId) {
    cart = cart.filter(c => c.saree_id !== sareeId);
    renderCart();
}

function updateQty(sareeId, newQty) {
    const item = cart.find(c => c.saree_id === sareeId);
    if (!item) return;
    newQty = parseInt(newQty);
    if (newQty < 1) newQty = 1;
    if (newQty > item.max_stock) {
        alert('Available stock se zyada nahi le sakte. Max: ' + item.max_stock);
        newQty = item.max_stock;
    }
    item.qty = newQty;
    renderCart();
}

function renderCart() {
    const body = document.getElementById('cartBody');
    body.innerHTML = '';

    if (cart.length === 0) {
        body.innerHTML = '<tr id="emptyCartRow"><td colspan="5">Cart khaali hai</td></tr>';
    } else {
        cart.forEach(item => {
            const row = document.createElement('tr');
            row.innerHTML = `
                <td>${item.name} (${item.sku})</td>
                <td><input type="number" min="1" max="${item.max_stock}" value="${item.qty}" style="width:60px;" onchange="updateQty(${item.saree_id}, this.value)"></td>
                <td>Rs.${item.price.toFixed(2)}</td>
                <td>Rs.${(item.price * item.qty).toFixed(2)}</td>
                <td><a href="#" onclick="removeFromCart(${item.saree_id}); return false;">Remove</a></td>
            `;
            body.appendChild(row);
        });
    }

    calculateTotals();
}

function calculateTotals() {
    const subtotal = cart.reduce((sum, item) => sum + (item.price * item.qty), 0);
    const discount = parseFloat(document.getElementById('discountInput').value) || 0;
    const taxPercent = parseFloat(document.getElementById('taxInput').value) || 0;
    const taxAmount = (subtotal - discount) * (taxPercent / 100);
    const preRoundTotal = subtotal - discount + taxAmount;
    const total = Math.round(preRoundTotal); // rounded to nearest rupee, same as server
    const roundOff = total - preRoundTotal;

    document.getElementById('subtotalDisplay').textContent = subtotal.toFixed(2);
    document.getElementById('discountDisplay').textContent = discount.toFixed(2);
    document.getElementById('taxDisplay').textContent = taxAmount.toFixed(2);
    document.getElementById('roundOffDisplay').textContent = (roundOff >= 0 ? '+' : '') + roundOff.toFixed(2);
    document.getElementById('totalDisplay').textContent = total.toFixed(2);
}

document.getElementById('discountInput').addEventListener('input', calculateTotals);
document.getElementById('taxInput').addEventListener('input', calculateTotals);
document.getElementById('saleType').addEventListener('change', function() {
    // Recalculate cart item prices based on new sale type
    cart.forEach(item => {
        const saree = sareeData.find(s => s.saree_id === item.saree_id);
        item.price = parseFloat(this.value === 'wholesale' && saree.wholesale_price ? saree.wholesale_price : saree.selling_price);
    });
    renderCart();
});

document.getElementById('paymentMode').addEventListener('change', function() {
    const total = parseFloat(document.getElementById('totalDisplay').textContent) || 0;
    if (this.value === 'credit') {
        document.getElementById('amountPaidInput').value = 0;
    } else {
        document.getElementById('amountPaidInput').value = total.toFixed(2);
    }
});

document.getElementById('saleForm').addEventListener('submit', function(e) {
    if (cart.length === 0) {
        e.preventDefault();
        alert('Cart me kam se kam ek saree add karo.');
        return;
    }
    document.getElementById('cartDataInput').value = JSON.stringify(cart.map(c => ({saree_id: c.saree_id, qty: c.qty})));
});
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>