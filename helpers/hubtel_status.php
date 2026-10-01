<?php
// Load .env if not already loaded
if (file_exists(__DIR__ . '/../vendor/autoload.php')) {
    require_once __DIR__ . '/../vendor/autoload.php';
    if (class_exists('Dotenv\Dotenv')) {
        $dotenv = Dotenv\Dotenv::createMutable(dirname(__DIR__, 1));
        $dotenv->safeLoad();
        if (method_exists($dotenv, 'overload')) {
            $dotenv->overload();
        }
    }
}

// Fallback: If getenv() still fails, manually parse .env and define constants
function define_env_constant($key) {
    if (getenv($key)) return;
    $envPath = __DIR__ . '/../.env';
    if (!file_exists($envPath)) return;
    $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        if (strpos($line, '=') !== false) {
            list($envKey, $envValue) = explode('=', $line, 2);
            $envKey = trim($envKey);
            $envValue = trim($envValue);
            if ($envKey === $key && !defined($key)) {
                define($key, $envValue);
                break;
            }
        }
    }
}
define_env_constant('HUBTEL_API_KEY');
define_env_constant('HUBTEL_API_SECRET');
define_env_constant('HUBTEL_MERCHANT_ACCOUNT');
define_env_constant('HUBTEL_STATUS_CLIENT_ID');
define_env_constant('HUBTEL_STATUS_CLIENT_SECRET');
define_env_constant('HUBTEL_COLLECTION_ACCOUNT_NUMBER');
define_env_constant('HUBTEL_CLIENT_ID');
define_env_constant('HUBTEL_CLIENT_SECRET');
require_once __DIR__ . '/../services/PaymentGatewayCallbackService.php';

/**
 * Check Hubtel transaction status using the transaction status API
 * @param string $transaction_id The transaction ID from Hubtel
 * @param string $client_reference The client reference for the transaction
 * @return array Response with success status and transaction data
 */
