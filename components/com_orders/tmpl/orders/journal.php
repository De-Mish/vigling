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
?>
<div class="com_orders orders-journal">
	<style>
	.com_orders.orders-journal .journal-layout {
		display: grid;
		grid-template-columns: minmax(0, 1.7fr) minmax(0, 1fr);
		gap: 20px;
		align-items: start;
	}
	.com_orders.orders-journal .journal-card {
		min-width: 0;
		background: #fff;
		border: 1px solid #e2e2e2;
		border-radius: 14px;
		padding: 18px;
		box-shadow: 0 2px 10px rgba(0, 0, 0, 0.04);
	}
	.com_orders.orders-journal .journal-card h2 {
		margin-top: 0;
		margin-bottom: 6px;
		font-size: 22px;
	}
	.com_orders.orders-journal .journal-meta {
		margin: 0 0 14px;
		color: #707070;
		font-size: 13px;
	}
	.com_orders.orders-journal .journal-controls {
		display: flex;
		flex-wrap: wrap;
		gap: 12px;
		align-items: end;
		margin-bottom: 16px;
	}
	.com_orders.orders-journal .journal-field {
		flex: 0 1 220px;
	}
	.com_orders.orders-journal .journal-field label {
		display: block;
		font-weight: 600;
		margin-bottom: 6px;
	}
	.com_orders.orders-journal .journal-field input {
		width: 100%;
		border: 1px solid #cfcfcf;
		border-radius: 10px;
		padding: 11px 12px;
	}
	.com_orders.orders-journal .journal-field textarea {
		width: 100%;
		min-height: 84px;
		border: 1px solid #cfcfcf;
		border-radius: 10px;
		padding: 11px 12px;
		resize: vertical;
	}
	.com_orders.orders-journal .journal-selected {
		flex: 1 1 220px;
		min-height: 44px;
		padding: 11px 12px;
		border: 1px dashed #d3d3d3;
		border-radius: 10px;
		background: #fafafa;
		color: #444;
	}
	.com_orders.orders-journal .journal-selected strong {
		display: block;
		margin-bottom: 2px;
	}
	.com_orders.orders-journal .journal-submit-wrap {
		margin-left: auto;
	}
	.com_orders.orders-journal .journal-submit {
		min-width: 180px;
	}
	.com_orders.orders-journal .calc__body {
		padding-bottom: 6px;
	}
	.com_orders.orders-journal .calc__body h2 {
		padding-left: 0;
		margin-bottom: 28px;
	}
	.com_orders.orders-journal .calendar-hint {
		text-align: center;
		color: #7a7a7a;
		font-size: 13px;
		margin: -8px 0 18px;
	}
	.com_orders.orders-journal #journal-calendar {
		width: 100% !important;
		max-width: 800px;
		margin: 0 auto !important;
	}
	.com_orders.orders-journal #journal-calendar .slick-list {
		margin: 0 -8px;
		padding: 4px 0 10px;
	}
	.com_orders.orders-journal #journal-calendar .slick-slide,
	.com_orders.orders-journal #journal-calendar .slick-slide > div {
		height: auto !important;
	}
	.com_orders.orders-journal #journal-calendar .calendar__master-item {
		padding: 0 8px;
		box-sizing: border-box;
	}
	.com_orders.orders-journal #journal-calendar .btns-m {
		padding-top: 2px;
	}
	.com_orders.orders-journal #journal-calendar .btn-select {
		margin-bottom: 18px;
	}
	.com_orders.orders-journal #journal-calendar.error .btn-select {
		box-shadow: 0 0 0 2px rgba(244, 11, 11, 0.5);
	}
	.com_orders.orders-journal #journal-calendar label.btn-select {
		cursor: pointer;
	}
	.com_orders.orders-journal #journal-calendar label.btn-select.reserved {
		cursor: inherit;
		background-color: #ddd;
		pointer-events: none;
	}
	.com_orders.orders-journal #journal-calendar.preload {
		visibility: hidden;
	}
	.com_orders.orders-journal .line-no.journal-line-no {
		margin-top: 47px;
	}
	.com_orders.orders-journal .journal-empty-text {
		margin-top: 12px;
		text-align: center;
		color: #9a9a9a;
		font-size: 13px;
	}
	.com_orders.orders-journal .error-msg {
		display: none;
		margin-top: 12px;
		color: #a94442;
	}
	.com_orders.orders-journal .journal-list {
		display: grid;
		gap: 12px;
	}
	.com_orders.orders-journal .journal-entry {
		border: 1px solid #eee;
		border-radius: 12px;
		padding: 12px 14px;
		background: #fcfcfc;
	}
	.com_orders.orders-journal .journal-entry__top {
		display: flex;
		align-items: start;
		justify-content: space-between;
		gap: 12px;
	}
	.com_orders.orders-journal .journal-entry__title {
		font-weight: 700;
	}
	.com_orders.orders-journal .journal-entry__meta {
		margin-top: 4px;
		color: #666;
		font-size: 13px;
	}
	.com_orders.orders-journal .journal-entry__comment {
		margin-top: 8px;
		color: #333;
		font-size: 13px;
		line-height: 1.45;
		word-break: break-word;
	}
	.com_orders.orders-journal .journal-entry__actions {
		margin-top: 10px;
	}
	.com_orders.orders-journal .journal-entry__actions .btn {
		margin-right: 8px;
		margin-bottom: 4px;
	}
	@media (max-width: 992px) {
		.com_orders.orders-journal .journal-layout {
			grid-template-columns: 1fr;
		}
		.com_orders.orders-journal .journal-card--calendar {
			order: 1;
		}
		.com_orders.orders-journal .journal-card--list {
			order: 2;
		}
		.com_orders.orders-journal .journal-submit-wrap {
			margin-left: 0;
			width: 100%;
		}
		.com_orders.orders-journal .journal-submit {
			width: 100%;
		}
	}
	@media (max-width: 768px) {
		.com_orders.orders-journal .journal-card {
			padding: 14px;
		}
		.com_orders.orders-journal .journal-controls {
			display: grid;
			grid-template-columns: 1fr;
		}
		.com_orders.orders-journal .calc__body h2 {
			font-size: 19px;
			margin-bottom: 22px;
		}
		.com_orders.orders-journal #journal-calendar .calendar__master-item {
			max-height: calc(100vh - 320px);
			overflow: hidden;
		}
		.com_orders.orders-journal #journal-calendar .calendar__master-item .btns-m {
			max-height: calc(100vh - 420px);
			overflow-y: auto;
			-webkit-overflow-scrolling: touch;
			padding-right: 4px;
			margin-bottom: 0;
		}
		.com_orders.orders-journal .journal-field,
		.com_orders.orders-journal .journal-selected,
		.com_orders.orders-journal .journal-submit-wrap {
			flex: none;
			width: 100%;
		}
	}
	</style>

	<h1 class="page-title">Журнал</h1>

	<div class="journal-layout">
		<div class="journal-card journal-card--calendar">
			<h2>Блокировка времени</h2>
			<p class="journal-meta">Часовой пояс мастера: <strong><?php echo $this->escape($journalTimezone); ?></strong></p>
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
						<span>Нажмите на свободное время в календаре.</span>
					</div>
					<div class="journal-submit-wrap">
						<button type="submit" class="btn btn-primary journal-submit" id="journal-submit" disabled>Заблокировать время</button>
					</div>
				</div>
				<div class="calc__body">
					<h2>Выберите дату и время</h2>
					<div class="calendar-hint">Листайте <i>вправо/влево</i> или используйте стрелки для других дат</div>
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
											<input
												type="radio"
												id="<?php echo $this->escape($slotId); ?>"
												name="journal_slot"
												value="<?php echo $this->escape($slotUtc); ?>"
												data-slot-label="<?php echo $this->escape($slotFull); ?>"
											>
											<label for="<?php echo $this->escape($slotId); ?>" class="btn-select"><?php echo $this->escape($slotLabel); ?></label>
										<?php endforeach; ?>
									</p>
								<?php else : ?>
									<span class="line-no journal-line-no"></span>
									<div class="journal-empty-text">Нет свободных слотов</div>
								<?php endif; ?>
							</div>
						<?php endforeach; ?>
					<?php else : ?>
						<div class="calendar__master-item">
							<span class="line-no journal-line-no"></span>
							<div class="journal-empty-text">Свободных слотов пока нет</div>
						</div>
					<?php endif; ?>
				</div>
				</div>
				<div class="error-msg" id="journal-error"></div>
			</form>
		</div>

		<div class="journal-card journal-card--list">
			<h2>Текущие блоки</h2>
			<?php if (empty($items)) : ?>
				<p class="journal-meta">Пока нет заблокированного времени.</p>
			<?php else : ?>
				<div class="journal-list">
					<?php
					$utc = new \DateTimeZone('UTC');
					try {
						$journalTz = new \DateTimeZone($journalTimezone !== '' ? $journalTimezone : 'UTC');
					} catch (\Throwable $e) {
						$journalTz = new \DateTimeZone('UTC');
					}
					foreach ($items as $item) :
						$startUtc = !empty($item->time) ? new \DateTimeImmutable((string) $item->time, $utc) : null;
						$endUtc = !empty($item->time_to) ? new \DateTimeImmutable((string) $item->time_to, $utc) : null;
						$startLocal = $startUtc ? $startUtc->setTimezone($journalTz) : null;
						$endLocal = $endUtc ? $endUtc->setTimezone($journalTz) : null;
						$durationMin = 0;
						if ($startUtc && $endUtc) {
							$durationMin = max(0, (int) floor(($endUtc->getTimestamp() - $startUtc->getTimestamp()) / 60));
						}
						$label = trim((string) ($item->service_name ?? ''));
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
					?>
					<div class="journal-entry">
						<div class="journal-entry__top">
							<div>
								<div class="journal-entry__title"><?php echo $this->escape($label); ?></div>
								<div class="journal-entry__meta">
									<?php echo $startLocal ? $this->escape($startLocal->format('d.m.Y H:i')) : '—'; ?>
									<?php if ($endLocal) : ?>
										&ndash; <?php echo $this->escape($endLocal->format('H:i')); ?>
									<?php endif; ?>
									<?php if ($durationMin > 0) : ?>
										, <?php echo (int) $durationMin; ?> мин.
									<?php endif; ?>
								</div>
								<?php if ($comment !== '') : ?>
									<div class="journal-entry__comment"><?php echo $this->escape($comment); ?></div>
								<?php endif; ?>
							</div>
						</div>
						<div class="journal-entry__actions">
							<form method="post" action="<?php echo $deleteAction; ?>" class="form-inline" style="display:inline;">
								<input type="hidden" name="<?php echo $token; ?>" value="1">
								<input type="hidden" name="return" value="<?php echo $returnEncoded; ?>">
								<input type="hidden" name="id" value="<?php echo (int) $item->id; ?>">
								<button type="submit" class="btn btn-xs btn-default" onclick="return confirm('Удалить блок времени?');">Удалить</button>
							</form>
						</div>
					</div>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
		</div>
	</div>
