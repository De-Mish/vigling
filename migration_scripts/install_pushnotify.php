<?php
/**
 * Установка com_pushnotify: таблицы БД + регистрация в #__extensions и autoload.
 * Запуск из корня public_html: php migration_scripts/install_pushnotify.php
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

$prefix = $cfg->dbprefix;
$sqlFile = $base . '/administrator/components/com_pushnotify/sql/install.mysql.utf8.sql';
if (!is_file($sqlFile)) {
    $sqlFile = dirname($base) . '/tz/sql/install.mysql.utf8.sql';
}
if (!is_file($sqlFile)) {
    $sqlFile = $base . '/tz/sql/install.mysql.utf8.sql';
}
if (!is_file($sqlFile)) {
    die("Не найден SQL-файл.\n");
}

$sql = file_get_contents($sqlFile);
$sql = str_replace('#__', $prefix, $sql);
$mysqli = new mysqli($cfg->host, $cfg->user, $cfg->password, $cfg->db);
if ($mysqli->connect_error) {
    die("Ошибка подключения к БД: " . $mysqli->connect_error . "\n");
}
$mysqli->set_charset('utf8mb4');
foreach (array_filter(array_map('trim', explode(';', $sql))) as $query) {
    if ($query === '') continue;
    if (!$mysqli->query($query)) {
        die("Ошибка SQL: " . $mysqli->error . "\n");
    }
}
echo "Таблицы pushnotify созданы.\n";

$manifestPath = $base . '/administrator/components/com_pushnotify/pushnotify.xml';
if (!is_file($manifestPath)) {
    die("Не найден pushnotify.xml.\n");
}
$xml = simplexml_load_file($manifestPath);
if (!$xml || $xml->getName() !== 'extension') {
    die("Ошибка манифеста.\n");
}

$name = (string) $xml->name;
$version = (string) $xml->version;
$description = (string) $xml->description;
$manifest_cache = json_encode([
    'name' => $name,
    'type' => 'component',
    'version' => $version,
    'description' => $description,
    'creationDate' => (string) ($xml->creationDate ?? ''),
    'author' => (string) ($xml->author ?? ''),
    'namespace' => (string) ($xml->namespace ?? ''),
]);
$table = $prefix . 'extensions';
$exists = $mysqli->query("SELECT extension_id FROM `{$table}` WHERE type = 'component' AND element = 'com_pushnotify' LIMIT 1");
if ($exists && $exists->fetch_object()) {
    echo "Компонент com_pushnotify уже в БД.\n";
} else {
    $manifest_json = $mysqli->real_escape_string($manifest_cache);
    $name_esc = $mysqli->real_escape_string($name);
    $cols = "package_id, name, type, element, folder, client_id, enabled, access, protected, locked, manifest_cache, params, custom_data, ordering, state, changelogurl";
    $vals = "0, '{$name_esc}', 'component', 'com_pushnotify', '', 1, 1, 1, 0, 0, '{$manifest_json}', '{}', '', 0, 0, ''";
    if (!$mysqli->query("INSERT INTO `{$table}` ({$cols}) VALUES ({$vals})")) {
        die("Ошибка INSERT: " . $mysqli->error . "\n");
    }
    echo "Компонент com_pushnotify зарегистрирован в БД.\n";
}
$mysqli->close();

$autoloadFile = $base . '/administrator/cache/autoload_psr4.php';
if (is_file($autoloadFile)) {
    $content = file_get_contents($autoloadFile);
    if (strpos($content, "Viglin\\\\Component\\\\Pushnotify\\\\") === false) {
        $insert = "\t'Viglin\\\\Component\\\\Pushnotify\\\\Administrator\\\\' => [JPATH_ADMINISTRATOR . '/components/com_pushnotify/src'],\n\t'Viglin\\\\Component\\\\Pushnotify\\\\Site\\\\' => [JPATH_SITE . '/components/com_pushnotify/src'],";
        $old = "'Viglin\\\\Component\\\\Poisk\\\\Site\\\\' => [JPATH_SITE . '/components/com_poisk/src'],";
        $new = "'Viglin\\\\Component\\\\Poisk\\\\Site\\\\' => [JPATH_SITE . '/components/com_poisk/src'],\n\t" . $insert;
        if (strpos($content, $old) !== false) {
            $content = str_replace($old, $new, $content);
            file_put_contents($autoloadFile, $content);
            echo "В autoload_psr4.php добавлен namespace com_pushnotify.\n";
        } else {
            echo "Добавьте вручную в administrator/cache/autoload_psr4.php namespace для Viglin\\Component\\Pushnotify.\n";
        }
    }
}

echo "Готово. Если в выпадающем меню «Компоненты» нет пункта «Push-уведомления», откройте в браузере (под админом):\n";
echo "  administrator/index.php?option=com_pushnotify&task=display.addMenuItem\n";
echo "Настройте configuration/firebase-config.php и firebase-credentials.json.\n";
