<?php

namespace Joomla\Plugin\User\Vigling\Helper;

\defined('_JEXEC') or die;

/**
 * Parses specialist work days and hours from custom-field JSON.
 * This is the schedule class. It is not the site offline page.
 * The offline page is templates/ryba/offline.php.
 * plugins/user/vigling/src/Helper/WorkScheduleHelper.php only loads this file.
 */
final class WorkScheduleHelper
{
    /**
     * @return array<int, array{0:int, 1:int}>
     */
    public static function rangesByDay(string $workDayRaw, string $workFromRaw, string $workToRaw): array
    {
        $parsed = self::timesByDay($workDayRaw, $workFromRaw, $workToRaw);
        $result = [];
        foreach ($parsed['days'] as $wd) {
            $fromMin = self::parseTimeToMinutes((string) ($parsed['from'][$wd] ?? ''));
            $toMin = self::parseTimeToMinutes((string) ($parsed['to'][$wd] ?? ''));
            if ($fromMin === null || $toMin === null || $toMin <= $fromMin) {
                continue;
            }
            $result[$wd] = [$fromMin, $toMin];
        }

        return $result;
    }

    /**
     * @return array{days: int[], from: array<int, string>, to: array<int, string>}
     */
    public static function timesByDay(string $workDayRaw, string $workFromRaw, string $workToRaw): array
    {
        $days = self::decodeDayList($workDayRaw);
        $fromByDay = array_fill(1, 7, '');
        $toByDay = array_fill(1, 7, '');
        self::assignTimes($fromByDay, $days, $workFromRaw);
        self::assignTimes($toByDay, $days, $workToRaw);

        return [
            'days' => $days,
            'from' => $fromByDay,
            'to' => $toByDay,
        ];
    }

    /**
     * @param array<int|string, mixed> $checkedDays
     * @param array<int|string, mixed> $fromByDay
     * @param array<int|string, mixed> $toByDay
     * @return array{days: string[], fromJson: string, toJson: string}
     */
    public static function encodeChecked(array $checkedDays, array $fromByDay, array $toByDay): array
    {
        $wanted = [];
        foreach ($checkedDays as $day) {
            $wd = (int) $day;
            if ($wd >= 1 && $wd <= 7) {
                $wanted[$wd] = true;
            }
        }
        $days = [];
        $from = [];
        $to = [];
        for ($wd = 1; $wd <= 7; $wd++) {
            if (empty($wanted[$wd])) {
                continue;
            }
            $fromVal = self::normalizeClock((string) ($fromByDay[$wd] ?? $fromByDay[(string) $wd] ?? ''));
            $toVal = self::normalizeClock((string) ($toByDay[$wd] ?? $toByDay[(string) $wd] ?? ''));
            if ($fromVal === '' || $toVal === '' || $fromVal >= $toVal) {
                continue;
            }
            $days[] = (string) $wd;
            $from[] = $fromVal;
            $to[] = $toVal;
        }

        return [
            'days' => $days,
            'fromJson' => $from === [] ? '' : json_encode($from, JSON_UNESCAPED_UNICODE),
            'toJson' => $to === [] ? '' : json_encode($to, JSON_UNESCAPED_UNICODE),
        ];
    }

    /**
     * @param array<int, string> $fromByDay
     * @param array<int, string> $toByDay
     * @return array{days: string[], fromJson: string, toJson: string}
     */
    public static function encodeAligned(array $fromByDay, array $toByDay): array
    {
        return self::encodeChecked(array_keys($fromByDay + $toByDay), $fromByDay, $toByDay);
    }

    public static function isTimesJson(string $raw): bool
    {
        $raw = trim($raw);
        if ($raw === '' || ($raw[0] !== '[' && $raw[0] !== '{')) {
            return false;
        }
        $decoded = json_decode($raw, true);

        return is_array($decoded);
    }

