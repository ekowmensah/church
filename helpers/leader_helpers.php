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
          AND (ats.service_date IS NULL OR ats.service_date <> '0000-00-00')
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
          AND (ats.service_date IS NULL OR ats.service_date <> '0000-00-00')
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

/**
 * Check whether member_organizations requires a manual id value.
 * Supports legacy schemas where the id column is not auto-incrementing.
 *
 * @param mysqli $conn Database connection
 * @return bool
 */
function member_organizations_requires_explicit_id($conn) {
    static $requiresExplicitId = null;
    if ($requiresExplicitId !== null) {
        return $requiresExplicitId;
    }

    $requiresExplicitId = false;
    $result = $conn->query("SHOW COLUMNS FROM member_organizations LIKE 'id'");
    if ($result && ($row = $result->fetch_assoc())) {
        $extra = strtolower((string) ($row['Extra'] ?? ''));
        $nullAllowed = strtoupper((string) ($row['Null'] ?? 'YES')) === 'YES';
        $defaultValue = $row['Default'] ?? null;
        $requiresExplicitId = strpos($extra, 'auto_increment') === false && !$nullAllowed && $defaultValue === null;
        $result->free();
    }

    return $requiresExplicitId;
}

/**
 * Get the next manual id for member_organizations in legacy schemas.
 *
 * @param mysqli $conn Database connection
 * @return int
 * @throws Exception
 */
function get_next_member_organization_id($conn) {
    $result = $conn->query('SELECT COALESCE(MAX(id), 0) + 1 AS next_id FROM member_organizations');
    if (!$result) {
        throw new Exception($conn->error ?: 'Failed to determine the next member organization id.');
    }

    $row = $result->fetch_assoc();
    $result->free();

    return max(1, (int) ($row['next_id'] ?? 1));
}

/**
 * Add a member to an organization, handling both current and legacy schemas.
 *
 * @param mysqli $conn Database connection
 * @param int $member_id Member ID
 * @param int $org_id Organization ID
 * @throws Exception
 */
function add_member_to_organization($conn, $member_id, $org_id) {
    if (member_organizations_requires_explicit_id($conn)) {
        $nextId = get_next_member_organization_id($conn);
        $stmt = $conn->prepare('INSERT INTO member_organizations (id, member_id, organization_id) VALUES (?, ?, ?)');
        if (!$stmt) {
            throw new Exception($conn->error ?: 'Failed to prepare membership insert.');
        }
        $stmt->bind_param('iii', $nextId, $member_id, $org_id);
    } else {
        $stmt = $conn->prepare('INSERT INTO member_organizations (member_id, organization_id) VALUES (?, ?)');
        if (!$stmt) {
            throw new Exception($conn->error ?: 'Failed to prepare membership insert.');
        }
        $stmt->bind_param('ii', $member_id, $org_id);
    }

    if (!$stmt->execute()) {
        $error = $stmt->error ?: 'Failed to add member to organization.';
        $stmt->close();
        throw new Exception($error);
    }

    $stmt->close();
}

/**
 * Check whether organization_leaders supports the member_id column.
 *
 * @param mysqli $conn Database connection
 * @return bool
 */
function organization_leaders_has_member_id($conn) {
    static $hasMemberId = null;
    if ($hasMemberId !== null) {
        return $hasMemberId;
    }

    $hasMemberId = false;
    $result = $conn->query("SHOW COLUMNS FROM organization_leaders LIKE 'member_id'");
    if ($result) {
        $hasMemberId = $result->num_rows > 0;
        $result->free();
    }

    return $hasMemberId;
}

/**
 * Check whether a member is the active leader of an organization.
 *
 * @param mysqli $conn Database connection
 * @param int $org_id Organization ID
 * @param int $member_id Member ID
 * @return bool
 */
