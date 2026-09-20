<?php

namespace Joomla\Plugin\User\Vigling\Service;

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Log\Log;
use Joomla\Database\DatabaseInterface;

final class UserServicesService
{
    /** @var array<string, array<int|string, mixed>> */
    private static $cache = [];
    /** @var array<string, int>|null */
    private static $legacyServiceMap = null;
    /** @var array<string, int>|null */
    private static $legacyServiceContextMap = null;

    /**
     * Legacy-compatible grouped structure for template UI (`prices` field shape).
     *
     * @return array<int, array<int, array{0:mixed,1:mixed,2:int}>>
     */
    public static function getUserServicesLegacyShape(int $userId): array
    {
        return self::getUserServicesLegacyShapeFromTable($userId, '#__vigling_user_services', false);
    }

    /**
     * Legacy-compatible grouped structure for template UI (`stock_prices` field shape).
     *
     * @return array<int, array<int, array{0:mixed,1:mixed,2:int,3:mixed,4:mixed,5:mixed}>>
     */
    public static function getUserStockServicesLegacyShape(int $userId): array
    {
        return self::getUserServicesLegacyShapeFromTable($userId, '#__vigling_user_stock_services', true);
    }

    public static function ensureRecommendationColumn(?DatabaseInterface $db = null): bool
    {
        static $ensured = null;
        if ($ensured !== null) {
            return $ensured;
        }

        try {
            $db = $db ?? Factory::getContainer()->get(DatabaseInterface::class);
            $ok = true;
            foreach (['#__vigling_user_services', '#__vigling_user_stock_services'] as $table) {
                $columns = array_change_key_case($db->getTableColumns($table, false) ?: [], CASE_LOWER);
                if (isset($columns['recommendation'])) {
                    continue;
                }
                $db->setQuery(
                    'ALTER TABLE ' . $db->quoteName($table)
                    . ' ADD COLUMN ' . $db->quoteName('recommendation') . ' VARCHAR(150) NOT NULL DEFAULT ' . $db->quote('')
                )->execute();
            }
            $ensured = $ok;

            return $ensured;
        } catch (\Throwable $e) {
            $ensured = false;

            return false;
        }
    }

    public static function ensureStockArchiveSchema(?DatabaseInterface $db = null): bool
    {
        static $ensured = null;
        if ($ensured !== null) {
            return $ensured;
        }

        try {
            $db = $db ?? Factory::getContainer()->get(DatabaseInterface::class);
            $table = '#__vigling_user_stock_services';
            $columns = array_change_key_case($db->getTableColumns($table, false) ?: [], CASE_LOWER);
            if (!isset($columns['count_stock_original'])) {
                $sql = 'ALTER TABLE ' . $db->quoteName($table)
                    . ' ADD COLUMN ' . $db->quoteName('count_stock_original') . ' INT NULL';
                if (isset($columns['count_stock'])) {
                    $sql .= ' AFTER ' . $db->quoteName('count_stock');
                }
                $db->setQuery($sql)->execute();
            }

            $db->setQuery('SHOW INDEX FROM ' . $db->quoteName($table));
            $indexes = $db->loadAssocList() ?: [];
            $hasUnique = false;
            $hasNodeIndex = false;
            foreach ($indexes as $index) {
                $keyName = strtolower((string) ($index['Key_name'] ?? $index['key_name'] ?? ''));
                if ($keyName === 'uniq_user_service') {
                    $hasUnique = true;
                }
                if ($keyName === 'idx_user_service_node') {
                    $hasNodeIndex = true;
                }
            }
            if ($hasUnique) {
                $db->setQuery(
                    'ALTER TABLE ' . $db->quoteName($table)
                    . ' DROP INDEX ' . $db->quoteName('uniq_user_service')
                )->execute();
            }
            if (!$hasNodeIndex) {
                $db->setQuery(
                    'ALTER TABLE ' . $db->quoteName($table)
                    . ' ADD INDEX ' . $db->quoteName('idx_user_service_node')
                    . ' (' . $db->quoteName('user_id') . ', ' . $db->quoteName('service_node_id') . ')'
                )->execute();
            }

            try {
                $s = $db->quoteName('s');
                $b = $db->quoteName('b');
                $db->setQuery(
                    'UPDATE ' . $db->quoteName($table) . ' AS ' . $s
                    . ' SET ' . $s . '.' . $db->quoteName('count_stock_original')
                    . ' = (SELECT COUNT(*) FROM ' . $db->quoteName('#__vigling_bookings') . ' AS ' . $b
                    . ' WHERE ' . $b . '.' . $db->quoteName('stock_service_id') . ' = ' . $s . '.' . $db->quoteName('id')
                    . ' AND ' . $b . '.' . $db->quoteName('booking_kind') . ' = ' . $db->quote('stock') . ')'
                    . ' WHERE (' . $s . '.' . $db->quoteName('count_stock') . ' IS NULL OR ' . $s . '.' . $db->quoteName('count_stock') . ' <= 0)'
                    . ' AND (' . $s . '.' . $db->quoteName('count_stock_original') . ' IS NULL OR ' . $s . '.' . $db->quoteName('count_stock_original') . ' <= 0)'
                )->execute();
            } catch (\Throwable $e) {
            }

            $ensured = true;

            return true;
        } catch (\Throwable $e) {
            $ensured = false;

            return false;
        }
    }

