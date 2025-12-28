# Flashcards: карта ресурсов и workflow тестирования (RU)

Этот документ нужен, чтобы быстро и одинаково понимать:
- какие источники данных у нас есть (Ordbank/argstr, Ordbøkene API, spaCy, ИИ, словарь произношений),
- где это искать в репозитории и в devtools-артефактах,
- как собирать воспроизводимые примеры и как читать ответы API,
- как проектировать улучшения так, чтобы ответы были достоверными и быстрыми для пользователя.

## 1) Быстрая ориентация: что где лежит

### Как пользоваться этим документом (для будущих доработок)
- Если ты тестируешь как пользователь: обновляй `.devtools/*.json` и присылай конкретный response/скриншот — этого достаточно.
- Если я анализирую/правлю код: начинаю с нужного `action` в `ajax.php`, затем смотрю клиентов источников (`spacy_client`, `ordbokene_client`, `ordbank_helper`) и только потом — LLM слой (`ai_helper`).

### DevTools-артефакты (ты обновляешь, я читаю)
Папка: `.devtools/`
- `.devtools/HAR.json` — экспорт сетевых запросов (полный контекст: URL, payload, response, timings).
- `.devtools/sentence_elements.json` — “эталонный” response для `action=sentence_elements`.
- `.devtools/ordbank_focus_helper&sesskey.json` — response для `action=ordbank_focus_helper`.
- `.devtools/ai_focus_helper&sesskey.json` — response для `action=ai_focus_helper`.
- `.devtools/ai_answer_question.json` — response для `action=ai_answer_question`.
- `.devtools/check_text_errors.json` — response для `action=check_text_errors`.

Правило: если есть новый кейс — обновляй один из этих файлов или добавляй новый рядом (лучше один файл = один кейс).

### Локальные корпуса/справочники
Папка: `.corpus/`
- `.corpus/ordlist/norsk_ordbank.pdf` — краткое описание структуры Norsk ordbank (7 базовых таблиц + leddanalyse).
- `.corpus/ordlist/norsk_ordbank_argstr.txt` — аргументные структуры (Prolog-описания `arg_code(...)`).
- `.corpus/ord.uib.no.html` — документация API ord.uib.no (suggest/articles/article).
- `.corpus/uttale/NB Uttale database/...` — исходники словаря произношений (например `e_spoken_pronunciation_lexicon.csv`).

## 2) Источники данных: “кто за что отвечает”

### A) spaCy (Python App)
Задача: токенизация/леммы/части речи/синтаксические зависимости.
- Endpoint: `POST https://abcnorsk.no/spacy/analyze` с JSON `{"text":"..."}`.
- Ответ: `tokens[]` со значениями `text/start/end/lemma/pos/tag/morph/is_alpha/is_punct` и (после фикса) `dep/head`.

Как spaCy используется в Moodle-плагине:
- `sentence_elements`: `words[].pos`, `words[].lemma`, `words[].dep` приходят из spaCy, но `words[].start/end` считаются regex-ом (см. ниже).
- `ai_focus_helper`: spaCy POS используется как сигнал для дизамбигуации кандидатов Ordbank.

Код:
- `classes/local/spacy_client.php` — вызов spaCy endpoint + кэширование результата.
- `ajax.php` — `mod_flashcards_spacy_*_map()` маппит spaCy-токены на word-токены.

### B) Norsk Ordbank + argstr (локальные таблицы в Moodle DB)
Задача: морфология, леммы, формы, составные слова, аргументные структуры (предлоги/шаблоны управления).

Таблицы на сервере (с Moodle-префиксом `mdl_`, в коде — без префикса):
- `mdl_ordbank_argstr` — аналоги `norsk_ordbank_argstr.txt` (arg_code/argumentstruktur).
- `mdl_ordbank_lemma`
- `mdl_ordbank_fullform`
- `mdl_ordbank_leddanalyse`
- `mdl_ordbank_lemma_paradigme`
- `mdl_ordbank_paradigme`
- `mdl_ordbank_paradigme_boying`
- `mdl_ordbank_boying_grupper`
- `mdl_ordbank_boying`

Как используется:
- Быстрый поиск кандидатов для слова (морфология/лемма/части речи).
- Извлечение `argcodes` из ordbank-тегов и получение “какие предлоги подходят/обязательны” (это и есть вход для `source: "argstr"` в генерации кандидатов выражений).

Код:
- `classes/local/ordbank_helper.php` — основной доступ к ordbank данным + `load_argstr_map()` (из DB или `.corpus/ordlist/norsk_ordbank_argstr.txt`).

