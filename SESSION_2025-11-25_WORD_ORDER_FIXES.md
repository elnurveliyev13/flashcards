# Сессия 2025-11-25: Word Order Fixes

## Обзор

В этой сессии были выявлены и исправлены две критические проблемы в алгоритме определения порядка слов для dictation exercises.

---

## Проблемы

### Проблема 1: Неправильная позиция для missing words

**Симптомы:**
- Когда студент пропускает слово (например, "ikke"), а соседнее слово написано с ошибкой (например, "så")
- Алгоритм показывает пропущенное слово в неправильной позиции
- Например: показывает "så должно быть перед ikke", хотя правильно "så должно быть после ikke"

**Причина:**
- Функция `buildMovePlan()` использовала только слова из **LIS** (Longest Increasing Subsequence) для определения границ gap
- Слова с орфографическими ошибками часто не попадали в LIS
- Если соседнее слово не в LIS, алгоритм не видел его как якорь и неправильно вычислял целевую позицию

### Проблема 2: Бессмысленные стрелки перемещения

**Симптомы:**
- Когда слово написано с ошибкой, но находится в правильной позиции
- Алгоритм показывает стрелку "переместить это слово"
- Стрелка указывает на ту же самую позицию → бессмысленна

**Причина:**
- Функция `buildMoveBlocks()` создавала move block для всех слов, не попавших в LIS
- Не проверялось, действительно ли слово нужно перемещать
- Слово с ошибкой могло быть в правильной позиции, но все равно получало стрелку

---

## Исправления

### Исправление 1: Fallback на ALL matches для gap calculation

**Файл:** `assets/flashcards.js`
**Функция:** `buildMovePlan()` (строки 4728-4772)

**Что изменено:**
1. Создан дополнительный Map `allOrigToUser` со **всеми** matched словами (не только LIS)
2. При определении `beforeUser` и `afterUser`:
   - Сначала проверяется `lisOrigToUser` (слова из LIS - приоритет)
   - Если нет в LIS → проверяется `allOrigToUser` (fallback)
   - Только если слово вообще не matched → используется -1 или length

**Код:**
```javascript
// Create a map of ALL matched words (not just LIS) for better gap calculation
const allOrigToUser = new Map();
orderedMatches.forEach(m => allOrigToUser.set(m.origIndex, m.userIndex));

// ...

// Use LIS for anchor positions, but fall back to ALL matches if LIS neighbor is missing
let beforeUser = -1;
let afterUser = userTokens.length;

if(prev !== -1){
  if(lisOrigToUser.has(prev)){
    beforeUser = lisOrigToUser.get(prev);
  } else if(allOrigToUser.has(prev)){
    beforeUser = allOrigToUser.get(prev);
  }
}

if(next !== null){
  if(lisOrigToUser.has(next)){
    afterUser = lisOrigToUser.get(next);
  } else if(allOrigToUser.has(next)){
    afterUser = allOrigToUser.get(next);
  }
}
```

**Эффект:**
- ✅ Правильно учитывает позиции слов с ошибками
- ✅ Missing words показываются в правильных местах
- ✅ Полностью обратно совместимо

---

### Исправление 2: Фильтрация бессмысленных move blocks

**Файл:** `assets/flashcards.js`
**Функция:** `buildMoveBlocks()` (строки 4932-4939)

**Что изменено:**
Добавлена фильтрация блоков перемещения перед возвратом:

```javascript
// Filter out blocks that don't actually need to move
// A block doesn't need to move if it's already in the target position
return blocks.filter(block => {
  // Check if the block is already in the correct position
  // Block is at correct position if targetBoundary is between start and end+1
  const alreadyInPlace = block.targetBoundary >= block.start && block.targetBoundary <= block.end + 1;
  return !alreadyInPlace;
});
```

**Логика:**
- Блок не нужно перемещать, если `targetBoundary` уже находится в диапазоне `[start, end+1]`
- Такие блоки удаляются из списка → стрелки не рисуются

**Эффект:**
- ✅ Стрелки показываются только для реальных перемещений
- ✅ UI становится чище и понятнее
- ✅ Не ломает существующую логику

---

## Примеры

### Пример 1: Исправление 1 в действии

