# Phase 4: Quick Start Testing Guide

## 🚀 Start Testing in 5 Minutes!

### Step 1: Create Test Users (2 minutes)

Run this SQL in phpMyAdmin:

```sql
-- Create test users for different roles
-- Note: Passwords are hashed, use actual hashing in production

-- 1. Test Admin
INSERT INTO users (username, email, password, role_id, is_active) 
VALUES ('test_admin', 'admin@test.com', MD5('test123'), 1, 1);

-- 2. Test Cashier  
INSERT INTO users (username, email, password, role_id, is_active)
VALUES ('test_cashier', 'cashier@test.com', MD5('test123'), 5, 1);

-- 3. Test Class Leader
INSERT INTO users (username, email, password, role_id, is_active)
VALUES ('test_leader', 'leader@test.com', MD5('test123'), 6, 1);

-- Verify users created
SELECT id, username, role_id FROM users WHERE username LIKE 'test_%';
```

### Step 2: Test Authentication (1 minute)

1. **Logout** if currently logged in
2. **Login** as `test_admin` / `test123`
3. **Verify** you see the dashboard
4. **Logout**
5. **Login** as `test_cashier` / `test123`
6. **Verify** you see cashier dashboard

✅ **Pass:** Can login with different users  
❌ **Fail:** Cannot login → Check credentials

### Step 3: Test Authorization (2 minutes)

#### Test as Cashier:
1. Login as `test_cashier`
2. Try to access: `/views/member_list.php`
3. **Expected:** 403 Forbidden
4. Try to access: `/views/payment_form.php`
5. **Expected:** 200 OK (can access)

✅ **Pass:** Cashier blocked from members, can access payments  
❌ **Fail:** Cashier can access members → Permission issue

#### Test as Admin:
1. Login as `test_admin`
2. Try to access: `/views/role_list.php`
3. **Expected:** 200 OK
4. Try to access: `/views/rbac_dashboard.php`
5. **Expected:** 200 OK

✅ **Pass:** Admin can access all pages  
❌ **Fail:** Admin blocked → Check super admin bypass

---

## 🎯 Critical Tests (Must Pass)

### Test 1: Permission Check Works
```php
// Login as cashier
// Access: /views/member_list.php
// Expected: 403 Forbidden

// Login as admin
// Access: /views/member_list.php
// Expected: 200 OK
```

### Test 2: Role Management Works
```php
// Login as admin
// Go to: /views/role_list.php
// Click "Edit" on any role
// Add/remove permissions
// Save
// Expected: Changes saved, audit log created
```

### Test 3: Audit Logging Works
```sql
-- Clear audit log
DELETE FROM rbac_audit_log WHERE created_at > NOW() - INTERVAL 1 HOUR;

-- Login and access a page
-- Then check:
SELECT * FROM rbac_audit_log 
WHERE created_at > NOW() - INTERVAL 5 MINUTE
ORDER BY created_at DESC;

-- Expected: See permission_check entries
```

### Test 4: Performance is Good
```javascript
// Open browser DevTools (F12)
// Go to Network tab
// Access: /views/rbac_dashboard.php
// Click Permissions tab
// Check response time

// Expected: < 100ms
```

---

## 📊 Quick Test Results Template

```
Date: [Today's Date]
Tester: [Your Name]

✅ Authentication: PASS / FAIL
✅ Authorization: PASS / FAIL  
✅ Role Management: PASS / FAIL
✅ Audit Logging: PASS / FAIL
✅ Performance: PASS / FAIL

Issues Found:
1. [Issue description]
2. [Issue description]

Notes:
[Any additional notes]
```

---

## 🐛 Common Issues & Fixes

### Issue: Cannot login
**Fix:** Check if user exists, password is correct, session is working

### Issue: 403 on all pages
**Fix:** Check if permissions_v2.php is included, check role_permissions table

### Issue: Audit log empty
**Fix:** Check if rbac_audit_log table exists, check if logging is enabled

### Issue: Slow performance
**Fix:** Check database indexes, check query optimization, check server load

---

## 📞 Need Help?

1. Check `PHASE4_TEST_PLAN.md` for detailed tests
2. Check browser console (F12) for errors
3. Check PHP error log: `C:\xampp\apache\logs\error.log`
4. Check database for missing data

---

**Ready to start? Begin with Step 1!** 🚀
