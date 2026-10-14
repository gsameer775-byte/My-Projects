<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/functions.php';
requirePermission($pdo, 'sales', 'view');

// ============================================
// Printable Item Tags
// - Jab ek saath kai saree/product ek hi process (jaise Decor) ke liye
//   vendor ko bheje jaate hain, physically pehchan-na mushkil ho jata hai
//   ki kaunsi saree kis invoice/customer ki hai.
// - Ye page selected items ke liye ek-ek chhota tag print karta hai
//   (Invoice No, Customer, Product, SKU, Qty, Process, Tag ID) - isko
//   print karke kaat lo aur har saree ke saath pin/thread se tag laga do.
// - Vendor se wapas aane par bhi yahi tag saree ke saath match karke
//   pehchana ja sakta hai.
// ============================================

$taskIdsRaw = $_GET['task_ids'] ?? '';
$taskIds = array_values(array_unique(array_filter(array_map('intval', explode(',', $taskIdsRaw)))));

$tags = [];
if (!empty($taskIds)) {
    $placeholders = implode(',', array_fill(0, count($taskIds), '?'));
    $stmt = $pdo->prepare(
        "SELECT t.task_id, fs.stage_name,
                sif.finishing_id, sif.invoice_no, sif.invoice_date, sif.customer_name, sif.customer_phone,
                sif.sku, sif.product_name, sif.item_qty
         FROM sale_item_finishing_tasks t
         JOIN finishing_stages fs ON fs.stage_id = t.stage_id
         JOIN sale_item_finishing sif ON sif.finishing_id = t.finishing_id
         WHERE t.task_id IN ($placeholders)
         ORDER BY sif.invoice_no ASC, t.task_id ASC"
    );
    $stmt->execute($taskIds);
    $tags = $stmt->fetchAll();
}

include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/sidebar.php';
?>
<main>
    <div class="toolbar no-print">
        <a href="finishing-by-stage.php">Back to Work Queue</a>
        <?php if (!empty($tags)): ?><button onclick="window.print()">Print Tags</button><?php endif; ?>
    </div>

    <h1>Item Tags - Print &amp; Attach</h1>
    <p class="no-print"><small>Har tag ko kaat kar us saree/product ke saath pin ya thread se laga do, phir vendor ko bhejo. Vendor se wapas aane par isi tag se match kar lo ki kaunsi saree kis invoice/customer ki hai.</small></p>

    <?php if (empty($tags)): ?>
        <div class="card"><p>Koi item select nahi kiya gaya. Work Queue pe wapas jaake pehle checkbox se items select karo, phir "Print Tags" dabao.</p></div>
    <?php else: ?>
    <div class="tags-grid">
        <?php foreach ($tags as $tag): ?>
        <div class="item-tag">
            <div class="item-tag-stage"><?php echo sanitize($tag['stage_name']); ?></div>
            <div class="item-tag-row"><span>Invoice</span><strong><?php echo sanitize($tag['invoice_no']); ?></strong></div>
            <div class="item-tag-row"><span>Customer</span><strong><?php echo sanitize($tag['customer_name']); ?></strong></div>
            <div class="item-tag-row"><span>Product</span><strong><?php echo sanitize($tag['product_name']); ?></strong></div>
            <div class="item-tag-row"><span>SKU</span><strong><?php echo sanitize($tag['sku'] ?: '-'); ?></strong></div>
            <div class="item-tag-row"><span>Qty</span><strong><?php echo (int)$tag['item_qty']; ?></strong></div>
            <div class="item-tag-footer">Tag #<?php echo (int)$tag['task_id']; ?> &middot; <?php echo sanitize(date('d M Y', strtotime($tag['invoice_date']))); ?></div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</main>
<style>
.tags-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 14px;
}
.item-tag {
    border: 1.5px dashed var(--maroon-700);
    border-radius: 8px;
    padding: 12px 14px;
    background: var(--paper);
    break-inside: avoid;
}
.item-tag-stage {
    display: inline-block;
    background: var(--gold-400);
    color: var(--maroon-950);
    font-size: 11px;
    font-weight: 700;
    padding: 2px 9px;
    border-radius: 10px;
    margin-bottom: 8px;
    text-transform: uppercase;
    letter-spacing: 0.3px;
}
.item-tag-row {
    display: flex;
    justify-content: space-between;
    gap: 8px;
    font-size: 13px;
    padding: 2px 0;
    border-bottom: 1px dotted #e6dcc9;
}
.item-tag-row span { color: var(--ink-400); }
.item-tag-row strong { text-align: right; }
.item-tag-footer {
    margin-top: 8px;
    font-size: 10.5px;
    color: var(--ink-400);
    font-family: 'IBM Plex Mono', monospace;
    text-align: center;
}
@media print {
    .sidebar, .no-print { display: none !important; }
    main { margin-left: 0 !important; }
    .tags-grid { grid-template-columns: repeat(3, 1fr); }
    .item-tag { break-inside: avoid; page-break-inside: avoid; }
}
</style>
<?php include __DIR__ . '/../../includes/footer.php'; ?>