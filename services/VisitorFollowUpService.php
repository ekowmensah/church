<?php

require_once __DIR__ . '/UnifiedAttendanceReportService.php';

final class VisitorFollowUpService {
    private mysqli $conn;
    private array $allowedChurchIds;

    public function __construct(mysqli $conn, array $allowedChurchIds) {
        $this->conn = $conn;
        $ids = array_filter(array_map('intval', $allowedChurchIds), static fn(int $id): bool => $id > 0);
        $this->allowedChurchIds = array_values(array_unique($ids));
    }

    public static function fromSession(mysqli $conn): self {
        $scope = UnifiedAttendanceReportService::fromSession($conn);
        $churches = $scope->getAllowedChurches();
        return new self($conn, array_column($churches, 'id'));
    }

    public static function statusLabels(): array {
        return [
            'not_started' => 'Not Started',
            'contacted' => 'Contacted',
            'in_progress' => 'In Progress',
            'completed' => 'Completed',
            'unreachable' => 'Unreachable',
            'declined' => 'Declined',
        ];
    }

    public static function contactMethodLabels(): array {
        return [
            'none' => 'No Contact',
            'phone' => 'Phone Call',
            'sms' => 'SMS',
            'email' => 'Email',
            'whatsapp' => 'WhatsApp',
            'in_person' => 'In Person',
            'other' => 'Other',
        ];
    }

    public function getAllowedChurchIds(): array {
        return $this->allowedChurchIds;
    }

    public function getVisitor(int $visitorId): ?array {
        if ($visitorId <= 0 || !$this->allowedChurchIds) return null;
        $placeholders = implode(',', array_fill(0, count($this->allowedChurchIds), '?'));
        $types = 'i' . str_repeat('i', count($this->allowedChurchIds));
        $params = array_merge([$visitorId], $this->allowedChurchIds);
        $stmt = $this->conn->prepare(
            "SELECT visitor.*, church.name AS church_name,
                    assignee.name AS assigned_to_name,
                    converted.crn AS converted_crn
               FROM visitors visitor
               LEFT JOIN churches church ON church.id = visitor.church_id
               LEFT JOIN users assignee ON assignee.id = visitor.follow_up_assigned_to_user_id
               LEFT JOIN members converted ON converted.id = visitor.converted_to_member_id
              WHERE visitor.id = ? AND visitor.is_duplicate_archived = 0
                AND visitor.church_id IN ($placeholders)
              LIMIT 1"
        );
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc() ?: null;
        $stmt->close();
        return $row;
    }

    public function getAssignableUsers(int $churchId): array {
        if (!in_array($churchId, $this->allowedChurchIds, true)) return [];
        $stmt = $this->conn->prepare(
            "SELECT id, name, email
               FROM users
              WHERE church_id = ? AND status = 'active'
              ORDER BY name, id"
        );
        $stmt->bind_param('i', $churchId);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return $rows;
    }

    public function getHistory(int $visitorId): array {
        $visitor = $this->getVisitor($visitorId);
        if (!$visitor) return [];
        $stmt = $this->conn->prepare(
            "SELECT history.*, recorder.name AS recorded_by_name,
                    assignee.name AS assigned_to_name
               FROM visitor_follow_up_history history
               LEFT JOIN users recorder ON recorder.id = history.recorded_by_user_id
               LEFT JOIN users assignee ON assignee.id = history.assigned_to_user_id
              WHERE history.visitor_id = ? AND history.church_id = ?
              ORDER BY history.created_at DESC, history.id DESC"
        );
        $churchId = (int) $visitor['church_id'];
        $stmt->bind_param('ii', $visitorId, $churchId);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return $rows;
    }

    public function recordFollowUp(
        int $visitorId,
        string $status,
        string $contactMethod,
        string $notes,
        ?string $nextFollowUpDate,
        ?int $assignedToUserId,
        ?int $recordedByUserId
    ): void {
        if (!isset(self::statusLabels()[$status])) {
            throw new InvalidArgumentException('Choose a valid follow-up status.');
        }
        if (!isset(self::contactMethodLabels()[$contactMethod])) {
            throw new InvalidArgumentException('Choose a valid contact method.');
        }
        $notes = trim($notes);
        if ($notes === '' || mb_strlen($notes) > 1000) {
            throw new InvalidArgumentException('Enter follow-up notes of no more than 1,000 characters.');
        }
        $nextFollowUpDate = $this->normalizeDate($nextFollowUpDate);
        if (in_array($status, ['completed', 'declined'], true)) {
            $nextFollowUpDate = null;
        }

        $visitor = $this->getVisitor($visitorId);
        if (!$visitor) throw new RuntimeException('Visitor not found in your authorized church scope.');
        $churchId = (int) $visitor['church_id'];
        if ($assignedToUserId !== null && !$this->userBelongsToChurch($assignedToUserId, $churchId)) {
            throw new RuntimeException('The assigned user must be an active user in the visitor\'s church.');
        }

        $previousStatus = (string) ($visitor['follow_up_status'] ?? 'not_started');
        $summary = mb_substr($notes, 0, 500);
        $this->conn->begin_transaction();
        try {
            $update = $this->conn->prepare(
                "UPDATE visitors
                    SET follow_up_status = ?, follow_up_assigned_to_user_id = ?,
                        next_follow_up_date = ?, last_follow_up_at = NOW(),
                        follow_up_summary = ?
                  WHERE id = ? AND church_id = ?"
            );
            $update->bind_param(
                'sissii',
                $status,
                $assignedToUserId,
                $nextFollowUpDate,
                $summary,
                $visitorId,
                $churchId
            );
            $update->execute();
            if ($update->affected_rows < 0) throw new RuntimeException('Unable to update visitor follow-up.');
            $update->close();

            $insert = $this->conn->prepare(
                "INSERT INTO visitor_follow_up_history
                    (visitor_id, church_id, previous_status, follow_up_status,
                     contact_method, notes, next_follow_up_date,
                     assigned_to_user_id, recorded_by_user_id)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
            );
            $insert->bind_param(
                'iisssssii',
                $visitorId,
                $churchId,
                $previousStatus,
                $status,
                $contactMethod,
                $notes,
                $nextFollowUpDate,
                $assignedToUserId,
                $recordedByUserId
            );
            $insert->execute();
            $insert->close();
            $this->conn->commit();
        } catch (Throwable $exception) {
            $this->conn->rollback();
            throw $exception;
        }
    }

    private function normalizeDate(?string $date): ?string {
        $date = trim((string) $date);
        if ($date === '') return null;
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if (!$parsed || $parsed->format('Y-m-d') !== $date || $date < '1900-01-01') {
            throw new InvalidArgumentException('Enter a valid next follow-up date.');
        }
        return $date;
    }

    private function userBelongsToChurch(int $userId, int $churchId): bool {
        $stmt = $this->conn->prepare(
            "SELECT 1 FROM users WHERE id = ? AND church_id = ? AND status = 'active' LIMIT 1"
        );
        $stmt->bind_param('ii', $userId, $churchId);
        $stmt->execute();
        $exists = (bool) $stmt->get_result()->fetch_row();
        $stmt->close();
        return $exists;
    }
}