function check_hubtel_transaction_status($transaction_id, $client_reference = null) {
    $readEnv = function ($key, $constFallback = null) {
        $val = getenv($key);
        if ($val === false || $val === null || $val === '') {
            if (isset($_ENV[$key]) && $_ENV[$key] !== '') $val = $_ENV[$key];
            elseif (isset($_SERVER[$key]) && $_SERVER[$key] !== '') $val = $_SERVER[$key];
            elseif ($constFallback && defined($constFallback)) $val = constant($constFallback);
        }
        return ($val === false || $val === '') ? null : $val;
    };

    // Hubtel's transaction-status service uses the Client ID/Client Secret
    // issued for that service and the collection account number in the URL.
    // Keep the legacy checkout variables as a compatibility fallback only;
    // installations with dedicated status credentials should set the three
    // HUBTEL_STATUS/HUBTEL_COLLECTION variables below.
    $statusClientId = $readEnv('HUBTEL_STATUS_CLIENT_ID', 'HUBTEL_STATUS_CLIENT_ID')
        ?: $readEnv('HUBTEL_CLIENT_ID', 'HUBTEL_CLIENT_ID')
        ?: $readEnv('HUBTEL_API_KEY', 'HUBTEL_API_KEY');
    $statusClientSecret = $readEnv('HUBTEL_STATUS_CLIENT_SECRET', 'HUBTEL_STATUS_CLIENT_SECRET')
        ?: $readEnv('HUBTEL_CLIENT_SECRET', 'HUBTEL_CLIENT_SECRET')
        ?: $readEnv('HUBTEL_API_SECRET', 'HUBTEL_API_SECRET');
    $collectionAccount = $readEnv('HUBTEL_COLLECTION_ACCOUNT_NUMBER', 'HUBTEL_COLLECTION_ACCOUNT_NUMBER')
        ?: $readEnv('HUBTEL_MERCHANT_ACCOUNT', 'HUBTEL_MERCHANT_ACCOUNT');
    $usesDedicatedStatusCredentials = (bool) $readEnv('HUBTEL_STATUS_CLIENT_ID', 'HUBTEL_STATUS_CLIENT_ID');
    
    // Ensure logs directory exists
    $logs_dir = __DIR__.'/../logs';
    if (!is_dir($logs_dir)) {
        mkdir($logs_dir, 0755, true);
    }
    
    file_put_contents($logs_dir.'/hubtel_debug.log', date('c') . " - Status configuration: " . json_encode([
        'credential_profile' => $usesDedicatedStatusCredentials ? 'dedicated_status' : 'legacy_checkout_fallback',
        'client_id_present' => $statusClientId !== null,
        'client_secret_present' => $statusClientSecret !== null,
        'collection_account_present' => $collectionAccount !== null,
    ]) . "\n", FILE_APPEND);

    if (!$statusClientId || !$statusClientSecret || !$collectionAccount) {
        return [
            'success' => false,
            'verification_status' => 'not_checked',
            'failure_kind' => 'configuration_error',
            'countable_failure' => false,
            'error' => 'Hubtel status configuration is incomplete. Set HUBTEL_STATUS_CLIENT_ID, HUBTEL_STATUS_CLIENT_SECRET, and HUBTEL_COLLECTION_ACCOUNT_NUMBER.',
        ];
    }

    $client_reference = trim((string) $client_reference);
    if ($client_reference === '') {
        return [
            'success' => false,
            'verification_status' => 'not_checked',
            'failure_kind' => 'invalid_request',
            'countable_failure' => false,
            'error' => 'A client reference is required for Hubtel status checking.',
        ];
    }

    $query = ['clientReference' => $client_reference];
    $transaction_id = trim((string) $transaction_id);
    if ($transaction_id !== '' && $transaction_id !== $client_reference) {
        $query['hubtelTransactionId'] = $transaction_id;
    }

    $url = 'https://api-txnstatus.hubtel.com/transactions/'
        . rawurlencode((string) $collectionAccount)
        . '/status?'
        . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
        CURLOPT_USERPWD => $statusClientId . ':' . $statusClientSecret,
        CURLOPT_HTTPHEADER => ['Accept: application/json'],
        CURLOPT_TIMEOUT => 30,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_USERAGENT => 'MyFreeman Church Management/1.0',
    ]);
    $response = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_errno($ch) ? curl_error($ch) : null;
    curl_close($ch);

    $decoded = is_string($response) && $response !== '' ? json_decode($response, true) : null;
    file_put_contents($logs_dir.'/hubtel_debug.log', date('c') . " - Status request result: " . json_encode([
        'http_code' => $httpCode,
        'curl_error' => $curlError,
        'client_reference' => $client_reference,
        'credential_profile' => $usesDedicatedStatusCredentials ? 'dedicated_status' : 'legacy_checkout_fallback',
    ]) . "\n", FILE_APPEND);

    if ($curlError) {
        return [
            'success' => false,
            'verification_status' => 'not_checked',
            'failure_kind' => 'network_error',
            'countable_failure' => false,
            'http_code' => $httpCode,
            'error' => 'Hubtel status request could not connect: ' . $curlError,
        ];
    }
    if ($httpCode === 401) {
        return [
            'success' => false,
            'verification_status' => 'not_checked',
            'failure_kind' => 'authentication_error',
            'countable_failure' => false,
            'http_code' => $httpCode,
            'error' => 'Hubtel rejected the transaction-status Client ID or Client Secret (HTTP 401). Configure HUBTEL_STATUS_CLIENT_ID and HUBTEL_STATUS_CLIENT_SECRET with the credentials enabled for Transaction Status.',
        ];
    }
    if ($httpCode === 403) {
        return [
            'success' => false,
            'verification_status' => 'not_checked',
            'failure_kind' => 'access_denied',
            'countable_failure' => false,
            'http_code' => $httpCode,
            'error' => 'Hubtel denied the status request (HTTP 403). Ask Hubtel to whitelist this server\'s public outbound IP for Transaction Status.',
        ];
    }
    if ($httpCode === 429) {
        return [
            'success' => false,
            'verification_status' => 'not_checked',
            'failure_kind' => 'rate_limited',
            'countable_failure' => false,
            'http_code' => $httpCode,
            'error' => 'Hubtel status checking is temporarily rate-limited. Wait and retry.',
        ];
    }
    if ($httpCode === 404) {
        return [
            'success' => false,
            'verification_status' => 'failed',
            'failure_kind' => 'not_found',
            'countable_failure' => true,
            'http_code' => $httpCode,
            'error' => 'Hubtel could not find a transaction for this client reference.',
        ];
    }
    if ($httpCode !== 200 || !is_array($decoded)) {
        $gatewayMessage = is_array($decoded)
            ? trim((string) ($decoded['message'] ?? $decoded['Message'] ?? ''))
            : '';
        return [
            'success' => false,
            'verification_status' => 'not_checked',
            'failure_kind' => 'gateway_error',
            'countable_failure' => false,
            'http_code' => $httpCode,
            'error' => 'Hubtel status request failed with HTTP ' . $httpCode
                . ($gatewayMessage !== '' ? ': ' . $gatewayMessage : '.'),
        ];
    }

    $transactionData = is_array($decoded['data'] ?? null) ? $decoded['data'] : $decoded;
    $status = $transactionData['status']
        ?? $transactionData['Status']
        ?? $transactionData['transactionStatus']
        ?? $decoded['status']
        ?? 'unknown';

    return [
        'success' => true,
        'failure_kind' => null,
        'countable_failure' => false,
        'data' => $decoded,
        'http_code' => $httpCode,
        'status' => $status,
        'amount' => $transactionData['amount'] ?? $transactionData['Amount'] ?? null,
        'reference' => $transactionData['clientReference'] ?? $transactionData['ClientReference'] ?? null,
        'transaction_id' => $transactionData['transactionId']
            ?? $transactionData['TransactionId']
            ?? $transactionData['hubtelTransactionId']
            ?? null,
        'external_transaction_id' => $transactionData['externalTransactionId'] ?? null,
        'payment_method' => $transactionData['paymentMethod'] ?? null,
        'charges' => $transactionData['charges'] ?? null,
        'amount_after_charges' => $transactionData['amountAfterCharges'] ?? null,
        'date' => $transactionData['date'] ?? null,
        'response_code' => $decoded['responseCode'] ?? $decoded['ResponseCode'] ?? null,
    ];
}

