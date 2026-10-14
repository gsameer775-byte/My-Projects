<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/functions.php';
requirePermission($pdo, 'expenses', 'view');

// ============================================
// Expenses Module
// - Manage expense categories (Rent, Electricity, Maintenance, etc.)
// - Log expense entries against a category
// - Filter by month + category, with a running total
// ============================================

$error = '';
$success = '';

// ---- Handle Add Category ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_category') {
    requirePermission($pdo, 'expenses', 'edit');
    $catName = sanitize($_POST['category_name'] ?? '');
    if ($catName === '') {
        $error = 'Category name zaroori hai.';
    } else {
        $dup = $pdo->prepare("SELECT COUNT(*) AS cnt FROM expense_categories WHERE name = :name");
        $dup->execute(['name' => $catName]);
        if ($dup->fetch()['cnt'] > 0) {
            $error = 'Ye category already exist karti hai.';
        } else {
            $pdo->prepare("INSERT INTO expense_categories (name) VALUES (:name)")->execute(['name' => $catName]);
            $success = 'Category add ho gayi.';
        }
    }
}

// ---- Handle Add Expense ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_expense') {
    requirePermission($pdo, 'expenses', 'edit');
    $categoryId  = (int)($_POST['expense_category_id'] ?? 0);
    $amount      = (float)($_POST['amount'] ?? 0);
    $expenseDate = $_POST['expense_date'] ?? date('Y-m-d');
    $paymentMode = $_POST['payment_mode'] ?? 'cash';
    $notes       = sanitize($_POST['notes'] ?? '');

    $allowedModes = ['cash', 'upi', 'bank_transfer', 'cheque'];

    if ($categoryId <= 0) {
        $error = 'Category select karo.';
    } elseif ($amount <= 0) {
        $error = 'Amount 0 se zyada hona chahiye.';
    } elseif (!in_array($paymentMode, $allowedModes, true)) {
        $error = 'Valid payment mode select karo.';
    } else {
        $pdo->prepare(
            "INSERT INTO expenses (expense_category_id, amount, expense_date, payment_mode, notes, created_by)
             VALUES (:cat_id, :amount, :date, :mode, :notes, :staff_id)"
        )->execute([
            'cat_id' => $categoryId,
            'amount' => $amount,
            'date' => $expenseDate,
            'mode' => $paymentMode,
            'notes' => $notes ?: null,
            'staff_id' => $_SESSION['staff_id'],
        ]);

        $pdo->prepare(
            "INSERT INTO audit_log (staff_id, action, module_name, description, ip_address)
             VALUES (:sid, 'create', 'expenses', :desc, :ip)"
        )->execute([
            'sid' => $_SESSION['staff_id'],
            'desc' => "Expense of ₹$amount recorded",
            'ip' => $_SERVER['REMOTE_ADDR'] ?? null,
        ]);

        $success = 'Expense record ho gaya.';
    }
}

// ---- Handle Delete Expense ----
if (isset($_GET['delete'])) {
    requirePermission($pdo, 'expenses', 'delete');
    $delId = (int)$_GET['delete'];
    $pdo->prepare("DELETE FROM expenses WHERE expense_id = :id")->execute(['id' => $delId]);
    $success = 'Expense delete ho gaya.';
}

// ---- Filters ----
$filterMonth = sanitize($_GET['month'] ?? date('Y-m')); // format: YYYY-MM
$filterCategory = (int)($_GET['category'] ?? 0);

$whereSql = " WHERE DATE_FORMAT(e.expense_date, '%Y-%m') = :month";
$whereParams = ['month' => $filterMonth];
if ($filterCategory > 0) {
    $whereSql .= " AND e.expense_category_id = :cat_id";
    $whereParams['cat_id'] = $filterCategory;
}

$sql = "SELECT e.expense_id, e.amount, e.expense_date, e.payment_mode, e.notes,
               ec.name AS category_name, st.name AS staff_name
        FROM expenses e
        JOIN expense_categories ec ON e.expense_category_id = ec.expense_category_id
        LEFT JOIN staff st ON e.created_by = st.staff_id"
        . $whereSql .
        " ORDER BY e.expense_date DESC, e.expense_id DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($whereParams);
$expenses = $stmt->fetchAll();

$totalAmount = array_sum(array_column($expenses, 'amount'));

