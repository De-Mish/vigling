<?php
/**
 * Добавляет пункт «Услуги» в меню «Компоненты» админки (таблица #__menu, menutype=main).
 * Запуск после 20: php migration_scripts/21_add_viglingservices_menu_item.php
 */
error_reporting(E_ALL);
ini_set('display_errors', '1');

$base = dirname(__DIR__);
require_once __DIR__ . '/load_j6_config.php';
$cfg = loadJ6Config($base . '/configuration.php');
if (!$cfg || empty($cfg->db)) {
    die("Ошибка: configuration.php или БД.\n");
}

$mysqli = new mysqli($cfg->host, $cfg->user, $cfg->password, $cfg->db);
if ($mysqli->connect_error) {
    die("Ошибка подключения: " . $mysqli->connect_error . "\n");
}
$mysqli->set_charset('utf8mb4');
$prefix = $cfg->dbprefix;

$ext = $mysqli->query("SELECT extension_id FROM `{$prefix}extensions` WHERE type='component' AND element='com_viglingservices' LIMIT 1");
if (!$ext || !($e = $ext->fetch_object())) {
    die("Сначала выполните 20_register_com_viglingservices.php\n");
}
$extId = (int) $e->extension_id;

$menuTable = $prefix . 'menu';
$has = $mysqli->query("SELECT id FROM `{$menuTable}` WHERE menutype='main' AND client_id=1 AND component_id=" . $extId . " LIMIT 1");
if ($has && $has->fetch_object()) {
    echo "Пункт меню уже есть.\n";
    $mysqli->close();
    exit(0);
}

$last = $mysqli->query("SELECT rgt FROM `{$menuTable}` WHERE menutype='main' AND client_id=1 AND parent_id='1' ORDER BY rgt DESC LIMIT 1");
if (!$last || !($r = $last->fetch_object())) {
    $root = $mysqli->query("SELECT rgt FROM `{$menuTable}` WHERE menutype='main' AND client_id=1 AND id=1 LIMIT 1");
    $r = $root ? $root->fetch_object() : null;
}
if (!$r) {
    die("Меню main (client_id=1) не найдено. Добавьте пункт вручную: Меню → создать → Компонент → Услуги.\n");
}

$parentId = 1;
$R = (int) $r->rgt;
$mysqli->query("UPDATE `{$menuTable}` SET rgt=rgt+2 WHERE menutype='main' AND client_id=1 AND rgt>=" . $R);
$mysqli->query("UPDATE `{$menuTable}` SET lft=lft+2 WHERE menutype='main' AND client_id=1 AND lft>" . $R);

$title = $mysqli->real_escape_string('COM_VIGLINGSERVICES');
$link = $mysqli->real_escape_string('index.php?option=com_viglingservices');
$alias = $mysqli->real_escape_string('com-viglingservices');
$img = $mysqli->real_escape_string('class:list');
$q = "INSERT INTO `{$menuTable}` (menutype, title, alias, path, link, type, published, parent_id, level, component_id, checked_out, checked_out_time, browserNav, access, img, template_style_id, params, lft, rgt, home, language, client_id) VALUES ('main', '{$title}', '{$alias}', '{$alias}', '{$link}', 'component', 1, {$parentId}, 2, {$extId}, 0, '1970-01-01 00:00:00', 0, 1, '{$img}', 0, '{}', {$R}, " . ($R + 1) . ", 0, '*', 1)";
if ($mysqli->query($q)) {
    echo "Пункт «Услуги» добавлен в меню «Компоненты». Обновите страницу админки.\n";
} else {
    echo "Ошибка: " . $mysqli->error . "\n";
}
$mysqli->close();
