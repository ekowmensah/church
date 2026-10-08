<?php
require_once __DIR__ . '/../helpers/rbac_identity.php';

final class PossibleDuplicateException extends RuntimeException {
    private array $matches;

    public function __construct(array $matches) {
        parent::__construct('Possible duplicate found. Review the matching records before continuing.');
        $this->matches = $matches;
    }

    public function getMatches(): array {
        return $this->matches;
    }
}

final class RegistrationDuplicateService {
    private mysqli $conn;
    private ?int $userId;
    private ?int $churchId = null;
    private bool $superAdmin;

    public function __construct(mysqli $conn, ?int $userId, bool $superAdmin = false) {
        $this->conn = $conn;
        $this->userId = $userId && $userId > 0 ? $userId : null;
        $this->superAdmin = $superAdmin;
        if ($this->userId !== null) {
            $stmt = $this->conn->prepare('SELECT church_id FROM users WHERE id = ? LIMIT 1');
            $stmt->bind_param('i', $this->userId);
            $stmt->execute();
            $this->churchId = (int) ($stmt->get_result()->fetch_assoc()['church_id'] ?? 0) ?: null;
            $stmt->close();
        }
    }

    public static function fromSession(mysqli $conn): self {
        $userId = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
        return new self(
            $conn,
            $userId,
            rbac_identity_is_super_admin($conn, $userId)
        );
    }

    public function findMatches(
        array $candidate,
        string $sourceType,
        ?int $excludeId,
        int $churchId
    ): array {
        $this->assertSourceType($sourceType);
        $this->assertChurchAccess($churchId);
        $needle = [
            'phone' => self::normalizePhone((string) ($candidate['phone'] ?? $candidate['contact'] ?? '')),
            'email' => strtolower(trim((string) ($candidate['email'] ?? ''))),
            'name' => self::normalizeName((string) ($candidate['name'] ?? self::fullName($candidate))),
            'dob' => self::normalizeDate((string) ($candidate['dob'] ?? '')),
        ];
        if ($needle['phone'] === '' && $needle['email'] === ''
            && ($needle['name'] === '' || $needle['dob'] === null)) {
            return [];
        }

        $matches = [];
        foreach ($this->loadChurchIdentities($churchId) as $identity) {
            if ($identity['source_type'] === $sourceType && (int) $identity['source_id'] === (int) $excludeId) {
                continue;
            }
            if ($this->isKnownTransferPair($sourceType, $excludeId, $identity)) continue;

            $rules = [];
            if ($needle['phone'] !== '' && $needle['phone'] === self::normalizePhone((string) $identity['phone'])) {
                $rules[] = 'phone';
            }
            if ($needle['email'] !== '' && $needle['email'] === strtolower(trim((string) $identity['email']))) {
                $rules[] = 'email';
            }
            if ($needle['name'] !== '' && $needle['dob'] !== null
                && $needle['name'] === self::normalizeName((string) $identity['display_name'])
                && $needle['dob'] === self::normalizeDate((string) $identity['dob'])) {
                $rules[] = 'name_dob';
            }
            if (!$rules) continue;
            $identity['match_rules'] = $rules;
            $matches[] = $identity;
        }

        usort($matches, static function (array $a, array $b): int {
            $scoreA = count($a['match_rules']);
            $scoreB = count($b['match_rules']);
            return $scoreA === $scoreB
                ? strcmp($a['display_name'], $b['display_name'])
                : $scoreB <=> $scoreA;
        });
        return $matches;
    }

