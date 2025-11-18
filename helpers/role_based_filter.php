<?php
/**
 * Role-Based Filtering Helper
 * Comprehensive role detection and data filtering for all user roles
 * 
 * Supports:
 * - Class Leaders (filter by bible class)
 * - Organizational Leaders (filter by organization)
 * - Sunday School (filter to juveniles only)
 * - Stewards (view-only restrictions)
 * - Cashiers (filter by recorded_by)
 * 
 * @version 2.0
 * @date 2025-11-18
 */

function get_role_based_filter($user_id = null) {
    global $conn;
    
    if (!$user_id) {
        $user_id = $_SESSION['user_id'] ?? 0;
    }
    
    // Super admin sees everything
    $is_super_admin = (isset($_SESSION['user_id']) && $_SESSION['user_id'] == 3) || 
                      (isset($_SESSION['role_id']) && $_SESSION['role_id'] == 1);
    
    if ($is_super_admin) {
        return ['where' => '', 'params' => [], 'types' => ''];
    }
    
    // Get user's role and associated bible class
    $stmt = $conn->prepare("
        SELECT u.role_id, r.name as role_name, u.member_id,
               m.class_id, bc.name as class_name
        FROM users u 
        LEFT JOIN roles r ON u.role_id = r.id
        LEFT JOIN members m ON u.member_id = m.id
        LEFT JOIN bible_classes bc ON m.class_id = bc.id
        WHERE u.id = ?
    ");
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $user_data = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    if (!$user_data) {
        return ['where' => 'AND 1=0', 'params' => [], 'types' => '']; // No access
    }
    
    $role_name = strtolower($user_data['role_name'] ?? '');
    $class_id = $user_data['class_id'];
    
    // Class Leader: Only see members of their bible class
    if (strpos($role_name, 'class leader') !== false && $class_id) {
        return [
            'where' => 'AND m.class_id = ?',
            'params' => [$class_id],
            'types' => 'i',
            'class_id' => $class_id,
            'class_name' => $user_data['class_name']
        ];
    }
    
    // Church Leader: Only see members of their church
    if (strpos($role_name, 'church leader') !== false) {
        $church_stmt = $conn->prepare("SELECT church_id FROM members WHERE id = ?");
        $church_stmt->bind_param('i', $user_data['member_id']);
        $church_stmt->execute();
        $church_result = $church_stmt->get_result()->fetch_assoc();
        $church_stmt->close();
        
        if ($church_result) {
            return [
                'where' => 'AND m.church_id = ?',
                'params' => [$church_result['church_id']],
                'types' => 'i',
                'church_id' => $church_result['church_id']
            ];
        }
    }
    
    // Default: No additional filtering (regular users see everything they have permission for)
    return ['where' => '', 'params' => [], 'types' => ''];
}

function apply_role_based_filter($base_query, $user_id = null) {
    $filter = get_role_based_filter($user_id);
    
    // Add the WHERE clause to the base query
    $filtered_query = $base_query . ' ' . $filter['where'];
    
    return [
        'query' => $filtered_query,
        'params' => $filter['params'],
        'types' => $filter['types'],
        'filter_info' => $filter
    ];
}

// ============================================
// CLASS LEADER FUNCTIONS
// ============================================

/**
 * Get bible class IDs assigned to current user as class leader
 * @param int|null $user_id User ID (defaults to session user)
 * @return array|null Array of class IDs or null if not a class leader
 */
function get_user_class_ids($user_id = null) {
    global $conn;
    
    if (!$user_id) {
        $user_id = $_SESSION['user_id'] ?? 0;
    }
    
    if (!$user_id) {
        return null;
    }
    
    $stmt = $conn->prepare("
        SELECT class_id 
        FROM bible_class_leaders 
        WHERE user_id = ? AND status = 'active'
    ");
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $class_ids = [];
    while ($row = $result->fetch_assoc()) {
        $class_ids[] = $row['class_id'];
    }
    $stmt->close();
    
    return empty($class_ids) ? null : $class_ids;
}

/**
 * Check if current user is a class leader
 * @param int|null $user_id User ID (defaults to session user)
 * @return bool
 */
function is_class_leader($user_id = null) {
    return get_user_class_ids($user_id) !== null;
}

/**
 * Apply class leader filter to SQL WHERE clause
 * @param string $member_table_alias Table alias for members table (e.g., 'm')
 * @param int|null $user_id User ID (defaults to session user)
 * @return array ['sql' => string, 'params' => array, 'types' => string]
 */
function apply_class_leader_filter($member_table_alias = 'm', $user_id = null) {
    $class_ids = get_user_class_ids($user_id);
    
    if ($class_ids === null) {
        return ['sql' => '', 'params' => [], 'types' => ''];
    }
    
    $placeholders = implode(',', array_fill(0, count($class_ids), '?'));
    $sql = "{$member_table_alias}.class_id IN ({$placeholders})";
    $types = str_repeat('i', count($class_ids));
    
    return [
        'sql' => $sql,
        'params' => $class_ids,
        'types' => $types
    ];
}

// ============================================
// ORGANIZATIONAL LEADER FUNCTIONS
// ============================================

/**
 * Get organization IDs assigned to current user as organizational leader
 * @param int|null $user_id User ID (defaults to session user)
 * @return array|null Array of organization IDs or null if not an org leader
 */
function get_user_organization_ids($user_id = null) {
    global $conn;
    
    if (!$user_id) {
        $user_id = $_SESSION['user_id'] ?? 0;
    }
    
    if (!$user_id) {
        return null;
    }
    
    $stmt = $conn->prepare("
        SELECT organization_id 
        FROM organization_leaders 
        WHERE user_id = ? AND status = 'active'
    ");
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $org_ids = [];
    while ($row = $result->fetch_assoc()) {
        $org_ids[] = $row['organization_id'];
    }
    $stmt->close();
    
    return empty($org_ids) ? null : $org_ids;
}

/**
 * Check if current user is an organizational leader
 * @param int|null $user_id User ID (defaults to session user)
 * @return bool
 */
function is_organizational_leader($user_id = null) {
    return get_user_organization_ids($user_id) !== null;
}

/**
 * Apply organizational leader filter to SQL WHERE clause
 * Filters to members who belong to the leader's organizations
 * @param string $member_table_alias Table alias for members table (e.g., 'm')
 * @param int|null $user_id User ID (defaults to session user)
 * @return array ['sql' => string, 'params' => array, 'types' => string]
 */
function apply_organizational_leader_filter($member_table_alias = 'm', $user_id = null) {
    $org_ids = get_user_organization_ids($user_id);
    
    if ($org_ids === null) {
        return ['sql' => '', 'params' => [], 'types' => ''];
    }
    
    $placeholders = implode(',', array_fill(0, count($org_ids), '?'));
    $sql = "{$member_table_alias}.id IN (
        SELECT member_id FROM member_organizations 
        WHERE organization_id IN ({$placeholders})
    )";
    $types = str_repeat('i', count($org_ids));
    
    return [
        'sql' => $sql,
        'params' => $org_ids,
        'types' => $types
    ];
}

// ============================================
// SUNDAY SCHOOL ROLE FUNCTIONS
// ============================================

/**
 * Check if current user has Sunday School role
 * @param int|null $user_id User ID (defaults to session user)
 * @return bool
 */
function is_sunday_school_role($user_id = null) {
    global $conn;
    
    if (!$user_id) {
        $user_id = $_SESSION['user_id'] ?? 0;
    }
    
    if (!$user_id) {
        return false;
    }
    
    $stmt = $conn->prepare("
        SELECT 1 FROM user_roles ur
        JOIN roles r ON ur.role_id = r.id
        WHERE ur.user_id = ? AND r.name = 'Sunday School'
    ");
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $result = $stmt->get_result()->num_rows > 0;
    $stmt->close();
    
    return $result;
}

/**
 * Apply Sunday School filter (juveniles only)
 * Filters to members under 18 or with Juvenile membership status
 * @param string $member_table_alias Table alias for members table (e.g., 'm')
 * @return array ['sql' => string, 'params' => array, 'types' => string]
 */
function apply_sunday_school_filter($member_table_alias = 'm') {
    if (!is_sunday_school_role()) {
        return ['sql' => '', 'params' => [], 'types' => ''];
    }
    
    // Filter to juveniles: age < 18 OR membership_status = 'Juvenile'
    $sql = "({$member_table_alias}.membership_status = 'Juvenile' OR TIMESTAMPDIFF(YEAR, {$member_table_alias}.dob, CURDATE()) < 18)";
    
    return [
        'sql' => $sql,
        'params' => [],
        'types' => ''
    ];
}

// ============================================
// STEWARD ROLE FUNCTIONS
// ============================================

/**
 * Check if current user is a steward
 * @param int|null $user_id User ID (defaults to session user)
 * @return bool
 */
function is_steward($user_id = null) {
    global $conn;
    
    if (!$user_id) {
        $user_id = $_SESSION['user_id'] ?? 0;
    }
    
    if (!$user_id) {
        return false;
    }
    
    $stmt = $conn->prepare("
        SELECT 1 FROM user_roles ur
        JOIN roles r ON ur.role_id = r.id
        WHERE ur.user_id = ? AND r.name = 'Steward'
    ");
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $result = $stmt->get_result()->num_rows > 0;
    $stmt->close();
    
    return $result;
}

// ============================================
// CASHIER ROLE FUNCTIONS
// ============================================

/**
 * Check if current user is a cashier
 * @param int|null $user_id User ID (defaults to session user)
 * @return bool
 */
function is_cashier($user_id = null) {
    global $conn;
    
    if (!$user_id) {
        $user_id = $_SESSION['user_id'] ?? 0;
    }
    
    if (!$user_id) {
        return false;
    }
    
    $stmt = $conn->prepare("
        SELECT 1 FROM user_roles ur
        JOIN roles r ON ur.role_id = r.id
        WHERE ur.user_id = ? AND r.name = 'Cashier'
    ");
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $result = $stmt->get_result()->num_rows > 0;
    $stmt->close();
    
    return $result;
}

/**
 * Apply cashier filter to payment queries
 * Filters payments to only those recorded by the cashier
 * @param string $payment_table_alias Table alias for payments table (e.g., 'p')
 * @param int|null $user_id User ID (defaults to session user)
 * @return array ['sql' => string, 'params' => array, 'types' => string]
 */
function apply_cashier_filter($payment_table_alias = 'p', $user_id = null) {
    if (!$user_id) {
        $user_id = $_SESSION['user_id'] ?? 0;
    }
    
    // Super admin sees all payments
    $is_super_admin = (isset($_SESSION['user_id']) && $_SESSION['user_id'] == 3) || 
                      (isset($_SESSION['role_id']) && $_SESSION['role_id'] == 1);
    
    if ($is_super_admin || !is_cashier($user_id)) {
        return ['sql' => '', 'params' => [], 'types' => ''];
    }
    
    $sql = "{$payment_table_alias}.recorded_by = ?";
    
    return [
        'sql' => $sql,
        'params' => [$user_id],
        'types' => 'i'
    ];
}

// ============================================
// COMBINED FILTER FUNCTION
// ============================================

/**
 * Apply all applicable role-based filters for current user
 * Automatically detects user's role and applies appropriate filters
 * @param string $member_table_alias Table alias for members table
 * @param string $payment_table_alias Table alias for payments table (optional)
 * @param int|null $user_id User ID (defaults to session user)
 * @return array ['sql' => string, 'params' => array, 'types' => string, 'role' => string]
 */
function apply_all_role_filters($member_table_alias = 'm', $payment_table_alias = 'p', $user_id = null) {
    global $conn;
    
    if (!$user_id) {
        $user_id = $_SESSION['user_id'] ?? 0;
    }
    
    // Super admin bypass - check both session and passed user_id
    $is_super_admin = ($user_id == 3) || 
                      (isset($_SESSION['user_id']) && $_SESSION['user_id'] == 3) || 
                      (isset($_SESSION['role_id']) && $_SESSION['role_id'] == 1);
    
    // Also check if user has super admin role in database
    if ($user_id && !$is_super_admin) {
        $stmt = $conn->prepare("
            SELECT 1 FROM user_roles ur
            JOIN roles r ON ur.role_id = r.id
            WHERE ur.user_id = ? AND r.id = 1
        ");
        $stmt->bind_param('i', $user_id);
        $stmt->execute();
        $is_super_admin = $stmt->get_result()->num_rows > 0;
        $stmt->close();
    }
    
    if ($is_super_admin) {
        return ['sql' => '', 'params' => [], 'types' => '', 'role' => 'super_admin'];
    }
    
    // Check class leader first (highest priority for member filtering)
    $class_filter = apply_class_leader_filter($member_table_alias, $user_id);
    if (!empty($class_filter['sql'])) {
        return array_merge($class_filter, ['role' => 'class_leader']);
    }
    
    // Check organizational leader
    $org_filter = apply_organizational_leader_filter($member_table_alias, $user_id);
    if (!empty($org_filter['sql'])) {
        return array_merge($org_filter, ['role' => 'organizational_leader']);
    }
    
    // Check Sunday School
    $ss_filter = apply_sunday_school_filter($member_table_alias);
    if (!empty($ss_filter['sql'])) {
        return array_merge($ss_filter, ['role' => 'sunday_school']);
    }
    
    // Check cashier (for payment filtering)
    $cashier_filter = apply_cashier_filter($payment_table_alias, $user_id);
    if (!empty($cashier_filter['sql'])) {
        return array_merge($cashier_filter, ['role' => 'cashier']);
    }
    
    // No special filtering
    return ['sql' => '', 'params' => [], 'types' => '', 'role' => 'regular_user'];
}

?>
