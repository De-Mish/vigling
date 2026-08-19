<?php
/**
 * Регистрирует плагин Task - Booking Notify в #__extensions (задачи планировщика: «Через 30 минут приём», «Приём начался»).
 * Запуск из корня сайта: php migration_scripts/29_register_plg_task_bookingnotify.php
 */
error_reporting(E_ALL);
ini_set('display_errors', '1');

$base = dirname(__DIR__);
require_once __DIR__ . '/load_j6_config.php';
$cfg = loadJ6Config($base . '/configuration.php');
if (!$cfg || empty($cfg->db)) {
    die("Ошибка: не найден configuration.php или нет доступа к БД.\n");
}

$manifestPath = $base . '/plugins/task/bookingnotify/bookingnotify.xml';
if (!is_file($manifestPath)) {
    die("Ошибка: не найден plugins/task/bookingnotify/bookingnotify.xml\n");
}

$xml = simplexml_load_file($manifestPath);
if (!$xml || $xml->getName() !== 'extension') {
    die("Ошибка: неверный manifest bookingnotify.xml\n");
}

$manifest_details = [
    'name'         => (string) $xml->name,
    'type'         => (string) $xml->attributes()->type,
    'version'      => (string) $xml->version,
    'description'  => (string) $xml->description,
    'creationDate' => (string) $xml->creationDate ?: 'JLIB_UNKNOWN',
    'author'       => (string) ($xml->author ?? '') ?: 'JLIB_UNKNOWN',
    'group'        => (string) $xml->group,
    'filename'     => 'bookingnotify',
];
if (isset($xml->namespace) && (string) $xml->namespace !== '') {
    $manifest_details['namespace'] = (string) $xml->namespace;
}

$name = $manifest_details['name'];
$iniPath = $base . '/plugins/task/bookingnotify/language/ru-RU/ru-RU.plg_task_bookingnotify.sys.ini';
if ((strpos($name, 'PLG_') === 0 || strpos($name, 'plg_') === 0) && is_file($iniPath)) {
    $ini = parse_ini_file($iniPath);
    if (!empty($ini[$name])) {
        $name = $ini[$name];
    }
}

$mysqli = new mysqli($cfg->host, $cfg->user, $cfg->password, $cfg->db);
if ($mysqli->connect_error) {
    die("Ошибка подключения к БД: " . $mysqli->connect_error . "\n");
}
$mysqli->set_charset('utf8mb4');

$prefix = $cfg->dbprefix;
$table  = $prefix . 'extensions';

$exists = $mysqli->query("SELECT extension_id FROM `{$table}` WHERE type = 'plugin' AND element = 'bookingnotify' AND folder = 'task' LIMIT 1");
if ($exists && $exists->fetch_object()) {
    echo "Плагин Task - Booking Notify уже зарегистрирован в БД.\n";
    $mysqli->close();
    exit(0);
}

$manifest_cache = $mysqli->real_escape_string(json_encode($manifest_details));
$name_esc       = $mysqli->real_escape_string($name);

$sql = "INSERT INTO `{$table}` (package_id, name, type, element, folder, client_id, enabled, access, protected, locked, manifest_cache, params, custom_data, ordering, state, changelogurl)
        VALUES (0, '{$name_esc}', 'plugin', 'bookingnotify', 'task', 0, 1, 1, 0, 0, '{$manifest_cache}', '{}', '', 0, 0, '')";

if (!$mysqli->query($sql)) {
    die("Ошибка INSERT: " . $mysqli->error . "\n");
}

$autoloadCache = $base . '/administrator/cache/autoload_psr4.php';
if (is_file($autoloadCache) && @unlink($autoloadCache)) {
    echo "Кэш PSR-4 (autoload_psr4.php) удалён — карта будет пересобрана при следующей загрузке.\n";
}

echo "Плагин Task - Booking Notify зарегистрирован в БД (enabled=1).\n";
echo "В админке: Расширения → Плагины, фильтр «Task» — включите «Task - Booking Notify». Затем: Компоненты → Запланированные задачи — создайте две задачи (типы «Через 30 минут приём» и «Приём начался»), укажите расписание.\n";
$mysqli->close();