    public function recordMatches(
        string $sourceType,
        int $sourceId,
        int $churchId,
        array $matches,
        string $context,
        string $continuedReason = ''
    ): void {
        $this->assertSourceType($sourceType);
        $this->assertChurchAccess($churchId);
        if (!in_array($context, ['registration', 'edit', 'conversion', 'manual'], true)) {
            throw new InvalidArgumentException('Choose a valid duplicate detection context.');
        }
        $continuedReason = mb_substr(trim($continuedReason), 0, 500);
        $status = $continuedReason === '' ? 'pending' : 'allowed_duplicate';
        $rank = ['member' => 1, 'sunday_school' => 2, 'visitor' => 3];

        $stmt = $this->conn->prepare(
            "INSERT INTO registration_duplicate_reviews
                (church_id, source_a_type, source_a_id, source_b_type, source_b_id,
                 match_rules, status, detection_context, continued_reason, detected_by_user_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                 match_rules = VALUES(match_rules),
                 detection_context = VALUES(detection_context),
                 continued_reason = COALESCE(NULLIF(VALUES(continued_reason), ''), continued_reason),
                 detected_by_user_id = COALESCE(VALUES(detected_by_user_id), detected_by_user_id),
                 status = IF(status IN ('confirmed_duplicate', 'not_duplicate'), status, VALUES(status))"
        );
        foreach ($matches as $match) {
            $otherType = (string) $match['source_type'];
            $otherId = (int) $match['source_id'];
            $this->assertSourceType($otherType);
            $aType = $sourceType;
            $aId = $sourceId;
            $bType = $otherType;
            $bId = $otherId;
            if ($rank[$aType] > $rank[$bType]
                || ($aType === $bType && $aId > $bId)) {
                [$aType, $bType] = [$bType, $aType];
                [$aId, $bId] = [$bId, $aId];
            }
            if ($aType === $bType && $aId === $bId) continue;
            $rules = implode(',', array_values(array_unique((array) ($match['match_rules'] ?? []))));
            $actor = $this->userId;
            $stmt->bind_param(
                'isisissssi',
                $churchId, $aType, $aId, $bType, $bId, $rules,
                $status, $context, $continuedReason, $actor
            );
            $stmt->execute();
        }
        $stmt->close();
    }

    public function listReviews(string $status = 'pending'): array {
        if (!in_array($status, ['pending', 'confirmed_duplicate', 'not_duplicate', 'allowed_duplicate', 'all'], true)) {
            throw new InvalidArgumentException('Choose a valid duplicate review status.');
        }
        $where = [];
        $types = '';
        $params = [];
        if ($status !== 'all') {
            $where[] = 'review.status = ?';
            $types .= 's';
            $params[] = $status;
        }
        if (!$this->superAdmin) {
            if ($this->churchId === null) return [];
            $where[] = 'review.church_id = ?';
            $types .= 'i';
            $params[] = $this->churchId;
        }
        $sql = 'SELECT review.*, church.name AS church_name, reviewer.name AS reviewer_name,
                       resolution.id AS resolution_id, resolution.survivor_type,
                       resolution.survivor_id, resolution.duplicate_type,
                       resolution.duplicate_id, resolution.resolution_action,
                       resolution.resolution_notes
                  FROM registration_duplicate_reviews review
                  LEFT JOIN churches church ON church.id = review.church_id
                  LEFT JOIN users reviewer ON reviewer.id = review.reviewed_by_user_id
                  LEFT JOIN registration_duplicate_resolutions resolution ON resolution.review_id = review.id';
        if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
        $sql .= ' ORDER BY review.created_at DESC, review.id DESC';
        $stmt = $this->conn->prepare($sql);
        if ($types !== '') $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        foreach ($rows as &$row) {
            $row['source_a'] = $this->getIdentity((string) $row['source_a_type'], (int) $row['source_a_id']);
            $row['source_b'] = $this->getIdentity((string) $row['source_b_type'], (int) $row['source_b_id']);
        }
        unset($row);
        return $rows;
    }

    public function review(int $reviewId, string $decision, string $notes): void {
        if (!in_array($decision, ['confirmed_duplicate', 'not_duplicate', 'allowed_duplicate'], true)) {
            throw new InvalidArgumentException('Choose a valid duplicate decision.');
        }
        $notes = mb_substr(trim($notes), 0, 500);
        if ($notes === '') throw new RuntimeException('Enter review notes for this decision.');
        $stmt = $this->conn->prepare('SELECT church_id FROM registration_duplicate_reviews WHERE id = ? LIMIT 1');
        $stmt->bind_param('i', $reviewId);
        $stmt->execute();
        $review = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$review) throw new RuntimeException('The duplicate review was not found.');
        $this->assertChurchAccess((int) $review['church_id']);
        $stmt = $this->conn->prepare(
            'UPDATE registration_duplicate_reviews
                SET status = ?, reviewed_by_user_id = ?, reviewed_at = NOW(), review_notes = ?
              WHERE id = ?'
        );
        $actor = $this->userId;
        $stmt->bind_param('sisi', $decision, $actor, $notes, $reviewId);
        $stmt->execute();
        $stmt->close();
    }

