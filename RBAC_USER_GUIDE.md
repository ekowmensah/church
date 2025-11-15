# RBAC User Guide - Templates & Role Management

## 📚 Table of Contents
1. [How to Add Templates](#how-to-add-templates)
2. [How to Edit/Update Role Permissions](#how-to-editupdateupdate-role-permissions)
3. [How to Use Templates](#how-to-use-templates)

---

## 1. How to Add Templates

### What are Templates?
Templates are pre-configured sets of permissions for common roles (e.g., "Cashier", "Class Leader", "Admin").

### Method 1: Using the API (Recommended)

#### Step 1: Open phpMyAdmin or MySQL client

#### Step 2: Insert Template
```sql
-- Insert a new template
INSERT INTO permission_templates (name, description, category, created_by) 
VALUES ('Cashier', 'Standard permissions for cashiers', 'Financial', 3);

-- Get the template ID
SET @template_id = LAST_INSERT_ID();
```

#### Step 3: Add Permissions to Template
```sql
-- Add permissions for Cashier template
INSERT INTO template_permissions (template_id, permission_id)
SELECT @template_id, id FROM permissions 
WHERE name IN (
    'view_dashboard',
    'view_payment_list',
    'make_payment',
    'create_payment',
    'view_payment_history',
    'payment_statistics',
    'resend_payment_sms'
);
```

### Method 2: Using the RBAC Dashboard

#### Coming Soon!
The template management UI is being developed. For now, use the SQL method above.

---

## 2. How to Edit/Update Role Permissions

### Option A: Using role_form.php (Recommended)

#### Step 1: Navigate to Role List
```
http://localhost/myfreemanchurchgit/church/views/role_list.php
```

#### Step 2: Click "Edit" on the Role
- Find the role you want to edit
- Click the "Edit" button

#### Step 3: Update Permissions
- You'll see a list of all permissions grouped by category
- Check/uncheck the permissions you want to add/remove
- Click "Save Changes"

### Option B: Using the RBAC Dashboard

#### Step 1: Navigate to RBAC Dashboard
```
http://localhost/myfreemanchurchgit/church/views/rbac_dashboard.php
```

#### Step 2: Go to Roles Tab
- Click on the "Roles" tab
- You'll see a list of all roles

#### Step 3: Click "View Details" or "Edit"
- Click on the role you want to edit
- This will redirect you to `role_form.php`

#### Step 4: Update Permissions
- Check/uncheck permissions
- Click "Save"

### Option C: Using SQL (Advanced)

#### View Current Role Permissions
```sql
-- See what permissions a role has
SELECT 
    r.name as role_name,
    p.name as permission_name,
    p.description
FROM roles r
JOIN role_permissions rp ON r.id = rp.role_id
JOIN permissions p ON rp.permission_id = p.id
WHERE r.id = 5  -- Change to your role ID
ORDER BY p.category_id, p.name;
```

#### Add Permission to Role
```sql
-- Add a permission to a role
INSERT INTO role_permissions (role_id, permission_id, granted_by, granted_at)
VALUES (
    5,  -- Role ID (e.g., Cashier)
    109,  -- Permission ID (e.g., make_payment)
    3,  -- User ID who granted it
    NOW()
);
```

#### Remove Permission from Role
```sql
-- Remove a permission from a role
DELETE FROM role_permissions 
WHERE role_id = 5  -- Role ID
AND permission_id = 109;  -- Permission ID
```

#### Bulk Add Permissions
```sql
-- Add multiple permissions at once
INSERT INTO role_permissions (role_id, permission_id, granted_by, granted_at)
SELECT 
    5 as role_id,  -- Your role ID
    id as permission_id,
    3 as granted_by,
    NOW() as granted_at
FROM permissions
WHERE name IN (
    'view_dashboard',
    'view_payment_list',
    'make_payment',
    'create_payment'
);
```

---

## 3. How to Use Templates

### Step 1: Create a New Role

#### Using role_form.php:
```
http://localhost/myfreemanchurchgit/church/views/role_form.php
```

1. Enter role name (e.g., "New Cashier")
2. Enter description
3. Look for "Apply Template" dropdown (if available)
4. Select template (e.g., "Cashier")
5. Click "Apply Template"
6. Review and adjust permissions
7. Click "Save"

### Step 2: Apply Template to Existing Role

#### Using SQL:
```sql
-- Apply a template to an existing role
-- First, clear existing permissions (optional)
DELETE FROM role_permissions WHERE role_id = 5;

-- Then add all permissions from template
INSERT INTO role_permissions (role_id, permission_id, granted_by, granted_at)
SELECT 
    5 as role_id,  -- Your role ID
    tp.permission_id,
    3 as granted_by,
    NOW() as granted_at
FROM template_permissions tp
WHERE tp.template_id = 1;  -- Your template ID
```

---

## 📋 Common Templates

### Template 1: Cashier
**Permissions:**
- view_dashboard
- view_payment_list
- make_payment
- create_payment
- view_payment_history
- payment_statistics
- resend_payment_sms
- view_payment_bulk
- submit_bulk_payment

**SQL to Create:**
```sql
-- Create Cashier template
INSERT INTO permission_templates (name, description, category, created_by) 
VALUES ('Cashier', 'Standard permissions for cashiers', 'Financial', 3);

SET @template_id = LAST_INSERT_ID();

INSERT INTO template_permissions (template_id, permission_id)
SELECT @template_id, id FROM permissions 
WHERE name IN (
    'view_dashboard',
    'view_payment_list',
    'make_payment',
    'create_payment',
    'view_payment_history',
    'payment_statistics',
    'resend_payment_sms',
    'view_payment_bulk',
    'submit_bulk_payment'
);
```

### Template 2: Class Leader
**Permissions:**
- view_dashboard
- view_member
- view_bibleclass_list
- mark_attendance
- view_attendance_list
- edit_member_in_own_class
- make_payment_for_own_class

**SQL to Create:**
```sql
-- Create Class Leader template
INSERT INTO permission_templates (name, description, category, created_by) 
VALUES ('Class Leader', 'Standard permissions for class leaders', 'Leadership', 3);

SET @template_id = LAST_INSERT_ID();

INSERT INTO template_permissions (template_id, permission_id)
SELECT @template_id, id FROM permissions 
WHERE name IN (
    'view_dashboard',
    'view_member',
    'view_bibleclass_list',
    'mark_attendance',
    'view_attendance_list',
    'edit_member_in_own_class',
    'make_payment_for_own_class'
);
```

### Template 3: Admin
**Permissions:**
- All permissions except system-level ones

**SQL to Create:**
```sql
-- Create Admin template
INSERT INTO permission_templates (name, description, category, created_by) 
VALUES ('Admin', 'Full permissions except system management', 'Administrative', 3);

SET @template_id = LAST_INSERT_ID();

-- Add all permissions except system ones
INSERT INTO template_permissions (template_id, permission_id)
SELECT @template_id, id FROM permissions 
WHERE permission_type != 'system' AND is_active = 1;
```

---

## 🔍 Useful Queries

### View All Templates
```sql
SELECT 
    pt.id,
    pt.name,
    pt.description,
    pt.category,
    COUNT(tp.permission_id) as permission_count
FROM permission_templates pt
LEFT JOIN template_permissions tp ON pt.id = tp.template_id
GROUP BY pt.id
ORDER BY pt.name;
```

### View Template Permissions
```sql
SELECT 
    pt.name as template_name,
    p.name as permission_name,
    p.description,
    pc.name as category
FROM permission_templates pt
JOIN template_permissions tp ON pt.id = tp.template_id
JOIN permissions p ON tp.permission_id = p.id
LEFT JOIN permission_categories pc ON p.category_id = pc.id
WHERE pt.id = 1  -- Change to your template ID
ORDER BY pc.name, p.name;
```

### Compare Role vs Template
```sql
-- See what permissions a role has vs a template
SELECT 
    p.name as permission_name,
    CASE WHEN rp.permission_id IS NOT NULL THEN 'Yes' ELSE 'No' END as has_permission,
    CASE WHEN tp.permission_id IS NOT NULL THEN 'Yes' ELSE 'No' END as in_template
FROM permissions p
LEFT JOIN role_permissions rp ON p.id = rp.permission_id AND rp.role_id = 5
LEFT JOIN template_permissions tp ON p.id = tp.permission_id AND tp.template_id = 1
WHERE p.is_active = 1
ORDER BY p.category_id, p.name;
```

---

## 🎯 Quick Reference

### URLs
- **Role List:** `/views/role_list.php`
- **Edit Role:** `/views/role_form.php?id=X`
- **RBAC Dashboard:** `/views/rbac_dashboard.php`
- **Permission List:** `/views/permission_list.php`

### Common Permission Names
- `view_dashboard` - View main dashboard
- `manage_members` - Full member management
- `view_member` - View member records
- `create_member` - Create new members
- `edit_member` - Edit member records
- `delete_member` - Delete members
- `make_payment` - Make payments
- `view_payment_list` - View payment list
- `payment_statistics` - Access payment statistics
- `manage_roles` - Manage roles
- `manage_permissions` - Manage permissions

---

## ❓ Troubleshooting

### Issue: Can't see template dropdown
**Solution:** Template UI is not yet implemented. Use SQL method to create and apply templates.

### Issue: Permissions not saving
**Solution:** 
1. Check browser console for errors
2. Verify you have `manage_roles` permission
3. Check database connection

### Issue: Role has no permissions after applying template
**Solution:**
```sql
-- Verify template has permissions
SELECT COUNT(*) FROM template_permissions WHERE template_id = 1;

-- If 0, add permissions to template first
```

---

## 📞 Need Help?

1. Check browser console (F12) for errors
2. Check PHP error log: `C:\xampp\apache\logs\error.log`
3. Verify database tables exist:
   - `roles`
   - `permissions`
   - `role_permissions`
   - `permission_templates`
   - `template_permissions`

---

**Last Updated:** November 15, 2025
**Version:** 1.0
