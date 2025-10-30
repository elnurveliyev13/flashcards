# Инструкция по очистке кеша Moodle

## Проблема
После обновления плагина кнопка "Install App" не появляется у некоторых пользователей из-за кеширования.

---

## Решение 1: Очистить кеш через веб-интерфейс (РЕКОМЕНДУЕТСЯ)

### Способ A: Полная очистка кеша
1. Войдите как **администратор**
2. Перейдите: **Site administration** → **Development** → **Purge all caches**
3. Нажмите кнопку **"Purge all caches"**
4. Подождите 10-20 секунд
5. Попросите студентов **обновить страницу** (Ctrl+F5 или Cmd+Shift+R)

### Способ B: Очистка только theme кеша
1. **Site administration** → **Appearance** → **Themes** → **Theme selector**
2. Нажмите **"Clear theme caches"**
3. Обновите страницу

---

## Решение 2: Очистить кеш через CLI (быстрее)

```bash
# Перейти в директорию Moodle
cd /path/to/moodle

# Очистить ВСЕ кеши
php admin/cli/purge_caches.php

# Или только JavaScript кеш
php admin/cli/purge_caches.php --js=true
```

---

## Решение 3: Форсировать перезагрузку у студентов

### Desktop браузеры:
- **Chrome/Edge**: `Ctrl + Shift + R` (Windows) или `Cmd + Shift + R` (Mac)
- **Firefox**: `Ctrl + F5` (Windows) или `Cmd + Shift + R` (Mac)
- **Safari**: `Cmd + Option + R`

### Mobile браузеры:
- **Android Chrome**:
  1. Открыть настройки браузера (3 точки)
  2. Settings → Privacy → Clear browsing data
  3. Выбрать "Cached images and files"
  4. Clear data

- **iOS Safari**:
  1. Settings → Safari → Clear History and Website Data
  2. Или просто обновить страницу (потянуть вниз)

---

## Решение 4: Проверить режим разработки (для тестирования)

Если вы часто обновляете плагин, включите режим разработки:

```bash
# В config.php добавьте:
$CFG->cachejs = false;      // Отключить кеширование JS
$CFG->cachetemplates = false; // Отключить кеширование шаблонов
$CFG->themedesignermode = true; // Режим разработки темы
```

⚠️ **ВАЖНО**: Не используйте это на production сервере! Только для разработки.

---

## Решение 5: Увеличить версию плагина (форсировать обновление)

Если после очистки кеша проблема осталась:

1. Откройте `version.php`
2. Увеличьте версию на 1:
   ```php
   $plugin->version = 2025103002; // было 2025103001
   ```
3. Запустите обновление:
   ```bash
   php admin/cli/upgrade.php
   ```

---

## Проверка что кеш очищен

### В браузере (DevTools):
1. Откройте консоль (F12)
2. Перейдите на вкладку **Network**
3. Обновите страницу
4. Найдите файл `app.min.js` или `flashcards.js`
5. Проверьте:
   - **Status**: должен быть `200` (не `304 Not Modified`)
   - **Size**: должен быть полный размер файла (не "from cache")

### В консоли браузера:
```javascript
// Вставьте в консоль:
console.log('Install button exists:', !!document.getElementById('btnInstallApp'));
```

Если выводит `true` - кнопка есть в DOM (может быть скрыта до события `beforeinstallprompt`)

---

## Почему кнопка может быть скрыта на мобильном?

### Причина 1: PWA критерии не выполнены
Проверьте в Chrome DevTools (подключите Android устройство):

1. Подключите телефон по USB
2. Откройте `chrome://inspect` на ПК
3. Выберите устройство → откройте Moodle
4. В DevTools: **Application** → **Manifest**
5. Проверьте:
   - ✅ Manifest загружен
   - ✅ Service Worker активен (Application → Service Workers)
   - ✅ HTTPS соединение (или localhost)

### Причина 2: Приложение уже установлено
Если PWA уже установлена на устройстве, событие `beforeinstallprompt` не сработает.

