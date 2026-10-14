<?php
// ============================================
// Hikayat Banaras - Shared Helper Functions
// ============================================

function sanitize($data) {
    return htmlspecialchars(trim($data), ENT_QUOTES, 'UTF-8');
}

function redirect($path) {
    header('Location: ' . BASE_URL . $path);
    exit;
}

function isLoggedIn() {
    return isset($_SESSION['staff_id']);
}

// ============================================
// Session / Device Management
// - A DB-backed session_token (separate from PHP's own session id) lets an
//   admin see every device currently logged in and force-logout any one of
//   them from admin/settings/sessions.php, without needing server-level
//   access to kill PHP session files.
// - Called once per request from requireLogin()/requirePermission() - if the
//   token was force-logged-out (or never existed, e.g. a session from before
//   this feature was added), the PHP session is destroyed and the person is
//   sent back to login.
// ============================================
function validateSession($pdo) {
    if (empty($_SESSION['session_token'])) {
        // Pre-upgrade session with no token yet - require a fresh login once
        session_unset();
        session_destroy();
        redirect('admin/login.php');
    }

    $stmt = $pdo->prepare("SELECT is_active FROM login_sessions WHERE session_token = :token");
    $stmt->execute(['token' => $_SESSION['session_token']]);
    $row = $stmt->fetch();

    if (!$row || (int)$row['is_active'] !== 1) {
        session_unset();
        session_destroy();
        redirect('admin/login.php');
    }

    // Lightweight "last active" heartbeat so the sessions list shows real activity
    $pdo->prepare("UPDATE login_sessions SET last_active_at = NOW() WHERE session_token = :token")
        ->execute(['token' => $_SESSION['session_token']]);
}

function requireLogin() {
    if (!isLoggedIn()) {
        redirect('admin/login.php');
    }
    global $pdo;
    validateSession($pdo);
}

/**
 * Parses a User-Agent string into a short human-friendly device label,
 * e.g. "Windows - Chrome". Not exhaustive, just enough to tell devices apart.
 */
function parseDeviceLabel($userAgent) {
    $userAgent = $userAgent ?: '';
    if (stripos($userAgent, 'Windows') !== false) {
        $os = 'Windows';
    } elseif (stripos($userAgent, 'iPhone') !== false || stripos($userAgent, 'iPad') !== false) {
        $os = 'iOS';
    } elseif (stripos($userAgent, 'Android') !== false) {
        $os = 'Android';
    } elseif (stripos($userAgent, 'Mac OS') !== false) {
        $os = 'Mac';
    } elseif (stripos($userAgent, 'Linux') !== false) {
        $os = 'Linux';
    } else {
        $os = 'Unknown Device';
    }

    if (stripos($userAgent, 'Edg/') !== false) {
        $browser = 'Edge';
    } elseif (stripos($userAgent, 'Chrome') !== false) {
        $browser = 'Chrome';
    } elseif (stripos($userAgent, 'Firefox') !== false) {
        $browser = 'Firefox';
    } elseif (stripos($userAgent, 'Safari') !== false) {
        $browser = 'Safari';
    } else {
        $browser = 'Browser';
    }

    return "$os - $browser";
}

// ============================================
// Roles & Permissions enforcement
// - Admin role always gets full access (bypass) - this is required because
//   setup-admin.php creates the first Admin account WITHOUT any
//   role_permissions rows, so without this bypass the very first admin
//   could never access anything, including the Roles & Permissions page
//   needed to grant themselves access.
// - Every other role is checked against the role_permissions table for the
//   given module + action ('view', 'edit', or 'delete').
// - Not logged in -> redirected to login (so pages don't need to call
//   requireLogin() separately when they already call requirePermission()).
// - Logged in but not allowed -> a plain "Access Denied" message, not a
//   silent redirect, so staff understand why and can ask an admin for access.
// ============================================
function requirePermission($pdo, $moduleName, $action = 'view') {
    if (!isLoggedIn()) {
        redirect('admin/login.php');
    }
    validateSession($pdo);

    $roleId = (int)($_SESSION['role_id'] ?? 0);

    $roleStmt = $pdo->prepare("SELECT role_name FROM roles WHERE role_id = :id");
    $roleStmt->execute(['id' => $roleId]);
    $role = $roleStmt->fetch();
    $roleName = $role ? $role['role_name'] : '';

    // Admin = full access, always
    if (strcasecmp($roleName, 'Admin') === 0) {
        return true;
    }

    if ($action === 'edit') {
        $column = 'can_edit';
    } elseif ($action === 'delete') {
        $column = 'can_delete';
    } else {
        $column = 'can_view';
    }

    $permStmt = $pdo->prepare(
        "SELECT $column FROM role_permissions WHERE role_id = :role_id AND module_name = :mod"
    );
    $permStmt->execute(['role_id' => $roleId, 'mod' => $moduleName]);
    $perm = $permStmt->fetch();

    if ($perm && (int)$perm[$column] === 1) {
        return true;
    }

    // Not allowed - show a clear message and stop, rather than a confusing fatal error
    http_response_code(403);
    echo '<!DOCTYPE html><html><head><title>Access Denied</title>';
    echo '<link rel="stylesheet" href="' . BASE_URL . 'assets/css/style.css"></head><body style="padding:40px;">';
    echo '<div class="alert alert-error" style="max-width:480px;">';
    echo '<strong>Access Denied.</strong><br>Aapke role (' . htmlspecialchars($roleName ?: 'Unknown') . ') ke paas is section (' . htmlspecialchars($moduleName) . ') ka permission nahi hai. Admin se contact karo.';
    echo '</div><p><a href="' . BASE_URL . 'admin/index.php">Dashboard pe wapas jao</a></p>';
    echo '</body></html>';
    exit;
}

