<?php
// ============================================
// cron/generate-reminders.php
// Run this daily via server crontab, e.g.:
//   0 6 * * * /usr/bin/php /path/to/hikayat_banaras/cron/generate-reminders.php >> /path/to/hikayat_banaras/cron/reminders.log 2>&1
//
// Scans all customers and auto-creates reminders for birthdays/anniversaries
// falling in the next 30 days, so staff never have to remember to click
// "Sync" manually on the Reminders page. Safe to run daily - duplicates are
// prevented automatically.
// ============================================

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/functions.php';

$count = syncUpcomingReminders($pdo, 30);
echo date('Y-m-d H:i:s') . " - Generated {$count} new reminder(s).\n";