<?php
/**
 * Мигратор legacy-услуг из #__fields_values в новую модель:
 * - #__vigling_user_services
 * - #__vigling_service_unresolved
 *
 * Важно:
 * - в `#__vigling_user_services` мигрируются только обычные услуги из поля `prices`
 * - `stock_prices` НЕ пишется в эту таблицу (иначе акционные цены перетирают обычные по uniq(user_id, service_node_id))
 *
 * Опции:
 *   --apply           Вносить изменения в БД (по умолчанию dry-run)
 *   --limit=N         Ограничить количество строк fields_values
 *   --user-id=ID      Обработать только одного пользователя
 *   --from-user-id=ID Обработать пользователей начиная с ID (включительно)
 *   --to-user-id=ID   Обработать пользователей до ID (включительно)
 *   --truncate-target Очистить target-таблицы перед --apply
 *
 * Запуск:
 *   php migration_scripts/40_vigling_services_migrator_skeleton.php
 *   php migration_scripts/40_vigling_services_migrator_skeleton.php --user-id=134438
 *   php migration_scripts/40_vigling_services_migrator_skeleton.php --apply --truncate-target
 */

define('_JEXEC', 1);
$baseDir = dirname(__DIR__);

require_once __DIR__ . '/load_j6_config.php';
$config = loadJ6Config($baseDir . '/configuration.php');
if (!$config) {
    fwrite(STDERR, "Не найден configuration.php\n");
    exit(1);
}

