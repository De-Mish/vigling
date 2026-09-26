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
$rescheduleAction = Route::_('index.php?option=com_orders&task=orders.reschedule');
$repeatAction = Route::_('index.php?option=com_orders&task=orders.repeat');
?>
<div class="com_orders orders-list">
	<style>
	.com_orders .reschedule-open { margin-right: 8px; margin-bottom: 4px; }
	.com_orders button.repeat-open.review-zlink {
		margin-left: 8px !important;
		margin-right: 0 !important;
		margin-bottom: 4px !important;
		vertical-align: middle;
	}
	.com_orders .order-comment {
		margin-top: 6px;
		font-size: 13px;
		color: #555;
	}
	.com_orders .order-feedback {
		margin-top: 10px;
		padding-top: 10px;
		border-top: 1px solid #eee;
	}
	.com_orders .order-feedback-form {
		margin-top: 8px;
	}
	.com_orders .order-feedback-form textarea {
		display: block;
		width: 100%;
		max-width: 360px;
		margin: 4px 0 8px;
		padding: 6px 8px;
		box-sizing: border-box;
		font-size: 13px;
	}
	.com_orders .order-feedback-label,
	.com_orders .order-feedback-inline {
		display: block;
		font-size: 13px;
		color: #555;
		margin-bottom: 4px;
	}
	.com_orders .order-feedback-inline {
		margin: 0 0 8px;
	}
	.com_orders .order-review-card {
		margin-top: 8px;
		font-size: 13px;
		color: #555;
	}
	.com_orders .order-review-card__head {
		font-weight: 600;
		color: #333;
	}
	#zapis-reschedule #reschedule-calendar .calendar__master-item .btns-m {
		display: grid;
		grid-template-columns: repeat(4, 1fr);
		gap: 6px;
		padding: 2px 2px 0;
	}
	#zapis-reschedule #reschedule-calendar .btns-m .btn-select {
		width: 100% !important;
		min-width: 0 !important;
		height: auto !important;
		margin: 0 !important;
		padding: 6px 2px !important;
		font-size: 11px !important;
		line-height: 1.4 !important;
		border-radius: 6px !important;
		box-sizing: border-box !important;
		text-align: center !important;
		background-color: #fff !important;
		border: 1px solid #e0e0e0 !important;
		box-shadow: 0 2px 6px rgba(0, 0, 0, 0.08) !important;
	}
	@media (min-width: 768px) {
		#zapis-reschedule #reschedule-calendar .calendar__master-item .btns-m {
			display: flex;
			flex-wrap: wrap;
			justify-content: flex-start;
			align-items: flex-start;
			gap: 6px 8px;
			grid-template-columns: none;
		}
		#zapis-reschedule #reschedule-calendar .btns-m .btn-select {
			width: auto !important;
			flex: 0 0 auto;
			padding-left: 3ch !important;
			padding-right: 3ch !important;
		}
	}
	#zapis-reschedule #reschedule-calendar .btns-m .btn-select.reserved {
		background-color: #f0f0f0 !important;
		color: #555 !important;
		border-color: #e8e8e8 !important;
	}
	#zapis-reschedule #reschedule-calendar .btns-m input:checked + .btn-select {
		background-color: #f7cc53 !important;
		border-color: #f7cc53 !important;
		box-shadow: 0 0 0 2px rgba(247, 204, 83, 0.3) !important;
	}
	#zapis-reschedule .modal-dialog {
		width: 96vw !important;
		max-width: 1180px !important;
		margin: 30px auto !important;
	}
	#zapis-reschedule .modal-content { overflow: hidden; }
	#zapis-reschedule .modal-body { overflow: hidden; padding: 20px 28px 28px; }
	#zapis-reschedule .calendar__master.preload { visibility: visible; }
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
		.com_orders .orders-table { display: block; border: 0; }
		.com_orders .orders-table thead { display: none; }
		.com_orders .orders-table tbody { display: block; }
		.com_orders .orders-table .orders-row { display: block; border: 1px solid #ddd; border-radius: 8px; margin-bottom: 12px; padding: 12px; box-shadow: 0 1px 3px rgba(0,0,0,0.08); }
		.com_orders .orders-table .orders-row td { display: block; border: 0; padding: 6px 0; }
		.com_orders .orders-table .orders-row td::before { content: attr(data-label); font-weight: bold; display: inline-block; min-width: 110px; color: #555; }
		.com_orders .orders-table .orders-row td.orders-actions { margin-top: 10px; padding-top: 10px; border-top: 1px solid #eee; }
		.com_orders .orders-table .orders-row td.orders-actions::before { display: none; }
		.com_orders .orders-table .orders-row td.orders-actions .btn { margin-right: 8px; margin-bottom: 4px; }
	}
	</style>
	<h1 class="page-title">Мои записи к мастерам</h1>
	<?php if (empty($items)) : ?>
		<p class="alert alert-info">У вас пока нет записей к мастерам. <a href="<?php echo Route::_('index.php?option=com_users&view=profile'); ?>">Перейти в профиль</a></p>
	<?php else : ?>
		<table class="table table-striped orders-table">
			<thead>
				<tr>
					<th>Дата и время</th>
					<th>Мастер</th>
					<th>Услуга</th>
					<th>Действия</th>
				</tr>
			</thead>
			<tbody>
				<?php
				$utc = new \DateTimeZone('UTC');
				foreach ($items as $item) :
					$timeUtc = $item->time ? new \DateTime($item->time, $utc) : null;
					$timeToUtc = $item->time_to ? new \DateTime($item->time_to, $utc) : null;
					$nowUtc = new \DateTime('now', $utc);
					$isPast = $timeUtc ? ($timeUtc < $nowUtc) : false;
					$isFixedCourse = trim((string) ($item->booking_kind ?? 'service')) === 'course' && (int) ($item->course_slot_id ?? 0) > 0;
					$isFixedSearch = trim((string) ($item->booking_kind ?? 'service')) === 'search' && (int) ($item->search_slot_id ?? 0) > 0;
					$isPromotion = trim((string) ($item->booking_kind ?? 'service')) === 'stock' || (int) ($item->stock_service_id ?? 0) > 0;
					$timeIso = $timeUtc ? $timeUtc->format('c') : '';
					$durationMin = 60;
					if ($timeUtc && $timeToUtc) {
						$diff = (int) floor(($timeToUtc->getTimestamp() - $timeUtc->getTimestamp()) / 60);
						if ($diff > 0) {
							$durationMin = max(15, min(480, $diff));
						}
					}
					$slotPayload = viglingOrdersBuildRescheduleSlots($db, (int) $item->master_id, $durationMin, (int) $item->id, 0, 45);
					$slotsJson = json_encode($slotPayload['days'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
				?>
				<tr data-order-id="<?php echo (int) $item->id; ?>" class="orders-row" data-time-utc="<?php echo $timeIso ? $this->escape($timeIso) : ''; ?>">
					<td data-label="Дата и время"><span class="lk-time-utc" data-time-utc="<?php echo $timeIso ? $this->escape($timeIso) : ''; ?>">—</span></td>
					<td data-label="Мастер"><?php
						$masterId = (int) ($item->master_id ?? 0);
						$masterName = (string) ($item->master_name ?? '—');
						if ($masterId > 0 && $masterName !== '—') :
							$masterProfileUrl = rtrim(Uri::root(true), '/') . '/' . $masterId;
							?><a href="<?php echo htmlspecialchars($masterProfileUrl); ?>"><?php echo htmlspecialchars($masterName); ?></a><?php
						else :
							echo htmlspecialchars($masterName);
						endif;
					?></td>
					<td data-label="Услуга">
						<?php echo htmlspecialchars((string) ($item->service_display_name ?? $item->service_name)); ?>
						<?php if ($isFixedCourse || $isFixedSearch) : ?>
							<div style="font-size:13px;color:#666;margin-top:4px;">Время <?php echo $isFixedSearch ? 'поиска' : 'курса'; ?> задаёт мастер для всей группы</div>
						<?php endif; ?>
						<?php if (trim((string) ($item->comment ?? '')) !== '') : ?>
							<div class="order-comment"><?php echo htmlspecialchars((string) $item->comment); ?></div>
						<?php endif; ?>
						<?php
						$role = 'client';
						$reviewsByDirection = is_array($item->_reviews ?? null) ? $item->_reviews : [];
						include __DIR__ . '/_feedback.php';
						?>
					</td>
					<td class="orders-actions" data-label="Действия">
						<?php if (!$isFixedCourse && !$isFixedSearch) : ?>
						<button type="button" class="btn btn-xs btn-warning reschedule-open"<?php echo $isPast ? ' disabled' : ''; ?> data-id="<?php echo (int) $item->id; ?>" data-duration="<?php echo (int) $durationMin; ?>" data-current-utc="<?php echo $timeIso ? $this->escape($timeIso) : ''; ?>">Перенести</button>
						<?php endif; ?>
						<?php if ($isPast) : ?>
						<form method="post" action="<?php echo Route::_('index.php?option=com_orders&task=orders.delete'); ?>" class="form-inline form-delete" style="display:inline;">
							<input type="hidden" name="<?php echo $token; ?>" value="1">
							<input type="hidden" name="id" value="<?php echo (int) $item->id; ?>">
							<input type="hidden" name="return" value="<?php echo $returnEncoded; ?>">
							<button type="submit" class="btn btn-xs btn-default" onclick="return confirm('Удалить запись из списка?');">Удалить</button>
						</form>
						<?php if (!$isFixedCourse && !$isFixedSearch && !$isPromotion) : ?>
						<button type="button" class="z-link review-zlink repeat-open" style="min-height: 18px;" data-id="<?php echo (int) $item->id; ?>" data-duration="<?php echo (int) $durationMin; ?>">Повторить<span></span></button>
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
				<form id="reschedule-modal-form" method="post" action="<?php echo $rescheduleAction; ?>" data-repeat-action="<?php echo $repeatAction; ?>">
					<input type="hidden" name="<?php echo $token; ?>" value="1">
					<input type="hidden" name="return" value="<?php echo $returnEncoded; ?>">
					<input type="hidden" name="id" id="reschedule-modal-id" value="0">
					<input type="hidden" name="duration_min" id="reschedule-modal-duration" value="60">
					<input type="hidden" name="time_utc" id="reschedule-modal-time-utc" value="">
					<div class="calc__body">
						<h2>Выберите дату и время</h2>
						<div class="calendar-hint">Прокрутите даты и нажмите подходящее время</div>
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
	var durationInp = document.getElementById('reschedule-modal-duration');
	var timeUtcInp = document.getElementById('reschedule-modal-time-utc');
	var errorEl = document.getElementById('reschedule-modal-error');
	var submitBtn = document.getElementById('reschedule-modal-submit');
	var submitLabel = submitBtn ? submitBtn.querySelector('.btn-label') : null;
	var rescheduleAction = form ? form.getAttribute('action') : '';
	var repeatAction = form ? (form.getAttribute('data-repeat-action') || '') : '';
	var modalMode = 'reschedule';
	var activeSlots = [];
	if (cal) bindRescheduleSlotPick(cal);

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
		if (!window.jQuery) return;
		var jqCal = jQuery(cal);
		if (jqCal.hasClass('slick-initialized')) {
			try { jqCal.slick('unslick'); } catch (e) {}
		}
	}

	function initSlider() {
		if (!cal) return;
		cal.classList.remove('preload');
		if (window.jQuery && jQuery(cal).hasClass('slick-initialized')) {
			try { jQuery(cal).slick('unslick'); } catch (e) {}
		}
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
		activeSlots = [];
		destroySlider();
		cal.innerHTML = '';
		if (!Array.isArray(days) || !days.length) {
			var emptyItem = document.createElement('div');
			emptyItem.className = 'calendar__master-item';
			var emptyNo = document.createElement('span');
			emptyNo.className = 'line-no';
			emptyNo.textContent = modalMode === 'repeat' ? 'Нет доступного времени' : 'Нет доступного времени для переноса';
			emptyItem.appendChild(emptyNo);
			cal.appendChild(emptyItem);
			if (submitBtn) {
				submitBtn.disabled = true;
			}
			cal.classList.remove('preload');
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
				activeSlots.push(utc);
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
		cal.classList.remove('preload');
	}

	function openSlotModal(orderId, duration, currentUtc, nextMode){
		hideError();
		if (!orderId) return;
		modalMode = nextMode === 'repeat' ? 'repeat' : 'reschedule';
		if (form) {
			form.setAttribute('action', modalMode === 'repeat' && repeatAction ? repeatAction : rescheduleAction);
		}
		if (submitLabel) {
			submitLabel.textContent = modalMode === 'repeat' ? 'Записаться' : 'Сохранить';
		}
		if (submitBtn) {
			submitBtn.classList.remove('is-loading');
			submitBtn.removeAttribute('disabled');
		}
		idInp.value = String(orderId);
		durationInp.value = String(isNaN(duration) ? 60 : duration);
		timeUtcInp.value = '';
		cal.classList.add('preload');
		renderCalendar(readSlots(orderId), currentUtc);
		jQuery(modal).modal('show');
	}

	document.querySelectorAll('.reschedule-open').forEach(function(btn){
		btn.addEventListener('click', function(){
			openSlotModal(
				parseInt(this.getAttribute('data-id') || '0', 10),
				parseInt(this.getAttribute('data-duration') || '60', 10),
				String(this.getAttribute('data-current-utc') || '').trim(),
				'reschedule'
			);
		});
	});

	document.querySelectorAll('.repeat-open').forEach(function(btn){
		btn.addEventListener('click', function(){
			openSlotModal(
				parseInt(this.getAttribute('data-id') || '0', 10),
				parseInt(this.getAttribute('data-duration') || '60', 10),
				'',
				'repeat'
			);
		});
	});

	jQuery(modal).on('shown.bs.modal', function () {
		initSlider();
	});

	jQuery(modal).on('hidden.bs.modal', function () {
		hideError();
		timeUtcInp.value = '';
		destroySlider();
		cal.innerHTML = '';
		cal.classList.add('preload');
		modalMode = 'reschedule';
		if (form && rescheduleAction) {
			form.setAttribute('action', rescheduleAction);
		}
		if (submitLabel) {
			submitLabel.textContent = 'Сохранить';
		}
		if (submitBtn) {
			submitBtn.classList.remove('is-loading');
			submitBtn.removeAttribute('disabled');
		}
	});

	form.addEventListener('submit', function(e){
		hideError();
		var selected = form.querySelector('input[name="reschedule_slot"]:checked');
		if (!selected || !selected.value) {
			e.preventDefault();
			showError(modalMode === 'repeat' ? 'Выберите доступное время' : 'Выберите доступное время для переноса');
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
