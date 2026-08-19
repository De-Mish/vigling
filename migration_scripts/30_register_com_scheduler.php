<?php
/**
 * Регистрирует компонент com_scheduler (Запланированные задачи) в #__extensions.
 * Нужно, если после миграции компонент не отображается и даёт 404.
 * Запуск из корня сайта: php migration_scripts/30_register_com_scheduler.php
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

$manifestPath = $base . '/administrator/components/com_scheduler/scheduler.xml';
if (!is_file($manifestPath)) {
    die("Ошибка: не найден administrator/components/com_scheduler/scheduler.xml\n");
}

$xml = simplexml_load_file($manifestPath);
if (!$xml || $xml->getName() !== 'extension') {
    die("Ошибка: неверный manifest scheduler.xml\n");
}

$name = (string) $xml->name;
$version = (string) $xml->version;
$description = (string) $xml->description;
$namespace = isset($xml->namespace) ? (string) $xml->namespace : '';

$manifest_cache = [
    'name'         => $name,
    'type'         => 'component',
    'version'      => $version,
    'description'  => $description,
    'creationDate' => (string) ($xml->creationDate ?? ''),
    'author'       => (string) ($xml->author ?? ''),
];
if ($namespace !== '') {
    $manifest_cache['namespace'] = $namespace;
}

$mysqli = new mysqli($cfg->host, $cfg->user, $cfg->password, $cfg->db);
if ($mysqli->connect_error) {
    die("Ошибка подключения к БД: " . $mysqli->connect_error . "\n");
}
$mysqli->set_charset('utf8mb4');

$prefix = $cfg->dbprefix;
$table  = $prefix . 'extensions';

$exists = $mysqli->query("SELECT extension_id FROM `{$table}` WHERE type = 'component' AND element = 'com_scheduler' LIMIT 1");
if ($exists && $exists->fetch_object()) {
    echo "Компонент com_scheduler уже зарегистрирован в БД.\n";
    $mysqli->close();
    exit(0);
}

$manifest_json = $mysqli->real_escape_string(json_encode($manifest_cache));
$name_esc      = $mysqli->real_escape_string($name);
$cols = "package_id, name, type, element, folder, client_id, enabled, access, protected, locked, manifest_cache, params, custom_data, ordering, state, changelogurl";
$vals = "0, '{$name_esc}', 'component', 'com_scheduler', '', 1, 1, 1, 0, 0, '{$manifest_json}', '{}', '', 0, 0, ''";
if (!$mysqli->query("INSERT INTO `{$table}` ({$cols}) VALUES ({$vals})")) {
    die("Ошибка INSERT: " . $mysqli->error . "\n");
}

echo "Компонент com_scheduler (Запланированные задачи) зарегистрирован в БД.\n";
echo "Откройте: administrator/index.php?option=com_scheduler&view=tasks\n";
$mysqli->close();
