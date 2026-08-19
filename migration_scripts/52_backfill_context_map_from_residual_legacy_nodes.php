<?php
/**
 * Дозасев context-aware mapping из уже мигрированных residual rows,
 * которые все еще ссылаются на fallback узлы `legacy/tag/...`.
 *
 * Источник:
 * - #__vigling_user_services + #__vigling_service_nodes
 * - #__vigling_user_stock_services + #__vigling_service_nodes
 *
 * Извлекает distinct пары:
 * - context = source_payload.cat_id (ожидаемо content.id)
 * - legacy tag id = service_nodes.legacy_id
 *
 * И дозаполняет:
 * - #__vigling_service_context_map
 * - branch-specific context nodes в #__vigling_service_nodes (если их нет)
 *
 * Запуск:
 *   php migration_scripts/52_backfill_context_map_from_residual_legacy_nodes.php
 */

define('_JEXEC', 1);
$baseDir = dirname(__DIR__);

require_once __DIR__ . '/load_j6_config.php';
$config = loadJ6Config($baseDir . '/configuration.php');
if (!$config) {
    fwrite(STDERR, "Не найден configuration.php\n");
    exit(1);
}

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
$tUserServices = $prefix . 'vigling_user_services';
$tUserStockServices = $prefix . 'vigling_user_stock_services';
$tContent = $prefix . 'content';
$tTags = $prefix . 'tags';
$tCats = $prefix . 'categories';

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
            s.context_id AS content_id,
            c.title AS content_title,
            c.catid,
            s.tag_id,
            t.title AS tag_title
        FROM (
            SELECT
                CAST(JSON_UNQUOTE(JSON_EXTRACT(us.source_payload, '$.cat_id')) AS UNSIGNED) AS context_id,
                n.legacy_id AS tag_id
            FROM `{$tUserServices}` us
            INNER JOIN `{$tNodes}` n ON n.id = us.service_node_id
            WHERE n.path LIKE 'legacy/tag/%'
              AND n.legacy_source = 'tag'
              AND n.legacy_id IS NOT NULL
              AND JSON_EXTRACT(us.source_payload, '$.cat_id') IS NOT NULL

            UNION DISTINCT

            SELECT
                CAST(JSON_UNQUOTE(JSON_EXTRACT(ss.source_payload, '$.cat_id')) AS UNSIGNED) AS context_id,
                n.legacy_id AS tag_id
            FROM `{$tUserStockServices}` ss
            INNER JOIN `{$tNodes}` n ON n.id = ss.service_node_id
            WHERE n.path LIKE 'legacy/tag/%'
              AND n.legacy_source = 'tag'
              AND n.legacy_id IS NOT NULL
              AND JSON_EXTRACT(ss.source_payload, '$.cat_id') IS NOT NULL
        ) s
        LEFT JOIN `{$tContent}` c ON c.id = s.context_id
        LEFT JOIN `{$tTags}` t ON t.id = s.tag_id
        WHERE s.context_id > 0
          AND s.tag_id > 0
        ORDER BY s.context_id ASC, s.tag_id ASC";

    $res = qs($mysqli, $sql);

    $stats = [
        'pairs_scanned' => 0,
        'ctx_map_upserted' => 0,
        'nodes_top_new' => 0,
        'nodes_content_new' => 0,
        'nodes_leaf_new' => 0,
    ];

    while ($row = $res->fetch_assoc()) {
        $stats['pairs_scanned']++;
        $contentId = (int) ($row['content_id'] ?? 0);
        $tagId = (int) ($row['tag_id'] ?? 0);
        $contentTitle = trim((string) ($row['content_title'] ?? ''));
        $tagTitle = trim((string) ($row['tag_title'] ?? ''));
        $catId = (int) ($row['catid'] ?? 0);

        if ($contentId <= 0 || $tagId <= 0) {
            continue;
        }

        $topMeta = topCategoryMeta($mysqli, $tCats, $catId);
        $topTitle = $topMeta['title'];
        $topSlug = $topMeta['slug'] ?: ('cat-' . (int) $topMeta['id']);
        $topPath = $rootPath . '/' . $topSlug . '-' . (int) $topMeta['id'];
        $topExisting = findNodeByPath($mysqli, $tNodes, $topPath);
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
        if ($topExisting === null) {
            $stats['nodes_top_new']++;
        }

        if ($contentTitle === '') {
            $contentTitle = 'Контент #' . $contentId;
        }
        $contentSlug = slugify_ctx($contentTitle);
        $contentPath = $topPath . '/' . $contentSlug . '-' . $contentId;
        $contentExisting = findNodeByPath($mysqli, $tNodes, $contentPath);
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
        if ($contentExisting === null) {
            $stats['nodes_content_new']++;
        }

        if ($tagTitle === '') {
            $tagTitle = 'Тег #' . $tagId;
        }
        $leafSlug = slugify_ctx($tagTitle);
        $leafPath = $contentPath . '/' . $leafSlug . '-tag' . $tagId . '-c' . $contentId;
        $leafExisting = findNodeByPath($mysqli, $tNodes, $leafPath);
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
        if ($leafExisting === null) {
            $stats['nodes_leaf_new']++;
        }

        $stmt = $mysqli->prepare(
            "INSERT INTO `{$tCtxMap}` (`context_source`,`context_id`,`legacy_source`,`legacy_id`,`service_node_id`,`confidence`,`note`)
             VALUES ('content', ?, 'tag', ?, ?, 0.950, 'backfill from residual legacy/tag rows')
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

    echo "Residual context backfill завершён.\n";
    foreach ($stats as $k => $v) {
        echo " - {$k}: {$v}\n";
    }
    exit(0);
} catch (Throwable $e) {
    $mysqli->rollback();
    fwrite(STDERR, "Ошибка residual context backfill: " . $e->getMessage() . "\n");
    exit(1);
}

