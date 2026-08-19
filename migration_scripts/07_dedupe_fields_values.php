<?php
/**
 * Удаление дубликатов в joomla_fields_values: для каждого (field_id, item_id)
 * оставляется одна строка (с минимальным id), остальные удаляются.
 *
 * Запуск из public_html:
 *   php migration_scripts/07_dedupe_fields_values.php
 *   php migration_scripts/07_dedupe_fields_values.php --dry-run
 */
error_reporting(E_ALL);
ini_set('display_errors', '1');
mysqli_report(MYSQLI_REPORT_OFF);

$base = dirname(__DIR__);
$dryRun = in_array('--dry-run', $argv ?? [], true);

require_once $base . '/migration_scripts/load_j6_config.php';
$cfg = loadJ6Config($base . '/configuration.php');
if (!$cfg || empty($cfg->db)) {
    die("Не удалось прочитать J6 configuration.php\n");
}

$conn = @new mysqli($cfg->host, $cfg->user, $cfg->password, $cfg->db);
if ($conn->connect_error) {
    die("Ошибка: " . $conn->connect_error . "\n");
}
$conn->set_charset('utf8mb4');

$prefix = $cfg->dbprefix ?? 'joomla_';
$table = $prefix . 'fields_values';

$res = $conn->query("
    SELECT COUNT(*) AS groups_count, COALESCE(SUM(cnt - 1), 0) AS rows_to_delete
    FROM (
        SELECT field_id, item_id, COUNT(*) AS cnt
        FROM `{$table}`
        GROUP BY field_id, item_id
        HAVING cnt > 1
    ) t
");
if (!$res) {
    echo "Ошибка запроса: " . $conn->error . "\n";
    $conn->close();
    exit(1);
}
$row = $res->fetch_assoc();
$rowsToDelete = (int) ($row['rows_to_delete'] ?? 0);
if (!$row || $rowsToDelete === 0) {
    echo "Дубликатов не найдено.\n";
    $conn->close();
    exit(0);
}

echo "Пар (field_id, item_id) с дубликатами: " . (int)($row['groups_count'] ?? 0) . "\n";
echo "Строк к удалению: " . $rowsToDelete . "\n";

if ($dryRun) {
    echo "[dry-run] Удаление не выполнялось.\n";
    $conn->close();
    exit(0);
}

$hasId = false;
$resCols = $conn->query("SHOW COLUMNS FROM `{$table}`");
if ($resCols) {
    while ($c = $resCols->fetch_assoc()) {
        if ($c['Field'] === 'id') {
            $hasId = true;
            break;
        }
    }
}

if ($hasId) {
    $chunkSize = 50000;
    $totalDeleted = 0;
    while (true) {
        $conn->query("
            DELETE FROM `{$table}` WHERE id IN (
                SELECT ids FROM (
                    SELECT t1.id AS ids FROM `{$table}` t1
                    INNER JOIN `{$table}` t2
                    ON t1.field_id = t2.field_id AND t1.item_id = t2.item_id AND t1.id > t2.id
                    LIMIT " . (int)$chunkSize . "
                ) tmp
            )
        ");
        if ($conn->error) {
            echo "Ошибка: " . $conn->error . "\n";
            break;
        }
        $n = $conn->affected_rows;
        $totalDeleted += $n;
        if ($n > 0) {
            echo date('H:i:s') . " удалено: {$n}, всего: {$totalDeleted}\n";
        }
        if ($n < $chunkSize) {
            break;
        }
    }
    echo "Готово. Удалено дубликатов: {$totalDeleted}\n";
} else {
    echo "Таблица без колонки id — используем временную таблицу.\n";
    $conn->query("DROP TEMPORARY TABLE IF EXISTS _fv_dedupe");
    $conn->query("
        CREATE TEMPORARY TABLE _fv_dedupe AS
        SELECT field_id, item_id, MIN(value) AS value
        FROM `{$table}`
        GROUP BY field_id, item_id
    ");
    if ($conn->error) {
        echo "Ошибка создания временной таблицы: " . $conn->error . "\n";
        $conn->close();
        exit(1);
    }
    echo date('H:i:s') . " временная таблица создана\n";
    $conn->query("DELETE FROM `{$table}`");
    $deleted = $conn->affected_rows;
    echo date('H:i:s') . " очищена основная таблица\n";
    $conn->query("INSERT INTO `{$table}` (field_id, item_id, value) SELECT field_id, item_id, value FROM _fv_dedupe");
    if ($conn->error) {
        echo "Ошибка вставки: " . $conn->error . "\n";
        $conn->close();
        exit(1);
    }
    echo "Готово. Удалено дубликатов: {$deleted}, оставлено строк: " . $conn->affected_rows . "\n";
    $conn->query("DROP TEMPORARY TABLE IF EXISTS _fv_dedupe");
}
$conn->close();
