<?php

require_once __DIR__ . '/../models/Payment.php';
require_once __DIR__ . '/../includes/payment_sms_template.php';

final class OnlinePaymentApprovalService
{
    private mysqli $conn;
    private int $actorUserId;
    private bool $superAdmin;
    private bool $sendReceipts;
    private ?int $churchId = null;

    public function __construct(mysqli $conn, int $actorUserId, bool $superAdmin = false, bool $sendReceipts = true)
    {
        $this->conn = $conn;
        $this->actorUserId = $actorUserId;
        $this->superAdmin = $superAdmin;
        $this->sendReceipts = $sendReceipts;
        $stmt = $conn->prepare('SELECT church_id FROM users WHERE id = ? LIMIT 1');
        $stmt->bind_param('i', $actorUserId);
        $stmt->execute();
        $this->churchId = (int) ($stmt->get_result()->fetch_assoc()['church_id'] ?? 0) ?: null;
        $stmt->close();
    }

    public function listPending(): array
    {
        $where = "approval.approval_status = 'pending' AND lifecycle.status_check_state <> 'archived'";
        $types = '';
        $params = [];
        if (!$this->superAdmin) {
            if ($this->churchId === null) {
                return [];
            }
            $where .= ' AND approval.church_id = ?';
            $types = 'i';
            $params[] = $this->churchId;
        }
        $stmt = $this->conn->prepare(
            "SELECT approval.*,
                    lifecycle.gateway_customer_paid_amount,
                    lifecycle.gateway_charge_amount,
                    church.name AS church_name,
                    payment_type.name AS payment_type_name,
                    CASE
                        WHEN approval.sundayschool_id IS NOT NULL
                            THEN TRIM(CONCAT_WS(' ', child.first_name, child.middle_name, child.last_name))
                        ELSE TRIM(CONCAT_WS(' ', member.first_name, member.middle_name, member.last_name))
                    END AS beneficiary_name,
                    CASE WHEN approval.sundayschool_id IS NOT NULL THEN child.srn ELSE member.crn END AS beneficiary_reference
               FROM v_pending_online_payment_approvals approval
               JOIN payment_intents lifecycle ON lifecycle.id = approval.payment_intent_id
               LEFT JOIN churches church ON church.id = approval.church_id
               LEFT JOIN payment_types payment_type ON payment_type.id = approval.payment_type_id
               LEFT JOIN members member ON member.id = approval.member_id
               LEFT JOIN sunday_school child ON child.id = approval.sundayschool_id
              WHERE {$where}
              ORDER BY approval.approval_requested_at, approval.payment_intent_id
              LIMIT 500"
        );
        if ($types !== '') {
            $stmt->bind_param($types, ...$params);
        }
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return $rows;
    }

