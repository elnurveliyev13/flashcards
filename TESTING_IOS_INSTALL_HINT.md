# Testing iOS Install Hint - Quick Guide

## 🎯 What to Test

iOS users should see a **blue hint banner** that explains how to manually install the PWA via "Add to Home Screen".

---

## ✅ Test Scenarios

### Scenario 1: iOS Safari (NOT installed) - SHOULD SHOW HINT

**Device**: iPhone/iPad with Safari
**Expected Result**:
- ❌ Install button (purple gradient) does NOT appear
- ✅ Blue hint banner appears below header
- ✅ Banner text: "📱 iOS users: Install this app"
- ✅ Instructions: "Tap the **Share** button, then select **Add to Home Screen**"

**Console logs** (Safari Developer Tools):
```
[PWA] iOS device: true
[PWA] Standalone mode: false
[PWA] ✅ iOS install hint shown
[PWA] iOS meta tags added
```

---

### Scenario 2: iOS Safari (Already installed) - SHOULD HIDE HINT

**Device**: iPhone/iPad with Safari (after manual installation)
**How to test**:
1. Open Safari → Visit flashcards page
2. Tap Share button → "Add to Home Screen"
3. Install app
4. Open app from home screen icon

**Expected Result**:
- ❌ Install button does NOT appear
- ❌ iOS hint banner does NOT appear
- ✅ App runs in full-screen (no Safari address bar)
- ✅ Status bar is black-translucent

**Console logs**:
```
[PWA] iOS device: true
[PWA] Standalone mode: true
[PWA] iOS hint hidden (not iOS or already installed)
```

---

### Scenario 3: Desktop Chrome/Edge - SHOULD HIDE HINT

**Device**: Windows/Mac desktop browser
**Expected Result**:
- ✅ Install button (purple gradient) appears (after 30 sec interaction)
- ❌ iOS hint banner does NOT appear

**Console logs**:
```
[PWA] iOS device: false
[PWA] iOS hint hidden (not iOS or already installed)
[PWA] ✅ Install prompt available - button shown
```

---

### Scenario 4: Android Chrome - SHOULD HIDE HINT

**Device**: Android phone with Chrome
**Expected Result**:
- ✅ Install button appears
- ❌ iOS hint banner does NOT appear

**Console logs**:
```
[PWA] iOS device: false
[PWA] iOS hint hidden (not iOS or already installed)
[PWA] ✅ Install prompt available - button shown
```

---

## 🔧 How to Test on iOS

### Using Real iPhone/iPad

1. **Connect iPhone to Mac**
2. **Enable Web Inspector**:
   - iPhone: Settings → Safari → Advanced → Web Inspector (ON)
   - Mac: Safari → Preferences → Advanced → Show Develop menu (✓)
3. **Open flashcards page on iPhone**:
   - Navigate to: `https://your-moodle.com/mod/flashcards/my/`
4. **Open Safari Developer Tools on Mac**:
   - Safari menu → Develop → [Your iPhone] → [Flashcards page]
5. **Check Console tab** for logs

### Using iOS Simulator (Mac only)

1. **Open Xcode** (free from App Store)
2. **Run iOS Simulator**:
   - Xcode → Open Developer Tool → Simulator
3. **Open Safari** in simulator
4. **Visit flashcards page**
5. **Use Safari Developer Tools** (same as above)

### Using BrowserStack (No iPhone needed)

