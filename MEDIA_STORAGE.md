## Flashcards Audio Storage Layout

To keep ElevenLabs / uploaded audio files easy to inspect and migrate, the plugin now mirrors every audio asset into a readable folder inside `moodledata`:

```
moodledata/
 └─ mod_flashcards/
     └─ media/
         └─ user_<userid>/
             └─ card_<cardid>/
                 ├─ audio_front_<original>.mp3
                 ├─ focusAudio_<original>.mp3
                 └─ manifest.json
```

* `manifest.json` contains the `cardId`, (optional) `deckId`, and a list of files (label, filename, original filename, source URL, timestamp).  
* When a card is updated, the manifest is refreshed; when a card is deleted, its folder is removed.  
* The original Moodle File API copy is still kept so that `pluginfile.php` continues to serve the media securely.

### Migration / backups

To move Flashcards to another instance (including audio):

1. Export the database tables (`flashcards_*`) as usual.
2. Copy the directory `moodledata/mod_flashcards/media` (rsync/zip).  
   Each `card_*` folder is self‑contained, so the structure can also be browsed manually by teachers/administrators.
3. Import the DB and unpack the `mod_flashcards/media` folder into the new instance’s `moodledata`.

Because filenames include the card ID and the manifest stores the source URL, it is straightforward to match any audio file to its card even outside Moodle.
