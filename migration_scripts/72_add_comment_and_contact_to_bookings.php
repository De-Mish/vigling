<?php
/**
 * Adds comment, contact_name, contact_phone to #__vigling_bookings.
 * Used by the public #zapis wizard and LK booking form.
 *
 * Запуск:
 *   php migration_scripts/72_add_comment_and_contact_to_bookings.php
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

$added = [];
$specs = [
    'comment' => ['sql' => "VARCHAR(500) NULL DEFAULT NULL", 'after' => 'service_name'],
    'contact_name' => ['sql' => "VARCHAR(150) NULL DEFAULT NULL", 'after' => 'comment'],
    'contact_phone' => ['sql' => "VARCHAR(50) NULL DEFAULT NULL", 'after' => 'contact_name'],
];
foreach ($specs as $name => $spec) {
    if (isset($columns[$name])) {
        continue;
    }
    $after = $spec['after'];
    if (!isset($columns[$after])) {
        $after = 'service_name';
    }
    $sql = "ALTER TABLE `{$tableEsc}` ADD COLUMN `{$name}` {$spec['sql']} AFTER `{$after}`";
    if (!$mysqli->query($sql)) {
        fwrite(STDERR, "Ошибка SQL ({$name}): {$mysqli->error}\n");
        exit(1);
    }
    $columns[$name] = true;
    $added[] = $name;
}

if ($added === []) {
    echo "Колонки comment / contact_name / contact_phone уже есть в {$table}\n";
    exit(0);
}

echo "Добавлены колонки " . implode(', ', $added) . " в {$table}\n";
exit(0);
