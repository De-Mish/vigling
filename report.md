# Отчёт о выполненной миграции Joomla 3.9 → Joomla 6

**Источник (J3):** `public_html/` (или прежний корень), БД **viglinbd_vigl1**, префикс `joomla_`  
**Приёмник (J6):** `public_html/` (J6 развёрнут в корне после переноса), БД **viglinbd_vigl2**, префикс `joomla_`  
**Бэкап БД J6 до миграций:** `_live.viglinbd_vigl2.3182039.sql.gz` (для восстановления админки — скрипт 05)

---

## 1. База данных

| Показатель | Значение |
|------------|----------|
| Таблиц в J3 (с префиксом) | 90 |
| Таблиц в J6 | 76 |
| Таблиц общих (перенесено) | 57 |
| Всего строк перенесено | 206 004 |
| Скрипт | `migration_scripts/04_migrate_all_tables.php` |

- Копируются только **общие колонки** между J3 и J6; на время переноса отключена проверка внешних ключей (FOREIGN_KEY_CHECKS=0).
- Крупные перенесённые таблицы: **joomla_users** 92 556, **joomla_user_usergroup_map** 92 511, **joomla_action_logs** 5 592, **joomla_overrider** 8 877, **joomla_tags** 1 944, **joomla_contentitem_tag_map** 1 266, **joomla_content** 247, **joomla_categories** 70, **joomla_menu** 69, **joomla_modules** 44, **joomla_extensions** 261, **joomla_template_styles** 5 и др.
- Вспомогательные скрипты: **01_list_tables.php**, **02_compare_table_structure.php**, **03_migrate_users.php** (пользователи + user_profiles), **load_j6_config.php** (парсинг J6 configuration.php без класса JConfig). Во всех скриптах детальное логирование.

**Важно после переноса БД:** таблицы **joomla_extensions**, **joomla_schemas**, **joomla_template_styles** и админ-часть **joomla_modules** / **joomla_template_styles** (client_id=1) — см. раздел «Восстановление админки».

---

## 2. Восстановление админки (пустая панель после переноса БД)

После полного переноса БД данные J3 перезаписывают админ-модули и стиль Atum (client_id=1) в J6 — админка становится пустой (нет модулей, нет стилей).

**Решение:** скрипт **`migration_scripts/05_restore_admin_from_backup.php`**.

- Восстанавливает в vigl2 только записи с **client_id=1** в таблицах **joomla_modules** и **joomla_template_styles** (и привязки в **joomla_modules_menu**).
- Источники: 1) БД viglinbd_vigl2_backup (если доступна); 2) дамп `.sql`/`.sql.gz` (пути в скрипте или env DUMP_FILE); 3) **чистая J6** — указать путь к её `configuration.php`:  
  `php migration_scripts/05_restore_admin_from_backup.php /путь/к/чистой/j6/configuration.php`
- Скрипт подставляет новые id (MAX(id)+1, …), чтобы избежать дубликатов PRIMARY KEY; после вставки модулей дописывает в **joomla_modules_menu** строки с menuid=0, чтобы модули отображались во всех пунктах меню админки.
- Выполнено: восстановлено **33 модуля** и **1 стиль** (Atum), **33 привязки** в joomla_modules_menu; админка отображается полностью.

---

## 3. Медиафайлы (изображения)

