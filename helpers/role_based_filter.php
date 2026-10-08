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

/**
 * Resolve Super Administrator access from active RBAC assignments. Account
 * numbers are data, not roles, and must never grant a global-scope bypass.
 */
function role_filter_is_super_admin($user_id = null) {
    global $conn;

    $user_id = (int) ($user_id ?: ($_SESSION['user_id'] ?? 0));
    if ($user_id < 1) {
        return false;
    }

    static $cache = [];
    if (array_key_exists($user_id, $cache)) {
        return $cache[$user_id];
    }

    $stmt = $conn->prepare(
        "SELECT 1
           FROM user_roles user_role
           JOIN roles role ON role.id = user_role.role_id
           JOIN users account ON account.id = user_role.user_id
          WHERE user_role.user_id = ?
            AND account.status = 'active'
            AND user_role.is_active = 1
            AND role.is_active = 1
            AND (user_role.expires_at IS NULL OR user_role.expires_at > NOW())
            AND LOWER(TRIM(role.name)) = 'super admin'
          LIMIT 1"
    );
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $cache[$user_id] = $stmt->get_result()->num_rows > 0;
    $stmt->close();

    return $cache[$user_id];
}

function get_role_based_filter($user_id = null) {
    if (!$user_id) {
        $user_id = $_SESSION['user_id'] ?? 0;
    }

    if (role_filter_is_super_admin($user_id)) {
        return ['where' => '', 'params' => [], 'types' => ''];
    }

    if ((int) $user_id < 1) {
        return ['where' => 'AND 1=0', 'params' => [], 'types' => '']; // No access
    }

    $classIds = get_user_class_ids($user_id);
    if ($classIds !== null) {
        $placeholders = implode(',', array_fill(0, count($classIds), '?'));
        return [
            'where' => "AND m.class_id IN ({$placeholders})",
            'params' => $classIds,
            'types' => str_repeat('i', count($classIds)),
            'class_ids' => $classIds,
        ];
    }

    $organizationIds = get_user_organization_ids($user_id);
    if ($organizationIds !== null) {
        $placeholders = implode(',', array_fill(0, count($organizationIds), '?'));
        return [
            'where' => "AND m.id IN (SELECT member_id FROM member_organizations WHERE organization_id IN ({$placeholders}))",
            'params' => $organizationIds,
            'types' => str_repeat('i', count($organizationIds)),
            'organization_ids' => $organizationIds,
        ];
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
        SELECT DISTINCT leader.class_id
          FROM users user_account
          JOIN bible_class_leaders leader
            ON leader.user_id = user_account.id
            OR (leader.member_id IS NOT NULL AND leader.member_id = user_account.member_id)
         WHERE user_account.id = ? AND leader.status = 'active'
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
        SELECT DISTINCT leader.organization_id
          FROM users user_account
          JOIN organization_leaders leader
            ON leader.user_id = user_account.id
            OR (leader.member_id IS NOT NULL AND leader.member_id = user_account.member_id)
         WHERE user_account.id = ? AND leader.status = 'active'
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
        WHERE ur.user_id = ? AND ur.is_active = 1
          AND (ur.expires_at IS NULL OR ur.expires_at >= NOW())
          AND r.name = 'Sunday School'
    ");
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $result = $stmt->get_result()->num_rows > 0;
    $stmt->close();
    
    return $result;
}

/**
 * Apply Sunday School filter (juveniles only)
 * Filters to members under 18 or with Junior Member membership status
 * @param string $member_table_alias Table alias for members table (e.g., 'm')
 * @return array ['sql' => string, 'params' => array, 'types' => string]
 */
function apply_sunday_school_filter($member_table_alias = 'm', $sunday_school_table_alias = null, $user_id = null) {
    if (!is_sunday_school_role($user_id)) {
        return ['sql' => '', 'params' => [], 'types' => ''];
    }
    
    // Filter to junior members: age < 18 OR the governed status is explicit.
    $memberScope = "({$member_table_alias}.membership_status = 'Junior Member' OR TIMESTAMPDIFF(YEAR, {$member_table_alias}.dob, CURDATE()) < 18)";
    $sql = $sunday_school_table_alias
        ? "({$sunday_school_table_alias}.id IS NOT NULL OR {$memberScope})"
        : $memberScope;
    
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
        WHERE ur.user_id = ? AND ur.is_active = 1
          AND (ur.expires_at IS NULL OR ur.expires_at >= NOW())
          AND r.name = 'Steward'
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
        WHERE ur.user_id = ? AND ur.is_active = 1
          AND (ur.expires_at IS NULL OR ur.expires_at >= NOW())
          AND r.name = 'Cashier'
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
    
    if (role_filter_is_super_admin($user_id) || !is_cashier($user_id)) {
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
    if (!$user_id) {
        $user_id = $_SESSION['user_id'] ?? 0;
    }

    if (role_filter_is_super_admin($user_id)) {
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
    $ss_filter = apply_sunday_school_filter($member_table_alias, null, $user_id);
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
