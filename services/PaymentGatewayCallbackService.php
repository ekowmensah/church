<?php

final class PaymentGatewayCallbackService
{
    private mysqli $conn;

    public function __construct(mysqli $conn)
    {
        $this->conn = $conn;
    }

    public static function normalizeStatus(string $status): string
    {
        $value = strtolower(trim($status));
        if (in_array($value, ['success', 'successful', 'completed', 'paid', 'approved'], true)) {
            return 'Completed';
        }
        if (in_array($value, ['failed', 'rejected', 'cancelled', 'canceled'], true)) {
            return 'Failed';
        }
        return 'Pending';
    }

    public function record(array $callback): array
    {
        $reference = trim((string) ($callback['client_reference'] ?? ''));
        if ($reference === '') {
            throw new InvalidArgumentException('The gateway callback has no client reference.');
        }
        $gatewayStatus = self::normalizeStatus((string) ($callback['status'] ?? ''));
        $source = (string) ($callback['payment_source'] ?? 'legacy_callback');
        if (!in_array($source, ['online_checkout', 'paystack', 'ussd', 'legacy_callback'], true)) {
            $source = 'legacy_callback';
        }
        $confirmationSource = (string) ($callback['confirmation_source'] ?? 'gateway_callback');
        if (!in_array($confirmationSource, ['gateway_callback', 'status_check'], true)) {
            $confirmationSource = 'gateway_callback';
        }
        $payloadHash = isset($callback['raw_payload'])
            ? hash('sha256', (string) $callback['raw_payload'])
            : null;

        $this->conn->begin_transaction();
        try {
            $find = $this->conn->prepare(
                'SELECT * FROM payment_intents WHERE client_reference = ? FOR UPDATE'
            );
            $find->bind_param('s', $reference);
            $find->execute();
            $intent = $find->get_result()->fetch_assoc();
            $find->close();

            $amount = max(0, (float) ($callback['amount'] ?? 0));
            $memberId = $this->nullableId($callback['member_id'] ?? null);
            $childId = $this->nullableId($callback['sundayschool_id'] ?? null);
            $churchId = $this->nullableId($callback['church_id'] ?? null);
            $paymentTypeId = $this->nullableId($callback['payment_type_id'] ?? null);
            $transactionId = trim((string) ($callback['transaction_id'] ?? '')) ?: null;
            $description = trim((string) ($callback['description'] ?? 'Gateway payment')) ?: 'Gateway payment';
            $customerName = trim((string) ($callback['customer_name'] ?? '')) ?: 'Hubtel customer';
            $customerPhone = trim((string) ($callback['customer_phone'] ?? ''));
            $paymentPeriod = trim((string) ($callback['payment_period'] ?? '')) ?: null;
            $periodDescription = trim((string) ($callback['payment_period_description'] ?? '')) ?: null;
            $bulkBreakdown = $callback['bulk_breakdown'] ?? null;
            if (is_array($bulkBreakdown)) {
                $bulkBreakdown = json_encode($bulkBreakdown, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }

            if (!$intent) {
                if ($amount <= 0) {
                    throw new RuntimeException('A new gateway intent requires a positive amount.');
                }
                $insert = $this->conn->prepare(
                    'INSERT INTO payment_intents
                        (client_reference, hubtel_transaction_id, transaction_id,
                         member_id, sundayschool_id, church_id, amount, description,
                         customer_name, customer_phone, status, approval_status,
                         success_confirmation_source, approval_requested_at,
                         payment_type_id, payment_period,
                         payment_period_description, bulk_breakdown, payment_source,
                         created_at, updated_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?,
                             CASE WHEN ? = \'pending\' THEN NOW() ELSE NULL END,
                             ?, ?, ?, ?, ?, NOW(), NOW())'
                );
                $approvalStatus = $gatewayStatus === 'Completed' ? 'pending' : 'not_required';
                $successfulConfirmation = $gatewayStatus === 'Completed' ? $confirmationSource : null;
                $insert->bind_param(
                    'sssiiidsssssssissss',
                    $reference, $transactionId, $transactionId, $memberId, $childId,
                    $churchId, $amount, $description, $customerName, $customerPhone,
                    $gatewayStatus, $approvalStatus, $successfulConfirmation,
                    $approvalStatus, $paymentTypeId,
                    $paymentPeriod, $periodDescription, $bulkBreakdown, $source
                );
                $insert->execute();
                $intentId = (int) $this->conn->insert_id;
                $insert->close();
                $previousApproval = 'not_required';
                $previousGatewayStatus = null;
            } else {
                $intentId = (int) $intent['id'];
                $storedSource = (string) ($intent['payment_source'] ?? 'online_checkout');
                if ($source !== 'ussd' && in_array($storedSource, ['online_checkout', 'paystack', 'ussd', 'legacy_callback'], true)) {
                    $source = $storedSource;
                }
                $storedGatewayStatus = self::normalizeStatus((string) ($intent['status'] ?? ''));
                $previousGatewayStatus = $storedGatewayStatus;
                // Gateway callbacks can arrive out of order. Once a success
                // has been observed, a later pending/failed retry must not
                // remove the approval work or invalidate an approved post.
                if ($storedGatewayStatus === 'Completed') {
                    $gatewayStatus = 'Completed';
                } elseif ($storedGatewayStatus === 'Failed' && $gatewayStatus === 'Pending') {
                    $gatewayStatus = 'Failed';
                }
                $successfulConfirmation = $storedGatewayStatus === 'Completed'
                    ? ($intent['success_confirmation_source'] ?? null)
                    : ($gatewayStatus === 'Completed'
                        ? ($storedGatewayStatus === 'Failed' ? 'status_check' : $confirmationSource)
                        : null);
                $previousApproval = (string) ($intent['approval_status'] ?? 'not_required');
                $approvalStatus = $previousApproval;
                if (!in_array($previousApproval, ['approved', 'rejected'], true)) {
                    $approvalStatus = $gatewayStatus === 'Completed' ? 'pending' : 'not_required';
                }
                $update = $this->conn->prepare(
                    'UPDATE payment_intents
                        SET status = ?,
                            hubtel_transaction_id = COALESCE(?, hubtel_transaction_id),
                            transaction_id = COALESCE(?, transaction_id),
                            amount = CASE WHEN amount <= 0 AND ? > 0 THEN ? ELSE amount END,
                            member_id = COALESCE(member_id, ?),
                            sundayschool_id = COALESCE(sundayschool_id, ?),
                            church_id = COALESCE(church_id, ?),
                            payment_type_id = COALESCE(payment_type_id, ?),
                            payment_period = COALESCE(payment_period, ?),
                            payment_period_description = COALESCE(payment_period_description, ?),
                            payment_source = ?, approval_status = ?,
                            success_confirmation_source = CASE
                                WHEN ? = \'Completed\'
                                    THEN COALESCE(success_confirmation_source, ?)
                                ELSE success_confirmation_source END,
                            approval_requested_at = CASE
                                WHEN ? = \'pending\' THEN COALESCE(approval_requested_at, NOW())
                                ELSE approval_requested_at END,
                            updated_at = NOW()
                      WHERE id = ?'
                );
                $update->bind_param(
                    'sssddiiiisssssssi',
                    $gatewayStatus, $transactionId, $transactionId, $amount, $amount,
                    $memberId, $childId, $churchId, $paymentTypeId, $paymentPeriod,
                    $periodDescription, $source, $approvalStatus, $gatewayStatus,
                    $successfulConfirmation, $approvalStatus, $intentId
                );
                $update->execute();
                $update->close();
            }

            $this->audit($intentId, 'callback_received', $gatewayStatus, 'Gateway callback captured without posting income.', $payloadHash, null);
            if ($approvalStatus === 'pending' && $previousApproval !== 'pending') {
                $this->audit($intentId, 'approval_requested', $gatewayStatus, 'Successful gateway payment queued for authorized review.', $payloadHash, null);
            }
            $this->conn->commit();
            return [
                'intent_id' => $intentId,
                'status' => $gatewayStatus,
                'approval_status' => $approvalStatus,
                'previous_status' => $previousGatewayStatus,
                'confirmation_source' => $successfulConfirmation,
                'was_existing' => (bool) $intent,
            ];
        } catch (Throwable $error) {
            $this->conn->rollback();
            throw $error;
        }
    }

    private function audit(int $intentId, string $action, ?string $gatewayStatus, string $notes, ?string $payloadHash, ?int $actorUserId): void
    {
        $stmt = $this->conn->prepare(
            'INSERT INTO online_payment_approval_audit
                (payment_intent_id, action, gateway_status, notes, payload_sha256, performed_by_user_id)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->bind_param('issssi', $intentId, $action, $gatewayStatus, $notes, $payloadHash, $actorUserId);
        $stmt->execute();
        $stmt->close();
    }

    private function nullableId($value): ?int
    {
        $id = (int) $value;
        return $id > 0 ? $id : null;
    }
}
