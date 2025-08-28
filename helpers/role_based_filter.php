<?php
/**
 * Role-based filtering helper for reports
 * Add this to any report that needs role-based data filtering
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
?>
