<?php
require_once __DIR__.'/../../config/config.php';
require_once __DIR__.'/../../helpers/auth.php';
require_once __DIR__.'/../../helpers/permissions_v2.php';
require_once __DIR__.'/../../helpers/report_scope.php';
require_once __DIR__.'/../../helpers/report_pagination.php';

// Only allow logged-in users
if (!is_logged_in()) {
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}

// Robust super admin bypass and permission check
$is_super_admin = is_super_admin();

if (!$is_super_admin && !has_permission('view_membership_report')) {
    http_response_code(403);
    if (file_exists(__DIR__.'/../../views/errors/403.php')) {
        include __DIR__.'/../../views/errors/403.php';
    } else if (file_exists(__DIR__.'/../errors/403.php')) {
        include __DIR__.'/../errors/403.php';
    } else {
        echo '<div class="alert alert-danger"><h4>403 Forbidden</h4><p>You do not have permission to access this report.</p></div>';
    }
    exit;
}

// Set permission flags for UI elements
$can_view = true; // Already validated above
$can_export = $is_super_admin || has_permission('export_membership_report');

$page_title = 'Membership Report';

ob_start();

// Fetch only filter options the current account is authorized to use.
$actorChurchId = report_scope_current_church_id($conn);
$churchOptionWhere = $is_super_admin ? '' : ' WHERE id = ' . max(0, $actorChurchId);
$classOptionWhere = $is_super_admin ? '' : ' WHERE church_id = ' . max(0, $actorChurchId);
$churches = $conn->query("SELECT id, name FROM churches{$churchOptionWhere} ORDER BY name");
$classes = $conn->query("SELECT id, name FROM bible_classes{$classOptionWhere} ORDER BY name");

