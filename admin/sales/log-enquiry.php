<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/functions.php';
requirePermission($pdo, 'sales', 'edit');

// ============================================
// Log Enquiry (Detailed) - captures customer demand even when it doesn't
// turn into a sale, with enough detail to actually act on later:
// - Full contact + WhatsApp opt-in (feeds CRM Campaigns custom audience)
// - Structured demand details (category/color/fabric/budget/occasion) so
//   even "custom" (not-in-catalog) requests are analyzable, not just free text
// - Optional reference photo (customer showed something on their phone)
// - Follow-up scheduling with an assigned staff member
// ============================================

define('ENQUIRY_UPLOAD_PATH', __DIR__ . '/../../assets/uploads/enquiry-photos/');

$error = '';
$success = '';

$sareeRows = $pdo->query("SELECT saree_id, sku, name FROM sarees WHERE is_active = 1 ORDER BY name ASC")->fetchAll();
$customerRows = $pdo->query("SELECT customer_id, name, phone FROM customers WHERE is_active = 1 ORDER BY name ASC")->fetchAll();
$categoryRows = $pdo->query("SELECT category_id, name FROM categories ORDER BY name ASC")->fetchAll();
$staffRows = $pdo->query("SELECT staff_id, name FROM staff WHERE is_active = 1 ORDER BY name ASC")->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $customerId = !empty($_POST['customer_id']) ? (int)$_POST['customer_id'] : null;
    $walkinName = sanitize($_POST['walkin_name'] ?? '');
    $walkinPhone = sanitize($_POST['walkin_phone'] ?? '');
    $walkinEmail = sanitize($_POST['walkin_email'] ?? '');
    $walkinAddress = sanitize($_POST['walkin_address'] ?? '');
    $walkinCity = sanitize($_POST['walkin_city'] ?? '');
    $leadSource = $_POST['lead_source'] ?? 'walk_in';
    $whatsappOptIn = isset($_POST['whatsapp_opt_in']) ? 1 : 0;

    $itemType = $_POST['item_type'] ?? '';
    $sareeId = !empty($_POST['saree_id']) ? (int)$_POST['saree_id'] : null;
    $customDescription = sanitize($_POST['custom_description'] ?? '');
    $categoryId = !empty($_POST['category_id']) ? (int)$_POST['category_id'] : null;
    $preferredColor = sanitize($_POST['preferred_color'] ?? '');
    $preferredFabric = sanitize($_POST['preferred_fabric'] ?? '');
    $budgetMin = $_POST['budget_min'] !== '' ? (float)$_POST['budget_min'] : null;
    $budgetMax = $_POST['budget_max'] !== '' ? (float)$_POST['budget_max'] : null;
    $occasion = sanitize($_POST['occasion'] ?? '');
    $blouseRequired = $_POST['blouse_required'] ?? 'not_specified';
    $quantity = max(1, (int)($_POST['quantity'] ?? 1));

    $outcome = $_POST['outcome'] ?? 'pending';
    $lostReason = $_POST['lost_reason'] ?? '';

    $needsFollowup = isset($_POST['needs_followup']) ? 1 : 0;
    $followupDate = $_POST['followup_date'] ?? '';
    $assignedStaffId = !empty($_POST['assigned_staff_id']) ? (int)$_POST['assigned_staff_id'] : $_SESSION['staff_id'];

    $notes = sanitize($_POST['notes'] ?? '');

    if (!in_array($itemType, ['existing_saree', 'custom'], true)) {
        $error = 'Item type select karo.';
    } elseif ($itemType === 'existing_saree' && !$sareeId) {
        $error = 'Saree search karke select karo.';
    } elseif ($itemType === 'custom' && $customDescription === '') {
        $error = 'Customer ne kya maanga tha, wo likho.';
    } elseif (!in_array($outcome, ['purchased', 'lost', 'pending'], true)) {
        $error = 'Outcome select karo.';
    } else {
        // Optional reference photo (staff uploads a photo the customer showed them - not a live capture)
        $photoPath = null;
        if (isset($_FILES['reference_photo']) && $_FILES['reference_photo']['error'] === UPLOAD_ERR_OK) {
            $allowed = ['jpg', 'jpeg', 'png', 'webp'];
            $ext = strtolower(pathinfo($_FILES['reference_photo']['name'], PATHINFO_EXTENSION));
            if (in_array($ext, $allowed, true) && $_FILES['reference_photo']['size'] <= 3 * 1024 * 1024) {
                if (!is_dir(ENQUIRY_UPLOAD_PATH)) mkdir(ENQUIRY_UPLOAD_PATH, 0755, true);
                $fileName = 'enq_' . time() . '_' . rand(1000, 9999) . '.' . $ext;
                if (move_uploaded_file($_FILES['reference_photo']['tmp_name'], ENQUIRY_UPLOAD_PATH . $fileName)) {
                    $photoPath = 'assets/uploads/enquiry-photos/' . $fileName;
                }
            }
        }

        $stmt = $pdo->prepare(
            "INSERT INTO enquiries
                (enquiry_date, customer_id, walkin_name, walkin_phone, walkin_email, walkin_address, walkin_city, lead_source, whatsapp_opt_in,
                 item_type, saree_id, custom_description, category_id, preferred_color, preferred_fabric, budget_min, budget_max, occasion, blouse_required, quantity, reference_photo,
                 outcome, lost_reason, needs_followup, followup_date, assigned_staff_id, notes, staff_id)
             VALUES
                (CURDATE(), :cid, :wname, :wphone, :wemail, :waddr, :wcity, :lsource, :wopt,
                 :itype, :sid, :desc, :catid, :color, :fabric, :bmin, :bmax, :occasion, :blouse, :qty, :photo,
                 :outcome, :lreason, :needsf, :fdate, :astaff, :notes, :staff)"
        );
        $stmt->execute([
            'cid' => $customerId,
            'wname' => $walkinName !== '' ? $walkinName : null,
            'wphone' => $walkinPhone !== '' ? $walkinPhone : null,
            'wemail' => $walkinEmail !== '' ? $walkinEmail : null,
            'waddr' => $walkinAddress !== '' ? $walkinAddress : null,
            'wcity' => $walkinCity !== '' ? $walkinCity : null,
            'lsource' => $leadSource,
            'wopt' => $whatsappOptIn,
            'itype' => $itemType,
            'sid' => $itemType === 'existing_saree' ? $sareeId : null,
            'desc' => $itemType === 'custom' ? $customDescription : null,
            'catid' => $categoryId,
            'color' => $preferredColor !== '' ? $preferredColor : null,
            'fabric' => $preferredFabric !== '' ? $preferredFabric : null,
            'bmin' => $budgetMin,
            'bmax' => $budgetMax,
            'occasion' => $occasion !== '' ? $occasion : null,
            'blouse' => $blouseRequired,
            'qty' => $quantity,
            'photo' => $photoPath,
            'outcome' => $outcome,
            'lreason' => ($outcome === 'lost' && $lostReason !== '') ? $lostReason : null,
            'needsf' => $needsFollowup,
            'fdate' => ($needsFollowup && $followupDate !== '') ? $followupDate : null,
            'astaff' => $needsFollowup ? $assignedStaffId : null,
            'notes' => $notes !== '' ? $notes : null,
            'staff' => $_SESSION['staff_id'],
        ]);

        $success = 'Enquiry save ho gayi. Ek aur log karni hai toh neeche se karo.';
    }
}

