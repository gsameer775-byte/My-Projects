<?php
// ============================================
// Hikayat Banaras - Site Configuration
// Copy this file to config.php and adjust BASE_URL for your setup.
// ============================================

date_default_timezone_set('Asia/Kolkata');

define('SITE_NAME', 'Hikayat Banaras');
define('BASE_URL', 'http://localhost/hikayat_banaras/');
define('UPLOAD_PATH', __DIR__ . '/../assets/uploads/saree-images/');
define('UPLOAD_URL', BASE_URL . 'assets/uploads/saree-images/');

// Session start (used across admin panel)
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/db.php';
