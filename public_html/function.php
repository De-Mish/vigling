<?php
/**
 * API функции для формы добавления/редактирования услуг
 * Endpoints: getCategory, getMetod, getTypeMaster
 */

use Joomla\CMS\Factory;

define('_JEXEC', 1);
define('JPATH_BASE', __DIR__);
define('DS', DIRECTORY_SEPARATOR);

try {
    require_once JPATH_BASE . DS . 'includes' . DS . 'defines.php';
    require_once JPATH_BASE . DS . 'includes' . DS . 'framework.php';

    $db = Factory::getContainer()->get('DatabaseDriver');
    $prefix = $db->getPrefix();
    $action = isset($_POST['action']) ? trim($_POST['action']) : '';
    $param = isset($_POST['param']) ? (int)$_POST['param'] : 0;

    if (!$action) {
        http_response_code(400);
        echo 'Не указано действие';
        exit;
    }

    switch ($action) {
        case 'getCategory':
            getCategory($db, $prefix, $param);
            break;

        case 'getMetod':
            getMetod($db, $prefix, $param);
            break;

        case 'getTypeMaster':
            getTypeMaster($db, $prefix);
            break;

        default:
            http_response_code(400);
            echo 'Неизвестное действие: ' . htmlspecialchars($action);
    }

} catch (Exception $e) {
    http_response_code(500);
    echo 'Ошибка: ' . htmlspecialchars($e->getMessage());
    exit;
}

/**
 * Получить дочерние категории для выбранной специализации
 * @param $db DatabaseDriver
 * @param $prefix Префикс БД
 * @param $parentId ID родительской категории
 */
function getCategory($db, $prefix, $parentId) {
    if (empty($parentId)) {
        echo '<option value="0">Выберите категорию...</option>';
        return;
    }

    $query = $db->getQuery(true)
        ->select(['id', 'title'])
        ->from($db->quoteName($prefix . 'categories'))
        ->where('parent_id = ' . $parentId)
        ->where('published = 1')
        ->order('title ASC');

    $db->setQuery($query);
    $categories = $db->loadAssocList();

    echo '<option value="0">Выберите категорию...</option>';
    if (!empty($categories)) {
        foreach ($categories as $cat) {
            printf(
                '<option value="%d">%s</option>',
                $cat['id'],
                htmlspecialchars($cat['title'])
            );
        }
    } else {
        echo '<option value="0" disabled>Подкатегории не найдены</option>';
    }
}

/**
 * Получить методы/теги для выбранной категории
 * @param $db DatabaseDriver
 * @param $prefix Префикс БД
 * @param $categoryId ID категории
 */
function getMetod($db, $prefix, $categoryId) {
    if (empty($categoryId)) {
        echo '<option value="0">Выберите метод...</option>';
        return;
    }

    // Подзапрос для дочерних категорий
    $subQuery = $db->getQuery(true)
        ->select($db->quoteName('id'))
        ->from($db->quoteName($prefix . 'categories'))
        ->where($db->quoteName('parent_id') . ' = ' . (int)$categoryId);

    // Получить все статьи в этой категории и её дочерних категориях
    $query = $db->getQuery(true)
        ->select('DISTINCT ' . $db->quoteName('fv') . '.' . $db->quoteName('field_id') . ', ' . $db->quoteName('fv') . '.' . $db->quoteName('value'))
        ->from($db->quoteName($prefix . 'fields_values', 'fv'))
        ->innerJoin($db->quoteName($prefix . 'content', 'c') . ' ON ' . $db->quoteName('fv') . '.' . $db->quoteName('item_id') . ' = ' . $db->quoteName('c') . '.' . $db->quoteName('id'))
        ->where('(' . $db->quoteName('c') . '.' . $db->quoteName('catid') . ' = ' . (int)$categoryId . ' OR ' . $db->quoteName('c') . '.' . $db->quoteName('catid') . ' IN (' . $subQuery . '))')
        ->where($db->quoteName('fv') . '.' . $db->quoteName('field_id') . ' = 56')  // field_id для методов
        ->order($db->quoteName('fv') . '.' . $db->quoteName('value') . ' ASC');

    $db->setQuery($query);
    $metods = $db->loadAssocList();

    echo '<option value="0">Выберите метод...</option>';
    if (!empty($metods)) {
        foreach ($metods as $metod) {
            printf(
                '<option value="56|%s">%s</option>',
                htmlspecialchars($metod['value']),
                htmlspecialchars($metod['value'])
            );
        }
    } else {
        echo '<option value="0" disabled>Методы не найдены</option>';
    }
}

/**
 * Получить типы мастеров (для field_id=57)
 * @param $db DatabaseDriver
 * @param $prefix Префикс БД
 */
function getTypeMaster($db, $prefix) {
    // Получить уникальные значения для field_id=57
    $query = $db->getQuery(true)
        ->select('DISTINCT value')
        ->from($db->quoteName($prefix . 'fields_values'))
        ->where('field_id = 57')
        ->order('value ASC');

    $db->setQuery($query);
    $types = $db->loadColumn();

    $html = '<select class="form-control spec_4" name="type" data-placeholder="Выбрать..." required>';
    $html .= '<option value="0">Выберите тип...</option>';

    if (!empty($types)) {
        foreach ($types as $type) {
            if (!empty($type)) {
                $html .= '<option value="' . htmlspecialchars($type) . '">' . htmlspecialchars($type) . '</option>';
            }
        }
    }

    $html .= '</select>';
    echo $html;
}

?>
