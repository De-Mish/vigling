<?php
defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Helper\ModuleHelper;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Session\Session;
use Joomla\CMS\Uri\Uri;
use Joomla\Database\DatabaseInterface;
use Joomla\Component\Fields\Administrator\Helper\FieldsHelper;

$app = Factory::getApplication();
$currentUser = $app->getIdentity();
$profileOwnerId = (int) ($this->data->id ?? 0);
$isLkEmbed = !empty($this->lkEmbed);

$jcfields = [];
if (!empty($this->data->jcfields)) {
	foreach ($this->data->jcfields as $f) {
		if (isset($f->name)) {
			$jcfields[$f->name] = $f;
		}
	}
}
if (empty($jcfields) && !empty($this->data->id)) {
	$fields = FieldsHelper::getFields('com_users.user', $this->data, true);
	if (!empty($fields)) {
		foreach ($fields as $f) {
			if (isset($f->name)) {
				$jcfields[$f->name] = $f;
			}
		}
	}
}

$fieldValue = static function (array $fields, string $name): string {
	if (!isset($fields[$name])) {
		return '';
	}
	$v = '';
	if (isset($fields[$name]->rawvalue) && is_scalar($fields[$name]->rawvalue)) {
		$v = (string) $fields[$name]->rawvalue;
	} elseif (isset($fields[$name]->value) && is_scalar($fields[$name]->value)) {
		$v = (string) $fields[$name]->value;
	}
	return trim($v);
};

$decodeScalar = static function (string $raw): string {
	$raw = trim($raw);
	if ($raw === '') {
		return '';
	}
	$current = $raw;
	for ($i = 0; $i < 3; $i++) {
		$decoded = json_decode($current, true);
		if (is_string($decoded)) {
			$current = trim($decoded);
			continue;
		}
		break;
	}
	return trim($current);
};

$profileData = [];
if (isset($this->data->profile) && is_array($this->data->profile)) {
	$profileData = $this->data->profile;
} elseif (isset($this->data->profile) && is_object($this->data->profile)) {
	$profileData = (array) $this->data->profile;
}

$profileValue = static function (array $data, string $key): string {
	if (!array_key_exists($key, $data)) {
		return '';
	}
	$val = $data[$key];
	if (is_array($val) || is_object($val)) {
		return '';
	}
	return trim((string) $val);
};

$parseIntList = static function (string $raw): array {
	$raw = trim($raw);
	if ($raw === '') {
		return [];
	}
	$decoded = json_decode($raw, true);
	$vals = [];
	if (is_array($decoded)) {
		$iter = new RecursiveIteratorIterator(new RecursiveArrayIterator($decoded));
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
		return $v > 0;
	})));
	return $vals;
};

$rawSpeciality = $fieldValue($jcfields, 'vyberite_spetsialnos');
$specialityIds = $parseIntList($rawSpeciality);
$specialityTitles = [];
if ($specialityIds !== []) {
	try {
		$db = Factory::getContainer()->get(\Joomla\Database\DatabaseInterface::class);
		$q = $db->getQuery(true)
			->select([$db->quoteName('id'), $db->quoteName('title')])
			->from($db->quoteName('#__categories'))
			->where($db->quoteName('id') . ' IN (' . implode(',', array_map('intval', $specialityIds)) . ')')
			->order($db->quoteName('title') . ' ASC');
		$db->setQuery($q);
		$rows = $db->loadAssocList() ?: [];
		foreach ($rows as $row) {
			$specialityTitles[] = (string) ($row['title'] ?? '');
		}
	} catch (\Throwable $e) {
		$specialityTitles = [];
	}
}
$specialityText = $specialityTitles !== [] ? implode(', ', array_filter($specialityTitles)) : '';

$aboutText = $decodeScalar($fieldValue($jcfields, 'about'));
if ($aboutText === '') {
	$aboutText = $decodeScalar($fieldValue($jcfields, 'o_sebe'));
}
if ($aboutText === '' && $specialityText !== '') {
	$aboutText = $specialityText;
}
$city = $fieldValue($jcfields, 'sity');
$area = $fieldValue($jcfields, 'area');
$street = $fieldValue($jcfields, 'street');
$house = $fieldValue($jcfields, 'house_number');
$phone = $fieldValue($jcfields, 'telefon');
$vk = $fieldValue($jcfields, 'link');
$telegram = $fieldValue($jcfields, 'telegram');
$max = $fieldValue($jcfields, 'max');
$city = $city !== '' ? $city : $profileValue($profileData, 'city');
$area = $area !== '' ? $area : $profileValue($profileData, 'region');
$street = $street !== '' ? $street : $profileValue($profileData, 'address1');
$house = $house !== '' ? $house : $profileValue($profileData, 'address2');
$phone = $phone !== '' ? $phone : $profileValue($profileData, 'phone');
$vk = $vk !== '' ? $vk : $profileValue($profileData, 'website');
$socialLinks = [];
if ($vk !== '') {
	$socialLinks[] = ['icon' => 'vk.svg', 'label' => 'Вконтакте', 'url' => $vk];
}
if ($telegram !== '') {
	$socialLinks[] = ['icon' => 'telegram.svg', 'label' => 'Телеграм', 'url' => $telegram];
}
if ($max !== '') {
	$socialLinks[] = ['icon' => 'max.svg', 'label' => 'Макс', 'url' => $max];
}
$addr = implode(', ', array_filter([$city, $area, $street, $house]));
$mapAddressCandidates = array_values(array_unique(array_filter([
	implode(', ', array_filter([$city, $area, $street, $house])),
	implode(', ', array_filter([$city, $street, $house])),
	implode(', ', array_filter([$city, $area])),
	$city,
], static function ($value) {
	return trim((string) $value) !== '';
})));
if ($mapAddressCandidates === []) {
	$mapAddressCandidates = ['Москва'];
}

$homeText = $fieldValue($jcfields, 'home');
$homeParts = [];
if ($homeText !== '') {
	$decoded = json_decode($homeText, true);
	$labels = [1 => 'Салон', 2 => 'Вызов на дом', 3 => 'Мастер на дому'];
	if (is_array($decoded)) {
		foreach ($decoded as $h) {
			$h = (int) $h;
			if (isset($labels[$h])) {
				$homeParts[] = $labels[$h];
			}
		}
	}
	if ($homeParts === []) {
		// Fallback: if value is already a human-readable label.
		$plainHome = $decodeScalar($homeText);
		if ($plainHome !== '' && !preg_match('/^\[.*\]$/', $plainHome)) {
			$homeParts[] = $plainHome;
		}
	}
}
$homeDisplay = $homeParts !== [] ? implode(', ', $homeParts) : '';

$workDayRaw = $fieldValue($jcfields, 'work_day');
$workFromRaw = $fieldValue($jcfields, 'work_from');
$workToRaw = $fieldValue($jcfields, 'work_to');
$workDayLabels = [1 => 'Понедельник', 2 => 'Вторник', 3 => 'Среда', 4 => 'Четверг', 5 => 'Пятница', 6 => 'Суббота', 7 => 'Воскресенье'];
$workRows = [];
$workDays = $parseIntList($workDayRaw);
$workFrom = json_decode($workFromRaw, true);
$workTo = json_decode($workToRaw, true);
if (!is_array($workFrom)) {
	$tmp = trim($workFromRaw);
	$workFrom = $tmp !== '' ? [$tmp] : [];
}
if (!is_array($workTo)) {
	$tmp = trim($workToRaw);
	$workTo = $tmp !== '' ? [$tmp] : [];
}
$workFrom = array_values(array_map('trim', array_map('strval', $workFrom)));
$workTo = array_values(array_map('trim', array_map('strval', $workTo)));

if ($workDays !== []) {
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

	foreach ($workDayLabels as $wd => $label) {
		if (!in_array($wd, $workDays, true)) {
			continue;
		}
		$from = trim((string) ($fromByDay[$wd] ?? ''));
		$to = trim((string) ($toByDay[$wd] ?? ''));
		$time = ($from !== '' || $to !== '') ? ($from . ' - ' . $to) : '—';
		$workRows[] = [$label, $time];
	}
}

$workRowsByLabel = [];
foreach ($workRows as $row) {
	$workRowsByLabel[(string) ($row[0] ?? '')] = (string) ($row[1] ?? '—');
}
$workScheduleRows = [];
foreach ($workDayLabels as $wd => $label) {
	$time = $workRowsByLabel[$label] ?? 'Выходной';
	if ($time === '' || $time === '— - —') {
		$time = 'Выходной';
	}
	$workScheduleRows[] = [$label, $time];
}

$parseScheduleTimeToMinutes = static function (string $raw): ?int {
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
		if ($m < 0) {
			$m = 0;
		}
		if ($m > 59) {
			$m = 59;
		}
		$h = max(0, min(23, $h));
		return $h * 60 + $m;
	}
	return null;
};

$formatMinutes = static function (int $minutes): string {
	$h = (int) floor($minutes / 60);
	$m = $minutes % 60;
	return sprintf('%02d:%02d', $h, $m);
};

$workRangeByDay = array_fill(1, 7, null);
foreach ($workDayLabels as $wd => $label) {
	$fromRaw = trim((string) (($fromByDay[$wd] ?? '')));
	$toRaw = trim((string) (($toByDay[$wd] ?? '')));
	$fromMin = $parseScheduleTimeToMinutes($fromRaw);
	$toMin = $parseScheduleTimeToMinutes($toRaw);
	if ($fromMin === null || $toMin === null || $toMin <= $fromMin) {
		continue;
	}
	$workRangeByDay[$wd] = [$fromMin, $toMin];
}

$calendarDays = [];
$bookedRangesByDate = [];
$siteOffset = (string) $app->get('offset', 'UTC');
$masterTz = new \DateTimeZone($siteOffset !== '' ? $siteOffset : 'UTC');
$utcTz = new \DateTimeZone('UTC');
$dowShort = [1 => 'пн.', 2 => 'вт.', 3 => 'ср.', 4 => 'чт.', 5 => 'пт.', 6 => 'сб.', 7 => 'вс.'];

if ($profileOwnerId > 0) {
	try {
		$db = Factory::getContainer()->get(DatabaseInterface::class);
		$userParamsQuery = $db->getQuery(true)
			->select($db->quoteName('params'))
			->from($db->quoteName('#__users'))
			->where($db->quoteName('id') . ' = ' . (int) $profileOwnerId);
		$db->setQuery($userParamsQuery);
		$userParamsRaw = (string) ($db->loadResult() ?? '');
		$userParams = json_decode($userParamsRaw, true);
		if (is_array($userParams) && !empty($userParams['timezone']) && is_string($userParams['timezone'])) {
			$tzCandidate = trim((string) $userParams['timezone']);
			if ($tzCandidate !== '') {
				try {
					$masterTz = new \DateTimeZone($tzCandidate);
				} catch (\Throwable $ignore) {
				}
			}
		}
		$table = $db->replacePrefix('#__vigling_bookings');
		$db->setQuery('SHOW TABLES LIKE ' . $db->quote($table));
		if ($db->loadResult()) {
			$query = $db->getQuery(true)
				->select([$db->quoteName('time'), $db->quoteName('time_to')])
				->from($db->quoteName('#__vigling_bookings'))
				->where($db->quoteName('master_id') . ' = ' . (int) $profileOwnerId)
				->where($db->quoteName('time_to') . ' >= UTC_TIMESTAMP()');
			$db->setQuery($query);
			$rows = $db->loadAssocList() ?: [];
			foreach ($rows as $row) {
				$timeFromRaw = trim((string) ($row['time'] ?? ''));
				$timeToRaw = trim((string) ($row['time_to'] ?? ''));
				if ($timeFromRaw === '') {
					continue;
				}
				$fromUtc = new \DateTimeImmutable($timeFromRaw, $utcTz);
				$toUtc = $timeToRaw !== '' ? new \DateTimeImmutable($timeToRaw, $utcTz) : $fromUtc->modify('+60 minutes');
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
						$bookedRangesByDate[$dayKey][] = [$startMinutes, $endMinutes];
					}
					if ($dayKey >= $lastDay) {
						break;
					}
					$cursor = $dayStart->modify('+1 day');
				}
			}
		}
	} catch (\Throwable $e) {
		$bookedRangesByDate = [];
	}
}