    public static function sanitizeRecommendation($value): string
    {
        $text = trim((string) $value);
        if ($text === '') {
            return '';
        }
        if (function_exists('mb_substr')) {
            return (string) mb_substr($text, 0, 150);
        }

        return substr($text, 0, 150);
    }

    /**
     * Flat stock items for cards/lists (com_aktsii).
     *
     * @param array<int> $userIds
     * @return array<int, array<int, array{price:int,old_price:int,about_stock:string,count_stock:int}>>
     */
    public static function getStockItemsForUsers(array $userIds): array
    {
        $ids = array_values(array_unique(array_map('intval', $userIds)));
        $ids = array_values(array_filter($ids, static fn(int $id): bool => $id > 0));
        if ($ids === []) {
            return [];
        }

        $cacheKey = 'stock-items:' . implode(',', $ids);
        if (isset(self::$cache[$cacheKey])) {
            return self::$cache[$cacheKey];
        }

        $rows = self::loadSourcePayloadRows($ids, '#__vigling_user_stock_services');
        $result = [];
        foreach ($rows as $row) {
            $userId = (int) ($row['user_id'] ?? 0);
            if ($userId <= 0) {
                continue;
            }

            $priceRaw = $row['price'] ?? null;
            $oldPriceRaw = $row['old_price'] ?? null;
            $aboutStockRaw = $row['about_stock'] ?? null;
            $countStockRaw = $row['count_stock'] ?? null;

            $price = self::toInt($priceRaw ?? 0);
            $oldPrice = self::toInt($oldPriceRaw ?? 0);
            $aboutStock = trim((string) ($aboutStockRaw ?? ''));
            $countStock = self::toInt($countStockRaw ?? 0);

            $result[$userId][] = [
                'price' => $price,
                'old_price' => $oldPrice,
                'about_stock' => $aboutStock,
                'count_stock' => $countStock,
            ];
        }

        return self::$cache[$cacheKey] = $result;
    }

