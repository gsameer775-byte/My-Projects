<?php
// ============================================
// ONE-TIME SETUP SCRIPT - creates first admin user
// DELETE THIS FILE after running it once.
// ============================================
require_once __DIR__ . '/../config/config.php';

$message = '';
$done = false;

// Safety check: if a staff row already exists, block re-running
$check = $pdo->query("SELECT COUNT(*) AS cnt FROM staff")->fetch();
if ($check['cnt'] > 0) {
    $message = 'Staff table already has records. For security, this script only works on an empty staff table. Delete this file and create users normally.';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name     = trim($_POST['name'] ?? '');
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $phone    = trim($_POST['phone'] ?? '');

    if ($name === '' || $username === '' || $password === '' || $phone === '') {
        $message = 'Saari fields bharo.';
    } else {
        // Ensure an Admin role exists
        $roleStmt = $pdo->prepare("SELECT role_id FROM roles WHERE role_name = 'Admin' LIMIT 1");
        $roleStmt->execute();
        $role = $roleStmt->fetch();

        if (!$role) {
            $pdo->prepare("INSERT INTO roles (role_name, description) VALUES ('Admin', 'Full access')")->execute();
            $roleId = $pdo->lastInsertId();
        } else {
            $roleId = $role['role_id'];
        }

        $hash = password_hash($password, PASSWORD_DEFAULT);

        $stmt = $pdo->prepare(
            "INSERT INTO staff (name, phone, username, password_hash, role_id, joining_date)
             VALUES (:name, :phone, :username, :hash, :role_id, CURDATE())"
        );
        $stmt->execute([
            'name'     => $name,
            'phone'    => $phone,
            'username' => $username,
            'hash'     => $hash,
            'role_id'  => $roleId,
        ]);

        $done = true;
        $message = 'Admin user created! Ab admin/login.php pe jaake login karo, phir is file (setup-admin.php) ko DELETE kar do.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Setup Admin - <?php echo SITE_NAME; ?></title>
    <style>
        body { font-family: Arial, sans-serif; background: #f7f5f2; }
        .wrap { max-width: 400px; margin: 60px auto; background: #fff; padding: 30px; border-radius: 8px; }
        input { width: 100%; padding: 10px; margin-bottom: 14px; border: 1px solid #ccc; border-radius: 4px; }
        button { padding: 10px 20px; background: #3b0a0a; color: #fff; border: none; border-radius: 4px; }
        .msg { padding: 12px; margin-bottom: 16px; border-radius: 4px; background: <?php echo $done ? '#e6f4ea' : '#fdecea'; ?>; color: <?php echo $done ? '#1e7e34' : '#b71c1c'; ?>; }
    </style>
</head>
<body>
    <div class="wrap">
        <h2>Create First Admin User</h2>
        <?php if ($message): ?>
            <div class="msg"><?php echo htmlspecialchars($message); ?></div>
        <?php endif; ?>
        <?php if (!$done && $check['cnt'] == 0): ?>
        <form method="POST">
            <input type="text" name="name" placeholder="Full Name" required>
            <input type="text" name="phone" placeholder="Phone" required>
            <input type="text" name="username" placeholder="Username" required>
            <input type="password" name="password" placeholder="Password" required>
            <button type="submit">Create Admin</button>
        </form>
        <?php endif; ?>
    </div>
</body>
</html>