    public function resolveConfirmedDuplicate(int $reviewId, string $survivorSide, string $notes): void {
        if (!in_array($survivorSide, ['a', 'b'], true)) {
            throw new InvalidArgumentException('Choose which record must survive.');
        }
        $notes = mb_substr(trim($notes), 0, 500);
        if ($notes === '') throw new RuntimeException('Enter resolution notes.');

        $this->conn->begin_transaction();
        try {
            $stmt = $this->conn->prepare(
                'SELECT review.*, resolution.id AS resolution_id
                   FROM registration_duplicate_reviews review
                   LEFT JOIN registration_duplicate_resolutions resolution ON resolution.review_id = review.id
                  WHERE review.id = ? FOR UPDATE'
            );
            $stmt->bind_param('i', $reviewId);
            $stmt->execute();
            $review = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if (!$review || $review['status'] !== 'confirmed_duplicate') {
                throw new RuntimeException('Confirm the duplicate before resolving it.');
            }
            if (!empty($review['resolution_id'])) throw new RuntimeException('This duplicate has already been resolved.');
            $this->assertChurchAccess((int) $review['church_id']);

            $duplicateSide = $survivorSide === 'a' ? 'b' : 'a';
            $survivorType = (string) $review['source_' . $survivorSide . '_type'];
            $survivorId = (int) $review['source_' . $survivorSide . '_id'];
            $duplicateType = (string) $review['source_' . $duplicateSide . '_type'];
            $duplicateId = (int) $review['source_' . $duplicateSide . '_id'];
            $this->assertSourceType($survivorType);
            $this->assertSourceType($duplicateType);
            $survivor = $this->getIdentity($survivorType, $survivorId);
            $duplicate = $this->getIdentity($duplicateType, $duplicateId);
            if (($survivor['display_name'] ?? 'Record no longer available') === 'Record no longer available'
                || ($duplicate['display_name'] ?? 'Record no longer available') === 'Record no longer available') {
                throw new RuntimeException('Both records must still exist before resolution.');
            }

            $action = 'archive_duplicate';
            $actor = $this->userId;
            if ($duplicateType === 'member') {
                $memberStmt = $this->conn->prepare(
                    'SELECT id, church_id, crn, first_name, middle_name, last_name, is_archived
                       FROM members WHERE id = ? FOR UPDATE'
                );
                $memberStmt->bind_param('i', $duplicateId);
                $memberStmt->execute();
                $member = $memberStmt->get_result()->fetch_assoc();
                $memberStmt->close();
                if (!$member || (int) $member['is_archived'] === 1) {
                    throw new RuntimeException('The duplicate member is already archived or unavailable.');
                }
                $reason = 'Duplicate of ' . $survivorType . ' #' . $survivorId . ': ' . $notes;
                $archive = $this->conn->prepare(
                    "UPDATE members SET status = 'de-activated', is_archived = 1,
                            archived_at = NOW(), archive_reason = ?, archive_reason_code = 'duplicate_record',
                            archived_by_user_id = ? WHERE id = ?"
                );
                $archive->bind_param('sii', $reason, $actor, $duplicateId);
                $archive->execute();
                $archive->close();
                $disableAccount = $this->conn->prepare(
                    "UPDATE users SET status = 'inactive' WHERE member_id = ? AND status = 'active'"
                );
                $disableAccount->bind_param('i', $duplicateId);
                $disableAccount->execute();
                $disableAccount->close();
                $name = trim(implode(' ', array_filter([
                    $member['first_name'], $member['middle_name'], $member['last_name']
                ])));
                $audit = $this->conn->prepare(
                    "INSERT INTO member_lifecycle_audit
                        (member_id, original_member_id, church_id, member_crn, member_name,
                         action, reason, reason_code, performed_by_user_id)
                     VALUES (?, ?, ?, ?, ?, 'archived', ?, 'duplicate_record', ?)"
                );
                $memberChurchId = (int) $member['church_id'];
                $memberCrn = (string) $member['crn'];
                $audit->bind_param(
                    'iiisssi', $duplicateId, $duplicateId, $memberChurchId,
                    $memberCrn, $name, $reason, $actor
                );
                $audit->execute();
                $audit->close();
            } elseif ($duplicateType === 'sunday_school') {
                $linkedMember = $survivorType === 'member' ? $survivorId : null;
                $archive = $this->conn->prepare(
                    'UPDATE sunday_school
                        SET is_duplicate_archived = 1, duplicate_archived_at = NOW(),
                            duplicate_archived_by_user_id = ?,
                            transferred_to_member_id = COALESCE(?, transferred_to_member_id)
                      WHERE id = ? AND is_duplicate_archived = 0'
                );
                $archive->bind_param('iii', $actor, $linkedMember, $duplicateId);
                $archive->execute();
                if ($archive->affected_rows !== 1) throw new RuntimeException('The Sunday School duplicate is already archived.');
                $archive->close();
                if ($linkedMember !== null) $action = 'link_to_member';
            } else {
                $linkedMember = $survivorType === 'member' ? $survivorId : null;
                $archive = $this->conn->prepare(
                    "UPDATE visitors
                        SET is_duplicate_archived = 1, duplicate_archived_at = NOW(),
                            duplicate_archived_by_user_id = ?,
                            conversion_status = IF(? IS NULL, conversion_status, 'registered'),
                            converted_to_member_id = COALESCE(?, converted_to_member_id),
                            converted_at = IF(? IS NULL, converted_at, NOW()),
                            converted_by_user_id = COALESCE(?, converted_by_user_id),
                            conversion_notes = CONCAT_WS(' ', conversion_notes, ?)
                      WHERE id = ? AND is_duplicate_archived = 0"
                );
                $resolutionText = 'Duplicate resolved: ' . $notes;
                $archive->bind_param(
                    'iiiiisi', $actor, $linkedMember, $linkedMember,
                    $linkedMember, $actor, $resolutionText, $duplicateId
                );
                $archive->execute();
                if ($archive->affected_rows !== 1) throw new RuntimeException('The visitor duplicate is already archived.');
                $archive->close();
                if ($linkedMember !== null) $action = 'link_to_member';
            }

            $insert = $this->conn->prepare(
                'INSERT INTO registration_duplicate_resolutions
                    (review_id, survivor_type, survivor_id, duplicate_type, duplicate_id,
                     resolution_action, resolution_notes, resolved_by_user_id)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $insert->bind_param(
                'isisissi', $reviewId, $survivorType, $survivorId,
                $duplicateType, $duplicateId, $action, $notes, $actor
            );
            $insert->execute();
            $insert->close();
            $this->conn->commit();
        } catch (Throwable $e) {
            $this->conn->rollback();
            throw $e;
        }
    }

    public function getVisitor(int $visitorId): ?array {
        $stmt = $this->conn->prepare('SELECT * FROM visitors WHERE id = ? AND is_duplicate_archived = 0 LIMIT 1');
        $stmt->bind_param('i', $visitorId);
        $stmt->execute();
        $visitor = $stmt->get_result()->fetch_assoc() ?: null;
        $stmt->close();
        if ($visitor) $this->assertChurchAccess((int) $visitor['church_id']);
        return $visitor;
    }

    public function convertVisitor(array $data, bool $continueDuplicate, string $reason): array {
        $visitorId = (int) ($data['visitor_id'] ?? 0);
        $visitor = $this->getVisitor($visitorId);
        if (!$visitor) throw new RuntimeException('The visitor was not found in your church.');
        if (($visitor['conversion_status'] ?? 'visitor') === 'registered' || !empty($visitor['converted_to_member_id'])) {
            throw new RuntimeException('This visitor is already linked to a registered member.');
        }

        $firstName = mb_substr(trim((string) ($data['first_name'] ?? '')), 0, 100);
        $middleName = mb_substr(trim((string) ($data['middle_name'] ?? '')), 0, 100);
        $lastName = mb_substr(trim((string) ($data['last_name'] ?? '')), 0, 100);
        $crn = mb_substr(trim((string) ($data['crn'] ?? '')), 0, 50);
        $phone = mb_substr(trim((string) ($data['phone'] ?? '')), 0, 20);
        $email = mb_substr(trim((string) ($data['email'] ?? '')), 0, 100);
        $classId = (int) ($data['class_id'] ?? 0);
        $churchId = (int) ($data['church_id'] ?? 0);
        if ($firstName === '' || $lastName === '' || $crn === '' || $phone === '' || $classId <= 0 || $churchId <= 0) {
            throw new RuntimeException('Complete all required member fields.');
        }
        if ($churchId !== (int) $visitor['church_id']) {
            throw new RuntimeException('A visitor can only be converted within the recorded church.');
        }
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Enter a valid email address.');
        }
        $stmt = $this->conn->prepare('SELECT id FROM bible_classes WHERE id = ? AND church_id = ? LIMIT 1');
        $stmt->bind_param('ii', $classId, $churchId);
        $stmt->execute();
        $validClass = (bool) $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$validClass) throw new RuntimeException('Choose a Bible Class belonging to the visitor church.');
        $stmt = $this->conn->prepare('SELECT id FROM members WHERE crn = ? LIMIT 1');
        $stmt->bind_param('s', $crn);
        $stmt->execute();
        $duplicateCrn = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($duplicateCrn) throw new RuntimeException('That CRN is already assigned to a member.');

