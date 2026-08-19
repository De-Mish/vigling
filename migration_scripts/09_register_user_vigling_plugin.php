<?php
/**
 * Регистрирует плагин User - Vigling в #__extensions (расшифровка полей JSN в форме пользователя).
 * Запуск из корня сайта: php migration_scripts/09_register_user_vigling_plugin.php
 */
error_reporting(E_ALL);
ini_set('display_errors', '1');

$base = dirname(__DIR__);
require_once __DIR__ . '/load_j6_config.php';
$cfg = loadJ6Config($base . '/configuration.php');
if (!$cfg || empty($cfg->db)) {
    die("Ошибка: не найден configuration.php или нет доступа к БД.\n");
}

$manifestPath = $base . '/plugins/user/vigling/vigling.xml';
if (!is_file($manifestPath)) {
    die("Ошибка: не найден plugins/user/vigling/vigling.xml\n");
}

$xml = simplexml_load_file($manifestPath);
if (!$xml || $xml->getName() !== 'extension') {
    die("Ошибка: неверный manifest vigling.xml\n");
}

$manifest_details = [
    'name'         => (string) $xml->name,
    'type'         => (string) $xml->attributes()->type,
    'version'      => (string) $xml->version,
    'description'  => (string) $xml->description,
    'creationDate' => (string) $xml->creationDate ?: 'JLIB_UNKNOWN',
    'author'       => (string) $xml->author ?: 'JLIB_UNKNOWN',
    'group'        => (string) $xml->group,
    'filename'     => 'vigling',
];
if (isset($xml->namespace) && (string) $xml->namespace !== '') {
    $manifest_details['namespace'] = (string) $xml->namespace;
}

$name = $manifest_details['name'];
if (strpos($name, 'PLG_') === 0) {
    $iniPath = $base . '/plugins/user/vigling/language/ru-RU/plg_user_vigling.sys.ini';
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

$exists = $mysqli->query("SELECT extension_id FROM `{$table}` WHERE type = 'plugin' AND element = 'vigling' AND folder = 'user' LIMIT 1");
if ($exists && $exists->fetch_object()) {
    echo "Плагин User - Vigling уже зарегистрирован в БД.\n";
    $mysqli->close();
    exit(0);
}

$manifest_cache = $mysqli->real_escape_string(json_encode($manifest_details));
$name_esc       = $mysqli->real_escape_string($name);

$sql = "INSERT INTO `{$table}` (package_id, name, type, element, folder, client_id, enabled, access, protected, locked, manifest_cache, params, custom_data, ordering, state, changelogurl)
        VALUES (0, '{$name_esc}', 'plugin', 'vigling', 'user', 0, 1, 1, 0, 0, '{$manifest_cache}', '{}', '', 0, 0, '')";

if (!$mysqli->query($sql)) {
    die("Ошибка INSERT: " . $mysqli->error . "\n");
}

echo "Плагин User - Vigling зарегистрирован в БД (enabled=1).\n";
echo "В админке: Расширения → Плагины, фильтр «User» — включите «Vigling».\n";
$mysqli->close();
