<?php

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Session\Session;
use Joomla\CMS\Uri\Uri;

require_once __DIR__ . '/_reschedule_helper.php';
require_once __DIR__ . '/_appointments_lib.php';

/** @var \Viglin\Component\Orders\Site\View\Orders\HtmlView $this */
$src = (isset($appointments) && is_object($appointments)) ? $appointments : $this;
$items = is_array($src->items ?? null) ? $src->items : [];
$user = Factory::getApplication()->getIdentity();
$masterId = (int) ($user->id ?? 0);
$token = Session::getFormToken();
$returnEncoded = base64_encode(Uri::getInstance()->toString());
$db = Factory::getContainer()->get(\Joomla\Database\DatabaseInterface::class);
$journalRangeChunk = !empty($journalRangeChunk);
$payload = ['timezone' => 'UTC', 'days' => []];
if (!$journalRangeChunk) {
	$payload = $masterId > 0 ? viglingOrdersBuildRescheduleSlots($db, $masterId, 15, 0, 0, 45) : ['timezone' => 'UTC', 'days' => []];
} elseif (function_exists('viglingOrdersGetUserTimezone')) {
	$payload['timezone'] = viglingOrdersGetUserTimezone($db, $masterId, (string) Factory::getApplication()->get('offset', 'UTC'));
}
$days = $payload['days'] ?? [];
$journalTimezone = (string) ($payload['timezone'] ?? 'UTC');
$addAction = Route::_('index.php?option=com_orders&task=orders.journalAdd');
$deleteAction = Route::_('index.php?option=com_orders&task=orders.journalDelete');
$rescheduleAction = Route::_('index.php?option=com_orders&task=orders.rescheduleByMaster');
$rescheduleClientAction = Route::_('index.php?option=com_orders&task=orders.reschedule');
$rescheduleCourseAction = Route::_('index.php?option=com_orders&task=orders.rescheduleCourseSlotByMaster');
$rescheduleSearchAction = Route::_('index.php?option=com_orders&task=orders.rescheduleSearchSlotByMaster');
$rescheduleSlotsAction = Route::_('index.php?option=com_orders&task=orders.rescheduleSlots');

$utc = new \DateTimeZone('UTC');
try {
	$journalTz = new \DateTimeZone($journalTimezone !== '' ? $journalTimezone : 'UTC');
} catch (\Throwable $e) {
	$journalTz = new \DateTimeZone('UTC');
}
$nowUtc = new \DateTimeImmutable('now', $utc);
$todayLocal = new \DateTimeImmutable('today', $journalTz);
$monthShort = [1 => 'янв', 2 => 'фев', 3 => 'мар', 4 => 'апр', 5 => 'май', 6 => 'июн', 7 => 'июл', 8 => 'авг', 9 => 'сен', 10 => 'окт', 11 => 'ноя', 12 => 'дек'];
$dowShort = [1 => 'Пн', 2 => 'Вт', 3 => 'Ср', 4 => 'Чт', 5 => 'Пт', 6 => 'Сб', 7 => 'Вс'];
$appointmentsEmbed = !empty($src->appointmentsEmbed);
$canBookTime = !isset($src->canBookTime) || !empty($src->canBookTime);
$pastDays = 21;
$futureDays = 21;
$dayCount = $pastDays + $futureDays;
$pxPerMin = 1.35;
$scheduleByDay = $masterId > 0 ? viglingOrdersLoadMasterSchedule($db, $masterId) : [];
$boardStartLocal = $todayLocal->modify('-' . $pastDays . ' day');
if ($appointmentsEmbed) {
	if (function_exists('viglingOrdersGetUserTimezone')) {
		$journalTimezone = viglingOrdersGetUserTimezone($db, $masterId, (string) Factory::getApplication()->get('offset', 'UTC'));
		try {
			$journalTz = new \DateTimeZone($journalTimezone !== '' ? $journalTimezone : 'UTC');
		} catch (\Throwable $e) {
			$journalTz = new \DateTimeZone('UTC');
		}
		$todayLocal = new \DateTimeImmutable('today', $journalTz);
	}
	$pastDays = 14;
	$futureDays = 14;
	if (!empty($src->weekStartLocal) && $src->weekStartLocal instanceof \DateTimeImmutable) {
		$boardStartLocal = $src->weekStartLocal->setTimezone($journalTz)->setTime(0, 0, 0);
	} else {
		$boardStartLocal = $todayLocal->modify('-' . $pastDays . ' day');
	}
	$dayCount = !empty($src->weekDayCount) ? (int) $src->weekDayCount : ($pastDays + 1 + $futureDays);
	if ($dayCount < 1) {
		$dayCount = $pastDays + 1 + $futureDays;
	}
}

$formatMinutes = static function (int $minutes): string {
	$minutes = max(0, min(24 * 60, $minutes));
	return sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);
};

$contactBits = static function ($item): array {
	$bookingPhone = trim((string) ($item->contact_phone ?? ''));
	$bookingContactName = trim((string) ($item->contact_name ?? ''));
	return array_values(array_filter([
		$bookingContactName !== '' && $bookingContactName !== trim((string) ($item->client_name ?? '')) ? $bookingContactName : '',
		$bookingPhone !== '' ? $bookingPhone : '',
		trim((string) ($item->client_email ?? '')),
		$bookingPhone === '' ? trim((string) ($item->client_phone ?? '')) : '',
	], static fn($v) => $v !== '' && $v !== '—'));
};

$parseJournalLabel = static function (string $label): array {
	$comment = '';
	if (strpos($label, '[journal]') === 0) {
		$label = trim(substr($label, 9));
	}
	$commentSeparator = '| Комментарий:';
	if (strpos($label, $commentSeparator) !== false) {
		$labelParts = explode($commentSeparator, $label, 2);
		$label = trim((string) ($labelParts[0] ?? ''));
		$comment = trim((string) ($labelParts[1] ?? ''));
	}
	if ($label === '' || $label === 'Блок времени') {
		$label = 'Забронировать время';
	}
	return [$label, $comment];
};

