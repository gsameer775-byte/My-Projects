<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/functions.php';
requirePermission($pdo, 'inventory', 'view');

$error = '';
$success = '';

// ---- Handle Delete ----
if (isset($_GET['delete'])) {
    requirePermission($pdo, 'inventory', 'delete');
    $delId = (int)$_GET['delete'];

    // Check if this saree is referenced anywhere (sales, purchase orders, stock movements)
    $refChecks = [
        'sale_items' => "SELECT COUNT(*) AS cnt FROM sale_items WHERE saree_id = :id",
        'purchase_order_items' => "SELECT COUNT(*) AS cnt FROM purchase_order_items WHERE saree_id = :id",
        'stock_movements' => "SELECT COUNT(*) AS cnt FROM stock_movements WHERE saree_id = :id",
    ];
    $isReferenced = false;
    foreach ($refChecks as $table => $q) {
        $chk = $pdo->prepare($q);
        $chk->execute(['id' => $delId]);
        if ($chk->fetch()['cnt'] > 0) {
            $isReferenced = true;
            break;
        }
    }

    if ($isReferenced) {
        // Soft delete instead - mark inactive so history/reports stay intact
        $pdo->prepare("UPDATE sarees SET is_active = 0 WHERE saree_id = :id")->execute(['id' => $delId]);
        $success = 'Ye saree ka sale/purchase/stock history hai, isliye poora delete nahi hua - ise Inactive kar diya gaya hai (list me nahi dikhegi POS me, par records surakshit hain).';
    } else {
        // Safe to hard delete - remove image file, stock row, then saree
        $imgStmt = $pdo->prepare("SELECT image_path FROM sarees WHERE saree_id = :id");
        $imgStmt->execute(['id' => $delId]);
        $imgRow = $imgStmt->fetch();
        if ($imgRow && !empty($imgRow['image_path']) && file_exists(__DIR__ . '/../../' . $imgRow['image_path'])) {
            @unlink(__DIR__ . '/../../' . $imgRow['image_path']);
        }
        $pdo->prepare("DELETE FROM stock WHERE saree_id = :id")->execute(['id' => $delId]);
        $pdo->prepare("DELETE FROM sarees WHERE saree_id = :id")->execute(['id' => $delId]);
        $success = 'Saree poori tarah delete ho gayi.';
    }
}

// ---- Search ----
$search = sanitize($_GET['search'] ?? '');

// ---- Pagination setup ----
$perPage = 20;
$page = max(1, (int)($_GET['page'] ?? 1));
$offset = ($page - 1) * $perPage;

// Build WHERE clause once, reuse for count + data query
$whereSql = '';
$whereParams = [];
if ($search !== '') {
    $whereSql = " WHERE s.name LIKE :search1 OR s.sku LIKE :search2 OR c.name LIKE :search3 OR s.rack_location LIKE :search4 OR s.design_code LIKE :search5";
    $whereParams['search1'] = "%$search%";
    $whereParams['search2'] = "%$search%";
    $whereParams['search3'] = "%$search%";
    $whereParams['search4'] = "%$search%";
    $whereParams['search5'] = "%$search%";
}

// Total count for pagination
$countSql = "SELECT COUNT(*) AS total
             FROM sarees s
             LEFT JOIN categories c ON s.category_id = c.category_id" . $whereSql;
$countStmt = $pdo->prepare($countSql);
$countStmt->execute($whereParams);
$totalRows = (int)$countStmt->fetch()['total'];
$totalPages = max(1, (int)ceil($totalRows / $perPage));

// Data query with LIMIT/OFFSET
$sql = "SELECT s.saree_id, s.sku, s.name, s.color, s.fabric, s.selling_price, s.rack_location,
               s.reorder_level, s.is_active, s.image_path, c.name AS category_name,
               COALESCE(st.quantity, 0) AS quantity
        FROM sarees s
        LEFT JOIN categories c ON s.category_id = c.category_id
        LEFT JOIN stock st ON s.saree_id = st.saree_id"
        . $whereSql .
        " ORDER BY s.created_at DESC
          LIMIT :limit OFFSET :offset";

$stmt = $pdo->prepare($sql);
foreach ($whereParams as $key => $val) {
    $stmt->bindValue(':' . $key, $val);
}
$stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$sarees = $stmt->fetchAll();

// Helper to build query string for pagination/export links (preserves search)
function buildQuery($extra = []) {
    $params = array_merge($_GET, $extra);
    return '?' . http_build_query($params);
}

