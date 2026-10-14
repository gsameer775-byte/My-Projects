<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/functions.php';
requirePermission($pdo, 'crm', 'view');

// ============================================
// CRM - Reminders
// - Auto-detects upcoming birthdays/anniversaries from customers table (next 30 days)
// - "Sync" button inserts them into `reminders` (dedup per year - safe to click anytime)
// - One-click WhatsApp send per reminder (wa.me, personalized message)
// - Custom follow-up reminders can be added manually
// - cron/generate-reminders.php runs the same sync automatically every day
// ============================================

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requirePermission($pdo, 'crm', 'edit');
    $action = $_POST['action'] ?? '';

    if ($action === 'sync') {
        $count = syncUpcomingReminders($pdo, 30);
        $success = $count > 0 ? "$count naye reminders add ho gaye." : 'Sab up to date hai, koi naya reminder nahi mila.';
    }

    if ($action === 'add_custom') {
        $customerId = (int)($_POST['customer_id'] ?? 0);
        $date       = $_POST['reminder_date'] ?? '';
        $message    = trim($_POST['message'] ?? '');

        if ($customerId <= 0 || $date === '' || $message === '') {
            $error = 'Customer, date aur message sab zaroori hai.';
        } else {
            $custCheck = $pdo->prepare("SELECT customer_id FROM customers WHERE customer_id = :id");
            $custCheck->execute(['id' => $customerId]);
            if (!$custCheck->fetch()) {
                $error = 'Customer ID galat hai.';
            } else {
                $pdo->prepare(
                    "INSERT INTO reminders (customer_id, reminder_type, reminder_date, message, is_sent)
                     VALUES (:cid, 'follow_up', :date, :msg, 0)"
                )->execute(['cid' => $customerId, 'date' => $date, 'msg' => $message]);
                $success = 'Custom reminder add ho gaya.';
            }
        }
    }

    if ($action === 'mark_sent') {
        $reminderId = (int)($_POST['reminder_id'] ?? 0);
        $pdo->prepare("UPDATE reminders SET is_sent = 1 WHERE reminder_id = :id")->execute(['id' => $reminderId]);
        $success = 'Reminder sent mark ho gaya.';
    }
}

// Pull pending reminders (upcoming + overdue-not-sent), with customer contact info
$reminders = $pdo->query(
    "SELECT r.*, c.name, c.phone
     FROM reminders r
     JOIN customers c ON c.customer_id = r.customer_id
     WHERE r.is_sent = 0
     ORDER BY r.reminder_date ASC"
)->fetchAll();

$todayStr = date('Y-m-d');

include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/sidebar.php';
?>
<main>
    <h1>CRM - Reminders</h1>

    <?php if ($error): ?><div class="alert alert-error"><?php echo sanitize($error); ?></div><?php endif; ?>
    <?php if ($success): ?><div class="alert alert-success"><?php echo sanitize($success); ?></div><?php endif; ?>

    <div class="card">
        <form method="POST" action="" style="display:inline;">
            <input type="hidden" name="action" value="sync">
            <button type="submit">Sync Upcoming Birthdays/Anniversaries (Next 30 Days)</button>
        </form>
        <p style="margin-top:8px; font-size:12px; color:#666;">
            Ye automatically bhi chalta hai roz raat ko (<code>cron/generate-reminders.php</code>) — is button ko sirf turant refresh karna ho tab dabao.
        </p>
    </div>

    <div class="card">
        <h2>Add Custom Follow-up Reminder</h2>
        <form method="POST" action="" class="inline-form">
            <input type="hidden" name="action" value="add_custom">
            <input type="text" name="customer_id" placeholder="Customer ID" required style="width:110px;">
            <input type="date" name="reminder_date" required>
            <input type="text" name="message" placeholder="Message" required style="flex:1; min-width:220px;">
            <button type="submit">Add</button>
        </form>
    </div>

    <div class="card">
        <h2>Pending Reminders (<?php echo count($reminders); ?>)</h2>
        <table class="data-table">
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Type</th>
                    <th>Customer</th>
                    <th>Message</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($reminders)): ?>
                    <tr><td colspan="5">Koi pending reminder nahi hai.</td></tr>
                <?php else: ?>
                    <?php foreach ($reminders as $r):
                        $isOverdue = $r['reminder_date'] < $todayStr;
                        $isToday = $r['reminder_date'] === $todayStr;
                        $phone = preg_replace('/[^0-9]/', '', $r['phone']);
                        if (strlen($phone) === 10) { $phone = '91' . $phone; }
                        $waLink = 'https://wa.me/' . $phone . '?text=' . urlencode($r['message']);
                    ?>
                    <tr>
                        <td>
                            <?php echo sanitize($r['reminder_date']); ?>
                            <?php if ($isToday): ?><span class="badge-warning">Today</span><?php endif; ?>
                            <?php if ($isOverdue): ?><span class="badge-warning" style="background:#fdecea;color:#b71c1c;">Overdue</span><?php endif; ?>
                        </td>
                        <td><?php echo sanitize(ucwords(str_replace('_', ' ', $r['reminder_type']))); ?></td>
                        <td><?php echo sanitize($r['name']); ?> (<?php echo sanitize($r['phone']); ?>)</td>
                        <td><?php echo sanitize($r['message']); ?></td>
                        <td>
                            <a href="<?php echo $waLink; ?>" target="_blank" class="btn-primary">WhatsApp</a>
                            <form method="POST" action="" style="display:inline;">
                                <input type="hidden" name="action" value="mark_sent">
                                <input type="hidden" name="reminder_id" value="<?php echo (int)$r['reminder_id']; ?>">
                                <button type="submit" style="background:#888;">Mark Sent</button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</main>
<?php include __DIR__ . '/../../includes/footer.php'; ?>