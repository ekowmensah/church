<?php
/**
 * Hikvision Member Enrollment
 * 
 * This page allows administrators to enroll church members with Hikvision face recognition devices,
 * manage their enrollment status, and sync member data with the devices.
 */
require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../helpers/auth.php';
require_once __DIR__.'/../helpers/permissions.php';
require_once __DIR__.'/../includes/admin_auth.php';

// Only allow users with appropriate permissions
if (!has_permission('manage_hikvision_enrollment')) {
    header('Location: ' . BASE_URL . '/dashboard.php');
    exit;
}

// Initialize variables
$success = '';
$error = '';
$selected_device = $_GET['device_id'] ?? null;

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'enroll_member':
                // Enroll a member with a device
                $member_id = $_POST['member_id'] ?? 0;
                $device_id = $_POST['device_id'] ?? 0;
                $hikvision_user_id = $_POST['hikvision_user_id'] ?? '';
                
                if (empty($member_id) || empty($device_id)) {
                    $error = 'Member and device must be selected.';
                } else {
                    // Check if member is already enrolled with this device
                    $stmt = $conn->prepare("
                        SELECT id FROM member_hikvision_data 
                        WHERE member_id = ? AND device_id = ?
                    ");
                    $stmt->bind_param('ii', $member_id, $device_id);
                    $stmt->execute();
                    $result = $stmt->get_result();
                    
                    if ($result->num_rows > 0) {
                        // Update existing enrollment
                        $enrollment = $result->fetch_assoc();
                        $stmt = $conn->prepare("
                            UPDATE member_hikvision_data 
                            SET hikvision_user_id = ?, updated_at = NOW()
                            WHERE id = ?
                        ");
                        $stmt->bind_param('si', $hikvision_user_id, $enrollment['id']);
                    } else {
                        // Create new enrollment
                        $stmt = $conn->prepare("
                            INSERT INTO member_hikvision_data 
                            (member_id, device_id, hikvision_user_id, created_at)
                            VALUES (?, ?, ?, NOW())
                        ");
                        $stmt->bind_param('iis', $member_id, $device_id, $hikvision_user_id);
                    }
                    
                    if ($stmt->execute()) {
                        // Get member name for success message
                        $stmt = $conn->prepare("
                            SELECT CONCAT(firstname, ' ', lastname) as name 
                            FROM members WHERE id = ?
                        ");
                        $stmt->bind_param('i', $member_id);
                        $stmt->execute();
                        $member = $stmt->get_result()->fetch_assoc();
                        
                        $success = "Member '{$member['name']}' enrolled successfully with Hikvision ID: {$hikvision_user_id}";
                    } else {
                        $error = "Failed to enroll member: " . $conn->error;
                    }
                }
                break;
                
            case 'remove_enrollment':
                // Remove a member's enrollment from a device
                $enrollment_id = $_POST['enrollment_id'] ?? 0;
                
                if (empty($enrollment_id)) {
                    $error = 'Invalid enrollment selected.';
                } else {
                    $stmt = $conn->prepare("DELETE FROM member_hikvision_data WHERE id = ?");
                    $stmt->bind_param('i', $enrollment_id);
                    
                    if ($stmt->execute()) {
                        $success = "Member enrollment removed successfully.";
                    } else {
                        $error = "Failed to remove enrollment: " . $conn->error;
                    }
                }
                break;
                
            case 'bulk_enroll':
                // Bulk enroll members
                $member_ids = $_POST['member_ids'] ?? [];
                $device_id = $_POST['bulk_device_id'] ?? 0;
                $start_id = $_POST['start_id'] ?? 1000;
                
                if (empty($member_ids) || empty($device_id)) {
                    $error = 'No members selected or invalid device.';
                } else {
                    $success_count = 0;
                    $error_count = 0;
                    $current_id = $start_id;
                    
                    foreach ($member_ids as $member_id) {
                        // Check if member is already enrolled with this device
                        $stmt = $conn->prepare("
                            SELECT id, hikvision_user_id FROM member_hikvision_data 
                            WHERE member_id = ? AND device_id = ?
                        ");
                        $stmt->bind_param('ii', $member_id, $device_id);
                        $stmt->execute();
                        $result = $stmt->get_result();
                        
                        if ($result->num_rows > 0) {
                            // Skip if already enrolled
                            $enrollment = $result->fetch_assoc();
                            if (!empty($enrollment['hikvision_user_id'])) {
                                continue;
                            }
                            
                            // Update existing enrollment with new ID
                            $hikvision_user_id = (string)$current_id;
                            $stmt = $conn->prepare("
                                UPDATE member_hikvision_data 
                                SET hikvision_user_id = ?, updated_at = NOW()
                                WHERE id = ?
                            ");
                            $stmt->bind_param('si', $hikvision_user_id, $enrollment['id']);
                        } else {
                            // Create new enrollment
                            $hikvision_user_id = (string)$current_id;
                            $stmt = $conn->prepare("
                                INSERT INTO member_hikvision_data 
                                (member_id, device_id, hikvision_user_id, created_at)
                                VALUES (?, ?, ?, NOW())
                            ");
                            $stmt->bind_param('iis', $member_id, $device_id, $hikvision_user_id);
                        }
                        
                        if ($stmt->execute()) {
                            $success_count++;
                            $current_id++;
                        } else {
                            $error_count++;
                        }
                    }
                    
                    if ($success_count > 0) {
                        $success = "{$success_count} members enrolled successfully.";
                        if ($error_count > 0) {
                            $success .= " {$error_count} members failed to enroll.";
                        }
                    } else {
                        $error = "Failed to enroll any members.";
                    }
                }
                break;
        }
    }
}

// Get all active devices
$query = "SELECT id, name FROM hikvision_devices WHERE is_active = 1 ORDER BY name";
$devices = $conn->query($query);

// Get enrolled members for selected device
$enrolled_members = [];
if ($selected_device) {
    $query = "
        SELECT e.id, e.member_id, e.hikvision_user_id, e.created_at, e.updated_at,
               m.firstname, m.lastname, m.gender, m.phone
        FROM member_hikvision_data e
        JOIN members m ON e.member_id = m.id
        WHERE e.device_id = ?
        ORDER BY m.lastname, m.firstname
    ";
    $stmt = $conn->prepare($query);
    $stmt->bind_param('i', $selected_device);
    $stmt->execute();
    $enrolled_members = $stmt->get_result();
}

// Page title
$pageTitle = 'Hikvision Member Enrollment';
include '../includes/header.php';
?>

<div class="content-wrapper">
    <section class="content-header">
        <div class="container-fluid">
            <div class="row mb-2">
                <div class="col-sm-6">
                    <h1>Hikvision Biometric Enrollment</h1>
                </div>
                <div class="col-sm-6">
                    <ol class="breadcrumb float-sm-right">
                        <li class="breadcrumb-item"><a href="<?= BASE_URL ?>/dashboard.php">Home</a></li>
                        <li class="breadcrumb-item"><a href="<?= BASE_URL ?>/views/hikvision_devices.php">Hikvision Devices</a></li>
                        <li class="breadcrumb-item active">Member Enrollment</li>
                    </ol>
                </div>
            </div>
        </div>
    </section>

    <section class="content">
        <div class="container-fluid">
            <?php if ($success): ?>
                <div class="alert alert-success alert-dismissible">
                    <button type="button" class="close" data-dismiss="alert">&times;</button>
                    <?= $success ?>
                </div>
            <?php endif; ?>
            
            <?php if ($error): ?>
                <div class="alert alert-danger alert-dismissible">
                    <button type="button" class="close" data-dismiss="alert">&times;</button>
                    <?= $error ?>
                </div>
            <?php endif; ?>
            
            <div class="row">
                <div class="col-md-12">
                    <div class="card">
                        <div class="card-header">
                            <h3 class="card-title">Select Device</h3>
                        </div>
                        <div class="card-body">
                            <form method="get" class="form-inline">
                                <div class="form-group mr-3">
                                    <label for="device_id" class="mr-2">Device:</label>
                                    <select name="device_id" id="device_id" class="form-control" onchange="this.form.submit()">
                                        <option value="">-- Select Device --</option>
                                        <?php if ($devices && $devices->num_rows > 0): ?>
                                            <?php while ($device = $devices->fetch_assoc()): ?>
                                                <option value="<?= $device['id'] ?>" <?= $selected_device == $device['id'] ? 'selected' : '' ?>>
                                                    <?= htmlspecialchars($device['name']) ?>
                                                </option>
                                            <?php endwhile; ?>
                                        <?php endif; ?>
                                    </select>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
            
            <?php if ($selected_device): ?>
                <div class="row">
                    <div class="col-md-12">
                        <div class="card">
                            <div class="card-header">
                                <h3 class="card-title">Enrolled Members</h3>
                                <div class="card-tools">
                                    <button type="button" class="btn btn-primary btn-sm" data-toggle="modal" data-target="#enrollMemberModal">
                                        <i class="fas fa-user-plus"></i> Enroll Member
                                    </button>
                                    <button type="button" class="btn btn-success btn-sm" data-toggle="modal" data-target="#bulkEnrollModal">
                                        <i class="fas fa-users"></i> Bulk Enroll
                                    </button>
                                </div>
                            </div>
                            <div class="card-body">
                                <table class="table table-bordered table-striped">
                                    <thead>
                                        <tr>
                                            <th>Name</th>
                                            <th>Gender</th>
                                            <th>Phone</th>
                                            <th>Hikvision ID</th>
                                            <th>Biometric Type</th>
                                            <th>Enrolled On</th>
                                            <th>Last Updated</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if ($enrolled_members && $enrolled_members->num_rows > 0): ?>
                                            <?php while ($member = $enrolled_members->fetch_assoc()): ?>
                                                <tr>
                                                    <td><?= htmlspecialchars($member['firstname'] . ' ' . $member['lastname']) ?></td>
                                                    <td><?= htmlspecialchars($member['gender']) ?></td>
                                                    <td><?= htmlspecialchars($member['phone']) ?></td>
                                                    <td><?= htmlspecialchars($member['hikvision_user_id']) ?></td>
                                                    <td>Fingerprint</td>
                                                    <td><?= date('Y-m-d', strtotime($member['created_at'])) ?></td>
                                                    <td><?= $member['updated_at'] ? date('Y-m-d', strtotime($member['updated_at'])) : 'N/A' ?></td>
                                                    <td>
                                                        <button class="btn btn-info btn-sm" onclick="editEnrollment(<?= $member['id'] ?>, <?= $member['member_id'] ?>, '<?= htmlspecialchars($member['firstname'] . ' ' . $member['lastname']) ?>', '<?= htmlspecialchars($member['hikvision_user_id']) ?>')">
                                                            <i class="fas fa-edit"></i> Edit
                                                        </button>
                                                        <button class="btn btn-danger btn-sm" onclick="removeEnrollment(<?= $member['id'] ?>, '<?= htmlspecialchars($member['firstname'] . ' ' . $member['lastname']) ?>')">
                                                            <i class="fas fa-trash"></i> Remove
                                                        </button>
                                                    </td>
                                                </tr>
                                            <?php endwhile; ?>
                                        <?php else: ?>
                                            <tr>
                                                <td colspan="7" class="text-center">No enrolled members found</td>
                                            </tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </section>
</div>

<!-- Enroll Member Modal -->
<div class="modal fade" id="enrollMemberModal">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h4 class="modal-title">Enroll Member</h4>
                <button type="button" class="close" data-dismiss="modal">&times;</button>
            </div>
            <form method="post">
                <div class="modal-body">
                    <input type="hidden" name="action" value="enroll_member">
                    <input type="hidden" name="device_id" value="<?= $selected_device ?>">
                    
                    <div class="form-group">
                        <label for="member_id">Select Member <span class="text-danger">*</span></label>
                        <select class="form-control select2" id="member_id" name="member_id" style="width: 100%;" required>
                            <option value="">-- Select Member --</option>
                            <!-- Members will be loaded via AJAX -->
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label for="hikvision_user_id">Hikvision User ID <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="hikvision_user_id" name="hikvision_user_id" required>
                        <small class="form-text text-muted">This ID must match the ID used in the Hikvision device for fingerprint enrollment.</small>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Enroll Member</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Enrollment Modal -->
<div class="modal fade" id="editEnrollmentModal">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h4 class="modal-title">Edit Enrollment</h4>
                <button type="button" class="close" data-dismiss="modal">&times;</button>
            </div>
            <form method="post">
                <div class="modal-body">
                    <input type="hidden" name="action" value="enroll_member">
                    <input type="hidden" name="device_id" value="<?= $selected_device ?>">
                    <input type="hidden" name="member_id" id="edit_member_id">
                    
                    <div class="form-group">
                        <label>Member</label>
                        <input type="text" class="form-control" id="edit_member_name" readonly>
                    </div>
                    
                    <div class="form-group">
                        <label for="edit_hikvision_user_id">Hikvision User ID <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="edit_hikvision_user_id" name="hikvision_user_id" required>
                        <small class="form-text text-muted">This ID must match the ID used in the Hikvision device for fingerprint enrollment.</small>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save Changes</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Remove Enrollment Modal -->
<div class="modal fade" id="removeEnrollmentModal">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h4 class="modal-title">Remove Enrollment</h4>
                <button type="button" class="close" data-dismiss="modal">&times;</button>
            </div>
            <form method="post">
                <div class="modal-body">
                    <input type="hidden" name="action" value="remove_enrollment">
                    <input type="hidden" name="enrollment_id" id="remove_enrollment_id">
                    
                    <p>Are you sure you want to remove the enrollment for <span id="remove_member_name" class="font-weight-bold"></span>?</p>
                    <p class="text-danger">This will remove the member's enrollment from the selected device.</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-danger">Remove</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Bulk Enroll Modal -->
<div class="modal fade" id="bulkEnrollModal">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h4 class="modal-title">Bulk Enroll Members</h4>
                <button type="button" class="close" data-dismiss="modal">&times;</button>
            </div>
            <form method="post">
                <div class="modal-body">
                    <input type="hidden" name="action" value="bulk_enroll">
                    <input type="hidden" name="bulk_device_id" value="<?= $selected_device ?>">
                    
                    <div class="form-group">
                        <label for="start_id">Starting Hikvision User ID</label>
                        <input type="number" class="form-control" id="start_id" name="start_id" value="1000" min="1">
                        <small class="form-text text-muted">Sequential IDs will be assigned starting from this number for fingerprint enrollment.</small>
                    </div>
                    
                    <div class="form-group">
                        <label>Select Members</label>
                        <div class="card">
                            <div class="card-header">
                                <div class="input-group">
                                    <input type="text" class="form-control" id="member_search" placeholder="Search members...">
                                    <div class="input-group-append">
                                        <button type="button" class="btn btn-default" id="search_button">
                                            <i class="fas fa-search"></i>
                                        </button>
                                    </div>
                                </div>
                            </div>
                            <div class="card-body" style="max-height: 300px; overflow-y: auto;">
                                <div class="form-check mb-2">
                                    <input class="form-check-input" type="checkbox" id="select_all">
                                    <label class="form-check-label" for="select_all">
                                        Select All
                                    </label>
                                </div>
                                <hr>
                                <div id="member_list">
                                    <!-- Members will be loaded via AJAX -->
                                    <div class="text-center">
                                        <p>Loading members...</p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Enroll Selected Members</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
    $(document).ready(function() {
        // Initialize Select2
        $('.select2').select2({
            ajax: {
                url: '<?= BASE_URL ?>/ajax/search_members.php',
                dataType: 'json',
                delay: 250,
                data: function(params) {
                    return {
                        q: params.term,
                        page: params.page
                    };
                },
                processResults: function(data, params) {
                    params.page = params.page || 1;
                    return {
                        results: data.items,
                        pagination: {
                            more: (params.page * 30) < data.total_count
                        }
                    };
                },
                cache: true
            },
            placeholder: 'Search for a member',
            minimumInputLength: 2,
            templateResult: formatMember,
            templateSelection: formatMemberSelection
        });
        
        // Load members for bulk enrollment
        loadMembers();
        
        // Search button click
        $('#search_button').click(function() {
            loadMembers($('#member_search').val());
        });
        
        // Search on enter key
        $('#member_search').keypress(function(e) {
            if (e.which == 13) {
                loadMembers($('#member_search').val());
                e.preventDefault();
            }
        });
        
        // Select all checkbox
        $('#select_all').change(function() {
            $('.member-checkbox').prop('checked', $(this).prop('checked'));
        });
        
        // Update select all when individual checkboxes change
        $(document).on('change', '.member-checkbox', function() {
            if ($('.member-checkbox:checked').length == $('.member-checkbox').length) {
                $('#select_all').prop('checked', true);
            } else {
                $('#select_all').prop('checked', false);
            }
        });
    });
    
    // Format member in dropdown
    function formatMember(member) {
        if (!member.id) {
            return member.text;
        }
        
        return $('<span>' + member.text + ' (' + member.phone + ')</span>');
    }
    
    // Format selected member
    function formatMemberSelection(member) {
        return member.text;
    }
    
    // Load members for bulk enrollment
    function loadMembers(search = '') {
        $('#member_list').html('<div class="text-center"><p>Loading members...</p></div>');
        
        $.ajax({
            url: '<?= BASE_URL ?>/ajax/get_members.php',
            type: 'GET',
            data: {
                search: search,
                device_id: <?= $selected_device ?? 0 ?>
            },
            success: function(response) {
                if (response.success) {
                    var html = '';
                    
                    if (response.members.length === 0) {
                        html = '<div class="text-center"><p>No members found</p></div>';
                    } else {
                        $.each(response.members, function(i, member) {
                            html += '<div class="form-check mb-2">';
                            html += '<input class="form-check-input member-checkbox" type="checkbox" name="member_ids[]" value="' + member.id + '" id="member_' + member.id + '">';
                            html += '<label class="form-check-label" for="member_' + member.id + '">';
                            html += member.name + ' (' + member.phone + ')';
                            html += '</label>';
                            html += '</div>';
                        });
                    }
                    
                    $('#member_list').html(html);
                } else {
                    $('#member_list').html('<div class="text-center"><p class="text-danger">Error loading members</p></div>');
                }
            },
            error: function() {
                $('#member_list').html('<div class="text-center"><p class="text-danger">Error loading members</p></div>');
            }
        });
    }
    
    // Edit enrollment
    function editEnrollment(id, memberId, memberName, hikvisionUserId) {
        document.getElementById('edit_member_id').value = memberId;
        document.getElementById('edit_member_name').value = memberName;
        document.getElementById('edit_hikvision_user_id').value = hikvisionUserId;
        
        $('#editEnrollmentModal').modal('show');
    }
    
    // Remove enrollment
    function removeEnrollment(id, memberName) {
        document.getElementById('remove_enrollment_id').value = id;
        document.getElementById('remove_member_name').textContent = memberName;
        
        $('#removeEnrollmentModal').modal('show');
    }
</script>

<?php include '../includes/footer.php'; ?>