$startDay = new \DateTimeImmutable('today', $masterTz);
for ($dayOffset = 0; $dayOffset < 45; $dayOffset++) {
	$currentDay = $startDay->modify('+' . $dayOffset . ' day');
	$dow = (int) $currentDay->format('N');
	$dateKey = $currentDay->format('Y-m-d');
	$slots = [];
	$slotUtcByTime = [];
	$slotMinutes = [];
	$range = $workRangeByDay[$dow] ?? null;
	if (is_array($range)) {
		$dayBookedRanges = $bookedRangesByDate[$dateKey] ?? [];
		for ($minute = (int) $range[0]; $minute <= (int) $range[1]; $minute += 15) {
			$isReserved = false;
			foreach ($dayBookedRanges as $bookedRange) {
				$bookedStart = (int) ($bookedRange[0] ?? 0);
				$bookedEnd = (int) ($bookedRange[1] ?? 0);
				if ($minute >= $bookedStart && $minute < $bookedEnd) {
					$isReserved = true;
					break;
				}
			}
			if (!$isReserved) {
				$slotLabel = $formatMinutes($minute);
				$slotDateTimeLocal = $currentDay->setTime((int) floor($minute / 60), $minute % 60, 0);
				$slotUtcByTime[$slotLabel] = $slotDateTimeLocal->setTimezone($utcTz)->format(\DateTimeInterface::ATOM);
				$slots[] = $slotLabel;
				$slotMinutes[] = (int) $minute;
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
		'range_from_min' => is_array($range) ? (int) $range[0] : null,
		'range_to_min' => is_array($range) ? (int) $range[1] : null,
	];
}

$calendarDaysJson = json_encode($calendarDays, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

$displayName = trim((string) ($this->data->name ?? ''));
if ($displayName === '') {
	$displayName = trim((string) ($this->data->username ?? 'Мастер'));
}

$avatarRaw = $fieldValue($jcfields, 'avatar');
$resolveProfileImage = static function (string $rawValue): string {
	$rawValue = trim($rawValue);
	if ($rawValue === '') {
		return '';
	}
	$decoded = json_decode($rawValue, true);
	if (is_string($decoded)) {
		$rawValue = trim($decoded);
	} elseif (is_array($decoded)) {
		$rawValue = trim((string) reset($decoded));
	}
	if ($rawValue === '') {
		return '';
	}
	if (stripos($rawValue, 'http://') === 0 || stripos($rawValue, 'https://') === 0) {
		return $rawValue;
	}
	$clean = str_replace('\\', '/', ltrim($rawValue, '/'));
	if (preg_match('#^(portfolio|portfolio/|images/portfolio|images/portfolio/)$#i', $clean)) {
		return '';
	}
	if (stripos($clean, 'images/profiler/') === 0) {
		return rtrim(Uri::root(), '/') . '/' . $clean;
	}
	if (stripos($clean, 'images/portfolio/') === 0) {
		return rtrim(Uri::root(), '/') . '/' . $clean;
	}
	if (stripos($clean, 'portfolio/') === 0) {
		return rtrim(Uri::root(), '/') . '/images/' . $clean;
	}
	if (preg_match('/^portfolio_field/i', $clean)) {
		return rtrim(Uri::root(), '/') . '/images/portfolio/' . $clean;
	}
	if (stripos($clean, 'images/') === 0) {
		return rtrim(Uri::root(), '/') . '/' . $clean;
	}
	return rtrim(Uri::root(), '/') . '/images/profiler/' . $clean;
};

$parseImageList = static function (string $rawValue, callable $resolver): array {
	$rawValue = trim($rawValue);
	if ($rawValue === '') {
		return [];
	}
	$decoded = json_decode($rawValue, true);
	$candidate = [];
	if (is_array($decoded)) {
		$iter = new RecursiveIteratorIterator(new RecursiveArrayIterator($decoded));
		foreach ($iter as $v) {
			if (is_scalar($v)) {
				$candidate[] = (string) $v;
			}
		}
	} else {
		$candidate[] = $rawValue;
	}
	$result = [];
	foreach ($candidate as $item) {
		$url = $resolver($item);
		if ($url === '' || str_ends_with(strtolower($url), '/true')) {
			continue;
		}
		$result[] = $url;
	}
	return array_values(array_unique($result));
};

$avatarUrl = $resolveProfileImage($avatarRaw);
$defaultImg = Uri::root() . 'templates/ryba/images/master.png';
if (!is_file(JPATH_ROOT . '/templates/ryba/images/master.png')) {
	$defaultImg = Uri::root() . 'components/com_jsn/assets/img/default.jpg';
}
$avatarPreviewUrl = $avatarUrl !== '' ? $avatarUrl : $defaultImg;

$portfolioRaw = $fieldValue($jcfields, 'portfolio_field');
$portfolioImages = $parseImageList($portfolioRaw, $resolveProfileImage);
if ($portfolioImages === [] && $avatarUrl !== '') {
	$portfolioImages[] = $avatarUrl;
}
if ($portfolioImages === []) {
	$portfolioImages[] = $defaultImg;
}
$portfolioCountTotal = count($portfolioImages);
$profileShortText = $specialityText !== '' ? $specialityText : $aboutText;

$pricesStructuredWithIds = [];
if ($profileOwnerId > 0 && class_exists('\\Joomla\\Plugin\\User\\Vigling\\Helper\\JsnDecodeHelper')) {
	$pricesStructuredWithIds = \Joomla\Plugin\User\Vigling\Helper\JsnDecodeHelper::getUserServicesStructuredWithIds($profileOwnerId);
}
$filterCatId = (int) $app->getInput()->getUint('cat_id', 0);
$filterServiceId = (int) $app->getInput()->getUint('service', 0);
$filterTagId = (int) $app->getInput()->getUint('tag', 0);
$highlightSearchService = $filterCatId > 0 && $filterServiceId > 0 && $filterTagId > 0;
$stockPricesStructuredWithIds = [];
if ($profileOwnerId > 0 && class_exists('\\Joomla\\Plugin\\User\\Vigling\\Helper\\JsnDecodeHelper')) {
	$stockPricesStructuredWithIds = \Joomla\Plugin\User\Vigling\Helper\JsnDecodeHelper::getUserStockServicesStructuredWithIds($profileOwnerId);
}
$coursesStructured = [];
if ($profileOwnerId > 0 && class_exists('\\Joomla\\Plugin\\User\\Vigling\\Service\\UserCoursesService')) {
	$coursesStructured = \Joomla\Plugin\User\Vigling\Service\UserCoursesService::getUserCoursesStructured($profileOwnerId);
}
$searchesStructured = [];
if ($profileOwnerId > 0 && class_exists('\\Joomla\\Plugin\\User\\Vigling\\Service\\UserSearchesService')) {
	$searchesStructured = \Joomla\Plugin\User\Vigling\Service\UserSearchesService::getUserSearchesStructured($profileOwnerId);
}
$currentUserCourseBookings = [];
$currentUserCourseSlotBookings = [];
if ((int) $currentUser->id > 0 && !empty($coursesStructured)) {
	$courseIds = array_values(array_filter(array_map(static fn($course): int => (int) ($course['id'] ?? 0), (array) $coursesStructured)));
	if ($courseIds !== []) {
		try {
			$db = Factory::getContainer()->get(DatabaseInterface::class);
			$tableColumns = array_change_key_case($db->getTableColumns('#__vigling_bookings', false), CASE_LOWER);
			if (isset($tableColumns['booking_kind'], $tableColumns['course_id'], $tableColumns['course_slot_id'])) {
				$query = $db->getQuery(true)
					->select([
						$db->quoteName('course_id'),
						$db->quoteName('course_slot_id'),
					])
					->from($db->quoteName('#__vigling_bookings'))
					->where($db->quoteName('user_id') . ' = ' . (int) $currentUser->id)
					->where($db->quoteName('booking_kind') . ' = ' . $db->quote('course'))
					->whereIn($db->quoteName('course_id'), $courseIds);
				$db->setQuery($query);
				foreach ((array) ($db->loadAssocList() ?: []) as $bookingRow) {
					$bookedCourseId = (int) ($bookingRow['course_id'] ?? 0);
					$bookedSlotId = (int) ($bookingRow['course_slot_id'] ?? 0);
					if ($bookedCourseId > 0) {
						$currentUserCourseBookings[$bookedCourseId] = true;
					}
					if ($bookedSlotId > 0) {
						$currentUserCourseSlotBookings[$bookedSlotId] = true;
					}
				}
			}
		} catch (\Throwable $e) {
			$currentUserCourseBookings = [];
			$currentUserCourseSlotBookings = [];
		}
	}
}
$currentUserSearchBookings = [];
$currentUserSearchSlotBookings = [];
if ((int) $currentUser->id > 0 && !empty($searchesStructured)) {
	$searchIds = array_values(array_filter(array_map(static fn($search): int => (int) ($search['id'] ?? 0), (array) $searchesStructured)));
	if ($searchIds !== []) {
		try {
			$db = Factory::getContainer()->get(DatabaseInterface::class);
			$tableColumns = array_change_key_case($db->getTableColumns('#__vigling_bookings', false), CASE_LOWER);
			if (isset($tableColumns['booking_kind'], $tableColumns['search_id'], $tableColumns['search_slot_id'])) {
				$query = $db->getQuery(true)
					->select([
						$db->quoteName('search_id'),
						$db->quoteName('search_slot_id'),
					])
					->from($db->quoteName('#__vigling_bookings'))
					->where($db->quoteName('user_id') . ' = ' . (int) $currentUser->id)
					->where($db->quoteName('booking_kind') . ' = ' . $db->quote('search'))
					->whereIn($db->quoteName('search_id'), $searchIds);
				$db->setQuery($query);
				foreach ((array) ($db->loadAssocList() ?: []) as $bookingRow) {
					$bookedSearchId = (int) ($bookingRow['search_id'] ?? 0);
					$bookedSlotId = (int) ($bookingRow['search_slot_id'] ?? 0);
					if ($bookedSearchId > 0) {
						$currentUserSearchBookings[$bookedSearchId] = true;
					}
					if ($bookedSlotId > 0) {
						$currentUserSearchSlotBookings[$bookedSlotId] = true;
					}
				}
			}
		} catch (\Throwable $e) {
			$currentUserSearchBookings = [];
			$currentUserSearchSlotBookings = [];
		}
	}
}

$pushnotifyTokenName = Session::getFormToken();
$pushnotifyTokenValue = '1';

$bookingServices = $masterServicesList ?? [];
if (empty($bookingServices) && !empty($pricesStructuredWithIds) && is_array($pricesStructuredWithIds)) {
	foreach ($pricesStructuredWithIds as $cat) {
		$catTitle = trim((string) ($cat['title'] ?? ''));
		foreach ((array) ($cat['items'] ?? []) as $it) {
			$sid = (string) ($it['svc_id'] ?? '');
			$name = trim((string) ($it['name'] ?? ''));
			if ($sid === '') {
				continue;
			}
			if ($name === '') {
				$name = 'Услуга #' . $sid;
			}
			$bookingServices[$sid] = $catTitle !== '' ? ($catTitle . ' — ' . $name) : $name;
		}
	}
}

$reviewsModules = ModuleHelper::getModules('master_reviews');
$reviewsHtml = '';
if (!empty($reviewsModules)) {
	foreach ($reviewsModules as $module) {
		$reviewsHtml .= ModuleHelper::renderModule($module, ['style' => 'none']);
	}
}

$isFavorite = false;
if ((int) $currentUser->id > 0 && $profileOwnerId > 0 && (int) $currentUser->id !== $profileOwnerId) {
	try {
		$db = Factory::getContainer()->get(DatabaseInterface::class);
		$table = $db->replacePrefix('#__vigling_user_favorites');
		$db->setQuery('SHOW TABLES LIKE ' . $db->quote($table));
		if ($db->loadResult()) {
			$query = $db->getQuery(true)
				->select('1')
				->from($db->quoteName('#__vigling_user_favorites'))
				->where($db->quoteName('user_id') . ' = ' . (int) $currentUser->id)
				->where($db->quoteName('master_id') . ' = ' . $profileOwnerId);
			$db->setQuery($query);
			$isFavorite = (bool) $db->loadResult();
		}
	} catch (\Throwable $e) {
		$isFavorite = false;
	}
}
?>

<div<?php echo $isLkEmbed ? '' : ' id="easyprofile"'; ?> class="view_profile view_profile-public<?php echo $isLkEmbed ? ' view_profile-public--embed' : ''; ?>">
	<div class="masters__big-img-cont col-md-6">
		<div class="arrows_master-slider">
			<button type="button" class="my-slick-prev slick-arrow"><i class="fa fa-angle-left" aria-hidden="true"></i></button>
			<button type="button" class="my-slick-next slick-arrow"><i class="fa fa-angle-right" aria-hidden="true"></i></button>
		</div>
		<div class="masters__big-img">
			<?php foreach ($portfolioImages as $imageUrl) : ?>
				<div style="background-image: url('<?php echo $this->escape($imageUrl); ?>'); width: 100%; display: inline-block;" class="masters__big-img-item"></div>
			<?php endforeach; ?>
		</div>
	</div>
	<div class="masters__big-info col-md-6">
		<div class="masters__big-info-head">
			<div class="masters__big-info-head-master" style="background-image: url('<?php echo $this->escape($avatarPreviewUrl); ?>'); background-size: cover;">
				<span class="masters__big-info-head-master-online"></span>
			</div>
			<h3 class="h3biginfo"><?php echo $this->escape($displayName); ?></h3>
			<?php if ((int) $currentUser->id !== $profileOwnerId) : ?>
			<a id="bookmarkme" class="<?php echo $isFavorite ? 'active' : ''; ?>" href="#" data-id="<?php echo $profileOwnerId; ?>" title="Добавить в избранное"></a>
			<?php endif; ?>
			<div class="clearFloat"></div>
		</div>
		<div class="masters__big-info-attr">
			<h3 class="h3biginfo1"><?php echo $this->escape($displayName); ?></h3>
			<div class="masters__attr-left">
				<?php if ($profileShortText !== '') : ?><span class="attr_left1"><?php echo $this->escape($profileShortText); ?></span><?php endif; ?>
				<?php if ($addr !== '') : ?><span class="attr_left2"><i class="fa fa-map-marker" aria-hidden="true"></i><?php echo $this->escape($addr); ?></span><?php endif; ?>
				<?php if ($homeDisplay !== '') : ?><span class="attr_left3">Форма работы: <b><?php echo $this->escape($homeDisplay); ?></b></span><?php endif; ?>
			</div>
			<div class="masters__attr-right">
				<span class="attr-rating">5.0</span>
				<div class="attr-div-rating">
					<ul class="category_cinfo-ratings" style="display:none">
						<li><i class="fa fa-star" aria-hidden="true"></i></li>
						<li><i class="fa fa-star" aria-hidden="true"></i></li>
						<li><i class="fa fa-star" aria-hidden="true"></i></li>
						<li><i class="fa fa-star" aria-hidden="true"></i></li>
						<li><i class="fa fa-star-half" aria-hidden="true"></i></li>
					</ul>
					<span style="display:none"></span>
				</div>
				<div class="clearFloat"></div>
			</div>
			<div class="clearFloat"></div>
		</div>
		<div class="masters__gall-small">
			<span class="masters__gall-small-count"><i>Еще <?php echo (int) $portfolioCountTotal; ?><br> фотографий</i></span>
			<div class="masters__small-img">
				<?php foreach ($portfolioImages as $imageUrl) : ?>
					<div style="background-image: url('<?php echo $this->escape($imageUrl); ?>'); width: 100%; display: inline-block;" class="masters__small-img-item"></div>
				<?php endforeach; ?>
			</div>
			<div class="clearFloat"></div>
		</div>
	</div>
	<div class="clearFloat"></div>
</div>

<section class="master__services master__services--fullbleed">
	<div class="container">
		<span class="req__info">Выберите услуги, нажав на кнопку +</span>
		<style>
		.master__services--fullbleed {
			max-width: none !important;
			margin-left: calc(50% - 50vw);
			margin-right: calc(50% - 50vw);
		}
		.master__services--fullbleed > .container {
			max-width: 1170px;
			margin-left: auto;
			margin-right: auto;
		}
		.review__master--fullbleed {
			max-width: none !important;
			margin-left: calc(50% - 50vw);
			margin-right: calc(50% - 50vw);
		}
		.review__master--fullbleed > .container {
			max-width: 1170px;
			margin-left: auto;
			margin-right: auto;
		}
		.master__services .accordionItemContent {
			display: block !important;
			max-height: none !important;
			opacity: 1 !important;
			overflow: visible !important;
		}
		.master__services .accordionItemHeading {
			cursor: default !important;
			color: #000;
			font-family: "GothamPro-Bold";
			font-size: 24px;
			font-weight: 700;
			line-height: 54px;
			text-transform: uppercase;
			letter-spacing: .6px;
			margin-bottom: 8px;
		}
		.master__services .accordionItemHeading:after {
			content: "" !important;
			width: 21px;
			height: 2px;
			background-color: #f7cc53;
			display: block !important;
			margin-top: 0;
			margin-left: 0;
			position: static !important;
			top: auto !important;
			right: auto !important;
			float: none !important;
			transform: none !important;
			border-radius: 0 !important;
			box-shadow: none !important;
			border: 0 !important;
			background-image: none !important;
			line-height: 0 !important;
			padding: 0 !important;
		}
		.master__services .accordionItem.opened > .accordionItemHeading:after,
		.master__services .accordionItem.closed > .accordionItemHeading:after {
			content: "" !important;
			width: 21px !important;
			height: 2px !important;
			background-color: #f7cc53 !important;
			display: block !important;
			margin-top: 0 !important;
			margin-left: 0 !important;
			position: static !important;
			top: auto !important;
			right: auto !important;
			float: none !important;
			transform: none !important;
			border-radius: 0 !important;
			box-shadow: none !important;
			border: 0 !important;
			background-image: none !important;
			line-height: 0 !important;
			padding: 0 !important;
		}
		#zapis .screen3 .calc__body {
			max-width: 860px;
			margin: 0 auto;
		}
		#zapis .screen3 .calc__body h2 { margin-bottom: 28px; }
		#zapis .screen3 .form__finish {
			padding: 0;
			max-width: 760px;
			margin: 0 auto;
			display: flex;
			flex-direction: column;
			gap: 22px;
		}
		#zapis .screen3 .auth-step-switch {
			display: flex;
			justify-content: center;
		}
		#zapis .screen3 .auth-step-switch ul {
			list-style: none;
			margin: 0 0 24px;
			padding: 0;
			display: flex;
			align-items: center;
			justify-content: center;
			gap: 12px;
			flex-wrap: wrap;
		}
		#zapis .screen3 .auth-step-switch li {
			margin: 0 !important;
		}
		#zapis .screen3 .auth-step-tab {
			min-width: 140px;
			height: 42px;
			border: 1px solid #111;
			border-radius: 21px;
			background: #fff;
			padding: 0 20px;
			font-family: "GothamPro-Medium";
			font-size: 18px;
			line-height: 40px;
			text-align: center;
			cursor: pointer;
			transition: background-color .2s ease, border-color .2s ease;
		}
		#zapis .screen3 .auth-step-tab.active {
			background: #f9ce54;
			border-color: #f9ce54;
		}
		#zapis .screen3 .js-auth-tab input[type="text"],
		#zapis .screen3 .js-auth-tab input[type="email"],
		#zapis .screen3 .js-auth-tab input[type="password"] {
			width: 100%;
			box-sizing: border-box;
		}
		#zapis .screen3 .js-auth-register,
		#zapis .screen3 .js-auth-login {
			display: grid;
			gap: 14px 20px;
			align-items: start;
		}
		#zapis .screen3 .js-auth-register {
			grid-template-columns: repeat(2, minmax(0, 1fr));
		}
		#zapis .screen3 .js-auth-login {
			grid-template-columns: repeat(2, minmax(0, 1fr));
		}
		#zapis .screen3 .js-auth-register .row-full,
		#zapis .screen3 .js-auth-register .phone-prefix-wrap,
		#zapis .screen3 .js-auth-register .email-control {
			grid-column: 1 / -1;
		}
		#zapis .screen3 .js-auth-register .form__finish-left,
		#zapis .screen3 .js-auth-register .form__finish-right,
		#zapis .screen3 .js-auth-login .form__finish-left,
		#zapis .screen3 .js-auth-login .form__finish-right {
			width: 100% !important;
			float: none !important;
			margin: 0 !important;
			min-width: 0;
		}
		#zapis .screen3 .row-full {
			width: 100% !important;
			float: none !important;
		}
		#zapis .screen3 .js-auth-tab .clearFloat {
			display: none;
		}
		#zapis .screen3 .js-auth-register .controls,
		#zapis .screen3 .js-auth-login .controls {
			margin: 0 !important;
			min-width: 0;
		}
		#zapis .screen3 .auth-col-full {
			width: 100%;
			float: none;
		}
		#zapis .screen3 .email-control input {
			display: block;
			width: 100%;
			height: 48px;
			border: 1px solid #1d1d1d;
			border-radius: 16px;
			padding: 0 20px;
			background: #fff;
			box-sizing: border-box;
		}
		#zapis .screen3 .phone-prefix-wrap span {
			display: flex;
			align-items: center;
			justify-content: flex-start;
			line-height: 1;
			white-space: nowrap;
			flex: 0 0 74px;
			min-width: 74px;
			margin-top: 0 !important;
		}
		#zapis .screen3 .phone-prefix-wrap {
			display: flex;
			align-items: center;
			gap: 18px;
		}
		#zapis .screen3 .phone-prefix-wrap input {
			flex: 1 1 auto;
			min-width: 0;
			width: auto !important;
		}
		#zapis .screen3 .phone-prefix-wrap span img {
			margin-left: 6px;
		}
		#zapis .screen3 .email-note {
			margin-top: 8px;
			font-size: 13px;
			line-height: 1.25;
			color: #777;
		}
		#zapis .screen3 .error-msg {
			margin-top: 8px;
		}
		#zapis.modal {
			padding-right: 0 !important;
		}
		body.modal-open {
			padding-right: 0 !important;
		}
		#zapis .modal-dialog {
			margin: 30px auto !important;
			left: auto !important;
			right: auto !important;
		}
		#zapis .screen3 .password-field-wrap {
			position: relative;
			width: 100%;
			display: block;
			min-height: 48px;
		}
		#zapis .screen3 .password-field-wrap input {
			display: block;
			width: 100% !important;
			padding-right: 122px;
		}
		#zapis .screen3 .password-toggle {
			position: absolute;
			top: 24px;
			right: 20px;
			transform: translateY(-50%);
			border: 0;
			padding: 0;
			background: transparent;
			font-size: 14px;
			font-weight: 600;
			line-height: 1;
			color: #1d1d1d;
			cursor: pointer;
			white-space: nowrap;
			z-index: 2;
		}
		#zapis .screen3 .password-toggle:focus {
			outline: none;
			color: #000;
		}
		#zapis .screen3 .field-error {
			border-color: #d93c3c !important;
			box-shadow: 0 0 0 1px rgba(217, 60, 60, 0.25);
		}
		#zapis .screen3 .calc__btn {
			display: flex;
			align-items: center;
			justify-content: center;
			gap: 16px;
			flex-wrap: nowrap;
			padding-left: 0 !important;
			overflow: visible;
		}
		#zapis .screen3 .calc__btn .btn-next {
			display: inline-flex;
			align-items: center;
			justify-content: center;
			width: auto;
			min-width: 240px;
			padding: 0 28px;
			margin: 0 !important;
			float: none;
		}
		#zapis .screen3 .calc__btn .close__btn {
			margin-top: 0;
			float: none;
		}
		#zapis .screen4 .calc__btn {
			display: flex;
			align-items: center;
			justify-content: center;
			gap: 18px;
			flex-wrap: wrap;
		}
		#zapis .screen4 #zapis__add-calendar,
		#zapis .screen4 #zapis__enable-notify,
		#zapis .screen4 .btn-fin {
			display: inline-flex;
			align-items: center;
			justify-content: center;
			min-width: 260px;
			padding: 0 24px;
			height: 56px;
			line-height: 1;
			box-sizing: border-box;
			text-align: center;
			white-space: nowrap;
			font-size: 20px;
			text-transform: none;
		}
		#zapis .screen4 #zapis__add-calendar {
			border: 0;
			cursor: pointer;
		}
		#zapis .screen4 #zapis__enable-notify {
			border: 1px solid #f9ce54;
			background: #fff;
			color: #000;
			cursor: pointer;
			border-radius: 28px;
			font-family: "GothamPro-Medium";
			font-weight: 500;
		}
		#zapis .calc__btn .btn-next.is-loading {
			pointer-events: none;
			opacity: .9;
		}
		#zapis .calc__btn .btn-next .btn-spinner {
			display: none;
			width: 14px;
			height: 14px;
			margin-right: 8px;
			border: 2px solid rgba(0,0,0,.25);
			border-top-color: #000;
			border-radius: 50%;
			animation: bookingSpin .75s linear infinite;
			vertical-align: middle;
		}
		#zapis .calc__btn .btn-next.is-loading .btn-spinner {
			display: inline-block;
		}
		.master__services .courseList__item {
			display: grid !important;
			grid-template-columns: minmax(140px, 180px) minmax(0, 1fr) auto;
			gap: 8px 18px;
			align-items: start;
			margin: 0 0 18px !important;
			padding: 18px !important;
			border-radius: 18px;
			background: #fff;
			box-shadow: 0 10px 26px rgba(0, 0, 0, 0.08);
			box-sizing: border-box;
		}
		.master__services .courseList__item:last-child {
			margin-bottom: 0 !important;
		}
		.master__services .courseList__item .stockList__item-coll {
			float: none !important;
			width: auto !important;
			max-width: none !important;
			margin: 0 !important;
			padding: 0 !important;
			line-height: 1.35;
			box-sizing: border-box;
		}
		.master__services .courseList__item .course__coll0 {
			grid-row: 1 / span 7;
			width: 100% !important;
		}
		.master__services .courseList__item .course__coll0 img {
			display: block;
			width: 100% !important;
			max-width: 180px !important;
			aspect-ratio: 4 / 3;
			object-fit: cover;
			border-radius: 14px !important;
		}
		.master__services .courseList__item .stock__coll1 {
			font-family: "GothamPro-Bold", sans-serif;
			font-size: 18px;
			line-height: 1.2;
		}
		.master__services .courseList__item .stock__coll2 {
			color: #555;
			overflow-wrap: anywhere;
		}
		.master__services .courseList__item .stock__coll4 {
			font-family: "GothamPro-Bold", sans-serif;
			font-size: 16px;
		}
		.master__services .courseList__item .course__seats {
			font-family: "GothamPro-Medium", sans-serif;
			color: #333;
		}
		.master__services .courseList__item .course__seats.is-full {
			color: #9b1c1c;
		}
		.master__services .courseList__item .plus-course {
			grid-column: 3;
			grid-row: 1 / span 3;
			justify-self: end;
			align-self: start;
			margin: 0 !important;
		}
		.master__services .courseList__item .plus-course.is-disabled,
		.master__services .courseList__item .plus-course:disabled {
			opacity: .45;
			cursor: not-allowed;
			pointer-events: none;
		}
		.master__services .courseList__item .clearFloat,
		.master__services .courseList__item br {
			display: none !important;
		}
		@keyframes bookingSpin {
			to { transform: rotate(360deg); }
		}
		@media (max-width: 820px) {
			.master__services .courseList__item {
				grid-template-columns: 1fr;
				gap: 10px;
				margin-bottom: 18px !important;
				padding: 14px !important;
			}
			.master__services .courseList__item .course__coll0 {
				grid-row: auto;
			}
			.master__services .courseList__item .course__coll0 img {
				max-width: 100% !important;
				width: 100% !important;
			}
			.master__services .courseList__item .plus-course {
				grid-column: auto;
				grid-row: auto;
				justify-self: start;
				margin-top: 6px !important;
			}
			#zapis .modal-dialog {
				width: min(96vw, 520px) !important;
				margin: 12px auto !important;
			}
			#zapis .screen1 .calendar__master .slick-slide,
			#zapis .screen1 .calendar__master .slick-slide > div {
				height: auto !important;
			}
			#zapis .screen1 .calendar__master-item {
				max-height: calc(100vh - 260px);
				overflow: hidden;
			}
			#zapis .screen1 .calendar__master-item .btns-m {
				max-height: calc(100vh - 360px);
				overflow-y: auto;
				-webkit-overflow-scrolling: touch;
				padding-right: 4px;
				margin-bottom: 0;
			}
			#zapis .screen4 #zapis__add-calendar,
			#zapis .screen4 #zapis__enable-notify,
			#zapis .screen4 .btn-fin {
				min-width: 100%;
				font-size: 18px;
			}
			#zapis .screen3 .form__finish {
				padding: 0;
			}
			#zapis .screen3 .auth-step-tab {
				min-width: 126px;
				font-size: 16px;
			}
			#zapis .screen3 .js-auth-register .form__finish-left,
			#zapis .screen3 .js-auth-register .form__finish-right,
			#zapis .screen3 .js-auth-login .form__finish-left,
			#zapis .screen3 .js-auth-login .form__finish-right {
				width: 100%;
				float: none;
			}
			#zapis .screen3 .phone-prefix-wrap {
				display: flex;
				gap: 8px;
				align-items: center;
			}
			#zapis .screen3 .form__finish-top.control-group {
				display: flex;
				margin: 0;
				flex-direction: column;
				gap: 10px;
			}
			#zapis .screen3 .form__finish-left,
			#zapis .screen3 .form__finish-right {
				float: none !important;
				width: 100% !important;
				margin: 0 0 12px !important;
			}
			#zapis .screen3 .form__finish-left input,
			#zapis .screen3 .form__finish-right input,
			#zapis .screen3 .phone-prefix-wrap input {
				width: 100% !important;
				float: none !important;
			}
			#zapis .screen3 .phone-prefix-wrap input {
				width: auto !important;
				flex: 1 1 auto;
				min-width: 0;
			}
			#zapis .screen3 .password-field-wrap {
				min-height: 46px;
			}
			#zapis .screen3 .password-field-wrap input {
				padding-right: 92px;
			}
			#zapis .screen3 .password-toggle {
				top: 23px;
				right: 14px;
			}
			#zapis .screen3 .calc__btn {
				display: flex;
				flex-direction: column;
				align-items: center;
				gap: 10px;
				padding-left: 0 !important;
				overflow: visible;
			}
			#zapis .screen3 .calc__btn .btn-next {
				display: inline-flex;
				align-items: center;
				justify-content: center;
				width: auto;
				min-width: 220px;
				padding: 0 28px;
				margin: 0 !important;
				float: none;
			}
			#zapis .screen3 .calc__btn .close__btn {
				margin-top: 0;
			}
		}
		</style>
			<div class="accordionWrapper">
				<?php
				$accCounter = 0;
				foreach ((array) $pricesStructuredWithIds as $cat) :
					$catTitle = (string) ($cat['title'] ?? '');
					$items = (array) ($cat['items'] ?? []);
					if ($items === []) {
						continue;
					}
					$accCounter++;
				?>
				<div class="accordionItem opened">
				<h2 class="accordionItemHeading accordionItemHeading<?php echo (int) $accCounter; ?>"><?php echo $this->escape($catTitle); ?></h2>
				<div class="accordionItemContent accordionItemContent<?php echo (int) $accCounter; ?>">
					<div class="priceList">
						<?php foreach ($items as $item) : ?>
						<?php
							$durationMin = (int) ($item['duration'] ?? 0);
							$pauseMin = (int) ($item['pause_min'] ?? 0);
							$srvTime = $pauseMin > 0 ? ($durationMin . '.' . $pauseMin) : (string) $durationMin;
							$itemCatId = (int) ($cat['cat_id'] ?? 0);
							$itemSvcId = (int) ($item['svc_id'] ?? 0);
							$itemTagId = (int) ($item['tag_id'] ?? 0);
							$isHighlightedService = $highlightSearchService
								&& $itemSvcId === $filterServiceId
								&& $itemTagId === $filterTagId
								&& ($itemCatId === 0 || $itemCatId === $filterCatId);
						?>
						<div class="priceList__item<?php echo $isHighlightedService ? ' highlighted-service' : ''; ?>" data-cat-id="<?php echo $itemCatId; ?>" data-svc-id="<?php echo $this->escape((string) ($item['svc_id'] ?? '')); ?>" data-tag-id="<?php echo $itemTagId; ?>">
							<div class="priceList__item-coll price__coll1 service-name"><?php echo $this->escape($catTitle . ' - ' . (string) ($item['name'] ?? '')); ?></div>
							<div class="priceList__item-coll price__coll2 service-price">от <?php echo (int) ($item['price'] ?? 0); ?> <span class="price_span">руб.</span></div>
							<div class="priceList__item-coll price__coll3"><?php echo (int) ($item['duration'] ?? 0); ?> мин</div>
							<button type="button" id="btn_order" class="btn_add-master plus" data-booking-toggle="1" data-toggle="modal" data-target="#zapis" data-service-id="<?php echo $this->escape((string) ($item['svc_id'] ?? '')); ?>" data-service-name="<?php echo $this->escape((string) ($item['name'] ?? '')); ?>" data-srv-time="<?php echo $this->escape($srvTime); ?>"></button>
							<div class="clearFloat"></div>
						</div>
						<?php endforeach; ?>
					</div>
				</div>
			</div>
				<?php endforeach; ?>
			</div>
		</div>
	</section>

	<?php if (!empty($stockPricesStructuredWithIds)) : ?>
	<section class="master__services master__services--fullbleed">
		<div class="container">
			<div class="accordionWrapper">
				<div style="background-color:#f7cc53; border-radius:10px; padding:5px; margin-bottom:5px" class="accordionItem opened">
					<button style="color:green; font-weight:bold; background-color:#fff; border-radius:5px" type="button">Акционные услуги:</button>
					<div class="accordionItem opened">
						<?php
						$stockAccCounter = 0;
						foreach ((array) $stockPricesStructuredWithIds as $stockCategory) :
							$stockCategoryTitle = (string) ($stockCategory['title'] ?? '');
							$stockItems = (array) ($stockCategory['items'] ?? []);
							if ($stockItems === []) {
								continue;
							}
							$stockAccCounter++;
						?>
						<h2 class="accordionItemHeading2"><?php echo $this->escape($stockCategoryTitle); ?></h2>
						<div class="accordionItemContent2">
							<div class="stockList">
								<?php foreach ($stockItems as $stockItem) : ?>
								<?php
									$stockDurationMin = (int) ($stockItem['duration'] ?? 0);
									$stockPauseMin = (int) ($stockItem['pause_min'] ?? 0);
									$stockSrvTime = $stockPauseMin > 0 ? ($stockDurationMin . '.' . $stockPauseMin) : (string) $stockDurationMin;
									$stockPrice = (int) ($stockItem['price'] ?? 0);
									$stockOldPrice = (int) ($stockItem['old_price'] ?? 0);
									$stockDescription = trim((string) ($stockItem['about_stock'] ?? ''));
									$stockCountLeft = (int) ($stockItem['count_stock'] ?? 0);
									$stockServiceId = (int) ($stockItem['stock_service_id'] ?? 0);
									$stockIsSoldOut = $stockServiceId <= 0 || $stockCountLeft <= 0;
								?>
								<div class="stockList__item<?php echo $stockIsSoldOut ? ' is-unavailable' : ''; ?>" data-svc-id="<?php echo $this->escape((string) ($stockItem['svc_id'] ?? '')); ?>" data-stock-service-id="<?php echo $stockServiceId; ?>" data-tag-id="<?php echo (int) ($stockItem['tag_id'] ?? 0); ?>">
									<div class="stockList__item-coll stock__coll1"><?php echo $this->escape($stockCategoryTitle . ' - ' . (string) ($stockItem['name'] ?? '')); ?></div>
									<div class="stockList__item-coll stock__coll2">Описание: <?php echo $this->escape($stockDescription !== '' ? $stockDescription : 'Акционное предложение'); ?></div>
									<div class="stockList__item-coll stock__coll3">от: <?php echo $stockOldPrice > 0 ? $stockOldPrice : $stockPrice; ?> <i class="old_price">руб.</i></div>
									<div class="stockList__item-coll stock__coll4"><?php echo $stockPrice; ?> <i class="akzii_price">руб.</i></div>
									<div class="stockList__item-coll stock__coll5"><?php echo $stockDurationMin; ?> мин</div><br>
									<div class="stockList__item-coll stock__coll6">Осталось предложений: <?php echo $stockCountLeft > 0 ? $stockCountLeft : 0; ?></div>
									<button
										type="button"
										class="btn_add-master plus plus-stock<?php echo $stockIsSoldOut ? ' is-disabled' : ''; ?>"
										data-booking-toggle="1"
										data-booking-disabled="<?php echo $stockIsSoldOut ? '1' : '0'; ?>"
										title="<?php echo $stockIsSoldOut ? 'Акция закончилась' : 'Записаться на акцию'; ?>"
										aria-label="<?php echo $stockIsSoldOut ? 'Акция закончилась' : 'Записаться на акцию'; ?>"
										<?php if (!$stockIsSoldOut) : ?>
										data-toggle="modal"
										data-target="#zapis"
										<?php else : ?>
										disabled="disabled"
										aria-disabled="true"
										<?php endif; ?>
										data-booking-kind="stock"
										data-service-id="<?php echo $this->escape((string) ($stockItem['svc_id'] ?? '')); ?>"
										data-stock-service-id="<?php echo $stockServiceId; ?>"
										data-service-name="<?php echo $this->escape((string) ($stockItem['name'] ?? '')); ?>"
										data-srv-time="<?php echo $this->escape($stockSrvTime); ?>"
									></button>
									<div class="clearFloat"></div>
								</div>
								<?php endforeach; ?>
							</div>
						</div>
						<?php endforeach; ?>
					</div>
				</div>
			</div>
		</div>
	</section>
	<?php endif; ?>

	<?php if (!empty($coursesStructured)) : ?>
	<section class="master__services master__services--fullbleed">
		<div class="container">
			<div class="accordionWrapper">
				<div style="background-color:#e8f0c8; border-radius:10px; padding:5px; margin-bottom:5px" class="accordionItem opened">
					<button style="color:#475329; font-weight:bold; background-color:#fff; border-radius:5px" type="button">Курсы:</button>
					<div class="accordionItem opened">
						<div class="stockList">
							<?php foreach ((array) $coursesStructured as $courseItem) : ?>
							<?php
								$courseCategoryTitle = trim((string) ($courseItem['category_title'] ?? ''));
								$courseTitle = trim((string) ($courseItem['title'] ?? $courseItem['description'] ?? ''));
								$courseDescription = trim((string) ($courseItem['description'] ?? ''));
								$courseMediaPath = trim((string) ($courseItem['media_path'] ?? ''));
								$courseMediaUrl = $courseMediaPath !== '' ? $resolveProfileImage($courseMediaPath) : '';
								$coursePrice = (int) ($courseItem['price'] ?? 0);
								$courseDurationMin = (int) ($courseItem['duration_min'] ?? 0);
								$courseCapacity = (int) ($courseItem['capacity'] ?? 0);
								$courseBookingCount = max(0, (int) ($courseItem['booking_count'] ?? 0));
								$courseBookingMode = trim((string) ($courseItem['booking_mode'] ?? 'free'));
								$courseId = (int) ($courseItem['id'] ?? 0);
								$courseSlotId = (int) ($courseItem['slot_id'] ?? 0);
								$courseSlotUtc = trim((string) ($courseItem['slot_start_utc'] ?? ''));
								$courseCapacityTotal = $courseBookingMode === 'fixed'
									? max(1, (int) ($courseItem['slot_capacity_total'] ?? $courseCapacity ?: 1))
									: max(1, $courseCapacity ?: 1);
								$courseSeatsLeft = max(0, $courseCapacityTotal - $courseBookingCount);
								$courseIsFull = $courseSeatsLeft <= 0;
								$courseAlreadyBooked = $courseId > 0 && !empty($currentUserCourseBookings[$courseId]);
								if ($courseSlotId > 0 && !empty($currentUserCourseSlotBookings[$courseSlotId])) {
									$courseAlreadyBooked = true;
								}
								$courseButtonDisabled = $courseIsFull || $courseAlreadyBooked;
								$courseButtonTitle = $courseAlreadyBooked ? 'Вы уже записаны на этот курс' : ($courseIsFull ? 'На курсе больше нет свободных мест' : 'Записаться на курс');
								$courseSlotIso = '';
								if ($courseSlotUtc !== '') {
									try {
										$courseSlotIso = (new \DateTimeImmutable($courseSlotUtc, new \DateTimeZone('UTC')))
											->setTimezone(new \DateTimeZone('UTC'))
											->format(\DateTimeInterface::ATOM);
									} catch (\Throwable $e) {
										$courseSlotIso = '';
									}
								}
							?>
							<div class="stockList__item courseList__item<?php echo $courseButtonDisabled ? ' is-unavailable' : ''; ?>">
								<?php if ($courseMediaUrl !== '') : ?>
								<div class="stockList__item-coll course__coll0" style="margin-bottom:10px;">
									<img src="<?php echo $this->escape($courseMediaUrl); ?>" alt="<?php echo $this->escape($courseTitle !== '' ? $courseTitle : 'Курс'); ?>" style="max-width:180px; border-radius:8px;">
								</div>
								<?php endif; ?>
								<div class="stockList__item-coll stock__coll1"><?php echo $this->escape($courseTitle !== '' ? $courseTitle : 'Курс'); ?></div>
								<?php if ($courseCategoryTitle !== '') : ?>
								<div class="stockList__item-coll course__coll1b">Категория: <?php echo $this->escape($courseCategoryTitle); ?></div>
								<?php endif; ?>
								<div class="stockList__item-coll stock__coll2">Описание: <?php echo $this->escape($courseDescription !== '' ? $courseDescription : 'Описание курса не заполнено'); ?></div>
								<div class="stockList__item-coll stock__coll4"><?php echo $coursePrice; ?> <i class="akzii_price">руб.</i></div>
								<div class="stockList__item-coll stock__coll5"><?php echo $courseDurationMin; ?> мин</div><br>
								<div class="stockList__item-coll stock__coll6 course__seats<?php echo $courseIsFull ? ' is-full' : ''; ?>">Свободно мест: <?php echo $courseSeatsLeft; ?> из <?php echo $courseCapacityTotal; ?></div>
								<?php if ($courseBookingMode === 'fixed' && $courseSlotUtc !== '') : ?>
								<div class="stockList__item-coll course__coll7">Дата и время: <span class="lk-time-utc" data-time-utc="<?php echo $this->escape($courseSlotIso); ?>"><?php echo $this->escape($courseSlotUtc); ?></span></div>
								<?php else : ?>
								<div class="stockList__item-coll course__coll7">Запись: по свободному времени мастера</div>
								<?php endif; ?>
								<button
									type="button"
									class="btn_add-master plus plus-course<?php echo $courseButtonDisabled ? ' is-disabled' : ''; ?>"
									data-booking-toggle="1"
									data-booking-disabled="<?php echo $courseButtonDisabled ? '1' : '0'; ?>"
									title="<?php echo $this->escape($courseButtonTitle); ?>"
									aria-label="<?php echo $this->escape($courseButtonTitle); ?>"
									<?php if (!$courseButtonDisabled) : ?>
									data-toggle="modal"
									data-target="#zapis"
									<?php else : ?>
									disabled="disabled"
									aria-disabled="true"
									<?php endif; ?>
									data-booking-kind="course"
									data-course-id="<?php echo $courseId; ?>"
									data-course-slot-id="<?php echo $courseSlotId; ?>"
									data-fixed-time-utc="<?php echo $this->escape($courseSlotUtc); ?>"
									data-service-name="<?php echo $this->escape($courseTitle !== '' ? ('Курс: ' . $courseTitle) : 'Курс'); ?>"
									data-srv-time="<?php echo $this->escape((string) $courseDurationMin); ?>"
								></button>
								<div class="clearFloat"></div>
							</div>
							<?php endforeach; ?>
						</div>
					</div>
				</div>
			</div>
		</div>
	</section>
	<?php endif; ?>

	<?php if (!empty($searchesStructured)) : ?>
	<section class="master__services master__services--fullbleed">
		<div class="container">
			<div class="accordionWrapper">
				<div style="background-color:#e8f0c8; border-radius:10px; padding:5px; margin-bottom:5px" class="accordionItem opened">
					<button style="color:#475329; font-weight:bold; background-color:#fff; border-radius:5px" type="button">Поиск моделей:</button>
					<div class="accordionItem opened">
						<div class="stockList">
							<?php foreach ((array) $searchesStructured as $searchItem) : ?>
							<?php
								$searchCategoryTitle = trim((string) ($searchItem['category_title'] ?? ''));
								$searchTitle = trim((string) ($searchItem['title'] ?? $searchItem['description'] ?? ''));
								$searchDescription = trim((string) ($searchItem['description'] ?? ''));
								$searchMediaPath = trim((string) ($searchItem['media_path'] ?? ''));
								$searchMediaUrl = $searchMediaPath !== '' ? $resolveProfileImage($searchMediaPath) : '';
								$searchPrice = (int) ($searchItem['price'] ?? 0);
								$searchDurationMin = (int) ($searchItem['duration_min'] ?? 0);
								$searchCapacity = (int) ($searchItem['capacity'] ?? 0);
								$searchBookingCount = max(0, (int) ($searchItem['booking_count'] ?? 0));
								$searchBookingMode = trim((string) ($searchItem['booking_mode'] ?? 'free'));
								$searchId = (int) ($searchItem['id'] ?? 0);
								$searchSlotId = (int) ($searchItem['slot_id'] ?? 0);
								$searchSlotUtc = trim((string) ($searchItem['slot_start_utc'] ?? ''));
								$searchCapacityTotal = $searchBookingMode === 'fixed'
									? max(1, (int) ($searchItem['slot_capacity_total'] ?? $searchCapacity ?: 1))
									: max(1, $searchCapacity ?: 1);
								$searchSeatsLeft = max(0, $searchCapacityTotal - $searchBookingCount);
								$searchIsFull = $searchSeatsLeft <= 0;
								$searchAlreadyBooked = $searchId > 0 && !empty($currentUserSearchBookings[$searchId]);
								if ($searchSlotId > 0 && !empty($currentUserSearchSlotBookings[$searchSlotId])) {
									$searchAlreadyBooked = true;
								}
								$searchButtonDisabled = $searchIsFull || $searchAlreadyBooked;
								$searchButtonTitle = $searchAlreadyBooked ? 'Вы уже записаны на этот поиск' : ($searchIsFull ? 'На поиске больше нет свободных мест' : 'Записаться на поиск');
								$searchSlotIso = '';
								if ($searchSlotUtc !== '') {
									try {
										$searchSlotIso = (new \DateTimeImmutable($searchSlotUtc, new \DateTimeZone('UTC')))
											->setTimezone(new \DateTimeZone('UTC'))
											->format(\DateTimeInterface::ATOM);
									} catch (\Throwable $e) {
										$searchSlotIso = '';
									}
								}
							?>
							<div class="stockList__item courseList__item<?php echo $searchButtonDisabled ? ' is-unavailable' : ''; ?>">
								<?php if ($searchMediaUrl !== '') : ?>
								<div class="stockList__item-coll course__coll0" style="margin-bottom:10px;">
									<img src="<?php echo $this->escape($searchMediaUrl); ?>" alt="<?php echo $this->escape($searchTitle !== '' ? $searchTitle : 'Поиск моделей'); ?>" style="max-width:180px; border-radius:8px;">
								</div>
								<?php endif; ?>
								<div class="stockList__item-coll stock__coll1"><?php echo $this->escape($searchTitle !== '' ? $searchTitle : 'Поиск моделей'); ?></div>
								<?php if ($searchCategoryTitle !== '') : ?>
								<div class="stockList__item-coll course__coll1b">Категория: <?php echo $this->escape($searchCategoryTitle); ?></div>
								<?php endif; ?>
								<div class="stockList__item-coll stock__coll2">Описание: <?php echo $this->escape($searchDescription !== '' ? $searchDescription : 'Описание поиска не заполнено'); ?></div>
								<div class="stockList__item-coll stock__coll4"><?php echo $searchPrice; ?> <i class="akzii_price">руб.</i></div>
								<div class="stockList__item-coll stock__coll5"><?php echo $searchDurationMin; ?> мин</div><br>
								<div class="stockList__item-coll stock__coll6 course__seats<?php echo $searchIsFull ? ' is-full' : ''; ?>">Свободно мест: <?php echo $searchSeatsLeft; ?> из <?php echo $searchCapacityTotal; ?></div>
								<?php if ($searchBookingMode === 'fixed' && $searchSlotUtc !== '') : ?>
								<div class="stockList__item-coll course__coll7">Дата и время: <span class="lk-time-utc" data-time-utc="<?php echo $this->escape($searchSlotIso); ?>"><?php echo $this->escape($searchSlotUtc); ?></span></div>
								<?php else : ?>
								<div class="stockList__item-coll course__coll7">Запись: по свободному времени мастера</div>
								<?php endif; ?>
								<button
									type="button"
									class="btn_add-master plus plus-course<?php echo $searchButtonDisabled ? ' is-disabled' : ''; ?>"
									data-booking-toggle="1"
									data-booking-disabled="<?php echo $searchButtonDisabled ? '1' : '0'; ?>"
									title="<?php echo $this->escape($searchButtonTitle); ?>"
									aria-label="<?php echo $this->escape($searchButtonTitle); ?>"
									<?php if (!$searchButtonDisabled) : ?>
									data-toggle="modal"
									data-target="#zapis"
									<?php else : ?>
									disabled="disabled"
									aria-disabled="true"
									<?php endif; ?>
									data-booking-kind="search"
									data-search-id="<?php echo $searchId; ?>"
									data-search-slot-id="<?php echo $searchSlotId; ?>"
									data-fixed-time-utc="<?php echo $this->escape($searchSlotUtc); ?>"
									data-service-name="<?php echo $this->escape($searchTitle !== '' ? ('Поиск моделей: ' . $searchTitle) : 'Поиск моделей'); ?>"
									data-srv-time="<?php echo $this->escape((string) $searchDurationMin); ?>"
								></button>
								<div class="clearFloat"></div>
							</div>
							<?php endforeach; ?>
						</div>
					</div>
				</div>
			</div>
		</div>
	</section>
	<?php endif; ?>

	<div class="modal fade" id="zapis" role="dialog" aria-labelledby="zapisModalLabel" aria-hidden="true">
	<div class="modal-dialog" role="document">
		<div class="modal-content">
			<div class="modal-header">
				<button type="button" class="close" data-dismiss="modal" aria-label="Close">
					<span aria-hidden="true">&times;</span>
				</button>
			</div>
			<div class="modal-body">
				<form id="order-form" method="POST" action="/">
					<div class="screen screen1">
						<div class="calc__body">
							<h2>Выберите дату и время</h2>
							<div class="calendar__master preload">
								<?php foreach ($calendarDays as $calendarDay) : ?>
								<div class="calendar__master-item">
									<span class="mas-date">
										<?php echo $this->escape((string) ($calendarDay['date_view'] ?? '')); ?>
										<b><?php echo $this->escape((string) ($calendarDay['dow'] ?? '')); ?></b>
									</span>
									<?php if (!empty($calendarDay['slots'])) : ?>
									<p class="btns-m">
										<?php foreach ((array) $calendarDay['slots'] as $slotTime) :
											$slotValue = (string) ($calendarDay['date'] ?? '') . ' ' . (string) $slotTime;
											$slotId = preg_replace('/[^a-zA-Z0-9\-_]/', '-', (string) ($calendarDay['date'] ?? '') . '-' . str_replace(':', '-', (string) $slotTime));
											$slotUtc = (string) (($calendarDay['slot_utc'][(string) $slotTime] ?? ''));
										?>
										<input type="radio" id="<?php echo $this->escape($slotId); ?>" name="time" value="<?php echo $this->escape($slotValue); ?>" data-time-utc="<?php echo $this->escape($slotUtc); ?>">
										<label for="<?php echo $this->escape($slotId); ?>" class="btn-select"><?php echo $this->escape((string) $slotTime); ?></label>
										<?php endforeach; ?>
									</p>
									<?php else : ?>
									<span class="line-no"></span>
									<?php endif; ?>
								</div>
								<?php endforeach; ?>
							</div>
						</div>
						<div class="calc__btn">
							<div class="btn-next">Далее</div>
							<button type="button" class="close__btn" data-dismiss="modal">Отмена</button>
						</div>
					</div>

					<div class="screen screen2" style="display: none;">
						<div class="calc__body">
							<h2>Запись</h2>
							<div class="zapis__data">
								<span class="zapis__data-time">Дата: <b id="req_date"></b></span>
								<span class="zapis__data-time">Время: <b id="req_time"></b></span>
								<div class="zapis__data-price-wrap"></div>
								<b class="zapis__data-result"></b>
								<textarea name="note" class="zapis__data-textarea" placeholder="Комментарий (необязательно)"></textarea>
								<div class="error-msg" style="display:none;"></div>
							</div>
							<div class="calc__btn">
								<div class="btn-next">Далее</div>
								<button class="close__btn" type="button" onclick="jQuery('.screen').hide();jQuery(this).closest('.screen').prev().show();">Назад</button>
							</div>
						</div>
					</div>

					<div class="screen screen3" style="display: none;">
						<div class="calc__body">
							<h2>Для записи необходимо авторизоваться</h2>
							<div class="form__finish">
								<?php if ((int) $currentUser->id > 0) : ?>
								<div class="form__finish-top control-group">
									<div class="form__finish-left controls">
										<input type="text" name="name" value="<?php echo $this->escape((string) ($currentUser->name ?? '')); ?>" placeholder="Ваше имя">
									</div>
									<div class="form__finish-right controls">
										<input type="text" name="telefon" value="" placeholder="Телефон (необязательно)">
									</div>
									<div class="clearFloat"></div>
								</div>
								<?php else : ?>
								<div class="auth-step-switch js-auth-switch-wrap">
									<ul>
										<li><button type="button" class="auth-step-tab js-auth-switch active" data-auth-tab="register">Регистрация</button></li>
										<li><button type="button" class="auth-step-tab js-auth-switch" data-auth-tab="login">Вход</button></li>
									</ul>
								</div>
								<div class="form__finish-top control-group js-auth-tab js-auth-register">
									<div class="form__finish-left controls row-full">
										<input type="text" name="qa_name" placeholder="Ваше имя" data-required="register" required>
									</div>
									<div class="clearFloat"></div>
									<div class="form__finish-right controls phone-prefix-wrap">
										<span>+7<img src="/templates/ryba/images/rus.png" alt=""></span>
										<input type="text" name="qa_phone" placeholder="Телефон" data-required="register" required>
									</div>
									<div class="form__finish-left controls email-control">
										<input type="email" name="qa_email" placeholder="Email *" data-required="register" required>
										<div class="email-note">* почта будет использоваться для восстановления аккаунта</div>
									</div>
									<div class="clearFloat"></div>
									<div class="form__finish-left controls">
										<div class="password-field-wrap">
											<input type="password" name="qa_password1" placeholder="Пароль" data-required="register" required>
											<button type="button" class="password-toggle" data-target-name="qa_password1">Показать</button>
										</div>
									</div>
									<div class="form__finish-right controls">
										<div class="password-field-wrap">
											<input type="password" name="qa_password2" placeholder="Пароль ещё раз" data-required="register" required>
											<button type="button" class="password-toggle" data-target-name="qa_password2">Показать</button>
										</div>
									</div>
									<div class="clearFloat"></div>
								</div>
								<div class="form__finish-top control-group js-auth-tab js-auth-login" style="display:none;">
									<div class="form__finish-left controls">
										<input type="text" name="qa_login_username" placeholder="Email" data-required="login" required>
									</div>
									<div class="form__finish-right controls">
										<div class="password-field-wrap">
											<input type="password" name="qa_login_password" placeholder="Пароль" data-required="login" required>
											<button type="button" class="password-toggle" data-target-name="qa_login_password">Показать</button>
										</div>
									</div>
									<div class="clearFloat"></div>
								</div>
								<?php endif; ?>
								<div class="error-msg" style="display:none;"></div>
							</div>
							<div class="calc__btn">
								<div class="btn-next"><?php echo (int) $currentUser->id > 0 ? 'Записаться' : 'Продолжить'; ?></div>
								<button class="close__btn" type="button" onclick="jQuery('.screen').hide();jQuery(this).closest('.screen').prev().show();">Назад</button>
							</div>
						</div>
					</div>

					<div class="screen screen4" style="display: none;">
						<div class="calc__body">
							<label class="succes-round">Ваша запись создана и передана в обработку</label>
							<div class="calc__btn">
								<button type="button" class="btn-next" id="zapis__add-calendar">Добавить в календарь</button>
								<button type="button" id="zapis__enable-notify">Включить уведомления</button>
								<div class="btn-fin" data-dismiss="modal">Закрыть</div>
							</div>
						</div>
					</div>

					<input type="hidden" name="master_id" value="<?php echo (int) $this->data->id; ?>">
					<input type="hidden" name="service_id" value="" id="zapis__data-service-id">
					<input id="zapis__data-s-id" type="hidden" name="svc_id" value="0">
					<input id="zapis__data-t-id" type="hidden" name="tag_id" value="0">
					<input id="zapis__data-s-nm" type="hidden" name="service_name" value="">
					<input id="zapis__booking-kind" type="hidden" name="booking_kind" value="service">
					<input id="zapis__stock-service-id" type="hidden" name="stock_service_id" value="0">
					<input id="zapis__course-id" type="hidden" name="course_id" value="0">
					<input id="zapis__course-slot-id" type="hidden" name="course_slot_id" value="0">
					<input id="zapis__search-id" type="hidden" name="search_id" value="0">
					<input id="zapis__search-slot-id" type="hidden" name="search_slot_id" value="0">
					<input id="zapis__fixed-time-utc" type="hidden" name="fixed_time_utc" value="">
					<input id="zapis__data-price" type="hidden" name="price" value="">
					<input id="time_sum" type="hidden" class="time_sum" name="time_sum" value="">
					<input id="zapis__duration-min" type="hidden" name="duration_min" value="60">
					<input id="zapis__booking-date" type="hidden" name="booking_date" value="">
					<input id="zapis__booking-time" type="hidden" name="booking_time" value="">
					<input id="zapis__booking-time-utc" type="hidden" name="booking_time_utc" value="">
					<input id="zapis__time-combined" type="hidden" name="time" value="">
					<input type="hidden" name="<?php echo $this->escape($pushnotifyTokenName); ?>" value="<?php echo $this->escape($pushnotifyTokenValue); ?>">
				</form>
			</div>
		</div>
	</div>
