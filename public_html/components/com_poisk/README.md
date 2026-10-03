# com_poisk — компонент «Поиск специалистов»

Компонент Joomla 6 для вывода списка специалистов (мастеров) с фильтрами. Замена списка JSN; данные из `#__users` и Custom Fields (`#__fields`, `#__fields_values`). Интеграция с темой **ryba**: те же CSS-классы и разметка, что в `templates/ryba/html/com_jsn/list/`.

---

## Установка и первый запуск

1. **Регистрация в БД и автозагрузка** (из корня сайта):
   ```bash
   php migration_scripts/14_register_com_poisk.php
   ```
   Скрипт добавляет запись в `#__extensions` и при необходимости — namespace в `administrator/cache/autoload_psr4.php`.

2. **Пункт меню**
   - Меню → нужное меню → Создать.
   - Тип: **Компонент** → **Поиск специалистов (com_poisk)** → **Список**.
   - Алиас: **poisk-spetsialistov** (или любой, например **zatochka-remont** для страницы «Заточка ремонт»).
   - Вкладка **«Настройки списка (Поиск специалистов)»**: поле **«Ветка категорий»** — «Все (услуги)» для полного списка или «Заточка ремонт» для фильтра по ветке zatochka-remont. Опционально: «Заголовок страницы», «Подпись блока фильтра».
   - Опубликовать.

3. **Ссылки**
   - Список без категории: `https://site.ru/poisk-spetsialistov`
   - Список по категории (например ID 16): `https://site.ru/poisk-spetsialistov/16`
   - Страница «Заточка ремонт» (отдельный пункт меню с веткой «Заточка ремонт»): `https://site.ru/zatochka-remont`, `https://site.ru/zatochka-remont/16`

Ссылки с главной («Волосы», «Ресницы», «Ногти» и т.д.) в шаблоне ryba ведут на `/poisk-spetsialistov/16`, `/poisk-spetsialistov/10` и т.д.

---

## Структура файлов

| Путь | Назначение |
|------|------------|
| **Сайт** | |
| `components/com_poisk/src/Controller/DisplayController.php` | Контроллер, default_view = list. |
| `components/com_poisk/src/Model/ListModel.php` | Модель списка: getItems, getTotal, buildListQuery, buildCountQuery, populateState. Без слияния state с меню (экономия памяти). |
| `components/com_poisk/src/View/List/HtmlView.php` | View: items, pagination, categories, pageTitle, filterTitle, fieldsByUser, cities, areas, listOrder, listDirn. Определение ветки категорий из пункта меню (query или getParams()). |
| `components/com_poisk/src/Helper/PoiskHelper.php` | getCategories($pathPrefix), getCategoryIdsByPathPrefix($pathPrefix), getFieldsForUserIds(), getCities(), getAreas(). |
| `components/com_poisk/src/Service/Router.php` | SEF: parse — сегмент после алиаса → cat_id; build — cat_id → сегмент. |
| `components/com_poisk/tmpl/list/default.php` | Layout: заголовок, «Результатов поиска — N», сортировка (Рекомендуемое/Рейтинг/Цена), форма фильтра с Chosen, карточки, пагинация, карта Yandex. |
| `components/com_poisk/tmpl/list/default_item.php` | Одна карточка специалиста (имя, о себе, адрес, форма работы, «Записаться»). |
| `components/com_poisk/tmpl/list/default.xml` | Метаданные layout и форма настроек пункта меню: «Ветка категорий», «Заголовок страницы», «Подпись блока фильтра». |
| **Админка** | |
| `administrator/components/com_poisk/poisk.xml` | Манифест. |
| `administrator/components/com_poisk/services/provider.php` | Регистрация MVCFactory, ComponentDispatcherFactory, RouterFactory, ComponentInterface. |
| `administrator/components/com_poisk/src/Extension/PoiskComponent.php` | Класс компонента: HTMLRegistryAwareTrait, RouterServiceTrait. |
| `administrator/components/com_poisk/src/Controller/DisplayController.php` | Редирект на сайт (option=com_poisk&view=list). |

---

## Модель (логика и память)

- **State** задаётся только из input (cat_id, city, area, home, filter_order, filter_order_Dir, list.limit, list.start). `parent::populateState()` не вызывается.
- **Запросы:** отдельно `buildCountQuery()` и `buildListQuery()`; оба используют `applyFiltersToQuery()`. Сортировка в `buildListQuery()` по `list.ordering` и `list.direction`: name (по умолчанию), rate (u.id DESC), price (u.name).
- **Критерий «мастер»:** пользователь считается мастером, если в Custom Fields есть хотя бы одно из: is_master=1, непустое vyberite_spetsialnos (и не `{}`), непустое sity, непустое telefon (EXISTS по `#__fields_values` + `#__fields`).
- **Фильтры:** при `category_path_prefix === 'zatochka-remont'` — только пользователи, у которых в vyberite_spetsialnos есть хотя бы один ID из ветки zatochka-remont; далее cat_id (vyberite_spetsialnos), city (sity), area (area), home[] (1/2/3 — Салон, Вызов, Мастер на дому).
- **Лимит:** 1–50 записей на страницу (по умолчанию 20).
- **Кэш:** getTotal и getItems кэшируются по getStoreId() в рамках одного запроса.

