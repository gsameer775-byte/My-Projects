<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/functions.php';
requirePermission($pdo, 'sales_orders', 'view');

// ============================================
// Delivery Challan - printable proof-of-delivery slip for one delivery trip
// (a bulk order can have several of these, one per partial delivery)
// ============================================

$delivery_id = (int)($_GET['delivery_id'] ?? 0);
if ($delivery_id === 0) {
    redirect('admin/sales-orders/list.php');
}

$stmt = $pdo->prepare(
    "SELECT sod.*, so.order_number, so.order_id, c.name AS customer_name, c.phone AS customer_phone, c.address AS customer_address,
            st.name AS staff_name
     FROM sales_order_deliveries sod
     JOIN sales_orders so ON so.order_id = sod.order_id
     JOIN customers c ON c.customer_id = so.customer_id
     LEFT JOIN staff st ON st.staff_id = sod.delivered_by
     WHERE sod.delivery_id = :id"
);
$stmt->execute(['id' => $delivery_id]);
$delivery = $stmt->fetch();

if (!$delivery) {
    redirect('admin/sales-orders/list.php');
}

$itemsStmt = $pdo->prepare(
    "SELECT sodi.quantity_delivered, s.name AS saree_name, s.sku, soi.unit_price
     FROM sales_order_delivery_items sodi
     JOIN sarees s ON s.saree_id = sodi.saree_id
     JOIN sales_order_items soi ON soi.order_item_id = sodi.order_item_id
     WHERE sodi.delivery_id = :id"
);
$itemsStmt->execute(['id' => $delivery_id]);
$items = $itemsStmt->fetchAll();

$totalQty = array_sum(array_column($items, 'quantity_delivered'));
$totalValue = array_sum(array_map(fn($i) => $i['quantity_delivered'] * $i['unit_price'], $items));

$shopSettings = $pdo->query(
    "SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ('shop_name', 'shop_address', 'shop_gstin')"
)->fetchAll(PDO::FETCH_KEY_PAIR);

include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/sidebar.php';
?>
<main>
    <div class="toolbar no-print">
        <a href="order-detail.php?id=<?php echo (int)$delivery['order_id']; ?>">Back to Order</a>
        <button onclick="window.print()">Print Challan</button>
        <button onclick="shareOnWhatsApp()">Share on WhatsApp</button>
    </div>

    <div class="card invoice-box">
        <div class="invoice-header">
            <h1><?php echo sanitize($shopSettings['shop_name'] ?? SITE_NAME); ?></h1>
            <?php if (!empty($shopSettings['shop_address'])): ?><p><?php echo sanitize($shopSettings['shop_address']); ?></p><?php endif; ?>
            <?php if (!empty($shopSettings['shop_gstin'])): ?><p>GSTIN: <?php echo sanitize($shopSettings['shop_gstin']); ?></p><?php endif; ?>
            <h2 style="margin-top:10px;">DELIVERY CHALLAN</h2>
        </div>

        <hr>

        <div class="invoice-meta">
            <p><strong>Order No:</strong> <?php echo sanitize($delivery['order_number']); ?></p>
            <p><strong>Challan No:</strong> DC-<?php echo (int)$delivery['delivery_id']; ?></p>
            <p><strong>Delivery Date:</strong> <?php echo sanitize($delivery['delivery_date']); ?></p>
            <?php if ($delivery['staff_name']): ?><p><strong>Delivered By:</strong> <?php echo sanitize($delivery['staff_name']); ?></p><?php endif; ?>
        </div>

        <p><strong>Customer:</strong> <?php echo sanitize($delivery['customer_name']); ?> (<?php echo sanitize($delivery['customer_phone']); ?>)</p>
        <?php if (!empty($delivery['customer_address'])): ?><p><strong>Address:</strong> <?php echo sanitize($delivery['customer_address']); ?></p><?php endif; ?>

        <table class="data-table" style="margin-top:16px;">
            <thead><tr><th>SKU</th><th>Item</th><th>Qty</th><th>Unit Price (₹)</th><th>Value (₹)</th></tr></thead>
            <tbody>
                <?php foreach ($items as $i): ?>
                <tr>
                    <td><?php echo sanitize($i['sku']); ?></td>
                    <td><?php echo sanitize($i['saree_name']); ?></td>
                    <td><?php echo (int)$i['quantity_delivered']; ?></td>
                    <td>₹<?php echo number_format($i['unit_price'], 2); ?></td>
                    <td>₹<?php echo number_format($i['quantity_delivered'] * $i['unit_price'], 2); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <p style="margin-top:12px;"><strong>Total Qty Delivered:</strong> <?php echo (int)$totalQty; ?></p>
        <p><strong>Total Value:</strong> ₹<?php echo number_format($totalValue, 2); ?></p>

        <?php if (!empty($delivery['notes'])): ?><p><strong>Notes:</strong> <?php echo sanitize($delivery['notes']); ?></p><?php endif; ?>

        <div style="margin-top:40px; display:flex; justify-content:space-between;">
            <div>_____________________<br><small>Delivered By</small></div>
            <div>_____________________<br><small>Received By (Customer Signature)</small></div>
        </div>
    </div>
</main>
<style>
@media print {
    .sidebar, .no-print { display: none !important; }
    main { margin-left: 0 !important; }
}
</style>
<script>
async function shareOnWhatsApp() {
    const message = `Namaste <?php echo addslashes($delivery['customer_name']); ?>, aapka delivery challan DC-<?php echo (int)$delivery['delivery_id']; ?> (Order <?php echo addslashes($delivery['order_number']); ?>) ready hai. <?php echo addslashes($shopSettings['shop_name'] ?? SITE_NAME); ?> se. Dhanyawad!`;
    const phone = '<?php echo preg_replace('/[^0-9]/', '', $delivery['customer_phone']); ?>';
    const waPhone = phone.length === 10 ? '91' + phone : phone;
    window.open('https://wa.me/' + waPhone + '?text=' + encodeURIComponent(message), '_blank');
}
</script>
<?php include __DIR__ . '/../../includes/footer.php'; ?>