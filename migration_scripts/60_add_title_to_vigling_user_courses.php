<?php
/**
 * Добавляет отдельное поле названия курса и переносит старые данные.
 *
 * Запуск:
 *   php migration_scripts/60_add_title_to_vigling_user_courses.php
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
$table = $mysqli->real_escape_string($prefix . 'vigling_user_courses');

$hasTitle = false;
$columnResult = $mysqli->query("SHOW COLUMNS FROM `{$table}` LIKE 'title'");
if ($columnResult && $columnResult->num_rows > 0) {
    $hasTitle = true;
}
if ($columnResult instanceof mysqli_result) {
    $columnResult->free();
}

if (!$hasTitle) {
    $alterSql = "ALTER TABLE `{$table}` ADD COLUMN `title` VARCHAR(150) NOT NULL DEFAULT '' AFTER `category_id`";
    if (!$mysqli->query($alterSql)) {
        fwrite(STDERR, "Ошибка ALTER TABLE: {$mysqli->error}\n");
        exit(1);
    }
    echo "Добавлено поле `title` в {$prefix}vigling_user_courses\n";
} else {
    echo "Поле `title` уже существует в {$prefix}vigling_user_courses\n";
}

$backfillSql = "UPDATE `{$table}` SET `title` = `description` WHERE TRIM(COALESCE(`title`, '')) = '' AND TRIM(COALESCE(`description`, '')) <> ''";
if (!$mysqli->query($backfillSql)) {
    fwrite(STDERR, "Ошибка UPDATE backfill: {$mysqli->error}\n");
    exit(1);
}

echo "Выполнен backfill title <- description для существующих курсов\n";
exit(0);
