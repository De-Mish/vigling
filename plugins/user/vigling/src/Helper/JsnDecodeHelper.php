<?php

namespace Joomla\Plugin\User\Vigling\Helper;

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\Database\DatabaseInterface;

final class JsnDecodeHelper
{
    private const ENCODED_FIELDS = ['prices', 'stock_prices', 'work_day', 'vyberite_spetsialnos'];
    private const EXPANDED_CAT_IDS_CACHE_TTL = 3600;
    private const SERVICE_HIERARCHY_CACHE_TTL = 3600;

    /** @var array<string, array{title: string}>|null */
    private static $categoriesFromDb;

    /** @var array<string, string>|null */
    private static $servicesFromDb;

    /** @var array<string, string>|null */
    private static $serviceCategoryTitlesFromDb;

    /** @var array<int, array{title:string,catid:int}> */
    private static $contentById = [];

    /** @var array<int, string> */
    private static $topCategoryByContentId = [];

    /** @var array<int, array{id:int,title:string}> */
    private static $topCategoryInfoByContentId = [];
    /** @var array<int, array{id:int,title:string,parent_id:int,path:string}> */
    private static $categoryById = [];
    /** @var array<int, array{id:int,title:string}> */
    private static $topCategoryInfoByCategoryId = [];
    /** @var array<string, array{content_id:int,content_title:string}> */
    private static $contentByTopCategoryAndTag = [];
    /** @var array<string, array<int, array{id:int,title:string,tags:array<int,array{id:int,title:string}>}>> */
    private static $filterHierarchyCache = [];
    /** @var array<string, int[]> */
    private static $expandedCatIdsCache = [];

    /**
     * Clear all cached filter hierarchies and expanded category IDs
     */
    public static function clearFilterCaches(): void
    {
        self::$filterHierarchyCache = [];
        self::$expandedCatIdsCache = [];

        $cacheDir = \defined('JPATH_CACHE') ? JPATH_CACHE . '/vigling-public-filters' : '';
        if ($cacheDir === '' || !is_dir($cacheDir)) {
            return;
        }

        try {
            $files = @glob($cacheDir . '/expanded_cat_ids_*.json');
            if ($files !== false) {
                foreach ($files as $file) {
                    @unlink($file);
                }
            }
            $files = @glob($cacheDir . '/hierarchy_*.json');
            if ($files !== false) {
                foreach ($files as $file) {
                    @unlink($file);
                }
            }
        } catch (\Throwable $e) {
            // Silently fail if we can't delete cache files
        }
    }

