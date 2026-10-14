<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/functions.php';
requirePermission($pdo, 'staff', 'view');

$search = sanitize($_GET['search'] ?? '');

$perPage = 20;
$page = max(1, (int)($_GET['page'] ?? 1));
$offset = ($page - 1) * $perPage;

$whereSql = '';
$whereParams = [];
if ($search !== '') {
    $whereSql = " WHERE s.name LIKE :search1 OR s.phone LIKE :search2 OR s.username LIKE :search3";
    $whereParams['search1'] = "%$search%";
    $whereParams['search2'] = "%$search%";
    $whereParams['search3'] = "%$search%";
}

$countStmt = $pdo->prepare("SELECT COUNT(*) AS total FROM staff s" . $whereSql);
$countStmt->execute($whereParams);
$totalRows = (int)$countStmt->fetch()['total'];
$totalPages = max(1, (int)ceil($totalRows / $perPage));

$sql = "SELECT s.staff_id, s.name, s.phone, s.username, s.monthly_salary, s.commission_rate,
               s.joining_date, s.is_active, r.role_name
        FROM staff s
        LEFT JOIN roles r ON r.role_id = s.role_id" . $whereSql .
        " ORDER BY s.created_at DESC LIMIT :limit OFFSET :offset";

$stmt = $pdo->prepare($sql);
foreach ($whereParams as $key => $val) {
    $stmt->bindValue(':' . $key, $val);
}
$stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$staffList = $stmt->fetchAll();

function buildQuery($extra = []) {
    $params = array_merge($_GET, $extra);
    return '?' . http_build_query($params);
}

include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/sidebar.php';
?>
<main>
    <h1>Staff</h1>

    <div class="card">
        <div class="toolbar">
            <form method="GET" action="">
                <input type="text" name="search" placeholder="Naam, phone ya username se search karo"
                       value="<?php echo sanitize($search); ?>">
                <button type="submit">Search</button>
            </form>
            <a href="add.php" class="btn-primary">+ Add Staff</a>
            <a href="roles.php">Roles &amp; Permissions</a>
            <a href="attendance.php">Attendance</a>
            <a href="salary-commission.php">Salary / Commission</a>
        </div>

        <p><small><?php echo $totalRows; ?> staff member(s) total<?php echo $search !== '' ? ' (filtered)' : ''; ?></small></p>

        <table class="data-table">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Phone</th>
                    <th>Username</th>
                    <th>Role</th>
                    <th>Monthly Salary</th>
                    <th>Commission %</th>
                    <th>Joined</th>
                    <th>Status</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($staffList)): ?>
                    <tr><td colspan="9">Koi staff member nahi mila. <a href="add.php">Pehla staff add karo</a>.</td></tr>
                <?php else: ?>
                    <?php foreach ($staffList as $s): ?>
                    <tr>
                        <td><?php echo sanitize($s['name']); ?></td>
                        <td><?php echo sanitize($s['phone']); ?></td>
                        <td><?php echo sanitize($s['username']); ?></td>
                        <td><?php echo sanitize($s['role_name'] ?? '-'); ?></td>
                        <td><?php echo $s['monthly_salary'] !== null ? '₹' . number_format($s['monthly_salary'], 2) : '-'; ?></td>
                        <td><?php echo $s['commission_rate'] !== null ? number_format($s['commission_rate'], 2) . '%' : '-'; ?></td>
                        <td><?php echo $s['joining_date'] ? sanitize($s['joining_date']) : '-'; ?></td>
                        <td>
                            <?php if ($s['is_active']): ?>
                                Active
                            <?php else: ?>
                                <span class="badge-warning">Inactive</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <a href="add.php?id=<?php echo (int)$s['staff_id']; ?>">Edit</a> |
                            <a href="attendance.php?staff_id=<?php echo (int)$s['staff_id']; ?>">Attendance</a> |
                            <a href="salary-commission.php?staff_id=<?php echo (int)$s['staff_id']; ?>">Salary</a>
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