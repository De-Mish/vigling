<?php
error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('log_errors', '1');

$isCli = php_sapi_name() === 'cli';
$log = [];

function logStep(array &$log, $msg, $isCli) {
    $log[] = '[' . date('Y-m-d H:i:s') . '] ' . $msg;
    if ($isCli) echo $msg . "\n";
}

function outputLog(array $log, $isCli) {
    if ($isCli) return;
    header('Content-Type: text/html; charset=utf-8');
    echo "<pre>" . htmlspecialchars(implode("\n", $log)) . "</pre>";
}

logStep($log, '02_compare_table_structure: старт', $isCli);

$baseOld = dirname(__DIR__);
$baseNew = $baseOld . '/jom_6';
logStep($log, '  base old: ' . $baseOld, $isCli);
logStep($log, '  base new: ' . $baseNew, $isCli);

if (!is_file($baseNew . '/configuration.php')) {
    logStep($log, '  ОШИБКА: не найден jom_6/configuration.php', $isCli);
    outputLog($log, $isCli);
    exit(1);
}

try {
    require_once $baseOld . '/configuration.php';
    $cfgOld = new JConfig();
    logStep($log, '  J3 config загружен, db: ' . $cfgOld->db, $isCli);
} catch (Throwable $e) {
    logStep($log, '  ОШИБКА загрузки J3 config: ' . $e->getMessage(), $isCli);
    outputLog($log, $isCli);
    exit(1);
}

require_once __DIR__ . '/load_j6_config.php';
$cfgNew = loadJ6Config($baseNew . '/configuration.php');
if (!$cfgNew || empty($cfgNew->db)) {
    logStep($log, '  ОШИБКА: не удалось прочитать J6 config из ' . $baseNew . '/configuration.php', $isCli);
    outputLog($log, $isCli);
    exit(1);
}
logStep($log, '  J6 config загружен (парсер), db: ' . $cfgNew->db, $isCli);

$table = isset($argv[1]) ? $argv[1] : (isset($_GET['table']) ? $_GET['table'] : 'users');
$table = preg_replace('/[^a-z0-9_]/', '', strtolower($table));
if ($table === '') $table = 'users';
logStep($log, '  таблица (без префикса): ' . $table, $isCli);

$prefixOld = $cfgOld->dbprefix;
$prefixNew = $cfgNew->dbprefix;
$nameOld = $prefixOld . $table;
$nameNew = $prefixNew . $table;
logStep($log, '  J3 полное имя: ' . $nameOld, $isCli);
logStep($log, '  J6 полное имя: ' . $nameNew, $isCli);

$connOld = @new mysqli($cfgOld->host, $cfgOld->user, $cfgOld->password, $cfgOld->db);
if ($connOld->connect_error) {
    logStep($log, '  ОШИБКА подключения к J3: ' . $connOld->connect_error, $isCli);
    outputLog($log, $isCli);
    exit(1);
}
logStep($log, '  Подключение к J3 OK', $isCli);
$connOld->set_charset('utf8mb4');

$connNew = @new mysqli($cfgNew->host, $cfgNew->user, $cfgNew->password, $cfgNew->db);
if ($connNew->connect_error) {
    logStep($log, '  ОШИБКА подключения к J6: ' . $connNew->connect_error, $isCli);
    $connOld->close();
    outputLog($log, $isCli);
    exit(1);
}
logStep($log, '  Подключение к J6 OK', $isCli);
$connNew->set_charset('utf8mb4');

$r = $connOld->query("SHOW TABLES LIKE '" . $connOld->real_escape_string($nameOld) . "'");
if (!$r || $r->num_rows === 0) {
    logStep($log, '  ОШИБКА: в J3 таблица ' . $nameOld . ' не найдена', $isCli);
    $connOld->close();
    $connNew->close();
    outputLog($log, $isCli);
    exit(1);
}
logStep($log, '  В J3 таблица ' . $nameOld . ' есть', $isCli);

$r = $connNew->query("SHOW TABLES LIKE '" . $connNew->real_escape_string($nameNew) . "'");
if (!$r || $r->num_rows === 0) {
    logStep($log, '  ОШИБКА: в J6 таблица ' . $nameNew . ' не найдена', $isCli);
    $connOld->close();
    $connNew->close();
    outputLog($log, $isCli);
    exit(1);
}
logStep($log, '  В J6 таблица ' . $nameNew . ' есть', $isCli);

$columns = function ($conn, $tname) use (&$log, $isCli) {
    $r = $conn->query("SHOW COLUMNS FROM `" . $conn->real_escape_string($tname) . "`");
    if (!$r) {
        $log[] = '  ОШИБКА SHOW COLUMNS: ' . $conn->error;
        return [];
    }
    $out = [];
    while ($row = $r->fetch_assoc()) {
        $out[$row['Field']] = $row['Type'] . ($row['Null'] === 'YES' ? ' NULL' : ' NOT NULL');
    }
    return $out;
};

$colOld = $columns($connOld, $nameOld);
$colNew = $columns($connNew, $nameNew);
logStep($log, '  Колонок в J3: ' . count($colOld), $isCli);
logStep($log, '  Колонок в J6: ' . count($colNew), $isCli);

$connOld->close();
$connNew->close();

$common = array_intersect_key($colOld, $colNew);
$onlyOld = array_diff_key($colOld, $colNew);
$onlyNew = array_diff_key($colNew, $colOld);

$log[] = '';
$log[] = 'Таблица: ' . $nameOld . ' (J3) vs ' . $nameNew . ' (J6)';
$log[] = str_repeat('-', 60);
$log[] = 'Общие колонки (можно копировать): ' . count($common);
foreach ($common as $c => $type) $log[] = '  ' . $c;
$log[] = '';
$log[] = 'Только в J3 (при переносе пропустятся): ' . count($onlyOld);
foreach ($onlyOld as $c => $type) $log[] = '  ' . $c . ' (' . $type . ')';
$log[] = '';
$log[] = 'Только в J6 (останутся default/NULL): ' . count($onlyNew);
foreach ($onlyNew as $c => $type) $log[] = '  ' . $c . ' (' . $type . ')';
$log[] = '';
$log[] = '02_compare_table_structure: завершено успешно';

$out = implode("\n", $log);
if ($isCli) {
    echo $out . "\n";
} else {
    header('Content-Type: text/html; charset=utf-8');
    echo "<pre>" . htmlspecialchars($out) . "</pre>";
}
