# Word Order Fix: Missing Words with Non-LIS Neighbors

## Проблема

### Описание ситуации

Когда студент:
1. Пропускает слово в предложении (например, "ikke")
2. Делает ошибку в соседнем слове (например, "så" вместо правильного написания)

Алгоритм **неправильно** указывает позицию для пропущенного слова.

### Конкретный пример

**Правильный ответ:**
```
Det er egentlig ikke så vanskelig .
0   1  2         3    4  5          6
```

**Ответ студента (с ошибкой в "så"):**
```
Det er egentlig så vanskelig .
0   1  2        3  4          5
```

**Ожидаемое поведение:**
- Пропущенное слово "ikke" должно быть показано **после "egentlig" и перед "så"**
- Позиция: между user index 2 и 3

**Фактическое поведение (ДО FIX):**
- "så" с ошибкой не попадает в LIS (Longest Increasing Subsequence)
- Алгоритм ищет соседей "ikke" в правильном ответе: prev="egentlig" (3), next="så" (4)
- Но "så" не в LIS, поэтому `lisOrigToUser.has(4)` = false
- Результат: `beforeUser = 2`, `afterUser = userTokens.length` (конец предложения!)
- Алгоритм показывает "ikke" **где-то в конце**, что неправильно

---

## Причина проблемы

### Код ДО исправления (строки 4749-4750)

```javascript
const beforeUser = prev !== -1 && lisOrigToUser.has(prev) ? lisOrigToUser.get(prev) : -1;
const afterUser = next !== null && lisOrigToUser.has(next) ? lisOrigToUser.get(next) : userTokens.length;
```

**Проблема:**
- Используется только `lisOrigToUser` (Map со словами из LIS)
- Если сосед в правильном ответе **существует в ответе студента**, но **не в LIS** (из-за ошибки или перестановки), он игнорируется
- В результате gap вычисляется неправильно

### Почему "så" не попадает в LIS?

LIS (Longest Increasing Subsequence) — это самая длинная последовательность слов, которые уже в правильном порядке.

Когда "så" написано с ошибкой:
- `tokenSimilarity()` возвращает score < 1 (не точное совпадение)
- Matching алгоритм может сопоставить его с правильным "så", но с низким score
- В зависимости от других ошибок, "så" может НЕ попасть в LIS

---

## Решение

### Логика FIX

1. **Создать `allOrigToUser` Map** со всеми matched словами (не только LIS)
2. **При вычислении `beforeUser` и `afterUser`:**
   - Сначала проверить `lisOrigToUser` (приоритет для якорных слов)
   - Если нет в LIS, проверить `allOrigToUser` (fallback)
   - Только если нет ни в LIS, ни в matches, использовать -1 или length

### Код ПОСЛЕ исправления (строки 4728-4772)

```javascript
// Create a map of ALL matched words (not just LIS) for better gap calculation
const allOrigToUser = new Map();
orderedMatches.forEach(m => allOrigToUser.set(m.origIndex, m.userIndex));

// ... (findNeighbors остается без изменений)

orderedMatches.forEach((m)=>{
  const inLis = lisSet.has(m.id);
  const { prev, next } = findNeighbors(m.origIndex);
  const gapKey = buildGapKey(prev, next);

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

  gapMeta[gapKey] = gapMeta[gapKey] || {
    before: prev,
    after: next,
    beforeUser,
    afterUser,
    targetBoundary: beforeUser + 1
  };
  // ... остальной код
});
```

---

## Как это работает

### Пример с исправлением

**Правильный ответ:**
```
Det(0) er(1) egentlig(2) ikke(3) så(4) vanskelig(5) .(6)
```

**Ответ студента:**
```
Det(0) er(1) egentlig(2) så(3) vanskelig(4) .(5)
```

**Matching:**
- Det(u0) -> Det(o0) ✓ (в LIS)
- er(u1) -> er(o1) ✓ (в LIS)
- egentlig(u2) -> egentlig(o2) ✓ (в LIS)
- så(u3) -> så(o4) ⚠️ (matched, но может быть НЕ в LIS из-за ошибки)
- vanskelig(u4) -> vanskelig(o5) ✓ (в LIS)
- .(u5) -> .(o6) ✓ (в LIS)

**Missing:**
- ikke(o3) — пропущено

**Вычисление позиции для "ikke":**

1. Соседи в правильном ответе:
   - prev = egentlig (o2)
   - next = så (o4)

2. **Старый алгоритм:**
   - `lisOrigToUser.has(2)` = true → beforeUser = 2 ✓
   - `lisOrigToUser.has(4)` = **false** (så не в LIS) → afterUser = **userTokens.length (6)** ❌
   - Результат: "ikke" должен быть между позициями 2 и 6 (неправильно!)

3. **Новый алгоритм:**
   - `lisOrigToUser.has(2)` = true → beforeUser = 2 ✓
   - `lisOrigToUser.has(4)` = false, НО `allOrigToUser.has(4)` = **true** → afterUser = **3** ✓
   - Результат: "ikke" должен быть между позициями 2 и 3 (правильно!)

---

## Тестирование

### Тестовый файл

Создан файл `test-word-order-fix.html` для проверки логики.

### Тест-кейсы

1. **"så" с ошибкой + missing "ikke"**
   - ДО FIX: "ikke" показывается в конце
   - ПОСЛЕ FIX: "ikke" показывается между "egentlig" и "så"

2. **"så" правильно + missing "ikke"**
   - ДО FIX: работает правильно (så в LIS)
   - ПОСЛЕ FIX: работает правильно (без изменений)

### Как тестировать

1. Откройте `test-word-order-fix.html` в браузере
2. Откройте консоль (F12)
3. Проверьте вывод:
   - OLD WAY: beforeUser и afterUser по старому алгоритму
   - NEW WAY: beforeUser и afterUser по новому алгоритму
4. Убедитесь, что NEW WAY дает правильные позиции

---

## Файлы изменены

- `assets/flashcards.js` (строки 4728-4772)
  - Добавлено: `allOrigToUser` Map
  - Изменено: логика вычисления `beforeUser` и `afterUser` с fallback

---

## Backward Compatibility

✅ **Изменение полностью обратно совместимо:**
- Если все слова в LIS (нет ошибок), поведение не меняется
- Если есть ошибки, но соседи в LIS, поведение не меняется
- Исправляется только случай: сосед не в LIS, но matched

---

## Дата исправления

2025-11-25

---

## Автор

Claude Code (по запросу пользователя)
