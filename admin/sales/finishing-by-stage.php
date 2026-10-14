<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/functions.php';
requirePermission($pdo, 'sales', 'view');

// ============================================
// Process-wise (Stage-wise) Bulk Work Queue
// - finishing.php / finishing-detail.php me kaam ITEM-wise hota hai (ek
//   invoice ka ek product, ek time par).
// - Yahan kaam PROCESS-wise hota hai: ek stage (jaise "Decor / Calendar")
//   select karo, aur us stage ki zaroorat wale SAARE items - chahe
//   kisi bhi invoice/customer ke ho - ek hi list me dikhte hain.
// - Karigar/vendor ko bhejna ho ya wapas aane par complete mark karna ho,
//   dono BULK me (checkbox select karke, ek hi form se) ho sakta hai -
//   ek-ek item khol ke jaane ki zaroorat nahi.
// - Ye "add-on" page hai: kisi bhi item ko required stages CSV import ya
//   manual-add ya internal "start finishing" se hi milte hain (jaisa
//   pehle se hai) - yahan sirf un already-assigned stages ko process
//   karne ka bulk tareeka hai.
// ============================================

$error = '';
$success = '';
$search = sanitize($_GET['search'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requirePermission($pdo, 'sales', 'edit');
    $action = $_POST['action'] ?? '';
    $postedStageId = (int)($_POST['stage_id'] ?? 0);

    // ---- Bulk: selected items ko is stage ke liye vendor/karigar ko bhejo ----
    if ($action === 'bulk_send_out') {
        $taskIds = array_map('intval', $_POST['task_ids'] ?? []);
        $vendorName = sanitize($_POST['vendor_name'] ?? '');
        $sentAtRaw = trim($_POST['sent_at'] ?? '') !== '' ? $_POST['sent_at'] : date('Y-m-d\TH:i');
        $sentAt = str_replace('T', ' ', $sentAtRaw) . ':00';
        $expReturn = trim($_POST['expected_return_date'] ?? '') !== '' ? $_POST['expected_return_date'] : null;

        if (empty($taskIds)) {
            $error = 'Kam se kam ek item select karo.';
        } else {
            $placeholders = implode(',', array_fill(0, count($taskIds), '?'));
            $stmt = $pdo->prepare(
                "UPDATE sale_item_finishing_tasks
                 SET status = 'sent_out', vendor_name = ?, sent_at = ?, expected_return_date = ?, updated_by = ?
                 WHERE status = 'pending' AND stage_id = ? AND task_id IN ($placeholders)"
            );
            $stmt->execute(array_merge([$vendorName ?: null, $sentAt, $expReturn, $_SESSION['staff_id'], $postedStageId], $taskIds));
            $updated = $stmt->rowCount();

            if ($updated > 0) {
                $pdo->prepare(
                    "INSERT INTO audit_log (staff_id, action, module_name, record_id, description, ip_address)
                     VALUES (:sid, 'update', 'finishing', 0, :desc, :ip)"
                )->execute([
                    'sid' => $_SESSION['staff_id'],
                    'desc' => "Bulk send-out: $updated item(s) stage #$postedStageId - vendor: " . ($vendorName ?: '-'),
                    'ip' => $_SERVER['REMOTE_ADDR'] ?? null,
                ]);
                $success = "$updated item(s) " . ($vendorName !== '' ? "\"$vendorName\" ko" : '') . " bhej diye gaye.";
            } else {
                $error = 'Koi item update nahi hua - shayad wo already bheja ja chuka hai, page refresh karke dobara try karo.';
            }
        }
    }

    // ---- Bulk: selected items is stage ke liye complete mark karo ----
    if ($action === 'bulk_mark_completed') {
        $taskIds = array_map('intval', $_POST['task_ids'] ?? []);
        $completedAtRaw = trim($_POST['completed_at'] ?? '') !== '' ? $_POST['completed_at'] : date('Y-m-d\TH:i');
        $completedAt = str_replace('T', ' ', $completedAtRaw) . ':00';
        $notes = sanitize($_POST['notes'] ?? '');

        if (empty($taskIds)) {
            $error = 'Kam se kam ek item select karo.';
        } else {
            $placeholders = implode(',', array_fill(0, count($taskIds), '?'));
            $stmt = $pdo->prepare(
                "UPDATE sale_item_finishing_tasks
                 SET status = 'completed', completed_at = ?, notes = ?, updated_by = ?
                 WHERE status = 'sent_out' AND stage_id = ? AND task_id IN ($placeholders)"
            );
            $stmt->execute([$completedAt, $notes ?: null, $_SESSION['staff_id'], $postedStageId, ...$taskIds]);
            $updated = $stmt->rowCount();

            if ($updated > 0) {
                $pdo->prepare(
                    "INSERT INTO audit_log (staff_id, action, module_name, record_id, description, ip_address)
                     VALUES (:sid, 'update', 'finishing', 0, :desc, :ip)"
                )->execute([
                    'sid' => $_SESSION['staff_id'],
                    'desc' => "Bulk complete: $updated item(s) stage #$postedStageId",
                    'ip' => $_SERVER['REMOTE_ADDR'] ?? null,
                ]);
                $success = "$updated item(s) complete mark ho gaye. (Har item ko \"Ready for Dispatch\" karne ke liye, agar uske saare stages complete ho chuke hain, uske detail page pe jaake ek baar confirm karna hoga.)";
            } else {
                $error = 'Koi item update nahi hua - shayad wo already complete ho chuka hai, page refresh karke dobara try karo.';
            }
        }
    }
}

// ---- Stage master list + per-stage pending/sent_out counts (for tabs) ----
$allStages = $pdo->query("SELECT * FROM finishing_stages ORDER BY sort_order ASC, stage_id ASC")->fetchAll();
$activeStages = array_values(array_filter($allStages, fn($s) => (int)$s['is_active'] === 1));

$countsRaw = $pdo->query(
    "SELECT stage_id,
            SUM(status = 'pending') AS pending_cnt,
            SUM(status = 'sent_out') AS sent_cnt
     FROM sale_item_finishing_tasks
     GROUP BY stage_id"
)->fetchAll();
$stageCounts = [];
foreach ($countsRaw as $row) {
    $stageCounts[(int)$row['stage_id']] = ['pending' => (int)$row['pending_cnt'], 'sent' => (int)$row['sent_cnt']];
}

// ---- Kaunsa stage abhi select hai (GET ya POST dono se, taaki submit ke baad bhi wahi stage khula rahe) ----
$requestedStageId = (int)($_REQUEST['stage_id'] ?? 0);
$activeStageIds = array_column($activeStages, 'stage_id');
if (!in_array($requestedStageId, array_map('intval', $activeStageIds), true)) {
    $requestedStageId = !empty($activeStages) ? (int)$activeStages[0]['stage_id'] : 0;
}
$currentStage = null;
foreach ($activeStages as $s) {
    if ((int)$s['stage_id'] === $requestedStageId) { $currentStage = $s; break; }
}

$pendingItems = [];
$sentOutItems = [];
$recentlyCompleted = [];

if ($currentStage) {
    $searchClause = '';
    $searchParams = ['stage_id' => $requestedStageId];
    if ($search !== '') {
        $searchClause = " AND (sif.invoice_no LIKE :s1 OR sif.customer_name LIKE :s2 OR sif.product_name LIKE :s3 OR sif.sku LIKE :s4)";
        $searchParams['s1'] = "%$search%";
        $searchParams['s2'] = "%$search%";
        $searchParams['s3'] = "%$search%";
        $searchParams['s4'] = "%$search%";
    }

    // Items pending - abhi tak karigar ko bheje nahi gaye
    $pendingStmt = $pdo->prepare(
        "SELECT t.task_id, sif.finishing_id, sif.invoice_no, sif.invoice_date, sif.customer_name,
                sif.sku, sif.product_name, sif.item_qty, sif.item_source, sif.expected_ready_date
         FROM sale_item_finishing_tasks t
         JOIN sale_item_finishing sif ON sif.finishing_id = t.finishing_id
         WHERE t.stage_id = :stage_id AND t.status = 'pending'" . $searchClause . "
         ORDER BY (sif.expected_ready_date IS NULL) ASC, sif.expected_ready_date ASC, sif.invoice_no ASC"
    );
    $pendingStmt->execute($searchParams);
    $pendingItems = $pendingStmt->fetchAll();

    // Items already vendor/karigar ke paas - wapas aane ka wait
    $sentStmt = $pdo->prepare(
        "SELECT t.task_id, t.vendor_name, t.sent_at, t.expected_return_date,
                sif.finishing_id, sif.invoice_no, sif.customer_name,
                sif.sku, sif.product_name, sif.item_qty, sif.item_source
         FROM sale_item_finishing_tasks t
         JOIN sale_item_finishing sif ON sif.finishing_id = t.finishing_id
         WHERE t.stage_id = :stage_id AND t.status = 'sent_out'" . $searchClause . "
         ORDER BY t.sent_at ASC"
    );
    $sentStmt->execute($searchParams);
    $sentOutItems = $sentStmt->fetchAll();

    // Recently completed (isi stage ke liye) - sirf reference ke liye
    $recentStmt = $pdo->prepare(
        "SELECT t.completed_at, t.vendor_name, sif.invoice_no, sif.customer_name, sif.product_name, sif.item_qty
         FROM sale_item_finishing_tasks t
         JOIN sale_item_finishing sif ON sif.finishing_id = t.finishing_id
         WHERE t.stage_id = :stage_id AND t.status = 'completed'
         ORDER BY t.completed_at DESC LIMIT 10"
    );
    $recentStmt->execute(['stage_id' => $requestedStageId]);
    $recentlyCompleted = $recentStmt->fetchAll();
}

$today = date('Y-m-d');
$nowLocal = date('Y-m-d\TH:i');

include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/sidebar.php';
?>
<main>
    <div class="toolbar no-print">
        <a href="finishing.php">Back to Order Finishing</a>
    </div>

    <h1>Process-wise Work Queue</h1>
    <p><small>Item-wise nahi, PROCESS-wise kaam karo - ek stage select karo (jaise Decor, Fall &amp; Piko, Tassel) aur us process ki zaroorat wale saare invoices/products ki list ek jagah mil jayegi. Checkbox se select karke ek saath vendor ko bhejo ya complete mark karo - ek-ek item khol ke jaane ki zaroorat nahi.</small></p>

    <?php if ($error): ?><div class="alert alert-error"><?php echo sanitize($error); ?></div><?php endif; ?>
    <?php if ($success): ?><div class="alert alert-success"><?php echo sanitize($success); ?></div><?php endif; ?>

    <?php if (empty($activeStages)): ?>
        <div class="card"><p>Koi active processing stage nahi hai. Pehle "Order Finishing" page se kam se kam ek stage banao/activate karo.</p></div>
    <?php else: ?>

    <!-- ================= Stage tabs ================= -->
    <div class="card no-print" style="padding-bottom:10px;">
        <div style="display:flex; flex-wrap:wrap; gap:8px;">
            <?php foreach ($activeStages as $stage):
                $sid = (int)$stage['stage_id'];
                $isCurrent = $sid === $requestedStageId;
                $pc = $stageCounts[$sid]['pending'] ?? 0;
                $sc = $stageCounts[$sid]['sent'] ?? 0;
                $totalPending = $pc + $sc;
            ?>
            <a href="finishing-by-stage.php?stage_id=<?php echo $sid; ?>"
               style="text-decoration:none; padding:9px 16px; border-radius:20px; font-size:13.5px; font-weight:700;
                      <?php echo $isCurrent
                          ? 'background:linear-gradient(180deg, var(--maroon-700) 0%, var(--maroon-800) 100%); color:#fff;'
                          : 'background:var(--ivory-100); color:var(--ink-900); border:1px solid #e6dcc9;'; ?>">
                <?php echo sanitize($stage['stage_name']); ?>
                <?php if ($totalPending > 0): ?>
                    <span style="background:<?php echo $isCurrent ? 'rgba(255,255,255,0.25)' : 'var(--gold-400)'; ?>; color:<?php echo $isCurrent ? '#fff' : 'var(--maroon-950)'; ?>; padding:1px 8px; border-radius:10px; font-size:11.5px; margin-left:6px;">
                        <?php echo $totalPending; ?>
                    </span>
                <?php endif; ?>
            </a>
            <?php endforeach; ?>
        </div>
    </div>

    <?php if ($currentStage): ?>

    <div class="card no-print">
        <form method="GET" action="" class="toolbar">
            <input type="hidden" name="stage_id" value="<?php echo $requestedStageId; ?>">
            <input type="text" name="search" placeholder="Invoice no, customer, product ya SKU" value="<?php echo sanitize($search); ?>">
            <button type="submit">Search</button>
            <?php if ($search !== ''): ?><a href="finishing-by-stage.php?stage_id=<?php echo $requestedStageId; ?>">Clear</a><?php endif; ?>
        </form>
    </div>

    <!-- ================= Pending: not yet sent to vendor ================= -->
    <div class="card">
        <h2><?php echo sanitize($currentStage['stage_name']); ?> - Bhejne Ke Liye Pending (<?php echo count($pendingItems); ?>)</h2>
        <?php if (empty($pendingItems)): ?>
            <p>Is process ke liye abhi koi item pending nahi hai.</p>
        <?php else: ?>
        <form method="POST" action="">
            <input type="hidden" name="action" value="bulk_send_out">
            <input type="hidden" name="stage_id" value="<?php echo $requestedStageId; ?>">
            <table class="data-table">
                <thead>
                    <tr>
                        <th><input type="checkbox" class="select-all" data-target="pending-checks"></th>
                        <th>Invoice</th>
                        <th>Item</th>
                        <th>Qty</th>
                        <th>Customer</th>
                        <th>Source</th>
                        <th>Expected Ready</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($pendingItems as $it): ?>
                    <tr>
                        <td><input type="checkbox" name="task_ids[]" value="<?php echo (int)$it['task_id']; ?>" class="pending-checks"></td>
                        <td><?php echo sanitize($it['invoice_no']); ?></td>
                        <td><?php echo sanitize($it['product_name']); ?><br><small><?php echo sanitize($it['sku']); ?></small></td>
                        <td><?php echo (int)$it['item_qty']; ?></td>
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
                        <td><?php echo $it['expected_ready_date'] ? sanitize(date('d M Y', strtotime($it['expected_ready_date']))) : '-'; ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <div class="toolbar no-print" style="margin-top:10px;">
                <button type="button" onclick="printSelectedTags('pending-checks')">Print Tags for Selected</button>
            </div>
            <div class="card" style="background:var(--ivory-100); margin-top:10px; box-shadow:none;">
                <p><strong>Selected items ko vendor/karigar ko bhejo:</strong></p>
                <table class="data-table" style="max-width:600px;">
                    <tr><td>Vendor / Karigar Naam</td><td><input type="text" name="vendor_name" style="width:100%;" placeholder="jaise: Ramesh Decor Works"></td></tr>
                    <tr><td>Sent On (date &amp; time)</td><td><input type="datetime-local" name="sent_at" value="<?php echo $nowLocal; ?>"></td></tr>
                    <tr><td>Expected Return Date</td><td><input type="date" name="expected_return_date" min="<?php echo $today; ?>"></td></tr>
                </table>
                <button type="submit" style="margin-top:10px;">Selected Items Vendor Ko Send Out Karo</button>
            </div>
        </form>
        <?php endif; ?>
    </div>

    <!-- ================= Sent out: with vendor, waiting to come back ================= -->
    <div class="card">
        <h2><?php echo sanitize($currentStage['stage_name']); ?> - Vendor Ke Paas Hai (<?php echo count($sentOutItems); ?>)</h2>
        <?php if (empty($sentOutItems)): ?>
            <p>Is process ke liye abhi koi item kisi vendor ke paas nahi hai.</p>
        <?php else: ?>
        <form method="POST" action="">
            <input type="hidden" name="action" value="bulk_mark_completed">
            <input type="hidden" name="stage_id" value="<?php echo $requestedStageId; ?>">
            <table class="data-table">
                <thead>
                    <tr>
                        <th><input type="checkbox" class="select-all" data-target="sent-checks"></th>
                        <th>Invoice</th>
                        <th>Item</th>
                        <th>Qty</th>
                        <th>Customer</th>
                        <th>Vendor</th>
                        <th>Sent On</th>
                        <th>Expected Return</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($sentOutItems as $it): ?>
                    <tr>
                        <td><input type="checkbox" name="task_ids[]" value="<?php echo (int)$it['task_id']; ?>" class="sent-checks"></td>
                        <td><?php echo sanitize($it['invoice_no']); ?></td>
                        <td><?php echo sanitize($it['product_name']); ?><br><small><?php echo sanitize($it['sku']); ?></small></td>
                        <td><?php echo (int)$it['item_qty']; ?></td>
                        <td><?php echo sanitize($it['customer_name']); ?></td>
                        <td><?php echo sanitize($it['vendor_name'] ?? '-'); ?></td>
                        <td><?php echo $it['sent_at'] ? sanitize(date('d M Y, h:i A', strtotime($it['sent_at']))) : '-'; ?></td>
                        <td><?php echo $it['expected_return_date'] ? sanitize(date('d M Y', strtotime($it['expected_return_date']))) : '-'; ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <div class="card" style="background:var(--ivory-100); margin-top:14px; box-shadow:none;">
                <p><strong>Selected items wapas aa gaye - complete mark karo:</strong></p>
                <table class="data-table" style="max-width:600px;">
                    <tr><td>Received On (date &amp; time)</td><td><input type="datetime-local" name="completed_at" value="<?php echo $nowLocal; ?>"></td></tr>
                    <tr><td>Note (optional, sabke liye common)</td><td><input type="text" name="notes" style="width:100%;"></td></tr>
                </table>
                <button type="submit" style="margin-top:10px;">Selected Items Complete Mark Karo</button>
            </div>
        </form>
        <?php endif; ?>
    </div>

    <!-- ================= Recently completed (reference only) ================= -->
    <div class="card">
        <h2><?php echo sanitize($currentStage['stage_name']); ?> - Recently Completed</h2>
        <table class="data-table">
            <thead><tr><th>Invoice</th><th>Item</th><th>Vendor</th><th>Completed At</th></tr></thead>
            <tbody>
                <?php if (empty($recentlyCompleted)): ?>
                    <tr><td colspan="4">Abhi tak is process ka koi item complete nahi hua.</td></tr>
                <?php else: ?>
                    <?php foreach ($recentlyCompleted as $rc): ?>
                    <tr>
                        <td><?php echo sanitize($rc['invoice_no']); ?></td>
                        <td><?php echo sanitize($rc['product_name']); ?> (x<?php echo (int)$rc['item_qty']; ?>)</td>
                        <td><?php echo sanitize($rc['vendor_name'] ?? '-'); ?></td>
                        <td><?php echo $rc['completed_at'] ? sanitize(date('d M Y, h:i A', strtotime($rc['completed_at']))) : '-'; ?></td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <?php endif; ?>
    <?php endif; ?>
</main>
<script>
document.querySelectorAll('.select-all').forEach(function (master) {
    master.addEventListener('change', function () {
        var targetClass = master.getAttribute('data-target');
        document.querySelectorAll('.' + targetClass).forEach(function (cb) {
            cb.checked = master.checked;
        });
    });
});

function printSelectedTags(checkboxClass) {
    var ids = Array.from(document.querySelectorAll('.' + checkboxClass + ':checked')).map(function (cb) { return cb.value; });
    if (ids.length === 0) {
        alert('Pehle kam se kam ek item select karo (checkbox tick karo), phir tags print karo.');
        return;
    }
    window.open('finishing-print-tags.php?task_ids=' + ids.join(','), '_blank');
}
</script>
<?php include __DIR__ . '/../../includes/footer.php'; ?>