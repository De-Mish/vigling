<?php
/**
 * Добавляет runtime-колонки в новые таблицы услуг и заполняет их из source_payload.
 *
 * Цель:
 * - уменьшить runtime-зависимость от source_payload JSON
 * - подготовить безопасный future cleanup source_payload
 *
 * Что делает:
 * - ALTER TABLE #__vigling_user_services:
 *   legacy_cat_id, legacy_tag_id, pause_min, payload_variant
 * - ALTER TABLE #__vigling_user_stock_services:
 *   legacy_cat_id, legacy_tag_id, pause_min, payload_variant, old_price, about_stock, count_stock
 * - backfill обычных услуг (SQL)
 * - backfill акций (PHP, с обработкой edge-case tuple)
 *
 * Запуск:
 *   php migration_scripts/54_add_runtime_columns_and_backfill_service_payload.php
 */

define('_JEXEC', 1);

$baseDir = dirname(__DIR__);
require_once __DIR__ . '/load_j6_config.php';

$cfg = loadJ6Config($baseDir . '/configuration.php');
if (!$cfg) {
    fwrite(STDERR, "Не найден configuration.php\n");
    exit(1);
}

$db = new mysqli($cfg->host, $cfg->user, $cfg->password, $cfg->db);
if ($db->connect_error) {
    fwrite(STDERR, "DB connect error: {$db->connect_error}\n");
    exit(1);
}
$db->set_charset('utf8mb4');

$p = $cfg->dbprefix;
$tUser = $p . 'vigling_user_services';
$tStock = $p . 'vigling_user_stock_services';

function tableExists(mysqli $db, string $dbName, string $table): bool
{
    $dbEsc = $db->real_escape_string($dbName);
    $tEsc = $db->real_escape_string($table);
    $res = $db->query("SELECT 1 FROM information_schema.tables WHERE table_schema='{$dbEsc}' AND table_name='{$tEsc}' LIMIT 1");
    return (bool) ($res && $res->num_rows > 0);
}

function columnExists(mysqli $db, string $table, string $column): bool
{
    $tEsc = $db->real_escape_string($table);
    $cEsc = $db->real_escape_string($column);
    $res = $db->query("SHOW COLUMNS FROM `{$tEsc}` LIKE '{$cEsc}'");
    return (bool) ($res && $res->num_rows > 0);
}

function addColumnIfMissing(mysqli $db, string $table, string $column, string $ddl): void
{
    if (columnExists($db, $table, $column)) {
        return;
    }
    $tEsc = $db->real_escape_string($table);
    if (!$db->query("ALTER TABLE `{$tEsc}` ADD COLUMN {$ddl}")) {
        throw new RuntimeException("ALTER TABLE {$table} add {$column} failed: " . $db->error);
    }
}

function qIdent(string $name): string
{
    return '`' . str_replace('`', '``', $name) . '`';
}

function toIntVal($v): ?int
{
    if ($v === null) return null;
    if (is_int($v)) return $v;
    if (is_float($v)) return (int) $v;
    if (is_string($v) && preg_match('/-?\d+/', $v, $m)) return (int) $m[0];
    return null;
}

function toFloatVal($v): ?float
{
    if ($v === null) return null;
    if (is_int($v) || is_float($v)) return (float) $v;
    if (is_string($v)) {
        $vv = str_replace(',', '.', trim($v));
        if (preg_match('/-?\d+(\.\d+)?/', $vv, $m)) return (float) $m[0];
    }
    return null;
}

/**
 * @return array{0:int,1:int}|null [duration_min, pause_min]
 */
function parseDurationPause($v): ?array
{
    if (is_int($v)) return [$v, 0];
    if (is_float($v)) {
        $s = rtrim(rtrim(number_format($v, 6, '.', ''), '0'), '.');
        $v = $s;
    }
    if (is_string($v)) {
        $v = trim($v);
        if ($v === '') return null;
        if (preg_match('/^-?\d+$/', $v)) return [(int) $v, 0];
        if (preg_match('/^-?\d+\.(\d+)$/', $v, $m)) {
            return [(int) $v, (int) $m[1]];
        }
    }
    return null;
}

/**
 * @return array<string,mixed>
 */
