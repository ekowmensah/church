<?php
// Usage: include this at the top of health_form.php to prefill by member_id or crn
$prefill_crn = '';
$prefill_member_id = 0;
if (isset($_GET['member_id']) && intval($_GET['member_id'])) {
    $prefill_member_id = intval($_GET['member_id']);
    $stmt = $conn->prepare("SELECT crn FROM members WHERE id = ? LIMIT 1");
    $stmt->bind_param('i', $prefill_member_id);
    $stmt->execute();
    $stmt->bind_result($prefill_crn);
    $stmt->fetch();
    $stmt->close();
}
if (isset($_GET['crn']) && $_GET['crn']) {
    $prefill_crn = $_GET['crn'];
}
