<?php
/**
 * Регистрирует плагин Ajax - Lkbooking в #__extensions (запись на приём с /lk?user_id=…).
 * Запуск из корня сайта: php migration_scripts/26_register_plg_ajax_lkbooking.php
 */
error_reporting(E_ALL);
ini_set('display_errors', '1');

$base = dirname(__DIR__);
require_once __DIR__ . '/load_j6_config.php';
$cfg = loadJ6Config($base . '/configuration.php');
if (!$cfg || empty($cfg->db)) {
    die("Ошибка: не найден configuration.php или нет доступа к БД.\n");
}

$manifestPath = $base . '/plugins/ajax/lkbooking/lkbooking.xml';
if (!is_file($manifestPath)) {
    die("Ошибка: не найден plugins/ajax/lkbooking/lkbooking.xml\n");
}

$xml = simplexml_load_file($manifestPath);
if (!$xml || $xml->getName() !== 'extension') {
    die("Ошибка: неверный manifest lkbooking.xml\n");
}

$manifest_details = [
    'name'         => (string) $xml->name,
    'type'         => (string) $xml->attributes()->type,
    'version'      => (string) $xml->version,
    'description'  => (string) $xml->description,
    'creationDate' => (string) $xml->creationDate ?: 'JLIB_UNKNOWN',
    'author'       => (string) $xml->author ?: 'JLIB_UNKNOWN',
    'group'        => (string) $xml->group,
    'filename'     => 'lkbooking',
];
if (isset($xml->namespace) && (string) $xml->namespace !== '') {
    $manifest_details['namespace'] = (string) $xml->namespace;
}

$name = $manifest_details['name'];
if (strpos($name, 'PLG_') === 0) {
    $iniPath = $base . '/plugins/ajax/lkbooking/language/ru-RU/plg_ajax_lkbooking.sys.ini';
    if (is_file($iniPath)) {
        $ini = parse_ini_file($iniPath);
        if (!empty($ini[$name])) {
            $name = $ini[$name];
        }
    }
}

$mysqli = new mysqli($cfg->host, $cfg->user, $cfg->password, $cfg->db);
if ($mysqli->connect_error) {
    die("Ошибка подключения к БД: " . $mysqli->connect_error . "\n");
}
$mysqli->set_charset('utf8mb4');

$prefix = $cfg->dbprefix;
$table  = $prefix . 'extensions';

$exists = $mysqli->query("SELECT extension_id FROM `{$table}` WHERE type = 'plugin' AND element = 'lkbooking' AND folder = 'ajax' LIMIT 1");
if ($exists && $exists->fetch_object()) {
    echo "Плагин Ajax - Lkbooking уже зарегистрирован в БД.\n";
    $mysqli->close();
    exit(0);
}

$manifest_cache = $mysqli->real_escape_string(json_encode($manifest_details));
$name_esc       = $mysqli->real_escape_string($name);

$sql = "INSERT INTO `{$table}` (package_id, name, type, element, folder, client_id, enabled, access, protected, locked, manifest_cache, params, custom_data, ordering, state, changelogurl)
        VALUES (0, '{$name_esc}', 'plugin', 'lkbooking', 'ajax', 0, 1, 1, 0, 0, '{$manifest_cache}', '{}', '', 0, 0, '')";

if (!$mysqli->query($sql)) {
    die("Ошибка INSERT: " . $mysqli->error . "\n");
}

$autoloadCache = $base . '/administrator/cache/autoload_psr4.php';
if (is_file($autoloadCache) && @unlink($autoloadCache)) {
    echo "Кэш PSR-4 удалён.\n";
}

echo "Плагин Ajax - Lkbooking зарегистрирован в БД (enabled=1).\n";
echo "В админке: Расширения → Плагины, фильтр «Ajax» — включите «Запись на приём из ЛК» при необходимости.\n";
$mysqli->close();