function parseStockPayloadForColumns(?string $payloadRaw): array
{
    $out = [
        'legacy_cat_id' => null,
        'legacy_tag_id' => null,
        'pause_min' => 0,
        'old_price' => null,
        'about_stock' => null,
        'count_stock' => null,
        'payload_variant' => null,
    ];

    if ($payloadRaw === null || trim($payloadRaw) === '') {
        return $out;
    }

    $decoded = json_decode($payloadRaw, true);
    if (!is_array($decoded)) {
        $out['payload_variant'] = 'invalid_json';
        return $out;
    }

    $catId = toIntVal($decoded['cat_id'] ?? null);
    if ($catId !== null && $catId > 0) {
        $out['legacy_cat_id'] = $catId;
    }

    // Support future payload_v1 shape too (dual-write path).
    if (isset($decoded['item']) && is_array($decoded['item'])) {
        $item = $decoded['item'];
        $out['legacy_cat_id'] = toIntVal($item['cat_id'] ?? $out['legacy_cat_id']);
        $serviceRaw = (string) ($item['service_raw'] ?? '');
        if ($serviceRaw !== '') {
            $parts = explode('-', $serviceRaw, 2);
            if (isset($parts[1])) {
                $tag = toIntVal($parts[1]);
                if ($tag !== null && $tag > 0) $out['legacy_tag_id'] = $tag;
            } else {
                $tag = toIntVal($parts[0]);
                if ($tag !== null && $tag > 0) $out['legacy_tag_id'] = $tag;
            }
        }
        $dp = parseDurationPause($item['duration'] ?? ($item['duration_min'] ?? null));
        if ($dp !== null) {
            $out['pause_min'] = $dp[1];
        }
        $out['old_price'] = toFloatVal($item['old_price'] ?? null);
        $about = trim((string) ($item['about_stock'] ?? ''));
        $out['about_stock'] = $about !== '' ? $about : null;
        $count = toIntVal($item['count_stock'] ?? null);
        $out['count_stock'] = $count !== null && $count > 0 ? $count : null;
        $out['payload_variant'] = 'vigling_payload_v1';
        return $out;
    }

    $tuple = $decoded['tuple'] ?? null;
    if (!is_array($tuple)) {
        $out['payload_variant'] = 'no_tuple';
        return $out;
    }

    // Nested malformed payload: keep fallback on source_payload.
    if (isset($tuple[0]) && is_array($tuple[0])) {
        $out['payload_variant'] = 'stock_tuple_nested_list';
        return $out;
    }

    $len = count($tuple);

    // Common format: [price, duration, tag, old_price, about, count]
    $isCommon = $len >= 3;
    if ($isCommon) {
        $durPause = parseDurationPause($tuple[1] ?? null);
        if ($durPause !== null) {
            $tag = toIntVal($tuple[2] ?? null);
            if ($tag !== null && $tag > 0) {
                $out['legacy_tag_id'] = $tag;
            }
            $out['pause_min'] = $durPause[1];
            $out['old_price'] = toFloatVal($tuple[3] ?? null);
            $about = trim((string) ($tuple[4] ?? ''));
            $out['about_stock'] = $about !== '' ? $about : null;
            $count = toIntVal($tuple[5] ?? null);
            $out['count_stock'] = $count !== null && $count > 0 ? $count : null;
            $out['payload_variant'] = ($len === 6) ? 'stock_tuple_v1_count' : (($len === 5) ? 'stock_tuple_v1' : 'stock_tuple_legacy');

            // Edge shifted format len=7: [price, pause, duration, tag, old, about, count]
            if (
                $len >= 7
                && parseDurationPause($tuple[2] ?? null) !== null
                && ($tmpTag = toIntVal($tuple[3] ?? null)) !== null
            ) {
                $out['legacy_tag_id'] = $tmpTag > 0 ? $tmpTag : $out['legacy_tag_id'];
                $out['pause_min'] = max(0, toIntVal($tuple[1] ?? 0) ?? 0);
                $out['old_price'] = toFloatVal($tuple[4] ?? null);
                $about = trim((string) ($tuple[5] ?? ''));
                $out['about_stock'] = $about !== '' ? $about : null;
                $count = toIntVal($tuple[6] ?? null);
                $out['count_stock'] = $count !== null && $count > 0 ? $count : null;
                $out['payload_variant'] = 'stock_tuple_v2_shifted_pause';
            }

            return $out;
        }
    }

    // Residual fallback rows (len=3 but unusual positions) - best effort
    if ($len >= 3) {
        $tag = toIntVal($tuple[2] ?? null);
        if ($tag !== null && $tag > 0) {
            $out['legacy_tag_id'] = $tag;
        }
        $dp = parseDurationPause($tuple[1] ?? null);
        if ($dp !== null) {
            $out['pause_min'] = $dp[1];
        }
        $out['payload_variant'] = 'stock_tuple_best_effort';
        return $out;
    }

    $out['payload_variant'] = 'stock_tuple_unknown';
    return $out;
}

