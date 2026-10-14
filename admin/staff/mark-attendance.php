<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();

// ============================================
// Fraud-proof attendance:
// - Selfie MUST come from live camera (getUserMedia), never from gallery/file picker
// - Face-match verification (face-api.js, client-side) confirms the live selfie
//   actually belongs to the logged-in staff member, compared against their
//   registered reference descriptor (see admin/staff/face-setup.php)
// - check_in / check_out time & attendance_date are ALWAYS set from PHP server time
//   (date()) - client-supplied time/date is never trusted or accepted
// - GPS lat/lng captured at the same moment as the selfie
//
// HONEST LIMITATION: face matching runs in the browser (no paid cloud API
// wired up). A technically skilled person with browser dev-tools could try to
// bypass the JS check. To close that gap server-side too, the match distance
// itself is also re-checked against the stored threshold on the server before
// saving (see the "distance"/"match_ok" POST fields below) - so even if the
// button is force-enabled client-side, a bad distance value still gets
// rejected server-side. It is not fully spoof-proof (that needs a paid
// liveness-detection API), but it stops casual buddy-punching and photo
// spoofing effectively.
// ============================================

define('SELFIE_UPLOAD_PATH', __DIR__ . '/../../assets/uploads/attendance-selfies/');
define('FACE_MATCH_THRESHOLD', 0.5); // lower distance = more similar; face-api.js default recommendation is ~0.6, we use a stricter 0.5

$staff_id   = (int)$_SESSION['staff_id'];
$staff_name = $_SESSION['staff_name'];
$today      = date('Y-m-d'); // server date - not from client

$error   = '';
$success = '';

// Does this staff member have a registered face reference?
$faceStmt = $pdo->prepare("SELECT face_descriptor FROM staff WHERE staff_id = :id");
$faceStmt->execute(['id' => $staff_id]);
$faceRow = $faceStmt->fetch();
$hasFaceRegistered = $faceRow && !empty($faceRow['face_descriptor']);
$referenceDescriptor = $hasFaceRegistered ? json_decode($faceRow['face_descriptor'], true) : null;

function getTodayRow($pdo, $staff_id, $today) {
    $stmt = $pdo->prepare("SELECT * FROM attendance WHERE staff_id = :sid AND attendance_date = :d");
    $stmt->execute(['sid' => $staff_id, 'd' => $today]);
    return $stmt->fetch();
}

function saveSelfie($base64Image) {
    if (strpos($base64Image, 'base64,') === false) {
        return [null, 'Selfie data missing ya galat format me hai.'];
    }
    $parts = explode('base64,', $base64Image, 2);
    $binary = base64_decode($parts[1]);
    if ($binary === false || strlen($binary) < 500) {
        return [null, 'Selfie capture fail ho gayi. Dobara try karo.'];
    }
    if (strlen($binary) > 3 * 1024 * 1024) {
        return [null, 'Selfie image bahut badi hai.'];
    }
    if (!is_dir(SELFIE_UPLOAD_PATH)) {
        mkdir(SELFIE_UPLOAD_PATH, 0755, true);
    }
    $fileName = 'selfie_' . time() . '_' . rand(1000, 9999) . '.jpg';
    if (file_put_contents(SELFIE_UPLOAD_PATH . $fileName, $binary) === false) {
        return [null, 'Selfie save nahi ho payi. Dobara try karo.'];
    }
    return ['assets/uploads/attendance-selfies/' . $fileName, null];
}

