# 🧪 TEST MODE: Минутные интервалы для тестирования SRS

**Версия плагина**: `0.3.1-test`
**Дата**: 2025-10-26

---

## ⚠️ ВАЖНО

Эта версия плагина **временная** и предназначена **только для тестирования**!

В этой версии интервалы повторения работают в **минутах** вместо **дней**:

| Уровень | Обычно (дни) | TEST MODE (минуты) |
|---------|--------------|-------------------|
| 0 → 1   | +1 день      | +1 минута        |
| 1 → 2   | +3 дня       | +3 минуты        |
| 2 → 3   | +7 дней      | +7 минут         |
| 3 → 4   | +15 дней     | +15 минут        |
| 4 → 5   | +31 день     | +31 минута       |
| 5 → 6   | +63 дня      | +63 минуты       |
| 6 → 7   | +127 дней    | +127 минут       |
| 7 → 8   | +255 дней    | +255 минут       |

---

## 📝 Что изменилось

### **Frontend** ([flashcards.js](./assets/flashcards.js))

```javascript
// БЫЛО:
function today0(){
  const d=new Date();
  d.setHours(0,0,0,0);
  return +d;
}
function addDays(t,days){
  const d=new Date(t);
  d.setDate(d.getDate()+days);
  return +d;
}

// СТАЛО (TEST MODE):
function today0(){
  return Date.now(); // Текущая миллисекунда
}
function addDays(t,days){
  return t + (days * 60 * 1000); // "дни" = минуты
}
```

### **Backend** ([ajax.php](./ajax.php))

```php
// БЫЛО:
function today0() {
  $d = usergetdate(time());
  return make_timestamp($d['year'], $d['mon'], $d['mday'], 0, 0, 0);
}
function srs_due_ts($added, $step) {
  // ...
  return $added + ($iv[$idx] * DAYSECS); // 86400 секунд
}

// СТАЛО (TEST MODE):
function today0() {
  return time(); // Текущая секунда
}
function srs_due_ts($added, $step) {
  // ...
  return $added + ($iv[$idx] * 60); // 60 секунд
}
```

---

## 🧪 Сценарий тестирования

### **Подготовка**

1. Обновите плагин в Moodle:
   ```
   Site administration → Notifications → Upgrade database now
   ```

2. Очистите кеш:
   ```
   Site administration → Development → Purge all caches
   ```
   ИЛИ нажмите `Ctrl+F5` в браузере

3. Откройте активность Flashcards в курсе

---

### **Тест 1: Новая карточка (Normal)**

1. **Создайте карточку**:
   - Front text: `å lære`
   - Translation: `to learn`
   - Нажмите "Add to my cards"

2. **Проверьте**:
   - ✅ Карточка появилась **сразу** в очереди (due = 0)
   - ✅ Stage badge: 🌰 **0**

3. **Нажмите "Normal"**:
   - Карточка исчезла из очереди
   - Подождите **1 минуту**
   - Обновите страницу (F5)

4. **Результат**:
   - ✅ Карточка вернулась в очередь через 1 минуту
   - ✅ Stage badge: 🌱 **1**

---

### **Тест 2: Easy (быстрый прогресс)**

1. **Нажмите "Easy"** на той же карточке:
   - Карточка исчезла
   - Stage должен стать **2**
   - Следующий повтор через **3 минуты**

2. **Подождите 3 минуты**, обновите страницу:
   - ✅ Карточка вернулась
   - ✅ Stage badge: 🌿 **3** (если снова Easy)

3. **Продолжайте нажимать "Easy"**:
   - Stage 3 → +7 минут → Stage 🌿
   - Stage 4 → +15 минут → Stage ☘️
   - Stage 5 → +31 минуту → Stage 🍀
   - И так далее

---

### **Тест 3: Hard (повтор сегодня)**

1. **Создайте еще одну карточку**:
   - Front: `å glemme`
   - Translation: `to forget`

2. **Нажмите "Hard"**:
   - ✅ Карточка переместилась **в конец очереди**
   - ✅ Stage остался **0**
   - ✅ due = текущее время (доступна сразу)

3. **Повторите "Hard" несколько раз**:
   - Карточка продолжает перемещаться в конец очереди
   - Stage не растет

---

### **Тест 4: Список карточек (Due time)**

1. Откройте **"Cards list"** (кнопка вверху)

2. **Проверьте колонку "Next due"**:
   - Время указано в **формате локального времени**
   - Карточки отсортированы по due (ближайшие сверху)

3. **Создайте 3 карточки** и нажмите разные кнопки:
   - Карточка 1: **Normal** → due через 1 минуту
   - Карточка 2: **Easy** → due через 3 минуты
   - Карточка 3: **Hard** → due = сейчас

4. **В списке карточек**:
   - ✅ Порядок: Hard (сейчас) → Normal (+1 мин) → Easy (+3 мин)

---

### **Тест 5: Множественные карточки**

1. **Создайте 5 карточек** с разными словами

2. **Активируйте колоду** "Mine / Min - U{userid}"

3. **Отметьте все 5 карточек**:
   - 3 карточки → **Normal**
   - 2 карточки → **Easy**

