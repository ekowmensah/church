<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../helpers/auth.php';
require_once __DIR__ . '/../helpers/permissions_v2.php';
require_once __DIR__ . '/../helpers/csrf.php';
require_once __DIR__ . '/../services/UnifiedAttendanceReportService.php';

if (!is_logged_in()) { header('Location: ' . BASE_URL . '/login.php'); exit; }
$isSuperAdmin = is_super_admin();
if (!$isSuperAdmin && !has_permission('create_transfer')) {
    http_response_code(403); include __DIR__ . '/errors/403.php'; exit;
}

$scope = UnifiedAttendanceReportService::fromSession($conn);
$allowedChurchIds = array_map('intval', array_column($scope->getAllowedChurches(), 'id'));
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_is_valid($_POST['csrf_token'] ?? null)) { http_response_code(419); exit('Your form expired. Refresh and try again.'); }
    try {
        $memberId = (int) ($_POST['member_id'] ?? 0);
        $movementType = (string) ($_POST['movement_type'] ?? '');
        $transferStatus = (string) ($_POST['transfer_status'] ?? 'completed');
        $transferDate = trim((string) ($_POST['transfer_date'] ?? ''));
        $externalLocation = trim((string) ($_POST['external_location'] ?? ''));
        $reason = trim((string) ($_POST['transfer_reason'] ?? ''));
        $notes = trim((string) ($_POST['transfer_notes'] ?? ''));
        if ($memberId <= 0 || !in_array($movementType, ['external_in','external_out'], true)) throw new RuntimeException('Choose a member and movement direction.');
        if (!in_array($transferStatus, ['pending','completed','cancelled'], true)) throw new RuntimeException('Choose a valid transfer status.');
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $transferDate);
        if (!$date || $date->format('Y-m-d') !== $transferDate || $transferDate < '1900-01-01') throw new RuntimeException('Enter a valid transfer date.');
        if ($externalLocation === '' || $reason === '') throw new RuntimeException('External church/location and transfer reason are required.');
        if (!$allowedChurchIds) throw new RuntimeException('No church is available for your role.');

        $placeholders = implode(',', array_fill(0, count($allowedChurchIds), '?'));
        $types = 'i' . str_repeat('i', count($allowedChurchIds));
        $params = array_merge([$memberId], $allowedChurchIds);
        $stmt = $conn->prepare(
            "SELECT member.id, member.crn, member.class_id, member.church_id,
                    class.name AS class_name, church.name AS church_name
               FROM members member
               LEFT JOIN bible_classes class ON class.id = member.class_id
               JOIN churches church ON church.id = member.church_id
              WHERE member.id = ? AND member.church_id IN ($placeholders) LIMIT 1"
        );
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $member = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$member) throw new RuntimeException('Member not found in your authorized church scope.');

        $localLocation = trim($member['church_name'] . ($member['class_name'] ? ' / ' . $member['class_name'] : ''));
        $fromClassId = $movementType === 'external_out' ? (int) $member['class_id'] : null;
        $toClassId = $movementType === 'external_in' ? (int) $member['class_id'] : null;
        $fromChurchId = $movementType === 'external_out' ? (int) $member['church_id'] : null;
        $toChurchId = $movementType === 'external_in' ? (int) $member['church_id'] : null;
        $originName = $movementType === 'external_in' ? $externalLocation : $localLocation;
        $destinationName = $movementType === 'external_out' ? $externalLocation : $localLocation;
        $actor = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
        $crn = (string) $member['crn'];
        $stmt = $conn->prepare(
            "INSERT INTO member_transfers
                (member_id, from_class_id, to_class_id, transfer_date, transferred_by,
                 old_crn, movement_type, transfer_status, from_church_id, to_church_id,
                 origin_name, destination_name, transfer_reason, transfer_notes, new_crn)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        $stmt->bind_param(
            'iiisisssiisssss',
            $memberId, $fromClassId, $toClassId, $transferDate, $actor, $crn,
            $movementType, $transferStatus, $fromChurchId, $toChurchId,
            $originName, $destinationName, $reason, $notes, $crn
        );
        $stmt->execute();
        $stmt->close();
        header('Location: transfer_list.php?recorded=1');
        exit;
    } catch (Throwable $exception) {
        $error = $exception->getMessage();
    }
}

$page_title = 'Record External Transfer';
ob_start();
?>
<div class="container mt-4"><div class="d-flex justify-content-between align-items-center mb-3"><h2>Record External Transfer</h2><a href="transfer_list.php" class="btn btn-outline-secondary btn-sm">Transfer Register</a></div>
  <div class="alert alert-info">This records a transfer into or out of the church for reporting. It does not deactivate the member, change their class, or alter their CRN.</div>
  <?php if ($error): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>
  <form method="post" class="card card-body" id="externalTransferForm">
    <?= csrf_input() ?>
    <div class="form-group"><label>Member CRN <span class="text-danger">*</span></label><div class="input-group"><input id="crn_search" class="form-control" required><div class="input-group-append"><button class="btn btn-info" type="button" id="find_member_btn">Find Member</button></div></div><input type="hidden" name="member_id" id="member_id"><div id="member_result" class="small mt-2"></div></div>
    <div class="form-row"><div class="form-group col-md-4"><label>Direction</label><select class="form-control" name="movement_type" required><option value="external_in">Transferred Into Church</option><option value="external_out">Transferred Out of Church</option></select></div><div class="form-group col-md-4"><label>Status</label><select class="form-control" name="transfer_status" required><option value="completed">Completed</option><option value="pending">Pending</option><option value="cancelled">Cancelled</option></select></div><div class="form-group col-md-4"><label>Transfer Date</label><input type="date" class="form-control" name="transfer_date" value="<?= date('Y-m-d') ?>" required></div></div>
    <div class="form-group"><label>External Church / Circuit / Diocese <span class="text-danger">*</span></label><input class="form-control" name="external_location" maxlength="255" required></div>
    <div class="form-group"><label>Transfer Reason <span class="text-danger">*</span></label><input class="form-control" name="transfer_reason" maxlength="500" required></div>
    <div class="form-group"><label>Notes</label><textarea class="form-control" name="transfer_notes" maxlength="500" rows="3"></textarea></div>
    <button class="btn btn-primary" id="submitTransfer" disabled>Record Transfer</button>
  </form>
</div>
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script>$(function(){$('#find_member_btn').on('click',function(){var crn=$('#crn_search').val().trim();$('#member_result').removeClass('text-success text-danger').text('Searching...');$.get('ajax_find_member_by_crn.php',{crn:crn},function(res){if(res&&res.id){$('#member_id').val(res.id);$('#member_result').addClass('text-success').text('Member: '+res.full_name+' ('+res.crn+')');$('#submitTransfer').prop('disabled',false);}else{$('#member_id').val('');$('#member_result').addClass('text-danger').text(res.error||'Member not found.');$('#submitTransfer').prop('disabled',true);}},'json').fail(function(){$('#member_result').addClass('text-danger').text('Unable to search for the member.');});});});</script>
<?php $page_content = ob_get_clean(); include __DIR__ . '/../includes/layout.php'; ?>
