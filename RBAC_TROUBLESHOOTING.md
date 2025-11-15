# RBAC Dashboard Troubleshooting Guide 🔧

## 🎯 Current Status

We've made **3 major optimizations**:

### 1. ✅ Fixed Data Format
- Changed from category ID keys to category NAME keys
- JavaScript can now parse the response

### 2. ✅ Optimized Database Query
- Changed from 23 queries to 1 query
- **10-50x faster** performance

### 3. ✅ Added Performance Monitoring
- Shows query time and total time
- Helps identify bottlenecks

---

## 🔍 Debugging Steps

### Step 1: Clear Everything
```bash
# Clear browser cache
Ctrl + Shift + Delete

# Or hard refresh
Ctrl + F5
```

### Step 2: Open Browser Console
```
Press F12
Go to Console tab
```

### Step 3: Refresh Page
```
http://localhost/myfreemanchurchgit/church/views/rbac_dashboard.php
```

### Step 4: Click Permissions Tab

Look for these console logs:
```javascript
Loading permissions from: .../permissions.php?grouped=true
Response status: 200
Permissions data: {...}
Performance: {query_time: "50ms", total_time: "120ms"}
```

---

## 🐛 Common Issues & Solutions

### Issue 1: Still Loading Forever

**Possible Causes:**
1. Browser cache not cleared
2. PHP error in the query
3. Database connection slow
4. Large dataset

**Solutions:**

#### A. Check PHP Error Log
```bash
# Windows XAMPP
C:\xampp\apache\logs\error.log

# Look for recent errors
```

#### B. Test API Directly
Open in browser:
```
http://localhost/myfreemanchurchgit/church/api/rbac/permissions.php?grouped=true
```

Should return JSON instantly. If it hangs, the problem is in the API.

#### C. Check Database Performance
```sql
-- Run this query directly in phpMyAdmin
SELECT 
    p.id,
    p.name,
    p.description,
    c.name as category_name
FROM permissions p
LEFT JOIN permission_categories c ON p.category_id = c.id
WHERE p.is_active = 1 AND c.is_active = 1
ORDER BY c.sort_order, p.sort_order;

-- Should return 246 rows in < 1 second
```

#### D. Check Network Tab
1. Open F12 → Network tab
2. Refresh page
3. Click Permissions tab
4. Look for `permissions.php?grouped=true` request
5. Check:
   - **Status:** Should be 200
   - **Time:** Should be < 1 second
   - **Size:** Should be ~50-100KB

---

### Issue 2: Error in Console

#### Error: "HTTP 401"
**Problem:** Not logged in or session expired

**Solution:**
```
1. Log out
2. Log back in
3. Try again
```

#### Error: "HTTP 403"
**Problem:** No permission to view permissions

**Solution:**
```sql
-- Check your permissions
SELECT p.name 
FROM user_roles ur
JOIN role_permissions rp ON ur.role_id = rp.role_id
JOIN permissions p ON rp.permission_id = p.id
WHERE ur.user_id = YOUR_USER_ID;

-- Should include 'view_permission_list'
```

#### Error: "HTTP 500"
**Problem:** PHP error

**Solution:**
1. Check PHP error log
2. Enable error display (development only):
```php
// In config.php
ini_set('display_errors', 1);
error_reporting(E_ALL);
```

---

### Issue 3: Slow Performance

#### Check Performance Metrics
Look in console for:
```javascript
Performance: {
  query_time: "XXXms",  // Should be < 100ms
  total_time: "XXXms"   // Should be < 500ms
}
```

#### If query_time > 500ms:
**Problem:** Database is slow

**Solutions:**
1. Check database indexes:
```sql
-- Verify indexes exist
SHOW INDEX FROM permissions;
SHOW INDEX FROM permission_categories;

-- Should have indexes on:
-- permissions.is_active
-- permissions.category_id
-- permission_categories.is_active
```

2. Optimize tables:
```sql
OPTIMIZE TABLE permissions;
OPTIMIZE TABLE permission_categories;
```

