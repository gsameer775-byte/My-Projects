<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/functions.php';
requirePermission($pdo, 'settings', 'view');

// ============================================
// Settings - Audit Log Viewer
// - Read-only view into the audit_log table populated by every module
// - Filterable by module + staff, paginated (log tables grow fast)
// ============================================

$moduleFilter = sanitize($_GET['module'] ?? '');
$staffFilter = (int)($_GET['staff_id'] ?? 0);

$perPage = 30;
$page = max(1, (int)($_GET['page'] ?? 1));
$offset = ($page - 1) * $perPage;

$whereSql = '';
$whereParams = [];
$conditions = [];

if ($moduleFilter !== '') {
    $conditions[] = "al.module_name = :module";
    $whereParams['module'] = $moduleFilter;
}
if ($staffFilter > 0) {
    $conditions[] = "al.staff_id = :staff_id";
    $whereParams['staff_id'] = $staffFilter;
}
if (!empty($conditions)) {
    $whereSql = " WHERE " . implode(' AND ', $conditions);
}

$countStmt = $pdo->prepare("SELECT COUNT(*) AS total FROM audit_log al" . $whereSql);
$countStmt->execute($whereParams);
$totalRows = (int)$countStmt->fetch()['total'];
$totalPages = max(1, (int)ceil($totalRows / $perPage));

$sql = "SELECT al.*, st.name AS staff_name
        FROM audit_log al
        LEFT JOIN staff st ON al.staff_id = st.staff_id"
        . $whereSql .
        " ORDER BY al.created_at DESC LIMIT :limit OFFSET :offset";

$stmt = $pdo->prepare($sql);
foreach ($whereParams as $key => $val) {
    $stmt->bindValue(':' . $key, $val);
}
$stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$logs = $stmt->fetchAll();

$modules = $pdo->query("SELECT DISTINCT module_name FROM audit_log ORDER BY module_name ASC")->fetchAll(PDO::FETCH_COLUMN);
$staffList = $pdo->query("SELECT staff_id, name FROM staff ORDER BY name ASC")->fetchAll();

function buildQuery($extra = []) {
    $params = array_merge($_GET, $extra);
    return '?' . http_build_query($params);
}

include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/sidebar.php';
?>
<main>
    <h1>Audit Log</h1>

    <div class="toolbar">
        <a href="general.php">General</a>
        <a href="users.php">Users</a>
        <a href="audit-log.php"><strong>Audit Log</strong></a>
        <a href="backups.php">Backups</a>
        <a href="sessions.php">Sessions</a>
        <a href="bank-reconciliation.php">Bank Reconciliation</a>
    </div>

    <div class="card">
        <form method="GET" action="" class="inline-form">
            <label>Module:</label>
            <select name="module">
                <option value="">All Modules</option>
                <?php foreach ($modules as $m): ?>
                    <option value="<?php echo sanitize($m); ?>" <?php echo $moduleFilter === $m ? 'selected' : ''; ?>>
                        <?php echo sanitize(ucfirst($m)); ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <label>Staff:</label>
            <select name="staff_id">
                <option value="0">All Staff</option>
                <?php foreach ($staffList as $s): ?>
                    <option value="<?php echo (int)$s['staff_id']; ?>" <?php echo $staffFilter === (int)$s['staff_id'] ? 'selected' : ''; ?>>
                        <?php echo sanitize($s['name']); ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <button type="submit">Filter</button>
        </form>

        <p style="margin-top:12px;"><small><?php echo $totalRows; ?> log entries total</small></p>

        <table class="data-table">
            <thead>
                <tr><th>Date/Time</th><th>Staff</th><th>Action</th><th>Module</th><th>Description</th><th>IP</th></tr>
            </thead>
            <tbody>
                <?php if (empty($logs)): ?>
                    <tr><td colspan="6">Koi log entry nahi mili.</td></tr>
                <?php else: ?>
                    <?php foreach ($logs as $log): ?>
                    <tr>
                        <td><?php echo sanitize(date('d M Y, h:i A', strtotime($log['created_at']))); ?></td>
                        <td><?php echo sanitize($log['staff_name'] ?? 'System'); ?></td>
                        <td><?php echo sanitize(ucfirst($log['action'])); ?></td>
                        <td><?php echo sanitize(ucfirst($log['module_name'])); ?></td>
                        <td><?php echo sanitize($log['description'] ?? '-'); ?></td>
                        <td><small><?php echo sanitize($log['ip_address'] ?? '-'); ?></small></td>
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