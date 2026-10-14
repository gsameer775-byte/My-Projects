<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/functions.php';
requirePermission($pdo, 'inventory', 'view');

$error = '';
$success = '';

$categories = $pdo->query("SELECT category_id, name FROM categories ORDER BY name ASC")->fetchAll();

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
    $initial_stock  = (int)($_POST['initial_stock'] ?? 0);

    // Handle optional image upload (extension-based check - doesn't need the fileinfo extension enabled)
    $image_path = null;
    if (isset($_FILES['saree_image']) && $_FILES['saree_image']['error'] === UPLOAD_ERR_OK) {
        $allowedExt = ['jpg', 'jpeg', 'png', 'webp'];
        $maxSize = 2 * 1024 * 1024; // 2MB
        $origName = $_FILES['saree_image']['name'];
        $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
        $size = $_FILES['saree_image']['size'];

        if (!in_array($ext, $allowedExt, true)) {
            $error = 'Image sirf JPG, PNG ya WEBP format me honi chahiye.';
        } elseif ($size > $maxSize) {
            $error = 'Image ka size 2MB se zyada nahi hona chahiye.';
        } else {
            $newFileName = 'saree_' . time() . '_' . rand(1000, 9999) . '.' . $ext;

            if (!is_dir(UPLOAD_PATH)) {
                mkdir(UPLOAD_PATH, 0755, true);
            }

            if (move_uploaded_file($_FILES['saree_image']['tmp_name'], UPLOAD_PATH . $newFileName)) {
                $image_path = 'assets/uploads/saree-images/' . $newFileName;
            } else {
                $error = 'Image upload fail ho gayi. Dobara try karo.';
            }
        }
    }

    if ($error === '' && ($sku === '' || $name === '' || $category_id === 0)) {
        $error = 'SKU, Name aur Category zaroori hain.';
    } elseif ($error === '') {
        // Check duplicate SKU
        $dup = $pdo->prepare("SELECT COUNT(*) AS cnt FROM sarees WHERE sku = :sku");
        $dup->execute(['sku' => $sku]);
        if ($dup->fetch()['cnt'] > 0) {
            $error = 'Ye SKU already exist karta hai. Alag SKU use karo.';
        } else {
            try {
                $pdo->beginTransaction();

                $stmt = $pdo->prepare(
                    "INSERT INTO sarees
                        (sku, design_code, name, category_id, fabric, color, design_pattern, has_blouse,
                         cost_price, selling_price, wholesale_price, rack_location, reorder_level, image_path)
                     VALUES
                        (:sku, :design_code, :name, :category_id, :fabric, :color, :design_pattern, :has_blouse,
                         :cost_price, :selling_price, :wholesale_price, :rack_location, :reorder_level, :image_path)"
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
                    'image_path' => $image_path,
                ]);

                $saree_id = $pdo->lastInsertId();

                // Create stock row
                $stockStmt = $pdo->prepare(
                    "INSERT INTO stock (saree_id, quantity) VALUES (:saree_id, :qty)"
                );
                $stockStmt->execute(['saree_id' => $saree_id, 'qty' => $initial_stock]);

                // Log stock movement
                if ($initial_stock > 0) {
                    $moveStmt = $pdo->prepare(
                        "INSERT INTO stock_movements (saree_id, change_type, quantity_change, notes, created_by)
                         VALUES (:saree_id, 'adjustment', :qty, 'Initial stock on saree creation', :staff_id)"
                    );
                    $moveStmt->execute([
                        'saree_id' => $saree_id,
                        'qty' => $initial_stock,
                        'staff_id' => $_SESSION['staff_id'],
                    ]);
                }

                $pdo->commit();
                $success = "Saree '$name' add ho gayi (SKU: $sku).";
            } catch (Exception $e) {
                $pdo->rollBack();
                $error = 'Kuch galat ho gaya: ' . $e->getMessage();
            }
        }
    }
}

include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/sidebar.php';
?>
<main>
    <h1>Add New Saree</h1>

    <?php if ($error): ?>
        <div class="alert alert-error"><?php echo sanitize($error); ?></div>
    <?php endif; ?>
    <?php if ($success): ?>
        <div class="alert alert-success"><?php echo sanitize($success); ?></div>
        <p><a href="list.php">View Inventory List</a></p>
    <?php endif; ?>

    <div class="toolbar">
        <a href="list.php">View Inventory List</a>
        <a href="import.php">Bulk Import (CSV)</a>
        <a href="categories.php">Manage Categories</a>
    </div>

    <div class="card">
        <form method="POST" action="" enctype="multipart/form-data" class="form-grid">
            <div style="grid-column: span 2;">
                <label>Saree Image (optional)</label>
                <input type="file" name="saree_image" accept="image/jpeg,image/png,image/webp">
                <small>Max 2MB. JPG, PNG ya WEBP. Baad me bhi Edit page se add/replace kar sakte ho.</small>
            </div>
            <div>
                <label>SKU / Barcode *</label>
                <input type="text" name="sku" required>
            </div>
            <div>
                <label>Design Code (optional)</label>
                <input type="text" name="design_code" placeholder="e.g. BAN-DESIGN-42">
                <small>Same design ke alag-alag sizes/variants ko isse group karo - har ek ka SKU alag rahega.</small>
            </div>
            <div>
                <label>Saree Name *</label>
                <input type="text" name="name" required>
            </div>
            <div>
                <label>Category *</label>
                <select name="category_id" required>
                    <option value="">-- Select Category --</option>
                    <?php foreach ($categories as $cat): ?>
                        <option value="<?php echo (int)$cat['category_id']; ?>">
                            <?php echo sanitize($cat['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <?php if (empty($categories)): ?>
                    <small><a href="categories.php">Pehle ek category add karo</a></small>
                <?php endif; ?>
            </div>
            <div>
                <label>Fabric</label>
                <input type="text" name="fabric" placeholder="Silk, Cotton, Georgette...">
            </div>
            <div>
                <label>Color</label>
                <input type="text" name="color">
            </div>
            <div>
                <label>Design / Pattern</label>
                <input type="text" name="design_pattern">
            </div>
            <div>
                <label><input type="checkbox" name="has_blouse" value="1"> Blouse Piece Included</label>
            </div>
            <div>
                <label>Cost Price (₹)</label>
                <input type="number" step="0.01" name="cost_price" required>
            </div>
            <div>
                <label>Selling Price (₹) *</label>
                <input type="number" step="0.01" name="selling_price" required>
            </div>
            <div>
                <label>Wholesale Price (₹)</label>
                <input type="number" step="0.01" name="wholesale_price">
            </div>
            <div>
                <label>Rack Location</label>
                <input type="text" name="rack_location" placeholder="e.g. Rack-3, Shelf-2">
            </div>
            <div>
                <label>Reorder Level</label>
                <input type="number" name="reorder_level" value="5">
            </div>
            <div>
                <label>Initial Stock Quantity</label>
                <input type="number" name="initial_stock" value="0">
            </div>
            <div>
                <button type="submit">Save Saree</button>
            </div>
        </form>
    </div>
</main>
<?php include __DIR__ . '/../../includes/footer.php'; ?>