<?php
\defined('_JEXEC') or die;
/** @var array $days */
/** @var string $addAction */
/** @var string $token */
/** @var string $returnEncoded */
?>
<div class="journal-card journal-card--calendar">
	<h2>Забронировать время</h2>
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
				<strong>Выберите время</strong>
				<span>Нажмите на свободное время в календаре ниже.</span>
			</div>
			<div class="journal-submit-wrap">
				<button type="submit" class="btn btn-primary journal-submit" id="journal-submit" disabled>Забронировать время</button>
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
<script>
(function(){
	var form = document.getElementById('journal-form');
	var cal = document.getElementById('journal-calendar');
	var timeInput = document.getElementById('journal-time-utc');
	var selectedBox = document.getElementById('journal-selected');
	var submitBtn = document.getElementById('journal-submit');
	if (cal && window.jQuery && !window.jQuery(cal).hasClass('slick-initialized') && cal.querySelector('.calendar__master-item')) {
		setTimeout(function(){
			window.jQuery(cal).slick({
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
			window.jQuery(cal).removeClass('preload');
		}, 0);
	} else if (cal) {
		cal.classList.remove('preload');
	}
	if (cal) {
		cal.querySelectorAll('input[name="journal_slot"]').forEach(function(input){
			input.addEventListener('change', function(){
				if (timeInput) timeInput.value = input.value || '';
				if (selectedBox) selectedBox.innerHTML = '<strong>Выбран слот</strong><span>' + (input.getAttribute('data-slot-label') || '') + '</span>';
				if (submitBtn) submitBtn.disabled = !input.value;
			});
		});
	}
	if (form) {
		form.addEventListener('submit', function(e){
			if (!timeInput || !timeInput.value.trim()) {
				e.preventDefault();
			}
		});
	}
})();
</script>
