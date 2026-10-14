<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/functions.php';
requirePermission($pdo, 'staff', 'view');

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'mark_attendance') {
    requirePermission($pdo, 'staff', 'edit');
    $attendance_date = $_POST['attendance_date'] ?? date('Y-m-d');
    $rows = $_POST['att'] ?? [];

    if (empty($rows)) {
        $error = 'Koi attendance data nahi mila.';
    } else {
        foreach ($rows as $staffId => $data) {
            $staffId   = (int)$staffId;
            $status    = in_array($data['status'] ?? '', ['present', 'absent', 'half_day', 'leave']) ? $data['status'] : 'present';
            $check_in  = $data['check_in'] !== '' ? $data['check_in'] : null;
            $check_out = $data['check_out'] !== '' ? $data['check_out'] : null;
            $notes     = sanitize($data['notes'] ?? '');

            $existing = $pdo->prepare(
                "SELECT attendance_id FROM attendance WHERE staff_id = :sid AND attendance_date = :d"
            );
            $existing->execute(['sid' => $staffId, 'd' => $attendance_date]);
            $row = $existing->fetch();

            if ($row) {
                $upd = $pdo->prepare(
                    "UPDATE attendance SET status = :status, check_in = :in, check_out = :out, notes = :notes
                     WHERE attendance_id = :id"
                );
                $upd->execute([
                    'status' => $status, 'in' => $check_in, 'out' => $check_out,
                    'notes' => $notes ?: null, 'id' => $row['attendance_id'],
                ]);
            } else {
                $ins = $pdo->prepare(
                    "INSERT INTO attendance (staff_id, attendance_date, check_in, check_out, status, notes)
                     VALUES (:sid, :d, :in, :out, :status, :notes)"
                );
                $ins->execute([
                    'sid' => $staffId, 'd' => $attendance_date, 'in' => $check_in,
                    'out' => $check_out, 'status' => $status, 'notes' => $notes ?: null,
                ]);
            }
        }
        $success = 'Attendance save ho gayi.';
    }
}

$markDate = $_GET['date'] ?? date('Y-m-d');

// Active staff with today's (or selected date's) existing attendance, if any
$staffStmt = $pdo->prepare(
    "SELECT s.staff_id, s.name,
            a.status, a.check_in, a.check_out, a.notes
     FROM staff s
     LEFT JOIN attendance a ON a.staff_id = s.staff_id AND a.attendance_date = :d
     WHERE s.is_active = 1
     ORDER BY s.name"
);
$staffStmt->execute(['d' => $markDate]);
$staffForDate = $staffStmt->fetchAll();

// History filters
$filterStaffId = (int)($_GET['staff_id'] ?? 0);
$fromDate = $_GET['from'] ?? date('Y-m-01');
$toDate   = $_GET['to'] ?? date('Y-m-d');

$histSql = "SELECT a.attendance_date, a.status, a.check_in, a.check_out, a.notes, s.name,
                   a.checkin_photo, a.checkin_lat, a.checkin_lng, a.checkin_address, a.checkin_face_score,
                   a.checkout_photo, a.checkout_lat, a.checkout_lng, a.checkout_address, a.checkout_face_score
            FROM attendance a
            JOIN staff s ON s.staff_id = a.staff_id
            WHERE a.attendance_date BETWEEN :from AND :to";
$histParams = ['from' => $fromDate, 'to' => $toDate];
if ($filterStaffId > 0) {
    $histSql .= " AND a.staff_id = :sid";
    $histParams['sid'] = $filterStaffId;
}
$histSql .= " ORDER BY a.attendance_date DESC, s.name";

$histStmt = $pdo->prepare($histSql);
$histStmt->execute($histParams);
$history = $histStmt->fetchAll();

$allStaff = $pdo->query("SELECT staff_id, name FROM staff ORDER BY name")->fetchAll();

