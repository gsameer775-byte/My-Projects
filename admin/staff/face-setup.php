<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();
requirePermission($pdo, 'staff', 'edit');

// ============================================
// Staff - Face Registration (for attendance face-matching)
// - Only staff with 'staff edit' permission can register/re-register a face
//   (a staff member should NOT be able to freely re-register their own face -
//   that would defeat the whole point of verification)
// - Captures 3 live shots (not gallery uploads) and averages their face
//   descriptors for a more reliable reference fingerprint
// - Stores the 128-number descriptor (JSON) - NOT the raw photo - as the
//   thing attendance check-ins get compared against. The photo is kept too,
//   only so admin can visually confirm whose face is on file.
// ============================================

define('FACE_UPLOAD_PATH', __DIR__ . '/../../assets/uploads/staff-faces/');

$staffId = (int)($_GET['staff_id'] ?? 0);
$stmt = $pdo->prepare("SELECT staff_id, name, face_reference_photo, face_registered_at FROM staff WHERE staff_id = :id");
$stmt->execute(['id' => $staffId]);
$staff = $stmt->fetch();

if (!$staff) {
    redirect('admin/staff/list.php');
}

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_face') {
    $descriptorJson = $_POST['descriptor'] ?? '';
    $photoData = $_POST['photo_data'] ?? '';

    $descriptor = json_decode($descriptorJson, true);

    if (!is_array($descriptor) || count($descriptor) !== 128) {
        $error = 'Face capture theek se process nahi hua. Dobara try karo (achi roshni me, chehra clearly camera ke saamne rakho).';
    } elseif (strpos($photoData, 'base64,') === false) {
        $error = 'Reference photo missing hai. Dobara try karo.';
    } else {
        $binary = base64_decode(explode('base64,', $photoData, 2)[1]);
        if (!is_dir(FACE_UPLOAD_PATH)) {
            mkdir(FACE_UPLOAD_PATH, 0755, true);
        }
        $fileName = 'face_' . $staffId . '_' . time() . '.jpg';
        file_put_contents(FACE_UPLOAD_PATH . $fileName, $binary);
        $photoPath = 'assets/uploads/staff-faces/' . $fileName;

        $pdo->prepare(
            "UPDATE staff SET face_reference_photo = :photo, face_descriptor = :desc, face_registered_at = NOW() WHERE staff_id = :id"
        )->execute(['photo' => $photoPath, 'desc' => json_encode($descriptor), 'id' => $staffId]);

        $pdo->prepare("INSERT INTO audit_log (staff_id, action, module_name, record_id, description, ip_address) VALUES (:sid,'update','staff_face',:rid,:desc,:ip)")
            ->execute(['sid' => $_SESSION['staff_id'], 'rid' => $staffId, 'desc' => 'Face reference (re)registered for ' . $staff['name'], 'ip' => $_SERVER['REMOTE_ADDR'] ?? null]);

        $success = 'Face register ho gaya. Ab ' . sanitize($staff['name']) . ' selfie attendance mark kar sakega.';
        $staff['face_reference_photo'] = $photoPath;
        $staff['face_registered_at'] = date('Y-m-d H:i:s');
    }
}

