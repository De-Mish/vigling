<?php
/**
 * Добавляет колонку completed в #__jsn_orders (для мастеров: «запись выполнена»).
 * Запуск из корня сайта: php migration_scripts/35_jsn_orders_add_completed.php
 */
error_reporting(E_ALL);
ini_set('display_errors', '1');

$base = dirname(__DIR__);
require_once __DIR__ . '/load_j6_config.php';
$cfg = loadJ6Config($base . '/configuration.php');
if (!$cfg || empty($cfg->db)) {
    die("Ошибка: не найден configuration.php или нет доступа к БД.\n");
}

$mysqli = new mysqli($cfg->host, $cfg->user, $cfg->password, $cfg->db);
if ($mysqli->connect_error) {
    die("Ошибка подключения к БД: " . $mysqli->connect_error . "\n");
}
$mysqli->set_charset('utf8mb4');

$tbl = $mysqli->real_escape_string($cfg->dbprefix . 'jsn_orders');
$res = $mysqli->query("SHOW COLUMNS FROM `{$tbl}` LIKE 'completed'");
if ($res && $res->num_rows > 0) {
    echo "Колонка completed уже есть в {$tbl}.\n";
    $mysqli->close();
    exit(0);
}

if (!$mysqli->query("ALTER TABLE `{$tbl}` ADD COLUMN `completed` TINYINT(1) NOT NULL DEFAULT 0 AFTER `service_name`")) {
    die("Ошибка ALTER TABLE: " . $mysqli->error . "\n");
}
echo "Колонка completed добавлена в {$tbl}.\n";
$mysqli->close();