if (!tableExists($db, $cfg->db, $tUser) || !tableExists($db, $cfg->db, $tStock)) {
    fwrite(STDERR, "Не найдены таблицы новых услуг/акций\n");
    exit(1);
}

echo "Adding columns if missing...\n";

addColumnIfMissing($db, $tUser, 'legacy_cat_id', qIdent('legacy_cat_id') . ' INT NULL');
addColumnIfMissing($db, $tUser, 'legacy_tag_id', qIdent('legacy_tag_id') . ' INT NULL');
addColumnIfMissing($db, $tUser, 'pause_min', qIdent('pause_min') . ' SMALLINT UNSIGNED NOT NULL DEFAULT 0');
addColumnIfMissing($db, $tUser, 'payload_variant', qIdent('payload_variant') . ' VARCHAR(32) NULL');

addColumnIfMissing($db, $tStock, 'legacy_cat_id', qIdent('legacy_cat_id') . ' INT NULL');
addColumnIfMissing($db, $tStock, 'legacy_tag_id', qIdent('legacy_tag_id') . ' INT NULL');
addColumnIfMissing($db, $tStock, 'pause_min', qIdent('pause_min') . ' SMALLINT UNSIGNED NOT NULL DEFAULT 0');
addColumnIfMissing($db, $tStock, 'payload_variant', qIdent('payload_variant') . ' VARCHAR(32) NULL');
addColumnIfMissing($db, $tStock, 'old_price', qIdent('old_price') . ' DECIMAL(10,2) NULL');
addColumnIfMissing($db, $tStock, 'about_stock', qIdent('about_stock') . ' VARCHAR(512) NULL');
addColumnIfMissing($db, $tStock, 'count_stock', qIdent('count_stock') . ' INT NULL');

echo "Backfill user services (SQL)...\n";
$tUserEsc = $db->real_escape_string($tUser);
$sqlUser = "
UPDATE `{$tUserEsc}`
SET
  `legacy_cat_id` = CASE
    WHEN JSON_EXTRACT(`source_payload`, '$.cat_id') IS NULL THEN `legacy_cat_id`
    WHEN JSON_UNQUOTE(JSON_EXTRACT(`source_payload`, '$.cat_id')) NOT REGEXP '^-?[0-9]+$' THEN NULL
    ELSE CAST(JSON_UNQUOTE(JSON_EXTRACT(`source_payload`, '$.cat_id')) AS UNSIGNED)
  END,
  `legacy_tag_id` = CASE
    WHEN JSON_EXTRACT(`source_payload`, '$.tuple[2]') IS NULL THEN `legacy_tag_id`
    WHEN JSON_UNQUOTE(JSON_EXTRACT(`source_payload`, '$.tuple[2]')) NOT REGEXP '^-?[0-9]+$' THEN NULL
    ELSE CAST(JSON_UNQUOTE(JSON_EXTRACT(`source_payload`, '$.tuple[2]')) AS SIGNED)
  END,
  `pause_min` = CASE
    WHEN JSON_UNQUOTE(JSON_EXTRACT(`source_payload`, '$.tuple[1]')) REGEXP '^-?[0-9]+\\\\.[0-9]+$'
      THEN CAST(SUBSTRING_INDEX(JSON_UNQUOTE(JSON_EXTRACT(`source_payload`, '$.tuple[1]')), '.', -1) AS UNSIGNED)
    ELSE 0
  END,
  `payload_variant` = CASE
    WHEN JSON_EXTRACT(`source_payload`, '$.source') IS NOT NULL THEN COALESCE(`payload_variant`, 'vigling_payload_v1')
    WHEN JSON_EXTRACT(`source_payload`, '$.tuple') IS NOT NULL THEN 'legacy_tuple_v1'
    ELSE COALESCE(`payload_variant`, 'unknown')
  END