include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/sidebar.php';
?>
<main>
    <h1>Inventory - Saree List</h1>

    <?php if ($success): ?>
        <div class="alert alert-success"><?php echo sanitize($success); ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="alert alert-error"><?php echo sanitize($error); ?></div>
    <?php endif; ?>

    <div class="card">
        <div class="toolbar">
            <form method="GET" action="">
                <input type="text" name="search" placeholder="Search by name, SKU, category"
                       value="<?php echo sanitize($search); ?>">
                <button type="submit">Search</button>
            </form>
            <a href="add.php" class="btn-primary">+ Add New Saree</a>
            <a href="import.php">Bulk Import (CSV)</a>
            <a href="categories.php">Manage Categories</a>
            <a href="print-barcodes.php">Print Barcodes</a>
            <a href="export.php<?php echo $search !== '' ? '?search=' . urlencode($search) : ''; ?>">Export CSV</a>
        </div>

        <p><small><?php echo $totalRows; ?> saree(s) total<?php echo $search !== '' ? ' (filtered)' : ''; ?> — Page <?php echo $page; ?> of <?php echo $totalPages; ?></small></p>

        <table class="data-table">
            <thead>
                <tr>
                    <th>Image</th>
                    <th>SKU</th>
                    <th>Name</th>
                    <th>Category</th>
                    <th>Fabric</th>
                    <th>Color</th>
                    <th>Location</th>
                    <th>Price (₹)</th>
                    <th>Stock</th>
                    <th>Status</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($sarees)): ?>
                    <tr><td colspan="11">Koi saree nahi mili. <a href="add.php">Pehli saree add karo</a>.</td></tr>
                <?php else: ?>
                    <?php foreach ($sarees as $s): ?>
                    <tr>
                        <td>
                            <?php if (!empty($s['image_path'])): ?>
                                <img src="<?php echo BASE_URL . sanitize($s['image_path']); ?>" alt="" style="width:40px;height:50px;object-fit:cover;border-radius:4px;">
                            <?php else: ?>
                                <span style="color:#999;font-size:12px;">No image</span>
                            <?php endif; ?>
                        </td>
                        <td><?php echo sanitize($s['sku']); ?></td>
                        <td><?php echo sanitize($s['name']); ?></td>
                        <td><?php echo sanitize($s['category_name'] ?? '-'); ?></td>
                        <td><?php echo sanitize($s['fabric'] ?? '-'); ?></td>
                        <td><?php echo sanitize($s['color'] ?? '-'); ?></td>
                        <td><?php echo sanitize($s['rack_location'] ?? '-'); ?></td>
                        <td><?php echo number_format($s['selling_price'], 2); ?></td>
                        <td>
                            <?php echo (int)$s['quantity']; ?>
                            <?php if ($s['quantity'] <= $s['reorder_level']): ?>
                                <span class="badge-warning">Low</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php echo $s['is_active'] ? 'Active' : 'Inactive'; ?>
                        </td>
                        <td>
                            <a href="edit.php?id=<?php echo (int)$s['saree_id']; ?>">Edit</a> |
                            <a href="stock-adjust.php?id=<?php echo (int)$s['saree_id']; ?>">Adjust Stock</a> |
                            <a href="?delete=<?php echo (int)$s['saree_id']; ?>"
                               onclick="return confirm('Ye saree delete karni hai? Agar iski sale/purchase history hai toh ye sirf Inactive ho jayegi, warna poori tarah delete ho jayegi.');">Delete</a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>

        <?php if ($totalPages > 1): ?>
        <div class="pagination">
            <?php if ($page > 1): ?>
                <a href="<?php echo buildQuery(['page' => $page - 1]); ?>">&laquo; Previous</a>
            <?php endif; ?>

            <?php for ($p = 1; $p <= $totalPages; $p++): ?>
                <?php if ($p === $page): ?>
                    <strong><?php echo $p; ?></strong>
                <?php else: ?>
                    <a href="<?php echo buildQuery(['page' => $p]); ?>"><?php echo $p; ?></a>
                <?php endif; ?>
            <?php endfor; ?>

            <?php if ($page < $totalPages): ?>
                <a href="<?php echo buildQuery(['page' => $page + 1]); ?>">Next &raquo;</a>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>
</main>
<?php include __DIR__ . '/../../includes/footer.php'; ?>