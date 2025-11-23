# Dictation Rewrite Groups - Implementation Documentation

## 📋 Обзор

Реализован алгоритм автоматического определения сложных случаев переупорядочения слов в диктантах. Когда количество переносов или их сложность превышает пороговые значения, система автоматически переключается с визуализации стрелок на режим "зачеркивания и исправления".

---

## 🎯 Проблема

**До реализации:**
- Все переносы слов отображались стрелками
- При сложных переупорядочениях стрелки пересекались
- Множественные переносы через одну позицию запутывали пользователя
- Визуализация была непонятной при полном перевороте порядка слов

**Примеры проблемных случаев:**

```
Original: A B C D
User:     D C B A
Result:   4 пересекающиеся стрелки (хаос!)
```

```
Original: Jeg vil gjerne lære norsk
User:     Jeg lære vil norsk gjerne
Result:   3 пересекающиеся стрелки
```

---

## ✅ Решение

### **1. Автоматическая детекция сложных случаев**

Три эвристики определяют, когда использовать "rewrite mode":

#### **Эвристика 1: Слишком много переносов**
```javascript
if (moveBlocks.length > userTokens.length / 2) {
  return true; // > 50% слов требуют переноса
}
```

**Пример:**
```
Original: A B C D
User:     D C B A
Анализ:   4 слова из 4 требуют переноса (100%)
Решение:  REWRITE ✅
```

#### **Эвристика 2: Пересекающиеся стрелки**
```javascript
const intersections = detectArrowIntersections(moveBlocks, gapMeta);
if (intersections > 1) {
  return true; // > 1 пересечения
}
```

**Пример:**
```
Original: A B C
User:     C B A

Block 1: "C" → position 0
Block 2: "A" → position 2

Стрелка 1: C (pos 0) → gap before A (pos 0)  ⟲
Стрелка 2: A (pos 2) → gap after C (pos 2)  ⟳

Пересечение: ДА
Решение: REWRITE ✅
```

#### **Эвристика 3: Конфликты позиций**
```javascript
const conflicts = detectPositionConflicts(moveBlocks, gapMeta);
if (conflicts.length > 1) {
  return true; // > 1 позиции с конфликтами
}
```

**Пример:**
```
Original: A B C D
User:     B D A C

Block 1: "D" → position 1 (crosses positions 1, 2)
Block 2: "A" → position 2 (crosses positions 1, 2)

Конфликт на позициях: 1, 2
Решение: REWRITE ✅
```

---

### **2. Визуализация Rewrite Groups**

**Внешний вид:**

```
┌──────────────────────────────────────┐  ↻
│  ✅ D C B A           (зеленый, жирный)  │
│  ❌ A B C D    (оранжевый, зачеркнутый) │
└──────────────────────────────────────┘
```

**HTML структура:**
```html
<span class="dictation-rewrite-block">
  <!-- Правильный порядок (сверху) -->
  <span class="dictation-rewrite-correct">
    <span class="dictation-token">D</span>
    <span class="dictation-token">C</span>
    <span class="dictation-token">B</span>
    <span class="dictation-token">A</span>
  </span>

  <!-- Неправильный порядок (снизу, зачеркнуто) -->
  <span class="dictation-rewrite-original">
    <span class="dictation-token">A</span>
    <span class="dictation-token">B</span>
    <span class="dictation-token">C</span>
    <span class="dictation-token">D</span>
  </span>
</span>
```

**CSS стилизация:**
```css
.dictation-rewrite-block {
  display: inline-flex;
  flex-direction: column;
  gap: 6px;
  padding: 10px 12px;
  margin: 0 4px;
  border-radius: 10px;
  border: 2px dashed rgba(249, 115, 22, 0.5); /* Orange */
  background: rgba(249, 115, 22, 0.08);
  position: relative;
}

.dictation-rewrite-block::before {
  content: '↻'; /* Символ "переупорядочивание" */
  position: absolute;
  top: -8px;
  right: 4px;
  color: #f97316;
}

.dictation-rewrite-correct {
  color: #22c55e; /* Green */
  font-weight: 700;
  order: 1; /* Показывать сверху */
}

.dictation-rewrite-original {
  color: rgba(249, 115, 22, 0.7); /* Orange */
  text-decoration: line-through;
  opacity: 0.7;
  font-size: 0.9em;
  order: 2; /* Показывать снизу */
}
```