// ============================================
// Generic key/value settings store (system_settings table)
// ============================================
function getSystemSetting($pdo, $key, $default = null) {
    $stmt = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key = :k");
    $stmt->execute(['k' => $key]);
    $row = $stmt->fetch();
    return $row ? $row['setting_value'] : $default;
}

function setSystemSetting($pdo, $key, $value, $desc = null) {
    $stmt = $pdo->prepare(
        "INSERT INTO system_settings (setting_key, setting_value, description) VALUES (:k, :v, :d)
         ON DUPLICATE KEY UPDATE setting_value = :v2"
    );
    $stmt->execute(['k' => $key, 'v' => $value, 'd' => $desc, 'v2' => $value]);
}

// ============================================
// CRM - Reminders
// Scans customers' birthday/anniversary dates and inserts reminders for
// anything falling within $windowDays. Safe to call repeatedly (dedups per
// customer/type/year), used by both admin/crm/reminders.php (manual sync
// button) and cron/generate-reminders.php (daily automatic sync).
// Returns number of new reminders inserted.
// ============================================
function syncUpcomingReminders($pdo, $windowDays = 30) {
    $inserted = 0;
    $types = ['birthday' => 'birthday', 'anniversary' => 'anniversary'];

    foreach ($types as $col => $type) {
        $customers = $pdo->query("SELECT customer_id, name, $col AS event_date FROM customers WHERE $col IS NOT NULL AND is_active = 1")->fetchAll();

        foreach ($customers as $cust) {
            $eventDate = new DateTime($cust['event_date']);
            $today = new DateTime(date('Y-m-d'));
            $thisYearEvent = new DateTime($today->format('Y') . '-' . $eventDate->format('m-d'));

            // if this year's date already passed, look at next year's occurrence
            if ($thisYearEvent < $today) {
                $thisYearEvent->modify('+1 year');
            }

            $daysAway = (int)$today->diff($thisYearEvent)->format('%r%a');
            if ($daysAway >= 0 && $daysAway <= $windowDays) {
                $targetDateStr = $thisYearEvent->format('Y-m-d');

                // Dedup: don't create the same reminder twice for the same customer/type/year
                $check = $pdo->prepare(
                    "SELECT reminder_id FROM reminders
                     WHERE customer_id = :cid AND reminder_type = :type AND YEAR(reminder_date) = :yr"
                );
                $check->execute(['cid' => $cust['customer_id'], 'type' => $type, 'yr' => $thisYearEvent->format('Y')]);

                if (!$check->fetch()) {
                    $msg = $type === 'birthday'
                        ? "Happy Birthday {$cust['name']}! Hikayat Banaras ki taraf se aapko dher saari shubhkamnayein. Aaj kisi bhi khareedari par khaas discount paayein!"
                        : "Happy Anniversary {$cust['name']}! Is khaas din par Hikayat Banaras me aapke liye special collection intezaar kar raha hai.";

                    $pdo->prepare(
                        "INSERT INTO reminders (customer_id, reminder_type, reminder_date, message, is_sent)
                         VALUES (:cid, :type, :date, :msg, 0)"
                    )->execute(['cid' => $cust['customer_id'], 'type' => $type, 'date' => $targetDateStr, 'msg' => $msg]);
                    $inserted++;
                }
            }
        }
    }
    return $inserted;
}