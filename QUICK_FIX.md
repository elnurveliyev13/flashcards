# Быстрое исправление видимости кнопки PWA Install

## Проблема
- ✅ Кнопка видна у админа на desktop
- ❌ Кнопка НЕ видна у студента (другой аккаунт)
- ❌ Кнопка НЕ видна на мобильном (даже у админа)

---

## Причина
**Moodle кеширует JavaScript и шаблоны Mustache для каждого пользователя.**

---

## Решение (3 шага)

### Шаг 1: Очистить кеш Moodle

**Вариант A: CLI (быстрее)**
```bash
cd /path/to/moodle
php admin/cli/purge_caches.php
```

**Вариант B: Веб-интерфейс**
1. Войти как администратор
2. Site administration → Development → **Purge all caches**
3. Нажать кнопку

---

### Шаг 2: Обновить плагин

Версия обновлена на **2025103002** с debug логами.

```bash
cd /path/to/moodle
php admin/cli/upgrade.php
```

Или через веб: Site administration → Notifications → Upgrade

---

### Шаг 3: Попросить пользователей очистить кеш браузера

**Desktop**:
- Chrome/Edge: `Ctrl + Shift + R` (Win) или `Cmd + Shift + R` (Mac)

**Mobile**:
- Android Chrome: Settings → Privacy → Clear browsing data → Cached images
- iOS Safari: Settings → Safari → Clear History and Website Data

---

## Проверка (в консоли браузера)

Откройте F12 (DevTools) → Console и найдите логи:

```
[PWA] Install button in DOM: true          ← Кнопка найдена
[PWA] Service Worker support: true         ← PWA поддерживается
[PWA] User agent: Mozilla/5.0 ...          ← Браузер
[PWA] ✅ Install prompt available          ← Событие сработало (кнопка видна)
```

**Если видите `[PWA] Install button in DOM: false`**:
→ Кеш Moodle не очищен, повторите Шаг 1

**Если видите `[PWA] Install button in DOM: true`, но НЕТ `✅ Install prompt available`**:
→ Браузер еще не готов показать PWA prompt (см. ниже)

---

## Почему событие `beforeinstallprompt` может НЕ сработать?

### Критерии Chrome для PWA:

1. ✅ **HTTPS** (или localhost)
2. ✅ **Service Worker** зарегистрирован
3. ✅ **Manifest** доступен
4. ✅ **Engagement**: пользователь взаимодействовал с сайтом
5. ✅ **Приложение НЕ установлено**

### Как проверить:

#### Desktop Chrome DevTools:
1. F12 → **Application** → **Manifest**
   - Должны быть иконки 192x192 и 512x512
   - Name: "Карточки SRS"

2. **Application** → **Service Workers**
   - Status: должен быть **activated and running**

3. **Console** → проверьте:
   ```javascript
   navigator.serviceWorker.controller
   ```
   - Должен вернуть объект (не null)

#### Mobile Chrome (через USB debugging):
1. Подключите Android по USB
2. На ПК: `chrome://inspect`
3. Выберите устройство → откройте Moodle
4. Проверьте то же самое в DevTools

---

## Быстрый тест на мобильном

### Android Chrome:

1. Откройте Moodle в Chrome
2. Подождите **30-60 секунд** на странице
3. Поскроллите, понажимайте кнопки (взаимодействие)
4. **Обновите страницу** (потяните вниз)
5. Откройте консоль через USB debugging
6. Проверьте логи `[PWA]`

### Если кнопка всё равно не появилась:

**Проверьте что приложение НЕ установлено**:
- Android: Settings → Apps → найдите "Карточки SRS" или "SRS Cards"
- Если есть → удалите, затем обновите страницу

---

## Временное решение (для тестирования)

Если нужно проверить **UI кнопки** (без ожидания события):

### Вариант 1: Убрать класс `hidden`

В `templates/app.mustache`, строка 86:

```html
<!-- Было -->
<button id="btnInstallApp" class="hidden install-btn" ...>

<!-- Стало (временно) -->
<button id="btnInstallApp" class="install-btn" ...>
```

Кнопка будет видна всегда (даже если PWA не готова).

### Вариант 2: Форсировать показ через консоль

```javascript
// В консоли браузера:
document.getElementById('btnInstallApp')?.classList.remove('hidden');
```

---

## Альтернатива: Показать инструкцию вместо кнопки

Если `beforeinstallprompt` не работает, можно показать текстовую подсказку:

```javascript
// Добавить в flashcards.js после строки 727:

// Если через 10 секунд событие не сработало
setTimeout(() => {
  if (btnInstallApp && btnInstallApp.classList.contains('hidden')) {
    // Проверка что это Android Chrome
    const isAndroidChrome = /Android/.test(navigator.userAgent) &&
                            /Chrome/.test(navigator.userAgent);
    if (isAndroidChrome) {
      console.log('[PWA] Event not fired. Show manual instruction?');
      // Можно показать hint: "Tap ⋮ menu → Install app"
    }
  }
}, 10000);
```

---

## Чеклист отладки

### Для студента (другой аккаунт):
- [ ] Админ очистил кеш Moodle
- [ ] Плагин обновлён до версии 2025103002
- [ ] Студент обновил страницу с очисткой кеша (Ctrl+Shift+R)
- [ ] В консоли видно: `[PWA] Install button in DOM: true`
- [ ] Нет ошибок JavaScript в консоли

### Для мобильного (Android Chrome):
- [ ] Используется Chrome/Edge (НЕ Firefox/Safari)
- [ ] Открыто по HTTPS
- [ ] Service Worker активен (проверить в DevTools)
- [ ] Manifest загружен (проверить в DevTools)
- [ ] Приложение НЕ установлено на устройстве
- [ ] Подождали 30-60 секунд на странице
- [ ] Обновили страницу после взаимодействия

### Для iOS:
- ⚠️ **iOS НЕ поддерживает `beforeinstallprompt`**
- Используйте стандартный способ: Safari → Share → Add to Home Screen

---

## Ожидаемый результат после исправления

### Desktop (Chrome/Edge):
```
[PWA] Install button in DOM: true
[PWA] Service Worker support: true
[PWA] User agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) ...
[PWA] ✅ Install prompt available - button shown
```
→ Кнопка "📱 Install App" появилась в header

### Mobile Android (Chrome):
```
[PWA] Install button in DOM: true
[PWA] Service Worker support: true
[PWA] User agent: Mozilla/5.0 (Linux; Android 13; ...) Chrome/120.0 ...
[PWA] ✅ Install prompt available - button shown
```
→ Кнопка "📱 Install App" появилась

### Mobile iOS (Safari):
```
[PWA] Install button in DOM: true
[PWA] Service Worker support: true
[PWA] User agent: Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 ...) Safari/604.1
```
→ Событие НЕ сработает (это нормально для iOS)
→ Используйте Share → Add to Home Screen

---

## Если ничего не помогло

1. Пришлите скриншот консоли (F12 → Console)
2. Пришлите вывод команд:
   ```bash
   php admin/cli/upgrade.php --version
   ls -la mod/flashcards/version.php
   cat mod/flashcards/version.php | grep version
   ```
3. Проверьте права доступа к файлам:
   ```bash
   chmod -R 755 mod/flashcards
   ```

---

**Автор**: Claude
**Дата**: 2025-10-30
**Версия плагина**: 2025103002 (v0.6.1-pwa-debug)