    public static function syncUserServicePayloadToTable(
        DatabaseInterface $db,
        int $userId,
        string $payloadJson,
        string $fieldName,
        string $targetTable
    ): void {
        $payloadJson = trim($payloadJson);
        if ($payloadJson === '') {
            return;
        }

        self::ensureRecommendationColumn($db);
        if ($targetTable === '#__vigling_user_stock_services') {
            self::ensureStockArchiveSchema($db);
        }

        $payload = json_decode($payloadJson, true);
        if (!is_array($payload) || !isset($payload['items']) || !is_array($payload['items'])) {
            Log::add(
                "Vigling {$fieldName} payload parse error on save for user_id={$userId}",
                Log::WARNING,
                'plg_user_vigling'
            );
            return;
        }

        if ($targetTable === '#__vigling_user_stock_services') {
            self::syncStockPayloadKeepingArchive($db, $userId, $payload['items']);
            \Joomla\Plugin\User\Vigling\Helper\JsnDecodeHelper::clearFilterCaches();
            return;
        }

        $query = $db->getQuery(true)
            ->delete($db->quoteName($targetTable))
            ->where($db->quoteName('user_id') . ' = ' . (int) $userId);
        $db->setQuery($query)->execute();

        $map = self::getLegacyServiceMap();
        $sourcePriority = ['content', 'vigling_services', 'tag'];
        $catFallbackPriority = ['tag', 'content', 'vigling_services', 'category'];

        foreach ($payload['items'] as $item) {
            if (!is_array($item)) {
                continue;
            }

            $catIdStr = (string) ($item['cat_id'] ?? '');
            $serviceRaw = self::toServiceRaw($item['service_raw'] ?? '');
            $price = self::parsePrice($item['price'] ?? 0);
            $durationRaw = $item['duration'] ?? ($item['duration_min'] ?? 0);
            $duration = self::parseDuration($durationRaw);
            $pauseMin = self::extractPauseMin($durationRaw);
            $parts = self::parseServiceRawParts($serviceRaw);
            $baseId = $parts['base_id'];
            $tagId = $parts['tag_id'];

            if ($baseId === null && trim($serviceRaw) === '') {
                $baseId = 0;
            }

            $resolvedNodeId = null;
            if ($tagId !== null && $tagId > 0) {
                if ($baseId !== null && $baseId > 0) {
                    $resolvedNodeId = self::resolveContextMappedNode(self::getLegacyServiceContextMap(), 'content', (int) $baseId, 'tag', $tagId);
                }
                if ($resolvedNodeId === null) {
                    $resolvedNodeId = self::resolveMappedNode($map, $tagId, ['tag']);
                }
            }

            if ($resolvedNodeId === null && $baseId !== null) {
                if ($baseId > 0) {
                    if ($resolvedNodeId === null) {
                        $resolvedNodeId = self::resolveMappedNode($map, $baseId, ['content', 'vigling_services', 'tag']);
                    }
                } elseif (preg_match('/^\d+$/', $catIdStr)) {
                    $resolvedNodeId = self::resolveMappedNode($map, (int) $catIdStr, $catFallbackPriority);
                }
            }

            if ($resolvedNodeId === null) {
                $floatLike = self::parseFloatLike($serviceRaw);
                if ($floatLike !== null) {
                    $resolvedNodeId = self::resolveMappedNode($map, (int) floor($floatLike), $sourcePriority);
                }
            }

            if ($resolvedNodeId === null && preg_match('/^\d+$/', $catIdStr)) {
                $resolvedNodeId = self::resolveMappedNode($map, (int) $catIdStr, $catFallbackPriority);
            }

            if ($resolvedNodeId === null) {
                continue;
            }

            $legacyCatId = preg_match('/^\d+$/', $catIdStr) ? (int) $catIdStr : null;
            $legacyTagId = ($tagId !== null && $tagId > 0) ? (int) $tagId : null;
            $oldPrice = null;
            $aboutStock = null;
            $countStock = null;
            if ($fieldName === 'stock_prices') {
                $oldPrice = self::parsePrice($item['old_price'] ?? 0);
                $aboutStock = trim((string) ($item['about_stock'] ?? ''));
                $countStock = self::toInt($item['count_stock'] ?? 0);
            }
            $recommendation = self::sanitizeRecommendation($item['recommendation'] ?? '');

            self::upsertUserServiceRow(
                $db,
                $targetTable,
                $userId,
                $resolvedNodeId,
                $price,
                $duration,
                null,
                $legacyCatId,
                $legacyTagId,
                $pauseMin,
                'vigling_payload_v1',
                $oldPrice,
                $aboutStock,
                $countStock,
                $recommendation
            );
        }

        // Clear filter hierarchy caches since service data has changed
        \Joomla\Plugin\User\Vigling\Helper\JsnDecodeHelper::clearFilterCaches();
    }

