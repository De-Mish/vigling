<?php
/**
 * Регистрирует компонент com_specialists в #__extensions (ручная установка без Discover).
 * Запуск из корня сайта: php migration_scripts/12_register_com_specialists.php
 */
error_reporting(E_ALL);
ini_set('display_errors', '1');

$base = dirname(__DIR__);
require_once __DIR__ . '/load_j6_config.php';
$cfg = loadJ6Config($base . '/configuration.php');
if (!$cfg || empty($cfg->db)) {
    die("Ошибка: не найден configuration.php или нет доступа к БД.\n");
}

$manifestPath = $base . '/administrator/components/com_specialists/specialists.xml';
if (!is_file($manifestPath)) {
    die("Ошибка: не найден administrator/components/com_specialists/specialists.xml\n");
}

$xml = simplexml_load_file($manifestPath);
if (!$xml || $xml->getName() !== 'extension') {
    die("Ошибка: неверный manifest specialists.xml\n");
}

$name        = (string) $xml->name;
$version     = (string) $xml->version;
$description = (string) $xml->description;
$namespace   = isset($xml->namespace) ? (string) $xml->namespace : '';

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

$exists = $mysqli->query("SELECT extension_id FROM `{$table}` WHERE type = 'component' AND element = 'com_specialists' LIMIT 1");
if ($exists && $exists->fetch_object()) {
    echo "Компонент com_specialists уже зарегистрирован в БД.\n";
    $mysqli->close();
    $needAutoload = true;
} else {
    $needAutoload = false;
}

$manifest_json = $mysqli->real_escape_string(json_encode($manifest_cache));
$name_esc      = $mysqli->real_escape_string($name);

$cols = "package_id, name, type, element, folder, client_id, enabled, access, protected, locked, manifest_cache, params, custom_data, ordering, state, changelogurl";
$vals = "0, '{$name_esc}', 'component', 'com_specialists', '', 1, 1, 1, 0, 0, '{$manifest_json}', '{}', '', 0, 0, ''";

$sql = "INSERT INTO `{$table}` ({$cols}) VALUES ({$vals})";

if (!$needAutoload) {
    if (!$mysqli->query($sql)) {
        die("Ошибка INSERT: " . $mysqli->error . "\n");
    }
    echo "Компонент com_specialists зарегистрирован в БД (enabled=1).\n";
}
$mysqli->close();

$autoloadFile = $base . '/administrator/cache/autoload_psr4.php';
if (is_file($autoloadFile)) {
    $content = file_get_contents($autoloadFile);
    $needle1 = "Viglin\\\\Component\\\\Specialists\\\\Administrator";
    if (strpos($content, $needle1) === false) {
        $insert = "\t'Viglin\\\\Component\\\\Specialists\\\\Administrator\\\\' => [JPATH_ADMINISTRATOR . '/components/com_specialists/src'],\n\t'Viglin\\\\Component\\\\Specialists\\\\Site\\\\' => [JPATH_SITE . '/components/com_specialists/src'],";
        $content = str_replace(
            "\t'Joomla\\\\Component\\\\Wrapper\\\\Site\\\\' => [JPATH_SITE . '/components/com_wrapper/src'],\n\t'Joomla\\\\Module",
            "\t'Joomla\\\\Component\\\\Wrapper\\\\Site\\\\' => [JPATH_SITE . '/components/com_wrapper/src'],\n\t" . $insert . ",\n\t'Joomla\\\\Module",
            $content
        );
        if (file_put_contents($autoloadFile, $content) !== false) {
            echo "В autoload_psr4.php добавлен namespace для com_specialists.\n";
        }
    }
}

if (!$needAutoload) {
    echo "Откройте на сайте: ?option=com_specialists&view=list\n";
    echo "Либо создайте пункт меню: Компонент → Поиск специалистов (com_specialists).\n";
}
