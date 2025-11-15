import pathlib
text=pathlib.Path('templates/app.mustache').read_text(encoding='utf-8')
pos=text.find('pref-language-field')
print(text[pos-200:pos+400])
