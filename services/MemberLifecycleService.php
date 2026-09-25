<?php

final class MemberLifecycleService {
    private mysqli $conn;
    private ?int $userId;
    private ?int $memberId;
    private ?int $churchId = null;
    private bool $superAdmin;

    private const PROFILE_FIELDS = [
        'first_name', 'middle_name', 'last_name', 'gender', 'dob', 'day_born',
        'place_of_birth', 'address', 'gps_address', 'marital_status',
        'marriage_type', 'spouse_crn', 'spouse_name', 'home_town', 'region',
        'phone', 'telephone', 'email', 'employment_status', 'profession',
        'photo', 'sms_notifications_enabled',
    ];

    public function __construct(
        mysqli $conn,
        ?int $userId,
        ?int $memberId,
        bool $superAdmin = false
    ) {
        $this->conn = $conn;
        $this->userId = $userId && $userId > 0 ? $userId : null;
        $this->memberId = $memberId && $memberId > 0 ? $memberId : null;
        $this->superAdmin = $superAdmin;

        if ($this->userId !== null) {
            $stmt = $this->conn->prepare('SELECT member_id, church_id FROM users WHERE id = ? LIMIT 1');
            $stmt->bind_param('i', $this->userId);
            $stmt->execute();
            $account = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ($account) {
                if ($this->memberId === null) $this->memberId = (int) ($account['member_id'] ?? 0) ?: null;
                $this->churchId = (int) ($account['church_id'] ?? 0) ?: null;
            }
        }

        if ($this->churchId === null && $this->memberId !== null) {
            $stmt = $this->conn->prepare('SELECT church_id FROM members WHERE id = ? LIMIT 1');
            $stmt->bind_param('i', $this->memberId);
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
            isset($_SESSION['member_id']) ? (int) $_SESSION['member_id'] : null,
            !empty($_SESSION['is_super_admin']) || in_array(1, $roles, true)
        );
    }

    public function getPendingRequestForMember(int $memberId): ?array {
        $stmt = $this->conn->prepare(
            "SELECT * FROM member_profile_change_requests
             WHERE member_id = ? AND status = 'pending' LIMIT 1"
        );
        $stmt->bind_param('i', $memberId);
        $stmt->execute();
        $request = $stmt->get_result()->fetch_assoc() ?: null;
        $stmt->close();
        return $request;
    }

    public function getScopedMember(int $memberId, bool $includeArchived = false): ?array {
        $member = $this->getMember($memberId, $includeArchived);
        if (!$member) return null;
        $this->assertChurchAccess((int) $member['church_id']);
        return $member;
    }

