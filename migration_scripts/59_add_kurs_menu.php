<?php
/**
 * Добавляет фронтовый пункт меню «Курсы» по пути /kurs.
 * Запуск после 58: php migration_scripts/59_add_kurs_menu.php
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

$mysqli = new mysqli($cfg->host, $cfg->user, $cfg->password, $cfg->db);
if ($mysqli->connect_error) {
    die("Ошибка подключения: " . $mysqli->connect_error . "\n");
}
$mysqli->set_charset('utf8mb4');
$prefix = $cfg->dbprefix;

$ext = $mysqli->query("SELECT extension_id FROM `{$prefix}extensions` WHERE type='component' AND element='com_kurs' LIMIT 1");
if (!$ext || !($e = $ext->fetch_object())) {
    die("Сначала выполните 58_register_com_kurs.php\n");
}
$componentId = (int) $e->extension_id;

$menuTable = $prefix . 'menu';
$has = $mysqli->query("SELECT id FROM `{$menuTable}` WHERE client_id=0 AND alias='kurs' LIMIT 1");
if ($has && $has->fetch_object()) {
    echo "Пункт меню kurs уже есть.\n";
    $mysqli->close();
    exit(0);
}

$menutype = 'top-menu';
$rootId = 1;
$level = 1;
$title = $mysqli->real_escape_string('Курсы');
$alias = $mysqli->real_escape_string('kurs');
$path = $alias;
$link = $mysqli->real_escape_string('index.php?option=com_kurs&view=list');

$lftRgt = $mysqli->query("SELECT COALESCE(MAX(rgt), 0) + 1 AS nlft FROM `{$menuTable}` WHERE client_id=0 AND menutype='{$menutype}'");
$n = $lftRgt && ($lr = $lftRgt->fetch_object()) ? (int) $lr->nlft : 1;
$rgt = $n + 1;

$query = "INSERT INTO `{$menuTable}` (menutype, title, alias, path, link, type, published, parent_id, level, component_id, checked_out, checked_out_time, browserNav, access, img, template_style_id, params, lft, rgt, home, language, client_id) VALUES ('{$menutype}', '{$title}', '{$alias}', '{$path}', '{$link}', 'component', 1, {$rootId}, {$level}, {$componentId}, 0, '1970-01-01 00:00:00', 0, 1, '', 0, '{}', {$n}, {$rgt}, 0, '*', 0)";
if ($mysqli->query($query)) {
    echo "Пункт меню «Курсы» добавлен. URL: /kurs\n";
} else {
    echo "Ошибка: " . $mysqli->error . "\n";
}

$mysqli->close();