// Handle filters
$where = "WHERE 1=1";
$params = [];
$types = '';
if (!$is_super_admin) {
    $where .= ' AND m.church_id = ?';
    $params[] = $actorChurchId;
    $types .= 'i';
} elseif (!empty($_GET['church_id'])) {
    $where .= " AND m.church_id = ?";
    $params[] = intval($_GET['church_id']);
    $types .= 'i';
}
if (!empty($_GET['class_id'])) {
    $where .= " AND m.class_id = ?";
    $params[] = intval($_GET['class_id']);
    $types .= 'i';
}
if (!empty($_GET['status'])) {
    $where .= " AND m.status = ?";
    $params[] = $_GET['status'];
    $types .= 's';
}
if (!empty($_GET['gender'])) {
    $where .= " AND m.gender = ?";
    $params[] = $_GET['gender'];
    $types .= 's';
}
if (!empty($_GET['marital_status'])) {
    $where .= " AND m.marital_status = ?";
    $params[] = $_GET['marital_status'];
    $types .= 's';
}
if (!empty($_GET['employment_status'])) {
    $where .= " AND m.employment_status = ?";
    $params[] = $_GET['employment_status'];
    $types .= 's';
}
if (!empty($_GET['baptism_status'])) {
    $where .= " AND m.baptized = ?";
    $params[] = $_GET['baptism_status'];
    $types .= 's';
}
if (!empty($_GET['confirmation_status'])) {
    $where .= " AND m.confirmed = ?";
    $params[] = $_GET['confirmation_status'];
    $types .= 's';
}
$membershipStatusMap = [
    'full' => 'Full Member',
    'catechumen' => 'Catechumen',
    'adherent' => 'Adherent',
    'junior' => 'Junior Member',
    'distant' => 'Distant Member',
    'invalid' => 'Invalid',
];
if (!empty($_GET['membership_status']) && isset($membershipStatusMap[$_GET['membership_status']])) {
    $where .= ' AND m.membership_status = ?';
    $params[] = $membershipStatusMap[$_GET['membership_status']];
    $types .= 's';
}
if (!empty($_GET['org_member'])) {
    $where .= " AND mo.organization_id = ?";
    $params[] = intval($_GET['org_member']);
    $types .= 'i';
}
if (!empty($_GET['dob'])) {
    $where .= " AND m.dob = ?";
    $params[] = $_GET['dob'];
    $types .= 's';
}
if (!empty($_GET['day_born'])) {
    $where .= " AND DAYNAME(m.dob) = ?";
    $params[] = $_GET['day_born'];
    $types .= 's';
}
// Age Bracket
if (!empty($_GET['age_bracket'])) {
    $today = date('Y-m-d');
    $age_bracket = $_GET['age_bracket'];
    if ($age_bracket == '0-12') {
        $where .= " AND TIMESTAMPDIFF(YEAR, m.dob, ?) BETWEEN 0 AND 12";
        $params[] = $today;
        $types .= 's';
    } elseif ($age_bracket == '13-17') {
        $where .= " AND TIMESTAMPDIFF(YEAR, m.dob, ?) BETWEEN 13 AND 17";
        $params[] = $today;
        $types .= 's';
    } elseif ($age_bracket == '18-35') {
        $where .= " AND TIMESTAMPDIFF(YEAR, m.dob, ?) BETWEEN 18 AND 35";
        $params[] = $today;
        $types .= 's';
    } elseif ($age_bracket == '36-59') {
        $where .= " AND TIMESTAMPDIFF(YEAR, m.dob, ?) BETWEEN 36 AND 59";
        $params[] = $today;
        $types .= 's';
    } elseif ($age_bracket == '60+') {
        $where .= " AND TIMESTAMPDIFF(YEAR, m.dob, ?) >= 60";
        $params[] = $today;
        $types .= 's';
    }
}
// Role of Service (join to member_roles_of_serving)
$role_join = '';
if (!empty($_GET['role_of_service'])) {
    $role_join = ' INNER JOIN member_roles_of_serving mrs ON mrs.member_id = m.id ';
    $where .= ' AND mrs.role_id = ?';
    $params[] = $_GET['role_of_service'];
    $types .= 'i';
}
if (!empty($_GET['from_date'])) {
    $where .= " AND m.created_at >= ?";
    $params[] = $_GET['from_date'];
    $types .= 's';
}
if (!empty($_GET['to_date'])) {
    $where .= " AND m.created_at <= ?";
    $params[] = $_GET['to_date'];
    $types .= 's';
}
$join = '';
if (!empty($_GET['org_member'])) {
    $join .= ' INNER JOIN member_organizations mo ON mo.member_id = m.id ';
}
if (!empty($_GET['role_of_service'])) {
    $join .= ' INNER JOIN member_roles_of_serving mrs ON mrs.member_id = m.id ';
}
$sql = "SELECT m.*, c.name AS class_name, ch.name AS church_name FROM members m $join LEFT JOIN bible_classes c ON m.class_id = c.id LEFT JOIN churches ch ON m.church_id = ch.id $where ORDER BY m.last_name, m.first_name, m.middle_name";
$pagination = report_paginate_query($conn, $sql, $types, $params);
$members = $pagination['result'];

