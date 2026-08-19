<?php
/**
 * Удаляет из БД компоненты, у которых нет папок (записи в #__extensions и пункты в #__menu).
 * Запуск: php migration_scripts/22_remove_orphan_components_from_db.php
 */
error_reporting(E_ALL);
ini_set('display_errors', '1');

$base = dirname(__DIR__);
if (!is_file($base . '/configuration.php')) {
    $base = dirname($base);
}
require_once __DIR__ . '/load_j6_config.php';
$cfg = loadJ6Config($base . '/configuration.php');
if (!$cfg || empty($cfg->db)) {
    die("Ошибка: configuration.php или БД.\n");
}

$componentsToRemove = [
    'com_akeeba',
    'com_jcrm',
    'com_jsn',
    'com_djimageslider',
    'com_easybookreloaded',
    'com_jce',
    'com_ajaxupload',
    'com_search',
];

$mysqli = new mysqli($cfg->host, $cfg->user, $cfg->password, $cfg->db);
if ($mysqli->connect_error) {
    die("Ошибка подключения: " . $mysqli->connect_error . "\n");
}
$mysqli->set_charset('utf8mb4');
$prefix = $cfg->dbprefix;
$extTable = $prefix . 'extensions';
$menuTable = $prefix . 'menu';

$escaped = [];
foreach ($componentsToRemove as $el) {
    $escaped[] = "'" . $mysqli->real_escape_string($el) . "'";
}
$inList = implode(',', $escaped);

$res = $mysqli->query("SELECT extension_id, element, name FROM `{$extTable}` WHERE type = 'component' AND element IN ({$inList})");
if (!$res) {
    die("Ошибка SELECT: " . $mysqli->error . "\n");
}

$ids = [];
$found = [];
while ($row = $res->fetch_object()) {
    $ids[] = (int) $row->extension_id;
    $found[] = $row->element . ' (' . $row->name . ')';
}

if (empty($ids)) {
    echo "В БД не найдено ни одного из перечисленных компонентов. Нечего удалять.\n";
    $mysqli->close();
    exit(0);
}

echo "Найдено в #__extensions: " . implode(', ', $found) . "\n";

$idList = implode(',', $ids);
$delMenu = $mysqli->query("DELETE FROM `{$menuTable}` WHERE component_id IN ({$idList})");
$menuCount = $mysqli->affected_rows;
if ($delMenu === false) {
    echo "Ошибка удаления из #__menu: " . $mysqli->error . "\n";
} else {
    echo "Удалено пунктов меню: {$menuCount}\n";
}

$delExt = $mysqli->query("DELETE FROM `{$extTable}` WHERE type = 'component' AND element IN ({$inList})");
$extCount = $mysqli->affected_rows;
if ($delExt === false) {
    echo "Ошибка удаления из #__extensions: " . $mysqli->error . "\n";
} else {
    echo "Удалено записей компонентов: {$extCount}\n";
}

$mysqli->close();
echo "Готово. Обновите страницу админки — пункты должны исчезнуть из меню «Компоненты».\n";
