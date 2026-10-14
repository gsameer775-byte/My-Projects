<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/functions.php';
requirePermission($pdo, 'vendors', 'view');

// ============================================
// Vendors - Receive Purchase Order
// - Supports partial receiving (receive less than ordered, multiple times)
// - On receive: stock quantity increases + stock_movements logged (change_type='purchase')
// - PO status auto-updates: pending -> partially_received -> received
// ============================================

$error = '';
$success = '';

$po_id = (int)($_GET['id'] ?? $_POST['po_id'] ?? 0);
if ($po_id === 0) {
    redirect('admin/vendors/purchase-orders.php');
}

// ---- Handle Receive Submission ----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requirePermission($pdo, 'vendors', 'edit');

    $receiveQtys = $_POST['receive_qty'] ?? []; // [po_item_id => qty]
    $anyReceived = false;

    try {
        $pdo->beginTransaction();

        $poCheck = $pdo->prepare("SELECT status FROM purchase_orders WHERE po_id = :id FOR UPDATE");
        $poCheck->execute(['id' => $po_id]);
        $poRow = $poCheck->fetch();

        if (!$poRow) {
            throw new Exception('PO nahi mila.');
        }
        if ($poRow['status'] === 'cancelled') {
            throw new Exception('Ye PO cancel ho chuka hai, receive nahi ho sakta.');
        }
        if ($poRow['status'] === 'received') {
            throw new Exception('Ye PO already poori tarah receive ho chuka hai.');
        }

        foreach ($receiveQtys as $poItemId => $qtyStr) {
            $qty = (int)$qtyStr;
            if ($qty <= 0) continue;

            $itemStmt = $pdo->prepare(
                "SELECT * FROM purchase_order_items WHERE po_item_id = :id AND po_id = :po_id FOR UPDATE"
            );
            $itemStmt->execute(['id' => (int)$poItemId, 'po_id' => $po_id]);
            $item = $itemStmt->fetch();

            if (!$item) continue;

            $remaining = $item['quantity_ordered'] - $item['quantity_received'];
            if ($qty > $remaining) {
                throw new Exception("Item ke liye max {$remaining} hi receive ho sakta hai (ordered: {$item['quantity_ordered']}, already received: {$item['quantity_received']}).");
            }

            $pdo->prepare(
                "UPDATE purchase_order_items SET quantity_received = quantity_received + :qty WHERE po_item_id = :id"
            )->execute(['qty' => $qty, 'id' => $poItemId]);

            $stockCheck = $pdo->prepare("SELECT quantity FROM stock WHERE saree_id = :id FOR UPDATE");
            $stockCheck->execute(['id' => $item['saree_id']]);
            if ($stockCheck->fetch()) {
                $pdo->prepare("UPDATE stock SET quantity = quantity + :qty WHERE saree_id = :id")
                    ->execute(['qty' => $qty, 'id' => $item['saree_id']]);
            } else {
                $pdo->prepare("INSERT INTO stock (saree_id, quantity) VALUES (:id, :qty)")
                    ->execute(['id' => $item['saree_id'], 'qty' => $qty]);
            }

            $pdo->prepare(
                "INSERT INTO stock_movements (saree_id, change_type, quantity_change, reference_id, notes, created_by)
                 VALUES (:saree_id, 'purchase', :qty, :po_id, :notes, :staff_id)"
            )->execute([
                'saree_id' => $item['saree_id'],
                'qty' => $qty,
                'po_id' => $po_id,
                'notes' => "Received against PO #$po_id",
                'staff_id' => $_SESSION['staff_id'],
            ]);

            $anyReceived = true;
        }

        if (!$anyReceived) {
            throw new Exception('Kam se kam ek item ke liye receive quantity bharo.');
        }

        $totalsStmt = $pdo->prepare(
            "SELECT COALESCE(SUM(quantity_ordered),0) AS total_ordered,
                    COALESCE(SUM(quantity_received),0) AS total_received
             FROM purchase_order_items WHERE po_id = :id"
        );
        $totalsStmt->execute(['id' => $po_id]);
        $totals = $totalsStmt->fetch();

        if ((int)$totals['total_received'] >= (int)$totals['total_ordered']) {
            $newStatus = 'received';
        } else {
            $newStatus = 'partially_received';
        }
        $pdo->prepare("UPDATE purchase_orders SET status = :status WHERE po_id = :id")
            ->execute(['status' => $newStatus, 'id' => $po_id]);

        $pdo->prepare(
            "INSERT INTO audit_log (staff_id, action, module_name, record_id, description, ip_address)
             VALUES (:sid, 'update', 'purchase_orders', :rid, :desc, :ip)"
        )->execute([
            'sid' => $_SESSION['staff_id'],
            'rid' => $po_id,
            'desc' => "Stock received against PO #$po_id (status: $newStatus)",
            'ip' => $_SERVER['REMOTE_ADDR'] ?? null,
        ]);

        $pdo->commit();
        $success = 'Stock receive ho gayi aur inventory update ho gayi.';
    } catch (Exception $e) {
        $pdo->rollBack();
        $error = 'Receive fail hua: ' . $e->getMessage();
    }
}

