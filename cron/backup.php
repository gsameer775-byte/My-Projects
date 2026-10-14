<?php
require_once __DIR__ . '/../config/config.php';

// ============================================
// cron/backup.php - Automated Database Backup
//
// Run this daily via Windows Task Scheduler (XAMPP has no cron) or Linux
// crontab in production:
//   C:\xampp\php\php.exe C:\xampp\htdocs\hikayat_banaras\cron\backup.php
//
// Pure PDO-based dump (no mysqldump.exe path guessing needed - works the
// same on XAMPP/Windows and any Linux host). For every table it writes a
// CREATE TABLE statement + all rows as INSERT statements into one timestamped
// .sql file under /backups (which is blocked from direct web access - see
// backups/.htaccess). Backups older than KEEP_DAYS are auto-deleted so the
// disk never fills up.
//
// Can also be triggered on-demand from admin/settings/backups.php ("Run
// Backup Now" button) - both paths call runBackup() below.
// ============================================

define('BACKUP_DIR', __DIR__ . '/../backups/');
define('KEEP_DAYS', 30);

function runBackup($pdo) {
    if (!is_dir(BACKUP_DIR)) {
        mkdir(BACKUP_DIR, 0755, true);
    }

    $fileName = 'backup_' . date('Y-m-d_His') . '.sql';
    $filePath = BACKUP_DIR . $fileName;
    $fh = fopen($filePath, 'w');

    if (!$fh) {
        return ['success' => false, 'message' => 'Backup file likh nahi paya (folder permissions check karo).'];
    }

    fwrite($fh, "-- Hikayat Banaras - Automated Backup\n-- Generated: " . date('Y-m-d H:i:s') . "\n\n");
    fwrite($fh, "SET FOREIGN_KEY_CHECKS=0;\n\n");

    $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);

    foreach ($tables as $table) {
        // Structure
        $createStmt = $pdo->query("SHOW CREATE TABLE `$table`")->fetch();
        fwrite($fh, "DROP TABLE IF EXISTS `$table`;\n");
        fwrite($fh, $createStmt['Create Table'] . ";\n\n");

        // Data (chunked so large tables don't blow up memory)
        $countStmt = $pdo->query("SELECT COUNT(*) FROM `$table`");
        $rowCount = (int)$countStmt->fetchColumn();

        if ($rowCount > 0) {
            $chunkSize = 500;
            $columns = null;

            for ($offset = 0; $offset < $rowCount; $offset += $chunkSize) {
                $rows = $pdo->query("SELECT * FROM `$table` LIMIT $chunkSize OFFSET $offset")->fetchAll();
                if (empty($rows)) continue;

                if ($columns === null) {
                    $columns = array_keys($rows[0]);
                }

                $colList = '`' . implode('`, `', $columns) . '`';
                $valueRows = [];

                foreach ($rows as $row) {
                    $escaped = array_map(function ($val) use ($pdo) {
                        return $val === null ? 'NULL' : $pdo->quote($val);
                    }, $row);
                    $valueRows[] = '(' . implode(', ', $escaped) . ')';
                }

                fwrite($fh, "INSERT INTO `$table` ($colList) VALUES\n" . implode(",\n", $valueRows) . ";\n\n");
            }
        }
    }

    fwrite($fh, "SET FOREIGN_KEY_CHECKS=1;\n");
    fclose($fh);

    // Clean up old backups
    $deleted = 0;
    $cutoff = time() - (KEEP_DAYS * 86400);
    foreach (glob(BACKUP_DIR . 'backup_*.sql') as $oldFile) {
        if (filemtime($oldFile) < $cutoff) {
            unlink($oldFile);
            $deleted++;
        }
    }

    return [
        'success' => true,
        'file' => $fileName,
        'size' => filesize($filePath),
        'deleted_old' => $deleted,
    ];
}

// If run directly (via Task Scheduler / crontab / CLI), execute immediately
if (php_sapi_name() === 'cli' || basename($_SERVER['SCRIPT_FILENAME'] ?? '') === 'backup.php') {
    $result = runBackup($pdo);
    if ($result['success']) {
        echo date('Y-m-d H:i:s') . " - Backup created: {$result['file']} (" . round($result['size'] / 1024, 1) . " KB). Old backups deleted: {$result['deleted_old']}.\n";
    } else {
        echo date('Y-m-d H:i:s') . " - Backup FAILED: {$result['message']}\n";
    }
}
