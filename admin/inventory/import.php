<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/functions.php';
requirePermission($pdo, 'inventory', 'view');

$error = '';
$results = null; // will hold summary after processing

// Expected column order in the CSV (row 1 must be this header, or any header - we just use position)
// SKU, Name, Category, Fabric, Color, DesignPattern, HasBlouse(Y/N), CostPrice, SellingPrice, WholesalePrice, RackLocation, ReorderLevel, InitialStock

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['csv_file'])) {
    requirePermission($pdo, 'inventory', 'edit');

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
            $categoryCache = []; // name (lowercase) => category_id

            // Preload existing categories into cache
            $existingCats = $pdo->query("SELECT category_id, name FROM categories")->fetchAll();
            foreach ($existingCats as $c) {
                $categoryCache[strtolower($c['name'])] = $c['category_id'];
            }

            while (($row = fgetcsv($handle)) !== false) {
                $rowNum++;

                // Skip header row (first row) if it looks like a header
                if ($rowNum === 1 && strtolower(trim($row[0])) === 'sku') {
                    continue;
                }

                // Expect at least 9 columns (SKU..SellingPrice mandatory, rest optional)
                if (count($row) < 9) {
                    $skipped[] = "Row $rowNum: kam columns hain, skip kiya.";
                    continue;
                }

                [$sku, $name, $categoryName, $fabric, $color, $designPattern,
                 $hasBlouseRaw, $costPrice, $sellingPrice, $wholesalePrice,
                 $rackLocation, $reorderLevel, $initialStock] = array_pad($row, 13, '');

                $sku = trim($sku);
                $name = trim($name);
                $categoryName = trim($categoryName);

                if ($sku === '' || $name === '' || $categoryName === '') {
                    $skipped[] = "Row $rowNum: SKU/Name/Category khaali hai, skip kiya.";
                    continue;
                }

                // Check duplicate SKU
                $dup = $pdo->prepare("SELECT COUNT(*) AS cnt FROM sarees WHERE sku = :sku");
                $dup->execute(['sku' => $sku]);
                if ($dup->fetch()['cnt'] > 0) {
                    $skipped[] = "Row $rowNum: SKU '$sku' already exist karta hai, skip kiya.";
                    continue;
                }

                // Get or create category
                $catKey = strtolower($categoryName);
                if (isset($categoryCache[$catKey])) {
                    $categoryId = $categoryCache[$catKey];
                } else {
                    $insCat = $pdo->prepare("INSERT INTO categories (name) VALUES (:name)");
                    $insCat->execute(['name' => $categoryName]);
                    $categoryId = $pdo->lastInsertId();
                    $categoryCache[$catKey] = $categoryId;
                }

                $hasBlouse = (strtoupper(trim($hasBlouseRaw)) === 'Y') ? 1 : 0;
                $costPrice = is_numeric($costPrice) ? (float)$costPrice : 0;
                $sellingPrice = is_numeric($sellingPrice) ? (float)$sellingPrice : 0;
                $wholesalePrice = is_numeric($wholesalePrice) ? (float)$wholesalePrice : null;
                $reorderLevel = is_numeric($reorderLevel) ? (int)$reorderLevel : 5;
                $initialStock = is_numeric($initialStock) ? (int)$initialStock : 0;

                try {
                    $pdo->beginTransaction();

                    $insSaree = $pdo->prepare(
                        "INSERT INTO sarees
                            (sku, name, category_id, fabric, color, design_pattern, has_blouse,
                             cost_price, selling_price, wholesale_price, rack_location, reorder_level)
                         VALUES
                            (:sku, :name, :category_id, :fabric, :color, :design_pattern, :has_blouse,
                             :cost_price, :selling_price, :wholesale_price, :rack_location, :reorder_level)"
                    );
                    $insSaree->execute([
                        'sku' => $sku,
                        'name' => $name,
                        'category_id' => $categoryId,
                        'fabric' => $fabric ?: null,
                        'color' => $color ?: null,
                        'design_pattern' => $designPattern ?: null,
                        'has_blouse' => $hasBlouse,
                        'cost_price' => $costPrice,
                        'selling_price' => $sellingPrice,
                        'wholesale_price' => $wholesalePrice,
                        'rack_location' => $rackLocation ?: null,
                        'reorder_level' => $reorderLevel,
                    ]);

                    $sareeId = $pdo->lastInsertId();

                    $insStock = $pdo->prepare("INSERT INTO stock (saree_id, quantity) VALUES (:id, :qty)");
                    $insStock->execute(['id' => $sareeId, 'qty' => $initialStock]);

                    if ($initialStock > 0) {
                        $insMove = $pdo->prepare(
                            "INSERT INTO stock_movements (saree_id, change_type, quantity_change, notes, created_by)
                             VALUES (:id, 'purchase', :qty, 'Bulk CSV import', :staff_id)"
                        );
                        $insMove->execute([
                            'id' => $sareeId,
                            'qty' => $initialStock,
                            'staff_id' => $_SESSION['staff_id'],
                        ]);
                    }

                    $pdo->commit();
                    $added++;
                } catch (Exception $e) {
                    $pdo->rollBack();
                    $skipped[] = "Row $rowNum: DB error - " . $e->getMessage();
                }
            }

            fclose($handle);

            $results = [
                'added' => $added,
                'skipped' => $skipped,
            ];
        }
    }
}

include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/sidebar.php';
?>
<main>
    <h1>Bulk Import Sarees (CSV)</h1>

    <?php if ($error): ?>
        <div class="alert alert-error"><?php echo sanitize($error); ?></div>
    <?php endif; ?>

    <?php if ($results !== null): ?>
        <div class="alert alert-success">
            <?php echo (int)$results['added']; ?> saree(s) successfully add ho gayi.
        </div>
        <?php if (!empty($results['skipped'])): ?>
            <div class="alert alert-error">
                <strong><?php echo count($results['skipped']); ?> row(s) skip hui:</strong>
                <ul>
                    <?php foreach ($results['skipped'] as $msg): ?>
                        <li><?php echo sanitize($msg); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>
        <p><a href="list.php">View Inventory List</a></p>
    <?php endif; ?>

    <div class="card">
        <h2>Step 1: Template Download Karo</h2>
        <p>Neeche diye gaye format me apna data bharo. Category naam agar system me nahi hai, toh wo automatically create ho jayegi.</p>
        <p><a href="<?php echo BASE_URL; ?>assets/sample_import_template.csv" download>Download Sample Template (CSV)</a></p>
        <p><small>Columns: SKU, Name, Category, Fabric, Color, DesignPattern, HasBlouse(Y/N), CostPrice, SellingPrice, WholesalePrice, RackLocation, ReorderLevel, InitialStock</small></p>
    </div>

    <div class="card">
        <h2>Step 2: Bhari hui CSV Upload Karo</h2>
        <form method="POST" action="" enctype="multipart/form-data" class="inline-form">
            <input type="file" name="csv_file" accept=".csv" required>
            <button type="submit">Import Sarees</button>
        </form>
    </div>
</main>
<?php include __DIR__ . '/../../includes/footer.php'; ?>