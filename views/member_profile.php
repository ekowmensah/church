<?php
require_once __DIR__.'/../includes/member_auth.php';

// Check if member or user is logged in
if (!isset($_SESSION['member_id']) && !isset($_SESSION['user_id'])) {
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}
// Only allow members to view their own profile here
if (isset($_SESSION['role_id']) && $_SESSION['role_id'] == 1) {
    // Super admin should use member_view.php for others
    header('Location: member_view.php?id=' . intval($_SESSION['member_id']));
    exit;
}
if (function_exists('has_permission') && has_permission('manage_members')) {
    // Admins/managers should use member_view.php
    header('Location: member_view.php?id=' . intval($_SESSION['member_id']));
    exit;
}
// Prefer member_id if present
$member_id = isset($_SESSION['member_id']) ? intval($_SESSION['member_id']) : 0;
if (!$member_id && isset($_GET['id'])) {
    $member_id = intval($_GET['id']);
}
$member_id = intval($_SESSION['member_id']);
$stmt = $conn->prepare('SELECT * FROM members WHERE id = ?');
$stmt->bind_param('i', $member_id);
$stmt->execute();
$member = $stmt->get_result()->fetch_assoc();

ob_start();
?>
<!--<div class="content-header">
    <div class="container-fluid">
        <div class="row mb-2">
            <div class="col-sm-6">
                <h1 class="m-0">My Profile</h1>
            </div>
        </div>
    </div>
