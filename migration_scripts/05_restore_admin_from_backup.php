<?php
/**
 * Восстанавливает в J6 (vigl2) только админ-модули и админ-стили шаблонов
 * из бэкап-БД, из дампа .sql/.sql.gz или из БД чистой J6. Сайтовые модули (client_id=0) не трогает.
 *
 * Варианты:
 * 1) БД viglinbd_vigl2_backup — если доступна.
 * 2) Дамп: ищет dump.sql.gz в public_html, в родителе, или путь в env DUMP_FILE.
 * 3) Чистая J6: php 05_restore_admin_from_backup.php /путь/к/чистой/j6/configuration.php
 *    (или env FRESH_J6_CONFIG). Установите чистую J6 в подпапку с отдельной БД, укажите её config.
 *
 * Запуск из public_html:
 *   php migration_scripts/05_restore_admin_from_backup.php
 *   php migration_scripts/05_restore_admin_from_backup.php /path/to/fresh_j6/configuration.php
 */
error_reporting(E_ALL);
ini_set('display_errors', '1');
mysqli_report(MYSQLI_REPORT_OFF);

$isCli = php_sapi_name() === 'cli';
$log = [];

function logStep(array &$log, $msg, $isCli) {
    $log[] = '[' . date('Y-m-d H:i:s') . '] ' . $msg;
    if ($isCli) echo $msg . "\n";
}

function parseSqlValue($raw) {
    $raw = trim($raw);
    if (strtoupper($raw) === 'NULL') return null;
    if (strlen($raw) >= 2 && ($raw[0] === "'" || $raw[0] === '"')) {
        return str_replace(["\\'", '\\"', '\\\\'], ["'", '"', '\\'], substr($raw, 1, -1));
    }
    return $raw;
}

function parseRowValues($str) {
    $vals = [];
    $len = strlen($str);
    $i = 0;
    while ($i < $len) {
        while ($i < $len && ($str[$i] === ' ' || $str[$i] === "\t")) $i++;
        if ($i >= $len) break;
        if ($str[$i] === "'" || $str[$i] === '"') {
            $q = $str[$i++];
            $v = '';
            while ($i < $len) {
                if ($str[$i] === '\\') { $v .= $str[$i + 1] ?? ''; $i += 2; continue; }
                if ($str[$i] === $q) { $i++; break; }
                $v .= $str[$i++];
            }
            $vals[] = $v;
        } else {
            $v = '';
            while ($i < $len && $str[$i] !== ',' && $str[$i] !== ')') $v .= $str[$i++];
            $vals[] = parseSqlValue(trim($v));
            if ($i < $len && $str[$i] === ',') $i++;
        }
    }
    return $vals;
}

function extractInsertRowsWithClientId1($insertSql, $tableName, $clientCol) {
    $q = preg_quote($tableName, '/');
    if (!preg_match('/INSERT\s+(?:IGNORE\s+)?INTO\s+[`\']?' . $q . '[`\']?\s*\(([^)]+)\)\s*VALUES\s*(.+)$/si', $insertSql, $m)) return [null, null, []];
    $colStr = $m[1];
    $valsStr = trim($m[2]);
    if (substr($valsStr, -1) === ';') $valsStr = substr($valsStr, 0, -1);
    $cols = array_map(function ($c) { return strtolower(trim(str_replace('`', '', $c))); }, explode(',', $colStr));
    $clientIdx = array_search(strtolower($clientCol), $cols);
    if ($clientIdx === false) return [null, null, []];
    $rows = [];
    $depth = 0;
    $inQuote = false;
    $quoteCh = null;
    $start = 0;
    $len = strlen($valsStr);
    for ($i = 0; $i < $len; $i++) {
        $c = $valsStr[$i];
        if ($inQuote) {
            if ($c === '\\') { $i++; continue; }
            if ($c === $quoteCh) { $inQuote = false; }
            continue;
        }
        if ($c === "'" || $c === '"') { $inQuote = true; $quoteCh = $c; continue; }
        if ($c === '(') {
            if ($depth === 0) $start = $i + 1;
            $depth++;
            continue;
        }
        if ($c === ')') {
            $depth--;
            if ($depth === 0) {
                $rowStr = substr($valsStr, $start, $i - $start);
                $vals = parseRowValues($rowStr);
                if (isset($vals[$clientIdx]) && ((string)$vals[$clientIdx] === '1' || $vals[$clientIdx] === 1)) {
                    $rows[] = array_combine($cols, $vals);
                }
            }
            continue;
        }
    }
    return [$cols, $clientIdx, $rows];
}

