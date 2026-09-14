<?php
session_start();
require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../helpers/auth.php';
require_once __DIR__.'/../helpers/permissions_v2.php';
require_once __DIR__.'/../helpers/bible_class_capacity.php';
require_once __DIR__.'/../helpers/csrf.php';
require_once __DIR__.'/../services/RegistrationDuplicateService.php';

// Authentication check
if (!is_logged_in()) {
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}

// Permission check
$is_super_admin = (int) ($_SESSION['role_id'] ?? 0) === 1 || !empty($_SESSION['is_super_admin']);
if (!$is_super_admin && !has_permission('transfer_sundayschool')) {
    http_response_code(403);
    echo '<div class="alert alert-danger"><h4>403 Forbidden</h4><p>You do not have permission to access this page.</p></div>';
    exit;
}

$id = isset($_GET['id']) ? intval($_GET['id']) : 0;
if (!$id) {
    header('Location: sundayschool_list.php');
    exit;
}

// Fetch Sunday School record
$stmt = $conn->prepare('SELECT * FROM sunday_school WHERE id = ? LIMIT 1');
$stmt->bind_param('i', $id);
$stmt->execute();
$result = $stmt->get_result();
$child = $result->fetch_assoc();
if (!$child) {
    header('Location: sundayschool_list.php');
    exit;
}
if (!empty($child['transferred_at'])) {
    header('Location: sundayschool_view.php?id='.$id);
    exit;
}

$duplicateService = RegistrationDuplicateService::fromSession($conn);
try {
    // An empty candidate still performs the service's church-scope check.
    $duplicateService->findMatches([], 'sunday_school', $id, (int) $child['church_id']);
} catch (Throwable $e) {
    http_response_code(403);
    exit('This Sunday School record is outside your authorized church.');
}

// Prepare form defaults
$member = [
    'first_name' => $child['first_name'],
    'middle_name' => $child['middle_name'],
    'last_name' => $child['last_name'],
    'dob' => $child['dob'],
    'phone' => $child['contact'],
    'class_id' => $child['class_id'],
    'church_id' => $child['church_id'],
    'photo' => $child['photo'],
    'crn' => '', // Will be generated on load/submit
    'status' => 'active',
    'address' => $child['residential_address'],
    'gps_address' => $child['gps_address'],
    'email' => '', // Optional, not from sunday_school
    // You can add more mappings as needed
];

