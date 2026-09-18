<?php
/**
 * Adds source column to #__vigling_bookings (catalog/map/profile/widget/share).
 *
 * Запуск:
 *   php migration_scripts/73_add_source_to_bookings.php
 */

define('_JEXEC', 1);
$baseDir = dirname(__DIR__);

require_once __DIR__ . '/load_j6_config.php';
$config = loadJ6Config($baseDir . '/configuration.php');
if (!$config) {
    fwrite(STDERR, "Не найден configuration.php\n");
    exit(1);
}

$mysqli = new mysqli($config->host, $config->user, $config->password, $config->db);
if ($mysqli->connect_error) {
    fwrite(STDERR, "Ошибка подключения к БД: " . $mysqli->connect_error . "\n");
    exit(1);
}
$mysqli->set_charset('utf8mb4');

$prefix = $config->dbprefix;
$table = $prefix . 'vigling_bookings';
$tableEsc = $mysqli->real_escape_string($table);

$columns = [];
$res = $mysqli->query("SHOW COLUMNS FROM `{$tableEsc}`");
if (!$res) {
    fwrite(STDERR, "SHOW COLUMNS failed: {$mysqli->error}\n");
    exit(1);
}
while ($row = $res->fetch_assoc()) {
    $columns[strtolower((string) ($row['Field'] ?? ''))] = true;
}
$res->free();

if (isset($columns['source'])) {
    echo "Колонка source уже есть в {$table}\n";
    exit(0);
}

$after = 'service_name';
if (isset($columns['contact_phone'])) {
    $after = 'contact_phone';
} elseif (isset($columns['comment'])) {
    $after = 'comment';
}
$afterEsc = $mysqli->real_escape_string($after);
$sql = "ALTER TABLE `{$tableEsc}` ADD COLUMN `source` VARCHAR(32) NULL DEFAULT NULL AFTER `{$afterEsc}`";
if (!$mysqli->query($sql)) {
    fwrite(STDERR, "Ошибка SQL: {$mysqli->error}\n");
    exit(1);
}

echo "Добавлена колонка source в {$table}\n";
exit(0);
