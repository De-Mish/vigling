<?php
/**
 * Авто-резолв unresolved записей:
 * - пробует ручной/авто map из #__vigling_service_legacy_map
 * - эвристики для service_raw:
 *   - integer -> legacy id
 *   - x.y -> integer part (например 45.15 -> 45)
 *   - tuple-array в source_payload -> tuple[2]
 *   - пустой service_raw -> fallback по cat_id
 * - fallback по cat_id на mapping category/tag/content/vigling_services
 *
 * При успешном резолве:
 * - upsert в #__vigling_user_services
 * - заполняет `resolved_node_id` в #__vigling_service_unresolved
 * - при `--delete-resolved` удаляет resolved строки из unresolved
 *
 * Опции:
 *   --apply
 *   --limit=N
 *   --delete-resolved
 *
 * Запуск:
 *   php migration_scripts/44_resolve_unresolved_auto.php
 *   php migration_scripts/44_resolve_unresolved_auto.php --apply --delete-resolved
 */

define('_JEXEC', 1);
$baseDir = dirname(__DIR__);
require_once __DIR__ . '/load_j6_config.php';

function optionInt(array $argv, string $key): ?int
{
    foreach ($argv as $arg) {
        if (strpos($arg, $key . '=') === 0) {
            return (int) substr($arg, strlen($key) + 1);
        }
    }
    return null;
}

function parseIntLike($value): ?int
{
    if (is_int($value)) {
        return $value;
    }
    if (is_string($value) && preg_match('/^-?\d+$/', trim($value))) {
        return (int) trim($value);
    }
    return null;
}

function parseFloatLike($value): ?float
{
    if (is_float($value) || is_int($value)) {
        return (float) $value;
    }
    if (is_string($value) && is_numeric(str_replace(',', '.', $value))) {
        return (float) str_replace(',', '.', $value);
    }
    return null;
}

function resolveMappedNode(array $map, int $legacyId, array $priority): ?int
{
    foreach ($priority as $source) {
        $k = $source . ':' . $legacyId;
        if (isset($map[$k])) {
            return (int) $map[$k];
        }
    }
    return null;
}

function extractCandidateId(array $row): ?int
{
    $raw = trim((string) ($row['service_raw'] ?? ''));
    if ($raw === '') {
        return 0;
    }

    if (preg_match('/^-?\d+$/', $raw)) {
        return (int) $raw;
    }

    $f = parseFloatLike($raw);
    if ($f !== null) {
        return (int) floor($f);
    }

    $payload = (string) ($row['source_payload'] ?? '');
    if ($payload !== '') {
        $decoded = json_decode($payload, true);
        if (is_array($decoded)) {
            if (isset($decoded['tuple']) && is_array($decoded['tuple'])) {
                $tuple = $decoded['tuple'];
                if (isset($tuple[2])) {
                    $svc = $tuple[2];
                    if (is_array($svc) && isset($svc[2])) {
                        $svc = $svc[2];
                    }
                    $int = parseIntLike($svc);
                    if ($int !== null) {
                        return $int;
                    }
                    $flt = parseFloatLike($svc);
                    if ($flt !== null) {
                        return (int) floor($flt);
                    }
                }
            }
        }
    }

    return null;
}

$apply = in_array('--apply', $argv ?? [], true);
$deleteResolved = in_array('--delete-resolved', $argv ?? [], true);
$limit = optionInt($argv ?? [], '--limit');

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
$tUnr = $prefix . 'vigling_service_unresolved';
$tUserSvc = $prefix . 'vigling_user_stock_services';

$map = [];
$mapRes = $mysqli->query("SELECT legacy_source, legacy_id, service_node_id FROM `{$tMap}`");
while ($mapRes && ($r = $mapRes->fetch_assoc())) {
    $map[$r['legacy_source'] . ':' . (int) $r['legacy_id']] = (int) $r['service_node_id'];
}
if ($mapRes) {
    $mapRes->free();
}

$sql = "SELECT id,user_id,field_name,cat_id,service_raw,price,duration_min,reason,source_payload
        FROM `{$tUnr}`
        WHERE resolved_node_id IS NULL AND field_name='stock_prices'";
if ($limit !== null && $limit > 0) {
    $sql .= " LIMIT " . (int) $limit;
}

$res = $mysqli->query($sql);
if (!$res) {
    fwrite(STDERR, "Ошибка выборки unresolved: {$mysqli->error}\n");
    exit(1);
}

