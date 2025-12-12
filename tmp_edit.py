from pathlib import Path
path = Path('assets/app.css')
text = path.read_text(encoding='utf-8')
old = '.status-chip.translation-status {\r\n  border: 1px solid var(--border);\r\n  border-radius: 999px;\r\n  padding: 0.2rem 0.65rem;\r\n  min-height: 30px;\r\n  background: rgba(15, 23, 42, 0.8);\r\n  color: var(--text-subtle);\r\n  font-size: 0.78rem;\r\n  font-weight: 600;\r\n  cursor: default;\r\n  pointer-events: none;\r\n  display: inline-flex;\r\n  align-items: center;\r\n  justify-content: center;\r\n  gap: 0.25rem;\r\n  transition: all 0.15s ease;\r\n  max-width: min(65vw, 18rem, 100%);\r\n  text-align: center;\r\n  white-space: normal;\r\n  word-break: anywhere;\r\n  overflow-wrap: anywhere;\r\n}\r\n'
if old not in text:
    raise SystemExit('old block not found')
new = old.replace('\r\n}\r\n', '\r\n  flex: 0 0 auto;\r\n  width: auto;\r\n}\r\n')
text = text.replace(old, new, 1)
path.write_text(text, encoding='utf-8')
