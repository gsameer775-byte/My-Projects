<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/functions.php';
requirePermission($pdo, 'sales', 'view');

$error = '';
$success = '';

$sale_id = (int)($_GET['sale_id'] ?? $_POST['sale_id'] ?? 0);
$searchInvoice = sanitize($_GET['invoice'] ?? '');

// If searching by invoice number instead of direct sale_id
if ($sale_id === 0 && $searchInvoice !== '') {
    $findStmt = $pdo->prepare("SELECT sale_id FROM sales WHERE invoice_no = :inv LIMIT 1");
    $findStmt->execute(['inv' => $searchInvoice]);
    $found = $findStmt->fetch();
    if ($found) {
        $sale_id = (int)$found['sale_id'];
    } else {
        $error = 'Ye invoice number nahi mila.';
    }
}

// ---- Handle Return Submission ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $sale_id > 0) {
    requirePermission($pdo, 'sales', 'edit');
    $returnItems = $_POST['return_qty'] ?? []; // [sale_item_id => qty]
    $reason = sanitize($_POST['reason'] ?? '');
    $refund_mode = $_POST['refund_mode'] ?? 'cash';

    $allowedModes = ['cash', 'upi', 'card', 'store_credit'];
    if (!in_array($refund_mode, $allowedModes, true)) {
        $refund_mode = 'cash';
    }

    $anyReturned = false;

    try {
        $pdo->beginTransaction();

        foreach ($returnItems as $saleItemId => $qtyStr) {
            $qty = (int)$qtyStr;
            if ($qty <= 0) continue;

            // Fetch item + already-returned quantity
            $itemStmt = $pdo->prepare(
                "SELECT si.*, sr_sum.already_returned
                 FROM sale_items si
                 LEFT JOIN (
                     SELECT sale_item_id, COALESCE(SUM(quantity),0) AS already_returned
                     FROM sale_returns GROUP BY sale_item_id
                 ) sr_sum ON sr_sum.sale_item_id = si.sale_item_id
                 WHERE si.sale_item_id = :id AND si.sale_id = :sale_id
                 FOR UPDATE"
            );
            $itemStmt->execute(['id' => (int)$saleItemId, 'sale_id' => $sale_id]);
            $item = $itemStmt->fetch();

            if (!$item) continue;

            $alreadyReturned = (int)($item['already_returned'] ?? 0);
            $returnable = $item['quantity'] - $alreadyReturned;

            if ($qty > $returnable) {
                throw new Exception("Item ke liye max {$returnable} hi return ho sakti hai (already {$alreadyReturned} return ho chuki hai).");
            }

            $refundAmount = $item['unit_price'] * $qty;

            // Insert return record
            $pdo->prepare(
                "INSERT INTO sale_returns (sale_item_id, quantity, reason, refund_amount, refund_mode)
                 VALUES (:sale_item_id, :qty, :reason, :refund_amount, :refund_mode)"
            )->execute([
                'sale_item_id' => $saleItemId,
                'qty' => $qty,
                'reason' => $reason ?: null,
                'refund_amount' => $refundAmount,
                'refund_mode' => $refund_mode,
            ]);

            // Mark sale_item as returned if fully returned now
            if (($alreadyReturned + $qty) >= $item['quantity']) {
                $pdo->prepare("UPDATE sale_items SET is_returned = 1 WHERE sale_item_id = :id")
                    ->execute(['id' => $saleItemId]);
            }

            // Restock: increase stock quantity + log movement
            $pdo->prepare("UPDATE stock SET quantity = quantity + :qty WHERE saree_id = :saree_id")
                ->execute(['qty' => $qty, 'saree_id' => $item['saree_id']]);

            $pdo->prepare(
                "INSERT INTO stock_movements (saree_id, change_type, quantity_change, reference_id, notes, created_by)
                 VALUES (:saree_id, 'return', :qty, :sale_id, :notes, :staff_id)"
            )->execute([
                'saree_id' => $item['saree_id'],
                'qty' => $qty,
                'sale_id' => $sale_id,
                'notes' => "Return against sale #$sale_id" . ($reason ? " - $reason" : ''),
                'staff_id' => $_SESSION['staff_id'],
            ]);

            $anyReturned = true;
        }

        if (!$anyReturned) {
            throw new Exception('Kam se kam ek item ke liye return quantity bharo.');
        }

        // Update sale_status: check if all items are now returned (fully) or some (partially)
        $statusCheck = $pdo->prepare(
            "SELECT COUNT(*) AS total, SUM(is_returned) AS returned_count
             FROM sale_items WHERE sale_id = :id"
        );
        $statusCheck->execute(['id' => $sale_id]);
        $counts = $statusCheck->fetch();

        if ((int)$counts['returned_count'] === (int)$counts['total']) {
            $newStatus = 'returned';
        } else {
            $newStatus = 'partially_returned';
        }
        $pdo->prepare("UPDATE sales SET sale_status = :status WHERE sale_id = :id")
            ->execute(['status' => $newStatus, 'id' => $sale_id]);

        $pdo->commit();
        $success = 'Return process ho gaya. Stock update ho gayi hai.';
    } catch (Exception $e) {
        $pdo->rollBack();
        $error = 'Return fail hua: ' . $e->getMessage();
    }
}

