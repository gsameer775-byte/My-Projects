<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/functions.php';
requirePermission($pdo, 'sales', 'view');

// ============================================
// Order Finishing - post-sale processing tracker
// - Kisi sold item ko decor/calendar, polish, fall & piko, tassel,
//   raffoo, dye, daag chhudana jaise processes se guzarna padta hai,
//   TABHI wo customer ko dispatch hota hai. Ye page un items ko track
//   karta hai jab tak wo "Ready for Dispatch" nahi ho jaate.
// - Do tarah ke items yahan aa sakte hain:
//     1) is system ke apne POS sale (neeche "Naya Sold Item" list se)
//     2) kisi DUSRE software me bane invoice ka item (CSV import ya
//        "Manual Item Add Karo" form se - ye seedha yahan create hota
//        hai, kyunki uska koi matching "sale" is system me hota hi
//        nahi hai)
// - Jab tak koi item yahan se explicitly na daala jaye, wo is tracking
//   me hai hi nahi (purana flow bilkul waisa hi chalega).
// ============================================

$error = '';
$success = '';
$search = sanitize($_GET['search'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requirePermission($pdo, 'sales', 'edit');
    $action = $_POST['action'] ?? '';

    // ---- Start finishing tracking for internal (this system's) sold items ----
    if ($action === 'start_finishing') {
        $stagesInput = $_POST['stages'] ?? [];   // [sale_item_id => [stage_id => '1', ...]]
        $expectedDates = $_POST['expected_date'] ?? []; // [sale_item_id => 'YYYY-MM-DD']
        $started = 0;
        $skipped = [];

        foreach ($stagesInput as $saleItemId => $checkedStages) {
            $saleItemId = (int)$saleItemId;
            $selectedStageIds = array_map('intval', array_keys(array_filter($checkedStages)));
            if (empty($selectedStageIds)) continue;

            try {
                $pdo->beginTransaction();

                // Fetch full item info (and lock row) - also confirms not already tracked
                $chk = $pdo->prepare(
                    "SELECT si.sale_item_id, si.quantity, sr.sku, sr.name AS product_name,
                            s.invoice_no, DATE(s.created_at) AS invoice_date,
                            COALESCE(c.name, s.walkin_name, 'Walk-in') AS customer_name,
                            COALESCE(c.phone, s.walkin_phone) AS customer_phone
                     FROM sale_items si
                     JOIN sarees sr ON sr.saree_id = si.saree_id
                     JOIN sales s ON s.sale_id = si.sale_id
                     LEFT JOIN customers c ON c.customer_id = s.customer_id
                     LEFT JOIN sale_item_finishing sif ON sif.sale_item_id = si.sale_item_id
                     WHERE si.sale_item_id = :id AND sif.finishing_id IS NULL
                     FOR UPDATE"
                );
                $chk->execute(['id' => $saleItemId]);
                $itemInfo = $chk->fetch();
                if (!$itemInfo) {
                    $pdo->rollBack();
                    $skipped[] = $saleItemId;
                    continue;
                }

                $expDate = trim($expectedDates[$saleItemId] ?? '') !== '' ? $expectedDates[$saleItemId] : null;

                $insSif = $pdo->prepare(
                    "INSERT INTO sale_item_finishing
                        (sale_item_id, item_source, invoice_no, invoice_date, customer_name, customer_phone,
                         sku, product_name, item_qty, overall_status, expected_ready_date, started_by)
                     VALUES
                        (:sid, 'internal', :inv, :idate, :cname, :cphone,
                         :sku, :pname, :qty, 'in_process', :exp, :by)"
                );
                $insSif->execute([
                    'sid' => $saleItemId, 'inv' => $itemInfo['invoice_no'], 'idate' => $itemInfo['invoice_date'],
                    'cname' => $itemInfo['customer_name'], 'cphone' => $itemInfo['customer_phone'],
                    'sku' => $itemInfo['sku'], 'pname' => $itemInfo['product_name'], 'qty' => $itemInfo['quantity'],
                    'exp' => $expDate, 'by' => $_SESSION['staff_id'],
                ]);
                $finishingId = $pdo->lastInsertId();

                $insTask = $pdo->prepare(
                    "INSERT INTO sale_item_finishing_tasks (finishing_id, stage_id, status) VALUES (:fid, :stid, 'pending')"
                );
                foreach ($selectedStageIds as $stageId) {
                    $insTask->execute(['fid' => $finishingId, 'stid' => $stageId]);
                }

                $pdo->prepare(
                    "INSERT INTO audit_log (staff_id, action, module_name, record_id, description, ip_address)
                     VALUES (:sid, 'create', 'finishing', :rid, :desc, :ip)"
                )->execute([
                    'sid' => $_SESSION['staff_id'],
                    'rid' => $finishingId,
                    'desc' => "Sale item #$saleItemId ko finishing me bheja (" . count($selectedStageIds) . " stage(s))",
                    'ip' => $_SERVER['REMOTE_ADDR'] ?? null,
                ]);

                $pdo->commit();
                $started++;
            } catch (Exception $e) {
                $pdo->rollBack();
                $skipped[] = $saleItemId;
            }
        }

        if ($started > 0) {
            $success = "$started item(s) finishing tracking me daal diye gaye.";
        }
        if (!empty($skipped)) {
            $error = 'Ye item(s) skip ho gaye (shayad already tracked hai): ' . implode(', ', $skipped);
        }
        if ($started === 0 && empty($skipped)) {
            $error = 'Koi item select nahi kiya, ya koi stage tick nahi kiya.';
        }
    }

    // ---- Manually add MULTIPLE external (other-software) invoice items ----
    // Ek hi invoice/customer ke against 4-5 alag-alag product (alag SKU, alag
    // naam) ho sakte hain - isliye invoice/customer sirf EK baar bhara jata hai,
    // aur "Products" section me jitne chahe utne rows (SKU + Product Name +
    // Qty + Expected Date + Stages) add ki ja sakti hain. Har row apni khud
    // ki sale_item_finishing entry banati hai (SKU/naam alag hone ki wajah se
    // in sabko track karne ke liye alag row zaroori hai), lekin sab ek hi
    // invoice_no/customer share karte hain.
    if ($action === 'add_manual_external') {
        $itemType = ($_POST['item_type'] ?? 'sold') === 'stock' ? 'stock' : 'sold';

        if ($itemType === 'stock') {
            $itemSource = 'stock';
            $invoiceNo = sanitize($_POST['stock_ref'] ?? '') ?: null; // optional batch/reference no.
            $invoiceDate = trim($_POST['stock_date'] ?? '') !== '' ? $_POST['stock_date'] : date('Y-m-d');
            $customerName = 'Stock (Pre-Sale)';
            $customerPhone = null;
        } else {
            $itemSource = 'external';
            $invoiceNo = sanitize($_POST['invoice_no'] ?? '');
            $invoiceDate = trim($_POST['invoice_date'] ?? '') !== '' ? $_POST['invoice_date'] : date('Y-m-d');
            $customerName = sanitize($_POST['customer_name'] ?? '') ?: 'Walk-in';
            $customerPhone = sanitize($_POST['customer_phone'] ?? '');
        }
        $itemsInput = $_POST['items'] ?? []; // [idx => ['sku','product_name','quantity','expected_ready_date','stages'=>[stage_id=>'1']]]

        // Sirf wahi rows lo jinme product name bhara ho (khaali/extra rows skip)
        $validItems = [];
        foreach ($itemsInput as $row) {
            $pname = sanitize($row['product_name'] ?? '');
            if ($pname === '') continue;
            $rowExpDate = trim($row['expected_ready_date'] ?? '') !== '' ? $row['expected_ready_date'] : null;
            $validItems[] = [
                'sku' => sanitize($row['sku'] ?? ''),
                'product_name' => $pname,
                'qty' => max(1, (int)($row['quantity'] ?? 1)),
                'exp' => $rowExpDate,
                'stage_ids' => array_map('intval', array_keys(array_filter($row['stages'] ?? []))),
            ];
        }

        if ($itemType === 'sold' && $invoiceNo === '') {
            $error = 'Invoice No likhna zaroori hai (ya "Stock / Bina-Bikey Item" select karo).';
        } elseif (empty($validItems)) {
            $error = 'Kam se kam ek product ka naam bharo.';
        } else {
            try {
                $pdo->beginTransaction();

                $insSif = $pdo->prepare(
                    "INSERT INTO sale_item_finishing
                        (sale_item_id, item_source, invoice_no, invoice_date, customer_name, customer_phone,
                         sku, product_name, item_qty, overall_status, expected_ready_date, started_by, imported_note)
                     VALUES
                        (NULL, :src, :inv, :idate, :cname, :cphone,
                         :sku, :pname, :qty, 'in_process', :exp, :by, :note)"
                );
                $insTask = $pdo->prepare(
                    "INSERT INTO sale_item_finishing_tasks (finishing_id, stage_id, status) VALUES (:fid, :stid, 'pending')"
                );
                $insLog = $pdo->prepare(
                    "INSERT INTO audit_log (staff_id, action, module_name, record_id, description, ip_address)
                     VALUES (:sid, 'create', 'finishing', :rid, :desc, :ip)"
                );

                $noteText = $itemType === 'stock' ? 'Manual add (stock / pre-sale item, no invoice yet)' : 'Manual add (other software invoice)';
                $addedCount = 0;
                $addedNames = [];
                foreach ($validItems as $it) {
                    $insSif->execute([
                        'src' => $itemSource, 'inv' => $invoiceNo, 'idate' => $invoiceDate, 'cname' => $customerName,
                        'cphone' => $customerPhone ?: null, 'sku' => $it['sku'] ?: null, 'pname' => $it['product_name'],
                        'qty' => $it['qty'], 'exp' => $it['exp'], 'by' => $_SESSION['staff_id'], 'note' => $noteText,
                    ]);
                    $finishingId = $pdo->lastInsertId();

                    foreach ($it['stage_ids'] as $stageId) {
                        $insTask->execute(['fid' => $finishingId, 'stid' => $stageId]);
                    }

                    $insLog->execute([
                        'sid' => $_SESSION['staff_id'], 'rid' => $finishingId,
                        'desc' => ($itemType === 'stock' ? "Manual stock item add: " : "Manual external item add: invoice $invoiceNo, ") . "item {$it['product_name']}",
                        'ip' => $_SERVER['REMOTE_ADDR'] ?? null,
                    ]);

                    $addedCount++;
                    $addedNames[] = $it['product_name'];
                }

                $pdo->commit();
                $success = $itemType === 'stock'
                    ? "$addedCount stock item(s) finishing tracking me add ho gaye: " . implode(', ', $addedNames)
                    : "$addedCount item(s) (Invoice $invoiceNo) finishing tracking me add ho gaye: " . implode(', ', $addedNames);
            } catch (Exception $e) {
                $pdo->rollBack();
                $error = 'Save nahi ho paya: ' . $e->getMessage();
            }
        }
    }

    // ---- Manage stage master list ----
    if ($action === 'add_stage') {
        $name = sanitize($_POST['stage_name'] ?? '');
        if ($name === '') {
            $error = 'Stage ka naam likho.';
        } else {
            $maxOrder = (int)$pdo->query("SELECT COALESCE(MAX(sort_order),0) FROM finishing_stages")->fetchColumn();
            $pdo->prepare("INSERT INTO finishing_stages (stage_name, sort_order, is_active) VALUES (:name, :ord, 1)")
                ->execute(['name' => $name, 'ord' => $maxOrder + 1]);
            $success = "Naya stage \"$name\" add ho gaya.";
        }
    }

    if ($action === 'toggle_stage') {
        $stageId = (int)($_POST['stage_id'] ?? 0);
        $pdo->prepare("UPDATE finishing_stages SET is_active = 1 - is_active WHERE stage_id = :id")
            ->execute(['id' => $stageId]);
        $success = 'Stage status update ho gaya.';
    }

    // ---- Rename an existing stage (jaise naam galat likha gaya ho, ya do
    //      alag processes ek hi stage me combine ho gaye hon - naam sahi karo) ----
    if ($action === 'rename_stage') {
        $stageId = (int)($_POST['stage_id'] ?? 0);
        $newName = sanitize($_POST['new_stage_name'] ?? '');
        if ($newName === '') {
            $error = 'Naya naam khaali nahi ho sakta.';
        } else {
            $oldNameStmt = $pdo->prepare("SELECT stage_name FROM finishing_stages WHERE stage_id = :id");
            $oldNameStmt->execute(['id' => $stageId]);
            $oldName = $oldNameStmt->fetchColumn();

            $pdo->prepare("UPDATE finishing_stages SET stage_name = :name WHERE stage_id = :id")
                ->execute(['name' => $newName, 'id' => $stageId]);

            $pdo->prepare(
                "INSERT INTO audit_log (staff_id, action, module_name, record_id, description, ip_address)
                 VALUES (:sid, 'update', 'finishing_stage', :rid, :desc, :ip)"
            )->execute([
                'sid' => $_SESSION['staff_id'], 'rid' => $stageId,
                'desc' => "Stage rename: \"$oldName\" -> \"$newName\"",
                'ip' => $_SERVER['REMOTE_ADDR'] ?? null,
            ]);
            $success = "Stage ka naam \"$oldName\" se \"$newName\" kar diya gaya. (Is stage se pehle se jude sabhi items ka naam bhi ab yehi dikhega.)";
        }
    }
}

// ---- Stage master list (for checkboxes + manage panel) ----
$allStages = $pdo->query("SELECT * FROM finishing_stages ORDER BY sort_order ASC, stage_id ASC")->fetchAll();
$activeStages = array_values(array_filter($allStages, fn($s) => (int)$s['is_active'] === 1));

// ---- Summary stats ----
$stats = $pdo->query(
    "SELECT
        SUM(overall_status = 'in_process') AS in_process,
        SUM(overall_status = 'ready') AS ready,
        SUM(overall_status IN ('in_process','ready') AND expected_ready_date IS NOT NULL AND expected_ready_date < CURDATE()) AS overdue,
        SUM(overall_status = 'dispatched' AND DATE(dispatched_at) = CURDATE()) AS dispatched_today
     FROM sale_item_finishing"
)->fetch();

// ---- Not-yet-tracked sold items from THIS system's own POS (search by invoice/customer) ----
$notTrackedSql =
    "SELECT si.sale_item_id, si.quantity, si.line_total,
            sr.name AS saree_name, sr.sku,
            s.invoice_no, s.created_at AS sale_date,
            COALESCE(c.name, s.walkin_name, 'Walk-in') AS customer_name
     FROM sale_items si
     JOIN sales s ON s.sale_id = si.sale_id
     JOIN sarees sr ON sr.saree_id = si.saree_id
     LEFT JOIN customers c ON c.customer_id = s.customer_id
     LEFT JOIN sale_item_finishing sif ON sif.sale_item_id = si.sale_item_id
     WHERE sif.finishing_id IS NULL AND si.is_returned = 0";
$notTrackedParams = [];
if ($search !== '') {
    $notTrackedSql .= " AND (s.invoice_no LIKE :s1 OR c.name LIKE :s2 OR s.walkin_name LIKE :s3 OR sr.name LIKE :s4)";
    $notTrackedParams = ['s1' => "%$search%", 's2' => "%$search%", 's3' => "%$search%", 's4' => "%$search%"];
}
$notTrackedSql .= " ORDER BY s.created_at DESC LIMIT 50";
$notTrackedStmt = $pdo->prepare($notTrackedSql);
$notTrackedStmt->execute($notTrackedParams);
$notTrackedItems = $notTrackedStmt->fetchAll();

// ---- Active tracking (in_process / ready) - internal AND external, all from sale_item_finishing itself now ----
$activeSql =
    "SELECT sif.finishing_id, sif.overall_status, sif.expected_ready_date, sif.started_at, sif.item_source,
            sif.item_qty, sif.product_name, sif.sku, sif.invoice_no, sif.customer_name,
            (SELECT COUNT(*) FROM sale_item_finishing_tasks t WHERE t.finishing_id = sif.finishing_id) AS total_tasks,
            (SELECT COUNT(*) FROM sale_item_finishing_tasks t WHERE t.finishing_id = sif.finishing_id AND t.status = 'completed') AS done_tasks
     FROM sale_item_finishing sif
     WHERE sif.overall_status IN ('in_process','ready')";
$activeParams = [];
if ($search !== '') {
    $activeSql .= " AND (sif.invoice_no LIKE :s1 OR sif.customer_name LIKE :s2 OR sif.product_name LIKE :s3)";
    $activeParams = ['s1' => "%$search%", 's2' => "%$search%", 's3' => "%$search%"];
}
$activeSql .= " ORDER BY (sif.overall_status = 'ready') DESC, (sif.expected_ready_date IS NULL) ASC, sif.expected_ready_date ASC";
$activeStmt = $pdo->prepare($activeSql);
$activeStmt->execute($activeParams);
$activeItems = $activeStmt->fetchAll();

// ---- Recently dispatched (reference only) ----
$dispatched = $pdo->query(
    "SELECT finishing_id, dispatched_at, item_qty, product_name, sku, invoice_no, customer_name
     FROM sale_item_finishing
     WHERE overall_status = 'dispatched'
     ORDER BY dispatched_at DESC LIMIT 15"
)->fetchAll();

$today = date('Y-m-d');

include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/sidebar.php';
?>
<main>
    <h1>Order Finishing</h1>
    <p><small>Sold items ko dispatch se pehle decor, polish, fall &amp; piko, tassel, raffoo, dye, daag chhudana jaise processing stages se track karo.</small></p>

    <div class="toolbar no-print">
        <a href="finishing-by-stage.php" class="btn-csv-import" style="background:linear-gradient(180deg, var(--maroon-700) 0%, var(--maroon-800) 100%); color:#fff; border-color:var(--maroon-600);">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7"></rect><rect x="14" y="3" width="7" height="7"></rect><rect x="14" y="14" width="7" height="7"></rect><rect x="3" y="14" width="7" height="7"></rect></svg>
            <span>Process-wise Work Queue (Bulk Send/Complete)</span>
        </a>
        <a href="import-invoice.php" class="btn-csv-import">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="7 10 12 15 17 10"></polyline><line x1="12" y1="15" x2="12" y2="3"></line></svg>
            <span>Import Invoices (CSV) - Doosre Software Se</span>
        </a>
    </div>
    <style>
        .btn-csv-import {
            display: inline-flex;
            align-items: center;
            gap: 9px;
            padding: 11px 22px;
            background: linear-gradient(180deg, var(--gold-400) 0%, var(--gold-600) 100%);
            color: var(--maroon-950);
            border: 1px solid var(--gold-300);
            border-radius: var(--radius-sm, 8px);
            font-weight: 700;
            font-size: 14px;
            letter-spacing: 0.2px;
            text-decoration: none;
            box-shadow: 0 2px 6px rgba(156, 122, 31, 0.35), inset 0 1px 0 rgba(255,255,255,0.4);
            transition: transform 0.12s ease, box-shadow 0.12s ease, background 0.15s ease;
        }
        .btn-csv-import svg { flex-shrink: 0; }
        .btn-csv-import:hover {
            background: linear-gradient(180deg, var(--gold-300) 0%, var(--gold-500) 100%);
            box-shadow: 0 4px 12px rgba(156, 122, 31, 0.45), inset 0 1px 0 rgba(255,255,255,0.5);
            transform: translateY(-1px);
        }
        .btn-csv-import:active { transform: translateY(0px); }
    </style>

    <?php if ($error): ?><div class="alert alert-error"><?php echo sanitize($error); ?></div><?php endif; ?>
    <?php if ($success): ?><div class="alert alert-success"><?php echo sanitize($success); ?></div><?php endif; ?>

    <div class="dashboard-grid">
        <div class="card stat-card">
            <p class="stat-label">In Process</p>
            <p class="stat-value"><?php echo (int)$stats['in_process']; ?></p>
        </div>
        <div class="card stat-card">
            <p class="stat-label">Ready for Dispatch</p>
            <p class="stat-value" style="color:#1e7e34;"><?php echo (int)$stats['ready']; ?></p>
        </div>
        <div class="card stat-card">
            <p class="stat-label">Overdue</p>
            <p class="stat-value" style="color:<?php echo (int)$stats['overdue'] > 0 ? '#b71c1c' : '#1e7e34'; ?>;"><?php echo (int)$stats['overdue']; ?></p>
        </div>
        <div class="card stat-card">
            <p class="stat-label">Dispatched Today</p>
            <p class="stat-value"><?php echo (int)$stats['dispatched_today']; ?></p>
        </div>
    </div>

    <div class="card">
        <div class="toolbar">
            <form method="GET" action="">
                <input type="text" name="search" placeholder="Invoice no, customer ya item naam" value="<?php echo sanitize($search); ?>">
                <button type="submit">Search</button>
                <?php if ($search !== ''): ?><a href="finishing.php">Clear</a><?php endif; ?>
            </form>
        </div>
    </div>

    <!-- ================= Start Finishing for this system's own sold items ================= -->
    <div class="card">
        <h2>Is System Ke Naya Sold Item - Finishing Shuru Karo</h2>
        <?php if (empty($notTrackedItems)): ?>
            <p>Koi naya item nahi mila jise finishing me bhejna ho.</p>
        <?php else: ?>
        <form method="POST" action="">
            <input type="hidden" name="action" value="start_finishing">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Invoice</th>
                        <th>Item</th>
                        <th>Qty</th>
                        <th>Customer</th>
                        <th>Required Stages</th>
                        <th>Expected Ready</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($notTrackedItems as $it): ?>
                    <tr>
                        <td><?php echo sanitize($it['invoice_no']); ?><br><small><?php echo sanitize(date('d M Y', strtotime($it['sale_date']))); ?></small></td>
                        <td><?php echo sanitize($it['saree_name']); ?><br><small><?php echo sanitize($it['sku']); ?></small></td>
                        <td><?php echo (int)$it['quantity']; ?></td>
                        <td><?php echo sanitize($it['customer_name']); ?></td>
                        <td>
                            <?php foreach ($activeStages as $stage): ?>
                                <label style="display:inline-block; margin-right:10px; font-weight:normal;">
                                    <input type="checkbox" name="stages[<?php echo (int)$it['sale_item_id']; ?>][<?php echo (int)$stage['stage_id']; ?>]" value="1">
                                    <?php echo sanitize($stage['stage_name']); ?>
                                </label>
                            <?php endforeach; ?>
                        </td>
                        <td><input type="date" name="expected_date[<?php echo (int)$it['sale_item_id']; ?>]" min="<?php echo $today; ?>" style="width:150px;"></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <button type="submit" style="margin-top:12px;">Send Ticked Items for Finishing</button>
        </form>
        <p><small>Jis item me ek bhi stage tick nahi hoga, wo skip ho jayega - yaani wo item normal (turant dispatch-ready) mana jayega.</small></p>
        <?php endif; ?>
    </div>

    <!-- ================= Manually add external invoice item(s) - ek ya kai products ================= -->
    <?php
        // Ek "product row" ka HTML template - __IDX__ jagah par row ka index aayega.
        // Isi template se pehla row PHP me render hota hai, aur yehi template
        // JS me bhi (as a string) use hota hai naya row clone/add karne ke liye,
        // taaki dono jagah exact wahi fields/naam rahen.
        ob_start();
        ?>
<div class="manual-item-row" style="border:1px solid #ddd; border-radius:6px; padding:12px; margin-bottom:12px; background:#fafafa;">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:8px;">
        <strong>Product #<span class="row-number">1</span></strong>
        <button type="button" class="remove-item-row" style="display:none; padding:2px 10px; font-size:12px;">Remove</button>
    </div>
    <table class="data-table" style="max-width:700px;">
        <tr><td>SKU</td><td><input type="text" name="items[__IDX__][sku]" style="width:100%;"></td></tr>
        <tr><td>Product Name *</td><td><input type="text" name="items[__IDX__][product_name]" style="width:100%;"></td></tr>
        <tr><td>Quantity</td><td><input type="number" name="items[__IDX__][quantity]" min="1" value="1" style="width:100px;"></td></tr>
        <tr><td>Expected Ready Date</td><td><input type="date" name="items[__IDX__][expected_ready_date]" min="<?php echo $today; ?>"></td></tr>
        <tr>
            <td>Required Stages</td>
            <td>
                <?php foreach ($activeStages as $stage): ?>
                    <label style="display:inline-block; margin-right:10px; font-weight:normal;">
                        <input type="checkbox" name="items[__IDX__][stages][<?php echo (int)$stage['stage_id']; ?>]" value="1">
                        <?php echo sanitize($stage['stage_name']); ?>
                    </label>
                <?php endforeach; ?>
            </td>
        </tr>
    </table>
</div>
        <?php
        $itemRowTemplate = ob_get_clean();
    ?>
    <div class="card no-print">
        <h2>Manual Item Add Karo</h2>
        <p><small>Ek hi invoice/customer me 4-5 alag-alag product hain to invoice/customer sirf ek baar bharo aur "+ Aur Product Add Karo" se neeche jitne chahe utne product row jod do. Bahut saare sold items (10-15+) ek saath ke liye <a href="import-invoice.php">CSV Import</a> use karo.</small></p>

        <div style="margin-bottom:16px; display:flex; gap:18px; flex-wrap:wrap;">
            <label style="font-weight:600;"><input type="radio" name="item_type" value="sold" checked onchange="toggleItemType()"> Bikey Hua Item (Kisi Invoice Ke Through)</label>
            <label style="font-weight:600;"><input type="radio" name="item_type" value="stock" onchange="toggleItemType()"> Stock / Bina-Bikey Item (Abhi Invoice Nahi Bana)</label>
        </div>

        <form method="POST" action="" id="manualExternalForm">
            <input type="hidden" name="action" value="add_manual_external">

            <div id="soldFieldsWrapper">
                <table class="data-table" style="max-width:700px;">
                    <tr><td>Invoice No *</td><td><input type="text" name="invoice_no" id="invoiceNoInput" required style="width:100%;"></td></tr>
                    <tr><td>Invoice Date</td><td><input type="date" name="invoice_date" value="<?php echo $today; ?>"></td></tr>
                    <tr><td>Customer Name</td><td><input type="text" name="customer_name" style="width:100%;" placeholder="Walk-in"></td></tr>
                    <tr><td>Customer Phone</td><td><input type="text" name="customer_phone" style="width:100%;"></td></tr>
                </table>
            </div>

            <div id="stockFieldsWrapper" style="display:none;">
                <p><small>Ye item abhi tak becha nahi gaya - bas processing (Decor/Calendar/Polish etc.) ke liye ja raha hai. Invoice/customer ki zaroorat nahi.</small></p>
                <table class="data-table" style="max-width:700px;">
                    <tr><td>Stock Entry Date</td><td><input type="date" name="stock_date" value="<?php echo $today; ?>"></td></tr>
                    <tr><td>Reference / Batch No (optional)</td><td><input type="text" name="stock_ref" style="width:100%;" placeholder="jaise: Naya Stock Lot - Sept"></td></tr>
                </table>
            </div>

            <h3 style="margin-top:18px; margin-bottom:8px;">Products</h3>
            <div id="itemRowsContainer">
                <?php echo str_replace('__IDX__', '0', $itemRowTemplate); ?>
            </div>
            <button type="button" id="addItemRowBtn" style="margin-bottom:14px;">+ Aur Product Add Karo</button>
            <br>
            <button type="submit">Add to Finishing Tracking</button>
        </form>
    </div>
    <script>
    function toggleItemType() {
        var isStock = document.querySelector('input[name="item_type"]:checked').value === 'stock';
        document.getElementById('soldFieldsWrapper').style.display = isStock ? 'none' : 'block';
        document.getElementById('stockFieldsWrapper').style.display = isStock ? 'block' : 'none';
        document.getElementById('invoiceNoInput').required = !isStock;
    }
    </script>
    <script>
    (function () {
        var container = document.getElementById('itemRowsContainer');
        var addBtn = document.getElementById('addItemRowBtn');
        var rowTemplate = <?php echo json_encode($itemRowTemplate); ?>;
        var nextIdx = 1; // idx 0 already used by the first row rendered above

        function renumberRows() {
            var rows = container.querySelectorAll('.manual-item-row');
            rows.forEach(function (row, i) {
                var label = row.querySelector('.row-number');
                if (label) label.textContent = i + 1;
                var removeBtn = row.querySelector('.remove-item-row');
                // Pehla row hamesha rahega jab tak sirf ek hi bacha ho - remove
                // button tabhi dikhega jab ek se zyada row ho
                if (removeBtn) removeBtn.style.display = rows.length > 1 ? 'inline-block' : 'none';
            });
        }

        addBtn.addEventListener('click', function () {
            var html = rowTemplate.split('__IDX__').join(nextIdx);
            container.insertAdjacentHTML('beforeend', html);
            nextIdx++;
            renumberRows();
        });

        container.addEventListener('click', function (e) {
            if (e.target && e.target.classList.contains('remove-item-row')) {
                var row = e.target.closest('.manual-item-row');
                if (row) row.remove();
                renumberRows();
            }
        });

        renumberRows();
    })();
    </script>

    <!-- ================= Active tracking ================= -->
    <div class="card">
        <h2>Finishing Me Chal Rahe Items</h2>
        <table class="data-table">
            <thead>
                <tr>
                    <th>Invoice</th>
                    <th>Item</th>
                    <th>Customer</th>
                    <th>Source</th>
                    <th>Progress</th>
                    <th>Expected Ready</th>
                    <th>Status</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($activeItems)): ?>
                    <tr><td colspan="8">Abhi koi item finishing me nahi hai.</td></tr>
                <?php else: ?>
                    <?php foreach ($activeItems as $it):
                        $isOverdue = $it['expected_ready_date'] && $it['expected_ready_date'] < $today && $it['overall_status'] !== 'ready';
                    ?>
                    <tr>
                        <td><?php echo sanitize($it['invoice_no']); ?></td>
                        <td><?php echo sanitize($it['product_name']); ?> (x<?php echo (int)$it['item_qty']; ?>)<br><small><?php echo sanitize($it['sku']); ?></small></td>
                        <td><?php echo sanitize($it['customer_name']); ?></td>
                        <td>
                            <?php if ($it['item_source'] === 'external'): ?>
                                <span class="badge-warning">External</span>
                            <?php elseif ($it['item_source'] === 'stock'): ?>
                                <span class="badge-success">Stock</span>
                            <?php else: ?>
                                Own POS
                            <?php endif; ?>
                        </td>
                        <td><?php echo (int)$it['done_tasks']; ?> / <?php echo (int)$it['total_tasks']; ?> done</td>
                        <td style="<?php echo $isOverdue ? 'color:#b71c1c; font-weight:600;' : ''; ?>">
                            <?php echo $it['expected_ready_date'] ? sanitize(date('d M Y', strtotime($it['expected_ready_date']))) : '-'; ?>
                            <?php echo $isOverdue ? ' (Overdue)' : ''; ?>
                        </td>
                        <td>
                            <?php if ($it['overall_status'] === 'ready'): ?>
                                <span class="badge-success">Ready for Dispatch</span>
                            <?php else: ?>
                                <span class="badge-warning">In Process</span>
                            <?php endif; ?>
                        </td>
                        <td><a href="finishing-detail.php?id=<?php echo (int)$it['finishing_id']; ?>">View / Update</a></td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- ================= Recently dispatched ================= -->
    <div class="card">
        <h2>Recently Dispatched</h2>
        <table class="data-table">
            <thead>
                <tr><th>Invoice</th><th>Item</th><th>Customer</th><th>Dispatched At</th><th>Action</th></tr>
            </thead>
            <tbody>
                <?php if (empty($dispatched)): ?>
                    <tr><td colspan="5">Abhi tak koi item is tarah dispatch nahi hua.</td></tr>
                <?php else: ?>
                    <?php foreach ($dispatched as $d): ?>
                    <tr>
                        <td><?php echo sanitize($d['invoice_no']); ?></td>
                        <td><?php echo sanitize($d['product_name']); ?> (x<?php echo (int)$d['item_qty']; ?>)</td>
                        <td><?php echo sanitize($d['customer_name']); ?></td>
                        <td><?php echo sanitize(date('d M Y, h:i A', strtotime($d['dispatched_at']))); ?></td>
                        <td><a href="finishing-detail.php?id=<?php echo (int)$d['finishing_id']; ?>">View</a></td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- ================= Manage stage types ================= -->
    <div class="card no-print">
        <h2>Processing Stage Types Manage Karo</h2>
        <table class="data-table" style="max-width:640px;">
            <thead><tr><th>Stage</th><th>Naya Naam</th><th>Status</th><th>Action</th></tr></thead>
            <tbody>
                <?php foreach ($allStages as $stage): ?>
                <tr>
                    <td><?php echo sanitize($stage['stage_name']); ?></td>
                    <td>
                        <form method="POST" action="" style="display:flex; gap:6px;">
                            <input type="hidden" name="action" value="rename_stage">
                            <input type="hidden" name="stage_id" value="<?php echo (int)$stage['stage_id']; ?>">
                            <input type="text" name="new_stage_name" value="<?php echo sanitize($stage['stage_name']); ?>" style="width:150px; padding:4px 8px; font-size:12px;">
                            <button type="submit" style="padding:4px 10px; font-size:12px;">Rename</button>
                        </form>
                    </td>
                    <td><?php echo (int)$stage['is_active'] === 1 ? '<span class="badge-success">Active</span>' : '<span class="badge-warning">Inactive</span>'; ?></td>
                    <td>
                        <form method="POST" action="" style="display:inline;">
                            <input type="hidden" name="action" value="toggle_stage">
                            <input type="hidden" name="stage_id" value="<?php echo (int)$stage['stage_id']; ?>">
                            <button type="submit" style="padding:4px 10px; font-size:12px;"><?php echo (int)$stage['is_active'] === 1 ? 'Deactivate' : 'Activate'; ?></button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <form method="POST" action="" class="inline-form" style="margin-top:12px;">
            <input type="hidden" name="action" value="add_stage">
            <input type="text" name="stage_name" placeholder="Naya stage naam (jaise: Ironing)" required style="width:240px;">
            <button type="submit">+ Add Stage</button>
        </form>
        <p><small>Deactivate karne se purane items ki history pe koi asar nahi padta, sirf naye items ke liye wo checkbox nahi dikhega.</small></p>
    </div>
</main>
<style>
.badge-success { display:inline-block; background:#e3f5e9; color:#1e7e34; padding:2px 8px; border-radius:10px; font-size:12px; font-weight:600; }
</style>
<?php include __DIR__ . '/../../includes/footer.php'; ?>