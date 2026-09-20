<?php
\defined('_JEXEC') or die;

use Joomla\CMS\Router\Route;
use Joomla\CMS\Uri\Uri;

if (!function_exists('viglingAppointmentsParseJournalLabel')) {
	function viglingAppointmentsParseJournalLabel(string $label): array
	{
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
	}
}

if (!function_exists('viglingAppointmentsCountLabel')) {
	function viglingAppointmentsCountLabel(int $count): string
	{
		$n = abs($count);
		$mod10 = $n % 10;
		$mod100 = $n % 100;
		if ($mod10 === 1 && $mod100 !== 11) {
			return $n . ' запись';
		}
		if ($mod10 >= 2 && $mod10 <= 4 && ($mod100 < 12 || $mod100 > 14)) {
			return $n . ' записи';
		}

		return $n . ' записей';
	}
}

if (!function_exists('viglingAppointmentsProfileUrl')) {
	function viglingAppointmentsProfileUrl(int $userId): string
	{
		if ($userId <= 0) {
			return '';
		}

		return rtrim(Uri::root(true), '/') . '/' . $userId;
	}
}

if (!function_exists('viglingAppointmentsPersonLink')) {
	function viglingAppointmentsPersonLink(int $userId, string $name): string
	{
		$name = $name !== '' ? $name : '—';
		$url = viglingAppointmentsProfileUrl($userId);
		if ($url !== '' && $name !== '—') {
			return '<a href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '</a>';
		}

		return htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
	}
}

if (!function_exists('viglingAppointmentsRenderPeople')) {
	function viglingAppointmentsRenderPeople($item): string
	{
		$userId = (int) ($item->user_id ?? 0);
		if ($userId <= 0) {
			[$label] = viglingAppointmentsParseJournalLabel(trim((string) ($item->service_name ?? '')));

			return '<div class="appointments-person">• ' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</div>';
		}
		$clientName = trim((string) ($item->client_name ?? '—')) ?: '—';
		$masterName = trim((string) ($item->master_name ?? '—')) ?: '—';

		return '<div class="appointments-people">'
			. '<div class="appointments-person">• ' . viglingAppointmentsPersonLink($userId, $clientName) . '</div>'
			. '<div class="appointments-person">• ' . viglingAppointmentsPersonLink((int) ($item->master_id ?? 0), $masterName) . '</div>'
			. '</div>';
	}
}

if (!function_exists('viglingAppointmentsContactBits')) {
	function viglingAppointmentsContactBits($item): array
	{
		$bookingPhone = trim((string) ($item->contact_phone ?? ''));
		$bookingContactName = trim((string) ($item->contact_name ?? ''));

		return array_values(array_filter([
			$bookingContactName !== '' && $bookingContactName !== trim((string) ($item->client_name ?? '')) ? $bookingContactName : '',
			$bookingPhone !== '' ? $bookingPhone : '',
			trim((string) ($item->client_email ?? '')),
			$bookingPhone === '' ? trim((string) ($item->client_phone ?? '')) : '',
		], static fn($v) => $v !== '' && $v !== '—'));
	}
}

if (!function_exists('viglingAppointmentsDurationMin')) {
	function viglingAppointmentsDurationMin($item): int
	{
		$utc = new \DateTimeZone('UTC');
		$timeUtc = !empty($item->time) ? new \DateTime((string) $item->time, $utc) : null;
		$timeToUtc = !empty($item->time_to) ? new \DateTime((string) $item->time_to, $utc) : null;
		if ($timeUtc && $timeToUtc) {
			$diff = (int) floor(($timeToUtc->getTimestamp() - $timeUtc->getTimestamp()) / 60);
			if ($diff > 0) {
				return max(15, min(480, $diff));
			}
		}

		return 60;
	}
}

