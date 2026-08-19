<?php
/**
 * Строит отчёт по аномалиям legacy-полей цен (prices/stock_prices) в #__fields_values.
 *
 * Что ищет:
 * - parse_error (не удалось распарсить payload)
 * - malformed_tuple (элемент не массив или < 3 значений)
 * - service_id_zero (service id = 0)
 * - service_id_negative (service id < 0)
 * - service_id_not_numeric (service id не число)
 * - service_id_missing_lookup (service id > 0, но не найден в справочниках)
 *
 * Запуск:
 *   php migration_scripts/39_vigling_services_anomaly_report.php
 *   php migration_scripts/39_vigling_services_anomaly_report.php --limit=5000
 *   php migration_scripts/39_vigling_services_anomaly_report.php --user-id=134438
 */

define('_JEXEC', 1);
$baseDir = dirname(__DIR__);

require_once __DIR__ . '/load_j6_config.php';
$config = loadJ6Config($baseDir . '/configuration.php');
if (!$config) {
    fwrite(STDERR, "Не найден configuration.php\n");
    exit(1);
}

function getOptionInt(array $argv, string $name): ?int
{
    foreach ($argv as $arg) {
        if (strpos($arg, $name . '=') === 0) {
            return (int) substr($arg, strlen($name) + 1);
        }
    }
    return null;
}

function normalizeLegacyJson(string $raw): ?array
{
    $raw = trim($raw);
    if ($raw === '') {
        return [];
    }

    $normalized = preg_replace('/(\d+)\s*:/', '"$1":', $raw);
    if ($normalized === null) {
        return null;
    }
    $normalized = preg_replace('/,\s*([}\]])/', '$1', $normalized);
    if ($normalized === null) {
        return null;
    }

    $decoded = json_decode($normalized, true);
    if (!is_array($decoded)) {
        return null;
    }

    return $decoded;
}

function mkSampleRow(int $userId, string $fieldName, string $catId, string $serviceRaw, float $price, int $duration, string $reason): array
{
    return [
        'user_id' => $userId,
        'field' => $fieldName,
        'cat_id' => $catId,
        'service_raw' => $serviceRaw,
        'price' => $price,
        'duration' => $duration,
        'reason' => $reason,
    ];
}

$limit = getOptionInt($argv ?? [], '--limit');
$userId = getOptionInt($argv ?? [], '--user-id');

$mysqli = new mysqli($config->host, $config->user, $config->password, $config->db);
if ($mysqli->connect_error) {
    fwrite(STDERR, "Ошибка подключения к БД: " . $mysqli->connect_error . "\n");
    exit(1);
}
$mysqli->set_charset('utf8mb4');
$prefix = $mysqli->real_escape_string($config->dbprefix);

$tFields = $prefix . 'fields';
$tValues = $prefix . 'fields_values';
$tVigling = $prefix . 'vigling_services';
$tContent = $prefix . 'content';
$tTags = $prefix . 'tags';

$lookupIds = [];

$lookupSql = [
    "SELECT id FROM `{$tVigling}`",
    "SELECT id FROM `{$tContent}`",
    "SELECT id FROM `{$tTags}`",
];
foreach ($lookupSql as $sql) {
    $res = $mysqli->query($sql);
    if (!$res) {
        fwrite(STDERR, "Ошибка выборки lookup: " . $mysqli->error . "\n");
        exit(1);
    }
    while ($row = $res->fetch_assoc()) {
        $lookupIds[(int) $row['id']] = true;
    }
    $res->free();
}

$query = "SELECT fv.item_id, fv.value, f.name
          FROM `{$tValues}` fv
          INNER JOIN `{$tFields}` f ON f.id = fv.field_id
          WHERE f.context = 'com_users.user'
            AND f.name IN ('prices', 'stock_prices')";
if ($userId !== null && $userId > 0) {
    $query .= " AND fv.item_id = " . (int) $userId;
}
$query .= " ORDER BY fv.item_id";
if ($limit !== null && $limit > 0) {
    $query .= " LIMIT " . (int) $limit;
}

$res = $mysqli->query($query);
if (!$res) {
    fwrite(STDERR, "Ошибка выборки данных: " . $mysqli->error . "\n");
    exit(1);
}

$stats = [
    'rows_total' => 0,
    'rows_parsed' => 0,
    'entries_total' => 0,
    'parse_error' => 0,
    'malformed_tuple' => 0,
    'service_id_zero' => 0,
    'service_id_negative' => 0,
    'service_id_not_numeric' => 0,
    'service_id_missing_lookup' => 0,
];

$topMissing = [];
$samples = [];
$maxSamples = 40;