/**
 * Independently verify that Hubtel returned the requested transaction and the
 * amount the application expects. An HTTP 200 by itself is not verification.
 */
function verify_hubtel_transaction_status($transaction_id, $client_reference, $expected_amount) {
    $result = check_hubtel_transaction_status($transaction_id, $client_reference);
    $result['verified'] = false;
    $result['verification_status'] = 'not_checked';

    if (empty($result['success'])) {
        return $result;
    }

    $expectedReference = trim((string) $client_reference);
    $actualReference = trim((string) ($result['reference'] ?? ''));
    if ($expectedReference === '' || $actualReference === '' || !hash_equals($expectedReference, $actualReference)) {
        $result['verification_status'] = 'failed';
        $result['failure_kind'] = 'reference_mismatch';
        $result['countable_failure'] = true;
        $result['error'] = 'Hubtel returned a different or missing client reference.';
        return $result;
    }

    $expectedAmount = (float) $expected_amount;
    $actualAmount = $result['amount'] ?? null;
    if ($expectedAmount <= 0 || $actualAmount === null || !is_numeric($actualAmount)
        || abs((float) $actualAmount - $expectedAmount) > 0.01) {
        $result['verification_status'] = 'failed';
        $result['failure_kind'] = 'amount_mismatch';
        $result['countable_failure'] = true;
        $result['error'] = 'Hubtel returned a different or missing transaction amount.';
        return $result;
    }

    $result['verified'] = true;
    $result['verification_status'] = 'verified';
    $result['failure_kind'] = null;
    $result['countable_failure'] = false;
    return $result;
}