    /**
     * Keep sold-out rows (count_stock = 0) as archive. Replace only remaining offers.
     *
     * @param array<int, mixed> $items
     */
    private static function syncStockPayloadKeepingArchive(DatabaseInterface $db, int $userId, array $items): void
    {
        $existing = [];
        try {
            $query = $db->getQuery(true)
                ->select([$db->quoteName('id'), $db->quoteName('count_stock')])
                ->from($db->quoteName('#__vigling_user_stock_services'))
                ->where($db->quoteName('user_id') . ' = ' . (int) $userId);
            $db->setQuery($query);
            $existing = $db->loadAssocList() ?: [];
        } catch (\Throwable $e) {
            $existing = [];
        }

        $activeIds = [];
        foreach ($existing as $row) {
            $id = (int) ($row['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            if ((int) ($row['count_stock'] ?? 0) > 0) {
                $activeIds[$id] = true;
            }
        }

        $keptActiveIds = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $resolved = self::resolvePayloadItemToNode($item);
            if ($resolved === null) {
                continue;
            }
            $countStock = self::toInt($item['count_stock'] ?? 0);
            $payloadId = (int) ($item['id'] ?? $item['stock_service_id'] ?? 0);
            $canUpdate = $payloadId > 0 && isset($activeIds[$payloadId]);
            if ($canUpdate) {
                self::updateStockServiceRow(
                    $db,
                    $payloadId,
                    $userId,
                    $resolved['node_id'],
                    $resolved['price'],
                    $resolved['duration'],
                    $resolved['legacy_cat_id'],
                    $resolved['legacy_tag_id'],
                    $resolved['pause_min'],
                    $resolved['old_price'],
                    $resolved['about_stock'],
                    $countStock,
                    $resolved['recommendation']
                );
                $keptActiveIds[$payloadId] = true;
            } else {
                self::upsertUserServiceRow(
                    $db,
                    '#__vigling_user_stock_services',
                    $userId,
                    $resolved['node_id'],
                    $resolved['price'],
                    $resolved['duration'],
                    null,
                    $resolved['legacy_cat_id'],
                    $resolved['legacy_tag_id'],
                    $resolved['pause_min'],
                    'vigling_payload_v1',
                    $resolved['old_price'],
                    $resolved['about_stock'],
                    $countStock,
                    $resolved['recommendation']
                );
            }
        }

        $deleteIds = [];
        foreach (array_keys($activeIds) as $id) {
            if (!isset($keptActiveIds[$id])) {
                $deleteIds[] = (int) $id;
            }
        }
        if ($deleteIds !== []) {
            $query = $db->getQuery(true)
                ->delete($db->quoteName('#__vigling_user_stock_services'))
                ->where($db->quoteName('user_id') . ' = ' . (int) $userId)
                ->where($db->quoteName('count_stock') . ' > 0')
                ->whereIn($db->quoteName('id'), $deleteIds);
            $db->setQuery($query)->execute();
        }
    }

    /**
     * @param array<string, mixed> $item
     * @return array{node_id:int,price:float,duration:int,pause_min:int,legacy_cat_id:?int,legacy_tag_id:?int,old_price:?float,about_stock:?string,recommendation:string}|null
     */
    private static function resolvePayloadItemToNode(array $item): ?array
    {
        $catIdStr = (string) ($item['cat_id'] ?? '');
        $serviceRaw = self::toServiceRaw($item['service_raw'] ?? '');
        $price = self::parsePrice($item['price'] ?? 0);
        $durationRaw = $item['duration'] ?? ($item['duration_min'] ?? 0);
        $duration = self::parseDuration($durationRaw);
        $pauseMin = self::extractPauseMin($durationRaw);
        $parts = self::parseServiceRawParts($serviceRaw);
        $baseId = $parts['base_id'];
        $tagId = $parts['tag_id'];
        $map = self::getLegacyServiceMap();
        $sourcePriority = ['content', 'vigling_services', 'tag'];
        $catFallbackPriority = ['tag', 'content', 'vigling_services', 'category'];

        if ($baseId === null && trim($serviceRaw) === '') {
            $baseId = 0;
        }

        $resolvedNodeId = null;
        if ($tagId !== null && $tagId > 0) {
            if ($baseId !== null && $baseId > 0) {
                $resolvedNodeId = self::resolveContextMappedNode(self::getLegacyServiceContextMap(), 'content', (int) $baseId, 'tag', $tagId);
            }
            if ($resolvedNodeId === null) {
                $resolvedNodeId = self::resolveMappedNode($map, $tagId, ['tag']);
            }
        }

        if ($resolvedNodeId === null && $baseId !== null) {
            if ($baseId > 0) {
                $resolvedNodeId = self::resolveMappedNode($map, $baseId, ['content', 'vigling_services', 'tag']);
            } elseif (preg_match('/^\d+$/', $catIdStr)) {
                $resolvedNodeId = self::resolveMappedNode($map, (int) $catIdStr, $catFallbackPriority);
            }
        }

        if ($resolvedNodeId === null) {
            $floatLike = self::parseFloatLike($serviceRaw);
            if ($floatLike !== null) {
                $resolvedNodeId = self::resolveMappedNode($map, (int) floor($floatLike), $sourcePriority);
            }
        }

        if ($resolvedNodeId === null && preg_match('/^\d+$/', $catIdStr)) {
            $resolvedNodeId = self::resolveMappedNode($map, (int) $catIdStr, $catFallbackPriority);
        }

        if ($resolvedNodeId === null) {
            return null;
        }

        return [
            'node_id' => (int) $resolvedNodeId,
            'price' => $price,
            'duration' => $duration,
            'pause_min' => $pauseMin,
            'legacy_cat_id' => preg_match('/^\d+$/', $catIdStr) ? (int) $catIdStr : null,
            'legacy_tag_id' => ($tagId !== null && $tagId > 0) ? (int) $tagId : null,
            'old_price' => self::parsePrice($item['old_price'] ?? 0),
            'about_stock' => trim((string) ($item['about_stock'] ?? '')),
            'recommendation' => self::sanitizeRecommendation($item['recommendation'] ?? ''),
        ];
    }