</div> -->
<div class="card card-primary card-outline">
    <div class="card-body box-profile">
        <div class="row">
            <div class="col-md-4 text-center align-self-start mb-3 mb-md-0">
                <img class="profile-user-img img-fluid img-circle"
                     src="<?php echo !empty($member['photo']) && file_exists(__DIR__.'/../uploads/members/' . $member['photo']) ? BASE_URL . '/uploads/members/' . rawurlencode($member['photo']) . '?v=' . time() : BASE_URL . '/assets/img/undraw_profile.svg'; ?>"
                     alt="Profile picture" style="width:120px;height:120px;object-fit:cover;">
                <h3 class="profile-username mt-2 mb-0"><?php echo htmlspecialchars($member['first_name'] . ' ' . $member['last_name']); ?></h3>
                <p class="text-muted">CRN: <?php echo htmlspecialchars($member['crn']); ?></p>
            </div>
            <div class="col-md-8">
                <div class="card mb-4 shadow border-0" id="church-membership-details" style="background: linear-gradient(90deg, #f8fafc 80%, #e3f0ff 100%); border-left: 6px solid #007bff;">
                    <div class="card-header bg-primary text-white font-weight-bold d-flex align-items-center" style="font-size:1.15rem;"><i class="fas fa-church fa-lg mr-2"></i>Church Membership Details</div>
                    <div class="row p-3">
    <div class="col-12 mb-3"><hr class="my-2"></div>
    <!-- Row 1 -->
    <div class="col-md-3 mb-3">
        <div class="d-flex align-items-center mb-1"><i class="fas fa-water fa-fw text-info mr-2"></i> <span class="small text-muted">Baptized Status</span></div>
        <?php if ($member['baptized'] === 'Yes'): ?>
            <span class="badge badge-success px-3 py-2"><i class="fas fa-check-circle"></i> Yes</span>
        <?php elseif ($member['baptized'] === 'No'): ?>
            <span class="badge badge-danger px-3 py-2"><i class="fas fa-times-circle"></i> No</span>
        <?php else: ?>
            <span class="badge badge-secondary px-3 py-2"><i class="fas fa-question-circle"></i> Unknown</span>
        <?php endif; ?>
    </div>
    <div class="col-md-3 mb-3">
        <div class="d-flex align-items-center mb-1"><i class="fas fa-calendar-day fa-fw text-secondary mr-2"></i> <span class="small text-muted">Baptism Date</span></div>
        <span><?php 
        if ($member['baptized'] === 'Yes' && $member['date_of_baptism']) {
            echo date('F j, Y', strtotime($member['date_of_baptism']));
        } else {
            echo '<span class="text-muted">Not Baptized</span>';
        }
        ?></span>
    </div>
    <div class="col-md-3 mb-3">
        <div class="d-flex align-items-center mb-1"><i class="fas fa-certificate fa-fw text-success mr-2"></i> <span class="small text-muted">Confirmation Status</span></div>
        <?php if ($member['confirmed'] === 'Yes'): ?>
            <span class="badge badge-success px-3 py-2"><i class="fas fa-check-circle"></i> Yes</span>
        <?php elseif ($member['confirmed'] === 'No'): ?>
            <span class="badge badge-danger px-3 py-2"><i class="fas fa-times-circle"></i> No</span>
        <?php else: ?>
            <span class="badge badge-secondary px-3 py-2"><i class="fas fa-question-circle"></i> Unknown</span>
        <?php endif; ?>
    </div>
    <div class="col-md-3 mb-3">
        <div class="d-flex align-items-center mb-1"><i class="fas fa-calendar-check fa-fw text-secondary mr-2"></i> <span class="small text-muted">Confirmation Date</span></div>
        <span><?php 
        if ($member['confirmed'] === 'Yes' && $member['date_of_confirmation']) {
            echo date('F j, Y', strtotime($member['date_of_confirmation']));
        } else {
            echo '<span class="text-muted">Not Confirmed</span>';
        }
        ?></span>
    </div>
    <!-- Row 2 -->
    <div class="col-md-3 mb-3">
        <div class="d-flex align-items-center mb-1"><i class="fas fa-users fa-fw text-primary mr-2"></i> <span class="small text-muted">Membership Status</span></div>
        <?php
        $is_confirmed = (isset($member['confirmed']) && strtolower($member['confirmed']) === 'yes');
        $is_baptized = (isset($member['baptized']) && strtolower($member['baptized']) === 'yes');
        $membership_status = ($is_confirmed && $is_baptized) ? 'Full Member' : 'Cathcumen';
        $badge_color = ($is_confirmed && $is_baptized) ? 'badge-success' : 'badge-primary';
        ?>
        <span class="badge badge-pill <?=$badge_color?> px-3 py-2"><?php echo $membership_status; ?></span>
    </div>
    <div class="col-md-3 mb-3">
        <div class="d-flex align-items-center mb-1"><i class="fas fa-calendar-alt fa-fw text-warning mr-2"></i> <span class="small text-muted">Date of Enrollment</span></div>
        <span><?php echo $member['date_of_enrollment'] ? date('F j, Y', strtotime($member['date_of_enrollment'])) : '<span class="text-muted">N/A</span>'; ?></span>
    </div>
    <div class="col-md-3 mb-3">
        <div class="d-flex align-items-center mb-1"><i class="fas fa-book fa-fw text-info mr-2"></i> <span class="small text-muted">Bible Class</span></div>
        <?php
        $class_name = 'Not Assigned';
        if (!empty($member['class_id'])) {
            $stmt_class = $conn->prepare('SELECT name FROM bible_classes WHERE id = ?');
            $stmt_class->bind_param('i', $member['class_id']);
            $stmt_class->execute();
            $res_class = $stmt_class->get_result();
            if ($row_class = $res_class->fetch_assoc()) {
                $class_name = $row_class['name'];
            }
            $stmt_class->close();
        }
        echo '<span class="badge badge-pill badge-primary px-3 py-2">' . htmlspecialchars($class_name) . '</span>';
        ?>
    </div>
    <div class="col-md-3 mb-3">
        <div class="d-flex align-items-center mb-1"><i class="fas fa-users-cog fa-fw text-purple mr-2"></i> <span class="small text-muted">Organizations</span></div>
        <?php
        $orgs = [];
        $stmt_org = $conn->prepare('SELECT o.name FROM organizations o INNER JOIN member_organizations mo ON mo.organization_id = o.id WHERE mo.member_id = ?');
        $stmt_org->bind_param('i', $member['id']);
        $stmt_org->execute();
        $res_org = $stmt_org->get_result();
        while ($row_org = $res_org->fetch_assoc()) {
            $orgs[] = $row_org['name'];
        }
        $stmt_org->close();
        if (count($orgs) > 0) {
            foreach ($orgs as $org) echo '<span class="badge badge-pill badge-info mr-1 mb-1">' . htmlspecialchars($org) . '</span>';
        } else {
            echo '<span class="text-muted">Not Assigned</span>';
        }
        ?>
    </div>
