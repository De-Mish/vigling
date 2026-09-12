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
