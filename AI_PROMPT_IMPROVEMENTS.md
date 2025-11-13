# AI Prompt Improvements - 2025-11-13

## Проблемы которые были решены

### 1. ❌ Базовая форма (BASE FORM) содержала артикли

**Проблема**:
Скрипт в поле "Базовая форма" (base form) писал артикль вместе со словом (например, "(en) helg" вместо "helg").

**Причина**:
В промпте не было отдельного поля BASE-FORM, и в код передавалось значение WORD, которое по дизайну должно содержать артикль для существительных.

**Решение**:
- Добавлено новое поле в формат ответа: `BASE-FORM: <lemma without any articles or "å" prefix - just the bare word>`
- Добавлено правило в NOTES: "BASE-FORM field must contain ONLY the lemma without articles (en/ei/et) and without "å" prefix. For example: if WORD is "en helg", BASE-FORM should be "helg"; if WORD is "å gjøre", BASE-FORM should be "gjøre"."
- Обновлён PHP код для извлечения и использования нового поля BASE-FORM
- Добавлена инструкция в user prompt: "IMPORTANT: Output BASE-FORM field with ONLY the bare lemma (without articles en/ei/et and without "å" prefix). For example: if WORD is "en helg", BASE-FORM should be "helg"."

**Файлы изменены**:
- `classes/local/openai_client.php`:
  - Строки 124-127: Добавлено поле BASE-FORM в FORMAT
  - Строка 149: Добавлено правило про BASE-FORM в NOTES
  - Строка 161: Добавлена инструкция в user prompt
  - Строка 371: Добавлено извлечение baseform из ответа AI
  - Строка 196: Использование baseform из AI вместо focus (с фоллбэком на focus если baseform пустой)

---

### 2. ❌ Объяснение ошибок на английском языке

**Проблема**:
Объяснение ошибок в поле CORR происходило на английском языке (например, "preposition needed", "correct preposition"), а должно происходить на языке перевода (например, на русском или украинском).

**Причина**:
В промпте не было явного требования писать объяснения ошибок на целевом языке ($targetlang).

**Решение**:
- Обновлено правило в RULES (строка 120): Добавлено "IN {$targetlang} LANGUAGE" в конце инструкции про проверку ошибок
- Обновлена строка CORR в FORMAT (строка 136): Явно указано "(explanation in {$targetlang})" для каждой ошибки
- Обновлено правило в NOTES (строка 148): Изменён формат на "(reason in {$targetlang})"
- Обновлена инструкция в user prompt (строка 163): Добавлено "IN ' . $targetlang . ' LANGUAGE" и примеры с "(explanation in ' . $targetlang . ')"

**Файлы изменены**:
- `classes/local/openai_client.php`:
  - Строка 120: Добавлено "IN {$targetlang} LANGUAGE"
  - Строка 136: Обновлён формат CORR с явным указанием языка объяснений
  - Строка 148: Обновлено правило NOTES с указанием языка
  - Строка 163: Обновлены инструкции в user prompt

---

## Примеры использования

### Пример 1: Существительное с артиклем

**Входные данные**:
- Предложение: "Hva pleier du å gjøre helgene?"
- Кликнутое слово: "helgene"
- Язык перевода: Русский

**Ожидаемый результат**:
```
WORD: en helg
BASE-FORM: helg
POS: substantiv
GENDER: hankjønn
EXPL-NO: periode med to dager (lørdag og søndag) når folk ikke jobber
TR-RU: выходные
...
```

### Пример 2: Глагол с инфинитивной частицей

**Входные данные**:
- Предложение: "Jeg liker å gjøre yoga"
- Кликнутое слово: "gjøre"
- Язык перевода: Украинский

**Ожидаемый результат**:
```
WORD: å gjøre
BASE-FORM: gjøre
POS: verb
GENDER: -
EXPL-NO: utføre en handling eller aktivitet
TR-UK: робити
...
```

### Пример 3: Ошибка в предложении

**Входные данные**:
- Предложение: "Hva pleier du å gjøre i helgene?" (правильно)
- Предложение: "Hva pleier du å gjøre helgene?" (ошибка - нет предлога "i")
- Кликнутое слово: "helgene"
- Язык перевода: Русский

**Ожидаемый результат**:
```
...
CORR: Hva pleier du å gjøre i helgene? — "helgene"→"i helgene" (нужен предлог "i" для обозначения времени)
```

**Старый результат (неправильный)**:
```
CORR: Hva pleier du å gjøre i helgene? — "helgene"→"i helgene" (preposition needed)
```

---

## Техническая документация

### Формат ответа AI (обновлённый)

```
FORMAT:
WORD: <base form with article or "å">
BASE-FORM: <lemma without any articles or "å" prefix - just the bare word>
POS: <one of substantiv|adjektiv|pronomen|determinativ|verb|adverb|preposisjon|konjunksjon|subjunksjon|interjeksjon|phrase|other>
GENDER: <hankjønn|hunkjønn|intetkjønn|-> (nouns only)
EXPL-NO: <simple Norwegian explanation>
TR-XX: <target language translation of meaning>
COLL: <0-5 common Norwegian collocations (no translations), semicolon-separated>
EX1: <NO sentence using a top collocation> | <target language>
EX2: <NO> | <target language>
EX3: <NO> | <target language>
FORMS: <other useful lexical forms (verb/noun/adj variants) with tiny NO gloss + target language>
CORR: <fully corrected sentence> — <list each error in target language: "wrong"→"correct" (explanation in target language); etc.>
```

