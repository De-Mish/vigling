<?php
/**
 * Добавляет stock_service_id в #__vigling_bookings,
 * чтобы при отмене акционной записи можно было вернуть count_stock.
 *
 * Запуск:
 *   php migration_scripts/69_add_stock_service_id_to_vigling_bookings.php
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

function stockBookingColumnExists(mysqli $db, string $tableName, string $column): bool
{
    $tableEsc = $db->real_escape_string($tableName);
    $columnEsc = $db->real_escape_string($column);
    $res = $db->query("SHOW COLUMNS FROM `{$tableEsc}` LIKE '{$columnEsc}'");
    return (bool) ($res && $res->num_rows > 0);
}

function stockBookingIndexExists(mysqli $db, string $tableName, string $index): bool
{
    $tableEsc = $db->real_escape_string($tableName);
    $indexEsc = $db->real_escape_string($index);
    $res = $db->query("SHOW INDEX FROM `{$tableEsc}` WHERE Key_name = '{$indexEsc}'");
    return (bool) ($res && $res->num_rows > 0);
}

$after = 'service_name';
if (stockBookingColumnExists($mysqli, $table, 'search_slot_id')) {
    $after = 'search_slot_id';
} elseif (stockBookingColumnExists($mysqli, $table, 'course_slot_id')) {
    $after = 'course_slot_id';
}

if (!stockBookingColumnExists($mysqli, $table, 'stock_service_id')) {
    $afterEsc = str_replace('`', '``', $after);
    if (!$mysqli->query("ALTER TABLE `{$tableEsc}` ADD COLUMN `stock_service_id` BIGINT UNSIGNED DEFAULT NULL AFTER `{$afterEsc}`")) {
        fwrite(STDERR, "Ошибка ALTER stock_service_id: {$mysqli->error}\n");
        exit(1);
    }
}

if (!stockBookingIndexExists($mysqli, $table, 'idx_stock_service_id')) {
    if (!$mysqli->query("ALTER TABLE `{$tableEsc}` ADD KEY `idx_stock_service_id` (`stock_service_id`)")) {
        fwrite(STDERR, "Ошибка INDEX idx_stock_service_id: {$mysqli->error}\n");
        exit(1);
    }
}

echo "Таблица {$table} расширена полем stock_service_id\n";
exit(0);
