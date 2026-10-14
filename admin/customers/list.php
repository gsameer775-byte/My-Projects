<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/functions.php';
requirePermission($pdo, 'customers', 'view');

$search = sanitize($_GET['search'] ?? '');

$perPage = 20;
$page = max(1, (int)($_GET['page'] ?? 1));
$offset = ($page - 1) * $perPage;

$whereSql = '';
$whereParams = [];
if ($search !== '') {
    $whereSql = " WHERE name LIKE :search1 OR phone LIKE :search2 OR customer_id = :search3";
    $whereParams['search1'] = "%$search%";
    $whereParams['search2'] = "%$search%";
    $whereParams['search3'] = is_numeric($search) ? (int)$search : 0;
}

$countStmt = $pdo->prepare("SELECT COUNT(*) AS total FROM customers" . $whereSql);
$countStmt->execute($whereParams);
$totalRows = (int)$countStmt->fetch()['total'];
$totalPages = max(1, (int)ceil($totalRows / $perPage));

$sql = "SELECT customer_id, name, phone, city, loyalty_points, is_active,
               (SELECT COALESCE(balance_after, 0) FROM credit_ledger
                WHERE customer_id = c.customer_id ORDER BY ledger_id DESC LIMIT 1) AS credit_due
        FROM customers c" . $whereSql .
        " ORDER BY created_at DESC LIMIT :limit OFFSET :offset";

$stmt = $pdo->prepare($sql);
foreach ($whereParams as $key => $val) {
    $stmt->bindValue(':' . $key, $val);
}
$stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$customers = $stmt->fetchAll();

function buildQuery($extra = []) {
    $params = array_merge($_GET, $extra);
    return '?' . http_build_query($params);
}

include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/sidebar.php';
?>
<main>
    <h1>Customers</h1>

    <div class="card">
        <div class="toolbar">
            <form method="GET" action="">
                <input type="text" name="search" placeholder="Naam, phone, ya Customer ID se search karo"
                       value="<?php echo sanitize($search); ?>">
                <button type="submit">Search</button>
            </form>
            <a href="add.php" class="btn-primary">+ Add Customer</a>
        </div>

        <p><small><?php echo $totalRows; ?> customer(s) total<?php echo $search !== '' ? ' (filtered)' : ''; ?></small></p>

        <table class="data-table">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Name</th>
                    <th>Phone</th>
                    <th>City</th>
                    <th>Loyalty Points</th>
                    <th>Credit Due (₹)</th>
                    <th>Status</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($customers)): ?>
                    <tr><td colspan="8">Koi customer nahi mila. <a href="add.php">Pehla customer add karo</a>.</td></tr>
                <?php else: ?>
                    <?php foreach ($customers as $c): ?>
                    <tr>
                        <td>
                            <code class="id-chip" onclick="navigator.clipboard.writeText('<?php echo (int)$c['customer_id']; ?>'); this.title='Copied!';" title="Click to copy">#<?php echo (int)$c['customer_id']; ?></code>
                        </td>
                        <td><a href="profile.php?id=<?php echo (int)$c['customer_id']; ?>"><?php echo sanitize($c['name']); ?></a></td>
                        <td><?php echo sanitize($c['phone']); ?></td>
                        <td><?php echo sanitize($c['city'] ?? '-'); ?></td>
                        <td><?php echo (int)$c['loyalty_points']; ?></td>
                        <td>
                            <?php if ($c['credit_due'] > 0): ?>
                                <span class="badge-warning">₹<?php echo number_format($c['credit_due'], 2); ?></span>
                            <?php else: ?>
                                -
                            <?php endif; ?>
                        </td>
                        <td><?php echo $c['is_active'] ? 'Active' : 'Inactive'; ?></td>
                        <td>
                            <a href="profile.php?id=<?php echo (int)$c['customer_id']; ?>">View</a> |
                            <a href="add.php?id=<?php echo (int)$c['customer_id']; ?>">Edit</a>
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