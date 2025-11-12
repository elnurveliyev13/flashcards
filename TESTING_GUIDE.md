# Тестирование системы языка интерфейса

## Быстрый тест

### 1. Проверка селектора языка
1. Откройте приложение карточек в Moodle
2. Посмотрите в правый верхний угол header
3. Убедитесь, что селектор языка показывает 8 языков полными названиями:
   - English
   - Українська
   - Русский
   - Français
   - Español
   - Polski
   - Italiano
   - Deutsch

### 2. Проверка переключения языка
1. Выберите любой язык из выпадающего списка (например, Українська)
2. Проверьте, что изменились надписи:
   - Заголовок приложения (может остаться "MyMemory")
   - Вкладки внизу: "Quick Input" → "Швидкий ввід", "Study" → "Навчання", "Dashboard" → "Панель"
3. Перезагрузите страницу (F5)
4. Убедитесь, что выбранный язык сохранился

### 3. Проверка приоритета языка
1. В Moodle выберите английский язык (через настройки профиля)
2. В приложении выберите украинский язык в header
3. Перезагрузите страницу
4. **Ожидается**: Интерфейс остался на украинском (не переключился на английский из Moodle)

### 4. Проверка независимости языков
1. Кликните на "??" рядом с полем "Translation (Українська)"
2. Выберите другой язык перевода (например, Polish)
3. Проверьте, что:
   - Язык перевода изменился на польский
   - Язык интерфейса остался украинским (не изменился)

## Полное тестирование

### Test Case 1: Первый запуск
**Шаги:**
1. Очистите localStorage: `localStorage.clear()`
2. Перезагрузите страницу
3. Проверьте, какой язык определился автоматически

**Ожидается:**
- Язык браузера или Moodle установлен по умолчанию

---

### Test Case 2: Выбор языка интерфейса
**Шаги:**
1. Выберите Russian из селектора в header
2. Проверьте изменение надписей вкладок

**Ожидается:**
- Quick Input → Быстрый ввод
- Study → Обучение
- Dashboard → Панель
- В localStorage появился ключ `flashcards_interface_lang` = "ru"

---

### Test Case 3: Сохранение после перезагрузки
**Шаги:**
1. Выберите Français
2. Нажмите F5 (перезагрузить)
3. Проверьте селектор и вкладки

**Ожидается:**
- Селектор показывает "Français"
- Вкладки на французском
- localStorage.flashcards_interface_lang = "fr"

---

### Test Case 4: Приоритет над Moodle
**Шаги:**
1. Выберите Español в приложении
2. Поменяйте язык Moodle на Norwegian
3. Перезагрузите страницу приложения

**Ожидается:**
- Интерфейс остался на испанском
- Moodle язык не перезаписал выбор пользователя

---

### Test Case 5: Независимость языков
**Шаги:**
1. Установите интерфейс на Polish (через header dropdown)
2. Установите язык перевода на German (через "??" → выбрать 5)
3. Создайте новую карточку с норвежским словом

**Ожидается:**
- Интерфейс на польском
- Поле перевода показывает "Translation (German)"
- Перевод создается на немецком
- Два разных ключа в localStorage:
  - `flashcards_interface_lang` = "pl"
  - `flashcards_translation_lang` = "de"

---

### Test Case 6: Все 8 языков работают
**Шаги:**
1. Последовательно переключайтесь между всеми 8 языками
2. Для каждого проверьте вкладки

**Ожидается для каждого:**

| Язык | Quick Input | Study | Dashboard |
|------|-------------|-------|-----------|
| English | Quick Input | Study | Dashboard |
| Українська | Швидкий ввід | Навчання | Панель |
| Русский | Быстрый ввод | Обучение | Панель |
| Français | Saisie rapide | Étudier | Tableau de bord |
| Español | Entrada rápida | Estudiar | Panel |
| Polski | Szybkie wprowadzanie | Nauka | Panel |
| Italiano | Inserimento rapido | Studiare | Pannello |
| Deutsch | Schnelleingabe | Studieren | Dashboard |

---

## Проверка в консоли браузера

### Проверить сохраненные настройки:
```javascript
console.log('Interface lang:', localStorage.getItem('flashcards_interface_lang'));
console.log('Translation lang:', localStorage.getItem('flashcards_translation_lang'));
```

### Сбросить настройки:
```javascript
localStorage.removeItem('flashcards_interface_lang');
localStorage.removeItem('flashcards_translation_lang');
location.reload();
```

### Установить язык программно:
```javascript
localStorage.setItem('flashcards_interface_lang', 'uk');
location.reload();
```

---

## Проблемы и решения

### Проблема: Язык не меняется
**Решение:**
1. Откройте консоль браузера (F12)
2. Проверьте на ошибки JavaScript
3. Убедитесь, что файл flashcards.js загрузился
4. Проверьте: `typeof flashcardsInit` должен быть `"function"`

### Проблема: Язык сбрасывается после перезагрузки
**Решение:**
1. Проверьте localStorage: `localStorage.getItem('flashcards_interface_lang')`
2. Убедитесь, что localStorage не заблокирован в браузере
3. Проверьте, что localStorage не очищается при закрытии браузера

### Проблема: Некоторые надписи не переводятся
**Решение:**
- Это нормально! Многие элементы пока используют PHP языковые строки Moodle
- Переведены только основные элементы: заголовок и вкладки
- Для полного перевода нужно расширить словарь `interfaceTranslations`

---

## Баги для отчета

Если найдете проблемы, укажите:
1. Шаги для воспроизведения
2. Ожидаемое поведение
3. Фактическое поведение
4. Браузер и версия
5. Содержимое консоли (F12 → Console)
6. Значения localStorage (см. команды выше)

---

## Откат изменений

Если нужно вернуться к предыдущей версии:

```bash
cd /d/moodle-dev/norwegian-learning-platform/moodle-plugin/flashcards_app/mod/flashcards

# Найти файлы backup
ls -la templates/app.mustache.bak_*
ls -la assets/flashcards.js.bak_*

# Восстановить (замените YYYYMMDD_HHMMSS на реальную дату)
cp templates/app.mustache.bak_YYYYMMDD_HHMMSS templates/app.mustache
cp assets/flashcards.js.bak_YYYYMMDD_HHMMSS assets/flashcards.js

# Очистить кеш Moodle
php admin/cli/purge_caches.php
```

---

## Следующие шаги

После успешного тестирования можно:
1. Добавить больше переводимых элементов
2. Создать языковые пакеты для всех сообщений
3. Добавить флаги стран в селектор
4. Создать систему уведомлений (toast) для смены языка
5. Синхронизировать с Moodle user preferences

