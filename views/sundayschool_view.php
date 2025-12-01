<?php
session_start();
require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../helpers/auth.php';
require_once __DIR__.'/../helpers/permissions_v2.php';

// Authentication check
if (!is_logged_in()) {
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}

// Permission check
if (!has_permission('view_sundayschool_list')) {
    http_response_code(403);
    echo '<div class="alert alert-danger"><h4>403 Forbidden</h4><p>You do not have permission to access this page.</p></div>';
    exit;
}

$id = isset($_GET['id']) ? intval($_GET['id']) : 0;
if (!$id) {
    header('Location: sundayschool_list.php');
    exit;
}

$stmt = $conn->prepare('SELECT ss.*, c.name as church_name, c.church_code, c.circuit_code, bc.name as class_name,
                        CASE 
                            WHEN ss.father_is_member = "yes" AND fm.id IS NOT NULL 
                            THEN CONCAT(fm.last_name, " ", fm.first_name, " ", COALESCE(fm.middle_name, ""))
                            ELSE ss.father_name 
                        END as display_father_name,
                        CASE 
                            WHEN ss.mother_is_member = "yes" AND mm.id IS NOT NULL 
                            THEN CONCAT(mm.last_name, " ", mm.first_name, " ", COALESCE(mm.middle_name, ""))
                            ELSE ss.mother_name 
                        END as display_mother_name,
                        CASE 
                            WHEN ss.father_is_member = "yes" AND fm.id IS NOT NULL 
                            THEN fm.phone
                            ELSE ss.father_contact 
                        END as display_father_contact,
                        CASE 
                            WHEN ss.mother_is_member = "yes" AND mm.id IS NOT NULL 
                            THEN mm.phone
                            ELSE ss.mother_contact 
                        END as display_mother_contact,
                        CASE 
                            WHEN ss.father_is_member = "yes" AND fm.id IS NOT NULL 
                            THEN fm.profession
                            ELSE ss.father_occupation 
                        END as display_father_occupation,
                        CASE 
                            WHEN ss.mother_is_member = "yes" AND mm.id IS NOT NULL 
                            THEN mm.profession
                            ELSE ss.mother_occupation 
                        END as display_mother_occupation
                        FROM sunday_school ss 
                        LEFT JOIN churches c ON ss.church_id = c.id 
                        LEFT JOIN bible_classes bc ON ss.class_id = bc.id 
                        LEFT JOIN members fm ON ss.father_member_id = fm.id AND ss.father_is_member = "yes"
                        LEFT JOIN members mm ON ss.mother_member_id = mm.id AND ss.mother_is_member = "yes"
                        WHERE ss.id = ? LIMIT 1');
$stmt->bind_param('i', $id);
$stmt->execute();
$result = $stmt->get_result();
$child = $result->fetch_assoc();
if (!$child) {
    header('Location: sundayschool_list.php');
    exit;
}

function calc_age($dob) {
    if ($dob && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dob)) {
        $dob_dt = new DateTime($dob);
        $now = new DateTime();
        $years = $now->diff($dob_dt)->y;
        return $years . ' yrs';
    }
    return '';
}

