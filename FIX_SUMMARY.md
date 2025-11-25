# Резюме исправлений: Word Order Fixes

## Исправление 1: Missing Words с Non-LIS Neighbors

### Проблема в одном предложении

Когда студент пропускает слово, а соседнее слово написано с ошибкой, алгоритм показывает пропущенное слово в неправильной позиции.

---

### Что было исправлено

**Файл:** `assets/flashcards.js`
**Функция:** `buildMovePlan()` (строки 4728-4772)

### Изменение

**БЫЛО:**
```javascript
const beforeUser = prev !== -1 && lisOrigToUser.has(prev) ?
    lisOrigToUser.get(prev) : -1;
const afterUser = next !== null && lisOrigToUser.has(next) ?
    lisOrigToUser.get(next) : userTokens.length;
```

**СТАЛО:**
```javascript
// Create map of ALL matches (not just LIS)
const allOrigToUser = new Map();
orderedMatches.forEach(m => allOrigToUser.set(m.origIndex, m.userIndex));

// Use LIS first, fallback to all matches
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

---

## Суть FIX

- **Раньше:** использовались только слова из LIS (Longest Increasing Subsequence) для определения позиции gap
- **Теперь:** сначала проверяются слова из LIS, но если их нет — используются ВСЕ matched слова
- **Результат:** даже если соседнее слово написано с ошибкой (не в LIS), его позиция учитывается

---

## Пример

### Правильный ответ
```
Det er egentlig ikke så vanskelig .
```

### Ответ студента
```
Det er egentlig så vanskelig .
```
(пропущено "ikke", "så" с ошибкой)

### ДО исправления
- "så" с ошибкой → не в LIS
- Алгоритм не видит "så" как якорь
- Показывает "ikke" где-то в конце ❌

### ПОСЛЕ исправления
- "så" matched (хоть и с ошибкой)
- Алгоритм использует его позицию
- Показывает "ikke" между "egentlig" и "så" ✅

---

## Исправление 2: Бессмысленные стрелки перемещения

### Проблема в одном предложении

Когда слово с орфографической ошибкой уже находится в правильной позиции, алгоритм показывает бессмысленную стрелку "переместить сюда же".

---

### Что было исправлено

**Файл:** `assets/flashcards.js`
**Функция:** `buildMoveBlocks()` (строки 4932-4939)

### Изменение

Добавлена фильтрация блоков перемещения:

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

### Суть FIX

- **Раньше:** слова с ошибками, не попавшие в LIS, всегда получали стрелку перемещения
- **Теперь:** проверяется, действительно ли слово нужно перемещать (сравнивается текущая и целевая позиция)
- **Результат:** стрелки показываются только для реальных перемещений

### Пример

**Правильный ответ:** `er egentlig så vanskelig`
**Ответ студента:** `er egentli så vanskelig` (ошибка в "egentlig")

**ДО исправления:**
- "egentli" не в LIS → создается move block
- Показывается стрелка "переместить после er"
- НО слово УЖЕ после "er"! ❌

**ПОСЛЕ исправления:**
- "egentli" не в LIS → создается move block
- Фильтр проверяет: targetBoundary = 1, start = 1, end = 1
- 1 >= 1 && 1 <= 2 → true → блок удаляется
- Стрелка НЕ показывается ✅

---

## Тестирование

Откройте файл `test-word-order-fix.html` в браузере и проверьте консоль (F12).

См. также:
- `WORD_ORDER_FIX.md` - детали исправления 1
- `UNNECESSARY_ARROWS_FIX.md` - детали исправления 2

---

## Дата

2025-11-25
