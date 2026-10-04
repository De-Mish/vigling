<?php

namespace Viglin\Component\Orders\Site\Helper;

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\Database\DatabaseInterface;

/**
 * 45-day public booking calendar. Built on demand when the booking dialog opens.
 */
class PublicBookingCalendarHelper
{
	/**
	 * @return array{days: array<int, array<string, mixed>>, has_schedule: bool}
	 */
	public static function build(int $masterId, int $viewerId): array
	{
		if ($masterId <= 0) {
			return ['days' => [], 'has_schedule' => false];
		}

		$db = Factory::getContainer()->get(DatabaseInterface::class);
		$ranges = self::workRanges($db, $masterId);
		$hasSchedule = false;
		foreach ($ranges as $range) {
			if (is_array($range)) {
				$hasSchedule = true;
				break;
			}
		}

		return [
			'days' => self::buildDays($db, $masterId, $viewerId, $ranges, self::masterTimezone($db, $masterId)),
			'has_schedule' => $hasSchedule,
		];
	}

	/**
	 * @return array<int, array{0:int,1:int}|null>
	 */
	private static function workRanges(DatabaseInterface $db, int $masterId): array
	{
		$ranges = array_fill(1, 7, null);
		$scheduleFile = JPATH_SITE . '/components/com_orders/tmpl/orders/_reschedule_helper.php';
		if (is_file($scheduleFile)) {
			require_once $scheduleFile;
		}
		if (!function_exists('viglingOrdersLoadMasterSchedule')) {
			return $ranges;
		}

		try {
			$parsed = viglingOrdersLoadMasterSchedule($db, $masterId);
		} catch (\Throwable $e) {
			return $ranges;
		}
		for ($wd = 1; $wd <= 7; $wd++) {
			if (isset($parsed[$wd]) && is_array($parsed[$wd])) {
				$ranges[$wd] = [(int) $parsed[$wd][0], (int) $parsed[$wd][1]];
			}
		}

		return $ranges;
	}

	private static function masterTimezone(DatabaseInterface $db, int $masterId): \DateTimeZone
	{
		$app = Factory::getApplication();
		$siteOffset = (string) $app->get('offset', 'UTC');
		$tz = new \DateTimeZone($siteOffset !== '' ? $siteOffset : 'UTC');
		try {
			$query = $db->getQuery(true)
				->select($db->quoteName('params'))
				->from($db->quoteName('#__users'))
				->where($db->quoteName('id') . ' = ' . (int) $masterId);
			$db->setQuery($query);
			$params = json_decode((string) ($db->loadResult() ?? ''), true);
			if (is_array($params) && !empty($params['timezone']) && is_string($params['timezone'])) {
				$candidate = trim($params['timezone']);
				if ($candidate !== '') {
					$tz = new \DateTimeZone($candidate);
				}
			}
		} catch (\Throwable $e) {
		}

		return $tz;
	}

