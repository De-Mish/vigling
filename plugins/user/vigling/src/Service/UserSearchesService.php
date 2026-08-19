<?php

namespace Joomla\Plugin\User\Vigling\Service;

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\Database\DatabaseInterface;
use Joomla\Event\DispatcherInterface;
use Viglin\Component\Orders\Site\Table\OrderTable;

final class UserSearchesService
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public static function getUserSearchesStructured(int $userId): array
    {
        if ($userId <= 0) {
            return [];
        }

        return self::getSearchesForUsers([$userId])[$userId] ?? [];
    }

    /**
     * @param array<int> $userIds
     * @return array<int, array<int, array<string, mixed>>>
     */
    public static function getSearchesForUsers(array $userIds): array
    {
        $ids = array_values(array_unique(array_map('intval', $userIds)));
        $ids = array_values(array_filter($ids, static fn(int $id): bool => $id > 0));
        if ($ids === []) {
            return [];
        }

        try {
            $db = Factory::getContainer()->get(DatabaseInterface::class);
            $query = $db->getQuery(true)
                ->select([
                    $db->quoteName('c.id'),
                    $db->quoteName('c.user_id'),
                    $db->quoteName('c.category_id'),
                    $db->quoteName('cat.title', 'category_title'),
                    $db->quoteName('c.title'),
                    $db->quoteName('c.description'),
                    $db->quoteName('c.media_path'),
                    $db->quoteName('c.price'),
                    $db->quoteName('c.duration_min'),
                    $db->quoteName('c.capacity'),
                    $db->quoteName('c.booking_mode'),
                    $db->quoteName('c.is_active'),
                    '(SELECT COUNT(*) FROM ' . $db->quoteName('#__vigling_bookings') .
                        ' WHERE ' . $db->quoteName('booking_kind') . ' = ' . $db->quote('search') .
                        ' AND ' . $db->quoteName('search_id') . ' = ' . $db->quoteName('c.id') . ') AS ' . $db->quoteName('booking_count'),
                    $db->quoteName('slot.id', 'slot_id'),
                    $db->quoteName('slot.starts_at_utc'),
                    $db->quoteName('slot.ends_at_utc'),
                    $db->quoteName('slot.capacity_total'),
                ])
                ->from($db->quoteName('#__vigling_user_searches', 'c'))
                ->join('LEFT', $db->quoteName('#__categories', 'cat') . ' ON ' . $db->quoteName('cat.id') . ' = ' . $db->quoteName('c.category_id'))
                ->join('LEFT', $db->quoteName('#__vigling_search_slots', 'slot') . ' ON ' . $db->quoteName('slot.search_id') . ' = ' . $db->quoteName('c.id') . ' AND ' . $db->quoteName('slot.is_active') . ' = 1')
                ->whereIn($db->quoteName('c.user_id'), $ids)
                ->where($db->quoteName('c.is_active') . ' = 1')
                ->order($db->quoteName('c.updated_at') . ' DESC');
            $db->setQuery($query);
            $rows = $db->loadAssocList() ?: [];
        } catch (\Throwable $e) {
            return [];
        }

        $result = [];
        foreach ($rows as $row) {
            $userId = (int) ($row['user_id'] ?? 0);
            if ($userId <= 0) {
                continue;
            }

            $result[$userId][] = [
                'id' => (int) ($row['id'] ?? 0),
                'user_id' => $userId,
                'category_id' => (int) ($row['category_id'] ?? 0),
                'category_title' => trim((string) ($row['category_title'] ?? '')),
                'title' => trim((string) ($row['title'] ?? $row['description'] ?? '')),
                'description' => trim((string) ($row['description'] ?? '')),
                'media_path' => trim((string) ($row['media_path'] ?? '')),
                'price' => self::toInt($row['price'] ?? 0),
                'duration_min' => self::toInt($row['duration_min'] ?? 0),
                'capacity' => max(1, self::toInt($row['capacity'] ?? 1)),
                'booking_mode' => self::normalizeBookingMode((string) ($row['booking_mode'] ?? 'free')),
                'booking_count' => max(0, self::toInt($row['booking_count'] ?? 0)),
                'slot_id' => (int) ($row['slot_id'] ?? 0),
                'slot_start_utc' => trim((string) ($row['starts_at_utc'] ?? '')),
                'slot_end_utc' => trim((string) ($row['ends_at_utc'] ?? '')),
                'slot_capacity_total' => max(0, self::toInt($row['capacity_total'] ?? 0)),
            ];
        }

        return $result;
    }

    public static function syncUserSearchesPayloadToTables(DatabaseInterface $db, int $userId, string $payloadJson): void
    {
        self::ensureOrderTableLoaded();

        $payloadJson = trim($payloadJson);
        $payload = [];
        if ($payloadJson !== '') {
            $decoded = json_decode($payloadJson, true);
            if (is_array($decoded) && isset($decoded['items']) && is_array($decoded['items'])) {
                $payload = $decoded['items'];
            }
        }

        $existingSearches = self::loadExistingSearchesForSync($db, $userId);
        $dispatcher = Factory::getContainer()->get(DispatcherInterface::class);
        $orderTable = new OrderTable($db, $dispatcher);

        $seenExistingIds = [];

        foreach ($payload as $item) {
            if (!is_array($item)) {
                continue;
            }

            $normalized = self::normalizePayloadItem($item);
            if ($normalized === null) {
                continue;
            }

            $searchId = (int) ($normalized['id'] ?? 0);
            if ($searchId > 0 && isset($existingSearches[$searchId])) {
                self::updateExistingSearch($db, $userId, $searchId, $normalized, $existingSearches[$searchId], $orderTable);
                $seenExistingIds[$searchId] = true;
                continue;
            }

            self::insertNewSearch($db, $userId, $normalized);
        }

        foreach ($existingSearches as $searchId => $searchInfo) {
            if (isset($seenExistingIds[$searchId])) {
                continue;
            }
            self::deleteSearchWithBookings($db, $userId, (int) $searchId, $orderTable);
        }
    }

    private static function ensureOrderTableLoaded(): void
    {
        if (class_exists(OrderTable::class)) {
            return;
        }

        $path = JPATH_ROOT . '/components/com_orders/src/Table/OrderTable.php';
        if (is_file($path)) {
            require_once $path;
        }
    }

    private static function normalizeBookingMode(string $mode): string
    {
        $mode = trim($mode);
        return $mode === 'fixed' ? 'fixed' : 'free';
    }

    private static function normalizeLocalDateTime(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        $value = str_replace('T', ' ', $value);
        if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $value)) {
            return $value . ':00';
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $value)) {
            return $value;
        }

        return '';
    }

    /**
     * @param array<string,mixed> $item
     * @return array<string,mixed>|null
     */
    private static function normalizePayloadItem(array $item): ?array
    {
        $categoryId = (int) ($item['category_id'] ?? 0);
        $title = trim((string) ($item['title'] ?? $item['description'] ?? ''));
        $description = self::truncateText(trim((string) ($item['description'] ?? '')), 150);
        $mediaPath = trim((string) ($item['media_path'] ?? ''));
        $price = self::parsePrice($item['price'] ?? 0);
        $durationMin = max(0, (int) ($item['duration_min'] ?? 0));
        $capacity = max(1, (int) ($item['capacity'] ?? 1));
        $bookingMode = self::normalizeBookingMode((string) ($item['booking_mode'] ?? 'free'));
        $slotStartUtc = self::normalizeUtcDateTime((string) ($item['slot_start_utc'] ?? ''));
        $slotStartLocal = self::normalizeLocalDateTime((string) ($item['slot_start_local'] ?? ''));

        if ($categoryId <= 0 || $title === '' || $durationMin <= 0) {
            return null;
        }

        return [
            'id' => (int) ($item['id'] ?? 0),
            'category_id' => $categoryId,
            'title' => $title,
            'description' => $description,
            'media_path' => $mediaPath,
            'price' => $price,
            'duration_min' => $durationMin,
            'capacity' => $capacity,
            'booking_mode' => $bookingMode,
            'slot_start_utc' => $bookingMode === 'fixed' ? $slotStartUtc : '',
            'slot_start_local' => $bookingMode === 'fixed' ? $slotStartLocal : '',
        ];
    }

    /**
     * @return array<int, array<string,mixed>>
     */
    private static function loadExistingSearchesForSync(DatabaseInterface $db, int $userId): array
    {
        if ($userId <= 0) {
            return [];
        }

        $query = $db->getQuery(true)
            ->select([
                $db->quoteName('c.id'),
                $db->quoteName('c.user_id'),
                $db->quoteName('c.category_id'),
                $db->quoteName('c.title'),
                $db->quoteName('c.description'),
                $db->quoteName('c.media_path'),
                $db->quoteName('c.price'),
                $db->quoteName('c.duration_min'),
                $db->quoteName('c.capacity'),
                $db->quoteName('c.booking_mode'),
                $db->quoteName('slot.id', 'slot_id'),
                $db->quoteName('slot.starts_at_utc', 'slot_start_utc'),
                $db->quoteName('slot.ends_at_utc', 'slot_end_utc'),
                $db->quoteName('slot.capacity_total', 'slot_capacity_total'),
            ])
            ->from($db->quoteName('#__vigling_user_searches', 'c'))
            ->join('LEFT', $db->quoteName('#__vigling_search_slots', 'slot') . ' ON ' . $db->quoteName('slot.search_id') . ' = ' . $db->quoteName('c.id') . ' AND ' . $db->quoteName('slot.is_active') . ' = 1')
            ->where($db->quoteName('c.user_id') . ' = ' . (int) $userId);
        $db->setQuery($query);
        $rows = $db->loadAssocList() ?: [];

        $result = [];
        foreach ($rows as $row) {
            $searchId = (int) ($row['id'] ?? 0);
            if ($searchId <= 0) {
                continue;
            }
            $slotId = (int) ($row['slot_id'] ?? 0);
            $result[$searchId] = [
                'id' => $searchId,
                'category_id' => (int) ($row['category_id'] ?? 0),
                'title' => trim((string) ($row['title'] ?? '')),
                'description' => trim((string) ($row['description'] ?? '')),
                'media_path' => trim((string) ($row['media_path'] ?? '')),
                'price' => self::parsePrice($row['price'] ?? 0),
                'duration_min' => (int) ($row['duration_min'] ?? 0),
                'capacity' => max(1, (int) ($row['capacity'] ?? 1)),
                'booking_mode' => self::normalizeBookingMode((string) ($row['booking_mode'] ?? 'free')),
                'slot_id' => $slotId,
                'slot_start_utc' => trim((string) ($row['slot_start_utc'] ?? '')),
                'slot_end_utc' => trim((string) ($row['slot_end_utc'] ?? '')),
                'slot_capacity_total' => max(0, (int) ($row['slot_capacity_total'] ?? 0)),
                'booking_count' => self::countSearchBookings($db, $searchId, $slotId),
            ];
        }

        return $result;
    }

    /**
     * @param array<string,mixed> $normalized
     */
    private static function insertNewSearch(DatabaseInterface $db, int $userId, array $normalized): void
    {
        $searchPayload = [
            'item' => [
                'id' => 0,
                'category_id' => (int) $normalized['category_id'],
                'title' => (string) $normalized['title'],
                'description' => (string) $normalized['description'],
                'media_path' => (string) $normalized['media_path'],
                'price' => (float) $normalized['price'],
                'duration_min' => (int) $normalized['duration_min'],
                'capacity' => (int) $normalized['capacity'],
                'booking_mode' => (string) $normalized['booking_mode'],
            ],
            'payload_variant' => 'searches_payload_v2',
        ];

        $columns = [
            $db->quoteName('user_id'),
            $db->quoteName('category_id'),
            $db->quoteName('title'),
            $db->quoteName('description'),
            $db->quoteName('media_path'),
            $db->quoteName('price'),
            $db->quoteName('duration_min'),
            $db->quoteName('capacity'),
            $db->quoteName('booking_mode'),
            $db->quoteName('is_active'),
            $db->quoteName('source_payload'),
        ];

        $values = [
            (string) $userId,
            (string) $normalized['category_id'],
            $db->quote((string) $normalized['title']),
            $db->quote((string) $normalized['description']),
            (string) $normalized['media_path'] !== '' ? $db->quote((string) $normalized['media_path']) : 'NULL',
            $db->quote(number_format((float) $normalized['price'], 2, '.', '')),
            (string) $normalized['duration_min'],
            (string) $normalized['capacity'],
            $db->quote((string) $normalized['booking_mode']),
            '1',
            $db->quote(json_encode($searchPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
        ];

        $query = $db->getQuery(true)
            ->insert($db->quoteName('#__vigling_user_searches'))
            ->columns($columns)
            ->values(implode(', ', $values));
        $db->setQuery($query)->execute();

        $searchId = (int) $db->insertid();
        if ($searchId <= 0) {
            return;
        }

        if ((string) $normalized['booking_mode'] === 'fixed') {
            self::upsertSearchSlot($db, $searchId, $userId, 0, (string) $normalized['slot_start_utc'], (int) $normalized['duration_min'], (int) $normalized['capacity'], 0, null, (string) $normalized['slot_start_local']);
        }
    }

    /**
     * @param array<string,mixed> $normalized
     * @param array<string,mixed> $existing
     */
    private static function updateExistingSearch(DatabaseInterface $db, int $userId, int $searchId, array $normalized, array $existing, OrderTable $orderTable): void
    {
        $bookingCount = (int) ($existing['booking_count'] ?? 0);
        $requestedMode = (string) $normalized['booking_mode'];
        $existingMode = (string) ($existing['booking_mode'] ?? 'free');

        if ($bookingCount > 0 && $existingMode === 'fixed' && (int) $normalized['duration_min'] !== (int) ($existing['duration_min'] ?? 0)) {
            self::warn('Длительность fixed-поиска "' . (string) ($existing['title'] ?? '') . '" не изменена: у поиска уже есть участники.');
            $normalized['duration_min'] = (int) ($existing['duration_min'] ?? $normalized['duration_min']);
        }

        if ($bookingCount > 0 && (int) $normalized['capacity'] < $bookingCount) {
            self::warn('Лимит мест поиска "' . (string) ($existing['title'] ?? '') . '" не может быть меньше числа записанных участников. Сохранено значение ' . $bookingCount . '.');
            $normalized['capacity'] = $bookingCount;
        }

        if ($bookingCount > 0 && $existingMode === 'free' && $requestedMode === 'fixed') {
            self::warn('Нельзя перевести поиск "' . (string) ($existing['title'] ?? '') . '" в fixed-режим, пока на него уже есть записи.');
            $requestedMode = 'free';
            $normalized['booking_mode'] = 'free';
            $normalized['slot_start_utc'] = '';
        }

        $searchPayload = [
            'item' => [
                'id' => $searchId,
                'category_id' => (int) $normalized['category_id'],
                'title' => (string) $normalized['title'],
                'description' => (string) $normalized['description'],
                'media_path' => (string) $normalized['media_path'],
                'price' => (float) $normalized['price'],
                'duration_min' => (int) $normalized['duration_min'],
                'capacity' => (int) $normalized['capacity'],
                'booking_mode' => $requestedMode,
            ],
            'payload_variant' => 'searches_payload_v2',
        ];

        $query = $db->getQuery(true)
            ->update($db->quoteName('#__vigling_user_searches'))
            ->set($db->quoteName('category_id') . ' = ' . (int) $normalized['category_id'])
            ->set($db->quoteName('title') . ' = ' . $db->quote((string) $normalized['title']))
            ->set($db->quoteName('description') . ' = ' . $db->quote((string) $normalized['description']))
            ->set($db->quoteName('media_path') . ' = ' . ((string) $normalized['media_path'] !== '' ? $db->quote((string) $normalized['media_path']) : 'NULL'))
            ->set($db->quoteName('price') . ' = ' . $db->quote(number_format((float) $normalized['price'], 2, '.', '')))
            ->set($db->quoteName('duration_min') . ' = ' . (int) $normalized['duration_min'])
            ->set($db->quoteName('capacity') . ' = ' . (int) $normalized['capacity'])
            ->set($db->quoteName('booking_mode') . ' = ' . $db->quote($requestedMode))
            ->set($db->quoteName('is_active') . ' = 1')
            ->set($db->quoteName('source_payload') . ' = ' . $db->quote(json_encode($searchPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)))
            ->where($db->quoteName('id') . ' = ' . $searchId)
            ->where($db->quoteName('user_id') . ' = ' . $userId);
        $db->setQuery($query)->execute();

        $existingSlotId = (int) ($existing['slot_id'] ?? 0);
        if ($requestedMode === 'fixed') {
            $slotStartUtc = (string) $normalized['slot_start_utc'];
            if ($slotStartUtc === '' && $existingSlotId > 0) {
                $slotStartUtc = (string) ($existing['slot_start_utc'] ?? '');
            }
            if ($slotStartUtc !== '') {
                self::upsertSearchSlot($db, $searchId, $userId, $existingSlotId, $slotStartUtc, (int) $normalized['duration_min'], (int) $normalized['capacity'], $bookingCount, $orderTable, (string) $normalized['slot_start_local']);
            }
            return;
        }

        if ($existingSlotId > 0) {
            if ($bookingCount > 0) {
                self::detachBookingsFromSlot($db, $existingSlotId);
            }
            self::deactivateAndDeleteSearchSlot($db, $existingSlotId);
        }
    }

    private static function deleteSearchWithBookings(DatabaseInterface $db, int $userId, int $searchId, OrderTable $orderTable): void
    {
        if ($searchId <= 0 || $userId <= 0) {
            return;
        }

        $bookingIds = self::getSearchBookingIds($db, $searchId, $userId);
        foreach ($bookingIds as $bookingId) {
            if (!$orderTable->load((int) $bookingId)) {
                continue;
            }
            $orderTable->delete((int) $bookingId);
        }

        $slotQuery = $db->getQuery(true)
            ->select($db->quoteName('id'))
            ->from($db->quoteName('#__vigling_search_slots'))
            ->where($db->quoteName('search_id') . ' = ' . $searchId);
        $db->setQuery($slotQuery);
        $slotIds = array_map('intval', $db->loadColumn() ?: []);
        foreach ($slotIds as $slotId) {
            self::deactivateAndDeleteSearchSlot($db, $slotId);
        }

        $searchDelete = $db->getQuery(true)
            ->delete($db->quoteName('#__vigling_user_searches'))
            ->where($db->quoteName('id') . ' = ' . $searchId)
            ->where($db->quoteName('user_id') . ' = ' . $userId);
        $db->setQuery($searchDelete)->execute();
    }

    private static function upsertSearchSlot(
        DatabaseInterface $db,
        int $searchId,
        int $userId,
        int $slotId,
        string $slotStartUtc,
        int $durationMin,
        int $capacity,
        int $bookingCount = 0,
        ?OrderTable $orderTable = null,
        string $slotStartLocal = ''
    ): void {
        $slotStartUtc = self::normalizeUtcDateTime($slotStartUtc);
        if ($searchId <= 0 || $userId <= 0 || $slotStartUtc === '') {
            return;
        }

        $durationMin = max(15, $durationMin);
        $capacity = max(1, $capacity);
        $start = new \DateTimeImmutable($slotStartUtc, new \DateTimeZone('UTC'));
        $end = $start->modify('+' . $durationMin . ' minutes');
        $startDb = $start->format('Y-m-d H:i:s');
        $endDb = $end->format('Y-m-d H:i:s');
        $slotInfo = $slotId > 0 ? self::loadSlotInfo($db, $slotId) : null;
        $timeChanged = true;

        if ($slotInfo !== null) {
            $timeChanged = trim((string) ($slotInfo['starts_at_utc'] ?? '')) !== $startDb
                || trim((string) ($slotInfo['ends_at_utc'] ?? '')) !== $endDb;
        }

        if ($slotId <= 0 || $timeChanged) {
            $excludeBookingIds = $slotId > 0 ? self::getSearchSlotBookingIds($db, $slotId, $userId) : [];
            self::assertFixedSlotCanBeSaved($db, $userId, $start, $end, $slotId, $excludeBookingIds, $slotStartLocal, $durationMin);
        }

        if ($slotId > 0) {
            if ($slotInfo !== null) {
                if ($bookingCount > 0 && $timeChanged && $orderTable !== null) {
                    self::rescheduleBookingsForSlot($db, $slotId, $userId, $startDb, $endDb, $orderTable);
                }
                $update = $db->getQuery(true)
                    ->update($db->quoteName('#__vigling_search_slots'))
                    ->set($db->quoteName('starts_at_utc') . ' = ' . $db->quote($startDb))
                    ->set($db->quoteName('ends_at_utc') . ' = ' . $db->quote($endDb))
                    ->set($db->quoteName('capacity_total') . ' = ' . $capacity)
                    ->set($db->quoteName('is_active') . ' = 1')
                    ->where($db->quoteName('id') . ' = ' . $slotId)
                    ->where($db->quoteName('search_id') . ' = ' . $searchId);
                $db->setQuery($update)->execute();
                return;
            }
        }

        $slotColumns = [
            $db->quoteName('search_id'),
            $db->quoteName('master_id'),
            $db->quoteName('starts_at_utc'),
            $db->quoteName('ends_at_utc'),
            $db->quoteName('capacity_total'),
            $db->quoteName('is_active'),
        ];

        $slotValues = [
            (string) $searchId,
            (string) $userId,
            $db->quote($startDb),
            $db->quote($endDb),
            (string) $capacity,
            '1',
        ];

        $slotQuery = $db->getQuery(true)
            ->insert($db->quoteName('#__vigling_search_slots'))
            ->columns($slotColumns)
            ->values(implode(', ', $slotValues));
        $db->setQuery($slotQuery)->execute();
    }

    private static function detachBookingsFromSlot(DatabaseInterface $db, int $slotId): void
    {
        if ($slotId <= 0) {
            return;
        }

        $query = $db->getQuery(true)
            ->update($db->quoteName('#__vigling_bookings'))
            ->set($db->quoteName('search_slot_id') . ' = NULL')
            ->where($db->quoteName('booking_kind') . ' = ' . $db->quote('search'))
            ->where($db->quoteName('search_slot_id') . ' = ' . $slotId);
        $db->setQuery($query)->execute();
    }

    private static function deactivateAndDeleteSearchSlot(DatabaseInterface $db, int $slotId): void
    {
        if ($slotId <= 0) {
            return;
        }

        $update = $db->getQuery(true)
            ->update($db->quoteName('#__vigling_search_slots'))
            ->set($db->quoteName('is_active') . ' = 0')
            ->where($db->quoteName('id') . ' = ' . $slotId);
        $db->setQuery($update)->execute();

        $delete = $db->getQuery(true)
            ->delete($db->quoteName('#__vigling_search_slots'))
            ->where($db->quoteName('id') . ' = ' . $slotId);
        $db->setQuery($delete)->execute();
    }

    private static function countSearchBookings(DatabaseInterface $db, int $searchId, int $slotId = 0): int
    {
        if ($searchId <= 0) {
            return 0;
        }

        $query = $db->getQuery(true)
            ->select('COUNT(*)')
            ->from($db->quoteName('#__vigling_bookings'))
            ->where($db->quoteName('booking_kind') . ' = ' . $db->quote('search'))
            ->where($db->quoteName('search_id') . ' = ' . $searchId);
        if ($slotId > 0) {
            $query->where($db->quoteName('search_slot_id') . ' = ' . $slotId);
        }
        $db->setQuery($query);
        return (int) $db->loadResult();
    }

    /**
     * @return int[]
     */
    private static function getSearchBookingIds(DatabaseInterface $db, int $searchId, int $masterId): array
    {
        if ($searchId <= 0 || $masterId <= 0) {
            return [];
        }

        $query = $db->getQuery(true)
            ->select($db->quoteName('id'))
            ->from($db->quoteName('#__vigling_bookings'))
            ->where($db->quoteName('booking_kind') . ' = ' . $db->quote('search'))
            ->where($db->quoteName('search_id') . ' = ' . $searchId)
            ->where($db->quoteName('master_id') . ' = ' . $masterId);
        $db->setQuery($query);
        return array_map('intval', $db->loadColumn() ?: []);
    }

    /**
     * @return array<string,mixed>|null
     */
    private static function loadSlotInfo(DatabaseInterface $db, int $slotId): ?array
    {
        if ($slotId <= 0) {
            return null;
        }

        $query = $db->getQuery(true)
            ->select('*')
            ->from($db->quoteName('#__vigling_search_slots'))
            ->where($db->quoteName('id') . ' = ' . $slotId);
        $db->setQuery($query);
        $row = $db->loadAssoc();
        return is_array($row) ? $row : null;
    }

    private static function rescheduleBookingsForSlot(DatabaseInterface $db, int $slotId, int $masterId, string $startDb, string $endDb, OrderTable $orderTable): void
    {
        $bookingIds = self::getSearchSlotBookingIds($db, $slotId, $masterId);
        foreach ($bookingIds as $bookingId) {
            if (!$orderTable->load((int) $bookingId)) {
                continue;
            }
            $orderTable->time = $startDb;
            $orderTable->time_to = $endDb;
            $orderTable->store();
        }
    }

    /**
     * @return int[]
     */
    private static function getSearchSlotBookingIds(DatabaseInterface $db, int $slotId, int $masterId): array
    {
        if ($slotId <= 0 || $masterId <= 0) {
            return [];
        }

        $query = $db->getQuery(true)
            ->select($db->quoteName('id'))
            ->from($db->quoteName('#__vigling_bookings'))
            ->where($db->quoteName('booking_kind') . ' = ' . $db->quote('search'))
            ->where($db->quoteName('search_slot_id') . ' = ' . $slotId)
            ->where($db->quoteName('master_id') . ' = ' . $masterId);
        $db->setQuery($query);
        return array_map('intval', $db->loadColumn() ?: []);
    }

    private static function warn(string $message): void
    {
        try {
            Factory::getApplication()->enqueueMessage($message, 'warning');
        } catch (\Throwable $e) {
        }
    }

    /**
     * @param int[] $excludeBookingIds
     */
    private static function assertFixedSlotCanBeSaved(
        DatabaseInterface $db,
        int $masterId,
        \DateTimeImmutable $startUtc,
        \DateTimeImmutable $endUtc,
        int $excludeSlotId = 0,
        array $excludeBookingIds = [],
        string $slotStartLocal = '',
        int $durationMin = 0
    ): void {
        $nowUtc = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        if ($startUtc <= $nowUtc) {
            throw new \RuntimeException('Нельзя сохранить поиск на прошедшее время');
        }

        $scheduleCheck = self::validateMasterScheduleByLocalInput($masterId, $slotStartLocal, $durationMin);
        if ($scheduleCheck === null) {
            $masterTimezone = self::getUserTimezoneById($masterId, (string) Factory::getApplication()->get('offset', 'UTC'));
            $scheduleCheck = self::validateMasterSchedule($masterId, $startUtc, $endUtc, $masterTimezone);
        }
        if (!$scheduleCheck['ok']) {
            throw new \RuntimeException((string) ($scheduleCheck['message'] ?? 'Выбранное время вне рабочего графика мастера'));
        }

        $startDb = $startUtc->format('Y-m-d H:i:s');
        $endDb = $endUtc->format('Y-m-d H:i:s');
        if (self::hasSearchSlotsOverlap($db, $masterId, $startDb, $endDb, $excludeSlotId)) {
            throw new \RuntimeException('На это время уже назначен другой поиск');
        }
        if (self::hasCourseSlotsOverlap($db, $masterId, $startDb, $endDb)) {
            throw new \RuntimeException('Это время уже занято курсом');
        }

        $bookingsTable = $db->getPrefix() . 'vigling_bookings';
        if (self::hasBookingsOverlap($db, $bookingsTable, $masterId, $startDb, $endDb, $excludeBookingIds)) {
            throw new \RuntimeException('Это время уже занято');
        }
    }

    /**
     * @return array{ok:bool,message:string}|null
     */
    private static function validateMasterScheduleByLocalInput(int $masterId, string $slotStartLocal, int $durationMin): ?array
    {
        $slotStartLocal = self::normalizeLocalDateTime($slotStartLocal);
        if ($slotStartLocal === '') {
            return null;
        }

        try {
            $startLocal = new \DateTimeImmutable($slotStartLocal, new \DateTimeZone('UTC'));
        } catch (\Throwable $e) {
            return null;
        }

        $durationMin = max(15, $durationMin);
        $endLocal = $startLocal->modify('+' . $durationMin . ' minutes');

        return self::validateMasterScheduleLocal($masterId, $startLocal, $endLocal);
    }

    private static function getUserTimezoneById(int $userId, string $fallback = 'UTC'): string
    {
        $fallback = trim($fallback) !== '' ? trim($fallback) : 'UTC';
        if ($userId <= 0) {
            return $fallback;
        }

        try {
            $query = Factory::getContainer()->get(DatabaseInterface::class)->getQuery(true)
                ->select('params')
                ->from('#__users')
                ->where('id = ' . (int) $userId);
            $db = Factory::getContainer()->get(DatabaseInterface::class);
            $db->setQuery($query);
            $paramsRaw = (string) ($db->loadResult() ?? '');
            $params = json_decode($paramsRaw, true);
            if (is_array($params) && !empty($params['timezone']) && is_string($params['timezone'])) {
                $tz = trim((string) $params['timezone']);
                if ($tz !== '') {
                    new \DateTimeZone($tz);
                    return $tz;
                }
            }
        } catch (\Throwable $e) {
        }

        return $fallback;
    }

    /**
     * @return array{ok:bool,message:string}
     */
    private static function validateMasterSchedule(int $masterId, \DateTimeImmutable $startUtc, \DateTimeImmutable $endUtc, string $siteOffset): array
    {
        try {
            $siteTz = new \DateTimeZone($siteOffset !== '' ? $siteOffset : 'UTC');
        } catch (\Throwable $e) {
            $siteTz = new \DateTimeZone('UTC');
        }

        $startLocal = $startUtc->setTimezone($siteTz);
        $endLocal = $endUtc->setTimezone($siteTz);

        return self::validateMasterScheduleLocal($masterId, $startLocal, $endLocal);
    }

    /**
     * @return array{ok:bool,message:string}
     */
    private static function validateMasterScheduleLocal(int $masterId, \DateTimeImmutable $startLocal, \DateTimeImmutable $endLocal): array
    {
        $schedule = self::loadMasterScheduleByDay($masterId);
        if ($schedule === []) {
            return ['ok' => true, 'message' => ''];
        }

        if ($endLocal <= $startLocal) {
            return ['ok' => false, 'message' => 'Некорректный интервал записи'];
        }

        if ($startLocal->format('Y-m-d') !== $endLocal->format('Y-m-d')) {
            return ['ok' => false, 'message' => 'Запись должна укладываться в один рабочий день'];
        }

        $dayNum = (int) $startLocal->format('N');
        $startMin = ((int) $startLocal->format('H')) * 60 + (int) $startLocal->format('i');
        $endMin = ((int) $endLocal->format('H')) * 60 + (int) $endLocal->format('i');
        if (!isset($schedule[$dayNum])) {
            return ['ok' => false, 'message' => 'Мастер не работает в выбранный день'];
        }

        $range = $schedule[$dayNum];
        $fromMin = (int) ($range[0] ?? 0);
        $toMin = (int) ($range[1] ?? 0);
        if ($startMin < $fromMin || $endMin > $toMin) {
            return ['ok' => false, 'message' => 'Выбранное время вне рабочего графика мастера'];
        }

        return ['ok' => true, 'message' => ''];
    }

    /**
     * @return array<int, array{0:int,1:int}>
     */
    private static function loadMasterScheduleByDay(int $masterId): array
    {
        if ($masterId <= 0) {
            return [];
        }

        try {
            $db = Factory::getContainer()->get(DatabaseInterface::class);
            $query = $db->getQuery(true)
                ->select([
                    $db->quoteName('f.name', 'field_name'),
                    $db->quoteName('fv.value', 'field_value'),
                ])
                ->from($db->quoteName('#__fields_values', 'fv'))
                ->join('INNER', $db->quoteName('#__fields', 'f') . ' ON ' . $db->quoteName('f.id') . ' = ' . $db->quoteName('fv.field_id'))
                ->where($db->quoteName('fv.item_id') . ' = ' . (int) $masterId)
                ->where($db->quoteName('f.context') . ' = ' . $db->quote('com_users.user'))
                ->where($db->quoteName('f.name') . ' IN (' . $db->quote('work_day') . ', ' . $db->quote('work_from') . ', ' . $db->quote('work_to') . ')');
            $db->setQuery($query);
            $rows = $db->loadAssocList() ?: [];
        } catch (\Throwable $e) {
            return [];
        }

        $raw = ['work_day' => '', 'work_from' => '', 'work_to' => ''];
        foreach ($rows as $row) {
            $name = (string) ($row['field_name'] ?? '');
            if (!isset($raw[$name])) {
                continue;
            }
            $raw[$name] = trim((string) ($row['field_value'] ?? ''));
        }

        $workDays = self::decodeIntList($raw['work_day']);
        if ($workDays === []) {
            return [];
        }
        $workFrom = self::decodeStringList($raw['work_from']);
        $workTo = self::decodeStringList($raw['work_to']);

        $fromByDay = array_fill(1, 7, '');
        $toByDay = array_fill(1, 7, '');
        if (count($workFrom) === 1) {
            foreach ($workDays as $wd) {
                $fromByDay[$wd] = $workFrom[0];
            }
        } elseif (count($workFrom) === count($workDays)) {
            foreach ($workDays as $idx => $wd) {
                $fromByDay[$wd] = (string) ($workFrom[$idx] ?? '');
            }
        }
        if (count($workTo) === 1) {
            foreach ($workDays as $wd) {
                $toByDay[$wd] = $workTo[0];
            }
        } elseif (count($workTo) === count($workDays)) {
            foreach ($workDays as $idx => $wd) {
                $toByDay[$wd] = (string) ($workTo[$idx] ?? '');
            }
        }

        $result = [];
        foreach ($workDays as $wd) {
            $fromMin = self::parseTimeToMinutes((string) ($fromByDay[$wd] ?? ''));
            $toMin = self::parseTimeToMinutes((string) ($toByDay[$wd] ?? ''));
            if ($fromMin === null || $toMin === null || $toMin <= $fromMin) {
                continue;
            }
            $result[$wd] = [$fromMin, $toMin];
        }

        return $result;
    }

    /**
     * @return array<int>
     */
    private static function decodeIntList(string $raw): array
    {
        $raw = trim($raw);
        if ($raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        $vals = [];
        if (is_array($decoded)) {
            $iter = new \RecursiveIteratorIterator(new \RecursiveArrayIterator($decoded));
            foreach ($iter as $v) {
                if (is_scalar($v) && preg_match('/^\d+$/', (string) $v)) {
                    $vals[] = (int) $v;
                }
            }
        } else {
            preg_match_all('/\d+/', $raw, $m);
            foreach (($m[0] ?? []) as $num) {
                $vals[] = (int) $num;
            }
        }
        $vals = array_values(array_unique(array_filter($vals, static function (int $v): bool {
            return $v >= 1 && $v <= 7;
        })));
        sort($vals);
        return $vals;
    }

    /**
     * @return array<int, string>
     */
    private static function decodeStringList(string $raw): array
    {
        $raw = trim($raw);
        if ($raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        $out = [];
        if (is_array($decoded)) {
            foreach ($decoded as $v) {
                if (is_scalar($v)) {
                    $s = trim((string) $v);
                    if ($s !== '') {
                        $out[] = $s;
                    }
                }
            }
        } else {
            $out[] = $raw;
        }
        return $out;
    }

    private static function parseTimeToMinutes(string $raw): ?int
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }
        if (preg_match('/^(\d{1,2}):(\d{2})$/', $raw, $m)) {
            $h = max(0, min(23, (int) $m[1]));
            $i = max(0, min(59, (int) $m[2]));
            return $h * 60 + $i;
        }
        if (is_numeric($raw)) {
            $num = (float) $raw;
            $h = (int) floor($num);
            $m = (int) round(($num - $h) * 60);
            $h = max(0, min(23, $h));
            $m = max(0, min(59, $m));
            return $h * 60 + $m;
        }
        return null;
    }

    /**
     * @param int[] $excludeIds
     */
    private static function hasBookingsOverlap(
        DatabaseInterface $db,
        string $tableName,
        int $masterId,
        string $startUtc,
        string $endUtc,
        array $excludeIds = []
    ): bool {
        $query = $db->getQuery(true)
            ->select('COUNT(*)')
            ->from($db->quoteName($tableName))
            ->where($db->quoteName('master_id') . ' = ' . (int) $masterId)
            ->where($db->quoteName('time') . ' < ' . $db->quote($endUtc))
            ->where($db->quoteName('time_to') . ' > ' . $db->quote($startUtc));
        $excludeIds = array_values(array_filter(array_map('intval', $excludeIds), static function (int $id): bool {
            return $id > 0;
        }));
        if ($excludeIds !== []) {
            $query->where($db->quoteName('id') . ' NOT IN (' . implode(', ', $excludeIds) . ')');
        }
        $db->setQuery($query);

        return ((int) $db->loadResult()) > 0;
    }

    private static function hasSearchSlotsOverlap(
        DatabaseInterface $db,
        int $masterId,
        string $startUtc,
        string $endUtc,
        int $excludeSlotId = 0
    ): bool {
        $query = $db->getQuery(true)
            ->select('COUNT(*)')
            ->from($db->quoteName('#__vigling_search_slots'))
            ->where($db->quoteName('master_id') . ' = ' . (int) $masterId)
            ->where($db->quoteName('is_active') . ' = 1')
            ->where($db->quoteName('starts_at_utc') . ' < ' . $db->quote($endUtc))
            ->where($db->quoteName('ends_at_utc') . ' > ' . $db->quote($startUtc));
        if ($excludeSlotId > 0) {
            $query->where($db->quoteName('id') . ' <> ' . (int) $excludeSlotId);
        }
        $db->setQuery($query);

        return ((int) $db->loadResult()) > 0;
    }

    private static function hasCourseSlotsOverlap(
        DatabaseInterface $db,
        int $masterId,
        string $startUtc,
        string $endUtc
    ): bool {
        try {
            $query = $db->getQuery(true)
                ->select('COUNT(*)')
                ->from($db->quoteName('#__vigling_course_slots'))
                ->where($db->quoteName('master_id') . ' = ' . (int) $masterId)
                ->where($db->quoteName('is_active') . ' = 1')
                ->where($db->quoteName('starts_at_utc') . ' < ' . $db->quote($endUtc))
                ->where($db->quoteName('ends_at_utc') . ' > ' . $db->quote($startUtc));
            $db->setQuery($query);

            return ((int) $db->loadResult()) > 0;
        } catch (\Throwable $e) {
            return false;
        }
    }

    private static function truncateText(string $value, int $limit): string
    {
        if ($limit <= 0 || $value === '') {
            return $limit <= 0 ? '' : $value;
        }

        if (function_exists('mb_substr')) {
            return mb_substr($value, 0, $limit);
        }

        return substr($value, 0, $limit);
    }

    private static function normalizeUtcDateTime(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        try {
            $date = new \DateTimeImmutable($value, new \DateTimeZone('UTC'));
            return $date->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        } catch (\Throwable $e) {
            return '';
        }
    }

    private static function parsePrice($value): float
    {
        if (is_int($value) || is_float($value)) {
            return max(0, (float) $value);
        }

        if (is_string($value)) {
            $normalized = str_replace(',', '.', trim($value));
            if (preg_match('/-?\d+(\.\d+)?/', $normalized, $m)) {
                return max(0, (float) $m[0]);
            }
        }

        return 0.0;
    }

    private static function toInt($value): int
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
}
