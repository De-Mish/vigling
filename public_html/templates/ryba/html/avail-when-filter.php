<?php
\defined('_JEXEC') or die;

$vgAvailWrapId = (string) ($vgAvailWrapId ?? 'avail-date-wrap');
$vgAvailHidden = !empty($vgAvailHidden);
$vgAvailDay = (string) ($vgAvailDay ?? '');
$vgAvailTime = (string) ($vgAvailTime ?? '');
?>
<span class="clearable<?php echo $vgAvailHidden ? ' hidden' : ''; ?>" id="<?php echo htmlspecialchars($vgAvailWrapId, ENT_QUOTES, 'UTF-8'); ?>">
	<input type="text" name="avail_day" class="filed__master vg-avail-day" value="<?php echo htmlspecialchars($vgAvailDay, ENT_QUOTES, 'UTF-8'); ?>" placeholder="Дата" autocomplete="off">
	<input type="text" name="avail_time" class="filed__master vg-avail-time" value="<?php echo htmlspecialchars($vgAvailTime, ENT_QUOTES, 'UTF-8'); ?>" placeholder="Время" autocomplete="off">
</span>
<script>
if (!window.vgAvailWhenFilterBound) {
	window.vgAvailWhenFilterBound = true;
	document.addEventListener('DOMContentLoaded', function () {
		if (typeof jQuery === 'undefined' || typeof jQuery.fn.datetimepicker !== 'function') {
			return;
		}
		var $ = jQuery;
		if ($.datetimepicker && $.datetimepicker.setLocale) {
			try { $.datetimepicker.setLocale('ru'); } catch (e) {}
		}
		var ruDates = {
			months: ['Январь', 'Февраль', 'Март', 'Апрель', 'Май', 'Июнь', 'Июль', 'Август', 'Сентябрь', 'Октябрь', 'Ноябрь', 'Декабрь'],
			monthsShort: ['Янв', 'Фев', 'Мар', 'Апр', 'Май', 'Июн', 'Июл', 'Авг', 'Сен', 'Окт', 'Ноя', 'Дек'],
			dayOfWeek: ['Воскресенье', 'Понедельник', 'Вторник', 'Среда', 'Четверг', 'Пятница', 'Суббота'],
			dayOfWeekShort: ['Вс', 'Пн', 'Вт', 'Ср', 'Чт', 'Пт', 'Сб']
		};
		$('.vg-avail-day').datetimepicker($.extend({
			format: 'Y-m-d',
			timepicker: false,
			minDate: 0,
			dayOfWeekStart: 1,
			validateOnBlur: false
		}, ruDates));
		$('.vg-avail-time').datetimepicker({
			format: 'H:i',
			datepicker: false,
			step: 15,
			validateOnBlur: false,
			onChangeDateTime: function (current, $input) {
				var val = String($input.val() || '');
				var parts = val.split(':');
				if (parts.length < 2) {
					return;
				}
				var minutes = parseInt(parts[0], 10) * 60 + parseInt(parts[1], 10);
				if (isNaN(minutes) || minutes % 15 === 0) {
					return;
				}
				var rounded = Math.round(minutes / 15) * 15;
				if (rounded >= 24 * 60) {
					rounded = 24 * 60 - 15;
				}
				var h = String(Math.floor(rounded / 60)).padStart(2, '0');
				var m = String(rounded % 60).padStart(2, '0');
				$input.val(h + ':' + m);
			}
		});
	});
}
</script>