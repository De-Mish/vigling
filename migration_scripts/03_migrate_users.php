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
    header('Content-Type: text/plain; charset=utf-8');
    echo "<pre>" . htmlspecialchars(implode("\n", $log)) . "</pre>";
}

logStep($log, '03_migrate_users: старт', $isCli);

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

$dry = isset($_GET['dry']) && $_GET['dry'] === '1' || isset($argv[1]) && $argv[1] === 'dry';
logStep($log, '  dry-run: ' . ($dry ? 'да (запись в J6 отключена)' : 'нет'), $isCli);

$connOld = @new mysqli($cfgOld->host, $cfgOld->user, $cfgOld->password, $cfgOld->db);
if ($connOld->connect_error) {
    logStep($log, '  ОШИБКА подключения к J3: ' . $connOld->connect_error . ' (errno: ' . $connOld->connect_errno . ')', $isCli);
    outputLog($log, $isCli);
    exit(1);
}
logStep($log, '  Подключение к J3 OK', $isCli);
$connOld->set_charset('utf8mb4');

$connNew = @new mysqli($cfgNew->host, $cfgNew->user, $cfgNew->password, $cfgNew->db);
if ($connNew->connect_error) {
    logStep($log, '  ОШИБКА подключения к J6: ' . $connNew->connect_error . ' (errno: ' . $connNew->connect_errno . ')', $isCli);
    $connOld->close();
    outputLog($log, $isCli);
    exit(1);
}
logStep($log, '  Подключение к J6 OK', $isCli);
$connNew->set_charset('utf8mb4');

$prefixOld = $cfgOld->dbprefix;
$prefixNew = $cfgNew->dbprefix;

function getColumns($conn, $table) {
    $r = $conn->query("SHOW COLUMNS FROM `" . $conn->real_escape_string($table) . "`");
    if (!$r) return [];
    $out = [];
    while ($row = $r->fetch_assoc()) $out[] = $row['Field'];
    return $out;
}

function tableExists($conn, $table) {
    $r = $conn->query("SHOW TABLES LIKE '" . $conn->real_escape_string($table) . "'");
    return $r && $r->num_rows > 0;
}

foreach (['users' => 'users', 'user_profiles' => 'user_profiles'] as $short => $dummy) {
    logStep($log, '  Обработка таблицы: ' . $short, $isCli);
    $tOld = $prefixOld . $short;
    $tNew = $prefixNew . $short;

    if (!tableExists($connOld, $tOld)) {
        logStep($log, '    Пропуск: в J3 нет таблицы ' . $tOld, $isCli);
        continue;
    }
    logStep($log, '    В J3 таблица ' . $tOld . ' есть', $isCli);

    if (!tableExists($connNew, $tNew)) {
        logStep($log, '    Пропуск: в J6 нет таблицы ' . $tNew, $isCli);
        continue;
    }
    logStep($log, '    В J6 таблица ' . $tNew . ' есть', $isCli);

    $colOld = getColumns($connOld, $tOld);
    $colNew = getColumns($connNew, $tNew);
    logStep($log, '    Колонок J3: ' . count($colOld) . ', J6: ' . count($colNew), $isCli);

    $common = array_intersect($colOld, $colNew);
    $common = array_values($common);
    if (empty($common)) {
        logStep($log, '    Пропуск: нет общих колонок', $isCli);
        continue;
    }
    logStep($log, '    Общих колонок: ' . count($common) . ' (' . implode(', ', array_slice($common, 0, 5)) . (count($common) > 5 ? '...' : '') . ')', $isCli);

    $colsList = '`' . implode('`,`', $common) . '`';
    $res = $connOld->query("SELECT " . $colsList . " FROM `" . $tOld . "`");
    if (!$res) {
        logStep($log, '    ОШИБКА SELECT из ' . $tOld . ': ' . $connOld->error, $isCli);
        continue;
    }
    $totalRows = $res->num_rows;
    logStep($log, '    Выбрано строк из J3: ' . $totalRows, $isCli);

    $count = 0;
    if (!$dry) {
        $del = $connNew->query("DELETE FROM `" . $tNew . "`");
        logStep($log, '    DELETE из ' . $tNew . ': ' . ($del ? 'OK, затронуто ' . $connNew->affected_rows : 'ОШИБКА ' . $connNew->error), $isCli);
    }

    $placeholders = implode(',', array_fill(0, count($common), '?'));
    $stmt = $connNew->prepare("INSERT INTO `" . $tNew . "` (" . $colsList . ") VALUES ($placeholders)");
    if (!$stmt && !$dry) {
        logStep($log, '    ОШИБКА PREPARE для ' . $tNew . ': ' . $connNew->error, $isCli);
        continue;
    }
    if ($stmt) logStep($log, '    PREPARE INSERT OK', $isCli);

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
            } else {
                $count++;
            }
        } else {
            $count++;
        }
    }
    logStep($log, '    Таблица ' . $short . ': обработано записей: ' . $count . ($dry ? ' (dry-run)' : ''), $isCli);
    if ($stmt) $stmt->close();
}

if (!$dry) {
    $r = $connNew->query("SELECT COUNT(*) FROM `" . $prefixNew . "users`");
    if ($r && ($row = $r->fetch_row())) {
        logStep($log, '  Проверка: в J6 в joomla_users сейчас записей: ' . $row[0], $isCli);
        if ((int) $row[0] !== 92556 && (int) $row[0] < 90000) {
            logStep($log, '  ВНИМАНИЕ: ожидалось 92556, возможна ошибка при вставке.', $isCli);
        }
    }
}

$connOld->close();
$connNew->close();

logStep($log, '03_migrate_users: завершено', $isCli);
outputLog($log, $isCli);
