<?php
/**
 * Устарел: плагин Vigling перенесён в группу User (plugins/user/vigling/).
 * Регистрация: php migration_scripts/09_register_user_vigling_plugin.php
 * Удаление старой записи System - Vigling: php migration_scripts/10_unregister_system_vigling.php
 */
echo "Плагин System - Vigling больше не используется. Используйте User - Vigling (скрипт 09).\n";
exit(0);
ini_set('display_errors', '1');

$isCli = php_sapi_name() === 'cli';
$base  = dirname(__DIR__);

require_once __DIR__ . '/load_j6_config.php';
$cfg = loadJ6Config($base . '/configuration.php');
if (!$cfg || empty($cfg->db)) {
    die("Ошибка: не найден configuration.php или нет доступа к БД.\n");
}

$manifestPath = $base . '/plugins/system/vigling/vigling.xml';
if (!is_file($manifestPath)) {
    die("Ошибка: не найден vigling.xml\n");
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
if (isset($xml->changelogurl)) {
    $manifest_details['changelogurl'] = (string) $xml->changelogurl;
}

$name = $manifest_details['name'];
if (strpos($name, 'PLG_') === 0) {
    $iniPath = $base . '/plugins/system/vigling/language/ru-RU/plg_system_vigling.sys.ini';
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

$exists = $mysqli->query("SELECT extension_id FROM `{$table}` WHERE type = 'plugin' AND element = 'vigling' AND folder = 'system' LIMIT 1");
if ($exists && $exists->fetch_object()) {
    echo "Плагин vigling уже зарегистрирован в БД. Ничего не делаем.\n";
    $mysqli->close();
    exit(0);
}

$manifest_cache = $mysqli->real_escape_string(json_encode($manifest_details));
$name_esc       = $mysqli->real_escape_string($name);
$changelogurl   = isset($manifest_details['changelogurl']) ? "'" . $mysqli->real_escape_string($manifest_details['changelogurl']) . "'" : "''";

$sql = "INSERT INTO `{$table}` (package_id, name, type, element, folder, client_id, enabled, access, protected, locked, manifest_cache, params, custom_data, ordering, state, changelogurl)
        VALUES (0, '{$name_esc}', 'plugin', 'vigling', 'system', 0, 1, 1, 0, 0, '{$manifest_cache}', '{}', '', 0, 0, {$changelogurl})";

if (!$mysqli->query($sql)) {
    die("Ошибка INSERT: " . $mysqli->error . "\n");
}

echo "Плагин System - Vigling зарегистрирован в БД (enabled=1).\n";
echo "Проверьте в админке: Расширения → Плагины → найдите «Vigling» и при необходимости включите.\n";
$mysqli->close();