    public function requestProfileChange(int $memberId, array $input, array $files = []): int {
        if ($this->memberId !== $memberId) {
            throw new RuntimeException('You may submit changes only for your own profile.');
        }
        $member = $this->getMember($memberId, false);
        if (!$member || (int) ($member['is_archived'] ?? 0) === 1) {
            throw new RuntimeException('This member profile is unavailable.');
        }
        if ($this->getPendingRequestForMember($memberId)) {
            throw new RuntimeException('A profile change is already awaiting review.');
        }

        $payload = $this->normalizeProfilePayload($member, $input);
        $stagedPhoto = $this->stageProfilePhoto($files['photo'] ?? null, (string) ($input['photo_data'] ?? ''));
        if ($stagedPhoto !== null) $payload['photo'] = $stagedPhoto;

        $contacts = [];
        foreach ((array) ($input['emergency_contacts'] ?? []) as $contact) {
            $name = mb_substr(trim((string) ($contact['name'] ?? '')), 0, 100);
            $mobile = mb_substr(trim((string) ($contact['mobile'] ?? '')), 0, 30);
            $relationship = mb_substr(trim((string) ($contact['relationship'] ?? '')), 0, 50);
            if ($name !== '' && $mobile !== '' && $relationship !== '') {
                $contacts[] = compact('name', 'mobile', 'relationship');
            }
        }
        if (!$contacts) throw new RuntimeException('Provide at least one complete emergency contact.');
        $payload['emergency_contacts'] = $contacts;

        $organizations = array_values(array_unique(array_filter(array_map(
            'intval', (array) ($input['organizations'] ?? [])
        ))));
        $payload['organizations'] = $this->validOrganizationIds($organizations, (int) $member['church_id']);

        $snapshot = $this->captureProfileSnapshot($member);

        $originalJson = json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $payloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($originalJson === false || $payloadJson === false) {
            throw new RuntimeException('The profile changes could not be encoded safely.');
        }

        $this->conn->begin_transaction();
        try {
            $stmt = $this->conn->prepare(
                "INSERT INTO member_profile_change_requests
                    (member_id, church_id, pending_member_id, original_snapshot, change_payload,
                     requested_by_member_id, requested_by_user_id)
                 VALUES (?, ?, ?, ?, ?, ?, ?)"
            );
            $churchId = (int) $member['church_id'];
            $actorUser = $this->userId;
            $stmt->bind_param(
                'iiissii',
                $memberId, $churchId, $memberId, $originalJson, $payloadJson, $memberId, $actorUser
            );
            $stmt->execute();
            $requestId = (int) $stmt->insert_id;
            $stmt->close();

            $password = (string) ($input['password'] ?? '');
            if ($password !== '') {
                if (strlen($password) < 8) {
                    throw new RuntimeException('A new password must be at least 8 characters.');
                }
                $hash = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $this->conn->prepare('UPDATE members SET password_hash = ? WHERE id = ?');
                $stmt->bind_param('si', $hash, $memberId);
                $stmt->execute();
                $stmt->close();
            }

            $this->writeAudit($member, 'profile_change_requested',
                'Member submitted profile changes for administrator review.', $requestId);
            $this->conn->commit();
            return $requestId;
        } catch (Throwable $e) {
            $this->conn->rollback();
            if ($stagedPhoto !== null) $this->deleteStagedPhoto($stagedPhoto);
            throw $e;
        }
    }

    public function listProfileRequests(string $status = 'pending'): array {
        if (!in_array($status, ['pending', 'approved', 'rejected', 'cancelled', 'all'], true)) {
            throw new InvalidArgumentException('Choose a valid request status.');
        }
        $where = [];
        $types = '';
        $params = [];
        if ($status !== 'all') {
            $where[] = 'request.status = ?';
            $types .= 's';
            $params[] = $status;
        }
        if (!$this->superAdmin) {
            if ($this->churchId === null) return [];
            $where[] = 'request.church_id = ?';
            $types .= 'i';
            $params[] = $this->churchId;
        }
        $sql = "SELECT request.*, member.crn, member.first_name, member.middle_name,
                       member.last_name, church.name AS church_name,
                       reviewer.name AS reviewer_name
                  FROM member_profile_change_requests request
                  JOIN members member ON member.id = request.member_id
                  JOIN churches church ON church.id = request.church_id
                  LEFT JOIN users reviewer ON reviewer.id = request.reviewed_by_user_id";
        if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
        $sql .= ' ORDER BY request.created_at DESC, request.id DESC';
        $stmt = $this->conn->prepare($sql);
        if ($types !== '') $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return $rows;
    }