$error = '';
$success = '';
$duplicate_matches = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_is_valid($_POST['csrf_token'] ?? null)) {
        $error = 'Your form session expired. Refresh the page and try again.';
    }
    // Validate required fields
    $member['first_name'] = trim($_POST['first_name'] ?? '');
    $member['middle_name'] = trim($_POST['middle_name'] ?? '');
    $member['last_name'] = trim($_POST['last_name'] ?? '');
    $member['dob'] = trim($_POST['dob'] ?? '');
    $member['phone'] = trim($_POST['phone'] ?? '');
    $member['class_id'] = intval($_POST['class_id'] ?? 0);
    $member['church_id'] = intval($_POST['church_id'] ?? 0);
    $member['status'] = 'active';
    $member['crn'] = trim($_POST['crn'] ?? '');
    $member['email'] = trim($_POST['email'] ?? '');

    if (!$error && (!$member['first_name'] || !$member['last_name'] || !$member['dob']
        || !$member['phone'] || !$member['class_id'] || !$member['church_id'])) {
        $error = 'Complete all required transfer fields.';
    }
    if (!$error && (int) $member['church_id'] !== (int) $child['church_id']) {
        $error = 'A Sunday School child can only be transferred within the recorded church.';
    }
    if (!$error && $member['email'] !== '' && !filter_var($member['email'], FILTER_VALIDATE_EMAIL)) {
        $error = 'Enter a valid email address.';
    }
    if (!$error) {
        $class_stmt = $conn->prepare('SELECT id FROM bible_classes WHERE id = ? AND church_id = ? LIMIT 1');
        $class_stmt->bind_param('ii', $member['class_id'], $member['church_id']);
        $class_stmt->execute();
        if (!$class_stmt->get_result()->fetch_assoc()) {
            $error = 'Choose a Bible Class belonging to the child church.';
        }
        $class_stmt->close();
    }

    // Auto-generate CRN if blank (use same logic as get_next_crn.php)
    if (!$error && $member['crn'] === '' && $member['class_id'] && $member['church_id']) {
        // Get class code
        $stmt = $conn->prepare('SELECT code FROM bible_classes WHERE id = ? AND church_id = ? LIMIT 1');
        $stmt->bind_param('ii', $member['class_id'], $member['church_id']);
        $stmt->execute();
        $result = $stmt->get_result();
        $class = $result->fetch_assoc();
        $class_code = $class ? $class['code'] : '';
        // Get church code and circuit code
        $stmt = $conn->prepare('SELECT church_code, circuit_code FROM churches WHERE id = ? LIMIT 1');
        $stmt->bind_param('i', $member['church_id']);
        $stmt->execute();
        $result = $stmt->get_result();
        $church = $result->fetch_assoc();
        $church_code = $church ? $church['church_code'] : '';
        $circuit_code = $church ? $church['circuit_code'] : '';
        // Get max sequence number used in CRN/SRN for this church/class in both tables
        $max_seq = 0;
        // Check members
        $stmt = $conn->prepare('SELECT crn FROM members WHERE class_id = ? AND church_id = ?');
        $stmt->bind_param('ii', $member['class_id'], $member['church_id']);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            if (preg_match('/'.preg_quote($church_code.'-'.$class_code, '/').'([0-9]+)-'.preg_quote($circuit_code, '/').'/i', $row['crn'], $m)) {
                $num = intval($m[1]);
                if ($num > $max_seq) $max_seq = $num;
            }
        }
        // Check sunday_school
        $stmt = $conn->prepare('SELECT srn FROM sunday_school WHERE class_id = ? AND church_id = ?');
        $stmt->bind_param('ii', $member['class_id'], $member['church_id']);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            if (preg_match('/'.preg_quote($church_code.'-'.$class_code, '/').'([0-9]+)-'.preg_quote($circuit_code, '/').'/i', $row['srn'], $m)) {
                $num = intval($m[1]);
                if ($num > $max_seq) $max_seq = $num;
            }
        }
        $seq = str_pad($max_seq + 1, 2, '0', STR_PAD_LEFT);
        $member['crn'] = $church_code . '-' . $class_code . $seq . '-' . $circuit_code;
    }
    $member['address'] = trim($_POST['residential_address'] ?? '');
    $member['gps_address'] = trim($_POST['gps_address'] ?? '');

    // CRNs are exact identifiers and cannot be overridden.
    if (!$error) {
        $stmt = $conn->prepare('SELECT id FROM members WHERE crn = ? LIMIT 1');
        $stmt->bind_param('s', $member['crn']);
        $stmt->execute();
        if ($stmt->get_result()->fetch_assoc()) {
            $error = 'That CRN is already assigned to a member.';
        }
        $stmt->close();
    }
    $duplicate_reason = trim((string) ($_POST['duplicate_reason'] ?? ''));
    if (!$error) {
        try {
            $duplicate_matches = $duplicateService->findMatches(
                $member, 'sunday_school', $id, (int) $member['church_id']
            );
            if ($duplicate_matches && (!isset($_POST['continue_duplicate']) || $duplicate_reason === '')) {
                $error = 'Possible duplicate found. Review the records below, or explain why this transfer is a separate registration.';
            }
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }
    }
    if (!$error) {
        $capacity = bible_class_validate_capacity($conn, (int) $member['class_id']);
        if (!$capacity['allowed']) {
            $error = 'Transfer blocked: ' . bible_class_capacity_error_message();
        }
    }

    if (!$error) {
        // Transfer photo file if present
        $photo_filename = $member['photo'];
        if ($photo_filename) {
            $src = __DIR__ . '/../uploads/sundayschool/' . $photo_filename;
            $dst_dir = __DIR__ . '/../uploads/members/';
            $dst = $dst_dir . $photo_filename;
            if (file_exists($src)) {
                if (!is_dir($dst_dir)) mkdir($dst_dir, 0777, true);
                // If file exists in destination, add a unique suffix
                $final_dst = $dst;
                $i = 1;
                $ext = pathinfo($photo_filename, PATHINFO_EXTENSION);
                $base = pathinfo($photo_filename, PATHINFO_FILENAME);
                while (file_exists($final_dst)) {
                    $final_dst = $dst_dir . $base . '_' . $i . ($ext ? ('.'.$ext) : '');
                    $i++;
                }
                copy($src, $final_dst);
                $member['photo'] = basename($final_dst);
            }
        }
        // Insert and link atomically so a child is never half-transferred.
        $conn->begin_transaction();
        try {
            $stmt = $conn->prepare("INSERT INTO members (first_name, middle_name, last_name, dob, phone, class_id, church_id, photo, crn, status, email, address, gps_address, deactivated_at, password_hash) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, '', '')");
            $stmt->bind_param('sssssiissssss', $member['first_name'], $member['middle_name'], $member['last_name'], $member['dob'], $member['phone'], $member['class_id'], $member['church_id'], $member['photo'], $member['crn'], $member['status'], $member['email'], $member['address'], $member['gps_address']);
            $stmt->execute();
            $new_member_id = (int) $stmt->insert_id;
            $stmt->close();
            // Mark Sunday School child as transferred
            $stmt2 = $conn->prepare('UPDATE sunday_school SET transferred_at = NOW(), transferred_to_member_id = ? WHERE id = ?');
            $stmt2->bind_param('ii', $new_member_id, $id);
            $stmt2->execute();
            $stmt2->close();
            if ($duplicate_matches) {
                $duplicateService->recordMatches(
                    'member', $new_member_id, (int) $member['church_id'],
                    $duplicate_matches, 'conversion', $duplicate_reason
                );
            }
            $conn->commit();
            // Send SMS notification
            try {
                require_once __DIR__.'/../includes/sms.php';
                $msg = 'You have been transferred to full membership. Your CRN is: ' . $member['crn'];
                send_sms($member['phone'], $msg);
            } catch (Throwable $smsError) {
                error_log('Sunday School transfer SMS failed: ' . $smsError->getMessage());
            }
            // Optionally, log the transfer
            $success = 'Transfer successful!';
            header('Location: member_view.php?id='.$new_member_id);
            exit;
        } catch (Throwable $e) {
            $conn->rollback();
            $error = is_bible_class_capacity_error($e->getMessage())
                ? ('Transfer blocked: ' . bible_class_capacity_error_message())
                : $e->getMessage();
        }
    }
}

