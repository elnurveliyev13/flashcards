# Deployment Guide: v0.5.14 - Grace Period & Cache Fixes

**Version**: 0.5.14-grace-period-cache-fix
**Date**: 2025-10-29
**Type**: Critical Bug Fixes

---

## What's New

### 1. Auto-Clear Cache on Plugin Update ⭐

**Problem**: localStorage кэш не очищался при обновлении плагина, что приводило к:
- Старым карточкам, "призракам" после удаления
- Рассинхронизации с БД
- Устаревшим данным

**Solution**: Автоматическая очистка кэша при изменении версии плагина.

**Implementation**: `assets/flashcards.js` строки 675-700

```javascript
const CACHE_VERSION = "2025102917"; // Matches version.php
if (currentCacheVersion !== CACHE_VERSION) {
    // Clear localStorage (srs-v6:*, srs-profile, srs-profiles)
    // Clear IndexedDB ("srs-media")
    // Set new version
}
```

**Impact**: При первом открытии после обновления:
- ✅ Все старые карточки удалены из кэша
- ✅ Загрузка с сервера заново
- ✅ Чистое состояние

---

### 2. Strict Grace Period - Expired Enrollments ⭐ CRITICAL

**Problem**: Пользователи с истёкшей подпиской (`ue.timeend` прошёл) всё ещё имели доступ к flashcards.

**Current Behavior (BEFORE v0.5.14)**:
```sql
-- Проверка только ue.status = 0
WHERE ue.status = 0  -- Пользователь активен
  AND (ue.timeend = 0 OR ue.timeend > :now)  -- НО проверка timeend игнорировалась!
```

**Result**: Если подписка истекла, но `ue.status` не изменился на 1 (suspended), доступ оставался.

**New Behavior (v0.5.14)**:
```sql
-- Проверка И ue.status, И e.status, И timeend
WHERE ue.status = 0           -- User active
  AND e.status = 0            -- ← NEW: Enrolment method active
  AND (ue.timestart = 0 OR ue.timestart <= :now)
  AND (ue.timeend = 0 OR ue.timeend > :now)  -- Strictly enforced
```

**Grace Period Flow**:
```
User subscription expires (timeend passes)
    ↓
has_active_enrolment() returns FALSE
    ↓
Status: active → grace (30 days)
    ↓
Notification sent: "Grace period started"
    ↓
User can review cards (can_review=true)
User CANNOT create cards (can_create=false)
    ↓
After 30 days:
    ↓
Status: grace → expired
    ↓
User CANNOT review or create (blocked completely)
```

**File**: `classes/access_manager.php` строка 182

---

### 3. Block localStorage Card Creation in Grace Period ⭐

**Problem**: В grace period сервер блокировал `upsert_card`, НО фронтенд всё равно сохранял карточку в localStorage.

**Previous Behavior**:
```javascript
try {
    await api('upsert_card', ...);  // Server returns 403 Forbidden
} catch(e) {
    /* continue with local save */  // ← BUG: Saves to localStorage anyway!
}
// Saves to localStorage even if server rejected
registry[deckId].cards.push(card);
```

**Result**: Карточки накапливались в кэше, но не синхронизировались с БД.

**New Behavior**:
```javascript
try {
    const result = await api('upsert_card', ...);
    if (result && !result.ok) {
        // Server rejected
        $("#status").textContent = "Access denied. Cannot create cards during grace period.";
        return;  // STOP - don't save to localStorage
    }
} catch(e) {
    if (e.message.includes('access') || e.message.includes('grace')) {
        $("#status").textContent = "Access denied.";
        return;  // STOP
    }
    // Only network errors fall through to local save
}
```

**File**: `assets/flashcards.js` строки 461-486

**Impact**: В grace period карточки **полностью заблокированы** (и в БД, и в кэше).

---

## Files Changed

| File | Lines | Description |
|------|-------|-------------|
| `assets/flashcards.js` | 675-700 | Auto-clear cache on version change |
| `assets/flashcards.js` | 461-486 | Block localStorage save in grace period |
| `classes/access_manager.php` | 182 | Add `e.status=0` check for strict enrollment validation |
| `version.php` | 10, 13 | Version bump to 2025102917 |

---

## Installation Steps

### Step 1: Backup

```bash
# Backup database
mysqldump -u moodle_user -p moodle_db > backup_v0.5.13.sql

# Backup plugin files
cp -r /path/to/moodle/mod/flashcards /path/to/backup/flashcards_v0.5.13
```

---

### Step 2: Deploy Plugin

```bash
# Copy updated files
rsync -av --delete /path/to/dev/flashcards/ /path/to/moodle/mod/flashcards/

# Clear Moodle caches
php admin/cli/purge_caches.php

# Optional: Clear PHP opcache
sudo systemctl restart php-fpm
```

