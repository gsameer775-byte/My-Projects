<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/functions.php';
requirePermission($pdo, 'sales', 'view');

$sale_id = (int)($_GET['id'] ?? 0);
if ($sale_id === 0) {
    redirect('admin/sales/history.php');
}

$stmt = $pdo->prepare(
    "SELECT s.*, c.name AS customer_name, c.phone AS customer_phone, st.name AS staff_name
     FROM sales s
     LEFT JOIN customers c ON s.customer_id = c.customer_id
     LEFT JOIN staff st ON s.staff_id = st.staff_id
     WHERE s.sale_id = :id"
);
$stmt->execute(['id' => $sale_id]);
$sale = $stmt->fetch();

if (!$sale) {
    redirect('admin/sales/history.php');
}

$itemsStmt = $pdo->prepare(
    "SELECT si.*, sr.name AS saree_name, sr.sku
     FROM sale_items si
     JOIN sarees sr ON si.saree_id = sr.saree_id
     WHERE si.sale_id = :id"
);
$itemsStmt->execute(['id' => $sale_id]);
$items = $itemsStmt->fetchAll();

$paymentsStmt = $pdo->prepare("SELECT * FROM payments WHERE sale_id = :id ORDER BY paid_at ASC");
$paymentsStmt->execute(['id' => $sale_id]);
$payments = $paymentsStmt->fetchAll();

$shopSettings = $pdo->query(
    "SELECT setting_key, setting_value FROM system_settings
     WHERE setting_key IN ('shop_name', 'shop_address', 'shop_gstin', 'shop_upi_id')"
)->fetchAll(PDO::FETCH_KEY_PAIR);

// Only show the QR code when balance is actually due and a UPI ID is configured
$totalPaidSoFar = array_sum(array_column($payments, 'amount'));
$amountDueForQr = $sale['total_amount'] - $totalPaidSoFar;
$showUpiQr = !empty($shopSettings['shop_upi_id']) && $amountDueForQr > 0;

include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/sidebar.php';
?>
<main>
    <div class="toolbar no-print">
        <a href="new-sale.php">+ New Sale</a>
        <a href="history.php">Sales History</a>
        <a href="finishing.php?search=<?php echo urlencode($sale['invoice_no']); ?>">Track Finishing</a>
        <button onclick="window.print()">Print Invoice</button>
        <button onclick="downloadInvoicePDF()">Download PDF</button>
        <button onclick="shareOnWhatsApp()">Share on WhatsApp</button>
    </div>

    <div class="card invoice-box">
        <div class="invoice-header">
            <h1><?php echo sanitize($shopSettings['shop_name'] ?? SITE_NAME); ?></h1>
            <?php if (!empty($shopSettings['shop_address'])): ?>
                <p><?php echo sanitize($shopSettings['shop_address']); ?></p>
            <?php endif; ?>
            <?php if (!empty($shopSettings['shop_gstin'])): ?>
                <p>GSTIN: <?php echo sanitize($shopSettings['shop_gstin']); ?></p>
            <?php endif; ?>
        </div>

        <hr>

        <div class="invoice-meta">
            <p><strong>Invoice No:</strong> <?php echo sanitize($sale['invoice_no']); ?></p>
            <p><strong>Date:</strong> <?php echo sanitize(date('d M Y, h:i A', strtotime($sale['created_at']))); ?></p>
            <p><strong>Sold By:</strong> <?php echo sanitize($sale['staff_name'] ?? '-'); ?></p>
            <p><strong>Customer:</strong>
                <?php
                    if ($sale['customer_name']) {
                        echo sanitize($sale['customer_name']) . ' (' . sanitize($sale['customer_phone']) . ')';
                    } elseif (!empty($sale['walkin_name']) || !empty($sale['walkin_phone'])) {
                        echo sanitize($sale['walkin_name'] ?: 'Walk-in') . (!empty($sale['walkin_phone']) ? ' (' . sanitize($sale['walkin_phone']) . ')' : '') . ' - Walk-in';
                    } else {
                        echo 'Walk-in Customer';
                    }
                ?>
            </p>
            <p><strong>Sale Type:</strong> <?php echo sanitize(ucfirst($sale['sale_type'])); ?></p>
        </div>

        <table class="data-table">
            <thead>
                <tr>
                    <th>SKU</th>
                    <th>Item</th>
                    <th>Qty</th>
                    <th>Unit Price</th>
                    <th>Total</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($items as $item): ?>
                <tr>
                    <td><?php echo sanitize($item['sku']); ?></td>
                    <td><?php echo sanitize($item['saree_name']); ?></td>
                    <td><?php echo (int)$item['quantity']; ?></td>
                    <td>₹<?php echo number_format($item['unit_price'], 2); ?></td>
                    <td>₹<?php echo number_format($item['line_total'], 2); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <div class="totals-box" style="margin-left:auto; max-width:300px;">
            <p>Subtotal: ₹<?php echo number_format($sale['subtotal'], 2); ?></p>
            <p>Discount: ₹<?php echo number_format($sale['discount_amount'], 2); ?></p>
            <p>Tax: ₹<?php echo number_format($sale['tax_amount'], 2); ?></p>
            <?php if (!empty($sale['round_off_amount']) && (float)$sale['round_off_amount'] != 0.0): ?>
                <p>Round Off: <?php echo ($sale['round_off_amount'] > 0 ? '+' : '') . '₹' . number_format($sale['round_off_amount'], 2); ?></p>
            <?php endif; ?>
            <p><strong>Total: ₹<?php echo number_format($sale['total_amount'], 2); ?></strong></p>
        </div>

        <div class="invoice-payment">
            <p><strong>Payment Status:</strong> <?php echo sanitize(ucfirst($sale['payment_status'])); ?></p>
            <?php if (!empty($payments)): ?>
                <p><strong>Payments Received:</strong></p>
                <ul>
                    <?php foreach ($payments as $p): ?>
                        <li><?php echo sanitize(ucfirst($p['payment_mode'])); ?>: ₹<?php echo number_format($p['amount'], 2); ?></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
            <?php
                $totalPaid = array_sum(array_column($payments, 'amount'));
                $due = $sale['total_amount'] - $totalPaid;
            ?>
            <?php if ($due > 0): ?>
                <p><strong>Balance Due: ₹<?php echo number_format($due, 2); ?></strong></p>
            <?php endif; ?>

            <?php if ($showUpiQr): ?>
                <div class="upi-qr-box">
                    <p><strong>Scan &amp; Pay via UPI</strong></p>
                    <div id="upiQrCode"></div>
                    <p><small><?php echo sanitize($shopSettings['shop_upi_id']); ?></small></p>
                </div>
            <?php endif; ?>
        </div>

        <p style="margin-top:24px; text-align:center;"><small>Thank you for shopping with <?php echo sanitize($shopSettings['shop_name'] ?? SITE_NAME); ?>!</small></p>
    </div>
