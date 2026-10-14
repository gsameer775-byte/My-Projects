<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/functions.php';
requirePermission($pdo, 'staff', 'view');

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_payment') {
    requirePermission($pdo, 'staff', 'edit');
    $staff_id          = (int)($_POST['staff_id'] ?? 0);
    $pay_month          = $_POST['pay_month'] ?? '';
    $base_salary        = (float)($_POST['base_salary'] ?? 0);
    $commission_earned  = (float)($_POST['commission_earned'] ?? 0);
    $deductions         = (float)($_POST['deductions'] ?? 0);
    $paid_on            = $_POST['paid_on'] !== '' ? $_POST['paid_on'] : null;
    $notes              = sanitize($_POST['notes'] ?? '');

    if ($staff_id === 0 || $pay_month === '') {
        $error = 'Staff aur pay month zaroori hain.';
    } else {
        // pay_month input type="month" gives YYYY-MM, convert to first-of-month date
        $payMonthDate = $pay_month . '-01';
        $net_paid = $base_salary + $commission_earned - $deductions;

        $stmt = $pdo->prepare(
            "INSERT INTO salary_payments (staff_id, pay_month, base_salary, commission_earned, deductions, net_paid, paid_on, notes)
             VALUES (:sid, :pm, :base, :comm, :ded, :net, :paid_on, :notes)"
        );
        $stmt->execute([
            'sid' => $staff_id, 'pm' => $payMonthDate, 'base' => $base_salary,
            'comm' => $commission_earned, 'ded' => $deductions, 'net' => $net_paid,
            'paid_on' => $paid_on, 'notes' => $notes ?: null,
        ]);
        $success = 'Salary payment record ho gaya.';
    }
}

$allStaff = $pdo->query("SELECT staff_id, name, monthly_salary, commission_rate FROM staff WHERE is_active = 1 ORDER BY name")->fetchAll();

$filterStaffId = (int)($_GET['staff_id'] ?? 0);

$histSql = "SELECT sp.pay_month, sp.base_salary, sp.commission_earned, sp.deductions, sp.net_paid, sp.paid_on, sp.notes, s.name
            FROM salary_payments sp
            JOIN staff s ON s.staff_id = sp.staff_id";
$histParams = [];
if ($filterStaffId > 0) {
    $histSql .= " WHERE sp.staff_id = :sid";
    $histParams['sid'] = $filterStaffId;
}
$histSql .= " ORDER BY sp.pay_month DESC, s.name";

$histStmt = $pdo->prepare($histSql);
$histStmt->execute($histParams);
$history = $histStmt->fetchAll();

include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/sidebar.php';
?>
<main>
    <h1>Salary &amp; Commission</h1>

    <?php if ($error): ?>
        <div class="alert alert-error"><?php echo sanitize($error); ?></div>
    <?php endif; ?>
    <?php if ($success): ?>
        <div class="alert alert-success"><?php echo sanitize($success); ?></div>
    <?php endif; ?>

    <div class="toolbar">
        <a href="list.php">Back to Staff List</a>
    </div>

    <div class="card">
        <h2>Record a Salary Payment</h2>
        <form method="POST" action="" class="form-grid">
            <input type="hidden" name="action" value="add_payment">
            <div>
                <label>Staff *</label>
                <select name="staff_id" required onchange="prefillSalary(this)">
                    <option value="">-- Select Staff --</option>
                    <?php foreach ($allStaff as $s): ?>
                        <option value="<?php echo (int)$s['staff_id']; ?>"
                            data-salary="<?php echo $s['monthly_salary'] !== null ? (float)$s['monthly_salary'] : ''; ?>"
                            <?php echo $filterStaffId === (int)$s['staff_id'] ? 'selected' : ''; ?>>
                            <?php echo sanitize($s['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label>Pay Month *</label>
                <input type="month" name="pay_month" value="<?php echo date('Y-m'); ?>" required>
            </div>
            <div>
                <label>Base Salary (₹)</label>
                <input type="number" step="0.01" id="base_salary_input" name="base_salary" value="0">
            </div>
            <div>
                <label>Commission Earned (₹)</label>
                <input type="number" step="0.01" name="commission_earned" value="0">
            </div>
            <div>
                <label>Deductions (₹)</label>
                <input type="number" step="0.01" name="deductions" value="0">
            </div>
            <div>
                <label>Paid On</label>
                <input type="date" name="paid_on" value="<?php echo date('Y-m-d'); ?>">
            </div>
            <div style="grid-column: span 2;">
                <label>Notes</label>
                <input type="text" name="notes">
            </div>
            <div>
                <button type="submit">Save Payment</button>
            </div>
        </form>
        <script>
        function prefillSalary(sel) {
            var opt = sel.options[sel.selectedIndex];
            var salary = opt.getAttribute('data-salary');
            if (salary) {
                document.getElementById('base_salary_input').value = salary;
            }
        }
        </script>
    </div>

    <div class="card">
        <h2>Payment History</h2>
        <form method="GET" action="" class="inline-form" style="margin-bottom: 16px;">
            <select name="staff_id">
                <option value="0">All Staff</option>
                <?php foreach ($allStaff as $s): ?>
                    <option value="<?php echo (int)$s['staff_id']; ?>" <?php echo $filterStaffId === (int)$s['staff_id'] ? 'selected' : ''; ?>>
                        <?php echo sanitize($s['name']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <button type="submit">Filter</button>
        </form>

        <table class="data-table">
            <thead>
                <tr>
                    <th>Month</th>
                    <th>Staff</th>
                    <th>Base Salary</th>
                    <th>Commission</th>
                    <th>Deductions</th>
                    <th>Net Paid</th>
                    <th>Paid On</th>
                    <th>Notes</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($history)): ?>
                    <tr><td colspan="8">Koi salary payment record nahi mila.</td></tr>
                <?php else: ?>
                    <?php foreach ($history as $h): ?>
                    <tr>
                        <td><?php echo date('M Y', strtotime($h['pay_month'])); ?></td>
                        <td><?php echo sanitize($h['name']); ?></td>
                        <td>₹<?php echo number_format($h['base_salary'], 2); ?></td>
                        <td>₹<?php echo number_format($h['commission_earned'], 2); ?></td>
                        <td>₹<?php echo number_format($h['deductions'], 2); ?></td>
                        <td><strong>₹<?php echo number_format($h['net_paid'], 2); ?></strong></td>
                        <td><?php echo $h['paid_on'] ? sanitize($h['paid_on']) : '-'; ?></td>
                        <td><?php echo sanitize($h['notes'] ?? '-'); ?></td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</main>
<?php include __DIR__ . '/../../includes/footer.php'; ?>