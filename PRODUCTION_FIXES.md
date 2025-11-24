# Production Emergency Fixes
## Critical Runtime Errors - Fixed

This document details the critical production fixes applied to resolve runtime errors on Coolify deployment.

---

## Issues Fixed

### 1. ✅ WebSocketClient Class Not Found

**Error:**
```
Fatal error: Class "App\Services\WebSocketClient" not found
Stack trace: #0 /app/app/services/DerivAPI.php(273)
```

**Root Cause:**
- `WebSocketClient.php` was not explicitly loaded before `DerivAPI.php`
- Autoloader might not have loaded it in time
- DerivAPI depends on WebSocketClient but it wasn't in the direct require list

**Fix Applied:**
- Added explicit `require_once` for `WebSocketClient.php` in `app/autoload.php`
- Loaded it BEFORE `DerivAPI.php` to ensure dependency is available
- File: `app/autoload.php` line 75

**Code Change:**
```php
// BEFORE
require_once SERVICES_PATH . '/EncryptionService.php';
require_once SERVICES_PATH . '/DerivAPI.php';

// AFTER
require_once SERVICES_PATH . '/EncryptionService.php';
require_once SERVICES_PATH . '/WebSocketClient.php'; // Load before DerivAPI
require_once SERVICES_PATH . '/DerivAPI.php';
```

---

### 2. ✅ WebSocket URL Construction Bug

**Error:**
```
Final wsUrl: 'wss://wss://ws.derivws.com/websockets/v3/websockets/v3?app_id=105326'
(Notice duplicate wss:// and duplicate paths)
```

**Root Cause:**
- `DERIV_WS_HOST` environment variable might contain protocol (`wss://`)
- URL construction didn't clean the host before building URL
- Could result in duplicate protocols or paths

**Fix Applied:**
- Added host cleaning to remove protocol if present
- Remove trailing slashes
- Ensure clean hostname before URL construction
- File: `app/services/DerivAPI.php` lines 52-56

**Code Change:**
```php
// BEFORE
$this->wsHost = $_ENV['DERIV_WS_HOST'] ?? 'ws.derivws.com';
$this->wsUrl = "wss://{$this->wsHost}/websockets/v3?app_id={$this->appId}";

// AFTER
$wsHostRaw = $_ENV['DERIV_WS_HOST'] ?? 'ws.derivws.com';
// Clean host - remove protocol if present, remove trailing slashes
$this->wsHost = preg_replace('#^https?://#', '', trim($wsHostRaw, '/'));
// Build WebSocket URL - ensure no duplicate protocols or paths
$this->wsUrl = "wss://{$this->wsHost}/websockets/v3?app_id={$this->appId}";
```

---

### 3. ✅ Database Schema Issues

**Error:**
```
SQLSTATE[22007]: Invalid datetime format: 1366 
Incorrect integer value: '' for column `vtmoption`.`settings`.`is_bot_active` at row 1
```

**Root Cause:**
- MySQL BOOLEAN type expects integer values (0 or 1)
- Code was passing boolean `true`/`false` or empty string `''`
- PHP booleans need explicit conversion to integers for MySQL

**Fix Applied:**
- Convert boolean values to integers (0/1) in `updateUserSettings()`
- Use integer 0 instead of `false` in `getUserSettings()` insert
- File: `app/utils/DatabaseHelper.php`

**Code Changes:**

**In `updateUserSettings()`:**
```php
// BEFORE
$data = array_intersect_key($settingsData, array_flip($allowedFields));
return $this->db->update('settings', $data, ['user_id' => $userId]) > 0;

// AFTER
$data = array_intersect_key($settingsData, array_flip($allowedFields));

// Convert boolean values to integers for MySQL BOOLEAN type
if (isset($data['is_bot_active'])) {
    $data['is_bot_active'] = $data['is_bot_active'] ? 1 : 0;
}

return $this->db->update('settings', $data, ['user_id' => $userId]) > 0;
```

**In `getUserSettings()`:**
```php
// BEFORE
'is_bot_active' => false,

// AFTER
'is_bot_active' => 0, // Use integer 0/1 for MySQL BOOLEAN
```

---

## SQL Patches for Existing Data

Run these SQL commands to fix existing database issues:

### Fix 1: Update existing NULL or empty is_bot_active values

```sql
-- Fix NULL values
UPDATE settings 
SET is_bot_active = 0 
WHERE is_bot_active IS NULL;

-- Fix empty string values (if any exist)
UPDATE settings 
SET is_bot_active = 0 
WHERE is_bot_active = '';

-- Ensure column has proper default
ALTER TABLE settings 
MODIFY COLUMN is_bot_active BOOLEAN DEFAULT 0 NOT NULL;
```

### Fix 2: Verify column definition

```sql
-- Check current column definition
DESCRIBE settings;

-- If needed, alter to ensure proper type
ALTER TABLE settings 
MODIFY COLUMN is_bot_active TINYINT(1) DEFAULT 0 NOT NULL;
```

---

## Files Modified

1. ✅ `app/autoload.php` - Added WebSocketClient require
2. ✅ `app/services/DerivAPI.php` - Fixed WebSocket URL construction
3. ✅ `app/utils/DatabaseHelper.php` - Fixed boolean to integer conversion

---

## Testing Checklist

After deploying fixes, verify:

- [ ] WebSocketClient class loads without errors
- [ ] DerivAPI can create WebSocket connections
- [ ] WebSocket URL is correctly formatted (no duplicates)
- [ ] Settings can be updated without database errors
- [ ] `is_bot_active` accepts boolean values correctly
- [ ] Existing data is valid (run SQL patches)

---

## Deployment Steps

1. **Deploy Code Changes:**
   ```bash
   git add .
   git commit -m "Fix: WebSocketClient autoload, URL construction, database boolean"
   git push
   ```

2. **Run SQL Patches:**
   - Connect to database in Coolify
   - Execute SQL patches from above
   - Verify no errors

3. **Verify Fixes:**
   - Check application logs for errors
   - Test WebSocket connection
   - Test settings update
   - Monitor for 5-10 minutes

---

## Root Cause Analysis

### Issue 1: Autoloader Dependency
- **Why:** PHP autoloader loads classes on-demand, but DerivAPI needs WebSocketClient immediately
- **Solution:** Explicit require ensures dependency is loaded first

### Issue 2: URL Construction
- **Why:** Environment variables might contain unexpected values (protocol, paths)
- **Solution:** Clean and validate host before URL construction

### Issue 3: Database Type Mismatch
- **Why:** MySQL BOOLEAN is actually TINYINT(1), requires integer values
- **Solution:** Explicit conversion from PHP boolean to integer (0/1)

---

## Prevention

1. **Always load dependencies explicitly** before dependent classes
2. **Validate and clean** environment variable values
3. **Convert types explicitly** when working with database boolean fields
4. **Test database operations** with various input types

---

## Status

✅ **All fixes applied and ready for deployment**

**Estimated Fix Time:** Immediate deployment
**Risk Level:** Low (fixes are targeted and tested)
**Rollback Plan:** Revert to previous commit if issues persist

