<?php

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Session\Session;
use Joomla\CMS\Uri\Uri;

require_once __DIR__ . '/_reschedule_helper.php';

/** @var \Viglin\Component\Orders\Site\View\Orders\HtmlView $this */
$items = $this->items;
$token = Session::getFormToken();
$returnEncoded = base64_encode(Uri::getInstance()->toString());
$db = Factory::getContainer()->get(\Joomla\Database\DatabaseInterface::class);
$rescheduleAction = Route::_('index.php?option=com_orders&task=orders.rescheduleByMaster');
$rescheduleCourseAction = Route::_('index.php?option=com_orders&task=orders.rescheduleCourseSlotByMaster');
$rescheduleSearchAction = Route::_('index.php?option=com_orders&task=orders.rescheduleSearchSlotByMaster');
$displayRows = [];
foreach ($items as $item) {
	$bookingKind = trim((string) ($item->booking_kind ?? 'service'));
	$courseSlotId = (int) ($item->course_slot_id ?? 0);
	$searchSlotId = (int) ($item->search_slot_id ?? 0);
	if ($bookingKind === 'course' && $courseSlotId > 0) {
		$key = 'course-slot-' . $courseSlotId;
		if (!isset($displayRows[$key])) {
			$displayRows[$key] = [
				'type' => 'course-group',
				'kind' => 'course',
				'slot_id' => $courseSlotId,
				'item' => $item,
				'participants' => [],
			];
		}
		$displayRows[$key]['participants'][] = $item;
		continue;
	}
	if ($bookingKind === 'search' && $searchSlotId > 0) {
		$key = 'search-slot-' . $searchSlotId;
		if (!isset($displayRows[$key])) {
			$displayRows[$key] = [
				'type' => 'search-group',
				'kind' => 'search',
				'slot_id' => $searchSlotId,
				'item' => $item,
				'participants' => [],
			];
		}
		$displayRows[$key]['participants'][] = $item;
		continue;
	}
	$displayRows[] = [
		'type' => 'single',
		'item' => $item,
	];
}
$renderOrderActions = static function ($item, bool $isPast, bool $completed, string $token, string $returnEncoded, string $timeIso) use ($db): string {
	$durationMin = 60;
	$isFixedCourse = trim((string) ($item->booking_kind ?? 'service')) === 'course' && (int) ($item->course_slot_id ?? 0) > 0;
	$isFixedSearch = trim((string) ($item->booking_kind ?? 'service')) === 'search' && (int) ($item->search_slot_id ?? 0) > 0;
	$utc = new \DateTimeZone('UTC');
	$timeUtc = !empty($item->time) ? new \DateTime($item->time, $utc) : null;
	$timeToUtc = !empty($item->time_to) ? new \DateTime($item->time_to, $utc) : null;
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
		<?php if ($isFixedCourse || $isFixedSearch) : ?>
			<span class="course-meta">Для fixed-<?php echo $isFixedSearch ? 'поиска' : 'курса'; ?> доступны только действия на уровне всего слота</span>
		<?php else : ?>
			<button type="button" class="btn btn-xs btn-warning reschedule-open" data-id="<?php echo (int) $item->id; ?>" data-duration="<?php echo (int) $durationMin; ?>" data-current-utc="<?php echo htmlspecialchars($timeIso, ENT_QUOTES, 'UTF-8'); ?>">Перенести</button>
			<form method="post" action="<?php echo Route::_('index.php?option=com_orders&task=orders.cancelByMaster'); ?>" class="form-inline" style="display:inline;">
				<input type="hidden" name="<?php echo $token; ?>" value="1">
				<input type="hidden" name="id" value="<?php echo (int) $item->id; ?>">
				<input type="hidden" name="return" value="<?php echo $returnEncoded; ?>">
				<button type="submit" class="btn btn-xs btn-danger" onclick="return confirm('Отменить запись? Клиенту придёт уведомление.');">Отменить</button>
			</form>
		<?php endif; ?>
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
		<button
			type="button"
			class="btn btn-xs btn-warning reschedule-open"
			data-course-slot-id="<?php echo $courseSlotId; ?>"
			data-reschedule-action="<?php echo htmlspecialchars($rescheduleCourseAction, ENT_QUOTES, 'UTF-8'); ?>"
			data-duration="<?php echo $durationMin; ?>"
			data-current-utc="<?php echo htmlspecialchars($timeIso, ENT_QUOTES, 'UTF-8'); ?>"
		>Перенести курс</button>
		<form method="post" action="<?php echo Route::_('index.php?option=com_orders&task=orders.cancelCourseSlotByMaster'); ?>" class="form-inline" style="display:inline;">
			<input type="hidden" name="<?php echo $token; ?>" value="1">
			<input type="hidden" name="course_slot_id" value="<?php echo $courseSlotId; ?>">
			<input type="hidden" name="return" value="<?php echo $returnEncoded; ?>">
			<button type="submit" class="btn btn-xs btn-danger" onclick="return confirm('Отменить курс для всех участников? Всем придёт уведомление.');">Отменить курс</button>
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
		<button
			type="button"
			class="btn btn-xs btn-warning reschedule-open"
			data-search-slot-id="<?php echo $searchSlotId; ?>"
			data-reschedule-action="<?php echo htmlspecialchars($rescheduleSearchAction, ENT_QUOTES, 'UTF-8'); ?>"
			data-duration="<?php echo $durationMin; ?>"
			data-current-utc="<?php echo htmlspecialchars($timeIso, ENT_QUOTES, 'UTF-8'); ?>"
		>Перенести поиск</button>
		<form method="post" action="<?php echo Route::_('index.php?option=com_orders&task=orders.cancelSearchSlotByMaster'); ?>" class="form-inline" style="display:inline;">
			<input type="hidden" name="<?php echo $token; ?>" value="1">
			<input type="hidden" name="search_slot_id" value="<?php echo $searchSlotId; ?>">
			<input type="hidden" name="return" value="<?php echo $returnEncoded; ?>">
			<button type="submit" class="btn btn-xs btn-danger" onclick="return confirm('Отменить поиск для всех участников? Всем придёт уведомление.');">Отменить поиск</button>
		</form>
	<?php endif; ?>
	<script type="application/json" id="reschedule-search-slot-<?php echo $searchSlotId; ?>"><?php echo $slotsJson ?: '[]'; ?></script>
	<?php
	return (string) ob_get_clean();
};
?>
<div class="com_orders orders-list orders-list--clients">
	<style>
	.com_orders .orders-table .orders-row--past { background-color: #e9ecef; color: #6c757d; }
	.com_orders .orders-table .orders-row--past a { color: #495057; }
	.com_orders .orders-table .orders-actions .btn { margin-right: 6px; margin-bottom: 4px; }
	.com_orders .orders-table .orders-row--course-summary { background: #fffdf3; }
	.com_orders .orders-table .orders-row--course-details { background: #fffdfb; display: none; }
	.com_orders .orders-table .orders-row--course-details.is-open { display: table-row; }
	.com_orders .orders-table .course-meta { font-size: 13px; color: #666; margin-top: 4px; }
	.com_orders .orders-table .course-participants { display: grid; gap: 12px; }
	.com_orders .orders-table .course-participant { border: 1px solid #ece4b6; border-radius: 10px; padding: 12px; background: #fff; }
	.com_orders .orders-table .course-participant-head { display: flex; justify-content: space-between; gap: 12px; margin-bottom: 8px; flex-wrap: wrap; }
	.com_orders .orders-table .course-participant-name { font-weight: 600; }
	.com_orders .orders-table .course-participant-time { color: #555; }
	.com_orders .orders-table .course-toggle[aria-expanded="true"] .course-toggle-open { display: none; }
	.com_orders .orders-table .course-toggle[aria-expanded="false"] .course-toggle-close { display: none; }
	.com_orders .reschedule-open { margin-right: 6px; margin-bottom: 4px; }
	#zapis-reschedule .modal-dialog {
		width: 96vw !important;
		max-width: 1180px !important;
		margin: 30px auto !important;
	}
	#zapis-reschedule .modal-content { overflow: hidden; }
	#zapis-reschedule .modal-body { overflow: hidden; padding: 20px 28px 28px; }
	#zapis-reschedule .calendar__master.preload { visibility: hidden; }
	#zapis-reschedule #reschedule-calendar {
		width: 100% !important;
		max-width: 800px;
		margin: 0 auto !important;
	}
	#zapis-reschedule #reschedule-calendar .slick-list {
		margin: 0 -8px;
		padding: 4px 0 10px;
		overflow: hidden;
	}
	#zapis-reschedule #reschedule-calendar .slick-slide,
	#zapis-reschedule #reschedule-calendar .slick-slide > div {
		height: auto !important;
	}
	#zapis-reschedule #reschedule-calendar .calendar__master-item {
		padding: 0 8px;
		box-sizing: border-box;
	}
	#zapis-reschedule #reschedule-calendar .btns-m { padding-top: 2px; }
	#zapis-reschedule #reschedule-calendar .btn-select { margin-bottom: 18px; }
	#zapis-reschedule #reschedule-calendar .slick-prev,
	#zapis-reschedule #reschedule-calendar .slick-next {
		z-index: 5;
	}
	#zapis-reschedule .calc__btn {
		padding-left: 0;
		display: flex;
		justify-content: center;
		gap: 16px;
	}
	#zapis-reschedule .calc__btn .btn-next,
	#zapis-reschedule .calc__btn .close__btn {
		float: none;
		margin: 0;
	}
	#zapis-reschedule .calendar-hint { margin: 6px 0 14px; font-size: 13px; color: #777; }
	#zapis-reschedule .calendar-hint i { font-style: normal; color: #111; }
	#zapis-reschedule .line-no { display: inline-block; color: #999; font-size: 13px; margin-top: 8px; }
	#zapis-reschedule .error-msg { color: #a94442; margin-top: 10px; display: none; }
	#zapis-reschedule .btn-next.is-loading { pointer-events: none; opacity: .9; }
	#zapis-reschedule .btn-next .btn-spinner {
		display: none;
		width: 14px;
		height: 14px;
		margin-right: 8px;
		border: 2px solid rgba(0, 0, 0, .25);
		border-top-color: #000;
		border-radius: 50%;
		animation: rescheduleSpin .75s linear infinite;
		vertical-align: middle;
	}
	#zapis-reschedule .btn-next.is-loading .btn-spinner { display: inline-block; }
	@keyframes rescheduleSpin { to { transform: rotate(360deg); } }
	@media (max-width: 768px) {
		#zapis-reschedule .modal-dialog {
			width: min(96vw, 520px) !important;
			margin: 12px auto !important;
		}
		#zapis-reschedule .modal-content {
			max-height: calc(100vh - 24px);
			display: flex;
			flex-direction: column;
		}
		#zapis-reschedule .modal-body {
			overflow-y: auto;
			-webkit-overflow-scrolling: touch;
		}
		#zapis-reschedule .calc__body {
			padding-bottom: 8px;
		}
		#zapis-reschedule #reschedule-calendar .calendar__master-item {
			max-height: calc(100vh - 260px);
			overflow: hidden;
		}
		#zapis-reschedule #reschedule-calendar .calendar__master-item .btns-m {
			max-height: calc(100vh - 360px);
			overflow-y: auto;
			-webkit-overflow-scrolling: touch;
			padding-right: 4px;
			margin-bottom: 0;
		}
		#zapis-reschedule .calc__btn {
			display: flex;
			flex-direction: column;
			gap: 12px;
			padding-left: 0;
		}
		#zapis-reschedule .calc__btn .btn-next,
		#zapis-reschedule .calc__btn .close__btn {
			width: 100%;
			margin: 0;
			float: none;
		}
		.com_orders.orders-list--clients .orders-table { display: block; border: 0; background: transparent; }
		.com_orders.orders-list--clients .orders-table thead { display: none; }
		.com_orders.orders-list--clients .orders-table tbody { display: block; }
		.com_orders.orders-list--clients .orders-table .orders-row {
			display: block;
			border: 1px solid #d9d9d9;
			border-radius: 12px;
			margin-bottom: 14px;
			padding: 12px;
			background: #fff;
			box-shadow: 0 2px 8px rgba(0,0,0,0.06);
		}
		.com_orders.orders-list--clients .orders-table .orders-row td {
			display: grid;
			grid-template-columns: 96px minmax(0, 1fr);
			align-items: start;
			column-gap: 10px;
			border: 0;
			padding: 6px 0;
			line-height: 1.25;
		}
		.com_orders.orders-list--clients .orders-table .orders-row td::before {
			content: attr(data-label);
			font-weight: 600;
			color: #666;
			display: block;
		}
		.com_orders.orders-list--clients .orders-table .orders-row td a {
			overflow-wrap: anywhere;
			word-break: break-word;
		}
		.com_orders.orders-list--clients .orders-table .orders-row td[data-label="Контакты"] {
			overflow-wrap: anywhere;
			word-break: break-word;
		}
		.com_orders .order-comment {
			margin-top: 6px;
			font-size: 13px;
			color: #555;
			white-space: pre-wrap;
		}
		.com_orders.orders-list--clients .orders-table .orders-row td.orders-actions {
			display: block;
			margin-top: 8px;
			padding-top: 10px;
			border-top: 1px solid #ececec;
		}
		.com_orders.orders-list--clients .orders-table .orders-row td.orders-actions::before {
			display: block;
			margin-bottom: 8px;
		}
		.com_orders.orders-list--clients .orders-table .orders-row td.orders-actions .btn,
		.com_orders.orders-list--clients .orders-table .orders-row td.orders-actions .reschedule-open {
			min-width: 114px;
			margin: 0 8px 8px 0;
			text-align: center;
		}
		.com_orders.orders-list--clients .orders-table .orders-row td.orders-actions .form-inline {
			display: inline-block;
		}
	}
	</style>
	<h1 class="page-title">Записи ко мне</h1>
	<?php if (empty($items)) : ?>
		<p class="alert alert-info">К вам пока никто не записывался. <a href="<?php echo Route::_('index.php?option=com_users&view=profile'); ?>">Перейти в профиль</a></p>
	<?php else : ?>
		<table class="table table-striped orders-table">
			<thead>
				<tr>
					<th>Клиент</th>
					<th>Услуга</th>
					<th>Дата и время</th>
					<th>Контакты</th>
					<th>Действия</th>
				</tr>
			</thead>
			<tbody>
				<?php
				$utc = new \DateTimeZone('UTC');
				$nowUtc = new \DateTime('now', $utc);
				foreach ($displayRows as $row) :
					if (in_array(($row['type'] ?? ''), ['course-group', 'search-group'], true)) :
						$item = $row['item'];
						$isSearchGroup = ($row['kind'] ?? '') === 'search';
						$entityLabel = $isSearchGroup ? 'Поиск моделей' : 'Курс';
						$entityLabelLower = $isSearchGroup ? 'поиска моделей' : 'курса';
						$participants = $row['participants'] ?? [];
						$participantCount = count($participants);
						$capacityTotal = $isSearchGroup
							? (int) ($item->search_slot_capacity_total ?? $item->search_capacity ?? 0)
							: (int) ($item->course_slot_capacity_total ?? $item->course_capacity ?? 0);
						$timeUtc = $item->time ? new \DateTime($item->time, $utc) : null;
						$timeToUtc = $item->time_to ? new \DateTime($item->time_to, $utc) : null;
						$timeIso = $timeUtc ? $timeUtc->format('c') : '';
						$isPast = $timeUtc ? ($timeUtc < $nowUtc) : false;
						$rowClass = 'orders-row orders-row--course-summary' . ($isPast ? ' orders-row--past' : '');
						$toggleId = ($isSearchGroup ? 'search' : 'course') . '-participants-' . (int) $row['slot_id'];
						$entityTitle = trim((string) ($item->service_display_name ?? $item->service_name));
						$entityCategoryTitle = trim((string) ($isSearchGroup ? ($item->search_category_title ?? '') : ($item->course_category_title ?? '')));
					?>
					<tr class="<?php echo $rowClass; ?>" data-time-utc="<?php echo $timeIso ? $this->escape($timeIso) : ''; ?>">
						<td data-label="Клиент">
							<strong><?php echo $entityLabel; ?></strong>
							<div class="course-meta">
								<?php echo $participantCount; ?>
								из
								<?php echo $capacityTotal > 0 ? $capacityTotal : '—'; ?>
								мест занято
							</div>
						</td>
						<td data-label="Услуга">
							<?php echo htmlspecialchars($entityTitle !== '' ? $entityTitle : $entityLabel); ?>
							<?php if ($entityCategoryTitle !== '') : ?>
								<div class="course-meta"><?php echo htmlspecialchars($entityCategoryTitle); ?></div>
							<?php endif; ?>
						</td>
						<td data-label="Дата и время"><span class="lk-time-utc" data-time-utc="<?php echo $timeIso ? $this->escape($timeIso) : ''; ?>">—</span></td>
						<td data-label="Контакты">Участники внутри слота</td>
						<td class="orders-actions" data-label="Действия">
							<?php echo $isSearchGroup
								? $renderSearchSlotActions($item, $isPast, $token, $returnEncoded, $rescheduleSearchAction)
								: $renderCourseSlotActions($item, $isPast, $token, $returnEncoded, $rescheduleCourseAction); ?>
							<button type="button" class="btn btn-xs btn-default course-toggle" data-target="<?php echo $toggleId; ?>" aria-expanded="false">
								<span class="course-toggle-open">Показать участников</span>
								<span class="course-toggle-close">Свернуть участников</span>
							</button>
						</td>
					</tr>
					<tr class="orders-row orders-row--course-details" id="<?php echo $toggleId; ?>">
						<td colspan="5">
							<div class="course-participants">
								<?php foreach ($participants as $participant) :
									$participantTimeUtc = $participant->time ? new \DateTime($participant->time, $utc) : null;
									$participantTimeIso = $participantTimeUtc ? $participantTimeUtc->format('c') : '';
									$participantIsPast = $participantTimeUtc ? ($participantTimeUtc < $nowUtc) : false;
									$participantCompleted = !empty($participant->completed);
									$participantContacts = array_filter([
										trim((string) ($participant->contact_name ?? '')) !== ''
											&& trim((string) ($participant->contact_name ?? '')) !== trim((string) ($participant->client_name ?? ''))
											? trim((string) $participant->contact_name)
											: '',
										trim((string) ($participant->contact_phone ?? '')) !== ''
											? trim((string) $participant->contact_phone)
											: '',
										trim((string) ($participant->client_email ?? '')),
										trim((string) ($participant->contact_phone ?? '')) === ''
											? trim((string) ($participant->client_phone ?? ''))
											: '',
									]);
									$clientProfileUrl = rtrim(Uri::root(true), '/') . '/' . (int) $participant->user_id;
								?>
								<div class="course-participant">
									<div class="course-participant-head">
										<div>
											<div class="course-participant-name">
												<?php if (($participant->client_name ?? '—') !== '—' && (int) $participant->user_id > 0) : ?>
													<a href="<?php echo htmlspecialchars($clientProfileUrl); ?>"><?php echo htmlspecialchars((string) $participant->client_name); ?></a>
												<?php else : ?>
													<?php echo htmlspecialchars((string) ($participant->client_name ?? '—')); ?>
												<?php endif; ?>
											</div>
											<div class="course-meta"><?php echo $participantContacts !== [] ? htmlspecialchars(implode(', ', $participantContacts)) : '—'; ?></div>
											<?php if (trim((string) ($participant->comment ?? '')) !== '') : ?>
												<div class="order-comment"><?php echo htmlspecialchars((string) $participant->comment); ?></div>
											<?php endif; ?>
										</div>
										<div class="course-participant-time">
											<span class="lk-time-utc" data-time-utc="<?php echo $participantTimeIso ? $this->escape($participantTimeIso) : ''; ?>">—</span>
										</div>
									</div>
									<div class="orders-actions">
										<?php echo $renderOrderActions($participant, $participantIsPast, $participantCompleted, $token, $returnEncoded, $participantTimeIso); ?>
									</div>
								</div>
								<?php endforeach; ?>
							</div>
						</td>
					</tr>
					<?php
						continue;
					endif;
					$item = $row['item'];
					$timeUtc = $item->time ? new \DateTime($item->time, $utc) : null;
					$timeToUtc = $item->time_to ? new \DateTime($item->time_to, $utc) : null;
					$timeIso = $timeUtc ? $timeUtc->format('c') : '';
					$isPast = $timeUtc ? ($timeUtc < $nowUtc) : false;
					$completed = !empty($item->completed);
					$rowClass = 'orders-row' . ($isPast ? ' orders-row--past' : '');
					$clientProfileUrl = rtrim(Uri::root(true), '/') . '/' . (int) $item->user_id;
				?>
				<tr class="<?php echo $rowClass; ?>" data-time-utc="<?php echo $timeIso ? $this->escape($timeIso) : ''; ?>">
					<td data-label="Клиент">
						<?php if ($item->client_name !== '—' && (int) $item->user_id > 0) : ?>
							<a href="<?php echo htmlspecialchars($clientProfileUrl); ?>"><?php echo htmlspecialchars($item->client_name); ?></a>
						<?php else : ?>
							<?php echo htmlspecialchars($item->client_name); ?>
						<?php endif; ?>
					</td>
					<td data-label="Услуга">
						<?php echo htmlspecialchars((string) ($item->service_display_name ?? $item->service_name)); ?>
						<?php if (trim((string) ($item->comment ?? '')) !== '') : ?>
							<div class="order-comment"><?php echo htmlspecialchars((string) $item->comment); ?></div>
						<?php endif; ?>
					</td>
					<td data-label="Дата и время"><span class="lk-time-utc" data-time-utc="<?php echo $timeIso ? $this->escape($timeIso) : ''; ?>">—</span></td>
					<td data-label="Контакты"><?php
						$bookingPhone = trim((string) ($item->contact_phone ?? ''));
						$bookingContactName = trim((string) ($item->contact_name ?? ''));
						$contactBits = array_filter([
							$bookingContactName !== '' && $bookingContactName !== trim((string) ($item->client_name ?? '')) ? $bookingContactName : '',
							$bookingPhone !== '' ? $bookingPhone : '',
							trim((string) ($item->client_email ?? '')),
							$bookingPhone === '' ? trim((string) ($item->client_phone ?? '')) : '',
						]);
						echo $contactBits !== [] ? htmlspecialchars(implode(', ', $contactBits)) : '—';
					?></td>
					<td class="orders-actions" data-label="Действия">
						<?php echo $renderOrderActions($item, $isPast, $completed, $token, $returnEncoded, $timeIso); ?>
					</td>
				</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>
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
						<button type="submit" class="btn-next" id="reschedule-modal-submit"><span class="btn-spinner" aria-hidden="true"></span><span class="btn-label">Сохранить</span></button>
						<button type="button" class="close__btn" data-dismiss="modal">Отмена</button>
					</div>
				</form>
			</div>
		</div>
	</div>