WHERE `source_payload` IS NOT NULL
";
if (!$db->query($sqlUser)) {
    fwrite(STDERR, "User services backfill failed: {$db->error}\n");
    exit(1);
}
$userAffected = $db->affected_rows;

echo "Backfill stock services (PHP batches)...\n";
$tStockEsc = $db->real_escape_string($tStock);
$selectSql = "SELECT id, source_payload FROM `{$tStockEsc}` ORDER BY id ASC";
$res = $db->query($selectSql);
if (!$res) {
    fwrite(STDERR, "Stock select failed: {$db->error}\n");
    exit(1);
}

$upd = $db->prepare(
    "UPDATE `{$tStockEsc}` SET
        `legacy_cat_id` = ?,
        `legacy_tag_id` = ?,
        `pause_min` = ?,
        `payload_variant` = ?,
        `old_price` = ?,
        `about_stock` = ?,
        `count_stock` = ?
      WHERE `id` = ?"
);
if (!$upd) {
    fwrite(STDERR, "Prepare stock update failed: {$db->error}\n");
    exit(1);
}

$stockTotal = 0;
$stockUpdated = 0;
$variantStats = [];
while ($row = $res->fetch_assoc()) {
    $stockTotal++;
    $parsed = parseStockPayloadForColumns((string) ($row['source_payload'] ?? ''));
    $variant = (string) ($parsed['payload_variant'] ?? 'null');
    $variantStats[$variant] = ($variantStats[$variant] ?? 0) + 1;

    $legacyCat = $parsed['legacy_cat_id'];
    $legacyTag = $parsed['legacy_tag_id'];
    $pauseMin = (int) ($parsed['pause_min'] ?? 0);
    $payloadVariant = $parsed['payload_variant'];
    $oldPrice = $parsed['old_price'];
    $about = $parsed['about_stock'];
    $count = $parsed['count_stock'];
    $id = (int) $row['id'];

    $legacyCatVal = ($legacyCat === null || (int) $legacyCat <= 0) ? null : (int) $legacyCat;
    $legacyTagVal = ($legacyTag === null || (int) $legacyTag <= 0) ? null : (int) $legacyTag;
    $oldPriceVal = ($oldPrice === null) ? null : round((float) $oldPrice, 2);
    $aboutVal = ($about === null || trim((string) $about) === '') ? null : mb_substr((string) $about, 0, 512);
    $countVal = ($count === null || (int) $count <= 0) ? null : (int) $count;
    $payloadVariantVal = $payloadVariant !== null ? (string) $payloadVariant : null;

    $upd->bind_param(
        'iiisdsii',
        $legacyCatVal,
        $legacyTagVal,
        $pauseMin,
        $payloadVariantVal,
        $oldPriceVal,
        $aboutVal,
        $countVal,
        $id
    );
    if (!$upd->execute()) {
        fwrite(STDERR, "Stock update failed for id={$id}: {$upd->error}\n");
        $res->free();
        $upd->close();
        exit(1);
    }
    $stockUpdated += $upd->affected_rows;
}
$res->free();
$upd->close();

ksort($variantStats);

echo "Done\n";
echo " - user_services_backfill_affected: {$userAffected}\n";
echo " - stock_rows_scanned: {$stockTotal}\n";
echo " - stock_rows_updated: {$stockUpdated}\n";
echo " - stock_payload_variants:\n";
foreach ($variantStats as $variant => $cnt) {
    echo "   - {$variant}: {$cnt}\n";
}

// Quick sanity
$checks = [
    'user_services_cols' => "SELECT COUNT(*) c FROM `{$tUserEsc}` WHERE `legacy_cat_id` IS NOT NULL OR `legacy_tag_id` IS NOT NULL OR `pause_min` > 0",
    'stock_services_cols' => "SELECT COUNT(*) c FROM `{$tStockEsc}` WHERE `legacy_cat_id` IS NOT NULL OR `legacy_tag_id` IS NOT NULL OR `pause_min` > 0 OR `old_price` IS NOT NULL OR `about_stock` IS NOT NULL OR `count_stock` IS NOT NULL",
];
foreach ($checks as $label => $sql) {
    $r = $db->query($sql);
    $c = $r ? (int) (($r->fetch_assoc()['c'] ?? 0)) : 0;
    if ($r) $r->free();
    echo " - {$label}: {$c}\n";
}