    /**
     * @return array<int, array<int, array{id:int,title:string,tags:array<int,array{id:int,title:string}>}>>
     */
    public static function getFilterServiceHierarchy(array $allowedCategoryIds = []): array
    {
        $allowedCategoryIds = array_values(array_unique(array_filter(array_map('intval', $allowedCategoryIds), static fn(int $id): bool => $id > 0)));
        sort($allowedCategoryIds);
        $cacheKey = implode(',', $allowedCategoryIds);

        if (isset(self::$filterHierarchyCache[$cacheKey])) {
            return self::$filterHierarchyCache[$cacheKey];
        }

        // Check file-based cache
        $cacheDir = \defined('JPATH_CACHE') ? JPATH_CACHE . '/vigling-public-filters' : '';
        $cacheFile = $cacheDir !== '' ? $cacheDir . '/hierarchy_' . md5($cacheKey) . '.json' : '';
        if ($cacheFile !== '' && is_file($cacheFile) && (time() - (int) filemtime($cacheFile)) < self::SERVICE_HIERARCHY_CACHE_TTL) {
            $cached = json_decode((string) file_get_contents($cacheFile), true);
            if (is_array($cached)) {
                return self::$filterHierarchyCache[$cacheKey] = $cached;
            }
        }

        $rows = array_merge(
            self::loadFilterRowsFromTable('#__vigling_user_services', $allowedCategoryIds),
            self::loadFilterRowsFromTable('#__vigling_user_stock_services', $allowedCategoryIds)
        );

        $hierarchy = [];

        foreach ($rows as $row) {
            $display = self::resolveNewModelDisplayPath($row);
            $catId = (int) ($display['payload_cat_id'] ?? 0);
            if ($catId <= 0) {
                continue;
            }
            if ($allowedCategoryIds !== [] && !in_array($catId, $allowedCategoryIds, true)) {
                continue;
            }

            $tagId = (int) ($row['legacy_tag_id'] ?? 0);
            $serviceId = (int) ($display['payload_service_id'] ?? 0);
            if ($serviceId <= 0) {
                continue;
            }

            $serviceTitle = '';
            $content = self::getContentInfoById($serviceId);
            if ($content !== null) {
                $serviceTitle = trim((string) ($content['title'] ?? ''));
            }
            if ($serviceTitle === '') {
                $serviceTitle = trim((string) (self::getServicesFromDb()[(string) $serviceId] ?? ''));
            }
            if ($serviceTitle === '') {
                $serviceTitle = trim((string) ($display['service_name'] ?? ''));
            }
            if ($serviceTitle === '') {
                continue;
            }

            if (!isset($hierarchy[$catId][$serviceId])) {
                $hierarchy[$catId][$serviceId] = [
                    'id' => $serviceId,
                    'title' => $serviceTitle,
                    'tags' => [],
                ];
            }

            if ($tagId > 0) {
                $tagTitle = trim((string) (self::getServicesFromDb()[(string) $tagId] ?? ''));
                if ($tagTitle === '') {
                    $tagTitle = trim((string) ($row['service_title'] ?? ''));
                }
                if ($tagTitle !== '') {
                    $hierarchy[$catId][$serviceId]['tags'][$tagId] = [
                        'id' => $tagId,
                        'title' => $tagTitle,
                    ];
                }
            }
        }

        foreach ($hierarchy as $catId => $services) {
            uasort($services, static function (array $a, array $b): int {
                return strnatcasecmp((string) ($a['title'] ?? ''), (string) ($b['title'] ?? ''));
            });
            foreach ($services as $serviceId => $service) {
                $tags = (array) ($service['tags'] ?? []);
                uasort($tags, static function (array $a, array $b): int {
                    return strnatcasecmp((string) ($a['title'] ?? ''), (string) ($b['title'] ?? ''));
                });
                $services[$serviceId]['tags'] = array_values($tags);
            }
            $hierarchy[$catId] = array_values($services);
        }

        // Cache to file
        if ($cacheFile !== '') {
            if (!is_dir($cacheDir)) {
                @mkdir($cacheDir, 0775, true);
            }
            if (is_dir($cacheDir) && is_writable($cacheDir)) {
                @file_put_contents($cacheFile, json_encode($hierarchy, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            }
        }

        return self::$filterHierarchyCache[$cacheKey] = $hierarchy;
    }

    private static function getServicesFromDb(): array
    {
        if (self::$servicesFromDb !== null) {
            return self::$servicesFromDb;
        }
        $rows = [];
        try {
            $db = Factory::getContainer()->get(DatabaseInterface::class);
            // Primary source: service lookup table built during migration.
            try {
                $query = $db->getQuery(true)
                    ->select($db->quoteName('id') . ', ' . $db->quoteName('title'))
                    ->from($db->quoteName('#__vigling_services'))
                    ->order($db->quoteName('id') . ' ASC');
                $db->setQuery($query);
                $rows = $db->loadAssocList() ?: [];
            } catch (\Throwable $e) {
                // Legacy lookup table may be removed after cutover cleanup.
            }

            // Merge with content IDs to restore names missing from vigling lookup table.
            $query = $db->getQuery(true)
                ->select($db->quoteName('c.id') . ', ' . $db->quoteName('c.title'))
                ->from($db->quoteName('#__content', 'c'))
                ->where($db->quoteName('c.state') . ' IN (0,1)')
                ->order($db->quoteName('c.id') . ' ASC');
            $db->setQuery($query);
            $contentRows = $db->loadAssocList() ?: [];
            if ($contentRows !== []) {
                $rows = array_merge($rows, $contentRows);
            }

            // Legacy JSN data may reference service IDs that exist only in tags.
            $query = $db->getQuery(true)
                ->select($db->quoteName('t.id') . ', ' . $db->quoteName('t.title'))
                ->from($db->quoteName('#__tags', 't'))
                ->where($db->quoteName('t.published') . ' IN (0,1)')
                ->order($db->quoteName('t.id') . ' ASC');
            $db->setQuery($query);
            $tagRows = $db->loadAssocList() ?: [];
            if ($tagRows !== []) {
                $rows = array_merge($rows, $tagRows);
            }
        } catch (\Throwable $e) {
            $rows = [];
        }
        self::$servicesFromDb = [];
        foreach ($rows as $r) {
            self::$servicesFromDb[(string) $r['id']] = $r['title'];
        }
        return self::$servicesFromDb;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function loadFilterRowsFromTable(string $userServicesTable, array $allowedCategoryIds = []): array
    {
        try {
            $db = Factory::getContainer()->get(DatabaseInterface::class);
            $query = $db->getQuery(true)
                ->select([
                    'DISTINCT ' . $db->quoteName('us.service_node_id'),
                    $db->quoteName('us.legacy_tag_id'),
                    $db->quoteName('us.legacy_cat_id'),
                    $db->quoteName('n.title', 'service_title'),
                    $db->quoteName('n.legacy_source', 'service_legacy_source'),
                    $db->quoteName('n.legacy_id', 'service_legacy_id'),
                    $db->quoteName('parent.title', 'category_title'),
                    $db->quoteName('parent.legacy_source', 'parent_legacy_source'),
                    $db->quoteName('parent.legacy_id', 'parent_legacy_id'),
                ])
                ->from($db->quoteName($userServicesTable, 'us'))
                ->join('INNER', $db->quoteName('#__vigling_service_nodes', 'n') . ' ON ' . $db->quoteName('us.service_node_id') . ' = ' . $db->quoteName('n.id'))
                ->join('LEFT', $db->quoteName('#__vigling_service_nodes', 'parent') . ' ON ' . $db->quoteName('n.parent_id') . ' = ' . $db->quoteName('parent.id'))
                ->where($db->quoteName('us.is_active') . ' = 1');

            if ($allowedCategoryIds !== []) {
                $expanded = self::expandToAllLegacyCatIds($allowedCategoryIds);
                $query->whereIn($db->quoteName('us.legacy_cat_id'), $expanded);
            }

            $db->setQuery($query);
            return $db->loadAssocList() ?: [];
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Expands a list of top-level category IDs to include:
     *  1. All descendant Joomla category IDs (via path LIKE matching).
     *  2. All com_content article IDs whose catid falls within those categories.
     *
     * This is necessary because legacy_cat_id in user_services can hold either a
     * Joomla category ID (rare, direct top-level match) or a com_content article ID
     * (common — the article sits in a subcategory of e.g. Маникюр). The SQL filter
     * must allow both forms so that resolveNewModelDisplayPath can do the final
     * normalisation to a top-level category.
     *
     * @param int[] $topIds
     * @return int[]
     */
    private static function expandToAllLegacyCatIds(array $topIds): array
    {
        if ($topIds === []) {
            return [];
        }

        $topIds = array_values(array_unique(array_filter(array_map('intval', $topIds))));
        if ($topIds === []) {
            return [];
        }

        // Check per-request cache first
        $cacheKey = implode(',', $topIds);
        if (isset(self::$expandedCatIdsCache[$cacheKey])) {
            return self::$expandedCatIdsCache[$cacheKey];
        }

        try {
            $db = Factory::getContainer()->get(DatabaseInterface::class);
            $prefix = $db->getPrefix();

            // Check file-based cache for entire result set
            $cacheDir = \defined('JPATH_CACHE') ? JPATH_CACHE . '/vigling-public-filters' : '';
            $cacheFile = $cacheDir !== '' ? $cacheDir . '/expanded_cat_ids_' . $cacheKey . '.json' : '';
            if ($cacheFile !== '' && is_file($cacheFile) && (time() - (int) filemtime($cacheFile)) < self::EXPANDED_CAT_IDS_CACHE_TTL) {
                $cached = json_decode((string) file_get_contents($cacheFile), true);
                if (is_array($cached)) {
                    return self::$expandedCatIdsCache[$cacheKey] = array_values(array_map('intval', $cached));
                }
            }

            // Step 1 – expand category IDs to include all subcategories via path.
            $query = $db->getQuery(true)
                ->select([$db->quoteName('id'), $db->quoteName('path')])
                ->from($db->quoteName($prefix . 'categories'))
                ->whereIn($db->quoteName('id'), $topIds);
            $db->setQuery($query);
            $topRows = $db->loadAssocList() ?: [];

            $catConditions = [];
            foreach ($topRows as $row) {
                $id = (int) $row['id'];
                $path = trim((string) $row['path']);
                $catConditions[] = $db->quoteName('id') . ' = ' . $id;
                if ($path !== '') {
                    $catConditions[] = $db->quoteName('path') . ' LIKE ' . $db->quote($path . '/%');
                }
            }

            $allCatIds = $topIds;
            if ($catConditions !== []) {
                $query = $db->getQuery(true)
                    ->select($db->quoteName('id'))
                    ->from($db->quoteName($prefix . 'categories'))
                    ->where('(' . implode(' OR ', $catConditions) . ')');
                $db->setQuery($query);
                $allCatIds = array_values(array_unique(array_map('intval', $db->loadColumn() ?: [])));
                if ($allCatIds === []) {
                    $allCatIds = $topIds;
                }
            }

            // Step 2 – also include com_content article IDs in those categories,
            // because legacy_cat_id often stores the content article ID, not the
            // category ID directly.
            $query = $db->getQuery(true)
                ->select($db->quoteName('id'))
                ->from($db->quoteName($prefix . 'content'))
                ->whereIn($db->quoteName('catid'), $allCatIds)
                ->where($db->quoteName('state') . ' IN (0,1)');
            $db->setQuery($query);
            $contentIds = array_values(array_map('intval', $db->loadColumn() ?: []));

            $result = array_values(array_unique(array_merge($allCatIds, $contentIds)));

            // Cache to file
            if ($cacheFile !== '') {
                if (!is_dir($cacheDir)) {
                    @mkdir($cacheDir, 0775, true);
                }
                if (is_dir($cacheDir) && is_writable($cacheDir)) {
                    @file_put_contents($cacheFile, json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                }
            }

            return self::$expandedCatIdsCache[$cacheKey] = $result;
        } catch (\Throwable $e) {
            return $topIds;
        }
    }

    private static function getServiceCategoryTitlesFromDb(): array
    {
        if (self::$serviceCategoryTitlesFromDb !== null) {
            return self::$serviceCategoryTitlesFromDb;
        }
        try {
            $db = Factory::getContainer()->get(DatabaseInterface::class);
            $query = $db->getQuery(true)
                ->select($db->quoteName('c.id') . ', ' . $db->quoteName('cat.title', 'category_title'))
                ->from($db->quoteName('#__content', 'c'))
                ->join('INNER', $db->quoteName('#__categories', 'cat') . ' ON ' . $db->quoteName('c.catid') . ' = ' . $db->quoteName('cat.id'));
            $db->setQuery($query);
            $rows = $db->loadAssocList() ?: [];

            // For tag-backed services, derive "category" from parent tag title.
            $query = $db->getQuery(true)
                ->select($db->quoteName('t.id') . ', ' . $db->quoteName('parent.title', 'category_title'))
                ->from($db->quoteName('#__tags', 't'))
                ->join('LEFT', $db->quoteName('#__tags', 'parent') . ' ON ' . $db->quoteName('t.parent_id') . ' = ' . $db->quoteName('parent.id'))
                ->where($db->quoteName('t.published') . ' IN (0,1)');
            $db->setQuery($query);
            $tagRows = $db->loadAssocList() ?: [];
            if ($tagRows !== []) {
                $rows = array_merge($rows, $tagRows);
            }
        } catch (\Throwable $e) {
            $rows = [];
        }

        self::$serviceCategoryTitlesFromDb = [];
        foreach ($rows as $row) {
            $serviceId = (string) ($row['id'] ?? '');
            $categoryTitle = (string) ($row['category_title'] ?? '');
            if ($serviceId !== '' && $categoryTitle !== '') {
                self::$serviceCategoryTitlesFromDb[$serviceId] = $categoryTitle;
            }
        }

        return self::$serviceCategoryTitlesFromDb;
    }

    private static function resolveCategoryTitle(string $catId, array $items, array $categories, array $serviceCategoryTitles): string
    {
        if (isset($categories[$catId]['title']) && (string) $categories[$catId]['title'] !== '') {
            return (string) $categories[$catId]['title'];
        }

        $scores = [];
        foreach ($items as $triple) {
            if (!\is_array($triple) || !isset($triple[2])) {
                continue;
            }
            $svcId = (string) $triple[2];
            $lookupId = (strpos($svcId, '-') !== false) ? explode('-', $svcId, 2)[0] : $svcId;
            if (isset($serviceCategoryTitles[$lookupId]) && $serviceCategoryTitles[$lookupId] !== '') {
                $title = $serviceCategoryTitles[$lookupId];
                $scores[$title] = ($scores[$title] ?? 0) + 1;
            }
        }

        if ($scores !== []) {
            arsort($scores);
            return (string) array_key_first($scores);
        }

        return 'Категория ' . $catId;
    }

    private static function getCategoriesFromDb(): array
    {
        if (self::$categoriesFromDb !== null) {
            return self::$categoriesFromDb;
        }
        try {
            $db = Factory::getContainer()->get(DatabaseInterface::class);
            $prefix = $db->getPrefix();
            $query = $db->getQuery(true)
                ->select($db->quoteName('id') . ', ' . $db->quoteName('title'))
                ->from($db->quoteName($prefix . 'categories'))
                ->where($db->quoteName('extension') . ' = ' . $db->quote('com_content'))
                ->where($db->quoteName('published') . ' = 1')
                ->where('(' . $db->quoteName('parent_id') . ' = 39 OR ' . $db->quoteName('path') . ' LIKE ' . $db->quote('uslugi/%') . ' OR ' . $db->quoteName('id') . ' IN (9,10,11,12,13,14,16,17,18,19,20,21))')
                ->order($db->quoteName('title') . ' ASC');
            $db->setQuery($query);
            $rows = $db->loadAssocList() ?: [];

            $query = $db->getQuery(true)
                ->select($db->quoteName('t.id') . ', ' . $db->quoteName('t.title'))
                ->from($db->quoteName('#__tags', 't'))
                ->where($db->quoteName('t.published') . ' IN (0,1)')
                ->order($db->quoteName('t.title') . ' ASC');
            $db->setQuery($query);
            $tagRows = $db->loadAssocList() ?: [];
            if ($tagRows !== []) {
                $seenIds = [];
                foreach ($rows as $row) {
                    if (isset($row['id'])) {
                        $seenIds[(string) $row['id']] = true;
                    }
                }
                foreach ($tagRows as $tagRow) {
                    $tagId = (string) ($tagRow['id'] ?? '');
                    if ($tagId !== '' && !isset($seenIds[$tagId])) {
                        $rows[] = $tagRow;
                    }
                }
            }
        } catch (\Throwable $e) {
            $rows = [];
        }
        self::$categoriesFromDb = [];
        foreach ($rows as $r) {
            self::$categoriesFromDb[(string) $r['id']] = ['title' => $r['title']];
        }
        return self::$categoriesFromDb;
    }

    /**
     * @return array<int, array{cat_id: string, title: string, items: array<int, array{name: string, price: int, duration: int, svc_id: string, tag_id: int, pause_min: int, stock_service_id?: int, old_price?: int, about_stock?: string, count_stock?: int}>}>
     */
    public static function getUserServicesStructuredWithIds(int $userId): array
    {
        return self::getUserServicesStructuredWithIdsFromTable($userId, '#__vigling_user_services');
    }

    /**
     * @return array<int, array{cat_id: string, title: string, items: array<int, array{name: string, price: int, duration: int, svc_id: string, tag_id: int, pause_min: int, stock_service_id?: int, old_price?: int, about_stock?: string, count_stock?: int}>}>
     */
    public static function getUserStockServicesStructuredWithIds(int $userId): array
    {
        return self::getUserServicesStructuredWithIdsFromTable($userId, '#__vigling_user_stock_services');
    }

    /**
     * @return array<int, array{cat_id: string, title: string, items: array<int, array{name: string, price: int, duration: int, svc_id: string, tag_id: int, pause_min: int, stock_service_id?: int, old_price?: int, about_stock?: string, count_stock?: int}>}>
     */
    private static function getUserServicesStructuredWithIdsFromTable(int $userId, string $userServicesTable): array
    {
        if ($userId <= 0) {
            return [];
        }

        try {
            $db = Factory::getContainer()->get(DatabaseInterface::class);
            $isStockTable = $userServicesTable === '#__vigling_user_stock_services';
            $select = [
                $db->quoteName('us.id', 'user_service_id'),
                $db->quoteName('us.service_node_id'),
                $db->quoteName('us.price'),
                $db->quoteName('us.duration_min'),
                $db->quoteName('us.legacy_tag_id'),
                $db->quoteName('us.pause_min'),
                $db->quoteName('us.legacy_cat_id'),
                $db->quoteName('n.title', 'service_title'),
                $db->quoteName('n.legacy_source', 'service_legacy_source'),
                $db->quoteName('n.legacy_id', 'service_legacy_id'),
                $db->quoteName('n.parent_id'),
                $db->quoteName('n.sort_order', 'node_sort'),
                $db->quoteName('parent.title', 'category_title'),
                $db->quoteName('parent.legacy_source', 'parent_legacy_source'),
                $db->quoteName('parent.legacy_id', 'parent_legacy_id'),
                $db->quoteName('parent.sort_order', 'parent_sort'),
            ];
            if ($isStockTable) {
                $select[] = $db->quoteName('us.old_price');
                $select[] = $db->quoteName('us.about_stock');
                $select[] = $db->quoteName('us.count_stock');
            } else {
                $select[] = '0 AS ' . $db->quoteName('old_price');
                $select[] = "'' AS " . $db->quoteName('about_stock');
                $select[] = '0 AS ' . $db->quoteName('count_stock');
            }
            $query = $db->getQuery(true)
                ->select($select)
                ->from($db->quoteName($userServicesTable, 'us'))
                ->join('INNER', $db->quoteName('#__vigling_service_nodes', 'n') . ' ON ' . $db->quoteName('us.service_node_id') . ' = ' . $db->quoteName('n.id'))
                ->join('LEFT', $db->quoteName('#__vigling_service_nodes', 'parent') . ' ON ' . $db->quoteName('n.parent_id') . ' = ' . $db->quoteName('parent.id'))
                ->where($db->quoteName('us.user_id') . ' = ' . (int) $userId)
                ->where($db->quoteName('us.is_active') . ' = 1')
                ->order($db->quoteName('parent.sort_order') . ' ASC')
                ->order($db->quoteName('parent.title') . ' ASC')
                ->order($db->quoteName('n.sort_order') . ' ASC')
                ->order($db->quoteName('n.title') . ' ASC');
            $db->setQuery($query);
            $rows = $db->loadAssocList() ?: [];
        } catch (\Throwable $e) {
            return [];
        }

        if ($rows === []) {
            return [];
        }

        $grouped = [];
        foreach ($rows as $row) {
            $display = self::resolveNewModelDisplayPath($row);
            $payloadCatId = $display['payload_cat_id'] ?? '';
            $catId = $payloadCatId !== '' ? $payloadCatId : (isset($row['parent_id']) && (int) $row['parent_id'] > 0 ? (string) (int) $row['parent_id'] : '0');
            $catTitle = trim((string) ($display['category_title'] ?? ''));
            if ($catTitle === '') {
                $catTitle = trim((string) ($row['category_title'] ?? ''));
            }
            if ($catTitle === '') {
                $catTitle = 'Услуги';
            }

            if (!isset($grouped[$catId])) {
                $grouped[$catId] = [
                    'cat_id' => $catId,
                    'title' => $catTitle,
                    'items' => [],
                ];
            }

            $legacyId = isset($row['service_legacy_id']) ? (int) $row['service_legacy_id'] : 0;
            $payloadServiceId = trim((string) ($display['payload_service_id'] ?? ''));
            $svcId = $payloadServiceId !== '' ? $payloadServiceId : ($legacyId > 0 ? (string) $legacyId : (string) ((int) $row['service_node_id']));
            $name = trim((string) ($display['service_name'] ?? ''));
            if ($name === '') {
                $name = trim((string) ($row['service_title'] ?? ''));
            }
            if ($name === '') {
                $name = 'Услуга #' . $svcId;
            }

            $item = [
                'name' => $name,
                'price' => (int) round((float) ($row['price'] ?? 0)),
                'duration' => (int) ($row['duration_min'] ?? 0),
                'svc_id' => $svcId,
                'tag_id' => (int) ($row['legacy_tag_id'] ?? 0),
                'legacy_cat_id' => (int) ($row['legacy_cat_id'] ?? 0),
                'pause_min' => (int) ($row['pause_min'] ?? 0),
            ];

            if ($userServicesTable === '#__vigling_user_stock_services') {
                $item['stock_service_id'] = (int) ($row['user_service_id'] ?? 0);
                $item['old_price'] = (int) round((float) ($row['old_price'] ?? 0));
                $item['about_stock'] = trim((string) ($row['about_stock'] ?? ''));
                $item['count_stock'] = (int) ($row['count_stock'] ?? 0);
            }

            $grouped[$catId]['items'][] = $item;
        }

        return array_values($grouped);
    }

    /**
     * @param array<string,mixed> $row
     * @return array{payload_cat_id:string, payload_service_id:string, category_title:string, service_name:string}
     */
    private static function resolveNewModelDisplayPath(array $row): array
    {
        $result = [
            'payload_cat_id' => '',
            'payload_service_id' => '',
            'category_title' => '',
            'service_name' => '',
        ];

        $legacySource = (string) ($row['service_legacy_source'] ?? '');
        $legacyId = (int) ($row['service_legacy_id'] ?? 0);
        $serviceTitle = trim((string) ($row['service_title'] ?? ''));
        $fallbackCategoryTitle = trim((string) ($row['category_title'] ?? ''));
        $parentLegacySource = (string) ($row['parent_legacy_source'] ?? '');
        $parentLegacyId = (int) ($row['parent_legacy_id'] ?? 0);
        $tagId = (int) ($row['legacy_tag_id'] ?? 0);
        $isTagBased = (strpos($legacySource, 'tag') !== false);

        $payloadCatId = (string) ((int) ($row['legacy_cat_id'] ?? 0));
        if ($payloadCatId === '0') {
            $payloadCatId = '';
        }

        if ($payloadCatId === '') {
            return [
                'payload_cat_id' => '',
                'payload_service_id' => $legacyId > 0 ? (string) $legacyId : '',
                'category_title' => $fallbackCategoryTitle,
                'service_name' => $serviceTitle,
            ];
        }
        $result['payload_cat_id'] = $payloadCatId;

        if (!preg_match('/^\d+$/', $payloadCatId)) {
            $result['payload_service_id'] = $legacyId > 0 ? (string) $legacyId : '';
            $result['category_title'] = $fallbackCategoryTitle;
            $result['service_name'] = $serviceTitle;
            return $result;
        }

        $payloadId = (int) $payloadCatId;
        $category = self::getCategoryInfoById($payloadId);
        if ($category !== null) {
            $topCategoryInfo = self::getTopCategoryInfoForCategoryId($payloadId);
            if (!empty($topCategoryInfo['id'])) {
                $result['payload_cat_id'] = (string) (int) $topCategoryInfo['id'];
            }
            $result['category_title'] = trim((string) ($topCategoryInfo['title'] ?? ''));
            if ($result['category_title'] === '') {
                $result['category_title'] = trim((string) ($category['title'] ?? ''));
            }
            if ($result['category_title'] === '') {
                $result['category_title'] = $fallbackCategoryTitle;
            }
            if ($isTagBased && $parentLegacySource === 'content' && $parentLegacyId > 0) {
                $result['payload_service_id'] = (string) $parentLegacyId;
                $parentContent = self::getContentInfoById($parentLegacyId);
                $parentTitle = trim((string) ($parentContent['title'] ?? ''));
                $tagTitle = trim((string) (self::getServicesFromDb()[(string) $legacyId] ?? ''));
                if ($tagTitle === '') {
                    $tagTitle = $serviceTitle;
                }
                if ($parentTitle !== '' && $tagTitle !== '') {
                    $result['service_name'] = $parentTitle . ' / ' . $tagTitle;
                } elseif ($tagTitle !== '') {
                    $result['service_name'] = $tagTitle;
                } elseif ($parentTitle !== '') {
                    $result['service_name'] = $parentTitle;
                }
                return $result;
            }
            if ($isTagBased && $tagId > 0) {
                $resolvedContent = self::findContentByTopCategoryAndTag((int) $result['payload_cat_id'], $tagId);
                if ($resolvedContent !== null) {
                    $result['payload_service_id'] = (string) (int) $resolvedContent['content_id'];
                    $baseTitle = trim((string) ($resolvedContent['content_title'] ?? ''));
                    $tagTitle = trim((string) (self::getServicesFromDb()[(string) $legacyId] ?? ''));
                    if ($tagTitle === '') {
                        $tagTitle = $serviceTitle;
                    }
                    if ($baseTitle !== '' && $tagTitle !== '') {
                        $result['service_name'] = $baseTitle . ' / ' . $tagTitle;
                    } elseif ($tagTitle !== '') {
                        $result['service_name'] = $tagTitle;
                    } else {
                        $result['service_name'] = $baseTitle;
                    }
                    return $result;
                }
            }
            $result['payload_service_id'] = $legacyId > 0 ? (string) $legacyId : '';

            $resolvedServiceName = $serviceTitle;
            if ($resolvedServiceName === '' && $legacyId > 0) {
                $resolvedServiceName = trim((string) (self::getServicesFromDb()[(string) $legacyId] ?? ''));
            }
            if ($resolvedServiceName === '') {
                $resolvedServiceName = trim((string) ($row['service_title'] ?? ''));
            }
            if ($resolvedServiceName === '') {
                $resolvedServiceName = trim((string) ($row['category_title'] ?? ''));
            }
            $result['service_name'] = $resolvedServiceName;
            return $result;
        }

        $contentId = $payloadId;
        $content = self::getContentInfoById($contentId);
        if ($content === null) {
            $result['payload_service_id'] = $legacyId > 0 ? (string) $legacyId : '';
            $result['category_title'] = $fallbackCategoryTitle;
            $result['service_name'] = $serviceTitle;
            return $result;
        }

        $topCategoryInfo = self::getTopCategoryInfoForContentId($contentId);
        if (!empty($topCategoryInfo['id'])) {
            $result['payload_cat_id'] = (string) (int) $topCategoryInfo['id'];
        }
        $result['payload_service_id'] = (string) $contentId;
        $result['category_title'] = trim((string) ($topCategoryInfo['title'] ?? '')) ?: $fallbackCategoryTitle;

        $subgroupTitle = trim((string) ($content['title'] ?? ''));
        if ($subgroupTitle === '') {
            $subgroupTitle = trim((string) ($row['category_title'] ?? ''));
        }

        if ($isTagBased) {
            if ($parentLegacySource === 'content' && $parentLegacyId > 0) {
                $result['payload_service_id'] = (string) $parentLegacyId;
                $parentContent = self::getContentInfoById($parentLegacyId);
                if ($parentContent !== null) {
                    $subgroupTitle = trim((string) ($parentContent['title'] ?? '')) ?: $subgroupTitle;
                    $topCategoryInfo = self::getTopCategoryInfoForContentId($parentLegacyId);
                    if (!empty($topCategoryInfo['id'])) {
                        $result['payload_cat_id'] = (string) (int) $topCategoryInfo['id'];
                    }
                    $result['category_title'] = trim((string) ($topCategoryInfo['title'] ?? '')) ?: $result['category_title'];
                }
            }
        }

        if ($isTagBased && $tagId > 0 && $legacyId > 0) {
            $tagTitle = trim((string) (self::getServicesFromDb()[(string) $legacyId] ?? ''));
            if ($tagTitle === '') {
                $tagTitle = $serviceTitle;
            }
            if ($subgroupTitle !== '' && mb_strtolower($subgroupTitle) !== mb_strtolower($tagTitle)) {
                $result['service_name'] = $subgroupTitle . ' /' . $tagTitle;
            } else {
                $result['service_name'] = $tagTitle;
            }
            return $result;
        }

        // For `ctx_tag` rows with tag_id = 0 keep subgroup name as service item label.
        if ($subgroupTitle !== '') {
            $result['service_name'] = $subgroupTitle;
            return $result;
        }

        $result['service_name'] = $serviceTitle;
        return $result;
    }

    /**
     * @return array{title:string,catid:int}|null
     */
    private static function getContentInfoById(int $contentId): ?array
    {
        if ($contentId <= 0) {
            return null;
        }
        if (isset(self::$contentById[$contentId])) {
            return self::$contentById[$contentId];
        }

        try {
            $db = Factory::getContainer()->get(DatabaseInterface::class);
            $query = $db->getQuery(true)
                ->select([$db->quoteName('id'), $db->quoteName('title'), $db->quoteName('catid')])
                ->from($db->quoteName('#__content'))
                ->where($db->quoteName('id') . ' = ' . $contentId)
                ->where($db->quoteName('state') . ' IN (0,1)');
            $db->setQuery($query);
            $row = $db->loadAssoc();
        } catch (\Throwable $e) {
            $row = null;
        }

        if (!is_array($row)) {
            return null;
        }

        self::$contentById[$contentId] = [
            'title' => (string) ($row['title'] ?? ''),
            'catid' => (int) ($row['catid'] ?? 0),
        ];

        return self::$contentById[$contentId];
    }

    private static function getTopCategoryTitleForContentId(int $contentId): string
    {
        $info = self::getTopCategoryInfoForContentId($contentId);
        return (string) ($info['title'] ?? '');
    }

    /**
     * @return array{id:int,title:string,parent_id:int,path:string}|null
     */
    private static function getCategoryInfoById(int $categoryId): ?array
    {
        if ($categoryId <= 0) {
            return null;
        }
        if (array_key_exists($categoryId, self::$categoryById)) {
            return self::$categoryById[$categoryId];
        }

        try {
            $db = Factory::getContainer()->get(DatabaseInterface::class);
            $query = $db->getQuery(true)
                ->select([$db->quoteName('id'), $db->quoteName('title'), $db->quoteName('parent_id'), $db->quoteName('path')])
                ->from($db->quoteName('#__categories'))
                ->where($db->quoteName('id') . ' = ' . (int) $categoryId)
                ->where($db->quoteName('published') . ' IN (0,1)');
            $db->setQuery($query);
            $row = $db->loadAssoc();
        } catch (\Throwable $e) {
            $row = null;
        }

        if (!is_array($row)) {
            self::$categoryById[$categoryId] = null;
            return null;
        }

        self::$categoryById[$categoryId] = [
            'id' => (int) ($row['id'] ?? 0),
            'title' => (string) ($row['title'] ?? ''),
            'parent_id' => (int) ($row['parent_id'] ?? 0),
            'path' => (string) ($row['path'] ?? ''),
        ];
        return self::$categoryById[$categoryId];
    }

    /**
     * @return array{id:int,title:string}
     */
    private static function getTopCategoryInfoForCategoryId(int $categoryId): array
    {
        if ($categoryId <= 0) {
            return ['id' => 0, 'title' => ''];
        }
        if (isset(self::$topCategoryInfoByCategoryId[$categoryId])) {
            return self::$topCategoryInfoByCategoryId[$categoryId];
        }

        $cat = self::getCategoryInfoById($categoryId);
        if ($cat === null) {
            self::$topCategoryInfoByCategoryId[$categoryId] = ['id' => 0, 'title' => ''];
            return self::$topCategoryInfoByCategoryId[$categoryId];
        }

        $result = [
            'id' => (int) ($cat['id'] ?? 0),
            'title' => (string) ($cat['title'] ?? ''),
        ];
        $parentId = (int) ($cat['parent_id'] ?? 0);
        $path = (string) ($cat['path'] ?? '');

        if ($parentId > 0 && $parentId !== 1) {
            $parent = self::getCategoryInfoById($parentId);
            $parentTitle = $parent !== null ? (string) ($parent['title'] ?? '') : '';
            $parentPath = $parent !== null ? (string) ($parent['path'] ?? '') : '';

            $isDirectServiceBranch = str_starts_with($path, 'uslugi/') && ($parentPath === 'uslugi' || $parentTitle === 'Услуги');
            $isDeepServiceBranch = str_starts_with($path, 'uslugi/') && !$isDirectServiceBranch;
            if ($parentTitle !== '' && $isDeepServiceBranch) {
                $result = [
                    'id' => (int) ($parent['id'] ?? 0),
                    'title' => $parentTitle,
                ];
            }
        }

        self::$topCategoryInfoByCategoryId[$categoryId] = $result;
        return $result;
    }

    /**
     * @return array{id:int,title:string}
     */
    private static function getTopCategoryInfoForContentId(int $contentId): array
    {
        if ($contentId <= 0) {
            return ['id' => 0, 'title' => ''];
        }
        if (isset(self::$topCategoryInfoByContentId[$contentId])) {
            return self::$topCategoryInfoByContentId[$contentId];
        }

        $content = self::getContentInfoById($contentId);
        if ($content === null || (int) $content['catid'] <= 0) {
            self::$topCategoryInfoByContentId[$contentId] = ['id' => 0, 'title' => ''];
            return self::$topCategoryInfoByContentId[$contentId];
        }

        $result = ['id' => (int) $content['catid'], 'title' => ''];
        try {
            $db = Factory::getContainer()->get(DatabaseInterface::class);
            $query = $db->getQuery(true)
                ->select([$db->quoteName('id'), $db->quoteName('title'), $db->quoteName('parent_id'), $db->quoteName('path')])
                ->from($db->quoteName('#__categories'))
                ->where($db->quoteName('id') . ' = ' . (int) $content['catid']);
            $db->setQuery($query);
            $cat = $db->loadAssoc();

            if (!is_array($cat)) {
                self::$topCategoryInfoByContentId[$contentId] = ['id' => 0, 'title' => ''];
                return self::$topCategoryInfoByContentId[$contentId];
            }

            $result = [
                'id' => (int) ($cat['id'] ?? 0),
                'title' => (string) ($cat['title'] ?? ''),
            ];
            $parentId = (int) ($cat['parent_id'] ?? 0);
            $path = (string) ($cat['path'] ?? '');

            if ($parentId > 0 && $parentId !== 1) {
                $query = $db->getQuery(true)
                    ->select([$db->quoteName('id'), $db->quoteName('title'), $db->quoteName('path')])
                    ->from($db->quoteName('#__categories'))
                    ->where($db->quoteName('id') . ' = ' . $parentId);
                $db->setQuery($query);
                $parent = $db->loadAssoc();
                $parentTitle = is_array($parent) ? (string) ($parent['title'] ?? '') : '';
                $parentPath = is_array($parent) ? (string) ($parent['path'] ?? '') : '';

                $isDirectServiceBranch = str_starts_with($path, 'uslugi/') && ($parentPath === 'uslugi' || $parentTitle === 'Услуги');
                $isDeepServiceBranch = str_starts_with($path, 'uslugi/') && !$isDirectServiceBranch;
                // Important: `zatochka-remont/*` keeps its own section title
                // (e.g. "Заточка инструмента", "Ремонт оборудования"), not the root parent.
                if ($parentTitle !== '' && $isDeepServiceBranch) {
                    $result = [
                        'id' => (int) ($parent['id'] ?? 0),
                        'title' => $parentTitle,
                    ];
                }
            }
        } catch (\Throwable $e) {
            $result = ['id' => 0, 'title' => ''];
        }

        self::$topCategoryInfoByContentId[$contentId] = $result;
        self::$topCategoryByContentId[$contentId] = (string) $result['title'];
        return $result;
    }

    /**
     * @return array{content_id:int,content_title:string}|null
     */
    private static function findContentByTopCategoryAndTag(int $topCategoryId, int $tagId): ?array
    {
        if ($topCategoryId <= 0 || $tagId <= 0) {
            return null;
        }

        $cacheKey = $topCategoryId . ':' . $tagId;
        if (array_key_exists($cacheKey, self::$contentByTopCategoryAndTag)) {
            $cached = self::$contentByTopCategoryAndTag[$cacheKey];
            return $cached['content_id'] > 0 ? $cached : null;
        }

        try {
            $db = Factory::getContainer()->get(DatabaseInterface::class);
            $query = $db->getQuery(true)
                ->select([
                    $db->quoteName('c.id', 'content_id'),
                    $db->quoteName('c.title', 'content_title'),
                ])
                ->from($db->quoteName('#__contentitem_tag_map', 'm'))
                ->join('INNER', $db->quoteName('#__content', 'c') . ' ON ' . $db->quoteName('c.id') . ' = ' . $db->quoteName('m.content_item_id'))
                ->where($db->quoteName('m.type_alias') . ' = ' . $db->quote('com_content.article'))
                ->where($db->quoteName('m.tag_id') . ' = ' . (int) $tagId)
                ->where($db->quoteName('c.state') . ' IN (0,1)')
                ->order($db->quoteName('c.id') . ' ASC');
            $db->setQuery($query);
            $rows = $db->loadAssocList() ?: [];
        } catch (\Throwable $e) {
            $rows = [];
        }

        foreach ($rows as $row) {
            $contentId = (int) ($row['content_id'] ?? 0);
            if ($contentId <= 0) {
                continue;
            }
            $topInfo = self::getTopCategoryInfoForContentId($contentId);
            if ((int) ($topInfo['id'] ?? 0) !== $topCategoryId) {
                continue;
            }
            self::$contentByTopCategoryAndTag[$cacheKey] = [
                'content_id' => $contentId,
                'content_title' => trim((string) ($row['content_title'] ?? '')),
            ];
            return self::$contentByTopCategoryAndTag[$cacheKey];
        }

        self::$contentByTopCategoryAndTag[$cacheKey] = ['content_id' => 0, 'content_title' => ''];
        return null;
    }

    /**
     * @return array<int, array{title: string, items: array<int, string>}>
     */
    public static function getUserServicesStructured(int $userId): array
    {
        return self::getUserServicesStructuredFromTable($userId, '#__vigling_user_services');
    }

    /**
     * @return array<int, array{title: string, items: array<int, string>}>
     */
    public static function getUserStockServicesStructured(int $userId): array
    {
        return self::getUserServicesStructuredFromTable($userId, '#__vigling_user_stock_services');
    }

    /**
     * @return array<int, array{title: string, items: array<int, string>}>
     */
    private static function getUserServicesStructuredFromTable(int $userId, string $userServicesTable): array
    {
        $withIds = self::getUserServicesStructuredWithIdsFromTable($userId, $userServicesTable);
        if ($withIds === []) {
            return [];
        }

        $result = [];
        foreach ($withIds as $category) {
            $title = (string) ($category['title'] ?? 'Услуги');
            $items = [];
            foreach (($category['items'] ?? []) as $item) {
                $name = trim((string) ($item['name'] ?? ''));
                if ($name === '') {
                    $name = 'Услуга';
                }
                $price = (int) ($item['price'] ?? 0);
                $duration = (int) ($item['duration'] ?? 0);
                $dur = $duration > 0 ? ' · ' . $duration . ' мин' : '';
                $items[] = $name . ' — ' . $price . ' руб' . $dur;
            }
            if ($items !== []) {
                $result[] = [
                    'title' => $title,
                    'items' => $items,
                ];
            }
        }

        return $result;
    }

    public static function decodeFieldValue(string $fieldName, string $value): ?string
    {
        $value = trim($value);
        if ($value === '' || !\in_array($fieldName, self::ENCODED_FIELDS, true)) {
            return null;
        }
        // prices/stock_prices are rendered from normalized tables in JsndecodeField.
        // Legacy JSON parsing stays available via dedicated diagnostic methods.
        if ($fieldName === 'work_day') {
            return self::formatWorkDayValue($value);
        }
        if ($fieldName === 'vyberite_spetsialnos') {
            return self::formatVyberiteSpetsialnosValue($value);
        }
        return null;
    }

    public static function getEncodedFieldNames(): array
    {
        return self::ENCODED_FIELDS;
    }

    /**
     * @return array<int, array{title: string, items: array<int, string>}>
     */
    public static function getPricesStructured(string $raw): array
    {
        $raw = trim($raw);
        $normalized = preg_replace('/(\d+)\s*:/', '"$1":', $raw);
        if ($normalized === null) {
            return [];
        }
        $normalized = preg_replace('/,\s*([}\]])/', '$1', $normalized);
        $data = json_decode($normalized, true);
        if (!\is_array($data)) {
            return [];
        }
        $svc = self::getServicesFromDb();
        $cats = self::getCategoriesFromDb();
        $svcCats = self::getServiceCategoryTitlesFromDb();
        $result = [];
        foreach ($data as $catId => $items) {
            if (!\is_array($items)) {
                continue;
            }
            $catTitle = self::resolveCategoryTitle((string) $catId, $items, $cats, $svcCats);
            $parts = [];
            foreach ($items as $triple) {
                if (!\is_array($triple) || count($triple) < 3) {
                    continue;
                }
                $price = (int) $triple[0];
                $duration = (int) $triple[1];
                $svcId = (string) $triple[2];
                $lookupId = (strpos($svcId, '-') !== false) ? explode('-', $svcId)[0] : $svcId;
                $svcName = $svc[$lookupId] ?? $svc[$svcId] ?? ('Услуга #' . $lookupId);
                if ($lookupId === '0' || $lookupId === '') {
                    $catIdStr = (string) $catId;
                    $svcName = $svc[$catIdStr] ?? ($cats[$catIdStr]['title'] ?? $svcName);
                }
                $dur = $duration > 0 ? ' · ' . $duration . ' мин' : '';
                $parts[] = $svcName . ' — ' . $price . ' руб' . $dur;
            }
            if ($parts !== []) {
                $result[] = ['title' => $catTitle, 'items' => $parts];
            }
        }
        return $result;
    }

    /**
     * @return array<int, array{cat_id: string, title: string, items: array<int, array{name: string, price: int, duration: int, svc_id: string, tag_id: int}>}>
     */
    public static function getPricesStructuredWithIds(string $raw): array
    {
        $raw = trim($raw);
        $normalized = preg_replace('/(\d+)\s*:/', '"$1":', $raw);
        if ($normalized === null) {
            return [];
        }
        $normalized = preg_replace('/,\s*([}\]])/', '$1', $normalized);
        $data = json_decode($normalized, true);
        if (!\is_array($data)) {
            return [];
        }
        $svc = self::getServicesFromDb();
        $cats = self::getCategoriesFromDb();
        $svcCats = self::getServiceCategoryTitlesFromDb();
        $result = [];
        foreach ($data as $catId => $items) {
            if (!\is_array($items)) {
                continue;
            }
            $catTitle = self::resolveCategoryTitle((string) $catId, $items, $cats, $svcCats);
            $parts = [];
            foreach ($items as $triple) {
                if (!\is_array($triple) || count($triple) < 3) {
                    continue;
                }
                $price = (int) $triple[0];
                $duration = (int) $triple[1];
                $svcId = (string) $triple[2];
                $tagId = 0;
                $lookupId = $svcId;
                if (strpos($svcId, '-') !== false) {
                    $bits = explode('-', $svcId, 2);
                    $lookupId = $bits[0];
                    $tagId = (int) ($bits[1] ?? 0);
                }
                $svcName = $svc[$lookupId] ?? $svc[$svcId] ?? ('Услуга #' . $lookupId);
                if ($lookupId === '0' || $lookupId === '') {
                    $catIdStr = (string) $catId;
                    $svcName = $svc[$catIdStr] ?? ($cats[$catIdStr]['title'] ?? $svcName);
                    $lookupId = $catIdStr;
                }
                $parts[] = [
                    'name' => $svcName,
                    'price' => $price,
                    'duration' => $duration,
                    'svc_id' => $lookupId,
                    'tag_id' => $tagId,
                ];
            }
            if ($parts !== []) {
                $result[] = ['cat_id' => (string) $catId, 'title' => $catTitle, 'items' => $parts];
            }
        }
        return $result;
    }

    private static function formatPricesValue(string $raw): string
    {
        $raw = trim($raw);
        $normalized = preg_replace('/(\d+)\s*:/', '"$1":', $raw);
        if ($normalized === null) {
            return $raw;
        }
        $normalized = preg_replace('/,\s*([}\]])/', '$1', $normalized);
        $data = json_decode($normalized, true);
        if (!\is_array($data)) {
            return $raw;
        }
        $svc = self::getServicesFromDb();
        $cats = self::getCategoriesFromDb();
        $svcCats = self::getServiceCategoryTitlesFromDb();
        $lines = [];
        foreach ($data as $catId => $items) {
            if (!\is_array($items)) {
                continue;
            }
            $catTitle = self::resolveCategoryTitle((string) $catId, $items, $cats, $svcCats);
            $parts = [];
            foreach ($items as $triple) {
                if (!\is_array($triple) || count($triple) < 3) {
                    continue;
                }
                $price = (int) $triple[0];
                $duration = (int) $triple[1];
                $svcId = (string) $triple[2];
                $lookupId = (strpos($svcId, '-') !== false) ? explode('-', $svcId)[0] : $svcId;
                $svcName = $svc[$lookupId] ?? $svc[$svcId] ?? ('Услуга #' . $lookupId);
                $dur = $duration > 0 ? ', ' . $duration . ' мин' : '';
                $parts[] = $svcName . ' — ' . $price . ' руб' . $dur;
            }
            if ($parts !== []) {
                $lines[] = $catTitle . ': ' . implode('; ', $parts);
            }
        }
        return $lines === [] ? $raw : implode("\n", $lines);
    }

    private static function formatWorkDayValue(string $raw): string
    {
        $data = json_decode($raw, true);
        if (!\is_array($data)) {
            return $raw;
        }
        $dayNames = ['', 'Пн', 'Вт', 'Ср', 'Чт', 'Пт', 'Сб', 'Вс'];
        $names = [];
        foreach ($data as $d) {
            $idx = (int) $d;
            if ($idx >= 1 && $idx <= 7) {
                $names[] = $dayNames[$idx];
            }
        }
        return $names === [] ? $raw : implode(', ', $names);
    }

    private static function formatVyberiteSpetsialnosValue(string $raw): string
    {
        $data = json_decode($raw, true);
        if (!\is_array($data)) {
            return $raw;
        }
        $cats = self::getCategoriesFromDb();
        $parts = [];
        foreach ($data as $catId) {
            $ids = self::extractCategoryIds($catId);
            foreach ($ids as $id) {
                $title = isset($cats[$id]['title']) ? $cats[$id]['title'] : '#' . $id;
                $parts[] = $title;
            }
        }
        return $parts === [] ? $raw : implode(', ', $parts);
    }

    /**
     * @return array<int, string>
     */
    private static function extractCategoryIds($value): array
    {
        if (\is_scalar($value) || $value === null) {
            $id = trim((string) $value);
            return $id === '' ? [] : [$id];
        }

        if (!\is_array($value)) {
            return [];
        }

        $ids = [];
        foreach ($value as $nested) {
            foreach (self::extractCategoryIds($nested) as $id) {
                $ids[] = $id;
            }
        }

        return array_values(array_unique($ids));
    }
}