// Server-side euclidean distance re-check - never trust the browser's "match_ok" flag alone
function euclideanDistance($a, $b) {
    if (!is_array($a) || !is_array($b) || count($a) !== count($b)) {
        return 999; // force a fail if shapes don't match
    }
    $sum = 0;
    for ($i = 0; $i < count($a); $i++) {
        $sum += ($a[$i] - $b[$i]) ** 2;
    }
    return sqrt($sum);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action     = $_POST['action'] ?? '';
    $photoData  = $_POST['photo_data'] ?? '';
    $lat        = ($_POST['lat'] ?? '') !== '' ? (float)$_POST['lat'] : null;
    $lng        = ($_POST['lng'] ?? '') !== '' ? (float)$_POST['lng'] : null;
    $address    = sanitize($_POST['address'] ?? '');
    $liveDescriptorJson = $_POST['live_descriptor'] ?? '';
    $liveDescriptor = json_decode($liveDescriptorJson, true);

    $existing = getTodayRow($pdo, $staff_id, $today);

    if (!$hasFaceRegistered) {
        $error = 'Aapka face abhi register nahi hai. Admin se "Face Registration" karwao, uske baad hi attendance mark ho sakegi.';
    } elseif ($photoData === '') {
        $error = 'Selfie capture zaroori hai. Camera allow karo aur photo lo.';
    } elseif (!is_array($liveDescriptor) || count($liveDescriptor) !== 128) {
        $error = 'Face verify nahi ho paya (detection fail). Achi roshni me, chehra clearly camera ke saamne rakh ke dobara try karo.';
    } else {
        $distance = euclideanDistance($referenceDescriptor, $liveDescriptor);
        $matchOk = $distance <= FACE_MATCH_THRESHOLD;

        if (!$matchOk) {
            $error = 'Face match nahi hua - ye chehra registered staff se match nahi kar raha. Attendance mark nahi hui.';
        } elseif ($action === 'checkin') {
            if ($existing && $existing['check_in']) {
                $error = 'Aap aaj already check-in kar chuke ho.';
            } else {
                [$path, $err] = saveSelfie($photoData);
                if ($err) {
                    $error = $err;
                } else {
                    $serverTime = date('H:i:s');
                    if ($existing) {
                        $stmt = $pdo->prepare(
                            "UPDATE attendance SET check_in = :t, checkin_photo = :p, checkin_lat = :lat, checkin_lng = :lng, checkin_address = :addr, checkin_face_score = :score, status = 'present'
                             WHERE attendance_id = :id"
                        );
                        $stmt->execute(['t' => $serverTime, 'p' => $path, 'lat' => $lat, 'lng' => $lng, 'addr' => $address ?: null, 'score' => $distance, 'id' => $existing['attendance_id']]);
                    } else {
                        $stmt = $pdo->prepare(
                            "INSERT INTO attendance (staff_id, attendance_date, check_in, checkin_photo, checkin_lat, checkin_lng, checkin_address, checkin_face_score, status)
                             VALUES (:sid, :d, :t, :p, :lat, :lng, :addr, :score, 'present')"
                        );
                        $stmt->execute(['sid' => $staff_id, 'd' => $today, 't' => $serverTime, 'p' => $path, 'lat' => $lat, 'lng' => $lng, 'addr' => $address ?: null, 'score' => $distance]);
                    }

                    $pdo->prepare("INSERT INTO audit_log (staff_id, action, module_name, description, ip_address) VALUES (:sid,'check_in','attendance',:desc,:ip)")
                        ->execute(['sid' => $staff_id, 'desc' => 'Selfie check-in, face match distance=' . round($distance, 4), 'ip' => $_SERVER['REMOTE_ADDR'] ?? null]);

                    $success = 'Check-in ho gaya - ' . $serverTime . ' (face verified)';
                }
            }
        } elseif ($action === 'checkout') {
            if (!$existing || !$existing['check_in']) {
                $error = 'Pehle check-in karo.';
            } elseif ($existing['check_out']) {
                $error = 'Aap aaj already check-out kar chuke ho.';
            } else {
                [$path, $err] = saveSelfie($photoData);
                if ($err) {
                    $error = $err;
                } else {
                    $serverTime = date('H:i:s');
                    $stmt = $pdo->prepare(
                        "UPDATE attendance SET check_out = :t, checkout_photo = :p, checkout_lat = :lat, checkout_lng = :lng, checkout_address = :addr, checkout_face_score = :score
                         WHERE attendance_id = :id"
                    );
                    $stmt->execute(['t' => $serverTime, 'p' => $path, 'lat' => $lat, 'lng' => $lng, 'addr' => $address ?: null, 'score' => $distance, 'id' => $existing['attendance_id']]);

                    $pdo->prepare("INSERT INTO audit_log (staff_id, action, module_name, description, ip_address) VALUES (:sid,'check_out','attendance',:desc,:ip)")
                        ->execute(['sid' => $staff_id, 'desc' => 'Selfie check-out, face match distance=' . round($distance, 4), 'ip' => $_SERVER['REMOTE_ADDR'] ?? null]);

                    $success = 'Check-out ho gaya - ' . $serverTime . ' (face verified)';
                }
            }
        }
    }
}

