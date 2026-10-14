<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/campaign_helpers.php';
requirePermission($pdo, 'crm', 'view');

// ============================================
// CRM - Campaigns
// - Create WhatsApp / SMS / Email campaigns targeting: All, Loyalty Members,
//   Credit (udhaar) Customers, or a Custom list of Customer IDs
// - Message supports {name}, {points}, {city}, {phone} placeholders,
//   personalized per-recipient at send time
// - Actual sending happens on campaign-send.php (since there's no paid
//   WhatsApp/SMS API wired up, it's a guided click-to-send queue using
//   wa.me / mailto links - genuinely usable without any subscription)
// ============================================

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create') {
    requirePermission($pdo, 'crm', 'edit');
    $title      = trim($_POST['title'] ?? '');
    $channel    = $_POST['channel'] ?? '';
    $message    = trim($_POST['message_content'] ?? '');
    $audience   = $_POST['target_audience'] ?? 'all';
    $customIds  = trim($_POST['custom_ids'] ?? '');
    $scheduleAt = trim($_POST['scheduled_at'] ?? '');

    if ($title === '' || $message === '') {
        $error = 'Title aur message dono zaroori hain.';
    } elseif (!in_array($channel, ['whatsapp', 'sms', 'email'], true)) {
        $error = 'Channel select karo.';
    } elseif (!in_array($audience, ['all', 'loyalty_members', 'credit_customers', 'custom'], true)) {
        $error = 'Audience select karo.';
    } elseif ($audience === 'custom' && $customIds === '') {
        $error = 'Custom audience ke liye Customer IDs comma-separated do.';
    } else {
        $status = $scheduleAt !== '' ? 'scheduled' : 'draft';
        $stmt = $pdo->prepare(
            "INSERT INTO campaigns (title, channel, message_content, target_audience, scheduled_at, status, created_by)
             VALUES (:t, :ch, :msg, :aud, :sch, :st, :by)"
        );
        $stmt->execute([
            't' => $title, 'ch' => $channel, 'msg' => $message, 'aud' => $audience,
            'sch' => $scheduleAt !== '' ? $scheduleAt : null, 'st' => $status, 'by' => $_SESSION['staff_id'],
        ]);
        $campaignId = $pdo->lastInsertId();

        // stash custom id list against the campaign so campaign-send.php can build the recipient list
        if ($audience === 'custom') {
            setSystemSetting($pdo, 'campaign_custom_ids_' . $campaignId, $customIds, 'Custom recipient IDs for campaign #' . $campaignId);
        }

        $log = $pdo->prepare("INSERT INTO audit_log (staff_id, action, module_name, record_id, description, ip_address) VALUES (:sid,'create','campaigns',:rid,:desc,:ip)");
        $log->execute(['sid' => $_SESSION['staff_id'], 'rid' => $campaignId, 'desc' => 'Campaign created: ' . $title, 'ip' => $_SERVER['REMOTE_ADDR'] ?? null]);

        redirect('admin/crm/campaign-send.php?id=' . $campaignId);
    }
}

if (($_GET['action'] ?? '') === 'cancel' && !empty($_GET['id'])) {
    requirePermission($pdo, 'crm', 'edit');
    $pdo->prepare("UPDATE campaigns SET status = 'cancelled' WHERE campaign_id = :id AND status IN ('draft','scheduled')")
        ->execute(['id' => (int)$_GET['id']]);
    redirect('admin/crm/campaigns.php');
}

$campaigns = $pdo->query(
    "SELECT c.*, s.name AS created_by_name,
            (SELECT COUNT(*) FROM campaign_recipients cr WHERE cr.campaign_id = c.campaign_id) AS total_recipients,
            (SELECT COUNT(*) FROM campaign_recipients cr WHERE cr.campaign_id = c.campaign_id AND cr.delivery_status = 'sent') AS sent_count
     FROM campaigns c
     LEFT JOIN staff s ON s.staff_id = c.created_by
     ORDER BY c.created_at DESC"
)->fetchAll();

$audienceCounts = [
    'all'              => (int)$pdo->query("SELECT COUNT(*) FROM customers WHERE is_active = 1")->fetchColumn(),
    'loyalty_members'  => (int)$pdo->query("SELECT COUNT(*) FROM customers WHERE is_active = 1 AND loyalty_points > 0")->fetchColumn(),
    'credit_customers' => (int)$pdo->query(
        "SELECT COUNT(*) FROM (
            SELECT cl.customer_id, cl.balance_after,
                   ROW_NUMBER() OVER (PARTITION BY cl.customer_id ORDER BY cl.created_at DESC) rn
            FROM credit_ledger cl
         ) t WHERE t.rn = 1 AND t.balance_after > 0"
    )->fetchColumn(),
];

