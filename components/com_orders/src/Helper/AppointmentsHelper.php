<?php

namespace Viglin\Component\Orders\Site\Helper;

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Router\Route;
use Joomla\Input\Input;

class AppointmentsHelper
{
	public static function profileUrl(array $extra = []): string
	{
		$app = Factory::getApplication();
		$query = array_merge([
			'option' => 'com_users',
			'view' => 'profile',
		], $extra);
		foreach ($query as $key => $value) {
			if ($value === null || $value === '') {
				unset($query[$key]);
			}
		}
		$profileItemId = 0;
		try {
			foreach ($app->getMenu()->getMenu() as $item) {
				$itemQuery = is_array($item->query ?? null) ? $item->query : [];
				if (($itemQuery['option'] ?? '') !== 'com_users' || ($itemQuery['view'] ?? '') !== 'profile') {
					continue;
				}
				if (($itemQuery['layout'] ?? 'default') !== 'default') {
					continue;
				}
				$profileItemId = (int) $item->id;
				break;
			}
		} catch (\Throwable $e) {
			$profileItemId = 0;
		}
		if ($profileItemId > 0) {
			$query['Itemid'] = $profileItemId;
		}

		return Route::_('index.php?' . http_build_query($query));
	}

	public static function redirectUrlFromRequest(?Input $input = null): string
	{
		$app = Factory::getApplication();
		$input = $input ?? $app->getInput();
		$layout = $input->getCmd('layout', 'default');
		$mode = $input->getCmd('zapisi', '');
		if ($mode === '') {
			$mode = $input->getCmd('mode', '');
		}
		if ($mode === '' && $layout === 'journal') {
			$mode = 'week';
		}
		if (!in_array($mode, ['day', 'week', 'month'], true)) {
			$mode = 'day';
		}
		$extra = ['zapisi' => $mode];
		$start = trim((string) $input->getString('start', ''));
		$month = trim((string) $input->getString('month', ''));
		if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $start)) {
			$extra['start'] = $start;
		}
		if (preg_match('/^\d{4}-\d{2}$/', $month)) {
			$extra['month'] = $month;
		}

		return self::profileUrl($extra);
	}

	public static function fill(object $target, ?Input $input = null): object
	{
		$app = Factory::getApplication();
		$user = $app->getIdentity();
		$input = $input ?? $app->getInput();
		$groups = $user && $user->id ? $user->getAuthorisedGroups() : [];
		$target->canBookTime = in_array(3, $groups, true) || in_array(8, $groups, true);

		$layout = $input->getCmd('layout', 'default');
		$mode = $input->getCmd('zapisi', '');
		if ($mode === '') {
			$mode = $input->getCmd('mode', '');
		}
		if ($mode === '' && $layout === 'journal') {
			$mode = 'week';
		}
		if (!in_array($mode, ['day', 'week', 'month'], true)) {
			$mode = 'day';
		}
		$target->appointmentsMode = $mode;
		$target->appointmentsEmbed = ($mode === 'week');

		require_once JPATH_SITE . '/components/com_orders/tmpl/orders/_reschedule_helper.php';
		$db = Factory::getContainer()->get(\Joomla\Database\DatabaseInterface::class);
		$tzName = function_exists('viglingOrdersGetUserTimezone')
			? viglingOrdersGetUserTimezone($db, (int) ($user->id ?? 0), (string) $app->get('offset', 'UTC'))
			: (string) $app->get('offset', 'UTC');
		try {
			$tz = new \DateTimeZone($tzName !== '' ? $tzName : 'UTC');
		} catch (\Throwable $e) {
			$tz = new \DateTimeZone('UTC');
		}
		$todayLocal = new \DateTimeImmutable('today', $tz);
		$utc = new \DateTimeZone('UTC');
		$monthStart = self::monthStart(self::parseMonth($input->getString('month', ''), $tz) ?? $todayLocal);
		$weekPastDays = 14;
		$weekFutureDays = 14;
		$weekFrom = self::parseDate($input->getString('from', ''), $tz);
		$weekDays = (int) $input->getInt('days', 0);
		if ($weekFrom instanceof \DateTimeImmutable && $weekDays > 0) {
			$weekDays = max(1, min(14, $weekDays));
			$weekStart = $weekFrom;
			$weekDayCount = $weekDays;
		} else {
			$weekStart = $todayLocal->modify('-' . $weekPastDays . ' days');
			$weekDayCount = $weekPastDays + 1 + $weekFutureDays;
		}
		$target->weekStartLocal = $weekStart;
		$target->weekDayCount = $weekDayCount;
		$target->monthCursor = $monthStart;
		$target->appointmentsBaseUrl = self::profileUrl(['zapisi' => 'day']);
		$target->dayUrl = self::profileUrl(['zapisi' => 'day']);
		$target->dayArchiveUrl = self::profileUrl(['zapisi' => 'day', 'entries' => 'archive']);
		$target->weekUrl = self::profileUrl(['zapisi' => 'week']);
		$target->monthUrl = self::profileUrl(['zapisi' => 'month']);
		$target->monthCurrentUrl = self::profileUrl(['zapisi' => 'month']);
		$target->weekPrevUrl = self::profileUrl(['zapisi' => 'week']);
		$target->weekNextUrl = self::profileUrl(['zapisi' => 'week']);
		$target->monthPrevUrl = self::profileUrl(['zapisi' => 'month', 'month' => $monthStart->modify('-1 month')->format('Y-m')]);
		$target->monthNextUrl = self::profileUrl(['zapisi' => 'month', 'month' => $monthStart->modify('+1 month')->format('Y-m')]);
		$target->weekRangeUrl = Route::_('index.php?option=com_orders&task=orders.weekRange&format=json');

		if ($mode === 'week') {
			$fromLocal = $weekStart;
			$toLocal = $weekStart->modify('+' . $weekDayCount . ' days');
		} elseif ($mode === 'month') {
			$fromLocal = self::mondayOf($monthStart);
			$monthEndExclusive = $monthStart->modify('+1 month');
			$lastDay = $monthEndExclusive->modify('-1 day');
			$dow = (int) $lastDay->format('N');
			$toLocal = $lastDay->modify('+' . (7 - $dow) . ' days')->modify('+1 day');
		} else {
			$fromLocal = $todayLocal->modify('-90 days');
			$toLocal = $todayLocal->modify('+2 years');
		}

		$target->items = [];
		try {
			$model = $app->bootComponent('com_orders')->getMVCFactory()->createModel('Orders', 'Site', ['ignore_request' => true]);
			if ($model) {
				$model->setState('layout', 'appointments');
				$model->setState('list.limit', 500);
				$model->setState('list.start', 0);
				$model->setState('as_master', 0);
				$model->setState('journal.from_utc', $fromLocal->setTimezone($utc)->format('Y-m-d H:i:s'));
				$model->setState('journal.to_utc', $toLocal->setTimezone($utc)->format('Y-m-d H:i:s'));
				$items = $model->getItems();
				$target->items = is_array($items) ? $items : [];
			}
		} catch (\Throwable $e) {
			$target->items = [];
		}

		return $target;
	}

	private static function parseDate(string $raw, \DateTimeZone $tz): ?\DateTimeImmutable
	{
		$raw = trim($raw);
		if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)) {
			return null;
		}
		try {
			return new \DateTimeImmutable($raw, $tz);
		} catch (\Throwable $e) {
			return null;
		}
	}

	private static function parseMonth(string $raw, \DateTimeZone $tz): ?\DateTimeImmutable
	{
		$raw = trim($raw);
		if (!preg_match('/^\d{4}-\d{2}$/', $raw)) {
			return null;
		}
		try {
			return new \DateTimeImmutable($raw . '-01', $tz);
		} catch (\Throwable $e) {
			return null;
		}
	}

	private static function mondayOf(\DateTimeImmutable $day): \DateTimeImmutable
	{
		$n = (int) $day->format('N');

		return $day->modify('-' . ($n - 1) . ' days')->setTime(0, 0, 0);
	}

	private static function monthStart(\DateTimeImmutable $day): \DateTimeImmutable
	{
		return $day->modify('first day of this month')->setTime(0, 0, 0);
	}
}
