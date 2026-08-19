<?php
/**
 * Перенос всех таблиц из J3 (vigl1) в J6 (vigl2).
 * Копируются только таблицы, которые есть в ОБЕИХ БД; для каждой — только общие колонки.
 * Перед запуском — бэкап vigl2. Запуск без dry реально перезаписывает данные в J6.
 *
 * Запуск из корня старого сайта (public_html):
 *   php migration_scripts/04_migrate_all_tables.php
 *   php migration_scripts/04_migrate_all_tables.php dry
 * Или в браузере: .../migration_scripts/04_migrate_all_tables.php?dry=1
 */
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
    header('Content-Type: text/plain; charset=utf-8');
    echo "<pre>" . htmlspecialchars(implode("\n", $log)) . "</pre>";
}

logStep($log, '04_migrate_all_tables: старт', $isCli);

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
    logStep($log, '  ОШИБКА: не удалось прочитать J6 config', $isCli);
    outputLog($log, $isCli);
    exit(1);
}
logStep($log, '  J6 config загружен, db: ' . $cfgNew->db, $isCli);

$dry = isset($_GET['dry']) && $_GET['dry'] === '1' || isset($argv[1]) && $argv[1] === 'dry';
logStep($log, '  dry-run: ' . ($dry ? 'да' : 'нет'), $isCli);

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

if (!$dry) {
    $connNew->query('SET FOREIGN_KEY_CHECKS = 0');
    logStep($log, '  FOREIGN_KEY_CHECKS = 0', $isCli);
}

$prefixOld = $cfgOld->dbprefix;
$prefixNew = $cfgNew->dbprefix;

function getTableList($conn, $prefix) {
    $prefix = $conn->real_escape_string($prefix);
    $r = $conn->query("SHOW TABLES LIKE '{$prefix}%'");
    if (!$r) return [];
    $list = [];
    while ($row = $r->fetch_array()) $list[] = $row[0];
    return $list;
}

function getColumns($conn, $table) {
    $r = $conn->query("SHOW COLUMNS FROM `" . $conn->real_escape_string($table) . "`");
    if (!$r) return [];
    $out = [];
    while ($row = $r->fetch_assoc()) $out[] = $row['Field'];
    return $out;
}

$tablesJ3 = getTableList($connOld, $prefixOld);
$tablesJ6 = getTableList($connNew, $prefixNew);
$tablesJ6Set = array_flip($tablesJ6);
logStep($log, '  Таблиц в J3: ' . count($tablesJ3) . ', в J6: ' . count($tablesJ6), $isCli);

$toCopy = [];
foreach ($tablesJ3 as $t) {
    if (isset($tablesJ6Set[$t])) {
        $toCopy[] = $t;
    }
}
logStep($log, '  Таблиц в обеих БД (будут перенесены): ' . count($toCopy), $isCli);
logStep($log, '', $isCli);

$totalRows = 0;
$errors = 0;
foreach ($toCopy as $tname) {
    logStep($log, '  Таблица: ' . $tname, $isCli);

    $colOld = getColumns($connOld, $tname);
    $colNew = getColumns($connNew, $tname);
    $common = array_intersect($colOld, $colNew);
    $common = array_values($common);
    if (empty($common)) {
        logStep($log, '    Пропуск: нет общих колонок', $isCli);
        continue;
    }

    $colsList = '`' . implode('`,`', $common) . '`';
    $res = $connOld->query("SELECT " . $colsList . " FROM `" . $tname . "`");
    if (!$res) {
        logStep($log, '    ОШИБКА SELECT: ' . $connOld->error, $isCli);
        $errors++;
        continue;
    }
    $numRows = $res->num_rows;
    logStep($log, '    Строк в J3: ' . $numRows . ', общих колонок: ' . count($common), $isCli);

    if (!$dry) {
        $connNew->query("DELETE FROM `" . $tname . "`");
        logStep($log, '    DELETE в J6: OK', $isCli);
    }

    if ($numRows === 0) {
        logStep($log, '    Вставлено: 0', $isCli);
        continue;
    }

    $placeholders = implode(',', array_fill(0, count($common), '?'));
    $stmt = $connNew->prepare("INSERT INTO `" . $tname . "` (" . $colsList . ") VALUES ($placeholders)");
    if (!$stmt && !$dry) {
        logStep($log, '    ОШИБКА PREPARE: ' . $connNew->error, $isCli);
        $errors++;
        continue;
    }

    $count = 0;
    $rowNum = 0;
    while ($row = $res->fetch_row()) {
        $rowNum++;
        if (!$dry && $stmt) {
            $rowCopy = array_values($row);
            $types = str_repeat('s', count($rowCopy));
            $refs = [&$types];
            foreach ($rowCopy as $k => $v) {
                $refs[] = &$rowCopy[$k];
            }
            call_user_func_array([$stmt, 'bind_param'], $refs);
            if (!$stmt->execute()) {
                logStep($log, '    ОШИБКА INSERT строка ' . $rowNum . ': ' . $stmt->error, $isCli);
                $errors++;
            } else {
                $count++;
            }
        } else {
            $count++;
        }
    }
    if ($stmt) $stmt->close();
    $totalRows += $count;
    logStep($log, '    Вставлено в J6: ' . $count, $isCli);
}

if (!$dry) {
    $connNew->query('SET FOREIGN_KEY_CHECKS = 1');
    logStep($log, '', $isCli);
    logStep($log, '  FOREIGN_KEY_CHECKS = 1', $isCli);
}

$connOld->close();
$connNew->close();

logStep($log, '', $isCli);
logStep($log, '  Всего таблиц обработано: ' . count($toCopy), $isCli);
logStep($log, '  Всего строк перенесено: ' . $totalRows, $isCli);
if ($errors > 0) {
    logStep($log, '  Ошибок при вставке: ' . $errors, $isCli);
}
logStep($log, '04_migrate_all_tables: завершено', $isCli);
outputLog($log, $isCli);
