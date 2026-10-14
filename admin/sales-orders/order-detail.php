<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/functions.php';
requirePermission($pdo, 'sales_orders', 'view');

// ============================================
// Bulk Order Detail
// - Shows a merged timeline of deliveries + payments (Order-to-Cash view)
// - Record Delivery: partial delivery supported, reduces actual stock +
//   releases the matching reservation, updates order status automatically
// - Record Payment: partial/final payments against the order total
// - Cancel: only allowed if nothing has been delivered yet (releases reservation)
// - Overdue banner + WhatsApp reminder link if expected date has passed
// ============================================

$orderId = (int)($_GET['id'] ?? 0);
$error = '';
$success = '';

function getOrder($pdo, $orderId) {
    $stmt = $pdo->prepare(
        "SELECT so.*, c.name AS customer_name, c.phone AS customer_phone
         FROM sales_orders so JOIN customers c ON c.customer_id = so.customer_id
         WHERE so.order_id = :id"
    );
    $stmt->execute(['id' => $orderId]);
    return $stmt->fetch();
}

$order = getOrder($pdo, $orderId);
if (!$order) {
    redirect('admin/sales-orders/list.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requirePermission($pdo, 'sales_orders', 'edit');
    $action = $_POST['action'] ?? '';

    if ($action === 'record_delivery') {
        $deliveryDate = $_POST['delivery_date'] ?? date('Y-m-d');
        $deliveryNotes = sanitize($_POST['delivery_notes'] ?? '');
        $qtys = $_POST['deliver_qty'] ?? []; // [order_item_id => qty]

        try {
            $pdo->beginTransaction();

            $orderCheck = $pdo->prepare("SELECT status FROM sales_orders WHERE order_id = :id FOR UPDATE");
            $orderCheck->execute(['id' => $orderId]);
            $ordRow = $orderCheck->fetch();
            if (!$ordRow || $ordRow['status'] === 'cancelled') throw new Exception('Ye order cancel ho chuka hai.');
            if ($ordRow['status'] === 'delivered') throw new Exception('Ye order already poori tarah deliver ho chuka hai.');

            $anyDelivered = false;
            $deliveryId = null;

            foreach ($qtys as $orderItemId => $qtyStr) {
                $qty = (int)$qtyStr;
                if ($qty <= 0) continue;

                $itemStmt = $pdo->prepare("SELECT * FROM sales_order_items WHERE order_item_id = :id AND order_id = :oid FOR UPDATE");
                $itemStmt->execute(['id' => (int)$orderItemId, 'oid' => $orderId]);
                $item = $itemStmt->fetch();
                if (!$item) continue;

                $remaining = $item['quantity_ordered'] - $item['quantity_delivered'];
                if ($qty > $remaining) {
                    throw new Exception("Item ke liye max {$remaining} hi deliver ho sakta hai.");
                }

                if ($deliveryId === null) {
                    $delStmt = $pdo->prepare(
                        "INSERT INTO sales_order_deliveries (order_id, delivery_date, delivered_by, notes) VALUES (:oid, :date, :by, :notes)"
                    );
                    $delStmt->execute(['oid' => $orderId, 'date' => $deliveryDate, 'by' => $_SESSION['staff_id'], 'notes' => $deliveryNotes ?: null]);
                    $deliveryId = $pdo->lastInsertId();
                }

                $pdo->prepare(
                    "INSERT INTO sales_order_delivery_items (delivery_id, order_item_id, saree_id, quantity_delivered) VALUES (:did, :oiid, :sid, :qty)"
                )->execute(['did' => $deliveryId, 'oiid' => $orderItemId, 'sid' => $item['saree_id'], 'qty' => $qty]);

                $pdo->prepare("UPDATE sales_order_items SET quantity_delivered = quantity_delivered + :qty WHERE order_item_id = :id")
                    ->execute(['qty' => $qty, 'id' => $orderItemId]);

                // Goods physically leave: reduce actual stock AND release the reservation
                $pdo->prepare("UPDATE stock SET quantity = GREATEST(quantity - :qty, 0), reserved_quantity = GREATEST(reserved_quantity - :qty2, 0) WHERE saree_id = :id")
                    ->execute(['qty' => $qty, 'qty2' => $qty, 'id' => $item['saree_id']]);

                $pdo->prepare(
                    "INSERT INTO stock_movements (saree_id, change_type, quantity_change, reference_id, notes, created_by)
                     VALUES (:sid, 'sale', :qty, :oid, :notes, :by)"
                )->execute(['sid' => $item['saree_id'], 'qty' => -$qty, 'oid' => $orderId, 'notes' => "Bulk order delivery #$deliveryId ({$order['order_number']})", 'by' => $_SESSION['staff_id']]);

                $anyDelivered = true;
            }

            if (!$anyDelivered) throw new Exception('Kam se kam ek item ke liye deliver quantity bharo.');

            $totalsStmt = $pdo->prepare("SELECT COALESCE(SUM(quantity_ordered),0) AS ordered, COALESCE(SUM(quantity_delivered),0) AS delivered FROM sales_order_items WHERE order_id = :id");
            $totalsStmt->execute(['id' => $orderId]);
            $totals = $totalsStmt->fetch();
            $newStatus = ((int)$totals['delivered'] >= (int)$totals['ordered']) ? 'delivered' : 'partially_delivered';
            $pdo->prepare("UPDATE sales_orders SET status = :s WHERE order_id = :id")->execute(['s' => $newStatus, 'id' => $orderId]);

            $pdo->prepare("INSERT INTO audit_log (staff_id, action, module_name, record_id, description, ip_address) VALUES (:sid,'update','sales_orders',:rid,:desc,:ip)")
                ->execute(['sid' => $_SESSION['staff_id'], 'rid' => $orderId, 'desc' => "Delivery #$deliveryId recorded (status: $newStatus)", 'ip' => $_SERVER['REMOTE_ADDR'] ?? null]);

            $pdo->commit();
            $success = 'Delivery record ho gayi. <a href="challan.php?delivery_id=' . $deliveryId . '" target="_blank">Challan print karo</a>.';
        } catch (Exception $e) {
            $pdo->rollBack();
            $error = 'Delivery record nahi ho payi: ' . $e->getMessage();
        }
    }

    if ($action === 'record_payment') {
        $amount = (float)($_POST['amount'] ?? 0);
        $mode = $_POST['payment_mode'] ?? '';
        $paidOn = $_POST['paid_on'] ?? date('Y-m-d');
        $notes = sanitize($_POST['notes'] ?? '');
        $allowedModes = ['cash', 'upi', 'bank_transfer', 'cheque'];

        $paidStmt = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM sales_order_payments WHERE order_id = :id");
        $paidStmt->execute(['id' => $orderId]);
        $alreadyPaid = (float)$paidStmt->fetchColumn();
        $due = (float)$order['total_amount'] - $alreadyPaid;

        if ($amount <= 0) {
            $error = 'Amount 0 se zyada hona chahiye.';
        } elseif (!in_array($mode, $allowedModes, true)) {
            $error = 'Payment mode select karo.';
        } elseif ($amount > $due + 0.01) {
            $error = 'Itna due hai hi nahi. Due amount: ₹' . number_format($due, 2);
        } else {
            $type = ($amount >= $due - 0.01) ? 'final' : 'partial';
            $pdo->prepare("INSERT INTO sales_order_payments (order_id, amount, payment_mode, payment_type, paid_on, notes) VALUES (:oid,:amt,:mode,:type,:date,:notes)")
                ->execute(['oid' => $orderId, 'amt' => $amount, 'mode' => $mode, 'type' => $type, 'date' => $paidOn, 'notes' => $notes ?: null]);

            $pdo->prepare("INSERT INTO audit_log (staff_id, action, module_name, record_id, description, ip_address) VALUES (:sid,'create','sales_order_payments',:rid,:desc,:ip)")
                ->execute(['sid' => $_SESSION['staff_id'], 'rid' => $orderId, 'desc' => "Payment of ₹$amount recorded ($mode)", 'ip' => $_SERVER['REMOTE_ADDR'] ?? null]);

            $success = 'Payment record ho gayi.';
        }
    }

    if ($action === 'cancel_order') {
        $deliveredCheck = $pdo->prepare("SELECT COALESCE(SUM(quantity_delivered),0) FROM sales_order_items WHERE order_id = :id");
        $deliveredCheck->execute(['id' => $orderId]);
        if ((int)$deliveredCheck->fetchColumn() > 0) {
            $error = 'Ye order cancel nahi ho sakta - kuch items already deliver ho chuke hain.';
        } else {
            $pdo->beginTransaction();
            $itemsToRelease = $pdo->prepare("SELECT saree_id, quantity_ordered FROM sales_order_items WHERE order_id = :id");
            $itemsToRelease->execute(['id' => $orderId]);
            foreach ($itemsToRelease->fetchAll() as $it) {
                $pdo->prepare("UPDATE stock SET reserved_quantity = GREATEST(reserved_quantity - :qty, 0) WHERE saree_id = :id")
                    ->execute(['qty' => $it['quantity_ordered'], 'id' => $it['saree_id']]);
            }
            $pdo->prepare("UPDATE sales_orders SET status = 'cancelled' WHERE order_id = :id")->execute(['id' => $orderId]);
            $pdo->commit();
            $success = 'Order cancel ho gaya, reserved stock release ho gaya.';
        }
    }

    $order = getOrder($pdo, $orderId);
}

// ---- Items with remaining qty ----
$items = $pdo->prepare(
    "SELECT soi.*, s.name AS saree_name, s.sku FROM sales_order_items soi JOIN sarees s ON s.saree_id = soi.saree_id WHERE soi.order_id = :id"
);
$items->execute(['id' => $orderId]);
$items = $items->fetchAll();

// ---- Payments ----
$payments = $pdo->prepare("SELECT * FROM sales_order_payments WHERE order_id = :id ORDER BY paid_on ASC, payment_id ASC");
$payments->execute(['id' => $orderId]);
$payments = $payments->fetchAll();
$totalPaid = array_sum(array_column($payments, 'amount'));
$due = (float)$order['total_amount'] - $totalPaid;

// ---- Deliveries ----
$deliveries = $pdo->prepare(
    "SELECT sod.*, st.name AS staff_name FROM sales_order_deliveries sod LEFT JOIN staff st ON st.staff_id = sod.delivered_by WHERE sod.order_id = :id ORDER BY sod.delivery_date ASC, sod.delivery_id ASC"
);
$deliveries->execute(['id' => $orderId]);
$deliveries = $deliveries->fetchAll();

// ---- Merge into one timeline ----
$timeline = [];
$timeline[] = ['type' => 'order', 'date' => $order['order_date'], 'label' => 'Order Placed - ' . $order['order_number']];
foreach ($deliveries as $d) {
    $timeline[] = ['type' => 'delivery', 'date' => $d['delivery_date'], 'label' => 'Delivery #' . $d['delivery_id'] . ($d['staff_name'] ? ' by ' . $d['staff_name'] : ''), 'id' => $d['delivery_id']];
}
foreach ($payments as $p) {
    $timeline[] = ['type' => 'payment', 'date' => $p['paid_on'], 'label' => ucfirst($p['payment_type']) . ' payment: ₹' . number_format($p['amount'], 2) . ' (' . ucwords(str_replace('_', ' ', $p['payment_mode'])) . ')'];
}
usort($timeline, fn($a, $b) => strcmp($a['date'], $b['date']));

$today = date('Y-m-d');
$isOverdue = $order['expected_delivery_date'] && $order['expected_delivery_date'] < $today && !in_array($order['status'], ['delivered', 'cancelled'], true);

$waPhone = preg_replace('/[^0-9]/', '', $order['customer_phone']);
if (strlen($waPhone) === 10) { $waPhone = '91' . $waPhone; }
$waMessage = "Namaste {$order['customer_name']}, aapke order {$order['order_number']} ki delivery due thi {$order['expected_delivery_date']} tak. Please confirm karein kab tak delivery le sakte hain.";
$waLink = 'https://wa.me/' . $waPhone . '?text=' . urlencode($waMessage);

include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/sidebar.php';
?>
<main>
    <h1>Order: <?php echo sanitize($order['order_number']); ?></h1>

    <?php if (!empty($_GET['created'])): ?><div class="alert alert-success">Order successfully create ho gaya. Stock reserve ho gaya hai.</div><?php endif; ?>
    <?php if ($error): ?><div class="alert alert-error"><?php echo sanitize($error); ?></div><?php endif; ?>
    <?php if ($success): ?><div class="alert alert-success"><?php echo $success; ?></div><?php endif; ?>

    <?php if ($isOverdue): ?>
        <div class="alert" style="background:#fdecea; color:#b71c1c;">
            <strong>Ye delivery overdue hai</strong> (expected: <?php echo sanitize($order['expected_delivery_date']); ?>).
            <a href="<?php echo $waLink; ?>" target="_blank">Customer ko WhatsApp reminder bhejo</a>
        </div>
    <?php endif; ?>

    <div class="stat-grid">
        <div class="stat-box"><span class="stat-label">Customer</span><span class="stat-value" style="font-size:16px;"><?php echo sanitize($order['customer_name']); ?></span></div>
        <div class="stat-box"><span class="stat-label">Status</span><span class="stat-value" style="font-size:16px;"><?php echo sanitize(ucwords(str_replace('_', ' ', $order['status']))); ?></span></div>
        <div class="stat-box"><span class="stat-label">Total Order Value</span><span class="stat-value">₹<?php echo number_format($order['total_amount'], 2); ?></span></div>
        <div class="stat-box"><span class="stat-label">Paid</span><span class="stat-value">₹<?php echo number_format($totalPaid, 2); ?></span></div>
        <div class="stat-box"><span class="stat-label">Due</span><span class="stat-value" style="color:<?php echo $due > 0 ? '#b71c1c' : '#1e7e34'; ?>;">₹<?php echo number_format($due, 2); ?></span></div>
    </div>

    <div class="card">
        <h2>Order-to-Cash Timeline</h2>
        <ul class="timeline">
            <?php foreach ($timeline as $t): ?>
                <li class="timeline-item timeline-<?php echo $t['type']; ?>">
                    <span class="timeline-date"><?php echo sanitize($t['date']); ?></span>
                    <span class="timeline-label">
                        <?php echo sanitize($t['label']); ?>
                        <?php if ($t['type'] === 'delivery'): ?>
                            - <a href="challan.php?delivery_id=<?php echo (int)$t['id']; ?>" target="_blank">Challan</a>
                        <?php endif; ?>
                    </span>
                </li>
            <?php endforeach; ?>
        </ul>
    </div>

    <?php if (!in_array($order['status'], ['delivered', 'cancelled'], true)): ?>
    <div class="card">
        <h2>Record Delivery</h2>
        <form method="POST" action="">
            <input type="hidden" name="action" value="record_delivery">
            <div class="form-grid" style="margin-bottom:10px;">
                <div><label>Delivery Date</label><input type="date" name="delivery_date" value="<?php echo date('Y-m-d'); ?>"></div>
                <div><label>Notes</label><input type="text" name="delivery_notes" placeholder="optional"></div>
            </div>
            <table class="data-table">
                <thead><tr><th>SKU</th><th>Item</th><th>Ordered</th><th>Delivered So Far</th><th>Deliver Now</th></tr></thead>
                <tbody>
                    <?php foreach ($items as $item): $remaining = $item['quantity_ordered'] - $item['quantity_delivered']; ?>
                    <tr>
                        <td><?php echo sanitize($item['sku']); ?></td>
                        <td><?php echo sanitize($item['saree_name']); ?></td>
                        <td><?php echo (int)$item['quantity_ordered']; ?></td>
                        <td><?php echo (int)$item['quantity_delivered']; ?></td>
                        <td>
                            <?php if ($remaining > 0): ?>
                                <input type="number" name="deliver_qty[<?php echo (int)$item['order_item_id']; ?>]" min="0" max="<?php echo (int)$remaining; ?>" value="0" style="width:70px;">
                                <small>(max <?php echo (int)$remaining; ?>)</small>
                            <?php else: ?>
                                <span style="color:#999;">Fully delivered</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <button type="submit" style="margin-top:12px;">Record Delivery</button>
        </form>
    </div>
    <?php endif; ?>

    <?php if ($due > 0.01): ?>
    <div class="card">
        <h2>Record Payment</h2>
        <form method="POST" action="" class="inline-form">
            <input type="hidden" name="action" value="record_payment">
            <input type="number" step="0.01" min="0.01" max="<?php echo $due; ?>" name="amount" placeholder="Amount (max ₹<?php echo number_format($due, 2); ?>)" required style="width:180px;">
            <select name="payment_mode" required>
                <option value="cash">Cash</option>
                <option value="upi">UPI</option>
                <option value="bank_transfer">Bank Transfer</option>
                <option value="cheque">Cheque</option>
            </select>
            <input type="date" name="paid_on" value="<?php echo date('Y-m-d'); ?>">
            <input type="text" name="notes" placeholder="notes (optional)">
            <button type="submit">Record Payment</button>
        </form>
    </div>
    <?php endif; ?>

    <?php
        $anyDelivered = array_sum(array_column($items, 'quantity_delivered')) > 0;
        if ($order['status'] === 'pending' && !$anyDelivered):
    ?>
    <div class="card">
        <form method="POST" action="" onsubmit="return confirm('Ye order cancel karna hai? Reserved stock release ho jayega.');">
            <input type="hidden" name="action" value="cancel_order">
            <button type="submit" style="background:#b71c1c;">Cancel Order</button>
        </form>
    </div>
    <?php endif; ?>

    <a href="list.php">Back to Bulk Orders</a>
</main>
<style>
.timeline { list-style: none; border-left: 3px solid #3b0a0a; padding-left: 16px; }
.timeline-item { margin-bottom: 14px; position: relative; }
.timeline-item::before { content: ''; position: absolute; left: -21px; top: 4px; width: 10px; height: 10px; border-radius: 50%; background: #3b0a0a; }
.timeline-date { display: block; font-size: 12px; color: #888; }
.timeline-label { font-size: 14px; }
</style>
<?php include __DIR__ . '/../../includes/footer.php'; ?>