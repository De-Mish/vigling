<?php
\defined('_JEXEC') or die;
/** @var array $days */
/** @var string $addAction */
/** @var string $token */
/** @var string $returnEncoded */
?>
<style>
	.journal-block-panel[hidden] { display: none !important; }
	.journal-block-hint {
		display: flex;
		align-items: center;
		gap: 10px;
		margin: 0 0 14px;
		color: #222;
		font-weight: 600;
		line-height: 1.35;
	}
	.journal-block-hint__mark {
		flex: 0 0 28px;
		width: 28px;
		height: 28px;
		border-radius: 50%;
		background: #f9ce54;
		color: #111;
		display: inline-flex;
		align-items: center;
		justify-content: center;
		font-weight: 700;
		font-size: 18px;
		line-height: 1;
	}
	#journal-calendar {
		display: flex;
		gap: 16px;
		overflow-x: auto;
		width: 100% !important;
		max-width: none !important;
		margin: 0 !important;
		visibility: visible !important;
		opacity: 1 !important;
		padding-bottom: 8px;
	}
	#journal-calendar .calendar__master-item {
		flex: 0 0 340px;
		width: 340px;
		opacity: 1 !important;
		visibility: visible !important;
	}
	#journal-calendar .btns-m {
		display: grid !important;
		grid-template-columns: repeat(4, minmax(0, 1fr)) !important;
		gap: 6px;
		margin: 0;
	}
	@media (max-width: 1020px), (display-mode: standalone), (display-mode: minimal-ui) {
		#journal-calendar .calendar__master-item {
			flex-basis: min(100%, 340px);
			width: min(100%, 340px);
		}
	}
	#journal-calendar .btn-select {
		width: 100%;
		margin: 0;
		padding: 6px 2px;
		border: 1px solid #e0e0e0;
		border-radius: 6px;
		background: #fff;
		color: #111;
		font-size: 12px;
		line-height: 1.4;
		cursor: pointer;
	}
	#journal-calendar .btn-select.is-picked {
		background: #e4e4e4;
		border-color: #d0d0d0;
		color: #555;
		box-shadow: none;
	}
</style>
<div class="journal-card journal-card--calendar">
	<h2>Забронировать время</h2>
	<form id="journal-form" method="post" action="<?php echo $addAction; ?>">
		<input type="hidden" name="<?php echo $token; ?>" value="1">
		<input type="hidden" name="return" value="<?php echo $returnEncoded; ?>">
		<input type="hidden" name="time_utc" id="journal-time-utc" value="">
		<input type="hidden" name="time_to_utc" id="journal-time-to-utc" value="">
		<div class="journal-controls">
			<div class="journal-field">
				<label for="journal-comment">Комментарий</label>
				<textarea id="journal-comment" name="comment" rows="3" placeholder="Причина блокировки времени"></textarea>
			</div>
			<div class="journal-selected" id="journal-selected">
				<strong>Время не выбрано</strong>
				<span>Нажмите «Забронировать время», затем выделите слоты.</span>
			</div>
			<div class="journal-submit-wrap">
				<button type="submit" class="btn btn-primary journal-submit" id="journal-submit">Забронировать время</button>
			</div>
		</div>
		<div class="journal-block-panel" id="journal-block-panel" hidden>
			<div class="journal-block-hint">
				<span class="journal-block-hint__mark" aria-hidden="true">!</span>
				<span>Выберите временные слоты, которые хотите заблокировать</span>
			</div>
			<div class="calc__body">
				<div class="calendar__master calendar__master--manual" id="journal-calendar">
				<?php if (!empty($days)) : ?>
					<?php foreach ($days as $day) : ?>
						<div class="calendar__master-item" data-date="<?php echo $this->escape((string) ($day['date'] ?? '')); ?>">
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
										$slotEnd = (string) ($slot['end_utc'] ?? '');
										$slotEndLabel = (string) ($slot['end_label'] ?? '');
										$slotFull = trim((string) ($day['date_view'] ?? '') . ' ' . $slotLabel);
										?>
										<button type="button" class="btn-select" data-utc="<?php echo $this->escape($slotUtc); ?>" data-end-utc="<?php echo $this->escape($slotEnd); ?>" data-end-label="<?php echo $this->escape($slotEndLabel); ?>" data-slot-label="<?php echo $this->escape($slotFull); ?>"><?php echo $this->escape($slotLabel); ?></button>
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
		</div>
		<div class="error-msg" id="journal-error"></div>
	</form>
