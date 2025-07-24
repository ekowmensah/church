<?php
/**
 * Helper function to get the current user's church_id
 * Handles fallback scenarios gracefully to prevent foreign key constraint errors
 */
function get_user_church_id($conn) {
    // Try to get church_id from session first
    $church_id = $_SESSION['church_id'] ?? null;
    
    // If not in session, try to get from user's member record
    if (!$church_id && isset($_SESSION['user_id'])) {
        $stmt = $conn->prepare('SELECT church_id FROM members WHERE id = (SELECT member_id FROM users WHERE id = ?)');
        $stmt->bind_param('i', $_SESSION['user_id']);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        $church_id = $result['church_id'] ?? null;
        $stmt->close();
    }
    
    // If still no church_id, get the first available church as fallback
    if (!$church_id) {
        $stmt = $conn->prepare('SELECT id FROM churches ORDER BY id LIMIT 1');
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        $church_id = $result['id'] ?? null;
        $stmt->close();
    }
    
    return $church_id;
}

/**
 * Validate that a church_id exists in the database
 */
function validate_church_id($conn, $church_id) {
    if (!$church_id) {
        return false;
    }
    
    $stmt = $conn->prepare('SELECT id FROM churches WHERE id = ?');
    $stmt->bind_param('i', $church_id);
    $stmt->execute();
    $exists = $stmt->get_result()->num_rows > 0;
    $stmt->close();
    
    return $exists;
}
?>
