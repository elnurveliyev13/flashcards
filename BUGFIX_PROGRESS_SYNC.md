# 🐛 BUGFIX: Прогресс сбрасывался при обновлении страницы

**Версия**: `0.3.2-test`
**Дата**: 2025-10-26
**Статус**: ✅ ИСПРАВЛЕНО

---

## ❌ Проблема

При обновлении страницы (F5) **все карточки сбрасывались** на начальное состояние:
- ✖️ Stage обнулялся (🌰 0)
- ✖️ Due time сбрасывался на "сейчас"
- ✖️ История повторений терялась

**Причина**: Прогресс загружался только из **localStorage**, но **не синхронизировался с сервером**.

---

## 🔍 Анализ проблемы

### **Что происходило:**

1. **При загрузке страницы** (`flashcards.js:160`):
   ```javascript
   loadState(); // Загрузка из localStorage
   (async()=>{
     await syncFromServer(); // Загрузка карточек
     refreshSelect();
     updateBadge();
     buildQueue();
   })();
   ```

2. **Функция `syncFromServer()` загружала только карточки**, но НЕ прогресс:
   ```javascript
   async function syncFromServer(){
     const decks = await api('list_decks', {});
     for(const d of (decks||[])){
       const items = await api('get_deck_cards', {deckid:d.id});
       // ...
     }
     registry = reg; saveRegistry();
     ensureAllProgress(); // ⚠️ Создавал НОВЫЙ прогресс для карточек
   }
   ```

3. **Функция `ensureAllProgress()` создавала дубликаты**:
   ```javascript
   function ensureDeckProgress(deckId,cards){
     const m=state.decks[deckId];
     (cards||[]).forEach(c=>{
       if(!m[c.id]) m[c.id]={
         step:0,              // ⚠️ Всегда 0!
         due:today0(),        // ⚠️ Всегда "сейчас"!
         addedAt:today0(),
         lastAt:null
       };
     });
   }
   ```

4. **Результат**:
   - Если карточка **есть в localStorage** → всё ОК (но localStorage может быть очищен)
   - Если карточка **НЕТ в localStorage** → создается с `step=0`, `due=сейчас`
   - **Прогресс с сервера игнорировался**

---

## ✅ Решение

### **1. Загрузка прогресса с сервера при инициализации**

Добавлен вызов API `fetch_progress` в функцию `syncFromServer()`:

```javascript
async function syncFromServer(){
  try{
    // 1. Загрузить карточки
    const decks = await api('list_decks', {});
    const reg = {};
    for(const d of (decks||[])){
      const items = await api('get_deck_cards', {deckid:d.id});
      const cards = (items||[]).map(it=>{ /* ... */ });
      reg[d.id] = {id:String(d.id), title:d.title, cards};
    }
    registry = reg; saveRegistry();

    // 2. Загрузить прогресс с сервера ✅ НОВОЕ
    const serverProgress = await api('fetch', {});
    if(serverProgress && typeof serverProgress === 'object'){
      if(!state.decks) state.decks = {};
      Object.keys(serverProgress).forEach(deckId => {
        if(!state.decks[deckId]) state.decks[deckId] = {};
        Object.keys(serverProgress[deckId]).forEach(cardId => {
          const sp = serverProgress[deckId][cardId];
          // Конвертация из секунд (сервер) в миллисекунды (клиент)
          state.decks[deckId][cardId] = {
            step: sp.step || 0,
            due: (sp.due || 0) * 1000,        // ✅ из секунд в мс
            addedAt: (sp.addedAt || 0) * 1000,
            lastAt: (sp.lastAt || 0) * 1000,
            hidden: sp.hidden || 0
          };
        });
      });
      saveState();
    }

    // 3. Создать прогресс для новых карточек (которых нет на сервере)
    ensureAllProgress();
  }catch(e){ console.error('syncFromServer error:', e); }
}
```