</div>

<section class="review__master review__master--fullbleed">
	<div class="container">
		<?php if ($reviewsHtml !== '') : ?>
			<?php echo $reviewsHtml; ?>
		<?php else : ?>
			<h2>Отзывы</h2>
			<div id="review__master" class="review__master-head">
				<a href="<?php echo (int) $currentUser->id > 0 ? '#' : Route::_('index.php?option=com_users&view=login&return=' . base64_encode(Uri::current())); ?>">Написать отзыв</a>
				<span class="easylast_noentry">Нет отзывов</span>
			</div>
		<?php endif; ?>
	</div>
</section>

<section class="master__about">
	<div class="container">
		<div class="master__about-left">
			<h2>О мастере</h2>
			<p><span><?php echo $this->escape('Обо мне: "' . $aboutText . '"'); ?></span></p><br>
			<div class="master__about-call">
				<img src="/templates/ryba/images/iphone1.png" alt="">
				<a href="tel:<?php echo $this->escape(preg_replace('/[^\d\+]/', '', $phone)); ?>" target="_blank" rel="noopener noreferrer">Позвонить мастеру</a>
			</div>
			<?php if ($socialLinks !== []) : ?>
			<div class="master__about-socials">
				<?php foreach ($socialLinks as $social) : ?>
					<a href="<?php echo $this->escape($social['url']); ?>" class="master__about-social-link" target="_blank" rel="noopener noreferrer" title="<?php echo $this->escape($social['label']); ?>">
						<img src="/templates/ryba/icons/social_icons/<?php echo $this->escape($social['icon']); ?>" alt="<?php echo $this->escape($social['label']); ?>">
					</a>
				<?php endforeach; ?>
			</div>
			<?php endif; ?>
			<?php if ($addr !== '') : ?>
			<div class="master__about-address">
				<img src="/templates/ryba/images/loclo.png" alt="">
				<?php echo $this->escape($addr); ?>
			</div>
			<?php endif; ?>
			<div class="master__about-time">
				<img src="/templates/ryba/images/timet.png" alt="">
				<ul class="category__content-info-list">
				<?php foreach ($workScheduleRows as $wr) : ?>
					<li>
						<span><?php echo $this->escape($wr[0]); ?></span>
						<span><?php echo $this->escape($wr[1]); ?></span>
					</li>
				<?php endforeach; ?>
				</ul>
				<div class="clearFloat"></div>
			</div>
		</div>
		<div class="master__about-right">
			<div id="map" style="width:100%; height:380px"></div>
		</div>
		<div class="clearFloat"></div>
	</div>
	<div class="container">
		<div class="container bot-tex"></div>
	</div>