	/**
	 * @param array<int, array{0:int,1:int}|null> $workRangeByDay
	 * @return array<int, array<string, mixed>>
	 */
	private static function buildDays(
		DatabaseInterface $db,
		int $masterId,
		int $viewerId,
		array $workRangeByDay,
		\DateTimeZone $masterTz
	): array {
		$bookedRangesByDate = [];
		$ownerBlockRangesByDate = [];
		$reservedRangesByDate = [];
		$anytimeOccupancyByDate = [];
		$viewerIsProfileOwner = $viewerId > 0 && $viewerId === $masterId;
		$utcTz = new \DateTimeZone('UTC');
		$dowShort = [1 => 'пн.', 2 => 'вт.', 3 => 'ср.', 4 => 'чт.', 5 => 'пт.', 6 => 'сб.', 7 => 'вс.'];

		try {
			$calendarUntilUtc = (new \DateTimeImmutable('today', $masterTz))
				->modify('+46 days')
				->setTimezone($utcTz)
				->format('Y-m-d H:i:s');
			self::loadBookings(
				$db,
				$masterId,
				$calendarUntilUtc,
				$utcTz,
				$masterTz,
				$bookedRangesByDate,
				$ownerBlockRangesByDate,
				$anytimeOccupancyByDate
			);
			self::loadOfferSlots($db, $masterId, $calendarUntilUtc, $utcTz, $masterTz, '#__vigling_course_slots', 'course', $reservedRangesByDate);
			self::loadOfferSlots($db, $masterId, $calendarUntilUtc, $utcTz, $masterTz, '#__vigling_search_slots', 'search', $reservedRangesByDate);
		} catch (\Throwable $e) {
			$bookedRangesByDate = [];
			$ownerBlockRangesByDate = [];
			$reservedRangesByDate = [];
			$anytimeOccupancyByDate = [];
		}

		$calendarDays = [];
		$startDay = new \DateTimeImmutable('today', $masterTz);
		for ($dayOffset = 0; $dayOffset < 45; $dayOffset++) {
			$currentDay = $startDay->modify('+' . $dayOffset . ' day');
			$dow = (int) $currentDay->format('N');
			$dateKey = $currentDay->format('Y-m-d');
			$slots = [];
			$slotUtcByTime = [];
			$slotMinutes = [];
			$slotReservedByTime = [];
			$slotOwnerBlockByTime = [];
			$slotAnytimeByTime = [];
			$range = $workRangeByDay[$dow] ?? null;
			if (is_array($range)) {
				$dayBookedRanges = $bookedRangesByDate[$dateKey] ?? [];
				$dayOwnerBlocks = $ownerBlockRangesByDate[$dateKey] ?? [];
				$dayOfferRanges = $reservedRangesByDate[$dateKey] ?? [];
				$dayAnytimeGroups = $anytimeOccupancyByDate[$dateKey] ?? [];
				for ($minute = (int) $range[0]; $minute <= (int) $range[1]; $minute += 15) {
					$isBooked = false;
					foreach ($dayBookedRanges as $bookedRange) {
						$bookedStart = (int) ($bookedRange[0] ?? 0);
						$bookedEnd = (int) ($bookedRange[1] ?? 0);
						if ($minute >= $bookedStart && $minute < $bookedEnd) {
							$isBooked = true;
							break;
						}
					}
					if ($isBooked) {
						continue;
					}
					$isOwnerBlock = false;
					foreach ($dayOwnerBlocks as $blockRange) {
						$blockStart = (int) ($blockRange[0] ?? 0);
						$blockEnd = (int) ($blockRange[1] ?? 0);
						if ($minute >= $blockStart && $minute < $blockEnd) {
							$isOwnerBlock = true;
							break;
						}
					}
					if ($isOwnerBlock && !$viewerIsProfileOwner) {
						continue;
					}
					$offerKind = '';
					foreach ($dayOfferRanges as $offerRange) {
						$offerStart = (int) ($offerRange[0] ?? 0);
						$offerEnd = (int) ($offerRange[1] ?? 0);
						if ($minute >= $offerStart && $minute < $offerEnd) {
							$kind = strtolower(trim((string) ($offerRange[2] ?? '')));
							$offerKind = ($kind === 'search') ? 'search' : 'course';
							break;
						}
					}
					if ($offerKind !== '' && !$isOwnerBlock) {
						continue;
					}
					$slotLabel = self::formatMinutes($minute);
					$slotDateTimeLocal = $currentDay->setTime((int) floor($minute / 60), $minute % 60, 0);
					$slotUtcByTime[$slotLabel] = $slotDateTimeLocal->setTimezone($utcTz)->format(\DateTimeInterface::ATOM);
					$slots[] = $slotLabel;
					$slotMinutes[] = (int) $minute;
					if ($isOwnerBlock) {
						$slotOwnerBlockByTime[$slotLabel] = '1';
					}
					foreach ($dayAnytimeGroups as $anytimeGroup) {
						$anytimeStart = (int) ($anytimeGroup['start'] ?? 0);
						$anytimeEnd = (int) ($anytimeGroup['end'] ?? 0);
						if ($minute < $anytimeStart || $minute >= $anytimeEnd) {
							continue;
						}
						$originStart = (int) ($anytimeGroup['origin_start'] ?? $anytimeStart);
						$slotAnytimeByTime[$slotLabel] = [
							'course_id' => (int) ($anytimeGroup['course_id'] ?? 0),
							'used' => max(0, (int) ($anytimeGroup['used'] ?? 0)),
							'max' => max(1, (int) ($anytimeGroup['max'] ?? 1)),
							'cover' => $minute !== $originStart,
						];
						break;
					}
				}
			}
			$calendarDays[] = [
				'date' => $dateKey,
				'date_view' => $currentDay->format('d.m.Y'),
				'dow' => $dowShort[$dow] ?? '',
				'slots' => $slots,
				'slot_utc' => $slotUtcByTime,
				'slot_minutes' => $slotMinutes,
				'slot_reserved' => $slotReservedByTime,
				'slot_owner_block' => $slotOwnerBlockByTime,
				'slot_anytime' => $slotAnytimeByTime,
				'range_from_min' => is_array($range) ? (int) $range[0] : null,
				'range_to_min' => is_array($range) ? (int) $range[1] : null,
			];
		}

		return $calendarDays;
	}