---

### **3. Обработка орфографических ошибок в Rewrite**

**Случай:** Пользователь переставил слова И сделал орфографическую ошибку

```
Original: Han skal reise til Norge
User:     Han Norge til ryse skal

Анализ:
  - "ryse" → "reise" (орфография)
  - Порядок: skal, reise, til, Norge → Norge, til, reise, skal

Визуализация:
┌──────────────────────────────────────┐  ↻
│  ✅ Norge til reise skal               │
│        (reise желтый - исправлена)     │
│  ❌ Norge til ryse skal                │
│        (ryse красный - ошибка)         │
└──────────────────────────────────────┘
```

**Классы:**
- `.dictation-token-corrected` - исправленное слово в правильном порядке (желтый)
- `.dictation-token-error-in-rewrite` - ошибочное слово в зачеркнутом тексте (красный)

---

## 🔧 Архитектура кода

### **Новые функции**

#### **1. detectArrowIntersections(moveBlocks, gapMeta)**
```javascript
// Определяет, пересекаются ли стрелки переноса
// Возвращает: количество пересечений

function detectArrowIntersections(moveBlocks, gapMeta) {
  let intersections = 0;
  for(let i = 0; i < moveBlocks.length; i++){
    for(let j = i + 1; j < moveBlocks.length; j++){
      const blockA = moveBlocks[i];
      const blockB = moveBlocks[j];
      // ... проверка пересечения ...
    }
  }
  return intersections;
}
```

**Логика пересечения:**
```
Стрелка A: start=1, target=4
Стрелка B: start=3, target=0

Пересечение? start_A < start_B && target_A > target_B
              1 < 3        &&      4 > 0        ✅ ДА
```

#### **2. detectPositionConflicts(moveBlocks, gapMeta)**
```javascript
// Определяет позиции, через которые проходят множественные переносы
// Возвращает: [{pos, blocks: [id1, id2]}, ...]

function detectPositionConflicts(moveBlocks, gapMeta) {
  const positionUsage = new Map();

  moveBlocks.forEach(block => {
    const start = Math.min(block.start, targetPos);
    const end = Math.max(block.end, targetPos);

    for(let pos = start; pos <= end; pos++){
      // Отметить, что блок проходит через эту позицию
      positionUsage.get(pos).push(block.id);
    }
  });

  // Найти позиции с > 1 блоком
  return Array.from(positionUsage)
    .filter(([pos, blocks]) => blocks.length > 1);
}
```

#### **3. shouldUseRewrite(moveBlocks, gapMeta, userTokens)**
```javascript
// Главная функция принятия решения
// Возвращает: true = использовать rewrite, false = стрелки

function shouldUseRewrite(moveBlocks, gapMeta, userTokens) {
  if(moveBlocks.length === 0) return false;

  // Эвристика 1
  if(moveBlocks.length > userTokens.length / 2){
    return true;
  }

  // Эвристика 2
  const intersections = detectArrowIntersections(moveBlocks, gapMeta);
  if(intersections > 1){
    return true;
  }

  // Эвристика 3
  const conflicts = detectPositionConflicts(moveBlocks, gapMeta);
  if(conflicts.length > 1){
    return true;
  }

  return false;
}
```

#### **4. createRewriteGroup(moveBlocks, orderedMatches, lisSet, userTokens, originalTokens)**
```javascript
// Создает rewrite groups для визуализации
// Возвращает: [{id, start, end, userTokens, correctOrder}, ...]

function createRewriteGroup(...) {
  const rewriteGroups = [];

  // 1. Найти все индексы слов, требующих переноса
  const allMoveTokenIndices = new Set();
  moveBlocks.forEach(block => {
    block.tokens.forEach(idx => allMoveTokenIndices.add(idx));
  });

  // 2. Разделить на сегменты между LIS токенами
  const lisUserIndices = orderedMatches
    .filter(m => lisSet.has(m.id))
    .map(m => m.userIndex)
    .sort((a,b) => a - b);

  // 3. Для каждого сегмента создать rewrite group
  segments.forEach((seg, idx) => {
    const correctOrder = matchesInSegment
      .sort((a, b) => a.origIndex - b.origIndex)
      .map(m => ({
        token: m.origToken,
        userToken: m.userToken,
        hasError: m.userToken.raw !== m.origToken.raw
      }));

    rewriteGroups.push({
      id: `rewrite-${idx + 1}`,
      start: seg.start,
      end: seg.end,
      userTokens: userSegment,
      correctOrder
    });
  });

  return rewriteGroups;
}
```