    private static function updateStockServiceRow(
        DatabaseInterface $db,
        int $rowId,
        int $userId,
        int $resolvedNodeId,
        float $price,
        int $duration,
        ?int $legacyCatId,
        ?int $legacyTagId,
        int $pauseMin,
        ?float $oldPrice,
        ?string $aboutStock,
        int $countStock,
        string $recommendation
    ): void {
        $hasRecommendation = self::ensureRecommendationColumn($db);
        $hasOriginal = self::ensureStockArchiveSchema($db);
        $fields = [
            $db->quoteName('service_node_id') . ' = ' . (int) $resolvedNodeId,
            $db->quoteName('price') . ' = ' . $db->quote(number_format((float) $price, 2, '.', '')),
            $db->quoteName('duration_min') . ' = ' . (int) $duration,
            $db->quoteName('is_active') . ' = 1',
            $db->quoteName('legacy_cat_id') . ' = ' . ($legacyCatId !== null ? (string) (int) $legacyCatId : 'NULL'),
            $db->quoteName('legacy_tag_id') . ' = ' . ($legacyTagId !== null ? (string) (int) $legacyTagId : 'NULL'),
            $db->quoteName('pause_min') . ' = ' . (string) max(0, (int) $pauseMin),
            $db->quoteName('old_price') . ' = ' . ($oldPrice !== null ? $db->quote(number_format((float) $oldPrice, 2, '.', '')) : 'NULL'),
            $db->quoteName('about_stock') . ' = ' . ($aboutStock !== null ? $db->quote($aboutStock) : 'NULL'),
            $db->quoteName('count_stock') . ' = ' . (int) $countStock,
        ];
        if ($hasOriginal) {
            $fields[] = $db->quoteName('count_stock_original') . ' = ' . (int) $countStock;
        }
        if ($hasRecommendation) {
            $fields[] = $db->quoteName('recommendation') . ' = ' . $db->quote(self::sanitizeRecommendation($recommendation));
        }
        $query = $db->getQuery(true)
            ->update($db->quoteName('#__vigling_user_stock_services'))
            ->set($fields)
            ->where($db->quoteName('id') . ' = ' . (int) $rowId)
            ->where($db->quoteName('user_id') . ' = ' . (int) $userId);
        $db->setQuery($query)->execute();
    }