while ($row = $res->fetch_assoc()) {
    $stats['rows_total']++;
    $uid = (int) $row['item_id'];
    $fieldName = (string) $row['name'];
    $payload = (string) $row['value'];

    $decoded = normalizeLegacyJson($payload);
    if ($decoded === null) {
        $stats['parse_error']++;
        if (count($samples) < $maxSamples) {
            $samples[] = mkSampleRow($uid, $fieldName, '-', '-', 0, 0, 'parse_error');
        }
        continue;
    }

    $stats['rows_parsed']++;
    foreach ($decoded as $catId => $items) {
        $catId = (string) $catId;
        if (!is_array($items)) {
            $stats['malformed_tuple']++;
            if (count($samples) < $maxSamples) {
                $samples[] = mkSampleRow($uid, $fieldName, $catId, '-', 0, 0, 'cat_items_not_array');
            }
            continue;
        }

        foreach ($items as $triple) {
            $stats['entries_total']++;

            if (!is_array($triple) || count($triple) < 3) {
                $stats['malformed_tuple']++;
                if (count($samples) < $maxSamples) {
                    $samples[] = mkSampleRow($uid, $fieldName, $catId, '-', 0, 0, 'malformed_tuple');
                }
                continue;
            }

            $price = is_numeric($triple[0]) ? (float) $triple[0] : 0.0;
            $duration = 0;
            if (is_numeric($triple[1])) {
                $duration = (int) $triple[1];
            } elseif (is_string($triple[1])) {
                $duration = (int) preg_replace('/[^0-9\-]/', '', $triple[1]);
            }

            $serviceRawValue = $triple[2];
            if (is_scalar($serviceRawValue) || $serviceRawValue === null) {
                $serviceRaw = (string) $serviceRawValue;
            } else {
                $serviceRaw = json_encode($serviceRawValue, JSON_UNESCAPED_UNICODE) ?: '[[non-scalar]]';
            }
            $svcBase = $serviceRaw;
            if (strpos($serviceRaw, '-') !== false) {
                $svcBase = explode('-', $serviceRaw, 2)[0];
            }

            if (!preg_match('/^-?\d+$/', trim($svcBase))) {
                $stats['service_id_not_numeric']++;
                if (count($samples) < $maxSamples) {
                    $samples[] = mkSampleRow($uid, $fieldName, $catId, $serviceRaw, $price, $duration, 'service_id_not_numeric');
                }
                continue;
            }

            $svcId = (int) $svcBase;
            if ($svcId === 0) {
                $stats['service_id_zero']++;
                if (count($samples) < $maxSamples) {
                    $samples[] = mkSampleRow($uid, $fieldName, $catId, $serviceRaw, $price, $duration, 'service_id_zero');
                }
                continue;
            }
            if ($svcId < 0) {
                $stats['service_id_negative']++;
                if (count($samples) < $maxSamples) {
                    $samples[] = mkSampleRow($uid, $fieldName, $catId, $serviceRaw, $price, $duration, 'service_id_negative');
                }
                continue;
            }

            if (!isset($lookupIds[$svcId])) {
                $stats['service_id_missing_lookup']++;
                if (!isset($topMissing[$svcId])) {
                    $topMissing[$svcId] = 0;
                }
                $topMissing[$svcId]++;
                if (count($samples) < $maxSamples) {
                    $samples[] = mkSampleRow($uid, $fieldName, $catId, $serviceRaw, $price, $duration, 'service_id_missing_lookup');
                }
            }
        }
    }
}
$res->free();

arsort($topMissing);
$topMissing = array_slice($topMissing, 0, 30, true);

$stamp = date('Ymd_His');
$reportDir = $baseDir . '/migration_reports';
if (!is_dir($reportDir)) {
    mkdir($reportDir, 0775, true);
}
$reportFile = $reportDir . '/uslugi_anomaly_report_' . $stamp . '.md';

$lines = [];
$lines[] = '# Отчёт аномалий legacy-услуг';
$lines[] = '';
$lines[] = '- Дата: ' . date('Y-m-d H:i:s');
$lines[] = '- База: `' . $config->db . '`';
$lines[] = '- Ограничение --limit: ' . ($limit ?? 'нет');
$lines[] = '- Фильтр --user-id: ' . ($userId ?? 'нет');
$lines[] = '';
$lines[] = '## Сводка';
$lines[] = '';
$lines[] = '- rows_total: ' . $stats['rows_total'];
$lines[] = '- rows_parsed: ' . $stats['rows_parsed'];
$lines[] = '- entries_total: ' . $stats['entries_total'];
$lines[] = '- parse_error: ' . $stats['parse_error'];
$lines[] = '- malformed_tuple: ' . $stats['malformed_tuple'];
$lines[] = '- service_id_zero: ' . $stats['service_id_zero'];
$lines[] = '- service_id_negative: ' . $stats['service_id_negative'];
$lines[] = '- service_id_not_numeric: ' . $stats['service_id_not_numeric'];
$lines[] = '- service_id_missing_lookup: ' . $stats['service_id_missing_lookup'];
$lines[] = '';
$lines[] = '## Топ отсутствующих service_id';
$lines[] = '';
$lines[] = '| service_id | count |';
$lines[] = '|---:|---:|';
if (empty($topMissing)) {
    $lines[] = '| - | 0 |';
} else {
    foreach ($topMissing as $id => $cnt) {
        $lines[] = '| ' . (int) $id . ' | ' . (int) $cnt . ' |';
    }
}
$lines[] = '';
$lines[] = '## Примеры аномалий';
$lines[] = '';
$lines[] = '| user_id | field | cat_id | service_raw | price | duration | reason |';
$lines[] = '|---:|---|---:|---|---:|---:|---|';
if (empty($samples)) {
    $lines[] = '| - | - | - | - | - | - | none |';
} else {
    foreach ($samples as $s) {
        $lines[] = '| ' . $s['user_id']
            . ' | ' . str_replace('|', '\\|', $s['field'])
            . ' | ' . str_replace('|', '\\|', $s['cat_id'])
            . ' | ' . str_replace('|', '\\|', $s['service_raw'])
            . ' | ' . number_format((float) $s['price'], 2, '.', '')
            . ' | ' . (int) $s['duration']
            . ' | ' . str_replace('|', '\\|', $s['reason'])
            . ' |';
    }
}
$lines[] = '';

file_put_contents($reportFile, implode("\n", $lines) . "\n");

echo "Отчёт создан: {$reportFile}\n";
echo "rows_total={$stats['rows_total']}, entries_total={$stats['entries_total']}, ";
echo "service_id_zero={$stats['service_id_zero']}, missing_lookup={$stats['service_id_missing_lookup']}\n";
exit(0);
