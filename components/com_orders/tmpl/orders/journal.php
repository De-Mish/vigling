<?php

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Session\Session;
use Joomla\CMS\Uri\Uri;

require_once __DIR__ . '/_reschedule_helper.php';

/** @var \Viglin\Component\Orders\Site\View\Orders\HtmlView $this */
$items = $this->items;
$user = Factory::getApplication()->getIdentity();
$masterId = (int) ($user->id ?? 0);
$token = Session::getFormToken();
$returnEncoded = base64_encode(Uri::getInstance()->toString());
$db = Factory::getContainer()->get(\Joomla\Database\DatabaseInterface::class);
$payload = $masterId > 0 ? viglingOrdersBuildRescheduleSlots($db, $masterId, 15, 0, 0, 45) : ['timezone' => 'UTC', 'days' => []];
$days = $payload['days'] ?? [];
$journalTimezone = (string) ($payload['timezone'] ?? 'UTC');
$addAction = Route::_('index.php?option=com_orders&task=orders.journalAdd');
$deleteAction = Route::_('index.php?option=com_orders&task=orders.journalDelete');
$rescheduleAction = Route::_('index.php?option=com_orders&task=orders.rescheduleByMaster');
$rescheduleCourseAction = Route::_('index.php?option=com_orders&task=orders.rescheduleCourseSlotByMaster');
$rescheduleSearchAction = Route::_('index.php?option=com_orders&task=orders.rescheduleSearchSlotByMaster');

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
$dayCount = 21;
$pxPerMin = 1.2;
$scheduleByDay = $masterId > 0 ? viglingOrdersLoadMasterSchedule($db, $masterId) : [];

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
	if ($label === '') {
		$label = 'Блок времени';
	}
	return [$label, $comment];
};