1. **Sign up for free trial**: [browserstack.com](https://www.browserstack.com)
2. **Select device**: iPhone 14 Pro / iOS 17 / Safari
3. **Enter URL**: `https://your-moodle.com/mod/flashcards/my/`
4. **Check if hint appears**

---

## 📱 Manual Installation Test (iOS)

After seeing the hint banner, test manual installation:

1. **Tap Share button** (square with arrow up) in Safari
2. **Scroll down** and tap **"Add to Home Screen"**
3. **Edit name** (optional): "SRS Cards"
4. **Tap "Add"**
5. **Return to home screen** → Find app icon
6. **Tap icon** to open
7. **Verify**:
   - ✅ App opens in full-screen (no Safari UI)
   - ✅ Hint banner does NOT appear anymore
   - ✅ Status bar is black-translucent
   - ✅ App icon is 192x192 SRS Cards icon

---

## 🐛 Troubleshooting

### Problem: Hint not appearing on iOS

**Check**:
1. Open Safari (not Chrome/Firefox on iOS!)
2. Not already installed (check home screen)
3. Open Console and check:
   - `[PWA] iOS device:` → should be `true`
   - `[PWA] Standalone mode:` → should be `false`

**Debug**:
```javascript
// Run in Safari Console:
console.log('iOS?', /iPad|iPhone|iPod/.test(navigator.userAgent));
console.log('Standalone?', window.navigator.standalone);
console.log('Hint element?', document.querySelector('#iosInstallHint'));
```

### Problem: Hint appearing on Desktop

**Check**:
- User agent detection might be incorrect
- Check console: `[PWA] iOS device:` → should be `false`

**Fix**:
- Clear browser cache (Ctrl+Shift+Delete)
- Hard refresh (Ctrl+Shift+R)

### Problem: App not full-screen after install

**Check**:
1. Installed via "Add to Home Screen" (not bookmarked)
2. Opening from home screen icon (not Safari directly)
3. Check `<head>` has meta tag:
   ```html
   <meta name="apple-mobile-web-app-capable" content="yes">
   ```

**Debug in Safari Console**:
```javascript
// Check meta tags:
document.querySelectorAll('meta[name^="apple"]').forEach(m => {
  console.log(m.name, '=', m.content);
});
```

---

## ✨ Expected Visual Result

### iOS Safari (NOT installed):

```
┌────────────────────────────────────┐
│ 🏠  SRS Cards                  [≡] │ ← Safari header
├────────────────────────────────────┤
│ SRS Cards          [Cards list]    │ ← App header
├────────────────────────────────────┤
│ 📱 iOS users: Install this app     │ ← HINT BANNER (blue)
│ Tap the Share button, then select  │
│ Add to Home Screen                 │
└────────────────────────────────────┘
│ [Review card interface]            │
└────────────────────────────────────┘
```

### iOS Safari (Already installed):

```
┌────────────────────────────────────┐
│ 🔋 09:41                           │ ← iOS status bar (translucent)
├────────────────────────────────────┤
│ SRS Cards          [Cards list]    │ ← App header (NO Safari UI!)
├────────────────────────────────────┤
│ (no hint banner)                   │
├────────────────────────────────────┤
│ [Review card interface]            │
└────────────────────────────────────┘
```

---

## 📊 Test Checklist

### Desktop Testing
- [ ] Chrome: Install button appears, NO iOS hint
- [ ] Edge: Install button appears, NO iOS hint
- [ ] Firefox: NO install button, NO iOS hint (expected - FF doesn't support PWA)

### Android Testing
- [ ] Chrome: Install button appears, NO iOS hint
- [ ] Samsung Internet: Install button appears, NO iOS hint

### iOS Testing (NOT installed)
- [ ] Safari: NO install button, iOS hint appears
- [ ] Hint text is readable and styled correctly
- [ ] Console shows: `iOS device: true`, `Standalone: false`
- [ ] Meta tags present in `<head>`

### iOS Testing (Installed)
- [ ] Manual installation via "Add to Home Screen" works
- [ ] App opens in full-screen (no Safari UI)
- [ ] iOS hint does NOT appear
- [ ] Console shows: `Standalone: true`
- [ ] Status bar is black-translucent
- [ ] App icon is correct (192x192)

---

## 🎓 Next Steps After Testing

### If everything works:
1. ✅ Mark feature as complete
2. 📝 Update plugin version number
3. 🚀 Deploy to production
4. 📢 Notify users about iOS installation method

### If issues found:
1. 🐛 Document bugs in GitHub Issues
2. 🔍 Check browser console for errors
3. 🔧 Debug using Safari Developer Tools
4. 📬 Report to developer with:
   - Device model + iOS version
   - Safari version
   - Console logs (screenshots)
   - Network tab (if CORS issues)

---

**Happy Testing!** 🎉

If you encounter any issues, check `IOS_PWA_SUPPORT.md` for detailed technical documentation.
