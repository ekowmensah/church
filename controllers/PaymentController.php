<?php
require_once __DIR__ . '/../models/Payment.php';
require_once __DIR__ . '/../config/config.php';

class PaymentController {
    private $conn;
    public function __construct($conn) {
        $this->conn = $conn;
    }
    public function create($data) {
        // 1. Create payment using Payment model
        require_once __DIR__.'/../models/Payment.php';
        $paymentModel = new Payment();
        $payment_date = $data['payment_date'] ?? date('Y-m-d');
        $insertData = [
            'member_id' => $data['member_id'],
            'amount' => $data['amount'],
            'type' => $data['type'],
            'payment_date' => $payment_date
        ];
        $paymentId = $paymentModel->add($this->conn, $insertData);
        if (!$paymentId) return false;
        $memberId = $data['member_id'] ?? null;
        if (!$memberId) return false;

        // 2. Fetch member details (updated balance)
        require_once __DIR__.'/../models/Member.php';
        $memberModel = new Member($this->conn);
        $member = $memberModel->findById($memberId);
        if (!$member || empty($member['phone'])) return false;
        // Do not send if member opted out
        if (isset($member['sms_notifications_enabled']) && !$member['sms_notifications_enabled']) {
            return $paymentId;
        }
        // Idempotency: check if SMS already sent for this payment
        $stmtCheck = $this->conn->prepare('SELECT id FROM sms_logs WHERE payment_id = ? AND type = ? LIMIT 1');
        $type = 'payment';
        $stmtCheck->bind_param('is', $paymentId, $type);
        $stmtCheck->execute();
        $stmtCheck->store_result();
        if ($stmtCheck->num_rows > 0) {
            return $paymentId; // Already sent
        }
        // 3. Prepare SMS
        require_once __DIR__.'/../includes/sms.php';
        require_once __DIR__.'/../includes/sms_templates.php';
        $tpl = get_sms_template('payment_received', $this->conn);
        if ($tpl) {
            $msg = fill_sms_template($tpl['body'], [
                'first_name' => $member['first_name'],
                'amount' => $data['amount'] ?? '',
                'date' => $payment_date,
                'payment_type' => $data['type'] ?? '',
                'balance' => $member['balance'] ?? ''
            ]);
            send_sms($member['phone'], $msg);
            log_sms($member['phone'], $msg, $paymentId, 'payment');
        }
        return $paymentId;
    }
    public function read($id) {}
    public function update($id, $data) {
        // Implement update logic
        $result = null; // your existing update logic here
        require_once __DIR__.'/../helpers/global_audit_log.php';
        log_activity('update', 'payment', $id, json_encode($data));
        return $result;
    }
    public function delete($id) {
        // Implement delete logic
        $result = null; // your existing delete logic here
        require_once __DIR__.'/../helpers/global_audit_log.php';
        log_activity('delete', 'payment', $id);
        return $result;
    }
    public function list() {
        // Implement list all logic
        $result = null; // your existing list logic here
        require_once __DIR__.'/../helpers/global_audit_log.php';
        log_activity('list', 'payment');
        return $result;
    }
}
?>
