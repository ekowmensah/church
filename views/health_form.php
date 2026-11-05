<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../helpers/auth.php';
require_once __DIR__ . '/../helpers/permissions_v2.php';
require_once __DIR__ . '/../helpers/csrf.php';
require_once __DIR__ . '/../services/HealthEncounterService.php';

require_login();
$actorUserId = (int) ($_SESSION['user_id'] ?? 0);
if ($actorUserId <= 0) {
    http_response_code(403);
    exit('Back-office authentication is required.');
}
$isSuperAdmin = is_super_admin();
$actorChurchId = (int) ($_SESSION['church_id'] ?? 0);
if ($actorChurchId <= 0) {
    $stmt = $conn->prepare('SELECT church_id FROM users WHERE id=? LIMIT 1');
    $stmt->bind_param('i', $actorUserId);
    $stmt->execute();
    $actorChurchId = (int) ($stmt->get_result()->fetch_assoc()['church_id'] ?? 0);
    $stmt->close();
}

$service = new HealthEncounterService($conn, $actorUserId, $actorChurchId, $isSuperAdmin);
$recordId = max(0, (int) ($_GET['id'] ?? $_POST['record_id'] ?? 0));
$editing = $recordId > 0;
$record = $editing ? $service->getRecord($recordId) : null;
if ($editing && !$record) {
    http_response_code(404);
    exit('Health encounter not found.');
}
$permission = $editing ? 'edit_health_record' : 'create_health_record';
$permissionChurch = $editing ? (int) $record['church_id'] : $actorChurchId;
if (!$service->authorize($permission, $permissionChurch)) {
    http_response_code(403);
    require __DIR__ . '/errors/403.php';
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_is_valid($_POST['csrf_token'] ?? null)) {
        $error = 'Your session token expired. Refresh the page and try again.';
    } else {
        try {
            $saved = $service->save($_POST, $recordId);
            header('Location: ' . BASE_URL . '/views/health_records.php?id=' . $saved['record_id'] . '&saved=1');
            exit;
        } catch (Throwable $exception) {
            $error = $exception->getMessage();
        }
    }
}