$categories = $pdo->query("SELECT expense_category_id, name FROM expense_categories ORDER BY name ASC")->fetchAll();

include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/sidebar.php';
?>
<main>
    <h1>Expenses</h1>

    <?php if ($error): ?>
        <div class="alert alert-error"><?php echo sanitize($error); ?></div>
    <?php endif; ?>
    <?php if ($success): ?>
        <div class="alert alert-success"><?php echo sanitize($success); ?></div>
    <?php endif; ?>

    <div class="card">
        <h2>Add Expense Category</h2>
        <form method="POST" action="" class="inline-form">
            <input type="hidden" name="action" value="add_category">
            <input type="text" name="category_name" placeholder="e.g. Rent, Electricity, Maintenance" required>
            <button type="submit">Add Category</button>
        </form>
    </div>

    <div class="card">
        <h2>Log New Expense</h2>
        <form method="POST" action="" class="form-grid">
            <input type="hidden" name="action" value="add_expense">
            <div>
                <label>Category *</label>
                <select name="expense_category_id" required>
                    <option value="">-- Select Category --</option>
                    <?php foreach ($categories as $c): ?>
                        <option value="<?php echo (int)$c['expense_category_id']; ?>">
                            <?php echo sanitize($c['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <?php if (empty($categories)): ?>
                    <small>Pehle upar se ek category add karo.</small>
                <?php endif; ?>
            </div>
            <div>
                <label>Amount (₹) *</label>
                <input type="number" step="0.01" min="0.01" name="amount" required>
            </div>
            <div>
                <label>Date</label>
                <input type="date" name="expense_date" value="<?php echo date('Y-m-d'); ?>">
            </div>
            <div>
                <label>Payment Mode</label>
                <select name="payment_mode">
                    <option value="cash">Cash</option>
                    <option value="upi">UPI</option>
                    <option value="bank_transfer">Bank Transfer</option>
                    <option value="cheque">Cheque</option>
                </select>
            </div>
            <div style="grid-column: span 2;">
                <label>Notes</label>
                <input type="text" name="notes" placeholder="optional - e.g. August month ka rent">
            </div>
            <div>
                <button type="submit">Save Expense</button>
            </div>
        </form>
    </div>

    <div class="card">
        <h2>Expense History</h2>
        <form method="GET" action="" class="inline-form">
            <label>Month:</label>
            <input type="month" name="month" value="<?php echo sanitize($filterMonth); ?>">
            <label>Category:</label>
            <select name="category">
                <option value="0">All Categories</option>
                <?php foreach ($categories as $c): ?>
                    <option value="<?php echo (int)$c['expense_category_id']; ?>" <?php echo $filterCategory === (int)$c['expense_category_id'] ? 'selected' : ''; ?>>
                        <?php echo sanitize($c['name']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <button type="submit">Filter</button>
        </form>

        <p style="margin-top:12px;"><strong>Total for this period: ₹<?php echo number_format($totalAmount, 2); ?></strong> (<?php echo count($expenses); ?> entries)</p>

        <table class="data-table">
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Category</th>
                    <th>Amount (₹)</th>
                    <th>Payment Mode</th>
                    <th>Notes</th>
                    <th>Recorded By</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($expenses)): ?>
                    <tr><td colspan="7">Is period me koi expense record nahi hai.</td></tr>
                <?php else: ?>
                    <?php foreach ($expenses as $e): ?>
                    <tr>
                        <td><?php echo sanitize(date('d M Y', strtotime($e['expense_date']))); ?></td>
                        <td><?php echo sanitize($e['category_name']); ?></td>
                        <td><?php echo number_format($e['amount'], 2); ?></td>
                        <td><?php echo sanitize(ucwords(str_replace('_', ' ', $e['payment_mode']))); ?></td>
                        <td><?php echo sanitize($e['notes'] ?? '-'); ?></td>
                        <td><?php echo sanitize($e['staff_name'] ?? '-'); ?></td>
                        <td>
                            <a href="?delete=<?php echo (int)$e['expense_id']; ?>&month=<?php echo urlencode($filterMonth); ?>&category=<?php echo $filterCategory; ?>"
                               onclick="return confirm('Ye expense delete karni hai?');">Delete</a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</main>
<?php include __DIR__ . '/../../includes/footer.php'; ?>