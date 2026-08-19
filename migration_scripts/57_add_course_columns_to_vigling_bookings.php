<?php
/**
 * Добавляет поля курсов в #__vigling_bookings.
 *
 * Запуск:
 *   php migration_scripts/57_add_course_columns_to_vigling_bookings.php
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

function courseColumnExists(mysqli $db, string $tableName, string $column): bool
{
    $tableEsc = $db->real_escape_string($tableName);
    $columnEsc = $db->real_escape_string($column);
    $res = $db->query("SHOW COLUMNS FROM `{$tableEsc}` LIKE '{$columnEsc}'");
    return (bool) ($res && $res->num_rows > 0);
}

function courseIndexExists(mysqli $db, string $tableName, string $index): bool
{
    $tableEsc = $db->real_escape_string($tableName);
    $indexEsc = $db->real_escape_string($index);
    $res = $db->query("SHOW INDEX FROM `{$tableEsc}` WHERE Key_name = '{$indexEsc}'");
    return (bool) ($res && $res->num_rows > 0);
}

if (!courseColumnExists($mysqli, $table, 'booking_kind')) {
    if (!$mysqli->query("ALTER TABLE `{$tableEsc}` ADD COLUMN `booking_kind` VARCHAR(16) NOT NULL DEFAULT 'service' AFTER `service_name`")) {
        fwrite(STDERR, "Ошибка ALTER booking_kind: {$mysqli->error}\n");
        exit(1);
    }
}

if (!courseColumnExists($mysqli, $table, 'course_id')) {
    if (!$mysqli->query("ALTER TABLE `{$tableEsc}` ADD COLUMN `course_id` BIGINT UNSIGNED DEFAULT NULL AFTER `booking_kind`")) {
        fwrite(STDERR, "Ошибка ALTER course_id: {$mysqli->error}\n");
        exit(1);
    }
}

if (!courseColumnExists($mysqli, $table, 'course_slot_id')) {
    if (!$mysqli->query("ALTER TABLE `{$tableEsc}` ADD COLUMN `course_slot_id` BIGINT UNSIGNED DEFAULT NULL AFTER `course_id`")) {
        fwrite(STDERR, "Ошибка ALTER course_slot_id: {$mysqli->error}\n");
        exit(1);
    }
}

if (!courseIndexExists($mysqli, $table, 'idx_booking_kind_time')) {
    if (!$mysqli->query("ALTER TABLE `{$tableEsc}` ADD KEY `idx_booking_kind_time` (`booking_kind`, `time`)")) {
        fwrite(STDERR, "Ошибка INDEX idx_booking_kind_time: {$mysqli->error}\n");
        exit(1);
    }
}

if (!courseIndexExists($mysqli, $table, 'idx_course_slot')) {
    if (!$mysqli->query("ALTER TABLE `{$tableEsc}` ADD KEY `idx_course_slot` (`course_slot_id`)")) {
        fwrite(STDERR, "Ошибка INDEX idx_course_slot: {$mysqli->error}\n");
        exit(1);
    }
}

if (!courseIndexExists($mysqli, $table, 'idx_course_id')) {
    if (!$mysqli->query("ALTER TABLE `{$tableEsc}` ADD KEY `idx_course_id` (`course_id`)")) {
        fwrite(STDERR, "Ошибка INDEX idx_course_id: {$mysqli->error}\n");
        exit(1);
    }
}

$mysqli->query(
    "UPDATE `{$tableEsc}`
     SET `booking_kind` = 'journal'
     WHERE `user_id` = 0
       AND (`service_name` LIKE '[journal]%' OR `service_name` LIKE 'Журнал:%')"
);

echo "Таблица {$table} расширена полями курсов\n";
exit(0);
