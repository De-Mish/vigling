<?php
/**
 * Удаляет плагин System - Vigling из БД (функционал перенесён в User - Vigling).
 * Запуск: php migration_scripts/10_unregister_system_vigling.php
 */
$base = dirname(__DIR__);
require_once __DIR__ . '/load_j6_config.php';
$cfg = loadJ6Config($base . '/configuration.php');
if (!$cfg || empty($cfg->db)) {
    die("Ошибка: не найден configuration.php.\n");
}
$mysqli = new mysqli($cfg->host, $cfg->user, $cfg->password, $cfg->db);
if ($mysqli->connect_error) {
    die("Ошибка подключения к БД: " . $mysqli->connect_error . "\n");
}
$mysqli->set_charset('utf8mb4');
$prefix = $cfg->dbprefix;
$table  = $prefix . 'extensions';
$r = $mysqli->query("DELETE FROM `{$table}` WHERE type = 'plugin' AND folder = 'system' AND element = 'vigling'");
if ($r && $mysqli->affected_rows > 0) {
    echo "Плагин System - Vigling удалён из БД.\n";
} else {
    echo "Плагин System - Vigling не найден в БД или уже удалён.\n";
}
$mysqli->close();
