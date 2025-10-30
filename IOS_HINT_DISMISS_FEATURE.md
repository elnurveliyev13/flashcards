# iOS Hint Dismiss Feature - Documentation

## 🎯 Problem Solved

**Original Issue**: iOS install hint banner didn't disappear after user installed PWA via "Add to Home Screen"

**Root Cause**: iOS Safari doesn't fire `appinstalled` event, and `window.navigator.standalone` only returns `true` when PWA is launched from home screen (not when user remains in Safari after installation)

**Solution**: Added dismiss button (×) that users can click to hide the banner permanently

---

## ✅ Implementation

### Visual Changes

**Before** (no dismiss option):
```
┌────────────────────────────────────┐
│ 📱 iOS users: Install this app     │
│ Tap the Share button, then select  │
│ Add to Home Screen                 │
└────────────────────────────────────┘
```

**After** (with dismiss button):
```
┌────────────────────────────────────┐
│ 📱 iOS users: Install this app  [×]│ ← Close button
│ Tap the Share button, then select  │
│ Add to Home Screen                 │
└────────────────────────────────────┘
```

### Features

1. **Close Button (×)**:
   - Position: Top-right corner of hint banner
   - Style: Gray text, becomes white on hover
   - Size: 28x28px tap target (iOS-friendly)
   - Click → Hides banner + saves preference to localStorage

2. **localStorage Persistence**:
   - Key: `ios-install-hint-dismissed`
   - Value: `"true"` (string)
   - Scope: Per-browser (survives page refresh)
   - Effect: Banner won't show again on future visits

3. **Detection Logic**:
   - Show banner if ALL conditions met:
     - ✅ iOS device (iPad/iPhone/iPod)
     - ✅ NOT in standalone mode
     - ✅ User hasn't dismissed banner previously
   - Hide banner if ANY condition fails

---

## 📁 Files Modified

### Template (HTML + CSS)

**File**: `templates/app.mustache`

**Changes**:
1. **CSS** (lines 87-130):
   ```css
   .ios-hint {
     position: relative;  /* ← NEW: for absolute positioning of close button */
     padding: 12px 36px 12px 14px;  /* ← CHANGED: right padding for button */
     /* ... other styles unchanged ... */
   }

   .ios-hint-close {  /* ← NEW: close button styles */
     position: absolute;
     top: 8px;
     right: 8px;
     background: transparent;
     /* ... full styles in file ... */
   }
   ```

2. **HTML** (lines 157-161):
   ```html
   <div id="iosInstallHint" class="ios-hint hidden">
     <button id="iosHintClose" class="ios-hint-close" title="Close" aria-label="Close">×</button>
     <!-- ↑ NEW: close button -->
     <strong>📱 iOS users: Install this app</strong><br>
     <!-- ... rest of hint text ... -->
   </div>
   ```

### JavaScript (Logic)

**Files**:
- `assets/flashcards.js` (lines 767-797)
- `amd/src/app.js` (lines 419-449)

**Changes**:
```javascript
// 1. NEW: Get close button element
const iosHintClose = $("#iosHintClose");

// 2. NEW: localStorage key
const hintDismissedKey = 'ios-install-hint-dismissed';

// 3. NEW: Check if user dismissed hint previously
const isHintDismissed = localStorage.getItem(hintDismissedKey) === 'true';

// 4. CHANGED: Add dismissed check to visibility logic
if(iosInstallHint && isIOS && !isInStandaloneMode && !isHintDismissed) {
  //                                               ^^^ NEW CONDITION
  iosInstallHint.classList.remove('hidden');
}

// 5. NEW: Handle close button click
if(iosHintClose) {
  iosHintClose.addEventListener('click', () => {
    iosInstallHint.classList.add('hidden');
    localStorage.setItem(hintDismissedKey, 'true');
    console.log('[PWA] iOS hint dismissed by user');
  });
}
```

---

## 🔄 User Experience Flow

### First Visit (iOS Safari)

1. User opens flashcards page in Safari
2. Detection runs:
   - ✅ iOS device detected
   - ✅ Not in standalone mode
   - ✅ `ios-install-hint-dismissed` = `null` (not set)
3. **Hint banner appears**
4. Console log: `[PWA] ✅ iOS install hint shown`

### User Dismisses Hint

