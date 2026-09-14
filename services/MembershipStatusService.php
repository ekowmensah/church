<?php

final class MembershipStatusService {
    public const STATUSES = [
        'Full Member', 'Catechumen', 'Adherent',
        'Junior Member', 'Distant Member', 'Invalid',
    ];

    private mysqli $conn;
    private ?int $userId;
    private ?int $churchId = null;
    private bool $superAdmin;

    public function __construct(mysqli $conn, ?int $userId, bool $superAdmin = false) {
        $this->conn = $conn;
        $this->userId = $userId && $userId > 0 ? $userId : null;
        $this->superAdmin = $superAdmin;
        if ($this->userId !== null) {
            $stmt = $conn->prepare('SELECT church_id FROM users WHERE id = ? LIMIT 1');
            $stmt->bind_param('i', $this->userId);
            $stmt->execute();
            $this->churchId = (int) ($stmt->get_result()->fetch_assoc()['church_id'] ?? 0) ?: null;
            $stmt->close();
        }
    }

    public static function fromSession(mysqli $conn): self {
        $roleIds = array_map('intval', (array) ($_SESSION['role_ids'] ?? []));
        if (isset($_SESSION['role_id'])) $roleIds[] = (int) $_SESSION['role_id'];
        return new self(
            $conn,
            isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null,
            !empty($_SESSION['is_super_admin']) || in_array(1, $roleIds, true)
        );
    }

    public function listMembers(string $status = '', string $search = '', bool $issuesOnly = false): array {
        if ($status !== '' && !in_array($status, self::STATUSES, true) && $status !== 'unclassified') {
            throw new InvalidArgumentException('Choose a valid membership status filter.');
        }
        $where = ['member.is_archived = 0'];
        $types = '';
        $params = [];
        if (!$this->superAdmin) {
            if ($this->churchId === null) return [];
            $where[] = 'member.church_id = ?';
            $types .= 'i';
            $params[] = $this->churchId;
        }
        if ($status === 'unclassified') {
            $where[] = 'member.membership_status IS NULL';
        } elseif ($status !== '') {
            $where[] = 'member.membership_status = ?';
            $types .= 's';
            $params[] = $status;
        }
        $search = mb_substr(trim($search), 0, 100);
        if ($search !== '') {
            $where[] = "(member.crn LIKE ? OR CONCAT_WS(' ', member.first_name, member.middle_name, member.last_name) LIKE ? OR member.phone LIKE ?)";
            $needle = '%' . $search . '%';
            $types .= 'sss';
            array_push($params, $needle, $needle, $needle);
        }
        if ($issuesOnly) {
            $where[] = 'EXISTS (SELECT 1 FROM membership_status_integrity_review open_review WHERE open_review.member_id = member.id AND open_review.resolved = 0)';
        }
        $sql = "SELECT member.id, member.crn, member.first_name, member.middle_name,
                       member.last_name, member.phone, member.membership_status,
                       member.baptized, member.confirmed, member.status,
                       church.name AS church_name, bible_class.name AS class_name,
                       GROUP_CONCAT(DISTINCT IF(review.resolved = 0, review.issue_type, NULL)
                                    ORDER BY review.issue_type SEPARATOR ',') AS review_issues
                  FROM members member
                  LEFT JOIN churches church ON church.id = member.church_id
                  LEFT JOIN bible_classes bible_class ON bible_class.id = member.class_id
                  LEFT JOIN membership_status_integrity_review review ON review.member_id = member.id
                 WHERE " . implode(' AND ', $where) . "
                 GROUP BY member.id
                 ORDER BY MIN(CASE WHEN review.resolved = 0 THEN 0 ELSE 1 END),
                          member.last_name, member.first_name
                 LIMIT 500";
        $stmt = $this->conn->prepare($sql);
        if ($types !== '') $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return $rows;
    }

    public function updateStatus(int $memberId, string $newStatus, string $reason): void {
        if (!in_array($newStatus, self::STATUSES, true)) {
            throw new InvalidArgumentException('Choose one of the six approved membership statuses.');
        }
        $reason = mb_substr(trim($reason), 0, 500);
        if ($reason === '') throw new RuntimeException('Enter a reason for the membership-status decision.');

        $stmt = $this->conn->prepare(
            'SELECT id, church_id, membership_status, baptized, confirmed
               FROM members WHERE id = ? AND is_archived = 0 LIMIT 1'
        );
        $stmt->bind_param('i', $memberId);
        $stmt->execute();
        $member = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$member) throw new RuntimeException('The member was not found.');
        $this->assertChurchAccess((int) $member['church_id']);

        $baptized = strtolower(trim((string) $member['baptized'])) === 'yes';
        $confirmed = strtolower(trim((string) $member['confirmed'])) === 'yes';
        if ($newStatus === 'Full Member' && (!$baptized || !$confirmed)) {
            throw new RuntimeException('Full Member requires recorded baptism and confirmation. Update the sacramental record first.');
        }
        if ($newStatus === 'Catechumen' && (!$baptized || $confirmed)) {
            throw new RuntimeException('Catechumen requires baptism without confirmation. Update the sacramental record first.');
        }

        $oldStatus = trim((string) ($member['membership_status'] ?? '')) ?: null;
        $this->conn->begin_transaction();
        try {
            $stmt = $this->conn->prepare('UPDATE members SET membership_status = ? WHERE id = ?');
            $stmt->bind_param('si', $newStatus, $memberId);
            $stmt->execute();
            $stmt->close();

            $stmt = $this->conn->prepare(
                'INSERT INTO member_membership_status_history
                    (member_id, old_status, new_status, reason, changed_by_user_id)
                 VALUES (?, ?, ?, ?, ?)'
            );
            $actor = $this->userId;
            $stmt->bind_param('isssi', $memberId, $oldStatus, $newStatus, $reason, $actor);
            $stmt->execute();
            $stmt->close();

            $stmt = $this->conn->prepare(
                'UPDATE membership_status_integrity_review
                    SET resolved = 1, resolved_by_user_id = ?, resolved_at = NOW()
                  WHERE member_id = ? AND resolved = 0'
            );
            $stmt->bind_param('ii', $actor, $memberId);
            $stmt->execute();
            $stmt->close();
            $this->conn->commit();
        } catch (Throwable $e) {
            $this->conn->rollback();
            throw $e;
        }
    }

    private function assertChurchAccess(int $churchId): void {
        if (!$this->superAdmin && ($this->churchId === null || $this->churchId !== $churchId)) {
            throw new RuntimeException('The member is outside your authorized church.');
        }
    }
}