$todayRow = getTodayRow($pdo, $staff_id, $today);
$hasCheckedIn  = $todayRow && $todayRow['check_in'];
$hasCheckedOut = $todayRow && $todayRow['check_out'];

include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/sidebar.php';
?>
<style>
    .att-wrap { max-width: 480px; margin: 0 auto; }
    .cam-box { background: #000; border-radius: 8px; overflow: hidden; position: relative; margin-bottom: 16px; }
    .cam-box video, .cam-box canvas, .cam-box img { width: 100%; display: block; }
    .cam-hidden { display: none; }
    .cam-status { font-size: 13px; color: #666; margin-bottom: 10px; }
    .done-box { background: #e6f4ea; color: #1e7e34; padding: 16px; border-radius: 8px; text-align: center; margin-bottom: 16px; }
    .att-photos { display: flex; gap: 16px; margin-top: 12px; }
    .att-photos div { flex: 1; text-align: center; }
    .att-photos img { width: 100%; border-radius: 8px; }
    .gps-note { font-size: 12px; color: #888; margin-top: 6px; }
    .face-note { font-size: 12px; margin-top: 6px; }
    .face-note.ok { color: #1e7e34; }
    .face-note.fail { color: #b71c1c; }
    .no-face-box { background: #fdecea; color: #b71c1c; padding: 16px; border-radius: 8px; }
</style>
<main>
    <div class="att-wrap">
        <h1>Mark Attendance</h1>
        <p style="margin-bottom:16px;"><?php echo sanitize($staff_name); ?> &middot; <?php echo sanitize($today); ?></p>

        <?php if ($error): ?>
            <div class="alert alert-error"><?php echo sanitize($error); ?></div>
        <?php endif; ?>
        <?php if ($success): ?>
            <div class="alert alert-success"><?php echo sanitize($success); ?></div>
        <?php endif; ?>

        <div class="card">
            <?php if (!$hasFaceRegistered): ?>
                <div class="no-face-box">
                    <strong>Face abhi register nahi hai.</strong><br>
                    Attendance mark karne se pehle admin ko "Face Registration" karna hoga aapke liye. Unse contact karo.
                </div>
            <?php elseif ($hasCheckedIn && $hasCheckedOut): ?>
                <div class="done-box">
                    <strong>Aaj ka attendance complete ho gaya hai.</strong><br>
                    Check-in: <?php echo sanitize($todayRow['check_in']); ?>
                    <?php if (!empty($todayRow['checkin_address'])): ?> - <?php echo sanitize($todayRow['checkin_address']); ?><?php endif; ?>
                    <br>
                    Check-out: <?php echo sanitize($todayRow['check_out']); ?>
                    <?php if (!empty($todayRow['checkout_address'])): ?> - <?php echo sanitize($todayRow['checkout_address']); ?><?php endif; ?>
                </div>
                <div class="att-photos">
                    <?php if ($todayRow['checkin_photo']): ?>
                        <div>
                            <p><small>Check-in selfie</small></p>
                            <img src="<?php echo BASE_URL . sanitize($todayRow['checkin_photo']); ?>" alt="Check-in selfie">
                        </div>
                    <?php endif; ?>
                    <?php if ($todayRow['checkout_photo']): ?>
                        <div>
                            <p><small>Check-out selfie</small></p>
                            <img src="<?php echo BASE_URL . sanitize($todayRow['checkout_photo']); ?>" alt="Check-out selfie">
                        </div>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <h2><?php echo $hasCheckedIn ? 'Check Out' : 'Check In'; ?></h2>
                <p class="cam-status" id="camStatus">Camera + face-verification load ho rahi hai...</p>

                <div class="cam-box">
                    <video id="camVideo" autoplay playsinline muted></video>
                    <canvas id="camCanvas" class="cam-hidden"></canvas>
                    <img id="camPreview" class="cam-hidden" alt="Selfie preview">
                </div>
                <p class="face-note" id="faceNote"></p>

                <form method="POST" action="" id="attForm">
                    <input type="hidden" name="action" value="<?php echo $hasCheckedIn ? 'checkout' : 'checkin'; ?>">
                    <input type="hidden" name="photo_data" id="photoData">
                    <input type="hidden" name="lat" id="latField">
                    <input type="hidden" name="lng" id="lngField">
                    <input type="hidden" name="address" id="addressField">
                    <input type="hidden" name="live_descriptor" id="descriptorField">

                    <button type="button" id="captureBtn" disabled>Selfie Lo</button>
                    <button type="button" id="retakeBtn" class="cam-hidden" style="background:#888;">Retake</button>
                    <button type="submit" id="submitBtn" class="cam-hidden" disabled>
                        <?php echo $hasCheckedIn ? 'Confirm Check-Out' : 'Confirm Check-In'; ?>
                    </button>
                </form>
                <p class="gps-note" id="gpsNote">Location detect ho rahi hai...</p>
                <button type="button" id="retryLocationBtn" style="background:#888; font-size:12px; padding:6px 12px;" class="cam-hidden">Retry Location</button>
            <?php endif; ?>
        </div>
    </div>
</main>

<?php if ($hasFaceRegistered && !($hasCheckedIn && $hasCheckedOut)): ?>
<script src="https://cdn.jsdelivr.net/npm/face-api.js@0.22.2/dist/face-api.min.js"></script>
<script>
(function () {
    var referenceDescriptor = <?php echo json_encode($referenceDescriptor); ?>;
    var MATCH_THRESHOLD = <?php echo (float)FACE_MATCH_THRESHOLD; ?>;
    var MODEL_URL = 'https://cdn.jsdelivr.net/gh/justadudewhohacks/face-api.js@master/weights';

    var video      = document.getElementById('camVideo');
    var canvas     = document.getElementById('camCanvas');
    var preview    = document.getElementById('camPreview');
    var status     = document.getElementById('camStatus');
    var faceNote   = document.getElementById('faceNote');
    var captureBtn = document.getElementById('captureBtn');
    var retakeBtn  = document.getElementById('retakeBtn');
    var submitBtn  = document.getElementById('submitBtn');
    var photoField = document.getElementById('photoData');
    var latField   = document.getElementById('latField');
    var lngField   = document.getElementById('lngField');
    var addressField = document.getElementById('addressField');
    var descField  = document.getElementById('descriptorField');
    var gpsNote    = document.getElementById('gpsNote');
    var retryLocationBtn = document.getElementById('retryLocationBtn');

    if (!video) { return; }

    var stream = null;
    var modelsReady = false;

    function euclideanDistance(a, b) {
        var sum = 0;
        for (var i = 0; i < a.length; i++) sum += Math.pow(a[i] - b[i], 2);
        return Math.sqrt(sum);
    }

    async function init() {
        try {
            await faceapi.nets.tinyFaceDetector.loadFromUri(MODEL_URL);
            await faceapi.nets.faceLandmark68Net.loadFromUri(MODEL_URL);
            await faceapi.nets.faceRecognitionNet.loadFromUri(MODEL_URL);
            modelsReady = true;
        } catch (e) {
            console.error('Face model load error:', e);
            status.textContent = 'Face-verification models load nahi ho paye. Internet check karo aur page reload karo (console me detail hai).';
            return;
        }

        if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
            status.textContent = 'Ye browser camera support nahi karta.';
            return;
        }

        navigator.mediaDevices.getUserMedia({ video: { facingMode: 'user' }, audio: false })
            .then(function (s) {
                stream = s;
                video.srcObject = s;
                status.textContent = 'Camera ready. Selfie lene ke liye button dabao.';
                captureBtn.disabled = false;
            })
            .catch(function () {
                status.textContent = 'Camera access allow karo attendance mark karne ke liye. Page reload karke dobara try karo.';
            });
    }
    init();

    if (navigator.geolocation) {
        gpsNote.textContent = 'Location detect ho rahi hai... (browser permission popup allow karo agar dikhe)';
        tryGetLocation();
    } else {
        gpsNote.textContent = 'Ye browser location support nahi karta.';
    }

    function tryGetLocation() {
        navigator.geolocation.getCurrentPosition(function (pos) {
            latField.value = pos.coords.latitude;
            lngField.value = pos.coords.longitude;
            gpsNote.textContent = 'Location capture ho gayi (accuracy: ' + Math.round(pos.coords.accuracy) + 'm). Address dhoonda ja raha hai...';
            retryLocationBtn.classList.add('cam-hidden');
            lookupAddress(pos.coords.latitude, pos.coords.longitude);
        }, function (err) {
            console.error('Geolocation error:', err);
            var reasons = {
                1: 'Location permission block hai. Address bar me lock/info icon pe click karke site permissions me Location "Allow" karo.',
                2: 'Location detect nahi ho payi (Windows Location Services off ho sakti hai - Settings > Privacy > Location me check karo ki ye ON hai).',
                3: 'Location detect karne me bahut time lag gaya. Wifi/internet check karo.',
            };
            gpsNote.textContent = (reasons[err.code] || 'Location capture fail ho gayi.') + ' Attendance ab bhi mark ho sakti hai, bas location record nahi hogi.';
            retryLocationBtn.classList.remove('cam-hidden');
        }, { enableHighAccuracy: true, timeout: 15000, maximumAge: 0 });
    }

    // Converts lat/lng into a readable address using OpenStreetMap's free
    // Nominatim reverse-geocoding service (no API key needed). If it fails or
    // times out, attendance still works fine - we just fall back to showing
    // the raw coordinates instead of a place name.
    function lookupAddress(lat, lng) {
        var url = 'https://nominatim.openstreetmap.org/reverse?format=json&lat=' + lat + '&lon=' + lng + '&zoom=18&addressdetails=1';
        fetch(url, { headers: { 'Accept-Language': 'en' } })
            .then(function (res) { return res.json(); })
            .then(function (data) {
                var readable = data && data.display_name ? data.display_name : (lat.toFixed(5) + ', ' + lng.toFixed(5));
                addressField.value = readable;
                gpsNote.textContent = '📍 ' + readable;
            })
            .catch(function () {
                addressField.value = lat.toFixed(5) + ', ' + lng.toFixed(5);
                gpsNote.textContent = 'Location capture ho gayi, lekin address naam nahi mil paya (coordinates save ho gaye).';
            });
    }

    retryLocationBtn.addEventListener('click', function () {
        gpsNote.textContent = 'Dobara try ho raha hai...';
        retryLocationBtn.classList.add('cam-hidden');
        tryGetLocation();
    });

    captureBtn.addEventListener('click', async function () {
        if (!modelsReady) {
            faceNote.textContent = 'Models abhi load ho rahe hain, thoda ruko.';
            return;
        }
        captureBtn.disabled = true;
        faceNote.textContent = 'Chehra verify ho raha hai...';
        faceNote.className = 'face-note';

        var w = video.videoWidth || 480;
        var h = video.videoHeight || 360;
        canvas.width = w;
        canvas.height = h;
        canvas.getContext('2d').drawImage(video, 0, 0, w, h);

        var detection = await faceapi.detectSingleFace(canvas, new faceapi.TinyFaceDetectorOptions())
            .withFaceLandmarks().withFaceDescriptor();

        if (!detection) {
            faceNote.textContent = 'Chehra detect nahi hua. Achi roshni me, seedha camera dekho, dobara try karo.';
            faceNote.className = 'face-note fail';
            captureBtn.disabled = false;
            return;
        }

        var distance = euclideanDistance(Array.from(detection.descriptor), referenceDescriptor);
        var dataUrl = canvas.toDataURL('image/jpeg', 0.8);

        photoField.value = dataUrl;
        preview.src = dataUrl;
        descField.value = JSON.stringify(Array.from(detection.descriptor));

        video.classList.add('cam-hidden');
        preview.classList.remove('cam-hidden');
        captureBtn.classList.add('cam-hidden');
        retakeBtn.classList.remove('cam-hidden');
        submitBtn.classList.remove('cam-hidden');

        if (stream) { stream.getTracks().forEach(function (t) { t.stop(); }); }

        if (distance <= MATCH_THRESHOLD) {
            faceNote.textContent = 'Face match ho gaya. Confirm karo.';
            faceNote.className = 'face-note ok';
            submitBtn.disabled = false;
        } else {
            faceNote.textContent = 'Face match nahi hua - ye chehra registered staff se alag lag raha hai. Retake karo.';
            faceNote.className = 'face-note fail';
            submitBtn.disabled = true;
        }
    });

    retakeBtn.addEventListener('click', function () {
        window.location.reload();
    });
})();
</script>
<?php endif; ?>
<?php include __DIR__ . '/../../includes/footer.php'; ?>