// For growth chart: get monthly registration counts
$growth_join = '';
if (!empty($_GET['org_member'])) {
    $growth_join .= ' INNER JOIN member_organizations mo ON mo.member_id = m.id ';
}
if (!empty($_GET['role_of_service'])) {
    $growth_join .= ' INNER JOIN member_roles_of_serving mrs ON mrs.member_id = m.id ';
}
$growth_sql = "SELECT DATE_FORMAT(m.created_at, '%Y-%m') AS ym, COUNT(*) AS count FROM members m $growth_join LEFT JOIN bible_classes c ON m.class_id = c.id LEFT JOIN churches ch ON m.church_id = ch.id $where GROUP BY ym ORDER BY ym";
$growth_stmt = $conn->prepare($growth_sql);
if ($params) {
    $growth_stmt->bind_param($types, ...$params);
}
$growth_stmt->execute();
$growth_res = $growth_stmt->get_result();
$growth_labels = [];
$growth_counts = [];
while ($row = $growth_res->fetch_assoc()) {
    $growth_labels[] = $row['ym'];
    $growth_counts[] = $row['count'];
}
?>
<div class="container-fluid mt-4">
  <h2 class="mb-4">Membership Report</h2>
  <div class="card card-body mb-4 shadow-sm">
    <h5 class="mb-3"><i class="fas fa-filter mr-2"></i>Filter Members</h5>
    <form class="form-row" method="get">
    <div class="form-group col-md-2">
      <label>Church</label>
      <select name="church_id" class="form-control">
        <option value="">All</option>
        <?php if ($churches) while($ch = $churches->fetch_assoc()): ?>
          <option value="<?= $ch['id'] ?>"<?= isset($_GET['church_id']) && $_GET['church_id']==$ch['id'] ? ' selected' : '' ?>><?= htmlspecialchars($ch['name']) ?></option>
        <?php endwhile; ?>
      </select>
    </div>
    <div class="form-group col-md-2">
      <label>Bible Class</label>
      <select name="class_id" class="form-control">
        <option value="">All</option>
        <?php if ($classes) while($cl = $classes->fetch_assoc()): ?>
          <option value="<?= $cl['id'] ?>"<?= isset($_GET['class_id']) && $_GET['class_id']==$cl['id'] ? ' selected' : '' ?>><?= htmlspecialchars($cl['name']) ?></option>
        <?php endwhile; ?>
      </select>
    </div>
    <div class="form-group col-md-2">
      <label>Status</label>
      <select name="status" class="form-control">
        <option value="">All</option>
        <option value="active"<?= isset($_GET['status']) && $_GET['status']=='active' ? ' selected' : '' ?>>Active</option>
        <option value="pending"<?= isset($_GET['status']) && $_GET['status']=='pending' ? ' selected' : '' ?>>Pending</option>
        <option value="de-activated"<?= isset($_GET['status']) && $_GET['status']=='de-activated' ? ' selected' : '' ?>>De-Activated</option>
      </select>
    </div>
    <div class="form-group col-md-2">
      <label>Gender</label>
      <select name="gender" class="form-control">
        <option value="">All</option>
        <option value="male"<?= isset($_GET['gender']) && $_GET['gender']=='male' ? ' selected' : '' ?>>Male</option>
        <option value="female"<?= isset($_GET['gender']) && $_GET['gender']=='female' ? ' selected' : '' ?>>Female</option>
      </select>
    </div>
    <div class="form-group col-md-2">
      <label>Age Bracket</label>
      <select name="age_bracket" class="form-control">
        <option value="">All</option>
        <option value="0-12"<?= isset($_GET['age_bracket']) && $_GET['age_bracket']=='0-12' ? ' selected' : '' ?>>0-12</option>
        <option value="13-17"<?= isset($_GET['age_bracket']) && $_GET['age_bracket']=='13-17' ? ' selected' : '' ?>>13-17</option>
        <option value="18-35"<?= isset($_GET['age_bracket']) && $_GET['age_bracket']=='18-35' ? ' selected' : '' ?>>18-35</option>
        <option value="36-59"<?= isset($_GET['age_bracket']) && $_GET['age_bracket']=='36-59' ? ' selected' : '' ?>>36-59</option>
        <option value="60+"<?= isset($_GET['age_bracket']) && $_GET['age_bracket']=='60+' ? ' selected' : '' ?>>60+</option>
      </select>
    </div>
    <div class="form-group col-md-2">
      <label>Organizational Member</label>
      <?php include __DIR__ . '/_membership_report_organizations_dropdown.php'; ?>
    </div>
    <div class="form-group col-md-2">
      <label>Marital Status</label>
      <select name="marital_status" class="form-control">
        <option value="">All</option>
        <option value="single"<?= isset($_GET['marital_status']) && $_GET['marital_status']=='single' ? ' selected' : '' ?>>Single</option>
        <option value="married"<?= isset($_GET['marital_status']) && $_GET['marital_status']=='married' ? ' selected' : '' ?>>Married</option>
        <option value="divorced"<?= isset($_GET['marital_status']) && $_GET['marital_status']=='divorced' ? ' selected' : '' ?>>Divorced</option>
        <option value="widowed"<?= isset($_GET['marital_status']) && $_GET['marital_status']=='widowed' ? ' selected' : '' ?>>Widowed</option>
      </select>
    </div>
    <div class="form-group col-md-2">
      <label>Employment Status</label>
      <select name="employment_status" class="form-control">
        <option value="">All</option>
        <option value="employed"<?= isset($_GET['employment_status']) && $_GET['employment_status']=='employed' ? ' selected' : '' ?>>Employed</option>
        <option value="unemployed"<?= isset($_GET['employment_status']) && $_GET['employment_status']=='unemployed' ? ' selected' : '' ?>>Unemployed</option>
        <option value="student"<?= isset($_GET['employment_status']) && $_GET['employment_status']=='student' ? ' selected' : '' ?>>Student</option>
        <option value="retired"<?= isset($_GET['employment_status']) && $_GET['employment_status']=='retired' ? ' selected' : '' ?>>Retired</option>
      </select>
    </div>
    <div class="form-group col-md-2">
      <label>Baptism Status</label>
      <select name="baptism_status" class="form-control">
        <option value="">All</option>
        <option value="yes"<?= isset($_GET['baptism_status']) && $_GET['baptism_status']=='yes' ? ' selected' : '' ?>>Yes</option>
        <option value="no"<?= isset($_GET['baptism_status']) && $_GET['baptism_status']=='no' ? ' selected' : '' ?>>No</option>
      </select>
    </div>
    <div class="form-group col-md-2">
      <label>Confirmation Status</label>
      <select name="confirmation_status" class="form-control">
        <option value="">All</option>
        <option value="yes"<?= isset($_GET['confirmation_status']) && $_GET['confirmation_status']=='yes' ? ' selected' : '' ?>>Yes</option>
        <option value="no"<?= isset($_GET['confirmation_status']) && $_GET['confirmation_status']=='no' ? ' selected' : '' ?>>No</option>
      </select>
    </div>
    <div class="form-group col-md-2">
      <label>Membership Status</label>
      <select name="membership_status" class="form-control">
        <option value="">All</option>
        <option value="full"<?= isset($_GET['membership_status']) && $_GET['membership_status']=='full' ? ' selected' : '' ?>>Full Member</option>
        <option value="catechumen"<?= isset($_GET['membership_status']) && $_GET['membership_status']=='catechumen' ? ' selected' : '' ?>>Catechumen</option>
      </select>
    </div>
    <div class="form-group col-md-2">
      <label>Date of Birth</label>
      <input type="date" name="dob" class="form-control" value="<?= htmlspecialchars($_GET['dob'] ?? '') ?>">
    </div>
    <div class="form-group col-md-2">
      <label>Day Born</label>
      <select name="day_born" class="form-control">
        <option value="">All</option>
        <?php $days = [
          'Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'
        ];
        foreach ($days as $day): ?>
          <option value="<?= $day ?>"<?= (isset($_GET['day_born']) && $_GET['day_born'] == $day) ? ' selected' : '' ?>><?= $day ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="form-group col-md-2">
      <label>Role of Service</label>
      <?php include __DIR__ . '/_membership_report_roles_dropdown.php'; ?>
    </div>
    <div class="form-group col-md-2">
      <label>From Date</label>
      <input type="date" name="from_date" class="form-control" value="<?= htmlspecialchars($_GET['from_date'] ?? '') ?>">
    </div>
    <div class="form-group col-md-2">
      <label>To Date</label>
      <input type="date" name="to_date" class="form-control" value="<?= htmlspecialchars($_GET['to_date'] ?? '') ?>">
    </div>
    <div class="form-group col-md-2 align-self-end">
      <button type="submit" class="btn btn-primary btn-block"><i class="fas fa-search mr-1"></i>Filter</button>
    </div>
    </form>
  </div>

  <div class="card shadow mb-4">
    <div class="card-header py-3">
      <h6 class="m-0 font-weight-bold text-primary">Member List</h6>
    </div>
    <div class="card-body">
      <div class="table-responsive">
        <table class="table table-bordered" id="memberTable" data-report-pagination="server" width="100%" cellspacing="0">
          <thead>
            <tr>
              <th>Photo</th>
              <th>CRN</th>
              <th>Full Name</th>
              <th>Phone</th>
              <th>Bible Class</th>
              <th>Church</th>
              <th>Day Born</th>
              <th>Gender</th>
              <th>Status</th>
            </tr>
          </thead>
          <tbody>
            <?php while($row = $members->fetch_assoc()): ?>
            <tr>
              <td>
                <?php
                $photo_path = !empty($row['photo']) ? __DIR__.'/../../uploads/members/' . $row['photo'] : '';
                if (!empty($row['photo']) && file_exists($photo_path)) {
                  $photo_url = BASE_URL . '/uploads/members/' . rawurlencode($row['photo']);
                } else {
                  $photo_url = BASE_URL . '/assets/img/undraw_profile.svg';
                }
                ?>
                <img src="<?= $photo_url ?>" alt="photo" height="40" style="border-radius:50%">
              </td>
              <td><?=htmlspecialchars($row['crn'])?></td>
              <td><?=htmlspecialchars(trim($row['last_name'].' '.$row['first_name'].' '.$row['middle_name']))?></td>
              <td><?=htmlspecialchars($row['phone'])?></td>
              <td><?=htmlspecialchars($row['class_name'])?></td>
              <td><?=htmlspecialchars($row['church_name'])?></td>
              <td><?=htmlspecialchars($row['dob'])?></td>
              <td><?=htmlspecialchars($row['gender'])?></td>
              <td><?=htmlspecialchars($row['status'])?></td>
            </tr>
            <?php endwhile; ?>
          </tbody>
        </table>
      </div>
      <?php report_render_server_pagination($pagination['total_rows'], $pagination['page'], $pagination['per_page'], 'Membership report pages'); ?>
    </div>
  </div>
