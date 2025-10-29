# v0.5.14 Summary - Grace Period & Cache Fixes

**Date**: 2025-10-29
**Version**: 2025102917

---

## Проблемы, которые были исправлены

### 1. ✅ localStorage кэш не очищался при обновлениях
**Симптомы**:
- Старые карточки "призраки" после удаления
- Рассинхронизация кэш ↔ БД
- Устаревшие данные

**Решение**: Автоматическая очистка при изменении версии плагина

**Файл**: `assets/flashcards.js:675-700`

---

### 2. ✅ Пользователи с истёкшей подпиской имели доступ
**Симптомы**:
- `ue.timeend` прошёл, но доступ остался
- Не переходили в grace period
- `ue.status=0` но подписка неактивна

**Решение**: Добавлена проверка `e.status=0` + строгая проверка `timeend`

**Файл**: `classes/access_manager.php:182`

---

### 3. ✅ Карточки сохранялись в localStorage в grace period
**Симптомы**:
- Сервер блокирует `upsert_card` (403)
- Но карточка всё равно в localStorage
- Накопление несинхронизированных карточек

**Решение**: Блокировка сохранения в localStorage при отказе сервера

**Файл**: `assets/flashcards.js:461-486`

---

## Изменения в коде

### JavaScript (`assets/flashcards.js`)

#### Авточистка кэша (строки 675-700)
```javascript
const CACHE_VERSION = "2025102917"; // Совпадает с version.php
const currentCacheVersion = localStorage.getItem("flashcards-cache-version");
if (currentCacheVersion !== CACHE_VERSION) {
  console.log(`Cache version mismatch: ${currentCacheVersion} → ${CACHE_VERSION}`);

  // Очистить localStorage
  for (let i = 0; i < localStorage.length; i++) {
    const key = localStorage.key(i);
    if (key && (key.startsWith('srs-v6:') || key === 'srs-profile')) {
      localStorage.removeItem(key);
    }
  }

  // Очистить IndexedDB
  indexedDB.deleteDatabase("srs-media");

  // Установить новую версию
  localStorage.setItem("flashcards-cache-version", CACHE_VERSION);
}
```

**Эффект**: При первом запуске после обновления весь кэш очищается.

---

#### Блокировка localStorage в grace period (строки 461-486)
```javascript
try {
  const result = await api('upsert_card', ...);
  if (result && result.ok) {
    // Успешно сохранено
    serverDeckId = result.deckId;
  } else if (result && !result.ok) {
    // Сервер отклонил (grace period, no access)
    $("#status").textContent = "Access denied. Cannot create cards during grace period.";
    return; // ← STOP - НЕ сохранять в localStorage
  }
} catch(e) {
  // Проверка на access error
  if (e.message && (e.message.includes('access') || e.message.includes('grace'))) {
    $("#status").textContent = "Access denied.";
    return; // ← STOP
  }
  // Только сетевые ошибки продолжают локальное сохранение
}
```

**Было**:
```javascript
catch(e) {
  /* continue with local save */  // ← Всегда сохраняло
}
```

**Стало**: Проверяет тип ошибки и блокирует сохранение при отказе доступа.

---

### PHP (`classes/access_manager.php`)

#### Строгая проверка подписки (строка 182)
```php
// БЫЛО:
WHERE ue.userid = :userid
  AND ue.status = 0  // Только статус пользователя
  AND m.name = 'flashcards'

// СТАЛО:
WHERE ue.userid = :userid
  AND ue.status = 0       // Статус пользователя
  AND e.status = 0        // ← NEW: Статус метода регистрации
  AND m.name = 'flashcards'
  AND (ue.timestart = 0 OR ue.timestart <= :now)
  AND (ue.timeend = 0 OR ue.timeend > :now)  // Строго проверяется
```

**Эффект**:
- Если `ue.timeend` истёк → `has_active_enrolment()` возвращает `false`
- Пользователь переходит в grace period (30 дней)
- После grace → полная блокировка

---

## Поведение до и после

| Ситуация | v0.5.13 (До) | v0.5.14 (После) |
|----------|--------------|-----------------|
| **Обновление плагина** | Кэш сохраняется, старые карточки видны | Кэш автоматически очищается ✅ |
| **Подписка истекла (`timeend < now`)** | Доступ остаётся | Переход в grace period ✅ |
| **Grace period: создание карточки** | Сервер блокирует, localStorage сохраняет ❌ | И сервер, и localStorage блокируют ✅ |
| **Grace period: просмотр карточек** | Работает ✅ | Работает ✅ |
| **Expired: любое действие** | Блокировка ✅ | Блокировка ✅ |

