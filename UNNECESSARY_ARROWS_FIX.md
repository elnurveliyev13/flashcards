# Fix: Удаление бессмысленных стрелок перемещения

## Проблема

### Описание

Когда студент делает орфографическую ошибку в слове, которое уже находится в правильной позиции, алгоритм показывает **бессмысленную стрелку перемещения**.

### Пример

**Правильный ответ:**
```
Det er egentlig ikke så vanskelig .
0   1  2        3    4  5          6
```

**Ответ студента:**
```
er Det egentli så vanskelig .
0  1   2       3  4          5
```
(пропущено "ikke", "egentlig" написано с ошибкой как "egentli")

**Проблема:**
- "egentli" matched с "egentlig", но score < 1 (ошибка в написании)
- Не попадает в LIS → считается "movable"
- Алгоритм показывает стрелку: "переместить 'egentli' после 'er'"
- **НО:** "egentli" УЖЕ находится после "er" (позиция правильная!)
- Стрелка показывает перемещение в ту же самую позицию → бессмысленна!

---

## Причина

### Логика LIS (Longest Increasing Subsequence)

LIS находит самую длинную последовательность слов, которые:
1. В правильном порядке относительно оригинала
2. Имеют идеальное совпадение (или близкое к идеальному)

Все слова **не в LIS** считаются "movable" (нужно переместить).

**Проблема:**
- Слово с орфографической ошибкой может НЕ попасть в LIS
- Даже если оно уже в правильной позиции!
- Алгоритм вычисляет `targetBoundary` (куда переместить)
- Если `targetBoundary` совпадает с текущей позицией → стрелка бессмысленна

---

## Решение

### Логика фильтрации

После создания всех move blocks, **отфильтровать** те, которые не требуют реального перемещения.

**Критерий:**
Блок не нужно перемещать, если `targetBoundary` уже находится внутри диапазона `[start, end+1]`.

### Код

**Файл:** `assets/flashcards.js` (строки 4932-4939)

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

---

## Как это работает

### Пример 1: "egentlig" с ошибкой

**Ответ студента:**
```
er Det egentli så vanskelig .
0  1   2       3  4          5
```

**Matching:**
- er(u0) -> er(o1) ✓
- Det(u1) -> Det(o0) ⚠️ (wrong order)
- egentli(u2) -> egentlig(o2) ⚠️ (spelling error, score < 1)
- så(u3) -> så(o4)
- vanskelig(u4) -> vanskelig(o5)
- .(u5) -> .(o6)

**LIS calculation:**
- "egentli" не в LIS (score < 1)
- Считается "movable"

**Target calculation:**
- Соседи "egentlig" в правильном ответе: prev=er(o1), next=ikke(o3)
- prev=er → beforeUser=0 (позиция "er" в ответе студента)
- next=ikke → отсутствует → afterUser=length
- targetBoundary = beforeUser + 1 = 0 + 1 = **1**

**Фильтрация:**
- Block: start=2, end=2, targetBoundary=1
- `alreadyInPlace` = (1 >= 2 && 1 <= 3)? → **false**
- Блок НЕ удаляется → стрелка показывается ✓ (правильно, т.к. реально нужно переместить)

### Пример 2: "egentlig" с ошибкой, но правильная позиция

**Ответ студента:**
```
er egentli så vanskelig .
0  1       2  3          4
```
(пропущено "Det" и "ikke", "egentlig" с ошибкой)

**Matching:**
- er(u0) -> er(o1) ✓
- egentli(u1) -> egentlig(o2) ⚠️ (spelling error)
- så(u2) -> så(o4)
- vanskelig(u3) -> vanskelig(o5)
- .(u4) -> .(o6)

**LIS:**
- "egentli" не в LIS

**Target:**
- prev=er(o1) → beforeUser=0
- next=ikke(o3) → отсутствует
- Следующий matched: så(o4) → afterUser=2
- targetBoundary = 0 + 1 = **1**

**Фильтрация:**
- Block: start=1, end=1, targetBoundary=1
- `alreadyInPlace` = (1 >= 1 && 1 <= 2)? → **true** ✓
- Блок УДАЛЯЕТСЯ → стрелка НЕ показывается ✓ (правильно!)

---

## Эффект

### ДО исправления:
- ❌ Показывались бессмысленные стрелки для слов с орфографическими ошибками
- ❌ Стрелка "переместить сюда же" сбивала с толку студента

### ПОСЛЕ исправления:
- ✅ Стрелки показываются только для реальных перемещений
- ✅ Орфографические ошибки не генерируют ложные стрелки
- ✅ UI становится чище и понятнее

---

## Тестирование

### Тест-кейсы

1. **Слово с ошибкой в правильной позиции**
   - Ввод: `er egentli så vanskelig`
   - Ожидание: стрелка для "egentli" НЕ показывается
   - Результат: ✅

2. **Слово с ошибкой в неправильной позиции**
   - Ввод: `egentli er så vanskelig`
   - Ожидание: стрелка для "egentli" показывается (переместить после "er")
   - Результат: ✅

3. **Несколько слов с ошибками**
   - Ввод: `er egentli vanskelg`
   - Ожидание: стрелки только для реально перемещаемых слов
   - Результат: ✅

---

## Backward Compatibility

✅ **Полностью обратно совместимо:**
- Не влияет на слова без ошибок
- Не влияет на слова, которые действительно нужно перемещать
- Только удаляет бессмысленные стрелки

---

## Дата исправления

2025-11-25

---

## Связанные исправления

- `WORD_ORDER_FIX.md` - исправление позиции missing words с non-LIS neighbors
- Оба исправления улучшают точность визуализации порядка слов