1. User clicks **×** button
2. Banner immediately hides
3. localStorage saves: `ios-install-hint-dismissed = "true"`
4. Console log: `[PWA] iOS hint dismissed by user`

### Subsequent Visits

1. User returns to flashcards page
2. Detection runs:
   - ✅ iOS device detected
   - ✅ Not in standalone mode
   - ❌ `ios-install-hint-dismissed` = `"true"` ← **DISMISSED!**
3. **Hint banner stays hidden**
4. Console log: `[PWA] iOS hint hidden (not iOS, already installed, or dismissed by user)`

### After Installing PWA

**Scenario A: User installs, then visits site in Safari again**
- Hint still hidden (dismissed flag remains)

**Scenario B: User launches PWA from home screen**
- ✅ `window.navigator.standalone` = `true`
- Hint automatically hidden (even if not dismissed)
- Console log: `[PWA] Standalone mode: true`

---

## 🧪 Testing

### Test 1: Dismiss Button Functionality

**Platform**: iOS Safari
**Steps**:
1. Open flashcards page (first visit)
2. Verify hint banner appears
3. Click **×** button
4. Verify banner disappears immediately
5. Refresh page (F5)
6. Verify banner does NOT reappear

**Expected Console Logs**:
```
[PWA] iOS device: true
[PWA] Standalone mode: false
[PWA] ✅ iOS install hint shown
[PWA] iOS hint dismissed by user  ← After clicking ×
```

**Expected localStorage**:
```javascript
localStorage.getItem('ios-install-hint-dismissed')
// → "true"
```

---

### Test 2: Reset Dismissed State

**Platform**: iOS Safari (Developer Tools)
**Steps**:
1. Open Safari → Develop → Show JavaScript Console
2. Run command:
   ```javascript
   localStorage.removeItem('ios-install-hint-dismissed');
   ```
3. Refresh page
4. Verify hint banner appears again

**Use Case**: Testing or user wants to see hint again

---

### Test 3: Cross-Browser Behavior

**Desktop Chrome**:
- ✅ Install button appears (no change)
- ❌ iOS hint never appears
- ❌ Close button not visible

**Android Chrome**:
- ✅ Install button appears (no change)
- ❌ iOS hint never appears

**iOS Safari (after dismiss)**:
- ❌ Hint stays hidden
- ✅ localStorage persists across sessions

---

## 🎨 Design Details

### Close Button Specifications

**Visual**:
- Symbol: × (Unicode multiplication sign)
- Color: #94a3b8 (gray-blue)
- Hover color: #e2e8f0 (light gray)
- Background: transparent → rgba(255,255,255,0.1) on hover

**Size**:
- Width: 28px
- Height: 28px
- Padding: 4px
- Font size: 20px

**Position**:
- Absolute positioning (relative to `.ios-hint`)
- Top: 8px
- Right: 8px

**Accessibility**:
- `title="Close"` - tooltip for desktop
- `aria-label="Close"` - screen reader label
- `<button>` element (not `<div>` or `<span>`)
- Keyboard accessible (can Tab to it, press Enter/Space)

### Banner Padding Adjustment

**Before**:
```css
padding: 12px 14px;  /* Equal padding on both sides */
```

**After**:
```css
padding: 12px 36px 12px 14px;  /* Extra right padding for close button */
```

**Reason**: Prevents text from overlapping with close button (28px button + 8px spacing = 36px)

---

## 🔍 Technical Details

### localStorage vs sessionStorage

**Why localStorage?**
- ✅ Persists across browser sessions (survives closing Safari)
- ✅ User dismissed = preference saved forever
- ❌ sessionStorage would show hint again after closing Safari

**Storage Scope**:
- Per-origin (same for all pages on same Moodle domain)
- Per-browser (Safari iOS has separate storage from Safari on Mac)
- Shared between Activity view and Global "My Flashcards" page

### Detection Priority

Hint is hidden if **ANY** condition is true:
1. Not iOS device → Hide
2. In standalone mode → Hide
3. User dismissed hint → Hide

**Logic**:
```javascript
if (isIOS && !isInStandaloneMode && !isHintDismissed) {
  show();
} else {
  hide();
}
```

**Truth Table**:

| iOS | Standalone | Dismissed | Result |
|-----|------------|-----------|--------|
| ❌  | -          | -         | Hide   |
| ✅  | ✅         | -         | Hide   |
| ✅  | ❌         | ✅        | Hide   |
| ✅  | ❌         | ❌        | **Show** |

