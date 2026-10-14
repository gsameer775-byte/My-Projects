<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/functions.php';
requirePermission($pdo, 'staff', 'view');

$error = '';
$success = '';

$staff_id = (int)($_GET['id'] ?? $_POST['staff_id'] ?? 0);
$isEdit = $staff_id > 0;

$roles = $pdo->query("SELECT role_id, role_name FROM roles ORDER BY role_name")->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requirePermission($pdo, 'staff', 'edit');
    $name             = sanitize($_POST['name'] ?? '');
    $phone            = sanitize($_POST['phone'] ?? '');
    $email            = sanitize($_POST['email'] ?? '');
    $username         = sanitize($_POST['username'] ?? '');
    $password         = $_POST['password'] ?? '';
    $role_id          = (int)($_POST['role_id'] ?? 0);
    $monthly_salary   = $_POST['monthly_salary'] !== '' ? (float)$_POST['monthly_salary'] : null;
    $commission_rate  = $_POST['commission_rate'] !== '' ? (float)$_POST['commission_rate'] : null;
    $joining_date     = $_POST['joining_date'] !== '' ? $_POST['joining_date'] : null;
    $is_active        = isset($_POST['is_active']) ? 1 : 0;

    if ($name === '' || $phone === '' || $username === '' || $role_id === 0) {
        $error = 'Naam, phone, username aur role zaroori hain.';
    } elseif (!$isEdit && $password === '') {
        $error = 'Naya staff banane ke liye password zaroori hai.';
    } else {
        // Duplicate username check (excluding self if editing)
        $dupSql = "SELECT COUNT(*) AS cnt FROM staff WHERE username = :username";
        $dupParams = ['username' => $username];
        if ($isEdit) {
            $dupSql .= " AND staff_id != :id";
            $dupParams['id'] = $staff_id;
        }
        $dup = $pdo->prepare($dupSql);
        $dup->execute($dupParams);

        // Duplicate phone check (excluding self if editing)
        $dupPhoneSql = "SELECT COUNT(*) AS cnt FROM staff WHERE phone = :phone";
        $dupPhoneParams = ['phone' => $phone];
        if ($isEdit) {
            $dupPhoneSql .= " AND staff_id != :id";
            $dupPhoneParams['id'] = $staff_id;
        }
        $dupPhone = $pdo->prepare($dupPhoneSql);
        $dupPhone->execute($dupPhoneParams);

        if ($dup->fetch()['cnt'] > 0) {
            $error = 'Ye username already kisi aur staff ka registered hai.';
        } elseif ($dupPhone->fetch()['cnt'] > 0) {
            $error = 'Ye phone number already kisi aur staff ka registered hai.';
        } else {
            if ($isEdit) {
                if ($password !== '') {
                    $stmt = $pdo->prepare(
                        "UPDATE staff SET
                            name = :name, phone = :phone, email = :email, username = :username,
                            password_hash = :password_hash, role_id = :role_id,
                            monthly_salary = :monthly_salary, commission_rate = :commission_rate,
                            joining_date = :joining_date, is_active = :is_active
                         WHERE staff_id = :id"
                    );
                    $stmt->execute([
                        'name' => $name, 'phone' => $phone, 'email' => $email ?: null,
                        'username' => $username, 'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                        'role_id' => $role_id, 'monthly_salary' => $monthly_salary,
                        'commission_rate' => $commission_rate, 'joining_date' => $joining_date,
                        'is_active' => $is_active, 'id' => $staff_id,
                    ]);
                } else {
                    $stmt = $pdo->prepare(
                        "UPDATE staff SET
                            name = :name, phone = :phone, email = :email, username = :username,
                            role_id = :role_id, monthly_salary = :monthly_salary,
                            commission_rate = :commission_rate, joining_date = :joining_date,
                            is_active = :is_active
                         WHERE staff_id = :id"
                    );
                    $stmt->execute([
                        'name' => $name, 'phone' => $phone, 'email' => $email ?: null,
                        'username' => $username, 'role_id' => $role_id,
                        'monthly_salary' => $monthly_salary, 'commission_rate' => $commission_rate,
                        'joining_date' => $joining_date, 'is_active' => $is_active, 'id' => $staff_id,
                    ]);
                }
                $success = 'Staff update ho gaya.';
            } else {
                $stmt = $pdo->prepare(
                    "INSERT INTO staff (name, phone, email, username, password_hash, role_id,
                        monthly_salary, commission_rate, joining_date, is_active)
                     VALUES (:name, :phone, :email, :username, :password_hash, :role_id,
                        :monthly_salary, :commission_rate, :joining_date, :is_active)"
                );
                $stmt->execute([
                    'name' => $name, 'phone' => $phone, 'email' => $email ?: null,
                    'username' => $username, 'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                    'role_id' => $role_id, 'monthly_salary' => $monthly_salary,
                    'commission_rate' => $commission_rate, 'joining_date' => $joining_date,
                    'is_active' => $is_active,
                ]);
                $staff_id = $pdo->lastInsertId();
                $isEdit = true;
                $success = 'Staff add ho gaya.';
            }
        }
    }
}

