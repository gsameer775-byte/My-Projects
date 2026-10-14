<?php
// ============================================
// includes/campaign_helpers.php
// Shared between admin/crm/campaigns.php and admin/crm/campaign-send.php
// ============================================

/**
 * Resolve the list of customers that belong to a campaign's target audience.
 * Returns an array of customer rows (customer_id, name, phone, email, city, loyalty_points).
 */
function resolveCampaignAudience($pdo, $campaign) {
    $audience = $campaign['target_audience'];
    $baseCols = "customer_id, name, phone, email, city, loyalty_points";

    if ($audience === 'all') {
        return $pdo->query("SELECT $baseCols FROM customers WHERE is_active = 1")->fetchAll();
    }

    if ($audience === 'loyalty_members') {
        return $pdo->query("SELECT $baseCols FROM customers WHERE is_active = 1 AND loyalty_points > 0")->fetchAll();
    }

    if ($audience === 'credit_customers') {
        $sql = "SELECT c.$baseCols FROM customers c
                JOIN (
                    SELECT cl.customer_id, cl.balance_after,
                           ROW_NUMBER() OVER (PARTITION BY cl.customer_id ORDER BY cl.created_at DESC) rn
                    FROM credit_ledger cl
                ) latest ON latest.customer_id = c.customer_id AND latest.rn = 1
                WHERE latest.balance_after > 0 AND c.is_active = 1";
        return $pdo->query($sql)->fetchAll();
    }

    if ($audience === 'custom') {
        $idsCsv = getSystemSetting($pdo, 'campaign_custom_ids_' . $campaign['campaign_id'], '');
        $ids = array_filter(array_map('intval', explode(',', $idsCsv)));
        if (empty($ids)) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $pdo->prepare("SELECT $baseCols FROM customers WHERE customer_id IN ($placeholders)");
        $stmt->execute(array_values($ids));
        return $stmt->fetchAll();
    }

    return [];
}

/**
 * Replace {name}, {points}, {city}, {phone} placeholders with a specific customer's data.
 */
function personalizeMessage($template, $customer) {
    return str_replace(
        ['{name}', '{points}', '{city}', '{phone}'],
        [$customer['name'], (int)$customer['loyalty_points'], ($customer['city'] ?: ''), $customer['phone']],
        $template
    );
}

/**
 * Build the send link for a given channel + personalized message + customer.
 * Returns null when the channel has no usable contact info (e.g. no email on file).
 */
function buildSendLink($channel, $customer, $message) {
    if ($channel === 'whatsapp') {
        $phone = preg_replace('/[^0-9]/', '', $customer['phone']);
        if (strlen($phone) === 10) { $phone = '91' . $phone; }
        return 'https://wa.me/' . $phone . '?text=' . urlencode($message);
    }

    if ($channel === 'email') {
        if (empty($customer['email'])) { return null; }
        return 'mailto:' . rawurlencode($customer['email']) . '?subject=' . rawurlencode('Hikayat Banaras') . '&body=' . rawurlencode($message);
    }

    // sms has no gateway configured - handled as copy-to-clipboard in the UI instead of a link
    return null;
}