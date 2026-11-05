<?php

require_once __DIR__ . '/../helpers/permissions_v2.php';

final class HealthEncounterService
{
    private mysqli $conn;
    private int $actorUserId;
    private int $actorChurchId;
    private bool $superAdmin;

    private const OBSERVATIONS = [
        'weight' => ['label' => 'Weight', 'unit' => 'kg', 'min' => 0.5, 'max' => 500, 'step' => '0.1'],
        'height' => ['label' => 'Height', 'unit' => 'cm', 'min' => 30, 'max' => 250, 'step' => '0.1'],
        'temperature' => ['label' => 'Temperature', 'unit' => '°C', 'min' => 25, 'max' => 45, 'step' => '0.1'],
        'blood_pressure_systolic' => ['label' => 'Systolic pressure', 'unit' => 'mmHg', 'min' => 40, 'max' => 300, 'step' => '1'],
        'blood_pressure_diastolic' => ['label' => 'Diastolic pressure', 'unit' => 'mmHg', 'min' => 20, 'max' => 200, 'step' => '1'],
        'pulse' => ['label' => 'Pulse', 'unit' => 'beats/min', 'min' => 20, 'max' => 250, 'step' => '1'],
        'respiratory_rate' => ['label' => 'Respiratory rate', 'unit' => 'breaths/min', 'min' => 5, 'max' => 80, 'step' => '1'],
        'oxygen_saturation' => ['label' => 'Oxygen saturation', 'unit' => '%', 'min' => 50, 'max' => 100, 'step' => '1'],
        'blood_glucose' => ['label' => 'Blood glucose', 'unit' => 'mmol/L', 'min' => 0.5, 'max' => 50, 'step' => '0.1'],
    ];

    public function __construct(mysqli $conn, int $actorUserId, int $actorChurchId, bool $superAdmin = false)
    {
        $this->conn = $conn;
        $this->actorUserId = $actorUserId;
        $this->actorChurchId = $actorChurchId;
        $this->superAdmin = $superAdmin;
    }

    public static function observationDefinitions(): array
    {
        return self::OBSERVATIONS;
    }

    public function authorize(string $permission, int $churchId): bool
    {
        if ($this->superAdmin) {
            return true;
        }
        if ($churchId <= 0 || ($this->actorChurchId > 0 && $churchId !== $this->actorChurchId)) {
            return false;
        }
        $decision = authorize_permission($permission, ['church_id' => $churchId], $this->actorUserId);
        return !empty($decision['allowed']);
    }

    public function findPersonByIdentifier(string $identifier, ?int $requestedChurchId = null): ?array
    {
        $identifier = trim($identifier);
        if ($identifier === '') {
            return null;
        }
        $churchId = $this->effectiveChurchId($requestedChurchId);
        if ($churchId <= 0) {
            return null;
        }

        $stmt = $this->conn->prepare(
            "SELECT 'member' AS person_type, member.id AS person_id, member.church_id,
                    member.crn AS identifier,
                    TRIM(CONCAT_WS(' ', member.first_name, member.middle_name, member.last_name)) AS full_name,
                    member.dob, member.gender, member.phone,
                    bible_class.name AS class_name, member.photo
             FROM members member
             LEFT JOIN bible_classes bible_class ON bible_class.id = member.class_id
             WHERE member.church_id = ? AND member.crn = ?
               AND member.is_archived = 0
             LIMIT 1"
        );
        $stmt->bind_param('is', $churchId, $identifier);
        $stmt->execute();
        $person = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($person) {
            return $this->decoratePerson($person);
        }

        $stmt = $this->conn->prepare(
            "SELECT 'sunday_school' AS person_type, child.id AS person_id, child.church_id,
                    child.srn AS identifier,
                    TRIM(CONCAT_WS(' ', child.first_name, child.middle_name, child.last_name)) AS full_name,
                    child.dob, child.gender, child.contact AS phone,
                    bible_class.name AS class_name, child.photo,
                    child.father_name, child.father_contact,
                    child.mother_name, child.mother_contact
             FROM sunday_school child
             LEFT JOIN bible_classes bible_class ON bible_class.id = child.class_id
             WHERE child.church_id = ? AND child.srn = ?
               AND child.is_duplicate_archived = 0
               AND child.transferred_to_member_id IS NULL
             LIMIT 1"
        );
        $stmt->bind_param('is', $churchId, $identifier);
        $stmt->execute();
        $person = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $person ? $this->decoratePerson($person) : null;
    }

