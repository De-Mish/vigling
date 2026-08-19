<?php
\defined('_JEXEC') or die;

if (!function_exists('viglingOrdersParseIntList')) {
	function viglingOrdersParseIntList(string $raw): array
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
}

if (!function_exists('viglingOrdersParseStringList')) {
	function viglingOrdersParseStringList(string $raw): array
	{
		$raw = trim($raw);
		if ($raw === '') {
			return [];
		}
		$decoded = json_decode($raw, true);
		if (is_array($decoded)) {
			$out = [];
			foreach ($decoded as $item) {
				if (is_scalar($item)) {
					$val = trim((string) $item);
					if ($val !== '') {
						$out[] = $val;
					}
				}
			}
			return $out;
		}
		return [$raw];
	}
}

if (!function_exists('viglingOrdersParseTimeToMinutes')) {
	function viglingOrdersParseTimeToMinutes(string $raw): ?int
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

if (!function_exists('viglingOrdersGetUserTimezone')) {
	function viglingOrdersGetUserTimezone(\Joomla\Database\DatabaseInterface $db, int $userId, string $fallback = 'UTC'): string
	{
		$fallback = trim($fallback) !== '' ? trim($fallback) : 'UTC';
		if ($userId <= 0) {
			return $fallback;
		}
		try {
			$query = $db->getQuery(true)
				->select($db->quoteName('params'))
				->from($db->quoteName('#__users'))
				->where($db->quoteName('id') . ' = ' . (int) $userId);
			$db->setQuery($query);
			$paramsRaw = (string) ($db->loadResult() ?? '');
			$params = json_decode($paramsRaw, true);
			if (is_array($params) && !empty($params['timezone']) && is_string($params['timezone'])) {
				$tz = trim((string) $params['timezone']);
				if ($tz !== '') {
					try {
						new \DateTimeZone($tz);
						return $tz;
					} catch (\Throwable $e) {
					}
				}
			}
		} catch (\Throwable $e) {
		}
		return $fallback;
	}
}

if (!function_exists('viglingOrdersLoadMasterSchedule')) {
	function viglingOrdersLoadMasterSchedule(\Joomla\Database\DatabaseInterface $db, int $masterId): array
	{
		if ($masterId <= 0) {
			return [];
		}
		try {
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

		$days = viglingOrdersParseIntList($raw['work_day']);
		if ($days === []) {
			return [];
		}
		$from = viglingOrdersParseStringList($raw['work_from']);
		$to = viglingOrdersParseStringList($raw['work_to']);

		$fromByDay = array_fill(1, 7, '');
		$toByDay = array_fill(1, 7, '');
		if (count($from) === 1) {
			foreach ($days as $d) {
				$fromByDay[$d] = $from[0];
			}
		} elseif (count($from) === count($days)) {
			foreach ($days as $idx => $d) {
				$fromByDay[$d] = (string) ($from[$idx] ?? '');
			}
		}
		if (count($to) === 1) {
			foreach ($days as $d) {
				$toByDay[$d] = $to[0];
			}
		} elseif (count($to) === count($days)) {
			foreach ($days as $idx => $d) {
				$toByDay[$d] = (string) ($to[$idx] ?? '');
			}
		}

		$result = [];
		foreach ($days as $d) {
			$fromMin = viglingOrdersParseTimeToMinutes((string) ($fromByDay[$d] ?? ''));
			$toMin = viglingOrdersParseTimeToMinutes((string) ($toByDay[$d] ?? ''));
			if ($fromMin === null || $toMin === null || $toMin <= $fromMin) {
				continue;
			}
			$result[$d] = [$fromMin, $toMin];
		}
		return $result;
	}
}

if (!function_exists('viglingOrdersBuildRescheduleSlots')) {
	function viglingOrdersBuildRescheduleSlots(
		\Joomla\Database\DatabaseInterface $db,
		int $masterId,
		int $durationMin,
		int $excludeOrderId = 0,
		int $excludeCourseSlotId = 0,
		int $daysLimit = 45,
		int $excludeSearchSlotId = 0
	): array {
		$durationMin = max(15, min(480, $durationMin));
		$app = \Joomla\CMS\Factory::getApplication();
		$fallbackTz = (string) $app->get('offset', 'UTC');
		$timezoneId = viglingOrdersGetUserTimezone($db, $masterId, $fallbackTz);
		try {
			$masterTz = new \DateTimeZone($timezoneId);
		} catch (\Throwable $e) {
			$masterTz = new \DateTimeZone('UTC');
			$timezoneId = 'UTC';
		}
		$utcTz = new \DateTimeZone('UTC');

		$schedule = viglingOrdersLoadMasterSchedule($db, $masterId);
		if ($schedule === []) {
			return ['timezone' => $timezoneId, 'days' => []];
		}

		$query = $db->getQuery(true)
			->select([$db->quoteName('id'), $db->quoteName('time'), $db->quoteName('time_to')])
			->from($db->quoteName('#__vigling_bookings'))
			->where($db->quoteName('master_id') . ' = ' . (int) $masterId)
			->where($db->quoteName('time_to') . ' >= UTC_TIMESTAMP()');
		if ($excludeOrderId > 0) {
			$query->where($db->quoteName('id') . ' <> ' . (int) $excludeOrderId);
		}
		if ($excludeCourseSlotId > 0) {
			$tableColumns = array_change_key_case($db->getTableColumns('#__vigling_bookings', false), CASE_LOWER);
			if (isset($tableColumns['course_slot_id'], $tableColumns['booking_kind'])) {
				$query->where(
					'NOT ('
					. $db->quoteName('booking_kind') . ' = ' . $db->quote('course')
					. ' AND '
					. $db->quoteName('course_slot_id') . ' = ' . (int) $excludeCourseSlotId
					. ')'
				);
			}
		}
		if ($excludeSearchSlotId > 0) {
			$tableColumns = isset($tableColumns) ? $tableColumns : array_change_key_case($db->getTableColumns('#__vigling_bookings', false), CASE_LOWER);
			if (isset($tableColumns['search_slot_id'], $tableColumns['booking_kind'])) {
				$query->where(
					'NOT ('
					. $db->quoteName('booking_kind') . ' = ' . $db->quote('search')
					. ' AND '
					. $db->quoteName('search_slot_id') . ' = ' . (int) $excludeSearchSlotId
					. ')'
				);
			}
		}
		$db->setQuery($query);
		$rows = $db->loadAssocList() ?: [];

		try {
			$slotQuery = $db->getQuery(true)
				->select([
					$db->quoteName('id'),
					$db->quoteName('starts_at_utc'),
					$db->quoteName('ends_at_utc'),
				])
				->from($db->quoteName('#__vigling_course_slots'))
				->where($db->quoteName('master_id') . ' = ' . (int) $masterId)
				->where($db->quoteName('is_active') . ' = 1');
			if ($excludeCourseSlotId > 0) {
				$slotQuery->where($db->quoteName('id') . ' <> ' . (int) $excludeCourseSlotId);
			}
			$db->setQuery($slotQuery);
			$slotRows = $db->loadAssocList() ?: [];
			foreach ($slotRows as $slotRow) {
				$rows[] = [
					'time' => (string) ($slotRow['starts_at_utc'] ?? ''),
					'time_to' => (string) ($slotRow['ends_at_utc'] ?? ''),
				];
			}
		} catch (\Throwable $e) {
		}

		try {
			$searchSlotQuery = $db->getQuery(true)
				->select([
					$db->quoteName('id'),
					$db->quoteName('starts_at_utc'),
					$db->quoteName('ends_at_utc'),
				])
				->from($db->quoteName('#__vigling_search_slots'))
				->where($db->quoteName('master_id') . ' = ' . (int) $masterId)
				->where($db->quoteName('is_active') . ' = 1');
			if ($excludeSearchSlotId > 0) {
				$searchSlotQuery->where($db->quoteName('id') . ' <> ' . (int) $excludeSearchSlotId);
			}
			$db->setQuery($searchSlotQuery);
			$searchSlotRows = $db->loadAssocList() ?: [];
			foreach ($searchSlotRows as $slotRow) {
				$rows[] = [
					'time' => (string) ($slotRow['starts_at_utc'] ?? ''),
					'time_to' => (string) ($slotRow['ends_at_utc'] ?? ''),
				];
			}
		} catch (\Throwable $e) {
		}

		$bookedByDate = [];
		foreach ($rows as $row) {
			$fromRaw = trim((string) ($row['time'] ?? ''));
			$toRaw = trim((string) ($row['time_to'] ?? ''));
			if ($fromRaw === '') {
				continue;
			}
			try {
				$fromUtc = new \DateTimeImmutable($fromRaw, $utcTz);
				$toUtc = $toRaw !== '' ? new \DateTimeImmutable($toRaw, $utcTz) : $fromUtc->modify('+60 minutes');
			} catch (\Throwable $e) {
				continue;
			}
			$fromLocal = $fromUtc->setTimezone($masterTz);
			$toLocal = $toUtc->setTimezone($masterTz);
			if ($toLocal <= $fromLocal) {
				$toLocal = $fromLocal->modify('+15 minutes');
			}
			$cursor = $fromLocal;
			$lastDay = $toLocal->format('Y-m-d');
			while (true) {
				$dayKey = $cursor->format('Y-m-d');
				$dayStart = new \DateTimeImmutable($dayKey . ' 00:00:00', $masterTz);
				$dayEnd = $dayStart->modify('+1 day');
				$rangeStart = $cursor > $dayStart ? $cursor : $dayStart;
				$rangeEnd = $toLocal < $dayEnd ? $toLocal : $dayEnd;
				if ($rangeEnd > $rangeStart) {
					$startMin = ((int) $rangeStart->format('H')) * 60 + (int) $rangeStart->format('i');
					$endMin = ((int) $rangeEnd->format('H')) * 60 + (int) $rangeEnd->format('i');
					$bookedByDate[$dayKey][] = [$startMin, $endMin];
				}
				if ($dayKey >= $lastDay) {
					break;
				}
				$cursor = $dayStart->modify('+1 day');
			}
		}

		$dowShort = [1 => 'пн.', 2 => 'вт.', 3 => 'ср.', 4 => 'чт.', 5 => 'пт.', 6 => 'сб.', 7 => 'вс.'];
		$resultDays = [];
		$startDay = new \DateTimeImmutable('today', $masterTz);
		$nowUtcTs = (new \DateTimeImmutable('now', $utcTz))->getTimestamp();

		for ($offset = 0; $offset < $daysLimit; $offset++) {
			$currentDay = $startDay->modify('+' . $offset . ' day');
			$dow = (int) $currentDay->format('N');
			$dayRange = $schedule[$dow] ?? null;
			if (!is_array($dayRange)) {
				continue;
			}
			$dayKey = $currentDay->format('Y-m-d');
			$bookedRanges = $bookedByDate[$dayKey] ?? [];
			$daySlots = [];

			for ($minute = (int) $dayRange[0]; $minute <= (int) $dayRange[1]; $minute += 15) {
				$endMinute = $minute + $durationMin;
				if ($endMinute > (int) $dayRange[1]) {
					continue;
				}
				$overlap = false;
				foreach ($bookedRanges as $r) {
					$bStart = (int) ($r[0] ?? 0);
					$bEnd = (int) ($r[1] ?? 0);
					if ($minute < $bEnd && $endMinute > $bStart) {
						$overlap = true;
						break;
					}
				}
				if ($overlap) {
					continue;
				}

				$slotLocal = $currentDay->setTime((int) floor($minute / 60), $minute % 60, 0);
				$slotUtc = $slotLocal->setTimezone($utcTz);
				if ($slotUtc->getTimestamp() <= $nowUtcTs + 60) {
					continue;
				}
				$daySlots[] = [
					'label' => $slotLocal->format('H:i'),
					'utc' => $slotUtc->format(\DateTimeInterface::ATOM),
				];
			}

			if ($daySlots !== []) {
				$resultDays[] = [
					'date' => $dayKey,
					'date_view' => $currentDay->format('d.m.Y'),
					'dow' => $dowShort[$dow] ?? '',
					'slots' => $daySlots,
				];
			}
		}

		return [
			'timezone' => $timezoneId,
			'days' => $resultDays,
		];
	}
}