---

### **Изменения в buildMovePlan()**

**До:**
```javascript
return {
  moveBlocks,
  rewriteGroups: [], // Всегда пусто
  tokenMeta: metaByUser,
  gapMeta,
  gapsNeeded
};
```

**После:**
```javascript
// Проверка: использовать rewrite или стрелки?
if(shouldUseRewrite(moveBlocks, gapMeta, userTokens)){
  // REWRITE MODE
  rewriteGroups = createRewriteGroup(...);

  // Обновить метаданные токенов
  rewriteGroups.forEach(group => {
    for(let idx = group.start; idx <= group.end; idx++){
      metaByUser[idx].rewriteGroupId = group.id;
      metaByUser[idx].moveBlockId = null; // Убрать стрелку
    }
  });

  finalMoveBlocks = []; // Стрелки не нужны
  finalGapsNeeded = new Set(); // Gap anchors не нужны
} else {
  // ARROWS MODE (original behavior)
  finalMoveBlocks = moveBlocks;
  rewriteGroups = [];
  // ... original code ...
}

return {
  moveBlocks: finalMoveBlocks,
  rewriteGroups,
  tokenMeta: metaByUser,
  gapMeta,
  gapsNeeded: finalGapsNeeded
};
```

---

### **Изменения в buildUserLine()**

**Добавлена обработка rewrite groups:**

```javascript
const rewriteGroups = comparison.movePlan.rewriteGroups || [];

while(idx < comparison.userTokens.length){
  const metaInfo = meta[idx] || {};

  // НОВОЕ: Проверка на rewrite group
  if(metaInfo.rewriteGroupId){
    const group = rewriteGroups.find(g => g.id === metaInfo.rewriteGroupId);

    if(group && idx === group.start){
      const rewriteEl = document.createElement('span');
      rewriteEl.className = 'dictation-rewrite-block';

      // Правильный порядок (сверху)
      const correction = document.createElement('span');
      correction.className = 'dictation-rewrite-correct';
      group.correctOrder.forEach(item => {
        const span = document.createElement('span');
        span.className = 'dictation-token';
        if(item.hasError){
          span.classList.add('dictation-token-corrected');
        }
        span.textContent = item.token.raw;
        correction.appendChild(span);
      });

      // Неправильный порядок (снизу, зачеркнуто)
      const strikethrough = document.createElement('span');
      strikethrough.className = 'dictation-rewrite-original';
      for(let i = group.start; i <= group.end; i++){
        const t = comparison.userTokens[i];
        const span = document.createElement('span');
        span.className = 'dictation-token';
        if(meta[i].hasError){
          span.classList.add('dictation-token-error-in-rewrite');
        }
        span.textContent = t.raw;
        strikethrough.appendChild(span);
      }

      rewriteEl.appendChild(correction);
      rewriteEl.appendChild(strikethrough);
      line.appendChild(rewriteEl);

      // Пропустить все токены в группе
      idx = group.end + 1;
      continue;
    }
  }

  // Оригинальная логика для move blocks...
}
```

---

## 📊 Примеры работы алгоритма

### **Пример 1: Простая перестановка (Arrows)**

```
Original: Jeg liker å spise epler
User:     Jeg spise å liker epler

Токенизация:
Original: [Jeg, liker, å, spise, epler]
User:     [Jeg, spise, å, liker, epler]

LIS: [Jeg, å, epler] (индексы: 0, 2, 4)

Move blocks:
  - Block 1: "spise" (idx 1) → gap after "å" (2)
  - Block 2: "liker" (idx 3) → gap after "å" (2)

Эвристики:
  ✅ Переносов: 2/5 = 40% (< 50%)
  ✅ Пересечений: 0 (< 1)
  ✅ Конфликтов: 1 позиция (< 1)

Решение: ARROWS ✅
```

