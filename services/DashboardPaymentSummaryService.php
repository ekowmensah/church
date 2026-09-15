<?php

final class DashboardPaymentSummaryService {
    private mysqli $conn;
    private int $userId;
    private bool $isSuperAdmin;
    private bool $isCashier;
    private ?int $churchId;

    public function __construct(mysqli $conn, int $userId, bool $isSuperAdmin, bool $isCashier) {
        $this->conn = $conn;
        $this->userId = $userId;
        $this->isSuperAdmin = $isSuperAdmin;
        $this->isCashier = $isCashier;
        $this->churchId = $this->loadChurchId();
    }

    public function getCurrentYearByPaymentType(?int $year = null): array {
        $year = $year ?? (int) date('Y');
        if ($year < 2000 || $year > 2100) {
            throw new InvalidArgumentException('Choose a valid reporting year.');
        }
        if (!$this->isSuperAdmin && !$this->isCashier && $this->churchId === null) {
            return [];
        }

        $start = sprintf('%04d-01-01 00:00:00', $year);
        $end = sprintf('%04d-01-01 00:00:00', $year + 1);
        $join = "p.payment_type_id = payment_type.id
                 AND p.payment_date >= ? AND p.payment_date < ?
                 AND (p.reversal_approved_at IS NULL OR p.reversal_undone_at IS NOT NULL)
                 AND (p.status IS NULL OR LOWER(p.status) IN
                      ('completed', 'paid', 'success', 'successful', 'approved'))";
        $params = [$start, $end];
        $types = 'ss';

        if ($this->isCashier) {
            $join .= ' AND p.recorded_by = ?';
            $params[] = (string) $this->userId;
            $types .= 's';
        } elseif (!$this->isSuperAdmin) {
            $join .= ' AND p.church_id = ?';
            $params[] = (int) $this->churchId;
            $types .= 'i';
        }

        $stmt = $this->conn->prepare(
            "SELECT payment_type.id, payment_type.name AS label,
                    COUNT(p.id) AS entry_count,
                    COALESCE(SUM(p.amount), 0) AS total_amount
               FROM payment_types payment_type
               LEFT JOIN v_posted_payments p ON {$join}
              WHERE payment_type.active = 1
              GROUP BY payment_type.id, payment_type.name
              ORDER BY total_amount DESC, payment_type.name ASC"
        );
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        foreach ($rows as &$row) {
            $row['id'] = (int) $row['id'];
            $row['entry_count'] = (int) $row['entry_count'];
            $row['total_amount'] = (float) $row['total_amount'];
        }
        unset($row);
        return $rows;
    }

    private function loadChurchId(): ?int {
        if ($this->userId < 1) return null;
        $stmt = $this->conn->prepare('SELECT church_id FROM users WHERE id = ? LIMIT 1');
        $stmt->bind_param('i', $this->userId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $churchId = (int) ($row['church_id'] ?? 0);
        return $churchId > 0 ? $churchId : null;
    }
}
