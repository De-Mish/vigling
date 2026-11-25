<?php

namespace Joomla\Plugin\User\Vigling\Service;

\defined('_JEXEC') or die;

use Joomla\Database\DatabaseInterface;

/**
 * Persistence and validation for model-search offers linked to course lessons.
 */
final class CourseLinkService
{
    public static function schemaReady(DatabaseInterface $db): bool
    {
        static $ready = null;
        if ($ready !== null) {
            return $ready;
        }

        try {
            $courses = array_change_key_case($db->getTableColumns('#__vigling_user_courses', false), CASE_LOWER);
            $tables = array_map('strtolower', (array) $db->getTableList());
            $ready = isset($courses['is_online'], $courses['online_url'])
                && in_array(strtolower($db->getPrefix() . 'vigling_course_search_links'), $tables, true);
        } catch (\Throwable $e) {
            $ready = false;
        }

        return $ready;
    }

    /**
     * @param int[] $courseIds
     * @return array<int, array<int, array<string,mixed>>>
     */
    public static function loadLinks(DatabaseInterface $db, array $courseIds): array
    {
        $courseIds = array_values(array_unique(array_filter(array_map('intval', $courseIds))));
        if ($courseIds === [] || !self::schemaReady($db)) {
            return [];
        }

        $query = $db->getQuery(true)
            ->select([
                $db->quoteName('l.course_id'),
                $db->quoteName('l.part_no'),
                $db->quoteName('l.search_id'),
                $db->quoteName('l.search_slot_id'),
                $db->quoteName('s.title', 'search_title'),
                $db->quoteName('s.booking_mode', 'search_booking_mode'),
                $db->quoteName('ss.starts_at_utc', 'search_slot_start_utc'),
                $db->quoteName('ss.ends_at_utc', 'search_slot_end_utc'),
            ])
            ->from($db->quoteName('#__vigling_course_search_links', 'l'))
            ->join('INNER', $db->quoteName('#__vigling_user_searches', 's') . ' ON ' . $db->quoteName('s.id') . ' = ' . $db->quoteName('l.search_id'))
            ->join('LEFT', $db->quoteName('#__vigling_search_slots', 'ss') . ' ON ' . $db->quoteName('ss.id') . ' = ' . $db->quoteName('l.search_slot_id'))
            ->whereIn($db->quoteName('l.course_id'), $courseIds);
        $db->setQuery($query);

        $result = [];
        foreach ($db->loadAssocList() ?: [] as $row) {
            $result[(int) $row['course_id']][(int) $row['part_no']] = [
                'search_id' => (int) $row['search_id'],
                'search_slot_id' => (int) ($row['search_slot_id'] ?? 0),
                'search_title' => trim((string) ($row['search_title'] ?? '')),
                'search_booking_mode' => trim((string) ($row['search_booking_mode'] ?? 'free')),
                'search_slot_start_utc' => trim((string) ($row['search_slot_start_utc'] ?? '')),
                'search_slot_end_utc' => trim((string) ($row['search_slot_end_utc'] ?? '')),
            ];
        }

        return $result;
    }

