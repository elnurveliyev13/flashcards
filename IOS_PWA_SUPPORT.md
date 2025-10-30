# iOS PWA Support Documentation

## Overview

iOS Safari does not support the `beforeinstallprompt` event, which means the PWA install button will NOT appear automatically on iOS devices. This document describes the iOS-specific implementation for manual PWA installation.

## What Was Implemented

### 1. iOS Install Hint Banner

**Location**: `templates/app.mustache` (lines 133-137)

A styled banner that appears only on iOS Safari when the app is NOT yet installed:

```html
<div id="iosInstallHint" class="ios-hint hidden">
  <strong>📱 iOS users: Install this app</strong><br>
  Tap the Share button, then select Add to Home Screen
</div>
```

**CSS Styling**:
- Blue gradient background (#1e3a5f → #3b82f6 border)
- Rounded corners (12px)
- Highlighted action buttons in step instructions
- Hidden by default (`.hidden` class)

### 2. iOS Detection JavaScript

**Location**:
- `assets/flashcards.js` (lines 736-750)
- `amd/src/app.js` (lines 419-433)

**Detection Logic**:
```javascript
const isIOS = /iPad|iPhone|iPod/.test(navigator.userAgent) && !window.MSStream;
const isInStandaloneMode = ('standalone' in window.navigator) && window.navigator.standalone;

if(iosInstallHint && isIOS && !isInStandaloneMode) {
  iosInstallHint.classList.remove('hidden');
  console.log('[PWA] ✅ iOS install hint shown');
}
```

**Conditions for showing hint**:
- ✅ Device is iOS (iPad/iPhone/iPod)
- ✅ App is NOT yet installed (not in standalone mode)
- ✅ Element exists in DOM

### 3. iOS-Specific Meta Tags

**Location**:
- `assets/flashcards.js` (lines 685-714)
- `amd/src/app.js` (lines 441-470)

**Dynamically injected meta tags**:

```javascript
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="SRS Cards">
<link rel="apple-touch-icon" href="/mod/flashcards/app/icons/icon-192.png">
```

**Purpose**:
- `apple-mobile-web-app-capable`: Enables full-screen mode (hides Safari UI)
- `apple-mobile-web-app-status-bar-style`: Status bar appearance (black with transparency)
- `apple-mobile-web-app-title`: App name on home screen
- `apple-touch-icon`: App icon on home screen (192x192px)

### 4. Language Strings

**Location**: `lang/en/flashcards.php` (lines 42-47)

```php
$string['ios_install_title'] = 'iOS users: Install this app';
$string['ios_install_step1'] = 'Tap the';
$string['ios_install_step2'] = 'button, then select';
$string['ios_share_button'] = 'Share';
$string['ios_add_to_home'] = 'Add to Home Screen';
```

**String registration**:
- `view.php` (lines 19-24)
- `my/index.php` (lines 28-33)

Both files register strings via `$PAGE->requires->string_for_js()` for Moodle's string system.

## How It Works

### User Experience Flow

#### On Android/Chrome/Edge (Desktop):
1. User visits app
2. After 30 seconds of interaction, `beforeinstallprompt` fires
3. **Install button appears automatically** (gradient purple button)
4. Click → PWA installs → button hides

#### On iOS Safari:
1. User visits app
2. `beforeinstallprompt` does NOT fire (iOS limitation)
3. **iOS hint banner appears** (blue info box)
4. User reads instructions:
   - "Tap the **Share** button"
   - "Then select **Add to Home Screen**"
5. User follows manual steps
6. After installation, app runs in standalone mode
7. Banner hides (detected via `window.navigator.standalone`)

### Technical Flow

```
┌─────────────────────────────────────┐
│  Page loads (view.php / my/index.php)│
└─────────────────────────────────────┘
              ↓
┌─────────────────────────────────────┐
│  flashcards.js initializes          │
└─────────────────────────────────────┘
              ↓
┌─────────────────────────────────────┐
│  Inject iOS meta tags to <head>     │
│  (apple-mobile-web-app-*)           │
└─────────────────────────────────────┘
              ↓
         iOS device?
         /          \
       YES           NO
        ↓             ↓
    Standalone?    Show install button
    /        \     (if beforeinstallprompt)
  YES        NO
   ↓          ↓
 Hide      Show iOS hint
 hint      banner
```

## Testing

### Desktop (Chrome/Edge)
- ✅ Install button should appear (after interaction)
- ❌ iOS hint should NOT appear

### Android (Chrome/Edge)
- ✅ Install button should appear
- ❌ iOS hint should NOT appear

### iOS Safari (NOT installed)
- ❌ Install button should NOT appear (expected)
- ✅ iOS hint banner should appear
- ✅ Meta tags should be in `<head>`

### iOS Safari (Already installed)
- ❌ Install button should NOT appear
- ❌ iOS hint should NOT appear
- ✅ App runs in full-screen (no Safari UI)

## Console Debugging

When testing, open browser console to see logs:

```javascript
[PWA] Install button in DOM: true
[PWA] Service Worker support: true
[PWA] User agent: Mozilla/5.0 (iPhone; CPU iPhone OS 17_0...
[PWA] iOS device: true
[PWA] Standalone mode: false
[PWA] ✅ iOS install hint shown
[PWA] iOS meta tags added
```

## Files Modified

### Templates
- ✅ `templates/app.mustache` - Added CSS + HTML for iOS hint banner

### JavaScript
- ✅ `assets/flashcards.js` - iOS detection + meta tags injection
- ✅ `amd/src/app.js` - AMD module (identical to assets version)

### PHP
- ✅ `view.php` - Register iOS language strings
- ✅ `my/index.php` - Register iOS language strings

### Language Strings
- ✅ `lang/en/flashcards.php` - iOS hint strings

### Documentation
- ✅ `IOS_PWA_SUPPORT.md` - This file

## Known Limitations

### iOS Safari Restrictions

1. **No automatic install prompt**
   - Cannot programmatically trigger installation
   - User MUST manually use "Add to Home Screen"

2. **No push notifications**
   - iOS does NOT support Web Push API
   - Alternative: Use Telegram Bot for notifications (planned)

3. **Storage limits**
   - IndexedDB may be cleared by iOS if device runs low on space
   - Limit: ~50MB (vs 1GB+ on Android)

4. **Background limitations**
   - Service Worker only runs when app is open
   - No background sync (unlike Android)

5. **Only Safari supported**
   - Chrome/Firefox on iOS do NOT support PWA installation
   - All iOS browsers use WebKit (Safari engine)

## Workarounds

### Push Notifications Workaround
**Telegram Bot** (planned Phase 2.5):
- Telegram notifications work on ALL platforms (iOS included!)
- Bot sends daily reminders: "You have 15 cards due"
- Deep link to PWA: `https://your-moodle.com/mod/flashcards/my/`

### Storage Workaround
**Server sync** (already implemented):
- Cards synced to Moodle database via AJAX
- IndexedDB used only for media files (images/audio)
- If IndexedDB cleared → re-download from server

## Future Improvements

### Phase 1 (Current)
- ✅ iOS hint banner
- ✅ iOS meta tags
- ✅ Detection logic

### Phase 2 (Optional)
- [ ] Dismissible hint (with localStorage flag)
- [ ] Animated instructions (GIF showing Share → Add to Home Screen)
- [ ] "Remind me later" button

### Phase 3 (Future)
- [ ] Ukrainian/Norwegian translations for iOS hint
- [ ] A/B testing: Text-only vs GIF instructions
- [ ] Analytics: Track iOS hint impressions vs conversions

## Troubleshooting

### Hint not appearing on iOS?

**Check console logs**:
```javascript
[PWA] iOS device: false  // ← Should be TRUE on iOS
[PWA] Standalone mode: true  // ← Should be FALSE if not installed
```

**Common issues**:
1. User agent detection failed → Check `/iPad|iPhone|iPod/` regex
2. Already in standalone mode → Hint correctly hidden
3. Element not in DOM → Check `templates/app.mustache`

### Meta tags not working?

**Inspect `<head>` in Safari Developer Tools**:
```html
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="SRS Cards">
<link rel="apple-touch-icon" href="/mod/flashcards/app/icons/icon-192.png">
```

**Common issues**:
1. JavaScript error before meta tags injected
2. Duplicate tags (check existing theme/Moodle settings)
3. Incorrect icon path (check `baseurl` variable)

### App not full-screen after install?

**Check**:
1. `apple-mobile-web-app-capable` meta tag present?
2. Installed via "Add to Home Screen" (not bookmarked)?
3. Opening from home screen icon (not Safari directly)?

## References

- [Apple: Configuring Web Applications](https://developer.apple.com/library/archive/documentation/AppleApplications/Reference/SafariWebContent/ConfiguringWebApplications/ConfiguringWebApplications.html)
- [MDN: Making PWAs installable](https://developer.mozilla.org/en-US/docs/Web/Progressive_web_apps/Guides/Making_PWAs_installable)
- [Safari 15.4+ Web Push](https://webkit.org/blog/12824/news-from-wwdc-webkit-features-in-safari-16-0/) - Note: NOT available for PWAs!

---

**Last Updated**: 2025-10-30
**Version**: 0.6.4 (iOS PWA support added)
