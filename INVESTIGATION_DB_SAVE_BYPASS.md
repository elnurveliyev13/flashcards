# Investigation: Cards Saving to DB Despite Access Block

**Version**: v0.5.15
**Issue ID**: #GracePeriod-DBBypass
**Severity**: MEDIUM (UI now blocks form, but server validation questionable)
**Status**: ⚠️ REQUIRES INVESTIGATION

---

## 🔍 Problem Description

**User Report** (2025-10-29):
> "When I click 'Add as new' during grace period I get message: 'Access denied. You cannot create cards during grace period or after access expires.' and card doesn't save [to localStorage], but card actually saves to database."

**Expected Behavior**:
1. User in grace period (`can_create=false`)
2. User clicks "Add as new"
3. Frontend calls `upsert_card` API
4. Server throws `moodle_exception('access_create_blocked')`
5. Card should NOT save to database

**Actual Behavior**:
1. Frontend shows "Access denied" message ✅
2. localStorage save blocked ✅
3. **BUT card still appears in `mdl_flashcards_cards` table** ❌

---

## 🧩 Current Implementation

### Backend: `ajax.php` (lines 27-30)

```php
// Check access before create actions.
if ($action === 'upsert_card' || $action === 'create_deck' || $action === 'upload_media') {
    if (!$access['can_create']) {
        throw new moodle_exception('access_create_blocked', 'mod_flashcards');
    }
}
```

**Expected**: Exception stops execution, no INSERT/UPDATE to database.

**Question**: Does Moodle's `AJAX_SCRIPT` automatically catch exceptions and still allow execution to continue?

---

### Frontend: `flashcards.js` (lines 461-486)

```javascript
try {
  const result = await api('upsert_card', {}, 'POST', {deckId:null,cardId:id,scope:'private',payload});
  if (result && result.ok) {
    // Success: use server's deckId
    if (result.deckId) {
      serverDeckId = String(result.deckId);
    }
  } else if (result && !result.ok) {
    // Server rejected (e.g., grace period, no access)
    const msg = result.error || 'Access denied...';
    $("#status").textContent = msg;
    setTimeout(()=>$("#status").textContent="", 3000);
    console.error('upsert_card rejected:', msg);
    return; // STOP - don't save to localStorage ✅
  }
} catch(e) {
  console.error('upsert_card error:', e);
  // Check if it's an access error
  if (e.message && (e.message.includes('access') || e.message.includes('grace') || e.message.includes('blocked'))) {
    $("#status").textContent = "Access denied. Cannot create cards.";
    setTimeout(()=>$("#status").textContent="", 3000);
    return; // STOP - don't save to localStorage ✅
  }
  // For other errors (network, etc), continue with local save
}

// Save to localStorage ONLY if server allowed
registry[id] = payload;
saveState();
```

**Frontend Correctly**:
- Checks `result.ok` and blocks localStorage save
- Shows "Access denied" message to user

**Frontend Question**: Is it receiving `result.ok=true` from server despite exception?

---

## 🔬 Investigation Steps

### Step 1: Check Browser Network Tab

**User Action Required**:
1. Open browser DevTools (F12)
2. Go to Network tab
3. Filter by "ajax.php"
4. Click "Add as new" button (if visible)
5. Check the response for `upsert_card` request

**Look for**:
- **Response Status**: Should be 200 (OK) or 400/500 (error)?
- **Response Body**: JSON structure
- **Expected if blocked**:
  ```json
  {
    "error": "Access denied...",
    "errorcode": "access_create_blocked"
  }
  ```
- **Unexpected if saving**:
  ```json
  {
    "ok": true,
    "deckId": "12345",
    "cardId": "card_xxx"
  }
  ```

---

### Step 2: Check Moodle Error Logs

**Server Action Required**:

```bash
# Check recent errors
tail -100 /var/log/moodle/error.log | grep flashcards

# OR via Moodle UI:
# Site administration > Reports > Logs
# Filter by: mod_flashcards, today
```

**Look for**:
- `access_create_blocked` exception logged?
- Stack trace showing where exception was thrown?
- Any SQL INSERT statements after exception?

---

### Step 3: Add Debug Logging to `ajax.php`

**Modify `ajax.php` temporarily** (lines 27-35):

```php
// Check access before create actions.
if ($action === 'upsert_card' || $action === 'create_deck' || $action === 'upload_media') {
    if (!$access['can_create']) {
        // DEBUG: Log before throwing exception
        error_log('[FLASHCARDS DEBUG] Access blocked for user ' . $USER->id . ', action: ' . $action);
        error_log('[FLASHCARDS DEBUG] Access status: ' . $access['status']);

        throw new moodle_exception('access_create_blocked', 'mod_flashcards');

        // DEBUG: This should NEVER execute
        error_log('[FLASHCARDS DEBUG] AFTER EXCEPTION - THIS IS A BUG!');
    }
}
```

