<?php
// ============================================
// Hikayat Banaras - Database Connection (PDO)
// Copy this file to db.php and fill in your own values.
// ============================================

$DB_HOST = 'localhost';
$DB_NAME = 'hikayat_banaras';
$DB_USER = 'root';       // your MySQL username
$DB_PASS = '';           // your MySQL password
$DB_CHARSET = 'utf8mb4';

$dsn = "mysql:host={$DB_HOST};dbname={$DB_NAME};charset={$DB_CHARSET}";

$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
    $pdo = new PDO($dsn, $DB_USER, $DB_PASS, $options);
} catch (PDOException $e) {
    // In production, log this instead of echoing raw error
    die('Database connection failed: ' . $e->getMessage());
}