// Fetch sale + items for display (after possible processing, to reflect latest state)
$sale = null;
$items = [];

if ($sale_id > 0) {
    $saleStmt = $pdo->prepare(
        "SELECT s.*, c.name AS customer_name, c.phone AS customer_phone
         FROM sales s
         LEFT JOIN customers c ON s.customer_id = c.customer_id
         WHERE s.sale_id = :id"
    );
    $saleStmt->execute(['id' => $sale_id]);
    $sale = $saleStmt->fetch();

    if ($sale) {
        $itemsStmt = $pdo->prepare(
            "SELECT si.*, sr.name AS saree_name, sr.sku,
                    COALESCE(sr_sum.already_returned, 0) AS already_returned
             FROM sale_items si
             JOIN sarees sr ON si.saree_id = sr.saree_id
             LEFT JOIN (
                 SELECT sale_item_id, SUM(quantity) AS already_returned
                 FROM sale_returns GROUP BY sale_item_id
             ) sr_sum ON sr_sum.sale_item_id = si.sale_item_id
             WHERE si.sale_id = :id"
        );
        $itemsStmt->execute(['id' => $sale_id]);
        $items = $itemsStmt->fetchAll();
    }
}

include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/sidebar.php';
?>
<main>
    <h1>Sale Returns</h1>

    <?php if ($error): ?>
        <div class="alert alert-error"><?php echo sanitize($error); ?></div>
    <?php endif; ?>
    <?php if ($success): ?>
        <div class="alert alert-success"><?php echo sanitize($success); ?></div>
    <?php endif; ?>

    <div class="toolbar">
        <a href="history.php">Back to Sales History</a>
    </div>

    <?php if (!$sale): ?>
        <div class="card">
            <h2>Invoice Dhundo</h2>
            <form method="GET" action="" class="inline-form">
                <input type="text" name="invoice" placeholder="Invoice number likho (e.g. HB-20260826-1234)" required>
                <button type="submit">Search</button>
            </form>
        </div>
    <?php else: ?>
        <div class="card">
            <h2>Invoice: <?php echo sanitize($sale['invoice_no']); ?></h2>
            <p><strong>Customer:</strong> <?php echo $sale['customer_name'] ? sanitize($sale['customer_name']) . ' (' . sanitize($sale['customer_phone']) . ')' : 'Walk-in Customer'; ?></p>
            <p><strong>Date:</strong> <?php echo sanitize(date('d M Y, h:i A', strtotime($sale['created_at']))); ?></p>
            <p><strong>Total:</strong> ₹<?php echo number_format($sale['total_amount'], 2); ?></p>
            <p><strong>Sale Status:</strong> <?php echo sanitize(ucfirst($sale['sale_status'])); ?></p>
        </div>

        <div class="card">
            <h2>Items - Select Return Quantity</h2>
            <form method="POST" action="">
                <input type="hidden" name="sale_id" value="<?php echo (int)$sale_id; ?>">

                <table class="data-table">
                    <thead>
                        <tr>
                            <th>SKU</th>
                            <th>Item</th>
                            <th>Sold Qty</th>
                            <th>Already Returned</th>
                            <th>Unit Price</th>
                            <th>Return Qty</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($items as $item): ?>
                        <?php $returnable = $item['quantity'] - $item['already_returned']; ?>
                        <tr>
                            <td><?php echo sanitize($item['sku']); ?></td>
                            <td><?php echo sanitize($item['saree_name']); ?></td>
                            <td><?php echo (int)$item['quantity']; ?></td>
                            <td><?php echo (int)$item['already_returned']; ?></td>
                            <td>₹<?php echo number_format($item['unit_price'], 2); ?></td>
                            <td>
                                <?php if ($returnable > 0): ?>
                                    <input type="number" name="return_qty[<?php echo (int)$item['sale_item_id']; ?>]"
                                           min="0" max="<?php echo (int)$returnable; ?>" value="0" style="width:70px;">
                                    <small>(max <?php echo (int)$returnable; ?>)</small>
                                <?php else: ?>
                                    <span style="color:#999;">Fully returned</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <div class="form-grid" style="margin-top:16px;">
                    <div>
                        <label>Reason</label>
                        <input type="text" name="reason" placeholder="e.g. Size issue, defective, customer changed mind">
                    </div>
                    <div>
                        <label>Refund Mode</label>
                        <select name="refund_mode">
                            <option value="cash">Cash</option>
                            <option value="upi">UPI</option>
                            <option value="card">Card</option>
                            <option value="store_credit">Store Credit</option>
                        </select>
                    </div>
                </div>

                <button type="submit" style="margin-top:16px;">Process Return</button>
            </form>
        </div>
    <?php endif; ?>
</main>
<?php include __DIR__ . '/../../includes/footer.php'; ?>