<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/functions.php';
requirePermission($pdo, 'sales', 'view');

// ============================================
// Finishing Detail - checklist for ONE item's post-sale processing
// - Kaam karta hai is system ke apne POS sale item ke liye BHI, aur
//   kisi DUSRE software ke invoice se import/manual-add hue item ke
//   liye BHI - dono ka data (invoice no, customer, SKU, product,
//   qty) ab seedha sale_item_finishing table me hi hai, isliye alag
//   se sales/sarees join karne ki zaroorat nahi.
// - Har stage: pending -> sent_out (karigar/vendor ko bheja) -> completed
// - Jab tak har required stage 'completed' na ho, "Ready for Dispatch"
//   allow nahi hota.
// - Ready hone ke baad hi "Dispatch to Customer" ka button aata hai.
// ============================================

$finishingId = (int)($_GET['id'] ?? 0);

function getFinishing($pdo, $finishingId) {
    $stmt = $pdo->prepare(
        "SELECT sif.*,
                startedStaff.name AS started_by_name,
                dispatchedStaff.name AS dispatched_by_name,
                si.sale_id AS internal_sale_id
         FROM sale_item_finishing sif
         LEFT JOIN staff startedStaff ON startedStaff.staff_id = sif.started_by
         LEFT JOIN staff dispatchedStaff ON dispatchedStaff.staff_id = sif.dispatched_by
         LEFT JOIN sale_items si ON si.sale_item_id = sif.sale_item_id
         WHERE sif.finishing_id = :id"
    );
    $stmt->execute(['id' => $finishingId]);
    return $stmt->fetch();
}

