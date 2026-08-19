<?php
/**
 * Импорт ручного mapping из CSV в #__vigling_service_legacy_map.
 *
 * CSV-формат:
 * legacy_source,legacy_id,service_node_id,confidence,note
 *
 * Пример:
 * tag,1897,123,1.000,manual map
 *
 * Запуск:
 *   php migration_scripts/43_import_manual_map_csv.php --file=/var/www/html/migration_reports/uslugi_manual_map_template_*.csv
 */

define('_JEXEC', 1);
$baseDir = dirname(__DIR__);
require_once __DIR__ . '/load_j6_config.php';

function optionString(array $argv, string $key): ?string
{
    foreach ($argv as $arg) {
        if (strpos($arg, $key . '=') === 0) {
            return substr($arg, strlen($key) + 1);
        }
    }
    return null;
}

$file = optionString($argv ?? [], '--file');
if ($file === null || trim($file) === '') {
    fwrite(STDERR, "Нужен путь к CSV: --file=/path/to/file.csv\n");
    exit(1);
}

if (!is_file($file)) {
    fwrite(STDERR, "CSV не найден: {$file}\n");
    exit(1);
}

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
$prefix = $mysqli->real_escape_string($config->dbprefix);

$tMap = $prefix . 'vigling_service_legacy_map';
$tNodes = $prefix . 'vigling_service_nodes';

$nodeExists = [];
$resNodes = $mysqli->query("SELECT id FROM `{$tNodes}`");
while ($resNodes && ($row = $resNodes->fetch_assoc())) {
    $nodeExists[(int) $row['id']] = true;
}
if ($resNodes) {
    $resNodes->free();
}

$fp = fopen($file, 'r');
if (!$fp) {
    fwrite(STDERR, "Не удалось открыть CSV: {$file}\n");
    exit(1);
}

$header = fgetcsv($fp);
if (!$header) {
    fclose($fp);
    fwrite(STDERR, "Пустой CSV: {$file}\n");
    exit(1);
}

$expected = ['legacy_source', 'legacy_id', 'service_node_id', 'confidence', 'note'];
$headerLower = array_map('strtolower', $header);
foreach ($expected as $col) {
    if (!in_array($col, $headerLower, true)) {
        fclose($fp);
        fwrite(STDERR, "CSV должен содержать колонку: {$col}\n");
        exit(1);
    }
}

$idx = [];
foreach ($headerLower as $i => $col) {
    $idx[$col] = $i;
}

$stmt = $mysqli->prepare(
    "INSERT INTO `{$tMap}` (`legacy_source`,`legacy_id`,`service_node_id`,`confidence`,`note`)
     VALUES (?,?,?,?,?)
     ON DUPLICATE KEY UPDATE
       `service_node_id`=VALUES(`service_node_id`),
       `confidence`=VALUES(`confidence`),
       `note`=VALUES(`note`)"
);
if (!$stmt) {
    fclose($fp);
    fwrite(STDERR, "Ошибка prepare: {$mysqli->error}\n");
    exit(1);
}

$stats = [
    'rows_total' => 0,
    'imported' => 0,
    'skipped_empty' => 0,
    'skipped_invalid' => 0,
    'skipped_unknown_node' => 0,
];

while (($row = fgetcsv($fp)) !== false) {
    $stats['rows_total']++;
    $legacySource = trim((string) ($row[$idx['legacy_source']] ?? ''));
    $legacyIdRaw = trim((string) ($row[$idx['legacy_id']] ?? ''));
    $nodeIdRaw = trim((string) ($row[$idx['service_node_id']] ?? ''));
    $confidenceRaw = trim((string) ($row[$idx['confidence']] ?? ''));
    $note = trim((string) ($row[$idx['note']] ?? ''));

    if ($legacySource === '' || $legacyIdRaw === '' || $nodeIdRaw === '') {
        $stats['skipped_empty']++;
        continue;
    }
    if (!preg_match('/^-?\d+$/', $legacyIdRaw) || !preg_match('/^\d+$/', $nodeIdRaw)) {
        $stats['skipped_invalid']++;
        continue;
    }

    $legacyId = (int) $legacyIdRaw;
    $nodeId = (int) $nodeIdRaw;
    if ($legacyId <= 0 || $nodeId <= 0) {
        $stats['skipped_invalid']++;
        continue;
    }
    if (!isset($nodeExists[$nodeId])) {
        $stats['skipped_unknown_node']++;
        continue;
    }

    $confidence = 1.0;
    if ($confidenceRaw !== '' && is_numeric($confidenceRaw)) {
        $confidence = (float) $confidenceRaw;
    }
    if ($confidence < 0.0) {
        $confidence = 0.0;
    }
    if ($confidence > 1.0) {
        $confidence = 1.0;
    }

    $stmt->bind_param('siids', $legacySource, $legacyId, $nodeId, $confidence, $note);
    if ($stmt->execute()) {
        $stats['imported']++;
    } else {
        $stats['skipped_invalid']++;
    }
}

$stmt->close();
fclose($fp);

echo "Импорт завершён: {$file}\n";
foreach ($stats as $k => $v) {
    echo "{$k}: {$v}\n";
}
exit(0);