### Изменения в коде PHP

**1. Извлечение BASE-FORM из ответа AI**

Файл: `classes/local/openai_client.php`

```php
// Добавлено в parse_structured_response() на строке 371
return [
    'word' => $data['WORD'] ?? '',
    'baseform' => $data['BASE-FORM'] ?? '',  // ← НОВОЕ ПОЛЕ
    'pos' => $data['POS'] ?? '',
    // ...
];
```

**2. Использование BASE-FORM в результате**

Файл: `classes/local/openai_client.php`

```php
// Изменено в detect_focus_data() на строке 196
return [
    'focus' => core_text::substr($focus, 0, 200),
    'baseform' => core_text::substr($parsed['baseform'] ?: $focus, 0, 200),  // ← ИСПОЛЬЗУЕТ BASE-FORM
    'pos' => $this->normalize_pos($parsed['pos'] ?? '', $focus),
    // ...
];
```

### Передача данных в JavaScript

Результат функции `detect_focus_data()` передаётся через AJAX в JavaScript (файл `assets/flashcards.js`), где используется:

```javascript
// В функции applyAiPayload() на строке 1698
if(data.focusBaseform && focusBaseInput){
  const current = (focusBaseInput.value || '').trim();
  if(!current || /ingen/i.test(current)){
    focusBaseInput.value = data.focusBaseform;  // ← ЗНАЧЕНИЕ ИЗ BASE-FORM
  }
}
```

---

## Тестирование

### Тест 1: Базовая форма без артиклей

**Шаги**:
1. Открыть карточку в режиме Create или Edit
2. Ввести предложение: "Hva pleier du å gjøre helgene?"
3. Кликнуть на слово "helgene"
4. Дождаться ответа AI Focus Helper

**Ожидаемый результат**:
- Поле "Fokus word/phrase" (Fokus): "en helg"
- Поле "Base form" (Базовая форма): "helg" (без артикля!)

**Как проверить вручную**:
Посмотреть на скриншот, который вы предоставили - поле "(en) helg" должно теперь отображать только "helg".

---

### Тест 2: Объяснение ошибок на языке перевода

**Шаги**:
1. Открыть карточку в режиме Create или Edit
2. Установить язык перевода: Русский (Голос → Русский)
3. Ввести предложение с ошибкой: "Hva pleier du å gjøre helgene?" (нет предлога "i")
4. Кликнуть на слово "helgene"
5. Дождаться ответа AI Focus Helper

**Ожидаемый результат**:
- Появится сообщение об ошибке:
  "Hva pleier du å gjøre i helgene? — "helgene"→"i helgene" (нужен предлог "i" для обозначения времени)"
- Объяснение "нужен предлог..." должно быть на русском языке

**Старый результат (неправильный)**:
- "...→"i helgene" (preposition needed)" или "(correct preposition)"

---

### Тест 3: Украинский язык

**Шаги**:
1. Открыть карточку в режиме Create или Edit
2. Установить язык перевода: Українська (Голос → Українська)
3. Ввести предложение с ошибкой: "Jeg liker å spise i restaurant" (нужен артикль "en")
4. Кликнуть на слово "restaurant"
5. Дождаться ответа AI Focus Helper

**Ожидаемый результат**:
- Появится сообщение об ошибке на украинском языке:
  "Jeg liker å spise på en restaurant. — "i"→"på" (потрібен прийменник "på"); "restaurant"→"en restaurant" (потрібен артикль)"

---

## Дополнительные улучшения (опционально)

Если потребуется дальнейшая оптимизация промпта, можно рассмотреть:

1. **Добавить примеры в промпт**: Добавить несколько примеров правильного формата BASE-FORM и CORR прямо в system prompt
2. **Усилить температуру**: Если AI всё ещё иногда путает языки, можно уменьшить `temperature` с 0.2 до 0.1
3. **Добавить валидацию на стороне PHP**: Проверять, что BASE-FORM не содержит артиклей или "å"
4. **Добавить фоллбэк**: Если AI не вернул BASE-FORM, автоматически удалять артикли и "å" из WORD

---

## Итоги

✅ **Проблема 1 решена**: Базовая форма теперь отображается без артиклей
✅ **Проблема 2 решена**: Объяснения ошибок теперь на языке перевода

**Файлы изменены**:
- `classes/local/openai_client.php` (7 изменений в промптах и коде)

**Новые требования для AI**:
- Добавлено поле BASE-FORM в формат ответа
- Все объяснения ошибок теперь должны быть на целевом языке ($targetlang)

**Обратная совместимость**: ✅ Сохранена (если AI не вернёт BASE-FORM, используется WORD как фоллбэк)
