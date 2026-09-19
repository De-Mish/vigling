<?php
/**
 * Adds recommendation (150 chars) to user services and stock services.
 *
 * Запуск:
 *   php migration_scripts/74_add_recommendation_to_user_services.php
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
$tables = [
    $prefix . 'vigling_user_services',
    $prefix . 'vigling_user_stock_services',
];

foreach ($tables as $table) {
    $tableEsc = $mysqli->real_escape_string($table);
    $res = $mysqli->query("SHOW COLUMNS FROM `{$tableEsc}` LIKE 'recommendation'");
    if ($res && $res->num_rows > 0) {
        echo "Колонка recommendation уже есть в {$table}\n";
        continue;
    }
    $sql = "ALTER TABLE `{$tableEsc}` ADD COLUMN `recommendation` VARCHAR(150) NOT NULL DEFAULT ''";
    if (!$mysqli->query($sql)) {
        fwrite(STDERR, "Ошибка SQL ({$table}): {$mysqli->error}\n");
        exit(1);
    }
    echo "Добавлена колонка recommendation в {$table}\n";
}

exit(0);
