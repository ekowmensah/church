<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../helpers/auth.php';
require_once __DIR__ . '/../helpers/permissions_v2.php';
require_once __DIR__ . '/../services/HealthEncounterService.php';

require_login();
$actorUserId = (int) ($_SESSION['user_id'] ?? 0);
$actorChurchId = (int) ($_SESSION['church_id'] ?? 0);
$superAdmin = is_super_admin();
if ($actorChurchId <= 0 && $actorUserId > 0) {
    $stmt = $conn->prepare('SELECT church_id FROM users WHERE id=? LIMIT 1');
    $stmt->bind_param('i', $actorUserId); $stmt->execute();
    $actorChurchId = (int) ($stmt->get_result()->fetch_assoc()['church_id'] ?? 0); $stmt->close();
}
$service = new HealthEncounterService($conn, $actorUserId, $actorChurchId, $superAdmin);
$selectedChurchId = $superAdmin ? max(0, (int) ($_GET['church_id'] ?? $actorChurchId)) : $actorChurchId;
if ($selectedChurchId <= 0 || !$service->authorize('view_health_list', $selectedChurchId)) {
    http_response_code(403); require __DIR__ . '/errors/403.php'; exit;
}
$canAdd = $service->authorize('create_health_record', $selectedChurchId);
$canEdit = $service->authorize('edit_health_record', $selectedChurchId);

$search = trim((string) ($_GET['q'] ?? ''));
$status = in_array(($_GET['status'] ?? ''), ['final','amended','voided'], true) ? $_GET['status'] : '';
$followUp = in_array(($_GET['follow_up'] ?? ''), ['recommended','scheduled','completed','cancelled','none'], true) ? $_GET['follow_up'] : '';
$from = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($_GET['from'] ?? '')) ? $_GET['from'] : '';
$to = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($_GET['to'] ?? '')) ? $_GET['to'] : '';
$page = max(1, (int) ($_GET['page'] ?? 1));
$requestedPerPage = (int) ($_GET['per_page'] ?? 25);
$perPage = in_array($requestedPerPage, [25, 50, 100], true)
    ? $requestedPerPage
    : 25;

$where = ['record.church_id=?']; $types = 'i'; $params = [$selectedChurchId];
if ($search !== '') {
    $where[] = "(member.crn LIKE CONCAT('%',?,'%') OR child.srn LIKE CONCAT('%',?,'%') OR CONCAT_WS(' ',member.first_name,member.middle_name,member.last_name) LIKE CONCAT('%',?,'%') OR CONCAT_WS(' ',child.first_name,child.middle_name,child.last_name) LIKE CONCAT('%',?,'%'))";
    $types .= 'ssss'; array_push($params, $search, $search, $search, $search);
}
if ($status !== '') { $where[]='record.status=?'; $types.='s'; $params[]=$status; }
if ($followUp !== '') { $where[]='record.follow_up_status=?'; $types.='s'; $params[]=$followUp; }
if ($from !== '') { $where[]='record.recorded_at>=?'; $types.='s'; $params[]=$from.' 00:00:00'; }
if ($to !== '') { $where[]='record.recorded_at<?'; $types.='s'; $params[]=(new DateTimeImmutable($to))->modify('+1 day')->format('Y-m-d').' 00:00:00'; }
$whereSql = implode(' AND ', $where);
$joinSql = ' FROM health_records record LEFT JOIN members member ON member.id=record.member_id LEFT JOIN sunday_school child ON child.id=record.sundayschool_id LEFT JOIN users recorder ON recorder.id=record.recorded_by ';

$stmt = $conn->prepare('SELECT COUNT(*) total '.$joinSql.' WHERE '.$whereSql);
$stmt->bind_param($types, ...$params); $stmt->execute(); $total=(int)$stmt->get_result()->fetch_assoc()['total']; $stmt->close();
$totalPages=max(1,(int)ceil($total/$perPage)); $page=min($page,$totalPages); $offset=($page-1)*$perPage;
$sql = "SELECT record.id,record.status,record.encounter_type,record.recorded_at,record.follow_up_status,record.follow_up_date,record.notes,
               COALESCE(member.crn,child.srn) identifier,
               COALESCE(TRIM(CONCAT_WS(' ',member.first_name,member.middle_name,member.last_name)),TRIM(CONCAT_WS(' ',child.first_name,child.middle_name,child.last_name))) person_name,
               CASE WHEN record.member_id IS NOT NULL THEN 'Member' ELSE 'Sunday School' END person_type,
               recorder.name recorder_name,
               (SELECT value_numeric FROM health_observations WHERE health_record_id=record.id AND observation_code='temperature' LIMIT 1) temperature,
               (SELECT value_numeric FROM health_observations WHERE health_record_id=record.id AND observation_code='blood_pressure_systolic' LIMIT 1) systolic,
               (SELECT value_numeric FROM health_observations WHERE health_record_id=record.id AND observation_code='blood_pressure_diastolic' LIMIT 1) diastolic,
               EXISTS(SELECT 1 FROM health_observations o WHERE o.health_record_id=record.id AND o.interpretation IN('attention','critical')) has_attention,
               EXISTS(SELECT 1 FROM health_screening_results s WHERE s.health_record_id=record.id AND s.result_code IN('positive','indeterminate')) has_screening_flag
        {$joinSql} WHERE {$whereSql} ORDER BY record.recorded_at DESC,record.id DESC LIMIT ? OFFSET ?";
