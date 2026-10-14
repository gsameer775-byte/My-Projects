<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/campaign_helpers.php';
requirePermission($pdo, 'crm', 'view');

// ============================================
// CRM - Campaign Send Queue
// Since no paid WhatsApp Business API / SMS gateway is configured, this page
// gives a guided "click to send" flow that still works for real: it builds
// the exact recipient list once (cached in campaign_recipients so refreshing
// doesn't rebuild it), personalizes each message, and gives one-click
// wa.me / mailto links per recipient with progress tracking + audit log.
// ============================================

$campaignId = (int)($_GET['id'] ?? 0);
$stmt = $pdo->prepare("SELECT * FROM campaigns WHERE campaign_id = :id");
$stmt->execute(['id' => $campaignId]);
$campaign = $stmt->fetch();

if (!$campaign) {
    redirect('admin/crm/campaigns.php');
}

$error = '';
$success = '';

// Build recipient list once (only if not already built for this campaign)
$existingCount = $pdo->prepare("SELECT COUNT(*) FROM campaign_recipients WHERE campaign_id = :id");
$existingCount->execute(['id' => $campaignId]);
if ((int)$existingCount->fetchColumn() === 0) {
    $audienceCustomers = resolveCampaignAudience($pdo, $campaign);
    if (!empty($audienceCustomers)) {
        $insert = $pdo->prepare("INSERT INTO campaign_recipients (campaign_id, customer_id, delivery_status) VALUES (:cid, :custid, 'pending')");
        foreach ($audienceCustomers as $cust) {
            $insert->execute(['cid' => $campaignId, 'custid' => $cust['customer_id']]);
        }
    }
    if ($campaign['status'] === 'draft' || $campaign['status'] === 'scheduled') {
        $pdo->prepare("UPDATE campaigns SET status = 'scheduled' WHERE campaign_id = :id")->execute(['id' => $campaignId]);
        $campaign['status'] = 'scheduled';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requirePermission($pdo, 'crm', 'edit');
    $action = $_POST['action'] ?? '';
    $recipientId = (int)($_POST['recipient_id'] ?? 0);

    if (in_array($action, ['mark_sent', 'mark_failed'], true) && $recipientId) {
        $status = $action === 'mark_sent' ? 'sent' : 'failed';
        $pdo->prepare("UPDATE campaign_recipients SET delivery_status = :s, sent_at = NOW() WHERE recipient_id = :id AND campaign_id = :cid")
            ->execute(['s' => $status, 'id' => $recipientId, 'cid' => $campaignId]);

        // if everyone is resolved (sent or failed), close out the campaign
        $pending = $pdo->prepare("SELECT COUNT(*) FROM campaign_recipients WHERE campaign_id = :id AND delivery_status = 'pending'");
        $pending->execute(['id' => $campaignId]);
        if ((int)$pending->fetchColumn() === 0) {
            $pdo->prepare("UPDATE campaigns SET status = 'sent' WHERE campaign_id = :id")->execute(['id' => $campaignId]);
            $campaign['status'] = 'sent';
        }
        $success = 'Status update ho gaya.';
    }
}

$recipients = $pdo->prepare(
    "SELECT cr.recipient_id, cr.delivery_status, c.customer_id, c.name, c.phone, c.email, c.city, c.loyalty_points
     FROM campaign_recipients cr
     JOIN customers c ON c.customer_id = cr.customer_id
     WHERE cr.campaign_id = :id
     ORDER BY FIELD(cr.delivery_status, 'pending', 'failed', 'sent'), c.name"
);
$recipients->execute(['id' => $campaignId]);
$recipients = $recipients->fetchAll();

$totalCount  = count($recipients);
$sentCount   = count(array_filter($recipients, fn($r) => $r['delivery_status'] === 'sent'));
$failedCount = count(array_filter($recipients, fn($r) => $r['delivery_status'] === 'failed'));

include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/sidebar.php';
?>
<main>
    <h1>Campaign: <?php echo sanitize($campaign['title']); ?></h1>
    <p style="margin-bottom:16px;">
        <?php echo sanitize(ucfirst($campaign['channel'])); ?> &middot;
        <?php echo sanitize(ucwords(str_replace('_', ' ', $campaign['target_audience']))); ?> &middot;
        Status: <?php echo sanitize(ucfirst($campaign['status'])); ?>
    </p>

    <?php if ($success): ?><div class="alert alert-success"><?php echo sanitize($success); ?></div><?php endif; ?>

    <div class="card">
        <h2>Progress</h2>
        <p><?php echo $sentCount; ?> sent, <?php echo $failedCount; ?> failed, <?php echo $totalCount - $sentCount - $failedCount; ?> pending out of <?php echo $totalCount; ?> total.</p>
        <div class="progress-bar-outer">
            <div class="progress-bar-inner" style="width: <?php echo $totalCount ? round(($sentCount / $totalCount) * 100) : 0; ?>%;"></div>
        </div>
    </div>

    <div class="card">
        <h2>Send Queue</h2>
        <?php if (empty($recipients)): ?>
            <p>Is audience me koi customer nahi mila (custom list check karo, ya audience khaali hai).</p>
        <?php else: ?>
        <table class="data-table">
            <thead>
                <tr><th>Customer</th><th>Contact</th><th>Message Preview</th><th>Status</th><th>Action</th></tr>
            </thead>
            <tbody>
                <?php foreach ($recipients as $r):
                    $personalized = personalizeMessage($campaign['message_content'], $r);
                    $link = buildSendLink($campaign['channel'], $r, $personalized);
                ?>
                <tr>
                    <td><?php echo sanitize($r['name']); ?></td>
                    <td>
                        <?php if ($campaign['channel'] === 'email'): ?>
                            <?php echo $r['email'] ? sanitize($r['email']) : '<em>no email on file</em>'; ?>
                        <?php else: ?>
                            <?php echo sanitize($r['phone']); ?>
                        <?php endif; ?>
                    </td>
                    <td style="max-width:260px; font-size:12px; color:#555;">
                        <?php echo sanitize($personalized); ?>
                    </td>
                    <td><?php echo sanitize(ucfirst($r['delivery_status'])); ?></td>
                    <td>
                        <?php if ($r['delivery_status'] === 'pending'): ?>
                            <?php if ($campaign['channel'] === 'sms'): ?>
                                <button type="button" class="copy-btn" data-msg="<?php echo sanitize($r['phone'] . ' - ' . $personalized); ?>">Copy</button>
                            <?php elseif ($link): ?>
                                <a href="<?php echo $link; ?>" target="_blank" class="btn-primary">Send</a>
                            <?php else: ?>
                                <em>no contact</em>
                            <?php endif; ?>
                            <form method="POST" action="" style="display:inline;">
                                <input type="hidden" name="action" value="mark_sent">
                                <input type="hidden" name="recipient_id" value="<?php echo (int)$r['recipient_id']; ?>">
                                <button type="submit" style="background:#1e7e34;">Sent</button>
                            </form>
                            <form method="POST" action="" style="display:inline;">
                                <input type="hidden" name="action" value="mark_failed">
                                <input type="hidden" name="recipient_id" value="<?php echo (int)$r['recipient_id']; ?>">
                                <button type="submit" style="background:#b71c1c;">Failed</button>
                            </form>
                        <?php else: ?>
                            -
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
    <a href="campaigns.php">Back to Campaigns</a>
</main>
<style>
.progress-bar-outer { background: #eee; border-radius: 6px; height: 14px; overflow: hidden; margin-top: 8px; }
.progress-bar-inner { background: #1e7e34; height: 100%; transition: width 0.3s; }
</style>
<script>
document.querySelectorAll('.copy-btn').forEach(function (btn) {
    btn.addEventListener('click', function () {
        navigator.clipboard.writeText(btn.dataset.msg).then(function () {
            btn.textContent = 'Copied!';
            setTimeout(function () { btn.textContent = 'Copy'; }, 1500);
        });
    });
});
</script>
<?php include __DIR__ . '/../../includes/footer.php'; ?>