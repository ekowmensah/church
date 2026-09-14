<?php

require_once __DIR__ . '/../includes/sms.php';

class UserOnboardingService {
    private mysqli $conn;
    private $sender;

    public function __construct(mysqli $conn, ?callable $sender = null) {
        $this->conn = $conn;
        $this->sender = $sender ?: static function ($phone, $message) {
            return send_sms($phone, $message);
        };
    }

    public function sendAccountCreated(int $userId, string $temporaryPassword, ?int $actorUserId): array {
        $account = $this->getAccount($userId);
        if (!$account) {
            throw new RuntimeException('The new user account could not be found.');
        }

        $phone = trim((string) $account['phone']);
        $roles = trim((string) $account['access_roles']);
        $reason = null;
        if ($temporaryPassword === '') {
            $reason = 'A temporary password was not available.';
        } elseif ((string) $account['status'] !== 'active') {
            $reason = 'The account is inactive, so onboarding was not sent.';
        } elseif ($phone === '') {
            $reason = 'The linked member has no contact number.';
        } elseif ($roles === '') {
            $reason = 'The user has no active system role.';
        }

        $provider = $this->configuredProvider();
        if ($reason !== null) {
            $auditId = $this->recordAttempt($account, $provider, 'skipped', null, $reason, $actorUserId);
            return ['status' => 'skipped', 'message' => $reason, 'audit_id' => $auditId];
        }

        $firstName = trim((string) $account['first_name']);
        if ($firstName === '') $firstName = 'Member';
        $message = sprintf(
            'Hello %s, you have been added to MyFreeman as %s. Email: %s Password: %s Login: https://portal.myfreeman.org Please change this temporary password after signing in.',
            $firstName,
            $roles,
            $account['email'],
            $temporaryPassword
        );

        try {
            $result = ($this->sender)($phone, $message);
        } catch (Throwable $exception) {
            error_log('User onboarding SMS sender threw an exception.');
            $result = ['status' => 'error'];
        }
        if (!is_array($result)) $result = ['status' => 'error'];

        $sent = strtolower((string) ($result['status'] ?? '')) === 'success';
        $status = $sent ? 'sent' : 'failed';
        $reference = $sent ? $this->providerReference($result) : null;
        $failureReason = $sent ? null : $this->safeFailureReason($result);
        $auditId = $this->recordAttempt(
            $account,
            $provider,
            $status,
            $reference,
            $failureReason,
            $actorUserId
        );

        return [
            'status' => $status,
            'message' => $sent
                ? 'The onboarding SMS was accepted by the provider.'
                : 'The account was created, but the onboarding SMS was not delivered.',
            'audit_id' => $auditId,
        ];
    }

    private function getAccount(int $userId): ?array {
        $stmt = $this->conn->prepare(
            "SELECT user_account.id, user_account.member_id, user_account.email, user_account.status,
                    COALESCE(NULLIF(TRIM(member.first_name), ''),
                             SUBSTRING_INDEX(TRIM(user_account.name), ' ', 1)) AS first_name,
                    COALESCE(NULLIF(TRIM(member.phone), ''), TRIM(user_account.phone)) AS phone,
                    GROUP_CONCAT(DISTINCT access_role.name ORDER BY access_role.name SEPARATOR ', ') AS access_roles
               FROM users user_account
               JOIN members member ON member.id = user_account.member_id
               LEFT JOIN user_roles user_role
                 ON user_role.user_id = user_account.id
                AND user_role.is_active = 1
                AND (user_role.expires_at IS NULL OR user_role.expires_at > NOW())
               LEFT JOIN roles access_role
                 ON access_role.id = user_role.role_id AND access_role.is_active = 1
              WHERE user_account.id = ?
              GROUP BY user_account.id, user_account.member_id, user_account.email, user_account.status,
                       member.first_name, member.phone, user_account.name, user_account.phone
              LIMIT 1"
        );
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $account = $stmt->get_result()->fetch_assoc() ?: null;
        $stmt->close();
        return $account;
    }

    private function configuredProvider(): ?string {
        $config = get_sms_config();
        $provider = is_array($config) ? trim((string) ($config['default_provider'] ?? '')) : '';
        return $provider !== '' ? substr($provider, 0, 40) : null;
    }

    private function providerReference(array $result): ?string {
        $values = [
            $result['messageId'] ?? null,
            $result['message_id'] ?? null,
            $result['id'] ?? null,
            $result['data']['messageId'] ?? null,
            $result['data']['message_id'] ?? null,
            $result['data']['id'] ?? null,
        ];
        foreach ($values as $value) {
            if (is_scalar($value) && trim((string) $value) !== '') {
                return substr(trim((string) $value), 0, 120);
            }
        }
        return null;
    }

    private function safeFailureReason(array $result): string {
        $httpCode = isset($result['http_code']) ? (int) $result['http_code'] : 0;
        return $httpCode > 0
            ? 'The SMS provider rejected the request (HTTP ' . $httpCode . ').'
            : 'The SMS provider did not accept the onboarding message.';
    }

    private function recordAttempt(
        array $account,
        ?string $provider,
        string $status,
        ?string $reference,
        ?string $failureReason,
        ?int $actorUserId
    ): int {
        $digits = preg_replace('/\D+/', '', (string) $account['phone']);
        $last4 = $digits !== '' ? substr($digits, -4) : null;
        $stmt = $this->conn->prepare(
            "INSERT INTO user_onboarding_notifications
                (user_id, member_id, notification_type, destination_last4, provider,
                 delivery_status, provider_reference, failure_reason, sent_by_user_id, sent_at)
             VALUES (?, ?, 'account_created', ?, ?, ?, ?, ?, ?,
                     CASE WHEN ? = 'sent' THEN NOW() ELSE NULL END)"
        );
        $userId = (int) $account['id'];
        $memberId = (int) $account['member_id'];
        $stmt->bind_param(
            'iisssssis',
            $userId,
            $memberId,
            $last4,
            $provider,
            $status,
            $reference,
            $failureReason,
            $actorUserId,
            $status
        );
        $stmt->execute();
        $auditId = (int) $stmt->insert_id;
        $stmt->close();
        return $auditId;
    }
}
