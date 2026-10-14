<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/functions.php';
requirePermission($pdo, 'vendors', 'view');

// ============================================
// Vendors - List (+ Add/Edit, same file, like Customers module)
// - Every vendor's outstanding due is computed live:
//   Total of all their non-cancelled Purchase Orders  MINUS  Total payments made
// - Duplicate phone (if given) is blocked, same as Customers
// ============================================

$error = '';
$success = '';

// ---- Handle Add/Edit submit ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save') {
    requirePermission($pdo, 'vendors', 'edit');
    $vendorId = (int)($_POST['vendor_id'] ?? 0);
    $name     = sanitize($_POST['name'] ?? '');
    $phone    = sanitize($_POST['phone'] ?? '');
    $email    = sanitize($_POST['email'] ?? '');
    $address  = sanitize($_POST['address'] ?? '');
    $gstin    = sanitize($_POST['gstin'] ?? '');

    if ($name === '') {
        $error = 'Vendor name zaroori hai.';
    } else {
        if ($phone !== '') {
            $dupCheck = $pdo->prepare("SELECT vendor_id FROM vendors WHERE phone = :phone AND vendor_id != :id");
            $dupCheck->execute(['phone' => $phone, 'id' => $vendorId]);
            if ($dupCheck->fetch()) {
                $error = 'Ye phone number kisi aur vendor ke naam pe already registered hai.';
            }
        }

        if ($error === '') {
            if ($vendorId > 0) {
                $stmt = $pdo->prepare(
                    "UPDATE vendors SET name = :name, phone = :phone, email = :email, address = :address, gstin = :gstin
                     WHERE vendor_id = :id"
                );
                $stmt->execute(['name' => $name, 'phone' => $phone ?: null, 'email' => $email ?: null, 'address' => $address ?: null, 'gstin' => $gstin ?: null, 'id' => $vendorId]);
                $success = 'Vendor update ho gaya.';
            } else {
                $stmt = $pdo->prepare(
                    "INSERT INTO vendors (name, phone, email, address, gstin) VALUES (:name, :phone, :email, :address, :gstin)"
                );
                $stmt->execute(['name' => $name, 'phone' => $phone ?: null, 'email' => $email ?: null, 'address' => $address ?: null, 'gstin' => $gstin ?: null]);
                $success = 'Vendor add ho gaya.';
            }
        }
    }
}

// ---- Handle activate/deactivate (soft - never hard delete, POs reference vendor_id) ----
if (($_GET['toggle'] ?? '') !== '' && !empty($_GET['id'])) {
    requirePermission($pdo, 'vendors', 'edit');
    $pdo->prepare("UPDATE vendors SET is_active = 1 - is_active WHERE vendor_id = :id")
        ->execute(['id' => (int)$_GET['id']]);
    redirect('admin/vendors/list.php');
}

// ---- Editing an existing vendor? ----
$editVendor = null;
if (!empty($_GET['edit'])) {
    $stmt = $pdo->prepare("SELECT * FROM vendors WHERE vendor_id = :id");
    $stmt->execute(['id' => (int)$_GET['edit']]);
    $editVendor = $stmt->fetch();
}

$search = sanitize($_GET['search'] ?? '');
$whereSql = '';
$whereParams = [];
if ($search !== '') {
    $whereSql = " WHERE v.name LIKE :s1 OR v.phone LIKE :s2 OR v.vendor_id = :s3";
    $whereParams = ['s1' => "%$search%", 's2' => "%$search%", 's3' => is_numeric($search) ? (int)$search : 0];
}

// Outstanding = SUM(non-cancelled PO totals) - SUM(payments made)
$sql = "SELECT v.*,
               COALESCE((SELECT SUM(po.total_amount) FROM purchase_orders po WHERE po.vendor_id = v.vendor_id AND po.status != 'cancelled'), 0) AS total_ordered,
               COALESCE((SELECT SUM(vp.amount) FROM vendor_payments vp WHERE vp.vendor_id = v.vendor_id), 0) AS total_paid
        FROM vendors v" . $whereSql . " ORDER BY v.name ASC";
$stmt = $pdo->prepare($sql);
$stmt->execute($whereParams);
$vendors = $stmt->fetchAll();

