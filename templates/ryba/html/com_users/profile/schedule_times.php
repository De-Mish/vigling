<?php

defined('_JEXEC') or die;

/**
 * Parses work_day / work_from / work_to without the plugin helper file.
 * Upload this whole file next to default_public.php.
 */
if (!function_exists('vigling_profile_normalize_clock')) {
	function vigling_profile_normalize_clock(string $raw): string
	{
		$raw = trim(str_replace('.', ':', $raw));
		if ($raw !== '' && preg_match('/^(\d{1,2}):(\d{2})$/', $raw, $m)) {
			return sprintf('%02d:%s', (int) $m[1], $m[2]);
		}

		return '';
	}
}

if (!function_exists('vigling_profile_is_day_map')) {
	function vigling_profile_is_day_map(array $decoded): bool
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
}

if (!function_exists('vigling_profile_assign_times')) {
	function vigling_profile_assign_times(array &$target, array $days, string $raw): void
	{
		$raw = trim($raw);
		if ($raw === '') {
			return;
		}
		$decoded = json_decode($raw, true);
		if (is_array($decoded)) {
			if (vigling_profile_is_day_map($decoded)) {
				foreach ($decoded as $key => $val) {
					$wd = (int) $key;
					if ($wd >= 1 && $wd <= 7 && is_scalar($val)) {
						$target[$wd] = vigling_profile_normalize_clock((string) $val);
					}
				}

				return;
			}
			$list = [];
			foreach ($decoded as $item) {
				if (!is_scalar($item)) {
					continue;
				}
				$val = vigling_profile_normalize_clock((string) $item);
				if ($val !== '') {
					$list[] = $val;
				}
			}
			if (count($list) === 1) {
				foreach ($days as $wd) {
					$target[(int) $wd] = $list[0];
				}

				return;
			}
			if (count($list) === count($days)) {
				foreach (array_values($days) as $idx => $wd) {
					$target[(int) $wd] = (string) ($list[$idx] ?? '');
				}
			}

			return;
		}
		$single = vigling_profile_normalize_clock($raw);
		if ($single === '') {
			return;
		}
		foreach ($days as $wd) {
			$target[(int) $wd] = $single;
		}
	}
}

if (!function_exists('vigling_profile_times_by_day')) {
	function vigling_profile_times_by_day(string $workDayRaw, string $workFromRaw, string $workToRaw): array
	{
		$decodedDays = json_decode(trim($workDayRaw), true);
		$vals = [];
		if (is_array($decodedDays)) {
			$iter = new RecursiveIteratorIterator(new RecursiveArrayIterator($decodedDays));
			foreach ($iter as $v) {
				if (is_scalar($v) && preg_match('/^\d+$/', (string) $v)) {
					$vals[] = (int) $v;
				}
			}
		} else {
			preg_match_all('/\d+/', $workDayRaw, $m);
			foreach (($m[0] ?? []) as $num) {
				$vals[] = (int) $num;
			}
		}
		$days = array_values(array_unique(array_filter($vals, static function ($v) {
			return $v >= 1 && $v <= 7;
		})));
		sort($days);
		$fromByDay = array_fill(1, 7, '');
		$toByDay = array_fill(1, 7, '');
		vigling_profile_assign_times($fromByDay, $days, $workFromRaw);
		vigling_profile_assign_times($toByDay, $days, $workToRaw);

		return [
			'days' => $days,
			'from' => $fromByDay,
			'to' => $toByDay,
		];
	}
}

if (!function_exists('vigling_profile_parse_time_to_minutes')) {
	function vigling_profile_parse_time_to_minutes(string $raw): ?int
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

if (!function_exists('vigling_profile_ranges_by_day')) {
	/**
	 * @return array<int, array{0:int, 1:int}>
	 */
	function vigling_profile_ranges_by_day(string $workDayRaw, string $workFromRaw, string $workToRaw): array
	{
		$parsed = vigling_profile_times_by_day($workDayRaw, $workFromRaw, $workToRaw);
		$result = [];
		foreach ($parsed['days'] as $wd) {
			$fromMin = vigling_profile_parse_time_to_minutes((string) ($parsed['from'][$wd] ?? ''));
			$toMin = vigling_profile_parse_time_to_minutes((string) ($parsed['to'][$wd] ?? ''));
			if ($fromMin === null || $toMin === null || $toMin <= $fromMin) {
				continue;
			}
			$result[(int) $wd] = [$fromMin, $toMin];
		}

		return $result;
	}
}

if (!function_exists('vigling_profile_encode_checked')) {
	/**
	 * @param array<int|string, mixed> $checkedDays
	 * @param array<int|string, mixed> $fromByDay
	 * @param array<int|string, mixed> $toByDay
	 * @return array{days: string[], fromJson: string, toJson: string}
	 */
	function vigling_profile_encode_checked(array $checkedDays, array $fromByDay, array $toByDay): array
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
			$fromVal = vigling_profile_normalize_clock((string) ($fromByDay[$wd] ?? $fromByDay[(string) $wd] ?? ''));
			$toVal = vigling_profile_normalize_clock((string) ($toByDay[$wd] ?? $toByDay[(string) $wd] ?? ''));
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
}

if (!function_exists('vigling_profile_is_times_json')) {
	function vigling_profile_is_times_json(string $raw): bool
	{
		$raw = trim($raw);
		if ($raw === '' || ($raw[0] !== '[' && $raw[0] !== '{')) {
			return false;
		}
		$decoded = json_decode($raw, true);

		return is_array($decoded);
	}
}

if (!function_exists('vigling_profile_snap_to_quarter_hour')) {
	function vigling_profile_snap_to_quarter_hour(string $value): string
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

		$totalMin = ((int) $dt->format('H')) * 60 + (int) $dt->format('i');
		$snapped = (int) round($totalMin / 15) * 15;
		if ($snapped >= 24 * 60) {
			return $dt->modify('+1 day')->setTime(0, 0, 0)->format('Y-m-d H:i:s');
		}

		return $dt->setTime(intdiv($snapped, 60), $snapped % 60, 0)->format('Y-m-d H:i:s');
	}
}

if (!function_exists('vigling_require_schedule_times')) {
	function vigling_require_schedule_times(): void
	{
		if (function_exists('vigling_profile_times_by_day')) {
			return;
		}
		$themes = defined('JPATH_THEMES') ? JPATH_THEMES : (defined('JPATH_ROOT') ? JPATH_ROOT . '/templates' : '');
		$candidates = [
			$themes !== '' ? $themes . '/ryba/html/com_users/profile/schedule_times.php' : '',
			defined('JPATH_ROOT') ? JPATH_ROOT . '/templates/ryba/html/com_users/profile/schedule_times.php' : '',
		];
		foreach (array_unique(array_filter($candidates)) as $file) {
			if (is_file($file)) {
				require_once $file;
				return;
			}
		}
	}
}