$listTypes=$types.'ii'; $listParams=array_merge($params,[$perPage,$offset]);
$stmt=$conn->prepare($sql); $stmt->bind_param($listTypes,...$listParams); $stmt->execute(); $records=$stmt->get_result();

$kpiStmt=$conn->prepare("SELECT COUNT(*) total,
 SUM(recorded_at>=CURDATE() AND status<>'voided') today_count,
 SUM(follow_up_status IN('recommended','scheduled') AND (follow_up_date IS NULL OR follow_up_date<=CURDATE()) AND status<>'voided') follow_up_due,
 SUM(status='voided') voided_count FROM health_records WHERE church_id=?");
$kpiStmt->bind_param('i',$selectedChurchId);$kpiStmt->execute();$kpis=$kpiStmt->get_result()->fetch_assoc();$kpiStmt->close();
$qualityStmt=$conn->prepare('SELECT COUNT(*) count FROM health_data_quality_review WHERE church_id=? AND resolved=0');$qualityStmt->bind_param('i',$selectedChurchId);$qualityStmt->execute();$qualityOpen=(int)$qualityStmt->get_result()->fetch_assoc()['count'];$qualityStmt->close();
$churches=$superAdmin?$conn->query('SELECT id,name FROM churches ORDER BY name'):null;
$page_title='Health Screening Centre'; ob_start();
?>
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/health-workspace.css">
<div class="container-fluid health-workspace py-3">
 <div class="health-hero mb-4 d-flex flex-wrap justify-content-between align-items-center"><div><h1><i class="fas fa-heartbeat mr-2"></i>Health Screening Centre</h1><p>Review encounters, act on follow-up and protect clinical evidence.</p></div><?php if($canAdd):?><a href="health_form.php" class="btn btn-light mt-2 mt-md-0"><i class="fas fa-plus mr-1"></i> New encounter</a><?php endif;?></div>
 <div class="health-kpis mb-4">
  <div class="health-kpi"><div class="health-kpi-value"><?= number_format((int)$kpis['total']) ?></div><div class="health-kpi-label">All encounters</div></div>
  <div class="health-kpi"><div class="health-kpi-value"><?= number_format((int)$kpis['today_count']) ?></div><div class="health-kpi-label">Recorded today</div></div>
  <div class="health-kpi"><div class="health-kpi-value text-warning"><?= number_format((int)$kpis['follow_up_due']) ?></div><div class="health-kpi-label">Follow-up due</div></div>
  <div class="health-kpi"><div class="health-kpi-value <?= $qualityOpen?'text-danger':'' ?>"><?= number_format($qualityOpen) ?></div><div class="health-kpi-label">Legacy values to review</div></div>
 </div>
 <form method="get" class="health-filter mb-4"><div class="form-row">
  <?php if($superAdmin):?><div class="form-group col-xl-2 col-md-4"><label>Church</label><select class="form-control" name="church_id"><?php while($church=$churches->fetch_assoc()):?><option value="<?= $church['id'] ?>" <?= $selectedChurchId===(int)$church['id']?'selected':'' ?>><?= htmlspecialchars($church['name']) ?></option><?php endwhile;?></select></div><?php endif;?>
  <div class="form-group col-xl-3 col-md-4"><label>Person / CRN / SRN</label><input class="form-control" name="q" value="<?= htmlspecialchars($search) ?>" placeholder="Search"></div>
  <div class="form-group col-xl-2 col-md-4"><label>Status</label><select class="form-control" name="status"><option value="">All statuses</option><?php foreach(['final'=>'Final','amended'=>'Amended','voided'=>'Voided'] as $value=>$label):?><option value="<?= $value ?>" <?= $status===$value?'selected':'' ?>><?= $label ?></option><?php endforeach;?></select></div>
  <div class="form-group col-xl-2 col-md-4"><label>Follow-up</label><select class="form-control" name="follow_up"><option value="">All</option><?php foreach(['recommended'=>'Recommended','scheduled'=>'Scheduled','completed'=>'Completed','none'=>'None'] as $value=>$label):?><option value="<?= $value ?>" <?= $followUp===$value?'selected':'' ?>><?= $label ?></option><?php endforeach;?></select></div>
  <div class="form-group col-xl-1 col-md-4"><label>From</label><input type="date" class="form-control" name="from" value="<?= htmlspecialchars($from) ?>"></div><div class="form-group col-xl-1 col-md-4"><label>To</label><input type="date" class="form-control" name="to" value="<?= htmlspecialchars($to) ?>"></div>
  <div class="form-group col-xl-1 col-md-4 d-flex align-items-end"><button class="btn btn-health-primary btn-block"><i class="fas fa-filter"></i></button></div>
 </div></form>
 <div class="health-panel"><div class="health-panel-header"><div><h2 class="health-section-title">Encounter register</h2><p class="health-section-copy"><?= number_format($total) ?> matching records; newest first.</p></div><a href="reports/health_report.php" class="btn btn-sm btn-outline-secondary"><i class="fas fa-chart-line mr-1"></i> Reports</a></div>
 <div class="table-responsive"><table class="table health-table mb-0"><thead><tr><th>Person</th><th>Encounter</th><th>Measurements</th><th>Follow-up</th><th>Recorded by</th><th class="text-right">Action</th></tr></thead><tbody>
 <?php if($records->num_rows===0):?><tr><td colspan="6"><div class="health-empty"><i class="fas fa-clipboard-list fa-2x mb-2"></i><div>No encounters match these filters.</div></div></td></tr><?php endif;?>
 <?php while($row=$records->fetch_assoc()):?><tr class="<?= $row['status']==='voided'?'text-muted':'' ?>">
  <td><div class="font-weight-bold"><?= htmlspecialchars($row['person_name'] ?: 'Unknown person') ?></div><small class="text-muted"><?= htmlspecialchars($row['identifier'] ?: '—') ?> · <?= htmlspecialchars($row['person_type']) ?></small></td>
  <td><div><?= date('d M Y, g:i A',strtotime($row['recorded_at'])) ?></div><span class="status-pill <?= htmlspecialchars($row['status']) ?>"><?= htmlspecialchars(ucfirst($row['status'])) ?></span> <small><?= htmlspecialchars(ucfirst(str_replace('_',' ',$row['encounter_type']))) ?></small></td>
  <td><?php if($row['systolic']!==null):?><span class="mr-2"><i class="fas fa-heartbeat text-muted"></i> <?= (int)$row['systolic'] ?>/<?= (int)$row['diastolic'] ?></span><?php endif;?><?php if($row['temperature']!==null):?><span><i class="fas fa-thermometer-half text-muted"></i> <?= number_format((float)$row['temperature'],1) ?>°C</span><?php endif;?><?php if($row['has_attention']||$row['has_screening_flag']):?><div><span class="status-pill attention"><i class="fas fa-flag"></i> Review flag</span></div><?php endif;?></td>
  <td><span class="status-pill"><?= htmlspecialchars(ucfirst(str_replace('_',' ',$row['follow_up_status']))) ?></span><?php if($row['follow_up_date']):?><div class="small text-muted"><?= date('d M Y',strtotime($row['follow_up_date'])) ?></div><?php endif;?></td>
  <td><?= htmlspecialchars($row['recorder_name'] ?: 'Unknown') ?></td><td class="text-right"><a href="health_records.php?id=<?= $row['id'] ?>" class="btn btn-sm btn-outline-info" title="Open"><i class="fas fa-arrow-right"></i></a><?php if($canEdit&&$row['status']!=='voided'):?> <a href="health_form.php?id=<?= $row['id'] ?>" class="btn btn-sm btn-outline-secondary" title="Correct"><i class="fas fa-pen"></i></a><?php endif;?></td>
 </tr><?php endwhile;?></tbody></table></div>
 <?php if($totalPages>1):?><div class="health-panel-body border-top d-flex flex-wrap justify-content-between align-items-center"><small class="text-muted">Showing <?= $offset+1 ?>–<?= min($offset+$perPage,$total) ?> of <?= $total ?></small><nav><ul class="pagination pagination-sm mb-0"><?php for($p=max(1,$page-2);$p<=min($totalPages,$page+2);$p++):$query=array_merge($_GET,['page'=>$p]);?><li class="page-item <?= $p===$page?'active':'' ?>"><a class="page-link" href="?<?= htmlspecialchars(http_build_query($query)) ?>"><?= $p ?></a></li><?php endfor;?></ul></nav></div><?php endif;?>
 </div>
</div>
<?php $page_content=ob_get_clean();require_once __DIR__.'/../includes/layout.php';
