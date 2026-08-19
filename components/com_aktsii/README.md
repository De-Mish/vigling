# com_aktsii — компонент «Поиск акций»

Компонент Joomla 6 для вывода списка мастеров с акциями. Данные из `#__users` и Custom Fields; в списке только пользователи с непустым полем `stock_prices`. Интерфейс аналогичен com_poisk (карта, фильтры, карточки, пагинация).

---

## Установка и первый запуск

1. **Регистрация в БД и автозагрузка** (из корня сайта):
   ```bash
   php migration_scripts/15_register_com_aktsii.php
   ```

2. **Пункт меню**
   - Меню → нужное меню → Создать.
   - Тип: **Компонент** → **com_aktsii** → **Поиск акций**.
   - Алиас: **poisk-aktsij** (или другой).
   - Опубликовать.

3. **Ссылки**
   - Список: `https://site.ru/poisk-aktsij`
   - По категории: `https://site.ru/poisk-aktsij/16`

---

## Структура и отличия от com_poisk

- **Модель ListModel:** фильтр по наличию непустого `stock_prices` (EXISTS по `#__fields_values`). Остальные фильтры (cat_id, city, area, home) и сортировка — как в com_poisk.
- **Helper:** `AktsiiHelper::getCategories()`, `getFieldsForUserIds()` (в т.ч. поле `stock_prices`), `getCities()`, `getAreas()`.
- **Шаблоны:** `tmpl/list/default.php` (класс `jsn_stockList`, заголовок «Акции», «Фильтр акций», «Акции не найдены»), `default_item.php` — карточка мастера плюс блок «Акции» с разбором JSON `stock_prices` (цена, осталось предложений, описание).
- **Роутер:** SEF сегмент после алиаса → `cat_id` (как в com_poisk).
- **Layout metadata:** `tmpl/list/default.xml` — для появления типа пункта меню в выборе «Тип пункта меню».

---

## Связанные файлы

- Регистрация: `migration_scripts/15_register_com_aktsii.php`.
- Поиск специалистов (аналог): `components/com_poisk/README.md`.
