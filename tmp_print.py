import pathlib
text = pathlib.Path('assets/app.css').read_text(encoding='utf-8')
start = text.index('.translation-reset')
print(text[start:start+400])
