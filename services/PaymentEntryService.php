<?php

final class PaymentEntryService {
    private mysqli $conn;
    private int $actorUserId;
    private bool $superAdmin;
    private ?int $actorChurchId = null;

    public function __construct(mysqli $conn, int $actorUserId, bool $superAdmin = false) {
        if ($actorUserId <= 0) throw new InvalidArgumentException('A signed-in recorder is required.');
        $this->conn = $conn;
        $this->actorUserId = $actorUserId;
        $this->superAdmin = $superAdmin;

        $stmt = $conn->prepare('SELECT church_id FROM users WHERE id = ? LIMIT 1');
        $stmt->bind_param('i', $actorUserId);
        $stmt->execute();
        $this->actorChurchId = (int) ($stmt->get_result()->fetch_assoc()['church_id'] ?? 0) ?: null;
        $stmt->close();
    }

    public function recordForPerson(
        int $memberId,
        int $sundaySchoolId,
        array $payments,
        bool $chequeChecklistConfirmed
    ): array {
        if (($memberId > 0) === ($sundaySchoolId > 0)) {
            throw new RuntimeException('Select exactly one member or Sunday School child.');
        }
        if (!$payments || count($payments) > 30) {
            throw new RuntimeException('Add between one and thirty payment lines.');
        }

        $person = $this->findPerson($memberId, $sundaySchoolId);
        $churchId = (int) $person['church_id'];
        if (!$this->superAdmin && ($this->actorChurchId === null || $this->actorChurchId !== $churchId)) {
            throw new RuntimeException('The payer is outside your authorized church.');
        }

        $normalized = [];
        $hasCheque = false;
        $sharedChequeIdentity = null;
        $sharedChequeAmount = null;
        $chequeAllocationTotalCents = 0;
        foreach ($payments as $index => $payment) {
            if (!is_array($payment)) throw new RuntimeException('Payment line ' . ($index + 1) . ' is invalid.');
            $normalized[] = $this->normalizePayment($payment, $index + 1);
            $normalizedPayment = end($normalized);
            if ($normalizedPayment['mode'] === 'Cheque') {
                $hasCheque = true;
                $chequeAllocationTotalCents += (int) round($normalizedPayment['amount'] * 100);
                $chequeIdentity = mb_strtolower($normalizedPayment['bank_name'])
                    . "\0" . mb_strtolower($normalizedPayment['cheque_number']);
                if ($sharedChequeIdentity === null) {
                    $sharedChequeIdentity = $chequeIdentity;
                } elseif ($sharedChequeIdentity !== $chequeIdentity) {
                    throw new RuntimeException(
                        'One payment submission can contain allocations for only one cheque. '
                        . 'Use a separate submission for another cheque.'
                    );
                }
                if ($sharedChequeAmount === null) {
                    $sharedChequeAmount = $normalizedPayment['cheque_amount'];
                } elseif ($sharedChequeAmount !== $normalizedPayment['cheque_amount']) {
                    throw new RuntimeException('All allocations for one cheque must use the same cheque amount.');
                }
            }
        }
        if ($hasCheque && !$chequeChecklistConfirmed) {
            throw new RuntimeException('Confirm the cheque-entry checklist before submission.');
        }
        if ($hasCheque && $chequeAllocationTotalCents !== (int) round($sharedChequeAmount * 100)) {
            throw new RuntimeException(
                'Cheque payment allocations must total exactly the cheque amount of GHS '
                . number_format((float) $sharedChequeAmount, 2) . '.'
            );
        }

        $batchReference = 'MAN-' . gmdate('YmdHis') . '-' . strtoupper(bin2hex(random_bytes(4)));
        $paymentIds = [];
        $this->conn->begin_transaction();
        try {
            $insert = $this->conn->prepare(
                'INSERT INTO payments
                    (member_id, sundayschool_id, payment_type_id, amount, mode,
                     cheque_number, bank_name, cheque_amount, payment_date, payment_period,
                     payment_period_description, reporting_period_label, description,
                     recorded_by, church_id, status, is_cheque_verified,
                     cheque_verification_status, manual_batch_reference)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $audit = $this->conn->prepare(
                'INSERT INTO payment_cheque_verification_audit
                    (payment_id, action, from_status, to_status, notes, performed_by_user_id)
                 VALUES (?, ?, ?, ?, ?, ?)'
            );

            foreach ($normalized as $payment) {
                $paymentMemberId = $memberId > 0 ? $memberId : null;
                $paymentSundaySchoolId = $sundaySchoolId > 0 ? $sundaySchoolId : null;
                $typeId = $payment['type_id'];
                $amount = $payment['amount'];
                $mode = $payment['mode'];
                $chequeNumber = $payment['cheque_number'];
                $bankName = $payment['bank_name'];
                $chequeAmount = $payment['cheque_amount'];
                $paymentDate = gmdate('Y-m-d H:i:s');
                $period = $payment['period'];
                $periodDescription = $payment['period_text'];
                $reportingLabel = $periodDescription;
                $description = $payment['description'];
                $status = $mode === 'Cheque' ? 'Pending' : 'Completed';
                $isVerified = 0;
                $verificationStatus = $mode === 'Cheque' ? 'pending' : 'not_applicable';
                $types = 'iiidsssd' . str_repeat('s', 5) . 'iisiss';
                $insert->bind_param(
                    $types,
                    $paymentMemberId, $paymentSundaySchoolId, $typeId, $amount, $mode,
                    $chequeNumber, $bankName, $chequeAmount, $paymentDate, $period,
                    $periodDescription, $reportingLabel, $description,
                    $this->actorUserId, $churchId, $status, $isVerified,
                    $verificationStatus, $batchReference
                );
                $insert->execute();
                $paymentId = (int) $insert->insert_id;
                $paymentIds[] = $paymentId;

                if ($mode === 'Cheque') {
                    $action = 'submitted';
                    $fromStatus = 'not_recorded';
                    $toStatus = 'pending';
                    $notes = 'Recorder confirmed the shared cheque evidence and this allocation amount and payment period; authorization remains pending. Manual batch: '
                        . $batchReference . '.';
                    $audit->bind_param(
                        'issssi', $paymentId, $action, $fromStatus, $toStatus,
                        $notes, $this->actorUserId
                    );
                    $audit->execute();
                }
            }
            $insert->close();
            $audit->close();
            $this->conn->commit();
        } catch (Throwable $e) {
            $this->conn->rollback();
            throw $e;
        }

        return [
            'payment_ids' => $paymentIds,
            'batch_reference' => $batchReference,
            'cash_count' => count(array_filter($normalized, static fn($row) => $row['mode'] === 'Cash')),
            'cheque_count' => count(array_filter($normalized, static fn($row) => $row['mode'] === 'Cheque')),
        ];
    }

    private function findPerson(int $memberId, int $sundaySchoolId): array {
        if ($memberId > 0) {
            $stmt = $this->conn->prepare(
                "SELECT id, church_id FROM members
                  WHERE id = ? AND status = 'active' AND is_archived = 0 LIMIT 1"
            );
            $stmt->bind_param('i', $memberId);
        } else {
            $stmt = $this->conn->prepare(
                'SELECT id, church_id FROM sunday_school
                  WHERE id = ? AND transferred_to_member_id IS NULL
                    AND is_duplicate_archived = 0 LIMIT 1'
            );
            $stmt->bind_param('i', $sundaySchoolId);
        }
        $stmt->execute();
        $person = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$person) throw new RuntimeException('The selected payer is unavailable or no longer active in that register.');
        return $person;
    }