    public function decide(int $intentId, string $decision, string $notes): array
    {
        if (!in_array($decision, ['approved', 'rejected'], true)) {
            throw new InvalidArgumentException('Choose Approve or Reject.');
        }
        $notes = mb_substr(trim($notes), 0, 500);
        if ($notes === '') {
            throw new RuntimeException('A decision note is required.');
        }

        $this->conn->begin_transaction();
        try {
            $stmt = $this->conn->prepare('SELECT * FROM payment_intents WHERE id = ? FOR UPDATE');
            $stmt->bind_param('i', $intentId);
            $stmt->execute();
            $intent = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if (!$intent || $intent['approval_status'] !== 'pending') {
                throw new RuntimeException('This online payment is no longer pending approval.');
            }
            if (!$this->superAdmin && ($this->churchId === null || (int) $intent['church_id'] !== $this->churchId)) {
                throw new RuntimeException('This online payment is outside your church scope.');
            }
            if (!in_array(strtolower(trim((string) $intent['status'])), ['completed', 'paid', 'success', 'successful', 'approved'], true)) {
                throw new RuntimeException('Only a successful gateway payment can be approved.');
            }

            if ($decision === 'rejected') {
                $update = $this->conn->prepare(
                    "UPDATE payment_intents
                        SET approval_status = 'rejected', approved_by_user_id = ?,
                            approved_at = NOW(), approval_notes = ?, updated_at = NOW()
                      WHERE id = ? AND approval_status = 'pending'"
                );
                $update->bind_param('isi', $this->actorUserId, $notes, $intentId);
                $update->execute();
                $update->close();
                $this->audit($intentId, 'rejected', (string) $intent['status'], $notes);
                $this->conn->commit();
                return ['decision' => 'rejected', 'payment_ids' => []];
            }

            if ((string) ($intent['gateway_verification_status'] ?? 'not_checked') !== 'verified') {
                throw new RuntimeException('Check this transaction with the gateway before approving and posting it.');
            }

            $existing = $this->conn->prepare('SELECT COUNT(*) AS total FROM payments WHERE client_reference = ?');
            $existing->bind_param('s', $intent['client_reference']);
            $existing->execute();
            $existingCount = (int) ($existing->get_result()->fetch_assoc()['total'] ?? 0);
            $existing->close();
            if ($existingCount > 0) {
                throw new RuntimeException('Payment rows already exist for this reference. Reconcile them in Gateway Integrity instead of posting again.');
            }

            $lines = $this->paymentLines($intent);
            $lineTotal = array_sum(array_column($lines, 'amount'));
            if (abs($lineTotal - (float) $intent['amount']) > 0.01) {
                throw new RuntimeException('The payment-line total does not match the amount confirmed by Hubtel.');
            }

            $paymentModel = new Payment();
            $paymentIds = [];
            foreach ($lines as $line) {
                $paymentId = $paymentModel->add($this->conn, $line);
                if (!$paymentId) {
                    throw new RuntimeException('A payment line could not be posted.');
                }
                $paymentIds[] = (int) $paymentId;
            }

            $update = $this->conn->prepare(
                "UPDATE payment_intents
                    SET approval_status = 'approved', approved_by_user_id = ?,
                        approved_at = NOW(), approval_notes = ?, updated_at = NOW()
                  WHERE id = ? AND approval_status = 'pending'"
            );
            $update->bind_param('isi', $this->actorUserId, $notes, $intentId);
            $update->execute();
            if ($update->affected_rows !== 1) {
                throw new RuntimeException('The approval state changed before posting completed.');
            }
            $update->close();
            $this->audit($intentId, 'approved', (string) $intent['status'], $notes);
            $this->conn->commit();

            if ($this->sendReceipts) {
                foreach ($lines as $index => $line) {
                    $this->sendReceipt($line, $paymentIds[$index] ?? null, $intent);
                }
            }
            return ['decision' => 'approved', 'payment_ids' => $paymentIds];
        } catch (Throwable $error) {
            $this->conn->rollback();
            throw $error;
        }
    }

    /** Post a definitive Hubtel shortcode fulfillment without human approval. */
    public function autoPostSuccessfulUssd(int $intentId, string $notes = 'Definitive Hubtel USSD fulfillment automatically posted.'): array
    {
        return $this->autoPostDefinitiveGatewayPayment($intentId, ['ussd'], $notes);
    }

