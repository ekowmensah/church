<?php
// helpers/attendance.php
// Helper functions for attendance management

require_once __DIR__ . '/../config/database.php';

/**
 * Get or create an attendance session for a given timestamp
 * This function maps Hikvision attendance logs to appropriate church sessions
 */
function get_or_create_attendance_session($timestamp, $conn) {
    $date = date('Y-m-d', strtotime($timestamp));
    $day_of_week = date('w', strtotime($timestamp)); // 0=Sunday, 1=Monday, etc.
    
    // First, try to find an existing session for this date
    $stmt = $conn->prepare('SELECT id FROM attendance_sessions WHERE service_date = ? LIMIT 1');
    $stmt->bind_param('s', $date);
    $stmt->execute();
    $stmt->bind_result($session_id);
    if ($stmt->fetch()) {
        $stmt->close();
        return $session_id;
    }
    $stmt->close();
    
    // No existing session, create a default one based on day of week
    $session_title = get_default_session_title($day_of_week);
    
    // Get default church_id (you may want to make this configurable)
    $church_stmt = $conn->prepare('SELECT id FROM churches LIMIT 1');
    $church_stmt->execute();
    $church_stmt->bind_result($church_id);
    if (!$church_stmt->fetch()) {
        $church_id = null; // No churches found
    }
    $church_stmt->close();
    
    // Create new session
    $insert_stmt = $conn->prepare('INSERT INTO attendance_sessions (church_id, title, service_date) VALUES (?, ?, ?)');
    $insert_stmt->bind_param('iss', $church_id, $session_title, $date);
    $insert_stmt->execute();
    $new_session_id = $insert_stmt->insert_id;
    $insert_stmt->close();
    
    return $new_session_id;
}

/**
 * Get default session title based on day of week
 */
function get_default_session_title($day_of_week) {
    switch ($day_of_week) {
        case 0: // Sunday
            return 'Sunday Service';
        case 3: // Wednesday
            return 'Midweek Service';
        case 5: // Friday
            return 'Friday Service';
        default:
            return 'General Service';
    }
}

/**
 * Get attendance statistics for a session
 */
function get_session_attendance_stats($session_id, $conn) {
    $stmt = $conn->prepare('
        SELECT 
            COUNT(*) as total_records,
            SUM(CASE WHEN status = "present" THEN 1 ELSE 0 END) as present_count,
            SUM(CASE WHEN status = "absent" THEN 1 ELSE 0 END) as absent_count
        FROM attendance_records 
        WHERE session_id = ?
    ');
    $stmt->bind_param('i', $session_id);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    return $result;
}