$finishing = getFinishing($pdo, $finishingId);
if (!$finishing) {
    redirect('admin/sales/finishing.php');
}

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requirePermission($pdo, 'sales', 'edit');
    $action = $_POST['action'] ?? '';

    if ($action === 'send_out') {
        $taskId = (int)($_POST['task_id'] ?? 0);
        $vendorName = sanitize($_POST['vendor_name'] ?? '');
        $sentAtRaw = trim($_POST['sent_at'] ?? '') !== '' ? $_POST['sent_at'] : date('Y-m-d\TH:i');
        $sentAt = str_replace('T', ' ', $sentAtRaw) . ':00';
        $expectedReturn = trim($_POST['expected_return_date'] ?? '') !== '' ? $_POST['expected_return_date'] : null;

        $pdo->prepare(
            "UPDATE sale_item_finishing_tasks
             SET status = 'sent_out', vendor_name = :vn, sent_at = :sd, expected_return_date = :erd, updated_by = :by
             WHERE task_id = :id AND finishing_id = :fid"
        )->execute([
            'vn' => $vendorName ?: null, 'sd' => $sentAt, 'erd' => $expectedReturn,
            'by' => $_SESSION['staff_id'], 'id' => $taskId, 'fid' => $finishingId,
        ]);
        $success = 'Stage "sent out" mark ho gaya.';
    }

    if ($action === 'mark_completed') {
        $taskId = (int)($_POST['task_id'] ?? 0);
        $completedAtRaw = trim($_POST['completed_at'] ?? '') !== '' ? $_POST['completed_at'] : date('Y-m-d\TH:i');
        $completedAt = str_replace('T', ' ', $completedAtRaw) . ':00';
        $notes = sanitize($_POST['notes'] ?? '');

        $pdo->prepare(
            "UPDATE sale_item_finishing_tasks
             SET status = 'completed', completed_at = :cd, notes = :notes, updated_by = :by
             WHERE task_id = :id AND finishing_id = :fid"
        )->execute([
            'cd' => $completedAt, 'notes' => $notes ?: null,
            'by' => $_SESSION['staff_id'], 'id' => $taskId, 'fid' => $finishingId,
        ]);
        $success = 'Stage complete mark ho gaya.';
    }

    if ($action === 'reopen_task') {
        $taskId = (int)($_POST['task_id'] ?? 0);
        // Completed -> wapas sent_out, Sent out -> wapas pending (galti sudharne ke liye)
        $taskRow = $pdo->prepare("SELECT status FROM sale_item_finishing_tasks WHERE task_id = :id AND finishing_id = :fid");
        $taskRow->execute(['id' => $taskId, 'fid' => $finishingId]);
        $current = $taskRow->fetch();
        if ($current) {
            $newStatus = $current['status'] === 'completed' ? 'sent_out' : 'pending';
            $pdo->prepare("UPDATE sale_item_finishing_tasks SET status = :s, updated_by = :by WHERE task_id = :id")
                ->execute(['s' => $newStatus, 'by' => $_SESSION['staff_id'], 'id' => $taskId]);
            $success = 'Stage reopen ho gaya, dobara update kar sakte ho.';
        }
    }

    if ($action === 'add_stage_to_item') {
        $stageId = (int)($_POST['stage_id'] ?? 0);
        if ($stageId > 0 && in_array($finishing['overall_status'], ['in_process'], true)) {
            try {
                $pdo->prepare("INSERT INTO sale_item_finishing_tasks (finishing_id, stage_id, status) VALUES (:fid, :sid, 'pending')")
                    ->execute(['fid' => $finishingId, 'sid' => $stageId]);
                $success = 'Naya stage add ho gaya.';
            } catch (Exception $e) {
                $error = 'Ye stage already list me hai.';
            }
        }
    }

    if ($action === 'remove_stage') {
        $taskId = (int)($_POST['task_id'] ?? 0);
        $pdo->prepare("DELETE FROM sale_item_finishing_tasks WHERE task_id = :id AND finishing_id = :fid AND status = 'pending'")
            ->execute(['id' => $taskId, 'fid' => $finishingId]);
        $success = 'Stage remove ho gaya.';
    }

    if ($action === 'update_expected_date') {
        $expDate = trim($_POST['expected_ready_date'] ?? '') !== '' ? $_POST['expected_ready_date'] : null;
        $pdo->prepare("UPDATE sale_item_finishing SET expected_ready_date = :d WHERE finishing_id = :id")
            ->execute(['d' => $expDate, 'id' => $finishingId]);
        $success = 'Expected ready date update ho gaya.';
    }

    if ($action === 'mark_ready') {
        $pendingCount = $pdo->prepare(
            "SELECT COUNT(*) FROM sale_item_finishing_tasks WHERE finishing_id = :id AND status != 'completed'"
        );
        $pendingCount->execute(['id' => $finishingId]);
        $totalCount = $pdo->prepare("SELECT COUNT(*) FROM sale_item_finishing_tasks WHERE finishing_id = :id");
        $totalCount->execute(['id' => $finishingId]);

        if ((int)$totalCount->fetchColumn() === 0) {
            $error = 'Kam se kam ek stage add karo pehle.';
        } elseif ((int)$pendingCount->fetchColumn() > 0) {
            $error = 'Sab stages "completed" hone ke baad hi ready mark kar sakte ho.';
        } elseif ($finishing['overall_status'] !== 'in_process') {
            $error = 'Ye item already ready ya dispatch ho chuka hai.';
        } else {
            $pdo->prepare("UPDATE sale_item_finishing SET overall_status = 'ready', ready_at = NOW() WHERE finishing_id = :id")
                ->execute(['id' => $finishingId]);
            $pdo->prepare(
                "INSERT INTO audit_log (staff_id, action, module_name, record_id, description, ip_address)
                 VALUES (:sid, 'update', 'finishing', :rid, 'Item ready for dispatch mark hua', :ip)"
            )->execute(['sid' => $_SESSION['staff_id'], 'rid' => $finishingId, 'ip' => $_SERVER['REMOTE_ADDR'] ?? null]);
            $success = 'Item ready for dispatch mark ho gaya.';
        }
    }

    if ($action === 'dispatch') {
        if ($finishing['overall_status'] !== 'ready') {
            $error = 'Pehle item ko "Ready for Dispatch" mark karo.';
        } else {
            $pdo->prepare(
                "UPDATE sale_item_finishing SET overall_status = 'dispatched', dispatched_at = NOW(), dispatched_by = :by WHERE finishing_id = :id"
            )->execute(['by' => $_SESSION['staff_id'], 'id' => $finishingId]);
            $pdo->prepare(
                "INSERT INTO audit_log (staff_id, action, module_name, record_id, description, ip_address)
                 VALUES (:sid, 'update', 'finishing', :rid, 'Item customer ko dispatch hua', :ip)"
            )->execute(['sid' => $_SESSION['staff_id'], 'rid' => $finishingId, 'ip' => $_SERVER['REMOTE_ADDR'] ?? null]);
            $success = 'Item dispatch mark ho gaya.';
        }
    }

    $finishing = getFinishing($pdo, $finishingId);
}