---

### Step 3: No Database Migration Needed

**Good news**: v0.5.14 has **NO database schema changes**.

```bash
# Still run upgrade to update version number
php admin/cli/upgrade.php --non-interactive
```

Expected output:
```
Upgrading mod_flashcards from 2025102916 to 2025102917
```

---

### Step 4: Verify Deployment

#### Test 1: Cache Auto-Clear

1. Open flashcards as any user
2. Open browser console (F12)
3. Look for log message:
   ```
   [Flashcards] Cache version mismatch: 2025102916 → 2025102917. Clearing cache...
   [Flashcards] Cache cleared successfully
   ```

**Pass**: Cache cleared on first load ✅

---

#### Test 2: Expired Enrollment → Grace Period

**Setup**:
1. Create test user (userid=500)
2. Enroll in course with flashcards activity
3. Set `timeend` to yesterday:
   ```sql
   UPDATE mdl_user_enrolments
   SET timeend = UNIX_TIMESTAMP(DATE_SUB(NOW(), INTERVAL 1 DAY))
   WHERE userid = 500;
   ```

**Test**:
1. Run scheduled task:
   ```bash
   php admin/cli/scheduled_task.php --execute='\mod_flashcards\task\check_user_access'
   ```

2. Check access status:
   ```sql
   SELECT userid, status, grace_period_start
   FROM mdl_flashcards_user_access
   WHERE userid = 500;
   ```

**Expected**:
```
userid | status | grace_period_start
500    | grace  | 1730203200 (today's timestamp)
```

**Pass**: User moved to grace period ✅

---

#### Test 3: Grace Period Blocks Card Creation (Backend)

**Setup**: User 500 in grace period (from Test 2)

**Test**:
```bash
# Try to create card via API
curl -X POST 'http://moodle.local/mod/flashcards/ajax.php?action=upsert_card&cmid=0' \
  -H 'Cookie: MoodleSession=...' \
  -d '{"deckId":null,"cardId":"test123","scope":"private","payload":{...}}'
```

**Expected Response**:
```json
{
  "ok": false,
  "error": "access_create_blocked"
}
```

**Pass**: Server blocks upsert_card ✅

---

#### Test 4: Grace Period Blocks Card Creation (Frontend + localStorage)

**Setup**: Login as user 500 (grace period)

**Test**:
1. Open flashcards UI
2. Fill in card form (front: "test", back: "тест")
3. Click "Add" button
4. Check status message: Should show "Access denied. Cannot create cards during grace period."
5. Open browser console → localStorage:
   ```javascript
   JSON.parse(localStorage.getItem('srs-v6:registry:U500'))
   ```

**Expected**: No new card in localStorage (registry unchanged)

**Pass**: localStorage save blocked ✅

---

#### Test 5: Grace Period Allows Review

**Setup**: User 500 in grace period with existing cards

**Test**:
1. Open flashcards UI
2. Review queue should show due cards
3. Rate cards (Easy/Normal/Hard)
4. Check progress saved:
   ```sql
   SELECT cardid, step, due
   FROM mdl_flashcards_progress
   WHERE userid = 500;
   ```

**Expected**: Progress updated (step incremented, due date changed)

**Pass**: Review works during grace period ✅

---

#### Test 6: Expired Access Blocks Everything

**Setup**:
1. User 500 in grace period
2. Set `grace_period_start` to 31 days ago:
   ```sql
   UPDATE mdl_flashcards_user_access
   SET grace_period_start = UNIX_TIMESTAMP(DATE_SUB(NOW(), INTERVAL 31 DAY))
   WHERE userid = 500;
   ```

**Test**:
1. Run scheduled task (check_user_access)
2. Login as user 500
3. Try to open flashcards

**Expected**:
- Status changed to `expired`
- UI shows "Access expired" message
- Review blocked (can_review=false)
- Create blocked (can_create=false)

**Pass**: Full block after grace period ✅

---

## Rollback Instructions

If issues occur:

```bash
# 1. Restore plugin files
rm -rf /path/to/moodle/mod/flashcards
cp -r /path/to/backup/flashcards_v0.5.13 /path/to/moodle/mod/flashcards

# 2. Clear caches
php admin/cli/purge_caches.php

# 3. Downgrade version in database (if needed)
# NOTE: Usually not needed since no schema changes

# 4. Clear user localStorage manually (ask users)
# In browser console:
localStorage.clear();
```

---

## Breaking Changes

### ⚠️ Users with Expired Enrollments Lose Access

**Before**: Users with `timeend < now` could still access flashcards if `ue.status=0`