**Then**:
1. User tries to create card
2. Check logs for debug messages
3. If "AFTER EXCEPTION" appears → **exception not stopping execution** (Moodle bug)

---

### Step 4: Check if `upsert_card` Action Handler Has Separate Validation

**File**: `ajax.php` (search for `case 'upsert_card':` or similar)

**Look for**:
- Is there a second access check inside the `upsert_card` handler?
- Does it bypass the initial check somehow?
- Are there multiple code paths that could save the card?

---

### Step 5: Verify Database Transactions

**Possibility**: Moodle might be:
1. Throwing exception ✅
2. BUT auto-committing transaction before exception bubbles up ❌

**Test Query**:
```sql
-- Check if card was created AFTER access should have been blocked
SELECT c.*, FROM_UNIXTIME(c.createdat) AS created_time
FROM mdl_flashcards_cards c
JOIN mdl_flashcards_user_access a ON a.userid = c.ownerid
WHERE a.status = 'grace'  -- User is in grace period
  AND c.createdat > a.grace_period_start  -- Card created AFTER grace started
ORDER BY c.createdat DESC
LIMIT 10;
```

**If results found**: Cards are being created despite grace period → server validation failing.

---

## 🛠️ Potential Fixes

### Option A: Explicit JSON Response (Recommended)

**Instead of throwing exception**, return explicit JSON:

**File**: `ajax.php` (lines 27-30)

```php
// Check access before create actions.
if ($action === 'upsert_card' || $action === 'create_deck' || $action === 'upload_media') {
    if (!$access['can_create']) {
        // Don't throw exception - return JSON and exit
        header('Content-Type: application/json');
        echo json_encode([
            'ok' => false,
            'error' => get_string('access_create_blocked', 'mod_flashcards')
        ]);
        exit;
    }
}
```

**Why This Might Work Better**:
- Explicitly stops execution with `exit`
- Guarantees response format
- No reliance on Moodle's exception handling

---

### Option B: Add Validation to `upsert_card` Handler

**In addition to** initial check, add validation inside the action handler:

```php
case 'upsert_card':
    // Re-check access (belt-and-suspenders approach)
    $access_recheck = \mod_flashcards\access_manager::check_user_access($USER->id, true);
    if (!$access_recheck['can_create']) {
        throw new moodle_exception('access_create_blocked', 'mod_flashcards');
    }

    // Continue with save logic...
    $deckid = required_param('deckId', PARAM_TEXT);
    // ...
    break;
```

---

### Option C: Database Constraint

**Add CHECK constraint** to prevent saves at DB level:

```sql
-- NOT RECOMMENDED (complex to maintain)
-- But theoretically possible with trigger:

CREATE TRIGGER prevent_grace_cards
BEFORE INSERT ON mdl_flashcards_cards
FOR EACH ROW
BEGIN
  DECLARE user_status VARCHAR(20);
  SELECT status INTO user_status
  FROM mdl_flashcards_user_access
  WHERE userid = NEW.ownerid;

  IF user_status IN ('grace', 'expired') THEN
    SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT = 'Cannot create cards during grace period';
  END IF;
END;
```

**Why Not Recommended**:
- Database triggers hard to debug
- Moodle doesn't expect DB-level validation
- Better to fix at application level

---

## 🧪 Testing Protocol

### Test Case 1: Verify Exception Actually Stops Execution

**Setup**:
1. Add debug logging as shown in Step 3
2. User in grace period
3. Clear browser cache

**Steps**:
1. Open DevTools (F12) → Network tab
2. Fill in card form
3. Click "Add as new"
4. Check Network tab response
5. Check server error logs

**Expected**:
- Network response: `{"error": "Access denied...", "errorcode": "access_create_blocked"}`
- Log shows: `[FLASHCARDS DEBUG] Access blocked for user X`
- Log does NOT show: `[FLASHCARDS DEBUG] AFTER EXCEPTION`
- Database: No new row in `mdl_flashcards_cards`

**If Failed**:
- Log shows "AFTER EXCEPTION" → Apply Option A fix
- Database has new row → Exception not stopping save

---

### Test Case 2: Verify Frontend Handles Response Correctly

**Setup**: Same as Test Case 1

**Steps**:
1. Open DevTools → Console tab
2. Click "Add as new"
3. Check console logs

**Expected**:
```
[Flashcards] Access info: {status: "grace", can_create: false, ...}
[Flashcards] Card creation form hidden (can_create=false)
upsert_card rejected: Access denied...
```

**NOT Expected**:
```
[Flashcards] Card saved successfully  ← This means frontend thinks save worked
```

---

### Test Case 3: Verify No Bypass via Direct API Call

**Setup**:
1. User in grace period
2. Form hidden by JavaScript

