<?php

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../services/AttendanceScopeService.php';

function attendance_smoke_assert(bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
    echo "PASS: {$message}\n";
}

$fixture = $conn->query(
    "SELECT unit.id AS unit_id, unit.organization_id,
            unit_leader.member_id AS group_leader_member_id,
            organization_leader.user_id AS organization_leader_user_id,
            COALESCE(organization_leader.member_id, leader_user.member_id) AS organization_leader_member_id
       FROM organization_unit_leaders unit_leader
       JOIN organization_units unit ON unit.id = unit_leader.unit_id AND unit.is_active = 1
       JOIN organization_leaders organization_leader
         ON organization_leader.organization_id = unit.organization_id
        AND organization_leader.status = 'active'
       LEFT JOIN users leader_user ON leader_user.id = organization_leader.user_id
      WHERE unit_leader.status = 'active'
        AND (unit_leader.effective_to IS NULL OR unit_leader.effective_to >= CURDATE())
        AND EXISTS (SELECT 1 FROM organization_unit_assignments assignment WHERE assignment.unit_id = unit.id)
      ORDER BY unit.id
      LIMIT 1"
)->fetch_assoc();
if (!$fixture) {
    throw new RuntimeException('Smoke test needs one active unit leader, organization leader, and assigned unit member.');
}

$organizationId = (int) $fixture['organization_id'];
$unitId = (int) $fixture['unit_id'];
$organizationLeaderUserId = !empty($fixture['organization_leader_user_id']) ? (int) $fixture['organization_leader_user_id'] : null;
$organizationLeaderMemberId = !empty($fixture['organization_leader_member_id']) ? (int) $fixture['organization_leader_member_id'] : null;
$groupLeaderMemberId = (int) $fixture['group_leader_member_id'];
$sessionId = null;

try {
    $organizationLeader = new AttendanceScopeService($conn, $organizationLeaderUserId, $organizationLeaderMemberId);
    $groupLeader = new AttendanceScopeService($conn, null, $groupLeaderMemberId);
    $outsider = new AttendanceScopeService($conn, null, null);

    $sessionId = $organizationLeader->createOrganizationSession(
        $organizationId,
        $unitId,
        'Automated group attendance smoke test',
        date('Y-m-d')
    );
    $session = $organizationLeader->getSession($sessionId);

    attendance_smoke_assert($organizationLeader->canReview($session), 'organization leader can review its organization');
    attendance_smoke_assert($groupLeader->canMark($session), 'assigned unit leader can mark its unit');
    attendance_smoke_assert(!$groupLeader->canReview($session), 'unit leader cannot approve its own submission');
    attendance_smoke_assert(!$outsider->canView($session), 'unassigned member cannot view the session');

    $members = $groupLeader->getEligibleMembers($session);
    $memberIds = array_map('intval', array_column($members, 'id'));
    $expectedMemberIds = [];
    $expectedResult = $conn->query(
        'SELECT membership.member_id FROM organization_unit_assignments assignment '
        . 'JOIN member_organizations membership ON membership.id = assignment.member_organization_id '
        . 'JOIN members member ON member.id = membership.member_id AND member.status = \'active\' '
        . 'WHERE assignment.unit_id = ' . $unitId . ' ORDER BY membership.member_id'
    );
    while ($row = $expectedResult->fetch_assoc()) {
        $expectedMemberIds[] = (int) $row['member_id'];
    }
    sort($memberIds);
    attendance_smoke_assert($memberIds === $expectedMemberIds, 'unit roster contains only assigned members');

    $statuses = [];
    foreach ($memberIds as $index => $memberId) {
        $statuses[$memberId] = $index % 2 === 0 ? 'present' : 'permission';
    }
    $saved = $groupLeader->submitAttendance($sessionId, $statuses);
    attendance_smoke_assert($saved === count($memberIds), 'group leader submits all scoped member records');

    $submitted = $organizationLeader->getSession($sessionId);
    attendance_smoke_assert($submitted['approval_status'] === 'submitted', 'submission enters organization review');

    $recordResult = $conn->query(
        'SELECT marked_by, marked_by_member_id FROM attendance_records WHERE session_id = ' . (int) $sessionId
    );
    while ($record = $recordResult->fetch_assoc()) {
        attendance_smoke_assert($record['marked_by'] === null, 'member-only leader does not populate user actor');
        attendance_smoke_assert((int) $record['marked_by_member_id'] === $groupLeaderMemberId, 'member actor is recorded separately');
    }

    $organizationLeader->reviewAttendance($sessionId, 'approved', 'Smoke-test approval.');
    $approved = $organizationLeader->getSession($sessionId);
    attendance_smoke_assert($approved['approval_status'] === 'approved', 'organization leader approves submitted attendance');

    $history = $conn->query(
        'SELECT GROUP_CONCAT(action ORDER BY id) AS actions FROM attendance_workflow_history WHERE session_id = ' . (int) $sessionId
    )->fetch_assoc();
    attendance_smoke_assert($history['actions'] === 'created,submitted,approved', 'workflow history records the complete transition trail');

    echo "Organization group attendance smoke test completed successfully.\n";
} finally {
    if ($sessionId !== null) {
        $delete = $conn->prepare('DELETE FROM attendance_sessions WHERE id = ?');
        $delete->bind_param('i', $sessionId);
        $delete->execute();
        $delete->close();
    }
}