</div>

<!-- DataTables and Chart.js scripts -->
<script src="<?= BASE_URL ?>/assets/js/report-export-branding.js"></script>
<script>
$(document).ready(function() {
    $('#memberTable').DataTable({
        paging: false,
        searching: false,
        info: false,
        dom: 'Bfrtip',
        buttons: <?= $can_export ? "[\n            'copy', 'csv', 'excel', 'pdf', 'print'\n        ]" : '[]' ?>
    });
    var ctx = document.getElementById('growthChart').getContext('2d');
    var growthChart = new Chart(ctx, {
        type: 'line',
        data: {
            labels: <?= json_encode($growth_labels) ?>,
            datasets: [{
                label: 'Registrations',
                data: <?= json_encode($growth_counts) ?>,
                backgroundColor: 'rgba(54, 162, 235, 0.2)',
                borderColor: 'rgba(54, 162, 235, 1)',
                borderWidth: 2,
                fill: true,
                tension: 0.3
            }]
        },
        options: {
            responsive: true,
            plugins: {
                legend: { display: false },
                title: { display: false }
            },
            scales: {
                y: { beginAtZero: true }
            }
        }
    });
});
</script>
<style>
.btn-xs { padding: 0.14rem 0.34rem !important; font-size: 0.89rem !important; line-height: 1.15 !important; border-radius: 0.22rem !important; }
#memberTable th, #memberTable td { vertical-align: middle !important; }
</style>

<?php
// End output buffering and inject content into layout
$page_content = ob_get_clean();
include_once __DIR__ . '/../../includes/layout.php';
?>
