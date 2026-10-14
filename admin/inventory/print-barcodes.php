<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/functions.php';
requirePermission($pdo, 'inventory', 'view');

// ============================================
// Inventory - Print Barcode Labels
// - Search/filter sarees, tick checkboxes, generate a printable
//   sheet of Code128 barcode labels (one per selected saree).
// - Barcodes are rendered entirely in the browser via JsBarcode -
//   no server-side image generation needed.
// ============================================

$search = sanitize($_GET['search'] ?? '');

$sql = "SELECT s.saree_id, s.sku, s.name, s.selling_price, c.name AS category_name
        FROM sarees s
        LEFT JOIN categories c ON s.category_id = c.category_id
        WHERE s.is_active = 1";
$params = [];
if ($search !== '') {
    $sql .= " AND (s.name LIKE :search1 OR s.sku LIKE :search2 OR s.design_code LIKE :search3)";
    $params['search1'] = "%$search%";
    $params['search2'] = "%$search%";
    $params['search3'] = "%$search%";
}
$sql .= " ORDER BY s.name ASC LIMIT 200";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$sarees = $stmt->fetchAll();

include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/sidebar.php';
?>
<main>
    <h1>Print Barcode Labels</h1>

    <div class="toolbar no-print">
        <a href="list.php">Back to Inventory List</a>
    </div>

    <div class="card no-print">
        <form method="GET" action="" class="inline-form">
            <input type="text" name="search" placeholder="Search by name, SKU, design code"
                   value="<?php echo sanitize($search); ?>">
            <button type="submit">Search</button>
        </form>
        <p><small>Showing up to 200 results. Narrow your search if you don't see what you need.</small></p>
    </div>

    <div class="card no-print">
        <h2>Step 1: Select Sarees</h2>
        <p>
            <label><input type="checkbox" id="selectAll"> Select All (visible below)</label>
        </p>
        <table class="data-table">
            <thead>
                <tr><th></th><th>SKU</th><th>Name</th><th>Category</th><th>Price (₹)</th></tr>
            </thead>
            <tbody>
                <?php if (empty($sarees)): ?>
                    <tr><td colspan="5">Koi saree nahi mili.</td></tr>
                <?php else: ?>
                    <?php foreach ($sarees as $s): ?>
                    <tr>
                        <td>
                            <input type="checkbox" class="sareeCheck"
                                   data-sku="<?php echo sanitize($s['sku']); ?>"
                                   data-name="<?php echo sanitize($s['name']); ?>"
                                   data-price="<?php echo sanitize($s['selling_price']); ?>">
                        </td>
                        <td><?php echo sanitize($s['sku']); ?></td>
                        <td><?php echo sanitize($s['name']); ?></td>
                        <td><?php echo sanitize($s['category_name'] ?? '-'); ?></td>
                        <td><?php echo number_format($s['selling_price'], 2); ?></td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <div class="card no-print">
        <h2>Step 2: Generate</h2>
        <div>
            <label>Copies per saree:</label>
            <input type="number" id="copiesInput" value="1" min="1" max="20" style="width:60px;">
            <button type="button" id="generateBtn">Generate Labels</button>
            <button type="button" id="printBtn" style="display:none;" onclick="window.print()">Print Labels</button>
        </div>
        <p id="selectionCount"><small>0 saree(s) selected.</small></p>
    </div>

    <div id="labelSheet" class="label-sheet"></div>
</main>

<style>
.label-sheet { display: none; }
.label-card {
    display: inline-block;
    width: 190px;
    padding: 8px;
    margin: 4px;
    border: 1px dashed #999;
    text-align: center;
    vertical-align: top;
    page-break-inside: avoid;
}
.label-card .label-name { font-size: 11px; font-weight: bold; margin-bottom: 2px; height: 28px; overflow: hidden; }
.label-card .label-price { font-size: 12px; margin-top: 2px; }

@media print {
    .no-print { display: none !important; }
    .sidebar { display: none !important; }
    main { margin-left: 0 !important; }
    .label-sheet { display: block !important; }
    h1 { display: none; }
}
</style>

<script src="https://cdn.jsdelivr.net/npm/jsbarcode@3.11.5/dist/JsBarcode.all.min.js"></script>
<script>
const selectAll = document.getElementById('selectAll');
const checkboxes = document.querySelectorAll('.sareeCheck');
const selectionCount = document.getElementById('selectionCount');

function updateCount() {
    const checked = document.querySelectorAll('.sareeCheck:checked').length;
    selectionCount.innerHTML = '<small>' + checked + ' saree(s) selected.</small>';
}

selectAll.addEventListener('change', function() {
    checkboxes.forEach(cb => cb.checked = this.checked);
    updateCount();
});
checkboxes.forEach(cb => cb.addEventListener('change', updateCount));

document.getElementById('generateBtn').addEventListener('click', function() {
    const selected = Array.from(document.querySelectorAll('.sareeCheck:checked'));
    if (selected.length === 0) {
        alert('Kam se kam ek saree select karo.');
        return;
    }

    const copies = parseInt(document.getElementById('copiesInput').value) || 1;
    const sheet = document.getElementById('labelSheet');
    sheet.innerHTML = '';

    selected.forEach(cb => {
        const sku = cb.dataset.sku;
        const name = cb.dataset.name;
        const price = parseFloat(cb.dataset.price).toFixed(2);

        for (let i = 0; i < copies; i++) {
            const card = document.createElement('div');
            card.className = 'label-card';
            card.innerHTML = `
                <div class="label-name">${name}</div>
                <svg class="barcode-svg" data-sku="${sku}"></svg>
                <div class="label-price">Rs. ${price}</div>
            `;
            sheet.appendChild(card);
        }
    });

    // Render all barcodes now that the SVG elements exist in the DOM
    document.querySelectorAll('.barcode-svg').forEach(svg => {
        JsBarcode(svg, svg.dataset.sku, {
            format: 'CODE128',
            width: 1.5,
            height: 40,
            fontSize: 12,
            margin: 4
        });
    });

    sheet.style.display = 'block';
    document.getElementById('printBtn').style.display = 'inline-block';
});
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>