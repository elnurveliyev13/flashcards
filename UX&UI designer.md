Для премиальной тёмной темы нужны нейтральные холодные поверхности + один яркий акцент. Ниже — точные HEX и готовый патч: шапка, поля ввода, вкладки, ссылки.
Палитра (замена «серо-зелёного» на нейтральный slate)
Page bg --bg: #0B1220
Panel / header --surface-1: #111827 ← заменяет текущий «зеленоватый»
Inputs / subpanels --surface-2: #1F2937 ← серо-синий, без зелени
Border / dividers --border: #2A3647
Hover for rows/inputs --hover: #273244
Text --text: #E5E7EB
Muted --text-muted: #B6BDCB
Placeholder --text-subtle: #8A93A7
Primary accent (кнопки/ссылки/индикаторы) --primary: #2563EB
Primary hover --primary-hover: #1D4ED8
Focus ring --focus: #93C5FD
Danger --danger: #EF4444
Success --success: #22C55E
Патч CSS (вставь поверх темы)
:root{
--bg:#0B1220;
--surface-1:#111827;   /* header, панели /
--surface-2:#1F2937;   / поля ввода, внутри панелей */
--border:#2A3647;
--hover:#273244;
--text:#E5E7EB;
--text-muted:#B6BDCB;
--text-subtle:#8A93A7;
--primary:#2563EB;
--primary-hover:#1D4ED8;
--focus:#93C5FD;
--danger:#EF4444;
--success:#22C55E;
}
/* Страница и шапка */
body{ background:var(--bg); color:var(--text); }
.header, .page-header, .card, .panel{ background:var(--surface-1)!important; border:1px solid var(--border); }
/* Вкладки */
.tabs{ background:var(--surface-1); }
.tab{ color:var(--text-muted); }
.tab--active{ color:var(--text); position:relative; }
.tab--active::after{ content:""; position:absolute; left:0; right:0; bottom:-8px; height:2px; background:var(--primary); }
/* Ссылки (Choose file, Hide Advanced — один и тот же стиль) */
a, .link, .btn-link{ color:var(--primary)!important; }
a:hover, .link:hover, .btn-link:hover{ color:var(--primary-hover)!important; text-decoration:underline; }
/* Поля ввода */
.input, textarea, select{
background:var(--surface-2)!important;
color:var(--text)!important;
border:1px solid var(--border)!important;
border-radius:12px; padding:12px 14px; line-height:1.5;
}
.input::placeholder, textarea::placeholder{ color:var(--text-subtle); }
.input:hover, textarea:hover, select:hover{ background:var(--hover); }
.input:focus, textarea:focus, select:focus{
outline:2px solid var(--focus); outline-offset:1px;
border-color:var(--primary)!important; caret-color:var(--primary);
}
/* Плитки Record/Photo — нейтральные, не синие */
.tile{ background:var(--surface-1); border:1px solid var(--border); color:var(--text); }
.tile:hover{ background:var(--hover); }
Что исправит этот патч на твоём скрине
Шапка и панель станут нейтрально-графитовыми (#111827), без зеленоватого оттенка.
Поля ввода станут более читаемыми: графитовый фон #1F2937, белый текст, холодный голубой фокус — равномерный контраст.
Ссылки «Choose file» и «Hide Advanced» — одним и тем же синим акцентом (#2563EB) с hover-подчёркиванием.
Вкладка Active перестанет быть «синей кнопкой» — останется нейтральной с тонкой синей линией снизу (дороже выглядит).
Плитки Record/Photo больше не «синие карточки» — станут нейтральными и перестанут спорить с полями.
