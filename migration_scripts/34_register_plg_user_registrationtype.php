<?php
/**
 * Регистрирует и включает плагин User - Registrationtype в #__extensions (тип регистрации: клиент/мастер).
 * Запуск из корня сайта: php migration_scripts/34_register_plg_user_registrationtype.php
 */
error_reporting(E_ALL);
ini_set('display_errors', '1');

$base = dirname(__DIR__);
require_once __DIR__ . '/load_j6_config.php';
$cfg = loadJ6Config($base . '/configuration.php');
if (!$cfg || empty($cfg->db)) {
    die("Ошибка: не найден configuration.php или нет доступа к БД.\n");
}

$manifestPath = $base . '/plugins/user/registrationtype/registrationtype.xml';
if (!is_file($manifestPath)) {
    die("Ошибка: не найден plugins/user/registrationtype/registrationtype.xml\n");
}

$xml = simplexml_load_file($manifestPath);
if (!$xml || $xml->getName() !== 'extension') {
    die("Ошибка: неверный manifest registrationtype.xml\n");
}

$manifest_details = [
    'name'         => (string) $xml->name,
    'type'         => (string) $xml->attributes()->type,
    'version'      => (string) $xml->version,
    'description'  => (string) $xml->description,
    'creationDate' => isset($xml->creationDate) ? (string) $xml->creationDate : 'JLIB_UNKNOWN',
    'author'       => isset($xml->author) ? (string) $xml->author : 'JLIB_UNKNOWN',
    'group'        => (string) $xml->group,
    'filename'     => 'registrationtype',
];
if (isset($xml->namespace) && (string) $xml->namespace !== '') {
    $manifest_details['namespace'] = (string) $xml->namespace;
}

$name = $manifest_details['name'];
if (strpos($name, 'PLG_') === 0) {
    $iniPath = $base . '/plugins/user/registrationtype/language/ru-RU/plg_user_registrationtype.sys.ini';
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

$exists = $mysqli->query("SELECT extension_id, enabled FROM `{$table}` WHERE type = 'plugin' AND element = 'registrationtype' AND folder = 'user' LIMIT 1");
$row = $exists ? $exists->fetch_object() : null;
if ($row) {
    if ((int) $row->enabled === 1) {
        echo "Плагин User - Registrationtype уже зарегистрирован и включён.\n";
    } else {
        $mysqli->query("UPDATE `{$table}` SET enabled = 1 WHERE extension_id = " . (int) $row->extension_id);
        echo "Плагин User - Registrationtype уже был в БД; включён (enabled=1).\n";
    }
    $mysqli->close();
    exit(0);
}

$manifest_cache = $mysqli->real_escape_string(json_encode($manifest_details));
$name_esc       = $mysqli->real_escape_string($name);

$sql = "INSERT INTO `{$table}` (package_id, name, type, element, folder, client_id, enabled, access, protected, locked, manifest_cache, params, custom_data, ordering, state, changelogurl)
        VALUES (0, '{$name_esc}', 'plugin', 'registrationtype', 'user', 0, 1, 1, 0, 0, '{$manifest_cache}', '{}', '', 0, 0, '')";

if (!$mysqli->query($sql)) {
    die("Ошибка INSERT: " . $mysqli->error . "\n");
}

echo "Плагин User - Registrationtype зарегистрирован в БД (enabled=1).\n";
echo "В админке: Расширения → Плагины, фильтр «User» — «Registration type».\n";
$mysqli->close();