---

## 🐛 Edge Cases

### Edge Case 1: User Clears Browser Data

**Scenario**: User goes to Settings → Safari → Clear History and Website Data
**Result**: localStorage cleared → hint will appear again
**Expected Behavior**: ✅ Correct (fresh start)

### Edge Case 2: User Switches Browsers

**Scenario**: User dismisses in Safari, then opens in Chrome (iOS)
**Result**: Different browser = different localStorage → hint appears in Chrome
**Expected Behavior**: ✅ Correct (Chrome on iOS can't install PWAs anyway)

### Edge Case 3: User Installs PWA BEFORE Dismissing Hint

**Scenario**:
1. Hint appears
2. User installs PWA (doesn't dismiss hint)
3. User opens PWA from home screen

**Result**:
- `standalone = true` → hint hidden
- `dismissed = false` (localStorage not set)
- If user returns to Safari → hint will appear again

**Expected Behavior**: ⚠️ Acceptable (user can dismiss if needed)

**Alternative Fix** (not implemented):
```javascript
// Auto-dismiss hint when entering standalone mode
if(isInStandaloneMode && !isHintDismissed) {
  localStorage.setItem(hintDismissedKey, 'true');
}
```

---

## 📊 Console Debugging

### Debug Commands

**Check if hint is dismissed**:
```javascript
localStorage.getItem('ios-install-hint-dismissed')
// → "true" (dismissed) or null (not dismissed)
```

**Force hint to appear**:
```javascript
localStorage.removeItem('ios-install-hint-dismissed');
location.reload();
```

**Simulate iOS device** (desktop browser):
```javascript
// WARNING: This is a hack for testing only
Object.defineProperty(navigator, 'userAgent', {
  get: () => 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0...'
});
location.reload();
```

### Expected Logs

**First visit (iOS, not dismissed)**:
```
[PWA] iOS device: true
[PWA] Standalone mode: false
[PWA] ✅ iOS install hint shown
```

**After clicking close button**:
```
[PWA] iOS hint dismissed by user
```

**Next visit (iOS, dismissed)**:
```
[PWA] iOS device: true
[PWA] Standalone mode: false
[PWA] iOS hint hidden (not iOS, already installed, or dismissed by user)
```

---

## 🚀 Deployment Notes

### Version Update

**Before**: v0.6.4 (iOS install hint)
**After**: v0.6.5 (iOS dismiss button)

### Cache Busting

**Moodle cache**:
```bash
php admin/cli/purge_caches.php
```

**Browser cache**:
- Desktop: Ctrl+Shift+R (hard refresh)
- iOS Safari: Settings → Safari → Clear History and Website Data

**JavaScript versioning**:
```php
// In view.php and my/index.php
$ver = 2025103001; // ← Increment this for cache busting
$PAGE->requires->js(new moodle_url('/mod/flashcards/assets/flashcards.js', ['v' => $ver]));
```

### Backward Compatibility

**Old browsers** (without localStorage):
- `localStorage.getItem()` returns `null` (treated as not dismissed)
- Hint will appear every visit (acceptable fallback)

**Old iOS versions** (iOS < 8):
- localStorage supported since iOS 5.1
- No issues expected

---

## 📚 Related Documentation

- **`IOS_PWA_SUPPORT.md`** - Original iOS PWA implementation
- **`TESTING_IOS_INSTALL_HINT.md`** - Testing guide
- **`IOS_INSTALL_IMPLEMENTATION_SUMMARY.md`** - Deployment summary

---

## 🎯 Summary

### What Changed
- ✅ Added close button (×) to iOS hint banner
- ✅ localStorage tracks dismissed state
- ✅ Banner respects user preference (stays hidden after dismiss)

### What Didn't Change
- ✅ Desktop/Android install button unchanged
- ✅ iOS standalone detection unchanged
- ✅ Meta tags unchanged

### User Benefits
- ✅ Users can dismiss hint if not interested
- ✅ Hint doesn't annoy users who already know about installation
- ✅ Cleaner UI after dismissal

### Technical Benefits
- ✅ No server-side storage needed (localStorage only)
- ✅ Works offline (localStorage persists)
- ✅ Per-user preference (different users on same device = separate settings)

---

**Last Updated**: 2025-10-30
**Version**: 0.6.5
**Feature Status**: ✅ Complete and tested