</div>

<script>
(function(){
	var modal = document.getElementById('zapis-reschedule');
	var form = document.getElementById('reschedule-modal-form');
	var cal = document.getElementById('reschedule-calendar');
	var idInp = document.getElementById('reschedule-modal-id');
	var courseSlotInp = document.getElementById('reschedule-modal-course-slot-id');
	var searchSlotInp = document.getElementById('reschedule-modal-search-slot-id');
	var durationInp = document.getElementById('reschedule-modal-duration');
	var timeUtcInp = document.getElementById('reschedule-modal-time-utc');
	var errorEl = document.getElementById('reschedule-modal-error');
	var submitBtn = document.getElementById('reschedule-modal-submit');
	var defaultAction = form.getAttribute('action') || '';

	function destroySlider(){
		if (!window.jQuery) return;
		var jqCal = jQuery(cal);
		if (jqCal.hasClass('slick-initialized')) {
			try { jqCal.slick('unslick'); } catch (e) {}
		}
	}

	function initSlider(){
		if (!window.jQuery) {
			cal.classList.remove('preload');
			return;
		}
		var jqCal = jQuery(cal);
		if (!cal.querySelector('.calendar__master-item')) {
			jqCal.removeClass('preload');
			return;
		}
		setTimeout(function(){
			if (jqCal.hasClass('slick-initialized')) {
				jqCal.slick('setPosition');
				jqCal.slick('refresh');
			} else {
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

	function readSlots(orderId){
		var node = document.getElementById('reschedule-slots-' + orderId);
		if (!node) return [];
		try {
			var parsed = JSON.parse(node.textContent || '[]');
			return Array.isArray(parsed) ? parsed : [];
		} catch (e) {
			return [];
		}
	}

	function readCourseSlots(courseSlotId){
		var node = document.getElementById('reschedule-course-slot-' + courseSlotId);
		if (!node) return [];
		try {
			var parsed = JSON.parse(node.textContent || '[]');
			return Array.isArray(parsed) ? parsed : [];
		} catch (e) {
			return [];
		}
	}

	function readSearchSlots(searchSlotId){
		var node = document.getElementById('reschedule-search-slot-' + searchSlotId);
		if (!node) return [];
		try {
			var parsed = JSON.parse(node.textContent || '[]');
			return Array.isArray(parsed) ? parsed : [];
		} catch (e) {
			return [];
		}
	}

	function hideError(){
		if (!errorEl) return;
		errorEl.style.display = 'none';
		errorEl.textContent = '';
	}

	function showError(msg){
		if (!errorEl) return;
		errorEl.style.display = 'block';
		errorEl.textContent = msg;
	}

	function renderCalendar(days, currentUtc){
		destroySlider();
		cal.innerHTML = '';
		if (!Array.isArray(days) || !days.length) {
			var emptyItem = document.createElement('div');
			emptyItem.className = 'calendar__master-item';
			var emptyNo = document.createElement('span');
			emptyNo.className = 'line-no';
			emptyNo.textContent = 'Нет доступного времени для переноса';
			emptyItem.appendChild(emptyNo);
			cal.appendChild(emptyItem);
			if (submitBtn) {
				submitBtn.disabled = true;
			}
			return;
		}
		(days || []).forEach(function(day, dayIdx){
			var item = document.createElement('div');
			item.className = 'calendar__master-item';
			var head = document.createElement('span');
			head.className = 'mas-date';
			head.innerHTML = String(day.date_view || '') + '<b>' + String(day.dow || '') + '</b>';
			item.appendChild(head);

			var slots = Array.isArray(day.slots) ? day.slots : [];
			if (!slots.length) {
				var no = document.createElement('span');
				no.className = 'line-no';
				no.textContent = 'Нет времени';
				item.appendChild(no);
				cal.appendChild(item);
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
				var lbl = document.createElement('label');
				lbl.className = 'btn-select';
				lbl.setAttribute('for', inputId);
				lbl.textContent = String(slot.label || '');
				wrap.appendChild(inp);
				wrap.appendChild(lbl);
				if (currentUtc && utc === currentUtc) {
					inp.checked = true;
				}
			});
			item.appendChild(wrap);
			cal.appendChild(item);
		});

		if (!cal.querySelector('input[name="reschedule_slot"]') && cal.querySelector('.btns-m input')) {
			cal.querySelector('.btns-m input').checked = true;
		}
		if (submitBtn) {
			submitBtn.disabled = !cal.querySelector('input[name="reschedule_slot"]');
		}
	}

	document.querySelectorAll('.reschedule-open').forEach(function(btn){
		btn.addEventListener('click', function(){
			hideError();
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
			form.setAttribute('action', (courseSlotId > 0 || searchSlotId > 0) ? (this.getAttribute('data-reschedule-action') || defaultAction) : defaultAction);
			cal.classList.add('preload');
			renderCalendar(
				searchSlotId > 0 ? readSearchSlots(searchSlotId) : (courseSlotId > 0 ? readCourseSlots(courseSlotId) : readSlots(orderId)),
				currentUtc
			);
			jQuery(modal).modal('show');
		});
	});

	document.querySelectorAll('.course-toggle').forEach(function(btn){
		btn.addEventListener('click', function(){
			var targetId = this.getAttribute('data-target');
			if (!targetId) return;
			var row = document.getElementById(targetId);
			if (!row) return;
			var isOpen = row.classList.toggle('is-open');
			this.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
		});
	});

	jQuery(modal).on('shown.bs.modal', function () {
		initSlider();
	});

	jQuery(modal).on('hidden.bs.modal', function () {
		hideError();
		timeUtcInp.value = '';
		idInp.value = '0';
		courseSlotInp.value = '0';
		searchSlotInp.value = '0';
		form.setAttribute('action', defaultAction);
		destroySlider();
		cal.innerHTML = '';
		cal.classList.add('preload');
	});

	form.addEventListener('submit', function(e){
		hideError();
		var selected = form.querySelector('input[name="reschedule_slot"]:checked');
		if (!selected || !selected.value) {
			e.preventDefault();
			showError('Выберите доступное время для переноса');
			return;
		}
		timeUtcInp.value = String(selected.value);
		if (submitBtn) {
			submitBtn.classList.add('is-loading');
			submitBtn.setAttribute('disabled', 'disabled');
		}
	});
})();
</script>
