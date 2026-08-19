<?php
/**
 * Экспорт unresolved-кейсов в отчёт и CSV для ручного mapping.
 *
 * Выход:
 * - migration_reports/uslugi_unresolved_report_YYYYmmdd_HHMMSS.md
 * - migration_reports/uslugi_unresolved_rows_YYYYmmdd_HHMMSS.csv
 * - migration_reports/uslugi_manual_map_template_YYYYmmdd_HHMMSS.csv
 *
 * Запуск:
 *   php migration_scripts/42_export_unresolved_report.php
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

$prefix = $mysqli->real_escape_string($config->dbprefix);
$tUnresolved = $prefix . 'vigling_service_unresolved';
$tNodes = $prefix . 'vigling_service_nodes';

$existsRes = $mysqli->query("SHOW TABLES LIKE '{$tUnresolved}'");
if (!$existsRes || $existsRes->num_rows === 0) {
    fwrite(STDERR, "Таблица не найдена: {$tUnresolved}\n");
    exit(1);
}

$stamp = date('Ymd_His');
$reportDir = $baseDir . '/migration_reports';
if (!is_dir($reportDir)) {
    mkdir($reportDir, 0775, true);
}

$reportPath = $reportDir . '/uslugi_unresolved_report_' . $stamp . '.md';
$rowsCsvPath = $reportDir . '/uslugi_unresolved_rows_' . $stamp . '.csv';
$templateCsvPath = $reportDir . '/uslugi_manual_map_template_' . $stamp . '.csv';

$summary = [];
$summaryRes = $mysqli->query("SELECT reason, COUNT(*) c FROM `{$tUnresolved}` GROUP BY reason ORDER BY c DESC");
while ($summaryRes && ($row = $summaryRes->fetch_assoc())) {
    $summary[] = ['reason' => (string) $row['reason'], 'count' => (int) $row['c']];
}
if ($summaryRes) {
    $summaryRes->free();
}

$rows = [];
$rowsRes = $mysqli->query(
    "SELECT id, user_id, field_name, cat_id, service_raw, price, duration_min, reason, source_payload
     FROM `{$tUnresolved}`
     ORDER BY reason ASC, user_id ASC, id ASC"
);
while ($rowsRes && ($row = $rowsRes->fetch_assoc())) {
    $rows[] = $row;
}
if ($rowsRes) {
    $rowsRes->free();
}

$userCountRes = $mysqli->query("SELECT COUNT(DISTINCT user_id) c FROM `{$tUnresolved}`");
$affectedUsers = 0;
if ($userCountRes) {
    $affectedUsers = (int) (($userCountRes->fetch_assoc()['c'] ?? 0));
    $userCountRes->free();
}

$nodeOptions = [];
$nodeRes = $mysqli->query(
    "SELECT id, title, legacy_source, legacy_id
     FROM `{$tNodes}`
     WHERE is_active = 1 AND type IN ('service','variant')
     ORDER BY title ASC
     LIMIT 5000"
);
while ($nodeRes && ($row = $nodeRes->fetch_assoc())) {
    $nodeOptions[] = $row;
}
if ($nodeRes) {
    $nodeRes->free();
}

// CSV unresolved rows
$rowsFp = fopen($rowsCsvPath, 'w');
fputcsv($rowsFp, ['id', 'user_id', 'field_name', 'cat_id', 'service_raw', 'price', 'duration_min', 'reason', 'source_payload']);
foreach ($rows as $r) {
    fputcsv($rowsFp, [
        $r['id'],
        $r['user_id'],
        $r['field_name'],
        $r['cat_id'],
        $r['service_raw'],
        $r['price'],
        $r['duration_min'],
        $r['reason'],
        $r['source_payload'],
    ]);
}
fclose($rowsFp);

// CSV template for manual map
$templateFp = fopen($templateCsvPath, 'w');
fputcsv($templateFp, [
    'legacy_source',
    'legacy_id',
    'service_node_id',
    'confidence',
    'note',
    'example_unresolved_reason',
    'example_user_id',
    'example_service_raw',
    'example_cat_id',
]);

$uniqueKeys = [];
foreach ($rows as $r) {
    $legacySource = $r['reason'] === 'service_id_missing_lookup' ? 'tag' : 'tag';
    $legacyId = null;
    if (preg_match('/^-?\d+$/', (string) $r['service_raw'])) {
        $legacyId = (int) $r['service_raw'];
    } elseif (preg_match('/"tuple"\s*:\s*\[[^,\]]+,[^,\]]+,(-?\d+)/', (string) $r['source_payload'], $m)) {
        $legacyId = (int) $m[1];
    }
    if ($legacyId === null || $legacyId <= 0) {
        continue;
    }
    $k = $legacySource . ':' . $legacyId;
    if (isset($uniqueKeys[$k])) {
        continue;
    }
    $uniqueKeys[$k] = true;
    fputcsv($templateFp, [
        $legacySource,
        $legacyId,
        '',
        '1.000',
        'manual map',
        $r['reason'],
        $r['user_id'],
        $r['service_raw'],
        $r['cat_id'],
    ]);
}
fclose($templateFp);

$lines = [];
$lines[] = '# Отчёт unresolved-услуг';
$lines[] = '';
$lines[] = '- Дата: ' . date('Y-m-d H:i:s');
$lines[] = '- БД: `' . $config->db . '`';
$lines[] = '- Всего unresolved: ' . count($rows);
$lines[] = '- Пользователей с unresolved: ' . $affectedUsers;
$lines[] = '';
$lines[] = '## Распределение по reason';
$lines[] = '';
$lines[] = '| reason | count |';
$lines[] = '|---|---:|';
if (empty($summary)) {
    $lines[] = '| none | 0 |';
} else {
    foreach ($summary as $s) {
        $lines[] = '| ' . $s['reason'] . ' | ' . $s['count'] . ' |';
    }
}
$lines[] = '';
$lines[] = '## Файлы';
$lines[] = '';
$lines[] = '- rows csv: `' . basename($rowsCsvPath) . '`';
$lines[] = '- manual map template: `' . basename($templateCsvPath) . '`';
$lines[] = '';
$lines[] = '## Пример доступных service_node_id (первые 50)';
$lines[] = '';
$lines[] = '| service_node_id | title | legacy_source | legacy_id |';
$lines[] = '|---:|---|---|---:|';
$preview = array_slice($nodeOptions, 0, 50);
if (empty($preview)) {
    $lines[] = '| - | - | - | - |';
} else {
    foreach ($preview as $n) {
        $lines[] = '| ' . (int) $n['id'] . ' | '
            . str_replace('|', '\\|', (string) $n['title']) . ' | '
            . str_replace('|', '\\|', (string) $n['legacy_source']) . ' | '
            . (int) $n['legacy_id'] . ' |';
    }
}
$lines[] = '';

file_put_contents($reportPath, implode("\n", $lines) . "\n");

echo "Готово.\n";
echo "report: {$reportPath}\n";
echo "rows_csv: {$rowsCsvPath}\n";
echo "template_csv: {$templateCsvPath}\n";
echo "unresolved_total: " . count($rows) . "\n";
exit(0);