include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/sidebar.php';
?>
<style>
    .face-wrap { max-width: 480px; margin: 0 auto; }
    .cam-box { background: #000; border-radius: 8px; overflow: hidden; position: relative; margin-bottom: 16px; }
    .cam-box video, .cam-box canvas { width: 100%; display: block; }
    .cam-hidden { display: none; }
    .cam-status { font-size: 13px; color: #666; margin-bottom: 10px; }
    .capture-dots { display: flex; gap: 6px; justify-content: center; margin: 10px 0; }
    .capture-dots span { width: 12px; height: 12px; border-radius: 50%; background: #ddd; display: inline-block; }
    .capture-dots span.done { background: #1e7e34; }
    .current-face { text-align: center; margin-bottom: 16px; }
    .current-face img { width: 120px; height: 120px; object-fit: cover; border-radius: 50%; border: 3px solid #3b0a0a; }
</style>
<main>
    <div class="face-wrap">
        <h1>Face Registration</h1>
        <p style="margin-bottom:16px;">Staff: <strong><?php echo sanitize($staff['name']); ?></strong></p>

        <?php if ($error): ?><div class="alert alert-error"><?php echo sanitize($error); ?></div><?php endif; ?>
        <?php if ($success): ?><div class="alert alert-success"><?php echo sanitize($success); ?></div><?php endif; ?>

        <?php if ($staff['face_reference_photo']): ?>
            <div class="current-face">
                <img src="<?php echo BASE_URL . sanitize($staff['face_reference_photo']); ?>" alt="Current reference face">
                <p><small>Currently registered <?php echo $staff['face_registered_at'] ? '(' . sanitize($staff['face_registered_at']) . ')' : ''; ?></small></p>
            </div>
        <?php endif; ?>

        <div class="card">
            <h2><?php echo $staff['face_reference_photo'] ? 'Re-register Face' : 'Register Face'; ?></h2>
            <p style="font-size:13px; color:#666; margin-bottom:10px;">
                3 photos liye jayenge (achi roshni me, seedha camera ki taraf dekhte hue) taaki matching zyada reliable ho.
            </p>

            <p class="cam-status" id="camStatus">Camera + face-detection models load ho rahe hain...</p>
            <div class="capture-dots">
                <span id="dot1"></span><span id="dot2"></span><span id="dot3"></span>
            </div>

            <div class="cam-box">
                <video id="camVideo" autoplay playsinline muted></video>
                <canvas id="overlayCanvas" style="position:absolute; top:0; left:0;"></canvas>
            </div>

            <form method="POST" action="" id="faceForm">
                <input type="hidden" name="action" value="save_face">
                <input type="hidden" name="descriptor" id="descriptorField">
                <input type="hidden" name="photo_data" id="photoField">
                <button type="button" id="captureBtn" disabled>Capture Photo</button>
                <button type="submit" id="submitBtn" class="cam-hidden">Save Face Reference</button>
            </form>
        </div>
        <a href="add.php?id=<?php echo (int)$staffId; ?>">Back to Staff Profile</a>
    </div>
</main>

<script src="https://cdn.jsdelivr.net/npm/face-api.js@0.22.2/dist/face-api.min.js"></script>
<script>
(function () {
    var video = document.getElementById('camVideo');
    var overlay = document.getElementById('overlayCanvas');
    var status = document.getElementById('camStatus');
    var captureBtn = document.getElementById('captureBtn');
    var submitBtn = document.getElementById('submitBtn');
    var descriptorField = document.getElementById('descriptorField');
    var photoField = document.getElementById('photoField');
    var dots = [document.getElementById('dot1'), document.getElementById('dot2'), document.getElementById('dot3')];

    var MODEL_URL = 'https://cdn.jsdelivr.net/gh/justadudewhohacks/face-api.js@master/weights';
    var captures = []; // { descriptor: Float32Array, dataUrl }
    var stream = null;

    async function init() {
        try {
            await faceapi.nets.tinyFaceDetector.loadFromUri(MODEL_URL);
            await faceapi.nets.faceLandmark68Net.loadFromUri(MODEL_URL);
            await faceapi.nets.faceRecognitionNet.loadFromUri(MODEL_URL);
        } catch (e) {
            console.error('Face model load error:', e);
            status.textContent = 'Face-detection models load nahi ho paye. Internet connection check karo (console me detail hai).';
            return;
        }

        if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
            status.textContent = 'Ye browser camera support nahi karta.';
            return;
        }

        navigator.mediaDevices.getUserMedia({ video: { facingMode: 'user' }, audio: false }).then(function (s) {
            stream = s;
            video.srcObject = s;
            status.textContent = 'Ready. Chehra camera ke saamne rakho aur "Capture Photo" dabao (3 baar).';
            captureBtn.disabled = false;
        }).catch(function () {
            status.textContent = 'Camera access allow karo.';
        });
    }

    captureBtn.addEventListener('click', async function () {
        captureBtn.disabled = true;
        status.textContent = 'Chehra detect ho raha hai...';

        var detection = await faceapi.detectSingleFace(video, new faceapi.TinyFaceDetectorOptions())
            .withFaceLandmarks().withFaceDescriptor();

        if (!detection) {
            status.textContent = 'Chehra detect nahi hua. Achi roshni me, seedha camera dekho, dobara try karo.';
            captureBtn.disabled = false;
            return;
        }

        var canvas = document.createElement('canvas');
        canvas.width = video.videoWidth;
        canvas.height = video.videoHeight;
        canvas.getContext('2d').drawImage(video, 0, 0, canvas.width, canvas.height);

        captures.push({ descriptor: detection.descriptor, dataUrl: canvas.toDataURL('image/jpeg', 0.85) });
        dots[captures.length - 1].classList.add('done');

        if (captures.length < 3) {
            status.textContent = captures.length + '/3 ho gaye. Thoda angle badal ke phir capture karo.';
            captureBtn.disabled = false;
        } else {
            status.textContent = 'Sab 3 photos capture ho gayi. "Save" dabao.';
            if (stream) stream.getTracks().forEach(function (t) { t.stop(); });

            // Average the 3 descriptors for a more stable reference fingerprint
            var avg = new Array(128).fill(0);
            captures.forEach(function (c) {
                for (var i = 0; i < 128; i++) avg[i] += c.descriptor[i] / captures.length;
            });

            descriptorField.value = JSON.stringify(avg);
            photoField.value = captures[0].dataUrl;
            captureBtn.classList.add('cam-hidden');
            submitBtn.classList.remove('cam-hidden');
        }
    });

    init();
})();
</script>
<?php include __DIR__ . '/../../includes/footer.php'; ?>