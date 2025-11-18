<?php
require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../helpers/auth.php';
require_once __DIR__.'/../helpers/permissions_v2.php';
require_once __DIR__.'/../helpers/role_based_filter.php';

// Only allow logged-in users
if (!is_logged_in()) {
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}

// Robust super admin bypass and permission check
$is_super_admin = (isset($_SESSION['user_id']) && $_SESSION['user_id'] == 3) || 
                  (isset($_SESSION['role_id']) && $_SESSION['role_id'] == 1);

if (!$is_super_admin && !has_permission('view_attendance_list')) {
    http_response_code(403);
    if (file_exists(__DIR__.'/errors/403.php')) {
        include __DIR__.'/errors/403.php';
    } else if (file_exists(dirname(__DIR__).'/views/errors/403.php')) {
        include dirname(__DIR__).'/views/errors/403.php';
    } else {
        echo '<div class="alert alert-danger"><h4>403 Forbidden</h4><p>You do not have permission to access this page.</p></div>';
    }
    exit;
}

// Set permission flags for UI elements
$can_add = $is_super_admin || has_permission('create_attendance');
$can_edit = $is_super_admin || has_permission('edit_attendance');
$can_delete = $is_super_admin || has_permission('delete_attendance');
$can_view = true; // Already validated above

// Handle create next recurring session
$success_msg = '';
$error_msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_next_recurring']) && isset($_POST['recurring_template_id'])) {
    $template_id = intval($_POST['recurring_template_id']);
    $tpl_q = $conn->prepare("SELECT * FROM attendance_sessions WHERE id = ? LIMIT 1");
    $tpl_q->bind_param('i', $template_id);
    $tpl_q->execute();
    $tpl_result = $tpl_q->get_result();
    if ($tpl_result && $tpl_result->num_rows > 0) {
        $row = $tpl_result->fetch_assoc();
        $rec_type = $row['recurrence_type'];
        $rec_day = $row['recurrence_day'];
        // Find the latest session for this template (by title, church, recurrence_type, recurrence_day)
        $latest = $conn->prepare("SELECT * FROM attendance_sessions WHERE is_recurring = 1 AND title = ? AND church_id = ? AND recurrence_type = ? AND recurrence_day = ? ORDER BY service_date DESC LIMIT 1");
        $latest->bind_param('sisi', $row['title'], $row['church_id'], $rec_type, $rec_day);
        $latest->execute();
        $latest_result = $latest->get_result();
        if ($latest_result && $latest_result->num_rows > 0) {
            $last = $latest_result->fetch_assoc();
            $next_date = '';
            if ($rec_type === 'weekly') {
                $last_date = $last['service_date'];
                $next_date = date('Y-m-d', strtotime($last_date . ' +7 days'));
            } elseif ($rec_type === 'monthly') {
                $last_date = $last['service_date'];
                $next_date = date('Y-m-d', strtotime($last_date . ' +1 month'));
            }
            // Check if session already exists for next_date
            $stmt = $conn->prepare("SELECT COUNT(*) FROM attendance_sessions WHERE is_recurring = 1 AND title = ? AND church_id = ? AND recurrence_type = ? AND recurrence_day = ? AND service_date = ?");
            $stmt->bind_param('sisis', $row['title'], $row['church_id'], $rec_type, $rec_day, $next_date);
            $stmt->execute();
            $stmt->bind_result($count);
            $stmt->fetch();
            $stmt->close();
            if ($count == 0 && $next_date) {
                // Copy all fields except id, service_date
                $stmt = $conn->prepare("INSERT INTO attendance_sessions (title, church_id, is_recurring, recurrence_type, recurrence_day, service_date) VALUES (?, ?, 1, ?, ?, ?)");
                $stmt->bind_param('sisis', $row['title'], $row['church_id'], $rec_type, $rec_day, $next_date);
                if ($stmt->execute()) {
                    $success_msg = 'Next recurring session created for ' . htmlspecialchars($next_date);
                } else {
                    $error_msg = 'Failed to create next recurring session.';
                }
                $stmt->close();
            } else {
                $error_msg = 'Session for next occurrence already exists or invalid date.';
            }
        } else {
            $error_msg = 'No previous session found for this template.';
        }
        $latest->close();
    } else {
        $error_msg = 'Template not found.';
    }
    $tpl_q->close();
}
// Handle delete
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_id'])) {
    $delete_id = intval($_POST['delete_id']);
    $stmt = $conn->prepare("DELETE FROM attendance_sessions WHERE id = ?");
    $stmt->bind_param('i', $delete_id);
    $stmt->execute();
    // Optionally: delete related attendance_records
    $stmt2 = $conn->prepare("DELETE FROM attendance_records WHERE session_id = ?");
    $stmt2->bind_param('i', $delete_id);
    $stmt2->execute();
    header('Location: attendance_list.php?deleted=1');
    exit;
}

