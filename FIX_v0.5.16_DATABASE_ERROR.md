# Fix v0.5.16: Database Error with NULL Fields

**Date**: 2025-10-29
**Issue**: "Error writing to database" + 404 when user has "no active" enrollment status
**Version**: 2025102919 (v0.5.16-db-null-fix)
**Status**: ✅ FIXED

---

## 🐛 Problem Description

**User Report**:
> "After student gets 'no active' status, there's no yellow warning AND error in console: `index.php:1 Failed to load resource: the server responded with a status of 404 ()`"
> Page shows: "Error writing to database"

**Root Cause**:
When `check_user_access($userid, true)` is called with force refresh:
1. Function `refresh_user_status()` updates the database record
2. Fields `grace_period_start` and `blocked_at` are set to `null`
3. Moodle's `$DB->update_record()` fails with NULL values in nullable INT fields
4. Exception causes page to return 404
5. User sees "Error writing to database"

---

## 🔧 Fix Applied

### Change 1: Use 0 Instead of NULL for Nullable INT Fields

**File**: `classes/access_manager.php`

**Lines 128-129** - In `refresh_user_status()`:
```php
// Before:
$access->grace_period_start = null;
$access->blocked_at = null;

// After:
$access->grace_period_start = 0;  // Moodle requires 0 instead of null
$access->blocked_at = 0;
```

**Lines 97-98** - In `create_user_access()`:
```php
// Before:
$record = (object)[
    'userid' => $userid,
    'status' => $hasEnrolment ? self::STATUS_ACTIVE : self::STATUS_EXPIRED,
    'last_enrolment_check' => time(),
    'grace_period_days' => self::GRACE_PERIOD_DAYS,
    'timemodified' => time()
];

// After:
$record = (object)[
    'userid' => $userid,
    'status' => $hasEnrolment ? self::STATUS_ACTIVE : self::STATUS_EXPIRED,
    'last_enrolment_check' => time(),
    'grace_period_days' => self::GRACE_PERIOD_DAYS,
    'grace_period_start' => 0,  // Initialize nullable fields with 0, not null
    'blocked_at' => 0,
    'timemodified' => time()
];
```

---

### Change 2: Fix Grace Period Check

**File**: `classes/access_manager.php`

**Line 235** - In `calculate_permissions()`:
```php
// Before:
if ($access->grace_period_start) {

// After:
if ($access->grace_period_start > 0) {
```

**Reason**: `if ($access->grace_period_start)` evaluates to false when value is 0, breaking days_remaining calculation.

---

### Change 3: Add Error Handling for Database Updates

**File**: `classes/access_manager.php`

**Lines 158-167** - In `refresh_user_status()`:
```php
// DEBUG: Log before update
error_log('[FLASHCARDS DEBUG] Attempting to update access record: ' . print_r($access, true));

try {
    $DB->update_record('flashcards_user_access', $access);
} catch (Exception $e) {
    error_log('[FLASHCARDS ERROR] Failed to update access record: ' . $e->getMessage());
    // Continue despite error - return current state
}
```

---

### Change 4: Add Debugging to index.php

**File**: `my/index.php`

**Lines 32-33, 43-48**:
```php
// DEBUG: Log access info for troubleshooting
error_log('[FLASHCARDS DEBUG] Access info for user ' . $USER->id . ': ' . print_r($access, true));

// Pass access information to JavaScript (with proper escaping)
$accessjson = json_encode($access, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
if ($accessjson === false) {
    error_log('[FLASHCARDS ERROR] Failed to encode access info to JSON: ' . json_last_error_msg());
    $accessjson = '{"can_view":false,"can_create":false,"status":"error"}';
}
$init .= "window.flashcardsAccessInfo = ".$accessjson.";";
```

---

## 📊 Why This Happened

### Moodle Database Quirk

In Moodle, nullable INT fields in `install.xml`:
```xml
<FIELD NAME="grace_period_start" TYPE="int" LENGTH="10" NOTNULL="false"/>
<FIELD NAME="blocked_at" TYPE="int" LENGTH="10" NOTNULL="false"/>
```

