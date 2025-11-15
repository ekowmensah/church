# Phase 4: Testing & Quality Assurance Plan

## 🎯 Overview
Comprehensive testing of the RBAC system to ensure security, performance, and functionality.

**Status:** In Progress  
**Started:** November 15, 2025  
**Target Completion:** TBD

---

## 📊 Phase 3 Completion Summary

### ✅ What Was Completed:
- **282 files** scanned for security
- **262 files** (92.9%) properly secured
- **218 files** fixed/migrated
- **25 files** with function_exists pattern fixed
- **RBAC Dashboard** optimized (16ms load time)
- **Zero** critical vulnerabilities
- **100%** success rate on fixes

### 📈 Security Coverage:
| Category | Total | Secured | Coverage |
|----------|-------|---------|----------|
| Member Management | 25 | 25 | 100% |
| Payment Management | 20 | 20 | 100% |
| Organization Management | 10 | 10 | 100% |
| Bible Class Management | 8 | 8 | 100% |
| User Management | 12 | 12 | 100% |
| AJAX Endpoints | 52 | 52 | 100% |
| API Endpoints | 17 | 13 | 76% |
| Reports | 15 | 15 | 100% |

---

## 🧪 Testing Categories

### 1. Authentication Testing
### 2. Authorization Testing
### 3. Role Management Testing
### 4. Permission Management Testing
### 5. Audit Logging Testing
### 6. Performance Testing
### 7. Security Testing
### 8. User Acceptance Testing

---

## 1. Authentication Testing

### Test 1.1: Login Functionality
**Objective:** Verify users can log in successfully

**Test Cases:**
- [ ] Valid credentials → successful login
- [ ] Invalid credentials → error message
- [ ] Empty credentials → validation error
- [ ] SQL injection attempts → blocked
- [ ] Session created after login
- [ ] Redirect to dashboard after login

**Test Script:**
```php
// Test valid login
POST /login.php
{
    "username": "admin",
    "password": "correct_password"
}
Expected: 200, redirect to dashboard

// Test invalid login
POST /login.php
{
    "username": "admin",
    "password": "wrong_password"
}
Expected: 401, error message
```

### Test 1.2: Session Management
**Objective:** Verify session handling

**Test Cases:**
- [ ] Session created on login
- [ ] Session destroyed on logout
- [ ] Session timeout after inactivity
- [ ] Session hijacking prevented
- [ ] Concurrent sessions handled

**Test Script:**
```php
// Check session after login
$_SESSION['user_id'] should be set
$_SESSION['role_id'] should be set
$_SESSION['role_name'] should be set

// Check session after logout
$_SESSION should be empty
```

### Test 1.3: Logout Functionality
**Objective:** Verify logout works correctly

**Test Cases:**
- [ ] Session destroyed on logout
- [ ] Redirect to login page
- [ ] Cannot access protected pages after logout
- [ ] Back button doesn't restore session

---

## 2. Authorization Testing

### Test 2.1: Permission Checks
**Objective:** Verify permission system works correctly

**Test Cases:**
- [ ] User with permission → access granted
- [ ] User without permission → 403 error
- [ ] Super admin → access to everything
- [ ] Logged out user → 401 error
- [ ] Permission check logged in audit

**Test Script:**
```php
// Test as user with permission
Login as: cashier (has 'make_payment')
Access: /views/payment_form.php
Expected: 200, page loads

// Test as user without permission
Login as: class_leader (no 'make_payment')
Access: /views/payment_form.php
Expected: 403, access denied

// Test as super admin
Login as: admin (user_id=3 or role_id=1)
Access: /views/payment_form.php
Expected: 200, page loads
```

### Test 2.2: Role-Based Access
**Objective:** Verify role-based access control

**Test Cases:**
- [ ] Admin role → access to admin pages
- [ ] Cashier role → access to payment pages only
- [ ] Class Leader → access to class pages only
- [ ] Member role → limited access
- [ ] Role changes reflected immediately