// Fetch all sessions with church name (LEFT JOIN)
// Apply role-based filtering for class leaders and organizational leaders
$session_sql = "SELECT s.*, c.name AS church_name FROM attendance_sessions s LEFT JOIN churches c ON s.church_id = c.id WHERE 1";
$session_params = [];
$session_types = '';

// Class leaders and org leaders should only see sessions from their church
if (is_class_leader() || is_organizational_leader()) {
    // Get the church_id from the leader's member record
    $user_id = $_SESSION['user_id'] ?? 0;
    if ($user_id) {
        $user_church_sql = "SELECT m.church_id FROM users u 
                           JOIN members m ON u.member_id = m.id 
                           WHERE u.id = ?";
        $user_church_stmt = $conn->prepare($user_church_sql);
        $user_church_stmt->bind_param('i', $user_id);
        $user_church_stmt->execute();
        $user_church_result = $user_church_stmt->get_result();
        if ($user_church_row = $user_church_result->fetch_assoc()) {
            $session_sql .= " AND s.church_id = ?";
            $session_params[] = $user_church_row['church_id'];
            $session_types .= 'i';
        }
        $user_church_stmt->close();
    }
}

$session_sql .= " ORDER BY s.service_date DESC, s.id DESC";

if (!empty($session_params)) {
    $result = $conn->prepare($session_sql);
    $result->bind_param($session_types, ...$session_params);
    $result->execute();
    $result = $result->get_result();
} else {
    $result = $conn->query($session_sql);
}

// Fetch session_ids that already have attendance records
$marked_sessions = [];
$marked_result = $conn->query("SELECT DISTINCT session_id FROM attendance_records");
while ($rowm = $marked_result->fetch_assoc()) {
    $marked_sessions[] = $rowm['session_id'];
}

ob_start();
?>
<div class="d-sm-flex align-items-center justify-content-between mb-4">
    <h1 class="h3 mb-0 text-gray-800">Attendance Sessions</h1>
    <div>
        <?php if ($can_add): ?>
            <a href="attendance_form.php" class="btn btn-primary btn-sm mr-2"><i class="fas fa-plus"></i> Add Session</a>
        <?php endif; ?>
        <?php
        // Check if there is at least one recurring session
        $recurring_check = $conn->query("SELECT * FROM attendance_sessions WHERE is_recurring = 1 ORDER BY service_date DESC LIMIT 1");
        if ($recurring_check && $recurring_check->num_rows > 0): ?>
            <button type="button" class="btn btn-success btn-sm" data-toggle="modal" data-target="#nextRecurringModal">
    <i class="fas fa-sync-alt"></i> Create Next Recurring Session
</button>
        <?php endif; ?>
    </div>
</div>

<?php
// Initialize modal_html variable
$modal_html = '';

// Check if there is at least one recurring session for modal
$recurring_check = $conn->query("SELECT * FROM attendance_sessions WHERE is_recurring = 1 ORDER BY service_date DESC LIMIT 1");
if ($recurring_check && $recurring_check->num_rows > 0): 
    ob_start(); ?>
<div class="modal fade" id="nextRecurringModal" tabindex="-1" role="dialog" aria-labelledby="nextRecurringModalLabel" aria-hidden="true">
  <div class="modal-dialog" role="document">
    <div class="modal-content">
      <form method="post" action="attendance_list.php">
        <div class="modal-header">
          <h5 class="modal-title" id="nextRecurringModalLabel">Select Recurring Session Template</h5>
          <button type="button" class="close" data-dismiss="modal" aria-label="Close">
            <span aria-hidden="true">&times;</span>
          </button>
        </div>
        <div class="modal-body">
          <div class="form-group">
            <label for="recurring_template_id">Session Template</label>
            <select class="form-control" id="recurring_template_id" name="recurring_template_id" required>
              <option value="">Select...</option>
              <?php
              $templates = $conn->query("SELECT s.id, s.title, c.name AS church_name FROM attendance_sessions s LEFT JOIN churches c ON s.church_id = c.id WHERE s.is_recurring = 1 GROUP BY s.title, s.church_id ORDER BY s.title, c.name");
              while ($tpl = $templates->fetch_assoc()): ?>
                <option value="<?= $tpl['id'] ?>"><?= htmlspecialchars($tpl['title']) ?> (<?= htmlspecialchars($tpl['church_name']) ?>)</option>
              <?php endwhile; ?>
            </select>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
          <button type="submit" name="create_next_recurring" value="1" class="btn btn-success">Create</button>
        </div>
      </form>
    </div>
  </div>
