<?php
/**
 * CLI скрипт исследования структуры услуг для Joomla 4+
 * Запуск: php cli_research.php
 */

use Joomla\CMS\Factory;

// Инициализация Joomla
define('_JEXEC', 1);
define('JPATH_BASE', __DIR__);
define('DS', DIRECTORY_SEPARATOR);

error_reporting(E_ALL);
ini_set('display_errors', 0);

try {
    require_once JPATH_BASE . DS . 'includes' . DS . 'defines.php';
    require_once JPATH_BASE . DS . 'includes' . DS . 'framework.php';

    $db = Factory::getContainer()->get('DatabaseDriver');
    $prefix = $db->getPrefix();

    echo "=== ИССЛЕДОВАНИЕ СТРУКТУРЫ БД ===\n\n";

    // 1. Проверить field_id
    echo "1. ИСПОЛЬЗУЕМЫЕ FIELD_ID В #__fields_values:\n";
    $query = $db->getQuery(true)
        ->select('DISTINCT field_id')
        ->from($db->quoteName($prefix . 'fields_values'))
        ->order('field_id');
    $db->setQuery($query);
    $fieldIds = $db->loadColumn();
    echo "Field IDs: " . implode(', ', $fieldIds) . "\n\n";

    // 2. Проверить определения полей для услуг
    echo "2. ОПРЕДЕЛЕНИЯ ПОЛЕЙ ДЛЯ УСЛУГ:\n";
    $important_fields = [29, 57, 63, 64, 66, 67, 68, 69, 70];
    $missing = [];
    foreach ($important_fields as $fid) {
        if (!in_array($fid, $fieldIds)) {
            $missing[] = $fid;
        }
    }

    $query = $db->getQuery(true)
        ->select(['id', 'name', 'label', 'type'])
        ->from($db->quoteName($prefix . 'fields'))
        ->where('id IN (' . implode(',', $important_fields) . ')')
        ->order('id');
    $db->setQuery($query);
    $fields = $db->loadAssocList();

    foreach ($fields as $field) {
        printf("  ID %-3d: %-35s (name: %s, type: %s)\n",
            $field['id'],
            substr($field['label'], 0, 33),
            $field['name'],
            $field['type']
        );
    }

    if (!empty($missing)) {
        echo "\n⚠️  ОТСУТСТВУЮТ ПОЛЯ: " . implode(', ', $missing) . "\n";
    }
    echo "\n";

    // 3. Статистика по услугам
    echo "3. СТАТИСТИКА ПО УСЛУГАМ:\n";
    $query = $db->getQuery(true)
        ->select('COUNT(*) as total')
        ->from($db->quoteName($prefix . 'content'))
        ->where('catid IN (SELECT id FROM ' . $db->quoteName($prefix . 'categories') . ' WHERE path LIKE ' . $db->quote('uslugi/%') . ' OR path LIKE ' . $db->quote('zatochka-remont/%') . ')');
    $db->setQuery($query);
    $total = $db->loadResult();

    $query = $db->getQuery(true)
        ->select('COUNT(DISTINCT created_by) as masters')
        ->from($db->quoteName($prefix . 'content'))
        ->where('catid IN (SELECT id FROM ' . $db->quoteName($prefix . 'categories') . ' WHERE path LIKE ' . $db->quote('uslugi/%') . ' OR path LIKE ' . $db->quote('zatochka-remont/%') . ')');
    $db->setQuery($query);
    $masters = $db->loadResult();

    echo "  Всего услуг: " . $total . "\n";
    echo "  Уникальных мастеров: " . $masters . "\n\n";

    // 4. Категории услуг
    echo "4. СТРУКТУРА КАТЕГОРИЙ УСЛУГ:\n";
    $query = $db->getQuery(true)
        ->select(['id', 'title', 'path', 'level'])
        ->from($db->quoteName($prefix . 'categories'))
        ->where('path LIKE ' . $db->quote('uslugi/%') . ' OR path LIKE ' . $db->quote('zatochka-remont/%'))
        ->order(['path', 'level']);
    $db->setQuery($query);
    $categories = $db->loadAssocList();

    echo "  Всего категорий: " . count($categories) . "\n";
    foreach ($categories as $cat) {
        $indent = str_repeat("  ", $cat['level']);
        printf("%sID %-4d: %-35s (path: %s)\n",
            $indent,
            $cat['id'],
            substr($cat['title'], 0, 33),
            $cat['path']
        );
    }
    echo "\n";

    // 5. Пример услуги
    echo "5. ПРИМЕР УСЛУГИ С ДОП. ПОЛЯМИ:\n";
    $query = $db->getQuery(true)
        ->select('c.id, c.title, c.catid, c.created_by, c.state, c.created')
        ->from($db->quoteName($prefix . 'content', 'c'))
        ->where('c.catid IN (SELECT id FROM ' . $db->quoteName($prefix . 'categories') . ' WHERE path LIKE ' . $db->quote('uslugi/%') . ')')
        ->order('c.id')
        ->setLimit(1);
    $db->setQuery($query);
    $example = $db->loadAssoc();

    if ($example) {
        echo "  ID: " . $example['id'] . "\n";
        echo "  Название: " . $example['title'] . "\n";
        echo "  Категория (catid): " . $example['catid'] . "\n";
        echo "  Мастер (created_by): " . $example['created_by'] . "\n";
        echo "  Статус: " . ($example['state'] == 1 ? 'Опубликовано' : 'На модерации/Черновик') . "\n";
        echo "  Создано: " . $example['created'] . "\n";
        echo "\n  Доп. поля (#__fields_values):\n";

        $query = $db->getQuery(true)
            ->select(['field_id', 'value'])
            ->from($db->quoteName($prefix . 'fields_values'))
            ->where('item_id = ' . $example['id'])
            ->order('field_id');
        $db->setQuery($query);
        $fields_data = $db->loadAssocList();
        foreach ($fields_data as $field) {
            $val = strlen($field['value']) > 60 ? substr($field['value'], 0, 60) . '...' : $field['value'];
            printf("    field_id %-3d: %s\n", $field['field_id'], $val);
        }
    } else {
        echo "  ⚠️  Услуги не найдены!\n";
    }
    echo "\n";

    // 6. Мастера и их специальности
    echo "6. ПРИМЕРЫ МАСТЕРОВ И ИХ СПЕЦИАЛЬНОСТЕЙ (field_id=29):\n";
    $query = $db->getQuery(true)
        ->select('DISTINCT item_id')
        ->from($db->quoteName($prefix . 'fields_values'))
        ->where('field_id = 29')
        ->order('item_id')
        ->setLimit(5);
    $db->setQuery($query);
    $masters_list = $db->loadColumn();

    if (count($masters_list) > 0) {
        foreach ($masters_list as $master_id) {
            $query = $db->getQuery(true)
                ->select(['name', 'username'])
                ->from($db->quoteName($prefix . 'users'))
                ->where('id = ' . $master_id);
            $db->setQuery($query);
            $master = $db->loadAssoc();

            $query = $db->getQuery(true)
                ->select('GROUP_CONCAT(value)')
                ->from($db->quoteName($prefix . 'fields_values'))
                ->where('item_id = ' . $master_id . ' AND field_id = 29');
            $db->setQuery($query);
            $specs = $db->loadResult();

            printf("  Мастер ID %-3d (%s): специальности = %s\n",
                $master_id,
                $master['name'] ?? $master['username'],
                $specs
            );
        }
    } else {
        echo "  Мастера с заполненными специальностями не найдены\n";
    }
    echo "\n";

    // 7. Проверить таблицу #__jsn_users (если она есть)
    echo "7. ПРОВЕРКА СТАРОЙ ТАБЛИЦЫ #__jsn_users:\n";
    try {
        $query = $db->getQuery(true)
            ->select('1')
            ->from($db->quoteName($prefix . 'jsn_users'))
            ->setLimit(1);
        $db->setQuery($query);
        $db->loadResult();

        echo "  ✓ Таблица существует\n";

        $query = $db->getQuery(true)
            ->select('COUNT(*)')
            ->from($db->quoteName($prefix . 'jsn_users'));
        $db->setQuery($query);
        $count = $db->loadResult();
        echo "  Записей: " . $count . "\n";
    } catch (Exception $e) {
        echo "  ✗ Таблица не найдена\n";
    }
    echo "\n";

    echo "=== КОНЕЦ ИССЛЕДОВАНИЯ ===\n";

} catch (Exception $e) {
    echo "ОШИБКА: " . $e->getMessage() . "\n";
    if (method_exists($e, 'getFile')) {
        echo "File: " . $e->getFile() . " (line " . $e->getLine() . ")\n";
    }
    exit(1);
}
?>
