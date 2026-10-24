<?php

defined('_JEXEC') or die;

use Joomla\CMS\Factory;

class PoiskHelperAvailability
{
    public static function isMasterAvailable($userId, $dateTime, $fields = null)
    {
        if (empty($dateTime)) {
            return true;
        }

        $db = Factory::getDbo();

        if ($fields === null) {
            $fields = self::getMasterFields($userId);
        }

        $dateObj = new \DateTime($dateTime);
        $dayOfWeek = $dateObj->format('N');

        $workingDays = self::getWorkingDays($fields);
        if (!in_array($dayOfWeek, $workingDays)) {
            return false;
        }

        $selectedTime = $dateObj->format('H:i');
        $workStart = self::getWorkStart($fields);
        $workEnd = self::getWorkEnd($fields);

        if (!self::isTimeInRange($selectedTime, $workStart, $workEnd)) {
            return false;
        }

        if (!self::isTimeMultipleOf15($selectedTime)) {
            return false;
        }

        return true;
    }

    protected static function getWorkingDays($fields)
    {
        $daysJson = $fields['85'] ?? '[]';
        $days = json_decode($daysJson, true);
        return is_array($days) ? array_map('intval', $days) : [];
    }

    protected static function getWorkStart($fields)
    {
        $start = $fields['86'] ?? '9.00';
        return str_replace('.', ':', $start);
    }

    protected static function getWorkEnd($fields)
    {
        $end = $fields['87'] ?? '17.00';
        return str_replace('.', ':', $end);
    }

    protected static function getMasterFields($userId)
    {
        $db = Factory::getDbo();
        $query = $db->getQuery(true)
            ->select(['field_id', 'value'])
            ->from($db->quoteName('#__fields_values'))
            ->where($db->quoteName('item_id') . ' = ' . (int)$userId)
            ->where($db->quoteName('field_id') . ' IN (85, 86, 87)');

        $db->setQuery($query);
        $results = $db->loadObjectList();

        $fields = [];
        foreach ($results as $row) {
            $fields[$row->field_id] = $row->value;
        }

        return $fields;
    }

    protected static function isTimeInRange($time, $start, $end)
    {
        $timeMinutes = self::timeToMinutes($time);
        $startMinutes = self::timeToMinutes($start);
        $endMinutes = self::timeToMinutes($end);

        return $timeMinutes >= $startMinutes && $timeMinutes <= $endMinutes;
    }

    protected static function timeToMinutes($time)
    {
        $parts = explode(':', $time);
        if (count($parts) < 2) {
            return 0;
        }
        return (int)$parts[0] * 60 + (int)$parts[1];
    }

    protected static function isTimeMultipleOf15($time)
    {
        $minutes = self::timeToMinutes($time);
        return $minutes % 15 === 0;
    }

    public static function getMasterWorkData($userId)
    {
        $fields = self::getMasterFields($userId);
        return [
            'work_days' => self::getWorkingDays($fields),
            'work_start' => self::getWorkStart($fields),
            'work_end' => self::getWorkEnd($fields)
        ];
    }
}