</main>

<style>
@media print {
    .sidebar, .no-print { display: none !important; }
    main { margin-left: 0 !important; }
}
</style>

<script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
<script>
const invoiceNo = <?php echo json_encode($sale['invoice_no']); ?>;
const customerPhone = <?php echo json_encode($sale['customer_phone'] ?? ''); ?>;
const customerName = <?php echo json_encode($sale['customer_name'] ?? 'Customer'); ?>;
const totalAmount = <?php echo json_encode(number_format($sale['total_amount'], 2)); ?>;
const shopName = <?php echo json_encode($shopSettings['shop_name'] ?? SITE_NAME); ?>;

<?php if ($showUpiQr): ?>
// Generate UPI payment QR code - standard "upi://pay" deep-link format
// that all UPI apps (GPay, PhonePe, Paytm, BHIM etc.) recognize.
(function() {
    const upiId = <?php echo json_encode($shopSettings['shop_upi_id']); ?>;
    const payeeName = shopName;
    const amount = <?php echo json_encode(number_format($amountDueForQr, 2, '.', '')); ?>;
    const note = 'Invoice ' + invoiceNo;

    const upiUrl = 'upi://pay?pa=' + encodeURIComponent(upiId)
        + '&pn=' + encodeURIComponent(payeeName)
        + '&am=' + encodeURIComponent(amount)
        + '&cu=INR'
        + '&tn=' + encodeURIComponent(note);

    new QRCode(document.getElementById('upiQrCode'), {
        text: upiUrl,
        width: 160,
        height: 160,
        correctLevel: QRCode.CorrectLevel.M
    });
})();
<?php endif; ?>

function getInvoicePdfBlob() {
    const element = document.querySelector('.invoice-box');
    const opt = {
        margin: 10,
        filename: invoiceNo + '.pdf',
        image: { type: 'jpeg', quality: 0.98 },
        html2canvas: { scale: 2 },
        jsPDF: { unit: 'mm', format: 'a4', orientation: 'portrait' }
    };
    return html2pdf().set(opt).from(element).outputPdf('blob');
}

function downloadInvoicePDF() {
    const element = document.querySelector('.invoice-box');
    const opt = {
        margin: 10,
        filename: invoiceNo + '.pdf',
        image: { type: 'jpeg', quality: 0.98 },
        html2canvas: { scale: 2 },
        jsPDF: { unit: 'mm', format: 'a4', orientation: 'portrait' }
    };
    html2pdf().set(opt).from(element).save();
}

async function shareOnWhatsApp() {
    const message = `Namaste ${customerName}, aapka invoice ${invoiceNo} (Total: Rs.${totalAmount}) - ${shopName} se. Dhanyawad!`;

    // Try native share (works on most mobile browsers - opens share sheet with WhatsApp option)
    if (navigator.canShare) {
        try {
            const blob = await getInvoicePdfBlob();
            const file = new File([blob], invoiceNo + '.pdf', { type: 'application/pdf' });

            if (navigator.canShare({ files: [file] })) {
                await navigator.share({
                    files: [file],
                    title: 'Invoice ' + invoiceNo,
                    text: message
                });
                return;
            }
        } catch (err) {
            console.log('Native share not available or cancelled, falling back.', err);
        }
    }

    // Fallback (mainly desktop): download PDF, then open WhatsApp with a pre-filled message
    downloadInvoicePDF();
    let phone = customerPhone.replace(/\D/g, '');
    if (phone && phone.length === 10) {
        phone = '91' + phone; // assume India country code if 10-digit local number
    }
    const waUrl = phone
        ? `https://wa.me/${phone}?text=${encodeURIComponent(message + ' (PDF just downloaded - please attach it here)')}`
        : `https://wa.me/?text=${encodeURIComponent(message + ' (PDF just downloaded - please attach it here)')}`;
    window.open(waUrl, '_blank');
}
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>