if (!function_exists('viglingAppointmentsBuildDisplayRows')) {
	function viglingAppointmentsBuildDisplayRows(array $items, int $viewerId): array
	{
		$displayRows = [];
		foreach ($items as $item) {
			$bookingKind = trim((string) ($item->booking_kind ?? 'service'));
			$courseSlotId = (int) ($item->course_slot_id ?? 0);
			$searchSlotId = (int) ($item->search_slot_id ?? 0);
			$userId = (int) ($item->user_id ?? 0);
			$isMasterOfItem = $viewerId > 0 && (int) ($item->master_id ?? 0) === $viewerId;
			if ($userId <= 0 || $bookingKind === 'journal') {
				$displayRows[] = ['type' => 'block', 'item' => $item];
				continue;
			}
			if ($isMasterOfItem && $bookingKind === 'course' && $courseSlotId > 0) {
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
			if ($isMasterOfItem && $bookingKind === 'search' && $searchSlotId > 0) {
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
			$displayRows[] = ['type' => 'single', 'item' => $item];
		}

		return $displayRows;
	}
}

if (!function_exists('viglingAppointmentsRenderClientActions')) {
	function viglingAppointmentsRenderClientActions($item, bool $isPast, string $token, string $returnEncoded, string $timeIso, $db, string $rescheduleAction, bool $withRepeat = true): string
	{
		$durationMin = viglingAppointmentsDurationMin($item);
		$isFixedCourse = trim((string) ($item->booking_kind ?? 'service')) === 'course' && (int) ($item->course_slot_id ?? 0) > 0;
		$isFixedSearch = trim((string) ($item->booking_kind ?? 'service')) === 'search' && (int) ($item->search_slot_id ?? 0) > 0;
		$isPromotion = trim((string) ($item->booking_kind ?? 'service')) === 'stock' || (int) ($item->stock_service_id ?? 0) > 0;
		$slotPayload = viglingOrdersBuildRescheduleSlots($db, (int) $item->master_id, $durationMin, (int) $item->id, 0, 45);
		$slotsJson = json_encode($slotPayload['days'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
		ob_start();
		?>
		<?php if (!$isFixedCourse && !$isFixedSearch) : ?>
		<button type="button" class="btn btn-xs btn-warning reschedule-open"<?php echo $isPast ? ' disabled' : ''; ?> data-id="<?php echo (int) $item->id; ?>" data-duration="<?php echo (int) $durationMin; ?>" data-current-utc="<?php echo htmlspecialchars($timeIso, ENT_QUOTES, 'UTF-8'); ?>" data-reschedule-action="<?php echo htmlspecialchars($rescheduleAction, ENT_QUOTES, 'UTF-8'); ?>" data-slots-embedded="1">Перенести</button>
		<?php endif; ?>
		<?php if ($isPast) : ?>
		<form method="post" action="<?php echo Route::_('index.php?option=com_orders&task=orders.delete'); ?>" class="form-inline form-delete" style="display:inline;">
			<input type="hidden" name="<?php echo $token; ?>" value="1">
			<input type="hidden" name="id" value="<?php echo (int) $item->id; ?>">
			<input type="hidden" name="return" value="<?php echo $returnEncoded; ?>">
			<button type="submit" class="btn btn-xs btn-default" onclick="return confirm('Удалить запись из списка?');">Удалить</button>
		</form>
		<?php if (!$isFixedCourse && !$isFixedSearch && !$isPromotion && $withRepeat) : ?>
		<button type="button" class="z-link review-zlink repeat-open" style="min-height: 18px;" data-id="<?php echo (int) $item->id; ?>" data-duration="<?php echo (int) $durationMin; ?>" data-slots-embedded="1">Повторить<span></span></button>
		<?php endif; ?>
		<?php else : ?>
		<form method="post" action="<?php echo Route::_('index.php?option=com_orders&task=orders.cancel'); ?>" class="form-inline form-cancel" style="display:inline;">
			<input type="hidden" name="<?php echo $token; ?>" value="1">
			<input type="hidden" name="id" value="<?php echo (int) $item->id; ?>">
			<input type="hidden" name="return" value="<?php echo $returnEncoded; ?>">
			<button type="submit" class="btn btn-xs btn-danger" onclick="return confirm('<?php echo ($isFixedCourse || $isFixedSearch) ? 'Отменить участие?' : 'Отменить запись?'; ?>');"><?php echo ($isFixedCourse || $isFixedSearch) ? 'Отменить участие' : 'Отменить'; ?></button>
		</form>
		<?php endif; ?>
		<script type="application/json" id="reschedule-slots-<?php echo (int) $item->id; ?>"><?php echo $slotsJson ?: '[]'; ?></script>
		<?php
		return (string) ob_get_clean();
	}
}

if (!function_exists('viglingAppointmentsRenderMasterActions')) {
	function viglingAppointmentsRenderMasterActions($item, bool $isPast, bool $completed, string $token, string $returnEncoded, string $timeIso, $db): string
	{
		$durationMin = viglingAppointmentsDurationMin($item);
		$isFixedCourse = trim((string) ($item->booking_kind ?? 'service')) === 'course' && (int) ($item->course_slot_id ?? 0) > 0;
		$isFixedSearch = trim((string) ($item->booking_kind ?? 'service')) === 'search' && (int) ($item->search_slot_id ?? 0) > 0;
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
			<button type="button" class="btn btn-xs btn-warning reschedule-open" data-id="<?php echo (int) $item->id; ?>" data-duration="<?php echo (int) $durationMin; ?>" data-current-utc="<?php echo htmlspecialchars($timeIso, ENT_QUOTES, 'UTF-8'); ?>" data-slots-embedded="1">Перенести</button>
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
	}
}

if (!function_exists('viglingAppointmentsRenderCourseSlotActions')) {
	function viglingAppointmentsRenderCourseSlotActions($item, bool $isPast, string $token, string $returnEncoded, string $rescheduleCourseAction, $db): string
	{
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
			<button type="button" class="btn btn-xs btn-warning reschedule-open" data-course-slot-id="<?php echo $courseSlotId; ?>" data-reschedule-action="<?php echo htmlspecialchars($rescheduleCourseAction, ENT_QUOTES, 'UTF-8'); ?>" data-duration="<?php echo $durationMin; ?>" data-current-utc="<?php echo htmlspecialchars($timeIso, ENT_QUOTES, 'UTF-8'); ?>" data-slots-embedded="1">Перенести курс</button>
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
	}
}

if (!function_exists('viglingAppointmentsRenderSearchSlotActions')) {
	function viglingAppointmentsRenderSearchSlotActions($item, bool $isPast, string $token, string $returnEncoded, string $rescheduleSearchAction, $db): string
	{
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
			<button type="button" class="btn btn-xs btn-warning reschedule-open" data-search-slot-id="<?php echo $searchSlotId; ?>" data-reschedule-action="<?php echo htmlspecialchars($rescheduleSearchAction, ENT_QUOTES, 'UTF-8'); ?>" data-duration="<?php echo $durationMin; ?>" data-current-utc="<?php echo htmlspecialchars($timeIso, ENT_QUOTES, 'UTF-8'); ?>" data-slots-embedded="1">Перенести поиск</button>
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
	}
}

if (!function_exists('viglingAppointmentsRenderBlockActions')) {
	function viglingAppointmentsRenderBlockActions($item, string $token, string $returnEncoded): string
	{
		ob_start();
		?>
		<form method="post" action="<?php echo Route::_('index.php?option=com_orders&task=orders.journalDelete'); ?>" class="form-inline" style="display:inline;">
			<input type="hidden" name="<?php echo $token; ?>" value="1">
			<input type="hidden" name="id" value="<?php echo (int) $item->id; ?>">
			<input type="hidden" name="return" value="<?php echo $returnEncoded; ?>">
			<button type="submit" class="btn btn-xs btn-default" onclick="return confirm('Удалить блок времени?');">Удалить</button>
		</form>
		<?php
		return (string) ob_get_clean();
	}
}

if (!function_exists('viglingAppointmentsRenderItemActions')) {
	function viglingAppointmentsRenderItemActions($item, bool $isPast, string $token, string $returnEncoded, string $timeIso, $db, string $rescheduleAction): string
	{
		if ((int) ($item->user_id ?? 0) <= 0) {
			return viglingAppointmentsRenderBlockActions($item, $token, $returnEncoded);
		}
		if (!empty($item->_viewer_is_master)) {
			return viglingAppointmentsRenderMasterActions($item, $isPast, !empty($item->completed), $token, $returnEncoded, $timeIso, $db);
		}

		return viglingAppointmentsRenderClientActions($item, $isPast, $token, $returnEncoded, $timeIso, $db, $rescheduleAction);
	}
}
