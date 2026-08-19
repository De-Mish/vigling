<?php
/**
 * Регистрация модуля mod_specialists в #__extensions и autoload_psr4.php
 * Запуск: php migration_scripts/14_register_mod_specialists.php
 */

$base = dirname(__DIR__);
require_once __DIR__ . '/load_j6_config.php';
$cfg = loadJ6Config($base . '/configuration.php');
if (!$cfg || empty($cfg->db)) {
    die("Ошибка: не найден configuration.php или нет доступа к БД.\n");
}

$manifestPath = $base . '/modules/mod_specialists/mod_specialists.xml';
if (!is_file($manifestPath)) {
    die("Ошибка: не найден modules/mod_specialists/mod_specialists.xml\n");
}

$xml = simplexml_load_file($manifestPath);
if (!$xml || $xml->getName() !== 'extension') {
    die("Ошибка: неверный manifest.\n");
}

$name = (string) $xml->name;
$version = (string) $xml->version;
$manifest_cache = json_encode([
    'name' => $name,
    'type' => 'module',
    'version' => $version,
    'description' => (string) ($xml->description ?? ''),
]);

$mysqli = new mysqli($cfg->host, $cfg->user, $cfg->password, $cfg->db);
if ($mysqli->connect_error) {
    die("Ошибка подключения к БД: " . $mysqli->connect_error . "\n");
}
$mysqli->set_charset('utf8mb4');

$prefix = $cfg->dbprefix;
$table = $prefix . 'extensions';

$exists = $mysqli->query("SELECT extension_id FROM `{$table}` WHERE type = 'module' AND element = 'mod_specialists' LIMIT 1");
if ($exists && $exists->fetch_object()) {
    echo "Модуль mod_specialists уже зарегистрирован в БД.\n";
} else {
    $mc = $mysqli->real_escape_string($manifest_cache);
    $nameEsc = $mysqli->real_escape_string($name);
    $sql = "INSERT INTO `{$table}` (package_id, name, type, element, folder, client_id, enabled, access, protected, locked, manifest_cache, params, custom_data, ordering, state, changelogurl) 
            VALUES (0, '{$nameEsc}', 'module', 'mod_specialists', '', 0, 1, 1, 0, 0, '{$mc}', '{}', '', 0, 0, '')";
    if (!$mysqli->query($sql)) {
        die("Ошибка INSERT: " . $mysqli->error . "\n");
    }
    echo "Модуль mod_specialists зарегистрирован в БД.\n";
}

$autoloadFile = $base . '/administrator/cache/autoload_psr4.php';
if (is_file($autoloadFile)) {
    $content = file_get_contents($autoloadFile);
    if (strpos($content, 'Joomla\\\\Module\\\\Specialists\\\\') === false) {
        $insert = "\t'Joomla\\\\Module\\\\Specialists\\\\Site\\\\' => [JPATH_SITE . '/modules/mod_specialists/src'],";
        $content = str_replace(
            "'Joomla\\\\Module\\\\Wrapper\\\\Site\\\\' => [JPATH_SITE . '/modules/mod_wrapper/src'],",
            "'Joomla\\\\Module\\\\Wrapper\\\\Site\\\\' => [JPATH_SITE . '/modules/mod_wrapper/src'],\n\t" . $insert,
            $content
        );
        if (file_put_contents($autoloadFile, $content) !== false) {
            echo "В autoload_psr4.php добавлен namespace для mod_specialists.\n";
        }
    } else {
        echo "Namespace mod_specialists уже есть в autoload.\n";
    }
}

$mysqli->close();
echo "\nДальше: Расширения → Модули → Новый → Поиск специалистов → сохранить и назначить позицию (например top).\n";