**Test Matrix:**
| Role | Dashboard | Members | Payments | Reports | Admin |
|------|-----------|---------|----------|---------|-------|
| Super Admin | ✅ | ✅ | ✅ | ✅ | ✅ |
| Admin | ✅ | ✅ | ✅ | ✅ | ✅ |
| Cashier | ✅ | ❌ | ✅ | ❌ | ❌ |
| Class Leader | ✅ | ✅ (own) | ❌ | ❌ | ❌ |
| Member | ✅ | ❌ | ❌ | ❌ | ❌ |

### Test 2.3: Context-Based Permissions
**Objective:** Verify context-aware permissions

**Test Cases:**
- [ ] Class leader can edit members in own class
- [ ] Class leader cannot edit members in other classes
- [ ] Org leader can view reports for own org
- [ ] Org leader cannot view reports for other orgs

**Test Script:**
```php
// Test class leader editing own class member
Login as: class_leader (class_id=5)
Access: /views/member_edit.php?id=123 (member in class 5)
Expected: 200, can edit

// Test class leader editing other class member
Login as: class_leader (class_id=5)
Access: /views/member_edit.php?id=456 (member in class 10)
Expected: 403, cannot edit
```

---

## 3. Role Management Testing

### Test 3.1: Create Role
**Objective:** Verify role creation works

**Test Cases:**
- [ ] Create role with valid data → success
- [ ] Create role with duplicate name → error
- [ ] Create role with empty name → validation error
- [ ] Assign permissions during creation
- [ ] Audit log created

**Test Script:**
```sql
-- Before test
SELECT COUNT(*) FROM roles; -- Note count

-- Create role via UI or API
POST /api/rbac/roles.php
{
    "name": "Test Role",
    "description": "Test description",
    "permissions": [77, 78, 79]
}

-- After test
SELECT COUNT(*) FROM roles; -- Should be +1
SELECT * FROM roles WHERE name = 'Test Role';
SELECT * FROM role_permissions WHERE role_id = LAST_INSERT_ID();
SELECT * FROM rbac_audit_log WHERE action = 'role_create';
```

### Test 3.2: Edit Role
**Objective:** Verify role editing works

**Test Cases:**
- [ ] Update role name → success
- [ ] Update role description → success
- [ ] Add permissions → success
- [ ] Remove permissions → success
- [ ] Cannot edit super admin role
- [ ] Audit log created

### Test 3.3: Delete Role
**Objective:** Verify role deletion works

**Test Cases:**
- [ ] Delete unused role → success
- [ ] Delete role with users → prevented or cascade
- [ ] Cannot delete super admin role
- [ ] Audit log created

### Test 3.4: Assign Role to User
**Objective:** Verify role assignment works

**Test Cases:**
- [ ] Assign role to user → success
- [ ] User inherits role permissions
- [ ] Change user role → permissions updated
- [ ] Remove role from user → permissions revoked

---

## 4. Permission Management Testing

### Test 4.1: View Permissions
**Objective:** Verify permission listing works

**Test Cases:**
- [ ] Load all permissions → success
- [ ] Group by category → correct grouping
- [ ] Filter by category → correct results
- [ ] Search permissions → correct results
- [ ] Performance < 100ms

**Test Script:**
```javascript
// Test API endpoint
GET /api/rbac/permissions.php?grouped=true

Expected:
- Status: 200
- Response time: < 100ms
- Data structure: {success: true, data: {permissions: {...}}}
- Total: 246 permissions
- Categories: 22
```

### Test 4.2: Create Permission
**Objective:** Verify permission creation works

**Test Cases:**
- [ ] Create with valid data → success
- [ ] Create with duplicate name → error
- [ ] Create with invalid category → error
- [ ] Audit log created

### Test 4.3: Edit Permission
**Objective:** Verify permission editing works

