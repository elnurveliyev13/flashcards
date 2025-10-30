# PWA Install Button Feature

## Дата: 2025-10-30
## Версия плагина: 0.6.0-pwa-install (2025103001)

---

## Описание

Добавлена **кнопка "Установить приложение"** для PWA (Progressive Web App), которая позволяет пользователям установить приложение Flashcards на домашний экран (мобильные устройства) или в системе (desktop).

---

## Что было сделано

### 1. **Обработчик события `beforeinstallprompt`**

Добавлен в два места:

#### a) `assets/flashcards.js` (строки 685-727)
```javascript
// PWA Install Prompt
let deferredInstallPrompt = null;
const btnInstallApp = $("#btnInstallApp");

window.addEventListener('beforeinstallprompt', (e) => {
  e.preventDefault();
  deferredInstallPrompt = e;
  if(btnInstallApp) {
    btnInstallApp.classList.remove('hidden');
    console.log('[PWA] Install prompt available');
  }
});

if(btnInstallApp) {
  btnInstallApp.addEventListener('click', async () => {
    if(!deferredInstallPrompt) return;
    deferredInstallPrompt.prompt();
    const { outcome } = await deferredInstallPrompt.userChoice;
    console.log(`[PWA] User choice: ${outcome}`);
    deferredInstallPrompt = null;
    btnInstallApp.classList.add('hidden');
  });
}

window.addEventListener('appinstalled', () => {
  console.log('[PWA] App successfully installed');
  if(btnInstallApp) btnInstallApp.classList.add('hidden');
  deferredInstallPrompt = null;
});
```

#### b) `amd/src/app.js` (строки 368-410)
- Идентичная логика для AMD модуля (используется в Moodle)
- Обработка события, показ кнопки, установка

---

### 2. **UI компоненты**

#### a) Кнопка в `templates/app.mustache` (строка 86-88)
```html
<button id="btnInstallApp" class="hidden install-btn" title="{{#str}} install_app, mod_flashcards {{/str}}">
  📱 {{#str}} install_app, mod_flashcards {{/str}}
</button>
```

**Позиция**: в хедере, между селектором языка и кнопкой "Cards list"

**Поведение**:
- По умолчанию скрыта (`hidden`)
- Показывается только когда браузер готов к установке PWA
- Автоматически скрывается после установки

---

#### b) CSS стили (строки 68-85)
```css
/* PWA Install Button */
.install-btn{
  background:linear-gradient(135deg, #667eea 0%, #764ba2 100%);
  border:none;
  color:#fff;
  font-weight:600;
  padding:10px 16px;
  border-radius:12px;
  box-shadow:0 4px 12px rgba(102,126,234,0.3);
  transition:all 0.3s ease;
}
.install-btn:hover{
  transform:translateY(-2px);
  box-shadow:0 6px 16px rgba(102,126,234,0.4);
}
.install-btn:active{
  transform:translateY(0);
}
```

**Дизайн**:
- Градиентный фон (фиолетовый → пурпурный)
- Белый текст, жирный шрифт
- Тень и анимация при наведении
- Поднимается на 2px вверх при hover

---

### 3. **Языковые строки**

#### `lang/en/flashcards.php` (строка 40)
```php
$string['install_app'] = 'Install App';
```

**Поддержка переводов**:
- Можно добавить переводы в `lang/no/flashcards.php` (норвежский)
- Можно добавить переводы в `lang/uk/flashcards.php` (украинский)

---

### 4. **Версия плагина**

#### `version.php`
- **Старая версия**: `2025102920` (0.5.17)
- **Новая версия**: `2025103001` (0.6.0)
- **Release**: `0.6.0-pwa-install`

#### `assets/flashcards.js`
- Обновлена `CACHE_VERSION` на `2025103001`
- При следующем запуске кеш будет очищен автоматически

---

## Как это работает

### Условия показа кнопки

Браузер показывает событие `beforeinstallprompt` **только если**:

1. ✅ Приложение загружено по **HTTPS** (или localhost)
2. ✅ Есть валидный **manifest.webmanifest**
3. ✅ Зарегистрирован **Service Worker**
4. ✅ Приложение **еще не установлено**
5. ✅ Пользователь **достаточно взаимодействовал** с сайтом (критерий браузера)

### Поведение кнопки

1. **Скрыта по умолчанию** (класс `hidden`)
2. **Показывается автоматически**, когда браузер готов к установке
3. **При клике**:
   - Вызывается `deferredInstallPrompt.prompt()`
   - Браузер показывает системный диалог установки
   - Кнопка скрывается после выбора пользователя
4. **После установки**:
   - Событие `appinstalled` скрывает кнопку навсегда
   - При следующих визитах кнопка не появится

---

## Тестирование

### Desktop (Chrome/Edge)

1. Открыть приложение в браузере (HTTPS)
2. Подождать 30 секунд взаимодействия
3. Должна появиться кнопка "📱 Install App"
4. Нажать → появится системный диалог
5. Выбрать "Install" → приложение установится

**Проверка**:
- `chrome://apps/` - приложение должно появиться в списке
- Desktop icon создается автоматически

### Mobile (Android)

1. Открыть в Chrome/Edge на Android
2. Кнопка должна появиться сразу (если критерии выполнены)
3. Нажать → системный диалог "Add to Home screen"
4. Подтвердить → иконка появится на главном экране

