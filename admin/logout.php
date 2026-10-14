<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/functions.php';

// Mark this device's DB-backed session as inactive too (not just destroy the
// PHP session), so it correctly disappears from Settings -> Sessions
if (!empty($_SESSION['session_token'])) {
    $pdo->prepare("UPDATE login_sessions SET is_active = 0 WHERE session_token = :token")
        ->execute(['token' => $_SESSION['session_token']]);
}

session_destroy();
redirect('admin/login.php');