$renderOrderActions = static function ($item, bool $isPast, bool $completed, string $token, string $returnEncoded, string $timeIso) use ($db): string {
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
	$slotPayload = viglingOrdersBuildRescheduleSlots($db, (int) $item->master_id, $durationMin, (int) $item->id, 0, 45);
	$slotsJson = json_encode($slotPayload['days'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
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
	<script type="application/json" id="reschedule-slots-<?php echo (int) $item->id; ?>"><?php echo $slotsJson ?: '[]'; ?></script>
	<?php
	return (string) ob_get_clean();
};

$renderCourseSlotActions = static function ($item, bool $isPast, string $token, string $returnEncoded, string $rescheduleCourseAction) use ($db): string {
	$courseSlotId = (int) ($item->course_slot_id ?? 0);
	$durationMin = (int) ($item->course_slot_end_utc && $item->course_slot_start_utc
		? max(15, min(480, (int) floor((strtotime((string) $item->course_slot_end_utc) - strtotime((string) $item->course_slot_start_utc)) / 60)))
		: (int) ($item->duration_min ?? 60));
	if ($durationMin <= 0) {
		$durationMin = 60;
	}
	$slotPayload = viglingOrdersBuildRescheduleSlots($db, (int) $item->master_id, $durationMin, 0, $courseSlotId, 45);
	$slotsJson = json_encode($slotPayload['days'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
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
	<script type="application/json" id="reschedule-course-slot-<?php echo $courseSlotId; ?>"><?php echo $slotsJson ?: '[]'; ?></script>
	<?php
	return (string) ob_get_clean();
};

$renderSearchSlotActions = static function ($item, bool $isPast, string $token, string $returnEncoded, string $rescheduleSearchAction) use ($db): string {
	$searchSlotId = (int) ($item->search_slot_id ?? 0);
	$durationMin = (int) ($item->search_slot_end_utc && $item->search_slot_start_utc
		? max(15, min(480, (int) floor((strtotime((string) $item->search_slot_end_utc) - strtotime((string) $item->search_slot_start_utc)) / 60)))
		: (int) ($item->duration_min ?? 60));
	if ($durationMin <= 0) {
		$durationMin = 60;
	}
	$slotPayload = viglingOrdersBuildRescheduleSlots($db, (int) $item->master_id, $durationMin, 0, 0, 45, $searchSlotId);
	$slotsJson = json_encode($slotPayload['days'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
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
	<script type="application/json" id="reschedule-search-slot-<?php echo $searchSlotId; ?>"><?php echo $slotsJson ?: '[]'; ?></script>
	<?php
	return (string) ob_get_clean();
};

$displayRows = [];
foreach ($items as $item) {
	$bookingKind = trim((string) ($item->booking_kind ?? 'service'));
	$courseSlotId = (int) ($item->course_slot_id ?? 0);
	$searchSlotId = (int) ($item->search_slot_id ?? 0);
	$userId = (int) ($item->user_id ?? 0);
	if ($userId <= 0 || $bookingKind === 'journal') {
		$displayRows[] = ['type' => 'block', 'item' => $item];
		continue;
	}
	if ($bookingKind === 'course' && $courseSlotId > 0) {
		$key = 'course-slot-' . $courseSlotId;
		if (!isset($displayRows[$key])) {
			$displayRows[$key] = ['type' => 'course-group', 'kind' => 'course', 'slot_id' => $courseSlotId, 'item' => $item, 'participants' => []];
		}
		$displayRows[$key]['participants'][] = $item;
		continue;
	}
	if ($bookingKind === 'search' && $searchSlotId > 0) {
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
	$day = $todayLocal->modify('+' . $offset . ' day');
	$boardDays[$day->format('Y-m-d')] = [
		'date' => $day->format('Y-m-d'),
		'dow' => (int) $day->format('N'),
		'dow_label' => $dowShort[(int) $day->format('N')] ?? '',
		'day_num' => (int) $day->format('j'),
		'month_label' => $monthShort[(int) $day->format('n')] ?? '',
		'date_view' => $day->format('d.m.Y'),
		'is_today' => $offset === 0,
		'events' => [],
	];
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
	$eventId = 'evt-' . (++$eventIndex);
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
		$title = trim((string) ($item->client_name ?? 'Клиент'));
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
?>
<div class="com_orders orders-journal">
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
	.com_orders.orders-journal .journal-nav button {
		min-width: 40px;
		height: 36px;
		border: 1px solid #d9d9d9;
		border-radius: 8px;
		background: #fff;
		cursor: pointer;
	}
	.com_orders.orders-journal .journal-nav button:disabled {
		opacity: .4;
		cursor: default;
	}
	.com_orders.orders-journal .journal-meta {
		margin: 0;
		color: #707070;
		font-size: 13px;
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
	}
	.com_orders.orders-journal .journal-board__inner {
		min-width: max(100%, calc(64px + 21 * 168px));
	}
	.com_orders.orders-journal .journal-board__head,
	.com_orders.orders-journal .journal-board__body {
		display: grid;
		grid-template-columns: 64px repeat(21, minmax(168px, 1fr));
	}
	.com_orders.orders-journal .journal-board__head {
		position: sticky;
		top: 0;
		z-index: 4;
		background: #fff;
		border-bottom: 1px solid #ececec;
	}
	.com_orders.orders-journal .journal-time-gutter {
		position: sticky;
		left: 0;
		z-index: 5;
		background: #fff;
		border-right: 1px solid #ececec;
	}
	.com_orders.orders-journal .journal-day-head {
		padding: 10px 8px 12px;
		text-align: center;
		border-right: 1px solid #f0f0f0;
	}
	.com_orders.orders-journal .journal-day-head.is-today {
		background: #fff8dc;
	}
	.com_orders.orders-journal .journal-day-head .dow {
		display: block;
		font-size: 12px;
		color: #888;
		text-transform: lowercase;
	}
	.com_orders.orders-journal .journal-day-head .date {
		display: block;
		margin-top: 2px;
		font-size: 18px;
		font-weight: 700;
		line-height: 1.1;
	}
	.com_orders.orders-journal .journal-day-head.is-today .date {
		display: inline-flex;
		align-items: center;
		justify-content: center;
		min-width: 34px;
		height: 34px;
		margin-top: 4px;
		border-radius: 50%;
		background: #ffc107;
		color: #111;
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
		transform: translateY(-50%);
		font-size: 11px;
		color: #9a9a9a;
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
		padding: 6px 8px;
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
	.com_orders.orders-journal .journal-event__time,
	.com_orders.orders-journal .journal-event__title,
	.com_orders.orders-journal .journal-event__service {
		display: block;
		overflow: hidden;
		text-overflow: ellipsis;
		white-space: nowrap;
	}
	.com_orders.orders-journal .journal-event__time { font-size: 11px; opacity: .85; }
	.com_orders.orders-journal .journal-event__title { font-size: 13px; font-weight: 700; }
	.com_orders.orders-journal .journal-event__service { font-size: 11px; opacity: .9; }
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
		align-items: end;
		margin-bottom: 16px;
	}
	.com_orders.orders-journal .journal-field { flex: 0 1 220px; }
	.com_orders.orders-journal .journal-field label { display: block; font-weight: 600; margin-bottom: 6px; }
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
		inset: 4px;
		z-index: 1050;
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
		width: 40px;
		height: 40px;
		margin: 8px 8px 0 0;
		border: 0;
		border-radius: 50%;
		background: #f3f3f3;
		font-size: 28px;
		line-height: 1;
		cursor: pointer;
	}
	.com_orders.orders-journal .journal-detail { display: none; padding: 24px 28px 36px; }
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
	#zapis-reschedule #reschedule-calendar.preload { visibility: hidden; }
	#zapis-reschedule .error-msg { color: #a94442; margin-top: 10px; display: none; }
	@media (max-width: 768px) {
		.com_orders.orders-journal .journal-board__inner { min-width: calc(52px + 21 * 148px); }
		.com_orders.orders-journal .journal-board__head,
		.com_orders.orders-journal .journal-board__body { grid-template-columns: 52px repeat(21, minmax(148px, 1fr)); }
		.com_orders.orders-journal .journal-detail { padding: 16px; }
		.com_orders.orders-journal .journal-detail__grid { grid-template-columns: 1fr; gap: 4px; }
		.com_orders.orders-journal .journal-controls,
		.com_orders.orders-journal .journal-submit-wrap { display: grid; width: 100%; }
		.com_orders.orders-journal .journal-submit { width: 100%; }
	}
	</style>

	<div class="journal-toolbar">
		<div>
			<h1 class="page-title">Журнал</h1>
			<p class="journal-meta">Часовой пояс: <strong><?php echo $this->escape($journalTimezone); ?></strong>. Листайте вправо к следующим дням. Назад — только до сегодня.</p>
		</div>
		<div class="journal-nav">
			<button type="button" id="journal-scroll-prev" aria-label="Назад">‹</button>
			<button type="button" id="journal-scroll-next" aria-label="Вперёд">›</button>
		</div>
	</div>

	<div class="journal-board">
		<div class="journal-board__scroll" id="journal-board-scroll">
			<div class="journal-board__inner">
				<div class="journal-board__head">
					<div class="journal-time-gutter"></div>
					<?php foreach ($boardDays as $day) : ?>
						<div class="journal-day-head<?php echo !empty($day['is_today']) ? ' is-today' : ''; ?>">
							<span class="dow"><?php echo $this->escape((string) $day['dow_label']); ?></span>
							<span class="date"><?php echo (int) $day['day_num']; ?></span>
							<span class="dow"><?php echo $this->escape((string) $day['month_label']); ?></span>
						</div>
					<?php endforeach; ?>
				</div>
				<div class="journal-board__body" style="min-height: <?php echo (int) round($gridHeight * $pxPerMin); ?>px;">
					<div class="journal-time-gutter journal-hours" style="height: <?php echo (int) round($gridHeight * $pxPerMin); ?>px;">
						<?php foreach ($hourMarks as $mark) : ?>
							<div class="journal-hour" style="top: <?php echo (int) round(($mark - $gridStart) * $pxPerMin); ?>px;"><?php echo $this->escape($formatMinutes($mark)); ?></div>
						<?php endforeach; ?>
					</div>
					<?php foreach ($boardDays as $day) : ?>
						<div class="journal-day-col<?php echo !empty($day['is_today']) ? ' is-today' : ''; ?>" style="height: <?php echo (int) round($gridHeight * $pxPerMin); ?>px;">
							<?php
							if (!empty($day['is_today'])) :
								$nowLocal = $nowUtc->setTimezone($journalTz);
								$nowMin = ((int) $nowLocal->format('H')) * 60 + (int) $nowLocal->format('i');
								if ($nowMin >= $gridStart && $nowMin <= $gridEnd) :
							?>
								<div class="journal-now" style="top: <?php echo (int) round(($nowMin - $gridStart) * $pxPerMin); ?>px;"></div>
							<?php
								endif;
							endif;
							foreach ($day['events'] as $event) :
								$cols = max(1, (int) ($event['cols'] ?? 1));
								$col = (int) ($event['col'] ?? 0);
								$top = (int) round(($event['startMin'] - $gridStart) * $pxPerMin);
								$height = max(28, (int) round(($event['endMin'] - $event['startMin']) * $pxPerMin));
								$width = 'calc(' . (100 / $cols) . '% - 6px)';
								$left = 'calc(' . (($col / $cols) * 100) . '% + 3px)';
								$timeLabel = $formatMinutes((int) $event['startMin']) . '–' . $formatMinutes((int) $event['endMin']);
							?>
							<button
								type="button"
								class="journal-event <?php echo $kindClass((string) $event['kind']); ?>"
								style="top: <?php echo $top; ?>px; height: <?php echo $height; ?>px; left: <?php echo $left; ?>; width: <?php echo $width; ?>;"
								data-event-id="<?php echo $this->escape((string) $event['id']); ?>"
							>
								<span class="journal-event__time"><?php echo $this->escape($timeLabel); ?></span>
								<span class="journal-event__title"><?php echo $this->escape((string) $event['title']); ?></span>
								<span class="journal-event__service"><?php echo $this->escape((string) $event['service']); ?></span>
							</button>
							<?php endforeach; ?>
						</div>
					<?php endforeach; ?>
				</div>
			</div>
		</div>
	</div>

	<div class="journal-card journal-card--calendar">
		<h2>Заблокировать время</h2>
		<form id="journal-form" method="post" action="<?php echo $addAction; ?>">
			<input type="hidden" name="<?php echo $token; ?>" value="1">
			<input type="hidden" name="return" value="<?php echo $returnEncoded; ?>">
			<input type="hidden" name="time_utc" id="journal-time-utc" value="">
			<div class="journal-controls">
				<div class="journal-field">
					<label for="journal-duration">Длительность</label>
					<input type="text" id="journal-duration" name="duration" value="60" placeholder="95 или 1:35">
				</div>
				<div class="journal-field">
					<label for="journal-comment">Комментарий</label>
					<textarea id="journal-comment" name="comment" rows="3" placeholder="Причина блокировки времени"></textarea>
				</div>
				<div class="journal-selected" id="journal-selected">
					<strong>Слот не выбран</strong>
					<span>Нажмите на свободное время в календаре ниже.</span>
				</div>
				<div class="journal-submit-wrap">
					<button type="submit" class="btn btn-primary journal-submit" id="journal-submit" disabled>Заблокировать время</button>
				</div>
			</div>
			<div class="calc__body">
				<div class="calendar__master calendar__master--manual preload" id="journal-calendar">
				<?php if (!empty($days)) : ?>
					<?php foreach ($days as $day) : ?>
						<div class="calendar__master-item">
							<span class="mas-date">
								<?php echo $this->escape((string) ($day['date_view'] ?? '')); ?>
								<b><?php echo $this->escape((string) ($day['dow'] ?? '')); ?></b>
							</span>
							<?php $slots = (array) ($day['slots'] ?? []); ?>
							<?php if (!empty($slots)) : ?>
								<p class="btns-m">
									<?php foreach ($slots as $slot) : ?>
										<?php
										$slotLabel = (string) ($slot['label'] ?? '');
										$slotUtc = (string) ($slot['utc'] ?? '');
										$slotFull = trim((string) ($day['date_view'] ?? '') . ' ' . $slotLabel);
										$slotId = preg_replace('/[^a-zA-Z0-9\-_]/', '-', (string) ($day['date'] ?? '') . '-' . str_replace(':', '-', $slotLabel));
										?>
										<input type="radio" id="<?php echo $this->escape($slotId); ?>" name="journal_slot" value="<?php echo $this->escape($slotUtc); ?>" data-slot-label="<?php echo $this->escape($slotFull); ?>">
										<label for="<?php echo $this->escape($slotId); ?>" class="btn-select"><?php echo $this->escape($slotLabel); ?></label>
									<?php endforeach; ?>
								</p>
							<?php else : ?>
								<div class="journal-empty-text">Нет свободных слотов</div>
							<?php endif; ?>
						</div>
					<?php endforeach; ?>
				<?php else : ?>
					<div class="calendar__master-item">
						<div class="journal-empty-text">Свободных слотов пока нет</div>
					</div>
				<?php endif; ?>
				</div>
			</div>
			<div class="error-msg" id="journal-error"></div>
		</form>
	</div>

	<div class="journal-backdrop" id="journal-backdrop" hidden></div>
	<div class="journal-overlay" id="journal-overlay" hidden>
		<button type="button" class="journal-overlay__close" id="journal-overlay-close" aria-label="Закрыть">&times;</button>
		<?php foreach ($boardDays as $day) : ?>
			<?php foreach ($day['events'] as $event) :
				$row = $event['row'];
				$item = $event['item'];
				$isPast = $event['startLocal'] < $nowUtc->setTimezone($journalTz);
				$timeText = $event['startLocal']->format('d.m.Y H:i') . ' – ' . $event['endLocal']->format('H:i');
			?>
			<div class="journal-detail" data-event-id="<?php echo $this->escape((string) $event['id']); ?>">
				<?php if (($row['type'] ?? '') === 'block') : ?>
					<h2><?php echo $this->escape((string) $event['title']); ?></h2>
					<div class="journal-detail__grid">
						<div class="journal-detail__label">Услуга</div>
						<div>Блок времени</div>
						<div class="journal-detail__label">Дата и время</div>
						<div><?php echo $this->escape($timeText); ?></div>
						<div class="journal-detail__label">Комментарий</div>
						<div><?php echo $this->escape((string) ($item->_journal_comment ?? '—')); ?></div>
					</div>
					<div class="journal-detail__actions">
						<form method="post" action="<?php echo $deleteAction; ?>" class="form-inline" style="display:inline;">
							<input type="hidden" name="<?php echo $token; ?>" value="1">
							<input type="hidden" name="return" value="<?php echo $returnEncoded; ?>">
							<input type="hidden" name="id" value="<?php echo (int) $item->id; ?>">
							<button type="submit" class="btn btn-xs btn-default" onclick="return confirm('Удалить блок времени?');">Удалить</button>
						</form>
					</div>
				<?php elseif (in_array(($row['type'] ?? ''), ['course-group', 'search-group'], true)) :
					$isSearchGroup = ($row['kind'] ?? '') === 'search';
					$entityLabel = $isSearchGroup ? 'Поиск моделей' : 'Курс';
					$participants = $row['participants'] ?? [];
					$participantCount = count($participants);
					$capacityTotal = $isSearchGroup
						? (int) ($item->search_slot_capacity_total ?? $item->search_capacity ?? 0)
						: (int) ($item->course_slot_capacity_total ?? $item->course_capacity ?? 0);
					$entityTitle = trim((string) ($item->service_display_name ?? $item->service_name ?? $entityLabel));
				?>
					<h2><?php echo $this->escape($entityTitle); ?></h2>
					<div class="journal-detail__grid">
						<div class="journal-detail__label">Клиент</div>
						<div><?php echo $this->escape($entityLabel); ?> · <?php echo (int) $participantCount; ?> из <?php echo $capacityTotal > 0 ? (int) $capacityTotal : '—'; ?></div>
						<div class="journal-detail__label">Услуга</div>
						<div><?php echo $this->escape($entityTitle); ?></div>
						<div class="journal-detail__label">Дата и время</div>
						<div><?php echo $this->escape($timeText); ?></div>
						<div class="journal-detail__label">Контакты</div>
						<div>Участники внутри слота</div>
					</div>
					<div class="journal-detail__actions">
						<?php echo $isSearchGroup
							? $renderSearchSlotActions($item, $isPast, $token, $returnEncoded, $rescheduleSearchAction)
							: $renderCourseSlotActions($item, $isPast, $token, $returnEncoded, $rescheduleCourseAction); ?>
					</div>
					<div class="course-participants">
						<?php foreach ($participants as $participant) :
							$participantTimeUtc = !empty($participant->time) ? new \DateTimeImmutable((string) $participant->time, $utc) : null;
							$participantTimeIso = $participantTimeUtc ? $participantTimeUtc->format('c') : '';
							$participantIsPast = $participantTimeUtc ? ($participantTimeUtc < $nowUtc) : false;
							$participantContacts = $contactBits($participant);
							$clientProfileUrl = rtrim(Uri::root(true), '/') . '/' . (int) $participant->user_id;
						?>
						<div class="course-participant">
							<div class="course-participant-name">
								<?php if (($participant->client_name ?? '—') !== '—' && (int) $participant->user_id > 0) : ?>
									<a href="<?php echo htmlspecialchars($clientProfileUrl); ?>"><?php echo htmlspecialchars((string) $participant->client_name); ?></a>
								<?php else : ?>
									<?php echo htmlspecialchars((string) ($participant->client_name ?? '—')); ?>
								<?php endif; ?>
							</div>
							<div><?php echo $participantContacts !== [] ? htmlspecialchars(implode(', ', $participantContacts)) : '—'; ?></div>
							<?php if (trim((string) ($participant->comment ?? '')) !== '') : ?>
								<div class="order-comment"><?php echo htmlspecialchars((string) $participant->comment); ?></div>
							<?php endif; ?>
							<div class="journal-detail__actions">
								<?php echo $renderOrderActions($participant, $participantIsPast, !empty($participant->completed), $token, $returnEncoded, $participantTimeIso); ?>
							</div>
						</div>
						<?php endforeach; ?>
					</div>
				<?php else :
					$clientProfileUrl = rtrim(Uri::root(true), '/') . '/' . (int) $item->user_id;
					$contacts = $contactBits($item);
					$completed = !empty($item->completed);
				?>
					<h2>
						<?php if (($item->client_name ?? '—') !== '—' && (int) $item->user_id > 0) : ?>
							<a href="<?php echo htmlspecialchars($clientProfileUrl); ?>"><?php echo htmlspecialchars((string) $item->client_name); ?></a>
						<?php else : ?>
							<?php echo htmlspecialchars((string) ($item->client_name ?? 'Клиент')); ?>
						<?php endif; ?>
					</h2>
					<div class="journal-detail__grid">
						<div class="journal-detail__label">Клиент</div>
						<div><?php echo htmlspecialchars((string) ($item->client_name ?? '—')); ?></div>
						<div class="journal-detail__label">Услуга</div>
						<div>
							<?php echo htmlspecialchars((string) ($item->service_display_name ?? $item->service_name ?? '—')); ?>
							<?php if (trim((string) ($item->comment ?? '')) !== '') : ?>
								<div class="order-comment"><?php echo htmlspecialchars((string) $item->comment); ?></div>
							<?php endif; ?>
						</div>
						<div class="journal-detail__label">Дата и время</div>
						<div><?php echo $this->escape($timeText); ?></div>
						<div class="journal-detail__label">Контакты</div>
						<div><?php echo $contacts !== [] ? htmlspecialchars(implode(', ', $contacts)) : '—'; ?></div>
					</div>
					<div class="journal-detail__actions">
						<?php echo $renderOrderActions($item, $isPast, $completed, $token, $returnEncoded, (string) $event['timeIso']); ?>
					</div>
				<?php endif; ?>
			</div>
			<?php endforeach; ?>
		<?php endforeach; ?>
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
						<div class="calendar-hint">Листайте <i>вправо/влево</i> или используйте стрелки для других дат</div>
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
	var overlay = document.getElementById('journal-overlay');
	var backdrop = document.getElementById('journal-backdrop');
	var closeBtn = document.getElementById('journal-overlay-close');

	function dayWidth() {
		var col = root ? root.querySelector('.journal-day-col') : null;
		return col ? col.getBoundingClientRect().width : 168;
	}
	function syncNav() {
		if (!scroller || !prevBtn || !nextBtn) return;
		prevBtn.disabled = scroller.scrollLeft <= 2;
		nextBtn.disabled = scroller.scrollLeft + scroller.clientWidth >= scroller.scrollWidth - 2;
	}
	if (prevBtn && scroller) {
		prevBtn.addEventListener('click', function(){
			scroller.scrollBy({ left: -dayWidth(), behavior: 'smooth' });
		});
	}
	if (nextBtn && scroller) {
		nextBtn.addEventListener('click', function(){
			scroller.scrollBy({ left: dayWidth(), behavior: 'smooth' });
		});
	}
	if (scroller) {
		scroller.addEventListener('scroll', syncNav);
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
		root.querySelectorAll('.journal-event').forEach(function(btn){
			btn.addEventListener('click', function(){
				openDetail(btn.getAttribute('data-event-id') || '');
			});
		});
	}
	if (closeBtn) closeBtn.addEventListener('click', closeDetail);
	if (backdrop) backdrop.addEventListener('click', closeDetail);
	document.addEventListener('keydown', function(e){
		if (e.key === 'Escape') closeDetail();
	});

	var form = document.getElementById('journal-form');
	var cal = document.getElementById('journal-calendar');
	var timeInput = document.getElementById('journal-time-utc');
	var durationInput = document.getElementById('journal-duration');
	var selectedBox = document.getElementById('journal-selected');
	var errorEl = document.getElementById('journal-error');
	var submitBtn = document.getElementById('journal-submit');

	function setError(msg) {
		if (!errorEl) return;
		errorEl.style.display = msg ? 'block' : 'none';
		errorEl.textContent = msg || '';
	}
	function initSlider() {
		if (!cal || !window.jQuery) {
			if (cal) cal.classList.remove('preload');
			return;
		}
		var jqCal = window.jQuery(cal);
		setTimeout(function(){
			if (jqCal.hasClass('slick-initialized')) {
				jqCal.slick('setPosition');
			} else if (cal.querySelector('.calendar__master-item')) {
				jqCal.slick({
					infinite: false,
					slidesToShow: 5,
					slidesToScroll: 1,
					dots: false,
					arrows: true,
					accessibility: false,
					responsive: [
						{ breakpoint: 1024, settings: { slidesToShow: 5, slidesToScroll: 1 } },
						{ breakpoint: 820, settings: { slidesToShow: 1, slidesToScroll: 1 } }
					]
				});
			}
			jqCal.removeClass('preload');
		}, 0);
	}
	if (cal) {
		initSlider();
		cal.querySelectorAll('input[name="journal_slot"]').forEach(function(input){
			input.addEventListener('change', function(){
				if (timeInput) timeInput.value = input.value || '';
				if (selectedBox) {
					selectedBox.innerHTML = '<strong>Выбран слот</strong><span>' + (input.getAttribute('data-slot-label') || '') + '</span>';
				}
				if (submitBtn) submitBtn.disabled = !input.value;
				setError('');
			});
		});
	}
	if (form) {
		form.addEventListener('submit', function(e){
			if (!timeInput || !timeInput.value.trim()) {
				e.preventDefault();
				setError('Сначала выберите слот в календаре.');
				return;
			}
			if (durationInput && !durationInput.value.trim()) {
				e.preventDefault();
				setError('Укажите длительность блока.');
			}
		});
	}

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
	function destroySlider(){
		if (!window.jQuery || !rCal) return;
		var jqCal = jQuery(rCal);
		if (jqCal.hasClass('slick-initialized')) {
			try { jqCal.slick('unslick'); } catch (e) {}
		}
	}
	function initRescheduleSlider(){
		if (!window.jQuery || !rCal) return;
		var jqCal = jQuery(rCal);
		setTimeout(function(){
			if (jqCal.hasClass('slick-initialized')) {
				jqCal.slick('setPosition');
			} else if (rCal.querySelector('.calendar__master-item')) {
				jqCal.slick({
					infinite: false,
					slidesToShow: 5,
					slidesToScroll: 1,
					dots: false,
					arrows: true,
					accessibility: false,
					responsive: [
						{ breakpoint: 1024, settings: { slidesToShow: 5, slidesToScroll: 1 } },
						{ breakpoint: 820, settings: { slidesToShow: 1, slidesToScroll: 1 } }
					]
				});
			}
			jqCal.removeClass('preload');
		}, 0);
	}
	function readJson(id){
		var node = document.getElementById(id);
		if (!node) return [];
		try {
			var parsed = JSON.parse(node.textContent || '[]');
			return Array.isArray(parsed) ? parsed : [];
		} catch (e) { return []; }
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
	}
	document.querySelectorAll('.reschedule-open').forEach(function(btn){
		btn.addEventListener('click', function(){
			var orderId = parseInt(this.getAttribute('data-id') || '0', 10);
			var courseSlotId = parseInt(this.getAttribute('data-course-slot-id') || '0', 10);
			var searchSlotId = parseInt(this.getAttribute('data-search-slot-id') || '0', 10);
			var duration = parseInt(this.getAttribute('data-duration') || '60', 10);
			var currentUtc = String(this.getAttribute('data-current-utc') || '').trim();
			if (!orderId && !courseSlotId && !searchSlotId) return;
			idInp.value = String(orderId > 0 ? orderId : 0);
			courseSlotInp.value = String(courseSlotId > 0 ? courseSlotId : 0);
			searchSlotInp.value = String(searchSlotId > 0 ? searchSlotId : 0);
			durationInp.value = String(isNaN(duration) ? 60 : duration);
			timeUtcInp.value = '';
			rForm.setAttribute('action', (courseSlotId > 0 || searchSlotId > 0) ? (this.getAttribute('data-reschedule-action') || defaultAction) : defaultAction);
			rCal.classList.add('preload');
			renderCalendar(
				searchSlotId > 0 ? readJson('reschedule-search-slot-' + searchSlotId) : (courseSlotId > 0 ? readJson('reschedule-course-slot-' + courseSlotId) : readJson('reschedule-slots-' + orderId)),
				currentUtc
			);
			jQuery(modal).modal('show');
		});
	});
	if (window.jQuery && modal) {
		jQuery(modal).on('shown.bs.modal', initRescheduleSlider);
		jQuery(modal).on('hidden.bs.modal', function () {
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