function parseDumpForAdminTables($dumpPath, $tables, &$debugSnippet = null) {
    $result = [];
    $debugSnippet = null;
    $isGz = (substr($dumpPath, -3) === '.gz');
    if ($isGz) {
        $fp = @gzopen($dumpPath, 'rb');
        if (!$fp) return $result;
        $read = function () use ($fp) { return gzread($fp, 65536); };
        $eof = function () use ($fp) { return gzeof($fp); };
        $close = function () use ($fp) { gzclose($fp); };
    } else {
        $fp = @fopen($dumpPath, 'rb');
        if (!$fp) return $result;
        $read = function () use ($fp) { return fread($fp, 65536); };
        $eof = function () use ($fp) { return feof($fp); };
        $close = function () use ($fp) { fclose($fp); };
    }
    $buf = '';
    while (!$eof()) {
        $buf .= $read();
        while (($pos = strpos($buf, 'INSERT INTO')) !== false) {
            $semicolon = strpos($buf, ';', $pos);
            if ($semicolon === false) break;
            $stmt = substr($buf, $pos, $semicolon - $pos + 1);
            $buf = substr($buf, $semicolon + 1);
            foreach ($tables as $t => $clientCol) {
                if (stripos($stmt, $t) !== false && (stripos($stmt, '`' . $t . '`') !== false || preg_match('/INSERT\s+(?:IGNORE\s+)?INTO\s+[`\']?' . preg_quote($t, '/') . '/i', $stmt))) {
                    list($cols, $idx, $rows) = extractInsertRowsWithClientId1($stmt, $t, $clientCol);
                    if ($cols !== null && !empty($rows)) {
                        if (!isset($result[$t])) $result[$t] = [];
                        $result[$t] = array_merge($result[$t], $rows);
                    } elseif ($cols !== null && $debugSnippet === null) {
                        $debugSnippet = substr(preg_replace('/\s+/', ' ', $stmt), 0, 600);
                    }
                    break;
                }
            }
        }
    }
    $close();
    return $result;
}

$baseNew = dirname(__DIR__);
$configPath = realpath($baseNew . '/configuration.php') ?: $baseNew . '/configuration.php';
$dumpPaths = [
    $baseNew . '/dump.sql.gz',
    $baseNew . '/dump.sql',
    dirname($baseNew) . '/dump.sql.gz',
    dirname($baseNew) . '/dump.sql',
];
if (getenv('DUMP_FILE')) $dumpPaths[] = getenv('DUMP_FILE');
$freshJ6ConfigPath = isset($argv[1]) ? $argv[1] : getenv('FRESH_J6_CONFIG');

require_once __DIR__ . '/load_j6_config.php';
$cfgCurrent = loadJ6Config($configPath);
if (!$cfgCurrent || empty($cfgCurrent->db)) {
    die("Не удалось прочитать J6 config. Путь: " . $configPath . "\n");
}

$backupDb = 'viglinbd_vigl2_backup';
$backupUser = $cfgCurrent->user;
$backupPass = $cfgCurrent->password;
$backupHost = $cfgCurrent->host;
$prefix = $cfgCurrent->dbprefix;

logStep($log, '05_restore_admin_from_backup: старт', $isCli);
logStep($log, '  Текущая БД (J6): ' . $cfgCurrent->db, $isCli);
logStep($log, '  Бэкап БД: ' . $backupDb, $isCli);

$connCurrent = @new mysqli($cfgCurrent->host, $cfgCurrent->user, $cfgCurrent->password, $cfgCurrent->db);
if ($connCurrent->connect_error) {
    logStep($log, '  ОШИБКА подключения к текущей БД: ' . $connCurrent->connect_error, $isCli);
    exit(1);
}
$connCurrent->set_charset('utf8mb4');

$connBackup = @new mysqli($backupHost, $backupUser, $backupPass, $backupDb);
$useDumpFile = false;
$rowsFromDump = [];

