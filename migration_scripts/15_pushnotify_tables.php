<?php
/**
 * Создаёт таблицы для push-уведомлений (#__pushnotify_*).
 * Запуск из корня сайта: php migration_scripts/15_pushnotify_tables.php
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
    die("Ошибка: не найден configuration.php или нет доступа к БД.\n");
}

$prefix = $cfg->dbprefix;
$sqlFile = dirname($base) . '/tz/sql/install.mysql.utf8.sql';
if (!is_file($sqlFile)) {
    $sqlFile = $base . '/tz/sql/install.mysql.utf8.sql';
}
if (!is_file($sqlFile)) {
    $sqlFile = $base . '/administrator/components/com_pushnotify/sql/install.mysql.utf8.sql';
}
if (!is_file($sqlFile)) {
    die("Не найден SQL-файл (tz/sql или administrator/components/com_pushnotify/sql).\n");
}
$sql = file_get_contents($sqlFile);
$sql = str_replace('#__', $prefix, $sql);

$mysqli = new mysqli($cfg->host, $cfg->user, $cfg->password, $cfg->db);
if ($mysqli->connect_error) {
    die("Ошибка подключения к БД: " . $mysqli->connect_error . "\n");
}
$mysqli->set_charset('utf8mb4');

foreach (array_filter(array_map('trim', explode(';', $sql))) as $query) {
    if ($query === '') continue;
    if (!$mysqli->query($query)) {
        die("Ошибка SQL: " . $mysqli->error . "\n" . $query . "\n");
    }
}
$mysqli->close();
echo "Таблицы pushnotify созданы (префикс: {$prefix}).\n";