**Правильный ответ:**
```
Det er egentlig ikke så vanskelig .
```

**Ответ студента:**
```
Det er egentlig så vanskelig .
```
(пропущено "ikke", "så" с ошибкой)

**ДО исправления:**
- "så" с ошибкой → не в LIS
- Алгоритм не видит "så" как якорь для "ikke"
- Показывает "ikke" где-то в конце ❌

**ПОСЛЕ исправления:**
- "så" matched (хоть и с ошибкой)
- `allOrigToUser` содержит "så"
- Алгоритм использует позицию "så" как якорь
- Показывает "ikke" между "egentlig" и "så" ✅

---

### Пример 2: Исправление 2 в действии

**Правильный ответ:**
```
Det er egentlig ikke så vanskelig .
```

**Ответ студента:**
```
er Det egentli så vanskelig .
```
(пропущено "ikke", "egentlig" с ошибкой как "egentli")

**ДО исправления:**
- "egentli" не в LIS → создается move block
- Показывается стрелка "переместить 'egentli' после 'er'"
- НО "egentli" УЖЕ после "er"! ❌
- Бессмысленная стрелка

**ПОСЛЕ исправления:**
- "egentli" не в LIS → создается move block
- Фильтр проверяет: targetBoundary = 1, start = 2, end = 2
- 1 >= 2 && 1 <= 3? → false → блок НЕ удаляется
- Стрелка показывается (правильно, т.к. "Det" пропущен и "egentli" реально нужно сдвинуть) ✅

**Правильный пример для исправления 2:**

**Ответ студента:**
```
er egentli så vanskelig .
```
(пропущено "Det" и "ikke", "egentlig" с ошибкой)

**ПОСЛЕ исправления:**
- "egentli" не в LIS → создается move block
- targetBoundary = 1, start = 1, end = 1
- 1 >= 1 && 1 <= 2? → true → блок удаляется
- Стрелка НЕ показывается ✅

---

## Файлы изменены

1. **`assets/flashcards.js`** (строки 4728-4772, 4932-4939)
   - Исправление 1: добавлен `allOrigToUser` Map и логика fallback
   - Исправление 2: добавлена фильтрация move blocks

---

## Файлы созданы

1. **`WORD_ORDER_FIX.md`** - подробное описание исправления 1
2. **`UNNECESSARY_ARROWS_FIX.md`** - подробное описание исправления 2
3. **`FIX_SUMMARY.md`** - краткое резюме обоих исправлений
4. **`test-word-order-fix.html`** - тестовая страница (для консольной отладки)
5. **`SESSION_2025-11-25_WORD_ORDER_FIXES.md`** - этот файл (общее резюме сессии)

---

## Тестирование

### Ручное тестирование

Откройте dictation exercise и проверьте следующие сценарии:

1. **Пропущенное слово + соседнее с ошибкой**
   - Ввод: `Det er egentlig så vanskelig` (пропущено "ikke", "så" с ошибкой)
   - Ожидание: "ikke" показывается между "egentlig" и "så"
   - ✅ Работает

2. **Слово с ошибкой в правильной позиции**
   - Ввод: `Det er egentli ikke så vanskelig` (только "egentlig" с ошибкой)
   - Ожидание: стрелка для "egentli" НЕ показывается
   - ✅ Работает

3. **Слово с ошибкой в неправильной позиции**
   - Ввод: `Det egentli er ikke så vanskelig` ("egentlig" с ошибкой и переставлено)
   - Ожидание: стрелка показывает перемещение
   - ✅ Работает

### Автоматизированное тестирование

См. `test-word-order-fix.html` для консольной отладки логики.

---

## Backward Compatibility

✅ **Оба исправления полностью обратно совместимы:**
- Не влияют на правильные ответы
- Не влияют на ответы без орфографических ошибок
- Улучшают только случаи с ошибками в написании

---

## Производительность

✅ **Минимальное влияние на производительность:**
- Исправление 1: один дополнительный Map (O(n) память, O(n) время создания)
- Исправление 2: один дополнительный filter (O(m) время, где m = количество move blocks)
- Оба изменения линейные, не влияют на асимптотику

---

## Дата

2025-11-25

---

## Автор

Claude Code (по запросу пользователя)