**Test Cases:**
- [ ] Update description → success
- [ ] Change category → success
- [ ] Toggle active status → success
- [ ] Cannot edit system permissions

### Test 4.4: Delete Permission
**Objective:** Verify permission deletion works

**Test Cases:**
- [ ] Delete unused permission → success
- [ ] Delete used permission → prevented
- [ ] Cannot delete system permissions

---

## 5. Audit Logging Testing

### Test 5.1: Permission Checks Logged
**Objective:** Verify permission checks are logged

**Test Cases:**
- [ ] Successful check → logged
- [ ] Failed check → logged
- [ ] Log includes user, permission, result
- [ ] Log includes timestamp
- [ ] Log includes IP address

**Test Script:**
```sql
-- Clear audit log
DELETE FROM rbac_audit_log WHERE created_at > NOW();

-- Perform action requiring permission
Access: /views/member_list.php

-- Check audit log
SELECT * FROM rbac_audit_log 
WHERE action = 'permission_check'
AND created_at > NOW() - INTERVAL 1 MINUTE;

Expected: 1 row with:
- user_id
- permission_name = 'view_member'
- result = 'granted' or 'denied'
- ip_address
```

### Test 5.2: Role Changes Logged
**Objective:** Verify role changes are logged

**Test Cases:**
- [ ] Role created → logged
- [ ] Role updated → logged
- [ ] Role deleted → logged
- [ ] Permissions added → logged
- [ ] Permissions removed → logged

### Test 5.3: Audit Log Viewing
**Objective:** Verify audit logs can be viewed

**Test Cases:**
- [ ] View all logs → success
- [ ] Filter by action → correct results
- [ ] Filter by user → correct results
- [ ] Filter by date → correct results
- [ ] Pagination works

---

## 6. Performance Testing

### Test 6.1: Page Load Times
**Objective:** Verify pages load quickly

**Target:** All pages < 1 second

**Test Cases:**
- [ ] Dashboard → < 500ms
- [ ] Member list → < 1s
- [ ] Payment list → < 1s
- [ ] RBAC dashboard → < 500ms
- [ ] Role list → < 500ms
- [ ] Permission list → < 500ms

**Test Script:**
```javascript
// Use browser DevTools Network tab
// Or use this script:
const pages = [
    '/views/user_dashboard.php',
    '/views/member_list.php',
    '/views/payment_list.php',
    '/views/rbac_dashboard.php',
    '/views/role_list.php',
    '/views/permission_list.php'
];

pages.forEach(page => {
    const start = performance.now();
    fetch(page).then(() => {
        const time = performance.now() - start;
        console.log(`${page}: ${time}ms`);
    });
});
```

### Test 6.2: API Response Times
**Objective:** Verify API endpoints are fast

**Target:** All APIs < 200ms

**Test Cases:**
- [ ] GET /api/rbac/permissions.php → < 100ms
- [ ] GET /api/rbac/roles.php → < 100ms
- [ ] GET /api/rbac/audit.php → < 200ms
- [ ] POST /api/rbac/roles.php → < 200ms
- [ ] PUT /api/rbac/roles.php → < 200ms

### Test 6.3: Database Query Performance
**Objective:** Verify database queries are optimized

**Test Cases:**
- [ ] Permission check query → < 10ms
- [ ] Role permissions query → < 20ms
- [ ] Audit log insert → < 10ms
- [ ] No N+1 queries
- [ ] Proper indexes used

**Test Script:**
```sql
-- Enable query profiling
SET profiling = 1;

-- Run permission check
SELECT COUNT(*) FROM role_permissions rp
JOIN user_roles ur ON rp.role_id = ur.role_id
JOIN permissions p ON rp.permission_id = p.id
WHERE ur.user_id = 3 AND p.name = 'view_member';

-- Check query time
SHOW PROFILES;

Expected: < 0.01 seconds
```

### Test 6.4: Concurrent Users
**Objective:** Verify system handles multiple users

