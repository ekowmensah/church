# RBAC API Documentation v2.0

Complete RESTful API for Role-Based Access Control management.

## 📋 Table of Contents

- [Authentication](#authentication)
- [Permissions API](#permissions-api)
- [Roles API](#roles-api)
- [Audit Logs API](#audit-logs-api)
- [Templates API](#templates-api)
- [Error Handling](#error-handling)
- [Examples](#examples)

---

## 🔐 Authentication

All API endpoints require authentication via PHP session. User must be logged in and have appropriate permissions.

**Session Required:**
```php
$_SESSION['user_id'] // Must be set
```

**Permission Checks:**
Each endpoint checks for specific permissions before allowing access.

---

## 🔑 Permissions API

Base URL: `/api/rbac/permissions.php`

### List All Permissions
```http
GET /api/rbac/permissions.php
```

**Permission Required:** `view_permission_list`

**Query Parameters:**
- `category_id` (int) - Filter by category
- `is_active` (bool) - Filter by active status
- `permission_type` (string) - Filter by type (action, resource, feature, system)
- `search` (string) - Search in name/description
- `grouped` (bool) - Group by category

**Response:**
```json
{
  "success": true,
  "data": {
    "permissions": [...],
    "total": 246,
    "filters": {...}
  }
}
```

### Get Specific Permission
```http
GET /api/rbac/permissions.php?id={id}
```

**Permission Required:** `view_permission_list`

**Response:**
```json
{
  "success": true,
  "data": {
    "permission": {
      "id": 1,
      "name": "view_dashboard",
      "description": "View dashboard",
      "category_id": 1,
      "category_name": "Dashboard",
      "permission_type": "feature",
      "is_system": true,
      "is_active": true,
      "children": [...]
    }
  }
}
```

### Create Permission
```http
POST /api/rbac/permissions.php
Content-Type: application/json
```

**Permission Required:** `manage_permissions`

**Request Body:**
```json
{
  "name": "export_reports",
  "description": "Export reports to PDF/Excel",
  "category_id": 5,
  "parent_id": null,
  "permission_type": "action",
  "is_system": false,
  "requires_context": false,
  "sort_order": 0,
  "is_active": true
}
```

**Response:**
```json
{
  "success": true,
  "message": "Permission created successfully",
  "data": {
    "permission": {...}
  }
}
```

### Update Permission
```http
PUT /api/rbac/permissions.php?id={id}
Content-Type: application/json
```

**Permission Required:** `manage_permissions`

**Request Body:**
```json
{
  "description": "Updated description",
  "sort_order": 10
}
```

### Delete Permission
```http
DELETE /api/rbac/permissions.php?id={id}
```

**Permission Required:** `manage_permissions`

**Query Parameters:**
- `hard_delete` (bool) - Permanently delete (default: false = soft delete)

---

## 👥 Roles API

Base URL: `/api/rbac/roles.php`

### List All Roles
```http
GET /api/rbac/roles.php
```

**Permission Required:** `view_role_list`

**Query Parameters:**
- `is_active` (bool) - Filter by active status
- `is_system` (bool) - Filter by system status
- `level` (int) - Filter by hierarchy level
- `hierarchy` (bool) - Return as hierarchy tree

**Response:**
```json
{
  "success": true,
  "data": {
    "roles": [...],
    "total": 10,
    "filters": {...}
  }
}
```

### Get Role Hierarchy
```http
GET /api/rbac/roles.php?hierarchy=true
```

**Permission Required:** `view_role_list`

**Response:**
```json
{
  "success": true,
  "data": {
    "hierarchy": [
      {
        "id": 1,
        "name": "Super Admin",
        "level": 0,
        "children": [...]
      }
    ]
  }
}
```

### Get Specific Role
```http
GET /api/rbac/roles.php?id={id}
```

**Permission Required:** `view_role_list`

### Get Role Permissions
```http
GET /api/rbac/roles.php?id={id}&permissions
```

**Permission Required:** `view_role_list`

**Query Parameters:**
- `include_inherited` (bool) - Include inherited permissions (default: true)

**Response:**
```json
{
  "success": true,
  "data": {
    "role_id": 1,
    "permissions": [...],
    "total": 243,
    "include_inherited": true
  }
}
```

### Create Role
```http
POST /api/rbac/roles.php
Content-Type: application/json
```

**Permission Required:** `manage_roles`

**Request Body:**
```json
{
  "name": "Content Manager",
  "description": "Manages website content",
  "parent_id": 2,
  "is_system": false,
  "is_active": true
}
```

### Update Role
```http
PUT /api/rbac/roles.php?id={id}
Content-Type: application/json
```

**Permission Required:** `manage_roles`

### Delete Role
```http
DELETE /api/rbac/roles.php?id={id}
```

**Permission Required:** `manage_roles`

**Query Parameters:**
- `hard_delete` (bool) - Permanently delete (default: false)

### Grant Permission to Role
```http
POST /api/rbac/roles.php?id={id}&grant
Content-Type: application/json
```

**Permission Required:** `manage_roles`

**Request Body:**
```json
{
  "permission_id": 5,
  "expires_at": "2025-12-31 23:59:59",
  "conditions": {"context": "value"}
}
```

### Revoke Permission from Role
```http
POST /api/rbac/roles.php?id={id}&revoke
Content-Type: application/json
```

**Permission Required:** `manage_roles`

**Request Body:**
```json
{
  "permission_id": 5
}
```

### Sync Role Permissions
```http
POST /api/rbac/roles.php?id={id}&sync
Content-Type: application/json
```

**Permission Required:** `manage_roles`

**Request Body:**
```json
{
  "permission_ids": [1, 2, 3, 5, 8, 13]
}
```

**Response:**
```json
{
  "success": true,
  "message": "Role permissions synced successfully",
  "data": {
    "synced_count": 6
  }
}
```

---

## 📝 Audit Logs API

Base URL: `/api/rbac/audit.php`

### List Audit Logs
```http
GET /api/rbac/audit.php
```

**Permission Required:** `view_audit_log`

**Query Parameters:**
- `page` (int) - Page number (default: 1)
- `limit` (int) - Items per page (default: 50, max: 100)
- `actor_user_id` (int) - Filter by actor
- `action` (string) - Filter by action (grant, revoke, check, etc.)
- `target_type` (string) - Filter by target type (role, user, permission)
- `target_id` (int) - Filter by target ID
- `result` (string) - Filter by result (success, failure)
- `date_from` (date) - Filter from date
- `date_to` (date) - Filter to date

**Response:**
```json
{
  "success": true,
  "data": [...],
  "pagination": {
    "total": 17092,
    "page": 1,
    "limit": 50,
    "total_pages": 342,
    "has_next": true,
    "has_prev": false
  }
}
```

### Get Audit Statistics
```http
GET /api/rbac/audit.php?stats
```

**Permission Required:** `view_audit_log`

**Query Parameters:**
- `date_from` (date) - From date
- `date_to` (date) - To date

**Response:**
```json
{
  "success": true,
  "data": {
    "statistics": {
      "total_entries": 17092,
      "unique_users": 30,
      "successful_actions": 15000,
      "failed_actions": 2092,
      "permission_checks": 16000,
      "grants": 500,
      "revokes": 50
    },
    "filters": {...}
  }
}
```

### Get User Activity
```http
GET /api/rbac/audit.php?user={id}
```

**Permission Required:** `view_audit_log`

**Query Parameters:**
- `days` (int) - Number of days to look back (default: 30, max: 365)

**Response:**
```json
{
  "success": true,
  "data": {
    "user_id": 3,
    "days": 30,
    "activity": [
      {
        "action": "grant",
        "target_type": "role",
        "count": 15,
        "last_activity": "2025-11-15 08:00:00"
      }
    ],
    "total_actions": 150
  }
}
```

### Get Most Active Users
```http
GET /api/rbac/audit.php?active_users
```

**Permission Required:** `view_audit_log`

**Query Parameters:**
- `days` (int) - Number of days (default: 7, max: 365)
- `limit` (int) - Number of users (default: 10, max: 100)

### Get Permission Usage
```http
GET /api/rbac/audit.php?permission_usage
```

**Permission Required:** `view_audit_log`

**Query Parameters:**
- `days` (int) - Number of days (default: 7, max: 365)
- `limit` (int) - Number of permissions (default: 20, max: 100)

### Get Failed Checks
```http
GET /api/rbac/audit.php?failed_checks
```

**Permission Required:** `view_audit_log`

**Query Parameters:**
- `limit` (int) - Number of records (default: 50, max: 100)

---

## 📋 Templates API

Base URL: `/api/rbac/templates.php`

### List All Templates
```http
GET /api/rbac/templates.php
```

**Permission Required:** `view_role_templates`

**Query Parameters:**
- `category` (string) - Filter by category (church, ministry, custom)

**Response:**
```json
{
  "success": true,
  "data": {
    "templates": [...],
    "total": 10,
    "category": null
  }
}
```

### Get Specific Template
```http
GET /api/rbac/templates.php?id={id}
```

**Permission Required:** `view_role_templates`

**Response:**
```json
{
  "success": true,
  "data": {
    "template": {
      "id": 2,
      "name": "Cashier",
      "description": "Payment collection and basic reporting",
      "category": "church",
      "is_system": true,
      "template_data": {
        "permissions": ["view_dashboard", "view_payment_list", ...],
        "description": "Handle payments and view payment reports"
      }
    }
  }
}
```

### Get Template Usage
```http
GET /api/rbac/templates.php?id={id}&usage
```

**Permission Required:** `view_role_templates`

**Response:**
```json
{
  "success": true,
  "data": {
    "template_id": 2,
    "usage": [
      {
        "role_id": 5,
        "role_name": "Senior Cashier",
        "created_by_name": "Admin",
        "created_at": "2025-11-15 08:00:00"
      }
    ],
    "total_uses": 1
  }
}
```

### Create Template
```http
POST /api/rbac/templates.php
Content-Type: application/json
```

**Permission Required:** `manage_role_templates`

**Request Body:**
```json
{
  "name": "Custom Template",
  "description": "Custom role template",
  "category": "custom",
  "is_system": false,
  "template_data": {
    "permissions": ["view_member", "edit_member"],
    "description": "Custom permissions set"
  }
}
```

### Update Template
```http
PUT /api/rbac/templates.php?id={id}
Content-Type: application/json
```

**Permission Required:** `manage_role_templates`

### Delete Template
```http
DELETE /api/rbac/templates.php?id={id}
```

**Permission Required:** `manage_role_templates`

### Create Role from Template
```http
POST /api/rbac/templates.php?id={id}&create_role
Content-Type: application/json
```

**Permission Required:** `manage_roles`

**Request Body:**
```json
{
  "role_name": "Senior Cashier"
}
```

**Response:**
```json
{
  "success": true,
  "message": "Role created from template successfully",
  "data": {
    "role": {
      "id": 11,
      "name": "Senior Cashier",
      ...
    }
  }
}
```

---

## ⚠️ Error Handling

All errors return consistent format:

```json
{
  "success": false,
  "error": "Error message here",
  "details": "Additional details (optional)"
}
```

**HTTP Status Codes:**
- `200` - Success
- `201` - Created
- `400` - Bad Request
- `401` - Unauthorized
- `403` - Forbidden (permission denied)
- `404` - Not Found
- `405` - Method Not Allowed
- `500` - Internal Server Error

---

## 📚 Examples

### Example 1: Create Role and Assign Permissions

```javascript
// 1. Create role
fetch('/api/rbac/roles.php', {
  method: 'POST',
  headers: {'Content-Type': 'application/json'},
  body: JSON.stringify({
    name: 'Content Manager',
    description: 'Manages content',
    parent_id: 2
  })
})
.then(r => r.json())
.then(data => {
  const roleId = data.data.role.id;
  
  // 2. Sync permissions
  return fetch(`/api/rbac/roles.php?id=${roleId}&sync`, {
    method: 'POST',
    headers: {'Content-Type': 'application/json'},
    body: JSON.stringify({
      permission_ids: [1, 2, 3, 5, 8]
    })
  });
})
.then(r => r.json())
.then(data => console.log('Role created with permissions!'));
```

### Example 2: Get Audit Logs with Filters

```javascript
fetch('/api/rbac/audit.php?action=grant&date_from=2025-11-01&limit=20')
  .then(r => r.json())
  .then(data => {
    console.log('Audit logs:', data.data);
    console.log('Pagination:', data.pagination);
  });
```

### Example 3: Create Role from Template

```javascript
fetch('/api/rbac/templates.php?id=2&create_role', {
  method: 'POST',
  headers: {'Content-Type': 'application/json'},
  body: JSON.stringify({
    role_name: 'Senior Cashier'
  })
})
.then(r => r.json())
.then(data => console.log('Role created:', data.data.role));
```

### Example 4: Get Permission Usage Statistics

```javascript
fetch('/api/rbac/audit.php?permission_usage&days=7&limit=10')
  .then(r => r.json())
  .then(data => {
    data.data.usage.forEach(perm => {
      console.log(`${perm.name}: ${perm.check_count} checks`);
    });
  });
```

---

## 🔒 Security Notes

1. **Authentication Required:** All endpoints require active PHP session
2. **Permission Checks:** Each endpoint validates user permissions
3. **Input Validation:** All inputs are validated and sanitized
4. **SQL Injection Protection:** All queries use prepared statements
5. **Audit Logging:** All actions are logged for audit trail
6. **Rate Limiting:** Consider implementing rate limiting for production

---

## 📞 Support

For issues or questions:
- Check error messages in response
- Review audit logs for activity
- Consult main documentation: `/services/rbac/README.md`

---

**Version:** 2.0  
**Last Updated:** November 15, 2025  
**Status:** Production Ready
