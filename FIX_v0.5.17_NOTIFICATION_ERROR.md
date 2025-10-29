# Fix v0.5.17: Notification Error - fullmessageformat Cannot Be NULL

**Date**: 2025-10-29
**Issue**: "Error writing to database" when sending grace period notification
**Version**: 2025102920 (v0.5.17-notification-fix)
**Status**: ✅ FIXED

---

## 🐛 Problem Description

**User Report**:
> "After student gets 'no active' status, page shows 'Error writing to database' and 404 error."

**Debug Output**:
```
Debug info: Column 'fullmessageformat' cannot be null
INSERT INTO mdl_notifications (...) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?)
[array (
  0 => -10,
  1 => '522',
  2 => 'Flashcards: Grace period started',
  3 => 'You are no longer enrolled...',
  4 => NULL,  ← THIS IS THE PROBLEM
  5 => '<p>You are no longer enrolled...</p>',
  ...
)]
Error code: dmlwriteexception
```

**Stack Trace**:
```
line 322 of /mod/flashcards/classes/access_manager.php: call to message_send()
line 138 of /mod/flashcards/classes/access_manager.php: call to send_notification()
line 73 of /mod/flashcards/classes/access_manager.php: call to refresh_user_status()
line 30 of /mod/flashcards/my/index.php: call to check_user_access()
```

---

## 🔍 Root Cause

When user's enrollment expires and status transitions from "active" → "grace":
1. Function `refresh_user_status()` detects the transition (line 132-135)
2. Calls `send_notification($userid, 'grace_period_started')` (line 135)
3. Inside `send_notification()`, creates `$message` object
4. Sets `$message->fullmessage` and `$message->fullmessagehtml`
5. **BUT DOES NOT set `$message->fullmessageformat`** ❌
6. Moodle's `message_send()` tries to insert into database
7. Database field `fullmessageformat` is NOT NULL, but value is NULL
8. SQL error: "Column 'fullmessageformat' cannot be null"

---

## 🔧 Fix Applied

### Change: Add fullmessageformat to All Notification Types

**File**: `classes/access_manager.php`

**Lines 307, 314, 321** - Added `$message->fullmessageformat = FORMAT_HTML;`:

```php
// Before:
switch ($type) {
    case 'grace_period_started':
        $message->subject = get_string('notification_grace_subject', 'mod_flashcards');
        $message->fullmessage = get_string('notification_grace_message', 'mod_flashcards', self::GRACE_PERIOD_DAYS);
        $message->fullmessagehtml = get_string('notification_grace_message_html', 'mod_flashcards', self::GRACE_PERIOD_DAYS);
        break;

    case 'access_expiring_soon':
        $message->subject = get_string('notification_expiring_subject', 'mod_flashcards');
        $message->fullmessage = get_string('notification_expiring_message', 'mod_flashcards');
        $message->fullmessagehtml = get_string('notification_expiring_message_html', 'mod_flashcards');
        break;

    case 'access_expired':
        $message->subject = get_string('notification_expired_subject', 'mod_flashcards');
        $message->fullmessage = get_string('notification_expired_message', 'mod_flashcards');
        $message->fullmessagehtml = get_string('notification_expired_message_html', 'mod_flashcards');
        break;
}

// After:
switch ($type) {
    case 'grace_period_started':
        $message->subject = get_string('notification_grace_subject', 'mod_flashcards');
        $message->fullmessage = get_string('notification_grace_message', 'mod_flashcards', self::GRACE_PERIOD_DAYS);
        $message->fullmessagehtml = get_string('notification_grace_message_html', 'mod_flashcards', self::GRACE_PERIOD_DAYS);
        $message->fullmessageformat = FORMAT_HTML; // ✅ ADDED
        break;

    case 'access_expiring_soon':
        $message->subject = get_string('notification_expiring_subject', 'mod_flashcards');
        $message->fullmessage = get_string('notification_expiring_message', 'mod_flashcards');
        $message->fullmessagehtml = get_string('notification_expiring_message_html', 'mod_flashcards');
        $message->fullmessageformat = FORMAT_HTML; // ✅ ADDED
        break;

    case 'access_expired':
        $message->subject = get_string('notification_expired_subject', 'mod_flashcards');
        $message->fullmessage = get_string('notification_expired_message', 'mod_flashcards');
        $message->fullmessagehtml = get_string('notification_expired_message_html', 'mod_flashcards');
        $message->fullmessageformat = FORMAT_HTML; // ✅ ADDED
        break;
}
```

---

## 📚 What is FORMAT_HTML?

Moodle constant defined in `lib/moodlelib.php`:
```php
define('FORMAT_HTML', 1);    // HTML format
define('FORMAT_PLAIN', 2);   // Plain text format
define('FORMAT_MARKDOWN', 4); // Markdown format
```

This tells Moodle how to render the message text. Since we're using HTML in `fullmessagehtml`, we use `FORMAT_HTML`.

---

## 🧪 Testing

### Test Case 1: Expired Enrollment (Grace Period Trigger)

