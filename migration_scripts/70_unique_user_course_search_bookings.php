<?php
/**
 * Unique (user_id, course_id) / (user_id, search_id) on #__vigling_bookings.
 * Prevents a second insert from double-booking the same course or search
 * when two requests pass the COUNT checks at the same time.
 *
 * Rows with NULL course_id / search_id are not constrained (MySQL unique + NULL).
 *
 * Запуск:
 *   php migration_scripts/70_unique_user_course_search_bookings.php
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

function uniqueBookingIndexExists(mysqli $db, string $tableName, string $index): bool
{
    $tableEsc = $db->real_escape_string($tableName);
    $indexEsc = $db->real_escape_string($index);
    $res = $db->query("SHOW INDEX FROM `{$tableEsc}` WHERE Key_name = '{$indexEsc}'");
    return (bool) ($res && $res->num_rows > 0);
}

function uniqueBookingColumnExists(mysqli $db, string $tableName, string $column): bool
{
    $tableEsc = $db->real_escape_string($tableName);
    $columnEsc = $db->real_escape_string($column);
    $res = $db->query("SHOW COLUMNS FROM `{$tableEsc}` LIKE '{$columnEsc}'");
    return (bool) ($res && $res->num_rows > 0);
}

function uniqueBookingDuplicateGroups(mysqli $db, string $tableName, string $column): int
{
    $tableEsc = $db->real_escape_string($tableName);
    $columnEsc = $db->real_escape_string($column);
    $sql = "SELECT COUNT(*) AS c FROM (
        SELECT `user_id`, `{$columnEsc}`
        FROM `{$tableEsc}`
        WHERE `{$columnEsc}` IS NOT NULL
        GROUP BY `user_id`, `{$columnEsc}`
        HAVING COUNT(*) > 1
    ) d";
    $res = $db->query($sql);
    if (!$res) {
        return 0;
    }
    $row = $res->fetch_assoc();
    return (int) ($row['c'] ?? 0);
}

$added = [];

if (uniqueBookingColumnExists($mysqli, $table, 'course_id') && !uniqueBookingIndexExists($mysqli, $table, 'uniq_user_course_id')) {
    $dupes = uniqueBookingDuplicateGroups($mysqli, $table, 'course_id');
    if ($dupes > 0) {
        fwrite(STDERR, "Пропуск uniq_user_course_id: уже есть {$dupes} пар user_id+course_id с дублями\n");
    } elseif (!$mysqli->query("ALTER TABLE `{$tableEsc}` ADD UNIQUE KEY `uniq_user_course_id` (`user_id`, `course_id`)")) {
        fwrite(STDERR, "Ошибка UNIQUE uniq_user_course_id: {$mysqli->error}\n");
        exit(1);
    } else {
        $added[] = 'uniq_user_course_id';
    }
}

if (uniqueBookingColumnExists($mysqli, $table, 'search_id') && !uniqueBookingIndexExists($mysqli, $table, 'uniq_user_search_id')) {
    $dupes = uniqueBookingDuplicateGroups($mysqli, $table, 'search_id');
    if ($dupes > 0) {
        fwrite(STDERR, "Пропуск uniq_user_search_id: уже есть {$dupes} пар user_id+search_id с дублями\n");
    } elseif (!$mysqli->query("ALTER TABLE `{$tableEsc}` ADD UNIQUE KEY `uniq_user_search_id` (`user_id`, `search_id`)")) {
        fwrite(STDERR, "Ошибка UNIQUE uniq_user_search_id: {$mysqli->error}\n");
        exit(1);
    } else {
        $added[] = 'uniq_user_search_id';
    }
}

if ($added === []) {
    echo "Уникальные индексы user+course / user+search уже на месте (или пропущены из-за дублей)\n";
} else {
    echo "Добавлены индексы: " . implode(', ', $added) . "\n";
}
exit(0);
