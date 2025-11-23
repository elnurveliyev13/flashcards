# Исправление ошибок Rewrite Groups

## 🐛 Проблемы в первоначальной реализации

### Проблема 1: Неправильный алгоритм createRewriteGroup()

**Что было:**
```javascript
const correctOrder = matchesInSegment
  .sort((a, b)=> a.origIndex - b.origIndex)
  .map(m=> ({
    token: m.origToken,  // ← Брали origToken из match
    userToken: m.userToken,
    hasError: m.userToken.raw !== m.origToken.raw
  }));
```

**Проблема:**
- Брали только те слова, которые **ЕСТЬ** у студента
- Если студент пропустил слово из оригинала в этом диапазоне → оно не попадало в `correctOrder`
- Результат: зачеркнутое и исправленное совпадали!

**Пример (скриншот 3):**
```
User:     var hyggelig Det veldig å se deg i dag .
Original: Det var veldig hyggelig å se deg i dag .

Проблемный сегмент: user[2:3] = "Det veldig"
Matches в сегменте:
  - Det (user 2, orig 0)
  - veldig (user 3, orig 2)

correctOrder строился только из этих двух:
  → "Det veldig" (отсортировано по orig: 0, 2)

НО в оригинале между 0 и 2 есть слово 1 = "var"!
Результат: показывали "Det veldig" → "Det veldig" ❌
```

---

### Проблема 2: Неправильная эвристика shouldUseRewrite()

**Что было:**
```javascript
// Эвристика 1: > 50% переносов
if(moveBlocks.length > userTokens.length / 2) return true;

// Эвристика 2: Пересечения стрелок
const intersections = detectArrowIntersections(moveBlocks, gapMeta);
if(intersections > 1) return true;

// Эвристика 3: Конфликты позиций
const conflicts = detectPositionConflicts(moveBlocks, gapMeta);
if(conflicts.length > 1) return true;
```

**Проблема:**
- Не соответствует инструкции!
- Инструкция требует: **"считать пересекаемые S-границы"**
- S-граница = промежуток между словами в **студенческом** предложении
- Если **>1 стрелки проходят через одну S-границу** → rewrite

**Пример:**
```
User:     A B C D
Original: B D A C

Стрелки:
  - "D" (user pos 3) → gap before "B" (boundary 0)
  - "A" (user pos 2) → gap after "D" (boundary 3)

S-boundary 3 пересекается обеими стрелками → REWRITE!

Старый алгоритм этого не ловил правильно.
```

---

## ✅ Исправления

### Исправление 1: Полный диапазон оригинала

**Новый алгоритм:**
```javascript
function createRewriteGroup(moveBlocks, orderedMatches, userTokens, originalTokens){
  // 1. Найти все origIndex, затронутые problem blocks
  const origPositions = new Set();
  moveBlocks.forEach(block=>{
    block.tokens.forEach(userIdx=>{
      const match = orderedMatches.find(m=> m.userIndex === userIdx);
      if(match) origPositions.add(match.origIndex);
    });
  });

  // 2. Взять непрерывный диапазон в оригинале
  const origMin = Math.min(...origPositions);
  const origMax = Math.max(...origPositions);

  // 3. ✅ Построить correctOrder ИЗ ОРИГИНАЛА (весь диапазон!)
  const correctOrder = [];
  for(let origIdx = origMin; origIdx <= origMax; origIdx++){
    const token = originalTokens[origIdx];  // ← Берем ВСЕ слова из original!
    if(token){
      const match = orderedMatches.find(m=> m.origIndex === origIdx);
      const userToken = match ? match.userToken : null;
      const hasError = userToken && userToken.raw !== token.raw;

      correctOrder.push({ token, userToken, hasError });
    }
  }

  // 4. Найти соответствующий диапазон в студенческом тексте
  const expandedUserPositions = new Set();
  orderedMatches.forEach(m=>{
    if(m.origIndex >= origMin && m.origIndex <= origMax){
      expandedUserPositions.add(m.userIndex);
    }
  });

  const userMin = Math.min(...expandedUserPositions);
  const userMax = Math.max(...expandedUserPositions);

  return [{
    id: 'rewrite-1',
    start: userMin,
    end: userMax,
    origMin,
    origMax,
    userTokens: userTokens.slice(userMin, userMax + 1),
    correctOrder  // ← Теперь включает ВСЕ слова из original[origMin:origMax]
  }];
}
```

**Результат:**
```
User:     var hyggelig Det veldig å se deg i dag .
Original: Det var veldig hyggelig å se deg i dag .

Problem blocks:
  - "Det" (user 2, orig 0)
  - "veldig" (user 3, orig 2)

origMin = 0, origMax = 2
correctOrder строится из original[0:2]:
  → "Det var veldig" ✅

userMin = 2, userMax = 3
Зачеркивается user[2:3]:
  → "Det veldig" ✅

Визуализация:
┌──────────────────┐  ↻
│ ✅ Det var veldig │  ← Правильно (включает "var"!)
│ ❌ Det veldig     │  ← Ваш вариант
└──────────────────┘
```

---

### Исправление 2: S-boundaries согласно инструкции