- Папка **images/** перенесена **переименованием** (без копирования), чтобы не занимать лишнее место:  
  `mv jom_6/images jom_6/images_j6_default` и `mv images jom_6/images`.
- Объём: ~**14 500** файлов (jpg, jpeg, png, avatars, thumbnails и т.д.).
- Дефолтные изображения J6 сохранены в `jom_6/images_j6_default/` (при необходимости можно удалить).

---

## 4. Шаблон (тема) ryba

- Шаблон **ryba** перенесён и адаптирован под J6: `jom_6/templates/ryba/`.
- Обновлены **index.php**, **component.php**, **error.php**, **offline.php** под J6 API (Factory, Uri, WebAssetManager; jdoc type="metas/styles/scripts").
- Скопированы папки: **css**, **js**, **images**, **html**, **feedback**, **fonts**, **favicon.png**.
- Позиции шаблона сохранены (top, offcanvas, topmenu, breadcrumbs, addmaster, content, loadapps, topposts, bottommenu, sidebar, slider, debug).
- Оверрайды в **html/** (com_content, com_jsn, com_users, com_media, mod_* и др.) перенесены как есть; параметры слайдера в **templateDetails.xml** переведены с imagelist на тип **media**.

---

## 5. PWA и конфигурация

- **manifest.json** перенесён в корень `jom_6/` (name, short_name, start_url, display, gcm_sender_id).
- В **configuration.php** J6 прописаны: live_site https://vigling.ru, sitename VIGLING, MetaDesc, mailfrom, fromname, force_ssl=2, gzip=true, list_limit=50, sef_rewrite=true.
- Создан **jom_6/.htaccess** с редиректами www→vigling.ru и http→https и правилами SEF Joomla 6.

---

## 6. Анализ БД: что не перенесено

Скрипт **`analyze/analyze_old_db.php`** подключается к vigl1 и vigl2 (конфиг vigl1 в **analyze/config_vigl1.php**), сравнивает размеры и списки таблиц.

| Показатель | Значение |
|------------|----------|
| vigl1 общий размер (данные+индексы) | ~302 MB |
| vigl2 общий размер | ~50 MB |
| Таблиц только в vigl1 (не перенесены) | 33 |
| Размер таблиц только в vigl1 | ~255 MB |

**Основной объём «не перенесённого» — данные сторонних расширений:**

| Группа | Таблицы | Размер | Примечание |
|--------|---------|--------|------------|
| JSN (профили/пользователи) | joomla_jsn_users — **данные перенесены в Custom Fields** (скрипт 06). joomla_jsn_fields, joomla_jsn_stocks, joomla_jsn_orders | ~176 MB | См. раздел 8 (миграция JSN). |
| EasyBook (гостевая книга) | joomla_easybook, joomla_easybook_badwords, joomla_easybook_gb | ~75 MB | Аналогично: J6-версия расширения, затем миграция данных |
| J3 UCM / логи | joomla_ucm_history | ~3.7 MB | История версий J3; в J6 другая модель, обычно не переносят |
| Finder (Smart Search J3) | joomla_finder_links_terms* | ~0.7 MB | Индексы J3; в J6 структура другая |
| Akeeba, DJ Slider, прочее | joomla_ak_*, joomla_akeeba_common, joomla_djimageslider, joomla_wf_profiles и др. | мелкие | Пересоздаются при установке расширений под J6 |

Ядро Joomla (users, content, menu, modules, categories, tags и т.д.) перенесено. Подробнее — раздел «Анализ БД» в **MIGRATION_CHECKLIST.md**.

---

## 7. Сторонние расширения для J6

Список расширений, где скачать и как установить, приведён в файле **`THIRD_PARTY_EXTENSIONS_J6.md`**.

- **Компоненты:** JCE Editor, Akeeba Backup, JL Sitemap, JSN, EasyBook, Ajax Upload.
- **Плагины:** JCE (в составе редактора), reCAPTCHA v3, JL Sitemap, Akeeba, PWA, Sourcerer, Regular Labs, JCH Optimize, Pweb Open Graph, Recaptcha.
- **Модули:** DJ-Image Slider, EasyBook Latest Entries, JSN Form/Map/Search/Users, Vertical Menu, Parallax Slider.

Установка в J6: **Расширения → Установить** → загрузить ZIP; плагины включить в **Расширения → Плагины**; модули создать в **Система → Модули**. Совместимость с Joomla 6 у каждого расширения уточнять на сайте разработчика или в JED (extensions.joomla.org).

---

## 8. Миграция JSN (joomla_jsn_users) → Custom Fields

JSN не поддерживается в J6. Данные расширенных профилей из **joomla_jsn_users** (vigl1) перенесены в стандартные **Custom Fields** пользователей (com_users.user) в vigl2.

| Показатель | Значение |
|------------|----------|
| Скрипт миграции | **06_jsn_users_to_custom_fields.php** — чтение vigl1, создание полей в #__fields и запись в #__fields_values |
| Перенесено профилей | 92 293 |
| Пропущено (нет в J6) | 3 |
| Создано полей (контекст com_users.user) | 28 (Имя, Фамилия, Город, Улица, Телефон, Рабочие дни, Цены, Рейтинг, Мастер и др., подпись с суффиксом «(JSN)») |
| Дедупликация | **07_dedupe_fields_values.php** — удаление дубликатов в #__fields_values (таблица без id: временная таблица, MIN(value) на пару field_id, item_id). Удалено 3 014 521 дубликат, оставлено 1 451 659 строк. |

Дополнительно: в **layouts/joomla/form/field/text.php** и **textarea.php** добавлено приведение значения-массива к строке (чтобы не было ошибки htmlspecialchars при отображении полей в форме пользователя); переопределения в **administrator/templates/atum/html/layouts/joomla/form/field/**; при создании полей и при каждом запуске скрипта 06 заполняется **label** = title для подписей в админке. Подробности — **MIGRATION_CHECKLIST.md** (раздел «Миграция JSN»), **JSN_MIGRATION_RSFORM.md**.

### 8.1. Расшифровка закодированных полей JSN в админке (плагин User — Vigling)

Часть перенесённых полей хранит структурированные данные (JSON): **prices**, **stock_prices** — категория → список [цена, мин, id_услуги]; **work_day** — массив дней недели; **vyberite_spetsialnos** — объект id→id специальностей. В форме пользователя они отображались как сырая строка или вызывали ошибки.

**Решение:** плагин **User — Vigling** (`plugins/user/vigling/`), группа User.

| Элемент | Описание |
|--------|----------|
| Событие | `onContentPrepareForm` для формы `com_users.user`; приоритет 100. |
| Вкладка | Добавляется вкладка **«JSN (расшифровка)»** (fieldset `jsn_decode` из `forms/user.xml`). |
| Поле | Кастомное поле типа **jsndecode** (`src/Field/JsndecodeField.php`): по текущему `item_id` (id пользователя) выбирает из `#__fields_values` и `#__fields` значения полей prices, stock_prices, work_day, vyberite_spetsialnos и выводит расшифровку. |
| Helper | `JsnDecodeHelper` — парсинг JSON, форматирование рабочих дней (Пн–Вс), специальностей; для цен — структурированный вывод по категориям с нумерованным списком (услуга — цена руб, мин). Названия категорий и услуг берутся из **lookups.json** (`jsn_adaptation/lookups.json`, скрипт `export_jsn_lookups.php`). |
| Нормализация | Во вкладке «Поля» значения-массивы приводятся к строке; для перечисленных имён полей сырое значение подменяется на расшифрованный текст (чтобы и во вкладке «Поля» было читаемо). |
| Оформление цен | Карточки Bootstrap (card, list-group, list-group-numbered), без кастомных цветов — корректно в светлой и тёмной теме Atum. |

**Зависимости:** Joomla 6, namespace из манифеста; при наличии `lookups.json` выводятся названия категорий и услуг, иначе «Категория N», «Услуга #N».

**Установка:** расширение в `plugins/user/vigling/`, включить в **Расширения → Плагины**. Подробнее — **plugins/user/vigling/README.md**.

**Проблемы при внедрении и решения:**

| Проблема | Решение |
|----------|---------|
| «Copy file failed» при установке через админку | Плагин зарегистрирован в БД вручную (таблица #__extensions, тип plugin, element vigling, folder user). |
| «Class ... not found» при открытии формы пользователя | Namespace плагина должен быть доступен при загрузке формы; у плагина User Joomla подключает его по манифесту. При необходимости проверить регистрацию в administrator/cache/autoload_psr4.php (для плагина user/vigling Joomla 6 регистрирует сам). |
| Расшифровка не срабатывала в плагине System | Обработка формы com_users.user гарантированно выполняется только у плагинов группы **User** (onContentPrepareForm для com_users.user). Функционал перенесён в **User — Vigling**; плагин System — Vigling удалён. |
| Цены: белый текст на белом фоне | Отказ от кастомных стилей; вывод через классы Bootstrap (card, list-group), чтобы тема Atum сама задавала цвета. |

---

## 9. Компонент com_poisk (поиск специалистов)

Замена списка специалистов JSN на нативный компонент J6: данные из **#__users** и **Custom Fields** (поля com_users.user), без тяжёлых запросов и слияния state с меню (избежание OutOfMemory на хостинге).

| Элемент | Описание |
|---------|----------|
| **Установка** | Скрипт **`migration_scripts/14_register_com_poisk.php`** — регистрация в #__extensions и namespace в administrator/cache/autoload_psr4.php. |
| **Меню** | Пункт «Поиск специалистов» → тип **com_poisk** → Список, алиас **poisk-spetsialistov**. Отдельный пункт «Заточка ремонт» — тот же тип, алиас **zatochka-remont**, в настройках списка ветка категорий «Заточка ремонт». |
| **URL** | `/poisk-spetsialistov`, `/poisk-spetsialistov/16`; `/zatochka-remont`, `/zatochka-remont/16` (при отдельном пункте меню). |
| **Настройки пункта меню** | Вкладка «Настройки списка»: **Ветка категорий** (пусто = все услуги, «Заточка ремонт» = только специалисты из ветки zatochka-remont), опционально заголовок страницы и подпись фильтра. Параметры читаются через `$menu->getParams()` (не `$menu->params`). |
| **Роутер** | Parse: сегмент после алиаса → cat_id. Build: cat_id в query → сегмент в URL. |
| **Модель** | ListModel: при ветке «Заточка ремонт» — фильтр по vyberite_spetsialnos (только ID категорий из zatochka-remont); далее cat_id, city, area, home[] (1=Салон, 2=Вызов, 3=Мастер на дому). Сортировка: filter_order (name / rate / price), filter_order_Dir (ASC/DESC). Лимит 1–50. |
| **Сортировка** | В шапке: «Результатов поиска — N», форма с радиокнопками **Сортировка: Рекомендуемое, Рейтинг, Цена** (отправка с сохранением фильтров). |
| **Форма фильтра** | Селекты **Город**, **Район**, **Мастер** (категория), **Вид услуги** (мульти). Данные для городов/районов — PoiskHelper::getCities(), getAreas() из #__fields_values. Стили ryba: Chosen (templates/ryba/css/chosen.min.css, js/chosen.jquery.min.js), инициализация по `.category__masters-sidebar select`. |
| **Пагинация** | Оверрайды в **templates/ryba/html/layouts/joomla/pagination/list.php** и **link.php**: обёртка `div.pagination__wrap`, `ul.pagination`, иконки icon-first/previous/next/last, для текущей страницы — класс active и стиль (#F9CE54). |
| **Внешний вид** | Разметка и классы темы **ryba** (category jsn_list, category__masters-sidebar, category__item, sort, filter). Карточка: имя, о себе, адрес, форма работы, «Записаться». Карта Yandex по адресам специалистов. |
| **Данные** | Категории из #__categories; города/районы из #__fields_values; поля пользователей — PoiskHelper::getFieldsForUserIds(). |

Ссылки с главной (Волосы, Ресницы, Ногти и т.д.) в шаблоне ryba ведут на `/poisk-spetsialistov/16`, `/poisk-spetsialistov/10` и т.д. Документация компонента: **components/com_poisk/README.md**. План замены JSN: **jsn_adaptation/PLAN_REPLACEMENT_JSN.md**.

---

## 9.1. Компонент com_aktsii (поиск акций)

Страница «Поиск акций» (замена JSN stocklist): список мастеров с непустым полем `stock_prices`; в карточке выводится блок акций из JSON. Интерфейс как у com_poisk (карта, фильтры, сортировка, пагинация).

| Элемент | Описание |
|---------|----------|
| **Установка** | Скрипт **`migration_scripts/15_register_com_aktsii.php`**. |
| **Меню** | Пункт типа **com_aktsii** → Поиск акций, алиас **poisk-aktsij**. |
| **URL** | `/poisk-aktsij`, `/poisk-aktsij/16` (cat_id в сегменте). |
| **Данные** | ListModel фильтрует по EXISTS по полю stock_prices; карточка — разбор JSON акций (цена, осталось, описание). |

Документация: **components/com_aktsii/README.md**.

---

## 10. Документация и скрипты

| Файл | Назначение |
|------|------------|
| **MIGRATION_CHECKLIST.md** | Полный чеклист миграции с путями, особенностями, порядком работ, рисками по таблицам БД, блоком анализа неперенесённых таблиц и разделом миграции JSN. |
| **THIRD_PARTY_EXTENSIONS_J6.md** | Список сторонних расширений, ссылки на загрузку, совместимость с J6, инструкция по установке. |
| **JSN_MIGRATION_RSFORM.md** | Варианты миграции JSN (Custom Fields, RSForm! Pro), анализ joomla_jsn_users. |
| **migration_scripts/** | 01_list_tables, 02_compare_table_structure, 03_migrate_users, 04_migrate_all_tables, 05_restore_admin_from_backup, **06_jsn_users_to_custom_fields**, **07_dedupe_fields_values**, **14_register_com_poisk**, **15_register_com_aktsii**, load_j6_config. |
| **analyze/** | config_vigl1.php (доступы к vigl1), analyze_old_db.php, analyze_jsn_users.php (анализ joomla_jsn_users). |
| **plugins/user/vigling/** | Плагин **User — Vigling**: вкладка «JSN (расшифровка)» в форме пользователя, расшифровка полей prices, work_day, vyberite_spetsialnos, stock_prices; нормализация массивов во вкладке «Поля». См. plugins/user/vigling/README.md. |
| **components/com_poisk/** | Компонент **Поиск специалистов**: список по #__users + Custom Fields, SEF /poisk-spetsialistov/ID и /zatochka-remont/ID, настройки пункта меню «Ветка категорий» (все услуги / Заточка ремонт), сортировка, форма фильтра с Chosen, пагинация, карта Yandex. См. components/com_poisk/README.md. |
| **components/com_aktsii/** | Компонент **Поиск акций**: список мастеров с полем stock_prices, SEF /poisk-aktsij/ID, интерфейс как у com_poisk. См. components/com_aktsii/README.md. |
| **templates/ryba/html/layouts/joomla/pagination/** | Оверрайды пагинации: list.php (pagination__wrap, ul.pagination), link.php (icon-first/previous/next/last, active). |

---

## 11. Не сделано / на усмотрение

- Перенос **language/overrides/** — только если в J3 использовались переопределения строк; при использовании только русского пакета в J6 не обязателен.
- Установка J6-совместимых расширений (JCE, Akeeba, JL Sitemap, JSN, EasyBook и т.д.) и перенос их настроек и данных (JSN, EasyBook) — по **THIRD_PARTY_EXTENSIONS_J6.md**.
- Полная проверка фронта и админки J6 (страницы, формы, авторизация, редиректы).

---

## 12. Риски и что делать при проблемах

| Таблица/ситуация | Риск | Действие |
|------------------|------|----------|
| **joomla_extensions** | В J6 подставлены 261 запись из J3; возможны конфликты с ядром J6. | При ошибках расширений — восстановить из бэкапа J6 только системные записи; из J3 оставить сторонние (JSN, JCE и т.п.). |
| **joomla_schemas** | Версии схемы J3 и J6 различаются. | При проблемах с обновлениями J6 — восстановить таблицу из бэкапа J6. |
| **joomla_template_styles / joomla_modules (client_id=1)** | Пустая админка после переноса. | Выполнить **05_restore_admin_from_backup.php** (из дампа или из чистой J6, см. выше). |