**Steps**:
1. Open DevTools → Console
2. Manually call API:
   ```javascript
   fetch('/mod/flashcards/ajax.php', {
     method: 'POST',
     headers: {'Content-Type': 'application/json'},
     body: JSON.stringify({
       action: 'upsert_card',
       sesskey: M.cfg.sesskey,
       deckId: null,
       cardId: 'test_bypass_001',
       scope: 'private',
       payload: {front: 'Test', back: 'Bypass', tags: []}
     })
   }).then(r => r.json()).then(console.log);
   ```
3. Check response
4. Check database

**Expected**:
- Response: `{ok: false, error: "Access denied..."}`
- Database: No row with `cardid='test_bypass_001'`

**If Failed**:
- Row exists in DB → Server not validating correctly → Apply Option A or B

---

## 📊 Decision Tree

```
Start: Card appears in DB despite access block
  │
  ├─ Step 1: Check Network Tab
  │   │
  │   ├─ Response: {ok: false, error: ...}
  │   │   └─> Server blocking correctly
  │   │       └─> Issue: How is card getting saved? Check for:
  │   │           - Multiple code paths to save
  │   │           - Cached writes from before grace period
  │   │           - Browser localStorage not cleared
  │   │
  │   └─ Response: {ok: true, deckId: ...}
  │       └─> Server NOT blocking correctly
  │           └─> Go to Step 2: Check Logs
  │
  ├─ Step 2: Check Error Logs
  │   │
  │   ├─ Exception logged: "access_create_blocked"
  │   │   └─> Exception thrown BUT execution continued
  │   │       └─> Apply Option A: Explicit JSON + exit
  │   │
  │   └─ NO exception logged
  │       └─> Access check not being reached
  │           └─> Go to Step 4: Check code paths
  │
  ├─ Step 3: Add Debug Logging
  │   │
  │   ├─ Log shows "AFTER EXCEPTION"
  │   │   └─> MOODLE BUG: throw doesn't stop execution
  │   │       └─> Apply Option A immediately
  │   │
  │   └─ Log does NOT show "AFTER EXCEPTION"
  │       └─> Exception working correctly
  │           └─> Card must be saving via different path
  │
  └─ Step 4: Database Query
      │
      ├─ Cards exist with createdat > grace_period_start
      │   └─> Definite validation failure
      │       └─> Apply Option A + Option B (belt-and-suspenders)
      │
      └─ No cards found
          └─> False alarm? User might be seeing cached data
              └─> Clear cache and retest
```

---

## ✅ Temporary Mitigation (v0.5.15)

**Until investigation complete**, the following mitigations are in place:

1. ✅ **Form Hidden**: User cannot click "Add as new" button through normal UI
2. ✅ **localStorage Blocked**: Even if server somehow saves, card won't appear in UI
3. ✅ **Clear Warning**: User sees permissions list explaining restrictions
4. ✅ **Force Refresh**: Grace period detected immediately (not after 24h)

**Impact**: User experience is correct even if backend has validation bug.

---

## 🚨 Priority Assessment

**Severity**: MEDIUM (not CRITICAL) because:
- ✅ User cannot trigger via UI (form hidden)
- ✅ Card won't appear in localStorage/frontend
- ⚠️ Possible bypass via direct API call (malicious user)
- ⚠️ Database integrity concern (cards shouldn't exist for grace users)

**Priority**: HIGH for investigation, MEDIUM for fix

**Timeline**:
- Investigation: 1-2 hours
- Fix (Option A): 15 minutes
- Testing: 30 minutes
- Total: ~3 hours

---

## 📝 Investigation Checklist

Copy this to track progress:

```
[ ] Step 1: User provides Network tab screenshot for ajax.php response
[ ] Step 2: Check server error logs for access_create_blocked exception
[ ] Step 3: Add debug logging to ajax.php (before/after throw)
[ ] Step 4: Review ajax.php code for all save paths
[ ] Step 5: Run database query to find cards created during grace period
[ ] Step 6: Test direct API call bypass (security test)
[ ] Decision: Choose fix option (A, B, or both)
[ ] Implement fix
[ ] Test with all 3 test cases
[ ] Document findings in this file
[ ] Update version to v0.5.16 if fix needed
```

---

## 📚 Related Files

- **Backend Validation**: `mod/flashcards/ajax.php` (lines 27-30)
- **Frontend Save Logic**: `assets/flashcards.js` (lines 461-486)
- **Access Manager**: `classes/access_manager.php` (grace period state machine)
- **Language Strings**: `lang/en/flashcards.php` (line 68: `access_create_blocked`)

---

## 🔗 References

- **Moodle AJAX_SCRIPT**: https://docs.moodle.org/dev/AJAX
- **Exception Handling**: https://docs.moodle.org/dev/Exception_handling
- **User Report**: Session summary, 2025-10-29

---

**Last Updated**: 2025-10-29
**Investigated By**: TBD (awaiting user feedback)
**Status**: ⏳ Awaiting Network tab screenshot and log access
