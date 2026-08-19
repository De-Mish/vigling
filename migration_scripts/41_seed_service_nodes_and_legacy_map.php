<?php
/**
 * Наполняет #__vigling_service_nodes и #__vigling_service_legacy_map из legacy-источников.
 *
 * Источники:
 * - #__vigling_services (legacy_source = vigling_services)
 * - #__tags            (legacy_source = tag)
 * - #__content         (legacy_source = content)
 * - #__categories      (legacy_source = category)
 *
 * Запуск:
 *   php migration_scripts/41_seed_service_nodes_and_legacy_map.php
 *   php migration_scripts/41_seed_service_nodes_and_legacy_map.php --truncate
 */

define('_JEXEC', 1);
$baseDir = dirname(__DIR__);

require_once __DIR__ . '/load_j6_config.php';
$config = loadJ6Config($baseDir . '/configuration.php');
if (!$config) {
    fwrite(STDERR, "Не найден configuration.php\n");
    exit(1);
}

$truncate = in_array('--truncate', $argv ?? [], true);

function slugify(string $value): string
{
    $value = trim(mb_strtolower($value, 'UTF-8'));
    $value = preg_replace('/[^a-z0-9а-яё]+/ui', '-', $value) ?? '';
    $value = trim($value, '-');
    return $value !== '' ? $value : 'item';
}

function findNodeByLegacy(mysqli $db, string $table, string $source, int $legacyId): ?int
{
    $stmt = $db->prepare("SELECT id FROM `{$table}` WHERE legacy_source = ? AND legacy_id = ? LIMIT 1");
    if (!$stmt) {
        return null;
    }
    $stmt->bind_param('si', $source, $legacyId);
    $stmt->execute();
    $stmt->bind_result($id);
    $found = $stmt->fetch();
    $stmt->close();
    return $found ? (int) $id : null;
}

function upsertGroupNode(mysqli $db, string $table, string $source, string $title, int $sortOrder): int
{
    $legacyId = 0;
    $existingId = findNodeByLegacy($db, $table, $source, $legacyId);
    $slug = slugify($source);
    $path = 'legacy/' . $slug;

    if ($existingId !== null) {
        $stmt = $db->prepare("UPDATE `{$table}` SET title = ?, slug = ?, path = ?, sort_order = ?, type = 'group', level = 0, parent_id = NULL, is_active = 1 WHERE id = ?");
        if (!$stmt) {
            return $existingId;
        }
        $stmt->bind_param('sssii', $title, $slug, $path, $sortOrder, $existingId);
        $stmt->execute();
        $stmt->close();
        return $existingId;
    }

    $stmt = $db->prepare(
        "INSERT INTO `{$table}` (`parent_id`,`level`,`path`,`sort_order`,`type`,`title`,`slug`,`is_active`,`legacy_source`,`legacy_id`)
         VALUES (NULL,0,?,?,'group',?,?,1,?,0)"
    );
    if (!$stmt) {
        return 0;
    }
    $stmt->bind_param('sisss', $path, $sortOrder, $title, $slug, $source);
    $stmt->execute();
    $id = (int) $db->insert_id;
    $stmt->close();
    return $id;
}

function upsertServiceNode(
    mysqli $db,
    string $nodeTable,
    string $mapTable,
    int $parentId,
    int $sortOrder,
    string $legacySource,
    int $legacyId,
    string $title
): bool {
    $title = trim($title);
    if ($title === '') {
        $title = 'Услуга #' . $legacyId;
    }

    $slug = slugify($title . '-' . $legacyId);
    $path = 'legacy/' . slugify($legacySource) . '/' . $slug;

    $nodeId = findNodeByLegacy($db, $nodeTable, $legacySource, $legacyId);

    if ($nodeId !== null) {
        $stmt = $db->prepare(
            "UPDATE `{$nodeTable}`
             SET parent_id = ?, level = 1, path = ?, sort_order = ?, type = 'service', title = ?, slug = ?, is_active = 1
             WHERE id = ?"
        );
        if (!$stmt) {
            return false;
        }
        $stmt->bind_param('isissi', $parentId, $path, $sortOrder, $title, $slug, $nodeId);
        $ok = $stmt->execute();
        $stmt->close();
        if (!$ok) {
            return false;
        }
    } else {
        $stmt = $db->prepare(
            "INSERT INTO `{$nodeTable}` (`parent_id`,`level`,`path`,`sort_order`,`type`,`title`,`slug`,`is_active`,`legacy_source`,`legacy_id`)
             VALUES (?,?,?,?,'service',?,?,1,?,?)"
        );
        if (!$stmt) {
            return false;
        }
        $level = 1;
        $stmt->bind_param('iisisssi', $parentId, $level, $path, $sortOrder, $title, $slug, $legacySource, $legacyId);
        $ok = $stmt->execute();
        $nodeId = (int) $db->insert_id;
        $stmt->close();
        if (!$ok) {
            return false;
        }
    }

    $stmt = $db->prepare(
        "INSERT INTO `{$mapTable}` (`legacy_source`,`legacy_id`,`service_node_id`,`confidence`,`note`)
         VALUES (?,?,?,1.000,'auto-seed exact id')
         ON DUPLICATE KEY UPDATE `service_node_id`=VALUES(`service_node_id`), `confidence`=VALUES(`confidence`), `note`=VALUES(`note`)"
    );
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param('sii', $legacySource, $legacyId, $nodeId);
    $ok = $stmt->execute();
    $stmt->close();
    return $ok;
}

