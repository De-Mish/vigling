<?php
/**
 * Регистрирует плагин System - Profile User ID в #__extensions.
 * Поддержка URL /lk?user_id=ID для просмотра карточки специалиста.
 * Запуск: php migration_scripts/18_register_plg_system_profileuserid.php
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

$manifestPath = $base . '/plugins/system/profileuserid/profileuserid.xml';
if (!is_file($manifestPath)) {
    die("Ошибка: не найден plugins/system/profileuserid/profileuserid.xml\n");
}

$xml = simplexml_load_file($manifestPath);
if (!$xml || $xml->getName() !== 'extension') {
    die("Ошибка: неверный manifest.\n");
}

$name = (string) ($xml->name ?? 'Profile User ID');
$version = (string) ($xml->version ?? '1.0.0');
$description = (string) ($xml->description ?? '');

$manifest_cache = json_encode([
    'name' => $name,
    'type' => 'plugin',
    'version' => $version,
    'description' => $description,
]);

$mysqli = new mysqli($cfg->host, $cfg->user, $cfg->password, $cfg->db);
if ($mysqli->connect_error) {
    die("Ошибка подключения к БД: " . $mysqli->connect_error . "\n");
}
$mysqli->set_charset('utf8mb4');

$prefix = $cfg->dbprefix;
$table = $prefix . 'extensions';

$exists = $mysqli->query("SELECT extension_id FROM `{$table}` WHERE type = 'plugin' AND element = 'profileuserid' AND folder = 'system' LIMIT 1");
if ($exists && $exists->fetch_object()) {
    $mysqli->query("UPDATE `{$table}` SET enabled = 1, manifest_cache = '" . $mysqli->real_escape_string($manifest_cache) . "' WHERE type = 'plugin' AND element = 'profileuserid' AND folder = 'system'");
    echo "Плагин System - Profile User ID уже в БД, включён (enabled=1).\n";
} else {
    $cols = "package_id, name, type, element, folder, client_id, enabled, access, protected, locked, manifest_cache, params, custom_data, ordering, state, changelogurl";
    $vals = "0, '" . $mysqli->real_escape_string($name) . "', 'plugin', 'profileuserid', 'system', 0, 1, 1, 0, 0, '" . $mysqli->real_escape_string($manifest_cache) . "', '{}', '', 0, 0, ''";
    if (!$mysqli->query("INSERT INTO `{$table}` ({$cols}) VALUES ({$vals})")) {
        die("Ошибка INSERT: " . $mysqli->error . "\n");
    }
    echo "Плагин System - Profile User ID зарегистрирован и включён.\n";
}
$mysqli->close();