    /**
     * SQL fragment: master works on $weekday and $timeCompare is inside that day's hours.
     */
    public static function sqlWorksAt(
        $db,
        string $itemIdSql,
        int $fieldWorkDay,
        int $fieldWorkFrom,
        int $fieldWorkTo,
        int $weekday,
        string $timeCompare,
        string $fieldsTable = '#__fields_values'
    ): string {
        if ($fieldWorkDay <= 0 || $weekday < 1 || $weekday > 7) {
            return '1 = 0';
        }

        $timeQ = $db->quote($timeCompare);
        $fromSql = $fieldWorkFrom > 0
            ? self::sqlExtractDayTime($db, 'wffv.value', 'wdfv.value', $weekday)
            : $db->quote('00:00');
        $toSql = $fieldWorkTo > 0
            ? self::sqlExtractDayTime($db, 'wtfv.value', 'wdfv.value', $weekday)
            : $db->quote('23:59');

        $sql = 'EXISTS (SELECT 1 FROM ' . $db->quoteName($fieldsTable, 'wdfv');
        if ($fieldWorkFrom > 0) {
            $sql .= ' LEFT JOIN ' . $db->quoteName($fieldsTable, 'wffv')
                . ' ON ' . $db->quoteName('wffv.item_id') . ' = ' . $db->quoteName('wdfv.item_id')
                . ' AND ' . $db->quoteName('wffv.field_id') . ' = ' . $fieldWorkFrom;
        }
        if ($fieldWorkTo > 0) {
            $sql .= ' LEFT JOIN ' . $db->quoteName($fieldsTable, 'wtfv')
                . ' ON ' . $db->quoteName('wtfv.item_id') . ' = ' . $db->quoteName('wdfv.item_id')
                . ' AND ' . $db->quoteName('wtfv.field_id') . ' = ' . $fieldWorkTo;
        }
        $sql .= ' WHERE ' . $db->quoteName('wdfv.item_id') . ' = ' . $itemIdSql
            . ' AND ' . $db->quoteName('wdfv.field_id') . ' = ' . $fieldWorkDay
            . ' AND ' . $db->quoteName('wdfv.value') . ' LIKE ' . $db->quote('%"' . $weekday . '"%')
            . ' AND ' . $fromSql . ' IS NOT NULL AND ' . $fromSql . ' <> ' . $db->quote('')
            . ' AND ' . $toSql . ' IS NOT NULL AND ' . $toSql . ' <> ' . $db->quote('')
            . ' AND STR_TO_DATE(REPLACE(' . $fromSql . ', ".", ":"), "%H:%i") <= STR_TO_DATE(' . $timeQ . ', "%H:%i:%s")'
            . ' AND STR_TO_DATE(REPLACE(' . $toSql . ', ".", ":"), "%H:%i") >= STR_TO_DATE(' . $timeQ . ', "%H:%i:%s"))';

        return $sql;
    }