    /**
     * Post a definitive gateway fulfillment without human approval.
     * Status-check callers must never use this method: a success discovered by
     * reconciliation stays pending until an authorized user approves it.
     *
     * @param string[] $allowedSources
     */
    public function autoPostDefinitiveGatewayPayment(
        int $intentId,
        array $allowedSources = ['online_checkout', 'paystack', 'ussd'],
        string $notes = 'Definitive gateway fulfillment automatically posted.'
    ): array {
        $notes = mb_substr(trim($notes), 0, 500);
        $this->conn->begin_transaction();
        try {
            $stmt = $this->conn->prepare('SELECT * FROM payment_intents WHERE id = ? FOR UPDATE');
            $stmt->bind_param('i', $intentId);
            $stmt->execute();
            $intent = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            $source = (string) ($intent['payment_source'] ?? '');
            if (!$intent || !in_array($source, $allowedSources, true)) {
                throw new RuntimeException('This gateway source is not eligible for automatic posting.');
            }
            if (!in_array(strtolower(trim((string) $intent['status'])), ['completed', 'paid', 'success', 'successful', 'approved'], true)) {
                throw new RuntimeException('The payment cannot be posted automatically until the gateway confirms success.');
            }
            if ((string) $intent['approval_status'] === 'rejected') {
                throw new RuntimeException('A rejected gateway intent requires manual reconciliation.');
            }
            if ((string) ($intent['success_confirmation_source'] ?? '') !== 'gateway_callback') {
                throw new RuntimeException('A success discovered by status checking requires authorized approval.');
            }
            if ((string) ($intent['gateway_verification_status'] ?? 'not_checked') !== 'verified') {
                throw new RuntimeException('The gateway reference and amount must be verified server-to-server before automatic posting.');
            }
            if (in_array($source, ['online_checkout', 'paystack'], true)
                && empty($intent['gateway_initialized_at'])) {
                throw new RuntimeException('This online payment has no local initialization baseline and requires manual reconciliation.');
            }

            $existing = $this->conn->prepare('SELECT id, amount FROM payments WHERE client_reference = ? ORDER BY id');
            $existing->bind_param('s', $intent['client_reference']);
            $existing->execute();
            $existingRows = $existing->get_result()->fetch_all(MYSQLI_ASSOC);
            $paymentIds = array_map('intval', array_column($existingRows, 'id'));
            $existing->close();
            if ($paymentIds) {
                $existingTotal = array_sum(array_map('floatval', array_column($existingRows, 'amount')));
                if (abs($existingTotal - (float) $intent['amount']) > 0.01) {
                    throw new RuntimeException('Existing payment rows do not match the fulfilled gateway amount. Reconcile them before approval.');
                }
                if ((string) $intent['approval_status'] !== 'approved') {
                    $update = $this->conn->prepare(
                        "UPDATE payment_intents
                            SET approval_status = 'approved', approved_by_user_id = NULL,
                                approved_at = COALESCE(approved_at, NOW()),
                                approval_notes = ?, updated_at = NOW()
                          WHERE id = ?"
                    );
                    $update->bind_param('si', $notes, $intentId);
                    $update->execute();
                    $update->close();
                }
                $this->conn->commit();
                return ['decision' => 'auto_posted', 'payment_ids' => $paymentIds, 'already_posted' => true];
            }

            $recorder = $source === 'ussd' ? 'USSD' : 'Online';
            $lines = $this->paymentLines($intent, $recorder, 'Completed');
            $lineTotal = array_sum(array_column($lines, 'amount'));
            if (abs($lineTotal - (float) $intent['amount']) > 0.01) {
                throw new RuntimeException('The gateway payment-line total does not match the fulfilled amount.');
            }

            $paymentModel = new Payment();
            foreach ($lines as $line) {
                $paymentId = $paymentModel->add($this->conn, $line);
                if (!$paymentId) {
                    throw new RuntimeException('A fulfilled gateway payment line could not be posted.');
                }
                $paymentIds[] = (int) $paymentId;
            }

            $update = $this->conn->prepare(
                "UPDATE payment_intents
                    SET approval_status = 'approved', approved_by_user_id = NULL,
                        approved_at = NOW(), approval_notes = ?, updated_at = NOW()
                  WHERE id = ? AND approval_status <> 'rejected'"
            );
            $update->bind_param('si', $notes, $intentId);
            $update->execute();
            $update->close();
            $this->audit($intentId, 'auto_posted', (string) $intent['status'], $notes);
            $this->conn->commit();

            if ($this->sendReceipts) {
                foreach ($lines as $index => $line) {
                    $this->sendReceipt($line, $paymentIds[$index] ?? null, $intent);
                }
            }
            return ['decision' => 'auto_posted', 'payment_ids' => $paymentIds, 'already_posted' => false];
        } catch (Throwable $error) {
            $this->conn->rollback();
            throw $error;
        }
    }