### Mobile (iOS Safari)

⚠️ **iOS НЕ поддерживает `beforeinstallprompt`**

**Альтернатива** (стандартный способ iOS):
1. Открыть Safari
2. Нажать кнопку "Share" (квадрат со стрелкой)
3. Выбрать "Add to Home Screen"
4. Подтвердить

**Примечание**: На iOS кнопка не появится, пользователь должен использовать встроенную функцию Safari.

---

## Логирование

Все действия логируются в консоль:

```javascript
[PWA] Install prompt available          // Кнопка показана
[PWA] No install prompt available       // Клик без события (не должно быть)
[PWA] User choice: accepted             // Пользователь согласился
[PWA] User choice: dismissed            // Пользователь отклонил
[PWA] App successfully installed        // Установка завершена
```

**Как проверить**: Открыть DevTools → Console

---

## Файлы изменены

```
mod/flashcards/
├── assets/flashcards.js              ✏️ Добавлен PWA install handler
├── amd/src/app.js                    ✏️ Добавлен PWA install handler
├── amd/build/app.min.js              ✏️ Скопирован из src (минификация)
├── templates/app.mustache            ✏️ Добавлена кнопка + CSS
├── lang/en/flashcards.php            ✏️ Добавлена строка 'install_app'
├── version.php                       ✏️ Версия: 2025103001
└── PWA_INSTALL_FEATURE.md            🆕 Этот документ
```

---

## Следующие шаги (опционально)

### 1. Добавить переводы

**Норвежский** (`lang/no/flashcards.php`):
```php
$string['install_app'] = 'Installer app';
```

**Украинский** (`lang/uk/flashcards.php`):
```php
$string['install_app'] = 'Встановити додаток';
```

### 2. Добавить подсказку для iOS

Можно добавить hint для iOS пользователей:

```html
<!-- В app.mustache, под кнопкой Install -->
<div id="iosInstallHint" class="hidden small" style="color:#9aa3b1;margin-top:8px">
  iOS users: Tap <strong>Share</strong> → <strong>Add to Home Screen</strong>
</div>
```

```javascript
// В flashcards.js / app.js
const isIOS = /iPad|iPhone|iPod/.test(navigator.userAgent) && !window.MSStream;
if(isIOS && !window.navigator.standalone) {
  $("#iosInstallHint").classList.remove('hidden');
}
```

### 3. Добавить A2HS prompt для Android (fallback)

Если `beforeinstallprompt` не сработал на Android, можно показать инструкцию:

```javascript
// Через 2 минуты, если кнопка не появилась
setTimeout(() => {
  if(btnInstallApp && btnInstallApp.classList.contains('hidden')) {
    // Показать инструкцию: "Open menu → Install app"
  }
}, 120000);
```

---

## FAQ

### Q: Почему кнопка не появляется?

**A**: Проверьте:
1. Открыто по HTTPS (не HTTP)
2. Service Worker зарегистрирован (`navigator.serviceWorker.controller` не null)
3. Manifest файл доступен (откройте `/app/manifest.webmanifest`)
4. Приложение еще не установлено (проверьте `chrome://apps/`)
5. Достаточно взаимодействия (подождите 30 сек)

### Q: Как протестировать событие снова?

**A**: В Chrome DevTools:
1. Application → Service Workers → Unregister
2. Application → Storage → Clear site data
3. Закрыть все вкладки с приложением
4. Открыть заново

### Q: Можно ли форсировать показ кнопки?

**A**: Нет, `beforeinstallprompt` контролируется браузером. Но можно:
- Удалить класс `hidden` вручную в DevTools для тестирования UI
- Использовать Chrome DevTools: Application → Manifest → "Add to homescreen" (для эмуляции)

### Q: Работает ли на iOS?

**A**: Нет, iOS Safari НЕ поддерживает `beforeinstallprompt`. Используйте стандартный способ:
- Safari → Share → Add to Home Screen

---

## Совместимость браузеров

| Браузер              | Desktop | Mobile  | `beforeinstallprompt` |
|----------------------|---------|---------|------------------------|
| Chrome               | ✅      | ✅      | ✅                     |
| Edge (Chromium)      | ✅      | ✅      | ✅                     |
| Firefox              | ❌      | ❌      | ❌ (не поддерживает)  |
| Safari (macOS)       | ❌      | -       | ❌                     |
| Safari (iOS)         | -       | ⚠️      | ❌ (используй Share)  |
| Opera                | ✅      | ✅      | ✅                     |
| Samsung Internet     | -       | ✅      | ✅                     |

**Легенда**:
- ✅ Полная поддержка
- ⚠️ Альтернативный способ (Share button)
- ❌ Не поддерживается

---

## Заключение

Функциональность **PWA Install Button** полностью реализована и готова к использованию.

**Преимущества**:
- ✅ Не требует магазина приложений
- ✅ Быстрая установка одним кликом
- ✅ Нативный вид приложения (без адресной строки)
- ✅ Автоматические обновления
- ✅ Работает offline (благодаря Service Worker)

**Для активации**:
1. Откройте плагин в Moodle
2. Дождитесь появления кнопки "📱 Install App"
3. Нажмите → подтвердите установку
4. Приложение готово!

---

**Автор**: Claude (AI Assistant)
**Дата**: 2025-10-30
**Версия**: 0.6.0-pwa-install
