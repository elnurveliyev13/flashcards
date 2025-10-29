# Исправление осиротевших записей прогресса

**Версия**: 0.5.13-progress-cascade-delete
**Дата**: 2025-10-29
**Проблема**: При удалении карточек прогресс оставался в БД (orphaned records)

---

## Что было исправлено

### До исправления (v0.5.12 и ранее):
```php
// Удалялся только прогресс ТЕКУЩЕГО пользователя
$DB->delete_records('flashcards_progress', [
    'deckid' => $deckid,
    'cardid' => $cardid,
    'userid' => $userid  // ← Только один пользователь
]);
```

**Проблема**: Если другие пользователи тоже работали с этой карточкой, их прогресс оставался без карточки (orphaned).

### После исправления (v0.5.13):
```php
// Удаляется прогресс ВСЕХ пользователей
$DB->delete_records('flashcards_progress', [
    'deckid' => $deckid,
    'cardid' => $cardid
    // Убрали userid - удаляется для всех
]);
```

**Результат**: Когда карточка удаляется, весь связанный прогресс тоже удаляется.

---

## Инструкция по развёртыванию

### Шаг 1: Проверить текущее состояние

Запустите в phpMyAdmin или psql:

```sql
-- Проверка: сколько осиротевших записей сейчас
SELECT COUNT(*) AS orphaned_count
FROM mdl_flashcards_progress p
LEFT JOIN mdl_flashcards_cards c
  ON c.deckid = p.deckid AND c.cardid = p.cardid
WHERE c.id IS NULL;
```

**Если результат > 0** → есть осиротевшие записи, переходите к Шагу 2.

**Если результат = 0** → всё чисто, просто обновите код (Шаг 3).

---

### Шаг 2: Очистить существующие осиротевшие записи

**Вариант А: Автоматический (рекомендуется)**

```bash
# Запустить готовый SQL скрипт
mysql -u moodle_user -p moodle_db < cleanup_orphaned_progress.sql

# Для PostgreSQL:
psql -U moodle_user -d moodle_db -f cleanup_orphaned_progress.sql
```

**Вариант Б: Вручную в phpMyAdmin**

1. Откройте файл `cleanup_orphaned_progress.sql`
2. Скопируйте запросы по очереди:
   - Сначала `SELECT COUNT(*)` - посмотреть сколько
   - Потом `DELETE p FROM ...` - удалить
   - В конце `SELECT COUNT(*)` снова - проверить (должно быть 0)

**Вариант В: Через Moodle CLI** (если умеете писать PHP скрипты)

Создайте `admin/cli/cleanup_flashcards_progress.php`:
```php
<?php
define('CLI_SCRIPT', true);
require(__DIR__.'/../../config.php');
require_once($CFG->libdir.'/clilib.php');

$sql = "DELETE p
        FROM {flashcards_progress} p
        LEFT JOIN {flashcards_cards} c
          ON c.deckid = p.deckid AND c.cardid = p.cardid
        WHERE c.id IS NULL";

$count = $DB->execute($sql);
cli_writeln("Deleted {$count} orphaned progress records");
```

Запустить:
```bash
php admin/cli/cleanup_flashcards_progress.php
```

---

### Шаг 3: Обновить код плагина

```bash
# 1. Скопировать обновлённые файлы
# (ajax.php и version.php уже исправлены)

# 2. Очистить кэши Moodle
php admin/cli/purge_caches.php

# 3. Обновить версию плагина
php admin/cli/upgrade.php --non-interactive

# 4. Проверить версию
# Site administration > Plugins > Activity modules > Flashcards
# Должно показать: 2025102916 (v0.5.13)
```

---

### Шаг 4: Проверка после установки

#### Тест 1: Удаление приватной карточки

1. Создать приватную карточку (card_test_001)
2. Добавить прогресс (пометить как Easy/Normal)
3. Удалить карточку
4. Проверить в БД:
   ```sql
   SELECT * FROM mdl_flashcards_progress
   WHERE cardid = 'card_test_001';
   -- Должно вернуть 0 строк
   ```

