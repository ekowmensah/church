<?php

final class PaymentCorrectionService {
    private mysqli $conn;
    private int $actorUserId;
    private bool $superAdmin;
    private ?int $actorChurchId = null;

    public function __construct(mysqli $conn, int $actorUserId, bool $superAdmin = false) {
        if ($actorUserId <= 0) {
            throw new InvalidArgumentException('A signed-in user is required to correct a payment.');
        }

        $this->conn = $conn;
        $this->actorUserId = $actorUserId;
        $this->superAdmin = $superAdmin;

        $stmt = $conn->prepare('SELECT church_id FROM users WHERE id = ? LIMIT 1');
        $stmt->bind_param('i', $actorUserId);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$user) {
            throw new RuntimeException('The signed-in user account could not be resolved.');
        }
        $this->actorChurchId = (int) ($user['church_id'] ?? 0) ?: null;
    }

    public function load(int $paymentId): array {
        if ($paymentId <= 0) {
            throw new InvalidArgumentException('Choose a valid payment.');
        }

        $payment = $this->fetchPayment($paymentId, false);
        if (!$payment) {
            throw new RuntimeException('Payment not found or outside your authorized church.');
        }

        [$allowed, $reason, $route] = $this->correctionEligibility($payment);
        $payment['correction_allowed'] = $allowed;
        $payment['correction_block_reason'] = $reason;
        $payment['correction_route'] = $route;
        return $payment;
    }

    public function paymentTypes(int $currentPaymentTypeId): array {
        $stmt = $this->conn->prepare(
            'SELECT id, name, active
               FROM payment_types
              WHERE active = 1 OR id = ?
              ORDER BY active DESC, name'
        );
        $stmt->bind_param('i', $currentPaymentTypeId);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return $rows;
    }

    public function correct(int $paymentId, array $input, string $reason): array {
        $reason = mb_substr(trim($reason), 0, 500);
        if ($reason === '') {
            throw new InvalidArgumentException('Enter a correction reason for the audit trail.');
        }

        $typeId = (int) ($input['payment_type_id'] ?? 0);
        $amountText = trim((string) ($input['amount'] ?? ''));
        $paymentDate = $this->validDate((string) ($input['payment_date'] ?? ''));
        $reportingMonth = $this->validMonth((string) ($input['reporting_month'] ?? ''));
        if ($typeId <= 0) {
            throw new InvalidArgumentException('Choose a valid payment type.');
        }
        if (!preg_match('/^\d{1,8}(?:\.\d{1,2})?$/', $amountText)) {
            throw new InvalidArgumentException('Enter a valid positive amount with no more than two decimal places.');
        }
        $amount = round((float) $amountText, 2);
        if ($amount <= 0 || $amount > 99999999.99) {
            throw new InvalidArgumentException('The corrected amount must be between GH\u{20B5}0.01 and GH\u{20B5}99,999,999.99.');
        }
        if ($paymentDate === '') {
            throw new InvalidArgumentException('Choose a valid transaction date.');
        }
        if ($reportingMonth === '') {
            throw new InvalidArgumentException('Choose a valid reporting month.');
        }

        $this->conn->begin_transaction();
        try {
            $payment = $this->fetchPayment($paymentId, true);
            if (!$payment) {
                throw new RuntimeException('Payment not found or outside your authorized church.');
            }
            [$allowed, $blockedReason] = $this->correctionEligibility($payment);
            if (!$allowed) {
                throw new RuntimeException($blockedReason);
            }

            $typeStmt = $this->conn->prepare(
                'SELECT id, name FROM payment_types
                  WHERE id = ? AND (active = 1 OR id = ?) LIMIT 1'
            );
            $currentTypeId = (int) ($payment['payment_type_id'] ?? 0);
            $typeStmt->bind_param('ii', $typeId, $currentTypeId);
            $typeStmt->execute();
            $paymentType = $typeStmt->get_result()->fetch_assoc();
            $typeStmt->close();
            if (!$paymentType) {
                throw new RuntimeException('The selected payment type is unavailable.');
            }

            $period = $reportingMonth . '-01';
            $periodDate = DateTimeImmutable::createFromFormat('!Y-m-d', $period);
            $periodLabel = $periodDate->format('F Y');
            $description = mb_substr('Payment for ' . $periodLabel . ' ' . $paymentType['name'], 0, 255);
            $oldTime = date('H:i:s', strtotime((string) $payment['payment_date']));
            $paymentDateTime = $paymentDate . ' ' . $oldTime;

            $previous = $this->snapshot($payment);
            $next = $previous;
            $next['payment_type_id'] = $typeId;
            $next['payment_type'] = (string) $paymentType['name'];
            $next['amount'] = number_format($amount, 2, '.', '');
            $next['payment_date'] = $paymentDateTime;
            $next['payment_period'] = $period;
            $next['payment_period_description'] = $periodLabel;
            $next['reporting_period_label'] = $periodLabel;
            $next['description'] = $description;

            if ($previous === $next) {
                throw new RuntimeException('No payment values changed.');
            }

            $stmt = $this->conn->prepare(
                'UPDATE payments
                    SET payment_type_id = ?, amount = ?, payment_date = ?,
                        payment_period = ?, payment_period_description = ?,
                        reporting_period_label = ?, description = ?
                  WHERE id = ? AND church_id = ?'
            );
            $churchId = (int) $payment['church_id'];
            $stmt->bind_param(
                'idsssssii',
                $typeId,
                $amount,
                $paymentDateTime,
                $period,
                $periodLabel,
                $periodLabel,
                $description,
                $paymentId,
                $churchId
            );
            $stmt->execute();
            if ($stmt->affected_rows !== 1) {
                throw new RuntimeException('The payment changed before the correction was saved. Refresh and try again.');
            }
            $stmt->close();

            $previousJson = json_encode($previous, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            $nextJson = json_encode($next, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            $audit = $this->conn->prepare(
                'INSERT INTO payment_correction_audit
                    (payment_id, church_id, previous_snapshot, new_snapshot,
                     correction_reason, changed_by_user_id)
                 VALUES (?, ?, ?, ?, ?, ?)'
            );
            $audit->bind_param(
                'iisssi',
                $paymentId,
                $churchId,
                $previousJson,
                $nextJson,
                $reason,
                $this->actorUserId
            );
            $audit->execute();
            $auditId = (int) $audit->insert_id;
            $audit->close();

            $this->conn->commit();
            return ['payment_id' => $paymentId, 'audit_id' => $auditId];
        } catch (Throwable $e) {
            $this->conn->rollback();
            throw $e;
        }
    }

    private function fetchPayment(int $paymentId, bool $forUpdate): ?array {
        $scope = '';
        $types = 'i';
        $params = [$paymentId];
        if (!$this->superAdmin) {
            if ($this->actorChurchId === null) return null;
            $scope = ' AND payment.church_id = ?';
            $types .= 'i';
            $params[] = $this->actorChurchId;
        }

        $sql = "SELECT payment.*,
                       type.name AS payment_type,
                       church.name AS church_name,
                       COALESCE(member.crn, child.srn, '') AS registration_number,
                       TRIM(CONCAT_WS(' ',
                           COALESCE(member.first_name, child.first_name),
                           COALESCE(member.middle_name, child.middle_name),
                           COALESCE(member.last_name, child.last_name))) AS payer_name,
                       recorder.name AS recorded_by_name
                  FROM payments payment
             LEFT JOIN payment_types type ON type.id = payment.payment_type_id
             LEFT JOIN churches church ON church.id = payment.church_id
             LEFT JOIN members member ON member.id = payment.member_id
             LEFT JOIN sunday_school child ON child.id = payment.sundayschool_id
             LEFT JOIN users recorder ON recorder.id = CAST(payment.recorded_by AS UNSIGNED)
                 WHERE payment.id = ?{$scope}
                 LIMIT 1" . ($forUpdate ? ' FOR UPDATE' : '');
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $payment = $stmt->get_result()->fetch_assoc() ?: null;
        $stmt->close();
        return $payment;
    }

    private function correctionEligibility(array $payment): array {
        if ((int) ($payment['church_id'] ?? 0) <= 0) {
            return [false, 'This legacy payment has no church ownership. Assign and review its ownership before correcting financial values.', 'payment_list.php'];
        }
        if (trim((string) ($payment['client_reference'] ?? '')) !== '') {
            return [false, 'Gateway-posted payments are immutable here. Use Gateway Integrity for gateway lifecycle actions.', 'payment_gateway_integrity.php'];
        }
        if (in_array(strtolower(trim((string) ($payment['mode'] ?? ''))), ['cheque', 'check'], true)) {
            return [false, 'Cheque payments are governed by the dedicated evidence and approval workflow.', 'cheque_payment_verification.php'];
        }
        if (!empty($payment['reversal_requested_at'])
            || !empty($payment['reversal_approved_at'])
            || !empty($payment['reversal_undone_at'])) {
            return [false, 'Payments with reversal history cannot be edited. Their original and reversal evidence must remain unchanged.', 'payment_reversal_log.php'];
        }
        return [true, '', ''];
    }

    private function snapshot(array $payment): array {
        return [
            'payment_type_id' => (int) ($payment['payment_type_id'] ?? 0),
            'payment_type' => (string) ($payment['payment_type'] ?? ''),
            'amount' => number_format((float) ($payment['amount'] ?? 0), 2, '.', ''),
            'payment_date' => (string) ($payment['payment_date'] ?? ''),
            'payment_period' => (string) ($payment['payment_period'] ?? ''),
            'payment_period_description' => (string) ($payment['payment_period_description'] ?? ''),
            'reporting_period_label' => (string) ($payment['reporting_period_label'] ?? ''),
            'description' => (string) ($payment['description'] ?? ''),
            'member_id' => isset($payment['member_id']) ? (int) $payment['member_id'] : null,
            'sundayschool_id' => isset($payment['sundayschool_id']) ? (int) $payment['sundayschool_id'] : null,
            'church_id' => (int) ($payment['church_id'] ?? 0),
            'mode' => (string) ($payment['mode'] ?? ''),
            'status' => (string) ($payment['status'] ?? ''),
            'recorded_by' => (string) ($payment['recorded_by'] ?? ''),
        ];
    }

    private function validDate(string $value): string {
        $value = trim($value);
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        return $date && $date->format('Y-m-d') === $value ? $value : '';
    }

    private function validMonth(string $value): string {
        $value = trim($value);
        $date = DateTimeImmutable::createFromFormat('!Y-m', $value);
        return $date && $date->format('Y-m') === $value ? $value : '';
    }
}
