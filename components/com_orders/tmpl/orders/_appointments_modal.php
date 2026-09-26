<?php
\defined('_JEXEC') or die;

use Joomla\CMS\Router\Route;

/** @var string $token */
/** @var string $returnEncoded */
/** @var string $rescheduleAction */
/** @var string $repeatAction */

$rescheduleSlotsAction = Route::_('index.php?option=com_orders&task=orders.rescheduleSlots');
?>
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
					<input type="hidden" name="course_slot_id" id="reschedule-modal-course-slot-id" value="0">
					<input type="hidden" name="search_slot_id" id="reschedule-modal-search-slot-id" value="0">
					<input type="hidden" name="duration_min" id="reschedule-modal-duration" value="60">
					<input type="hidden" name="time_utc" id="reschedule-modal-time-utc" value="">
					<div class="calc__body">
						<h2>Выберите дату и время</h2>
						<div class="calendar-hint">Прокрутите даты и нажмите подходящее время</div>
						<div class="calendar__master calendar__master--manual" id="reschedule-calendar"></div>
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
	if (!modal || !form || !cal) return;
	var idInp = document.getElementById('reschedule-modal-id');
	var courseSlotInp = document.getElementById('reschedule-modal-course-slot-id');
	var searchSlotInp = document.getElementById('reschedule-modal-search-slot-id');
	var durationInp = document.getElementById('reschedule-modal-duration');
	var timeUtcInp = document.getElementById('reschedule-modal-time-utc');
	var errorEl = document.getElementById('reschedule-modal-error');
	var submitBtn = document.getElementById('reschedule-modal-submit');
	var submitLabel = submitBtn ? submitBtn.querySelector('.btn-label') : null;
	var defaultAction = form.getAttribute('action') || '';
	var repeatAction = form.getAttribute('data-repeat-action') || '';
	var modalMode = 'reschedule';
	var slotsUrl = <?php echo json_encode($rescheduleSlotsAction); ?>;
	var slotsToken = <?php echo json_encode($token); ?>;
	var slotsRequestId = 0;
	bindRescheduleSlotPick(cal);

	function bindRescheduleSlotPick(root) {
		if (!root || root.getAttribute('data-slot-pick') === '1') return;
		root.setAttribute('data-slot-pick', '1');
		function pick(e) {
			var label = e.target && e.target.closest ? e.target.closest('.btn-select') : null;
			if (!label || !root.contains(label)) return;
			e.preventDefault();
			e.stopPropagation();
			var inputId = label.getAttribute('data-slot-for') || label.getAttribute('for') || '';
			var input = inputId ? document.getElementById(inputId) : null;
			if (!input) input = label.querySelector('input[type="radio"]');
			if (!input || input.disabled) return;
			input.checked = true;
			root.querySelectorAll('.btn-select.is-picked').forEach(function(node) {
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
		cal.classList.remove('preload');
		if (window.jQuery && jQuery(cal).hasClass('slick-initialized')) {
			try { jQuery(cal).slick('unslick'); } catch (e) {}
		}
	}
	function readJson(id){
		var node = document.getElementById(id);
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
			emptyNo.textContent = modalMode === 'repeat' ? 'Нет доступного времени' : 'Нет доступного времени для переноса';
			emptyItem.appendChild(emptyNo);
			cal.appendChild(emptyItem);
			if (submitBtn) submitBtn.disabled = true;
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
				var inputId = 'reschedule-slot-' + dayIdx + '-' + slotIdx;
				var inp = document.createElement('input');
				inp.type = 'radio';
				inp.name = 'reschedule_slot';
				inp.id = inputId;
				inp.value = utc;
				var lbl = document.createElement('button');
				lbl.type = 'button';
				lbl.className = 'btn-select';
				lbl.setAttribute('data-slot-for', inputId);
				lbl.textContent = String(slot.label || '');
				wrap.appendChild(inp);
				wrap.appendChild(lbl);
				if (currentUtc && utc === currentUtc) inp.checked = true;
			});
			item.appendChild(wrap);
			cal.appendChild(item);
		});
		if (!cal.querySelector('input[name="reschedule_slot"]') && cal.querySelector('.btns-m input')) {
			cal.querySelector('.btns-m input').checked = true;
		}
		if (submitBtn) submitBtn.disabled = !cal.querySelector('input[name="reschedule_slot"]');
		cal.classList.remove('preload');
	}
	function loadRescheduleDays(orderId, courseSlotId, searchSlotId, duration, currentUtc) {
		var requestId = ++slotsRequestId;
		hideError();
		cal.classList.add('preload');
		cal.innerHTML = '';
		if (submitBtn) submitBtn.disabled = true;
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
				initSlider();
				if ((!data || !data.success) && errorEl) {
					showError((data && data.message) ? data.message : 'Не удалось загрузить свободное время');
				}
			})
			.catch(function(){
				if (requestId !== slotsRequestId) return;
				renderCalendar([], currentUtc);
				showError('Не удалось загрузить свободное время');
			});
	}
	function openSlotModal(btn, nextMode){
		hideError();
		var orderId = parseInt(btn.getAttribute('data-id') || '0', 10);
		var courseSlotId = parseInt(btn.getAttribute('data-course-slot-id') || '0', 10);
		var searchSlotId = parseInt(btn.getAttribute('data-search-slot-id') || '0', 10);
		var duration = parseInt(btn.getAttribute('data-duration') || '60', 10);
		var currentUtc = String(btn.getAttribute('data-current-utc') || '').trim();
		if (!orderId && !courseSlotId && !searchSlotId) return;
		modalMode = nextMode === 'repeat' ? 'repeat' : 'reschedule';
		form.setAttribute(
			'action',
			modalMode === 'repeat' && repeatAction
				? repeatAction
				: (btn.getAttribute('data-reschedule-action') || defaultAction)
		);
		if (submitLabel) submitLabel.textContent = modalMode === 'repeat' ? 'Записаться' : 'Сохранить';
		if (submitBtn) {
			submitBtn.classList.remove('is-loading');
			submitBtn.removeAttribute('disabled');
		}
		idInp.value = String(orderId > 0 ? orderId : 0);
		courseSlotInp.value = String(courseSlotId > 0 ? courseSlotId : 0);
		searchSlotInp.value = String(searchSlotId > 0 ? searchSlotId : 0);
		durationInp.value = String(isNaN(duration) ? 60 : duration);
		timeUtcInp.value = '';
		cal.classList.add('preload');
		cal.innerHTML = '';
		var highlightUtc = modalMode === 'repeat' ? '' : currentUtc;
		var embeddedDays = searchSlotId > 0
			? readJson('reschedule-search-slot-' + searchSlotId)
			: (courseSlotId > 0 ? readJson('reschedule-course-slot-' + courseSlotId) : readJson('reschedule-slots-' + orderId));
		jQuery(modal).modal('show');
		if (btn.getAttribute('data-slots-embedded') === '1' && embeddedDays.length) {
			renderCalendar(embeddedDays, highlightUtc);
			initSlider();
			return;
		}
		loadRescheduleDays(orderId, courseSlotId, searchSlotId, isNaN(duration) ? 60 : duration, highlightUtc);
	}
	document.querySelectorAll('.reschedule-open').forEach(function(btn){
		btn.addEventListener('click', function(){ openSlotModal(this, 'reschedule'); });
	});
	document.querySelectorAll('.repeat-open').forEach(function(btn){
		btn.addEventListener('click', function(){ openSlotModal(this, 'repeat'); });
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
	jQuery(modal).on('shown.bs.modal', initSlider);
	jQuery(modal).on('hidden.bs.modal', function () {
		slotsRequestId++;
		hideError();
		timeUtcInp.value = '';
		idInp.value = '0';
		courseSlotInp.value = '0';
		searchSlotInp.value = '0';
		form.setAttribute('action', defaultAction);
		modalMode = 'reschedule';
		if (submitLabel) submitLabel.textContent = 'Сохранить';
		if (submitBtn) {
			submitBtn.classList.remove('is-loading');
			submitBtn.removeAttribute('disabled');
		}
		destroySlider();
		cal.innerHTML = '';
		cal.classList.add('preload');
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
