<?php
/**
 * Добавляет поля «Поиск» в #__vigling_bookings.
 *
 * Запуск:
 *   php migration_scripts/65_add_search_columns_to_vigling_bookings.php
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

function searchColumnExists(mysqli $db, string $tableName, string $column): bool
{
    $tableEsc = $db->real_escape_string($tableName);
    $columnEsc = $db->real_escape_string($column);
    $res = $db->query("SHOW COLUMNS FROM `{$tableEsc}` LIKE '{$columnEsc}'");
    return (bool) ($res && $res->num_rows > 0);
}

function searchIndexExists(mysqli $db, string $tableName, string $index): bool
{
    $tableEsc = $db->real_escape_string($tableName);
    $indexEsc = $db->real_escape_string($index);
    $res = $db->query("SHOW INDEX FROM `{$tableEsc}` WHERE Key_name = '{$indexEsc}'");
    return (bool) ($res && $res->num_rows > 0);
}

if (!searchColumnExists($mysqli, $table, 'search_id')) {
    if (!$mysqli->query("ALTER TABLE `{$tableEsc}` ADD COLUMN `search_id` BIGINT UNSIGNED DEFAULT NULL AFTER `course_slot_id`")) {
        fwrite(STDERR, "Ошибка ALTER search_id: {$mysqli->error}\n");
        exit(1);
    }
}

if (!searchColumnExists($mysqli, $table, 'search_slot_id')) {
    if (!$mysqli->query("ALTER TABLE `{$tableEsc}` ADD COLUMN `search_slot_id` BIGINT UNSIGNED DEFAULT NULL AFTER `search_id`")) {
        fwrite(STDERR, "Ошибка ALTER search_slot_id: {$mysqli->error}\n");
        exit(1);
    }
}

if (!searchIndexExists($mysqli, $table, 'idx_search_slot')) {
    if (!$mysqli->query("ALTER TABLE `{$tableEsc}` ADD KEY `idx_search_slot` (`search_slot_id`)")) {
        fwrite(STDERR, "Ошибка INDEX idx_search_slot: {$mysqli->error}\n");
        exit(1);
    }
}

if (!searchIndexExists($mysqli, $table, 'idx_search_id')) {
    if (!$mysqli->query("ALTER TABLE `{$tableEsc}` ADD KEY `idx_search_id` (`search_id`)")) {
        fwrite(STDERR, "Ошибка INDEX idx_search_id: {$mysqli->error}\n");
        exit(1);
    }
}

echo "Таблица {$table} расширена полями «Поиск»\n";
exit(0);
