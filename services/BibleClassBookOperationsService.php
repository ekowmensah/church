<?php

require_once __DIR__ . '/BibleClassAttendanceScheduleService.php';

final class BibleClassBookOperationsService {
    private mysqli $conn;
    private BibleClassAttendanceScheduleService $scheduleService;
    private ?int $userId;
    private ?int $memberId;

    public function __construct(mysqli $conn, ?int $userId, ?int $memberId) {
        $this->conn = $conn;
        $this->userId = $userId && $userId > 0 ? $userId : null;
        $this->memberId = $memberId && $memberId > 0 ? $memberId : null;
        $this->scheduleService = new BibleClassAttendanceScheduleService($conn, $this->userId, $this->memberId);
    }

    public static function fromSession(mysqli $conn): self {
        $service = new self(
            $conn,
            isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null,
            isset($_SESSION['member_id']) ? (int) $_SESSION['member_id'] : null
        );
        $service->scheduleService = BibleClassAttendanceScheduleService::fromSession($conn);
        return $service;
    }

    public function requestRemoval(int $classId, int $memberId, string $reason): int {
        if (!$this->scheduleService->canAccessClass($classId)) {
            throw new RuntimeException('You cannot request removals for that Bible class.');
        }
        $reason = trim($reason);
        if ($reason === '') {
            throw new RuntimeException('Explain why this member should be removed from the class list.');
        }
        if (mb_strlen($reason) > 500) {
            throw new RuntimeException('The removal reason cannot exceed 500 characters.');
        }

        $memberStmt = $this->conn->prepare(
            'SELECT member.id, member.church_id, member.class_id
               FROM members member WHERE member.id = ? AND member.class_id = ? LIMIT 1'
        );
        $memberStmt->bind_param('ii', $memberId, $classId);
        $memberStmt->execute();
        $member = $memberStmt->get_result()->fetch_assoc();
        $memberStmt->close();
        if (!$member) {
            throw new RuntimeException('That member is not currently assigned to this Bible class.');
        }

        try {
            $stmt = $this->conn->prepare(
                "INSERT INTO bible_class_member_removal_requests
                    (church_id, class_id, member_id, requested_reason, status,
                     requested_by_user_id, requested_by_member_id, pending_member_key)
                 VALUES (?, ?, ?, ?, 'pending', ?, ?, ?)"
            );
            $churchId = (int) $member['church_id'];
            $pendingMemberKey = $classId . ':' . $memberId;
            $stmt->bind_param('iiisiis', $churchId, $classId, $memberId, $reason, $this->userId, $this->memberId, $pendingMemberKey);
            $stmt->execute();
            $requestId = (int) $this->conn->insert_id;
            $stmt->close();
            return $requestId;
        } catch (mysqli_sql_exception $error) {
            if ((int) $error->getCode() === 1062) {
                throw new RuntimeException('A pending removal request already exists for this member.');
            }
            throw $error;
        }
    }

    public function reviewRemoval(int $requestId, int $expectedClassId, string $decision, string $notes, bool $authorized): void {
        if (!$authorized) {
            throw new RuntimeException('You do not have permission to review class-list removals.');
        }
        if (!in_array($decision, ['approved', 'rejected'], true)) {
            throw new RuntimeException('Choose approve or reject.');
        }
        $notes = trim($notes);
        if ($decision === 'rejected' && $notes === '') {
            throw new RuntimeException('Enter a review note when rejecting a request.');
        }
        if (mb_strlen($notes) > 500) {
            throw new RuntimeException('The review note cannot exceed 500 characters.');
        }

        $this->conn->begin_transaction();
        try {
            $stmt = $this->conn->prepare(
                'SELECT * FROM bible_class_member_removal_requests WHERE id = ? FOR UPDATE'
            );
            $stmt->bind_param('i', $requestId);
            $stmt->execute();
            $request = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if (!$request || $request['status'] !== 'pending') {
                throw new RuntimeException('This removal request is no longer pending.');
            }
            if ((int) $request['class_id'] !== $expectedClassId) {
                throw new RuntimeException('That request does not belong to the selected Bible class.');
            }

            if ($decision === 'approved') {
                $remove = $this->conn->prepare(
                    'UPDATE members SET class_id = NULL WHERE id = ? AND class_id = ?'
                );
                $memberId = (int) $request['member_id'];
                $classId = (int) $request['class_id'];
                $remove->bind_param('ii', $memberId, $classId);
                $remove->execute();
                if ($remove->affected_rows !== 1) {
                    $remove->close();
                    throw new RuntimeException('The member is no longer assigned to the recorded class; no change was made.');
                }
                $remove->close();
            }

            $review = $this->conn->prepare(
                'UPDATE bible_class_member_removal_requests
                    SET status = ?, pending_member_key = NULL,
                        reviewed_by_user_id = ?, reviewed_by_member_id = ?,
                        reviewed_at = NOW(), review_notes = ?
                  WHERE id = ?'
            );
            $review->bind_param('siisi', $decision, $this->userId, $this->memberId, $notes, $requestId);
            $review->execute();
            $review->close();
            $this->conn->commit();
        } catch (Throwable $error) {
            $this->conn->rollback();
            throw $error;
        }
    }

    public function listRequests(int $classId, bool $reviewAccess): array {
        if (!$reviewAccess && !$this->scheduleService->canAccessClass($classId)) {
            throw new RuntimeException('You cannot view removal requests for that Bible class.');
        }
        return $this->listRequestsForClasses([$classId]);
    }

    public function listRequestsForClasses(array $classIds): array {
        $classIds = array_values(array_unique(array_filter(array_map('intval', $classIds), static function (int $id): bool {
            return $id > 0;
        })));
        if (!$classIds) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($classIds), '?'));
        $stmt = $this->conn->prepare(
            "SELECT request.*, class.name AS class_name, member.crn,
                    CONCAT_WS(' ', member.first_name, member.middle_name, member.last_name) AS member_name,
                    COALESCE(requester.name,
                        CONCAT_WS(' ', requester_member.first_name, requester_member.last_name)) AS requested_by_name,
                    COALESCE(reviewer.name,
                        CONCAT_WS(' ', reviewer_member.first_name, reviewer_member.last_name)) AS reviewed_by_name
               FROM bible_class_member_removal_requests request
               JOIN bible_classes class ON class.id = request.class_id
               JOIN members member ON member.id = request.member_id
               LEFT JOIN users requester ON requester.id = request.requested_by_user_id
               LEFT JOIN members requester_member ON requester_member.id = request.requested_by_member_id
               LEFT JOIN users reviewer ON reviewer.id = request.reviewed_by_user_id
               LEFT JOIN members reviewer_member ON reviewer_member.id = request.reviewed_by_member_id
              WHERE request.class_id IN ({$placeholders})
              ORDER BY (request.status = 'pending') DESC, request.created_at DESC"
        );
        $types = str_repeat('i', count($classIds));
        $stmt->bind_param($types, ...$classIds);
        $stmt->execute();
        $requests = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return $requests;
    }
}
