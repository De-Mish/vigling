<?php
/**
 * Устанавливает шаблон ryba стилем по умолчанию для сайта (client_id=0).
 * Запуск из public_html: php migration_scripts/11_set_ryba_default.php
 */
error_reporting(E_ALL);
ini_set('display_errors', '1');

$baseDir = dirname(__DIR__);
if (!is_file($baseDir . '/configuration.php')) {
    fwrite(STDERR, "configuration.php not found\n");
    exit(1);
}
require_once __DIR__ . '/load_j6_config.php';
$cfg = loadJ6Config($baseDir . '/configuration.php');
if (!$cfg || empty($cfg->db)) {
    fwrite(STDERR, "Could not load config\n");
    exit(1);
}

$mysqli = new mysqli($cfg->host, $cfg->user, $cfg->password, $cfg->db);
if ($mysqli->connect_error) {
    fwrite(STDERR, "DB connect error: " . $mysqli->connect_error . "\n");
    exit(1);
}
$mysqli->set_charset('utf8mb4');
$prefix = $cfg->dbprefix ?? 'joomla_';
$tbl = $prefix . 'template_styles';

$mysqli->query("UPDATE `$tbl` SET home = 0 WHERE client_id = 0");
$mysqli->query("UPDATE `$tbl` SET home = 1 WHERE client_id = 0 AND template = 'ryba'");
$affected = $mysqli->affected_rows;
$mysqli->close();

echo "Ryba set as default site template. Rows updated: $affected\n";