</div>
<script>
(function(){
	var form = document.getElementById('journal-form');
	var panel = document.getElementById('journal-block-panel');
	var cal = document.getElementById('journal-calendar');
	var timeInput = document.getElementById('journal-time-utc');
	var timeToInput = document.getElementById('journal-time-to-utc');
	var selectedBox = document.getElementById('journal-selected');
	var anchor = null;
	if (cal) cal.classList.remove('preload');

	function slotStamp(btn, attr) {
		var raw = btn.getAttribute(attr) || '';
		var ms = Date.parse(raw);
		return isNaN(ms) ? 0 : ms;
	}
	function applyRange(day, fromBtn, toBtn) {
		var buttons = Array.prototype.slice.call(day.querySelectorAll('.btn-select'));
		var a = buttons.indexOf(fromBtn);
		var b = buttons.indexOf(toBtn);
		if (a < 0 || b < 0) return;
		var start = Math.min(a, b);
		var end = Math.max(a, b);
		var contiguous = true;
		for (var i = start; i < end; i++) {
			var leftEnd = slotStamp(buttons[i], 'data-end-utc');
			var rightStart = slotStamp(buttons[i + 1], 'data-utc');
			if (!leftEnd || !rightStart || Math.abs(rightStart - leftEnd) > 90000) {
				contiguous = false;
				break;
			}
		}
		if (!contiguous) {
			start = b;
			end = b;
			anchor = toBtn;
		}
		cal.querySelectorAll('.btn-select.is-picked').forEach(function(node){
			node.classList.remove('is-picked');
		});
		for (var n = start; n <= end; n++) buttons[n].classList.add('is-picked');
		var first = buttons[start];
		var last = buttons[end];
		if (timeInput) timeInput.value = first.getAttribute('data-utc') || '';
		if (timeToInput) timeToInput.value = last.getAttribute('data-end-utc') || '';
		if (selectedBox) {
			var fromLabel = first.getAttribute('data-slot-label') || first.textContent || '';
			var toLabel = last.getAttribute('data-end-label') || last.textContent || '';
			selectedBox.innerHTML = '<strong>Выбран интервал</strong><span>' + fromLabel + ' – ' + toLabel + '</span>';
		}
	}
	if (cal) {
		cal.addEventListener('click', function(e){
			var btn = e.target && e.target.closest ? e.target.closest('.btn-select') : null;
			if (!btn || !cal.contains(btn)) return;
			var day = btn.closest('.calendar__master-item');
			if (!day) return;
			if (!anchor || !anchor.isConnected || anchor.closest('.calendar__master-item') !== day) {
				anchor = btn;
			}
			applyRange(day, anchor, btn);
		});
	}
	if (form) {
		form.addEventListener('submit', function(e){
			if (panel && panel.hidden) {
				e.preventDefault();
				panel.hidden = false;
				if (selectedBox) {
					selectedBox.innerHTML = '<strong>Выберите слоты</strong><span>Выделите время, которое нужно заблокировать.</span>';
				}
				panel.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
				return;
			}
			if (!timeInput || !timeInput.value.trim() || !timeToInput || !timeToInput.value.trim()) {
				e.preventDefault();
				var error = document.getElementById('journal-error');
				if (error) {
					error.style.display = 'block';
					error.textContent = 'Выберите временные слоты, которые хотите заблокировать';
				}
			}
		});
	}
})();
</script>