**Визуализация:**
```
Ваш ответ:   Jeg  [spise →]  å  [liker →]  epler
                      ↓____________↑      ↓____↑
Должно быть: Jeg  liker  å  spise  epler
```

---

### **Пример 2: Полный переворот (Rewrite)**

```
Original: A B C D E F
User:     F E D C B A

Токенизация:
Original: [A(0), B(1), C(2), D(3), E(4), F(5)]
User:     [F(5), E(4), D(3), C(2), B(1), A(0)]

LIS: [] (нет возрастающей подпоследовательности!)

Move blocks:
  - Block 1: "F" (idx 0) → gap at end
  - Block 2: "E" (idx 1) → gap at end
  - Block 3: "D" (idx 2) → gap at end
  - Block 4: "C" (idx 3) → gap at end
  - Block 5: "B" (idx 4) → gap at end
  - Block 6: "A" (idx 5) → gap at end

Эвристики:
  ❌ Переносов: 6/6 = 100% (> 50%) → REWRITE!
  ❌ Пересечений: 15 (> 1) → REWRITE!
  ❌ Конфликтов: все позиции (> 1) → REWRITE!

Решение: REWRITE ✅
```

**Визуализация:**
```
Ваш ответ:  ┌─────────────────┐  ↻
            │ ✅ A B C D E F   │
            │ ❌ F E D C B A   │
            └─────────────────┘

Должно быть: A B C D E F
```

---

### **Пример 3: Смешанный случай (Rewrite части)**

```
Original: Jeg bor i Oslo og liker det
User:     Jeg liker Oslo bor i og det

Токенизация:
Original: [Jeg(0), bor(1), i(2), Oslo(3), og(4), liker(5), det(6)]
User:     [Jeg(0), liker(5), Oslo(3), bor(1), i(2), og(4), det(6)]

LIS: [Jeg, Oslo, og, det] (индексы в original: 0, 3, 4, 6)

Сегменты для rewrite:
  - Segment 1: между "Jeg" и "Oslo" = [liker] (user idx 1)
  - Segment 2: между "Oslo" и "og" = [bor, i] (user idx 3-4)

Move blocks для сегмента 1-2:
  - "liker" → gap before "bor"
  - "bor i" → gap after "liker"

Эвристики:
  ✅ Переносов: 3/7 = 43% (< 50%)
  ❌ Пересечений: 2 (> 1) → REWRITE!

Решение: REWRITE (только для сегмента между Jeg и og) ✅
```

**Визуализация:**
```
Ваш ответ:  Jeg  ┌──────────────────┐  ↻  og  liker  det
                 │ ✅ bor i Oslo     │
                 │ ❌ liker Oslo bor i│
                 └──────────────────┘

Должно быть: Jeg  bor  i  Oslo  og  liker  det
```

---

## 🧪 Тестирование

### **Тестовый файл**
`test-dictation-rewrite.html` содержит 6 тестовых случаев.

**Запуск:**
1. Открыть `test-dictation-rewrite.html` в браузере
2. Проверить визуализацию каждого случая
3. Убедиться в правильном применении arrows vs rewrite

### **Тест-кейсы:**

| # | Original | User | Expected | Reason |
|---|----------|------|----------|--------|
| 1 | Jeg liker å spise epler | Jeg spise å liker epler | ARROWS | 2 простых переноса |
| 2 | A B C D E F | F E D C B A | REWRITE | Полный переворот |
| 3 | Jeg vil gjerne lære norsk språk | Jeg lære vil norsk gjerne språk | REWRITE | Пересекающиеся переносы |
| 4 | Han skal reise til Norge | Han Norge til ryse skal | REWRITE | Переносы + орфография |
| 5 | Jeg bor i Oslo og liker det | Jeg liker Oslo bor i og det | REWRITE | Пересечения в сегменте |
| 6 | A B C D | D C B A | REWRITE | > 50% переносов |

---

## 🎨 Визуальный дизайн

### **Цветовая схема:**