$renderOrderActions = static function ($item, bool $isPast, bool $completed, string $token, string $returnEncoded, string $timeIso): string {
	$durationMin = 60;
	$isFixedCourse = trim((string) ($item->booking_kind ?? 'service')) === 'course' && (int) ($item->course_slot_id ?? 0) > 0;
	$isFixedSearch = trim((string) ($item->booking_kind ?? 'service')) === 'search' && (int) ($item->search_slot_id ?? 0) > 0;
	$timeUtc = !empty($item->time) ? new \DateTime((string) $item->time, new \DateTimeZone('UTC')) : null;
	$timeToUtc = !empty($item->time_to) ? new \DateTime((string) $item->time_to, new \DateTimeZone('UTC')) : null;
	if ($timeUtc && $timeToUtc) {
		$diff = (int) floor(($timeToUtc->getTimestamp() - $timeUtc->getTimestamp()) / 60);
		if ($diff > 0) {
			$durationMin = max(15, min(480, $diff));
		}
	}
	ob_start();
	?>
	<?php if ($isPast) : ?>
		<?php if (!$completed) : ?>
			<form method="post" action="<?php echo Route::_('index.php?option=com_orders&task=orders.completeByMaster'); ?>" class="form-inline" style="display:inline;">
				<input type="hidden" name="<?php echo $token; ?>" value="1">
				<input type="hidden" name="id" value="<?php echo (int) $item->id; ?>">
				<input type="hidden" name="return" value="<?php echo $returnEncoded; ?>">
				<button type="submit" class="btn btn-xs btn-success">Запись выполнена</button>
			</form>
		<?php else : ?>
			<form method="post" action="<?php echo Route::_('index.php?option=com_orders&task=orders.deleteByMaster'); ?>" class="form-inline" style="display:inline;">
				<input type="hidden" name="<?php echo $token; ?>" value="1">
				<input type="hidden" name="id" value="<?php echo (int) $item->id; ?>">
				<input type="hidden" name="return" value="<?php echo $returnEncoded; ?>">
				<button type="submit" class="btn btn-xs btn-default" onclick="return confirm('Удалить запись из списка?');">Удалить</button>
			</form>
		<?php endif; ?>
	<?php else : ?>
		<button type="button" class="btn btn-xs btn-warning reschedule-open" data-id="<?php echo (int) $item->id; ?>" data-duration="<?php echo (int) $durationMin; ?>" data-current-utc="<?php echo htmlspecialchars($timeIso, ENT_QUOTES, 'UTF-8'); ?>">Перенести</button>
		<form method="post" action="<?php echo Route::_('index.php?option=com_orders&task=orders.cancelByMaster'); ?>" class="form-inline" style="display:inline;">
			<input type="hidden" name="<?php echo $token; ?>" value="1">
			<input type="hidden" name="id" value="<?php echo (int) $item->id; ?>">
			<input type="hidden" name="return" value="<?php echo $returnEncoded; ?>">
			<button type="submit" class="btn btn-xs btn-danger" onclick="return confirm('<?php echo ($isFixedCourse || $isFixedSearch) ? 'Отменить участие этого клиента? Ему придёт уведомление.' : 'Отменить запись? Клиенту придёт уведомление.'; ?>');">Отменить</button>
		</form>
	<?php endif; ?>
	<?php
	return (string) ob_get_clean();
};

$renderCourseSlotActions = static function ($item, bool $isPast, string $token, string $returnEncoded, string $rescheduleCourseAction): string {
	$courseSlotId = (int) ($item->course_slot_id ?? 0);
	$durationMin = (int) ($item->course_slot_end_utc && $item->course_slot_start_utc
		? max(15, min(480, (int) floor((strtotime((string) $item->course_slot_end_utc) - strtotime((string) $item->course_slot_start_utc)) / 60)))
		: (int) ($item->duration_min ?? 60));
	if ($durationMin <= 0) {
		$durationMin = 60;
	}
	$timeIso = '';
	if (!empty($item->time)) {
		try {
			$timeIso = (new \DateTime((string) $item->time, new \DateTimeZone('UTC')))->format('c');
		} catch (\Throwable $e) {
			$timeIso = '';
		}
	}
	ob_start();
	?>
	<?php if (!$isPast) : ?>
		<button type="button" class="btn btn-xs btn-warning reschedule-open" data-course-slot-id="<?php echo $courseSlotId; ?>" data-reschedule-action="<?php echo htmlspecialchars($rescheduleCourseAction, ENT_QUOTES, 'UTF-8'); ?>" data-duration="<?php echo $durationMin; ?>" data-current-utc="<?php echo htmlspecialchars($timeIso, ENT_QUOTES, 'UTF-8'); ?>">Перенести</button>
		<form method="post" action="<?php echo Route::_('index.php?option=com_orders&task=orders.cancelCourseSlotByMaster'); ?>" class="form-inline" style="display:inline;">
			<input type="hidden" name="<?php echo $token; ?>" value="1">
			<input type="hidden" name="course_slot_id" value="<?php echo $courseSlotId; ?>">
			<input type="hidden" name="return" value="<?php echo $returnEncoded; ?>">
			<button type="submit" class="btn btn-xs btn-danger" onclick="return confirm('Отменить курс для всех участников? Всем придёт уведомление.');">Отменить</button>
		</form>
	<?php endif; ?>
	<?php
	return (string) ob_get_clean();
};

$renderSearchSlotActions = static function ($item, bool $isPast, string $token, string $returnEncoded, string $rescheduleSearchAction): string {
	$searchSlotId = (int) ($item->search_slot_id ?? 0);
	$durationMin = (int) ($item->search_slot_end_utc && $item->search_slot_start_utc
		? max(15, min(480, (int) floor((strtotime((string) $item->search_slot_end_utc) - strtotime((string) $item->search_slot_start_utc)) / 60)))
		: (int) ($item->duration_min ?? 60));
	if ($durationMin <= 0) {
		$durationMin = 60;
	}
	$timeIso = '';
	if (!empty($item->time)) {
		try {
			$timeIso = (new \DateTime((string) $item->time, new \DateTimeZone('UTC')))->format('c');
		} catch (\Throwable $e) {
			$timeIso = '';
		}
	}
	ob_start();
	?>
	<?php if (!$isPast) : ?>
		<button type="button" class="btn btn-xs btn-warning reschedule-open" data-search-slot-id="<?php echo $searchSlotId; ?>" data-reschedule-action="<?php echo htmlspecialchars($rescheduleSearchAction, ENT_QUOTES, 'UTF-8'); ?>" data-duration="<?php echo $durationMin; ?>" data-current-utc="<?php echo htmlspecialchars($timeIso, ENT_QUOTES, 'UTF-8'); ?>">Перенести</button>
		<form method="post" action="<?php echo Route::_('index.php?option=com_orders&task=orders.cancelSearchSlotByMaster'); ?>" class="form-inline" style="display:inline;">
			<input type="hidden" name="<?php echo $token; ?>" value="1">
			<input type="hidden" name="search_slot_id" value="<?php echo $searchSlotId; ?>">
			<input type="hidden" name="return" value="<?php echo $returnEncoded; ?>">
			<button type="submit" class="btn btn-xs btn-danger" onclick="return confirm('Отменить поиск для всех участников? Всем придёт уведомление.');">Отменить</button>
		</form>
	<?php endif; ?>
	<?php
	return (string) ob_get_clean();
};

$displayRows = [];
foreach ($items as $item) {
	$bookingKind = trim((string) ($item->booking_kind ?? 'service'));
	$courseSlotId = (int) ($item->course_slot_id ?? 0);
	$searchSlotId = (int) ($item->search_slot_id ?? 0);
	$userId = (int) ($item->user_id ?? 0);
	$isMasterOfItem = (int) ($user->id ?? 0) > 0 && (int) ($item->master_id ?? 0) === (int) ($user->id ?? 0);
	if ($userId <= 0 || $bookingKind === 'journal') {
		$displayRows[] = ['type' => 'block', 'item' => $item];
		continue;
	}
	if ($isMasterOfItem && $bookingKind === 'course' && $courseSlotId > 0) {
		$key = 'course-slot-' . $courseSlotId;
		if (!isset($displayRows[$key])) {
			$displayRows[$key] = ['type' => 'course-group', 'kind' => 'course', 'slot_id' => $courseSlotId, 'item' => $item, 'participants' => []];
		}
		$displayRows[$key]['participants'][] = $item;
		continue;
	}
	if ($isMasterOfItem && $bookingKind === 'search' && $searchSlotId > 0) {
		$key = 'search-slot-' . $searchSlotId;
		if (!isset($displayRows[$key])) {
			$displayRows[$key] = ['type' => 'search-group', 'kind' => 'search', 'slot_id' => $searchSlotId, 'item' => $item, 'participants' => []];
		}
		$displayRows[$key]['participants'][] = $item;
		continue;
	}
	$displayRows[] = ['type' => 'single', 'item' => $item];
}