</div>
<?php $modal_html = ob_get_clean(); 
endif; ?>
    </div>
</div>
<?php if ($success_msg): ?>
    <div class="alert alert-success"> <?= $success_msg ?> </div>
<?php elseif ($error_msg): ?>
    <div class="alert alert-danger"> <?= $error_msg ?> </div>
<?php endif; ?>
<div class="card shadow mb-4">
    <div class="card-header py-3">
        <h6 class="m-0 font-weight-bold text-primary">List of Attendance Sessions</h6>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-bordered table-hover" width="100%">
                <thead class="thead-light">
                    <tr>
                        <th>Title</th>
                        <th>Church</th>
                        <th>Session Type</th>
                        <th>Recurrence Type</th>
                        <th>Day/Month</th>
                        <th>Service Date</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php while($row = $result->fetch_assoc()): ?>
                    <tr>
                        <td><?= htmlspecialchars($row['title']) ?></td>
<td><?= $row['church_name'] ? htmlspecialchars($row['church_name']) : '—' ?></td>
                        <td><?= $row['is_recurring'] ? 'Recurring' : 'One-time' ?></td>
                        <td>
                            <?php
                                if ($row['is_recurring']) {
                                    echo $row['recurrence_type'] ? ucfirst($row['recurrence_type']) : '—';
                                } else {
                                    echo '—';
                                }
                                // Mark Date below
                                echo '<br><small class="text-muted">Mark Date: ' . htmlspecialchars($row['service_date']) . '</small>';
                            ?>
                        </td>
                        <td>
                            <?php
                                if ($row['is_recurring']) {
                                    if ($row['recurrence_type'] === 'weekly') {
                                        $days = ['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'];
                                        echo isset($days[$row['recurrence_day']]) ? $days[$row['recurrence_day']] : '—';
                                    } elseif ($row['recurrence_type'] === 'monthly') {
                                        $months = [1=>'January',2=>'February',3=>'March',4=>'April',5=>'May',6=>'June',7=>'July',8=>'August',9=>'September',10=>'October',11=>'November',12=>'December'];
                                        echo isset($months[$row['recurrence_day']]) ? $months[$row['recurrence_day']] : '—';
                                    } else {
                                        echo '—';
                                    }
                                } else {
                                    echo '—';
                                }
                            ?>
                        </td>
                        <td><?= $row['is_recurring'] ? 'N/A' : htmlspecialchars($row['service_date']) ?></td>
                        <td>
                            <?php if (in_array($row['id'], $marked_sessions)): ?>
    <a href="attendance_mark.php?id=<?= $row['id'] ?>" class="btn btn-sm btn-warning" style="background-color: orange; border-color: orange;"><i class="fas fa-redo"></i> Re-Mark</a>
<?php else: ?>
    <a href="attendance_mark.php?id=<?= $row['id'] ?>" class="btn btn-sm btn-success"><i class="fas fa-check"></i> Mark</a>
<?php endif; ?>
                            <?php if ($can_view): ?>
                                <a href="attendance_view.php?id=<?= $row['id'] ?>" class="btn btn-sm btn-info"><i class="fas fa-eye"></i> View</a>
                            <?php endif; ?>
                            <?php if (in_array($row['id'], $marked_sessions)): ?>
    <button class="btn btn-sm btn-warning" disabled title="Cannot edit a session that has been marked"><i class="fas fa-edit"></i> Edit</button>
    <button class="btn btn-sm btn-danger" disabled title="Cannot delete a session that has been marked"><i class="fas fa-trash"></i> Delete</button>
<?php else: ?>
    <?php if ($can_edit): ?>
        <a href="attendance_form.php?id=<?= $row['id'] ?>" class="btn btn-sm btn-warning"><i class="fas fa-edit"></i> Edit</a>
    <?php endif; ?>
    <?php if ($can_delete): ?>
        <form method="post" action="attendance_list.php" style="display:inline;" onsubmit="return confirm('Delete this session? This will remove all attendance records for this session.');">
            <input type="hidden" name="delete_id" value="<?= $row['id'] ?>">
            <button type="submit" class="btn btn-sm btn-danger"><i class="fas fa-trash"></i> Delete</button>
        </form>
    <?php endif; ?>
<?php endif; ?>
                        </td>
                    </tr>
                <?php endwhile; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php $page_content = ob_get_clean(); include '../includes/layout.php'; echo $modal_html; ?>
