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

$categories = $pdo->query("SELECT category_id, name FROM categories ORDER BY name ASC")->fetchAll();

// Handle form submit
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requirePermission($pdo, 'inventory', 'edit');
    $sku            = sanitize($_POST['sku'] ?? '');
    $design_code    = sanitize($_POST['design_code'] ?? '');
    $name           = sanitize($_POST['name'] ?? '');
    $category_id    = (int)($_POST['category_id'] ?? 0);
    $fabric         = sanitize($_POST['fabric'] ?? '');
    $color          = sanitize($_POST['color'] ?? '');
    $design_pattern = sanitize($_POST['design_pattern'] ?? '');
    $has_blouse     = isset($_POST['has_blouse']) ? 1 : 0;
    $cost_price     = (float)($_POST['cost_price'] ?? 0);
    $selling_price  = (float)($_POST['selling_price'] ?? 0);
    $wholesale_price = $_POST['wholesale_price'] !== '' ? (float)$_POST['wholesale_price'] : null;
    $rack_location  = sanitize($_POST['rack_location'] ?? '');
    $reorder_level  = (int)($_POST['reorder_level'] ?? 5);
    $is_active      = isset($_POST['is_active']) ? 1 : 0;

    // Fetch existing image path (to delete old one if replaced)
    $existingImgStmt = $pdo->prepare("SELECT image_path FROM sarees WHERE saree_id = :id");
    $existingImgStmt->execute(['id' => $saree_id]);
    $existingImagePath = $existingImgStmt->fetch()['image_path'] ?? null;
    $image_path = $existingImagePath; // default: keep existing

    // Handle optional new image upload
    if (isset($_FILES['saree_image']) && $_FILES['saree_image']['error'] === UPLOAD_ERR_OK) {
        $allowedExt = ['jpg', 'jpeg', 'png', 'webp'];
        $ext = strtolower(pathinfo($_FILES['saree_image']['name'], PATHINFO_EXTENSION));
        $fileSize = $_FILES['saree_image']['size'];

        if (!in_array($ext, $allowedExt, true)) {
            $error = 'Image sirf JPG, PNG ya WEBP format me honi chahiye.';
        } elseif ($fileSize > 2 * 1024 * 1024) {
            $error = 'Image ka size 2MB se zyada nahi hona chahiye.';
        } else {
            $ext = pathinfo($_FILES['saree_image']['name'], PATHINFO_EXTENSION);
            $newFileName = 'saree_' . time() . '_' . rand(1000, 9999) . '.' . $ext;
            $destination = UPLOAD_PATH . $newFileName;

            if (!is_dir(UPLOAD_PATH)) {
                mkdir(UPLOAD_PATH, 0755, true);
            }

            if (move_uploaded_file($_FILES['saree_image']['tmp_name'], $destination)) {
                // Delete old image file if it existed
                if ($existingImagePath && file_exists(__DIR__ . '/../../' . $existingImagePath)) {
                    @unlink(__DIR__ . '/../../' . $existingImagePath);
                }
                $image_path = 'assets/uploads/saree-images/' . $newFileName;
            } else {
                $error = 'Image upload fail ho gayi. Dobara try karo.';
            }
        }
    }

    // Handle "remove image" checkbox
    if (isset($_POST['remove_image']) && $_POST['remove_image'] === '1') {
        if ($existingImagePath && file_exists(__DIR__ . '/../../' . $existingImagePath)) {
            @unlink(__DIR__ . '/../../' . $existingImagePath);
        }
        $image_path = null;
    }

    if ($sku === '' || $name === '' || $category_id === 0) {
        $error = 'SKU, Name aur Category zaroori hain.';
    } else {
        // Check duplicate SKU (excluding this saree itself)
        $dup = $pdo->prepare("SELECT COUNT(*) AS cnt FROM sarees WHERE sku = :sku AND saree_id != :id");
        $dup->execute(['sku' => $sku, 'id' => $saree_id]);
        if ($dup->fetch()['cnt'] > 0) {
            $error = 'Ye SKU already kisi aur saree me use ho raha hai.';
        } else {
            $stmt = $pdo->prepare(
                "UPDATE sarees SET
                    sku = :sku,
                    design_code = :design_code,
                    name = :name,
                    category_id = :category_id,
                    fabric = :fabric,
                    color = :color,
                    design_pattern = :design_pattern,
                    has_blouse = :has_blouse,
                    cost_price = :cost_price,
                    selling_price = :selling_price,
                    wholesale_price = :wholesale_price,
                    rack_location = :rack_location,
                    reorder_level = :reorder_level,
                    is_active = :is_active,
                    image_path = :image_path
                 WHERE saree_id = :saree_id"
            );
            $stmt->execute([
                'sku' => $sku,
                'design_code' => $design_code ?: null,
                'name' => $name,
                'category_id' => $category_id,
                'fabric' => $fabric,
                'color' => $color,
                'design_pattern' => $design_pattern,
                'has_blouse' => $has_blouse,
                'cost_price' => $cost_price,
                'selling_price' => $selling_price,
                'wholesale_price' => $wholesale_price,
                'rack_location' => $rack_location,
                'reorder_level' => $reorder_level,
                'is_active' => $is_active,
                'image_path' => $image_path,
                'saree_id' => $saree_id,
            ]);

            // Audit log
            $log = $pdo->prepare(
                "INSERT INTO audit_log (staff_id, action, module_name, record_id, description, ip_address)
                 VALUES (:staff_id, 'update', 'inventory', :record_id, :desc, :ip)"
            );
            $log->execute([
                'staff_id' => $_SESSION['staff_id'],
                'record_id' => $saree_id,
                'desc' => "Updated saree: $name (SKU: $sku)",
                'ip' => $_SERVER['REMOTE_ADDR'] ?? null,
            ]);

            $success = 'Saree update ho gayi.';
        }
    }
}