if ($connBackup->connect_error) {
    logStep($log, '  Бэкап БД недоступна (' . $connBackup->connect_error . '). Пробуем дамп или чистую J6.', $isCli);
    $connBackup = null;
    $dumpFound = null;
    foreach ($dumpPaths as $p) {
        if (!empty($p) && file_exists($p) && is_readable($p)) {
            $dumpFound = $p;
            $debugSnippet = null;
            $rowsFromDump = parseDumpForAdminTables($p, ['joomla_modules' => 'client_id', 'joomla_template_styles' => 'client_id'], $debugSnippet);
            $total = isset($rowsFromDump['joomla_modules']) ? count($rowsFromDump['joomla_modules']) : 0;
            $total += isset($rowsFromDump['joomla_template_styles']) ? count($rowsFromDump['joomla_template_styles']) : 0;
            if ($total > 0) {
                $useDumpFile = true;
                logStep($log, '  Дамп: ' . $p . ' (' . filesize($p) . ' байт). Извлечено client_id=1: modules=' . (isset($rowsFromDump['joomla_modules']) ? count($rowsFromDump['joomla_modules']) : 0) . ', template_styles=' . (isset($rowsFromDump['joomla_template_styles']) ? count($rowsFromDump['joomla_template_styles']) : 0), $isCli);
                break;
            }
            logStep($log, '  Файл найден, записей client_id=1 не найдено (формат дампа?): ' . $p, $isCli);
            if ($debugSnippet !== null) logStep($log, '  Начало INSERT из дампа: ' . $debugSnippet . '...', $isCli);
        }
    }
    if (!$useDumpFile && $freshJ6ConfigPath) {
        $configPathToTry = $freshJ6ConfigPath;
        if (!is_file($configPathToTry) && $baseNew && ($configPathToTry[0] ?? '') === '/') {
            $configPathToTry = rtrim($baseNew, '/') . $freshJ6ConfigPath;
        }
        if (!is_file($configPathToTry)) {
            $configPathToTry = $baseNew . '/' . ltrim($freshJ6ConfigPath, '/');
        }
        if (is_file($configPathToTry)) {
            $cfgFresh = loadJ6Config($configPathToTry);
            if ($cfgFresh && !empty($cfgFresh->db)) {
                $connBackup = @new mysqli($cfgFresh->host, $cfgFresh->user, $cfgFresh->password, $cfgFresh->db);
                if (!$connBackup->connect_error) {
                    $connBackup->set_charset('utf8mb4');
                    logStep($log, '  Используем БД чистой J6: ' . $cfgFresh->db . ' (config: ' . $configPathToTry . ')', $isCli);
                } else {
                    $connBackup = null;
                }
            }
        }
    }
    if (!$useDumpFile && (!$connBackup || $connBackup->connect_error)) {
        logStep($log, '  ОШИБКА: дамп не найден/пуст и чистая J6 не указана или недоступна.', $isCli);
        logStep($log, '  Варианты: 1) Положите дамп dump.sql.gz в корень сайта или укажите DUMP_FILE=/путь. 2) Установите чистую J6 в подпапку (другая БД), затем: php ' . basename(__FILE__) . ' /путь/к/чистой/j6/configuration.php', $isCli);
        $connCurrent->close();
        exit(1);
    }
} else {
    $connBackup->set_charset('utf8mb4');
}