	private static function loadBookings(
		DatabaseInterface $db,
		int $masterId,
		string $calendarUntilUtc,
		\DateTimeZone $utcTz,
		\DateTimeZone $masterTz,
		array &$bookedRangesByDate,
		array &$ownerBlockRangesByDate,
		array &$anytimeOccupancyByDate
	): void {
		try {
			$tableColumns = array_change_key_case($db->getTableColumns('#__vigling_bookings', false), CASE_LOWER);
			$hasBookingKind = isset($tableColumns['booking_kind']);
			$hasCourseId = isset($tableColumns['course_id']);
			$hasCourseSlotId = isset($tableColumns['course_slot_id']);
			$hasServiceName = isset($tableColumns['service_name']);
			$hasTimeSum = isset($tableColumns['time_sum']);
			$selectCols = [$db->quoteName('time'), $db->quoteName('time_to')];
			if ($hasTimeSum) {
				$selectCols[] = $db->quoteName('time_sum');
			}
			if ($hasBookingKind) {
				$selectCols[] = $db->quoteName('booking_kind');
			}
			if ($hasServiceName) {
				$selectCols[] = $db->quoteName('service_name');
			}
			if ($hasCourseId) {
				$selectCols[] = $db->quoteName('course_id');
			}
			if ($hasCourseSlotId) {
				$selectCols[] = $db->quoteName('course_slot_id');
			}
			$query = $db->getQuery(true)
				->select($selectCols)
				->from($db->quoteName('#__vigling_bookings'))
				->where($db->quoteName('master_id') . ' = ' . (int) $masterId)
				->where($db->quoteName('time') . ' < ' . $db->quote($calendarUntilUtc));
			if ($hasTimeSum) {
				$query->where(
					'('
					. $db->quoteName('time_to') . ' >= UTC_TIMESTAMP() OR DATE_ADD('
					. $db->quoteName('time') . ', INTERVAL ' . $db->quoteName('time_sum') . ' MINUTE) >= UTC_TIMESTAMP())'
				);
			} else {
				$query->where($db->quoteName('time_to') . ' >= UTC_TIMESTAMP()');
			}
			$db->setQuery($query);
			$rows = $db->loadAssocList() ?: [];
			$anytimeGroupsRaw = [];
			foreach ($rows as $row) {
				$bookingKind = $hasBookingKind ? strtolower(trim((string) ($row['booking_kind'] ?? ''))) : '';
				$serviceName = $hasServiceName ? trim((string) ($row['service_name'] ?? '')) : '';
				$isOwnerBlock = $bookingKind === 'journal' || strpos($serviceName, '[journal]') === 0;
				$occupiedTo = self::effectiveEndRaw(
					(string) ($row['time'] ?? ''),
					(string) ($row['time_to'] ?? ''),
					$hasTimeSum ? (int) ($row['time_sum'] ?? 0) : 0
				);
				if ($isOwnerBlock) {
					self::appendUtcRangeToLocalDays(
						$ownerBlockRangesByDate,
						(string) ($row['time'] ?? ''),
						$occupiedTo,
						$utcTz,
						$masterTz
					);
					continue;
				}
				if ($bookingKind === 'search') {
					self::appendUtcRangeToLocalDays(
						$bookedRangesByDate,
						(string) ($row['time'] ?? ''),
						$occupiedTo,
						$utcTz,
						$masterTz
					);
					continue;
				}
				if ($bookingKind === 'course') {
					$courseSlotId = $hasCourseSlotId ? (int) ($row['course_slot_id'] ?? 0) : 0;
					if ($courseSlotId > 0) {
						self::appendUtcRangeToLocalDays(
							$bookedRangesByDate,
							(string) ($row['time'] ?? ''),
							$occupiedTo,
							$utcTz,
							$masterTz
						);
						continue;
					}
					$courseId = $hasCourseId ? (int) ($row['course_id'] ?? 0) : 0;
					if ($courseId <= 0) {
						continue;
					}
					$startRaw = trim((string) ($row['time'] ?? ''));
					$groupKey = $courseId . '|' . $startRaw;
					if (!isset($anytimeGroupsRaw[$groupKey])) {
						$anytimeGroupsRaw[$groupKey] = [
							'course_id' => $courseId,
							'time' => $startRaw,
							'time_to' => $occupiedTo,
							'used' => 0,
						];
					} elseif (strcmp($occupiedTo, (string) $anytimeGroupsRaw[$groupKey]['time_to']) > 0) {
						$anytimeGroupsRaw[$groupKey]['time_to'] = $occupiedTo;
					}
					$anytimeGroupsRaw[$groupKey]['used']++;
					continue;
				}
				self::appendUtcRangeToLocalDays(
					$bookedRangesByDate,
					(string) ($row['time'] ?? ''),
					$occupiedTo,
					$utcTz,
					$masterTz
				);
			}
			if ($anytimeGroupsRaw === []) {
				return;
			}
			$anytimeCourseIds = [];
			foreach ($anytimeGroupsRaw as $group) {
				$anytimeCourseIds[(int) $group['course_id']] = true;
			}
			$anytimeCourseIds = array_keys($anytimeCourseIds);
			$concurrentByCourse = [];
			try {
				$hasConcurrentCol = class_exists('\\Joomla\\Plugin\\User\\Vigling\\Service\\UserCoursesService')
					&& \Joomla\Plugin\User\Vigling\Service\UserCoursesService::ensureConcurrentParticipantsColumn($db);
				$courseSelect = [
					$db->quoteName('id'),
					$db->quoteName('capacity'),
				];
				if ($hasConcurrentCol) {
					$courseSelect[] = $db->quoteName('concurrent_participants');
				}
				$courseQuery = $db->getQuery(true)
					->select($courseSelect)
					->from($db->quoteName('#__vigling_user_courses'))
					->whereIn($db->quoteName('id'), $anytimeCourseIds);
				$db->setQuery($courseQuery);
				$courseRows = $db->loadAssocList() ?: [];
				foreach ($courseRows as $courseRow) {
					$cid = (int) ($courseRow['id'] ?? 0);
					if ($cid <= 0) {
						continue;
					}
					$capacity = max(1, (int) ($courseRow['capacity'] ?? 1));
					$concurrent = $hasConcurrentCol ? max(1, (int) ($courseRow['concurrent_participants'] ?? 1)) : 1;
					if ($concurrent > $capacity) {
						$concurrent = $capacity;
					}
					$concurrentByCourse[$cid] = $concurrent;
				}
			} catch (\Throwable $ignore) {
			}
			foreach ($anytimeGroupsRaw as $group) {
				$cid = (int) ($group['course_id'] ?? 0);
				$used = max(0, (int) ($group['used'] ?? 0));
				$max = max(1, (int) ($concurrentByCourse[$cid] ?? 1));
				self::appendAnytimeOccupancyToLocalDays(
					$anytimeOccupancyByDate,
					(string) ($group['time'] ?? ''),
					(string) ($group['time_to'] ?? ''),
					$utcTz,
					$masterTz,
					$cid,
					$used,
					$max
				);
			}
		} catch (\Throwable $ignore) {
		}
	}