    public function reviewProfileRequest(int $requestId, string $decision, string $notes): void {
        if (!in_array($decision, ['approved', 'rejected'], true)) {
            throw new InvalidArgumentException('Choose approve or reject.');
        }
        $notes = mb_substr(trim($notes), 0, 500);
        if ($decision === 'rejected' && $notes === '') {
            throw new RuntimeException('Enter a reason when rejecting a change.');
        }

        $this->conn->begin_transaction();
        try {
            $stmt = $this->conn->prepare(
                "SELECT request.*, member.first_name, member.middle_name, member.last_name,
                        member.crn, member.church_id AS current_church_id, member.is_archived
                   FROM member_profile_change_requests request
                   JOIN members member ON member.id = request.member_id
                  WHERE request.id = ? FOR UPDATE"
            );
            $stmt->bind_param('i', $requestId);
            $stmt->execute();
            $request = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if (!$request || $request['status'] !== 'pending') {
                throw new RuntimeException('This profile request is no longer pending.');
            }
            $this->assertChurchAccess((int) $request['church_id']);
            if ((int) $request['is_archived'] === 1) {
                throw new RuntimeException('An archived member profile cannot be changed.');
            }

            $payload = json_decode((string) $request['change_payload'], true);
            if (!is_array($payload)) throw new RuntimeException('The stored profile change is invalid.');
            $original = json_decode((string) $request['original_snapshot'], true);
            $member = $this->getMember((int) $request['member_id'], true);
            if (!$member || !is_array($original)) {
                throw new RuntimeException('The original profile snapshot is unavailable.');
            }
            if ((int) $member['church_id'] !== (int) $request['church_id']) {
                throw new RuntimeException('The member changed church after submitting this request. Reject it and ask for a new submission.');
            }
            if (json_encode($this->captureProfileSnapshot($member)) !== json_encode($original)) {
                throw new RuntimeException('The official profile changed after this request was submitted. Reject it and ask the member to review the newer profile.');
            }

            if ($decision === 'approved') {
                $this->applyProfilePayload((int) $request['member_id'], (int) $request['church_id'], $payload);
                if (($payload['marital_status'] ?? '') === 'Married' && trim((string) ($payload['spouse_crn'] ?? '')) !== '') {
                    require_once __DIR__ . '/../helpers/spouse_link_helper.php';
                    spouse_link_create_request_by_crn(
                        $this->conn,
                        (int) $request['member_id'],
                        (string) $payload['spouse_crn']
                    );
                }
            } else {
                $this->deleteStagedPhoto((string) ($payload['photo'] ?? ''));
            }

            $stmt = $this->conn->prepare(
                'UPDATE member_profile_change_requests
                    SET status = ?, pending_member_id = NULL, reviewed_by_user_id = ?,
                        reviewed_at = NOW(), review_notes = ?
                  WHERE id = ?'
            );
            $actorUser = $this->userId;
            $stmt->bind_param('sisi', $decision, $actorUser, $notes, $requestId);
            $stmt->execute();
            $stmt->close();

            $auditAction = $decision === 'approved' ? 'profile_change_approved' : 'profile_change_rejected';
            $auditReason = $notes !== '' ? $notes : 'Profile changes approved after review.';
            $this->writeAudit($member, $auditAction, $auditReason, $requestId);
            $this->conn->commit();
        } catch (Throwable $e) {
            $this->conn->rollback();
            throw $e;
        }
    }

    public static function lifecycleReasonOptions(): array {
        return [
            'deceased' => 'Deceased',
            'unknown' => 'Unknown',
            'exited' => 'Exited',
            'duplicate_record' => 'Duplicate Record',
        ];
    }

    public function deactivateMember(int $memberId, string $reasonCode, string $details = ''): void {
        [$reasonCode, $reason] = $this->normalizeLifecycleReason($reasonCode, $details);
        $member = $this->getMember($memberId, true);
        if (!$member || (int) ($member['is_archived'] ?? 0) === 1) {
            throw new RuntimeException('The member is unavailable.');
        }
        $this->assertChurchAccess((int) $member['church_id']);
        if ((string) $member['status'] === 'de-activated') {
            throw new RuntimeException('The member is already deactivated.');
        }
        $this->conn->begin_transaction();
        try {
            $stmt = $this->conn->prepare(
                "UPDATE members SET status = 'de-activated', deactivated_at = NOW(),
                        deactivation_reason = ?, deactivation_reason_code = ?,
                        deactivated_by_user_id = ?
                  WHERE id = ? AND is_archived = 0"
            );
            $actor = $this->userId;
            $stmt->bind_param('ssii', $reason, $reasonCode, $actor, $memberId);
            $stmt->execute();
            $stmt->close();
            $this->writeAudit($member, 'deactivated', $reason, null, $reasonCode);
            $this->conn->commit();
        } catch (Throwable $e) {
            $this->conn->rollback();
            throw $e;
        }
    }