### C) Ordbøkene / ord.uib.no API (внешний словарь)
Задача: подтверждение выражений/значения/примеры (надежный источник).

Ключевые endpoint’ы (см. `.corpus/ord.uib.no.html`):
- `/api/suggest` — автодополнение/варианты.
- `/api/articles?w=...` — список статей.
- `/{dict}/article/{id}.json` — содержимое статьи.

Как используется в плагине:
- Подтверждение multiword выражений и получение объяснения/примеров.
- Там, где `expressions[].source = "ordbokene"` и `confidence = "high"`.

Код:
- `classes/local/ordbokene_client.php` — клиент для ord.uib.no.
- `ajax.php` — функции “lookup/search expressions”, а также `flashcards_fetch_ordbokene_suggestions()`.

### D) ИИ (LLM API)
Задача: перевод, краткие пояснения, fallback-подтверждение “кандидатов”, где словарь не помог.

Где LLM участвует:
- `data.enrichment` — перевод предложения + перевод/ноты для слов и найденных выражений (элементы `type: word/phrase`).
- `expressions[].source = "llm"` — когда словарь не подтвердил, но LLM подтвердил кандидат (обычно `confidence = "medium"`).

Код:
- `classes/local/ai_helper.php` — `enrich_sentence_elements()` и `confirm_expression_candidates()`.

### E) Словарь произношений (NB Uttale)
Задача: транскрипция/произношение (достоверные данные), поддержка TTS/обучения произношению.

Источник:
- `.corpus/uttale/NB Uttale database/nb_uttale_leksika/e_spoken_pronunciation_lexicon.csv` (и родственные файлы).
- На сервере загружено в таблицу: `mdl_flashcards_pron_dict`.

Код:
- `classes/local/pronunciation_manager.php` — lookup в `flashcards_pron_dict`.
- Используется в `classes/local/api.php`, `classes/local/ai_helper.php`, `classes/local/ordbank_helper.php` (для связывания орфографии и произношения).

## 3) “Откуда берётся” в response: шпаргалка по `sentence_elements`

### `data.text`
Входной текст (из запроса UI).

### `data.words[]`
- `index` — порядковый номер “слова” в токенизации по буквам.
- `text` — буквенный токен (без пунктуации).
- `start/end` — позиции в строке (regex `\\p{L}+`, пунктуация игнорируется).
- `pos/lemma/dep` — из spaCy (маппится на `index`).

### `data.expressions[]`
Список “устойчивых” выражений, которые прошли фильтры.
Поля:
- `expression` — нормализованная строка выражения.
- `source`:
  - `ordbokene` — подтверждено ord.uib.no (надежно)
  - `cache` — подтверждено локальным кэшем (надежно)
  - `examples` — подтверждено примерами (средне)
  - `pattern` — шаблон/эвристика (обычно низко → отфильтровывается)
  - `llm` — подтверждено LLM (средне)
  - `argstr` — это метка “кандидат собран по argstr-сигналам”, но финальный `source` может стать `ordbokene/llm` после подтверждения.
- `confidence`:
  - `high` — `ordbokene`/`cache`
  - `medium` — `examples`/`llm`
  - `low` — не показываем пользователю (фильтруется)

### `data.enrichment`
Всегда LLM. Это “слой подачи”:
- `sentenceTranslation` — перевод предложения.
- `elements[]` — переводы/ноты для фраз (`type: phrase`) и слов (`type: word`) в заданном порядке.
- `usage` — токены/стоимость.

### `data.debug.spacy`
Debug-секция spaCy. Для проверки “работает ли spaCy”:
- `token_count` > 0 и `tokens[]` содержит `dep` → spaCy даёт зависимости.

## 4) Как мы проверяем качество (достоверность) и скорость (UX)

### Достоверность: слоистая система источников
Рекомендуемый приоритет:
1) Ordbøkene / Ordbank (детерминированно, с источником) → `high`
2) Примеры/кэш → `medium`
3) LLM — только как fallback/перевод/пояснение → `medium`, всегда помечать `source: llm`

Практика:
- Всегда сохраняем `source` и `confidence` и показываем это пользователю (хотя бы кратко).
- Если выражение “важное для изучения”, но подтверждено только LLM — стараться дополнить проверкой через ord.uib.no при следующей возможности.