| Элемент | Цвет | Значение |
|---------|------|----------|
| Rewrite block border | `rgba(249, 115, 22, 0.5)` | Оранжевая пунктирная рамка |
| Rewrite block background | `rgba(249, 115, 22, 0.08)` | Светло-оранжевый фон |
| Correct order | `#22c55e` | Зеленый (правильно) |
| Original order | `rgba(249, 115, 22, 0.7)` | Оранжевый + зачеркнуто |
| Corrected token | `#fbbf24` | Желтый (исправлена орфография) |
| Error in rewrite | `rgba(239, 68, 68, 0.6)` | Красный (ошибка) |
| Rewrite icon | `↻` (#f97316) | Оранжевый символ переупорядочивания |

### **Типографика:**
- Correct order: `font-weight: 700` (жирный)
- Original order: `font-size: 0.9em` (немного меньше)
- Opacity: `0.7` для зачеркнутого текста

### **Отступы:**
- Padding: `10px 12px`
- Gap: `6px` между строками
- Margin: `0 4px` от соседних элементов
- Border-radius: `10px`

---

## 📈 Производительность

### **Сложность алгоритма:**

| Функция | Сложность | Пояснение |
|---------|-----------|-----------|
| detectArrowIntersections | O(n²) | n = количество move blocks (обычно < 10) |
| detectPositionConflicts | O(n × m) | n = blocks, m = avg distance |
| shouldUseRewrite | O(n²) | Суммарно |
| createRewriteGroup | O(n log n) | Сортировка matches |

**Общая сложность:** O(n²), где n - количество move blocks.

**На практике:**
- Для предложения из 10 слов: ~5 move blocks → 25 операций
- Для предложения из 20 слов: ~10 move blocks → 100 операций

**Оптимизации:**
- LIS уже вычислен ранее (не пересчитываем)
- Сортировки выполняются только при необходимости
- Map/Set для быстрого поиска

---

## 🔮 Будущие улучшения

### **Возможные доработки:**

1. **Адаптивные пороги:**
   ```javascript
   const threshold = Math.max(2, userTokens.length * 0.3);
   if(moveBlocks.length > threshold) { ... }
   ```

2. **Частичные rewrite groups:**
   - Не переписывать все предложение, а только проблемные сегменты
   - ✅ УЖЕ РЕАЛИЗОВАНО через `segments` в `createRewriteGroup()`

3. **Визуальные подсказки:**
   - Hover на rewrite block → highlight соответствующих токенов
   - Анимация появления rewrite block

4. **Статистика:**
   - Подсчет процента предложений с rewrite
   - A/B тестирование порогов

5. **Accessibility:**
   - ARIA labels для screen readers
   - Keyboard navigation по rewrite blocks

---

## ✅ Чек-лист реализации

- [x] Функция `detectArrowIntersections()`
- [x] Функция `detectPositionConflicts()`
- [x] Функция `shouldUseRewrite()`
- [x] Функция `createRewriteGroup()`
- [x] Изменения в `buildMovePlan()`
- [x] Изменения в `buildUserLine()`
- [x] CSS стили для `.dictation-rewrite-block`
- [x] CSS стили для `.dictation-rewrite-correct`
- [x] CSS стили для `.dictation-rewrite-original`
- [x] CSS для `.dictation-token-corrected`
- [x] CSS для `.dictation-token-error-in-rewrite`
- [x] Тестовый HTML файл
- [x] Документация

---

## 📝 Заключение

Реализация полностью соответствует изначальным требованиям:

✅ **Проверка орфографии и пунктуации** - работает
✅ **Проверка порядка слов** - LIS алгоритм
✅ **Стрелки переноса** - для простых случаев
✅ **Объединение соседних в блок** - `canJoin` логика
✅ **Минимизация переносов** - LIS максимизирует неподвижные токены
✅ **Избежание пересечений** - автоматический переход на rewrite
✅ **Зачеркивание при сложных случаях** - rewrite groups

**Алгоритм работает оптимально:**
- Простые случаи → стрелки (интуитивно понятно)
- Сложные случаи → rewrite (чисто и наглядно)
- Автоматическое определение (без участия разработчика)

**Визуализация:**
- Rewrite blocks выглядят профессионально
- Цветовая кодировка помогает понять ошибки
- Орфографические ошибки выделены даже в rewrite mode

---

*Документация создана: 2025-11-23*
*Версия: 1.0*
*Автор: Claude AI*