    public function archiveMember(int $memberId, string $reasonCode, string $details = ''): void {
        [$reasonCode, $reason] = $this->normalizeLifecycleReason($reasonCode, $details);
        $member = $this->getMember($memberId, true);
        if (!$member || (int) ($member['is_archived'] ?? 0) === 1) {
            throw new RuntimeException('The member is already archived or unavailable.');
        }
        $this->assertChurchAccess((int) $member['church_id']);
        if (!in_array((string) $member['status'], ['pending', 'de-activated', ''], true)) {
            throw new RuntimeException('Deactivate an active member before archiving the record.');
        }

        $this->conn->begin_transaction();
        try {
            $stmt = $this->conn->prepare('SELECT COUNT(*) AS total FROM deleted_members WHERE id = ?');
            $stmt->bind_param('i', $memberId);
            $stmt->execute();
            $exists = (int) $stmt->get_result()->fetch_assoc()['total'] > 0;
            $stmt->close();
            if (!$exists) $this->copyToLegacyArchive($member);

            $stmt = $this->conn->prepare(
                "UPDATE members SET status = 'de-activated', is_archived = 1,
                        archived_at = NOW(), archive_reason = ?, archive_reason_code = ?,
                        archived_by_user_id = ?
                  WHERE id = ?"
            );
            $actor = $this->userId;
            $stmt->bind_param('ssii', $reason, $reasonCode, $actor, $memberId);
            $stmt->execute();
            $stmt->close();
            $this->writeAudit($member, 'archived', $reason, null, $reasonCode);
            $this->conn->commit();
        } catch (Throwable $e) {
            $this->conn->rollback();
            throw $e;
        }
    }

    public function restoreMember(int $memberId, string $reason): void {
        $reason = $this->requireReason($reason);
        $member = $this->getMember($memberId, true);
        if (!$member || (int) ($member['is_archived'] ?? 0) !== 1) {
            throw new RuntimeException('The archived member was not found.');
        }
        $this->assertChurchAccess((int) $member['church_id']);
        $this->conn->begin_transaction();
        try {
            $stmt = $this->conn->prepare(
                "UPDATE members SET status = 'pending', is_archived = 0,
                        archived_at = NULL, archive_reason = NULL,
                        archive_reason_code = NULL, archived_by_user_id = NULL
                  WHERE id = ?"
            );
            $stmt->bind_param('i', $memberId);
            $stmt->execute();
            $stmt->close();
            $stmt = $this->conn->prepare('DELETE FROM deleted_members WHERE id = ?');
            $stmt->bind_param('i', $memberId);
            $stmt->execute();
            $stmt->close();
            $this->writeAudit($member, 'restored', $reason);
            $this->conn->commit();
        } catch (Throwable $e) {
            $this->conn->rollback();
            throw $e;
        }
    }

    public function listArchivedMembers(): array {
        $where = ['member.is_archived = 1'];
        $types = '';
        $params = [];
        if (!$this->superAdmin) {
            if ($this->churchId === null) return [];
            $where[] = 'member.church_id = ?';
            $types = 'i';
            $params[] = $this->churchId;
        }
        $stmt = $this->conn->prepare(
            "SELECT member.*, class.name AS class_name, church.name AS church_name,
                    actor.name AS archived_by_name
               FROM members member
               LEFT JOIN bible_classes class ON class.id = member.class_id
               LEFT JOIN churches church ON church.id = member.church_id
               LEFT JOIN users actor ON actor.id = member.archived_by_user_id
              WHERE " . implode(' AND ', $where) . '
              ORDER BY member.archived_at DESC, member.last_name, member.first_name'
        );
        if ($types !== '') $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return $rows;
    }