**Setup**:
```sql
UPDATE mdl_user_enrolments
SET timeend = UNIX_TIMESTAMP(DATE_SUB(NOW(), INTERVAL 1 DAY))
WHERE userid = YOUR_TEST_USER_ID;

-- Clear access cache to force fresh check
DELETE FROM mdl_flashcards_user_access WHERE userid = YOUR_TEST_USER_ID;
```

**Test**:
1. User visits `/mod/flashcards/my/index.php`
2. System detects expired enrollment
3. Transitions status from "active" → "grace"
4. Sends notification "Grace period started"

**Expected Result**:
- ✅ Page loads successfully (no 404)
- ✅ Yellow warning appears
- ✅ Form hidden
- ✅ Notification sent to user (check mdl_notifications table)
- ✅ No "Error writing to database"

**Verify Notification**:
```sql
SELECT * FROM mdl_notifications
WHERE useridto = YOUR_TEST_USER_ID
  AND component = 'mod_flashcards'
  AND eventtype = 'grace_period_started'
ORDER BY timecreated DESC
LIMIT 1;
```

Should show:
- `fullmessageformat` = 1 (FORMAT_HTML)
- `fullmessage` = plain text version
- `fullmessagehtml` = HTML version with `<p>` tags

---

### Test Case 2: Already in Grace Period (No Notification)

**Setup**: User already has status 'grace' in database

**Test**:
1. User visits `/mod/flashcards/my/index.php`

**Expected Result**:
- ✅ Page loads normally
- ✅ Yellow warning shown
- ✅ NO new notification sent (already in grace)
- ✅ No errors

---

### Test Case 3: Complete Removal from Courses

**Setup**:
```sql
DELETE FROM mdl_user_enrolments WHERE userid = YOUR_TEST_USER_ID;
```

**Test**:
1. User with active access visits flashcards page
2. System detects no enrollments
3. Transitions to grace period

**Expected Result**:
- ✅ Same as Test Case 1
- ✅ Notification sent successfully

---

## 🚀 Deployment

### Files Changed (3 total)

1. **`classes/access_manager.php`** (lines 307, 314, 321):
   - Added `$message->fullmessageformat = FORMAT_HTML;` to all 3 notification types

2. **`version.php`** (line 10, 13):
   - Version bumped to 2025102920
   - Release name: '0.5.17-notification-fix'

3. **`assets/flashcards.js`** (line 694):
   - CACHE_VERSION updated to "2025102920"

### Deploy Steps

```bash
# 1. Copy updated files
rsync -av /path/to/dev/flashcards/ /path/to/moodle/mod/flashcards/

# 2. Clear caches (REQUIRED)
php admin/cli/purge_caches.php

# 3. Test with user who has expired enrollment
# Should see yellow warning and NO database errors

# 4. Check notifications were sent
SELECT * FROM mdl_notifications
WHERE component = 'mod_flashcards'
ORDER BY timecreated DESC
LIMIT 5;
```

---

## 📊 Impact

### Before v0.5.17:
- ❌ Page crashes with "Error writing to database"
- ❌ 404 error on index.php
- ❌ Grace period notification fails to send
- ❌ User has no idea their access changed

### After v0.5.17:
- ✅ Page loads successfully
- ✅ Grace period UI appears immediately
- ✅ Notification sent to user successfully
- ✅ User informed via notification + UI banner

---

## 🔗 Related Issues

- **v0.5.16**: Fixed NULL values in `grace_period_start` and `blocked_at` fields
- **v0.5.15**: Introduced force refresh which triggered notifications more frequently
- **Moodle Message API**: Requires `fullmessageformat` to be set (cannot be NULL)

---

## 📝 Lessons Learned

### Moodle Message Object Requirements

When creating `\core\message\message` object, these fields are **REQUIRED**:

```php
$message = new \core\message\message();
$message->component = 'mod_pluginname';        // REQUIRED
$message->name = 'messagename';                // REQUIRED
$message->userfrom = $user;                    // REQUIRED
$message->userto = $user;                      // REQUIRED
$message->subject = 'Subject';                 // REQUIRED
$message->fullmessage = 'Plain text';          // REQUIRED
$message->fullmessageformat = FORMAT_HTML;     // REQUIRED ← WE FORGOT THIS!
$message->fullmessagehtml = '<p>HTML</p>';     // Optional but recommended
$message->notification = 1;                    // REQUIRED for notifications
```

**Common Mistake**: Forgetting to set `fullmessageformat` because it's not obvious from documentation.

---

## ✅ Summary

**Root Cause**: Missing `fullmessageformat` field in notification message object.

**Solution**: Add `$message->fullmessageformat = FORMAT_HTML;` to all notification types.

**Testing**: Verify notification sent successfully and page loads without errors.

**Status**: ✅ FIXED - Ready for production deployment

---

**Version**: 2025102920 (v0.5.17-notification-fix)
**Files Changed**: 3
**Risk Level**: LOW (single line addition to 3 switch cases)
**Testing Priority**: HIGH (critical grace period functionality)