include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/sidebar.php';
?>
<main>
    <h1>CRM - Campaigns</h1>

    <?php if ($error): ?><div class="alert alert-error"><?php echo sanitize($error); ?></div><?php endif; ?>
    <?php if ($success): ?><div class="alert alert-success"><?php echo sanitize($success); ?></div><?php endif; ?>

    <div class="card">
        <h2>New Campaign</h2>
        <form method="POST" action="" class="form-grid">
            <input type="hidden" name="action" value="create">
            <div>
                <label>Title</label>
                <input type="text" name="title" required placeholder="e.g. Diwali Offer 2026">
            </div>
            <div>
                <label>Channel</label>
                <select name="channel" required>
                    <option value="whatsapp">WhatsApp</option>
                    <option value="sms">SMS</option>
                    <option value="email">Email</option>
                </select>
            </div>
            <div>
                <label>Target Audience</label>
                <select name="target_audience" id="audienceSelect" required onchange="document.getElementById('customIdsWrap').style.display = this.value === 'custom' ? 'block' : 'none';">
                    <option value="all">All Active Customers (<?php echo $audienceCounts['all']; ?>)</option>
                    <option value="loyalty_members">Loyalty Members (<?php echo $audienceCounts['loyalty_members']; ?>)</option>
                    <option value="credit_customers">Credit / Udhaar Customers (<?php echo $audienceCounts['credit_customers']; ?>)</option>
                    <option value="custom">Custom List</option>
                </select>
            </div>
            <div>
                <label>Schedule For (optional)</label>
                <input type="datetime-local" name="scheduled_at">
                <small>Khaali chhodo toh draft rahega, turant "Build Recipient List" se bhej sakte ho.</small>
            </div>
            <div id="customIdsWrap" style="display:none; grid-column: span 2;">
                <label>Custom Customer IDs (comma-separated)</label>
                <input type="text" name="custom_ids" placeholder="e.g. 4,17,22,31">
            </div>
            <div style="grid-column: span 2;">
                <label>Message</label>
                <textarea name="message_content" rows="4" required placeholder="Namaste {name}! Aapke {points} loyalty points ka intezaar hai. Hikayat Banaras me aaj special offer chal raha hai."></textarea>
                <small>Placeholders use kar sakte ho: <code>{name}</code>, <code>{points}</code>, <code>{city}</code>, <code>{phone}</code> — har customer ke liye automatically fill honge.</small>
            </div>
            <div style="grid-column: span 2;">
                <button type="submit">Create & Build Recipient List</button>
            </div>
        </form>
    </div>

    <div class="card">
        <h2>All Campaigns</h2>
        <table class="data-table">
            <thead>
                <tr><th>Title</th><th>Channel</th><th>Audience</th><th>Status</th><th>Progress</th><th>Created</th><th>Action</th></tr>
            </thead>
            <tbody>
                <?php if (empty($campaigns)): ?>
                    <tr><td colspan="7">Koi campaign nahi banaya abhi tak.</td></tr>
                <?php else: ?>
                    <?php foreach ($campaigns as $c): ?>
                    <tr>
                        <td><?php echo sanitize($c['title']); ?></td>
                        <td><?php echo sanitize(ucfirst($c['channel'])); ?></td>
                        <td><?php echo sanitize(ucwords(str_replace('_', ' ', $c['target_audience']))); ?></td>
                        <td><?php echo sanitize(ucfirst($c['status'])); ?></td>
                        <td>
                            <?php if ($c['total_recipients'] > 0): ?>
                                <?php echo (int)$c['sent_count']; ?> / <?php echo (int)$c['total_recipients']; ?> sent
                            <?php else: ?>
                                -
                            <?php endif; ?>
                        </td>
                        <td><?php echo sanitize($c['created_at']); ?></td>
                        <td>
                            <a href="campaign-send.php?id=<?php echo (int)$c['campaign_id']; ?>">Open Queue</a>
                            <?php if (in_array($c['status'], ['draft', 'scheduled'], true)): ?>
                                | <a href="campaigns.php?action=cancel&id=<?php echo (int)$c['campaign_id']; ?>" onclick="return confirm('Ye campaign cancel karna hai?');">Cancel</a>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</main>
<?php include __DIR__ . '/../../includes/footer.php'; ?>