    /**
     * SQL fragment: this weekday is a working day with a real hour range.
     * $endsAfter (H:i:s) keeps a day only when the shift is still open.
     */
    public static function sqlWorksOnWeekday(
        $db,
        string $itemIdSql,
        int $fieldWorkDay,
        int $fieldWorkFrom,
        int $fieldWorkTo,
        int $weekday,
        string $fieldsTable = '#__fields_values',
        string $endsAfter = ''
    ): string {
        if ($fieldWorkDay <= 0 || $weekday < 1 || $weekday > 7) {
            return '1 = 0';
        }

        $fromSql = $fieldWorkFrom > 0
            ? self::sqlExtractDayTime($db, 'wffv.value', 'wdfv.value', $weekday)
            : $db->quote('00:00');
        $toSql = $fieldWorkTo > 0
            ? self::sqlExtractDayTime($db, 'wtfv.value', 'wdfv.value', $weekday)
            : $db->quote('23:59');
        $fromTime = 'STR_TO_DATE(REPLACE(' . $fromSql . ', ".", ":"), "%H:%i")';
        $toTime = 'STR_TO_DATE(REPLACE(' . $toSql . ', ".", ":"), "%H:%i")';

        $sql = 'EXISTS (SELECT 1 FROM ' . $db->quoteName($fieldsTable, 'wdfv');
        if ($fieldWorkFrom > 0) {
            $sql .= ' LEFT JOIN ' . $db->quoteName($fieldsTable, 'wffv')
                . ' ON ' . $db->quoteName('wffv.item_id') . ' = ' . $db->quoteName('wdfv.item_id')
                . ' AND ' . $db->quoteName('wffv.field_id') . ' = ' . $fieldWorkFrom;
        }
        if ($fieldWorkTo > 0) {
            $sql .= ' LEFT JOIN ' . $db->quoteName($fieldsTable, 'wtfv')
                . ' ON ' . $db->quoteName('wtfv.item_id') . ' = ' . $db->quoteName('wdfv.item_id')
                . ' AND ' . $db->quoteName('wtfv.field_id') . ' = ' . $fieldWorkTo;
        }
        $sql .= ' WHERE ' . $db->quoteName('wdfv.item_id') . ' = ' . $itemIdSql
            . ' AND ' . $db->quoteName('wdfv.field_id') . ' = ' . $fieldWorkDay
            . ' AND ' . $db->quoteName('wdfv.value') . ' LIKE ' . $db->quote('%"' . $weekday . '"%')
            . ' AND ' . $fromSql . ' IS NOT NULL AND ' . $fromSql . ' <> ' . $db->quote('')
            . ' AND ' . $toSql . ' IS NOT NULL AND ' . $toSql . ' <> ' . $db->quote('')
            . ' AND ' . $fromTime . ' < ' . $toTime;
        if (preg_match('/^\d{2}:\d{2}:\d{2}$/', $endsAfter)) {
            $sql .= ' AND ' . $toTime . ' >= STR_TO_DATE(' . $db->quote($endsAfter) . ', "%H:%i:%s")';
        }
        $sql .= ')';

        return $sql;
    }

    /**
     * @return array{day: string, time: string}
     */
    public static function splitAvailFilter(string $raw): array
    {
        $raw = str_replace('+', ' ', trim($raw));
        $day = '';
        $time = '';
        if (preg_match('/^(\d{4}-\d{2}-\d{2})(?:[ T](\d{2}:\d{2}))?$/', $raw, $matches)) {
            $day = self::isRealDate($matches[1]) ? $matches[1] : '';
            $time = isset($matches[2]) && self::isClock($matches[2]) ? $matches[2] : '';
        } elseif (preg_match('/^(\d{2}:\d{2})$/', $raw, $matches) && self::isClock($matches[1])) {
            $time = $matches[1];
        }

        return ['day' => $day, 'time' => $time];
    }