ob_start();
?>
<style>
.ss-profile-bg {
    background: linear-gradient(135deg, #f8fafc 60%, #e9ecef 100%);
    min-height: 100vh;
}
.ss-card {
    border-radius: 1.25rem;
    box-shadow: 0 2px 16px rgba(0,0,0,0.07);
    overflow: hidden;
}
.ss-photo {
    width: 140px; height: 140px; object-fit: cover; border-radius: 50%; box-shadow: 0 2px 8px rgba(0,0,0,0.10);
    border: 4px solid #fff;
    margin-top: -70px;
    background: #fff;
}
.ss-section-title {
    font-size: 1.2rem; font-weight: 600; color: #495057; margin-bottom: 10px;
    letter-spacing: 0.02em;
}
@media (max-width: 767px) {
    .ss-card { border-radius: 0.75rem; }
    .ss-photo { width: 100px; height: 100px; margin-top: -50px; }
}
</style>
<div class="ss-profile-bg py-4">
  <div class="container">
    <div class="row justify-content-center">
      <div class="col-lg-10">
        <div class="card ss-card p-0 mb-4 position-relative">
          <?php if (!empty($child['transferred_at'])): ?>
            <span class="badge badge-success position-absolute" style="top:18px;right:18px;font-size:1rem;z-index:10;">Transferred</span>
          <?php else: ?>
            <span class="badge badge-secondary position-absolute" style="top:18px;right:18px;font-size:1rem;z-index:10;">Not Transferred</span>
          <?php endif; ?>
          <div class="row no-gutters align-items-center">
            <div class="col-md-4 text-center bg-white pt-4 pb-3">
              <img src="<?= $child['photo'] ? (BASE_URL.'/uploads/sundayschool/'.rawurlencode($child['photo'])) : (BASE_URL.'/assets/img/avatar-child.png') ?>" class="ss-photo" alt="Child Photo">
              <h4 class="mt-3 mb-1 font-weight-bold text-primary">SRN: <?= htmlspecialchars($child['srn']) ?></h4>
              <span class="badge badge-success mb-2">Sunday School</span>
              <div class="mb-2">
                <span class="badge badge-info"><i class="fa fa-church"></i> <?= htmlspecialchars($child['church_name']) ?></span>
                <span class="badge badge-secondary"><i class="fa fa-users"></i> <?= htmlspecialchars($child['class_name']) ?></span>
              </div>
              <?php if(empty($child['transferred_at'])): ?>
              <a href="sundayschool_transfer.php?id=<?= $child['id'] ?>" class="btn btn-success btn-block mb-2"><i class="fa fa-exchange-alt"></i> Transfer to Member</a>
              <?php endif; ?>
              <a href="sundayschool_form.php?id=<?= $child['id'] ?>" class="btn btn-warning btn-block mb-2"><i class="fa fa-edit"></i> Edit</a>
              <a href="sundayschool_list.php" class="btn btn-outline-secondary btn-block"><i class="fa fa-arrow-left"></i> Back to List</a>
            </div>
            <div class="col-md-8 p-4">
              <h2 class="font-weight-bold mb-2 text-dark ss-section-title"><i class="fa fa-child text-primary"></i> <?= htmlspecialchars(trim($child['last_name'].' '.$child['middle_name'].' '.$child['first_name'])) ?></h2>
              <div class="row mb-2">
                <div class="col-sm-6 mb-2"><i class="fa fa-birthday-cake text-muted"></i> <b>Date of Birth:</b> <?= htmlspecialchars($child['dob']) ?> <?php $age = calc_age($child['dob']); if($age): ?><span class="text-muted small">(<?= $age ?>)</span><?php endif; ?></div>
                <div class="col-sm-6 mb-2"><i class="fa fa-phone text-muted"></i> <b>Contact:</b> <?= htmlspecialchars($child['contact']) ?></div>
                <div class="col-sm-6 mb-2"><i class="fa fa-school text-muted"></i> <b>School Attend:</b> <?= htmlspecialchars($child['school_attend']) ?></div>
                <div class="col-sm-6 mb-2"><i class="fa fa-map-marker-alt text-muted"></i> <b>GPS Address:</b> <?= htmlspecialchars($child['gps_address']) ?></div>
                <div class="col-sm-6 mb-2"><i class="fa fa-home text-muted"></i> <b>Residential Address:</b> <?= htmlspecialchars($child['residential_address']) ?></div>
                <div class="col-sm-6 mb-2"><i class="fa fa-building text-muted"></i> <b>Organisation:</b> <?= htmlspecialchars($child['organization']) ?></div>
              </div>
              <div class="row mt-3">
                <div class="col-md-6 mb-3 mb-md-0">
                  <div class="card border-0 shadow-sm h-100">
                    <div class="card-body">
                      <div class="ss-section-title"><i class="fa fa-male text-info"></i> Father</div>
                      <div><b>Name:</b> <?= htmlspecialchars($child['display_father_name']) ?></div>
                      <div><b>Contact:</b> <?= htmlspecialchars($child['display_father_contact']) ?></div>
                      <div><b>Occupation:</b> <?= htmlspecialchars($child['display_father_occupation']) ?></div>
                    </div>
                  </div>
                </div>
                <div class="col-md-6">
                  <div class="card border-0 shadow-sm h-100">
                    <div class="card-body">
                      <div class="ss-section-title"><i class="fa fa-female text-pink"></i> Mother</div>
                      <div><b>Name:</b> <?= htmlspecialchars($child['display_mother_name']) ?></div>
                      <div><b>Contact:</b> <?= htmlspecialchars($child['display_mother_contact']) ?></div>
                      <div><b>Occupation:</b> <?= htmlspecialchars($child['display_mother_occupation']) ?></div>
                    </div>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>
<?php $page_content = ob_get_clean(); include '../includes/layout.php'; ?>
