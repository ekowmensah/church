<?php
header('Content-Type: application/json');
require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../helpers/auth.php';
require_once __DIR__.'/../helpers/permissions.php';

// Only allow logged-in users with proper permissions
if (!is_logged_in()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$is_super_admin = (isset($_SESSION['user_id']) && $_SESSION['user_id'] == 3) || 
                  (isset($_SESSION['role_id']) && $_SESSION['role_id'] == 1);

if (!$is_super_admin && !has_permission('create_member')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Insufficient permissions']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!$input || !isset($input['member_ids']) || !isset($input['defaults'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid request data']);
    exit;
}

$member_ids = $input['member_ids'];
$defaults = $input['defaults'];

if (empty($member_ids) || !is_array($member_ids)) {
    echo json_encode(['success' => false, 'message' => 'No members selected']);
    exit;
}

// Validate member IDs
$member_ids = array_map('intval', $member_ids);
$member_ids = array_filter($member_ids, function($id) { return $id > 0; });

if (empty($member_ids)) {
    echo json_encode(['success' => false, 'message' => 'Invalid member IDs']);
    exit;
}

$conn->begin_transaction();

try {
    $success_count = 0;
    $error_count = 0;
    $errors = [];
    
    // Get pending members
    $placeholders = str_repeat('?,', count($member_ids) - 1) . '?';
    $stmt = $conn->prepare("SELECT id, first_name, last_name, phone, crn FROM members WHERE id IN ($placeholders) AND status = 'pending'");
    $stmt->bind_param(str_repeat('i', count($member_ids)), ...$member_ids);
    $stmt->execute();
    $pending_members = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    
    if (empty($pending_members)) {
        throw new Exception('No pending members found with the selected IDs');
    }
    
    foreach ($pending_members as $member) {
        try {
            // Generate default password if not provided
            $password = $defaults['password'] ?? '123456';
            $password_hash = password_hash($password, PASSWORD_DEFAULT);
            
            // Prepare update data with defaults
            $update_data = [
                'gender' => $defaults['gender'] ?? 'Male',
                'dob' => $defaults['dob'] ?? '1990-01-01',
                'day_born' => $defaults['dob'] ? date('l', strtotime($defaults['dob'])) : 'Monday',
                'place_of_birth' => $defaults['place_of_birth'] ?? 'Ghana',
                'address' => $defaults['address'] ?? '',
                'gps_address' => $defaults['gps_address'] ?? '',
                'marital_status' => $defaults['marital_status'] ?? 'Single',
                'home_town' => $defaults['home_town'] ?? '',
                'region' => $defaults['region'] ?? 'Greater Accra',
                'telephone' => $defaults['telephone'] ?? '',
                'employment_status' => $defaults['employment_status'] ?? 'Formal',
                'profession' => $defaults['profession'] ?? '',
                'baptized' => $defaults['baptized'] ?? 'Yes',
                'confirmed' => $defaults['confirmed'] ?? 'Yes',
                'date_of_baptism' => $defaults['date_of_baptism'] ?? null,
                'date_of_confirmation' => $defaults['date_of_confirmation'] ?? null,
                'membership_status' => $defaults['membership_status'] ?? 'Full Member',
                'date_of_enrollment' => $defaults['date_of_enrollment'] ?? date('Y-m-d'),
                'password_hash' => $password_hash,
                'status' => 'active',
                'registration_token' => null
            ];
            
            // Build update query
            $update_fields = [];
            $update_values = [];
            $types = '';
            
            foreach ($update_data as $field => $value) {
                if ($value !== null && $value !== '') {
                    $update_fields[] = "$field = ?";
                    $update_values[] = $value;
                    $types .= 's';
                }
            }
            
            if (!empty($update_fields)) {
                $update_sql = "UPDATE members SET " . implode(', ', $update_fields) . " WHERE id = ?";
                $update_values[] = $member['id'];
                $types .= 'i';
                
                $update_stmt = $conn->prepare($update_sql);
                $update_stmt->bind_param($types, ...$update_values);
                
                if ($update_stmt->execute()) {
                    $success_count++;
                    
                    // Send SMS notification if phone number exists
                    if (!empty($member['phone'])) {
                        try {
                            $sms_message = "Dear {$member['first_name']}, your registration is complete. CRN: {$member['crn']}, Password: $password";
                            
                            // Include SMS sending logic here if available
                            // For now, just log the SMS attempt
                            error_log("SMS would be sent to {$member['phone']}: $sms_message");
                            
                        } catch (Exception $sms_error) {
                            error_log("SMS sending failed for member {$member['id']}: " . $sms_error->getMessage());
                        }
                    }
                } else {
                    $error_count++;
                    $errors[] = "Failed to update member {$member['crn']}: " . $update_stmt->error;
                }
                
                $update_stmt->close();
            } else {
                $error_count++;
                $errors[] = "No valid data to update for member {$member['crn']}";
            }
            
        } catch (Exception $e) {
            $error_count++;
            $errors[] = "Error processing member {$member['crn']}: " . $e->getMessage();
        }
    }
    
    $conn->commit();
    
    $message = "Bulk registration completed: $success_count successful";
    if ($error_count > 0) {
        $message .= ", $error_count failed";
    }
    
    echo json_encode([
        'success' => true,
        'message' => $message,
        'details' => [
            'success_count' => $success_count,
            'error_count' => $error_count,
            'errors' => $errors
        ]
    ]);
    
} catch (Exception $e) {
    $conn->rollback();
    error_log("Bulk registration error: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'Bulk registration failed: ' . $e->getMessage()
    ]);
}
?>