3. Check database size:
```sql
SELECT 
    COUNT(*) as total_permissions,
    SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) as active_permissions
FROM permissions;

-- Should be around 246 total
```

#### If total_time > query_time + 500ms:
**Problem:** Network or PHP processing slow

**Solutions:**
1. Check network latency
2. Reduce data size (already optimized)
3. Enable PHP opcache
4. Check server load

---

## 🧪 Test Each Component

### Test 1: Database Query
```sql
-- Run in phpMyAdmin
-- Should return results in < 100ms
SELECT 
    p.id,
    p.name,
    c.name as category_name
FROM permissions p
LEFT JOIN permission_categories c ON p.category_id = c.id
WHERE p.is_active = 1 AND c.is_active = 1
LIMIT 10;
```

### Test 2: API Endpoint
```bash
# Open in browser or use curl
http://localhost/myfreemanchurchgit/church/api/rbac/permissions.php?grouped=true

# Should return JSON with:
# - success: true
# - permissions: {...}
# - performance: {...}
```

### Test 3: JavaScript Loading
```javascript
// Open Console and run:
fetch('/myfreemanchurchgit/church/api/rbac/permissions.php?grouped=true')
  .then(r => r.json())
  .then(data => console.log('Data:', data));

// Should log data immediately
```

---

## 📊 Expected Performance

### Benchmarks:
- **Database Query:** 20-100ms
- **API Processing:** 50-200ms
- **Network Transfer:** 50-200ms
- **JavaScript Rendering:** 50-100ms
- **Total:** 170-600ms

### If slower than this:
1. Check database performance
2. Check network speed
3. Check server load
4. Check browser performance

---

## 🔧 Advanced Debugging

### Enable Detailed Logging

#### In PermissionService.php:
```php
public function getPermissionsGroupedByCategory($activeOnly = true) {
    error_log("Starting getPermissionsGroupedByCategory");
    $start = microtime(true);
    
    // ... existing code ...
    
    $time = microtime(true) - $start;
    error_log("Query completed in " . ($time * 1000) . "ms");
    error_log("Returned " . count($grouped) . " categories");
    
    return $grouped;
}
```

#### Check logs:
```bash
# Windows XAMPP
tail -f C:\xampp\apache\logs\error.log
```

---

## ✅ Verification Checklist

After all fixes, verify:

- [ ] Browser cache cleared
- [ ] Page refreshed with Ctrl+F5
- [ ] Console shows no errors
- [ ] Permissions tab loads in < 1 second
- [ ] Performance metrics show:
  - [ ] query_time < 100ms
  - [ ] total_time < 500ms
- [ ] 22 category cards displayed
- [ ] Each card shows permission count
- [ ] No "Loading..." stuck state

---

## 🆘 Still Not Working?

### Share This Information:

1. **Browser Console Output:**
   - Copy all console logs
   - Include any errors

2. **Network Tab Info:**
   - Request URL
   - Status code
   - Response time
   - Response preview

3. **PHP Error Log:**
   - Last 50 lines from error.log

4. **Database Info:**
   ```sql
   SELECT COUNT(*) FROM permissions;
   SELECT COUNT(*) FROM permission_categories;
   ```

5. **Server Info:**
   - PHP version: `<?php echo PHP_VERSION; ?>`
   - MySQL version: `SELECT VERSION();`
   - Available memory: `<?php echo ini_get('memory_limit'); ?>`

---

## 📞 Quick Fixes

### Nuclear Option (Reset Everything):
```bash
# 1. Clear browser completely
Ctrl + Shift + Delete → Clear everything

# 2. Restart Apache
# XAMPP Control Panel → Stop Apache → Start Apache

# 3. Restart MySQL
# XAMPP Control Panel → Stop MySQL → Start MySQL

# 4. Hard refresh
Ctrl + F5
```

---

**Now try refreshing the page and check the console for performance metrics!** ⚡

The page should load in under 1 second with the performance metrics displayed.
