Flashcards (mod_flashcards)

Overview
- Moodle activity module that embeds the existing Flashcards PWA inside an iframe.
- Keeps your PWA isolated, including service worker and offline support, while integrating as a course activity.

How to install
1) Copy `mod/flashcards` to your Moodle server under `moodle/mod/flashcards`.
2) Visit Site administration -> Notifications to complete installation.
3) Copy your PWA files into `mod/flashcards/app`:
   - index.html
   - sw.js
   - manifest.webmanifest
   - audio/ (folder)
   - icons/ (folder)
   - packs/ (folder)

Using
- Add a new activity of type "Flashcards" to a course. The app will load in-page.
- All app data remains in the browser (localStorage/IndexedDB), just like your local version.

Notes
- If your app uses camera/microphone or audio autoplay, run Moodle over HTTPS.
- The service worker will scope to `/mod/flashcards/app` and should work for offline usage of the app page.
- You can later replace the iframe approach with a native AMD + mustache integration if desired.