### Скорость: выдавать результат порциями
Идеальная UX-стратегия:
1) Сразу (быстро): токены + базовые выражения из детерминированных источников (Ordbank/Ordbøkene/spaCy).
2) Чуть позже: LLM enrichment (переводы/пояснения) и optional подтверждения “сомнительных” кандидатов.

Реализации:
- Два запроса: сначала `sentence_elements` без `enrich`, затем повтор с `enrich` (или отдельный endpoint `sentence_enrich`).
- Или один запрос, но UI показывает “скелет” сразу, а enrichment подставляется, когда пришёл.

## 5) Что важно для ученика (и что мы стараемся показывать)
Минимальный “полезный набор”:
- лемма (base form) и перевод,
- управление/предлог (особенно для глаголов),
- устойчивые выражения (2+ слов) и 1–2 примера,
- грамматическая роль (subject/object и т.п.) — spaCy `dep` + простой лейбл,
- произношение/транскрипция (из `flashcards_pron_dict`) и аудио (TTS/запись),
- “не перегружать”: A1–B1 показывать только то, что помогает действию (понять/построить фразу/выбрать предлог).

## 6) Чеклист: что мне присылать, когда “что-то не так”
Если видишь странное поведение:
1) Скопируй response нужного `action` (из Network → Response) и сохрани в `.devtools/<action>_YYYYMMDD_case.json` или обнови существующий.
2) Если важно время/порядок запросов — обнови `.devtools/HAR.json`.
3) В описании кейса (1–2 строки вверху файла или отдельным `.md`) укажи:
   - что вводил в UI,
   - что ожидал увидеть,
   - что получилось,
   - (если есть) какие `source/confidence` пришли.

## 7) Карта кода: куда смотреть по `action`

### `action=sentence_elements`
Назначение: разобрать предложение на слова + найти устойчивые выражения + (опционально) LLM enrichment.
Смотреть:
- `ajax.php` (ветка `case 'sentence_elements'`)
- spaCy: `classes/local/spacy_client.php`, `ajax.php` функции `mod_flashcards_spacy_*_map()`
- Ordbank/argstr: `classes/local/ordbank_helper.php`, особенно `load_argstr_map()` и `extract_argcodes_from_tag()`
- Ordbøkene API: `classes/local/ordbokene_client.php` и ordbokene-lookup в `ajax.php`
- LLM: `classes/local/ai_helper.php` (`enrich_sentence_elements`, `confirm_expression_candidates`)
- UI: `assets/flashcards.js` (`fetchSentenceElements`, рендер анализа)

### `action=ordbank_focus_helper`
Назначение: “клик по слову” → дизамбигуация кандидатов, орфография/лемма/формы/примеры/произношение.
Смотреть:
- `ajax.php` (ветка `ordbank_focus_helper`)
- `classes/local/ordbank_helper.php` (кандидаты, POS/лемма, интеграция с произношением)
- `classes/local/pronunciation_manager.php`

### `action=ai_focus_helper`
Назначение: LLM-помощник для выбора фокуса (слово/выражение), плюс интеграция со словарями.
Смотреть:
- `ajax.php` (ветка `ai_focus_helper`)
- `classes/local/ai_helper.php`
- Ordbøkene/Ordbank части, которые подмешиваются в контекст

### `action=check_text_errors`
Назначение: проверка/коррекция текста (LLM).
Смотреть:
- `ajax.php` (`case 'check_text_errors'`)
- `classes/local/ai_helper.php`

### `action=ai_answer_question`
Назначение: чат/ответ на вопрос по контексту.
Смотреть:
- `ajax.php` (`case 'ai_answer_question'`)
- `classes/local/ai_helper.php`

## 8) Кэширование и ограничения (чтобы было быстро)
Точки, которые обычно дают скорость:
- spaCy: кэш в Moodle (`db/caches.php` → `mod_flashcards/spacy`) + таймауты в `classes/local/spacy_client.php`
- Ordbøkene: кэширование результатов статей/выражений (где применимо) + ограничение количества запросов
- LLM: ограничивать элементы (слова/фразы), короткие промпты, 2-стадийная стратегия (сначала детерминированно, потом enrichment)

## 9) Мини-глоссарий “ярлыков”
- `argstr` (как source/метка): кандидат собран с использованием аргументной структуры (Ordbank `ordbank_argstr`), но финальное подтверждение обычно делается Ordbøkene или LLM.
- `ordbokene`: подтверждено внешним словарём ord.uib.no (высокая надёжность).
- `pattern`: эвристика по POS/предлогам/частицам (обычно требует подтверждения).