	private static function loadOfferSlots(
		DatabaseInterface $db,
		int $masterId,
		string $calendarUntilUtc,
		\DateTimeZone $utcTz,
		\DateTimeZone $masterTz,
		string $table,
		string $kind,
		array &$reservedRangesByDate
	): void {
		try {
			$query = $db->getQuery(true)
				->select([$db->quoteName('starts_at_utc'), $db->quoteName('ends_at_utc')])
				->from($db->quoteName($table))
				->where($db->quoteName('master_id') . ' = ' . (int) $masterId)
				->where($db->quoteName('is_active') . ' = 1')
				->where($db->quoteName('ends_at_utc') . ' >= UTC_TIMESTAMP()')
				->where($db->quoteName('starts_at_utc') . ' < ' . $db->quote($calendarUntilUtc));
			$db->setQuery($query);
			foreach (($db->loadAssocList() ?: []) as $slotRow) {
				self::appendUtcRangeToLocalDays(
					$reservedRangesByDate,
					(string) ($slotRow['starts_at_utc'] ?? ''),
					(string) ($slotRow['ends_at_utc'] ?? ''),
					$utcTz,
					$masterTz,
					$kind
				);
			}
		} catch (\Throwable $ignore) {
		}
	}

	/**
	 * Service length is time..time_to. time_sum is service plus the configured break.
	 * The break stays occupied so the next client cannot start inside it.
	 */
	private static function effectiveEndRaw(string $fromRaw, string $toRaw, int $timeSumMin): string
	{
		$fromRaw = trim($fromRaw);
		$toRaw = trim($toRaw);
		if ($fromRaw === '' || $timeSumMin <= 0) {
			return $toRaw;
		}
		try {
			$utc = new \DateTimeZone('UTC');
			$from = new \DateTimeImmutable($fromRaw, $utc);
			$to = $toRaw !== '' ? new \DateTimeImmutable($toRaw, $utc) : $from->modify('+60 minutes');
		} catch (\Throwable $e) {
			return $toRaw;
		}
		$durationMin = (int) floor(($to->getTimestamp() - $from->getTimestamp()) / 60);
		if ($timeSumMin <= $durationMin) {
			return $to->format('Y-m-d H:i:s');
		}

		return $from->modify('+' . min(480, $timeSumMin) . ' minutes')->format('Y-m-d H:i:s');
	}