foreach (
    [
        'joomla_modules' => 'client_id',
        'joomla_template_styles' => 'client_id',
    ] as $table => $clientCol
) {
    logStep($log, '  Таблица: ' . $table, $isCli);
    $safeTable = $connCurrent->real_escape_string($table);
    if ($useDumpFile) {
        $rows = isset($rowsFromDump[$table]) ? $rowsFromDump[$table] : [];
        logStep($log, '    Из дампа записей client_id=1: ' . count($rows), $isCli);
    } else {
        $res = $connBackup->query("SELECT * FROM `{$safeTable}` WHERE `{$clientCol}` = 1");
        if (!$res) {
            logStep($log, '    ОШИБКА SELECT из бэкапа: ' . $connBackup->error, $isCli);
            continue;
        }
        $rows = [];
        while ($row = $res->fetch_assoc()) {
            $rows[] = $row;
        }
        logStep($log, '    В бэкапе записей client_id=1: ' . count($rows), $isCli);
    }

    $connCurrent->query("DELETE FROM `{$safeTable}` WHERE `{$clientCol}` = 1");
    $deleted = $connCurrent->affected_rows;
    logStep($log, '    Удалено в текущей БД (client_id=1): ' . $deleted, $isCli);

    if (empty($rows)) {
        logStep($log, '    Вставлять нечего.', $isCli);
        continue;
    }

    $resCols = $connCurrent->query("SHOW COLUMNS FROM `{$safeTable}`");
    $targetCols = [];
    while ($r = $resCols->fetch_assoc()) $targetCols[strtolower($r['Field'])] = true;
    $sourceColumns = array_keys($rows[0]);
    $columns = array_values(array_filter($sourceColumns, function ($c) use ($targetCols) { return isset($targetCols[strtolower($c)]); }));
    $skippedCols = array_diff($sourceColumns, $columns);
    if (!empty($skippedCols)) logStep($log, '    Колонки источника, отсутствующие в целевой таблице (пропущены): ' . implode(', ', $skippedCols), $isCli);
    if (empty($columns)) {
        logStep($log, '    ОШИБКА: нет общих колонок с целевой таблицей.', $isCli);
        continue;
    }

    $hasId = isset($targetCols['id']);
    if ($hasId) {
        $resMax = $connCurrent->query("SELECT COALESCE(MAX(`id`), 0) AS mx FROM `{$safeTable}`");
        $maxId = $resMax ? (int)(($resMax->fetch_assoc())['mx'] ?? 0) : 0;
        foreach ($rows as $idx => $row) {
            $rows[$idx]['id'] = (string)($maxId + 1 + $idx);
        }
        logStep($log, '    Переназначены id для избежания дубликатов (max_id=' . $maxId . ', новых: ' . count($rows) . ')', $isCli);
    }

    $colsList = '`' . implode('`,`', $columns) . '`';
    $placeholders = implode(',', array_fill(0, count($columns), '?'));
    $stmt = $connCurrent->prepare("INSERT INTO `{$safeTable}` ({$colsList}) VALUES ({$placeholders})");
    if (!$stmt) {
        logStep($log, '    ОШИБКА PREPARE: ' . $connCurrent->error, $isCli);
        continue;
    }
    $count = 0;
    $firstErr = null;
    foreach ($rows as $idx => $row) {
        $vals = [];
        foreach ($columns as $c) $vals[] = $row[$c] ?? null;
        $types = str_repeat('s', count($vals));
        $refs = [&$types];
        foreach ($vals as $k => $v) {
            $refs[] = &$vals[$k];
        }
        call_user_func_array([$stmt, 'bind_param'], $refs);
        if ($stmt->execute()) {
            $count++;
        } else {
            if ($firstErr === null) $firstErr = $stmt->error;
        }
    }
    $stmt->close();
    if ($firstErr !== null) logStep($log, '    Ошибки при вставке (первая): ' . $firstErr, $isCli);
    logStep($log, '    Вставлено в текущую БД: ' . $count . ' из ' . count($rows), $isCli);

    if ($table === 'joomla_modules' && $count > 0 && $hasId && isset($maxId)) {
        $resMmCols = $connCurrent->query("SHOW COLUMNS FROM `joomla_modules_menu`");
        $mmCols = [];
        while ($r = $resMmCols->fetch_assoc()) $mmCols[strtolower($r['Field'])] = true;
        if (isset($mmCols['moduleid']) && isset($mmCols['menuid'])) {
            $ins = 0;
            for ($i = 0; $i < $count; $i++) {
                $mid = $maxId + 1 + $i;
                if ($connCurrent->query("INSERT INTO `joomla_modules_menu` (`moduleid`,`menuid`) VALUES (" . (int)$mid . ", 0)")) $ins++;
            }
            logStep($log, '    joomla_modules_menu: привязка к меню (menuid=0 для всех): ' . $ins . ' строк', $isCli);
        }
    }
}

$connCurrent->close();
if ($connBackup) $connBackup->close();
logStep($log, '05_restore_admin_from_backup: завершено', $isCli);
if ($isCli) {
    echo implode("\n", $log) . "\n";
} else {
    header('Content-Type: text/plain; charset=utf-8');
    echo "<pre>" . htmlspecialchars(implode("\n", $log)) . "</pre>";
}
