<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/functions.php';
requirePermission($pdo, 'sales', 'view');

// ============================================
// Import Invoices (CSV) - bulk add items from ANOTHER software's
// invoices straight into the finishing tracker. Har CSV row ek item
// hai. Agar row me "Stages" column bhara hai, wo item turant apni
// checklist ke saath "In Process" me chala jayega - jaisa inventory
// bulk-import turant stock update kar deta hai, waise hi.
//
// Expected columns (row 1 header, order fixed):
// InvoiceNo, InvoiceDate, CustomerName, CustomerPhone, SKU,
// ProductName, Quantity, ExpectedReadyDate, Stages
//
// Stages column optional - pipe (|) se alag likho, jaise:
//   Polish|Fall & Piko
// Khaali chhod do to item bina checklist ke create hoga, baad me
// finishing-detail.php se stages add kar sakte ho.
// ============================================

$error = '';
$results = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['csv_file'])) {
    requirePermission($pdo, 'sales', 'edit');

    if ($_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
        $error = 'File upload fail hua. Dobara try karo.';
    } else {
        $tmpPath = $_FILES['csv_file']['tmp_name'];
        $handle = fopen($tmpPath, 'r');

        if (!$handle) {
            $error = 'File open nahi ho payi.';
        } else {
            $rowNum = 0;
            $added = 0;
            $skipped = [];

            // Preload active stages into a lowercase-name => stage_id cache
            $stageCache = [];
            $stagesRs = $pdo->query("SELECT stage_id, stage_name FROM finishing_stages WHERE is_active = 1");
            foreach ($stagesRs as $st) {
                $stageCache[strtolower(trim($st['stage_name']))] = $st['stage_id'];
            }

            while (($row = fgetcsv($handle)) !== false) {
                $rowNum++;

                // Skip header row
                if ($rowNum === 1 && strtolower(trim($row[0])) === 'invoiceno') {
                    continue;
                }
                if (count(array_filter($row, fn($v) => trim((string)$v) !== '')) === 0) {
                    continue; // blank line
                }

                if (count($row) < 7) {
                    $skipped[] = "Row $rowNum: kam columns hain, skip kiya.";
                    continue;
                }

                [$invoiceNo, $invoiceDateRaw, $customerName, $customerPhone,
                 $sku, $productName, $qtyRaw, $expectedDateRaw, $stagesRaw] = array_pad($row, 9, '');

                $invoiceNo = trim($invoiceNo);
                $customerName = trim($customerName) ?: 'Walk-in';
                $customerPhone = trim($customerPhone);
                $sku = trim($sku);
                $productName = trim($productName);
                $qty = is_numeric($qtyRaw) ? (int)$qtyRaw : 0;

                if ($invoiceNo === '' || $productName === '' || $qty < 1) {
                    $skipped[] = "Row $rowNum: InvoiceNo/ProductName/Quantity sahi se nahi bhara, skip kiya.";
                    continue;
                }

                $invoiceDate = trim($invoiceDateRaw) !== '' && strtotime($invoiceDateRaw) ? date('Y-m-d', strtotime($invoiceDateRaw)) : date('Y-m-d');
                $expectedDate = trim($expectedDateRaw) !== '' && strtotime($expectedDateRaw) ? date('Y-m-d', strtotime($expectedDateRaw)) : null;

                // Parse stages (pipe separated); unknown names are warned but row still imports
                $stageIds = [];
                $unknownStages = [];
                if (trim($stagesRaw) !== '') {
                    foreach (explode('|', $stagesRaw) as $sName) {
                        $sName = strtolower(trim($sName));
                        if ($sName === '') continue;
                        if (isset($stageCache[$sName])) {
                            $stageIds[] = $stageCache[$sName];
                        } else {
                            $unknownStages[] = trim($sName);
                        }
                    }
                }

                try {
                    $pdo->beginTransaction();

                    $insSif = $pdo->prepare(
                        "INSERT INTO sale_item_finishing
                            (sale_item_id, item_source, invoice_no, invoice_date, customer_name, customer_phone,
                             sku, product_name, item_qty, overall_status, expected_ready_date, started_by, imported_note)
                         VALUES
                            (NULL, 'external', :inv, :idate, :cname, :cphone,
                             :sku, :pname, :qty, 'in_process', :exp, :by, 'CSV import (other software invoice)')"
                    );
                    $insSif->execute([
                        'inv' => $invoiceNo, 'idate' => $invoiceDate, 'cname' => $customerName,
                        'cphone' => $customerPhone ?: null, 'sku' => $sku ?: null, 'pname' => $productName,
                        'qty' => $qty, 'exp' => $expectedDate, 'by' => $_SESSION['staff_id'],
                    ]);
                    $finishingId = $pdo->lastInsertId();

                    if (!empty($stageIds)) {
                        $insTask = $pdo->prepare(
                            "INSERT INTO sale_item_finishing_tasks (finishing_id, stage_id, status) VALUES (:fid, :stid, 'pending')"
                        );
                        foreach (array_unique($stageIds) as $stageId) {
                            $insTask->execute(['fid' => $finishingId, 'stid' => $stageId]);
                        }
                    }

                    $pdo->prepare(
                        "INSERT INTO audit_log (staff_id, action, module_name, record_id, description, ip_address)
                         VALUES (:sid, 'create', 'finishing', :rid, :desc, :ip)"
                    )->execute([
                        'sid' => $_SESSION['staff_id'],
                        'rid' => $finishingId,
                        'desc' => "CSV import: invoice $invoiceNo, item $productName",
                        'ip' => $_SERVER['REMOTE_ADDR'] ?? null,
                    ]);

                    $pdo->commit();
                    $added++;

                    if (!empty($unknownStages)) {
                        $skipped[] = "Row $rowNum: item add ho gaya, lekin ye stage naam nahi mile (typo check karo): " . implode(', ', $unknownStages);
                    }
                } catch (Exception $e) {
                    $pdo->rollBack();
                    $skipped[] = "Row $rowNum: DB error - " . $e->getMessage();
                }
            }

            fclose($handle);

            $results = ['added' => $added, 'skipped' => $skipped];
        }
    }
}