$upsertUser = $mysqli->prepare(
    "INSERT INTO `{$tUserSvc}` (`user_id`,`service_node_id`,`price`,`duration_min`,`currency`,`is_active`,`source_payload`)
     VALUES (?,?,?,?,NULL,1,?)
     ON DUPLICATE KEY UPDATE
       `price`=VALUES(`price`),
       `duration_min`=VALUES(`duration_min`),
       `is_active`=VALUES(`is_active`),
       `source_payload`=VALUES(`source_payload`)"
);
$markResolved = $mysqli->prepare("UPDATE `{$tUnr}` SET resolved_node_id = ? WHERE id = ?");
$deleteResolvedStmt = $mysqli->prepare("DELETE FROM `{$tUnr}` WHERE id = ?");

$stats = [
    'rows_total' => 0,
    'resolved' => 0,
    'still_unresolved' => 0,
    'resolved_by_direct_id' => 0,
    'resolved_by_float_to_int' => 0,
    'resolved_by_tuple_extract' => 0,
    'resolved_by_cat_fallback' => 0,
];

$directPriority = ['tag', 'vigling_services', 'content', 'category'];
$catPriority = ['category', 'tag', 'content', 'vigling_services'];
$examples = [];

if ($apply) {
    $mysqli->autocommit(false);
}

while ($row = $res->fetch_assoc()) {
    $stats['rows_total']++;
    $id = (int) $row['id'];
    $uid = (int) $row['user_id'];
    $catId = trim((string) $row['cat_id']);
    $reason = (string) $row['reason'];
    $candidate = extractCandidateId($row);
    $resolvedNodeId = null;
    $resolvedBy = '';

    if ($candidate !== null) {
        if ($candidate > 0) {
            $resolvedNodeId = resolveMappedNode($map, $candidate, $directPriority);
            if ($resolvedNodeId !== null) {
                if (preg_match('/^\d+\.\d+$/', trim((string) $row['service_raw']))) {
                    $resolvedBy = 'float_to_int';
                } elseif ($reason === 'service_id_not_numeric') {
                    $resolvedBy = 'tuple_extract';
                } else {
                    $resolvedBy = 'direct_id';
                }
            }
        }
    }

    if ($resolvedNodeId === null && preg_match('/^\d+$/', $catId)) {
        $resolvedNodeId = resolveMappedNode($map, (int) $catId, $catPriority);
        if ($resolvedNodeId !== null) {
            $resolvedBy = 'cat_fallback';
        }
    }

    if ($resolvedNodeId !== null) {
        $stats['resolved']++;
        if ($resolvedBy === 'direct_id') {
            $stats['resolved_by_direct_id']++;
        } elseif ($resolvedBy === 'float_to_int') {
            $stats['resolved_by_float_to_int']++;
        } elseif ($resolvedBy === 'tuple_extract') {
            $stats['resolved_by_tuple_extract']++;
        } elseif ($resolvedBy === 'cat_fallback') {
            $stats['resolved_by_cat_fallback']++;
        }

        $payload = (string) ($row['source_payload'] ?? '');
        $price = (float) ($row['price'] ?? 0);
        $duration = (int) ($row['duration_min'] ?? 0);

        if ($apply) {
            $upsertUser->bind_param('iidds', $uid, $resolvedNodeId, $price, $duration, $payload);
            $upsertUser->execute();

            $markResolved->bind_param('ii', $resolvedNodeId, $id);
            $markResolved->execute();

            if ($deleteResolved) {
                $deleteResolvedStmt->bind_param('i', $id);
                $deleteResolvedStmt->execute();
            }
        }
    } else {
        $stats['still_unresolved']++;
        if (count($examples) < 20) {
            $examples[] = [
                'id' => $id,
                'user_id' => $uid,
                'reason' => $reason,
                'service_raw' => (string) $row['service_raw'],
                'cat_id' => $catId,
            ];
        }
    }
}
$res->free();

if ($apply) {
    $mysqli->commit();
    $mysqli->autocommit(true);
}

$upsertUser->close();
$markResolved->close();
$deleteResolvedStmt->close();

echo "=== Resolve Stock Unresolved Auto ===\n";
echo "Mode: " . ($apply ? 'APPLY' : 'DRY-RUN') . "\n";
echo "delete-resolved: " . ($deleteResolved ? 'yes' : 'no') . "\n";
echo "limit: " . ($limit ?? 'none') . "\n\n";
foreach ($stats as $k => $v) {
    echo "{$k}: {$v}\n";
}

if (!empty($examples)) {
    echo "\nExamples still unresolved:\n";
    foreach ($examples as $e) {
        echo " - id={$e['id']}, user={$e['user_id']}, reason={$e['reason']}, service_raw={$e['service_raw']}, cat_id={$e['cat_id']}\n";
    }
}

exit(0);