---

## Роутер

- **parse:** активный пункт меню — com_poisk, view=list; первый сегмент после алиаса — число → `vars['cat_id']`.
- **build:** при наличии пункта меню com_poisk list и query['cat_id'] в segments добавляется число, из query убираются view и cat_id.

---

## Настройки пункта меню (ветка категорий)

- Режим страницы задаётся **только** настройками пункта меню (не алиасом и не URL).
- **Источник:** сначала `$menu->query['category_path']` (из ссылки), затем `$menu->getParams()->get('category_path')`. Параметр `MenuItem::$params` защищён — используется только `getParams()`.
- В форме пункта меню (вкладка «Настройки списка») при типе «com_poisk → Список» доступны поля из `tmpl/list/default.xml`: **Ветка категорий** (пусто = все услуги, `zatochka-remont` = только категории path LIKE 'zatochka-remont/%'), **Заголовок страницы**, **Подпись блока фильтра**.
- При непустой ветке модель фильтрует список: показываются только специалисты, у которых в `vyberite_spetsialnos` есть хотя бы одна категория из этой ветки (`PoiskHelper::getCategoryIdsByPathPrefix()`).

---

## View и данные для карточки

- **Категории:** `PoiskHelper::getCategories($pathPrefix)` — при `$pathPrefix === 'zatochka-remont'` выбираются категории level=2, path LIKE 'zatochka-remont/%'; иначе — parent_id=39 или path LIKE 'uslugi/%' или id IN (9,10,…,21).
- **Города и районы:** `PoiskHelper::getCities()` и `PoiskHelper::getAreas()` — уникальные значения из `#__fields_values` по полям sity и area (для селектов фильтра).
- **Поля по пользователям:** `PoiskHelper::getFieldsForUserIds($ids, [...])` — один запрос к `#__fields_values` + `#__fields`, результат по item_id.

В карточке выводятся: имя, о себе, адрес, форма работы, ссылка «Записаться».

---

## Стили и шаблон

- Классы из темы ryba: `category jsn_list`, `category__head`, `category__body`, `category__masters`, `category__masters-sidebar`, `category__item`, `category__content-info`, `pagination__wrap`, `sort`, `radioBox`, `filter`, `masters-sidebar__body`, `clearable` и т.д.
- **Сортировка:** в шапке форма с `ul.sort` и радиокнопками «Рекомендуемое», «Рейтинг», «Цена» (filter_order: name, rate, price); при смене форма отправляется с сохранением фильтров.
- **Форма фильтра:** селекты «Город», «Район», «Мастер» (категория), «Вид услуги» (мульти); подключены Chosen (CSS/JS из `templates/ryba/`), инициализация по `.category__masters-sidebar select` после DOMContentLoaded.
- **Пагинация:** вывод через `$pagination->getPagesLinks()`. Стили ryba применяются за счёт оверрайдов в `templates/ryba/html/layouts/joomla/pagination/list.php` и `link.php` (обёртка `pagination__wrap`, иконки icon-first/previous/next/last, класс active для текущей страницы).
- В `templates/ryba/index.php` для `option === 'com_poisk'` задаётся `$page = 'page'`.

---

## Параметры GET / state

| Параметр | Тип | Описание |
|----------|-----|----------|
| cat_id | int | ID категории, фильтр по vyberite_spetsialnos. |
| city | string | Фильтр по полю «Город» (sity). |
| area | string | Фильтр по полю «Район» (area). |
| home[] | int[] | 1=Салон, 2=Вызов на дом, 3=Мастер на дому. |
| filter_order | string | Сортировка: name (рекомендуемое), rate (рейтинг), price (цена). |
| filter_order_Dir | string | ASC или DESC. |
| limit | int | Записей на страницу (1–50). |
| limitstart | int | Смещение для пагинации. |

---

## Исправления при внедрении

| Проблема | Решение |
|----------|---------|
| Call to undefined method PoiskComponent::setRegistry() | В PoiskComponent добавлен use HTMLRegistryAwareTrait. |
| Cannot redeclare class ListModel | Импорт родителя: use ListModel as BaseListModel; class ListModel extends BaseListModel. |
| populateState() must be compatible with parent | Сигнатура: populateState($ordering = null, $direction = null). |
| 404 на /poisk-spetsialistov | Алиас пункта меню должен совпадать с ожидаемым (например **poisk-spetsialistov**), иначе не совпадает с ссылками с главной. |
| Cannot access protected property MenuItem::$params | Использовать только `$menu->getParams()`, не `$menu->params`. |

---

## Связанные файлы

- План замены JSN: `jsn_adaptation/PLAN_REPLACEMENT_JSN.md`.
- Регистрация: `migration_scripts/14_register_com_poisk.php`.
- Оверрайды пагинации ryba: `templates/ryba/html/layouts/joomla/pagination/list.php`, `link.php`.
- Модуль формы поиска (отдельно): `modules/mod_specialists/` (форма ведёт на specialists-list.php или на com_poisk при смене URL в настройках).
- Компонент «Поиск акций»: `components/com_aktsii/` (отдельная страница, аналог списка специалистов по полю stock_prices). См. `components/com_aktsii/README.md` при наличии.