/** Persist one status-check result and apply the three-failure archive rule. */
function record_hubtel_status_check_result(mysqli $conn, array $intent, array $result, ?int $actor_user_id = null): array {
    $intentId = (int) ($intent['id'] ?? 0);
    if ($intentId <= 0) {
        throw new InvalidArgumentException('A payment intent is required for status-check auditing.');
    }

    $verified = !empty($result['verified']);
    $verifiedGatewayStatus = strtolower(trim((string) ($result['status'] ?? '')));
    $terminalVerification = $verified && !in_array(
        $verifiedGatewayStatus,
        ['', 'pending', 'processing', 'initiated', 'unknown'],
        true
    );
    $failureKind = (string) ($result['failure_kind'] ?? 'gateway_error');
    $outcome = $verified ? 'verified' : match ($failureKind) {
        'not_found' => 'not_found',
        'reference_mismatch' => 'reference_mismatch',
        'amount_mismatch' => 'amount_mismatch',
        default => 'gateway_error',
    };
    $countable = !$verified && !empty($result['countable_failure']);
    $details = mb_substr(trim((string) ($result['error'] ?? 'Hubtel status verified.')), 0, 500);
    if ($details === '') {
        $details = $verified ? 'Hubtel status verified.' : 'Hubtel status check failed.';
    }

    $conn->begin_transaction();
    try {
        $lock = $conn->prepare(
            'SELECT status_check_attempts, status_check_state
               FROM payment_intents WHERE id = ? FOR UPDATE'
        );
        $lock->bind_param('i', $intentId);
        $lock->execute();
        $current = $lock->get_result()->fetch_assoc();
        $lock->close();
        if (!$current) {
            throw new RuntimeException('The payment intent no longer exists.');
        }

        $previousState = (string) $current['status_check_state'];
        $attempts = (int) $current['status_check_attempts'];
        $archivedNow = false;
        if ($verified) {
            // A valid Pending response proves the response identity/amount but
            // is not a terminal payment decision, so it stays in the queue.
            $state = $terminalVerification ? 'verified' : $previousState;
            $update = $conn->prepare(
                "UPDATE payment_intents
                    SET status_check_state = ?, last_status_checked_at = NOW(),
                        status_check_archived_at = NULL, status_check_archive_reason = NULL,
                        gateway_verification_status = 'verified',
                        gateway_verified_at = COALESCE(gateway_verified_at, NOW())
                  WHERE id = ?"
            );
            $update->bind_param('si', $state, $intentId);
        } else {
            if ($countable && $previousState !== 'archived') {
                $attempts = min(3, $attempts + 1);
            }
            $state = $previousState;
            if ($countable) {
                $state = $attempts >= 3 ? 'archived' : 'retrying';
                $archivedNow = $state === 'archived' && $previousState !== 'archived';
            }
            $archiveReason = $state === 'archived'
                ? 'Archived after three transaction-specific Hubtel status-check failures. Last failure: ' . $details
                : null;
            $update = $conn->prepare(
                "UPDATE payment_intents
                    SET status_check_attempts = ?, status_check_state = ?,
                        last_status_checked_at = NOW(),
                        status_check_archived_at = CASE WHEN ? = 'archived' THEN COALESCE(status_check_archived_at, NOW()) ELSE status_check_archived_at END,
                        status_check_archive_reason = CASE WHEN ? = 'archived' THEN ? ELSE status_check_archive_reason END,
                        gateway_verification_status = CASE
                            WHEN gateway_verification_status = 'verified' THEN 'verified'
                            WHEN ? = 1 THEN 'failed'
                            ELSE gateway_verification_status END
                  WHERE id = ?"
            );
            $countableInt = $countable ? 1 : 0;
            $update->bind_param('issssii', $attempts, $state, $state, $state, $archiveReason, $countableInt, $intentId);
        }
        $update->execute();
        $update->close();

        $expectedReference = (string) ($intent['client_reference'] ?? '');
        $observedReference = isset($result['reference']) ? (string) $result['reference'] : null;
        $expectedAmount = isset($intent['amount']) ? (float) $intent['amount'] : null;
        $observedAmount = isset($result['amount']) && is_numeric($result['amount']) ? (float) $result['amount'] : null;
        $httpCode = isset($result['http_code']) ? (int) $result['http_code'] : null;
        $actorId = $actor_user_id && $actor_user_id > 0 ? $actor_user_id : null;
        $countableInt = $countable ? 1 : 0;
        $audit = $conn->prepare(
            'INSERT INTO payment_status_check_audit
                (payment_intent_id, attempt_number, outcome, countable_failure,
                 expected_reference, observed_reference, expected_amount,
                 observed_amount, http_code, details, checked_by_user_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $audit->bind_param(
            'iisissddisi',
            $intentId, $attempts, $outcome, $countableInt,
            $expectedReference, $observedReference, $expectedAmount,
            $observedAmount, $httpCode, $details, $actorId
        );
        $audit->execute();
        $audit->close();

        if ($archivedNow) {
            $archiveOutcome = 'archived';
            $archiveDetails = 'Automatically archived after three counted verification failures.';
            $archiveAudit = $conn->prepare(
                'INSERT INTO payment_status_check_audit
                    (payment_intent_id, attempt_number, outcome, countable_failure,
                     expected_reference, expected_amount, details, checked_by_user_id)
                 VALUES (?, ?, ?, 0, ?, ?, ?, ?)'
            );
            $archiveAudit->bind_param(
                'iissdsi',
                $intentId, $attempts, $archiveOutcome, $expectedReference,
                $expectedAmount, $archiveDetails, $actorId
            );
            $archiveAudit->execute();
            $archiveAudit->close();
        }

        $conn->commit();
        return [
            'status_check_attempts' => $attempts,
            'status_check_state' => $state,
            'status_check_archived' => $state === 'archived',
            'countable_failure' => $countable,
            'status_check_outcome' => $outcome,
        ];
    } catch (Throwable $error) {
        $conn->rollback();
        throw $error;
    }
}

/** Restore an archived intent for a fresh, auditable three-check cycle. */
function restore_archived_hubtel_status_check(mysqli $conn, int $intent_id, ?int $actor_user_id = null): void {
    $conn->begin_transaction();
    try {
        $lock = $conn->prepare(
            "SELECT id, client_reference, amount, status_check_attempts
               FROM payment_intents
              WHERE id = ? AND status_check_state = 'archived' FOR UPDATE"
        );
        $lock->bind_param('i', $intent_id);
        $lock->execute();
        $intent = $lock->get_result()->fetch_assoc();
        $lock->close();
        if (!$intent) {
            throw new RuntimeException('This status-check item is not archived.');
        }

        $update = $conn->prepare(
            "UPDATE payment_intents
                SET status_check_attempts = 0, status_check_state = 'unchecked',
                    status_check_archived_at = NULL, status_check_archive_reason = NULL,
                    gateway_verification_status = CASE
                        WHEN gateway_verification_status = 'verified' THEN 'verified'
                        ELSE 'not_checked' END
              WHERE id = ?"
        );
        $update->bind_param('i', $intent_id);
        $update->execute();
        $update->close();

        $outcome = 'restored';
        $details = 'Restored by an authorized user for a fresh status-check cycle.';
        $actorId = $actor_user_id && $actor_user_id > 0 ? $actor_user_id : null;
        $attemptNumber = (int) $intent['status_check_attempts'];
        $expectedReference = (string) $intent['client_reference'];
        $expectedAmount = (float) $intent['amount'];
        $audit = $conn->prepare(
            'INSERT INTO payment_status_check_audit
                (payment_intent_id, attempt_number, outcome, countable_failure,
                 expected_reference, expected_amount, details, checked_by_user_id)
             VALUES (?, ?, ?, 0, ?, ?, ?, ?)'
        );
        $audit->bind_param(
            'iissdsi',
            $intent_id, $attemptNumber, $outcome, $expectedReference,
            $expectedAmount, $details, $actorId
        );
        $audit->execute();
        $audit->close();
        $conn->commit();
    } catch (Throwable $error) {
        $conn->rollback();
        throw $error;
    }
}

/**
 * Check transaction status by client reference
 * @param object $conn Database connection
 * @param string $client_reference The client reference from payment intent
 * @param string $transaction_id Optional Hubtel transaction ID
 * @return array Status check result
 */
function check_transaction_by_reference($conn, $client_reference, $transaction_id = null, ?int $actor_user_id = null) {
    // First get the payment intent
    $stmt = $conn->prepare("SELECT * FROM payment_intents WHERE client_reference = ?");
    $stmt->bind_param('s', $client_reference);
    $stmt->execute();
    $intent = $stmt->get_result()->fetch_assoc();
    
    if (!$intent) {
        return [
            'success' => false,
            'error' => 'Payment intent not found',
            'client_reference' => $client_reference
        ];
    }
    if (($intent['status_check_state'] ?? 'unchecked') === 'archived') {
        return [
            'success' => false,
            'error' => 'This transaction was archived after three failed checks. Restore it before checking again.',
            'current_status' => $intent['status'],
            'status_check_attempts' => (int) $intent['status_check_attempts'],
            'status_check_state' => 'archived',
            'status_check_archived' => true,
            'method' => 'database_only',
        ];
    }
    
    // If no transaction ID provided, try to extract from stored data or use client reference
    if (!$transaction_id) {
        // Check if we have stored transaction ID in the intent (if column exists)
        $transaction_id = isset($intent['hubtel_transaction_id']) ? $intent['hubtel_transaction_id'] : null;
        
        // If still no transaction ID, use client_reference as fallback
        // This allows status checking for older payment intents before the migration
        if (!$transaction_id) {
            $transaction_id = $client_reference;
            
            // Log that we're using fallback method
            error_log("Hubtel Status Check: Using client_reference as transaction_id fallback for {$client_reference}");
        }
    }
    
    // Try the Hubtel API if we have a transaction ID
    if ($transaction_id) {
        $status_result = verify_hubtel_transaction_status(
            $transaction_id,
            $client_reference,
            (float) ($intent['amount'] ?? 0)
        );
        
        if (!empty($status_result['verified'])) {
            // Update local status if different
            $hubtel_status = $status_result['status'];
            $local_status = match (strtolower($hubtel_status)) {
                'success', 'completed', 'successful', 'paid' => 'Completed',
                'failed', 'cancelled', 'canceled', 'declined', 'error' => 'Failed',
                'pending', 'processing', 'initiated', 'unknown' => 'Pending',
                default => 'Pending',
            };
            
            // Log status mapping for debugging
            file_put_contents(__DIR__.'/../logs/hubtel_debug.log', date('c') . " - Status Mapping: '{$hubtel_status}' -> '{$local_status}' (was: '{$intent['status']}')\n", FILE_APPEND);
            
            // Reuse the canonical capture path so a success discovered during
            // reconciliation is auditable and enters the approval queue. A
            // status check must never write directly to the payments ledger.
            $captureService = new PaymentGatewayCallbackService($conn);
            $capture = $captureService->record([
                'client_reference' => $client_reference,
                'transaction_id' => $status_result['transaction_id'] ?? $transaction_id,
                'status' => $local_status,
                'amount' => $status_result['amount'] ?? $intent['amount'],
                'description' => $intent['description'] ?? 'Hubtel payment',
                'customer_name' => $intent['customer_name'] ?? 'Hubtel customer',
                'customer_phone' => $intent['customer_phone'] ?? '',
                'member_id' => $intent['member_id'] ?? null,
                'sundayschool_id' => $intent['sundayschool_id'] ?? null,
                'church_id' => $intent['church_id'] ?? null,
                'payment_type_id' => $intent['payment_type_id'] ?? null,
                'payment_period' => $intent['payment_period'] ?? null,
                'payment_period_description' => $intent['payment_period_description'] ?? null,
                'bulk_breakdown' => $intent['bulk_breakdown'] ?? null,
                'payment_source' => $intent['payment_source'] ?? 'legacy_callback',
                'confirmation_source' => 'status_check',
                'gateway_verification_status' => 'verified',
                'raw_payload' => json_encode($status_result['data'] ?? $status_result),
            ]);

            $lifecycle = record_hubtel_status_check_result($conn, $intent, $status_result, $actor_user_id);

            return array_merge([
                'success' => true,
                'status_updated' => $local_status !== $intent['status'],
                'old_status' => $intent['status'],
                'new_status' => $capture['status'],
                'current_status' => $capture['status'],
                'approval_status' => $capture['approval_status'],
                'confirmation_source' => $capture['confirmation_source'],
                'gateway_verification_status' => $capture['gateway_verification_status'],
                'hubtel_data' => $status_result['data'],
                'transaction_id' => $transaction_id,
                'method' => 'hubtel_api'
            ], $lifecycle);
        }
    }
    
    // If API fails or we're using client_reference as transaction_id, 
    // return current status from database (webhook-based system)
    $apiError = isset($status_result)
        ? ($status_result['error'] ?? 'Gateway response was not verified.')
        : 'No Hubtel transaction identifier was available for verification.';
    $lifecycle = record_hubtel_status_check_result(
        $conn,
        $intent,
        $status_result ?? [
            'verified' => false,
            'failure_kind' => 'gateway_error',
            'countable_failure' => false,
            'error' => $apiError,
        ],
        $actor_user_id
    );
    return array_merge([
        'success' => false,
        'error' => $apiError,
        'status_updated' => false,
        'current_status' => $intent['status'],
        'note' => 'The displayed status is local only; Hubtel did not verify it.',
        'transaction_id' => $transaction_id,
        'method' => 'database_only',
        'verified' => false,
        'verification_status' => $status_result['verification_status'] ?? 'not_checked',
        'api_error' => $apiError,
    ], $lifecycle);
}

/**
 * Bulk check status for recent pending or failed Hubtel payment intents.
 * @param object $conn Database connection
 * @param int $limit Maximum number of records to check
 * @return array Results of bulk status check
 */
function bulk_check_pending_payments($conn, $limit = 50, ?int $actor_user_id = null) {
    $stmt = $conn->prepare(
        "SELECT client_reference, created_at
           FROM payment_intents
          WHERE status IN ('Pending', 'Failed')
             AND approval_status = 'not_required'
             AND payment_source IN ('ussd', 'online_checkout', 'legacy_callback')
             AND status_check_state IN ('unchecked', 'retrying')
          ORDER BY created_at DESC LIMIT ?"
    );
    $stmt->bind_param('i', $limit);
    $stmt->execute();
    $pending_intents = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    
    $results = [
        'total_checked' => 0,
        'updated_count' => 0,
        'failed_count' => 0,
        'archived_count' => 0,
        'details' => []
    ];
    
    foreach ($pending_intents as $intent) {
        $results['total_checked']++;
        $check_result = check_transaction_by_reference($conn, $intent['client_reference'], null, $actor_user_id);
        
        if ($check_result['success']) {
            if ($check_result['status_updated'] ?? false) {
                $results['updated_count']++;
            }
        } else {
            $results['failed_count']++;
        }
        if (!empty($check_result['status_check_archived'])) {
            $results['archived_count']++;
        }
        
        $results['details'][] = [
            'client_reference' => $intent['client_reference'],
            'created_at' => $intent['created_at'],
            'result' => $check_result
        ];
    }
    
    return $results;
}
