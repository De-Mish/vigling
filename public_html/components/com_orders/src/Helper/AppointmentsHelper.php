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
		if (!in_array($mode, ['day', 'week', 'month', 'list'], true)) {
			$mode = 'day';
		}
		if ($mode === 'day' && $input->getCmd('entries', '') === 'archive') {
			$mode = 'list';
		}
		$extra = ['zapisi' => $mode];
		$start = trim((string) $input->getString('start', ''));
		$month = trim((string) $input->getString('month', ''));
		if ($mode === 'day' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $start)) {
			$extra['start'] = $start;
		}
		if ($mode === 'month' && preg_match('/^\d{4}-\d{2}$/', $month)) {
			$extra['month'] = $month;
		}
		if ($mode === 'list' && $input->getCmd('entries', '') === 'archive') {
			$extra['entries'] = 'archive';
			$shown = (int) $input->getInt('shown', 0);
			if ($shown > 7) {
				$extra['shown'] = (string) $shown;
			}
		} elseif ($mode === 'list') {
			$shownRaw = trim((string) $input->getString('shown', ''));
			if ($shownRaw === 'all' || (int) $shownRaw > 5) {
				$extra['shown'] = $shownRaw === 'all' ? 'all' : (string) (int) $shownRaw;
			}
		}

		return self::profileUrl($extra);
	}

	public static function fill(object $target, ?Input $input = null): object
	{
		$app = Factory::getApplication();
		$user = $app->getIdentity();
		$input = $input ?? $app->getInput();
		$masterHelper = JPATH_PLUGINS . '/user/vigling/src/Helper/MasterGroupHelper.php';
		if (is_file($masterHelper)) {
			require_once $masterHelper;
		}
		if (class_exists(\Joomla\Plugin\User\Vigling\Helper\MasterGroupHelper::class, false)) {
			$target->canBookTime = \Joomla\Plugin\User\Vigling\Helper\MasterGroupHelper::isMaster($user);
		} else {
			$groups = $user && $user->id ? array_map('intval', (array) $user->getAuthorisedGroups()) : [];
			$target->canBookTime = in_array(3, $groups, true) || in_array(8, $groups, true);
		}

		$layout = $input->getCmd('layout', 'default');
		$mode = $input->getCmd('zapisi', '');
		if ($mode === '') {
			$mode = $input->getCmd('mode', '');
		}
		if ($mode === '' && $layout === 'journal') {
			$mode = 'week';
		}
		if (!in_array($mode, ['day', 'week', 'month', 'list'], true)) {
			$mode = 'day';
		}
		if ($mode === 'day' && $input->getCmd('entries', '') === 'archive') {
			$mode = 'list';
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
		$target->dayArchiveUrl = self::profileUrl(['zapisi' => 'list', 'entries' => 'archive']);
		$target->listUrl = self::profileUrl(['zapisi' => 'list']);
		$target->weekUrl = self::profileUrl(['zapisi' => 'week']);
		$target->monthUrl = self::profileUrl(['zapisi' => 'month']);
		$target->monthCurrentUrl = self::profileUrl(['zapisi' => 'month']);
		$target->weekPrevUrl = self::profileUrl(['zapisi' => 'week']);
		$target->weekNextUrl = self::profileUrl(['zapisi' => 'week']);
		$target->monthPrevUrl = self::profileUrl(['zapisi' => 'month', 'month' => $monthStart->modify('-1 month')->format('Y-m')]);
		$target->monthNextUrl = self::profileUrl(['zapisi' => 'month', 'month' => $monthStart->modify('+1 month')->format('Y-m')]);
		$target->weekRangeUrl = Route::_('index.php?option=com_orders&task=orders.weekRange&format=json');

		$dayScope = '';
		$dayOrder = 'ASC';
		$listLimit = 500;
		$pageShown = 0;
		$fetchExtra = false;
		$selectedDay = $todayLocal;
		if ($mode === 'week') {
			$fromLocal = $weekStart;
			$toLocal = $weekStart->modify('+' . $weekDayCount . ' days');
			$fromUtc = $fromLocal->setTimezone($utc)->format('Y-m-d H:i:s');
			$toUtc = $toLocal->setTimezone($utc)->format('Y-m-d H:i:s');
		} elseif ($mode === 'month') {
			$fromLocal = self::mondayOf($monthStart);
			$monthEndExclusive = $monthStart->modify('+1 month');
			$lastDay = $monthEndExclusive->modify('-1 day');
			$dow = (int) $lastDay->format('N');
			$toLocal = $lastDay->modify('+' . (7 - $dow) . ' days')->modify('+1 day');
			$fromUtc = $fromLocal->setTimezone($utc)->format('Y-m-d H:i:s');
			$toUtc = $toLocal->setTimezone($utc)->format('Y-m-d H:i:s');
		} elseif ($mode === 'list' && $input->getCmd('entries', '') === 'archive') {
			$nowUtc = new \DateTimeImmutable('now', $utc);
			$toUtc = $nowUtc->format('Y-m-d H:i:s');
			$fromUtc = '';
			$dayScope = 'archive';
			$dayOrder = 'DESC';
			$pageShown = (int) $input->getInt('shown', 7);
			if ($pageShown < 7) {
				$pageShown = 7;
			}
			if ($pageShown > 5000) {
				$pageShown = 5000;
			}
			$listLimit = $pageShown + 1;
			$fetchExtra = true;
		} elseif ($mode === 'list') {
			$shownRaw = trim((string) $input->getString('shown', ''));
			$dayScope = 'all';
			$dayOrder = 'DESC';
			$fromUtc = '';
			$toUtc = '';
			if ($shownRaw === 'all') {
				$listLimit = 0;
				$pageShown = 0;
			} else {
				$pageShown = (int) $shownRaw >= 15 ? 15 : 5;
				$listLimit = $pageShown + 1;
				$fetchExtra = true;
			}
		} else {
			$parsedDay = self::parseDate($input->getString('start', ''), $tz);
			$selectedDay = $parsedDay instanceof \DateTimeImmutable ? $parsedDay->setTime(0, 0, 0) : $todayLocal;
			if ($selectedDay < $todayLocal) {
				$selectedDay = $todayLocal;
			}
			$fromUtc = $selectedDay->setTimezone($utc)->format('Y-m-d H:i:s');
			$toUtc = $selectedDay->modify('+1 day')->setTimezone($utc)->format('Y-m-d H:i:s');
			$dayScope = 'oneday';
			$dayOrder = 'ASC';
			$listLimit = 0;
		}

		$target->items = [];
		try {
			$model = $app->bootComponent('com_orders')->getMVCFactory()->createModel('Orders', 'Site', ['ignore_request' => true]);
			if ($model) {
				$model->setState('layout', 'appointments');
				$model->setState('list.limit', $listLimit);
				$model->setState('list.start', 0);
				$model->setState('as_master', 0);
				$model->setState('journal.from_utc', $fromUtc);
				$model->setState('journal.to_utc', $toUtc);
				$model->setState('journal.day_scope', $dayScope);
				$model->setState('journal.order', $dayOrder);
				$items = $model->getItems();
				$target->items = is_array($items) ? $items : [];
			}
		} catch (\Throwable $e) {
			$target->items = [];
		}

		$hasMore = false;
		if ($fetchExtra && count($target->items) > $pageShown) {
			$hasMore = true;
			$target->items = array_slice($target->items, 0, $pageShown);
		}
		$target->appointmentsHasMore = $hasMore;
		$target->appointmentsShown = $pageShown;
		$target->selectedDay = $selectedDay;
		$target->dayStripStart = $todayLocal;
		$target->dayStripEnd = $todayLocal->modify('+45 days');
		if ($selectedDay > $target->dayStripEnd) {
			$target->dayStripEnd = $selectedDay;
		}
		$target->appointmentsMoreUrl = '';
		$target->appointmentsMoreLabel = '';
		if ($hasMore && $dayScope === 'archive') {
			$target->appointmentsMoreUrl = self::profileUrl([
				'zapisi' => 'list',
				'entries' => 'archive',
				'shown' => (string) ($pageShown + 10),
			]);
			$target->appointmentsMoreLabel = 'Показать следующие 10';
		} elseif ($hasMore && $mode === 'list') {
			if ($pageShown < 15) {
				$target->appointmentsMoreUrl = self::profileUrl(['zapisi' => 'list', 'shown' => '15']);
				$target->appointmentsMoreLabel = 'Показать следующие 10';
			} else {
				$target->appointmentsMoreUrl = self::profileUrl(['zapisi' => 'list', 'shown' => 'all']);
				$target->appointmentsMoreLabel = 'Показать все';
			}
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