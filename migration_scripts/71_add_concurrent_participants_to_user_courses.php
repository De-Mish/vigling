<?php
/**
 * Adds concurrent_participants to #__vigling_user_courses.
 * Used by "Любое время" courses: how many clients may share one time slot.
 *
 * Запуск:
 *   php migration_scripts/71_add_concurrent_participants_to_user_courses.php
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
$table = $prefix . 'vigling_user_courses';
$tableEsc = $mysqli->real_escape_string($table);
$res = $mysqli->query("SHOW COLUMNS FROM `{$tableEsc}` LIKE 'concurrent_participants'");
if ($res && $res->num_rows > 0) {
    echo "Колонка concurrent_participants уже есть в {$table}\n";
    exit(0);
}

$sql = "ALTER TABLE `{$tableEsc}` ADD COLUMN `concurrent_participants` INT UNSIGNED NOT NULL DEFAULT 1 AFTER `capacity`";
if (!$mysqli->query($sql)) {
    fwrite(STDERR, "Ошибка SQL: {$mysqli->error}\n");
    exit(1);
}

echo "Добавлена колонка concurrent_participants в {$table}\n";
exit(0);