</div>

<script>
(function(){
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
		if (cal) {
			cal.classList.toggle('error', !!msg);
		}
	}

	function clearSelection() {
		if (!cal) return;
		cal.querySelectorAll('input[name="journal_slot"]').forEach(function(input){
			input.checked = false;
		});
		if (timeInput) timeInput.value = '';
		if (selectedBox) {
			selectedBox.innerHTML = '<strong>Слот не выбран</strong><span>Нажмите на свободное время в календаре.</span>';
		}
		if (submitBtn) {
			submitBtn.disabled = true;
		}
	}

	function initSlider() {
		if (!cal) return;
		if (!window.jQuery) {
			cal.classList.remove('preload');
			return;
		}
		var jqCal = window.jQuery(cal);
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

	if (cal) {
		initSlider();
		cal.querySelectorAll('input[name="journal_slot"]').forEach(function(input){
			input.addEventListener('change', function(){
				var utc = input.value || '';
				var label = input.getAttribute('data-slot-label') || '';
				if (timeInput) timeInput.value = utc;
				if (selectedBox) {
					selectedBox.innerHTML = '<strong>Выбран слот</strong><span>' + label + '</span>';
				}
				if (submitBtn) {
					submitBtn.disabled = !utc;
				}
				setError('');
			});
		});
	}

	if (form) {
		form.addEventListener('submit', function(e){
			var utc = timeInput ? timeInput.value.trim() : '';
			if (!utc) {
				e.preventDefault();
				setError('Сначала выберите слот в календаре.');
				return;
			}
			var duration = durationInput ? durationInput.value.trim() : '';
			if (!duration) {
				e.preventDefault();
				setError('Укажите длительность блока.');
			}
		});
	}

	if (durationInput) {
		durationInput.addEventListener('focus', function(){
			setError('');
		});
	}

	if (window.jQuery) {
		window.jQuery(window).on('resize orientationchange', function() {
			var jqCal = window.jQuery(cal);
			if (jqCal.hasClass('slick-initialized')) {
				jqCal.slick('setPosition');
			}
		});
	}
})();
</script>