$boardDays = [];
for ($offset = 0; $offset < $dayCount; $offset++) {
	$day = $boardStartLocal->modify('+' . $offset . ' day');
	$dateKey = $day->format('Y-m-d');
	$boardDays[$dateKey] = [
		'date' => $dateKey,
		'dow' => (int) $day->format('N'),
		'dow_label' => $dowShort[(int) $day->format('N')] ?? '',
		'day_num' => (int) $day->format('j'),
		'month_label' => $monthShort[(int) $day->format('n')] ?? '',
		'date_view' => $day->format('d.m.Y'),
		'is_today' => $dateKey === $todayLocal->format('Y-m-d'),
		'events' => [],
	];
}
$todayIndex = array_search($todayLocal->format('Y-m-d'), array_keys($boardDays), true);
if ($todayIndex === false) {
	$todayIndex = max(0, (int) $pastDays);
}

$gridStart = 8 * 60;
$gridEnd = 21 * 60;
foreach ($scheduleByDay as $range) {
	if (!is_array($range) || count($range) < 2) {
		continue;
	}
	$gridStart = min($gridStart, (int) $range[0]);
	$gridEnd = max($gridEnd, (int) $range[1]);
}

$eventIndex = 0;
foreach ($displayRows as $row) {
	$item = $row['item'];
	$startUtc = !empty($item->time) ? new \DateTimeImmutable((string) $item->time, $utc) : null;
	$endUtc = !empty($item->time_to) ? new \DateTimeImmutable((string) $item->time_to, $utc) : null;
	if (!$startUtc || !$endUtc || $endUtc <= $startUtc) {
		continue;
	}
	$startLocal = $startUtc->setTimezone($journalTz);
	$endLocal = $endUtc->setTimezone($journalTz);
	$dateKey = $startLocal->format('Y-m-d');
	if (!isset($boardDays[$dateKey])) {
		continue;
	}
	$startMin = ((int) $startLocal->format('H')) * 60 + (int) $startLocal->format('i');
	$endMin = ((int) $endLocal->format('H')) * 60 + (int) $endLocal->format('i');
	if ($endLocal->format('Y-m-d') !== $dateKey) {
		$endMin = 24 * 60;
	}
	$endMin = max($startMin + 15, $endMin);
	$gridStart = min($gridStart, $startMin);
	$gridEnd = max($gridEnd, $endMin);
	$type = (string) ($row['type'] ?? 'single');
	$kind = $type === 'block' ? 'journal' : (string) ($row['kind'] ?? ($item->booking_kind ?? 'service'));
	$eventId = 'evt-' . $dateKey . '-' . (int) ($item->id ?? 0) . '-' . (++$eventIndex);
	$title = '';
	$service = trim((string) ($item->service_display_name ?? $item->service_name ?? ''));
	if ($type === 'block') {
		[$title, $blockComment] = $parseJournalLabel(trim((string) ($item->service_name ?? '')));
		if (trim((string) ($item->comment ?? '')) !== '') {
			$blockComment = trim((string) $item->comment);
		}
		$service = $title;
		$item->_journal_comment = $blockComment;
	} elseif ($type === 'course-group' || $type === 'search-group') {
		$isSearch = $type === 'search-group';
		$title = $isSearch ? 'Поиск моделей' : 'Курс';
		$service = trim((string) ($item->service_display_name ?? $item->service_name ?? $title));
	} else {
		$viewerId = (int) ($user->id ?? 0);
		$title = trim((string) ($item->client_name ?? 'Клиент'));
		if ($viewerId > 0 && (int) ($item->master_id ?? 0) !== $viewerId) {
			$title = trim((string) ($item->master_name ?? $title));
		}
	}
	$boardDays[$dateKey]['events'][] = [
		'id' => $eventId,
		'type' => $type,
		'kind' => $kind,
		'startMin' => $startMin,
		'endMin' => $endMin,
		'title' => $title,
		'service' => $service,
		'row' => $row,
		'item' => $item,
		'startLocal' => $startLocal,
		'endLocal' => $endLocal,
		'timeIso' => $startUtc->format('c'),
	];
}

$gridStart = (int) (floor(max(0, $gridStart) / 60) * 60);
$gridEnd = (int) (ceil(min(24 * 60, max($gridStart + 60, $gridEnd)) / 60) * 60);
if ($journalRangeChunk) {
	$reqStart = (int) Factory::getApplication()->getInput()->getInt('grid_start', 0);
	$reqEnd = (int) Factory::getApplication()->getInput()->getInt('grid_end', 0);
	if ($reqStart >= 0 && $reqEnd > $reqStart) {
		$gridStart = $reqStart;
		$gridEnd = $reqEnd;
	}
}
$gridHeight = max(60, $gridEnd - $gridStart);
$hourMarks = [];
for ($mark = $gridStart; $mark < $gridEnd; $mark += 60) {
	$hourMarks[] = $mark;
}

$packDayEvents = static function (array $events): array {
	usort($events, static function ($a, $b) {
		if ($a['startMin'] === $b['startMin']) {
			return $b['endMin'] <=> $a['endMin'];
		}
		return $a['startMin'] <=> $b['startMin'];
	});
	$colEnds = [];
	foreach ($events as $i => $event) {
		$col = 0;
		while (isset($colEnds[$col]) && $colEnds[$col] > $event['startMin']) {
			$col++;
		}
		$events[$i]['col'] = $col;
		$colEnds[$col] = $event['endMin'];
	}
	$count = count($events);
	for ($i = 0; $i < $count; $i++) {
		$maxCol = (int) ($events[$i]['col'] ?? 0);
		for ($j = 0; $j < $count; $j++) {
			if ($events[$i]['startMin'] < $events[$j]['endMin'] && $events[$j]['startMin'] < $events[$i]['endMin']) {
				$maxCol = max($maxCol, (int) ($events[$j]['col'] ?? 0));
			}
		}
		$events[$i]['cols'] = $maxCol + 1;
	}
	return $events;
};

foreach ($boardDays as $dateKey => $day) {
	$boardDays[$dateKey]['events'] = $packDayEvents($day['events']);
}

