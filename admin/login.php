<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/functions.php';

$error = '';

// If already logged in, go straight to dashboard
if (isLoggedIn()) {
    redirect('admin/index.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = sanitize($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($username === '' || $password === '') {
        $error = 'Username aur password dono bharo.';
    } else {
        $stmt = $pdo->prepare(
            "SELECT staff_id, name, username, password_hash, role_id, is_active
             FROM staff WHERE username = :username LIMIT 1"
        );
        $stmt->execute(['username' => $username]);
        $staff = $stmt->fetch();

        if (!$staff) {
            $error = 'Galat username ya password.';
        } elseif ((int)$staff['is_active'] !== 1) {
            $error = 'Ye account inactive hai. Admin se contact karo.';
        } elseif (!password_verify($password, $staff['password_hash'])) {
            $error = 'Galat username ya password.';
        } else {
            // Login success
            $_SESSION['staff_id']   = $staff['staff_id'];
            $_SESSION['staff_name'] = $staff['name'];
            $_SESSION['role_id']    = $staff['role_id'];

            // Create a DB-backed session record so this device shows up in
            // Settings -> Sessions, and can be remotely logged out later
            $sessionToken = bin2hex(random_bytes(32));
            $_SESSION['session_token'] = $sessionToken;

            $deviceLabel = parseDeviceLabel($_SERVER['HTTP_USER_AGENT'] ?? '');
            $sessionStmt = $pdo->prepare(
                "INSERT INTO login_sessions (staff_id, session_token, device_label, ip_address)
                 VALUES (:sid, :token, :device, :ip)"
            );
            $sessionStmt->execute([
                'sid' => $staff['staff_id'],
                'token' => $sessionToken,
                'device' => $deviceLabel,
                'ip' => $_SERVER['REMOTE_ADDR'] ?? null,
            ]);

            // Audit log entry
            $log = $pdo->prepare(
                "INSERT INTO audit_log (staff_id, action, module_name, description, ip_address)
                 VALUES (:staff_id, 'login', 'auth', 'Staff logged in', :ip)"
            );
            $log->execute([
                'staff_id' => $staff['staff_id'],
                'ip'       => $_SERVER['REMOTE_ADDR'] ?? null,
            ]);

            redirect('admin/index.php');
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Login - <?php echo SITE_NAME; ?></title>
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>assets/css/style.css">
    <style>
        .login-wrap { max-width: 360px; margin: 80px auto; background: #fff; padding: 32px; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.1); }
        .login-wrap h1 { margin-bottom: 20px; font-size: 22px; color: #3b0a0a; }
        .login-wrap label { display: block; margin-bottom: 6px; font-size: 14px; }
        .login-wrap input { width: 100%; padding: 10px; margin-bottom: 16px; border: 1px solid #ccc; border-radius: 4px; }
        .login-wrap button { width: 100%; padding: 10px; background: #3b0a0a; color: #fff; border: none; border-radius: 4px; cursor: pointer; }
        .login-wrap .error { background: #fdecea; color: #b71c1c; padding: 10px; margin-bottom: 16px; border-radius: 4px; font-size: 14px; }
    </style>
</head>
<body>
    <div class="login-wrap">
        <h1><?php echo SITE_NAME; ?></h1>
        <?php if ($error): ?>
            <div class="error"><?php echo sanitize($error); ?></div>
        <?php endif; ?>
        <form method="POST" action="">
            <label for="username">Username</label>
            <input type="text" id="username" name="username" required autofocus>

            <label for="password">Password</label>
            <input type="password" id="password" name="password" required>

            <button type="submit">Login</button>
        </form>
    </div>
</body>
</html>