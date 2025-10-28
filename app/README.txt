Place the Flashcards PWA here to run inside Moodle.

Copy the following from your local app into this folder:

- index.html
- sw.js
- manifest.webmanifest
- audio/ (folder)
- icons/ (folder)
- packs/ (folder)

Notes:
- Paths inside index.html should remain relative (e.g. audio/..., packs/...).
- Service worker scope will be /mod/flashcards/app, which is OK for offline use of the app page.
- If camera/microphone are used, ensure the Moodle site uses HTTPS.