    private function paymentLines(array $intent, ?string $recordedBy = null, string $paymentStatus = 'Approved'): array
    {
        $items = [];
        if (trim((string) ($intent['bulk_breakdown'] ?? '')) !== '') {
            $decoded = json_decode((string) $intent['bulk_breakdown'], true);
            if (!is_array($decoded) || !$decoded) {
                throw new RuntimeException('The stored bulk payment breakdown is invalid.');
            }
            $items = $decoded;
        } else {
            $items[] = [];
        }

        $lines = [];
        foreach ($items as $item) {
            $memberId = (int) ($item['member_id'] ?? $intent['member_id'] ?? 0);
            $childId = (int) ($item['sundayschool_id'] ?? $intent['sundayschool_id'] ?? 0);
            if (($memberId > 0) === ($childId > 0)) {
                throw new RuntimeException('Each online payment line must identify exactly one CRN or SRN beneficiary.');
            }
            $amount = (float) ($item['amount'] ?? $intent['amount']);
            if ($amount <= 0) {
                throw new RuntimeException('Every online payment line requires a positive amount.');
            }
            $paymentTypeId = (int) ($item['payment_type_id'] ?? $item['typeId'] ?? $intent['payment_type_id'] ?? 0);
            if ($paymentTypeId <= 0) {
                throw new RuntimeException('Every online payment line requires a payment type.');
            }
            $churchId = (int) ($item['church_id'] ?? $intent['church_id'] ?? 0);
            if ($churchId <= 0) {
                $churchId = $this->subjectChurchId($memberId, $childId);
            }
            $paymentPeriod = trim((string) ($item['payment_period'] ?? $item['period'] ?? $item['date'] ?? $intent['payment_period'] ?? '')) ?: null;
            $periodDescription = trim((string) ($item['payment_period_description'] ?? $item['periodText'] ?? $intent['payment_period_description'] ?? '')) ?: null;
            $description = trim((string) ($item['desc'] ?? $item['typeName'] ?? $intent['description'] ?? 'Online payment'));
            $paymentDate = (string) ($intent['approval_requested_at'] ?: $intent['updated_at'] ?: $intent['created_at'] ?: date('Y-m-d H:i:s'));
            $lines[] = [
                'member_id' => $memberId > 0 ? $memberId : null,
                'sundayschool_id' => $childId > 0 ? $childId : null,
                'amount' => $amount,
                'description' => $description,
                'payment_date' => $paymentDate,
                'client_reference' => $intent['client_reference'],
                'status' => $paymentStatus,
                'church_id' => $churchId,
                'payment_type_id' => $paymentTypeId,
                'payment_period' => $paymentPeriod,
                'payment_period_description' => $periodDescription,
                // payments.recorded_by is a legacy VARCHAR column, but the
                // rest of the application treats numeric values as user IDs.
                // Retain the actual approver so scoped payment lists can show
                // the posted row and the recorder name remains auditable.
                'recorded_by' => $recordedBy ?? (string) $this->actorUserId,
                'mode' => $intent['payment_source'] === 'ussd' ? 'Mobile Money' : 'Online',
            ];
        }
        return $lines;
    }

    private function subjectChurchId(int $memberId, int $childId): int
    {
        $table = $memberId > 0 ? 'members' : 'sunday_school';
        $id = $memberId > 0 ? $memberId : $childId;
        $stmt = $this->conn->prepare("SELECT church_id FROM {$table} WHERE id = ? LIMIT 1");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $churchId = (int) ($stmt->get_result()->fetch_assoc()['church_id'] ?? 0);
        $stmt->close();
        if ($churchId <= 0) {
            throw new RuntimeException('The payment beneficiary has no church assignment.');
        }
        return $churchId;
    }

    private function audit(int $intentId, string $action, string $gatewayStatus, string $notes): void
    {
        $stmt = $this->conn->prepare(
            'INSERT INTO online_payment_approval_audit
                (payment_intent_id, action, gateway_status, notes, performed_by_user_id)
             VALUES (?, ?, ?, ?, ?)'
        );
        $actorUserId = $this->actorUserId > 0 ? $this->actorUserId : null;
        $stmt->bind_param('isssi', $intentId, $action, $gatewayStatus, $notes, $actorUserId);
        $stmt->execute();
        $stmt->close();
    }