$mysqli = new mysqli($config->host, $config->user, $config->password, $config->db);
if ($mysqli->connect_error) {
    fwrite(STDERR, "Ошибка подключения к БД: " . $mysqli->connect_error . "\n");
    exit(1);
}
$mysqli->set_charset('utf8mb4');
$prefix = $mysqli->real_escape_string($config->dbprefix);

$tNodes = $prefix . 'vigling_service_nodes';
$tMap = $prefix . 'vigling_service_legacy_map';
$tVigling = $prefix . 'vigling_services';
$tTags = $prefix . 'tags';
$tContent = $prefix . 'content';
$tCategories = $prefix . 'categories';

if ($truncate) {
    if (!$mysqli->query("TRUNCATE TABLE `{$tMap}`")) {
        fwrite(STDERR, "Ошибка truncate {$tMap}: " . $mysqli->error . "\n");
        exit(1);
    }
    if (!$mysqli->query("TRUNCATE TABLE `{$tNodes}`")) {
        fwrite(STDERR, "Ошибка truncate {$tNodes}: " . $mysqli->error . "\n");
        exit(1);
    }
    echo "Очищены таблицы {$tNodes} и {$tMap}\n";
}

$groups = [
    'vigling_services' => ['title' => 'Legacy / vigling_services', 'sort' => 10],
    'tag' => ['title' => 'Legacy / tags', 'sort' => 20],
    'content' => ['title' => 'Legacy / content', 'sort' => 30],
    'category' => ['title' => 'Legacy / categories', 'sort' => 40],
];

$groupNodeIds = [];
foreach ($groups as $source => $meta) {
    $groupNodeIds[$source] = upsertGroupNode($mysqli, $tNodes, $source, $meta['title'], (int) $meta['sort']);
}

$sources = [];

$sources[] = [
    'legacy_source' => 'vigling_services',
    'sql' => "SELECT id, title FROM `{$tVigling}` ORDER BY id",
];
$sources[] = [
    'legacy_source' => 'tag',
    'sql' => "SELECT id, title FROM `{$tTags}` WHERE published IN (0,1) ORDER BY id",
];
$sources[] = [
    'legacy_source' => 'content',
    'sql' => "SELECT id, title FROM `{$tContent}` WHERE state IN (0,1) ORDER BY id",
];
$sources[] = [
    'legacy_source' => 'category',
    'sql' => "SELECT id, title FROM `{$tCategories}` WHERE published = 1 ORDER BY id",
];

$stats = [];
foreach ($sources as $src) {
    $legacySource = $src['legacy_source'];
    $stats[$legacySource] = ['total' => 0, 'upserted' => 0, 'failed' => 0];

    $res = $mysqli->query($src['sql']);
    if (!$res) {
        fwrite(STDERR, "Ошибка выборки {$legacySource}: " . $mysqli->error . "\n");
        exit(1);
    }

    $sort = 1;
    while ($row = $res->fetch_assoc()) {
        $stats[$legacySource]['total']++;
        $legacyId = (int) $row['id'];
        $title = (string) $row['title'];
        $ok = upsertServiceNode(
            $mysqli,
            $tNodes,
            $tMap,
            (int) $groupNodeIds[$legacySource],
            $sort,
            $legacySource,
            $legacyId,
            $title
        );
        if ($ok) {
            $stats[$legacySource]['upserted']++;
        } else {
            $stats[$legacySource]['failed']++;
        }
        $sort++;
    }
    $res->free();
}

echo "Seed завершён.\n";
foreach ($stats as $source => $st) {
    echo " - {$source}: total={$st['total']}, upserted={$st['upserted']}, failed={$st['failed']}\n";
}
exit(0);