    private function normalizePayment(array $payment, int $line): array {
        $typeId = (int) ($payment['type_id'] ?? 0);
        $amount = round((float) ($payment['amount'] ?? 0), 2);
        $chequeAmountInput = $payment['cheque_amount'] ?? null;
        $chequeAmount = is_numeric($chequeAmountInput) ? round((float) $chequeAmountInput, 2) : 0.0;
        $mode = ucfirst(strtolower(trim((string) ($payment['mode'] ?? 'Cash'))));
        if (!in_array($mode, ['Cash', 'Cheque'], true)) {
            throw new RuntimeException("Payment line {$line} must be Cash or Cheque.");
        }
        if ($amount <= 0) throw new RuntimeException("Payment line {$line} requires an amount greater than zero.");

        $stmt = $this->conn->prepare('SELECT name FROM payment_types WHERE id = ? AND active = 1 LIMIT 1');
        $stmt->bind_param('i', $typeId);
        $stmt->execute();
        $type = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$type) throw new RuntimeException("Payment line {$line} has an invalid payment type.");

        $period = trim((string) ($payment['period'] ?? ''));
        $periodDate = DateTimeImmutable::createFromFormat('!Y-m-d', $period);
        if (!$periodDate || $periodDate->format('Y-m-d') !== $period) {
            throw new RuntimeException("Payment line {$line} has an invalid reporting period.");
        }
        $period = $periodDate->modify('first day of this month')->format('Y-m-d');
        $periodText = $periodDate->format('F Y');
        $description = mb_substr(trim((string) ($payment['desc'] ?? '')), 0, 255);
        if ($description === '') $description = 'Payment for ' . $periodText . ' ' . $type['name'];

        $chequeNumber = mb_substr(trim((string) ($payment['cheque_number'] ?? '')), 0, 100);
        $bankName = mb_substr(trim((string) ($payment['bank_name'] ?? '')), 0, 120);
        if ($mode === 'Cheque' && ($chequeNumber === '' || $bankName === '')) {
            throw new RuntimeException("Payment line {$line} requires both bank name and cheque number.");
        }
        if ($mode === 'Cheque' && $chequeAmount <= 0) {
            throw new RuntimeException("Payment line {$line} requires a cheque amount greater than zero.");
        }
        if ($mode === 'Cash') {
            $chequeNumber = '';
            $bankName = '';
            $chequeAmount = null;
        }

        return [
            'type_id' => $typeId,
            'amount' => $amount,
            'mode' => $mode,
            'period' => $period,
            'period_text' => $periodText,
            'description' => $description,
            'cheque_number' => $chequeNumber,
            'bank_name' => $bankName,
            'cheque_amount' => $chequeAmount,
        ];
    }
}