$definitions = HealthEncounterService::observationDefinitions();
$values = [];
foreach ($definitions as $code => $definition) {
    $values[$code] = $_POST['observations'][$code]
        ?? ($record['observations'][$code]['value_numeric'] ?? '');
}
$screenings = [];
foreach (['malaria', 'hepatitis_b'] as $code) {
    $screenings[$code] = $_POST['screenings'][$code]
        ?? ($record['screenings'][$code]['result_code'] ?? '');
}
$prefillPerson = null;
if (!$editing && $_SERVER['REQUEST_METHOD'] !== 'POST' && !empty($_GET['member_id'])) {
    $prefillId = (int) $_GET['member_id'];
    $stmt = $conn->prepare("SELECT 'member' person_type,id person_id,church_id,crn identifier,
        TRIM(CONCAT_WS(' ',first_name,middle_name,last_name)) full_name,dob,gender,phone,photo
        FROM members WHERE id=? AND church_id=? AND is_archived=0 LIMIT 1");
    $stmt->bind_param('ii', $prefillId, $actorChurchId);
    $stmt->execute(); $prefillPerson = $stmt->get_result()->fetch_assoc(); $stmt->close();
}
$recordedAt = $_POST['recorded_at']
    ?? ($record ? date('Y-m-d\TH:i', strtotime($record['recorded_at'])) : date('Y-m-d\TH:i'));
$personType = $_POST['person_type'] ?? ($record['person_type'] ?? '');
$personId = (int) ($_POST['person_id'] ?? ($record
    ? ($record['member_id'] ?: $record['sundayschool_id']) : 0));
$identifier = $_POST['identifier'] ?? ($record['identifier'] ?? ($_GET['identifier'] ?? ''));
if ($prefillPerson) {
    $personType = 'member'; $personId = (int) $prefillPerson['person_id'];
    $identifier = $prefillPerson['identifier'];
}
$page_title = $editing ? 'Correct Health Encounter' : 'New Health Encounter';
ob_start();
?>
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/health-workspace.css">
<div class="container-fluid health-workspace py-3">
    <div class="health-hero mb-4 d-flex flex-wrap justify-content-between align-items-center">
        <div>
            <h1><i class="fas fa-notes-medical mr-2"></i><?= $editing ? 'Correct health encounter' : 'Record health encounter' ?></h1>
            <p>Structured measurements, screening results and accountable follow-up in one workflow.</p>
        </div>
        <a class="btn btn-light mt-2 mt-md-0" href="health_list.php"><i class="fas fa-arrow-left mr-1"></i> Screening Centre</a>
    </div>

    <?php if ($error !== ''): ?>
        <div class="alert alert-danger health-alert"><i class="fas fa-exclamation-circle mr-2"></i><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>
    <div class="alert alert-info health-alert small">
        <i class="fas fa-shield-alt mr-2"></i>Operational flags support follow-up; they are not a diagnosis. Confirm unusual readings and refer according to local clinical policy.
    </div>

    <form method="post" id="healthEncounterForm" autocomplete="off">
        <?= csrf_input() ?>
        <input type="hidden" name="record_id" value="<?= $recordId ?>">
        <input type="hidden" name="person_type" id="person_type" value="<?= htmlspecialchars($personType) ?>">
        <input type="hidden" name="person_id" id="person_id" value="<?= $personId ?>">

        <div class="health-panel mb-4">
            <div class="health-panel-header">
                <div><h2 class="health-section-title">1. Person and encounter</h2><p class="health-section-copy">Find the person by CRN or SRN, then confirm the encounter context.</p></div>
            </div>
            <div class="health-panel-body">
                <div class="row">
                    <div class="col-lg-5 mb-3">
                        <label for="identifier" class="font-weight-bold">CRN or SRN</label>
                        <div class="input-group">
                            <input class="form-control" id="identifier" name="identifier" value="<?= htmlspecialchars($identifier) ?>" <?= $editing ? 'readonly' : '' ?> required>
                            <?php if (!$editing): ?><div class="input-group-append"><button class="btn btn-outline-info" type="button" id="findPerson"><i class="fas fa-search mr-1"></i> Find</button></div><?php endif; ?>
                        </div>
                        <small class="form-text text-muted">Search is restricted to your authorized church.</small>
                    </div>
                    <div class="col-md-3 mb-3">
                        <label class="font-weight-bold" for="encounter_type">Encounter type</label>
                        <?php $encounterType = $_POST['encounter_type'] ?? ($record['encounter_type'] ?? 'screening'); ?>
                        <select class="form-control" id="encounter_type" name="encounter_type">
                            <?php foreach (['screening'=>'Routine screening','follow_up'=>'Follow-up','outreach'=>'Outreach','other'=>'Other'] as $value=>$label): ?>
                                <option value="<?= $value ?>" <?= $encounterType === $value ? 'selected' : '' ?>><?= $label ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-4 mb-3">
                        <label class="font-weight-bold" for="recorded_at">Observed at</label>
                        <input type="datetime-local" class="form-control" id="recorded_at" name="recorded_at" value="<?= htmlspecialchars($recordedAt) ?>" max="<?= date('Y-m-d\TH:i') ?>" required>
                    </div>
                </div>
                <div id="personMessage" class="small text-danger mb-2" aria-live="polite"></div>
                <div id="personCard" class="health-person <?= $personId ? '' : 'd-none' ?>">
                    <?php $displayPerson = $record ?: ($prefillPerson ?: []); ?>
                    <img class="health-avatar" id="personPhoto" src="<?= htmlspecialchars(!empty($displayPerson['photo']) ? BASE_URL . '/' . ltrim($displayPerson['photo'], '/') : BASE_URL . '/assets/img/undraw_profile.svg') ?>" alt="">
                    <div><div class="health-person-name" id="personName"><?= htmlspecialchars($displayPerson['full_name'] ?? '') ?></div><div class="health-person-meta" id="personMeta"><?= htmlspecialchars(trim(($displayPerson['identifier'] ?? '') . (!empty($displayPerson['gender']) ? ' · ' . $displayPerson['gender'] : ''))) ?></div></div>
                </div>
            </div>
        </div>

        <div class="health-panel mb-4">
            <div class="health-panel-header"><div><h2 class="health-section-title">2. Measurements</h2><p class="health-section-copy">All fields are optional, but at least one measurement or screening is required.</p></div></div>
            <div class="health-panel-body">
                <div class="metric-grid">
                    <?php foreach ($definitions as $code => $definition): if ($code === 'blood_glucose') continue; ?>
                        <div class="metric-card">
                            <label class="metric-label" for="obs_<?= $code ?>"><?= htmlspecialchars($definition['label']) ?></label>
                            <div class="input-unit"><input type="number" class="form-control" id="obs_<?= $code ?>" name="observations[<?= $code ?>]" value="<?= htmlspecialchars((string) $values[$code]) ?>" min="<?= $definition['min'] ?>" max="<?= $definition['max'] ?>" step="<?= $definition['step'] ?>"><span><?= htmlspecialchars($definition['unit']) ?></span></div>
                            <div class="metric-help">Accepted technical range: <?= $definition['min'] ?>–<?= $definition['max'] ?></div>
                        </div>
                    <?php endforeach; ?>
                    <div class="metric-card">
                        <label class="metric-label" for="obs_blood_glucose">Blood glucose</label>
                        <div class="input-unit"><input type="number" class="form-control" id="obs_blood_glucose" name="observations[blood_glucose]" value="<?= htmlspecialchars((string) $values['blood_glucose']) ?>" min="0.5" max="50" step="0.1"><span>mmol/L</span></div>
                        <?php $glucoseContext = $_POST['observation_context']['blood_glucose'] ?? ($record['observations']['blood_glucose']['measurement_context'] ?? ''); ?>
                        <select class="form-control form-control-sm mt-2" name="observation_context[blood_glucose]">
                            <option value="">Select context</option><option value="fasting" <?= $glucoseContext==='fasting'?'selected':'' ?>>Fasting</option><option value="random" <?= $glucoseContext==='random'?'selected':'' ?>>Random</option><option value="post_meal" <?= $glucoseContext==='post_meal'?'selected':'' ?>>Post-meal</option>
                        </select>
                    </div>
                </div>
            </div>
        </div>

        <div class="health-panel mb-4">
            <div class="health-panel-header"><div><h2 class="health-section-title">3. Screening results</h2><p class="health-section-copy">Screening results are separate from vital signs and retain test context.</p></div></div>
            <div class="health-panel-body"><div class="row">
                <?php foreach (['malaria'=>'Malaria','hepatitis_b'=>'Hepatitis B'] as $code=>$label): $method = $_POST['screening_method'][$code] ?? ($record['screenings'][$code]['method'] ?? ''); ?>
                    <div class="col-md-6 mb-3"><div class="metric-card h-100"><label class="metric-label" for="screen_<?= $code ?>"><?= $label ?></label><select class="form-control" id="screen_<?= $code ?>" name="screenings[<?= $code ?>]"><option value="">Not recorded</option><?php foreach (['negative'=>'Negative','positive'=>'Positive','indeterminate'=>'Indeterminate','not_tested'=>'Not tested'] as $value=>$text): ?><option value="<?= $value ?>" <?= $screenings[$code] === $value ? 'selected' : '' ?>><?= $text ?></option><?php endforeach; ?></select><input class="form-control form-control-sm mt-2" name="screening_method[<?= $code ?>]" value="<?= htmlspecialchars((string) $method) ?>" maxlength="100" placeholder="Method / kit (optional)"></div></div>
                <?php endforeach; ?>
            </div></div>
        </div>

        <div class="health-panel mb-4">
            <div class="health-panel-header"><div><h2 class="health-section-title">4. Assessment and follow-up</h2><p class="health-section-copy">Document observations, next steps and referral ownership.</p></div></div>
            <div class="health-panel-body">
                <div class="form-group"><label class="font-weight-bold" for="notes">Encounter notes</label><textarea class="form-control" id="notes" name="notes" rows="4" maxlength="5000" placeholder="Relevant observations, actions taken and instructions given."><?= htmlspecialchars($_POST['notes'] ?? ($record['notes'] ?? '')) ?></textarea></div>
                <div class="row">
                    <?php $followStatus = $_POST['follow_up_status'] ?? ($record['follow_up_status'] ?? 'none'); ?>
                    <div class="col-md-4 form-group"><label class="font-weight-bold">Follow-up status</label><select class="form-control" name="follow_up_status" id="follow_up_status"><?php foreach (['none'=>'None','recommended'=>'Recommended','scheduled'=>'Scheduled','completed'=>'Completed','cancelled'=>'Cancelled'] as $value=>$label): ?><option value="<?= $value ?>" <?= $followStatus===$value?'selected':'' ?>><?= $label ?></option><?php endforeach; ?></select></div>
                    <div class="col-md-4 form-group"><label class="font-weight-bold">Follow-up date</label><input type="date" class="form-control" name="follow_up_date" value="<?= htmlspecialchars($_POST['follow_up_date'] ?? ($record['follow_up_date'] ?? '')) ?>"></div>
                    <div class="col-md-4 form-group"><label class="font-weight-bold">Referral destination</label><input class="form-control" name="referral_destination" maxlength="180" value="<?= htmlspecialchars($_POST['referral_destination'] ?? ($record['referral_destination'] ?? '')) ?>" placeholder="Clinic, hospital or care team"></div>
                </div>
                <?php if ($editing): ?><div class="form-group"><label class="font-weight-bold" for="correction_reason">Correction reason <span class="text-danger">*</span></label><textarea class="form-control" id="correction_reason" name="correction_reason" rows="2" minlength="8" maxlength="500" required><?= htmlspecialchars($_POST['correction_reason'] ?? '') ?></textarea><small class="form-text text-muted">Original authorship remains unchanged; the before/after values and your reason are retained in the audit trail.</small></div><?php endif; ?>
                <div class="health-actions d-flex flex-wrap justify-content-end"><a class="btn btn-light mr-2" href="health_list.php">Cancel</a><button class="btn btn-health-primary" type="submit" id="saveEncounter"><i class="fas fa-check mr-1"></i><?= $editing ? 'Save audited correction' : 'Finalize encounter' ?></button></div>
            </div>
        </div>
    </form>
</div>
<script>
(function(){
    const findButton=document.getElementById('findPerson');
    if(!findButton)return;
    const message=document.getElementById('personMessage');
    function showPerson(person){
        document.getElementById('person_type').value=person.person_type;
        document.getElementById('person_id').value=person.person_id;
        document.getElementById('personName').textContent=person.full_name;
        document.getElementById('personMeta').textContent=[person.identifier,person.gender,person.age!==null?person.age+' years':null,person.class_name].filter(Boolean).join(' · ');
        document.getElementById('personPhoto').src=person.photo ? <?= json_encode(rtrim(BASE_URL, '/')) ?>+'/'+String(person.photo).replace(/^\//,'') : <?= json_encode(rtrim(BASE_URL, '/') . '/assets/img/undraw_profile.svg') ?>;
        document.getElementById('personCard').classList.remove('d-none'); message.textContent='';
    }
    function lookup(){
        const identifier=document.getElementById('identifier').value.trim();
        if(!identifier){message.textContent='Enter a CRN or SRN.';return;}
        findButton.disabled=true;findButton.innerHTML='<i class="fas fa-spinner fa-spin mr-1"></i> Finding';
        fetch('ajax_health_person_lookup.php?identifier='+encodeURIComponent(identifier),{headers:{'Accept':'application/json'}})
            .then(async response=>{const data=await response.json();if(!response.ok||!data.success)throw new Error(data.error||'Person not found.');return data;})
            .then(data=>showPerson(data.person)).catch(error=>{message.textContent=error.message;document.getElementById('personCard').classList.add('d-none');document.getElementById('person_id').value='';})
            .finally(()=>{findButton.disabled=false;findButton.innerHTML='<i class="fas fa-search mr-1"></i> Find';});
    }
    findButton.addEventListener('click',lookup);
    document.getElementById('identifier').addEventListener('keydown',event=>{if(event.key==='Enter'){event.preventDefault();lookup();}});
})();
</script>
<?php
$page_content = ob_get_clean();
require_once __DIR__ . '/../includes/layout.php';