// ---- Tasks (checklist) ----
$tasksStmt = $pdo->prepare(
    "SELECT t.*, fs.stage_name, fs.sort_order
     FROM sale_item_finishing_tasks t
     JOIN finishing_stages fs ON fs.stage_id = t.stage_id
     WHERE t.finishing_id = :id
     ORDER BY fs.sort_order ASC, t.task_id ASC"
);
$tasksStmt->execute(['id' => $finishingId]);
$tasks = $tasksStmt->fetchAll();

$totalTasks = count($tasks);
$completedTasks = count(array_filter($tasks, fn($t) => $t['status'] === 'completed'));
$allComplete = $totalTasks > 0 && $completedTasks === $totalTasks;

// ---- Stages not yet in this item's checklist (for "add extra stage") ----
$existingStageIds = array_column($tasks, 'stage_id');
$availableStagesStmt = $pdo->prepare(
    "SELECT * FROM finishing_stages WHERE is_active = 1"
    . (!empty($existingStageIds) ? " AND stage_id NOT IN (" . implode(',', array_map('intval', $existingStageIds)) . ")" : "")
    . " ORDER BY sort_order ASC"
);
$availableStagesStmt->execute();
$availableStages = $availableStagesStmt->fetchAll();

$waPhone = preg_replace('/[^0-9]/', '', (string)$finishing['customer_phone']);
if (strlen($waPhone) === 10) { $waPhone = '91' . $waPhone; }
$waMessage = "Namaste {$finishing['customer_name']}, aapka item ({$finishing['product_name']}, Invoice {$finishing['invoice_no']}) ready hai. Store aakar collect kar sakte hain. Dhanyawad!";
$waLink = 'https://wa.me/' . $waPhone . '?text=' . urlencode($waMessage);