// Fetch church/class lists for select fields
$churches = $conn->query('SELECT id, name FROM churches ORDER BY name');
$classes = $conn->query('SELECT id, name FROM bible_classes ORDER BY name');

ob_start();
?>
<div class="container py-4">
    <div class="row justify-content-center">
        <div class="col-lg-8">
            <div class="card shadow p-4">
                <h3 class="mb-4 text-primary"><i class="fa fa-exchange-alt"></i> Transfer to Member</h3>
                <?php if($error): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>
                <?php if($success): ?><div class="alert alert-success"><?= htmlspecialchars($success) ?></div><?php endif; ?>
                <form method="post">
                    <?= csrf_input() ?>
                    <div class="form-row">
                        <div class="form-group col-md-4">
                            <label>First Name</label>
                            <input type="text" name="first_name" class="form-control" required value="<?= htmlspecialchars($member['first_name']) ?>">
                        </div>
                        <div class="form-group col-md-4">
                            <label>Middle Name</label>
                            <input type="text" name="middle_name" class="form-control" value="<?= htmlspecialchars($member['middle_name']) ?>">
                        </div>
                        <div class="form-group col-md-4">
                            <label>Last Name</label>
                            <input type="text" name="last_name" class="form-control" required value="<?= htmlspecialchars($member['last_name']) ?>">
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group col-md-4">
                            <label>Date of Birth</label>
                            <input type="date" name="dob" class="form-control" required value="<?= htmlspecialchars($member['dob']) ?>">
                        </div>
                        <div class="form-group col-md-4">
                            <label>Phone</label>
                            <input type="text" name="phone" class="form-control" required value="<?= htmlspecialchars($member['phone']) ?>">
                        </div>
                        <div class="form-group col-md-4">
                            <label>CRN</label>
                            <input type="text" name="crn" id="crn" class="form-control" required value="<?= htmlspecialchars($member['crn']) ?>" readonly>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group col-md-6">
                            <label>Church</label>
                            <select name="church_id" class="form-control" required>
                                <?php while($c = $churches->fetch_assoc()): ?>
                                    <option value="<?= $c['id'] ?>" <?= $member['church_id'] == $c['id'] ? 'selected' : '' ?>><?= htmlspecialchars($c['name']) ?></option>
                                <?php endwhile; ?>
                            </select>
                        </div>
                        <div class="form-group col-md-6">
                            <label>Bible Class</label>
                            <select name="class_id" class="form-control" required>
                                <?php while($cl = $classes->fetch_assoc()): ?>
                                    <option value="<?= $cl['id'] ?>" <?= $member['class_id'] == $cl['id'] ? 'selected' : '' ?>><?= htmlspecialchars($cl['name']) ?></option>
                                <?php endwhile; ?>
                            </select>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group col-md-6">
                            <label>Email (optional)</label>
                            <input type="email" name="email" class="form-control" value="<?= htmlspecialchars($member['email']) ?>">
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group col-md-6">
                            <label>GPS Address</label>
                            <input type="text" name="gps_address" class="form-control" value="<?= htmlspecialchars($member['gps_address']) ?>">
                        </div>
                        <div class="form-group col-md-6">
                            <label>Residential Address</label>
                            <input type="text" name="residential_address" class="form-control" value="<?= htmlspecialchars($member['address']) ?>">
                        </div>
                    </div>
                    <?php if ($duplicate_matches): ?>
                    <div class="alert alert-warning">
                        <strong>Possible duplicate<?= count($duplicate_matches) === 1 ? '' : 's' ?>:</strong>
                        <ul class="mb-2 mt-2">
                        <?php foreach ($duplicate_matches as $match): ?>
                            <li><?= htmlspecialchars($match['display_name']) ?> (<?= htmlspecialchars($match['identifier']) ?>) &mdash; matched by <?= htmlspecialchars(implode(', ', $match['match_rules'])) ?></li>
                        <?php endforeach; ?>
                        </ul>
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="continue_duplicate" id="continue_duplicate" value="1" <?= isset($_POST['continue_duplicate']) ? 'checked' : '' ?>>
                            <label class="form-check-label" for="continue_duplicate">Continue transfer as a separate member record.</label>
                        </div>
                        <label for="duplicate_reason" class="mt-2">Reason for continuing</label>
                        <textarea class="form-control" id="duplicate_reason" name="duplicate_reason" maxlength="500"><?= htmlspecialchars($_POST['duplicate_reason'] ?? '') ?></textarea>
                    </div>
                    <?php endif; ?>
                    <button type="submit" class="btn btn-success"><i class="fa fa-exchange-alt"></i> Transfer to Member</button>
                    <a href="sundayschool_view.php?id=<?= $id ?>" class="btn btn-secondary">Cancel</a>
                </form>
            </div>
        </div>
    </div>
</div>
<script>
function updateCRN() {
    var classId = document.querySelector('[name="class_id"]').value;
    var churchId = document.querySelector('[name="church_id"]').value;
    if(classId && churchId) {
        var xhr = new XMLHttpRequest();
        xhr.open('GET', 'get_next_crn.php?class_id=' + encodeURIComponent(classId) + '&church_id=' + encodeURIComponent(churchId), true);
        xhr.onload = function() {
            if(xhr.status === 200) {
                document.getElementById('crn').value = xhr.responseText;
            }
        };
        xhr.send();
    } else {
        document.getElementById('crn').value = '';
    }
}
document.addEventListener('DOMContentLoaded', function() {
    var classSelect = document.querySelector('[name="class_id"]');
    var churchSelect = document.querySelector('[name="church_id"]');
    if(classSelect) classSelect.addEventListener('change', updateCRN);
    if(churchSelect) churchSelect.addEventListener('change', updateCRN);
    updateCRN(); // Initial call on page load
});
</script>
<?php $page_content = ob_get_clean(); include '../includes/layout.php'; ?>
