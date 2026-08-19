<?php
/**
 * Скрипт сохранения услуги (AJAX)
 * Joomla 4+ совместимый
 */

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Filter\OutputFilter;

define('_JEXEC', 1);
define('JPATH_BASE', $_SERVER['DOCUMENT_ROOT']);
define('DS', DIRECTORY_SEPARATOR);

require_once JPATH_BASE . DS . 'includes' . DS . 'defines.php';
require_once JPATH_BASE . DS . 'includes' . DS . 'framework.php';

try {
    $db = Factory::getContainer()->get('DatabaseDriver');
    $prefix = $db->getPrefix();

    // Подготовка данных
    $img_arr = [
        'image_intro' => '',
        'float_intro' => '',
        'image_intro_alt' => '',
        'image_intro_caption' => '',
        'image_fulltext' => '',
        'float_fulltext' => '',
        'image_fulltext_alt' => '',
        'image_fulltext_caption' => ''
    ];

    // Получить POST параметры с очисткой
    $category = isset($_POST['category']) ? (int)$_POST['category'] : 0;
    $user_id = isset($_POST['user_id']) ? (int)$_POST['user_id'] : 0;
    $name = isset($_POST['name']) ? trim($_POST['name']) : '';
    $specialisation = isset($_POST['specialisation']) ? (int)$_POST['specialisation'] : 0;
    $type = isset($_POST['type']) ? trim($_POST['type']) : '';
    $duration = isset($_POST['duration']) ? trim($_POST['duration']) : '';
    $pause = isset($_POST['pause']) ? trim($_POST['pause']) : '';
    $fix = isset($_POST['fix']) ? (int)$_POST['fix'] : 0;
    $cost = isset($_POST['cost']) ? trim($_POST['cost']) : '';
    $foto_service = isset($_POST['foto_service']) ? trim($_POST['foto_service']) : '';
    $metod = isset($_POST['metod']) ? (array)$_POST['metod'] : [];

    // Валидация обязательных полей
    if (empty($name) || empty($category) || empty($user_id)) {
        echo '<div class="message"><h2>Ошибка</h2><p>Не заполнены обязательные поля</p></div>';
        exit;
    }

    // Подготовка данных для сохранения в #__content
    $data = [
        'catid' => $category,
        'created_by' => $user_id,
        'title' => $name,
        'alias' => translit($name),
        'introtext' => '',
        'fulltext' => '',
        'images' => json_encode($img_arr),
        'state' => 0,  // На модерации
        'urls' => '{"urla":false,"urlatext":"","targeta":"","urlb":false,"urlbtext":"","targetb":"","urlc":false,"urlctext":"","targetc":""}',
        'attribs' => json_encode([
            'show_title' => '',
            'link_titles' => '',
            'show_tags' => '',
            'show_intro' => '',
            'info_block_position' => '',
            'show_category' => '',
            'link_category' => '',
            'show_parent_category' => '',
            'link_parent_category' => '',
            'show_author' => '',
            'link_author' => '',
            'show_create_date' => '',
            'show_modify_date' => '',
            'show_publish_date' => '',
            'show_item_navigation' => '',
            'show_icons' => '',
            'show_print_icon' => '',
            'show_email_icon' => '',
            'show_vote' => '',
            'show_hits' => '',
            'show_noauth' => '',
            'urls_position' => '',
            'alternative_readmore' => '',
            'article_layout' => '',
            'show_publishing_options' => '',
            'show_article_options' => '',
            'show_urls_images_backend' => '',
            'show_urls_images_frontend' => ''
        ]),
        'metadata' => json_encode([
            'robots' => '',
            'author' => '',
            'rights' => '',
            'xreference' => ''
        ]),
        'language' => '*',
        'created' => date('Y-m-d H:i:s'),
        'metakey' => '',
        'access' => 1,
    ];

    // Сохранить в #__content
    $query = $db->getQuery(true)
        ->insert($db->quoteName($prefix . 'content'))
        ->columns([
            $db->quoteName('catid'),
            $db->quoteName('created_by'),
            $db->quoteName('title'),
            $db->quoteName('alias'),
            $db->quoteName('introtext'),
            $db->quoteName('fulltext'),
            $db->quoteName('state'),
            $db->quoteName('images'),
            $db->quoteName('urls'),
            $db->quoteName('attribs'),
            $db->quoteName('metadata'),
            $db->quoteName('language'),
            $db->quoteName('created'),
            $db->quoteName('modified'),
            $db->quoteName('metakey'),
            $db->quoteName('access'),
            $db->quoteName('hits')
        ])
        ->values(
            $category . ',' .
            $user_id . ',' .
            $db->quote($data['title']) . ',' .
            $db->quote($data['alias']) . ',' .
            $db->quote('') . ',' .
            $db->quote('') . ',' .
            '0,' .
            $db->quote($data['images']) . ',' .
            $db->quote($data['urls']) . ',' .
            $db->quote($data['attribs']) . ',' .
            $db->quote($data['metadata']) . ',' .
            $db->quote('*') . ',' .
            $db->quote(date('Y-m-d H:i:s')) . ',' .
            $db->quote(date('Y-m-d H:i:s')) . ',' .
            $db->quote('') . ',' .
            '1,' .
            '0'
        );

    $db->setQuery($query);
    $db->execute();

    // Получить ID созданной статьи
    $articleId = $db->insertid();

    if ($articleId) {
        // Сохранить доп. поля в #__fields_values
        $fields = [
            68 => $name,
            69 => $specialisation,
            57 => $type,
            66 => $duration,
            67 => $pause,
            64 => $fix,
            70 => $cost,
            63 => $foto_service
        ];

        // Методы/теги
        if (!empty($metod)) {
            foreach ($metod as $m) {
                $tmp = explode('|', $m);
                if (count($tmp) === 2) {
                    $fieldId = (int)$tmp[0];
                    $value = trim($tmp[1]);

                    $query = $db->getQuery(true)
                        ->insert($db->quoteName($prefix . 'fields_values'))
                        ->columns([$db->quoteName('field_id'), $db->quoteName('item_id'), $db->quoteName('value')])
                        ->values($fieldId . ', ' . $articleId . ', ' . $db->quote($value));

                    $db->setQuery($query);
                    $db->execute();
                }
            }
        }

        // Остальные поля
        foreach ($fields as $fieldId => $value) {
            if ($value !== null && $value !== '') {
                // Обработать pipe-separated values
                $pos = strpos($value, '|');
                if ($pos !== false) {
                    $tmp = explode('|', $value);
                    $value = isset($tmp[1]) ? trim($tmp[1]) : '';
                }

                if (!empty($value)) {
                    $query = $db->getQuery(true)
                        ->insert($db->quoteName($prefix . 'fields_values'))
                        ->columns([$db->quoteName('field_id'), $db->quoteName('item_id'), $db->quoteName('value')])
                        ->values($fieldId . ', ' . $articleId . ', ' . $db->quote($value));

                    $db->setQuery($query);
                    $db->execute();
                }
            }
        }

        echo '<div class="message"><h2>Услуга</h2><p>' . htmlspecialchars($name) . ' создана</p><p>После проверки модератором она будет доступна</p></div>';
    } else {
        throw new Exception('Не удалось создать услугу в БД');
    }

} catch (Exception $e) {
    echo '<div class="message"><h2>Произошла ошибка</h2><p>' . htmlspecialchars($e->getMessage()) . '</p></div>';
    exit(1);
}