---

## Инструкция по развёртыванию

### Быстрая установка (3 минуты)

```bash
# 1. Скопировать файлы
rsync -av /path/to/dev/flashcards/ /path/to/moodle/mod/flashcards/

# 2. Очистить кэши
php admin/cli/purge_caches.php

# 3. Обновить версию
php admin/cli/upgrade.php --non-interactive
```

**Изменений БД нет** - только обновление версии.

---

### Проверка после установки

#### Тест 1: Кэш очищен?
Откройте flashcards → F12 Console → должно быть:
```
[Flashcards] Cache version mismatch: ... → 2025102917. Clearing cache...
[Flashcards] Cache cleared successfully
```

#### Тест 2: Grace period работает?
```sql
-- Установить истёкшую подписку
UPDATE mdl_user_enrolments
SET timeend = UNIX_TIMESTAMP(DATE_SUB(NOW(), INTERVAL 1 DAY))
WHERE userid = 500;

-- Запустить проверку
php admin/cli/scheduled_task.php --execute='\mod_flashcards\task\check_user_access'

-- Проверить статус
SELECT status FROM mdl_flashcards_user_access WHERE userid = 500;
-- Должно быть: 'grace'
```

#### Тест 3: Создание карточки заблокировано в grace period?
Войдите как пользователь в grace period → попытайтесь создать карточку → должно показать:
```
Access denied. Cannot create cards during grace period.
```

И карточка НЕ должна появиться в localStorage.

---

## Откат

Если что-то пошло не так:

```bash
# Восстановить файлы v0.5.13
cp -r /backup/flashcards_v0.5.13 /path/to/moodle/mod/flashcards

# Очистить кэши
php admin/cli/purge_caches.php

# Попросить пользователей очистить localStorage вручную
# (в консоли браузера):
localStorage.clear();
```

---

## Breaking Changes

### ⚠️ Пользователи с истёкшей подпиской потеряют доступ

**До**: Могли работать с карточками даже после истечения `timeend`

**После**: Автоматический переход в grace period → через 30 дней полная блокировка

**Действия**:
1. Уведомить пользователей о новых правилах
2. Рассмотреть увеличение grace period с 30 до 60 дней:
   ```php
   // access_manager.php:25
   const GRACE_PERIOD_DAYS = 60; // Было: 30
   ```

---

### ⚠️ localStorage очищается при первом запуске

**До**: Кэш сохранялся между обновлениями

**После**: Полная очистка при изменении версии

**Влияние**:
- Первая загрузка медленнее (запрос к серверу)
- Офлайн-карточки (не синхронизированные) теряются
- Профили сбрасываются

**Решение**: Пользователи должны работать онлайн и синхронизировать карточки.

---

## Технические детали

### CACHE_VERSION синхронизирован с version.php

**Важно**: При каждом обновлении `version.php` нужно обновлять и `CACHE_VERSION` в JavaScript:

```php
// version.php
$plugin->version = 2025102917;
```

```javascript
// flashcards.js
const CACHE_VERSION = "2025102917"; // ← Должно совпадать!
```

**Автоматизация**: Рассмотрите использование build-скрипта для синхронизации.

---

### Grace Period States

```
STATUS_ACTIVE (active)
  ↓ (enrollment expires: timeend < now OR e.status != 0)
STATUS_GRACE (grace)
  - can_review = true
  - can_create = false
  - grace_period_days = 30
  ↓ (30 days pass)
STATUS_EXPIRED (expired)
  - can_review = false
  - can_create = false
```

---

## Итоги

### Исправлено 3 критических бага:
1. ✅ Авточистка кэша предотвращает рассинхронизацию
2. ✅ Строгая проверка подписки закрывает лазейку доступа
3. ✅ Блокировка localStorage предотвращает обход grace period

### Файлы изменены:
- `assets/flashcards.js` (2 изменения)
- `classes/access_manager.php` (1 изменение)
- `version.php` (версия + описание)

### Изменений БД: **НЕТ**

### Время развёртывания: **~5 минут**

---

**Статус**: ✅ Готово к развёртыванию