include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/sidebar.php';
?>
<main>
    <h1>Vendors</h1>

    <?php if ($error): ?><div class="alert alert-error"><?php echo sanitize($error); ?></div><?php endif; ?>
    <?php if ($success): ?><div class="alert alert-success"><?php echo sanitize($success); ?></div><?php endif; ?>

    <div class="card">
        <h2><?php echo $editVendor ? 'Edit Vendor' : 'Add New Vendor'; ?></h2>
        <form method="POST" action="" class="form-grid">
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="vendor_id" value="<?php echo $editVendor ? (int)$editVendor['vendor_id'] : 0; ?>">
            <div>
                <label>Name *</label>
                <input type="text" name="name" required value="<?php echo $editVendor ? sanitize($editVendor['name']) : ''; ?>">
            </div>
            <div>
                <label>Phone</label>
                <input type="text" name="phone" value="<?php echo $editVendor ? sanitize($editVendor['phone'] ?? '') : ''; ?>">
            </div>
            <div>
                <label>Email</label>
                <input type="email" name="email" value="<?php echo $editVendor ? sanitize($editVendor['email'] ?? '') : ''; ?>">
            </div>
            <div>
                <label>GSTIN</label>
                <input type="text" name="gstin" value="<?php echo $editVendor ? sanitize($editVendor['gstin'] ?? '') : ''; ?>">
            </div>
            <div style="grid-column: span 2;">
                <label>Address</label>
                <input type="text" name="address" value="<?php echo $editVendor ? sanitize($editVendor['address'] ?? '') : ''; ?>">
            </div>
            <div style="grid-column: span 2;">
                <button type="submit"><?php echo $editVendor ? 'Update Vendor' : 'Add Vendor'; ?></button>
                <?php if ($editVendor): ?>
                    <a href="list.php" style="margin-left:10px;">Cancel</a>
                <?php endif; ?>
            </div>
        </form>
    </div>

    <div class="card">
        <div class="toolbar">
            <form method="GET" action="">
                <input type="text" name="search" placeholder="Naam, phone, ya Vendor ID se search karo" value="<?php echo sanitize($search); ?>">
                <button type="submit">Search</button>
            </form>
        </div>

        <table class="data-table">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Name</th>
                    <th>Phone</th>
                    <th>GSTIN</th>
                    <th>Total Ordered (₹)</th>
                    <th>Outstanding Due (₹)</th>
                    <th>Status</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($vendors)): ?>
                    <tr><td colspan="8">Koi vendor nahi mila. Upar se add karo.</td></tr>
                <?php else: ?>
                    <?php foreach ($vendors as $v):
                        $due = (float)$v['total_ordered'] - (float)$v['total_paid'];
                    ?>
                    <tr>
                        <td><code class="id-chip" onclick="navigator.clipboard.writeText('<?php echo (int)$v['vendor_id']; ?>'); this.title='Copied!';" title="Click to copy">#<?php echo (int)$v['vendor_id']; ?></code></td>
                        <td><?php echo sanitize($v['name']); ?></td>
                        <td><?php echo sanitize($v['phone'] ?? '-'); ?></td>
                        <td><?php echo sanitize($v['gstin'] ?? '-'); ?></td>
                        <td>₹<?php echo number_format($v['total_ordered'], 2); ?></td>
                        <td>
                            <?php if ($due > 0): ?>
                                <span class="badge-warning">₹<?php echo number_format($due, 2); ?></span>
                            <?php else: ?>
                                -
                            <?php endif; ?>
                        </td>
                        <td><?php echo $v['is_active'] ? 'Active' : 'Inactive'; ?></td>
                        <td>
                            <a href="list.php?edit=<?php echo (int)$v['vendor_id']; ?>">Edit</a> |
                            <a href="purchase-orders.php?vendor_id=<?php echo (int)$v['vendor_id']; ?>">New PO</a> |
                            <a href="list.php?toggle=1&id=<?php echo (int)$v['vendor_id']; ?>" onclick="return confirm('<?php echo $v['is_active'] ? 'Deactivate' : 'Activate'; ?> this vendor?');">
                                <?php echo $v['is_active'] ? 'Deactivate' : 'Activate'; ?>
                            </a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</main>
<?php include __DIR__ . '/../../includes/footer.php'; ?>