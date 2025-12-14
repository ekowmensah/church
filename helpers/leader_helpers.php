<?php
/**
 * Leader Helper Functions
 * Provides utilities for checking leader status and managing leader-specific operations
 */

/**
 * Check if the current logged-in user/member is a Bible class leader
 * @param mysqli $conn Database connection
 * @param int $user_id User ID (optional)
 * @param int $member_id Member ID (optional)
 * @return array|false Returns array with class info if leader, false otherwise
 */
function is_bible_class_leader($conn, $user_id = null, $member_id = null) {
    if (!$user_id && !$member_id) {
        if (isset($_SESSION['user_id'])) {
            $user_id = $_SESSION['user_id'];
        } elseif (isset($_SESSION['member_id'])) {
            $member_id = $_SESSION['member_id'];
        } else {
            return false;
        }
    }
    
    // Check if user is a leader via user_id
    if ($user_id) {
        $stmt = $conn->prepare("
            SELECT bcl.class_id, bc.name as class_name, bc.code, bc.church_id
            FROM bible_class_leaders bcl
            INNER JOIN bible_classes bc ON bcl.class_id = bc.id
            WHERE bcl.user_id = ? AND bcl.status = 'active'
            LIMIT 1
        ");
        $stmt->bind_param('i', $user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result->num_rows > 0) {
            $data = $result->fetch_assoc();
            $stmt->close();
            return $data;
        }
        $stmt->close();
    }
    
    // Check if member is a leader via member_id
    if ($member_id) {
        $stmt = $conn->prepare("
            SELECT bcl.class_id, bc.name as class_name, bc.code, bc.church_id
            FROM bible_class_leaders bcl
            INNER JOIN bible_classes bc ON bcl.class_id = bc.id
            WHERE bcl.member_id = ? AND bcl.status = 'active'
            LIMIT 1
        ");
        $stmt->bind_param('i', $member_id);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result->num_rows > 0) {
            $data = $result->fetch_assoc();
            $stmt->close();
            return $data;
        }
        $stmt->close();
    }
    
    return false;
}

/**
 * Check if the current logged-in user/member is an organization leader
 * @param mysqli $conn Database connection
 * @param int $user_id User ID (optional)
 * @param int $member_id Member ID (optional)
 * @return array|false Returns array of ALL organization leaderships, or false if not a leader
 */
function is_organization_leader($conn, $user_id = null, $member_id = null) {
    if (!$user_id && !$member_id) {
        if (isset($_SESSION['user_id'])) {
            $user_id = $_SESSION['user_id'];
        } elseif (isset($_SESSION['member_id'])) {
            $member_id = $_SESSION['member_id'];
        } else {
            return false;
        }
    }
    
    $organizations = [];
    
    // Check if user is a leader via user_id
    if ($user_id) {
        $stmt = $conn->prepare("
            SELECT ol.organization_id, o.name as org_name, o.description, o.church_id
            FROM organization_leaders ol
            INNER JOIN organizations o ON ol.organization_id = o.id
            WHERE ol.user_id = ? AND ol.status = 'active'
            ORDER BY o.name ASC
        ");
        $stmt->bind_param('i', $user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $organizations[] = $row;
        }
        $stmt->close();
    }
    
    // Check if member is a leader via member_id
    if ($member_id && empty($organizations)) {
        $stmt = $conn->prepare("
            SELECT ol.organization_id, o.name as org_name, o.description, o.church_id
            FROM organization_leaders ol
            INNER JOIN organizations o ON ol.organization_id = o.id
            WHERE ol.member_id = ? AND ol.status = 'active'
            ORDER BY o.name ASC
        ");
        $stmt->bind_param('i', $member_id);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $organizations[] = $row;
        }
        $stmt->close();
    }
    
    return !empty($organizations) ? $organizations : false;
}

/**
 * Get all Bible class leaders (for a specific class or all)
 * @param mysqli $conn Database connection
 * @param int $class_id Optional class ID to filter
 * @return array Array of leaders
 */
function get_bible_class_leaders($conn, $class_id = null) {
    $sql = "
        SELECT bcl.*, bc.name as class_name, bc.code,
               u.name as user_name, u.email as user_email,
               CONCAT(m.first_name, ' ', m.last_name) as member_name, m.email as member_email
        FROM bible_class_leaders bcl
        INNER JOIN bible_classes bc ON bcl.class_id = bc.id
        LEFT JOIN users u ON bcl.user_id = u.id
        LEFT JOIN members m ON bcl.member_id = m.id
        WHERE bcl.status = 'active'
    ";
    
    if ($class_id) {
        $sql .= " AND bcl.class_id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('i', $class_id);
    } else {
        $stmt = $conn->prepare($sql);
    }
    
    $stmt->execute();
    $result = $stmt->get_result();
    $leaders = [];
    while ($row = $result->fetch_assoc()) {
        $leaders[] = $row;
    }
    $stmt->close();
    return $leaders;
}

/**
 * Get all organization leaders (for a specific organization or all)
 * @param mysqli $conn Database connection
 * @param int $org_id Optional organization ID to filter
 * @return array Array of leaders
 */
function get_organization_leaders($conn, $org_id = null) {
    $sql = "
        SELECT ol.*, o.name as org_name, o.description,
               u.name as user_name, u.email as user_email,
               CONCAT(m.first_name, ' ', m.last_name) as member_name, m.email as member_email
        FROM organization_leaders ol
        INNER JOIN organizations o ON ol.organization_id = o.id
        LEFT JOIN users u ON ol.user_id = u.id
        LEFT JOIN members m ON ol.member_id = m.id
        WHERE ol.status = 'active'
    ";
    
    if ($org_id) {
        $sql .= " AND ol.organization_id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('i', $org_id);
    } else {
        $stmt = $conn->prepare($sql);
    }
    
    $stmt->execute();
    $result = $stmt->get_result();
    $leaders = [];
    while ($row = $result->fetch_assoc()) {
        $leaders[] = $row;
    }
    $stmt->close();
    return $leaders;
}

/**
 * Get members of a Bible class
 * @param mysqli $conn Database connection
 * @param int $class_id Bible class ID
 * @return array Array of members
 */
function get_bible_class_members($conn, $class_id) {
    $stmt = $conn->prepare("
        SELECT m.*, bc.name as class_name, c.name as church_name
        FROM members m
        LEFT JOIN bible_classes bc ON m.class_id = bc.id
        LEFT JOIN churches c ON m.church_id = c.id
        WHERE m.class_id = ?
        ORDER BY m.last_name, m.first_name
    ");
    $stmt->bind_param('i', $class_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $members = [];
    while ($row = $result->fetch_assoc()) {
        $members[] = $row;
    }
    $stmt->close();
    return $members;
}

/**
 * Get members of an organization
 * @param mysqli $conn Database connection
 * @param int $org_id Organization ID
 * @return array Array of members
 */
function get_organization_members($conn, $org_id) {
    $stmt = $conn->prepare("
        SELECT m.*, o.name as org_name, c.name as church_name
        FROM members m
        INNER JOIN member_organizations mo ON m.id = mo.member_id
        LEFT JOIN organizations o ON mo.organization_id = o.id
        LEFT JOIN churches c ON m.church_id = c.id
        WHERE mo.organization_id = ?
        ORDER BY m.last_name, m.first_name
    ");
    $stmt->bind_param('i', $org_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $members = [];
    while ($row = $result->fetch_assoc()) {
        $members[] = $row;
    }
    $stmt->close();
    return $members;
}

/**
 * Get payment statistics for Bible class members
 * @param mysqli $conn Database connection
 * @param int $class_id Bible class ID
 * @param string $start_date Optional start date
 * @param string $end_date Optional end date
 * @return array Payment statistics
 */
function get_bible_class_payment_stats($conn, $class_id, $start_date = null, $end_date = null) {
    $sql = "
        SELECT 
            COUNT(p.id) as total_payments,
            SUM(p.amount) as total_amount,
            COUNT(DISTINCT p.member_id) as unique_payers,
            AVG(p.amount) as avg_payment
        FROM payments p
        INNER JOIN members m ON p.member_id = m.id
        WHERE m.class_id = ?
    ";
    
    $params = [$class_id];
    $types = 'i';
    
    if ($start_date) {
        $sql .= " AND p.payment_date >= ?";
        $params[] = $start_date;
        $types .= 's';
    }
    if ($end_date) {
        $sql .= " AND p.payment_date <= ?";
        $params[] = $end_date;
        $types .= 's';
    }
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
    $stats = $result->fetch_assoc();
    $stmt->close();
    return $stats;
}

/**
 * Get payment statistics for organization members
 * @param mysqli $conn Database connection
 * @param int $org_id Organization ID
 * @param string $start_date Optional start date
 * @param string $end_date Optional end date
 * @return array Payment statistics
 */
function get_organization_payment_stats($conn, $org_id, $start_date = null, $end_date = null) {
    $sql = "
        SELECT 
            COUNT(p.id) as total_payments,
            SUM(p.amount) as total_amount,
            COUNT(DISTINCT p.member_id) as unique_payers,
            AVG(p.amount) as avg_payment
        FROM payments p
        INNER JOIN member_organizations mo ON p.member_id = mo.member_id
        WHERE mo.organization_id = ?
    ";
    
    $params = [$org_id];
    $types = 'i';
    
    if ($start_date) {
        $sql .= " AND p.payment_date >= ?";
        $params[] = $start_date;
        $types .= 's';
    }
    if ($end_date) {
        $sql .= " AND p.payment_date <= ?";
        $params[] = $end_date;
        $types .= 's';
    }
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
    $stats = $result->fetch_assoc();
    $stmt->close();
    return $stats;
}

/**
 * Get attendance statistics for Bible class
 * @param mysqli $conn Database connection
 * @param int $class_id Bible class ID
 * @param string $start_date Optional start date
 * @param string $end_date Optional end date
 * @return array Attendance statistics
 */
function get_bible_class_attendance_stats($conn, $class_id, $start_date = null, $end_date = null) {
    $sql = "
        SELECT 
            COUNT(ar.id) as total_records,
            SUM(CASE WHEN ar.status = 'present' THEN 1 ELSE 0 END) as total_present,
            SUM(CASE WHEN ar.status = 'absent' THEN 1 ELSE 0 END) as total_absent,
            COUNT(DISTINCT ar.member_id) as unique_attendees,
            COUNT(DISTINCT ar.session_id) as total_sessions
        FROM attendance_records ar
        INNER JOIN members m ON ar.member_id = m.id
        INNER JOIN attendance_sessions ats ON ar.session_id = ats.id
        WHERE m.class_id = ?
    ";
    
    $params = [$class_id];
    $types = 'i';
    
    if ($start_date) {
        $sql .= " AND ats.service_date >= ?";
        $params[] = $start_date;
        $types .= 's';
    }
    if ($end_date) {
        $sql .= " AND ats.service_date <= ?";
        $params[] = $end_date;
        $types .= 's';
    }
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
    $stats = $result->fetch_assoc();
    $stmt->close();
    
    // Calculate attendance rate
    if ($stats['total_records'] > 0) {
        $stats['attendance_rate'] = round(($stats['total_present'] / $stats['total_records']) * 100, 1);
    } else {
        $stats['attendance_rate'] = 0;
    }
    
    return $stats;
}

/**
 * Get attendance statistics for organization
 * @param mysqli $conn Database connection
 * @param int $org_id Organization ID
 * @param string $start_date Optional start date
 * @param string $end_date Optional end date
 * @return array Attendance statistics
 */
function get_organization_attendance_stats($conn, $org_id, $start_date = null, $end_date = null) {
    $sql = "
        SELECT 
            COUNT(ar.id) as total_records,
            SUM(CASE WHEN ar.status = 'present' THEN 1 ELSE 0 END) as total_present,
            SUM(CASE WHEN ar.status = 'absent' THEN 1 ELSE 0 END) as total_absent,
            COUNT(DISTINCT ar.member_id) as unique_attendees,
            COUNT(DISTINCT ar.session_id) as total_sessions
        FROM attendance_records ar
        INNER JOIN member_organizations mo ON ar.member_id = mo.member_id
        INNER JOIN attendance_sessions ats ON ar.session_id = ats.id
        WHERE mo.organization_id = ?
    ";
    
    $params = [$org_id];
    $types = 'i';
    
    if ($start_date) {
        $sql .= " AND ats.service_date >= ?";
        $params[] = $start_date;
        $types .= 's';
    }
    if ($end_date) {
        $sql .= " AND ats.service_date <= ?";
        $params[] = $end_date;
        $types .= 's';
    }
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
    $stats = $result->fetch_assoc();
    $stmt->close();
    
    // Calculate attendance rate
    if ($stats['total_records'] > 0) {
        $stats['attendance_rate'] = round(($stats['total_present'] / $stats['total_records']) * 100, 1);
    } else {
        $stats['attendance_rate'] = 0;
    }
    
    return $stats;
}
