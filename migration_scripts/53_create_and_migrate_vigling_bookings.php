<?php
/**
 * Создает #__vigling_bookings и переносит данные из #__jsn_orders.
 *
 * По умолчанию:
 * - создаёт таблицу, если её нет
 * - копирует недостающие записи по PK id из #__jsn_orders
 *
 * Опции:
 *   --truncate-target  очистить #__vigling_bookings перед копированием
 *
 * Запуск:
 *   php migration_scripts/53_create_and_migrate_vigling_bookings.php
 *   php migration_scripts/53_create_and_migrate_vigling_bookings.php --truncate-target
 */

define('_JEXEC', 1);
$baseDir = dirname(__DIR__);
require_once __DIR__ . '/load_j6_config.php';
$cfg = loadJ6Config($baseDir . '/configuration.php');
if (!$cfg) {
    fwrite(STDERR, "Не найден configuration.php\n");
    exit(1);
}

$truncate = in_array('--truncate-target', $argv ?? [], true);

$db = new mysqli($cfg->host, $cfg->user, $cfg->password, $cfg->db);
if ($db->connect_error) {
    fwrite(STDERR, "DB connect error: {$db->connect_error}\n");
    exit(1);
}
$db->set_charset('utf8mb4');

$p = $cfg->dbprefix;
$src = $p . 'jsn_orders';
$dst = $p . 'vigling_bookings';

$dbEsc = $db->real_escape_string($cfg->db);
$srcEsc = $db->real_escape_string($src);
$dstEsc = $db->real_escape_string($dst);

$exists = function(string $table) use ($db, $dbEsc): bool {
    $t = $db->real_escape_string($table);
    $res = $db->query("SELECT 1 FROM information_schema.tables WHERE table_schema='{$dbEsc}' AND table_name='{$t}' LIMIT 1");
    return $res && $res->num_rows > 0;
};

if (!$exists($src)) {
    fwrite(STDERR, "Source table not found: {$src}\n");
    exit(1);
}

if (!$exists($dst)) {
    if (!$db->query("CREATE TABLE `{$dstEsc}` LIKE `{$srcEsc}`")) {
        fwrite(STDERR, "Create table failed: {$db->error}\n");
        exit(1);
    }
}

if ($truncate) {
    if (!$db->query("TRUNCATE TABLE `{$dstEsc}`")) {
        fwrite(STDERR, "Truncate failed: {$db->error}\n");
        exit(1);
    }
}

$srcColsRes = $db->query("SHOW COLUMNS FROM `{$srcEsc}`");
$dstColsRes = $db->query("SHOW COLUMNS FROM `{$dstEsc}`");
if (!$srcColsRes || !$dstColsRes) {
    fwrite(STDERR, "SHOW COLUMNS failed: {$db->error}\n");
    exit(1);
}

$srcCols = [];
while ($r = $srcColsRes->fetch_assoc()) $srcCols[] = (string) $r['Field'];
$dstCols = [];
while ($r = $dstColsRes->fetch_assoc()) $dstCols[] = (string) $r['Field'];
$srcColsRes->free();
$dstColsRes->free();

$common = array_values(array_intersect($srcCols, $dstCols));
if ($common === []) {
    fwrite(STDERR, "No common columns between {$src} and {$dst}\n");
    exit(1);
}

$colSql = implode(', ', array_map(static fn($c) => '`' . str_replace('`', '``', $c) . '`', $common));

$copySql = "INSERT INTO `{$dstEsc}` ({$colSql})
            SELECT {$colSql}
            FROM `{$srcEsc}` s
            WHERE NOT EXISTS (SELECT 1 FROM `{$dstEsc}` d WHERE d.id = s.id)";

if (!$db->query($copySql)) {
    fwrite(STDERR, "Copy failed: {$db->error}\n");
    exit(1);
}
$inserted = $db->affected_rows;

$srcCount = 0;
$dstCount = 0;
if ($res = $db->query("SELECT COUNT(*) AS c FROM `{$srcEsc}`")) {
    $srcCount = (int) ($res->fetch_assoc()['c'] ?? 0);
    $res->free();
}
if ($res = $db->query("SELECT COUNT(*) AS c FROM `{$dstEsc}`")) {
    $dstCount = (int) ($res->fetch_assoc()['c'] ?? 0);
    $res->free();
}

echo "vigling_bookings ready\n";
echo " - source_rows: {$srcCount}\n";
echo " - target_rows: {$dstCount}\n";
echo " - inserted_now: {$inserted}\n";