include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/sidebar.php';
?>
<main>
    <h1>Log Customer Enquiry</h1>
    <p style="margin-bottom:16px; font-size:13px; color:#666;">
        Jab koi customer kuch maange aur kharide nahi. Jitna detail bhar sako utna behtar - <a href="enquiries.php">report</a> utni hi kaam ki banegi.
    </p>

    <?php if ($error): ?><div class="alert alert-error"><?php echo sanitize($error); ?></div><?php endif; ?>
    <?php if ($success): ?><div class="alert alert-success"><?php echo sanitize($success); ?></div><?php endif; ?>

    <div class="card">
        <form method="POST" action="" id="enquiryForm" enctype="multipart/form-data">

            <h3 style="margin-bottom:8px;">1. Customer Details</h3>
            <div class="form-grid">
                <div>
                    <label>Registered Customer (agar hai)</label>
                    <select name="customer_id">
                        <option value="">-- Walk-in / Unknown --</option>
                        <?php foreach ($customerRows as $c): ?>
                            <option value="<?php echo (int)$c['customer_id']; ?>"><?php echo sanitize($c['name']) . ' (' . sanitize($c['phone']) . ')'; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label>Lead Source</label>
                    <select name="lead_source">
                        <option value="walk_in">Walk-in (dukaan pe aaye)</option>
                        <option value="phone_call">Phone Call</option>
                        <option value="whatsapp">WhatsApp</option>
                        <option value="instagram">Instagram</option>
                        <option value="referral">Kisi ne bheja (Referral)</option>
                        <option value="other">Other</option>
                    </select>
                </div>
                <div>
                    <label>Naam</label>
                    <input type="text" name="walkin_name" placeholder="e.g. Ramesh">
                </div>
                <div>
                    <label>Phone</label>
                    <input type="text" name="walkin_phone" placeholder="10-digit mobile">
                </div>
                <div>
                    <label>Email (optional)</label>
                    <input type="email" name="walkin_email">
                </div>
                <div>
                    <label>City</label>
                    <input type="text" name="walkin_city" placeholder="e.g. Varanasi">
                </div>
                <div style="grid-column: span 2;">
                    <label>Address / Area (optional)</label>
                    <input type="text" name="walkin_address" placeholder="e.g. Mahmoorganj">
                </div>
                <div style="grid-column: span 2; display:flex; align-items:center; gap:8px;">
                    <input type="checkbox" name="whatsapp_opt_in" id="waOptIn" style="width:auto;">
                    <label for="waOptIn" style="margin:0;">WhatsApp pe offers/updates bhej sakte hain (CRM Campaigns me use hoga)</label>
                </div>
            </div>

            <h3 style="margin:20px 0 8px;">2. Customer Ne Kya Maanga?</h3>
            <div class="form-grid">
                <div>
                    <label>Type</label>
                    <select name="item_type" id="itemTypeSelect" onchange="
                        document.getElementById('existingWrap').style.display = this.value === 'existing_saree' ? 'block' : 'none';
                        document.getElementById('customWrap').style.display = this.value === 'custom' ? 'block' : 'none';
                    " required>
                        <option value="">-- Select --</option>
                        <option value="existing_saree">Hamari catalog wali saree</option>
                        <option value="custom">Aisi cheez jo hum rakhte hi nahi</option>
                    </select>
                </div>
                <div>
                    <label>Category</label>
                    <select name="category_id">
                        <option value="">-- Koi bhi --</option>
                        <?php foreach ($categoryRows as $cat): ?>
                            <option value="<?php echo (int)$cat['category_id']; ?>"><?php echo sanitize($cat['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label>Preferred Color</label>
                    <input type="text" name="preferred_color" placeholder="e.g. Green">
                </div>
                <div>
                    <label>Preferred Fabric</label>
                    <input type="text" name="preferred_fabric" placeholder="e.g. Silk">
                </div>
                <div>
                    <label>Budget Min (₹)</label>
                    <input type="number" step="1" min="0" name="budget_min">
                </div>
                <div>
                    <label>Budget Max (₹)</label>
                    <input type="number" step="1" min="0" name="budget_max">
                </div>
                <div>
                    <label>Occasion</label>
                    <input type="text" name="occasion" placeholder="e.g. Wedding, Festival, Gift">
                </div>
                <div>
                    <label>Blouse Chahiye?</label>
                    <select name="blouse_required">
                        <option value="not_specified">Pata nahi / Discuss nahi hua</option>
                        <option value="yes">Haan</option>
                        <option value="no">Nahi</option>
                    </select>
                </div>
                <div>
                    <label>Quantity</label>
                    <input type="number" min="1" name="quantity" value="1">
                </div>
            </div>

            <div id="existingWrap" style="display:none; margin-top:10px;">
                <label>Saree Search Karo</label>
                <input type="text" id="sareeSearch" placeholder="SKU ya naam se search karo...">
                <div class="saree-results" id="sareeResults"></div>
                <input type="hidden" name="saree_id" id="sareeIdField">
                <p id="selectedSareeLabel" style="font-weight:bold; margin-top:6px;"></p>
            </div>

            <div id="customWrap" style="display:none; margin-top:10px;">
                <label>Customer ne kya describe kiya</label>
                <input type="text" name="custom_description" placeholder="e.g. Peacock design wali green Kanjivaram">
            </div>

            <div style="margin-top:10px;">
                <label>Reference Photo (agar customer ne apne phone pe dikhaya)</label>
                <input type="file" name="reference_photo" accept="image/*">
            </div>

            <h3 style="margin:20px 0 8px;">3. Outcome</h3>
            <div class="form-grid">
                <div>
                    <label>Kya hua</label>
                    <select name="outcome" id="outcomeSelect" onchange="document.getElementById('lostReasonWrap').style.display = this.value === 'lost' ? 'block' : 'none';" required>
                        <option value="pending">Abhi soch rahe hain (Pending)</option>
                        <option value="lost">Bina kharide chale gaye (Lost)</option>
                        <option value="purchased">Kharid liya (Purchased)</option>
                    </select>
                </div>
                <div id="lostReasonWrap" style="display:none;">
                    <label>Kyun nahi kharida</label>
                    <select name="lost_reason">
                        <option value="out_of_stock">Stock khatam tha</option>
                        <option value="not_carried">Hum ye design/item rakhte hi nahi</option>
                        <option value="too_expensive">Price zyada laga customer ko</option>
                        <option value="other">Koi aur wajah</option>
                    </select>
                </div>
            </div>

            <h3 style="margin:20px 0 8px;">4. Follow-up</h3>
            <div class="form-grid">
                <div style="display:flex; align-items:center; gap:8px;">
                    <input type="checkbox" name="needs_followup" id="needsFollowup" style="width:auto;" onchange="document.getElementById('followupWrap').style.display = this.checked ? 'flex' : 'none';">
                    <label for="needsFollowup" style="margin:0;">Follow-up karna hai (jaise stock aaye toh batana)</label>
                </div>
            </div>
            <div id="followupWrap" style="display:none; gap:10px; margin-top:10px;" class="form-grid">
                <div>
                    <label>Follow-up Date</label>
                    <input type="date" name="followup_date">
                </div>
                <div>
                    <label>Kisko assign karna hai</label>
                    <select name="assigned_staff_id">
                        <?php foreach ($staffRows as $s): ?>
                            <option value="<?php echo (int)$s['staff_id']; ?>" <?php echo (int)$s['staff_id'] === (int)$_SESSION['staff_id'] ? 'selected' : ''; ?>>
                                <?php echo sanitize($s['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div style="margin-top:16px;">
                <label>Notes (optional)</label>
                <input type="text" name="notes" placeholder="koi aur detail">
            </div>

            <button type="submit" style="margin-top:16px;">Save Enquiry</button>
        </form>
    </div>
</main>
<script>
const sarees = <?php echo json_encode($sareeRows); ?>;
const searchInput = document.getElementById('sareeSearch');
const resultsBox = document.getElementById('sareeResults');
const sareeIdField = document.getElementById('sareeIdField');
const selectedLabel = document.getElementById('selectedSareeLabel');

if (searchInput) {
    searchInput.addEventListener('input', function () {
        const q = this.value.trim().toLowerCase();
        resultsBox.innerHTML = '';
        if (q.length < 1) return;
        const matches = sarees.filter(s => s.name.toLowerCase().includes(q) || s.sku.toLowerCase().includes(q)).slice(0, 15);
        matches.forEach(s => {
            const div = document.createElement('div');
            div.className = 'saree-result-item';
            div.textContent = s.sku + ' - ' + s.name;
            div.addEventListener('click', function () {
                sareeIdField.value = s.saree_id;
                selectedLabel.textContent = 'Selected: ' + s.sku + ' - ' + s.name;
                searchInput.value = '';
                resultsBox.innerHTML = '';
            });
            resultsBox.appendChild(div);
        });
    });
}
</script>
<?php include __DIR__ . '/../../includes/footer.php'; ?>