        $candidate = [
            'first_name' => $firstName, 'middle_name' => $middleName,
            'last_name' => $lastName, 'phone' => $phone, 'email' => $email,
        ];
        $matches = $this->findMatches($candidate, 'visitor', $visitorId, $churchId);
        $reason = mb_substr(trim($reason), 0, 500);
        if ($matches && (!$continueDuplicate || $reason === '')) {
            throw new PossibleDuplicateException($matches);
        }

        require_once __DIR__ . '/../helpers/bible_class_capacity.php';
        $capacity = bible_class_validate_capacity($this->conn, $classId);
        if (!$capacity['allowed']) {
            throw new RuntimeException('Member creation blocked: ' . bible_class_capacity_error_message());
        }

        $token = bin2hex(random_bytes(16));
        $this->conn->begin_transaction();
        try {
            $stmt = $this->conn->prepare(
                "INSERT INTO members
                    (first_name, middle_name, last_name, crn, phone, email, class_id,
                     church_id, registration_token, status, deactivated_at, password_hash)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', '', '')"
            );
            $stmt->bind_param(
                'ssssssiis',
                $firstName, $middleName, $lastName, $crn, $phone, $email,
                $classId, $churchId, $token
            );
            $stmt->execute();
            $memberId = (int) $stmt->insert_id;
            $stmt->close();

            $conversionNotes = $matches
                ? ('Possible duplicate override: ' . $reason)
                : 'Converted from visitor register.';
            $stmt = $this->conn->prepare(
                "UPDATE visitors
                    SET conversion_status = 'registered', converted_to_member_id = ?,
                        converted_at = NOW(), converted_by_user_id = ?, conversion_notes = ?,
                        follow_up_status = 'completed', next_follow_up_date = NULL,
                        last_follow_up_at = NOW(), follow_up_summary = ?
                  WHERE id = ? AND conversion_status = 'visitor'"
            );
            $actor = $this->userId;
            $followUpSummary = 'Completed when the visitor was converted to membership.';
            $stmt->bind_param('iissi', $memberId, $actor, $conversionNotes, $followUpSummary, $visitorId);
            $stmt->execute();
            if ($stmt->affected_rows !== 1) throw new RuntimeException('The visitor conversion state changed. Try again.');
            $stmt->close();

            $previousFollowUpStatus = (string) ($visitor['follow_up_status'] ?? 'not_started');
            $assignedFollowUpUser = isset($visitor['follow_up_assigned_to_user_id'])
                ? (int) $visitor['follow_up_assigned_to_user_id'] : null;
            $history = $this->conn->prepare(
                "INSERT INTO visitor_follow_up_history
                    (visitor_id, church_id, previous_status, follow_up_status,
                     contact_method, notes, assigned_to_user_id, recorded_by_user_id)
                 VALUES (?, ?, ?, 'completed', 'none', ?, ?, ?)"
            );
            $history->bind_param(
                'iissii',
                $visitorId, $churchId, $previousFollowUpStatus,
                $followUpSummary, $assignedFollowUpUser, $actor
            );
            $history->execute();
            $history->close();

            if ($matches) {
                $this->recordMatches('member', $memberId, $churchId, $matches, 'conversion', $reason);
            }
            $this->conn->commit();
            return [
                'member_id' => $memberId,
                'registration_token' => $token,
                'matches' => $matches,
            ];
        } catch (Throwable $e) {
            $this->conn->rollback();
            throw $e;
        }
    }

    public static function normalizePhone(string $phone): string {
        $digits = preg_replace('/\D+/', '', $phone);
        if ($digits === null || strlen($digits) < 9) return '';
        return substr($digits, -9);
    }

    private static function normalizeName(string $name): string {
        $name = mb_strtoupper(trim($name));
        return preg_replace('/[^\p{L}\p{N}]+/u', '', $name) ?? '';
    }

    private static function normalizeDate(string $date): ?string {
        if ($date === '' || $date === '0000-00-00') return null;
        $parsed = DateTimeImmutable::createFromFormat('Y-m-d', $date);
        return $parsed && $parsed->format('Y-m-d') === $date ? $date : null;
    }

    private static function fullName(array $row): string {
        return trim(implode(' ', array_filter([
            $row['first_name'] ?? '', $row['middle_name'] ?? '', $row['last_name'] ?? ''
        ], static fn($part) => trim((string) $part) !== '')));
    }

    private function loadChurchIdentities(int $churchId): array {
        $identities = [];
        $stmt = $this->conn->prepare(
            "SELECT id, crn AS identifier, first_name, middle_name, last_name,
                    dob, phone, email, is_archived
               FROM members WHERE church_id = ? AND is_archived = 0"
        );
        $stmt->bind_param('i', $churchId);
        $stmt->execute();
        foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
            $identities[] = [
                'source_type' => 'member', 'source_id' => (int) $row['id'],
                'identifier' => $row['identifier'], 'display_name' => self::fullName($row),
                'dob' => $row['dob'], 'phone' => $row['phone'], 'email' => $row['email'],
                'status_label' => (int) $row['is_archived'] === 1 ? 'Archived member' : 'Member',
                'transferred_to_member_id' => null,
            ];
        }
        $stmt->close();

        $stmt = $this->conn->prepare(
            'SELECT id, srn AS identifier, first_name, middle_name, last_name,
                    dob, contact AS phone, transferred_to_member_id
               FROM sunday_school WHERE church_id = ? AND is_duplicate_archived = 0'
        );
        $stmt->bind_param('i', $churchId);
        $stmt->execute();
        foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
            $identities[] = [
                'source_type' => 'sunday_school', 'source_id' => (int) $row['id'],
                'identifier' => $row['identifier'], 'display_name' => self::fullName($row),
                'dob' => $row['dob'], 'phone' => $row['phone'], 'email' => '',
                'status_label' => $row['transferred_to_member_id'] ? 'Transferred Sunday School record' : 'Sunday School',
                'transferred_to_member_id' => $row['transferred_to_member_id'],
            ];
        }
        $stmt->close();

        $stmt = $this->conn->prepare(
            'SELECT id, name, phone, email, conversion_status, converted_to_member_id
               FROM visitors WHERE church_id = ? AND is_duplicate_archived = 0'
        );
        $stmt->bind_param('i', $churchId);
        $stmt->execute();
        foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
            $identities[] = [
                'source_type' => 'visitor', 'source_id' => (int) $row['id'],
                'identifier' => 'Visitor #' . $row['id'], 'display_name' => trim((string) $row['name']),
                'dob' => null, 'phone' => $row['phone'], 'email' => $row['email'],
                'status_label' => $row['conversion_status'] === 'registered' ? 'Registered visitor' : 'Visitor',
                'transferred_to_member_id' => $row['converted_to_member_id'],
            ];
        }
        $stmt->close();
        return $identities;
    }

    private function getIdentity(string $type, int $id): array {
        $this->assertSourceType($type);
        if ($type === 'member') {
            $stmt = $this->conn->prepare(
                'SELECT id, crn AS identifier, first_name, middle_name, last_name,
                        phone, email, status, is_archived FROM members WHERE id = ? LIMIT 1'
            );
        } elseif ($type === 'sunday_school') {
            $stmt = $this->conn->prepare(
                "SELECT id, srn AS identifier, first_name, middle_name, last_name,
                        contact AS phone, '' AS email, 'Sunday School' AS status,
                        0 AS is_archived FROM sunday_school WHERE id = ? LIMIT 1"
            );
        } else {
            $stmt = $this->conn->prepare(
                "SELECT id, CONCAT('Visitor #', id) AS identifier, name AS first_name,
                        '' AS middle_name, '' AS last_name, phone, email,
                        conversion_status AS status, converted_to_member_id,
                        0 AS is_archived
                   FROM visitors WHERE id = ? LIMIT 1"
            );
        }
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$row) return ['identifier' => ucfirst(str_replace('_', ' ', $type)) . ' #' . $id,
                           'display_name' => 'Record no longer available', 'phone' => '', 'email' => ''];
        $row['display_name'] = self::fullName($row);
        return $row;
    }

    private function isKnownTransferPair(string $sourceType, ?int $sourceId, array $identity): bool {
        if ($sourceId === null) return false;
        if ($sourceType === 'member' && $identity['source_type'] === 'sunday_school') {
            return (int) ($identity['transferred_to_member_id'] ?? 0) === $sourceId;
        }
        if ($sourceType === 'visitor' && $identity['source_type'] === 'member') {
            $visitor = $this->getIdentity('visitor', $sourceId);
            return (int) ($visitor['converted_to_member_id'] ?? 0) === (int) $identity['source_id'];
        }
        return false;
    }

    private function assertSourceType(string $type): void {
        if (!in_array($type, ['member', 'sunday_school', 'visitor'], true)) {
            throw new InvalidArgumentException('Choose a valid registration source.');
        }
    }

    private function assertChurchAccess(int $churchId): void {
        if ($churchId <= 0) throw new RuntimeException('A valid church is required.');
        if (!$this->superAdmin && ($this->churchId === null || $this->churchId !== $churchId)) {
            throw new RuntimeException('The record is outside your authorized church.');
        }
    }
}