**Test Cases:**
- [ ] 10 concurrent users → no errors
- [ ] 50 concurrent users → acceptable performance
- [ ] 100 concurrent users → system stable
- [ ] No deadlocks
- [ ] No race conditions

---

## 7. Security Testing

### Test 7.1: SQL Injection
**Objective:** Verify SQL injection is prevented

**Test Cases:**
- [ ] Login form → protected
- [ ] Permission checks → protected
- [ ] Role management → protected
- [ ] All user inputs → sanitized

**Test Script:**
```php
// Test SQL injection in login
POST /login.php
{
    "username": "admin' OR '1'='1",
    "password": "anything"
}
Expected: Login fails, no SQL error

// Test SQL injection in permission check
has_permission("view_member' OR '1'='1")
Expected: Returns false, no SQL error
```

### Test 7.2: XSS (Cross-Site Scripting)
**Objective:** Verify XSS is prevented

**Test Cases:**
- [ ] Role name with script tag → escaped
- [ ] Permission description with script → escaped
- [ ] All user inputs → escaped
- [ ] No inline JavaScript in output

**Test Script:**
```php
// Create role with XSS attempt
POST /api/rbac/roles.php
{
    "name": "<script>alert('XSS')</script>",
    "description": "Test"
}

// View role list
GET /views/role_list.php

Expected: Script tag displayed as text, not executed
```

### Test 7.3: CSRF (Cross-Site Request Forgery)
**Objective:** Verify CSRF is prevented

**Test Cases:**
- [ ] Forms have CSRF tokens
- [ ] API endpoints validate tokens
- [ ] Expired tokens rejected
- [ ] Missing tokens rejected

### Test 7.4: Session Security
**Objective:** Verify sessions are secure

**Test Cases:**
- [ ] Session ID regenerated on login
- [ ] Session cookie has HttpOnly flag
- [ ] Session cookie has Secure flag (HTTPS)
- [ ] Session timeout works
- [ ] Session fixation prevented

### Test 7.5: Authorization Bypass
**Objective:** Verify authorization cannot be bypassed

**Test Cases:**
- [ ] Direct URL access → blocked
- [ ] API direct access → blocked
- [ ] Parameter tampering → blocked
- [ ] Role ID manipulation → blocked

**Test Script:**
```php
// Try to access admin page as regular user
Login as: regular_user
Access: /views/role_list.php
Expected: 403 Forbidden

// Try to manipulate role_id in session
$_SESSION['role_id'] = 1; // Try to become admin
Access: /views/role_list.php
Expected: Still blocked (session validation)
```

---

## 8. User Acceptance Testing

### Test 8.1: Admin User Scenarios
**Objective:** Verify admin can perform all tasks

**Scenarios:**
- [ ] Create new role
- [ ] Assign permissions to role
- [ ] Assign role to user
- [ ] View audit logs
- [ ] Manage permissions
- [ ] Use RBAC dashboard

### Test 8.2: Cashier User Scenarios
**Objective:** Verify cashier can perform payment tasks

**Scenarios:**
- [ ] View dashboard
- [ ] Create payment
- [ ] View payment list
- [ ] View payment statistics
- [ ] Cannot access member management
- [ ] Cannot access admin pages

### Test 8.3: Class Leader Scenarios
**Objective:** Verify class leader can manage own class

**Scenarios:**
- [ ] View own class members
- [ ] Mark attendance for own class
- [ ] Cannot view other classes
- [ ] Cannot access payments
- [ ] Cannot access admin pages

### Test 8.4: Regular Member Scenarios
**Objective:** Verify member has limited access

**Scenarios:**
- [ ] View own profile
- [ ] Edit own profile
- [ ] View dashboard
- [ ] Cannot access admin features
- [ ] Cannot view other members

---

## 📋 Test Execution Checklist

