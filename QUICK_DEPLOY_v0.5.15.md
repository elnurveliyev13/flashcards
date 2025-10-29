# Quick Deploy: v0.5.15 - Grace Period UI Block

**Version**: 0.5.15-grace-ui-block
**Date**: 2025-10-29
**Время установки**: 3 минуты

---

## Что исправлено

### Проблема
Во время grace period:
- ❌ Кнопка "Add as new" была видна
- ❌ Пользователь мог нажать кнопку
- ❌ Сервер блокировал сохранение (выдавал ошибку)
- ❌ Но карточка всё равно сохранялась в БД (баг!)
- ❌ Не было пояснения о том, что можно/нельзя делать

### Решение
✅ Форма создания карточки полностью скрыта в grace period
✅ Уведомление о grace period показывает права:
- "✓ You CAN review existing cards"
- "✗ You CANNOT create new cards"

✅ Информация о доступе передаётся в JavaScript
✅ Кнопка "Add" физически недоступна

---

## Файлы изменены

| Файл | Изменение |
|------|-----------|
| `my/index.php` | Передача `$access` в JavaScript + улучшенное уведомление |
| `lang/en/flashcards.php` | 3 новых строки для пояснений прав |
| `templates/app.mustache` | Добавлен `id="cardCreationForm"` |
| `assets/flashcards.js` | Скрытие формы если `can_create=false` |
| `version.php` | Версия 2025102918 (v0.5.15) |

---

## Установка

```bash
# 1. Очистить кэши
php admin/cli/purge_caches.php

# 2. Обновить версию
php admin/cli/upgrade.php --non-interactive

# Готово!
```

---

## Проверка

### Тест 1: Форма скрыта в grace period

1. Установить пользователя в grace period:
   ```sql
   UPDATE mdl_flashcards_user_access
   SET status = 'grace', grace_period_start = UNIX_TIMESTAMP()
   WHERE userid = 500;
   ```

2. Войти как этот пользователь
3. Открыть `/mod/flashcards/my/index.php`

**Ожидается**:
- ⚠️ Жёлтое уведомление: "You can review your cards for 30 more days..."
- ✅ Список прав:
  - "✓ You CAN review existing cards"
  - "✗ You CANNOT create new cards"
- ❌ Форма создания карточки НЕ ВИДНА (скрыта)
- ✅ Можно просматривать существующие карточки

---

### Тест 2: Console логи

Открыть Console (F12):

```
[Flashcards] Cache version mismatch: 2025102917 → 2025102918. Clearing cache...
[Flashcards] Cache cleared successfully
[Flashcards] Access info: {status: "grace", can_create: false, can_review: true, ...}
[Flashcards] Card creation form hidden (can_create=false)
```

---

### Тест 3: Форма видна при активном доступе

1. Установить пользователя в active:
   ```sql
   UPDATE mdl_flashcards_user_access
   SET status = 'active', grace_period_start = NULL
   WHERE userid = 500;
   ```

2. Перезагрузить страницу

**Ожидается**:
- ✅ Зелёное уведомление ИЛИ приветствие
- ✅ Форма создания карточки ВИДНА
- ✅ Можно создавать карточки

---

## Что НЕ исправлено

### Карточка сохраняется в БД несмотря на блокировку

**Статус**: Требует дополнительного исследования

**Возможные причины**:
1. Moodle перехватывает `moodle_exception` в AJAX_SCRIPT и не останавливает выполнение
2. Frontend делает повторный запрос после ошибки
3. Запрос идёт в обход проверки доступа

**Временное решение**: Форма скрыта, пользователь не может нажать кнопку

**TODO**: Проверить логи сервера и Network tab в браузере

---

## Откат

```bash
# Восстановить v0.5.14
cp -r /backup/flashcards_v0.5.14 /path/to/moodle/mod/flashcards
php admin/cli/purge_caches.php
```

---

## Итог

✅ **UI проблема решена**: Форма создания карточки скрыта в grace period
✅ **UX улучшен**: Понятно что можно/нельзя делать
⚠️ **Backend проблема остаётся**: Требует дополнительной проверки

**Приоритет**: СРЕДНИЙ (пользователь не может создать карточку через UI, но backend может потребовать доработки)