// Fetch current saree data (after possible update, to reflect latest)
$stmt = $pdo->prepare(
    "SELECT s.*, COALESCE(st.quantity, 0) AS quantity
     FROM sarees s
     LEFT JOIN stock st ON s.saree_id = st.saree_id
     WHERE s.saree_id = :id"
);
$stmt->execute(['id' => $saree_id]);
$saree = $stmt->fetch();

if (!$saree) {
    redirect('admin/inventory/list.php');
}

// Other variants sharing the same design code (e.g. same design, different size)
$otherVariants = [];
if (!empty($saree['design_code'])) {
    $variantStmt = $pdo->prepare(
        "SELECT saree_id, sku, name, COALESCE(st.quantity, 0) AS quantity
         FROM sarees s
         LEFT JOIN stock st ON s.saree_id = st.saree_id
         WHERE s.design_code = :design_code AND s.saree_id != :self_id
         ORDER BY s.name ASC"
    );
    $variantStmt->execute(['design_code' => $saree['design_code'], 'self_id' => $saree_id]);
    $otherVariants = $variantStmt->fetchAll();
}

include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/sidebar.php';
?>
<main>
    <h1>Edit Saree</h1>

    <?php if ($error): ?>
        <div class="alert alert-error"><?php echo sanitize($error); ?></div>
    <?php endif; ?>
    <?php if ($success): ?>
        <div class="alert alert-success"><?php echo sanitize($success); ?></div>
    <?php endif; ?>

    <div class="toolbar">
        <a href="list.php">Back to Inventory List</a>
        <a href="stock-adjust.php?id=<?php echo (int)$saree['saree_id']; ?>">Adjust Stock (Current: <?php echo (int)$saree['quantity']; ?>)</a>
    </div>

    <div class="card">
        <form method="POST" action="" enctype="multipart/form-data" class="form-grid">
            <input type="hidden" name="saree_id" value="<?php echo (int)$saree['saree_id']; ?>">

            <div style="grid-column: span 2;">
                <label>Saree Image</label>
                <?php if (!empty($saree['image_path'])): ?>
                    <div style="margin-bottom: 10px;">
                        <img src="<?php echo BASE_URL . sanitize($saree['image_path']); ?>"
                             alt="Saree image" style="max-width: 150px; border-radius: 6px; display: block; margin-bottom: 6px;">
                        <label><input type="checkbox" name="remove_image" value="1"> Remove current image</label>
                    </div>
                <?php else: ?>
                    <p><small>Koi image abhi tak upload nahi hui.</small></p>
                <?php endif; ?>
                <input type="file" name="saree_image" accept="image/jpeg,image/png,image/webp">
                <small>Naya file upload karoge toh purani image replace ho jayegi. Max 2MB.</small>
            </div>

            <div>
                <label>SKU / Barcode *</label>
                <input type="text" name="sku" value="<?php echo sanitize($saree['sku']); ?>" required>
            </div>
            <div>
                <label>Design Code (optional)</label>
                <input type="text" name="design_code" value="<?php echo sanitize($saree['design_code'] ?? ''); ?>" placeholder="e.g. BAN-DESIGN-42">
                <small>Same design ke alag-alag sizes/variants ko isse group karo.</small>
            </div>
            <div>
                <label>Saree Name *</label>
                <input type="text" name="name" value="<?php echo sanitize($saree['name']); ?>" required>
            </div>
            <div>
                <label>Category *</label>
                <select name="category_id" required>
                    <option value="">-- Select Category --</option>
                    <?php foreach ($categories as $cat): ?>
                        <option value="<?php echo (int)$cat['category_id']; ?>"
                            <?php echo ((int)$cat['category_id'] === (int)$saree['category_id']) ? 'selected' : ''; ?>>
                            <?php echo sanitize($cat['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label>Fabric</label>
                <input type="text" name="fabric" value="<?php echo sanitize($saree['fabric'] ?? ''); ?>">
            </div>
            <div>
                <label>Color</label>
                <input type="text" name="color" value="<?php echo sanitize($saree['color'] ?? ''); ?>">
            </div>
            <div>
                <label>Design / Pattern</label>
                <input type="text" name="design_pattern" value="<?php echo sanitize($saree['design_pattern'] ?? ''); ?>">
            </div>
            <div>
                <label>
                    <input type="checkbox" name="has_blouse" value="1" <?php echo $saree['has_blouse'] ? 'checked' : ''; ?>>
                    Blouse Piece Included
                </label>
            </div>
            <div>
                <label>Cost Price (₹)</label>
                <input type="number" step="0.01" name="cost_price" value="<?php echo sanitize($saree['cost_price']); ?>" required>
            </div>
            <div>
                <label>Selling Price (₹) *</label>
                <input type="number" step="0.01" name="selling_price" value="<?php echo sanitize($saree['selling_price']); ?>" required>
            </div>
            <div>
                <label>Wholesale Price (₹)</label>
                <input type="number" step="0.01" name="wholesale_price" value="<?php echo sanitize($saree['wholesale_price'] ?? ''); ?>">
            </div>
            <div>
                <label>Rack Location</label>
                <input type="text" name="rack_location" value="<?php echo sanitize($saree['rack_location'] ?? ''); ?>">
            </div>
            <div>
                <label>Reorder Level</label>
                <input type="number" name="reorder_level" value="<?php echo sanitize($saree['reorder_level']); ?>">
            </div>
            <div>
                <label>
                    <input type="checkbox" name="is_active" value="1" <?php echo $saree['is_active'] ? 'checked' : ''; ?>>
                    Active (visible in POS/list)
                </label>
            </div>
            <div>
                <button type="submit">Update Saree</button>
            </div>
        </form>
    </div>

    <p><small>Stock quantity yahan se edit nahi hoti — <a href="stock-adjust.php?id=<?php echo (int)$saree['saree_id']; ?>">Adjust Stock page</a> use karo, taaki har change ka log (stock_movements) sahi se ban sake.</small></p>

    <?php if (!empty($saree['design_code'])): ?>
    <div class="card">
        <h2>Other Variants (Design Code: <?php echo sanitize($saree['design_code']); ?>)</h2>
        <table class="data-table">
            <thead>
                <tr><th>SKU</th><th>Name</th><th>Stock</th><th>Action</th></tr>
            </thead>
            <tbody>
                <?php if (empty($otherVariants)): ?>
                    <tr><td colspan="4">Is design code se koi aur variant nahi hai abhi tak.</td></tr>
                <?php else: ?>
                    <?php foreach ($otherVariants as $v): ?>
                    <tr>
                        <td><?php echo sanitize($v['sku']); ?></td>
                        <td><?php echo sanitize($v['name']); ?></td>
                        <td><?php echo (int)$v['quantity']; ?></td>
                        <td><a href="edit.php?id=<?php echo (int)$v['saree_id']; ?>">Edit</a></td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</main>
<?php include __DIR__ . '/../../includes/footer.php'; ?>