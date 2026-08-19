<?php
/**
 * Создаёт общую таблицу настроек и регистрирует админ-меню com_pushnotify.
 * Запуск из корня сайта: php migration_scripts/62_create_vigling_app_settings.php
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

$mysqli = new mysqli($cfg->host, $cfg->user, $cfg->password, $cfg->db);
if ($mysqli->connect_error) {
    die("Ошибка подключения к БД: " . $mysqli->connect_error . "\n");
}
$mysqli->set_charset('utf8mb4');
$prefix = $cfg->dbprefix;

$settingsTable = $prefix . 'vigling_app_settings';
$sql = "CREATE TABLE IF NOT EXISTS `{$settingsTable}` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `setting_key` VARCHAR(128) NOT NULL,
  `setting_value` JSON NOT NULL,
  `created_at` DATETIME NOT NULL,
  `updated_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_setting_key` (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
if (!$mysqli->query($sql)) {
    die("Ошибка CREATE settings: " . $mysqli->error . "\n");
}

$eventDefaults = [
    'enabled' => true,
    'recipients' => ['client' => true, 'master' => true],
    'fcm' => true,
    'inbox' => true,
    'title' => '',
    'body' => '',
];
$events = [];
foreach ([
    'booking_confirmed' => 'Запись подтверждена',
    'booking_cancelled' => 'Запись отменена',
    'booking_rescheduled' => 'Запись перенесена',
    'booking_reminder' => 'Напоминание о записи',
    'booking_in_30min' => 'Через 30 минут приём',
    'booking_started' => 'Приём начался',
    'course_created' => 'Курс создан/изменён',
    'course_cancelled' => 'Курс отменён',
    'course_rescheduled' => 'Курс перенесён',
    'booking_finished' => 'Окончание записи/курса',
] as $key => $title) {
    $events[$key] = array_merge($eventDefaults, ['title' => $title]);
}
$notifications = [
    'global' => [
        'enabled' => true,
        'fcm_enabled' => true,
        'inbox_enabled' => true,
        'logging_enabled' => true,
        'fcm_retry_attempts' => 2,
        'fcm_retry_delay_ms' => 300,
    ],
    'events' => $events,
    'reminders' => [
        ['minutes' => 30, 'enabled' => true, 'label' => 'За 30 минут'],
        ['minutes' => 1440, 'enabled' => false, 'label' => 'За 1 день'],
        ['minutes' => 0, 'enabled' => true, 'label' => 'В момент начала'],
    ],
    'booking_kinds' => [
        'service' => true,
        'stock' => true,
        'course' => true,
        'journal' => true,
    ],
];
$emailVerification = [
    'activation_grace_minutes' => 4320,
    'token_ttl_days' => 30,
    'expiration_block_enabled' => true,
    'resend_enabled' => true,
    'resend_cooldown_seconds' => 120,
];

$now = gmdate('Y-m-d H:i:s');
$stmt = $mysqli->prepare("INSERT INTO `{$settingsTable}` (`setting_key`, `setting_value`, `created_at`, `updated_at`) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE `setting_value` = VALUES(`setting_value`), `updated_at` = VALUES(`updated_at`)");
foreach (['notifications' => $notifications, 'email_verification' => $emailVerification] as $key => $value) {
    $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $stmt->bind_param('ssss', $key, $json, $now, $now);
    if (!$stmt->execute()) {
        die("Ошибка INSERT settings {$key}: " . $stmt->error . "\n");
    }
}
$stmt->close();
echo "Таблица настроек и дефолты созданы/обновлены.\n";

$extensionsTable = $prefix . 'extensions';
$menuTable = $prefix . 'menu';
$manifestPath = $base . '/administrator/components/com_pushnotify/pushnotify.xml';
$manifestCache = [
    'name' => 'com_pushnotify',
    'type' => 'component',
    'version' => '1.0.0',
    'description' => 'Управление push- и inbox-уведомлениями',
    'namespace' => 'Viglin\\Component\\Pushnotify',
];
if (is_file($manifestPath) && ($xml = simplexml_load_file($manifestPath))) {
    $manifestCache['name'] = (string) $xml->name;
    $manifestCache['version'] = (string) $xml->version;
    $manifestCache['description'] = (string) $xml->description;
}
$manifestJson = $mysqli->real_escape_string(json_encode($manifestCache, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
$nameEsc = $mysqli->real_escape_string((string) $manifestCache['name']);
$res = $mysqli->query("SELECT extension_id FROM `{$extensionsTable}` WHERE type = 'component' AND element = 'com_pushnotify' LIMIT 1");
$row = $res ? $res->fetch_assoc() : null;
if ($row) {
    $extensionId = (int) $row['extension_id'];
    $mysqli->query("UPDATE `{$extensionsTable}` SET name = '{$nameEsc}', enabled = 1, manifest_cache = '{$manifestJson}' WHERE extension_id = {$extensionId}");
} else {
    $cols = "package_id, name, type, element, folder, client_id, enabled, access, protected, locked, manifest_cache, params, custom_data, ordering, state, changelogurl";
    $vals = "0, '{$nameEsc}', 'component', 'com_pushnotify', '', 1, 1, 1, 0, 0, '{$manifestJson}', '{}', '', 0, 0, ''";
    if (!$mysqli->query("INSERT INTO `{$extensionsTable}` ({$cols}) VALUES ({$vals})")) {
        die("Ошибка регистрации com_pushnotify: " . $mysqli->error . "\n");
    }
    $extensionId = (int) $mysqli->insert_id;
}
echo "Компонент com_pushnotify зарегистрирован/обновлён.\n";

$title = $mysqli->real_escape_string('COM_PUSHNOTIFY_MANAGEMENT');
$alias = $mysqli->real_escape_string('notification-management');
$link = $mysqli->real_escape_string('index.php?option=com_pushnotify');
$img = $mysqli->real_escape_string('class:bell');
$menuRes = $mysqli->query("SELECT id FROM `{$menuTable}` WHERE client_id = 1 AND link LIKE '%option=com_pushnotify%' LIMIT 1");
if ($menuRes && ($menuRow = $menuRes->fetch_assoc())) {
    $id = (int) $menuRow['id'];
    $mysqli->query("UPDATE `{$menuTable}` SET title = '{$title}', alias = '{$alias}', link = '{$link}', component_id = {$extensionId}, img = '{$img}', published = 1 WHERE id = {$id}");
    echo "Админ-меню com_pushnotify обновлено.\n";
} else {
    $afterId = 0;
    $afterRes = $mysqli->query("SELECT id, rgt FROM `{$menuTable}` WHERE client_id = 1 AND link LIKE '%option=com_viglingservices%' ORDER BY id DESC LIMIT 1");
    if ($afterRes && ($afterRow = $afterRes->fetch_assoc())) {
        $rgt = (int) $afterRow['rgt'];
    } else {
        $rootRes = $mysqli->query("SELECT rgt FROM `{$menuTable}` WHERE id = 1 LIMIT 1");
        $rootRow = $rootRes ? $rootRes->fetch_assoc() : null;
        $rgt = $rootRow ? max(1, ((int) $rootRow['rgt'] - 1)) : 1;
    }
    $mysqli->query("UPDATE `{$menuTable}` SET rgt = rgt + 2 WHERE menutype = 'main' AND client_id = 1 AND rgt >= {$rgt}");
    $mysqli->query("UPDATE `{$menuTable}` SET lft = lft + 2 WHERE menutype = 'main' AND client_id = 1 AND lft > {$rgt}");
    $insert = "INSERT INTO `{$menuTable}` (menutype, title, alias, path, link, type, published, parent_id, level, component_id, checked_out, checked_out_time, browserNav, access, img, template_style_id, params, lft, rgt, home, language, client_id) VALUES "
        . "('main', '{$title}', '{$alias}', '{$alias}', '{$link}', 'component', 1, 1, 2, {$extensionId}, 0, '1970-01-01 00:00:00', 0, 1, '{$img}', 0, '{}', {$rgt}, " . ($rgt + 1) . ", 0, '*', 1)";
    if (!$mysqli->query($insert)) {
        die("Ошибка создания админ-меню: " . $mysqli->error . "\n");
    }
    echo "Админ-меню com_pushnotify создано.\n";
}

$mysqli->close();
echo "Готово.\n";
