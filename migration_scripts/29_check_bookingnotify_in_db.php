<?php
/**
 * Проверяет, есть ли плагин Task - Booking Notify в #__extensions.
 * Запуск: php migration_scripts/29_check_bookingnotify_in_db.php
 */
$base = dirname(__DIR__);
require_once __DIR__ . '/load_j6_config.php';
$cfg = loadJ6Config($base . '/configuration.php');
if (!$cfg || empty($cfg->db)) {
    die("Ошибка: не найден configuration.php.\n");
}
$mysqli = new mysqli($cfg->host, $cfg->user, $cfg->password, $cfg->db);
if ($mysqli->connect_error) {
    die("Ошибка подключения: " . $mysqli->connect_error . "\n");
}
$mysqli->set_charset('utf8mb4');
$prefix = $cfg->dbprefix;
$table  = $prefix . 'extensions';
$row = $mysqli->query("SELECT extension_id, name, type, element, folder, enabled, state FROM `{$table}` WHERE type = 'plugin' AND element = 'bookingnotify' AND folder = 'task' LIMIT 1")->fetch_object();
if (!$row) {
    echo "Плагин НЕ найден в БД. Запустите: php migration_scripts/29_register_plg_task_bookingnotify.php\n";
    $mysqli->close();
    exit(1);
}
echo "Плагин найден в БД:\n  extension_id: {$row->extension_id}, name: {$row->name}, enabled: {$row->enabled}, state: {$row->state}\n";
echo "\nПрямая ссылка на редактирование плагина (подставьте свой домен вместо ВАШ_ДОМЕН):\n  https://ВАШ_ДОМЕН/administrator/index.php?option=com_plugins&task=plugin.edit&extension_id=" . (int) $row->extension_id . "\n";
$mysqli->close();
