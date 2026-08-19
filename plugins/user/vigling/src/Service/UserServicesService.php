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

        $query = $db->getQuery(true)
            ->delete($db->quoteName($targetTable))
            ->where($db->quoteName('user_id') . ' = ' . (int) $userId);
        $db->setQuery($query)->execute();

        if ($payloadJson === '') {
            return;
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
                $countStock
            );
        }

        // Clear filter hierarchy caches since service data has changed
        \Joomla\Plugin\User\Vigling\Helper\JsnDecodeHelper::clearFilterCaches();
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
        ?int $countStock = null
    ): void {
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

        if ($targetTable === '#__vigling_user_stock_services') {
            $columns[] = $db->quoteName('old_price');
            $columns[] = $db->quoteName('about_stock');
            $columns[] = $db->quoteName('count_stock');
            $values[] = $oldPriceSql;
            $values[] = $aboutStockSql;
            $values[] = $countStockSql;
            $updates[] = $db->quoteName('old_price') . '=VALUES(' . $db->quoteName('old_price') . ')';
            $updates[] = $db->quoteName('about_stock') . '=VALUES(' . $db->quoteName('about_stock') . ')';
            $updates[] = $db->quoteName('count_stock') . '=VALUES(' . $db->quoteName('count_stock') . ')';
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
            $columns = [
                $db->quoteName('user_id'),
                $db->quoteName('price'),
                $db->quoteName('duration_min'),
                $db->quoteName('legacy_cat_id'),
                $db->quoteName('legacy_tag_id'),
                $db->quoteName('pause_min'),
                $db->quoteName('payload_variant'),
            ];
            if ($table === '#__vigling_user_stock_services') {
                $columns[] = $db->quoteName('old_price');
                $columns[] = $db->quoteName('about_stock');
                $columns[] = $db->quoteName('count_stock');
            }
            $query = $db->getQuery(true)
                ->select($columns)
                ->from($db->quoteName($table))
                ->whereIn($db->quoteName('user_id'), array_map('intval', $userIds))
                ->where($db->quoteName('is_active') . ' = 1')
                ->order($db->quoteName('id') . ' ASC');
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