✅ **Pass**: Прогресс удалён вместе с карточкой

---

#### Тест 2: "Удаление" общей карточки

1. Открыть общую карточку (shared card)
2. Нажать "Delete"
3. Проверить в БД:
   ```sql
   SELECT * FROM mdl_flashcards_progress
   WHERE cardid = 'shared_card_123' AND userid = YOUR_USER_ID;
   -- Должно показать hidden=1
   ```

✅ **Pass**: Карточка не удалена, только помечена `hidden=1` для вас

---

#### Тест 3: Нет новых осиротевших записей

1. Удалить 10 разных карточек
2. Проверить:
   ```sql
   SELECT COUNT(*) AS orphaned_count
   FROM mdl_flashcards_progress p
   LEFT JOIN mdl_flashcards_cards c
     ON c.deckid = p.deckid AND c.cardid = p.cardid
   WHERE c.id IS NULL;
   ```

✅ **Pass**: orphaned_count = 0 (не увеличилось)

---

## Важные замечания

### ⚠️ Удаление Activity НЕ удаляет прогресс

**Это правильно!** Потому что:
- Карточки **глобальные** (не привязаны к activity)
- Activity только контролирует **доступ** (кто может использовать flashcards)
- Если удалить activity → карточки остаются → прогресс тоже остаётся

**Пример**:
```
У вас есть Activity A и Activity B в разных курсах
Обе используют одни и те же карточки
Удаление Activity A НЕ должно удалять карточки/прогресс (они нужны для Activity B)
```

---

### ✅ Удаление карточки удаляет ВСЕ прогресс

**Теперь правильно!** Потому что:
- Карточки удалена → прогресс больше не имеет смысла
- Все пользователи теряют прогресс для этой карточки
- Это касается только приватных карточек (`scope='private'`)

**Общие карточки** (`scope='shared'`) всё ещё используют **soft delete**:
- Карточка остаётся в БД
- Прогресс помечается `hidden=1` только для пользователя, который нажал "Delete"
- Для других пользователей карточка и прогресс остаются видимыми

---

## Откат (если что-то пошло не так)

### Вернуть старое поведение (v0.5.12):

```php
// В ajax.php строка 584 вернуть:
$DB->delete_records('flashcards_progress', [
    'deckid' => $deckid,
    'cardid' => $cardid,
    'userid' => $userid  // ← Вернуть userid
]);
```

### Восстановить случайно удалённые записи:

**Нельзя**, если у вас нет бэкапа БД. Поэтому **ОБЯЗАТЕЛЬНО** сделайте бэкап перед Шагом 2:

```bash
# MySQL
mysqldump -u moodle_user -p moodle_db mdl_flashcards_progress > backup_progress.sql

# PostgreSQL
pg_dump -U moodle_user -d moodle_db -t mdl_flashcards_progress > backup_progress.sql
```

---

## Итоги

### Было:
- ❌ Прогресс удалялся только для текущего пользователя
- ❌ Осиротевшие записи накапливались в БД
- ❌ Требовалась ручная очистка периодически

### Стало:
- ✅ Прогресс удаляется для ВСЕХ пользователей при удалении карточки
- ✅ Осиротевшие записи больше не создаются
- ✅ Одноразовая очистка существующих orphaned records

### Поведение:
| Действие | Карточка | Прогресс |
|----------|----------|----------|
| **Удалить свою приватную карточку** | DELETE | DELETE (все пользователи) |
| **"Удалить" общую карточку** | Остаётся | hidden=1 (только для вас) |
| **Удалить Activity** | Остаётся | Остаётся (правильно!) |

---

**Статус**: ✅ Готово к развёртыванию
**Файлы изменены**:
- `ajax.php` (строка 584)
- `version.php` (2025102916)
- `cleanup_orphaned_progress.sql` (новый)
- `FIX_ORPHANED_PROGRESS.md` (этот файл)