</div>
                    </div>
                </div>
            </div>
        </div>

        <div class="row">
            <div class="col-md-6">
                <div class="card mb-4 shadow rounded border-0 bg-white h-100">
                    <div class="card-header bg-primary text-white font-weight-bold d-flex align-items-center">
                        <i class="fas fa-user fa-lg mr-2"></i> Personal Information
                    </div>
                    <div class="card-body p-3">
                        <div class="row">
                            <div class="col-6 mb-2"><span class="font-weight-bold">First Name:</span><br><?php echo htmlspecialchars($member['first_name']); ?></div>
                            <div class="col-6 mb-2"><span class="font-weight-bold">Middle Name:</span><br><?php echo htmlspecialchars($member['middle_name']); ?></div>
                            <div class="col-6 mb-2"><span class="font-weight-bold">Last Name:</span><br><?php echo htmlspecialchars($member['last_name']); ?></div>
                            <div class="col-6 mb-2"><span class="font-weight-bold">Gender:</span><br><?php echo htmlspecialchars($member['gender']); ?></div>
                            <div class="col-6 mb-2"><span class="font-weight-bold">Date of Birth:</span><br><?php echo $member['dob'] ? date('F j, Y', strtotime($member['dob'])) : '<span class="text-muted">N/A</span>'; ?></div>
                            <div class="col-6 mb-2"><span class="font-weight-bold">Place of Birth:</span><br><?php echo htmlspecialchars($member['place_of_birth']); ?></div>
                            <div class="col-6 mb-2"><span class="font-weight-bold">Marital Status:</span><br><?php echo htmlspecialchars($member['marital_status']); ?></div>
                            <div class="col-6 mb-2"><span class="font-weight-bold">Home Town:</span><br><?php echo htmlspecialchars($member['home_town']); ?></div>
                            <div class="col-6 mb-2"><span class="font-weight-bold">Region:</span><br><?php echo htmlspecialchars($member['region']); ?></div>
                            <div class="col-6 mb-2"><span class="font-weight-bold">Profession:</span><br><?php echo htmlspecialchars($member['profession']); ?></div>
                            <div class="col-6 mb-2"><span class="font-weight-bold">Employment Status:</span><br><?php echo htmlspecialchars($member['employment_status']); ?></div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="card mb-4 shadow rounded border-0 bg-white h-100">
                    <div class="card-header bg-success text-white font-weight-bold d-flex align-items-center">
                        <i class="fas fa-address-book fa-lg mr-2"></i> Contact Information
                    </div>
                    <div class="card-body p-3">
                        <div class="row">
                            <div class="col-12 mb-2"><span class="font-weight-bold">Email:</span><br><?php echo htmlspecialchars($member['email']); ?></div>
                            <div class="col-6 mb-2"><span class="font-weight-bold">Phone:</span><br><?php echo htmlspecialchars($member['phone']); ?></div>
                            <div class="col-6 mb-2"><span class="font-weight-bold">Telephone:</span><br><?php echo htmlspecialchars($member['telephone']); ?></div>
                            <div class="col-12 mb-2"><span class="font-weight-bold">Address:</span><br><?php echo htmlspecialchars($member['address']); ?></div>
                            <div class="col-12 mb-2"><span class="font-weight-bold">GPS Address:</span><br><?php echo htmlspecialchars($member['gps_address']); ?></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="row">
            <div class="col-md-12">
                <div class="card mb-4 shadow rounded border-0 bg-white">
                    <div class="card-header bg-info text-white font-weight-bold d-flex align-items-center">
                        <i class="fas fa-user-shield fa-lg mr-2"></i> Emergency Contacts
                    </div>
                    <div class="card-body">
                        <div class="row">
                        <?php
                        $contacts = [];
                        $ecq = $conn->prepare("SELECT name, mobile, relationship FROM member_emergency_contacts WHERE member_id = ?");
                        $ecq->bind_param('i', $member['id']);
                        $ecq->execute();
                        $ecq_res = $ecq->get_result();
                        while ($row = $ecq_res->fetch_assoc()) $contacts[] = $row;
                        $ecq->close();
                        if (count($contacts) === 0): ?>
                          <div class="col-12 text-center py-4"><span class="text-muted"><i class="fas fa-exclamation-circle fa-2x mb-2"></i><br>No emergency contacts recorded.</span></div>
                        <?php else: ?>
                          <?php foreach ($contacts as $i => $c): ?>
                            <div class="col-md-6 col-lg-4 mb-3">
                              <div class="card h-100 border-info shadow-sm">
                                <div class="card-body">
                                  <h5 class="card-title mb-2"><i class="fas fa-user-friends text-info mr-1"></i> <?=htmlspecialchars($c['name'])?></h5>
                                  <p class="mb-1"><span class="font-weight-bold">Mobile:</span> <?=htmlspecialchars($c['mobile'])?></p>
                                  <p class="mb-1"><span class="font-weight-bold">Relationship:</span> <?=htmlspecialchars($c['relationship'])?></p>
                                </div>
                              </div>
                            </div>
                          <?php endforeach; ?>
                        <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <a href="<?php echo BASE_URL; ?>/views/member_profile_edit.php" class="btn btn-primary btn-block"><b>Edit Profile</b></a>

        <!-- <div class="card mt-4">
            <div class="card-header bg-secondary text-white font-weight-bold"><i class="fas fa-sms mr-2"></i>Registration & Transfer SMS Notifications</div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-bordered table-sm">
                        <thead>
                            <tr>
                                <th>Date/Time</th>
                                <th>Message</th>
                                <th>Status</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php
                        $sms_stmt = $conn->prepare("SELECT * FROM sms_logs WHERE phone = ? ORDER BY sent_at DESC");
                        $sms_stmt->bind_param('s', $member['phone']);
                        $sms_stmt->execute();
                        $sms_res = $sms_stmt->get_result();
                        while ($sms = $sms_res->fetch_assoc()): ?>
                            <tr>
                                <td><?=htmlspecialchars($sms['sent_at'])?></td>
                                <td style="max-width:300px;overflow:auto;word-break:break-word;">
                                    <?=htmlspecialchars($sms['message'])?>
                                </td>
                                <td>
                                    <?php
                                    $status = $sms['status'] ?? '';
                                    if (stripos($status, 'fail') !== false) {
                                        echo '<span class="badge badge-danger">Failed</span>';
                                    } elseif (stripos($status, 'sent') !== false || stripos($status, 'success') !== false) {
                                        echo '<span class="badge badge-success">Sent</span>';
                                    } else {
                                        echo '<span class="badge badge-secondary">' . htmlspecialchars($status) . '</span>';
                                    }
                                    ?>
                                </td>
                                <td>
                                    <?php if (isset($sms['status']) && stripos($sms['status'], 'fail') !== false): ?>
                                        <button class="btn btn-sm btn-warning resend-sms-btn ml-2" data-log-id="<?=intval($sms['id'])?>">Resend</button>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div> -->
<?php
$page_content = ob_get_clean();
include '../includes/layout.php';
?>
    </div>
</div>