include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/sidebar.php';
?>
<main>
    <div class="toolbar no-print">
        <a href="finishing.php">Order Finishing</a>
    </div>

    <h1>Import Invoices (CSV) - Doosre Software Se</h1>
    <p><small>Agar invoice kisi aur billing software me bani hai, uska data yahan CSV se ek saath import kar do - har item automatically finishing tracking me aa jayega.</small></p>

    <?php if ($error): ?>
        <div class="alert alert-error"><?php echo sanitize($error); ?></div>
    <?php endif; ?>

    <?php if ($results !== null): ?>
        <div class="alert alert-success">
            <?php echo (int)$results['added']; ?> item(s) successfully finishing tracking me add ho gaye.
        </div>
        <?php if (!empty($results['skipped'])): ?>
            <div class="alert alert-error">
                <strong><?php echo count($results['skipped']); ?> row(s) me note/skip hua:</strong>
                <ul>
                    <?php foreach ($results['skipped'] as $msg): ?>
                        <li><?php echo sanitize($msg); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>
        <p><a href="finishing.php">Order Finishing List Dekho</a></p>
    <?php endif; ?>

    <div class="card">
        <h2>Step 1: Template Download Karo</h2>
        <p>Neeche diye gaye format me apna data bharo (dusre software se export/copy kar ke).</p>
        <p><a href="<?php echo BASE_URL; ?>assets/sample_external_invoice_template.csv" download>Download Sample Template (CSV)</a></p>
        <p><small>Columns: InvoiceNo, InvoiceDate (YYYY-MM-DD), CustomerName, CustomerPhone, SKU, ProductName, Quantity, ExpectedReadyDate (optional), Stages (optional)</small></p>
        <p><small><strong>Stages column:</strong> agar pata hai is item ko kya processing chahiye, naam pipe ( | ) se alag likh do - jaise <code>Polish|Fall &amp; Piko</code>. Khaali chhoda to item bina checklist ke import hoga, baad me detail page se stage add kar sakte ho. Stage ka naam bilkul waisa hi likhna jaisa "Order Finishing" page ke "Manage Stage Types" me dikhta hai.</small></p>
    </div>

    <div class="card">
        <h2>Step 2: Bhari hui CSV Upload Karo</h2>
        <form method="POST" action="" enctype="multipart/form-data" class="inline-form">
            <input type="file" name="csv_file" accept=".csv" required>
            <button type="submit">Import Items</button>
        </form>
    </div>

    <div class="card">
        <h2>Ek Item Manually Bhi Add Kar Sakte Ho</h2>
        <p><small>Agar sirf ek-do item hai aur poori CSV banana zyada kaam lagta hai, "Order Finishing" page par jaake "Manual Item Add Karo" section use karo.</small></p>
        <p><a href="finishing.php">Order Finishing Par Jao</a></p>
    </div>
</main>
<?php include __DIR__ . '/../../includes/footer.php'; ?>