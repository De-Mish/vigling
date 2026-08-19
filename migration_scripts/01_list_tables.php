<?php
error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('log_errors', '1');

$isCli = php_sapi_name() === 'cli';
$log = [];

function logStep(array &$log, $msg, $isCli) {
    $log[] = '[' . date('Y-m-d H:i:s') . '] ' . $msg;
    if ($isCli) {
        echo $msg . "\n";
    }
}

logStep($log, '01_list_tables: старт', $isCli);

$base = dirname(__DIR__);
logStep($log, '  base path: ' . $base, $isCli);

if (!is_file($base . '/configuration.php')) {
    logStep($log, '  ОШИБКА: не найден configuration.php', $isCli);
    outputLog($log, $isCli);
    exit(1);
}
logStep($log, '  configuration.php найден', $isCli);

define('_JEXEC', 1);
define('JPATH_BASE', $base);
try {
    require_once $base . '/configuration.php';
    logStep($log, '  configuration.php подключён', $isCli);
} catch (Throwable $e) {
    logStep($log, '  ОШИБКА require configuration: ' . $e->getMessage(), $isCli);
    outputLog($log, $isCli);
    exit(1);
}

if (!class_exists('JConfig')) {
    logStep($log, '  ОШИБКА: класс JConfig не найден в configuration.php', $isCli);
    outputLog($log, $isCli);
    exit(1);
}
logStep($log, '  класс JConfig найден', $isCli);

$config = new JConfig();
logStep($log, '  БД: ' . $config->db . ', host: ' . $config->host . ', prefix: ' . $config->dbprefix, $isCli);

$conn = @new mysqli($config->host, $config->user, $config->password, $config->db);
if ($conn->connect_error) {
    logStep($log, '  ОШИБКА подключения к БД: ' . $conn->connect_error . ' (errno: ' . $conn->connect_errno . ')', $isCli);
    outputLog($log, $isCli);
    exit(1);
}
logStep($log, '  Подключение к БД успешно', $isCli);

$conn->set_charset('utf8mb4');
$prefix = $conn->real_escape_string($config->dbprefix);
$sql = "SHOW TABLES LIKE '{$prefix}%'";
logStep($log, '  Выполняю: ' . $sql, $isCli);

$res = $conn->query($sql);
if ($res === false) {
    logStep($log, '  ОШИБКА запроса: ' . $conn->error . ' (errno: ' . $conn->errno . ')', $isCli);
    $conn->close();
    outputLog($log, $isCli);
    exit(1);
}
logStep($log, '  Запрос выполнен, строк: ' . $res->num_rows, $isCli);

$tables = [];
while ($row = $res->fetch_array()) {
    $tables[] = $row[0];
}
$conn->close();
logStep($log, '  Таблицы прочитаны: ' . count($tables), $isCli);

logStep($log, '', $isCli);
logStep($log, 'Tables in ' . $config->db . ' (prefix ' . $config->dbprefix . '):', $isCli);
foreach ($tables as $t) {
    logStep($log, '  ' . $t, $isCli);
}
logStep($log, 'Total: ' . count($tables), $isCli);
logStep($log, '01_list_tables: завершено успешно', $isCli);

function outputLog(array $log, $isCli) {
    if ($isCli) return;
    header('Content-Type: text/html; charset=utf-8');
    echo "<pre>" . htmlspecialchars(implode("\n", $log)) . "</pre>";
}

outputLog($log, $isCli);