**Проверка**:
- Android: Settings → Apps → найдите "SRS Cards" или "Карточки SRS"
- Если есть - удалите, затем обновите страницу в браузере

### Причина 3: Браузер не поддерживает PWA
- ✅ Chrome Android - поддерживает
- ✅ Edge Android - поддерживает
- ❌ Firefox Android - НЕ поддерживает `beforeinstallprompt`
- ❌ Samsung Internet - частично поддерживает

### Причина 4: Недостаточно взаимодействия
Chrome требует "engagement" перед показом:
- Подождите 30-60 секунд на странице
- Поскроллите, понажимайте кнопки
- Затем обновите страницу

---

## Быстрый чеклист для отладки

### Для студента, который не видит кнопку:

1. ✅ **Админ очистил кеш** Moodle? (`php admin/cli/purge_caches.php`)
2. ✅ **Студент обновил страницу** с очисткой кеша? (`Ctrl+Shift+R`)
3. ✅ **Версия плагина обновлена**? (проверьте `Site admin → Plugins → Activity modules → Flashcards`)
4. ✅ **В консоли нет ошибок JS**? (откройте F12 → Console)
5. ✅ **Кнопка есть в DOM**? (в консоли: `!!document.getElementById('btnInstallApp')`)
6. ✅ **Service Worker зарегистрирован**? (в консоли: `!!navigator.serviceWorker.controller`)

### Для мобильного устройства:

1. ✅ **Используется Chrome/Edge**, а не Firefox/Safari?
2. ✅ **HTTPS соединение**? (проверьте адресную строку)
3. ✅ **Приложение НЕ установлено** на устройстве?
4. ✅ **Manifest доступен**? (откройте `/mod/flashcards/app/manifest.webmanifest`)
5. ✅ **Service Worker активен**? (в консоли: `navigator.serviceWorker.getRegistration()`)
6. ✅ **Достаточно взаимодействия**? (подождите 30 сек, поскроллите)

---

## Логирование для отладки

Добавьте временно в консоль (для проверки):

```javascript
// Проверка DOM
console.log('Button in DOM:', !!document.getElementById('btnInstallApp'));

// Проверка класса hidden
const btn = document.getElementById('btnInstallApp');
console.log('Button hidden:', btn?.classList.contains('hidden'));

// Проверка Service Worker
navigator.serviceWorker.getRegistration().then(reg => {
  console.log('Service Worker:', reg ? 'Registered' : 'Not registered');
});

// Проверка manifest
fetch('/mod/flashcards/app/manifest.webmanifest')
  .then(r => console.log('Manifest status:', r.status))
  .catch(e => console.error('Manifest error:', e));

// Слушатель события
window.addEventListener('beforeinstallprompt', (e) => {
  console.log('[DEBUG] beforeinstallprompt fired!', e);
});
```

---

## Если ничего не помогло

### Вариант 1: Добавить кнопку принудительно (для тестирования)

Временно уберите класс `hidden`:

```html
<!-- В app.mustache, строка 86 -->
<button id="btnInstallApp" class="install-btn" ...>
```

Это покажет кнопку всегда (даже если PWA не готова). Для проверки что UI работает.

### Вариант 2: Добавить debug режим

В `flashcards.js` добавьте после строки 687:

```javascript
const btnInstallApp = $("#btnInstallApp");

// DEBUG: показать кнопку через 5 секунд, даже если события нет
setTimeout(() => {
  if(btnInstallApp && btnInstallApp.classList.contains('hidden')) {
    console.warn('[DEBUG] Force showing install button for testing');
    btnInstallApp.classList.remove('hidden');
  }
}, 5000);
```

Это форсирует показ кнопки через 5 секунд для тестирования.

---

## Резюме

**Главная причина**: Moodle кеширует JavaScript и шаблоны.

**Главное решение**:
```bash
php admin/cli/purge_caches.php
```

**Проверка на мобильном**:
- Используйте Chrome Android
- Подождите 30 секунд
- Проверьте что PWA не установлена
- Проверьте Service Worker в DevTools

---

**Если проблема осталась** - пришлите скриншот консоли (F12) и вывод команд выше.
