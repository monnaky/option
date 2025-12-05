# Emergency Production Deployment Guide
## Critical Fixes for Coolify Production Environment

**Status:** ✅ All fixes ready for immediate deployment

---

## Quick Summary

Three critical production errors have been fixed:

1. ✅ **WebSocketClient Class Not Found** - Fixed autoloader
2. ✅ **WebSocket URL Duplication** - Fixed URL construction
3. ✅ **Database Boolean Error** - Fixed type conversion

---

## Immediate Deployment Steps

### Step 1: Deploy Code Changes

```bash
# Commit and push fixes
git add app/autoload.php app/services/DerivAPI.php app/utils/DatabaseHelper.php api/auth.php
git commit -m "EMERGENCY FIX: WebSocketClient autoload, URL construction, database boolean"
git push origin main
```

### Step 2: Run Database SQL Patch

**In Coolify Database Console or via MySQL client:**

```sql
-- Run the fix script
SOURCE database/fixes/002_fix_boolean_schema.sql;

-- OR manually run:
UPDATE settings SET is_bot_active = 0 WHERE is_bot_active IS NULL OR is_bot_active = '';
ALTER TABLE settings MODIFY COLUMN is_bot_active TINYINT(1) DEFAULT 0 NOT NULL;
UPDATE settings SET reset_date = DATE_ADD(CURDATE(), INTERVAL 1 DAY) WHERE reset_date IS NULL OR reset_date = '0000-00-00';
```

### Step 3: Verify Deployment

1. **Check Application Logs:**
   - No "Class not found" errors
   - No WebSocket URL errors
   - No database boolean errors

2. **Test WebSocket Connection:**
   - Try user login
   - Try starting trading bot
   - Check for WebSocket connection success

3. **Test Settings Update:**
   - Update user settings
   - Verify no database errors

---

## Files Changed

| File | Change | Impact |
|------|--------|--------|
| `app/autoload.php` | Added WebSocketClient require | Fixes class not found |
| `app/services/DerivAPI.php` | Fixed URL construction | Fixes duplicate wss:// |
| `app/utils/DatabaseHelper.php` | Boolean to integer conversion | Fixes database errors |
| `api/auth.php` | Boolean to integer conversion | Fixes registration errors |

---

## Verification Commands

### Check WebSocketClient Loading
```php
// Should not throw error
$client = new \App\Services\WebSocketClient('wss://ws.derivws.com/websockets/v3?app_id=105326');
```

### Check Database Schema
```sql
DESCRIBE settings;
-- is_bot_active should be TINYINT(1) DEFAULT 0 NOT NULL
```

### Check Existing Data
```sql
SELECT COUNT(*) FROM settings WHERE is_bot_active IS NULL OR is_bot_active = '';
-- Should return 0
```

---

## Rollback Plan

If issues persist:

```bash
# Revert to previous commit
git revert HEAD
git push origin main
```

Then investigate further.

---

## Expected Results

After deployment:

✅ No "Class not found" errors in logs  
✅ WebSocket URLs are correctly formatted  
✅ Settings updates work without database errors  
✅ User registration works  
✅ Trading bot can start  

---

## Monitoring

Monitor for 10-15 minutes after deployment:

1. Check error logs every 2 minutes
2. Test critical user flows
3. Monitor database for errors
4. Check WebSocket connections

---

## Support

If issues persist after deployment:

1. Check application logs: `/var/log/php/error.log` or `error_log`
2. Check database logs in Coolify
3. Verify environment variables are set correctly
4. Test WebSocket connection manually

---

**Deployment Time:** ~5 minutes  
**Risk Level:** Low (targeted fixes)  
**Testing Required:** Yes (verify all three fixes)