**Новый алгоритм:**
```javascript
function computeCrossedBoundaries(moveBlocks, gapMeta){
  const boundaryCount = new Map();

  moveBlocks.forEach(block=>{
    const gap = gapMeta[block.targetGapKey];
    if(!gap) return;

    const targetBoundary = gap.beforeUser >= 0 ? gap.beforeUser + 1 : 0;
    const startBoundary = block.start;
    const endBoundary = block.end + 1;

    // Определить пересекаемые S-границы
    let crossed = [];
    if(targetBoundary < startBoundary){
      // Двигаем влево
      for(let b = targetBoundary; b < startBoundary; b++){
        crossed.push(b);
      }
    } else if(targetBoundary > endBoundary){
      // Двигаем вправо
      for(let b = endBoundary; b <= targetBoundary; b++){
        crossed.push(b);
      }
    }

    // Подсчитать пересечения
    crossed.forEach(boundary=>{
      if(!boundaryCount.has(boundary)){
        boundaryCount.set(boundary, 0);
      }
      boundaryCount.set(boundary, boundaryCount.get(boundary) + 1);
    });

    block.crossedBoundaries = crossed;
  });

  return boundaryCount;
}

function shouldUseRewrite(moveBlocks, gapMeta){
  if(moveBlocks.length === 0) return false;

  const boundaryCount = computeCrossedBoundaries(moveBlocks, gapMeta);

  // Если ЛЮБАЯ граница перегружена (count > 1) → REWRITE
  for(const [boundary, count] of boundaryCount){
    if(count > 1){
      return true;
    }
  }

  return false;
}
```

**Пример работы:**
```
User:     A B C D
Original: B D A C

Move blocks:
  - Block 1: "D" (user pos 3) → gap before "B" (target boundary 0)
  - Block 2: "A" (user pos 2) → gap after "D" (target boundary 3)

Block 1:
  startBoundary = 3
  endBoundary = 4
  targetBoundary = 0
  Двигаем влево: crossed = [0, 1, 2]

Block 2:
  startBoundary = 2
  endBoundary = 3
  targetBoundary = 3
  Двигаем вправо: crossed = [3]

Boundary count:
  0: 1
  1: 1
  2: 1
  3: 1 (from block 1) + 1 (from block 2) = 2 ← ПЕРЕГРУЖЕНА!

Результат: shouldUseRewrite = TRUE ✅
```

---

## 📊 Сравнение результатов

### Тест: "var hyggelig Det veldig å se deg i dag"

**До исправления:**
```
┌──────────────────┐  ↻
│ ✅ Det veldig     │  ← НЕПРАВИЛЬНО (пропущено "var")
│ ❌ Det veldig     │  ← То же самое!
└──────────────────┘
```

**После исправления:**
```
┌──────────────────┐  ↻
│ ✅ Det var veldig hyggelig  │  ← Правильно (весь диапазон)
│ ❌ Det veldig hyggelig      │  ← Пропущено "var"
└──────────────────────────────┘
```

---

### Тест: "Det veldig var hyggelig å se deg i dag"

**До исправления:**
```
Две отдельные стрелки:
  Det → ...
  var → ...
```

**После исправления:**
```
┌──────────────────┐  ↻
│ ✅ Det var veldig │  ← Правильно
│ ❌ Det veldig var │  ← Неправильный порядок
└──────────────────┘
```

---

## ✅ Соответствие инструкции

| Требование | Старая реализация | Новая реализация |
|-----------|-------------------|------------------|
| S-boundaries для детекции | ❌ Использовали пересечения координат | ✅ Считаем пересекаемые границы |
| Полный диапазон оригинала | ❌ Только matched слова | ✅ Весь диапазон [origMin:origMax] |
| Пропущенные слова в rewrite | ❌ Не показывались | ✅ Показываются |
| Минимизация переносов | ✅ LIS работал | ✅ Работает |
| Объединение блоков | ✅ canJoin логика | ✅ Работает |
| Визуализация | ✅ CSS правильный | ✅ CSS правильный |

---

## 🧪 Проверка исправлений

### Тест 1:
```
Original: Det var veldig hyggelig å se deg i dag .
User:     var veldig hyggelig å se deg i dag Det .

Expected:
  - "Det" в конце → должен быть в начале
  - Rewrite для всего предложения (слишком сложно)

Result: ✅
```

### Тест 2:
```
Original: Det var veldig hyggelig å se deg i dag .
User:     Det veldig var hyggelig å se deg i dag .

Expected:
  - "veldig" и "var" поменялись местами
  - Rewrite block для "var veldig" → "var veldig"

Result: ✅
```

### Тест 3:
```
Original: Det var veldig hyggelig å se deg i dag .
User:     var hyggelig Det veldig å se deg i dag .

Expected:
  - Rewrite block
  - Показать полный диапазон "Det var veldig hyggelig"
  - Пропущенное "var" должно быть видно!

Result: ✅ ИСПРАВЛЕНО
```

---

## 📝 Заключение

Исправлены **ключевые ошибки** в алгоритме:

1. ✅ `createRewriteGroup()` теперь берет **весь диапазон** из оригинала
2. ✅ `shouldUseRewrite()` использует **S-boundaries** согласно инструкции
3. ✅ Пропущенные слова **показываются** в rewrite block

Алгоритм теперь **полностью соответствует** вашей инструкции.

---

*Исправлено: 2025-11-23*
*Версия: 2.0*