function is_active_organization_leader_member($conn, $org_id, $member_id) {
    if (organization_leaders_has_member_id($conn)) {
        $stmt = $conn->prepare("
            SELECT 1
            FROM organization_leaders ol
            LEFT JOIN users u ON ol.user_id = u.id
            WHERE ol.organization_id = ?
              AND ol.status = 'active'
              AND (ol.member_id = ? OR u.member_id = ?)
            LIMIT 1
        ");
        $stmt->bind_param('iii', $org_id, $member_id, $member_id);
    } else {
        $stmt = $conn->prepare("
            SELECT 1
            FROM organization_leaders ol
            LEFT JOIN users u ON ol.user_id = u.id
            WHERE ol.organization_id = ?
              AND ol.status = 'active'
              AND u.member_id = ?
            LIMIT 1
        ");
        $stmt->bind_param('ii', $org_id, $member_id);
    }

    $stmt->execute();
    $result = $stmt->get_result();
    $isLeader = $result->num_rows > 0;
    $stmt->close();

    return $isLeader;
}

/**
 * Get the ids of active leader members for an organization.
 *
 * @param mysqli $conn Database connection
 * @param int $org_id Organization ID
 * @return array
 */
function get_active_organization_leader_member_ids($conn, $org_id) {
    if (organization_leaders_has_member_id($conn)) {
        $stmt = $conn->prepare("
            SELECT DISTINCT
                CASE
                    WHEN ol.member_id IS NOT NULL AND ol.member_id > 0 THEN ol.member_id
                    ELSE u.member_id
                END AS member_id
            FROM organization_leaders ol
            LEFT JOIN users u ON ol.user_id = u.id
            WHERE ol.organization_id = ? AND ol.status = 'active'
        ");
        $stmt->bind_param('i', $org_id);
    } else {
        $stmt = $conn->prepare("
            SELECT DISTINCT u.member_id AS member_id
            FROM organization_leaders ol
            LEFT JOIN users u ON ol.user_id = u.id
            WHERE ol.organization_id = ? AND ol.status = 'active'
        ");
        $stmt->bind_param('i', $org_id);
    }

    $stmt->execute();
    $result = $stmt->get_result();
    $leaderMemberIds = [];
    while ($row = $result->fetch_assoc()) {
        $leaderMemberId = (int) ($row['member_id'] ?? 0);
        if ($leaderMemberId > 0) {
            $leaderMemberIds[] = $leaderMemberId;
        }
    }
    $stmt->close();

    return array_values(array_unique($leaderMemberIds));
}

/**
 * Count pending organization membership requests.
 *
 * @param mysqli $conn Database connection
 * @param int $org_id Organization ID
 * @return int
 */
function get_organization_pending_membership_count($conn, $org_id) {
    return count(get_organization_pending_membership_requests($conn, $org_id));
}

/**
 * Get pending organization membership requests that are valid for display.
 *
 * @param mysqli $conn Database connection
 * @param int $org_id Organization ID
 * @return array
 */
function get_organization_pending_membership_requests($conn, $org_id) {
    $stmt = $conn->prepare("
        SELECT oma.id, oma.member_id, oma.organization_id, oma.requested_at,
               m.first_name, m.last_name, m.email, m.phone, m.crn
        FROM organization_membership_approvals oma
        INNER JOIN members m ON oma.member_id = m.id
        WHERE oma.organization_id = ? AND oma.status = 'pending'
        ORDER BY oma.requested_at ASC
    ");
    $stmt->bind_param('i', $org_id);
    $stmt->execute();
    $result = $stmt->get_result();

    $requests = [];
    while ($row = $result->fetch_assoc()) {
        $requests[] = $row;
    }
    $stmt->close();

    return $requests;
}

/**
 * Search active members that can be added to an organization.
 *
 * @param mysqli $conn Database connection
 * @param int $org_id Organization ID
 * @param string $search Search term
 * @param int|null $church_id Optional church scope
 * @param int $limit Result limit
 * @return array
 */
function search_organization_member_candidates($conn, $org_id, $search, $church_id = null, $limit = 25) {
    $search = trim((string) $search);
    if (strlen($search) < 2) {
        return [];
    }

    $searchLike = '%' . $search . '%';
    $limit = max(1, (int) $limit);

    $sql = "
        SELECT m.id, m.first_name, m.last_name, m.email, m.phone, m.crn
        FROM members m
        WHERE m.status = 'active'
          AND (
              CONCAT_WS(' ', m.first_name, m.last_name) LIKE ?
              OR m.crn LIKE ?
              OR m.phone LIKE ?
              OR m.email LIKE ?
          )
          AND NOT EXISTS (
              SELECT 1
              FROM member_organizations mo
              WHERE mo.member_id = m.id AND mo.organization_id = ?
          )
    ";

    $params = [$searchLike, $searchLike, $searchLike, $searchLike, $org_id];
    $types = 'ssssi';

    if ($church_id !== null && (int) $church_id > 0) {
        $sql .= " AND m.church_id = ?";
        $params[] = (int) $church_id;
        $types .= 'i';
    }

    $sql .= " ORDER BY m.last_name, m.first_name LIMIT " . $limit;

    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
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
 * Get recent payments for an organization.
 *
 * @param mysqli $conn Database connection
 * @param int $org_id Organization ID
 * @param string $start_date Start date
 * @param string $end_date End date
 * @param int $limit Result limit
 * @return array
 */
function get_organization_recent_payments($conn, $org_id, $start_date, $end_date, $limit = 10) {
    $limit = max(1, (int) $limit);
    $stmt = $conn->prepare("
        SELECT p.*, pt.name AS payment_type_name,
               CONCAT(m.first_name, ' ', m.last_name) AS member_name
        FROM payments p
        INNER JOIN member_organizations mo ON p.member_id = mo.member_id
        INNER JOIN members m ON p.member_id = m.id
        LEFT JOIN payment_types pt ON p.payment_type_id = pt.id
        WHERE mo.organization_id = ? AND p.payment_date BETWEEN ? AND ?
        ORDER BY p.payment_date DESC
        LIMIT $limit
    ");
    $stmt->bind_param('iss', $org_id, $start_date, $end_date);
    $stmt->execute();
    $payments = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    return $payments;
}

/**
 * Get upcoming attendance sessions for an organization's church.
 *
 * @param mysqli $conn Database connection
 * @param int $church_id Church ID
 * @param int $limit Result limit
 * @return array
 */
function get_upcoming_organization_sessions($conn, $church_id, $limit = 5) {
    $limit = max(1, (int) $limit);
    $stmt = $conn->prepare("
        SELECT ats.*, c.name AS church_name
        FROM attendance_sessions ats
        LEFT JOIN churches c ON ats.church_id = c.id
        WHERE ats.church_id = ? AND ats.service_date >= CURDATE()
        ORDER BY ats.service_date ASC
        LIMIT $limit
    ");
    $stmt->bind_param('i', $church_id);
    $stmt->execute();
    $sessions = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    return $sessions;
}