include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/sidebar.php';
?>
<main>
    <div class="toolbar no-print">
        <a href="finishing.php">Back to Order Finishing</a>
        <button onclick="window.print()">Print Job Card</button>
        <?php if ($finishing['item_source'] === 'internal' && $finishing['internal_sale_id']): ?>
            <a href="invoice.php?id=<?php echo (int)$finishing['internal_sale_id']; ?>">View Original Invoice (This System)</a>
        <?php endif; ?>
    </div>

    <h1>Finishing: <?php echo sanitize($finishing['product_name']); ?></h1>
    <?php if ($finishing['item_source'] === 'external'): ?>
        <p><span class="badge-warning">External Invoice</span> <small><?php echo sanitize($finishing['imported_note'] ?? ''); ?></small></p>
    <?php elseif ($finishing['item_source'] === 'stock'): ?>
        <p><span class="badge-success">Stock / Pre-Sale Item</span> <small><?php echo sanitize($finishing['imported_note'] ?? ''); ?></small></p>
    <?php endif; ?>

    <?php if ($error): ?><div class="alert alert-error"><?php echo sanitize($error); ?></div><?php endif; ?>
    <?php if ($success): ?><div class="alert alert-success"><?php echo sanitize($success); ?></div><?php endif; ?>

    <div class="dashboard-grid">
        <div class="card stat-card">
            <p class="stat-label">Invoice</p>
            <p class="stat-value" style="font-size:18px;"><?php echo sanitize($finishing['invoice_no']); ?></p>
        </div>
        <div class="card stat-card">
            <p class="stat-label">Customer</p>
            <p class="stat-value" style="font-size:18px;"><?php echo sanitize($finishing['customer_name']); ?></p>
        </div>
        <div class="card stat-card">
            <p class="stat-label">Qty</p>
            <p class="stat-value"><?php echo (int)$finishing['item_qty']; ?></p>
        </div>
        <div class="card stat-card">
            <p class="stat-label">Status</p>
            <p class="stat-value" style="font-size:16px;">
                <?php
                    $labels = ['in_process' => 'In Process', 'ready' => 'Ready for Dispatch', 'dispatched' => 'Dispatched'];
                    echo sanitize($labels[$finishing['overall_status']] ?? $finishing['overall_status']);
                ?>
            </p>
        </div>
    </div>

    <div class="card no-print">
        <h2>Expected Ready Date</h2>
        <form method="POST" action="" class="inline-form">
            <input type="hidden" name="action" value="update_expected_date">
            <input type="date" name="expected_ready_date" value="<?php echo sanitize($finishing['expected_ready_date'] ?? ''); ?>">
            <button type="submit">Update</button>
        </form>
    </div>

    <div class="card">
        <h2>Processing Checklist (<?php echo $completedTasks; ?> / <?php echo $totalTasks; ?> complete)</h2>
        <table class="data-table">
            <thead>
                <tr>
                    <th>Stage</th>
                    <th>Status</th>
                    <th>Vendor / Karigar</th>
                    <th>Sent On</th>
                    <th>Expected Return</th>
                    <th>Received / Completed On</th>
                    <th class="no-print">Update</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($tasks as $t): ?>
                <tr>
                    <td><?php echo sanitize($t['stage_name']); ?></td>
                    <td>
                        <?php if ($t['status'] === 'completed'): ?>
                            <span class="badge-success">Completed</span>
                        <?php elseif ($t['status'] === 'sent_out'): ?>
                            <span class="badge-warning">Sent Out</span>
                        <?php else: ?>
                            <span class="badge-pending">Pending</span>
                        <?php endif; ?>
                    </td>
                    <td><?php echo sanitize($t['vendor_name'] ?? '-'); ?></td>
                    <td><?php echo $t['sent_at'] ? sanitize(date('d M Y, h:i A', strtotime($t['sent_at']))) : '-'; ?></td>
                    <td><?php echo $t['expected_return_date'] ? sanitize(date('d M Y', strtotime($t['expected_return_date']))) : '-'; ?></td>
                    <td><?php echo $t['completed_at'] ? sanitize(date('d M Y, h:i A', strtotime($t['completed_at']))) : '-'; ?></td>
                    <td class="no-print">
                        <?php if ($finishing['overall_status'] === 'in_process'): ?>
                            <?php if ($t['status'] === 'pending'): ?>
                                <details>
                                    <summary>Send Out</summary>
                                    <form method="POST" action="" style="margin-top:6px;">
                                        <input type="hidden" name="action" value="send_out">
                                        <input type="hidden" name="task_id" value="<?php echo (int)$t['task_id']; ?>">
                                        <input type="text" name="vendor_name" placeholder="Karigar / vendor naam" style="width:100%; margin-bottom:4px;">
                                        <label style="font-weight:normal; font-size:12px;">Sent on (date &amp; time): <input type="datetime-local" name="sent_at" value="<?php echo date('Y-m-d\TH:i'); ?>"></label><br>
                                        <label style="font-weight:normal; font-size:12px;">Expected back (date): <input type="date" name="expected_return_date"></label><br>
                                        <button type="submit" style="margin-top:4px; padding:4px 10px; font-size:12px;">Save</button>
                                    </form>
                                </details>
                            <?php elseif ($t['status'] === 'sent_out'): ?>
                                <details>
                                    <summary>Mark Completed</summary>
                                    <form method="POST" action="" style="margin-top:6px;">
                                        <input type="hidden" name="action" value="mark_completed">
                                        <input type="hidden" name="task_id" value="<?php echo (int)$t['task_id']; ?>">
                                        <label style="font-weight:normal; font-size:12px;">Received on (date &amp; time): <input type="datetime-local" name="completed_at" value="<?php echo date('Y-m-d\TH:i'); ?>"></label><br>
                                        <input type="text" name="notes" placeholder="Notes (optional)" style="width:100%; margin:4px 0;">
                                        <button type="submit" style="padding:4px 10px; font-size:12px;">Save</button>
                                    </form>
                                </details>
                            <?php endif; ?>
                            <?php if ($t['status'] !== 'pending'): ?>
                                <form method="POST" action="" style="margin-top:4px;" onsubmit="return confirm('Ye stage reopen karna hai?');">
                                    <input type="hidden" name="action" value="reopen_task">
                                    <input type="hidden" name="task_id" value="<?php echo (int)$t['task_id']; ?>">
                                    <button type="submit" style="padding:2px 8px; font-size:11px; background:#888;">Reopen</button>
                                </form>
                            <?php endif; ?>
                            <?php if ($t['status'] === 'pending'): ?>
                                <form method="POST" action="" style="margin-top:4px;" onsubmit="return confirm('Ye stage remove karna hai?');">
                                    <input type="hidden" name="action" value="remove_stage">
                                    <input type="hidden" name="task_id" value="<?php echo (int)$t['task_id']; ?>">
                                    <button type="submit" style="padding:2px 8px; font-size:11px; background:#b71c1c;">Remove</button>
                                </form>
                            <?php endif; ?>
                        <?php else: ?>
                            -
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($tasks)): ?>
                    <tr><td colspan="7">Koi stage add nahi hai.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>

        <?php if ($finishing['overall_status'] === 'in_process' && !empty($availableStages)): ?>
        <form method="POST" action="" class="inline-form no-print" style="margin-top:14px;">
            <input type="hidden" name="action" value="add_stage_to_item">
            <select name="stage_id" required>
                <option value="">+ Extra stage add karo (jaise: daag baad me pata chala)</option>
                <?php foreach ($availableStages as $s): ?>
                    <option value="<?php echo (int)$s['stage_id']; ?>"><?php echo sanitize($s['stage_name']); ?></option>
                <?php endforeach; ?>
            </select>
            <button type="submit">Add</button>
        </form>
        <?php endif; ?>
    </div>

    <?php if ($finishing['overall_status'] === 'in_process'): ?>
    <div class="card no-print">
        <h2>Ready for Dispatch</h2>
        <?php if ($allComplete): ?>
            <form method="POST" action="" onsubmit="return confirm('Sab stages complete hain. Item ready for dispatch mark karna hai?');">
                <input type="hidden" name="action" value="mark_ready">
                <button type="submit" style="background:#1e7e34;">Mark Ready for Dispatch</button>
            </form>
        <?php else: ?>
            <p style="color:#b71c1c;">Sab stages "Completed" hone ke baad hi ye item ready mark hoga.</p>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <?php if ($finishing['overall_status'] === 'ready'): ?>
    <div class="card">
        <h2>Dispatch to Customer</h2>
        <p><strong>Ready since:</strong> <?php echo sanitize(date('d M Y, h:i A', strtotime($finishing['ready_at']))); ?></p>
        <div class="toolbar no-print">
            <form method="POST" action="" onsubmit="return confirm('Item customer ko dispatch karna hai?');">
                <input type="hidden" name="action" value="dispatch">
                <button type="submit" style="background:#1e7e34;">Dispatch to Customer</button>
            </form>
            <?php if ($waPhone): ?>
                <a href="<?php echo $waLink; ?>" target="_blank">Notify Customer on WhatsApp</a>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($finishing['overall_status'] === 'dispatched'): ?>
    <div class="card">
        <h2>Dispatched</h2>
        <p><strong>Dispatched at:</strong> <?php echo sanitize(date('d M Y, h:i A', strtotime($finishing['dispatched_at']))); ?></p>
        <?php if ($finishing['dispatched_by_name']): ?><p><strong>By:</strong> <?php echo sanitize($finishing['dispatched_by_name']); ?></p><?php endif; ?>
    </div>
    <?php endif; ?>
</main>
<style>
.badge-success { display:inline-block; background:#e3f5e9; color:#1e7e34; padding:2px 8px; border-radius:10px; font-size:12px; font-weight:600; }
.badge-pending { display:inline-block; background:#eee; color:#666; padding:2px 8px; border-radius:10px; font-size:12px; font-weight:600; }
details summary { cursor:pointer; color:#3b0a0a; font-size:12px; font-weight:600; }
@media print {
    .sidebar, .no-print { display: none !important; }
    main { margin-left: 0 !important; }
}
</style>
<?php include __DIR__ . '/../../includes/footer.php'; ?>