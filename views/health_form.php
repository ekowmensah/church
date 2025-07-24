<?php
require_once __DIR__.'/../config/config.php';
?>
<script>
    window.BASE_URL = "<?= htmlspecialchars($base_url ?? '/myfreeman/') ?>";
</script>
<?php
require_once __DIR__.'/../helpers/auth.php';
if (!is_logged_in()) {
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}
// Allow Super Admin (role_id==1 or role name 'Super Admin') to always access
$super_admin = false;
$role_id = $_SESSION['role_id'] ?? 0;
if ($role_id == 1) {
    $super_admin = true;
} else {
    $stmt = $conn->prepare("SELECT name FROM roles WHERE id = ? LIMIT 1");
    $stmt->bind_param('i', $role_id);
    $stmt->execute();
    $stmt->bind_result($role_name);
    $stmt->fetch();
    $stmt->close();
    if ($role_name === 'Super Admin') {
        $super_admin = true;
    }
}
if (!$super_admin && !has_permission('health_statistics')) {
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}
$id = isset($_GET['id']) ? intval($_GET['id']) : 0;
$editing = $id > 0;
$record = [
    'member_id' => '',
    'crn' => '',
    'vitals' => '',
    'notes' => '',
    'recorded_at' => date('Y-m-d\TH:i'),
];
// Prefill CRN/member_id if adding (not editing)
if (!$editing) {
    include __DIR__.'/health_form_prefill.php';
    if (!empty($prefill_crn)) {
        $record['crn'] = $prefill_crn;
    }
    if (!empty($prefill_member_id)) {
        $record['member_id'] = $prefill_member_id;
    }
}

$error = '';