    public static function composeAvailFilter(string $dayInput, string $timeInput, string $legacy): string
    {
        $day = '';
        $time = '';
        $dayInput = trim($dayInput);
        $timeInput = trim($timeInput);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $dayInput) && self::isRealDate($dayInput)) {
            $day = $dayInput;
        }
        if (self::isClock($timeInput)) {
            $time = $timeInput;
        }
        if ($day === '' && $time === '') {
            $split = self::splitAvailFilter($legacy);
            $day = $split['day'];
            $time = $split['time'];
        }
        if ($day !== '' && $time !== '') {
            return $day . ' ' . $time;
        }

        return $day !== '' ? $day : $time;
    }

    public static function clockIfDateIsToday(string $day): string
    {
        if (!self::isRealDate($day)) {
            return '';
        }
        $tz = self::siteTimezone();
        $today = (new \DateTimeImmutable('now', $tz))->format('Y-m-d');
        if ($day !== $today) {
            return '';
        }

        return (new \DateTimeImmutable('now', $tz))->format('H:i:s');
    }

    /**
     * The next 45 days, the same horizon as the public booking calendar.
     *
     * @return list<array{date: string, weekday: int, is_today: bool, now: string}>
     */
    public static function upcomingDateRows(int $days = 45): array
    {
        $days = max(1, min(45, $days));
        $tz = self::siteTimezone();
        $start = new \DateTimeImmutable('today', $tz);
        $now = (new \DateTimeImmutable('now', $tz))->format('H:i');
        $rows = [];
        for ($offset = 0; $offset < $days; $offset++) {
            $day = $start->modify('+' . $offset . ' days');
            $rows[] = [
                'date' => $day->format('Y-m-d'),
                'weekday' => (int) $day->format('N'),
                'is_today' => $offset === 0,
                'now' => $now,
            ];
        }

        return $rows;
    }

    /**
     * $andSql receives "Y-m-d H:i:s" and returns an extra SQL predicate.
     * Without it, each weekday is checked once.
     */
    public static function sqlWorksAtOnUpcoming(
        $db,
        string $itemIdSql,
        int $fieldWorkDay,
        int $fieldWorkFrom,
        int $fieldWorkTo,
        string $timeHi,
        string $fieldsTable = '#__fields_values',
        ?callable $andSql = null
    ): string {
        if (!self::isClock($timeHi) || $fieldWorkDay <= 0) {
            return '1 = 0';
        }
        $parts = [];
        $byWeekday = [];
        foreach (self::upcomingDateRows(45) as $row) {
            if ($row['is_today'] && strcmp($timeHi, $row['now']) < 0) {
                continue;
            }
            $works = self::sqlWorksAt(
                $db,
                $itemIdSql,
                $fieldWorkDay,
                $fieldWorkFrom,
                $fieldWorkTo,
                (int) $row['weekday'],
                $timeHi . ':00',
                $fieldsTable
            );
            if ($andSql === null) {
                $byWeekday[(int) $row['weekday']] = $works;
                continue;
            }
            $extra = trim((string) $andSql($row['date'] . ' ' . $timeHi . ':00'));
            if ($extra === '') {
                $parts[] = '(' . $works . ')';
                continue;
            }
            $parts[] = '(' . $works . ' AND ' . $extra . ')';
        }
        if ($andSql === null) {
            $parts = array_values($byWeekday);
        }
        if ($parts === []) {
            return '1 = 0';
        }

        return '(' . implode(' OR ', $parts) . ')';
    }

    private static function siteTimezone(): \DateTimeZone
    {
        $name = 'UTC';
        try {
            $offset = trim((string) \Joomla\CMS\Factory::getApplication()->get('offset', 'UTC'));
            if ($offset !== '') {
                $name = $offset;
            }
        } catch (\Throwable $e) {
        }
        try {
            return new \DateTimeZone($name);
        } catch (\Throwable $e) {
            return new \DateTimeZone('UTC');
        }
    }

    private static function isRealDate(string $day): bool
    {
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $day);

        return $parsed instanceof \DateTimeImmutable && $parsed->format('Y-m-d') === $day;
    }

    private static function isClock(string $time): bool
    {
        if (!preg_match('/^(\d{2}):(\d{2})$/', $time, $matches)) {
            return false;
        }

        return (int) $matches[1] <= 23 && (int) $matches[2] <= 59;
    }

    public static function ensureLoaded(): void
    {
        // Class is already loaded when this method runs.
    }

    private static function sqlExtractDayTime($db, string $timeValueSql, string $daysValueSql, int $weekday): string
    {
        $dayQ = $db->quote((string) $weekday);
        $pathQ = $db->quote('$."' . $weekday . '"');

        return '(CASE'
            . ' WHEN ' . $timeValueSql . ' IS NULL OR TRIM(' . $timeValueSql . ') = ' . $db->quote('') . ' THEN NULL'
            . ' WHEN TRIM(' . $timeValueSql . ') LIKE ' . $db->quote('{%')
            . ' THEN JSON_UNQUOTE(JSON_EXTRACT(' . $timeValueSql . ', ' . $pathQ . '))'
            . ' WHEN TRIM(' . $timeValueSql . ') LIKE ' . $db->quote('[%')
            . ' AND JSON_LENGTH(' . $timeValueSql . ') = 1'
            . ' THEN JSON_UNQUOTE(JSON_EXTRACT(' . $timeValueSql . ', ' . $db->quote('$[0]') . '))'
            . ' WHEN TRIM(' . $timeValueSql . ') LIKE ' . $db->quote('[%')
            . ' AND JSON_SEARCH(' . $daysValueSql . ', ' . $db->quote('one') . ', ' . $dayQ . ') IS NOT NULL'
            . ' THEN JSON_UNQUOTE(JSON_EXTRACT(' . $timeValueSql . ', JSON_UNQUOTE(JSON_SEARCH(' . $daysValueSql . ', ' . $db->quote('one') . ', ' . $dayQ . '))))'
            . ' ELSE TRIM(' . $timeValueSql . ')'
            . ' END)';
    }

    /**
     * @param array<int, string> $target
     * @param int[] $days
     */
    private static function assignTimes(array &$target, array $days, string $raw): void
    {
        $raw = trim($raw);
        if ($raw === '') {
            return;
        }
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            if (self::isDayMap($decoded)) {
                foreach ($decoded as $key => $val) {
                    $wd = (int) $key;
                    if ($wd >= 1 && $wd <= 7 && is_scalar($val)) {
                        $target[$wd] = self::normalizeClock((string) $val);
                    }
                }

                return;
            }
            $list = [];
            foreach ($decoded as $item) {
                if (is_scalar($item)) {
                    $val = self::normalizeClock((string) $item);
                    if ($val !== '') {
                        $list[] = $val;
                    }
                }
            }
            if (count($list) === 1) {
                foreach ($days as $wd) {
                    $target[$wd] = $list[0];
                }

                return;
            }
            if (count($list) === count($days)) {
                foreach ($days as $idx => $wd) {
                    $target[$wd] = (string) ($list[$idx] ?? '');
                }
            }

            return;
        }
        $single = self::normalizeClock($raw);
        if ($single === '') {
            return;
        }
        foreach ($days as $wd) {
            $target[$wd] = $single;
        }
    }

    /**
     * @param array<mixed> $decoded
     */
    private static function isDayMap(array $decoded): bool
    {
        if ($decoded === []) {
            return false;
        }
        foreach (array_keys($decoded) as $key) {
            if (!is_scalar($key) || !preg_match('/^[1-7]$/', (string) $key)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return int[]
     */
    private static function decodeDayList(string $raw): array
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
        $vals = array_values(array_unique(array_filter($vals, static function ($v) {
            return $v >= 1 && $v <= 7;
        })));
        sort($vals);

        return $vals;
    }

    public static function normalizeClock(string $raw): string
    {
        $raw = trim(str_replace('.', ':', $raw));
        if ($raw === '') {
            return '';
        }
        if (preg_match('/^(\d{1,2}):(\d{2})$/', $raw, $m)) {
            return sprintf('%02d', (int) $m[1]) . ':' . $m[2];
        }

        return '';
    }

    /**
     * Snap a datetime to the nearest 15-minute mark used by bookings and the schedule.
     */
    public static function snapToQuarterHour(string $value): string
    {
        $value = trim(str_replace('T', ' ', $value));
        if ($value === '' || !preg_match('/^(\d{4}-\d{2}-\d{2}) (\d{2}):(\d{2})(?::(\d{2}))?$/', $value, $m)) {
            return '';
        }

        try {
            $dt = new \DateTimeImmutable($m[1] . ' ' . $m[2] . ':' . $m[3] . ':00', new \DateTimeZone('UTC'));
        } catch (\Throwable $e) {
            return '';
        }

        return self::snapDateTimeToQuarterHour($dt)->format('Y-m-d H:i:s');
    }

    public static function snapDateTimeToQuarterHour(\DateTimeImmutable $dt): \DateTimeImmutable
    {
        $totalMin = ((int) $dt->format('H')) * 60 + (int) $dt->format('i');
        $snapped = (int) round($totalMin / 15) * 15;
        if ($snapped >= 24 * 60) {
            return $dt->modify('+1 day')->setTime(0, 0, 0);
        }

        return $dt->setTime(intdiv($snapped, 60), $snapped % 60, 0);
    }

    public static function parseTimeToMinutes(string $raw): ?int
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
            $m = max(0, min(59, $m));
            $h = max(0, min(23, $h));

            return $h * 60 + $m;
        }

        return null;
    }
}
