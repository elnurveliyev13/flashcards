from pathlib import Path
text = Path('assets/app.css').read_text(encoding='utf-8')
start = text.find('.front-text-actions .translation-status-inline')
print(repr(text[start:start+200]))