    /**
     * Validate that every selected search belongs to this master and is active.
     * Fixed searches must reference their active slot; free searches never carry a slot.
     *
     * @param array<int, array<string,mixed>> $rawLinks
     * @return array<int, array{search_id:int,search_slot_id:int}>
     */
    public static function normalizeLinks(DatabaseInterface $db, int $masterId, array $rawLinks, int $lessonsCount): array
    {
        if ($masterId <= 0 || !self::schemaReady($db)) {
            return [];
        }

        $normalized = [];
        $seenSearches = [];
        foreach ($rawLinks as $partNo => $raw) {
            if (!is_array($raw)) {
                continue;
            }
            $partNo = max(1, min($lessonsCount, (int) ($raw['part_no'] ?? $partNo)));
            $searchId = (int) ($raw['search_id'] ?? 0);
            if ($searchId <= 0) {
                continue;
            }
            if (isset($seenSearches[$searchId])) {
                throw new \RuntimeException('Один и тот же «Поиск моделей» нельзя привязать к нескольким занятиям курса.');
            }

            $query = $db->getQuery(true)
                ->select([
                    $db->quoteName('s.id'),
                    $db->quoteName('s.booking_mode'),
                    $db->quoteName('slot.id', 'slot_id'),
                ])
                ->from($db->quoteName('#__vigling_user_searches', 's'))
                ->join('LEFT', $db->quoteName('#__vigling_search_slots', 'slot') . ' ON ' . $db->quoteName('slot.search_id') . ' = ' . $db->quoteName('s.id') . ' AND ' . $db->quoteName('slot.is_active') . ' = 1')
                ->where($db->quoteName('s.id') . ' = ' . $searchId)
                ->where($db->quoteName('s.user_id') . ' = ' . $masterId)
                ->where($db->quoteName('s.is_active') . ' = 1');
            $db->setQuery($query);
            $search = $db->loadAssoc();
            if (!$search) {
                throw new \RuntimeException('Выбранный «Поиск моделей» не найден или больше не активен.');
            }

            $isFixed = trim((string) $search['booking_mode']) === 'fixed';
            $slotId = $isFixed ? (int) ($search['slot_id'] ?? 0) : 0;
            if ($isFixed && $slotId <= 0) {
                throw new \RuntimeException('У выбранного «Поиска моделей» нет активного временного интервала.');
            }
            $normalized[$partNo] = ['search_id' => $searchId, 'search_slot_id' => $slotId];
            $seenSearches[$searchId] = true;
        }

        ksort($normalized);
        return $normalized;
    }

    /**
     * @param array<int, array{search_id:int,search_slot_id:int}> $links
     */
    public static function saveLinks(DatabaseInterface $db, int $courseId, array $links): void
    {
        if ($courseId <= 0 || !self::schemaReady($db)) {
            return;
        }

        $db->setQuery(
            $db->getQuery(true)
                ->delete($db->quoteName('#__vigling_course_search_links'))
                ->where($db->quoteName('course_id') . ' = ' . $courseId)
        )->execute();

        foreach ($links as $partNo => $link) {
            $query = $db->getQuery(true)
                ->insert($db->quoteName('#__vigling_course_search_links'))
                ->columns([
                    $db->quoteName('course_id'),
                    $db->quoteName('part_no'),
                    $db->quoteName('search_id'),
                    $db->quoteName('search_slot_id'),
                ])
                ->values(implode(', ', [
                    $courseId,
                    max(1, (int) $partNo),
                    (int) $link['search_id'],
                    (int) $link['search_slot_id'] > 0 ? (int) $link['search_slot_id'] : 'NULL',
                ]));
            $db->setQuery($query)->execute();
        }
    }

    public static function deleteCourseLinks(DatabaseInterface $db, int $courseId): void
    {
        if ($courseId <= 0 || !self::schemaReady($db)) {
            return;
        }
        $db->setQuery(
            $db->getQuery(true)
                ->delete($db->quoteName('#__vigling_course_search_links'))
                ->where($db->quoteName('course_id') . ' = ' . $courseId)
        )->execute();
    }

    /**
     * @return array<string,mixed>|null
     */
    public static function linkedSearch(DatabaseInterface $db, int $courseId, int $partNo): ?array
    {
        $links = self::loadLinks($db, [$courseId]);
        return $links[$courseId][max(1, $partNo)] ?? null;
    }

    /**
     * @return int[]
     */
    public static function linkedCourseSlotIds(DatabaseInterface $db, int $searchId): array
    {
        if ($searchId <= 0 || !self::schemaReady($db)) {
            return [];
        }
        $query = $db->getQuery(true)
            ->select($db->quoteName('cs.id'))
            ->from($db->quoteName('#__vigling_course_search_links', 'l'))
            ->join('INNER', $db->quoteName('#__vigling_course_slots', 'cs') . ' ON ' . $db->quoteName('cs.course_id') . ' = ' . $db->quoteName('l.course_id') . ' AND ' . $db->quoteName('cs.part_no') . ' = ' . $db->quoteName('l.part_no'))
            ->where($db->quoteName('l.search_id') . ' = ' . $searchId)
            ->where($db->quoteName('cs.is_active') . ' = 1');
        $db->setQuery($query);
        return array_map('intval', $db->loadColumn() ?: []);
    }
}