4. **Подождите 1 минуту**, обновите страницу:
   - ✅ Появились 3 карточки (Normal)
   - ✅ Badge: "Due: 3"

5. **Подождите еще 2 минуты** (итого 3 минуты):
   - ✅ Появились оставшиеся 2 карточки (Easy)
   - ✅ Badge: "Due: 5"

---

### **Тест 6: Прогресс до Stage 8**

Для проверки всех уровней нажимайте **Easy** и ждите:

| После нажатия | Stage | Ждать     | Эмодзи |
|---------------|-------|-----------|--------|
| Easy #1       | 1     | 1 минута  | 🌱     |
| Easy #2       | 2     | 3 минуты  | 🌿     |
| Easy #3       | 3     | 7 минут   | ☘️     |
| Easy #4       | 4     | 15 минут  | 🍀     |
| Easy #5       | 5     | 31 минуту | 🌷     |
| Easy #6       | 6     | 63 минуты | 🌼     |
| Easy #7       | 7     | 127 минут | 🌳     |
| Easy #8       | 8     | 255 минут | 🌴     |

**Совет**: Для тестирования Stage 6-8 можно вручную изменить `due` в базе данных:

```sql
UPDATE mdl_flashcards_progress
SET due = UNIX_TIMESTAMP() - 60
WHERE userid = 5 AND cardid = 'my-test123';
```

---

## 🔍 Проверка в базе данных

### **1. Проверить due timestamp**

```sql
SELECT
  p.cardid,
  p.step,
  p.due,
  FROM_UNIXTIME(p.due) AS next_review,
  TIMESTAMPDIFF(MINUTE, NOW(), FROM_UNIXTIME(p.due)) AS minutes_until_due
FROM mdl_flashcards_progress p
WHERE p.userid = 5
ORDER BY p.due ASC;
```

**Ожидаемый результат** (Normal на уровне 0):
```
cardid       | step | due        | next_review         | minutes_until_due
-------------|------|------------|---------------------|------------------
my-abc123    | 0    | 1730000460 | 2025-10-26 14:21:00 | 1
```

### **2. Проверить прогресс после Easy**

```sql
SELECT
  p.cardid,
  p.step,
  FROM_UNIXTIME(p.lastat) AS last_review,
  FROM_UNIXTIME(p.due) AS next_review,
  TIMESTAMPDIFF(MINUTE, FROM_UNIXTIME(p.lastat), FROM_UNIXTIME(p.due)) AS interval_minutes
FROM mdl_flashcards_progress p
WHERE p.userid = 5 AND p.cardid = 'my-abc123';
```

**Ожидаемый результат** (после Easy на уровне 1→2):
```
step | last_review         | next_review         | interval_minutes
-----|---------------------|---------------------|------------------
2    | 2025-10-26 14:20:00 | 2025-10-26 14:23:00 | 3
```

---

## ⚙️ Как вернуть обычные интервалы (дни)

### **1. Откатите изменения в `flashcards.js`:**

```javascript
// Вернуть на:
function today0(){
  const d=new Date();
  d.setHours(0,0,0,0);
  return +d;
}
function addDays(t,days){
  const d=new Date(t);
  d.setDate(d.getDate()+days);
  return +d;
}
```

### **2. Откатите изменения в `ajax.php`:**

```php
// Вернуть на:
function today0() {
  $d = usergetdate(time());
  return make_timestamp($d['year'], $d['mon'], $d['mday'], 0, 0, 0);
}
function srs_due_ts($added, $step) {
  $added = (int)$added; if ($added <= 0) { $added = today0(); }
  $step = (int)$step; if ($step <= 0) { return $added + DAYSECS; }
  $iv = srs_intervals();
  $idx = min(max($step, 1), count($iv)) - 1;
  return $added + ($iv[$idx] * DAYSECS);
}
```

### **3. Обновите версию:**

```php
// version.php
$plugin->version   = 2025102604; // Увеличьте версию
$plugin->release   = '0.4.0'; // Уберите "-test"

// view.php
$ver = 2025102604;
```

### **4. Очистите кеш и обновите БД**

---

## 📊 Ожидаемые результаты

✅ **Новая карточка** появляется сразу (due=0)
✅ **Normal**: следующий повтор через 1 минуту
✅ **Easy**: интервал увеличивается (1→3→7→15...)
✅ **Hard**: карточка повторяется сразу (в конец очереди)
✅ **Badge "Due"** обновляется при обновлении страницы
✅ **Stage badge** растет при успешных повторениях
✅ **Cards list** показывает корректное время "Next due"

---

## 🐛 Что проверить

1. ✅ Карточка появляется в очереди **ровно через N минут** (не раньше, не позже)
2. ✅ Stage badge меняется корректно (🌰→🌱→🌿→☘️→🍀→🌷→🌼→🌳→🌴)
3. ✅ Кнопка "Hard" не увеличивает step
4. ✅ Кнопка "Easy" пропускает уровни
5. ✅ Список карточек сортируется по due (ближайшие сверху)
6. ✅ После перезагрузки страницы прогресс сохраняется

---

**Удачи в тестировании! 🚀**