    private function getMember(int $memberId, bool $includeArchived): ?array {
        $sql = 'SELECT * FROM members WHERE id = ?';
        if (!$includeArchived) $sql .= ' AND is_archived = 0';
        $sql .= ' LIMIT 1';
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param('i', $memberId);
        $stmt->execute();
        $member = $stmt->get_result()->fetch_assoc() ?: null;
        $stmt->close();
        return $member;
    }

    private function assertChurchAccess(int $churchId): void {
        if (!$this->superAdmin && ($this->churchId === null || $this->churchId !== $churchId)) {
            throw new RuntimeException('The member is outside your authorized church.');
        }
    }

    private function normalizeProfilePayload(array $member, array $input): array {
        $payload = [];
        foreach (self::PROFILE_FIELDS as $field) {
            if (in_array($field, ['day_born', 'photo'], true)) {
                $payload[$field] = $member[$field] ?? '';
                continue;
            }
            if ($field === 'sms_notifications_enabled') {
                $payload[$field] = isset($input[$field]) ? 1 : 0;
                continue;
            }
            $payload[$field] = trim((string) ($input[$field] ?? ''));
        }
        if ($payload['dob'] !== '') {
            $date = DateTimeImmutable::createFromFormat('Y-m-d', $payload['dob']);
            if (!$date || $date->format('Y-m-d') !== $payload['dob']) {
                throw new RuntimeException('Enter a valid date of birth.');
            }
            $payload['day_born'] = $date->format('l');
        }
        if ($payload['email'] !== '' && !filter_var($payload['email'], FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Enter a valid email address.');
        }
        if (!in_array($payload['gender'], ['Male', 'Female'], true)) {
            throw new RuntimeException('Choose a valid gender.');
        }
        if (!in_array($payload['employment_status'], ['Formal', 'Informal', 'Self Employed', 'Retired', 'Student'], true)) {
            throw new RuntimeException('Choose a valid employment status.');
        }
        if ($payload['marital_status'] !== 'Married') {
            $payload['marriage_type'] = '';
            $payload['spouse_crn'] = '';
            $payload['spouse_name'] = '';
        } elseif (!in_array($payload['marriage_type'], ['Customary', 'Ordinance', 'Blessing', 'Court Registration'], true)) {
            throw new RuntimeException('Choose a valid marriage type.');
        }
        foreach (['first_name', 'last_name', 'dob', 'place_of_birth', 'home_town', 'region', 'phone'] as $required) {
            if ($payload[$required] === '') throw new RuntimeException('Complete all required profile fields.');
        }
        return $payload;
    }

    private function applyProfilePayload(int $memberId, int $churchId, array $payload): void {
        if (str_starts_with((string) ($payload['photo'] ?? ''), 'pending/profile_')) {
            $pendingPath = __DIR__ . '/../uploads/members/' . $payload['photo'];
            $approvedName = 'member_' . bin2hex(random_bytes(16)) . '.' . pathinfo($pendingPath, PATHINFO_EXTENSION);
            $approvedPath = __DIR__ . '/../uploads/members/' . $approvedName;
            if (!is_file($pendingPath) || !rename($pendingPath, $approvedPath)) {
                throw new RuntimeException('The staged profile image is unavailable. Ask the member to resubmit it.');
            }
            $payload['photo'] = $approvedName;
        }
        $fields = [];
        $values = [];
        foreach (self::PROFILE_FIELDS as $field) {
            if (array_key_exists($field, $payload)) {
                $fields[] = $field;
                $values[] = $payload[$field];
            }
        }
        $set = implode(', ', array_map(static fn($field) => $field . ' = ?', $fields));
        $stmt = $this->conn->prepare('UPDATE members SET ' . $set . ' WHERE id = ?');
        $types = str_repeat('s', count($values)) . 'i';
        $values[] = $memberId;
        $stmt->bind_param($types, ...$values);
        $stmt->execute();
        $stmt->close();

        $stmt = $this->conn->prepare('DELETE FROM member_emergency_contacts WHERE member_id = ?');
        $stmt->bind_param('i', $memberId);
        $stmt->execute();
        $stmt->close();
        $stmt = $this->conn->prepare(
            'INSERT INTO member_emergency_contacts (member_id, name, mobile, relationship) VALUES (?, ?, ?, ?)'
        );
        foreach ((array) ($payload['emergency_contacts'] ?? []) as $contact) {
            $stmt->bind_param('isss', $memberId, $contact['name'], $contact['mobile'], $contact['relationship']);
            $stmt->execute();
        }
        $stmt->close();

        $stmt = $this->conn->prepare('DELETE FROM member_organizations WHERE member_id = ?');
        $stmt->bind_param('i', $memberId);
        $stmt->execute();
        $stmt->close();
        $organizationIds = $this->validOrganizationIds(
            array_map('intval', (array) ($payload['organizations'] ?? [])), $churchId
        );
        if ($organizationIds) {
            $stmt = $this->conn->prepare(
                'INSERT INTO member_organizations (member_id, organization_id) VALUES (?, ?)'
            );
            foreach ($organizationIds as $organizationId) {
                $stmt->bind_param('ii', $memberId, $organizationId);
                $stmt->execute();
            }
            $stmt->close();
        }
    }

    private function validOrganizationIds(array $ids, int $churchId): array {
        if (!$ids) return [];
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $types = str_repeat('i', count($ids)) . 'i';
        $params = $ids;
        $params[] = $churchId;
        $stmt = $this->conn->prepare(
            "SELECT id FROM organizations WHERE id IN ($placeholders)
             AND (church_id = ? OR church_id IS NULL)"
        );
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $valid = array_map('intval', array_column($stmt->get_result()->fetch_all(MYSQLI_ASSOC), 'id'));
        $stmt->close();
        sort($valid);
        return $valid;
    }

    private function getEmergencyContacts(int $memberId): array {
        $stmt = $this->conn->prepare(
            'SELECT name, mobile, relationship FROM member_emergency_contacts WHERE member_id = ? ORDER BY id'
        );
        $stmt->bind_param('i', $memberId);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return $rows;
    }

    private function getOrganizationIds(int $memberId): array {
        $stmt = $this->conn->prepare(
            'SELECT organization_id FROM member_organizations WHERE member_id = ? ORDER BY organization_id'
        );
        $stmt->bind_param('i', $memberId);
        $stmt->execute();
        $ids = array_map('intval', array_column($stmt->get_result()->fetch_all(MYSQLI_ASSOC), 'organization_id'));
        $stmt->close();
        return $ids;
    }

    private function captureProfileSnapshot(array $member): array {
        $snapshot = [];
        foreach (self::PROFILE_FIELDS as $field) $snapshot[$field] = $member[$field] ?? null;
        $snapshot['emergency_contacts'] = $this->getEmergencyContacts((int) $member['id']);
        $snapshot['organizations'] = $this->getOrganizationIds((int) $member['id']);
        return $snapshot;
    }

    private function stageProfilePhoto($upload, string $photoData): ?string {
        $binary = null;
        if (is_array($upload) && ($upload['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK
            && is_uploaded_file((string) $upload['tmp_name'])) {
            $binary = file_get_contents((string) $upload['tmp_name']);
        } elseif (str_starts_with($photoData, 'data:image/')) {
            $parts = explode(',', $photoData, 2);
            $binary = count($parts) === 2 ? base64_decode($parts[1], true) : false;
        }
        if ($binary === null) return null;
        if ($binary === false || strlen($binary) > 5 * 1024 * 1024) {
            throw new RuntimeException('Upload a valid profile image no larger than 5 MB.');
        }
        $info = @getimagesizefromstring($binary);
        $extensions = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp'];
        if (!$info || !isset($extensions[$info[2]])) {
            throw new RuntimeException('Profile photos must be JPEG, PNG, or WebP images.');
        }
        $directory = __DIR__ . '/../uploads/members/pending';
        if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
            throw new RuntimeException('The pending profile image directory is unavailable.');
        }
        $relative = 'pending/profile_' . bin2hex(random_bytes(16)) . '.' . $extensions[$info[2]];
        if (file_put_contents(__DIR__ . '/../uploads/members/' . $relative, $binary, LOCK_EX) === false) {
            throw new RuntimeException('The profile image could not be stored for review.');
        }
        return $relative;
    }

    private function deleteStagedPhoto(string $relative): void {
        if (!str_starts_with($relative, 'pending/profile_')) return;
        $path = __DIR__ . '/../uploads/members/' . $relative;
        if (is_file($path)) @unlink($path);
    }

    private function copyToLegacyArchive(array $member): void {
        $columns = [];
        $result = $this->conn->query('SHOW COLUMNS FROM deleted_members');
        while ($column = $result->fetch_assoc()) $columns[$column['Field']] = true;
        $copy = array_intersect_key($member, $columns);
        unset($copy['deleted_at']);
        if (array_key_exists('password_hash', $copy)) $copy['password_hash'] = '';
        if (array_key_exists('registration_token', $copy)) $copy['registration_token'] = null;
        $copy['deleted_at'] = date('Y-m-d H:i:s');
        $names = array_keys($copy);
        $sql = 'INSERT INTO deleted_members (`' . implode('`,`', $names) . '`)' .
               ' VALUES (' . implode(',', array_fill(0, count($names), '?')) . ')';
        $stmt = $this->conn->prepare($sql);
        $values = array_values($copy);
        $types = str_repeat('s', count($values));
        $stmt->bind_param($types, ...$values);
        $stmt->execute();
        $stmt->close();
    }

    private function requireReason(string $reason): string {
        $reason = mb_substr(trim($reason), 0, 500);
        if ($reason === '') throw new RuntimeException('Enter a reason for this action.');
        return $reason;
    }

    private function normalizeLifecycleReason(string $reasonCode, string $details): array {
        $reasonCode = trim($reasonCode);
        $options = self::lifecycleReasonOptions();
        if (!isset($options[$reasonCode])) {
            throw new RuntimeException('Select a valid lifecycle reason.');
        }
        $details = mb_substr(trim($details), 0, 430);
        $reason = $options[$reasonCode];
        if ($details !== '') {
            $reason .= ' — ' . $details;
        }
        return [$reasonCode, mb_substr($reason, 0, 500)];
    }

    private function writeAudit(
        array $member,
        string $action,
        string $reason,
        ?int $requestId = null,
        ?string $reasonCode = null
    ): void {
        $memberId = (int) $member['id'];
        $churchId = (int) ($member['church_id'] ?? 0) ?: null;
        $crn = (string) ($member['crn'] ?? '');
        $name = trim(implode(' ', array_filter([
            $member['first_name'] ?? '', $member['middle_name'] ?? '', $member['last_name'] ?? ''
        ], static fn($part) => trim((string) $part) !== '')));
        if ($name === '') $name = 'Member #' . $memberId;
        $actorUser = $this->userId;
        $actorMember = $this->memberId;
        $stmt = $this->conn->prepare(
            'INSERT INTO member_lifecycle_audit
                (member_id, original_member_id, church_id, member_crn, member_name, action,
                 reason, reason_code, profile_change_request_id,
                 performed_by_user_id, performed_by_member_id)
              VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->bind_param(
            'iiisssssiii',
            $memberId, $memberId, $churchId, $crn, $name, $action, $reason,
            $reasonCode, $requestId, $actorUser, $actorMember
        );
        $stmt->execute();
        $stmt->close();
    }
}
