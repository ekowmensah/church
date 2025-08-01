<?php
require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../includes/admin_auth.php';

$id = isset($_GET['id']) ? intval($_GET['id']) : 0;
$editing = $id > 0;
$error = '';
$success = '';
$record = [
    'srn'=>'','photo'=>'','last_name'=>'','middle_name'=>'','first_name'=>'','dob'=>'','contact'=>'','gps_address'=>'','residential_address'=>'','organization'=>'','school_attend'=>'','father_name'=>'','father_contact'=>'','father_occupation'=>'','mother_name'=>'','mother_contact'=>'','mother_occupation'=>'','church_id'=>'','class_id'=>'','father_member_id'=>'','mother_member_id'=>'','father_is_member'=>'','mother_is_member'=>''
];
if ($editing) {
    $stmt = $conn->prepare('SELECT * FROM sunday_school WHERE id = ?');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $result = $stmt->get_result();
    $record = $result->fetch_assoc();
    if (!$record) { $error = 'Record not found.'; }
    // Ensure all expected fields exist
    foreach(['church_id','class_id','father_member_id','mother_member_id','father_is_member','mother_is_member'] as $f) {
        if (!isset($record[$f])) $record[$f] = '';
    }
    $stmt->close();
}
if ($_SERVER['REQUEST_METHOD']==='POST') {
    foreach($record as $k=>$v) if(isset($_POST[$k])) $record[$k]=trim($_POST[$k]);
    // Handle photo upload
    if (!empty($_FILES['photo']['name'])) {
        $target_dir = __DIR__.'/../uploads/sundayschool/';
        if (!is_dir($target_dir)) mkdir($target_dir,0777,true);
        $filename = uniqid('ss_').basename($_FILES['photo']['name']);
        $target_file = $target_dir . $filename;
        if (move_uploaded_file($_FILES['photo']['tmp_name'], $target_file)) {
            $record['photo'] = $filename;
        } else {
            $error = 'Photo upload failed.';
        }
    }
    // Ensure integer fields are null if empty
        foreach(['church_id','class_id','father_member_id','mother_member_id'] as $f) {
            if ($record[$f]==='') $record[$f] = null;
        }
        if (!$error) {
        if ($editing) {
            $stmt = $conn->prepare('UPDATE sunday_school SET srn=?, photo=?, last_name=?, middle_name=?, first_name=?, dob=?, contact=?, gps_address=?, residential_address=?, organization=?, school_attend=?, father_name=?, father_contact=?, father_occupation=?, mother_name=?, mother_contact=?, mother_occupation=?, church_id=?, class_id=?, father_member_id=?, mother_member_id=?, father_is_member=?, mother_is_member=? WHERE id=?');
            $stmt->bind_param('ssssssssssssssssssiiissi', $record['srn'],$record['photo'],$record['last_name'],$record['middle_name'],$record['first_name'],$record['dob'],$record['contact'],$record['gps_address'],$record['residential_address'],$record['organization'],$record['school_attend'],$record['father_name'],$record['father_contact'],$record['father_occupation'],$record['mother_name'],$record['mother_contact'],$record['mother_occupation'],$record['church_id'],$record['class_id'],$record['father_member_id'],$record['mother_member_id'],$record['father_is_member'],$record['mother_is_member'],$id);
            $stmt->execute();
            $stmt->close();
            $success = 'Record updated.';
            header('Location: sundayschool_list.php');
            exit;
        } else {
            $stmt = $conn->prepare('INSERT INTO sunday_school (srn, photo, last_name, middle_name, first_name, dob, contact, gps_address, residential_address, organization, school_attend, father_name, father_contact, father_occupation, mother_name, mother_contact, mother_occupation, church_id, class_id, father_member_id, mother_member_id, father_is_member, mother_is_member) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
            $stmt->bind_param('ssssssssssssssssssiiiss', $record['srn'],$record['photo'],$record['last_name'],$record['middle_name'],$record['first_name'],$record['dob'],$record['contact'],$record['gps_address'],$record['residential_address'],$record['organization'],$record['school_attend'],$record['father_name'],$record['father_contact'],$record['father_occupation'],$record['mother_name'],$record['mother_contact'],$record['mother_occupation'],$record['church_id'],$record['class_id'],$record['father_member_id'],$record['mother_member_id'],$record['father_is_member'],$record['mother_is_member']);
            $stmt->execute();
            $stmt->close();
            $success = 'Record added.';
            header('Location: sundayschool_list.php');
            exit;
            $record = array_map(function(){return '';}, $record);
        }
    }
}
ob_start();
?>
<style>
    .ss-section {
        background: #f8f9fa;
        border-radius: 12px;
        padding: 24px 18px 18px 18px;
        margin-bottom: 24px;
        box-shadow: 0 2px 8px rgba(0,0,0,0.03);
    }
    .ss-section-title {
        font-weight: 600;
        font-size: 1.15rem;
        margin-bottom: 1.2rem;
        display: flex;
        align-items: center;
        gap: 0.5em;
    }
    .ss-parent-section {
        background: #e9ecef;
        border-left: 4px solid #007bff;
        padding: 20px 15px 15px 15px;
        border-radius: 10px;
        margin-bottom: 20px;
    }
    .ss-icon { color: #007bff; margin-right: 6px; }
    @media (max-width: 767px) {
        .form-row { flex-direction: column; }
        .form-group { width: 100% !important; }
    }
</style>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css"/>
<div class="container-fluid px-lg-5 px-md-4 px-2">
    <div class="card shadow-lg border-0 mb-4 mt-3">
        <div class="card-header bg-primary text-white d-flex align-items-center">
            <i class="fa-solid fa-child ss-icon"></i>
            <span class="h4 mb-0"><?=$editing?'Edit':'Add'?> Sunday School Child</span>
        </div>
        <div class="card-body p-4">
            <?php if($error): ?><div class="alert alert-danger"><?=$error?></div><?php endif; ?>
            <?php if($success): ?><div class="alert alert-success"><?=$success?></div><?php endif; ?>
            
<form method="post" enctype="multipart/form-data">
                <div class="ss-section mb-4">
                    <div class="ss-section-title"><i class="fa-solid fa-user-graduate ss-icon"></i> Personal Information</div>
<div class="form-row">
    <div class="form-group col-md-4">
        <label>Church</label>
        <select name="church_id" id="church_id" class="form-control" required>
<option value="">Select Church</option>
<?php $churches = $conn->query("SELECT id, name FROM churches ORDER BY name");
while($c = $churches->fetch_assoc()): ?>
    <option value="<?=$c['id']?>" <?=($record['church_id']??'')==$c['id']?'selected="selected"':''?>><?=htmlspecialchars($c['name'])?></option>
<?php endwhile; ?>
</select>
    </div>
    <div class="form-group col-md-4">
        <label>Bible Class</label>
        <select name="class_id" id="class_id" class="form-control" required <?=empty($record['church_id'])?'disabled':''?>>
<option value="">-- Select Class --</option>
<?php if (!empty($record['church_id'])): 
    $classes = $conn->query("SELECT id, name FROM bible_classes WHERE church_id = ".intval($record['church_id'])." ORDER BY name ASC");
    while($cl = $classes->fetch_assoc()): ?>
        <option value="<?=$cl['id']?>" <?=($record['class_id']??'')==$cl['id']?'selected="selected"':''?>><?=htmlspecialchars($cl['name'])?></option>
    <?php endwhile; endif; ?>
</select>
<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script>
$(function(){
    // Only one initialization for Select2
    $('#class_id').select2();
    // Only use AJAX if church changes
    $('#church_id').on('change', function(){
        var churchId = this.value;
        $('#class_id').prop('disabled', !churchId);
        if(!churchId){
            $('#class_id').html('<option value="">-- Select Class --</option>');
            $('#class_id').val('').trigger('change');
            return;
        }
        $.ajax({
            url: 'ajax_get_classes_by_church.php',
            data: {church_id: churchId},
            method: 'GET',
            success: function(data){
                $('#class_id').html(data);
                $('#class_id').val('').trigger('change');
                $('#class_id').select2();
            }
        });
    });
    // Robustly pre-populate parent Select2 fields (Father/Mother)
    var fatherMemberId = "<?=isset($record['father_member_id'])?$record['father_member_id']:''?>";
    var fatherMemberText = <?php
    if (!empty($record['father_member_id'])) {
        $fm = $conn->query("SELECT CONCAT(last_name, ' ', first_name, ' ', middle_name, ' (', crn, ')') as name FROM members WHERE id=".intval($record['father_member_id']));
        $row = $fm->fetch_assoc();
        echo json_encode($row ? $row['name'] : '');
    } else { echo '""'; }
    ?>;
    if(fatherMemberId && fatherMemberText && $('#father_member_id').length){
        if($('#father_member_id option[value="'+fatherMemberId+'"]').length==0){
            $('#father_member_id').append(new Option(fatherMemberText, fatherMemberId, true, true));
        }
        $('#father_member_id').val(fatherMemberId).trigger('change');
    }
    // Now initialize Select2 (after option is present and selected)
    if($('#father_member_id').length && !$('#father_member_id').hasClass('select2-hidden-accessible')){
        $('#father_member_id').select2({
            placeholder: 'Search Father by name or CRN',
            ajax: {
                url: $('#church_id').val() ? 'ajax_members_by_church.php' : '',
                dataType: 'json',
                delay: 250,
                data: function(params) {
                    return {q: params.term, church_id: $('#church_id').val(), gender: 'male'};
                },
                processResults: function (data) {
                    return {results: data.results};
                },
                cache: true
            },
            minimumInputLength: 2
        });
    }
    var motherMemberId = "<?=isset($record['mother_member_id'])?$record['mother_member_id']:''?>";
    var motherMemberText = <?php
    if (!empty($record['mother_member_id'])) {
        $mm = $conn->query("SELECT CONCAT(last_name, ' ', first_name, ' ', middle_name, ' (', crn, ')') as name FROM members WHERE id=".intval($record['mother_member_id']));
        $row = $mm->fetch_assoc();
        echo json_encode($row ? $row['name'] : '');
    } else { echo '""'; }
    ?>;
    if(motherMemberId && motherMemberText && $('#mother_member_id').length){
        if($('#mother_member_id option[value="'+motherMemberId+'"]:selected').length==0){
            $('#mother_member_id').append(new Option(motherMemberText, motherMemberId, true, true)).trigger('change');
        } else {
            $('#mother_member_id').val(motherMemberId).trigger('change');
        }
    }
});
</script>
    </div>
</div>
<script>
$(function(){
    function loadClasses(churchId, selectedClassId) {
    $('#class_id').prop('disabled', !churchId);
    if(!churchId){ $('#class_id').html('<option value="">-- Select Class --</option>'); $('#class_id').select2(); return; }
    $.ajax({
        url: 'ajax_get_classes_by_church.php',
        data: {church_id: churchId, class_id: selectedClassId},
        method: 'GET',
        success: function(data){
            $('#class_id').html(data);
            if(selectedClassId) $('#class_id').val(selectedClassId);
            $('#class_id').select2();
        },
        error: function(xhr, status, err){
            alert('Could not load classes: ' + xhr.responseText);
        }
    });
}
    $('#church_id').on('change', function(){
        loadClasses(this.value, '');
        $('#class_id').val('');
        updateSRN();
    });
    $('#class_id').on('change', updateSRN);
    function updateSRN(){
        var churchId = $('#church_id').val();
        var classId = $('#class_id').val();
        if(churchId && classId){
            $.get('get_next_crn.php', {church_id: churchId, class_id: classId}, function(data){
                $('input[name="srn"]').val(data);
            });
        } else {
            $('input[name="srn"]').val('');
        }
    }
    // On page load, if editing or after error, populate classes and SRN
    $('#class_id').select2();
    // Only use AJAX if church changes
    $('#church_id').on('change', function(){
        var churchId = this.value;
        $('#class_id').prop('disabled', !churchId);
        if(!churchId){
            $('#class_id').html('<option value="">-- Select Class --</option>');
            $('#class_id').val('').trigger('change');
            return;
        }
        $.ajax({
            url: 'ajax_get_classes_by_church.php',
            data: {church_id: churchId},
            method: 'GET',
            success: function(data){
                $('#class_id').html(data);
                $('#class_id').val('').trigger('change');
            }
        });
    });
    // Pre-populate parent select2 fields robustly
    $('#father_is_member').val("<?=isset($record['father_is_member'])?$record['father_is_member']:''?>");
    $('#mother_is_member').val("<?=isset($record['mother_is_member'])?$record['mother_is_member']:''?>");
    // For select2 member selects, if editing and value exists, ensure the option is present and selected

    var motherMemberId = "<?=isset($record['mother_member_id'])?$record['mother_member_id']:''?>";
    var motherMemberText = <?php
    if (!empty($record['mother_member_id'])) {
        $mm = $conn->query("SELECT CONCAT(last_name, ' ', first_name, ' ', middle_name) as name FROM members WHERE id=".intval($record['mother_member_id']));
        $row = $mm->fetch_assoc();
        echo json_encode($row ? $row['name'] : '');
    } else { echo '""'; }
    ?>;
    if(motherMemberId && motherMemberText){
        if($('#mother_member_id option[value="'+motherMemberId+'"]:selected').length==0){
            $('#mother_member_id').append(new Option(motherMemberText, motherMemberId, true, true)).trigger('change');
        } else {
            $('#mother_member_id').val(motherMemberId).trigger('change');
        }
    }

    // Robustly pre-populate parent fields on edit
    document.addEventListener('DOMContentLoaded', function() {
        // Father is_member
        var fatherIsMember = "<?=isset($record['father_is_member'])?$record['father_is_member']:''?>";
        if(fatherIsMember){
            $('#father_is_member').val(fatherIsMember).trigger('change');
        }
        // Father member
        var fatherMemberId = "<?=isset($record['father_member_id'])?$record['father_member_id']:''?>";
        if(fatherIsMember==='yes' && fatherMemberId){
            // If editing, ensure select2 loads with correct member
            var $fatherSel = $('#father_member_id');
            if($fatherSel.find('option[value="'+fatherMemberId+'"]').length===0 && fatherMemberId){
                $fatherSel.append(new Option('<?=htmlspecialchars(@$row["name"]??"")?>', fatherMemberId, true, true)).trigger('change');
            } else {
                $fatherSel.val(fatherMemberId).trigger('change');
            }
        }
        // Mother is_member
        var motherIsMember = "<?=isset($record['mother_is_member'])?$record['mother_is_member']:''?>";
        if(motherIsMember){
            $('#mother_is_member').val(motherIsMember).trigger('change');
        }
        // Mother member
        var motherMemberId = "<?=isset($record['mother_member_id'])?$record['mother_member_id']:''?>";
        if(motherIsMember==='yes' && motherMemberId){
            var $motherSel = $('#mother_member_id');
            if($motherSel.find('option[value="'+motherMemberId+'"]').length===0 && motherMemberId){
                $motherSel.append(new Option('<?=htmlspecialchars(@$row["name"]??"")?>', motherMemberId, true, true)).trigger('change');
            } else {
                $motherSel.val(motherMemberId).trigger('change');
            }
        }
    });
});
</script>
        <div class="form-row">
            <div class="form-group col-md-3">
                <label>SRN</label>
                <input type="text" name="srn" class="form-control" value="<?=htmlspecialchars($record['srn'])?>" required readonly>
            </div>
            <div class="form-group col-md-3">
                <label>Picture</label><br>
                <?php if($record['photo']): ?><img src="<?=BASE_URL?>/uploads/sundayschool/<?=rawurlencode($record['photo'])?>" style="height:50px;width:50px;object-fit:cover;border-radius:8px;"/><br><?php endif; ?>
                <input type="file" name="photo" class="form-control-file">
            </div>
            <div class="form-group col-md-3">
                <label>Surname</label>
                <input type="text" name="last_name" class="form-control" value="<?=htmlspecialchars($record['last_name'])?>" required>
            </div>
            <div class="form-group col-md-3">
                <label>Middle Name</label>
                <input type="text" name="middle_name" class="form-control" value="<?=htmlspecialchars($record['middle_name'])?>">
            </div>
        </div>
        <div class="form-row">
            <div class="form-group col-md-3">
                <label>First Name</label>
                <input type="text" name="first_name" class="form-control" value="<?=htmlspecialchars($record['first_name'])?>">
            </div>
            <div class="form-group col-md-3 d-flex align-items-end">
                <div style="width:100%">
                    <label>Date of Birth</label>
                    <input type="date" name="dob" id="dob" class="form-control" value="<?=htmlspecialchars($record['dob'])?>">
                </div>
                <span id="age_display" class="ml-2 text-muted small" style="white-space:nowrap"></span>
            </div>
<script>
$(function(){
  function updateAgeDisplay(){
    var val = $('#dob').val();
    if(!val){ $('#age_display').text(''); return; }
    var birth = new Date(val);
    var now = new Date();
    if(isNaN(birth.getTime())){ $('#age_display').text(''); return; }
    var years = now.getFullYear() - birth.getFullYear();
    var months = now.getMonth() - birth.getMonth();
    if(months < 0){ years--; months += 12; }
    var ageStr = years+'yrs'+(months>0?(' '+months+'mo'):'');
    $('#age_display').text(ageStr);
  }
  $('#dob').on('input change', updateAgeDisplay);
  updateAgeDisplay();
});
</script>
        </div>
        <hr/>
        <div class="ss-section-title"><i class="fa-solid fa-address-card ss-icon"></i> Other Details</div>
        <div class="form-row">
            <div class="form-group col-md-3">
                <label>Contact</label>
                <input type="text" name="contact" class="form-control" value="<?=htmlspecialchars($record['contact'])?>">
            </div>
            <div class="form-group col-md-3">
                <label>GPS Address</label>
                <input type="text" name="gps_address" class="form-control" value="<?=htmlspecialchars($record['gps_address'])?>">
            </div>
            <div class="form-group col-md-3">
                <label>Residential Address</label>
                <input type="text" name="residential_address" class="form-control" value="<?=htmlspecialchars($record['residential_address'])?>">
            </div>
            <div class="form-group col-md-3">
                <label>Organisation</label>
                <input type="text" name="organization" class="form-control" value="<?=htmlspecialchars($record['organization'])?>">
            </div>
        </div>
        <div class="form-row">
            <div class="form-group col-md-4">
                <label>School Attend</label>
                <input type="text" name="school_attend" class="form-control" value="<?=htmlspecialchars($record['school_attend'])?>">
            </div>
        </div>
        <hr/>
        <div class="ss-section-title"><i class="fa-solid fa-people-roof ss-icon"></i> Parent Information</div>
<div class="ss-parent-section mb-3">
    <div class="form-row align-items-end">
        <div class="form-group col-md-4">
            <label><i class="fa-solid fa-person ss-icon"></i>Is Father a Church Member?</label>
            <select name="father_is_member" id="father_is_member" class="form-control" required>
                <option value="" disabled selected>Select...</option>
                <option value="no" <?=($record['father_is_member']??'')=='no'?'selected':''?>>No</option>
                <option value="yes" <?=($record['father_is_member']??'')=='yes'?'selected':''?>>Yes</option>
            </select>
        </div>
    </div>
    <div class="form-row" id="father_member_row" style="display:none;">
        <div class="form-group col-md-4">
            <label><i class="fa-solid fa-id-badge ss-icon"></i>Father (Select Member)</label>
            <select name="father_member_id" id="father_member_id" class="form-control">
<?php if (!empty($record['father_member_id'])): 
    $fm = $conn->query("SELECT id, CONCAT(last_name, ' ', first_name, ' ', middle_name, ' (', crn, ')') as name FROM members WHERE id=".intval($record['father_member_id']));
    if($row = $fm->fetch_assoc()): ?>
        <option value="<?=$row['id']?>" selected="selected"><?=htmlspecialchars($row['name'])?></option>
    <?php endif; endif; ?>
</select>
        </div>
        <div class="form-group col-md-4">
            <label><i class="fa-solid fa-phone ss-icon"></i>Father's Contact</label>
            <input type="text" name="father_contact" id="father_contact_member" class="form-control" readonly>
        </div>
        <div class="form-group col-md-4">
            <label><i class="fa-solid fa-briefcase ss-icon"></i>Father's Occupation</label>
            <input type="text" name="father_occupation" id="father_occupation_member" class="form-control" readonly>
        </div>
    </div>
    <div class="form-row" id="father_name_row">
        <div class="form-group col-md-4">
            <label><i class="fa-solid fa-signature ss-icon"></i>Father's Name</label>
            <input type="text" name="father_name" class="form-control" value="<?=htmlspecialchars($record['father_name'])?>">
        </div>
        <div class="form-group col-md-4">
            <label><i class="fa-solid fa-phone ss-icon"></i>Father's Contact</label>
            <input type="text" name="father_contact" class="form-control" value="<?=htmlspecialchars($record['father_contact'])?>">
        </div>
        <div class="form-group col-md-4">
            <label><i class="fa-solid fa-briefcase ss-icon"></i>Father's Occupation</label>
            <input type="text" name="father_occupation" class="form-control" value="<?=htmlspecialchars($record['father_occupation'])?>">
        </div>
    </div>
</div>

<div class="ss-parent-section mb-2">
    <div class="form-row align-items-end">
        <div class="form-group col-md-4">
            <label><i class="fa-solid fa-person-dress ss-icon"></i>Is Mother a Church Member?</label>
            <select name="mother_is_member" id="mother_is_member" class="form-control" required>
                <option value="" disabled selected>Select...</option>
                <option value="no" <?=($record['mother_is_member']??'')=='no'?'selected':''?>>No</option>
                <option value="yes" <?=($record['mother_is_member']??'')=='yes'?'selected':''?>>Yes</option>
            </select>
        </div>
    </div>
    <div class="form-row" id="mother_member_row" style="display:none;">
        <div class="form-group col-md-4">
            <label><i class="fa-solid fa-id-badge ss-icon"></i>Mother (Select Member)</label>
            <select name="mother_member_id" id="mother_member_id" class="form-control">
<?php if (!empty($record['mother_member_id'])): 
    $mm = $conn->query("SELECT id, CONCAT(last_name, ' ', first_name, ' ', middle_name) as name FROM members WHERE id=".intval($record['mother_member_id']));
    if($row = $mm->fetch_assoc()): ?>
        <option value="<?=$row['id']?>" selected="selected"><?=htmlspecialchars($row['name'])?></option>
    <?php endif; endif; ?>
</select>
<script>$(function(){
  // Robust Select2 init for Father (preserve pre-selected on edit)
  var fatherInit = false;
  if($('#father_member_id option:selected').val()) fatherInit = true;
  $('#father_member_id').select2({
    width:'100%',
    ajax:{
      url:'ajax_members_by_church.php',
      dataType:'json',
      delay:250,
      data:function(params){return{q:params.term,church_id:$('#church_id').val()};},
      processResults:function(data){return{results:data.results};},
      cache:true
    },
    minimumInputLength:1,
    placeholder:'Search member...'
  });
  if(fatherInit){
    // Re-set to preselected value after init
    $('#father_member_id').trigger('change');
  }
  // Mother as before
  $('#mother_member_id').select2({width:'100%',ajax:{url:'ajax_members_by_church.php',dataType:'json',delay:250,data:function(params){return{q:params.term,church_id:$('#church_id').val()};},processResults:function(data){return{results:data.results};},cache:true},minimumInputLength:1,placeholder:'Search member...'});
});</script>
        </div>
        <div class="form-group col-md-4">
            <label><i class="fa-solid fa-phone ss-icon"></i>Mother's Contact</label>
            <input type="text" name="mother_contact" id="mother_contact_member" class="form-control" readonly>
        </div>
        <div class="form-group col-md-4">
            <label><i class="fa-solid fa-briefcase ss-icon"></i>Mother's Occupation</label>
            <input type="text" name="mother_occupation" id="mother_occupation_member" class="form-control" readonly>
        </div>
    </div>
    <div class="form-row" id="mother_name_row">
        <div class="form-group col-md-4">
            <label><i class="fa-solid fa-signature ss-icon"></i>Mother's Name</label>
            <input type="text" name="mother_name" class="form-control" value="<?=htmlspecialchars($record['mother_name'])?>">
        </div>
        <div class="form-group col-md-4">
            <label><i class="fa-solid fa-phone ss-icon"></i>Mother's Contact</label>
            <input type="text" name="mother_contact" class="form-control" value="<?=htmlspecialchars($record['mother_contact'])?>">
        </div>
        <div class="form-group col-md-4">
            <label><i class="fa-solid fa-briefcase ss-icon"></i>Mother's Occupation</label>
            <input type="text" name="mother_occupation" class="form-control" value="<?=htmlspecialchars($record['mother_occupation'])?>">
        </div>
    </div>
</div>


<script src="https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/js/select2.min.js"></script>
<link href="https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/css/select2.min.css" rel="stylesheet" />
<script>
// Real-time phone validation & duplicate check for main and parent contact fields
$(function(){
  function setupPhoneValidation(inputSelector, idSelector) {
    var phoneInput = $(inputSelector);
    var feedback = $('<div class="invalid-feedback">Please enter a 10-digit valid phone number.</div>');
    var validFeedback = $('<div class="valid-feedback">Looks good!</div>');
    if(phoneInput.length){
      if(phoneInput.next('.invalid-feedback').length==0) phoneInput.after(feedback);
      if(phoneInput.nextAll('.valid-feedback').length==0) phoneInput.after(validFeedback);
      var lastVal = '';
      phoneInput.on('input', function(){
        var val = $(this).val().replace(/\s+/g,'');
        var valid = !val.length || /^((0|233|\+233)[235]\d{8})$/.test(val); // allow empty for parent fields
        var self = $(this);
        if(!valid && val.length>0){
          feedback.text('Please enter a 10-digit valid phone number.');
          self.removeClass('is-valid').addClass('is-invalid');
          return;
        }
        if(valid && val.length>=10 && val!==lastVal){
          // AJAX duplicate check
          $.get('views/ajax_check_phone_duplicate.php', {phone: val, id: $(idSelector).val()||''}, function(resp){
            if(resp && typeof resp.exists !== 'undefined') {
              if(resp.exists){
                feedback.text('Phone already exists.');
                self.removeClass('is-valid').addClass('is-invalid');
              } else {
                self.removeClass('is-invalid').addClass('is-valid');
              }
            } else {
              self.removeClass('is-valid is-invalid');
              feedback.text('Could not validate phone. Try again.');
            }
          },'json').fail(function(){
            self.removeClass('is-valid is-invalid');
            feedback.text('Could not validate phone (AJAX error).');
          });
        } else if(valid && val.length>=10) {
          self.removeClass('is-invalid').addClass('is-valid');
        } else if(!val.length) {
          self.removeClass('is-valid is-invalid');
        }
        lastVal = val;
      });
    }
  }
  setupPhoneValidation("input[name='contact']", "input[name='id']");
  setupPhoneValidation("#father_contact_member", "input[name='id']");
  setupPhoneValidation("#mother_contact_member", "input[name='id']");
});
</script>
<script>
function toggleParentFields() {
    let church_id = $('#church_id').val();
    // Father
    if ($('#father_is_member').val()==='yes') {
        $('#father_name_row').hide();
        $('#father_member_row').show();
        $('#father_member_id').val(null).trigger('change');
        $('#father_contact_member').val('');
        $('#father_occupation_member').val('');
        $('#father_member_id').select2({
            placeholder: 'Search Father by name or CRN',
            ajax: {
                url: church_id ? 'ajax_members_by_church.php' : '',
                dataType: 'json',
                delay: 250,
                data: function(params) {
                    return {q: params.term, church_id: church_id};
                },
                processResults: function (data) {
                    return {results: data};
                },
                cache: true
            },
            minimumInputLength: 2
        });
    } else {
        $('#father_name_row').show();
        $('#father_member_row').hide();
        if ($('#father_member_id').hasClass('select2-hidden-accessible')) {
    $('#father_member_id').select2('destroy');
}
        $('#father_contact_member').val('');
        $('#father_occupation_member').val('');
    }
    // Mother
    if ($('#mother_is_member').val()==='yes') {
        $('#mother_name_row').hide();
        $('#mother_member_row').show();
        $('#mother_member_id').val(null).trigger('change');
        $('#mother_contact_member').val('');
        $('#mother_occupation_member').val('');
        $('#mother_member_id').select2({
            placeholder: 'Search Mother by name or CRN',
            ajax: {
                url: church_id ? 'ajax_members_by_church.php' : '',
                dataType: 'json',
                delay: 250,
                data: function(params) {
                    return {q: params.term, church_id: church_id};
                },
                processResults: function (data) {
                    return {results: data};
                },
                cache: true
            },
            minimumInputLength: 2
        });
    } else {
        $('#mother_name_row').show();
        $('#mother_member_row').hide();
        if ($('#mother_member_id').hasClass('select2-hidden-accessible')) {
    $('#mother_member_id').select2('destroy');
}
        $('#mother_contact_member').val('');
        $('#mother_occupation_member').val('');
    }
}
$('#father_is_member, #church_id').on('change', toggleFatherField);
$('#mother_is_member, #church_id').on('change', toggleMotherField);

function toggleFatherField() {
    let church_id = $('#church_id').val();
    var father_val = $('#father_is_member').val();
    if (!father_val) {
        $('#father_member_row').hide();
        $('#father_name_row').hide();
    } else if (father_val==='yes') {
        $('#father_name_row').hide();
        $('#father_member_row').show();
        if (!$('#father_member_id').hasClass('select2-hidden-accessible')) {
            $('#father_member_id').select2({
                placeholder: 'Search Father by name or CRN',
                ajax: {
                    url: church_id ? 'ajax_members_by_church.php' : '',
                    dataType: 'json',
                    delay: 250,
                    data: function(params) {
                        return {q: params.term, church_id: church_id, gender: 'male'};
                    },
                    processResults: function (data) {
                        return {results: data.results};
                    },
                    cache: true
                },
                minimumInputLength: 2
            });
        }
        // Pre-populate value after Select2 is initialized and row is shown
        var fatherMemberId = "<?=isset($record['father_member_id'])?$record['father_member_id']:''?>";
        if (fatherMemberId) {
            $('#father_member_id').val(fatherMemberId).trigger('change');
        }
    } else {
        $('#father_name_row').show();
        $('#father_member_row').hide();
        if ($('#father_member_id').hasClass('select2-hidden-accessible')) {
            $('#father_member_id').select2('destroy');
        }
        $('#father_member_id').val(''); // Always clear value so it is POSTed as empty (insert and update)
        $('#father_contact_member').val('');
        $('#father_occupation_member').val('');
    }
}

function toggleMotherField() {
    let church_id = $('#church_id').val();
    var mother_val = $('#mother_is_member').val();
    if (!mother_val) {
        $('#mother_member_row').hide();
        $('#mother_name_row').hide();
    } else if (mother_val==='yes') {
        $('#mother_name_row').hide();
        $('#mother_member_row').show();
        if (!$('#mother_member_id').hasClass('select2-hidden-accessible')) {
            $('#mother_member_id').select2({
                placeholder: 'Search Mother by name or CRN',
                ajax: {
                    url: church_id ? 'ajax_members_by_church.php' : '',
                    dataType: 'json',
                    delay: 250,
                    data: function(params) {
                        return {q: params.term, church_id: church_id, gender: 'female'};
                    },
                    processResults: function (data) {
                        return {results: data.results};
                    },
                    cache: true
                },
                minimumInputLength: 2
            });
        }
    } else {
        $('#mother_name_row').show();
        $('#mother_member_row').hide();
        if ($('#mother_member_id').hasClass('select2-hidden-accessible')) {
            $('#mother_member_id').select2('destroy');
        }
        $('#mother_contact_member').val('');
        $('#mother_occupation_member').val('');
    }
}
// Initial call
$(document).ready(function(){
    toggleFatherField();
    toggleMotherField();
});

function hideAllParentFields() {
    $('#father_member_row').hide();
    $('#father_name_row').hide();
    $('#mother_member_row').hide();
    $('#mother_name_row').hide();
}

function toggleParentFields() {
    let church_id = $('#church_id').val();
    // Father
    var father_val = $('#father_is_member').val();
    if (!father_val) {
        $('#father_member_row').hide();
        $('#father_name_row').hide();
    } else if (father_val==='yes') {
        $('#father_name_row').hide();
        $('#father_member_row').show();
        $('#father_member_id').val(null).trigger('change');
        $('#father_contact_member').val('');
        $('#father_occupation_member').val('');
        $('#father_member_id').select2({
            placeholder: 'Search Father by name or CRN',
            ajax: {
                url: church_id ? 'ajax_members_by_church.php' : '',
                dataType: 'json',
                delay: 250,
                data: function(params) {
                    return {q: params.term, church_id: church_id, gender: 'male'};
                },
                processResults: function (data) {
                    return {results: data.results};
                },
                cache: true
            },
            minimumInputLength: 2
        });
    } else {
        $('#father_name_row').show();
        $('#father_member_row').hide();
        $('#father_member_id').select2('destroy');
        $('#father_contact_member').val('');
        $('#father_occupation_member').val('');
    }
    // Mother
    var mother_val = $('#mother_is_member').val();
    if (!mother_val) {
        $('#mother_member_row').hide();
        $('#mother_name_row').hide();
    } else if (mother_val==='yes') {
        $('#mother_name_row').hide();
        $('#mother_member_row').show();
        $('#mother_member_id').val(null).trigger('change');
        $('#mother_contact_member').val('');
        $('#mother_occupation_member').val('');
        $('#mother_member_id').select2({
            placeholder: 'Search Mother by name or CRN',
            ajax: {
                url: church_id ? 'ajax_members_by_church.php' : '',
                dataType: 'json',
                delay: 250,
                data: function(params) {
                    return {q: params.term, church_id: church_id, gender: 'female'};
                },
                processResults: function (data) {
                    return {results: data.results};
                },
                cache: true
            },
            minimumInputLength: 2
        });
    } else {
        $('#mother_name_row').show();
        $('#mother_member_row').hide();
        $('#mother_member_id').select2('destroy');
        $('#mother_contact_member').val('');
        $('#mother_occupation_member').val('');
    }
}

$(document).ready(function(){
    hideAllParentFields();
    toggleParentFields();
    // Pre-select father/mother member if editing
    var fatherMemberId = "<?=isset($record['father_member_id'])?$record['father_member_id']:''?>";
    if(fatherMemberId){
        $('#father_member_id').val(fatherMemberId).trigger('change');
    }
    var motherMemberId = "<?=isset($record['mother_member_id'])?$record['mother_member_id']:''?>";
    if(motherMemberId){
        $('#mother_member_id').val(motherMemberId).trigger('change');
    }
    // Pre-select is_member options
    var fatherIsMember = "<?=isset($record['father_is_member'])?$record['father_is_member']:''?>";
    if(fatherIsMember){
        $('#father_is_member').val(fatherIsMember);
    }
    var motherIsMember = "<?=isset($record['mother_is_member'])?$record['mother_is_member']:''?>";
    if(motherIsMember){
        $('#mother_is_member').val(motherIsMember);
    }
});

$('#father_member_id').on('select2:select', function(e) {
    var memberId = e.params.data.id;
    if (memberId) {
        $.getJSON('../ajax_member_details.php', {id: memberId}, function(data) {
            $('#father_contact_member').val(data.phone || '');
            $('#father_occupation_member').val(data.profession || '');
        });
    } else {
        $('#father_contact_member').val('');
        $('#father_occupation_member').val('');
    }
});
$('#mother_member_id').on('select2:select', function(e) {
    var memberId = e.params.data.id;
    if (memberId) {
        $.getJSON('../ajax_member_details.php', {id: memberId}, function(data) {
            $('#mother_contact_member').val(data.phone || '');
            $('#mother_occupation_member').val(data.profession || '');
        });
    } else {
        $('#mother_contact_member').val('');
        $('#mother_occupation_member').val('');
    }
});
$('#church_id').on('change', function() {
    // Clear parent selects and details on church change
    $('#father_member_id').val(null).trigger('change');
    $('#father_contact_member').val('');
    $('#father_occupation_member').val('');
    $('#mother_member_id').val(null).trigger('change');
    $('#mother_contact_member').val('');
    $('#mother_occupation_member').val('');
});

$(document).ready(function(){
    toggleParentFields();
});
</script>

        <button class="btn btn-success" type="submit">Save</button>
        <a href="sundayschool_list.php" class="btn btn-secondary">Back</a>
    </form>
</div>
<?php $page_content = ob_get_clean(); include '../includes/layout.php'; ?>