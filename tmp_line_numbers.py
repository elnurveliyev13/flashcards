import pathlib
lines = pathlib.Path('assets/app.css').read_text(encoding='utf-8').splitlines()
for idx in range(1410, 1455):
    print(f"{idx+1}: {lines[idx]}")