$kindClass = static function (string $kind): string {
	if ($kind === 'course') {
		return 'is-course';
	}
	if ($kind === 'search') {
		return 'is-search';
	}
	if ($kind === 'journal') {
		return 'is-block';
	}
	return 'is-service';
};
if ($journalRangeChunk) {
	ob_start();
	$journalCellPart = 'heads';
	include __DIR__ . '/_journal_cells.php';
	$journalChunkHeads = trim((string) ob_get_clean());
	ob_start();
	$journalCellPart = 'cols';
	include __DIR__ . '/_journal_cells.php';
	$journalChunkCols = trim((string) ob_get_clean());
	ob_start();
	$journalCellPart = 'details';
	include __DIR__ . '/_journal_cells.php';
	$journalChunkDetails = trim((string) ob_get_clean());
	$journalChunkFrom = $boardStartLocal->format('Y-m-d');
	$journalChunkDays = (int) $dayCount;
} else {
?>
<div class="com_orders orders-journal" style="--journal-days: <?php echo (int) $dayCount; ?>; --journal-gutter: 64px; --journal-col: 168px;">
	<style>
	.com_orders.orders-journal .journal-toolbar {
		display: flex;
		flex-wrap: wrap;
		gap: 12px;
		align-items: center;
		justify-content: space-between;
		margin: 0 0 14px;
	}
	.com_orders.orders-journal .journal-toolbar h1 {
		margin: 0;
	}
	.com_orders.orders-journal .journal-nav {
		display: flex;
		gap: 8px;
		align-items: center;
	}
	.com_orders.orders-journal .journal-nav button,
	.com_orders.orders-journal .journal-nav a.journal-nav-link {
		min-width: 40px;
		height: 36px;
		border: 1px solid #bbb;
		border-radius: 8px;
		background: #fff;
		font-size: 22px;
		line-height: 1;
		cursor: pointer;
		display: inline-flex;
		align-items: center;
		justify-content: center;
		text-decoration: none;
		color: #111;
	}
	.com_orders.orders-journal .journal-time-gutter {
		position: sticky;
		left: 0;
		z-index: 5;
		background: #fff;
		border-right: 1px solid #ececec;
	}
	.com_orders.orders-journal .journal-board__head .journal-time-gutter {
		z-index: 6;
	}
	.com_orders.orders-journal .journal-nav button:disabled {
		opacity: .4;
		cursor: default;
	}
	.com_orders.orders-journal .journal-today-btn {
		display: inline-flex;
		align-items: center;
		gap: 6px;
		margin: 0;
	}
	.com_orders.orders-journal .journal-meta {
		margin: 0;
		color: #707070;
		font-size: 13px;
	}
	.com_orders.orders-journal .journal-week-label {
		font-size: 18px;
		font-weight: 700;
		line-height: 1.2;
	}
	.com_orders.orders-journal .journal-board {
		background: #fff;
		border: 1px solid #e4e4e4;
		border-radius: 12px;
		overflow: hidden;
	}
	.com_orders.orders-journal .journal-board__scroll {
		overflow: auto;
		max-height: calc(100vh - 210px);
		-webkit-overflow-scrolling: touch;
		overscroll-behavior: contain;
		scrollbar-width: thin;
		scrollbar-color: #888 #e6e6e6;
	}
	.com_orders.orders-journal .journal-board__scroll::-webkit-scrollbar {
		width: 10px;
		height: 10px;
	}
	.com_orders.orders-journal .journal-board__scroll::-webkit-scrollbar-track {
		background: #e6e6e6;
	}
	.com_orders.orders-journal .journal-board__scroll::-webkit-scrollbar-thumb {
		background: #888;
		border-radius: 8px;
	}
	.com_orders.orders-journal .journal-board__inner {
		min-width: max(100%, calc(var(--journal-gutter) + var(--journal-days) * var(--journal-col)));
	}
	.com_orders.orders-journal .journal-board__head,
	.com_orders.orders-journal .journal-board__body {
		display: grid;
		grid-template-columns: var(--journal-gutter) repeat(var(--journal-days), minmax(var(--journal-col), 1fr));
	}
	.com_orders.orders-journal .journal-board__head {
		position: sticky;
		top: 0;
		z-index: 4;
		background: #fff;
		border-bottom: 1px solid #ececec;
	}
	.com_orders.orders-journal .journal-day-head {
		display: flex;
		flex-direction: column;
		align-items: center;
		justify-content: center;
		gap: 0;
		min-height: 0;
		padding: 2px 4px 1px;
		line-height: 1.05;
		text-align: center;
		border-right: 1px solid #f0f0f0;
	}
	.com_orders.orders-journal .journal-day-head.is-today {
		background: #fff8dc;
	}
	.com_orders.orders-journal .journal-day-head .dow {
		display: block;
		font-size: 11px;
		color: #888;
		text-transform: lowercase;
		line-height: 1.05;
	}
	.com_orders.orders-journal .journal-day-head .date {
		display: block;
		margin-top: 0;
		font-size: 14px;
		font-weight: 700;
		line-height: 1.05;
	}
	.com_orders.orders-journal .journal-day-head.is-today .date {
		display: inline-flex;
		align-items: center;
		justify-content: center;
		min-width: 22px;
		height: 22px;
		margin-top: 0;
		border-radius: 50%;
		background: #ffc107;
		color: #111;
	}
	.com_orders.orders-journal .journal-day-head .month {
		display: block;
		font-size: 11px;
		color: #999;
		line-height: 1.05;
	}
	.com_orders.orders-journal .journal-board__body {
		position: relative;
	}
	.com_orders.orders-journal .journal-hours {
		position: relative;
	}
	.com_orders.orders-journal .journal-hour {
		position: absolute;
		right: 8px;
		font-size: 11px;
		color: #9a9a9a;
		line-height: 1;
	}
	.com_orders.orders-journal .journal-day-col {
		position: relative;
		border-right: 1px solid #f0f0f0;
		background-image: linear-gradient(#f4f4f4 1px, transparent 1px);
		background-size: 100% <?php echo (int) round(60 * $pxPerMin); ?>px;
	}
	.com_orders.orders-journal .journal-day-col.is-today {
		background-color: #fffdf4;
	}
	.com_orders.orders-journal .journal-event {
		position: absolute;
		z-index: 2;
		box-sizing: border-box;
		padding: 1px 6px 3px;
		min-height: 46px;
		border: 0;
		border-radius: 8px;
		color: #123;
		text-align: left;
		cursor: pointer;
		overflow: hidden;
		box-shadow: 0 1px 2px rgba(0,0,0,.08);
	}
	.com_orders.orders-journal .journal-event.is-service { background: #cfe3ff; }
	.com_orders.orders-journal .journal-event.is-course { background: #ffe7a3; }
	.com_orders.orders-journal .journal-event.is-search { background: #c8efd9; }
	.com_orders.orders-journal .journal-event.is-block { background: #e4e4e4; color: #555; }
	.com_orders.orders-journal .journal-event.is-past {
		color: #6c757d;
		background: #e6e6e6;
	}
	.com_orders.orders-journal .journal-event__time,
	.com_orders.orders-journal .journal-event__title,
	.com_orders.orders-journal .journal-event__service {
		display: block;
		overflow: hidden;
		line-height: 1.2;
		white-space: normal;
	}
	.com_orders.orders-journal .journal-event__time { font-size: 11px; opacity: .85; }
	.com_orders.orders-journal .journal-event__title { font-size: 13px; font-weight: 700; }
	.com_orders.orders-journal .journal-event__service { margin-top: 1px; font-size: 11px; opacity: .9; }
	.com_orders.orders-journal .journal-now {
		position: absolute;
		left: 0;
		right: 0;
		z-index: 3;
		height: 2px;
		background: #e53935;
		pointer-events: none;
	}
	.com_orders.orders-journal .journal-card {
		margin-top: 18px;
		background: #fff;
		border: 1px solid #e2e2e2;
		border-radius: 14px;
		padding: 18px;
	}
	.com_orders.orders-journal .journal-card h2 { margin-top: 0; font-size: 20px; }
	.com_orders.orders-journal .journal-controls {
		display: flex;
		flex-wrap: wrap;
		gap: 12px;
		align-items: flex-start;
		margin-bottom: 16px;
	}
	.com_orders.orders-journal .journal-field { flex: 0 1 220px; }
	.com_orders.orders-journal .journal-field label { display: block; font-weight: 600; margin-bottom: 6px; line-height: 1.2; }
	.com_orders.orders-journal .journal-field input,
	.com_orders.orders-journal .journal-field textarea {
		width: 100%;
		border: 1px solid #cfcfcf;
		border-radius: 10px;
		padding: 11px 12px;
	}
	.com_orders.orders-journal .journal-field textarea { min-height: 84px; resize: vertical; }
	.com_orders.orders-journal .journal-selected {
		flex: 1 1 220px;
		min-height: 44px;
		padding: 11px 12px;
		border: 1px dashed #d3d3d3;
		border-radius: 10px;
		background: #fafafa;
		color: #444;
	}
	.com_orders.orders-journal .journal-submit-wrap { margin-left: auto; }
	.com_orders.orders-journal .journal-submit,
	.com_orders.orders-journal #journal-submit,
	.com_orders.orders-journal #journal-submit.btn-primary {
		background: #f9ce54 !important;
		background-color: #f9ce54 !important;
		color: #3b3636 !important;
		border-color: #f9ce54 !important;
	}
	.com_orders.orders-journal .journal-submit:hover,
	.com_orders.orders-journal .journal-submit:focus,
	.com_orders.orders-journal .journal-submit:active,
	.com_orders.orders-journal .journal-submit:disabled,
	.com_orders.orders-journal .journal-submit.disabled,
	.com_orders.orders-journal #journal-submit:hover,
	.com_orders.orders-journal #journal-submit:focus,
	.com_orders.orders-journal #journal-submit:active,
	.com_orders.orders-journal #journal-submit:disabled,
	.com_orders.orders-journal #journal-submit.disabled {
		background: #f9ce54 !important;
		background-color: #f9ce54 !important;
		color: #3b3636 !important;
		border-color: #f9ce54 !important;
	}
	.com_orders.orders-journal #journal-calendar { width: 100% !important; max-width: 800px; margin: 0 auto !important; }
	.com_orders.orders-journal #journal-calendar.preload { visibility: hidden; }
	.com_orders.orders-journal .error-msg { display: none; margin-top: 12px; color: #a94442; }
	.com_orders.orders-journal .journal-backdrop {
		display: none;
		position: fixed;
		inset: 0;
		z-index: 1040;
		background: rgba(17, 17, 17, .45);
	}
	.com_orders.orders-journal .journal-overlay {
		display: none;
		position: fixed;
		top: 50%;
		left: 50%;
		right: auto;
		bottom: auto;
		transform: translate(-50%, -50%);
		z-index: 1050;
		width: min(560px, calc(100% - 24px));
		max-height: calc(100vh - 24px);
		height: auto;
		background: #fff;
		border-radius: 12px;
		box-shadow: 0 10px 40px rgba(0,0,0,.22);
		overflow: auto;
	}
	.com_orders.orders-journal.is-detail-open .journal-backdrop,
	.com_orders.orders-journal.is-detail-open .journal-overlay { display: block; }
	.com_orders.orders-journal .journal-overlay__close {
		position: sticky;
		top: 8px;
		float: right;
		z-index: 2;
		width: 36px;
		height: 36px;
		margin: 8px 8px 0 0;
		border: 0;
		border-radius: 50%;
		background: #f3f3f3;
		font-size: 26px;
		line-height: 1;
		cursor: pointer;
	}
	.com_orders.orders-journal .journal-detail { display: none; padding: 16px 20px 20px; }
	.com_orders.orders-journal .journal-detail.is-active { display: block; }
	.com_orders.orders-journal .journal-detail h2 { margin: 0 48px 16px 0; }
	.com_orders.orders-journal .journal-detail__grid {
		display: grid;
		grid-template-columns: 160px minmax(0, 1fr);
		gap: 10px 16px;
		max-width: 820px;
	}
	.com_orders.orders-journal .journal-detail__label { color: #777; font-weight: 600; }
	.com_orders.orders-journal .journal-detail__actions { margin-top: 22px; }
	.com_orders.orders-journal .journal-detail__actions .btn { margin: 0 8px 8px 0; }
	.com_orders.orders-journal .course-participants { display: grid; gap: 12px; margin-top: 18px; }
	.com_orders.orders-journal .course-participant { border: 1px solid #ece4b6; border-radius: 10px; padding: 12px; background: #fffdf6; }
	.com_orders.orders-journal .order-comment { margin-top: 6px; white-space: pre-wrap; color: #555; }
	#zapis-reschedule .modal-dialog { width: 96vw !important; max-width: 1180px !important; margin: 30px auto !important; }
	#zapis-reschedule #reschedule-calendar { width: 100% !important; max-width: 800px; margin: 0 auto !important; }
	#zapis-reschedule #reschedule-calendar.preload { visibility: visible; }
	#zapis-reschedule .error-msg { color: #a94442; margin-top: 10px; display: none; }
	@media (max-width: 768px) {
		html {
			scrollbar-width: thin;
			scrollbar-color: #888 #e6e6e6;
			overflow-y: scroll;
		}
		html::-webkit-scrollbar,
		body::-webkit-scrollbar {
			width: 10px;
			height: 10px;
		}
		html::-webkit-scrollbar-track,
		body::-webkit-scrollbar-track {
			background: #e6e6e6;
		}
		html::-webkit-scrollbar-thumb,
		body::-webkit-scrollbar-thumb {
			background: #888;
			border-radius: 8px;
		}
		.com_orders.orders-journal {
			--journal-gutter: 52px;
			--journal-col: 148px;
		}
		.com_orders.orders-journal .journal-board {
			overflow: visible;
		}
		.com_orders.orders-journal .journal-board__scroll {
			max-height: none;
			overflow-x: auto;
			overflow-y: visible;
			overscroll-behavior-x: contain;
		}
		.com_orders.orders-journal .journal-detail { padding: 14px 16px 16px; }
		.com_orders.orders-journal .journal-detail__grid { grid-template-columns: 1fr; gap: 4px; }
		.com_orders.orders-journal .journal-controls,
		.com_orders.orders-journal .journal-submit-wrap { display: grid; width: 100%; }
		.com_orders.orders-journal .journal-submit { width: 100%; }
	}
	</style>

	<div class="journal-toolbar">
		<div>
			<?php if (!$appointmentsEmbed) : ?>
			<h1 class="page-title">Журнал</h1>
			<p class="journal-meta">Часовой пояс: <strong><?php echo $this->escape($journalTimezone); ?></strong>. Листайте влево к прошедшим дням и вправо к следующим.</p>
			<?php else : ?>
			<button type="button" class="btn btn-xs btn-default journal-today-btn" id="journal-today-btn">
				<i class="jsn-icon jsn-icon-calendar"></i> Текущая дата
			</button>
			<?php endif; ?>
		</div>
		<div class="journal-nav">
			<button type="button" id="journal-scroll-prev" aria-label="Назад">‹</button>
			<button type="button" id="journal-scroll-next" aria-label="Вперёд">›</button>
		</div>
	</div>

	<div class="journal-board">
		<div
			class="journal-board__scroll"
			id="journal-board-scroll"
			data-today-index="<?php echo (int) $todayIndex; ?>"
			data-from="<?php echo $this->escape($boardStartLocal->format('Y-m-d')); ?>"
			data-days="<?php echo (int) $dayCount; ?>"
			data-grid-start="<?php echo (int) $gridStart; ?>"
			data-grid-end="<?php echo (int) $gridEnd; ?>"
			data-range-url="<?php echo $this->escape((string) ($src->weekRangeUrl ?? '')); ?>"
			data-token="<?php echo $this->escape($token); ?>"
			data-load-more="<?php echo $appointmentsEmbed ? '1' : '0'; ?>"
		>
			<div class="journal-board__inner">
				<div class="journal-board__head">
					<div class="journal-time-gutter"></div>
					<?php $journalCellPart = 'heads'; include __DIR__ . '/_journal_cells.php'; ?>
				</div>
				<div class="journal-board__body" style="min-height: <?php echo (int) round($gridHeight * $pxPerMin); ?>px;">
					<div class="journal-time-gutter journal-hours" style="height: <?php echo (int) round($gridHeight * $pxPerMin); ?>px;">
						<?php foreach ($hourMarks as $mark) : ?>
							<div class="journal-hour" style="top: <?php echo (int) round(($mark - $gridStart) * $pxPerMin); ?>px;"><?php echo $this->escape($formatMinutes($mark)); ?></div>
						<?php endforeach; ?>
					</div>
					<?php $journalCellPart = 'cols'; include __DIR__ . '/_journal_cells.php'; ?>
				</div>
			</div>
		</div>
	</div>

	<?php if ($canBookTime) : ?>
		<?php include __DIR__ . '/_journal_book.php'; ?>
	<?php endif; ?>

	<div class="journal-backdrop" id="journal-backdrop" hidden></div>
	<div class="journal-overlay" id="journal-overlay" hidden>
		<button type="button" class="journal-overlay__close" id="journal-overlay-close" aria-label="Закрыть">&times;</button>
		<?php $journalCellPart = 'details'; include __DIR__ . '/_journal_cells.php'; ?>
	</div>
</div>

<div class="modal fade" id="zapis-reschedule" role="dialog" aria-hidden="true">
	<div class="modal-dialog" role="document">
		<div class="modal-content">
			<div class="modal-header">
				<button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
			</div>
			<div class="modal-body">
				<form id="reschedule-modal-form" method="post" action="<?php echo $rescheduleAction; ?>">
					<input type="hidden" name="<?php echo $token; ?>" value="1">
					<input type="hidden" name="return" value="<?php echo $returnEncoded; ?>">
					<input type="hidden" name="id" id="reschedule-modal-id" value="0">
					<input type="hidden" name="course_slot_id" id="reschedule-modal-course-slot-id" value="0">
					<input type="hidden" name="search_slot_id" id="reschedule-modal-search-slot-id" value="0">
					<input type="hidden" name="duration_min" id="reschedule-modal-duration" value="60">
					<input type="hidden" name="time_utc" id="reschedule-modal-time-utc" value="">
					<div class="calc__body">
						<h2>Выберите дату и время</h2>
						<div class="calendar-hint">Прокрутите даты и нажмите подходящее время</div>
						<div class="calendar__master calendar__master--manual preload" id="reschedule-calendar"></div>
						<div class="error-msg" id="reschedule-modal-error"></div>
					</div>
					<div class="calc__btn">
						<button type="submit" class="btn-next" id="reschedule-modal-submit"><span class="btn-label">Сохранить</span></button>
						<button type="button" class="close__btn" data-dismiss="modal">Отмена</button>
					</div>
				</form>
			</div>
		</div>
	</div>
</div>

<script>
(function(){
	var root = document.querySelector('.com_orders.orders-journal');
	var scroller = document.getElementById('journal-board-scroll');
	var prevBtn = document.getElementById('journal-scroll-prev');
	var nextBtn = document.getElementById('journal-scroll-next');
	var todayBtn = document.getElementById('journal-today-btn');
	var overlay = document.getElementById('journal-overlay');
	var backdrop = document.getElementById('journal-backdrop');
	var closeBtn = document.getElementById('journal-overlay-close');
	var canLoadMore = !!(scroller && scroller.getAttribute('data-load-more') === '1');
	var rangeUrl = scroller ? (scroller.getAttribute('data-range-url') || '') : '';
	var rangeToken = scroller ? (scroller.getAttribute('data-token') || '') : '';
	var gridStart = scroller ? parseInt(scroller.getAttribute('data-grid-start') || '0', 10) : 0;
	var gridEnd = scroller ? parseInt(scroller.getAttribute('data-grid-end') || '0', 10) : 0;
	var loadedFrom = scroller ? (scroller.getAttribute('data-from') || '') : '';
	var loadedDays = scroller ? parseInt(scroller.getAttribute('data-days') || '0', 10) : 0;
	var loadingDir = '';

	function pad2(n) { return (n < 10 ? '0' : '') + n; }
	function parseYmd(value) {
		var parts = String(value || '').split('-');
		if (parts.length !== 3) return null;
		var y = parseInt(parts[0], 10), m = parseInt(parts[1], 10), d = parseInt(parts[2], 10);
		if (!y || !m || !d) return null;
		return new Date(y, m - 1, d);
	}
	function formatYmd(date) {
		return date.getFullYear() + '-' + pad2(date.getMonth() + 1) + '-' + pad2(date.getDate());
	}
	function shiftYmd(value, days) {
		var date = parseYmd(value);
		if (!date) return '';
		date.setDate(date.getDate() + days);
		return formatYmd(date);
	}
	function dayWidth() {
		var col = root ? root.querySelector('.journal-day-col') : null;
		return col ? col.getBoundingClientRect().width : 168;
	}
	function setDayCount(n) {
		loadedDays = n;
		if (root) root.style.setProperty('--journal-days', String(n));
		if (scroller) scroller.setAttribute('data-days', String(n));
	}
	function syncNav() {
		if (!scroller || !prevBtn || !nextBtn) return;
		if (canLoadMore) {
			prevBtn.disabled = loadingDir !== '';
			nextBtn.disabled = loadingDir !== '';
			return;
		}
		prevBtn.disabled = scroller.scrollLeft <= 2;
		nextBtn.disabled = scroller.scrollLeft + scroller.clientWidth >= scroller.scrollWidth - 2;
	}
	function nearStart() {
		return scroller && scroller.scrollLeft <= dayWidth() * 2;
	}
	function nearEnd() {
		return scroller && scroller.scrollLeft + scroller.clientWidth >= scroller.scrollWidth - dayWidth() * 2;
	}
	function loadRange(from, prepend) {
		if (!canLoadMore || !rangeUrl || !from || loadingDir) return Promise.resolve(false);
		loadingDir = prepend ? 'prev' : 'next';
		syncNav();
		var url = rangeUrl + (rangeUrl.indexOf('?') >= 0 ? '&' : '?') + 'from=' + encodeURIComponent(from) + '&days=14';
		if (rangeToken) url += '&' + encodeURIComponent(rangeToken) + '=1';
		if (!isNaN(gridStart)) url += '&grid_start=' + encodeURIComponent(String(gridStart));
		if (!isNaN(gridEnd) && gridEnd > gridStart) url += '&grid_end=' + encodeURIComponent(String(gridEnd));
		return fetch(url, { credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
			.then(function(res){ return res.json(); })
			.then(function(data){
				if (!data || !data.success || !data.heads || !data.cols) return false;
				var head = scroller.querySelector('.journal-board__head');
				var body = scroller.querySelector('.journal-board__body');
				if (!head || !body) return false;
				var firstHead = head.querySelector('.journal-day-head');
				var firstCol = body.querySelector('.journal-day-col');
				var prevWidth = scroller.scrollWidth;
				if (prepend) {
					if (firstHead) firstHead.insertAdjacentHTML('beforebegin', data.heads);
					else head.insertAdjacentHTML('beforeend', data.heads);
					if (firstCol) firstCol.insertAdjacentHTML('beforebegin', data.cols);
					else body.insertAdjacentHTML('beforeend', data.cols);
					loadedFrom = data.from || from;
					if (scroller) scroller.setAttribute('data-from', loadedFrom);
					scroller.scrollLeft += (scroller.scrollWidth - prevWidth);
				} else {
					head.insertAdjacentHTML('beforeend', data.heads);
					body.insertAdjacentHTML('beforeend', data.cols);
				}
				if (overlay && data.details) overlay.insertAdjacentHTML('beforeend', data.details);
				setDayCount(loadedDays + (parseInt(data.days, 10) || 14));
				return true;
			})
			.catch(function(){ return false; })
			.then(function(ok){
				loadingDir = '';
				syncNav();
				return ok;
			});
	}
	function ensureEdge(dir) {
		if (!canLoadMore) return Promise.resolve();
		if (dir === 'prev' && nearStart()) return loadRange(shiftYmd(loadedFrom, -14), true);
		if (dir === 'next' && nearEnd()) return loadRange(shiftYmd(loadedFrom, loadedDays), false);
		return Promise.resolve();
	}
	function todayScrollLeft() {
		if (!scroller) return 0;
		var todayCol = scroller.querySelector('.journal-day-col.is-today');
		if (todayCol && todayCol.getBoundingClientRect().width > 1) {
			var gutter = scroller.querySelector('.journal-time-gutter');
			var gutterW = gutter ? gutter.getBoundingClientRect().width : 0;
			return Math.max(0, todayCol.offsetLeft - gutterW);
		}
		var idx = parseInt(scroller.getAttribute('data-today-index') || '0', 10);
		var colW = dayWidth();
		if (colW < 8 && root) {
			colW = parseFloat(window.getComputedStyle(root).getPropertyValue('--journal-col')) || 148;
		}
		return Math.max(0, idx * colW);
	}
	function scrollToToday(smooth) {
		if (!scroller) return false;
		var left = todayScrollLeft();
		if (smooth) {
			scroller.scrollTo({ left: left, behavior: 'smooth' });
		} else {
			scroller.scrollLeft = left;
		}
		return true;
	}
	function scheduleScrollToToday() {
		var tries = 0;
		function tick() {
			tries += 1;
			scrollToToday(false);
			var todayCol = scroller && scroller.querySelector('.journal-day-col.is-today');
			var ready = todayCol && todayCol.getBoundingClientRect().width > 1;
			if (!ready && tries < 30) {
				window.requestAnimationFrame(tick);
			}
		}
		tick();
	}
	if (prevBtn && scroller) {
		prevBtn.addEventListener('click', function(){
			ensureEdge('prev').then(function(){
				scroller.scrollBy({ left: -dayWidth(), behavior: 'smooth' });
			});
		});
	}
	if (nextBtn && scroller) {
		nextBtn.addEventListener('click', function(){
			ensureEdge('next').then(function(){
				scroller.scrollBy({ left: dayWidth(), behavior: 'smooth' });
			});
		});
	}
	if (todayBtn) {
		todayBtn.addEventListener('click', function(){ scrollToToday(true); });
	}
	if (scroller) {
		scroller.addEventListener('scroll', function(){
			syncNav();
			if (nearStart()) ensureEdge('prev');
			if (nearEnd()) ensureEdge('next');
		});
		scheduleScrollToToday();
		window.addEventListener('load', function(){ scheduleScrollToToday(); });
		window.addEventListener('vigling:tab-shown', function(e){
			if (e && e.detail && e.detail.name === 'profile-tab11') {
				scheduleScrollToToday();
			}
		});
		syncNav();
	}

	function openDetail(id) {
		if (!root || !overlay) return;
		overlay.querySelectorAll('.journal-detail').forEach(function(node){
			node.classList.toggle('is-active', node.getAttribute('data-event-id') === id);
		});
		root.classList.add('is-detail-open');
		overlay.hidden = false;
		if (backdrop) backdrop.hidden = false;
		document.body.style.overflow = 'hidden';
	}
	function closeDetail() {
		if (!root || !overlay) return;
		root.classList.remove('is-detail-open');
		overlay.hidden = true;
		if (backdrop) backdrop.hidden = true;
		document.body.style.overflow = '';
	}
	if (root) {
		root.addEventListener('click', function(e){
			var btn = e.target.closest('.journal-event');
			if (!btn || !root.contains(btn)) return;
			openDetail(btn.getAttribute('data-event-id') || '');
		});
	}
	if (closeBtn) closeBtn.addEventListener('click', closeDetail);
	if (backdrop) backdrop.addEventListener('click', closeDetail);
	document.addEventListener('keydown', function(e){
		if (e.key === 'Escape') closeDetail();
	});

	var modal = document.getElementById('zapis-reschedule');
	var rForm = document.getElementById('reschedule-modal-form');
	var rCal = document.getElementById('reschedule-calendar');
	var idInp = document.getElementById('reschedule-modal-id');
	var courseSlotInp = document.getElementById('reschedule-modal-course-slot-id');
	var searchSlotInp = document.getElementById('reschedule-modal-search-slot-id');
	var durationInp = document.getElementById('reschedule-modal-duration');
	var timeUtcInp = document.getElementById('reschedule-modal-time-utc');
	var rError = document.getElementById('reschedule-modal-error');
	var rSubmit = document.getElementById('reschedule-modal-submit');
	var defaultAction = rForm ? (rForm.getAttribute('action') || '') : '';
	var slotsUrl = <?php echo json_encode($rescheduleSlotsAction); ?>;
	var slotsToken = <?php echo json_encode($token); ?>;
	var slotsRequestId = 0;
	if (rCal) bindRescheduleSlotPick(rCal);
	function bindRescheduleSlotPick(root) {
		if (!root || root.getAttribute('data-slot-pick') === '1') return;
		root.setAttribute('data-slot-pick', '1');
		function pick(e) {
			var label = e.target && e.target.closest ? e.target.closest('label.btn-select') : null;
			if (!label || !root.contains(label)) return;
			e.stopPropagation();
			var inputId = label.getAttribute('for') || '';
			var input = inputId ? document.getElementById(inputId) : null;
			if (!input) input = label.querySelector('input[type="radio"]');
			if (!input || input.disabled) return;
			input.checked = true;
			root.querySelectorAll('label.btn-select.is-picked').forEach(function(node) {
				node.classList.remove('is-picked');
			});
			label.classList.add('is-picked');
		}
		root.addEventListener('pointerdown', pick, true);
		root.addEventListener('click', pick, true);
	}
	function destroySlider(){
		if (!window.jQuery || !rCal) return;
		var jqCal = jQuery(rCal);
		if (jqCal.hasClass('slick-initialized')) {
			try { jqCal.slick('unslick'); } catch (e) {}
		}
	}
	function initRescheduleSlider(){
		if (!rCal) return;
		rCal.classList.remove('preload');
		if (window.jQuery && jQuery(rCal).hasClass('slick-initialized')) {
			try { jQuery(rCal).slick('unslick'); } catch (e) {}
		}
	}
	function loadRescheduleDays(orderId, courseSlotId, searchSlotId, duration, currentUtc) {
		if (!rCal) return;
		rCal.classList.add('preload');
		rCal.innerHTML = '';
		var requestId = ++slotsRequestId;
		if (rError) { rError.style.display = 'none'; rError.textContent = ''; }
		var fd = new FormData();
		fd.append(slotsToken, '1');
		fd.append('id', String(orderId || 0));
		fd.append('course_slot_id', String(courseSlotId || 0));
		fd.append('search_slot_id', String(searchSlotId || 0));
		fd.append('duration', String(duration || 60));
		fetch(slotsUrl, { method: 'POST', body: fd, credentials: 'same-origin' })
			.then(function(response){ return response.json(); })
			.then(function(data){
				if (requestId !== slotsRequestId) return;
				var days = (data && data.success && Array.isArray(data.days)) ? data.days : [];
				renderCalendar(days, currentUtc);
				initRescheduleSlider();
				if ((!data || !data.success) && rError) {
					rError.style.display = 'block';
					rError.textContent = (data && data.message) ? data.message : 'Не удалось загрузить свободное время';
				}
			})
			.catch(function(){
				if (requestId !== slotsRequestId) return;
				renderCalendar([], currentUtc);
				if (rError) {
					rError.style.display = 'block';
					rError.textContent = 'Не удалось загрузить свободное время';
				}
			});
	}
	function renderCalendar(days, currentUtc){
		destroySlider();
		if (!rCal) return;
		rCal.innerHTML = '';
		(days || []).forEach(function(day, dayIdx){
			var item = document.createElement('div');
			item.className = 'calendar__master-item';
			var head = document.createElement('span');
			head.className = 'mas-date';
			head.innerHTML = String(day.date_view || '') + '<b>' + String(day.dow || '') + '</b>';
			item.appendChild(head);
			var slots = Array.isArray(day.slots) ? day.slots : [];
			if (!slots.length) {
				var no = document.createElement('div');
				no.textContent = 'Нет времени';
				item.appendChild(no);
				rCal.appendChild(item);
				return;
			}
			var wrap = document.createElement('p');
			wrap.className = 'btns-m';
			slots.forEach(function(slot, slotIdx){
				var utc = String(slot.utc || '').trim();
				if (!utc) return;
				var inputId = 'reschedule-slot-' + dayIdx + '-' + slotIdx;
				var inp = document.createElement('input');
				inp.type = 'radio';
				inp.name = 'reschedule_slot';
				inp.id = inputId;
				inp.value = utc;
				if (currentUtc && utc === currentUtc) inp.checked = true;
				var lbl = document.createElement('label');
				lbl.className = 'btn-select';
				lbl.setAttribute('for', inputId);
				lbl.textContent = String(slot.label || '');
				wrap.appendChild(inp);
				wrap.appendChild(lbl);
			});
			item.appendChild(wrap);
			rCal.appendChild(item);
		});
		rCal.classList.remove('preload');
	}
	function readEmbeddedDays(orderId, courseSlotId, searchSlotId) {
		var id = '';
		if (searchSlotId > 0) id = 'reschedule-search-slot-' + searchSlotId;
		else if (courseSlotId > 0) id = 'reschedule-course-slot-' + courseSlotId;
		else if (orderId > 0) id = 'reschedule-slots-' + orderId;
		if (!id) return [];
		var node = document.getElementById(id);
		if (!node) return [];
		try {
			var parsed = JSON.parse(node.textContent || '[]');
			return Array.isArray(parsed) ? parsed : [];
		} catch (e) {
			return [];
		}
	}
	document.addEventListener('click', function(e){
		var btn = e.target && e.target.closest ? e.target.closest('.reschedule-open') : null;
		if (!btn || !root || !root.contains(btn)) return;
		var orderId = parseInt(btn.getAttribute('data-id') || '0', 10);
		var courseSlotId = parseInt(btn.getAttribute('data-course-slot-id') || '0', 10);
		var searchSlotId = parseInt(btn.getAttribute('data-search-slot-id') || '0', 10);
		var duration = parseInt(btn.getAttribute('data-duration') || '60', 10);
		var currentUtc = String(btn.getAttribute('data-current-utc') || '').trim();
		if (!orderId && !courseSlotId && !searchSlotId) return;
		idInp.value = String(orderId > 0 ? orderId : 0);
		courseSlotInp.value = String(courseSlotId > 0 ? courseSlotId : 0);
		searchSlotInp.value = String(searchSlotId > 0 ? searchSlotId : 0);
		durationInp.value = String(isNaN(duration) ? 60 : duration);
		timeUtcInp.value = '';
		rForm.setAttribute('action', btn.getAttribute('data-reschedule-action') || defaultAction);
		rCal.classList.add('preload');
		if (rCal) rCal.innerHTML = '';
		jQuery(modal).modal('show');
		if (btn.getAttribute('data-slots-embedded') === '1') {
			renderCalendar(readEmbeddedDays(orderId, courseSlotId, searchSlotId), currentUtc);
			initRescheduleSlider();
			if (rCal) rCal.classList.remove('preload');
		} else {
			loadRescheduleDays(orderId, courseSlotId, searchSlotId, isNaN(duration) ? 60 : duration, currentUtc);
		}
	});
	if (window.jQuery && modal) {
		jQuery(modal).on('shown.bs.modal', initRescheduleSlider);
		jQuery(modal).on('hidden.bs.modal', function () {
			slotsRequestId++;
			timeUtcInp.value = '';
			idInp.value = '0';
			courseSlotInp.value = '0';
			searchSlotInp.value = '0';
			rForm.setAttribute('action', defaultAction);
			destroySlider();
			rCal.innerHTML = '';
			rCal.classList.add('preload');
		});
	}
	if (rForm) {
		rForm.addEventListener('submit', function(e){
			var selected = rForm.querySelector('input[name="reschedule_slot"]:checked');
			if (!selected || !selected.value) {
				e.preventDefault();
				if (rError) { rError.style.display = 'block'; rError.textContent = 'Выберите доступное время для переноса'; }
				return;
			}
			timeUtcInp.value = String(selected.value);
			if (rSubmit) rSubmit.setAttribute('disabled', 'disabled');
		});
	}
})();
</script>
<?php
}
?>