$poStmt = $pdo->prepare(
    "SELECT po.*, v.name AS vendor_name, v.phone AS vendor_phone
     FROM purchase_orders po
     JOIN vendors v ON v.vendor_id = po.vendor_id
     WHERE po.po_id = :id"
);
$poStmt->execute(['id' => $po_id]);
$po = $poStmt->fetch();

if (!$po) {
    redirect('admin/vendors/purchase-orders.php');
}

$itemsStmt = $pdo->prepare(
    "SELECT poi.*, s.name AS saree_name, s.sku
     FROM purchase_order_items poi
     JOIN sarees s ON s.saree_id = poi.saree_id
     WHERE poi.po_id = :id"
);
$itemsStmt->execute(['id' => $po_id]);
$items = $itemsStmt->fetchAll();

include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/sidebar.php';
?>
<main>
    <h1>Receive Purchase Order</h1>

    <?php if ($error): ?>
        <div class="alert alert-error"><?php echo sanitize($error); ?></div>
    <?php endif; ?>
    <?php if ($success): ?>
        <div class="alert alert-success"><?php echo sanitize($success); ?></div>
    <?php endif; ?>

    <div class="toolbar">
        <a href="purchase-orders.php">Back to Purchase Orders</a>
    </div>

    <div class="card">
        <h2>PO: <?php echo sanitize($po['po_number']); ?></h2>
        <p><strong>Vendor:</strong> <?php echo sanitize($po['vendor_name']); ?> (<?php echo sanitize($po['vendor_phone'] ?? '-'); ?>)</p>
        <p><strong>Order Date:</strong> <?php echo sanitize($po['order_date']); ?></p>
        <p><strong>Status:</strong> <?php echo sanitize(ucwords(str_replace('_', ' ', $po['status']))); ?></p>
        <p><strong>Total Amount:</strong> ₹<?php echo number_format($po['total_amount'], 2); ?></p>
    </div>

    <?php if ($po['status'] === 'received'): ?>
        <div class="card">
            <p>Ye PO poori tarah receive ho chuka hai. Koi item baaki nahi hai.</p>
        </div>
    <?php else: ?>
        <div class="card">
            <h2>Items - Receive Quantity Bharo</h2>
            <form method="POST" action="">
                <input type="hidden" name="po_id" value="<?php echo (int)$po_id; ?>">

                <table class="data-table">
                    <thead>
                        <tr>
                            <th>SKU</th>
                            <th>Item</th>
                            <th>Ordered</th>
                            <th>Already Received</th>
                            <th>Unit Cost</th>
                            <th>Receive Now</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($items as $item): ?>
                        <?php $remaining = $item['quantity_ordered'] - $item['quantity_received']; ?>
                        <tr>
                            <td><?php echo sanitize($item['sku']); ?></td>
                            <td><?php echo sanitize($item['saree_name']); ?></td>
                            <td><?php echo (int)$item['quantity_ordered']; ?></td>
                            <td><?php echo (int)$item['quantity_received']; ?></td>
                            <td>₹<?php echo number_format($item['unit_cost'], 2); ?></td>
                            <td>
                                <?php if ($remaining > 0): ?>
                                    <input type="number" name="receive_qty[<?php echo (int)$item['po_item_id']; ?>]"
                                           min="0" max="<?php echo (int)$remaining; ?>" value="<?php echo (int)$remaining; ?>" style="width:70px;">
                                    <small>(max <?php echo (int)$remaining; ?>)</small>
                                <?php else: ?>
                                    <span style="color:#999;">Fully received</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <p><small>Default me poori remaining quantity bhari hui hai - agar kam mila hai toh number badal do (partial receive ke liye).</small></p>

                <button type="submit" style="margin-top:16px;">Receive Stock</button>
            </form>
        </div>
    <?php endif; ?>
</main>
<?php include __DIR__ . '/../../includes/footer.php'; ?>