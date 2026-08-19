<?php
/**
 * Добавляет пункт меню «Записи» по пути /lk/orders (option=com_orders, view=orders).
 * Родитель: пункт с alias=lk (Личный кабинет). Если нет — создаётся как корневой.
 * Запуск после 27: php migration_scripts/28_add_lk_orders_menu.php
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

$ext = $mysqli->query("SELECT extension_id FROM `{$prefix}extensions` WHERE type='component' AND element='com_orders' LIMIT 1");
if (!$ext || !($e = $ext->fetch_object())) {
    die("Сначала выполните 27_register_com_orders.php\n");
}
$componentId = (int) $e->extension_id;

$menuTable = $prefix . 'menu';
$has = $mysqli->query("SELECT id FROM `{$menuTable}` WHERE client_id=0 AND component_id=" . $componentId . " LIMIT 1");
if ($has && $has->fetch_object()) {
    echo "Пункт меню com_orders уже есть.\n";
    $mysqli->close();
    exit(0);
}

$parentId = 1;
$path = 'orders';
$parentAlias = '';
$parentRow = $mysqli->query("SELECT id, path, alias FROM `{$menuTable}` WHERE client_id=0 AND (alias='lk' OR path='lk') LIMIT 1");
if ($parentRow && ($pr = $parentRow->fetch_object())) {
    $parentId = (int) $pr->id;
    $parentAlias = $pr->path ?: $pr->alias;
    $path = $parentAlias . '/orders';
}

$menutype = 'main';
$mt = $mysqli->query("SELECT menutype FROM `{$menuTable}` WHERE client_id=0 AND id=" . $parentId . " LIMIT 1");
if ($mt && ($m = $mt->fetch_object())) {
    $menutype = $mysqli->real_escape_string($m->menutype);
}

$title = $mysqli->real_escape_string('Записи');
$alias = $mysqli->real_escape_string('orders');
$pathEsc = $mysqli->real_escape_string($path);
$link = $mysqli->real_escape_string('index.php?option=com_orders&view=orders');
$level = $parentId === 1 ? 1 : 2;
$lftRgt = $mysqli->query("SELECT COALESCE(MAX(rgt), 0) + 1 AS nlft FROM `{$menuTable}` WHERE menutype='" . $menutype . "' AND client_id=0");
$n = $lftRgt && ($lr = $lftRgt->fetch_object()) ? (int) $lr->nlft : 1;
$rgt = $n + 1;

$q = "INSERT INTO `{$menuTable}` (menutype, title, alias, path, link, type, published, parent_id, level, component_id, checked_out, checked_out_time, browserNav, access, img, template_style_id, params, lft, rgt, home, language, client_id) VALUES ('{$menutype}', '{$title}', '{$alias}', '{$pathEsc}', '{$link}', 'component', 1, {$parentId}, {$level}, {$componentId}, 0, '1970-01-01 00:00:00', 0, 1, '', 0, '{}', {$n}, {$rgt}, 0, '*', 0)";
if ($mysqli->query($q)) {
    echo "Пункт меню «Записи» добавлен. URL: /" . $path . " (или по Itemid).\n";
} else {
    echo "Ошибка: " . $mysqli->error . "\n";
}
$mysqli->close();