	private static function appendUtcRangeToLocalDays(
		array &$target,
		string $fromRaw,
		string $toRaw,
		\DateTimeZone $utcTz,
		\DateTimeZone $masterTz,
		string $kind = ''
	): void {
		$fromRaw = trim($fromRaw);
		$toRaw = trim($toRaw);
		if ($fromRaw === '') {
			return;
		}
		try {
			$fromUtc = new \DateTimeImmutable($fromRaw, $utcTz);
			$toUtc = $toRaw !== '' ? new \DateTimeImmutable($toRaw, $utcTz) : $fromUtc->modify('+60 minutes');
		} catch (\Throwable $e) {
			return;
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
				$startMinutes = ((int) $rangeStart->format('H')) * 60 + (int) $rangeStart->format('i');
				$endMinutes = ((int) $rangeEnd->format('H')) * 60 + (int) $rangeEnd->format('i');
				$entry = [$startMinutes, $endMinutes];
				if ($kind !== '') {
					$entry[] = $kind;
				}
				$target[$dayKey][] = $entry;
			}
			if ($dayKey >= $lastDay) {
				break;
			}
			$cursor = $dayStart->modify('+1 day');
		}
	}

	private static function appendAnytimeOccupancyToLocalDays(
		array &$target,
		string $fromRaw,
		string $toRaw,
		\DateTimeZone $utcTz,
		\DateTimeZone $masterTz,
		int $courseId,
		int $used,
		int $max
	): void {
		$fromRaw = trim($fromRaw);
		$toRaw = trim($toRaw);
		if ($fromRaw === '' || $courseId <= 0) {
			return;
		}
		try {
			$fromUtc = new \DateTimeImmutable($fromRaw, $utcTz);
			$toUtc = $toRaw !== '' ? new \DateTimeImmutable($toRaw, $utcTz) : $fromUtc->modify('+60 minutes');
		} catch (\Throwable $e) {
			return;
		}
		$fromLocal = $fromUtc->setTimezone($masterTz);
		$toLocal = $toUtc->setTimezone($masterTz);
		if ($toLocal <= $fromLocal) {
			$toLocal = $fromLocal->modify('+15 minutes');
		}

		$originStartMin = ((int) $fromLocal->format('H')) * 60 + (int) $fromLocal->format('i');
		$originDayKey = $fromLocal->format('Y-m-d');
		$cursor = $fromLocal;
		$lastDay = $toLocal->format('Y-m-d');
		while (true) {
			$dayKey = $cursor->format('Y-m-d');
			$dayStart = new \DateTimeImmutable($dayKey . ' 00:00:00', $masterTz);
			$dayEnd = $dayStart->modify('+1 day');
			$rangeStart = $cursor > $dayStart ? $cursor : $dayStart;
			$rangeEnd = $toLocal < $dayEnd ? $toLocal : $dayEnd;
			if ($rangeEnd > $rangeStart) {
				$startMinutes = ((int) $rangeStart->format('H')) * 60 + (int) $rangeStart->format('i');
				$endMinutes = ((int) $rangeEnd->format('H')) * 60 + (int) $rangeEnd->format('i');
				$target[$dayKey][] = [
					'start' => $startMinutes,
					'end' => $endMinutes,
					'course_id' => $courseId,
					'used' => $used,
					'max' => $max,
					'origin_start' => $dayKey === $originDayKey ? $originStartMin : -1,
				];
			}
			if ($dayKey >= $lastDay) {
				break;
			}
			$cursor = $dayStart->modify('+1 day');
		}
	}

	private static function formatMinutes(int $minutes): string
	{
		$h = (int) floor($minutes / 60);
		$m = $minutes % 60;

		return sprintf('%02d:%02d', $h, $m);
	}
}