    /**
     * @return array<int, array<int, array<int, mixed>>>
     */
    private static function getUserServicesLegacyShapeFromTable(int $userId, string $table, bool $isStock): array
    {
        if ($userId <= 0) {
            return [];
        }

        $cacheKey = ($isStock ? 'stock:' : 'prices:') . $userId;
        if (isset(self::$cache[$cacheKey])) {
            /** @var array<int, array<int, array<int, mixed>>> $cached */
            $cached = self::$cache[$cacheKey];
            return $cached;
        }

        $rows = self::loadSourcePayloadRows([$userId], $table);
        $result = [];

        foreach ($rows as $row) {
            $catId = self::toInt($row['legacy_cat_id'] ?? 0);
            $tagId = self::toInt($row['legacy_tag_id'] ?? 0);
            $durationBase = self::toInt($row['duration_min'] ?? 0);
            $pauseMin = self::toInt($row['pause_min'] ?? 0);

            if ($catId <= 0) {
                continue;
            }
            $payload = null;
            $tuple = null;

            $price = $row['price'] ?? 0;
            if ($durationBase > 0 || $pauseMin > 0) {
                $duration = self::composeDurationWithPause($durationBase, $pauseMin);
            } else {
                $duration = $durationBase;
            }

            if (!isset($result[$catId])) {
                $result[$catId] = [];
            }

            if ($isStock) {
                $oldPrice = $row['old_price'] ?? 0;
                $aboutStock = $row['about_stock'] ?? '';
                $countStock = $row['count_stock'] ?? '';
                $result[$catId][] = [$price, $duration, $tagId, $oldPrice, $aboutStock, $countStock];
            } else {
                $result[$catId][] = [$price, $duration, $tagId];
            }
        }

        return self::$cache[$cacheKey] = $result;
    }

    private static function upsertUserServiceRow(
        DatabaseInterface $db,
        string $targetTable,
        int $userId,
        int $resolvedNodeId,
        float $price,
        int $duration,
        ?string $payload,
        ?int $legacyCatId = null,
        ?int $legacyTagId = null,
        int $pauseMin = 0,
        ?string $payloadVariant = null,
        ?float $oldPrice = null,
        ?string $aboutStock = null,
        ?int $countStock = null,
        string $recommendation = ''
    ): void {
        $hasRecommendation = self::ensureRecommendationColumn($db);
        $recommendation = self::sanitizeRecommendation($recommendation);
        $priceSql = $db->quote(number_format((float) $price, 2, '.', ''));
        $legacyCatSql = $legacyCatId !== null ? (string) (int) $legacyCatId : 'NULL';
        $legacyTagSql = $legacyTagId !== null ? (string) (int) $legacyTagId : 'NULL';
        $pauseSql = (string) max(0, (int) $pauseMin);
        $payloadVariantSql = $payloadVariant !== null ? $db->quote($payloadVariant) : 'NULL';
        $oldPriceSql = $oldPrice !== null ? $db->quote(number_format((float) $oldPrice, 2, '.', '')) : 'NULL';
        $aboutStockSql = $aboutStock !== null ? $db->quote($aboutStock) : 'NULL';
        $countStockSql = $countStock !== null ? (string) (int) $countStock : 'NULL';

        $columns = [
            $db->quoteName('user_id'),
            $db->quoteName('service_node_id'),
            $db->quoteName('price'),
            $db->quoteName('duration_min'),
            $db->quoteName('currency'),
            $db->quoteName('is_active'),
            $db->quoteName('legacy_cat_id'),
            $db->quoteName('legacy_tag_id'),
            $db->quoteName('pause_min'),
            $db->quoteName('payload_variant'),
        ];
        $values = [
            (string) (int) $userId,
            (string) (int) $resolvedNodeId,
            $priceSql,
            (string) (int) $duration,
            'NULL',
            '1',
            $legacyCatSql,
            $legacyTagSql,
            $pauseSql,
            $payloadVariantSql,
        ];
        $updates = [
            $db->quoteName('price') . '=VALUES(' . $db->quoteName('price') . ')',
            $db->quoteName('duration_min') . '=VALUES(' . $db->quoteName('duration_min') . ')',
            $db->quoteName('is_active') . '=VALUES(' . $db->quoteName('is_active') . ')',
            $db->quoteName('legacy_cat_id') . '=VALUES(' . $db->quoteName('legacy_cat_id') . ')',
            $db->quoteName('legacy_tag_id') . '=VALUES(' . $db->quoteName('legacy_tag_id') . ')',
            $db->quoteName('pause_min') . '=VALUES(' . $db->quoteName('pause_min') . ')',
            $db->quoteName('payload_variant') . '=VALUES(' . $db->quoteName('payload_variant') . ')',
        ];
        if ($hasRecommendation) {
            $columns[] = $db->quoteName('recommendation');
            $values[] = $db->quote($recommendation);
            $updates[] = $db->quoteName('recommendation') . '=VALUES(' . $db->quoteName('recommendation') . ')';
        }

        if ($targetTable === '#__vigling_user_stock_services') {
            $hasOriginal = self::ensureStockArchiveSchema($db);
            $columns[] = $db->quoteName('old_price');
            $columns[] = $db->quoteName('about_stock');
            $columns[] = $db->quoteName('count_stock');
            $values[] = $oldPriceSql;
            $values[] = $aboutStockSql;
            $values[] = $countStockSql;
            $updates[] = $db->quoteName('old_price') . '=VALUES(' . $db->quoteName('old_price') . ')';
            $updates[] = $db->quoteName('about_stock') . '=VALUES(' . $db->quoteName('about_stock') . ')';
            $updates[] = $db->quoteName('count_stock') . '=VALUES(' . $db->quoteName('count_stock') . ')';
            if ($hasOriginal) {
                $columns[] = $db->quoteName('count_stock_original');
                $values[] = $countStockSql;
                $updates[] = $db->quoteName('count_stock_original') . '=VALUES(' . $db->quoteName('count_stock_original') . ')';
            }
        }

        $sql = 'INSERT INTO ' . $db->quoteName($targetTable)
            . ' (' . implode(',', $columns) . ') VALUES ('
            . implode(',', $values)
            . ') ON DUPLICATE KEY UPDATE '
            . implode(', ', $updates);
        $db->setQuery($sql)->execute();
    }

