<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/functions.php';
requirePermission($pdo, 'inventory', 'view');

$error = '';
$success = '';

$saree_id = (int)($_GET['id'] ?? $_POST['saree_id'] ?? 0);

if ($saree_id === 0) {
    redirect('admin/inventory/list.php');
}

// Handle adjustment submit
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requirePermission($pdo, 'inventory', 'edit');
    $change_type = $_POST['change_type'] ?? '';
    $quantity    = (int)($_POST['quantity'] ?? 0);
    $notes       = sanitize($_POST['notes'] ?? '');

    $allowed_types = ['purchase', 'return', 'damage', 'adjustment'];

    if (!in_array($change_type, $allowed_types, true)) {
        $error = 'Invalid change type.';
    } elseif ($quantity === 0) {
        $error = 'Quantity 0 nahi ho sakti.';
    } else {
        // Determine sign: purchase/return add stock, damage/adjustment can go either way
        // For damage, always subtract. For purchase/return, always add.
        // For adjustment, user enters +/- directly via a signed field.
        if ($change_type === 'damage') {
            $quantity_change = -abs($quantity);
        } elseif (in_array($change_type, ['purchase', 'return'], true)) {
            $quantity_change = abs($quantity);
        } else { // adjustment - allow negative input directly
            $quantity_change = $quantity;
        }

        try {
            $pdo->beginTransaction();

            // Lock and fetch current stock
            $stockStmt = $pdo->prepare("SELECT quantity FROM stock WHERE saree_id = :id FOR UPDATE");
            $stockStmt->execute(['id' => $saree_id]);
            $stockRow = $stockStmt->fetch();

            if (!$stockRow) {
                // No stock row yet - create one
                $pdo->prepare("INSERT INTO stock (saree_id, quantity) VALUES (:id, 0)")
                    ->execute(['id' => $saree_id]);
                $currentQty = 0;
            } else {
                $currentQty = (int)$stockRow['quantity'];
            }

            $newQty = $currentQty + $quantity_change;

            if ($newQty < 0) {
                throw new Exception("Stock negative nahi ho sakta. Current stock: $currentQty, ye change karega: $quantity_change");
            }

            $pdo->prepare("UPDATE stock SET quantity = :qty WHERE saree_id = :id")
                ->execute(['qty' => $newQty, 'id' => $saree_id]);

            $moveStmt = $pdo->prepare(
                "INSERT INTO stock_movements (saree_id, change_type, quantity_change, notes, created_by)
                 VALUES (:saree_id, :change_type, :quantity_change, :notes, :staff_id)"
            );
            $moveStmt->execute([
                'saree_id' => $saree_id,
                'change_type' => $change_type,
                'quantity_change' => $quantity_change,
                'notes' => $notes ?: null,
                'staff_id' => $_SESSION['staff_id'],
            ]);

            $pdo->commit();
            $success = "Stock update ho gaya. Naya stock: $newQty";
        } catch (Exception $e) {
            $pdo->rollBack();
            $error = $e->getMessage();
        }
    }
}

// Fetch saree info + current stock
$stmt = $pdo->prepare(
    "SELECT s.saree_id, s.sku, s.name, COALESCE(st.quantity, 0) AS quantity
     FROM sarees s
     LEFT JOIN stock st ON s.saree_id = st.saree_id
     WHERE s.saree_id = :id"
);
$stmt->execute(['id' => $saree_id]);
$saree = $stmt->fetch();

if (!$saree) {
    redirect('admin/inventory/list.php');
}

// Fetch recent movement history for this saree
$historyStmt = $pdo->prepare(
    "SELECT sm.change_type, sm.quantity_change, sm.notes, sm.created_at, st.name AS staff_name
     FROM stock_movements sm
     LEFT JOIN staff st ON sm.created_by = st.staff_id
     WHERE sm.saree_id = :id
     ORDER BY sm.created_at DESC
     LIMIT 20"
);
$historyStmt->execute(['id' => $saree_id]);
$history = $historyStmt->fetchAll();

include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/sidebar.php';
?>
<main>
    <h1>Adjust Stock</h1>

    <?php if ($error): ?>
        <div class="alert alert-error"><?php echo sanitize($error); ?></div>
    <?php endif; ?>
    <?php if ($success): ?>
        <div class="alert alert-success"><?php echo sanitize($success); ?></div>
    <?php endif; ?>

    <div class="toolbar">
        <a href="list.php">Back to Inventory List</a>
        <a href="edit.php?id=<?php echo (int)$saree['saree_id']; ?>">Edit Saree Details</a>
    </div>

    <div class="card">
        <h2><?php echo sanitize($saree['name']); ?> (SKU: <?php echo sanitize($saree['sku']); ?>)</h2>
        <p>Current Stock: <strong><?php echo (int)$saree['quantity']; ?></strong></p>

        <form method="POST" action="" class="form-grid">
            <input type="hidden" name="saree_id" value="<?php echo (int)$saree['saree_id']; ?>">

            <div>
                <label>Change Type *</label>
                <select name="change_type" required>
                    <option value="purchase">Purchase (naya stock aaya)</option>
                    <option value="return">Return (customer se wapas aaya)</option>
                    <option value="damage">Damage / Wastage</option>
                    <option value="adjustment">Manual Adjustment (+/-)</option>
                </select>
            </div>
            <div>
                <label>Quantity *</label>
                <input type="number" name="quantity" required>
                <small>Purchase/Return/Damage me positive number do. Adjustment me negative bhi de sakte ho (e.g. -2).</small>
            </div>
            <div style="grid-column: span 2;">
                <label>Notes</label>
                <input type="text" name="notes" placeholder="Optional - reason likho">
            </div>
            <div>
                <button type="submit">Update Stock</button>
            </div>
        </form>
    </div>

    <div class="card">
        <h2>Recent Stock History</h2>
        <table class="data-table">
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Type</th>
                    <th>Change</th>
                    <th>Notes</th>
                    <th>By</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($history)): ?>
                    <tr><td colspan="5">Koi history nahi hai abhi tak.</td></tr>
                <?php else: ?>
                    <?php foreach ($history as $h): ?>
                    <tr>
                        <td><?php echo sanitize(date('d M Y, h:i A', strtotime($h['created_at']))); ?></td>
                        <td><?php echo sanitize(ucfirst($h['change_type'])); ?></td>
                        <td><?php echo ($h['quantity_change'] > 0 ? '+' : '') . (int)$h['quantity_change']; ?></td>
                        <td><?php echo sanitize($h['notes'] ?? '-'); ?></td>
                        <td><?php echo sanitize($h['staff_name'] ?? '-'); ?></td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</main>
<?php include __DIR__ . '/../../includes/footer.php'; ?>