    private function sendReceipt(array $line, ?int $paymentId, array $intent): void
    {
        try {
            require_once __DIR__ . '/../includes/sms.php';
            if (!function_exists('log_sms')) {
                return;
            }
            if (!empty($line['member_id'])) {
                $stmt = $this->conn->prepare(
                    "SELECT phone, crn AS subject_reference,
                            TRIM(CONCAT_WS(' ', first_name, middle_name, last_name)) AS full_name
                       FROM members WHERE id = ? LIMIT 1"
                );
                $subjectId = (int) $line['member_id'];
            } else {
                $stmt = $this->conn->prepare(
                    "SELECT contact AS phone, srn AS subject_reference,
                            TRIM(CONCAT_WS(' ', first_name, middle_name, last_name)) AS full_name
                       FROM sunday_school WHERE id = ? AND is_duplicate_archived = 0 LIMIT 1"
                );
                $subjectId = (int) $line['sundayschool_id'];
            }
            $stmt->bind_param('i', $subjectId);
            $stmt->execute();
            $subject = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if (!$subject || trim((string) $subject['phone']) === '') {
                return;
            }

            $context = $this->conn->prepare(
                'SELECT payment_type.name AS payment_type_name, church.name AS church_name
                   FROM payment_types payment_type
                   LEFT JOIN churches church ON church.id = ?
                  WHERE payment_type.id = ? LIMIT 1'
            );
            $churchId = (int) ($line['church_id'] ?? 0);
            $paymentTypeId = (int) ($line['payment_type_id'] ?? 0);
            $context->bind_param('ii', $churchId, $paymentTypeId);
            $context->execute();
            $receiptContext = $context->get_result()->fetch_assoc() ?: [];
            $context->close();

            $paymentTypeName = (string) ($receiptContext['payment_type_name'] ?? $line['description'] ?? 'Payment');
            $churchName = (string) ($receiptContext['church_name'] ?? 'Freeman Methodist Church - KM');
            $fallbackDate = (string) ($line['payment_date'] ?? date('Y-m-d H:i:s'));
            $harvestYear = null;
            $harvestTotal = null;
            if (!empty($line['member_id']) && is_harvest_payment_type($paymentTypeName)) {
                $harvestYear = get_payment_period_year(
                    $line['payment_period'] ?? null,
                    $line['payment_period_description'] ?? null,
                    $fallbackDate
                );
                $harvestTotal = get_member_yearly_harvest_total(
                    $this->conn,
                    (int) $line['member_id'],
                    $harvestYear,
                    $paymentTypeId
                );
            }

            $source = (string) ($intent['payment_source'] ?? 'online_checkout');
            $payerPhone = trim((string) ($intent['customer_phone'] ?? ''));
            $phonesDiffer = $payerPhone !== ''
                && $this->normalizePhone($payerPhone) !== $this->normalizePhone((string) $subject['phone']);
            if ($source === 'ussd') {
                $senderName = $phonesDiffer ? (string) ($intent['customer_name'] ?? '') : '';
                $message = build_hubtel_ussd_member_payment_sms(
                    $subject['full_name'] ?: 'Member',
                    (float) $line['amount'],
                    $line['payment_period_description'] ?? null,
                    $paymentTypeName,
                    $senderName,
                    $churchName,
                    $harvestYear,
                    $harvestTotal,
                    $line['payment_period'] ?? null,
                    $fallbackDate
                );
                $smsType = 'ussd_payment_target';
            } else {
                $message = build_hubtel_portal_payment_sms(
                    $subject['full_name'] ?: 'Member',
                    (float) $line['amount'],
                    $line['payment_period_description'] ?? null,
                    $paymentTypeName,
                    (string) ($subject['subject_reference'] ?? ''),
                    $churchName,
                    $harvestYear,
                    $harvestTotal,
                    $line['payment_period'] ?? null,
                    $fallbackDate
                );
                $smsType = 'online_payment_target';
            }
            log_sms((string) $subject['phone'], $message, $paymentId, $smsType);

            if ($source === 'ussd' && $phonesDiffer) {
                $payerName = trim((string) ($intent['customer_name'] ?? '')) ?: 'Payer';
                $payerMessage = build_hubtel_ussd_payer_confirmation_sms(
                    $payerName,
                    (float) $line['amount'],
                    $line['payment_period_description'] ?? null,
                    $paymentTypeName,
                    $subject['full_name'] ?: 'the beneficiary',
                    $churchName,
                    $line['payment_period'] ?? null,
                    $fallbackDate
                );
                log_sms($payerPhone, $payerMessage, $paymentId, 'ussd_payment_payer');
            }
        } catch (Throwable $error) {
            error_log('Approved gateway payment SMS failed: ' . $error->getMessage());
        }
    }

    private function normalizePhone(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?: '';
        return strlen($digits) > 9 ? substr($digits, -9) : $digits;
    }
}