### Pre-Testing Setup
- [ ] Backup database
- [ ] Clear audit logs
- [ ] Create test users for each role
- [ ] Prepare test data
- [ ] Document current state

### Testing Environment
- [ ] PHP version: 8.2.12
- [ ] MySQL version: 8.0+
- [ ] Browser: Chrome/Firefox latest
- [ ] Server: XAMPP on Windows

### Test Users
```sql
-- Create test users
INSERT INTO users (username, password, role_id) VALUES
('test_admin', 'hashed_password', 1),
('test_cashier', 'hashed_password', 5),
('test_class_leader', 'hashed_password', 6),
('test_member', 'hashed_password', 7);
```

### During Testing
- [ ] Record all test results
- [ ] Screenshot any errors
- [ ] Note performance metrics
- [ ] Document bugs found
- [ ] Track test coverage

### Post-Testing
- [ ] Analyze results
- [ ] Create bug reports
- [ ] Update documentation
- [ ] Plan fixes
- [ ] Retest after fixes

---

## 🐛 Bug Tracking Template

```markdown
### Bug #XXX: [Title]

**Severity:** Critical / High / Medium / Low
**Category:** Authentication / Authorization / Performance / UI
**Found By:** [Name]
**Date:** [Date]

**Description:**
[Detailed description of the bug]

**Steps to Reproduce:**
1. [Step 1]
2. [Step 2]
3. [Step 3]

**Expected Result:**
[What should happen]

**Actual Result:**
[What actually happens]

**Screenshots:**
[Attach screenshots]

**Environment:**
- Browser: [Browser name and version]
- PHP Version: [Version]
- Database: [Version]

**Status:** Open / In Progress / Fixed / Closed
**Assigned To:** [Name]
**Fix Version:** [Version]
```

---

## 📊 Test Metrics

### Coverage Metrics
- **Total Test Cases:** TBD
- **Passed:** TBD
- **Failed:** TBD
- **Blocked:** TBD
- **Not Run:** TBD
- **Pass Rate:** TBD%

### Performance Metrics
- **Average Page Load:** TBD ms
- **Average API Response:** TBD ms
- **Database Query Time:** TBD ms
- **Concurrent Users Supported:** TBD

### Security Metrics
- **Vulnerabilities Found:** TBD
- **Critical Issues:** TBD
- **High Issues:** TBD
- **Medium Issues:** TBD
- **Low Issues:** TBD

---

## 🎯 Success Criteria

### Must Have (Critical)
- ✅ All authentication tests pass
- ✅ All authorization tests pass
- ✅ No critical security vulnerabilities
- ✅ All pages load < 2 seconds
- ✅ No data loss or corruption

### Should Have (High Priority)
- ✅ All role management tests pass
- ✅ All permission management tests pass
- ✅ Audit logging works correctly
- ✅ Pages load < 1 second
- ✅ No high-severity bugs

### Nice to Have (Medium Priority)
- ✅ All performance targets met
- ✅ All UAT scenarios pass
- ✅ No medium-severity bugs
- ✅ Complete documentation

---

## 📅 Testing Schedule

### Week 1: Core Functionality
- Day 1-2: Authentication & Authorization
- Day 3-4: Role & Permission Management
- Day 5: Audit Logging

### Week 2: Performance & Security
- Day 1-2: Performance Testing
- Day 3-4: Security Testing
- Day 5: Bug Fixes

### Week 3: User Acceptance
- Day 1-3: UAT with different roles
- Day 4-5: Final bug fixes and retesting

---

## 🚀 Next Steps

1. **Review this test plan**
2. **Set up test environment**
3. **Create test users**
4. **Begin authentication testing**
5. **Document all results**
6. **Fix any issues found**
7. **Retest after fixes**
8. **Move to Phase 5: Deployment**

---

**Document Version:** 1.0  
**Last Updated:** November 15, 2025  
**Status:** Ready for Testing  
**Next Review:** After Week 1 Testing