include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/sidebar.php';
?>
<main>
    <h1>Attendance</h1>

    <?php if ($error): ?>
        <div class="alert alert-error"><?php echo sanitize($error); ?></div>
    <?php endif; ?>
    <?php if ($success): ?>
        <div class="alert alert-success"><?php echo sanitize($success); ?></div>
    <?php endif; ?>

    <div class="toolbar">
        <a href="list.php">Back to Staff List</a>
        <a href="mark-attendance.php" class="btn-primary">My Selfie Check-In / Check-Out</a>
    </div>

    <div class="alert" style="background:#f0e6d2; color:#3b0a0a;">
        Staff ab apni attendance khud <strong>mark-attendance.php</strong> se selfie + face verification + GPS ke saath mark karenge (server ka time/date automatic lagta hai, fraud possible nahi). Face Match column me score jitna kam ho utna better match hai (0.5 se kam = pass). Neeche wala table sirf admin ke manual-override ke liye hai — normal use me isse avoid karo.
    </div>

    <div class="card">
        <h2>Manual Override (Admin Only)</h2>
        <form method="GET" action="" class="inline-form" style="margin-bottom: 16px;">
            <label>Date:</label>
            <input type="date" name="date" value="<?php echo sanitize($markDate); ?>" onchange="this.form.submit()">
        </form>

        <form method="POST" action="">
            <input type="hidden" name="action" value="mark_attendance">
            <input type="hidden" name="attendance_date" value="<?php echo sanitize($markDate); ?>">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Staff</th>
                        <th>Status</th>
                        <th>Check In</th>
                        <th>Check Out</th>
                        <th>Notes</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($staffForDate)): ?>
                        <tr><td colspan="5">Koi active staff nahi mila.</td></tr>
                    <?php else: ?>
                        <?php foreach ($staffForDate as $s): ?>
                        <tr>
                            <td><?php echo sanitize($s['name']); ?></td>
                            <td>
                                <select name="att[<?php echo (int)$s['staff_id']; ?>][status]">
                                    <?php foreach (['present' => 'Present', 'absent' => 'Absent', 'half_day' => 'Half Day', 'leave' => 'Leave'] as $val => $label): ?>
                                        <option value="<?php echo $val; ?>" <?php echo ($s['status'] ?? 'present') === $val ? 'selected' : ''; ?>>
                                            <?php echo $label; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                            <td><input type="time" name="att[<?php echo (int)$s['staff_id']; ?>][check_in]" value="<?php echo sanitize($s['check_in'] ?? ''); ?>"></td>
                            <td><input type="time" name="att[<?php echo (int)$s['staff_id']; ?>][check_out]" value="<?php echo sanitize($s['check_out'] ?? ''); ?>"></td>
                            <td><input type="text" name="att[<?php echo (int)$s['staff_id']; ?>][notes]" value="<?php echo sanitize($s['notes'] ?? ''); ?>" style="width: 100%;"></td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
            <br>
            <button type="submit">Save Attendance</button>
        </form>
    </div>

    <div class="card">
        <h2>Attendance History</h2>
        <form method="GET" action="" class="inline-form" style="margin-bottom: 16px;">
            <select name="staff_id">
                <option value="0">All Staff</option>
                <?php foreach ($allStaff as $s): ?>
                    <option value="<?php echo (int)$s['staff_id']; ?>" <?php echo $filterStaffId === (int)$s['staff_id'] ? 'selected' : ''; ?>>
                        <?php echo sanitize($s['name']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <label>From:</label>
            <input type="date" name="from" value="<?php echo sanitize($fromDate); ?>">
            <label>To:</label>
            <input type="date" name="to" value="<?php echo sanitize($toDate); ?>">
            <button type="submit">Filter</button>
        </form>

        <table class="data-table">
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Staff</th>
                    <th>Status</th>
                    <th>Check In</th>
                    <th>Check Out</th>
                    <th>Selfies</th>
                    <th>Face Match</th>
                    <th>Location</th>
                    <th>Notes</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($history)): ?>
                    <tr><td colspan="9">Is date range me koi attendance record nahi mila.</td></tr>
                <?php else: ?>
                    <?php foreach ($history as $h): ?>
                    <tr>
                        <td><?php echo sanitize($h['attendance_date']); ?></td>
                        <td><?php echo sanitize($h['name']); ?></td>
                        <td><?php echo sanitize(ucwords(str_replace('_', ' ', $h['status']))); ?></td>
                        <td><?php echo sanitize($h['check_in'] ?? '-'); ?></td>
                        <td><?php echo sanitize($h['check_out'] ?? '-'); ?></td>
                        <td>
                            <?php if (!empty($h['checkin_photo'])): ?>
                                <a href="<?php echo BASE_URL . sanitize($h['checkin_photo']); ?>" target="_blank">In</a>
                            <?php endif; ?>
                            <?php if (!empty($h['checkin_photo']) && !empty($h['checkout_photo'])): ?> | <?php endif; ?>
                            <?php if (!empty($h['checkout_photo'])): ?>
                                <a href="<?php echo BASE_URL . sanitize($h['checkout_photo']); ?>" target="_blank">Out</a>
                            <?php endif; ?>
                            <?php if (empty($h['checkin_photo']) && empty($h['checkout_photo'])): ?>-<?php endif; ?>
                        </td>
                        <td>
                            <?php
                                $scores = array_filter([$h['checkin_face_score'], $h['checkout_face_score']], fn($s) => $s !== null);
                            ?>
                            <?php if (empty($scores)): ?>
                                -
                            <?php else: ?>
                                <?php foreach (['checkin_face_score' => 'In', 'checkout_face_score' => 'Out'] as $col => $label): ?>
                                    <?php if ($h[$col] !== null): ?>
                                        <?php if ((float)$h[$col] <= 0.5): ?>
                                            <span class="badge-warning" style="background:#e6f4ea;color:#1e7e34;"><?php echo $label; ?>: <?php echo number_format($h[$col], 3); ?></span>
                                        <?php else: ?>
                                            <span class="badge-warning" style="background:#fdecea;color:#b71c1c;"><?php echo $label; ?>: <?php echo number_format($h[$col], 3); ?> (weak)</span>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if (!empty($h['checkin_address'])): ?>
                                <div style="font-size:11px;">In: <?php echo sanitize($h['checkin_address']); ?></div>
                            <?php endif; ?>
                            <?php if (!empty($h['checkout_address'])): ?>
                                <div style="font-size:11px;">Out: <?php echo sanitize($h['checkout_address']); ?></div>
                            <?php endif; ?>
                            <?php if (!empty($h['checkin_lat']) && !empty($h['checkin_lng'])): ?>
                                <a href="https://maps.google.com/?q=<?php echo $h['checkin_lat']; ?>,<?php echo $h['checkin_lng']; ?>" target="_blank">Map In</a>
                            <?php endif; ?>
                            <?php if (!empty($h['checkin_lat']) && !empty($h['checkout_lat'])): ?> | <?php endif; ?>
                            <?php if (!empty($h['checkout_lat']) && !empty($h['checkout_lng'])): ?>
                                <a href="https://maps.google.com/?q=<?php echo $h['checkout_lat']; ?>,<?php echo $h['checkout_lng']; ?>" target="_blank">Map Out</a>
                            <?php endif; ?>
                            <?php if (empty($h['checkin_lat']) && empty($h['checkout_lat'])): ?>-<?php endif; ?>
                        </td>
                        <td><?php echo sanitize($h['notes'] ?? '-'); ?></td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</main>
<?php include __DIR__ . '/../../includes/footer.php'; ?>