    private static function getLegacyServiceMap(): array
    {
        if (self::$legacyServiceMap !== null) {
            return self::$legacyServiceMap;
        }

        self::$legacyServiceMap = [];

        try {
            $db = Factory::getContainer()->get(DatabaseInterface::class);
            $query = $db->getQuery(true)
                ->select([
                    $db->quoteName('legacy_source'),
                    $db->quoteName('legacy_id'),
                    $db->quoteName('service_node_id'),
                ])
                ->from($db->quoteName('#__vigling_service_legacy_map'));
            $db->setQuery($query);
            $rows = $db->loadAssocList() ?: [];

            foreach ($rows as $row) {
                $key = (string) ($row['legacy_source'] ?? '') . ':' . (int) ($row['legacy_id'] ?? 0);
                self::$legacyServiceMap[$key] = (int) ($row['service_node_id'] ?? 0);
            }
        } catch (\Throwable $e) {
            Log::add('Vigling legacy map load failed: ' . $e->getMessage(), Log::ERROR, 'plg_user_vigling');
        }

        return self::$legacyServiceMap;
    }

    private static function getLegacyServiceContextMap(): array
    {
        if (self::$legacyServiceContextMap !== null) {
            return self::$legacyServiceContextMap;
        }

        self::$legacyServiceContextMap = [];

        try {
            $db = Factory::getContainer()->get(DatabaseInterface::class);
            $query = $db->getQuery(true)
                ->select([
                    $db->quoteName('context_source'),
                    $db->quoteName('context_id'),
                    $db->quoteName('legacy_source'),
                    $db->quoteName('legacy_id'),
                    $db->quoteName('service_node_id'),
                ])
                ->from($db->quoteName('#__vigling_service_context_map'));
            $db->setQuery($query);
            $rows = $db->loadAssocList() ?: [];

            foreach ($rows as $row) {
                $key = (string) ($row['context_source'] ?? '')
                    . ':' . (int) ($row['context_id'] ?? 0)
                    . '|' . (string) ($row['legacy_source'] ?? '')
                    . ':' . (int) ($row['legacy_id'] ?? 0);
                self::$legacyServiceContextMap[$key] = (int) ($row['service_node_id'] ?? 0);
            }
        } catch (\Throwable $e) {
            Log::add('Vigling legacy context map load failed: ' . $e->getMessage(), Log::ERROR, 'plg_user_vigling');
        }

        return self::$legacyServiceContextMap;
    }

