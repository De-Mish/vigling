<?php
/**
 * Регистрирует компонент com_viglingservices в #__extensions (админка: управление услугами).
 * Запуск: php migration_scripts/20_register_com_viglingservices.php
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

$manifestPath = $base . '/administrator/components/com_viglingservices/viglingservices.xml';
if (!is_file($manifestPath)) {
    die("Ошибка: не найден administrator/components/com_viglingservices/viglingservices.xml\n");
}

$xml = simplexml_load_file($manifestPath);
if (!$xml || $xml->getName() !== 'extension') {
    die("Ошибка: неверный manifest viglingservices.xml\n");
}

$name = (string) $xml->name;
$version = (string) $xml->version;
$description = (string) $xml->description;
$namespace = isset($xml->namespace['path']) ? (string) $xml->namespace['path'] : (isset($xml->namespace) ? (string) $xml->namespace : '');

$manifest_cache = [
    'name' => $name,
    'type' => 'component',
    'version' => $version,
    'description' => $description,
    'creationDate' => (string) ($xml->creationDate ?? ''),
    'author' => (string) ($xml->author ?? ''),
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
$table = $prefix . 'extensions';

$extRow = $mysqli->query("SELECT extension_id FROM `{$table}` WHERE type = 'component' AND element = 'com_viglingservices' LIMIT 1");
$extId = null;
if ($extRow && $o = $extRow->fetch_object()) {
    $extId = (int) $o->extension_id;
    echo "Компонент com_viglingservices уже зарегистрирован в БД.\n";
} else {
    $manifest_json = $mysqli->real_escape_string(json_encode($manifest_cache));
    $name_esc = $mysqli->real_escape_string($name);
    $cols = "package_id, name, type, element, folder, client_id, enabled, access, protected, locked, manifest_cache, params, custom_data, ordering, state, changelogurl";
    $vals = "0, '{$name_esc}', 'component', 'com_viglingservices', '', 1, 1, 1, 0, 0, '{$manifest_json}', '{}', '', 0, 0, ''";
    if (!$mysqli->query("INSERT INTO `{$table}` ({$cols}) VALUES ({$vals})")) {
        die("Ошибка INSERT: " . $mysqli->error . "\n");
    }
    $extId = (int) $mysqli->insert_id;
    echo "Компонент com_viglingservices зарегистрирован в БД (enabled=1).\n";
}

$autoloadFile = $base . '/administrator/cache/autoload_psr4.php';
if (is_file($autoloadFile)) {
    $content = file_get_contents($autoloadFile);
    if (strpos($content, "Viglin\\\\Component\\\\Viglingservices\\\\") === false) {
        $insert = "\t'Viglin\\\\Component\\\\Viglingservices\\\\Administrator\\\\' => [JPATH_ADMINISTRATOR . '/components/com_viglingservices/src'],";
        $content = str_replace(
            "'Viglin\\\\Component\\\\Poisk\\\\Site\\\\' => [JPATH_SITE . '/components/com_poisk/src'],",
            "'Viglin\\\\Component\\\\Poisk\\\\Site\\\\' => [JPATH_SITE . '/components/com_poisk/src'],\n\t" . $insert,
            $content
        );
        if (file_put_contents($autoloadFile, $content) !== false) {
            echo "В autoload_psr4.php добавлен namespace для com_viglingservices.\n";
        }
    }
}

echo "Админка: Компоненты → Услуги (или index.php?option=com_viglingservices).\n";