</section>

<script>
(function () {
	var bookmarkEl = document.getElementById('bookmarkme');
	var quickAuthTitle = 'Авторизуйтесь, чтобы добавить мастера в избранное';
	if (bookmarkEl) {
		bookmarkEl.addEventListener('click', function (e) {
			e.preventDefault();
			var masterId = parseInt(this.getAttribute('data-id') || '0', 10);
			if (!masterId) {
				return;
			}
			var isLoggedIn = <?php echo $currentUser->id > 0 ? 'true' : 'false'; ?>;
			if (!isLoggedIn) {
				if (window.QuickAuth && typeof window.QuickAuth.show === 'function') {
					window.QuickAuth.show('', { title: quickAuthTitle });
				}
				return;
			}
			var fd = new FormData();
			fd.append('<?php echo $this->escape($pushnotifyTokenName); ?>', '<?php echo $this->escape($pushnotifyTokenValue); ?>');
			fd.append('action', 'toggle_favorite');
			fd.append('master_id', String(masterId));
			fetch('<?php echo Route::_('index.php?option=com_ajax&group=ajax&plugin=quickauth&format=json', false); ?>', {
				method: 'POST',
				body: fd,
				credentials: 'same-origin'
			})
			.then(function (r) { return r.json(); })
			.then(function (data) {
				var res = data && data.data ? data.data : data;
				if (res && res.success) {
					bookmarkEl.classList.toggle('active', !!res.active);
				}
			})
			.catch(function () {});
		});
	}

		var bookingModal = document.getElementById('zapis');
	var bookingForm = document.getElementById('order-form');
	if (bookingModal && bookingForm) {
		var isLoggedIn = <?php echo $currentUser->id > 0 ? 'true' : 'false'; ?>;
		var quickAuthUrl = <?php echo json_encode(Route::_('index.php?option=com_ajax&plugin=Quickauth&format=json', false)); ?>;
		var bookingMasterName = <?php echo json_encode($displayName); ?>;
		var bookingAddress = <?php echo json_encode($addr); ?>;
		var bookingCalendarDays = <?php echo $calendarDaysJson ?: '[]'; ?>;
		var bookingCalendarByDate = {};
		if (Array.isArray(bookingCalendarDays)) {
			bookingCalendarDays.forEach(function (day) {
				if (day && day.date) {
					bookingCalendarByDate[String(day.date)] = day;
				}
			});
		}
		var activeBookingButton = null;
		var authMode = 'register';
		var redirectAfterClose = false;
		var redirectAfterCloseUrl = '';

		function parseInteger(value, fallback) {
			var num = parseInt(String(value || '').replace(/[^\d]/g, ''), 10);
			return isNaN(num) ? fallback : num;
		}

		function parseSrvTime(raw) {
			var parts = String(raw || '').split('.');
			return {
				duration: parseInteger(parts[0], 60),
				pause: parseInteger(parts[1], 0)
			};
		}

		function parseTimeToMinutes(raw) {
			var match = String(raw || '').match(/^(\d{1,2}):(\d{2})$/);
			if (!match) {
				return null;
			}
			var h = parseInt(match[1], 10);
			var m = parseInt(match[2], 10);
			if (isNaN(h) || isNaN(m) || h < 0 || h > 23 || m < 0 || m > 59) {
				return null;
			}
			return h * 60 + m;
		}

		function parseSlotValue(raw, radioEl) {
			var parts = splitDateTime(raw);
			if (!parts) {
				return null;
			}
			var minutes = parseTimeToMinutes(parts.time);
			if (minutes === null) {
				return null;
			}
			var utcRaw = '';
			if (radioEl && typeof radioEl.getAttribute === 'function') {
				utcRaw = String(radioEl.getAttribute('data-time-utc') || '').trim();
			}
			var dt = utcRaw ? new Date(utcRaw) : new Date(parts.date + 'T' + parts.time + ':00');
			if (isNaN(dt.getTime())) {
				return null;
			}
			return { date: parts.date, time: parts.time, minutes: minutes, dateObj: dt, timeUtc: utcRaw };
		}

		function setCalendarNoSlots(itemEl, show) {
			var marker = itemEl.querySelector('.line-no.dynamic-line-no');
			if (show) {
				if (!marker) {
					marker = document.createElement('span');
					marker.className = 'line-no dynamic-line-no';
					itemEl.appendChild(marker);
				}
			} else if (marker && marker.parentNode) {
				marker.parentNode.removeChild(marker);
			}
		}

		function applyAvailableSlotsFilter() {
			var requiredMin = parseInteger((bookingForm.querySelector('#zapis__duration-min') || {}).value, 60);
			requiredMin = Math.max(15, Math.min(480, requiredMin));
			var steps = Math.max(1, Math.ceil(requiredMin / 15));
			var nowTs = Date.now();
			var minFutureTs = nowTs + (60 * 1000);

			bookingModal.querySelectorAll('.calendar__master-item').forEach(function (dayItem) {
				var radios = Array.prototype.slice.call(dayItem.querySelectorAll('input[name="time"]'));
				var btnsWrap = dayItem.querySelector('.btns-m');
				if (!radios.length || !btnsWrap) {
					return;
				}

				var slotInfos = [];
				var availableStartMins = new Set();
				var dayDate = '';

				radios.forEach(function (radio) {
					var parsed = parseSlotValue(radio.value || '', radio);
					if (!parsed) {
						return;
					}
					dayDate = parsed.date;
					slotInfos.push({ radio: radio, parsed: parsed });
					availableStartMins.add(parsed.minutes);
				});

				var dayMeta = dayDate ? bookingCalendarByDate[dayDate] : null;
				var rangeTo = dayMeta && dayMeta.range_to_min !== null ? parseInteger(dayMeta.range_to_min, null) : null;

				var visibleCount = 0;
				slotInfos.forEach(function (slotInfo) {
					var radio = slotInfo.radio;
					var label = dayItem.querySelector('label[for="' + radio.id + '"]');
					var startMin = slotInfo.parsed.minutes;
					var slotTs = slotInfo.parsed.dateObj.getTime();
					var visible = slotTs > minFutureTs;

					if (visible && rangeTo !== null && (startMin + requiredMin) > rangeTo) {
						visible = false;
					}
					if (visible) {
						for (var i = 0; i < steps; i++) {
							if (!availableStartMins.has(startMin + (i * 15))) {
								visible = false;
								break;
							}
						}
					}

					radio.disabled = !visible;
					if (!visible && radio.checked) {
						radio.checked = false;
					}
					if (label) {
						label.style.display = visible ? '' : 'none';
					}
					if (visible) {
						visibleCount++;
					}
				});

				setCalendarNoSlots(dayItem, visibleCount === 0);
			});
		}

		function splitDateTime(value) {
			var chunks = String(value || '').trim().split(/\s+/);
			if (chunks.length < 2) {
				return null;
			}
			return {
				date: chunks[0],
				time: chunks[1]
			};
		}

		function formatDate(dateIso) {
			var parts = String(dateIso || '').split('-');
			if (parts.length !== 3) {
				return dateIso;
			}
			return [parts[2], parts[1], parts[0]].join('.');
		}

		function hideErrors() {
			bookingForm.querySelectorAll('.error-msg').forEach(function (el) {
				el.style.display = 'none';
				el.textContent = '';
			});
			bookingForm.querySelectorAll('.screen3 .field-error').forEach(function (el) {
				el.classList.remove('field-error');
			});
		}

		function setError(screen, text) {
			var errorEl = screen.querySelector('.error-msg');
			if (!errorEl) {
				return;
			}
			errorEl.textContent = text;
			errorEl.style.display = 'block';
		}

		function markFieldError(selector) {
			var el = bookingForm.querySelector(selector);
			if (el) {
				el.classList.add('field-error');
			}
		}

		function normalizeQuickAuthError(rawMessage, mode) {
			var msg = String(rawMessage || '').trim();
			var low = msg.toLowerCase();
			if (mode === 'register') {
				if (low.indexOf('логин') !== -1 || low.indexOf('username') !== -1 || low.indexOf('user name') !== -1) {
					return 'Эта почта уже занята. Укажите другую почту или войдите.';
				}
			}
			return msg || 'Ошибка авторизации';
		}

		function highlightQuickAuthErrorFields(rawMessage, mode) {
			var low = String(rawMessage || '').toLowerCase();
			if (mode === 'login') {
				markFieldError('input[name="qa_login_username"]');
				markFieldError('input[name="qa_login_password"]');
				return;
			}
			if (low.indexOf('логин') !== -1 || low.indexOf('username') !== -1 || low.indexOf('email') !== -1 || low.indexOf('почт') !== -1) {
				markFieldError('input[name="qa_email"]');
			}
			if (low.indexOf('парол') !== -1) {
				markFieldError('input[name="qa_password1"]');
				markFieldError('input[name="qa_password2"]');
			}
			if (low.indexOf('телефон') !== -1 || low.indexOf('phone') !== -1) {
				markFieldError('input[name="qa_phone"]');
			}
			if (low.indexOf('имя') !== -1 || low.indexOf('name') !== -1) {
				markFieldError('input[name="qa_name"]');
			}
		}

		function showScreen(screenClass) {
			bookingModal.querySelectorAll('.screen').forEach(function (scr) {
				scr.style.display = 'none';
			});
			var target = bookingModal.querySelector('.' + screenClass);
			if (target) {
				target.style.display = 'block';
			}
		}

		function syncNotifyButtonVisibility() {
			var enableNotifyBtn = document.getElementById('zapis__enable-notify');
			if (!enableNotifyBtn) {
				return;
			}
			enableNotifyBtn.style.display = '';
			if (!window.ViglingPushPrompt || typeof window.ViglingPushPrompt.getPreferences !== 'function') {
				return;
			}
			window.ViglingPushPrompt.getPreferences().then(function (prefs) {
				if (!prefs || !prefs.success) {
					enableNotifyBtn.style.display = '';
					return;
				}
				var enabled = prefs.subscribed === true && prefs.notifications_enabled !== false;
				enableNotifyBtn.style.display = enabled ? 'none' : '';
			}).catch(function () {
				enableNotifyBtn.style.display = '';
			});
		}

		function setButtonLoading(button, loading, loadingText) {
			if (!button) {
				return;
			}
			if (!button.dataset.originalHtml) {
				button.dataset.originalHtml = button.innerHTML;
			}
			if (loading) {
				button.classList.add('is-loading');
				button.innerHTML = '<span class="btn-spinner" aria-hidden="true"></span><span class="btn-label">' + (loadingText || 'Подождите...') + '</span>';
				button.setAttribute('disabled', 'disabled');
			} else {
				button.classList.remove('is-loading');
				button.innerHTML = button.dataset.originalHtml || button.innerHTML;
				button.removeAttribute('disabled');
			}
		}
		function updateButtonLoadingText(button, loadingText) {
			if (!button || !button.classList.contains('is-loading')) {
				return;
			}
			var label = button.querySelector('.btn-label');
			if (label) {
				label.textContent = loadingText || 'Подождите...';
			}
		}

		function decrementActiveStockCounter(button) {
			if (!button || String(button.getAttribute('data-booking-kind') || '') !== 'stock') {
				return;
			}
			var item = button.closest('.stockList__item');
			var counter = item ? item.querySelector('.stock__coll6') : null;
			if (!counter) {
				return;
			}
			var current = parseInteger(counter.textContent, 0);
			var next = Math.max(0, current - 1);
			counter.textContent = 'Осталось предложений: ' + next;
			if (next <= 0) {
				item.classList.add('is-unavailable');
				button.classList.add('is-disabled');
				button.setAttribute('data-booking-disabled', '1');
				button.setAttribute('disabled', 'disabled');
				button.setAttribute('aria-disabled', 'true');
				button.setAttribute('title', 'Акция закончилась');
				button.setAttribute('aria-label', 'Акция закончилась');
			}
		}

		function updateSummaryFromButton(button) {
			if (!button) {
				return;
			}
			var item = button.closest('.priceList__item, .stockList__item');
			if (!item) {
				return;
			}
			var isStockItem = item.classList.contains('stockList__item');
			var titleSelector = isStockItem ? '.stock__coll1' : '.price__coll1';
			var priceSelector = isStockItem ? '.stock__coll4' : '.price__coll2';
			var title = ((item.querySelector(titleSelector) || {}).textContent || '').trim();
			var priceText = ((item.querySelector(priceSelector) || {}).textContent || '').trim();
			var price = parseInteger(priceText, 0);
			var oldPriceText = isStockItem ? ((item.querySelector('.stock__coll3') || {}).textContent || '').trim() : '';
			var oldPrice = isStockItem ? parseInteger(oldPriceText, 0) : 0;
			var bookingKind = String(button.getAttribute('data-booking-kind') || 'service').trim() || 'service';
			var courseId = String(button.getAttribute('data-course-id') || '0').trim();
			var courseSlotId = String(button.getAttribute('data-course-slot-id') || '0').trim();
			var searchId = String(button.getAttribute('data-search-id') || '0').trim();
			var searchSlotId = String(button.getAttribute('data-search-slot-id') || '0').trim();
			var fixedTimeUtc = String(button.getAttribute('data-fixed-time-utc') || '').trim();
			var srvTime = parseSrvTime(button.getAttribute('data-srv-time'));
			var timeSum = srvTime.duration + srvTime.pause;
			var serviceName = String(button.getAttribute('data-service-name') || title).trim();

			var wrap = bookingForm.querySelector('.zapis__data-price-wrap');
			var totalEl = bookingForm.querySelector('.zapis__data-result');
			if (wrap) {
				if (isStockItem && oldPrice > 0) {
					wrap.innerHTML = '<p class="zapis__data-price"><span>' + title + '</span><span style="margin-left: 5px"> - длительность: ' + srvTime.duration + ' мин. </span><b><span style="text-decoration: line-through; opacity: .65; margin-right: 8px;">' + oldPrice + '</span>~ ' + price + ' <i class="valuta_price">руб.</i></b></p>';
				} else {
					wrap.innerHTML = '<p class="zapis__data-price"><span>' + title + '</span><span style="margin-left: 5px"> - длительность: ' + srvTime.duration + ' мин. </span><b>~ ' + price + ' <i class="valuta_price">руб.</i></b></p>';
				}
			}
			if (totalEl) {
				totalEl.innerHTML = 'Итого:  ' + price + ' <i class="valuta_price">руб.</i>';
			}

			bookingForm.querySelector('#zapis__data-s-id').value = item.getAttribute('data-svc-id') || '0';
			bookingForm.querySelector('#zapis__data-service-id').value = item.getAttribute('data-svc-id') || '';
			bookingForm.querySelector('#zapis__data-t-id').value = item.getAttribute('data-tag-id') || '0';
			bookingForm.querySelector('#zapis__data-s-nm').value = serviceName;
			bookingForm.querySelector('#zapis__booking-kind').value = bookingKind;
			bookingForm.querySelector('#zapis__stock-service-id').value = button.getAttribute('data-stock-service-id') || item.getAttribute('data-stock-service-id') || '0';
			bookingForm.querySelector('#zapis__course-id').value = courseId || '0';
			bookingForm.querySelector('#zapis__course-slot-id').value = courseSlotId || '0';
			bookingForm.querySelector('#zapis__search-id').value = searchId || '0';
			bookingForm.querySelector('#zapis__search-slot-id').value = searchSlotId || '0';
			bookingForm.querySelector('#zapis__fixed-time-utc').value = fixedTimeUtc;
			bookingForm.querySelector('#zapis__data-price').value = String(price);
			bookingForm.querySelector('#time_sum').value = String(timeSum);
			bookingForm.querySelector('#zapis__duration-min').value = String(Math.max(15, srvTime.duration));
			applyAvailableSlotsFilter();
		}

		function getFixedCourseUtcValue() {
			return String((bookingForm.querySelector('#zapis__fixed-time-utc') || {}).value || '').trim();
		}

		function isFixedCourseBooking() {
			var bookingKind = String((bookingForm.querySelector('#zapis__booking-kind') || {}).value || '').trim();
			var courseSlotId = parseInteger((bookingForm.querySelector('#zapis__course-slot-id') || {}).value, 0);
			var searchSlotId = parseInteger((bookingForm.querySelector('#zapis__search-slot-id') || {}).value, 0);
			return ((bookingKind === 'course' && courseSlotId > 0) || (bookingKind === 'search' && searchSlotId > 0)) && getFixedCourseUtcValue() !== '';
		}

		function applyFixedCourseSelection() {
			var fixedUtc = getFixedCourseUtcValue();
			if (!fixedUtc) {
				return false;
			}
			var normalized = fixedUtc.indexOf('T') === -1 ? fixedUtc.replace(' ', 'T') + 'Z' : fixedUtc;
			var fixedDate = new Date(normalized);
			if (isNaN(fixedDate.getTime())) {
				return false;
			}
			var dateLocal = fixedDate.getFullYear() + '-' + String(fixedDate.getMonth() + 1).padStart(2, '0') + '-' + String(fixedDate.getDate()).padStart(2, '0');
			var timeLocal = String(fixedDate.getHours()).padStart(2, '0') + ':' + String(fixedDate.getMinutes()).padStart(2, '0');
			bookingForm.querySelector('#req_date').textContent = formatDate(dateLocal);
			bookingForm.querySelector('#req_time').textContent = timeLocal;
			bookingForm.querySelector('#zapis__booking-date').value = dateLocal;
			bookingForm.querySelector('#zapis__booking-time').value = timeLocal;
			bookingForm.querySelector('#zapis__time-combined').value = dateLocal + ' ' + timeLocal;
			bookingForm.querySelector('#zapis__booking-time-utc').value = fixedUtc;
			return true;
		}

		function submitBooking(formTokenValue) {
			hideErrors();
			var chosenTime = bookingForm.querySelector('input[name="time"]:checked');
			var split = null;
			if (!chosenTime) {
				if (!(isFixedCourseBooking() && applyFixedCourseSelection())) {
					setError(bookingForm.querySelector('.screen1'), 'Выберите дату и время');
					showScreen('screen1');
					return Promise.reject(new Error('time-required'));
				}
			} else {
				split = splitDateTime(chosenTime.value);
				if (!split) {
					setError(bookingForm.querySelector('.screen1'), 'Неверный формат времени');
					showScreen('screen1');
					return Promise.reject(new Error('time-invalid'));
				}

				bookingForm.querySelector('#zapis__booking-date').value = split.date;
				bookingForm.querySelector('#zapis__booking-time').value = split.time;
				bookingForm.querySelector('#zapis__time-combined').value = split.date + ' ' + split.time;
				bookingForm.querySelector('#req_date').textContent = formatDate(split.date);
				bookingForm.querySelector('#req_time').textContent = split.time;
			}

				var fd = new FormData(bookingForm);
				applyRequestToken(fd, formTokenValue || '');
				var chosenUtc = chosenTime ? String(chosenTime.getAttribute('data-time-utc') || '').trim() : '';
				if (chosenUtc) {
					fd.set('time_utc', chosenUtc);
					bookingForm.querySelector('#zapis__booking-time-utc').value = chosenUtc;
				} else if (isFixedCourseBooking()) {
					var fixedUtc = getFixedCourseUtcValue();
					if (fixedUtc) {
						fd.set('time_utc', fixedUtc);
						bookingForm.querySelector('#zapis__booking-time-utc').value = fixedUtc;
					}
				} else if (!isFixedCourseBooking()) {
					var dt = new Date(split.date + 'T' + split.time);
					if (!isNaN(dt.getTime())) {
						var fallbackUtc = dt.toISOString();
						fd.set('time_utc', fallbackUtc);
						bookingForm.querySelector('#zapis__booking-time-utc').value = fallbackUtc;
					}
				}
			try {
				fd.set('timezone', Intl.DateTimeFormat().resolvedOptions().timeZone);
			} catch (err) {}

			return fetch('<?php echo Route::_('index.php?option=com_ajax&group=ajax&plugin=lkbooking&format=json', false); ?>', {
				method: 'POST',
				body: fd,
				credentials: 'same-origin'
			})
			.then(function (r) { return r.json(); })
			.then(function (data) {
				var res = (data && data.data && typeof data.data.success !== 'undefined') ? data.data : data;
				if (!res || !res.success) {
					throw new Error((res && res.message) ? res.message : 'Ошибка записи');
				}
				if (activeBookingButton) {
					activeBookingButton.classList.remove('plus');
					activeBookingButton.classList.add('btn__am-yellow', 'fa', 'fa-check');
					decrementActiveStockCounter(activeBookingButton);
				}
				syncNotifyButtonVisibility();
				showScreen('screen4');
			});
		}

		function getCsrfTokenName() {
			var tokenName = '';
			try {
				if (window.Joomla && typeof window.Joomla.getOptions === 'function') {
					tokenName = String(window.Joomla.getOptions('csrf.token') || '');
				}
			} catch (e) {}
			if (!tokenName) {
				tokenName = '<?php echo $this->escape($pushnotifyTokenName); ?>';
			}
			return tokenName;
		}

		function applyRequestToken(fd, forcedTokenName) {
			var defaultToken = '<?php echo $this->escape($pushnotifyTokenName); ?>';
			fd.delete(defaultToken);
			var tokenName = String(forcedTokenName || '').trim() || getCsrfTokenName();
			fd.append(tokenName, '1');
		}

		function appendBookingSuccessFlag(url) {
			var src = String(url || '').trim();
			if (!src) {
				return '<?php echo Route::_('index.php?option=com_users&view=profile&booking_success=1', false); ?>';
			}
			try {
				var abs = new URL(src, window.location.origin);
				abs.searchParams.set('booking_success', '1');
				if (src.indexOf('http://') === 0 || src.indexOf('https://') === 0) {
					return abs.toString();
				}
				return abs.pathname + abs.search + abs.hash;
			} catch (e) {
				return src + (src.indexOf('?') === -1 ? '?' : '&') + 'booking_success=1';
			}
		}

		function redirectWithRetry(url) {
			var target = appendBookingSuccessFlag(url);
			try {
				window.location.assign(target);
			} catch (e) {
				window.location.href = target;
			}
			setTimeout(function () {
				try {
					window.location.replace(target);
				} catch (e) {}
			}, 350);
			setTimeout(function () {
				if (window.location.href !== target) {
					window.location.href = target;
				}
			}, 1200);
		}

		function formatIcsDateFromUtc(utcIso) {
			var dt = new Date(String(utcIso || '').trim());
			if (isNaN(dt.getTime())) {
				return '';
			}
			var y = dt.getUTCFullYear();
			var m = String(dt.getUTCMonth() + 1).padStart(2, '0');
			var d = String(dt.getUTCDate()).padStart(2, '0');
			var h = String(dt.getUTCHours()).padStart(2, '0');
			var i = String(dt.getUTCMinutes()).padStart(2, '0');
			var s = String(dt.getUTCSeconds()).padStart(2, '0');
			return '' + y + m + d + 'T' + h + i + s + 'Z';
		}

		function escapeIcsText(raw) {
			return String(raw || '')
				.replace(/\\/g, '\\\\')
				.replace(/\r?\n/g, '\\n')
				.replace(/,/g, '\\,')
				.replace(/;/g, '\\;');
		}

		function getBookingCalendarPayload() {
			var startUtc = String((bookingForm.querySelector('#zapis__booking-time-utc') || {}).value || '').trim();
			if (!startUtc) {
				return null;
			}
			var durationMin = parseInteger((bookingForm.querySelector('#zapis__duration-min') || {}).value, 60);
			durationMin = Math.max(15, Math.min(480, durationMin));
			var startDate = new Date(startUtc);
			if (isNaN(startDate.getTime())) {
				return null;
			}
			var endDate = new Date(startDate.getTime() + (durationMin * 60 * 1000));
			var serviceName = String((bookingForm.querySelector('#zapis__data-s-nm') || {}).value || 'Запись').trim();
			var title = serviceName ? ('Запись: ' + serviceName) : 'Запись';
			var description = ('Мастер: ' + String(bookingMasterName || ''));
			return {
				startDate: startDate,
				endDate: endDate,
				title: title,
				description: description,
				location: String(bookingAddress || '')
			};
		}

		function buildGoogleCalendarUrl(payload) {
			var start = formatIcsDateFromUtc(payload.startDate.toISOString());
			var end = formatIcsDateFromUtc(payload.endDate.toISOString());
			var params = new URLSearchParams();
			params.set('action', 'TEMPLATE');
			params.set('text', payload.title);
			params.set('dates', start + '/' + end);
			params.set('details', payload.description);
			if (payload.location) {
				params.set('location', payload.location);
			}
			return 'https://calendar.google.com/calendar/render?' + params.toString();
		}

		function buildIcsText(payload) {
			var lines = [
				'BEGIN:VCALENDAR',
				'VERSION:2.0',
				'PRODID:-//Vigling//Booking//RU',
				'CALSCALE:GREGORIAN',
				'METHOD:PUBLISH',
				'BEGIN:VEVENT',
				'UID:' + Date.now() + '-' + Math.random().toString(36).slice(2) + '@vigling',
				'DTSTAMP:' + formatIcsDateFromUtc(new Date().toISOString()),
				'DTSTART:' + formatIcsDateFromUtc(payload.startDate.toISOString()),
				'DTEND:' + formatIcsDateFromUtc(payload.endDate.toISOString()),
				'SUMMARY:' + escapeIcsText(payload.title),
				'DESCRIPTION:' + escapeIcsText(payload.description),
				'LOCATION:' + escapeIcsText(payload.location),
				'END:VEVENT',
				'END:VCALENDAR'
			];
			return lines.join('\r\n');
		}

		function downloadBookingIcs(payload) {
			if (!payload) {
				payload = getBookingCalendarPayload();
			}
			if (!payload) {
				return;
			}
			var icsText = buildIcsText(payload);
			var blob = new Blob([icsText], { type: 'text/calendar;charset=utf-8' });
			var a = document.createElement('a');
			a.href = URL.createObjectURL(blob);
			a.download = 'vigling-booking.ics';
			document.body.appendChild(a);
			a.click();
			setTimeout(function () {
				URL.revokeObjectURL(a.href);
				a.remove();
			}, 1000);
		}

		function tryOpenCalendarApp() {
			var payload = getBookingCalendarPayload();
			if (!payload) {
				return;
			}
			var googleUrl = buildGoogleCalendarUrl(payload);
			var ua = navigator.userAgent || '';
			var isIOS = /iP(hone|od|ad)/i.test(ua);
			var isAndroid = /Android/i.test(ua);
			var isMobile = isIOS || isAndroid;
			var fallbackDownload = function () {
				setTimeout(function () {
					if (!document.hidden) {
						downloadBookingIcs(payload);
					}
				}, 1200);
			};

			if (!isMobile) {
				window.open(googleUrl, '_blank', 'noopener');
				downloadBookingIcs(payload);
				return;
			}

			if (isIOS && navigator.share && window.File) {
				try {
					var file = new File([buildIcsText(payload)], 'vigling-booking.ics', { type: 'text/calendar' });
					navigator.share({
						title: payload.title,
						text: payload.description,
						files: [file]
					}).catch(function () {
						window.location.href = googleUrl;
						fallbackDownload();
					});
					return;
				} catch (e) {
				}
			}

			if (isAndroid) {
				var intentUrl = 'intent://calendar.google.com/calendar/render?' + googleUrl.split('?')[1] + '#Intent;scheme=https;package=com.google.android.calendar;end';
				window.location.href = intentUrl;
				setTimeout(function () {
					if (!document.hidden) {
						window.location.href = googleUrl;
						fallbackDownload();
					}
				}, 700);
				return;
			}

			window.location.href = googleUrl;
			fallbackDownload();
		}

		function setAuthMode(mode) {
			authMode = mode === 'login' ? 'login' : 'register';
			bookingForm.querySelectorAll('.js-auth-switch').forEach(function (btn) {
				btn.classList.toggle('active', btn.getAttribute('data-auth-tab') === authMode);
			});
			bookingForm.querySelectorAll('.js-auth-tab').forEach(function (tab) {
				if (tab.classList.contains('js-auth-' + authMode)) {
					tab.style.display = '';
				} else {
					tab.style.display = 'none';
				}
			});
		}

		function resetPasswordToggles() {
			bookingForm.querySelectorAll('.password-toggle').forEach(function (toggle) {
				var targetName = toggle.getAttribute('data-target-name');
				var target = targetName ? bookingForm.querySelector('input[name="' + targetName + '"]') : null;
				if (target) {
					target.setAttribute('type', 'password');
				}
				toggle.textContent = 'Показать';
			});
		}

		bookingForm.querySelectorAll('.password-toggle').forEach(function (toggle) {
			toggle.addEventListener('click', function () {
				var targetName = this.getAttribute('data-target-name');
				var target = targetName ? bookingForm.querySelector('input[name="' + targetName + '"]') : null;
				if (!target) {
					return;
				}
				var isPassword = target.getAttribute('type') === 'password';
				target.setAttribute('type', isPassword ? 'text' : 'password');
				this.textContent = isPassword ? 'Скрыть' : 'Показать';
			});
		});

		function validateGuestAuth(screen3) {
			if (authMode === 'login') {
				var login = bookingForm.querySelector('input[name="qa_login_username"]');
				var pass = bookingForm.querySelector('input[name="qa_login_password"]');
				if (!login || !login.value.trim() || !pass || !pass.value.trim()) {
					markFieldError('input[name="qa_login_username"]');
					markFieldError('input[name="qa_login_password"]');
					setError(screen3, 'Введите email и пароль');
					return false;
				}
				return true;
			}

			var name = bookingForm.querySelector('input[name="qa_name"]');
			var phone = bookingForm.querySelector('input[name="qa_phone"]');
			var email = bookingForm.querySelector('input[name="qa_email"]');
			var pass1 = bookingForm.querySelector('input[name="qa_password1"]');
			var pass2 = bookingForm.querySelector('input[name="qa_password2"]');
			if (!name || !name.value.trim()) {
				markFieldError('input[name="qa_name"]');
				setError(screen3, 'Введите имя');
				return false;
			}
			if (!phone || !phone.value.trim()) {
				markFieldError('input[name="qa_phone"]');
				setError(screen3, 'Введите телефон');
				return false;
			}
			if (!email || !email.value.trim()) {
				markFieldError('input[name="qa_email"]');
				setError(screen3, 'Введите email');
				return false;
			}
			if (!pass1 || !pass1.value.trim() || !pass2 || !pass2.value.trim()) {
				markFieldError('input[name="qa_password1"]');
				markFieldError('input[name="qa_password2"]');
				setError(screen3, 'Введите пароль и подтверждение');
				return false;
			}
			if (pass1.value !== pass2.value) {
				markFieldError('input[name="qa_password1"]');
				markFieldError('input[name="qa_password2"]');
				setError(screen3, 'Пароли не совпадают');
				return false;
			}
			return true;
		}

		function authAndSubmit(screen3, actionBtn) {
			if (!validateGuestAuth(screen3)) {
				return Promise.resolve(false);
			}
			setButtonLoading(actionBtn, true, authMode === 'login' ? 'Входим...' : 'Регистрируем...');

			var fd = new FormData();
			applyRequestToken(fd);
			if (authMode === 'login') {
				fd.append('action', 'login');
				fd.append('username', (bookingForm.querySelector('input[name="qa_login_username"]') || {}).value || '');
				fd.append('password', (bookingForm.querySelector('input[name="qa_login_password"]') || {}).value || '');
			} else {
				var email = ((bookingForm.querySelector('input[name="qa_email"]') || {}).value || '').trim();
				fd.append('action', 'register');
				fd.append('jform[name]', ((bookingForm.querySelector('input[name="qa_name"]') || {}).value || '').trim());
				fd.append('jform[profile][phone]', ((bookingForm.querySelector('input[name="qa_phone"]') || {}).value || '').trim());
				fd.append('jform[email1]', email);
				fd.append('jform[username]', email);
				fd.append('jform[password1]', ((bookingForm.querySelector('input[name="qa_password1"]') || {}).value || '').trim());
				fd.append('jform[password2]', ((bookingForm.querySelector('input[name="qa_password2"]') || {}).value || '').trim());
				fd.append('jform[registration_type]', 'client');
			}
			fd.append('format', 'json');
			var withRecaptcha = Promise.resolve();
			if (
				authMode === 'register'
				&& window.ViglingRecaptcha
				&& typeof window.ViglingRecaptcha.getToken === 'function'
				&& typeof window.ViglingRecaptcha.isEnabled === 'function'
				&& window.ViglingRecaptcha.isEnabled()
			) {
				withRecaptcha = window.ViglingRecaptcha.getToken('booking_quickauth_register').then(function (token) {
					if (!token) {
						throw new Error('empty token');
					}
					fd.append('recaptcha_token', token);
					fd.append('recaptcha_action', 'booking_quickauth_register');
				});
			}

			return withRecaptcha.then(function () {
				return fetch(quickAuthUrl, {
					method: 'POST',
					body: fd,
					credentials: 'same-origin'
				});
			})
			.then(function (r) { return r.json(); })
				.then(function (data) {
					var res = (data && data.data) ? data.data : data;
					if (!res || !res.success) {
						var serverMsg = (res && res.message) ? res.message : '';
						highlightQuickAuthErrorFields(serverMsg, authMode);
						setError(screen3, normalizeQuickAuthError(serverMsg, authMode));
						return false;
					}
					isLoggedIn = true;
					var lkRedirect = (res && res.redirect) ? String(res.redirect) : '<?php echo Route::_('index.php?option=com_users&view=profile', false); ?>';
					setButtonLoading(actionBtn, true, authMode === 'login' ? 'Вход выполнен. Записываем...' : 'Аккаунт создан. Записываем...');
					var slowBookingTimer = setTimeout(function () {
						updateButtonLoadingText(actionBtn, 'Завершаем запись...');
					}, 7000);
					return submitBooking((res && res.form_token) ? String(res.form_token) : '')
						.catch(function (err) {
							var msg = String((err && err.message) || '');
							if (/авториза|токен|token|session/i.test(msg)) {
								return new Promise(function (resolve) { setTimeout(resolve, 450); }).then(function () {
									return submitBooking('');
								});
							}
							throw err;
							})
							.then(function () {
								redirectAfterClose = true;
								redirectAfterCloseUrl = lkRedirect;
								return true;
							})
						.catch(function (err) {
							setError(screen3, err.message || 'Ошибка записи');
							return false;
						})
						.finally(function () {
							clearTimeout(slowBookingTimer);
						});
				})
				.catch(function (err) {
					var msg = String((err && err.message) || '');
					if (/recaptcha|token|robot|empty token/i.test(msg)) {
						setError(screen3, 'Подтвердите, что вы не робот');
					} else {
						setError(screen3, 'Ошибка соединения');
					}
					return false;
				})
				.finally(function () {
					setButtonLoading(actionBtn, false);
				});
		}

		bookingForm.querySelectorAll('.js-auth-switch').forEach(function (btn) {
			btn.addEventListener('click', function () {
				setAuthMode(this.getAttribute('data-auth-tab') || 'register');
				hideErrors();
			});
		});

		var guestPhoneInput = bookingForm.querySelector('input[name="qa_phone"]');
		if (guestPhoneInput) {
			guestPhoneInput.addEventListener('input', function () {
				var digits = this.value.replace(/\D/g, '');
				if (digits.charAt(0) === '8') {
					digits = '7' + digits.slice(1);
				}
				if (digits.charAt(0) !== '7' && digits.length > 0) {
					digits = '7' + digits;
				}
				digits = digits.slice(0, 11);
				if (!digits.length) {
					this.value = '';
					return;
				}
				var formatted = '+7';
				if (digits.length > 1) formatted += ' (' + digits.slice(1, 4);
				if (digits.length >= 4) formatted += ') ' + digits.slice(4, 7);
				if (digits.length >= 7) formatted += '-' + digits.slice(7, 9);
				if (digits.length >= 9) formatted += '-' + digits.slice(9, 11);
				this.value = formatted;
			});
		}

		document.querySelectorAll('.btn_add-master[data-booking-toggle="1"]').forEach(function (button) {
			button.addEventListener('click', function () {
				if (this.disabled || this.getAttribute('data-booking-disabled') === '1') {
					return;
				}
				activeBookingButton = this;
				updateSummaryFromButton(this);
			});
		});

		jQuery(bookingModal).on('shown.bs.modal', function () {
			hideErrors();
			showScreen('screen1');
			bookingForm.reset();
			resetPasswordToggles();
			updateSummaryFromButton(activeBookingButton);
			setAuthMode('register');
			redirectAfterClose = false;
			redirectAfterCloseUrl = '';
			syncNotifyButtonVisibility();
			if (isFixedCourseBooking() && applyFixedCourseSelection()) {
				showScreen('screen2');
			}

			var cal = jQuery(bookingModal).find('.calendar__master');
			if (cal.length) {
				if (!cal.hasClass('slick-initialized')) {
					cal.slick({
						infinite: false,
						slidesToShow: 5,
						slidesToScroll: 1,
						dots: false,
						arrows: true,
						responsive: [
							{ breakpoint: 1024, settings: { slidesToShow: 5, slidesToScroll: 1 } },
							{ breakpoint: 820, settings: { slidesToShow: 1, slidesToScroll: 1 } }
						]
					});
				} else {
					cal.slick('setPosition');
					cal.slick('refresh');
				}
				cal.removeClass('preload');
			}
			applyAvailableSlotsFilter();
		});

		jQuery(bookingModal).on('hidden.bs.modal', function () {
			if (redirectAfterClose && redirectAfterCloseUrl) {
				var to = String(redirectAfterCloseUrl);
				redirectAfterClose = false;
				redirectAfterCloseUrl = '';
				redirectWithRetry(to);
			}
		});

		bookingModal.querySelectorAll('.btn-next').forEach(function (nextBtn) {
			nextBtn.addEventListener('click', function () {
				hideErrors();
				var screen = this.closest('.screen');
				if (!screen) {
					return;
				}
				if (screen.classList.contains('screen1')) {
					var selected = bookingForm.querySelector('input[name="time"]:checked');
					if (!selected && !isFixedCourseBooking()) {
						setError(screen, 'Выберите дату и время');
						return;
					}
					if (selected) {
						var selectedParts = splitDateTime(selected.value);
						if (!selectedParts) {
							setError(screen, 'Неверный формат времени');
							return;
						}
						bookingForm.querySelector('#req_date').textContent = formatDate(selectedParts.date);
						bookingForm.querySelector('#req_time').textContent = selectedParts.time;
						bookingForm.querySelector('#zapis__booking-date').value = selectedParts.date;
						bookingForm.querySelector('#zapis__booking-time').value = selectedParts.time;
						bookingForm.querySelector('#zapis__time-combined').value = selectedParts.date + ' ' + selectedParts.time;
					} else if (!applyFixedCourseSelection()) {
						setError(screen, 'Неверное время курса');
						return;
					}
					showScreen('screen2');
					return;
				}
					if (screen.classList.contains('screen2')) {
						if (isLoggedIn) {
							redirectAfterClose = false;
							redirectAfterCloseUrl = '';
							var screen2Btn = this;
							setButtonLoading(screen2Btn, true, 'Записываем...');
							submitBooking()
							.catch(function (err) {
								setError(screen, err.message || 'Ошибка записи');
							})
							.finally(function () {
								setButtonLoading(screen2Btn, false);
							});
						return;
					}
					showScreen('screen3');
					return;
				}
				if (!screen.classList.contains('screen3')) {
					return;
				}

					if (!isLoggedIn) {
						authAndSubmit(screen, this);
						return;
					}

					redirectAfterClose = false;
					redirectAfterCloseUrl = '';
					submitBooking().catch(function (err) {
						setError(screen, err.message || 'Ошибка записи');
					});
				});
			});

		var addCalendarBtn = document.getElementById('zapis__add-calendar');
		if (addCalendarBtn) {
			addCalendarBtn.addEventListener('click', function () {
				tryOpenCalendarApp();
			});
		}
		var enableNotifyBtn = document.getElementById('zapis__enable-notify');
		if (enableNotifyBtn) {
			enableNotifyBtn.addEventListener('click', function () {
				if (window.jQuery) {
					window.jQuery('#zapis').modal('hide');
					return;
				}
				if (redirectAfterClose && redirectAfterCloseUrl) {
					var to = String(redirectAfterCloseUrl);
					redirectAfterClose = false;
					redirectAfterCloseUrl = '';
					redirectWithRetry(to);
				}
			});
		}
	}

	var mapAddresses = <?php echo json_encode($mapAddressCandidates, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
	var mapEl = document.getElementById('map');
	if (!mapEl) {
		return;
	}
	function initMap() {
		if (!window.ymaps) {
			return;
		}
		window.ymaps.ready(function () {
			var fallbackCenter = [55.751244, 37.618423];
			var map = null;

			function buildMap(center, placemarkGeoObject) {
				map = new window.ymaps.Map('map', { center: center, zoom: placemarkGeoObject ? 14 : 11, controls: [] });
				if (placemarkGeoObject) {
					map.geoObjects.add(new window.ymaps.Placemark(center, {
						balloonContent: placemarkGeoObject.getAddressLine ? placemarkGeoObject.getAddressLine() : ''
					}));
				}
			}

			function geocodeNext(index) {
				if (index >= mapAddresses.length) {
					buildMap(fallbackCenter, null);
					return;
				}

				window.ymaps.geocode(mapAddresses[index], { results: 1 }).then(function (res) {
					var first = res.geoObjects.get(0);
					if (!first || !first.geometry) {
						geocodeNext(index + 1);
						return;
					}

					var center = first.geometry.getCoordinates();
					if (!Array.isArray(center) || center.length < 2) {
						geocodeNext(index + 1);
						return;
					}

					buildMap(center, first);
				}).catch(function () {
					geocodeNext(index + 1);
				});
			}

			geocodeNext(0);
		});
	}
	if (window.ymaps) {
		initMap();
	} else {
		var script = document.createElement('script');
		script.src = 'https://api-maps.yandex.ru/2.1/?lang=ru-RU&apikey=705d45a1-9138-4d99-afd4-dc261c612036';
		script.async = true;
		script.defer = true;
		script.onload = initMap;
		document.head.appendChild(script);
	}
})();
</script>
<script>
(function () {
	function parsePositiveInt(value) {
		var n = parseInt(value, 10);
		return n > 0 ? n : 0;
	}

	function readStoredFilters() {
		try {
			var raw = sessionStorage.getItem('vigling_poisk_filters');
			if (!raw) {
				return null;
			}
			var data = JSON.parse(raw);
			if (!data) {
				return null;
			}
			return {
				cat_id: parsePositiveInt(data.cat_id),
				service: parsePositiveInt(data.service),
				tag: parsePositiveInt(data.tag)
			};
		} catch (e) {
			return null;
		}
	}

	function filtersFromUrl() {
		var params = new URLSearchParams(window.location.search);
		return {
			cat_id: parsePositiveInt(params.get('cat_id')),
			service: parsePositiveInt(params.get('service')),
			tag: parsePositiveInt(params.get('tag'))
		};
	}

	function applyHighlight(filters) {
		if (!filters || !filters.cat_id || !filters.service || !filters.tag) {
			return null;
		}
		var items = document.querySelectorAll('.master__services .priceList__item');
		var first = null;
		items.forEach(function (item) {
			var svcId = parsePositiveInt(item.getAttribute('data-svc-id'));
			var tagId = parsePositiveInt(item.getAttribute('data-tag-id'));
			var catId = parsePositiveInt(item.getAttribute('data-cat-id'));
			if (svcId !== filters.service || tagId !== filters.tag) {
				return;
			}
			if (catId > 0 && catId !== filters.cat_id) {
				return;
			}
			item.classList.add('highlighted-service');
			if (!first) {
				first = item;
			}
		});
		return first;
	}

	var highlighted = document.querySelector('.highlighted-service');
	if (!highlighted) {
		var urlFilters = filtersFromUrl();
		highlighted = applyHighlight(urlFilters);
		if (!highlighted) {
			highlighted = applyHighlight(readStoredFilters());
		}
	}
	if (highlighted && typeof highlighted.scrollIntoView === 'function') {
		window.setTimeout(function () {
			highlighted.scrollIntoView({ behavior: 'smooth', block: 'center' });
		}, 80);
	}
})();
</script>
