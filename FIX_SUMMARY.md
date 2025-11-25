# Резюме исправления: Word Order для Missing Words

## Проблема в одном предложении

Когда студент пропускает слово, а соседнее слово написано с ошибкой, алгоритм показывает пропущенное слово в неправильной позиции.

---

## Что было исправлено

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

## Тестирование

Откройте файл `test-word-order-fix.html` в браузере и проверьте консоль (F12).

---

## Дата

2025-11-25