function optionInt(array $argv, string $key): ?int
{
    foreach ($argv as $arg) {
        if (strpos($arg, $key . '=') === 0) {
            return (int) substr($arg, strlen($key) + 1);
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
    return is_array($decoded) ? $decoded : null;
}

function toServiceRaw($value): string
{
    if (is_scalar($value) || $value === null) {
        return (string) $value;
    }
    return json_encode($value, JSON_UNESCAPED_UNICODE) ?: '[[non-scalar]]';
}

function parseDuration($value): int
{
    if (is_int($value)) {
        return $value;
    }
    if (is_float($value)) {
        return (int) $value;
    }
    if (is_string($value) && preg_match('/-?\d+/', $value, $m)) {
        return (int) $m[0];
    }
    return 0;
}

function parsePrice($value): float
{
    if (is_numeric($value)) {
        return (float) $value;
    }
    if (is_string($value)) {
        $value = str_replace(',', '.', $value);
        if (preg_match('/-?\d+(\.\d+)?/', $value, $m)) {
            return (float) $m[0];
        }
    }
    return 0.0;
}

function parseNumericBaseId(string $raw): ?int
{
    $base = $raw;
    if (strpos($raw, '-') !== false) {
        $base = explode('-', $raw, 2)[0];
    }
    $base = trim($base);
    if ($base === '' || !preg_match('/^-?\d+$/', $base)) {
        return null;
    }
    return (int) $base;
}

function fetchMap(mysqli $db, string $table): array
{
    $res = $db->query("SELECT legacy_source, legacy_id, service_node_id FROM `{$table}`");
    if (!$res) {
        return [];
    }
    $map = [];
    while ($row = $res->fetch_assoc()) {
        $key = $row['legacy_source'] . ':' . (int) $row['legacy_id'];
        $map[$key] = (int) $row['service_node_id'];
    }
    $res->free();
    return $map;
}

function fetchContextMap(mysqli $db, string $table): array
{
    $res = $db->query("SELECT context_source, context_id, legacy_source, legacy_id, service_node_id FROM `{$table}`");
    if (!$res) {
        return [];
    }
    $map = [];
    while ($row = $res->fetch_assoc()) {
        $key = (string) $row['context_source']
            . ':' . (int) $row['context_id']
            . '|' . (string) $row['legacy_source']
            . ':' . (int) $row['legacy_id'];
        $map[$key] = (int) $row['service_node_id'];
    }
    $res->free();
    return $map;
}

function resolveMappedNode(array $map, int $legacyId, array $priority): ?int
{
    foreach ($priority as $source) {
        $key = $source . ':' . $legacyId;
        if (isset($map[$key])) {
            return (int) $map[$key];
        }
    }
    return null;
}

function resolveContextMappedNode(array $ctxMap, string $contextSource, int $contextId, string $legacySource, int $legacyId): ?int
{
    $key = $contextSource . ':' . $contextId . '|' . $legacySource . ':' . $legacyId;
    return isset($ctxMap[$key]) ? (int) $ctxMap[$key] : null;
}

$apply = in_array('--apply', $argv ?? [], true);
$truncateTarget = in_array('--truncate-target', $argv ?? [], true);
$limit = optionInt($argv ?? [], '--limit');
$userId = optionInt($argv ?? [], '--user-id');
$fromUserId = optionInt($argv ?? [], '--from-user-id');
$toUserId = optionInt($argv ?? [], '--to-user-id');

$mysqli = new mysqli($config->host, $config->user, $config->password, $config->db);
if ($mysqli->connect_error) {
    fwrite(STDERR, "Ошибка подключения к БД: " . $mysqli->connect_error . "\n");
    exit(1);
}
$mysqli->set_charset('utf8mb4');
$prefix = $mysqli->real_escape_string($config->dbprefix);

$tables = [
    'nodes' => $prefix . 'vigling_service_nodes',
    'user_services' => $prefix . 'vigling_user_services',
    'legacy_map' => $prefix . 'vigling_service_legacy_map',
    'context_map' => $prefix . 'vigling_service_context_map',
    'unresolved' => $prefix . 'vigling_service_unresolved',
    'fields' => $prefix . 'fields',
    'values' => $prefix . 'fields_values',
];

foreach (['nodes', 'user_services', 'legacy_map', 'unresolved'] as $key) {
    $tableName = $mysqli->real_escape_string($tables[$key]);
    $dbName = $mysqli->real_escape_string($config->db);
    $check = $mysqli->query(
        "SELECT 1
         FROM information_schema.tables
         WHERE table_schema = '{$dbName}' AND table_name = '{$tableName}'
         LIMIT 1"
    );
    if (!$check || $check->num_rows === 0) {
        fwrite(STDERR, "Таблица не найдена: {$tables[$key]}\n");
        fwrite(STDERR, "Сначала запустите: php migration_scripts/38_create_vigling_services_tree_tables.php\n");
        exit(1);
    }
}

$map = fetchMap($mysqli, $tables['legacy_map']);
if (empty($map)) {
    fwrite(STDERR, "Таблица mapping пуста: {$tables['legacy_map']}\n");
    fwrite(STDERR, "Сначала заполните mapping, например: php migration_scripts/41_seed_service_nodes_and_legacy_map.php\n");
    exit(1);
}
$ctxMap = [];
$ctxMapExists = false;
$ctxCheckTable = $mysqli->real_escape_string($tables['context_map']);
$dbName = $mysqli->real_escape_string($config->db);
$ctxCheck = $mysqli->query(
    "SELECT 1
     FROM information_schema.tables
     WHERE table_schema = '{$dbName}' AND table_name = '{$ctxCheckTable}'
     LIMIT 1"
);
if ($ctxCheck && $ctxCheck->num_rows > 0) {
    $ctxMapExists = true;
    $ctxMap = fetchContextMap($mysqli, $tables['context_map']);
}

if ($apply && $truncateTarget) {
    if (!$mysqli->query("TRUNCATE TABLE `{$tables['user_services']}`")) {
        fwrite(STDERR, "Ошибка truncate {$tables['user_services']}: {$mysqli->error}\n");
        exit(1);
    }
    if (!$mysqli->query("TRUNCATE TABLE `{$tables['unresolved']}`")) {
        fwrite(STDERR, "Ошибка truncate {$tables['unresolved']}: {$mysqli->error}\n");
        exit(1);
    }
}

if ($apply && $userId !== null && $userId > 0) {
    $uid = (int) $userId;
    if (!$mysqli->query("DELETE FROM `{$tables['user_services']}` WHERE user_id = {$uid}")) {
        fwrite(STDERR, "Ошибка очистки user_services для user_id={$uid}: {$mysqli->error}\n");
        exit(1);
    }
    if (!$mysqli->query("DELETE FROM `{$tables['unresolved']}` WHERE user_id = {$uid}")) {
        fwrite(STDERR, "Ошибка очистки unresolved для user_id={$uid}: {$mysqli->error}\n");
        exit(1);
    }
}

$query = "SELECT fv.item_id, fv.value, f.name
          FROM `{$tables['values']}` fv
          INNER JOIN `{$tables['fields']}` f ON f.id = fv.field_id
          WHERE f.context = 'com_users.user'
            AND f.name = 'prices'";
if ($userId !== null && $userId > 0) {
    $query .= " AND fv.item_id = " . (int) $userId;
} else {
    if ($fromUserId !== null && $fromUserId > 0) {
        $query .= " AND fv.item_id >= " . (int) $fromUserId;
    }
    if ($toUserId !== null && $toUserId > 0) {
        $query .= " AND fv.item_id <= " . (int) $toUserId;
    }
}
$query .= " ORDER BY fv.item_id";
if ($limit !== null && $limit > 0) {
    $query .= " LIMIT " . (int) $limit;
}

$res = $mysqli->query($query);
if (!$res) {
    fwrite(STDERR, "Ошибка выборки legacy-данных: {$mysqli->error}\n");
    exit(1);
}

$insertUserServiceStmt = $mysqli->prepare(
    "INSERT INTO `{$tables['user_services']}` (`user_id`,`service_node_id`,`price`,`duration_min`,`currency`,`is_active`,`source_payload`)
     VALUES (?,?,?,?,NULL,1,?)
     ON DUPLICATE KEY UPDATE
       `price`=VALUES(`price`),
       `duration_min`=VALUES(`duration_min`),
       `is_active`=VALUES(`is_active`),
       `source_payload`=VALUES(`source_payload`)"
);
if (!$insertUserServiceStmt) {
    fwrite(STDERR, "Ошибка prepare user_services: {$mysqli->error}\n");
    exit(1);
}

$insertUnresolvedStmt = $mysqli->prepare(
    "INSERT INTO `{$tables['unresolved']}` (`user_id`,`field_name`,`cat_id`,`service_raw`,`price`,`duration_min`,`reason`,`source_payload`,`resolved_node_id`)
     VALUES (?,?,?,?,?,?,?,?,NULL)
     ON DUPLICATE KEY UPDATE
       `source_payload`=VALUES(`source_payload`),
       `updated_at`=CURRENT_TIMESTAMP"
);
if (!$insertUnresolvedStmt) {
    fwrite(STDERR, "Ошибка prepare unresolved: {$mysqli->error}\n");
    exit(1);
}

$batchSize = 2000;
$pendingWrites = 0;
if ($apply) {
    $mysqli->autocommit(false);
}

$stats = [
    'rows_total' => 0,
    'rows_parsed' => 0,
    'entries_total' => 0,
    'migrated' => 0,
    'unresolved' => 0,
    'parse_error' => 0,
    'malformed_tuple' => 0,
    'service_id_not_numeric' => 0,
    'service_id_negative' => 0,
    'service_id_zero_fallback_cat' => 0,
    'service_id_zero_unresolved' => 0,
    'service_id_missing_lookup' => 0,
];

$sourcePriority = ['tag', 'vigling_services', 'content'];
$catFallbackPriority = ['tag', 'content', 'vigling_services', 'category'];

$sampleUnresolved = [];
$maxUnresolvedSample = 30;

while ($row = $res->fetch_assoc()) {
    $stats['rows_total']++;
    $uid = (int) $row['item_id'];
    $fieldName = (string) $row['name'];
    $decoded = normalizeLegacyJson((string) $row['value']);

    if ($decoded === null) {
        $stats['parse_error']++;
        $stats['unresolved']++;
        $payload = json_encode(['reason' => 'parse_error', 'raw' => (string) $row['value']], JSON_UNESCAPED_UNICODE);
                if ($apply) {
                    $catId = '-';
                    $serviceRaw = '-';
                    $price = 0.0;
                    $duration = 0;
                    $reason = 'parse_error';
                    $insertUnresolvedStmt->bind_param('isssddss', $uid, $fieldName, $catId, $serviceRaw, $price, $duration, $reason, $payload);
                    $insertUnresolvedStmt->execute();
                    $pendingWrites++;
                }
                continue;
    }

    $stats['rows_parsed']++;
    foreach ($decoded as $catId => $items) {
        $catIdStr = (string) $catId;
        if (!is_array($items)) {
            $stats['malformed_tuple']++;
            $stats['unresolved']++;
            $payload = json_encode(['reason' => 'cat_items_not_array', 'cat_id' => $catIdStr], JSON_UNESCAPED_UNICODE);
                if ($apply) {
                    $serviceRaw = '-';
                    $price = 0.0;
                    $duration = 0;
                    $reason = 'cat_items_not_array';
                    $insertUnresolvedStmt->bind_param('isssddss', $uid, $fieldName, $catIdStr, $serviceRaw, $price, $duration, $reason, $payload);
                    $insertUnresolvedStmt->execute();
                    $pendingWrites++;
                }
                continue;
        }

        foreach ($items as $triple) {
            $stats['entries_total']++;
            if (!is_array($triple) || count($triple) < 3) {
                $stats['malformed_tuple']++;
                $stats['unresolved']++;
                $payload = json_encode(['reason' => 'malformed_tuple', 'tuple' => $triple], JSON_UNESCAPED_UNICODE);
                if ($apply) {
                    $serviceRaw = '-';
                    $price = 0.0;
                    $duration = 0;
                    $reason = 'malformed_tuple';
                    $insertUnresolvedStmt->bind_param('isssddss', $uid, $fieldName, $catIdStr, $serviceRaw, $price, $duration, $reason, $payload);
                    $insertUnresolvedStmt->execute();
                    $pendingWrites++;
                }
                continue;
            }

            $price = parsePrice($triple[0]);
            $duration = parseDuration($triple[1]);
            $serviceRaw = toServiceRaw($triple[2]);
            $svcId = parseNumericBaseId($serviceRaw);

            if ($svcId === null && trim($serviceRaw) === '') {
                $svcId = 0;
            }

            if ($svcId === null) {
                $stats['service_id_not_numeric']++;
                $stats['unresolved']++;
                $reason = 'service_id_not_numeric';
                $payload = json_encode(['cat_id' => $catIdStr, 'tuple' => $triple], JSON_UNESCAPED_UNICODE);
                if (count($sampleUnresolved) < $maxUnresolvedSample) {
                    $sampleUnresolved[] = [$uid, $fieldName, $catIdStr, $serviceRaw, $reason];
                }
                if ($apply) {
                    $insertUnresolvedStmt->bind_param('isssddss', $uid, $fieldName, $catIdStr, $serviceRaw, $price, $duration, $reason, $payload);
                    $insertUnresolvedStmt->execute();
                    $pendingWrites++;
                }
                continue;
            }

            if ($svcId < 0) {
                $stats['service_id_negative']++;
                $stats['unresolved']++;
                $reason = 'service_id_negative';
                $payload = json_encode(['cat_id' => $catIdStr, 'tuple' => $triple], JSON_UNESCAPED_UNICODE);
                if (count($sampleUnresolved) < $maxUnresolvedSample) {
                    $sampleUnresolved[] = [$uid, $fieldName, $catIdStr, $serviceRaw, $reason];
                }
                if ($apply) {
                    $insertUnresolvedStmt->bind_param('isssddss', $uid, $fieldName, $catIdStr, $serviceRaw, $price, $duration, $reason, $payload);
                    $insertUnresolvedStmt->execute();
                    $pendingWrites++;
                }
                continue;
            }

            $resolvedNodeId = null;
            $reason = 'ok';

            if ($svcId === 0) {
                $catNumeric = preg_match('/^\d+$/', $catIdStr) ? (int) $catIdStr : null;
                if ($catNumeric !== null) {
                    $resolvedNodeId = resolveMappedNode($map, $catNumeric, $catFallbackPriority);
                }
                if ($resolvedNodeId !== null) {
                    $stats['service_id_zero_fallback_cat']++;
                    $reason = 'service_id_zero_fallback_cat';
                } else {
                    $stats['service_id_zero_unresolved']++;
                    $stats['unresolved']++;
                    $reason = 'service_id_zero_unresolved';
                }
            } else {
                $catNumeric = preg_match('/^\d+$/', $catIdStr) ? (int) $catIdStr : null;
                if ($catNumeric !== null && $ctxMapExists && !empty($ctxMap)) {
                    $resolvedNodeId = resolveContextMappedNode($ctxMap, 'content', $catNumeric, 'tag', $svcId);
                    if ($resolvedNodeId !== null) {
                        $reason = 'ok_ctx_content_tag';
                    }
                }
                if ($resolvedNodeId === null) {
                    $resolvedNodeId = resolveMappedNode($map, $svcId, $sourcePriority);
                }
                if ($resolvedNodeId === null) {
                    $stats['service_id_missing_lookup']++;
                    $stats['unresolved']++;
                    $reason = 'service_id_missing_lookup';
                }
            }

            $payload = json_encode([
                'field' => $fieldName,
                'cat_id' => $catIdStr,
                'tuple' => $triple,
                'resolved_reason' => $reason,
            ], JSON_UNESCAPED_UNICODE);

            if ($resolvedNodeId !== null) {
                $stats['migrated']++;
                if ($apply) {
                    $insertUserServiceStmt->bind_param('iidds', $uid, $resolvedNodeId, $price, $duration, $payload);
                    $insertUserServiceStmt->execute();
                    $pendingWrites++;
                }
            } else {
                if (count($sampleUnresolved) < $maxUnresolvedSample) {
                    $sampleUnresolved[] = [$uid, $fieldName, $catIdStr, $serviceRaw, $reason];
                }
                if ($apply) {
                    $insertUnresolvedStmt->bind_param('isssddss', $uid, $fieldName, $catIdStr, $serviceRaw, $price, $duration, $reason, $payload);
                    $insertUnresolvedStmt->execute();
                    $pendingWrites++;
                }
            }

            if ($apply && $pendingWrites >= $batchSize) {
                if (!$mysqli->commit()) {
                    fwrite(STDERR, "Ошибка commit batch: {$mysqli->error}\n");
                    $mysqli->rollback();
                    exit(1);
                }
                $pendingWrites = 0;
            }
        }
    }
}
$res->free();
$insertUserServiceStmt->close();
$insertUnresolvedStmt->close();

if ($apply) {
    if ($pendingWrites > 0) {
        if (!$mysqli->commit()) {
            fwrite(STDERR, "Ошибка финального commit: {$mysqli->error}\n");
            $mysqli->rollback();
            exit(1);
        }
    }
    $mysqli->autocommit(true);
}

echo "=== Vigling Services Migrator ===\n";
echo "DB: {$config->db}\n";
echo "Mode: " . ($apply ? 'APPLY' : 'DRY-RUN') . "\n";
echo "user-id: " . ($userId ?? 'all') . "\n";
echo "from-user-id: " . ($fromUserId ?? 'none') . "\n";
echo "to-user-id: " . ($toUserId ?? 'none') . "\n";
echo "limit: " . ($limit ?? 'none') . "\n\n";

foreach ($stats as $k => $v) {
    echo "{$k}: {$v}\n";
}

if (!empty($sampleUnresolved)) {
    echo "\nПримеры unresolved:\n";
    foreach ($sampleUnresolved as $s) {
        echo " - user={$s[0]}, field={$s[1]}, cat={$s[2]}, service_raw={$s[3]}, reason={$s[4]}\n";
    }
}

echo "\nГотово.\n";
exit(0);
