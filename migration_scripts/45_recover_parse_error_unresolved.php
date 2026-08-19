<?php
/**
 * Восстановление unresolved reason=parse_error из source_payload.raw.
 *
 * Идея:
 * - в raw-строке ищем паттерны tuple вида:
 *   [price, duration_or_duration.oldprice, serviceId, ...]
 * - берём первые 3 числовых поля и маппим serviceId через #__vigling_service_legacy_map
 * - найденные tuple upsert-им в #__vigling_user_services
 * - unresolved строку отмечаем resolved_node_id (первый найденный node) или удаляем по флагу
 *
 * Опции:
 *   --apply
 *   --delete-resolved
 *
 * Запуск:
 *   php migration_scripts/45_recover_parse_error_unresolved.php
 *   php migration_scripts/45_recover_parse_error_unresolved.php --apply --delete-resolved
 */

define('_JEXEC', 1);
$baseDir = dirname(__DIR__);
require_once __DIR__ . '/load_j6_config.php';

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

function parseDurationFromToken(string $token): int
{
    if (preg_match('/-?\d+/', $token, $m)) {
        return (int) $m[0];
    }
    return 0;
}

$apply = in_array('--apply', $argv ?? [], true);
$deleteResolved = in_array('--delete-resolved', $argv ?? [], true);

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
$tUserSvc = $prefix . 'vigling_user_services';

$map = [];
$mapRes = $mysqli->query("SELECT legacy_source, legacy_id, service_node_id FROM `{$tMap}`");
while ($mapRes && ($r = $mapRes->fetch_assoc())) {
    $map[$r['legacy_source'] . ':' . (int) $r['legacy_id']] = (int) $r['service_node_id'];
}
if ($mapRes) {
    $mapRes->free();
}

$rowsRes = $mysqli->query(
    "SELECT id,user_id,field_name,source_payload
     FROM `{$tUnr}`
     WHERE reason='parse_error' AND resolved_node_id IS NULL
     ORDER BY id"
);
if (!$rowsRes) {
    fwrite(STDERR, "Ошибка выборки parse_error: {$mysqli->error}\n");
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

$priority = ['tag', 'vigling_services', 'content', 'category'];

$stats = [
    'rows_total' => 0,
    'tuples_found' => 0,
    'tuples_mapped' => 0,
    'rows_resolved' => 0,
    'rows_still_unresolved' => 0,
];

if ($apply) {
    $mysqli->autocommit(false);
}

while ($row = $rowsRes->fetch_assoc()) {
    $stats['rows_total']++;
    $id = (int) $row['id'];
    $uid = (int) $row['user_id'];
    $payload = (string) $row['source_payload'];
    $decoded = json_decode($payload, true);
    $raw = is_array($decoded) ? (string) ($decoded['raw'] ?? '') : '';

    $resolvedAny = false;
    $firstResolvedNodeId = null;

    if ($raw !== '') {
        // Глобально ищем tuple-паттерны; этого хватает для текущих parse_error кейсов.
        $pattern = '/\[\s*([0-9]+(?:\.[0-9]+)?)\s*,\s*"?([0-9]+(?:\.[0-9]+)?(?:\.[0-9]+)?)"?\s*,\s*(-?[0-9]+)/u';
        if (preg_match_all($pattern, $raw, $m, PREG_SET_ORDER)) {
            foreach ($m as $tuple) {
                $stats['tuples_found']++;
                $price = (float) $tuple[1];
                $duration = parseDurationFromToken((string) $tuple[2]);
                $legacyId = (int) $tuple[3];
                if ($legacyId <= 0) {
                    continue;
                }
                $nodeId = resolveMappedNode($map, $legacyId, $priority);
                if ($nodeId === null) {
                    continue;
                }

                $stats['tuples_mapped']++;
                $resolvedAny = true;
                if ($firstResolvedNodeId === null) {
                    $firstResolvedNodeId = $nodeId;
                }

                $tuplePayload = json_encode([
                    'recovered_from' => 'parse_error_raw',
                    'legacy_service_id' => $legacyId,
                    'raw' => $raw,
                    'tuple' => [$price, $duration, $legacyId],
                ], JSON_UNESCAPED_UNICODE);

                if ($apply) {
                    $upsertUser->bind_param('iidds', $uid, $nodeId, $price, $duration, $tuplePayload);
                    $upsertUser->execute();
                }
            }
        }
    }

    if ($resolvedAny && $firstResolvedNodeId !== null) {
        $stats['rows_resolved']++;
        if ($apply) {
            $markResolved->bind_param('ii', $firstResolvedNodeId, $id);
            $markResolved->execute();
            if ($deleteResolved) {
                $deleteResolvedStmt->bind_param('i', $id);
                $deleteResolvedStmt->execute();
            }
        }
    } else {
        $stats['rows_still_unresolved']++;
    }
}
$rowsRes->free();

if ($apply) {
    $mysqli->commit();
    $mysqli->autocommit(true);
}

$upsertUser->close();
$markResolved->close();
$deleteResolvedStmt->close();

echo "=== Recover Parse Error Unresolved ===\n";
echo "Mode: " . ($apply ? 'APPLY' : 'DRY-RUN') . "\n";
echo "delete-resolved: " . ($deleteResolved ? 'yes' : 'no') . "\n\n";
foreach ($stats as $k => $v) {
    echo "{$k}: {$v}\n";
}

exit(0);

