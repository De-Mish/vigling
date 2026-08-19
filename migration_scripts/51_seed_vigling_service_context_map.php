<?php
/**
 * Строит context-aware mapping (content + tag -> service node) и branch-specific узлы.
 *
 * Источник:
 * - #__contentitem_tag_map (type_alias = com_content.article)
 *
 * Создаваемая структура узлов:
 * - Context / content-tag
 *   - <Top category>                (group)
 *     - <Content title>             (group)
 *       - <Tag title>               (service)  // отдельный leaf на каждую пару (content_id, tag_id)
 *
 * И записывает в #__vigling_service_context_map:
 * - context_source='content'
 * - context_id=content.id
 * - legacy_source='tag'
 * - legacy_id=tag.id
 * - service_node_id=<leaf node id>
 *
 * Запуск:
 *   php migration_scripts/51_seed_vigling_service_context_map.php
 *   php migration_scripts/51_seed_vigling_service_context_map.php --truncate
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

function slugify_ctx(string $value): string
{
    $value = trim(mb_strtolower($value, 'UTF-8'));
    $value = preg_replace('/[^a-z0-9а-яё]+/ui', '-', $value) ?? '';
    $value = trim($value, '-');
    return $value !== '' ? $value : 'item';
}

function qs(mysqli $db, string $sql)
{
    $res = $db->query($sql);
    if (!$res) {
        throw new RuntimeException($db->error . ' | SQL: ' . $sql);
    }
    return $res;
}

function findNodeByPath(mysqli $db, string $nodeTable, string $path): ?array
{
    $pathEsc = $db->real_escape_string($path);
    $res = $db->query("SELECT id, level FROM `{$nodeTable}` WHERE path = '{$pathEsc}' LIMIT 1");
    if (!$res) {
        return null;
    }
    $row = $res->fetch_assoc();
    $res->free();
    return $row ? ['id' => (int) $row['id'], 'level' => (int) $row['level']] : null;
}

function upsertNodeByPath(
    mysqli $db,
    string $nodeTable,
    ?int $parentId,
    int $level,
    string $path,
    int $sortOrder,
    string $type,
    string $title,
    string $slug,
    ?string $legacySource,
    ?int $legacyId
): int {
    $existing = findNodeByPath($db, $nodeTable, $path);
    if ($existing) {
        $id = (int) $existing['id'];
        $parentSql = $parentId === null ? 'NULL' : (string) (int) $parentId;
        $legacySourceSql = $legacySource === null ? 'NULL' : "'" . $db->real_escape_string($legacySource) . "'";
        $legacyIdSql = $legacyId === null ? 'NULL' : (string) (int) $legacyId;
        $sql = "UPDATE `{$nodeTable}`
                SET parent_id = {$parentSql},
                    level = " . (int) $level . ",
                    sort_order = " . (int) $sortOrder . ",
                    type = '" . $db->real_escape_string($type) . "',
                    title = '" . $db->real_escape_string($title) . "',
                    slug = '" . $db->real_escape_string($slug) . "',
                    is_active = 1,
                    legacy_source = {$legacySourceSql},
                    legacy_id = {$legacyIdSql}
                WHERE id = {$id}";
        if (!$db->query($sql)) {
            throw new RuntimeException($db->error);
        }
        return $id;
    }

    $stmt = $db->prepare(
        "INSERT INTO `{$nodeTable}` (`parent_id`,`level`,`path`,`sort_order`,`type`,`title`,`slug`,`is_active`,`legacy_source`,`legacy_id`)
         VALUES (?,?,?,?,?,?,?,1,?,?)"
    );
    if (!$stmt) {
        throw new RuntimeException($db->error);
    }
    $legacySourceParam = $legacySource;
    $legacyIdParam = $legacyId;
    $stmt->bind_param(
        'iisissssi',
        $parentId,
        $level,
        $path,
        $sortOrder,
        $type,
        $title,
        $slug,
        $legacySourceParam,
        $legacyIdParam
    );
    if (!$stmt->execute()) {
        $err = $stmt->error;
        $stmt->close();
        throw new RuntimeException($err);
    }
    $id = (int) $db->insert_id;
    $stmt->close();
    return $id;
}

function topCategoryMeta(mysqli $db, string $catTable, int $catId): array
{
    static $cache = [];
    if ($catId <= 0) {
        return ['id' => 0, 'title' => 'Услуги', 'slug' => 'uslugi'];
    }
    if (isset($cache[$catId])) {
        return $cache[$catId];
    }

    $res = $db->query("SELECT id,title,parent_id,path FROM `{$catTable}` WHERE id = " . (int) $catId . " LIMIT 1");
    $cat = $res ? $res->fetch_assoc() : null;
    if ($res) {
        $res->free();
    }
    if (!$cat) {
        return $cache[$catId] = ['id' => $catId, 'title' => 'Категория #' . $catId, 'slug' => 'cat-' . $catId];
    }

    $title = (string) $cat['title'];
    $path = (string) ($cat['path'] ?? '');
    $parentId = (int) ($cat['parent_id'] ?? 0);

    if (str_starts_with($path, 'uslugi/') && $parentId > 0) {
        $res = $db->query("SELECT id,title,path FROM `{$catTable}` WHERE id = {$parentId} LIMIT 1");
        $parent = $res ? $res->fetch_assoc() : null;
        if ($res) {
            $res->free();
        }
        $parentTitle = (string) ($parent['title'] ?? '');
        $parentPath = (string) ($parent['path'] ?? '');
        $isDirectUnderUslugi = $parentPath === 'uslugi' || $parentTitle === 'Услуги';
        if (!$isDirectUnderUslugi && $parentTitle !== '') {
            $title = $parentTitle;
            return $cache[$catId] = ['id' => (int) ($parent['id'] ?? $parentId), 'title' => $title, 'slug' => slugify_ctx($title)];
        }
    }

    return $cache[$catId] = ['id' => (int) $cat['id'], 'title' => $title, 'slug' => slugify_ctx($title)];
}

$mysqli = new mysqli($config->host, $config->user, $config->password, $config->db);
if ($mysqli->connect_error) {
    fwrite(STDERR, "Ошибка подключения к БД: " . $mysqli->connect_error . "\n");
    exit(1);
}
$mysqli->set_charset('utf8mb4');

$prefix = $config->dbprefix;
$tNodes = $prefix . 'vigling_service_nodes';
$tCtxMap = $prefix . 'vigling_service_context_map';
$tMap = $prefix . 'contentitem_tag_map';
$tContent = $prefix . 'content';
$tTags = $prefix . 'tags';
$tCats = $prefix . 'categories';

if ($truncate) {
    if (!$mysqli->query("TRUNCATE TABLE `{$tCtxMap}`")) {
        fwrite(STDERR, "Ошибка truncate {$tCtxMap}: {$mysqli->error}\n");
        exit(1);
    }
    // Remove only context-generated nodes.
    if (!$mysqli->query("DELETE FROM `{$tNodes}` WHERE `path` LIKE 'context/%'")) {
        fwrite(STDERR, "Ошибка очистки context nodes: {$mysqli->error}\n");
        exit(1);
    }
    echo "Очищены context map и context nodes\n";
}

try {
    $mysqli->begin_transaction();

    $rootPath = 'context/content-tag';
    $rootId = upsertNodeByPath(
        $mysqli,
        $tNodes,
        null,
        0,
        $rootPath,
        1000,
        'group',
        'Context / content-tag',
        'content-tag',
        'context_root',
        1
    );

    $sql = "
        SELECT DISTINCT
            m.tag_id,
            c.id AS content_id,
            c.title AS content_title,
            c.catid,
            t.title AS tag_title
        FROM `{$tMap}` m
        INNER JOIN `{$tContent}` c ON c.id = m.content_item_id
        INNER JOIN `{$tTags}` t ON t.id = m.tag_id
        WHERE m.type_alias = 'com_content.article'
          AND c.state IN (0,1)
          AND t.published IN (0,1)
        ORDER BY c.id ASC, m.tag_id ASC";

    $res = qs($mysqli, $sql);

    $stats = [
        'pairs_total' => 0,
        'ctx_map_upserted' => 0,
        'nodes_top' => 0,
        'nodes_content' => 0,
        'nodes_leaf' => 0,
    ];

    $seenTop = [];
    $seenContent = [];
    $seenLeaf = [];

    while ($row = $res->fetch_assoc()) {
        $stats['pairs_total']++;
        $tagId = (int) $row['tag_id'];
        $contentId = (int) $row['content_id'];
        $contentTitle = trim((string) $row['content_title']);
        $catId = (int) ($row['catid'] ?? 0);
        $tagTitle = trim((string) $row['tag_title']);

        if ($tagId <= 0 || $contentId <= 0) {
            continue;
        }

        $topMeta = topCategoryMeta($mysqli, $tCats, $catId);
        $topTitle = $topMeta['title'];
        $topSlug = $topMeta['slug'] ?: ('cat-' . (int) $topMeta['id']);

        $topPath = $rootPath . '/' . $topSlug . '-' . (int) $topMeta['id'];
        $topId = upsertNodeByPath(
            $mysqli,
            $tNodes,
            $rootId,
            1,
            $topPath,
            (int) $topMeta['id'],
            'group',
            $topTitle,
            $topSlug,
            'ctx_top_category',
            (int) $topMeta['id']
        );
        if (!isset($seenTop[$topId])) {
            $seenTop[$topId] = true;
            $stats['nodes_top']++;
        }

        if ($contentTitle === '') {
            $contentTitle = 'Контент #' . $contentId;
        }
        $contentSlug = slugify_ctx($contentTitle);
        $contentPath = $topPath . '/' . $contentSlug . '-' . $contentId;
        $contentNodeId = upsertNodeByPath(
            $mysqli,
            $tNodes,
            $topId,
            2,
            $contentPath,
            $contentId,
            'group',
            $contentTitle,
            $contentSlug,
            'ctx_content',
            $contentId
        );
        if (!isset($seenContent[$contentNodeId])) {
            $seenContent[$contentNodeId] = true;
            $stats['nodes_content']++;
        }

        if ($tagTitle === '') {
            $tagTitle = 'Тег #' . $tagId;
        }
        $leafSlug = slugify_ctx($tagTitle);
        // Include content_id in path to guarantee uniqueness for the same tag in different branches.
        $leafPath = $contentPath . '/' . $leafSlug . '-tag' . $tagId . '-c' . $contentId;
        $leafNodeId = upsertNodeByPath(
            $mysqli,
            $tNodes,
            $contentNodeId,
            3,
            $leafPath,
            $tagId,
            'service',
            $tagTitle,
            $leafSlug,
            'ctx_tag',
            $tagId
        );
        if (!isset($seenLeaf[$leafNodeId])) {
            $seenLeaf[$leafNodeId] = true;
            $stats['nodes_leaf']++;
        }

        $stmt = $mysqli->prepare(
            "INSERT INTO `{$tCtxMap}` (`context_source`,`context_id`,`legacy_source`,`legacy_id`,`service_node_id`,`confidence`,`note`)
             VALUES ('content', ?, 'tag', ?, ?, 1.000, 'auto-seed content+tag')
             ON DUPLICATE KEY UPDATE
               `service_node_id` = VALUES(`service_node_id`),
               `confidence` = VALUES(`confidence`),
               `note` = VALUES(`note`)"
        );
        if (!$stmt) {
            throw new RuntimeException($mysqli->error);
        }
        $stmt->bind_param('iii', $contentId, $tagId, $leafNodeId);
        if (!$stmt->execute()) {
            $err = $stmt->error;
            $stmt->close();
            throw new RuntimeException($err);
        }
        $stmt->close();
        $stats['ctx_map_upserted']++;
    }
    $res->free();

    $mysqli->commit();

    echo "Context seed завершён.\n";
    foreach ($stats as $k => $v) {
        echo " - {$k}: {$v}\n";
    }
    exit(0);
} catch (Throwable $e) {
    $mysqli->rollback();
    fwrite(STDERR, "Ошибка context seed: " . $e->getMessage() . "\n");
    exit(1);
}