    /**
     * @param array<int> $userIds
     * @return array<int, array<string, mixed>>
     */
    private static function loadSourcePayloadRows(array $userIds, string $table): array
    {
        try {
            $db = Factory::getContainer()->get(DatabaseInterface::class);
            $hasRecommendation = self::ensureRecommendationColumn($db);
            $columns = [
                $db->quoteName('user_id'),
                $db->quoteName('price'),
                $db->quoteName('duration_min'),
                $db->quoteName('legacy_cat_id'),
                $db->quoteName('legacy_tag_id'),
                $db->quoteName('pause_min'),
                $db->quoteName('payload_variant'),
            ];
            if ($hasRecommendation) {
                $columns[] = $db->quoteName('recommendation');
            }
            if ($table === '#__vigling_user_stock_services') {
                $columns[] = $db->quoteName('old_price');
                $columns[] = $db->quoteName('about_stock');
                $columns[] = $db->quoteName('count_stock');
            }
            $query = $db->getQuery(true)
                ->select($columns)
                ->from($db->quoteName($table))
                ->whereIn($db->quoteName('user_id'), array_map('intval', $userIds))
                ->where($db->quoteName('is_active') . ' = 1');
            if ($table === '#__vigling_user_stock_services') {
                $query->where($db->quoteName('count_stock') . ' > 0');
            }
            $query->order($db->quoteName('id') . ' ASC');
            $db->setQuery($query);

            /** @var array<int, array<string, mixed>> $rows */
            $rows = (array) $db->loadAssocList();
            return $rows;
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * @param mixed $value
     */
    private static function toInt($value): int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_float($value)) {
            return (int) $value;
        }
        if (is_string($value)) {
            if (preg_match('/-?\d+/', $value, $m)) {
                return (int) $m[0];
            }
            return 0;
        }
        return 0;
    }

    /**
     * Preserve legacy UI-compatible duration format (`60.15` => 60m + 15m pause).
     *
     * @return int|string
     */
    private static function composeDurationWithPause(int $durationMin, int $pauseMin)
    {
        if ($pauseMin <= 0) {
            return $durationMin;
        }

        return $durationMin . '.' . $pauseMin;
    }

    private static function toServiceRaw($value): string
    {
        if (is_scalar($value) || $value === null) {
            return (string) $value;
        }
        return json_encode($value, JSON_UNESCAPED_UNICODE) ?: '[[non-scalar]]';
    }

    private static function parseDuration($value): int
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

    /**
     * Pause is encoded as decimal part in legacy duration (`45.15` => pause 15).
     *
     * @param mixed $value
     */
    private static function extractPauseMin($value): int
    {
        if (is_string($value)) {
            $value = trim($value);
            if (preg_match('/^-?\d+\.(\d+)$/', $value, $m)) {
                return (int) $m[1];
            }
            return 0;
        }

        if (is_float($value)) {
            $str = rtrim(rtrim(number_format($value, 6, '.', ''), '0'), '.');
            if (preg_match('/^-?\d+\.(\d+)$/', $str, $m)) {
                return (int) $m[1];
            }
        }

        return 0;
    }

    private static function parsePrice($value): float
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

    private static function parseFloatLike($value): ?float
    {
        if (is_float($value) || is_int($value)) {
            return (float) $value;
        }
        if (is_string($value) && is_numeric(str_replace(',', '.', $value))) {
            return (float) str_replace(',', '.', $value);
        }
        return null;
    }

    /**
     * `service_raw` contract:
     * - `<content_id>` for plain service
     * - `<content_id>-<tag_id>` for tagged variant
     *
     * @return array{base_id:?int,tag_id:?int}
     */
    private static function parseServiceRawParts(string $raw): array
    {
        $raw = trim($raw);
        if ($raw === '') {
            return ['base_id' => null, 'tag_id' => null];
        }

        $base = $raw;
        $tag = null;
        if (strpos($raw, '-') !== false) {
            [$base, $tail] = explode('-', $raw, 2);
            $tail = trim($tail);
            if ($tail !== '' && preg_match('/^-?\d+$/', $tail)) {
                $tag = (int) $tail;
            }
        }
        $base = trim($base);
        if ($base === '' || !preg_match('/^-?\d+$/', $base)) {
            return ['base_id' => null, 'tag_id' => $tag];
        }

        return ['base_id' => (int) $base, 'tag_id' => $tag];
    }

    private static function resolveMappedNode(array $map, int $legacyId, array $priority): ?int
    {
        foreach ($priority as $source) {
            $key = $source . ':' . $legacyId;
            if (isset($map[$key])) {
                return (int) $map[$key];
            }
        }
        return null;
    }

    private static function resolveContextMappedNode(array $map, string $contextSource, int $contextId, string $legacySource, int $legacyId): ?int
    {
        $key = $contextSource . ':' . $contextId . '|' . $legacySource . ':' . $legacyId;
        return isset($map[$key]) ? (int) $map[$key] : null;
    }
}