// Fetch current data for edit form
$staff = [
    'name' => '', 'phone' => '', 'email' => '', 'username' => '',
    'role_id' => '', 'monthly_salary' => '', 'commission_rate' => '',
    'joining_date' => '', 'is_active' => 1,
];
if ($isEdit) {
    $stmt = $pdo->prepare("SELECT * FROM staff WHERE staff_id = :id");
    $stmt->execute(['id' => $staff_id]);
    $row = $stmt->fetch();
    if ($row) {
        $staff = $row;
    } else {
        redirect('admin/staff/list.php');
    }
}

include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/sidebar.php';
?>
<main>
    <h1><?php echo $isEdit ? 'Edit Staff' : 'Add New Staff'; ?></h1>

    <?php if ($error): ?>
        <div class="alert alert-error"><?php echo sanitize($error); ?></div>
    <?php endif; ?>
    <?php if ($success): ?>
        <div class="alert alert-success"><?php echo sanitize($success); ?></div>
    <?php endif; ?>

    <div class="toolbar">
        <a href="list.php">Back to Staff List</a>
        <?php if ($isEdit): ?>
            <a href="attendance.php?staff_id=<?php echo (int)$staff_id; ?>">Attendance</a>
            <a href="salary-commission.php?staff_id=<?php echo (int)$staff_id; ?>">Salary / Commission</a>
            <a href="face-setup.php?staff_id=<?php echo (int)$staff_id; ?>" class="btn-primary">
                <?php echo empty($staff['face_reference_photo']) ? 'Register Face (for attendance)' : 'Re-register Face'; ?>
            </a>
        <?php endif; ?>
    </div>

    <div class="card">
        <form method="POST" action="" class="form-grid">
            <?php if ($isEdit): ?>
                <input type="hidden" name="staff_id" value="<?php echo (int)$staff_id; ?>">
            <?php endif; ?>

            <div>
                <label>Name *</label>
                <input type="text" name="name" value="<?php echo sanitize($staff['name']); ?>" required>
            </div>
            <div>
                <label>Phone *</label>
                <input type="text" name="phone" value="<?php echo sanitize($staff['phone']); ?>" required>
            </div>
            <div>
                <label>Email</label>
                <input type="email" name="email" value="<?php echo sanitize($staff['email'] ?? ''); ?>">
            </div>
            <div>
                <label>Username *</label>
                <input type="text" name="username" value="<?php echo sanitize($staff['username']); ?>" required>
            </div>
            <div>
                <label>Password <?php echo $isEdit ? '(khaali chhodo agar change nahi karna)' : '*'; ?></label>
                <input type="password" name="password" <?php echo $isEdit ? '' : 'required'; ?>>
            </div>
            <div>
                <label>Role *</label>
                <select name="role_id" required>
                    <option value="">-- Select Role --</option>
                    <?php foreach ($roles as $r): ?>
                        <option value="<?php echo (int)$r['role_id']; ?>"
                            <?php echo (int)$staff['role_id'] === (int)$r['role_id'] ? 'selected' : ''; ?>>
                            <?php echo sanitize($r['role_name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <small><a href="roles.php">Roles manage karo</a></small>
            </div>
            <div>
                <label>Monthly Salary (₹)</label>
                <input type="number" step="0.01" name="monthly_salary" value="<?php echo $staff['monthly_salary'] !== null ? sanitize((string)$staff['monthly_salary']) : ''; ?>">
            </div>
            <div>
                <label>Commission Rate (%)</label>
                <input type="number" step="0.01" name="commission_rate" value="<?php echo $staff['commission_rate'] !== null ? sanitize((string)$staff['commission_rate']) : ''; ?>">
            </div>
            <div>
                <label>Joining Date</label>
                <input type="date" name="joining_date" value="<?php echo sanitize($staff['joining_date'] ?? ''); ?>">
            </div>
            <div>
                <label>Status</label>
                <select name="is_active">
                    <option value="1" <?php echo (int)$staff['is_active'] === 1 ? 'selected' : ''; ?>>Active</option>
                    <option value="0" <?php echo (int)$staff['is_active'] === 0 ? 'selected' : ''; ?>>Inactive</option>
                </select>
            </div>
            <div>
                <button type="submit"><?php echo $isEdit ? 'Update Staff' : 'Save Staff'; ?></button>
            </div>
        </form>
    </div>
</main>
<?php include __DIR__ . '/../../includes/footer.php'; ?>