**After**: Strict enforcement - expired enrollments trigger grace period immediately

**Impact**:
- Users may suddenly see "Grace period" messages
- Need to communicate this change to users
- Consider extending grace period from 30 to 60 days if needed

**Mitigation**:
```php
// In access_manager.php line 25:
const GRACE_PERIOD_DAYS = 60; // Increase from 30 to 60 days
```

---

### ⚠️ localStorage Cleared on First Load

**Before**: localStorage persisted across updates

**After**: Cleared when CACHE_VERSION changes

**Impact**:
- First load after update is slower (fetches from server)
- Users lose offline cards (if not synced)
- Profile data reset (need to re-select profile)

**Mitigation**: None needed - this is intentional cleanup

---

## Configuration

### Adjust Grace Period Length

**Default**: 30 days

**To change**:

**File**: `classes/access_manager.php` line 25

```php
const GRACE_PERIOD_DAYS = 60; // Change from 30 to 60 days
```

**Impact**: Affects new grace periods only (existing ones keep original duration)

---

### Disable Auto-Cache Clear (Not Recommended)

**To disable** (for testing only):

**File**: `assets/flashcards.js` line 675-700

```javascript
// Comment out cache clear logic
// const CACHE_VERSION = "2025102917";
// if (currentCacheVersion !== CACHE_VERSION) { ... }
```

**Warning**: Only disable for debugging. Always enable in production.

---

## Known Issues

### Issue 1: Profile dropdown resets after cache clear

**Symptom**: User's selected profile reverts to "Guest" after update

**Cause**: localStorage cleared, including `srs-profile` key

**Workaround**: User must re-select profile from dropdown

**Status**: By design (intentional cache clear)

---

### Issue 2: Offline cards lost

**Symptom**: Cards created offline (not synced) disappear after update

**Cause**: localStorage cleared before sync

**Workaround**: Users should sync before updating (not feasible for auto-updates)

**Status**: Known limitation - advise users to stay online

---

## Troubleshooting

### Cache not clearing

**Symptom**: Old cards still visible after update

**Debug**:
```javascript
// Browser console:
localStorage.getItem('flashcards-cache-version')
// Should be: "2025102917"
```

**Fix**:
```javascript
// Manually clear:
localStorage.clear();
location.reload();
```

---

### Grace period not triggered

**Symptom**: User with expired enrollment still has full access

**Debug**:
```sql
-- Check enrollment status
SELECT ue.userid, e.courseid, ue.status, ue.timeend,
       FROM_UNIXTIME(ue.timeend) AS timeend_date
FROM mdl_user_enrolments ue
JOIN mdl_enrol e ON e.id = ue.enrolid
WHERE ue.userid = 500;

-- Check access status
SELECT * FROM mdl_flashcards_user_access WHERE userid = 500;
```

**Fix**:
```bash
# Run scheduled task manually
php admin/cli/scheduled_task.php --execute='\mod_flashcards\task\check_user_access'
```

---

### Cards still saving to localStorage in grace period

**Symptom**: Cards appear in localStorage despite grace period

**Debug**:
```javascript
// Browser console (Network tab):
// Check response from upsert_card API
// Should be: {"ok": false, "error": "access_create_blocked"}
```

**Fix**:
- Clear browser cache
- Hard reload (Ctrl+Shift+R)
- Check if JavaScript file updated

---

## Support

**Logs to check**:
```bash
# Moodle error log
tail -f /path/to/moodledata/error_log

# Browser console
F12 → Console → Look for [Flashcards] messages
```

**Common log entries**:
```
[Flashcards] Cache version mismatch: null → 2025102917. Clearing cache...
[Flashcards] Cache cleared successfully
upsert_card rejected: Access denied. You cannot create cards during grace period.
```

---

## Summary

### v0.5.14 Fixes Three Critical Issues:

1. ✅ **Auto-clear cache** - Prevents stale data on updates
2. ✅ **Strict grace period** - Enforces `e.status=0` + `timeend` checks
3. ✅ **Block localStorage saves** - Prevents card creation bypass in grace period

### Testing Priority:

- **HIGH**: Grace period triggered for expired enrollments (Test 2)
- **HIGH**: Card creation blocked in grace period (Tests 3, 4)
- **MEDIUM**: Cache cleared on first load (Test 1)
- **MEDIUM**: Review still works in grace period (Test 5)

### Deployment Time: ~10 minutes

**Steps**:
1. Backup (2 min)
2. Deploy files (3 min)
3. Purge caches (1 min)
4. Run upgrade (2 min)
5. Verify (2 min)

---

**Deployed by**: _____________
**Date**: _____________
**Notes**: _____________
