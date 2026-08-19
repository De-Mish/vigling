<?php
/**
 * Скрипт исследования структуры услуг и миграции
 * Запустить: php migration_research.php
 */

// Подключение к Joomla
define( '_JEXEC', 1 );
define('JPATH_BASE', __DIR__ . '/public_html');
define('DS', DIRECTORY_SEPARATOR);

require_once JPATH_BASE . DS . 'includes' . DS . 'defines.php';
require_once JPATH_BASE . DS . 'includes' . DS . 'framework.php';

$app = JFactory::getApplication('site');
$db = JFactory::getDbo();
$prefix = $db->getPrefix();

echo "=== ИССЛЕДОВАНИЕ СТРУКТУРЫ БД ===\n\n";

// 1. Проверить существующие таблицы
echo "1. Существующие таблицы:\n";
$query = "SHOW TABLES LIKE '" . str_replace('_', '\\_', $prefix) . "%'";
$db->setQuery($query);
$tables = $db->loadColumn();
echo "Всего таблиц: " . count($tables) . "\n";
echo "Таблицы, связанные с услугами:\n";
foreach ($tables as $table) {
    if (strpos($table, 'service') !== false || strpos($table, 'content') !== false ||
        strpos($table, 'category') !== false || strpos($table, 'field') !== false) {
        echo "  - $table\n";
    }
}

// 2. Проверить, какие field_id используются для услуг
echo "\n2. Используемые field_id в #__fields_values:\n";
$query = "SELECT DISTINCT field_id FROM {$prefix}fields_values ORDER BY field_id";
$db->setQuery($query);
$fieldIds = $db->loadColumn();
echo "Field IDs: " . implode(', ', $fieldIds) . "\n";

// 3. Проверить существующие поля
echo "\n3. Определения полей (#__fields):\n";
$query = "SELECT id, name, label, type FROM {$prefix}fields ORDER BY id";
$db->setQuery($query);
$fields = $db->loadAssocList();
foreach ($fields as $field) {
    if (in_array($field['id'], [29, 57, 63, 64, 66, 67, 68, 69, 70])) {
        echo "  ID {$field['id']}: {$field['label']} ({$field['name']}, type={$field['type']})\n";
    }
}

// 4. Статистика по услугам (если они хранятся в #__content)
echo "\n4. Статистика по услугам в #__content:\n";
$query = "SELECT COUNT(*) as total, COUNT(DISTINCT created_by) as masters FROM {$prefix}content WHERE catid IN (SELECT id FROM {$prefix}categories WHERE path LIKE 'uslugi/%' OR path LIKE 'zatochka-remont/%')";
$db->setQuery($query);
$stats = $db->loadAssoc();
echo "  Услуг: " . $stats['total'] . "\n";
echo "  Уникальных мастеров: " . $stats['masters'] . "\n";

// 5. Проверить категории услуг
echo "\n5. Категории услуг (путь LIKE 'uslugi/%'):\n";
$query = "SELECT id, title, path, level FROM {$prefix}categories WHERE path LIKE 'uslugi/%' OR path LIKE 'zatochka-remont/%' ORDER BY path, level";
$db->setQuery($query);
$categories = $db->loadAssocList();
echo "  Всего: " . count($categories) . "\n";
foreach ($categories as $cat) {
    echo "    ID {$cat['id']}: {$cat['title']} (path={$cat['path']}, level={$cat['level']})\n";
}

// 6. Пример услуги со всеми полями
echo "\n6. Пример услуги:\n";
$query = "SELECT c.id, c.title, c.catid, c.created_by, c.state FROM {$prefix}content c
          WHERE c.catid IN (SELECT id FROM {$prefix}categories WHERE path LIKE 'uslugi/%')
          LIMIT 1";
$db->setQuery($query);
$example = $db->loadAssoc();
if ($example) {
    echo "  ID: {$example['id']}\n";
    echo "  Название: {$example['title']}\n";
    echo "  Категория: {$example['catid']}\n";
    echo "  Мастер: {$example['created_by']}\n";
    echo "  Статус: {$example['state']}\n";

    // Получить доп. поля этой услуги
    echo "  Доп. поля:\n";
    $query = "SELECT field_id, value FROM {$prefix}fields_values WHERE item_id = " . $example['id'];
    $db->setQuery($query);
    $fields = $db->loadAssocList();
    foreach ($fields as $field) {
        echo "    field_id {$field['field_id']}: {$field['value']}\n";
    }
}

// 7. Проверить таблицу #__jsn_users (старая система)
echo "\n7. Проверка таблицы #__jsn_users (для миграции профилей):\n";
$query = "SHOW TABLES LIKE '{$prefix}jsn_users'";
$db->setQuery($query);
if ($db->loadResult()) {
    echo "  Таблица существует\n";
    $query = "SELECT COUNT(*) FROM {$prefix}jsn_users";
    $db->setQuery($query);
    echo "  Записей: " . $db->loadResult() . "\n";
} else {
    echo "  Таблица не найдена\n";
}

echo "\n=== КОНЕЦ ИССЛЕДОВАНИЯ ===\n";
?>