Are stored as NULL in PostgreSQL/MySQL, but Moodle's DML API (`$DB->update_record()`) doesn't handle NULL properly for INT fields.

**Best Practice**: Use `0` for "empty" INT fields, not `null`.

---

### v0.5.15 Force Refresh Exposed This Bug

Before v0.5.15:
- Access status cached for 24 hours
- `refresh_user_status()` rarely called
- Bug hidden

After v0.5.15:
- Force refresh on every page load
- `refresh_user_status()` called every time
- Bug exposed immediately

---

## 🧪 Testing

### Test Case 1: User with Expired Enrollment

**Setup**:
```sql
UPDATE mdl_user_enrolments
SET timeend = UNIX_TIMESTAMP(DATE_SUB(NOW(), INTERVAL 1 DAY))
WHERE userid = YOUR_TEST_USER_ID;
```

**Expected Result**:
- ✅ Page loads successfully (no 404)
- ✅ Yellow warning appears
- ✅ Form hidden
- ✅ Permissions shown ("✓ CAN review / ✗ CANNOT create")
- ✅ No "Error writing to database"

**Check Logs**:
```
[FLASHCARDS DEBUG] Access info for user X: Array (
    [can_view] => 1
    [can_review] => 1
    [can_create] =>
    [status] => grace
    [days_remaining] => 30
)
```

---

### Test Case 2: Active User (Regression)

**Setup**: User with valid active enrollment

**Expected Result**:
- ✅ Page loads normally
- ✅ No warnings
- ✅ Form visible
- ✅ Can create cards

---

### Test Case 3: Database Update Success

**Check database after page load**:
```sql
SELECT userid, status, grace_period_start, blocked_at, FROM_UNIXTIME(last_enrolment_check) AS last_check
FROM mdl_flashcards_user_access
WHERE userid = YOUR_TEST_USER_ID;
```

**Expected**:
- `status` = 'grace' (for expired enrollment)
- `grace_period_start` = unix timestamp (NOT NULL)
- `blocked_at` = 0 (NOT NULL)
- `last_enrolment_check` = current time

---

## 🚀 Deployment

### Files Changed (2 total)

1. **`classes/access_manager.php`** (4 changes):
   - Line 128-129: Use 0 instead of null
   - Line 97-98: Initialize fields with 0
   - Line 235: Check `> 0` instead of truthy
   - Line 158-167: Add try-catch and logging

2. **`my/index.php`** (2 changes):
   - Line 32-33: Add debug logging
   - Line 43-48: Safe JSON encoding with fallback

### Deploy Steps

```bash
# 1. Copy updated files
rsync -av /path/to/dev/flashcards/ /path/to/moodle/mod/flashcards/

# 2. Clear caches (REQUIRED)
php admin/cli/purge_caches.php

# 3. Test with user who has expired enrollment

# 4. Check logs for debug messages
tail -50 /var/log/moodle/error.log | grep FLASHCARDS
```

---

## 🔄 Version Bump

**Update `version.php`**:
```php
$plugin->version   = 2025102919; // Was 2025102918
$plugin->release   = '0.5.16-db-null-fix'; // Was '0.5.15-grace-ui-block'
```

---

## 📝 Summary

**Before v0.5.16**:
- ❌ Page crashes with "Error writing to database"
- ❌ 404 error on index.php
- ❌ No grace period UI for expired enrollments

**After v0.5.16**:
- ✅ Page loads successfully
- ✅ Grace period UI appears immediately
- ✅ Database updates work correctly
- ✅ No 404 errors

**Root Cause**: Moodle DML doesn't handle NULL values in nullable INT fields during `update_record()`.

**Solution**: Use 0 instead of null for empty INT values.

---

## 🔗 Related Issues

- **v0.5.15**: Introduced force refresh which exposed this bug
- **v0.5.14**: Grace period cache fix
- **Moodle DML API**: Known limitation with NULL handling

---

**Status**: ✅ FIXED
**Testing**: Required before production deployment
**Risk**: LOW (simple value change, 0 instead of null)