    public function getRecord(int $recordId): ?array
    {
        if ($recordId <= 0) {
            return null;
        }
        $sql = "SELECT record.*,
                       CASE WHEN record.member_id IS NOT NULL THEN 'member' ELSE 'sunday_school' END person_type,
                       COALESCE(member.crn, child.srn) identifier,
                       COALESCE(
                           TRIM(CONCAT_WS(' ', member.first_name, member.middle_name, member.last_name)),
                           TRIM(CONCAT_WS(' ', child.first_name, child.middle_name, child.last_name))
                       ) full_name,
                       COALESCE(member.dob, child.dob) dob,
                       COALESCE(member.gender, child.gender) gender,
                       COALESCE(member.phone, child.contact) phone,
                       COALESCE(member.photo, child.photo) photo,
                       recorder.name recorded_by_name,
                       updater.name updated_by_name
                FROM health_records record
                LEFT JOIN members member ON member.id = record.member_id
                LEFT JOIN sunday_school child ON child.id = record.sundayschool_id
                LEFT JOIN users recorder ON recorder.id = record.recorded_by
                LEFT JOIN users updater ON updater.id = record.updated_by_user_id
                WHERE record.id = ?";
        $types = 'i';
        $params = [$recordId];
        if (!$this->superAdmin) {
            $sql .= ' AND record.church_id = ?';
            $types .= 'i';
            $params[] = $this->actorChurchId;
        }
        $sql .= ' LIMIT 1';
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $record = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$record) {
            return null;
        }
        $record['observations'] = $this->loadObservations($recordId, (string) ($record['vitals'] ?? ''));
        $record['screenings'] = $this->loadScreenings($recordId, (string) ($record['vitals'] ?? ''));
        return $record;
    }

    /** @return array{record_id:int,created:bool,person:array} */
    public function save(array $input, int $recordId = 0): array
    {
        $editing = $recordId > 0;
        $existing = $editing ? $this->getRecord($recordId) : null;
        if ($editing && !$existing) {
            throw new RuntimeException('Health encounter was not found in your authorized church.');
        }

        $personType = (string) ($input['person_type'] ?? '');
        $personId = (int) ($input['person_id'] ?? 0);
        $person = $this->findPersonById($personType, $personId);
        if (!$person) {
            throw new InvalidArgumentException('Select a valid member or Sunday School child in your church.');
        }
        $churchId = (int) $person['church_id'];
        $permission = $editing ? 'edit_health_record' : 'create_health_record';
        if (!$this->authorize($permission, $churchId)) {
            throw new RuntimeException('You are not authorized to save this health encounter.');
        }
        if ($editing && (int) $existing['church_id'] !== $churchId) {
            throw new InvalidArgumentException('The person linked to an existing encounter cannot be changed to another church.');
        }

        $observedAt = $this->validDateTime((string) ($input['recorded_at'] ?? ''));
        $encounterType = (string) ($input['encounter_type'] ?? 'screening');
        if (!in_array($encounterType, ['screening', 'follow_up', 'outreach', 'other'], true)) {
            throw new InvalidArgumentException('Select a valid encounter type.');
        }
        $observations = $this->validateObservations((array) ($input['observations'] ?? []), (array) ($input['observation_context'] ?? []));
        $screenings = $this->validateScreenings((array) ($input['screenings'] ?? []), (array) ($input['screening_method'] ?? []));
        if (!$observations && !$screenings) {
            throw new InvalidArgumentException('Enter at least one measurement or screening result.');
        }
        if ((isset($observations['blood_pressure_systolic']) xor isset($observations['blood_pressure_diastolic']))) {
            throw new InvalidArgumentException('Blood pressure requires both systolic and diastolic values.');
        }

        $notes = trim((string) ($input['notes'] ?? ''));
        $followUpStatus = (string) ($input['follow_up_status'] ?? 'none');
        if (!in_array($followUpStatus, ['none', 'recommended', 'scheduled', 'completed', 'cancelled'], true)) {
            throw new InvalidArgumentException('Select a valid follow-up status.');
        }
        $followUpDate = trim((string) ($input['follow_up_date'] ?? ''));
        if ($followUpDate !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $followUpDate)) {
            throw new InvalidArgumentException('Follow-up date is invalid.');
        }
        if ($followUpStatus === 'scheduled' && $followUpDate === '') {
            throw new InvalidArgumentException('A scheduled follow-up requires a date.');
        }
        $referral = mb_substr(trim((string) ($input['referral_destination'] ?? '')), 0, 180);
        $reason = mb_substr(trim((string) ($input['correction_reason'] ?? '')), 0, 500);
        if ($editing && mb_strlen($reason) < 8) {
            throw new InvalidArgumentException('Provide a correction reason of at least 8 characters.');
        }

        $legacyJson = json_encode($this->legacyVitals($observations, $screenings), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($legacyJson === false) {
            throw new RuntimeException('The measurement values could not be encoded.');
        }
        $memberId = $personType === 'member' ? $personId : null;
        $childId = $personType === 'sunday_school' ? $personId : null;

        $this->conn->begin_transaction();
        try {
            if ($editing) {
                $previous = $this->snapshot($existing);
                $stmt = $this->conn->prepare(
                    'UPDATE health_records
                     SET member_id=?, sundayschool_id=?, church_id=?, encounter_type=?,
                         status=CASE WHEN status="final" THEN "amended" ELSE status END,
                         follow_up_status=?, follow_up_date=?, referral_destination=?,
                         vitals=?, notes=?, recorded_at=?, updated_by_user_id=?
                     WHERE id=? AND church_id=? AND status<>"voided"'
                );
                $stmt->bind_param('iiissssssssiii', $memberId, $childId, $churchId,
                    $encounterType, $followUpStatus, $followUpDate, $referral,
                    $legacyJson, $notes, $observedAt, $this->actorUserId, $recordId, $churchId);
                $stmt->execute();
                if ($stmt->affected_rows !== 1) {
                    throw new RuntimeException('Voided encounters cannot be edited.');
                }
                $stmt->close();
                $this->replaceObservations($recordId, $churchId, $observedAt, $observations);
                $this->replaceScreenings($recordId, $churchId, $observedAt, $screenings);
                $fresh = $this->snapshotFromValues($personType, $personId, $encounterType, $observedAt,
                    $notes, $followUpStatus, $followUpDate, $referral, $observations, $screenings);
                $this->insertAudit($recordId, $churchId, 'corrected', $previous, $fresh, $reason);
            } else {
                $stmt = $this->conn->prepare(
                    'INSERT INTO health_records
                     (member_id, sundayschool_id, church_id, encounter_type, status,
                      follow_up_status, follow_up_date, referral_destination, vitals,
                      notes, recorded_at, recorded_by, updated_by_user_id, finalized_at)
                     VALUES (?, ?, ?, ?, "final", ?, NULLIF(?, ""), NULLIF(?, ""), ?, ?, ?, ?, ?, ?)'
                );
                $stmt->bind_param('iiisssssssiis', $memberId, $childId, $churchId,
                    $encounterType, $followUpStatus, $followUpDate, $referral,
                    $legacyJson, $notes, $observedAt, $this->actorUserId,
                    $this->actorUserId, $observedAt);
                $stmt->execute();
                $recordId = (int) $stmt->insert_id;
                $stmt->close();
                $this->replaceObservations($recordId, $churchId, $observedAt, $observations);
                $this->replaceScreenings($recordId, $churchId, $observedAt, $screenings);
                $fresh = $this->snapshotFromValues($personType, $personId, $encounterType, $observedAt,
                    $notes, $followUpStatus, $followUpDate, $referral, $observations, $screenings);
                $this->insertAudit($recordId, $churchId, 'created', null, $fresh, 'Health encounter recorded.');
            }
            $this->conn->commit();
        } catch (Throwable $e) {
            $this->conn->rollback();
            throw $e;
        }

        return ['record_id' => $recordId, 'created' => !$editing, 'person' => $person];
    }

    public function void(int $recordId, string $reason): void
    {
        $record = $this->getRecord($recordId);
        if (!$record || !$this->authorize('void_health_record', (int) $record['church_id'])) {
            throw new RuntimeException('Health encounter was not found or you are not authorized to void it.');
        }
        $reason = mb_substr(trim($reason), 0, 500);
        if (mb_strlen($reason) < 8) {
            throw new InvalidArgumentException('Provide a void reason of at least 8 characters.');
        }
        if ($record['status'] === 'voided') {
            return;
        }
        $this->conn->begin_transaction();
        try {
            $stmt = $this->conn->prepare(
                'UPDATE health_records SET status="voided", void_reason=?,
                 voided_by_user_id=?, voided_at=NOW(), updated_by_user_id=?
                 WHERE id=? AND church_id=? AND status<>"voided"'
            );
            $stmt->bind_param('siiii', $reason, $this->actorUserId, $this->actorUserId,
                $recordId, $record['church_id']);
            $stmt->execute();
            $stmt->close();
            $this->insertAudit($recordId, (int) $record['church_id'], 'voided',
                $this->snapshot($record), ['status' => 'voided', 'reason' => $reason], $reason);
            $this->conn->commit();
        } catch (Throwable $e) {
            $this->conn->rollback();
            throw $e;
        }
    }

    private function effectiveChurchId(?int $requested): int
    {
        if ($this->superAdmin && $requested && $requested > 0) {
            return $requested;
        }
        return $this->actorChurchId;
    }

    private function findPersonById(string $type, int $id): ?array
    {
        if (!in_array($type, ['member', 'sunday_school'], true) || $id <= 0) {
            return null;
        }
        $table = $type === 'member' ? 'members' : 'sunday_school';
        $idColumn = $type === 'member' ? 'crn' : 'srn';
        $phoneColumn = $type === 'member' ? 'phone' : 'contact';
        $archive = $type === 'member'
            ? 'AND is_archived=0'
            : 'AND is_duplicate_archived=0 AND transferred_to_member_id IS NULL';
        $sql = "SELECT '{$type}' person_type, id person_id, church_id,
                       {$idColumn} identifier,
                       TRIM(CONCAT_WS(' ', first_name, middle_name, last_name)) full_name,
                       dob, gender, {$phoneColumn} phone, photo
                FROM {$table} WHERE id=? {$archive}";
        $types = 'i';
        $params = [$id];
        if (!$this->superAdmin) {
            $sql .= ' AND church_id=?';
            $types .= 'i';
            $params[] = $this->actorChurchId;
        }
        $sql .= ' LIMIT 1';
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $person = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $person ? $this->decoratePerson($person) : null;
    }

    private function decoratePerson(array $person): array
    {
        $person['age'] = null;
        if (!empty($person['dob']) && $person['dob'] !== '0000-00-00') {
            try {
                $person['age'] = (new DateTimeImmutable($person['dob']))->diff(new DateTimeImmutable('today'))->y;
            } catch (Throwable $ignored) {
            }
        }
        return $person;
    }

    private function validDateTime(string $value): string
    {
        if ($value === '') {
            throw new InvalidArgumentException('Encounter date and time are required.');
        }
        $date = DateTimeImmutable::createFromFormat('Y-m-d\TH:i', $value)
            ?: DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $value);
        if (!$date) {
            throw new InvalidArgumentException('Encounter date and time are invalid.');
        }
        if ($date > new DateTimeImmutable('+10 minutes')) {
            throw new InvalidArgumentException('Encounter time cannot be in the future.');
        }
        return $date->format('Y-m-d H:i:s');
    }

    private function validateObservations(array $values, array $contexts): array
    {
        $clean = [];
        foreach (self::OBSERVATIONS as $code => $definition) {
            $raw = trim((string) ($values[$code] ?? ''));
            if ($raw === '') {
                continue;
            }
            if (!is_numeric($raw)) {
                throw new InvalidArgumentException($definition['label'] . ' must be numeric.');
            }
            $value = (float) $raw;
            if ($value < $definition['min'] || $value > $definition['max']) {
                throw new InvalidArgumentException(sprintf('%s must be between %s and %s %s.',
                    $definition['label'], $definition['min'], $definition['max'], $definition['unit']));
            }
            $context = mb_substr(trim((string) ($contexts[$code] ?? '')), 0, 60);
            if ($code === 'blood_glucose' && !in_array($context, ['fasting', 'random', 'post_meal'], true)) {
                throw new InvalidArgumentException('Select fasting, random or post-meal context for blood glucose.');
            }
            $clean[$code] = [
                'value' => $value,
                'unit' => $definition['unit'],
                'context' => $context,
                'interpretation' => $this->interpretation($code, $value),
            ];
        }
        if (isset($clean['weight'], $clean['height'])) {
            $heightM = $clean['height']['value'] / 100;
            $clean['bmi'] = [
                'value' => round($clean['weight']['value'] / ($heightM * $heightM), 1),
                'unit' => 'kg/m2', 'context' => '', 'interpretation' => 'unknown',
            ];
        }
        return $clean;
    }

    private function validateScreenings(array $values, array $methods): array
    {
        $clean = [];
        foreach (['malaria', 'hepatitis_b'] as $code) {
            $result = trim((string) ($values[$code] ?? ''));
            if ($result === '') {
                continue;
            }
            if (!in_array($result, ['negative', 'positive', 'indeterminate', 'not_tested'], true)) {
                throw new InvalidArgumentException('Select a valid ' . str_replace('_', ' ', $code) . ' result.');
            }
            $clean[$code] = [
                'result' => $result,
                'method' => mb_substr(trim((string) ($methods[$code] ?? '')), 0, 100),
            ];
        }
        return $clean;
    }

    private function interpretation(string $code, float $value): string
    {
        $attention = [
            'temperature' => $value < 35.0 || $value > 37.5,
            'blood_pressure_systolic' => $value < 90 || $value >= 140,
            'blood_pressure_diastolic' => $value < 60 || $value >= 90,
            'pulse' => $value < 50 || $value > 120,
            'respiratory_rate' => $value < 10 || $value > 24,
            'oxygen_saturation' => $value < 94,
            'blood_glucose' => $value < 3.9 || $value > 11.0,
        ];
        return isset($attention[$code]) ? ($attention[$code] ? 'attention' : 'within_range') : 'unknown';
    }

    private function replaceObservations(int $recordId, int $churchId, string $observedAt, array $observations): void
    {
        $stmt = $this->conn->prepare('DELETE FROM health_observations WHERE health_record_id=?');
        $stmt->bind_param('i', $recordId);
        $stmt->execute();
        $stmt->close();
        $stmt = $this->conn->prepare(
            'INSERT INTO health_observations
             (health_record_id, church_id, observation_code, value_numeric, unit_code,
              measurement_context, source, interpretation, observed_at, recorded_by_user_id)
             VALUES (?, ?, ?, ?, ?, NULLIF(?, ""), "manual", ?, ?, ?)'
        );
        foreach ($observations as $code => $observation) {
            $value = $observation['value'];
            $unit = $observation['unit'];
            $context = $observation['context'];
            $interpretation = $observation['interpretation'];
            $stmt->bind_param('iisdssssi', $recordId, $churchId, $code, $value,
                $unit, $context, $interpretation, $observedAt, $this->actorUserId);
            $stmt->execute();
        }
        $stmt->close();
    }

    private function replaceScreenings(int $recordId, int $churchId, string $observedAt, array $screenings): void
    {
        $stmt = $this->conn->prepare('DELETE FROM health_screening_results WHERE health_record_id=?');
        $stmt->bind_param('i', $recordId);
        $stmt->execute();
        $stmt->close();
        $stmt = $this->conn->prepare(
            'INSERT INTO health_screening_results
             (health_record_id, church_id, screening_code, result_code, method,
              tested_at, recorded_by_user_id)
             VALUES (?, ?, ?, ?, NULLIF(?, ""), ?, ?)'
        );
        foreach ($screenings as $code => $screening) {
            $result = $screening['result'];
            $method = $screening['method'];
            $stmt->bind_param('iissssi', $recordId, $churchId, $code, $result,
                $method, $observedAt, $this->actorUserId);
            $stmt->execute();
        }
        $stmt->close();
    }

    private function loadObservations(int $recordId, string $legacyJson): array
    {
        $stmt = $this->conn->prepare(
            'SELECT observation_code, value_numeric, value_text, unit_code,
                    measurement_context, interpretation
             FROM health_observations WHERE health_record_id=? ORDER BY id'
        );
        $stmt->bind_param('i', $recordId);
        $stmt->execute();
        $result = $stmt->get_result();
        $values = [];
        while ($row = $result->fetch_assoc()) {
            $values[$row['observation_code']] = $row;
        }
        $stmt->close();
        if ($values) {
            return $values;
        }
        $legacy = json_decode($legacyJson, true);
        if (!is_array($legacy)) {
            return [];
        }
        $map = [
            'weight' => 'weight', 'height' => 'height', 'temperature' => 'temperature',
            'bp_systolic' => 'blood_pressure_systolic',
            'bp_diastolic' => 'blood_pressure_diastolic', 'sugar' => 'blood_glucose',
            'pulse' => 'pulse', 'respiratory_rate' => 'respiratory_rate',
            'oxygen_saturation' => 'oxygen_saturation',
        ];
        foreach ($map as $legacyCode => $code) {
            if (isset($legacy[$legacyCode]) && is_numeric($legacy[$legacyCode]) && (float) $legacy[$legacyCode] > 0) {
                $values[$code] = [
                    'observation_code' => $code,
                    'value_numeric' => (float) $legacy[$legacyCode],
                    'unit_code' => self::OBSERVATIONS[$code]['unit'] ?? '',
                    'measurement_context' => null, 'interpretation' => 'unknown',
                ];
            }
        }
        return $values;
    }

    private function loadScreenings(int $recordId, string $legacyJson): array
    {
        $stmt = $this->conn->prepare(
            'SELECT screening_code, result_code, method, result_notes
             FROM health_screening_results WHERE health_record_id=? ORDER BY id'
        );
        $stmt->bind_param('i', $recordId);
        $stmt->execute();
        $result = $stmt->get_result();
        $values = [];
        while ($row = $result->fetch_assoc()) {
            $values[$row['screening_code']] = $row;
        }
        $stmt->close();
        if ($values) {
            return $values;
        }
        $legacy = json_decode($legacyJson, true);
        if (!is_array($legacy)) {
            return [];
        }
        foreach (['malaria', 'hepatitis_b'] as $code) {
            $resultCode = strtolower((string) ($legacy[$code] ?? ''));
            if (in_array($resultCode, ['negative', 'positive', 'indeterminate', 'not_tested'], true)) {
                $values[$code] = ['screening_code' => $code, 'result_code' => $resultCode, 'method' => null];
            }
        }
        return $values;
    }

    private function legacyVitals(array $observations, array $screenings): array
    {
        $legacy = [];
        $reverse = [
            'weight' => 'weight', 'height' => 'height', 'temperature' => 'temperature',
            'blood_pressure_systolic' => 'bp_systolic',
            'blood_pressure_diastolic' => 'bp_diastolic', 'blood_glucose' => 'sugar',
            'pulse' => 'pulse', 'respiratory_rate' => 'respiratory_rate',
            'oxygen_saturation' => 'oxygen_saturation', 'bmi' => 'bmi',
        ];
        foreach ($observations as $code => $value) {
            $legacy[$reverse[$code] ?? $code] = $value['value'];
        }
        if (isset($legacy['bp_systolic'], $legacy['bp_diastolic'])) {
            $legacy['bp'] = $legacy['bp_systolic'] . '/' . $legacy['bp_diastolic'];
        }
        foreach ($screenings as $code => $value) {
            $legacy[$code] = $value['result'];
        }
        return $legacy;
    }

    private function snapshot(array $record): array
    {
        return [
            'person_type' => $record['person_type'] ?? null,
            'member_id' => $record['member_id'] ?? null,
            'sundayschool_id' => $record['sundayschool_id'] ?? null,
            'encounter_type' => $record['encounter_type'] ?? null,
            'status' => $record['status'] ?? null,
            'recorded_at' => $record['recorded_at'] ?? null,
            'notes' => $record['notes'] ?? null,
            'follow_up_status' => $record['follow_up_status'] ?? null,
            'follow_up_date' => $record['follow_up_date'] ?? null,
            'referral_destination' => $record['referral_destination'] ?? null,
            'observations' => $record['observations'] ?? [],
            'screenings' => $record['screenings'] ?? [],
        ];
    }

    private function snapshotFromValues(string $type, int $personId, string $encounterType,
        string $recordedAt, string $notes, string $followStatus, string $followDate,
        string $referral, array $observations, array $screenings): array
    {
        return compact('type', 'personId', 'encounterType', 'recordedAt', 'notes',
            'followStatus', 'followDate', 'referral', 'observations', 'screenings');
    }

    private function insertAudit(int $recordId, int $churchId, string $action,
        ?array $previous, array $next, string $reason): void
    {
        $previousJson = $previous === null ? null : json_encode($previous, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $nextJson = json_encode($next, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $stmt = $this->conn->prepare(
            'INSERT INTO health_record_audit
             (health_record_id, church_id, action, previous_snapshot, new_snapshot,
              reason, changed_by_user_id)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->bind_param('iissssi', $recordId, $churchId, $action,
            $previousJson, $nextJson, $reason, $this->actorUserId);
        $stmt->execute();
        $stmt->close();
    }
}