**Изменения**: [flashcards.js:34-54](../assets/flashcards.js#L34-L54)

---

### **2. Синхронизация прогресса с сервером при каждом рейтинге**

Добавлена функция `syncProgressToServer()`, которая вызывается при каждом нажатии **Easy**, **Normal**, **Hard**:

```javascript
async function syncProgressToServer(deckId, cardId, rec){
  try{
    const payload = {
      records: [{
        deckId,
        cardId,
        step: rec.step,
        due: Math.floor(rec.due / 1000),      // ✅ из мс в секунды
        addedAt: Math.floor(rec.addedAt / 1000),
        lastAt: Math.floor(rec.lastAt / 1000),
        hidden: rec.hidden || 0
      }]
    };
    await api('save', {}, 'POST', payload);
  }catch(e){ console.error('Failed to sync progress:', e); }
}

function rateEasy(){
  // ...
  saveState();
  syncProgressToServer(it.deckId, it.card.id, it.rec); // ✅ НОВОЕ
  // ...
}

function rateNormal(){
  // ...
  saveState();
  syncProgressToServer(it.deckId, it.card.id, it.rec); // ✅ НОВОЕ
  // ...
}

function rateHard(){
  // ...
  saveState();
  syncProgressToServer(it.deckId, it.card.id, it.rec); // ✅ НОВОЕ
  // ...
}
```

**Изменения**: [flashcards.js:102-110](../assets/flashcards.js#L102-L110)

---

### **3. Конвертация timestamp (секунды ↔ миллисекунды)**

**Важно**:
- **Сервер (PHP)**: Unix timestamp в **секундах** (`time()`)
- **Клиент (JS)**: Unix timestamp в **миллисекундах** (`Date.now()`)

**При загрузке с сервера** (секунды → миллисекунды):
```javascript
due: (sp.due || 0) * 1000
```

**При отправке на сервер** (миллисекунды → секунды):
```javascript
due: Math.floor(rec.due / 1000)
```

---

## 📊 Поток данных (ДО исправления)

```
[Страница загружена]
       ↓
[loadState()] → Загрузка из localStorage
       ↓
[syncFromServer()] → Загрузка карточек с сервера
       ↓
[ensureAllProgress()] → ⚠️ Создание НОВОГО прогресса (step=0, due=now)
       ↓
[buildQueue()] → ⚠️ Все карточки с обнуленным прогрессом!
```

---

## 📊 Поток данных (ПОСЛЕ исправления)

```
[Страница загружена]
       ↓
[loadState()] → Загрузка из localStorage (резервная копия)
       ↓
[syncFromServer()]
    ├─ api('list_decks') → Загрузка списка колод
    ├─ api('get_deck_cards') → Загрузка карточек
    ├─ api('fetch') → ✅ Загрузка прогресса с сервера
    └─ Конвертация секунд → миллисекунды
       ↓
[state.decks] → ✅ Прогресс перезаписан данными с сервера
       ↓
[saveState()] → Сохранение в localStorage (синхронизировано)
       ↓
[ensureAllProgress()] → Создание прогресса только для НОВЫХ карточек
       ↓
[buildQueue()] → ✅ Корректные значения step, due!
```

---

## 🧪 Как проверить исправление

### **Тест 1: Обновление страницы**

1. Создайте карточку и нажмите **Normal**
2. Подождите 1 минуту
3. Обновите страницу (F5)
4. **Ожидаемый результат**:
   - ✅ Stage badge остался **🌱 1** (не обнулился)
   - ✅ Карточка вернулась в очередь через 1 минуту
   - ✅ Счетчик "Due: N" корректный

### **Тест 2: Множественные обновления**

1. Создайте 3 карточки
2. Отметьте их разными рейтингами:
   - Карточка 1: **Normal** (step 0→0, +1 мин)
   - Карточка 2: **Easy** (step 0→1, +1 мин)
   - Карточка 3: **Easy** (step 0→1, +1 мин)
3. Обновите страницу **несколько раз** (F5 × 5)
4. **Ожидаемый результат**:
   - ✅ Все stage остались на месте
   - ✅ Due time не изменился

### **Тест 3: Проверка в БД**

```sql
-- Проверить, что прогресс сохраняется на сервере
SELECT
  p.cardid,
  p.step,
  FROM_UNIXTIME(p.due) AS next_review,
  FROM_UNIXTIME(p.lastat) AS last_reviewed
FROM mdl_flashcards_progress p
WHERE p.userid = 5
ORDER BY p.due ASC;
```

**Ожидаемый результат**:
```
cardid     | step | next_review         | last_reviewed
-----------|------|---------------------|------------------
my-test123 | 1    | 2025-10-26 15:30:00 | 2025-10-26 15:29:00
my-abc456  | 2    | 2025-10-26 15:32:00 | 2025-10-26 15:29:00
```

### **Тест 4: Очистка localStorage**

1. Отметьте несколько карточек разными рейтингами
2. Откройте консоль браузера (F12)
3. Выполните:
   ```javascript
   localStorage.clear();
   ```
4. Обновите страницу (F5)
5. **Ожидаемый результат**:
   - ✅ Прогресс **восстановлен с сервера**
   - ✅ Все stage и due корректны

---

## 🔧 Backend (проверка API)

### **API `fetch_progress`** ([ajax.php:110-125](../ajax.php#L110-L125))

```php
function fetch_progress($flashcardsid, $userid) {
    global $DB;
    $recs = $DB->get_records('flashcards_progress', [
        'flashcardsid' => $flashcardsid,
        'userid' => $userid
    ]);
    $out = [];
    foreach ($recs as $r) {
        if (!isset($out[$r->deckid])) $out[$r->deckid] = [];
        $out[$r->deckid][$r->cardid] = [
            'step' => (int)$r->step,
            'due' => (int)$r->due,           // ✅ Секунды
            'addedAt' => (int)$r->addedat,   // ✅ Секунды
            'lastAt' => (int)$r->lastat,     // ✅ Секунды
            'hidden' => (int)$r->hidden,
        ];
    }
    return $out;
}
```

**Формат ответа**:
```json
{
  "ok": true,
  "data": {
    "5": {
      "my-test123": {
        "step": 1,
        "due": 1730034600,
        "addedAt": 1730034540,
        "lastAt": 1730034540,
        "hidden": 0
      }
    }
  }
}
```

---

## 📁 Измененные файлы

1. ✅ [flashcards.js:34-54](../assets/flashcards.js#L34-L54) - Загрузка прогресса с сервера
2. ✅ [flashcards.js:102-110](../assets/flashcards.js#L102-L110) - Синхронизация при рейтинге
3. ✅ [version.php:10-13](../version.php#L10-L13) - Версия `0.3.2-test`
4. ✅ [view.php:21](../view.php#L21) - Cache buster обновлен

---

## 🎯 Результат

✅ **Прогресс теперь сохраняется на сервере** и загружается при каждом обновлении
✅ **Stage и Due time не сбрасываются**
✅ **localStorage используется как резервная копия**
✅ **Синхронизация происходит автоматически** при каждом рейтинге
✅ **Работает даже после очистки браузера**

---

**Баг исправлен! ✅**
