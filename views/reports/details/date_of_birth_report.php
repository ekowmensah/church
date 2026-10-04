<?php
require_once __DIR__.'/../../../config/config.php';
require_once __DIR__.'/../../../helpers/auth.php';
require_once __DIR__.'/../../../helpers/permissions_v2.php';
require_once __DIR__.'/../../../helpers/report_scope.php';
require_once __DIR__.'/../../../helpers/report_pagination.php';

// Only allow logged-in users
if (!is_logged_in()) {
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}

// Robust super admin bypass and permission check
$is_super_admin = is_super_admin();

if (!$is_super_admin && !has_permission('view_date_of_birth_report')) {
    http_response_code(403);
    if (file_exists(__DIR__.'/../../../views/errors/403.php')) {
        include __DIR__.'/../../../views/errors/403.php';
    } else if (file_exists(__DIR__.'/../../errors/403.php')) {
        include __DIR__.'/../../errors/403.php';
    } else {
        echo '<div class="alert alert-danger"><h4>403 Forbidden</h4><p>You do not have permission to access this report.</p></div>';
    }
    exit;
}

// Set permission flags for UI elements
$can_view = true; // Already validated above
$can_export = $is_super_admin || has_permission('export_date_of_birth_report');

$page_title = 'Date of Birth Report';
$conn = $GLOBALS['conn'];
$month = max(0, min(12, (int) ($_GET['month'] ?? 0)));
$memberScope = report_scope_member_condition($conn, 'm');
$sql = "SELECT m.crn, m.first_name, m.middle_name, m.last_name, m.dob,
               m.gender, m.phone, bc.name AS class_name,
               TIMESTAMPDIFF(YEAR, m.dob, CURDATE()) AS age
        FROM members m
        LEFT JOIN bible_classes bc ON bc.id = m.class_id
        WHERE m.status = 'active' AND {$memberScope}
          AND m.dob IS NOT NULL AND m.dob <> '0000-00-00'";
if ($month > 0) {
    $sql .= ' AND MONTH(m.dob) = ' . $month;
}
$sql .= ' ORDER BY MONTH(m.dob), DAY(m.dob), m.last_name, m.first_name';
$pagination = report_paginate_query($conn, $sql);
$members = $pagination['result']->fetch_all(MYSQLI_ASSOC);

ob_start();
?>
<div class="container-fluid mt-4">
  <div class="card">
    <div class="card-header d-flex flex-wrap justify-content-between align-items-center">
      <div><strong>Birthday Directory</strong><div class="small text-muted"><?= number_format($pagination['total_rows']) ?> active members with a recorded date of birth</div></div>
      <form method="get" class="form-inline ml-auto">
        <label for="month" class="mr-2 mb-0">Birth month</label>
        <select class="form-control form-control-sm mr-2" id="month" name="month">
          <option value="0">All months</option>
          <?php for ($number = 1; $number <= 12; $number++): ?>
            <option value="<?= $number ?>" <?= $month === $number ? 'selected' : '' ?>><?= date('F', mktime(0, 0, 0, $number, 1)) ?></option>
          <?php endfor; ?>
        </select>
        <button class="btn btn-primary btn-sm" type="submit"><i class="fas fa-filter mr-1"></i>Apply</button>
      </form>
    </div>
    <div class="card-body p-0">
      <div class="table-responsive">
      <table id="birthdaysTable" class="table table-hover table-striped" data-report-pagination="server">
      <thead>
        <tr>
          <th>Date of Birth</th>
          <th>Member Name</th>
          <th>CRN</th>
          <th>Class</th>
          <th>Gender</th>
          <th>Age</th>
          <th>Contact</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($members as $member): ?>
          <tr>
            <td data-order="<?= htmlspecialchars($member['dob']) ?>"><?= date('j F Y', strtotime($member['dob'])) ?></td>
            <td><?= htmlspecialchars(trim(implode(' ', array_filter([$member['first_name'], $member['middle_name'], $member['last_name']])))) ?></td>
            <td><?= htmlspecialchars($member['crn']) ?></td>
            <td><?= htmlspecialchars($member['class_name'] ?: 'Unassigned') ?></td>
            <td><?= htmlspecialchars($member['gender'] ?: 'Not specified') ?></td>
            <td><?= number_format((int) $member['age']) ?></td>
            <td><?= htmlspecialchars($member['phone'] ?: '-') ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
      </div>
      <?php if (!$members): ?><div class="report-empty-state"><i class="fas fa-birthday-cake"></i>No birthdays match the selected month.</div><?php endif; ?>
      <?php report_render_server_pagination($pagination['total_rows'], $pagination['page'], $pagination['per_page'], 'Birthday report pages'); ?>
    </div>
  </div>
</div>
<script>
$(function () {
  if ($.fn.DataTable && $('#birthdaysTable tbody tr').length) {
    $('#birthdaysTable').DataTable({
      paging: false,
      searching: false,
      info: false,
      responsive: true,
      order: [[0, 'asc']]
    });
  }
});
</script>
<?php $page_content = ob_get_clean(); include __DIR__.'/../../../includes/layout.php'; ?>
