<?php
/**
 * Регистрирует компонент com_orders в #__extensions (список записей, перенос, отмена).
 * Запуск из корня сайта: php migration_scripts/27_register_com_orders.php
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

$manifestPath = $base . '/components/com_orders/orders.xml';
if (!is_file($manifestPath)) {
    die("Ошибка: не найден components/com_orders/orders.xml\n");
}

$xml = simplexml_load_file($manifestPath);
if (!$xml || $xml->getName() !== 'extension') {
    die("Ошибка: неверный manifest orders.xml\n");
}

$name = (string) ($xml->name ?? 'Записи');
$version = (string) ($xml->version ?? '1.0.0');
$description = (string) ($xml->description ?? 'Мои записи к мастерам');
$namespace = isset($xml->namespace) ? (string) $xml->namespace : 'Viglin\\Component\\Orders';

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

$exists = $mysqli->query("SELECT extension_id FROM `{$table}` WHERE type = 'component' AND element = 'com_orders' LIMIT 1");
if ($exists && $exists->fetch_object()) {
    echo "Компонент com_orders уже зарегистрирован в БД.\n";
} else {
    $manifest_json = $mysqli->real_escape_string(json_encode($manifest_cache));
    $name_esc = $mysqli->real_escape_string($name);
    $cols = "package_id, name, type, element, folder, client_id, enabled, access, protected, locked, manifest_cache, params, custom_data, ordering, state, changelogurl";
    $vals = "0, '{$name_esc}', 'component', 'com_orders', '', 0, 1, 1, 0, 0, '{$manifest_json}', '{}', '', 0, 0, ''";
    if (!$mysqli->query("INSERT INTO `{$table}` ({$cols}) VALUES ({$vals})")) {
        die("Ошибка INSERT: " . $mysqli->error . "\n");
    }
    echo "Компонент com_orders зарегистрирован в БД (client_id=0, enabled=1).\n";
}

$autoloadFile = $base . '/administrator/cache/autoload_psr4.php';
if (is_file($autoloadFile)) {
    $content = file_get_contents($autoloadFile);
    if (strpos($content, "Viglin\\\\Component\\\\Orders\\\\") === false) {
        $insert = "\t'Viglin\\\\Component\\\\Orders\\\\Administrator\\\\' => [JPATH_ADMINISTRATOR . '/components/com_orders/src'],\n\t'Viglin\\\\Component\\\\Orders\\\\Site\\\\' => [JPATH_SITE . '/components/com_orders/src'],";
        $content = str_replace(
            "'Viglin\\\\Component\\\\Viglingservices\\\\Administrator\\\\' => [JPATH_ADMINISTRATOR . '/components/com_viglingservices/src'],",
            "'Viglin\\\\Component\\\\Viglingservices\\\\Administrator\\\\' => [JPATH_ADMINISTRATOR . '/components/com_viglingservices/src'],\n\t" . $insert,
            $content
        );
        if (file_put_contents($autoloadFile, $content) !== false) {
            echo "В autoload_psr4.php добавлен namespace для com_orders.\n";
        }
    }
}

$mysqli->close();
echo "Далее: php migration_scripts/28_add_lk_orders_menu.php — добавит пункт меню /lk/orders.\n";