$vitals = [];
// Fetch members for dropdown
$members = $conn->query("SELECT id, first_name, last_name FROM members ORDER BY first_name, last_name");
// On edit, fetch record
if ($editing) {
    $stmt = $conn->prepare("SELECT * FROM health_records WHERE id = ? LIMIT 1");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($row = $result->fetch_assoc()) {
        $record = $row;
        $record['recorded_at'] = date('Y-m-d\TH:i', strtotime($row['recorded_at']));
        $vitals = json_decode($row['vitals'], true) ?: [];
    } else {
        $error = 'Health record not found.';
        $editing = false;
    }
    $stmt->close();
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $member_id = intval($_POST['member_id'] ?? 0);
    $sundayschool_id = intval($_POST['sundayschool_id'] ?? 0);
    if (!$member_id) $member_id = null;
    if (!$sundayschool_id) $sundayschool_id = null;
    $vitals = $_POST['vitals'] ?? [];
    $notes = trim($_POST['notes'] ?? '');
    $recorded_at = trim($_POST['recorded_at'] ?? '');
    $recorded_by = $_SESSION['user_id'];
    // Validate
    if (!$member_id && !$sundayschool_id) {
        $error = 'Please select a member or Sunday School child.';
    } elseif ($member_id && $sundayschool_id) {
        $error = 'Cannot select both member and Sunday School child.';
    } elseif (empty($vitals['weight']) && empty($vitals['temperature']) && empty($vitals['bp']) && empty($vitals['sugar']) && empty($vitals['hepatitis_b']) && empty($vitals['malaria'])) {
        $error = 'At least one vital field is required.';
    } elseif (!$recorded_at) {
        $error = 'Date/time is required.';
    } else {
        // Prepare BP as systolic/diastolic string for storage
        if (isset($_POST['vitals']['bp_systolic']) && isset($_POST['vitals']['bp_diastolic'])) {
            $_POST['vitals']['bp'] = $_POST['vitals']['bp_systolic'] . '/' . $_POST['vitals']['bp_diastolic'];
            // Calculate status
            $sys = intval($_POST['vitals']['bp_systolic']);
            $dia = intval($_POST['vitals']['bp_diastolic']);
            if ($sys >= 140 || $dia >= 90) {
                $_POST['vitals']['bp_status'] = 'high';
            } elseif ($sys < 90 || $dia < 60) {
                $_POST['vitals']['bp_status'] = 'low';
            } else {
                $_POST['vitals']['bp_status'] = 'normal';
            }
        }
        // Prepare Sugar status
        if (isset($_POST['vitals']['sugar'])) {
            $sugar = floatval($_POST['vitals']['sugar']);
            if ($sugar >= 7.0) {
                $_POST['vitals']['sugar_status'] = 'high';
            } elseif ($sugar < 4.0) {
                $_POST['vitals']['sugar_status'] = 'low';
            } else {
                $_POST['vitals']['sugar_status'] = 'normal';
            }
        }
        // Prepare vitals as JSON
        $vitals_json = json_encode($_POST['vitals']);
        if ($editing) {
            $stmt = $conn->prepare("UPDATE health_records SET member_id=?, sundayschool_id=?, vitals=?, notes=?, recorded_at=?, recorded_by=? WHERE id=?");
            $stmt->bind_param('iiisssi', $member_id, $sundayschool_id, $vitals_json, $notes, $recorded_at, $recorded_by, $id);
            $ok = $stmt->execute();
        } else {
            $stmt = $conn->prepare("INSERT INTO health_records (member_id, sundayschool_id, vitals, notes, recorded_at, recorded_by) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->bind_param('iisssi', $member_id, $sundayschool_id, $vitals_json, $notes, $recorded_at, $recorded_by);
            $ok = $stmt->execute();
        }
        if ($ok) {
            header('Location: health_list.php?msg=saved');
            exit;
        } else {
            $error = 'Database error: ' . $stmt->error;
        }
    }
    // Repopulate form
    $record = [
        'member_id' => $member_id,
        'sundayschool_id' => $sundayschool_id,
        'vitals' => $vitals,
        'notes' => $notes,
        'recorded_at' => $recorded_at,
    ];
}
ob_start();
?>
<div class="card shadow mb-5">
    <div class="card-header bg-primary text-white d-flex align-items-center">
        <i class="fas fa-heartbeat mr-2"></i>
        <h4 class="mb-0 flex-grow-1"> <?= $editing ? 'Edit' : 'Add' ?> Health Record</h4>
        <a href="health_list.php" class="btn btn-light btn-sm ml-3"><i class="fas fa-list"></i> Back to List</a>
    </div>
    <div class="card-body">
        <?php if ($error): ?>
            <div class="alert alert-danger mb-4"> <?= htmlspecialchars($error) ?> </div>
        <?php endif; ?>
        <form method="post" autocomplete="off">
            <!-- Member Info -->
            <div class="mb-4">
    <h5 class="text-primary mb-3"><i class="fas fa-user mr-2"></i>Member Info</h5>
    <div class="form-row align-items-end">
        <div class="form-group col-md-4">
            <label for="crn">Search by CRN <span class="text-danger">*</span></label>
            <div class="input-group">
                <input type="text" class="form-control" id="crn" name="crn" placeholder="Enter CRN" value="<?= htmlspecialchars($record['crn'] ?? '') ?>" autocomplete="off" required>
                <div class="input-group-append">
                    <button class="btn btn-outline-primary" type="button" id="search_crn_btn"><i class="fas fa-search"></i> Find</button>
                </div>
            </div>
            <small class="form-text text-muted">Type CRN and click Find</small>
        </div>
        <input type="hidden" id="member_id" name="member_id" value="<?= htmlspecialchars($record['member_id']) ?>">
        <input type="hidden" id="sundayschool_id" name="sundayschool_id" value="<?= htmlspecialchars($record['sundayschool_id'] ?? '') ?>">
        <div class="form-group col-md-12 mt-2" id="crn_error_box" style="display:none;">
            <div class="alert alert-warning mb-0" id="crn_error_msg"></div>
        </div>
    </div>
    <div class="form-row member-info-summary" id="member_info_summary" style="display:none;">
        <div class="form-group col-md-2 d-flex align-items-center justify-content-center">
            <img id="member_photo" src="<?= htmlspecialchars($base_url ?? '/myfreeman/') ?>assets/default_avatar.png" alt="Photo" style="width:64px;height:64px;object-fit:cover;border-radius:50%;border:2px solid #ddd;">
        </div>
        <div class="form-group col-md-3">
            <label>Name</label>
            <input type="text" class="form-control" id="member_name" readonly>
        </div>
        <div class="form-group col-md-2">
            <label>Class/SRN</label>
            <input type="text" class="form-control" id="member_class" readonly>
        </div>
        <div class="form-group col-md-2">
            <label>Phone/Contact</label>
            <input type="text" class="form-control" id="member_phone" readonly>
        </div>
        <div class="form-group col-md-2">
            <label>Age</label>
            <input type="text" class="form-control" id="member_age" readonly>
        </div>
        <div class="form-group col-md-1">
            <label>Gender</label>
            <input type="text" class="form-control" id="member_gender" readonly>
        </div>
        <!-- Sunday School Child Extra Fields -->
        <div class="form-group col-md-4" id="child_parent_fields" style="display:none;">
            <label>Father's Name/Contact</label>
            <div class="input-group mb-1">
                <input type="text" class="form-control" id="father_name" placeholder="Father's Name" readonly>
                <input type="text" class="form-control" id="father_contact" placeholder="Father's Contact" readonly>
            </div>
            <label>Mother's Name/Contact</label>
            <div class="input-group">
                <input type="text" class="form-control" id="mother_name" placeholder="Mother's Name" readonly>
                <input type="text" class="form-control" id="mother_contact" placeholder="Mother's Contact" readonly>
            </div>
        </div>
        <div class="form-group col-md-3" id="child_school_field" style="display:none;">
            <label>School Attended</label>
            <input type="text" class="form-control" id="school_attend" placeholder="School Attended" readonly>
        </div>
    </div>
    </div>
    <div class="form-row">
        <div class="form-group col-md-6">
            <label for="recorded_at">Date/Time <span class="text-danger">*</span></label>
            <input type="datetime-local" class="form-control" id="recorded_at" name="recorded_at" value="<?= htmlspecialchars($record['recorded_at']) ?>" required>
        </div>
    </div>
</div>
            <!-- Member-dependent Sections -->
            <div class="member-sections" style="display:none;">
                <!-- Vitals Section -->
                <div class="vitals-section mb-4 p-3 rounded bg-light border">
                    <h5 class="text-success mb-3"><i class="fas fa-stethoscope mr-2"></i>Vitals</h5>
                    <div class="form-row">
                        <div class="form-group col-md-3">
                            <label for="weight">Weight (Kg)</label>
                            <input type="number" step="0.1" class="form-control" id="weight" name="vitals[weight]" value="<?= htmlspecialchars($vitals['weight'] ?? '') ?>">
                        </div>
                        <div class="form-group col-md-3">
                            <label for="temperature">Temperature (°C)</label>
                            <input type="number" step="0.1" class="form-control" id="temperature" name="vitals[temperature]" value="<?= htmlspecialchars($vitals['temperature'] ?? '') ?>">
                        </div>
                        <div class="form-group col-md-3">
                            <label>Blood Pressure (mmHg)</label>
                            <div class="input-group">
                                <input type="number" min="0" class="form-control" id="bp_systolic" name="vitals[bp_systolic]" value="<?= htmlspecialchars($vitals['bp_systolic'] ?? (isset($vitals['bp']) && strpos($vitals['bp'], '/')!==false ? explode('/', $vitals['bp'])[0] : '')) ?>" placeholder="Systolic">
                                <div class="input-group-append input-group-prepend">
                                    <span class="input-group-text">/</span>
                                </div>
                                <input type="number" min="0" class="form-control" id="bp_diastolic" name="vitals[bp_diastolic]" value="<?= htmlspecialchars($vitals['bp_diastolic'] ?? (isset($vitals['bp']) && strpos($vitals['bp'], '/')!==false ? explode('/', $vitals['bp'])[1] : '')) ?>" placeholder="Diastolic">
                            </div>
                            <small class="form-text text-muted">Systolic / Diastolic</small>
                        </div>
                        <div class="form-group col-md-3">
                            <label>BP Status</label>
                            <div id="bp_status_display">
                                <?php
                                    $sys = isset($vitals['bp_systolic']) ? intval($vitals['bp_systolic']) : (isset($vitals['bp']) && strpos($vitals['bp'], '/')!==false ? intval(explode('/', $vitals['bp'])[0]) : null);
                                    $dia = isset($vitals['bp_diastolic']) ? intval($vitals['bp_diastolic']) : (isset($vitals['bp']) && strpos($vitals['bp'], '/')!==false ? intval(explode('/', $vitals['bp'])[1]) : null);
                                    $bp_status = '';
                                    $bp_color = '';
                                    if (isset($vitals['bp_status']) && $vitals['bp_status']) {
                                        $bp_status = ucfirst($vitals['bp_status']);
                                        if ($bp_status === 'High') $bp_color = 'red';
                                        elseif ($bp_status === 'Low') $bp_color = 'pink';
                                        elseif ($bp_status === 'Normal') $bp_color = 'green';
                                    } elseif ($sys && $dia) {
                                        if ($sys >= 140 || $dia >= 90) { $bp_status = 'High'; $bp_color = 'red'; }
                                        elseif ($sys < 90 || $dia < 60) { $bp_status = 'Low'; $bp_color = 'pink'; }
                                        else { $bp_status = 'Normal'; $bp_color = 'green'; }
                                    }
                                ?>
                                <span id="bp_status_text" class="badge px-3 py-2" style="font-size:1rem;background:<?= $bp_color ?>;color:#fff;">
                                    <?= $bp_status ? $bp_status : 'Enter BP values' ?>
                                </span>
                                <input type="hidden" id="bp_status" name="vitals[bp_status]" value="<?= strtolower($bp_status) ?>">
                            </div>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group col-md-4">
                            <label for="sugar">Blood Sugar (mmol/L)</label>
                            <input type="number" step="0.1" class="form-control" id="sugar" name="vitals[sugar]" value="<?= htmlspecialchars($vitals['sugar'] ?? '') ?>">
                        </div>
                        <div class="form-group col-md-4">
                            <label>Sugar Status</label>
                            <div id="sugar_status_display">
                                <?php
                                    $sugar = isset($vitals['sugar']) ? floatval($vitals['sugar']) : null;
                                    $sugar_status = '';
                                    $sugar_color = '';
                                    if (isset($vitals['sugar_status']) && $vitals['sugar_status']) {
                                        $sugar_status = ucfirst($vitals['sugar_status']);
                                        if ($sugar_status === 'High') $sugar_color = 'red';
                                        elseif ($sugar_status === 'Low') $sugar_color = 'pink';
                                        elseif ($sugar_status === 'Normal') $sugar_color = 'green';
                                    } elseif ($sugar !== null && $sugar !== '') {
                                        if ($sugar >= 7.0) { $sugar_status = 'High'; $sugar_color = 'red'; }
                                        elseif ($sugar < 4.0) { $sugar_status = 'Low'; $sugar_color = 'pink'; }
                                        else { $sugar_status = 'Normal'; $sugar_color = 'green'; }
                                    }
                                ?>
                                <span id="sugar_status_text" class="badge px-3 py-2" style="font-size:1rem;background:<?= $sugar_color ?>;color:#fff;">
                                    <?= $sugar_status ? $sugar_status : 'Enter Sugar value' ?>
                                </span>
                                <input type="hidden" id="sugar_status" name="vitals[sugar_status]" value="<?= strtolower($sugar_status) ?>">
                            </div>
                        </div>
                    </div>
                </div>
                <!-- Tests Section -->
                <div class="tests-section mb-4">
                    <h5 class="text-info mb-3"><i class="fas fa-vial mr-2"></i>Tests</h5>
                    <div class="form-row">
                        <div class="form-group col-md-6">
                            <label for="hepatitis_b">Hepatitis B Test</label>
                            <select class="form-control" id="hepatitis_b" name="vitals[hepatitis_b]">
                                <option value="">--Select--</option>
                                <option value="Positive" <?= (isset($vitals['hepatitis_b']) && $vitals['hepatitis_b']==='Positive') ? 'selected' : '' ?>>Positive</option>
                                <option value="Negative" <?= (isset($vitals['hepatitis_b']) && $vitals['hepatitis_b']==='Negative') ? 'selected' : '' ?>>Negative</option>
                            </select>
                        </div>
                        <div class="form-group col-md-6">
                            <label for="malaria">Malaria Test</label>
                            <select class="form-control" id="malaria" name="vitals[malaria]">
                                <option value="">--Select--</option>
                                <option value="Positive" <?= (isset($vitals['malaria']) && $vitals['malaria']==='Positive') ? 'selected' : '' ?>>Positive</option>
                                <option value="Negative" <?= (isset($vitals['malaria']) && $vitals['malaria']==='Negative') ? 'selected' : '' ?>>Negative</option>
                            </select>
                        </div>
                    </div>
                </div>
                <!-- Additional Notes -->
                <div class="notes-section mb-4">
                    <h5 class="text-warning mb-3"><i class="fas fa-sticky-note mr-2"></i>Additional Notes</h5>
                    <textarea class="form-control" id="notes" name="notes" rows="2"><?= htmlspecialchars($record['notes']) ?></textarea>
                </div>
                <div class="d-flex justify-content-end">
                    <button type="submit" class="btn btn-success mr-2"><i class="fas fa-save"></i> Save</button>
                    <a href="health_list.php" class="btn btn-link">Cancel</a>
                </div>
            </div>
        </form>
    </div>
</div>
<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script>
function updateBPStatus() {
    var sys = parseInt(document.getElementById('bp_systolic').value) || 0;
    var dia = parseInt(document.getElementById('bp_diastolic').value) || 0;
    var status = '';
    var color = '';
    if (sys && dia) {
        if (sys >= 140 || dia >= 90) { status = 'High'; color = 'red'; }
        else if (sys < 90 || dia < 60) { status = 'Low'; color = 'pink'; }
        else { status = 'Normal'; color = 'green'; }
    } else {
        status = 'Enter BP values'; color = '#333';
    }
    var bpText = document.getElementById('bp_status_text');
    bpText.innerText = status;
    bpText.style.background = color;
    bpText.style.color = (status === 'Low' || status === 'High' || status === 'Normal') ? '#fff' : '#333';
    document.getElementById('bp_status').value = status.toLowerCase();
}
function updateSugarStatus() {
    var sugar = parseFloat(document.getElementById('sugar').value);
    var status = '';
    var color = '';
    if (!isNaN(sugar)) {
        if (sugar >= 7.0) { status = 'High'; color = 'red'; }
        else if (sugar < 4.0) { status = 'Low'; color = 'pink'; }
        else { status = 'Normal'; color = 'green'; }
    } else {
        status = 'Enter Sugar value'; color = '#333';
    }
    var sugarText = document.getElementById('sugar_status_text');
    sugarText.innerText = status;
    sugarText.style.background = color;
    sugarText.style.color = (status === 'Low' || status === 'High' || status === 'Normal') ? '#fff' : '#333';
    document.getElementById('sugar_status').value = status.toLowerCase();
}
function showFormSections(show) {
    // Hide or show all member-dependent sections
    var memberSections = document.querySelectorAll('.member-sections');
    for (var i = 0; i < memberSections.length; i++) {
        memberSections[i].style.display = show ? '' : 'none';
    }
}
function populateMemberFields(member) {
    document.getElementById('member_id').value = member.id;
    document.getElementById('sundayschool_id').value = '';
    document.getElementById('member_name').value = member.first_name + ' ' + member.last_name;
    document.getElementById('member_class').value = member.class_name || '';
    document.getElementById('member_phone').value = member.phone || '';
    document.getElementById('member_age').value = member.age !== null ? member.age : '';
    document.getElementById('member_gender').value = member.gender;
    document.getElementById('member_id').value = member.id;
    document.getElementById('sundayschool_id').value = '';
    document.getElementById('member_info_summary').style.display = '';
    // Set photo
    var photoUrl = member.photo && member.photo.trim() ? member.photo : (window.BASE_URL + 'assets/default_avatar.png');
    if (photoUrl && !photoUrl.match(/^https?:/)) {
        // If not absolute, prepend base
        photoUrl = window.BASE_URL + photoUrl.replace(/^\/+/, '');
    }
    document.getElementById('member_photo').src = photoUrl;
    // Show/hide fields for member
    document.getElementById('member_class').parentElement.style.display = '';
    document.getElementById('member_phone').parentElement.style.display = '';
    document.getElementById('member_gender').parentElement.style.display = '';
    // Hide child-only fields if any
    var parentFields = document.getElementById('child_parent_fields');
    if (parentFields) parentFields.style.display = 'none';
    var schoolField = document.getElementById('child_school_field');
    if (schoolField) schoolField.style.display = 'none';
}

function populateChildFields(child) {
    document.getElementById('sundayschool_id').value = child.id;
    document.getElementById('member_id').value = '';
    document.getElementById('member_name').value = child.first_name + ' ' + (child.middle_name ? child.middle_name + ' ' : '') + child.last_name;
    document.getElementById('member_class').value = child.srn;
    document.getElementById('member_phone').value = child.contact || '';
    document.getElementById('member_age').value = child.age !== null ? child.age : '';
    document.getElementById('member_gender').value = child.gender;
    document.getElementById('sundayschool_id').value = child.id;
    document.getElementById('member_id').value = '';
    document.getElementById('member_info_summary').style.display = '';
    // Set photo
    var photoUrl = child.photo && child.photo.trim() ? child.photo : (window.BASE_URL + 'assets/default_avatar.png');
    if (photoUrl && !photoUrl.match(/^https?:/)) {
        // If not absolute, prepend base
        photoUrl = window.BASE_URL + photoUrl.replace(/^\/+/, '');
    }
    document.getElementById('member_photo').src = photoUrl;
    // Show child-only fields
    var parentFields = document.getElementById('child_parent_fields');
    if (parentFields) {
        parentFields.style.display = '';
        document.getElementById('father_name').value = child.father_name || '';
        document.getElementById('father_contact').value = child.father_contact || '';
        document.getElementById('mother_name').value = child.mother_name || '';
        document.getElementById('mother_contact').value = child.mother_contact || '';
    }
    var schoolField = document.getElementById('child_school_field');
    if (schoolField) {
        schoolField.style.display = '';
        document.getElementById('school_attend').value = child.school_attend || '';
    }
}
function clearMemberFields() {
    document.getElementById('member_id').value = '';
    document.getElementById('sundayschool_id').value = '';
    document.getElementById('member_name').value = '';
    document.getElementById('member_class').value = '';
    document.getElementById('member_phone').value = '';
    document.getElementById('member_age').value = '';
    document.getElementById('member_gender').value = '';
    document.getElementById('member_id').value = '';
    document.getElementById('sundayschool_id').value = '';
    document.getElementById('member_info_summary').style.display = 'none';
    var parentFields = document.getElementById('child_parent_fields');
    if (parentFields) parentFields.style.display = 'none';
    var schoolField = document.getElementById('child_school_field');
    if (schoolField) schoolField.style.display = 'none';
}
document.addEventListener('DOMContentLoaded', function() {
    // Hide form sections until member is found
    var editing = <?= json_encode($editing) ?>;
    if (!editing) {
        showFormSections(false);
    } else {
        showFormSections(true);
        // Prefill member info if editing
        <?php if ($editing && isset($record['member_id']) && $record['member_id']): ?>
        fetch('ajax_get_member_by_crn.php?crn=<?= urlencode($vitals['crn'] ?? $record['crn'] ?? '') ?>')
            .then(r=>r.json()).then(function(data){
                if(data.success && data.member) populateMemberFields(data.member);
            });
        <?php endif; ?>
    }
    document.getElementById('search_crn_btn').addEventListener('click', function() {
        var id = document.getElementById('crn').value.trim();
        var errorBox = document.getElementById('crn_error_box');
        var errorMsg = document.getElementById('crn_error_msg');
        if (!id) {
            errorBox.style.display = '';
            errorMsg.innerText = 'Please enter a CRN or SRN.';
            clearMemberFields();
            showFormSections(false);
            return;
        }
        fetch('ajax_get_person_by_id.php?id=' + encodeURIComponent(id))
            .then(response => response.json())
            .then(function(data) {
                if (data.success && data.type === 'member') {
                    errorBox.style.display = 'none';
                    populateMemberFields(data.data);
                    showFormSections(true);
                } else if (data.success && data.type === 'sundayschool') {
                    errorBox.style.display = 'none';
                    populateChildFields(data.data);
                    showFormSections(true);
                } else {
                    errorBox.style.display = '';
                    errorMsg.innerText = data.msg || 'ID not found.';
                    clearMemberFields();
                    showFormSections(false);
                }
            })
            .catch(function() {
                errorBox.style.display = '';
                errorMsg.innerText = 'Error searching for ID.';
                clearMemberFields();
                showFormSections(false);
            });
    });
    // Allow Enter key in CRN field to trigger search
    document.getElementById('crn').addEventListener('keydown', function(e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            document.getElementById('search_crn_btn').click();
        }
    });
    // BP/Sugar listeners
    var sys = document.getElementById('bp_systolic');
    var dia = document.getElementById('bp_diastolic');
    if (sys && dia) {
        sys.addEventListener('input', updateBPStatus);
        dia.addEventListener('input', updateBPStatus);
    }
    var sugar = document.getElementById('sugar');
    if (sugar) {
        sugar.addEventListener('input', updateSugarStatus);
    }
});
</script>
<style>
    .vitals-section, .tests-section, .notes-section, .d-flex.justify-content-end { transition: opacity 0.2s; }
</style>
<?php
$page_content = ob_get_clean();
require_once __DIR__.'/../includes/layout.php';
