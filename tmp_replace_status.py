from pathlib import Path
path = Path('assets/app.css')
text = path.read_text(encoding='utf-8')
old = '.front-text-actions .translation-status-inline{\n  margin-left: 0;\n  align-self: center;\n  flex: 0 1 auto;\n  min-width: fit-content;\n  max-width: min(60vw, 18rem);\n  width: auto;\n  word-break: break-word;\n'
if old not in text:
    raise SystemExit('old block not found')
new = '.front-text-actions .translation-status-inline{\n  margin-left: 0;\n  align-self: center;\n  flex: 0 0 auto;\n  min-width: fit-content;\n  max-width: 12rem;\n  width: auto;\n  word-break: break-word;\n  overflow-wrap: anywhere;\n  white-space: normal;\n  text-align: center;\n'
text = text.replace(old, new, 1)
path.write_text(text, encoding='utf-8')
