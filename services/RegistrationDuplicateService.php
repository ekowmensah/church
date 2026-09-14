<?php

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
        $roles = array_map('intval', (array) ($_SESSION['role_ids'] ?? []));
        if (isset($_SESSION['role_id'])) $roles[] = (int) $_SESSION['role_id'];
        return new self(
            $conn,
            isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null,
            !empty($_SESSION['is_super_admin']) || in_array(1, $roles, true)
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
        $sql = 'SELECT review.*, church.name AS church_name, reviewer.name AS reviewer_name
                  FROM registration_duplicate_reviews review
                  LEFT JOIN churches church ON church.id = review.church_id
                  LEFT JOIN users reviewer ON reviewer.id = review.reviewed_by_user_id';
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

    public function getVisitor(int $visitorId): ?array {
        $stmt = $this->conn->prepare('SELECT * FROM visitors WHERE id = ? LIMIT 1');
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
                        converted_at = NOW(), converted_by_user_id = ?, conversion_notes = ?
                  WHERE id = ? AND conversion_status = 'visitor'"
            );
            $actor = $this->userId;
            $stmt->bind_param('iisi', $memberId, $actor, $conversionNotes, $visitorId);
            $stmt->execute();
            if ($stmt->affected_rows !== 1) throw new RuntimeException('The visitor conversion state changed. Try again.');
            $stmt->close();

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
               FROM members WHERE church_id = ?"
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
               FROM sunday_school WHERE church_id = ?'
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
               FROM visitors WHERE church_id = ?'
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