/**
 * Функция транслитерации (русский → латиница)
 */
function translit($s) {
    $s = (string)$s;
    $s = strip_tags($s);
    $s = str_replace(["\n", "\r"], " ", $s);
    $s = preg_replace("/\s+/", ' ', $s);
    $s = trim($s);
    $s = function_exists('mb_strtolower') ? mb_strtolower($s) : strtolower($s);
    $s = strtr($s, [
        'а' => 'a', 'б' => 'b', 'в' => 'v', 'г' => 'g', 'д' => 'd',
        'е' => 'e', 'ё' => 'e', 'ж' => 'j', 'з' => 'z', 'и' => 'i',
        'й' => 'y', 'к' => 'k', 'л' => 'l', 'м' => 'm', 'н' => 'n',
        'о' => 'o', 'п' => 'p', 'р' => 'r', 'с' => 's', 'т' => 't',
        'у' => 'u', 'ф' => 'f', 'х' => 'h', 'ц' => 'c', 'ч' => 'ch',
        'ш' => 'sh', 'щ' => 'shch', 'ы' => 'y', 'э' => 'e', 'ю' => 'yu',
        'я' => 'ya', 'ъ' => '', 'ь' => ''
    ]);
    $s = preg_replace("/[^0-9a-z-_. ]/i", "", $s);
    $s = str_replace(" ", "-", $s);
    return $s;
}

?>
