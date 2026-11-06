<?php

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../helpers/auth.php';
require_once __DIR__ . '/../helpers/permissions_v2.php';
require_once __DIR__ . '/../services/HealthEncounterService.php';

header('Content-Type: application/json; charset=utf-8');

$respond = static function (int $status, bool $success, string $data = '', string $error = ''): never {
    http_response_code($status);
    echo json_encode(
        ['success' => $success, 'data' => $data, 'error' => $error],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
    exit;
};

if (!is_logged_in() || empty($_SESSION['user_id'])) {
    $respond(401, false, '', 'Authentication required.');
}
$memberId = max(0, (int) ($_GET['member_id'] ?? 0));
if ($memberId < 1) {
    $respond(400, false, '', 'Select a valid member.');
}

try {
    $actorUserId = (int) $_SESSION['user_id'];
    $actorChurchId = (int) ($_SESSION['church_id'] ?? 0);
    $superAdmin = is_super_admin();
    if ($actorChurchId < 1 && !$superAdmin) {
        $actorStmt = $conn->prepare("SELECT church_id FROM users WHERE id = ? AND status = 'active' LIMIT 1");
        $actorStmt->bind_param('i', $actorUserId);
        $actorStmt->execute();
        $actorChurchId = (int) ($actorStmt->get_result()->fetch_assoc()['church_id'] ?? 0);
        $actorStmt->close();
    }

    $memberSql = "SELECT id, church_id, crn,
            TRIM(CONCAT_WS(' ', first_name, middle_name, last_name)) AS full_name
        FROM members WHERE id = ?" . ($superAdmin ? '' : ' AND church_id = ?') . ' LIMIT 1';
    $memberStmt = $conn->prepare($memberSql);
    if ($superAdmin) {
        $memberStmt->bind_param('i', $memberId);
    } else {
        $memberStmt->bind_param('ii', $memberId, $actorChurchId);
    }
    $memberStmt->execute();
    $member = $memberStmt->get_result()->fetch_assoc();
    $memberStmt->close();
    if (!$member) {
        $respond(404, false, '', 'Member not found within your authorized church.');
    }

    $memberChurchId = (int) $member['church_id'];
    $service = new HealthEncounterService($conn, $actorUserId, $actorChurchId, $superAdmin);
    if (!$service->authorize('view_health_records', $memberChurchId)) {
        $respond(403, false, '', 'You do not have permission to view these health records.');
    }

    $recordStmt = $conn->prepare(
        "SELECT record.id, record.status, record.encounter_type, record.recorded_at,
                record.follow_up_status, record.follow_up_date, record.notes,
                recorder.name AS recorded_by_name
           FROM health_records record
           LEFT JOIN users recorder ON recorder.id = record.recorded_by
          WHERE record.member_id = ? AND record.church_id = ?
          ORDER BY record.recorded_at DESC, record.id DESC
          LIMIT 50"
    );
    $recordStmt->bind_param('ii', $memberId, $memberChurchId);
    $recordStmt->execute();
    $records = $recordStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $recordStmt->close();

    if (!$records) {
        $empty = '<div class="profile-empty py-5"><i class="fas fa-notes-medical"></i>'
            . '<strong>No health encounters found</strong>'
            . '<span>No health record has been entered for this member.</span></div>';
        $respond(200, true, $empty);
    }

    $recordIds = array_map(static fn(array $record): int => (int) $record['id'], $records);
    $idList = implode(',', $recordIds);
    $observations = [];
    $observationResult = $conn->query(
        "SELECT health_record_id, observation_code, value_numeric, unit_code,
                measurement_context, interpretation
           FROM health_observations
          WHERE health_record_id IN ({$idList})
          ORDER BY health_record_id, id"
    );
    while ($row = $observationResult->fetch_assoc()) {
        $observations[(int) $row['health_record_id']][] = $row;
    }

    $screenings = [];
    $screeningResult = $conn->query(
        "SELECT health_record_id, screening_code, result_code, method
           FROM health_screening_results
          WHERE health_record_id IN ({$idList})
          ORDER BY health_record_id, id"
    );
    while ($row = $screeningResult->fetch_assoc()) {
        $screenings[(int) $row['health_record_id']][] = $row;
    }

    $definitions = HealthEncounterService::observationDefinitions();
    $escape = static fn($value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    $formatValue = static function ($value): string {
        if ($value === null || $value === '') return '-';
        return rtrim(rtrim(number_format((float) $value, 2, '.', ''), '0'), '.');
    };

    ob_start();
    ?>
    <div class="health-modal-heading">
      <div><strong><?= $escape($member['full_name'] ?: 'Member') ?></strong><span><?= $escape($member['crn'] ?: 'No CRN') ?></span></div>
      <span class="health-modal-count"><?= count($records) ?> encounter<?= count($records) === 1 ? '' : 's' ?></span>
    </div>
    <div class="health-modal-list">
      <?php foreach ($records as $record):
          $recordId = (int) $record['id'];
          $recordStatus = strtolower((string) $record['status']);
      ?>
      <article class="health-modal-record<?= $recordStatus === 'voided' ? ' is-voided' : '' ?>">
        <header>
          <div><time><?= $escape(date('d M Y, g:i A', strtotime($record['recorded_at']))) ?></time><span><?= $escape(ucwords(str_replace('_', ' ', (string) $record['encounter_type']))) ?></span></div>
          <span class="health-modal-status is-<?= $escape($recordStatus) ?>"><?= $escape(ucfirst($recordStatus)) ?></span>
        </header>
        <div class="health-modal-measurements">
          <?php if (!empty($observations[$recordId])): ?>
            <?php foreach ($observations[$recordId] as $observation):
                $code = (string) $observation['observation_code'];
                $label = $definitions[$code]['label'] ?? ucwords(str_replace('_', ' ', $code));
            ?>
            <div class="health-modal-measurement<?= $observation['interpretation'] === 'attention' ? ' needs-attention' : '' ?>">
              <small><?= $escape($label) ?></small>
              <strong><?= $escape($formatValue($observation['value_numeric'])) ?> <span><?= $escape($observation['unit_code']) ?></span></strong>
              <?php if (!empty($observation['measurement_context'])): ?><em><?= $escape(ucwords(str_replace('_', ' ', $observation['measurement_context']))) ?></em><?php endif; ?>
            </div>
            <?php endforeach; ?>
          <?php else: ?><span class="text-muted small">No structured measurements recorded.</span><?php endif; ?>
        </div>
        <?php if (!empty($screenings[$recordId])): ?><div class="health-modal-screenings">
          <?php foreach ($screenings[$recordId] as $screening): ?><span><strong><?= $escape(ucwords(str_replace('_', ' ', $screening['screening_code']))) ?>:</strong> <?= $escape(ucfirst(str_replace('_', ' ', $screening['result_code']))) ?></span><?php endforeach; ?>
        </div><?php endif; ?>
        <footer>
          <div><span><i class="fas fa-user-nurse"></i><?= $escape($record['recorded_by_name'] ?: 'Unknown recorder') ?></span><span><i class="fas fa-calendar-check"></i><?= $escape(ucfirst(str_replace('_', ' ', (string) $record['follow_up_status']))) ?><?= $record['follow_up_date'] ? ' - ' . $escape(date('d M Y', strtotime($record['follow_up_date']))) : '' ?></span></div>
          <a class="btn btn-sm btn-outline-info" href="<?= $escape(BASE_URL) ?>/views/health_records.php?id=<?= $recordId ?>">Open encounter <i class="fas fa-arrow-right ml-1"></i></a>
        </footer>
      </article>
      <?php endforeach; ?>
    </div>
    <?php
    $respond(200, true, (string) ob_get_clean());
} catch (Throwable $exception) {
    error_log('Member health modal failed: ' . $exception->getMessage());
    $respond(500, false, '', 'Health records could not be loaded.');
}
