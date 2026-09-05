<?php

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Uri\Uri;

/** @var \Viglin\Component\Aktsii\Site\View\List\HtmlView $this */
$items = $this->items;
$pagination = $this->pagination;
$categories = $this->categories;
$pageTitle = $this->pageTitle;
$fieldsByUser = $this->fieldsByUser;
$mapItems = $this->mapItems ?? [];
$mapFieldsByUser = $this->mapFieldsByUser ?? [];
$cities = $this->cities;
$areas = $this->areas;
$services = $this->services ?? [];
$tags = $this->tags ?? [];
$serviceHierarchy = $this->serviceHierarchy ?? [];
$currentService = (int) ($this->currentService ?? 0);
$currentTag = (int) ($this->currentTag ?? 0);
$listOrder = $this->listOrder;
$listDirn = $this->listDirn;
$baseUrl = (string) Uri::getInstance()->toString(['path']);
if ($baseUrl === '') {
	$baseUrl = '/';
}
$homeLabels = [1 => 'Салон', 2 => 'Вызов на дом', 3 => 'Мастер на дому'];
$input = Factory::getApplication()->getInput();
$currentCatId = (int) $input->get('cat_id', 0);
$currentCity = $input->getString('city', '');
$currentArea = $input->getString('area', '');
$currentHome = array_map('intval', (array) $input->get('home', [], 'array'));
$limit = (int) $input->getUint('limit', 20);
$currentAvailDate = $input->getString('avail_date', '');
if ($currentAvailDate !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}(?:[ T]\d{2}:\d{2})?$/', $currentAvailDate)) {
	$currentAvailDate = '';
}
$vgMapCity = $currentCity !== '' ? $currentCity : 'Москва';
$vgMapCityLocked = $currentCity !== '';
$vgMapTotal = $pagination ? (int) $pagination->total : 0;
$vgMapQuery = [
	'option' => 'com_aktsii',
	'task' => 'map.pins',
	'format' => 'raw',
	'cat_id' => $currentCatId,
	'service' => $currentService,
	'tag' => $currentTag,
	'city' => $currentCity,
	'area' => $currentArea,
	'avail_date' => $currentAvailDate,
];
foreach ($currentHome as $homeId) {
	$vgMapQuery['home'][] = (int) $homeId;
}
\Viglin\Component\Poisk\Site\Helper\ListMapHelper::applyViewerCity($vgMapCity, $vgMapCityLocked, $vgMapQuery);
$vgMapPinsUrl = rtrim(Uri::root(true), '/') . '/index.php?' . http_build_query($vgMapQuery);
$doc = Factory::getDocument();
$doc->addStyleSheet(\Joomla\CMS\Uri\Uri::root(true) . '/templates/ryba/css/chosen.min.css');
?>
<?php include JPATH_ROOT . '/templates/ryba/html/list-map.php'; ?>

<div class="category jsn_stockList">
	<style>
		main > .container { padding: 0; max-width: 1170px; }
		.category__masters-sidebar .clearable { width: 100%; }
		.category__masters-sidebar select { width: 100%; }
		.category__masters-sidebar .chosen-container { width: 100% !important; }
		.category__masters-sidebar input.filed__master.vg-datetime-picker { width: 100%; box-sizing: border-box; }
	</style>
	<div class="category__head">
		<h2><?php echo htmlspecialchars($pageTitle); ?></h2>
		<?php if ($pagination && $pagination->total > 0) : ?>
			<span class="cat_head-res">Результатов поиска - <span><?php echo $pagination->total; ?></span></span>
		<?php endif; ?>
		<form action="<?php echo $baseUrl; ?>" class="form-horizontal" method="get">
			<ul class="sort">
				<li>Сортировка:</li>
				<li><label class="radioBox">Рекомендуемое <input type="radio" name="filter_order" value="id"<?php echo $listOrder === 'id' ? ' checked' : ''; ?> onchange="this.form.submit()"><span class="checkmark"></span></label></li>
				<li><label class="radioBox">Рейтинг <input type="radio" name="filter_order" value="rate"<?php echo $listOrder === 'rate' ? ' checked' : ''; ?> onchange="this.form.submit()"><span class="checkmark"></span></label></li>
				<li><label class="radioBox">Цена <input type="radio" name="filter_order" value="price"<?php echo $listOrder === 'price' ? ' checked' : ''; ?> onchange="this.form.submit()"><span class="checkmark"></span></label></li>
			</ul>
			<input type="hidden" name="filter_order_Dir" value="<?php echo htmlspecialchars($listDirn); ?>">
			<input type="hidden" name="limit" value="<?php echo $limit; ?>">
			<input type="hidden" name="cat_id" value="<?php echo $currentCatId; ?>">
			<input type="hidden" name="service" value="<?php echo $currentService; ?>">
			<input type="hidden" name="tag" value="<?php echo $currentTag; ?>">
			<input type="hidden" name="city" value="<?php echo htmlspecialchars($currentCity); ?>">
			<input type="hidden" name="area" value="<?php echo htmlspecialchars($currentArea); ?>">
			<?php foreach ($currentHome as $h) : ?>
				<input type="hidden" name="home[]" value="<?php echo (int) $h; ?>">
			<?php endforeach; ?>
			<input type="hidden" name="avail_date" value="<?php echo htmlspecialchars($currentAvailDate); ?>">
		</form>
	</div>
	<div class="category__body">
		<div class="clearFloat">&nbsp;</div>
		<div class="category__masters">
			<?php if (empty($items)) : ?>
				<div class="alert alert-warning">Акции не найдены.</div>
			<?php else : ?>
				<?php foreach ($items as $item) :
					$fields = $fieldsByUser[$item->id] ?? [];
					include __DIR__ . '/default_item.php';
				endforeach; ?>
				<?php if ($pagination && $pagination->pagesTotal > 1) : ?>
					<div class="pagination__wrap" style="clear:both"><?php echo $pagination->getPagesLinks(); ?></div>
				<?php endif; ?>
			<?php endif; ?>
		</div>
		<div data-da=".pagination__wrap,922,1" class="category__masters-sidebar">
			<h2>Фильтр акций</h2>
			<form action="<?php echo $baseUrl; ?>" class="form-horizontal filter" method="get">
				<div class="masters-sidebar__body">
					<span class="clearable">
						<select id="city" name="city" data-placeholder="Город" class="filed__master">
							<option value=""></option>
							<?php foreach ($cities as $c) : ?>
								<option value="<?php echo htmlspecialchars($c); ?>"<?php echo $currentCity === $c ? ' selected' : ''; ?>><?php echo htmlspecialchars($c); ?></option>
							<?php endforeach; ?>
						</select>
					</span>
					<span class="clearable">
						<select id="area" name="area" data-placeholder="Район" class="filed__master">
							<option value=""></option>
							<?php foreach ($areas as $a) : ?>
								<option value="<?php echo htmlspecialchars($a); ?>"<?php echo $currentArea === $a ? ' selected' : ''; ?>><?php echo htmlspecialchars($a); ?></option>
							<?php endforeach; ?>
						</select>
					</span>
					<span class="clearable">
						<select id="vyberite_spetsialnos" name="cat_id" data-placeholder="Мастер" class="filed__master">
							<option value="0"></option>
							<?php foreach ($categories as $id => $cat) : ?>
								<option value="<?php echo (int) $id; ?>"<?php echo (int) $id === $currentCatId ? ' selected' : ''; ?>><?php echo htmlspecialchars($cat['filter_title'] ?? $cat['title'] ?? ''); ?></option>
							<?php endforeach; ?>
						</select>
					</span>
					<span class="clearable<?php echo $currentCatId > 0 ? '' : ' hidden'; ?>" id="aktsii-service-wrap">
						<select id="service" name="service" data-placeholder="Услуга" class="filed__master">
							<option value="0"></option>
							<?php foreach ($services as $service) : ?>
								<option value="<?php echo (int) ($service['id'] ?? 0); ?>"<?php echo (int) ($service['id'] ?? 0) === $currentService ? ' selected' : ''; ?>><?php echo htmlspecialchars((string) ($service['title'] ?? '')); ?></option>
							<?php endforeach; ?>
						</select>
					</span>
					<span class="clearable<?php echo ($currentCatId > 0 && $currentService > 0 && $tags !== []) ? '' : ' hidden'; ?>" id="aktsii-tag-wrap">
						<select id="tag" name="tag" data-placeholder="Метод" class="filed__master">
							<option value="0"></option>
							<?php foreach ($tags as $tag) : ?>
								<option value="<?php echo (int) ($tag['id'] ?? 0); ?>"<?php echo (int) ($tag['id'] ?? 0) === $currentTag ? ' selected' : ''; ?>><?php echo htmlspecialchars((string) ($tag['title'] ?? '')); ?></option>
							<?php endforeach; ?>
						</select>
					</span>
					<span class="clearable">
						<select id="home" name="home[]" data-placeholder="Вид услуги" multiple="multiple" class="filed__master">
							<option value="1"<?php echo in_array(1, $currentHome, true) ? ' selected' : ''; ?>>Салон</option>
							<option value="2"<?php echo in_array(2, $currentHome, true) ? ' selected' : ''; ?>>Вызов на дом</option>
							<option value="3"<?php echo in_array(3, $currentHome, true) ? ' selected' : ''; ?>>Мастер на дому</option>
						</select>
					</span>
					<span class="clearable<?php echo ($currentCity !== '' || $currentCatId > 0) ? '' : ' hidden'; ?>" id="aktsii-avail-date-wrap">
						<input type="text" name="avail_date" class="filed__master vg-datetime-picker" value="<?php echo htmlspecialchars($currentAvailDate); ?>" placeholder="Дата и время записи" autocomplete="off">
					</span>
				</div>
				<input type="hidden" name="filter_order" value="<?php echo htmlspecialchars($listOrder); ?>">
				<input type="hidden" name="filter_order_Dir" value="<?php echo htmlspecialchars($listDirn); ?>">
				<input type="hidden" name="limit" value="<?php echo $limit; ?>">
				<input class="submit__search" type="submit" value="Поиск">
			</form>
		</div>
	</div>
</div>
<script>
function normalizeTime(time) {
	if (!time) return '09:00';
	time = String(time).trim();
	time = time.replace('.', ':');
	var parts = time.split(':');
	if (parts.length < 2) return '09:00';
	var hours = String(parseInt(parts[0], 10)).padStart(2, '0');
	var minutes = String(parseInt(parts[1], 10)).padStart(2, '0');
	return hours + ':' + minutes;
}

function timeToMinutes(time) {
	if (!time) return 0;
	var normalized = normalizeTime(time);
	var parts = normalized.split(':');
	return parseInt(parts[0] || 0, 10) * 60 + parseInt(parts[1] || 0, 10);
}

function isTimeMultipleOf15(time) {
	if (!time) return true;
	var normalized = normalizeTime(time);
	var parts = normalized.split(':');
	if (parts.length < 2) return true;
	var minutes = parseInt(parts[0], 10) * 60 + parseInt(parts[1], 10);
	return minutes % 15 === 0;
}

function validateTimeStep(timeStr) {
	if (!timeStr) return true;
	var parts = timeStr.split(':');
	if (parts.length < 2) return true;
	var minutes = parseInt(parts[0], 10) * 60 + parseInt(parts[1], 10);
	return minutes % 15 === 0;
}

function autoCorrectTimeStep(input) {
	if (!input) return;
	var val = input.value;
	if (!val) return;
	var timeMatch = val.match(/\d{2}:\d{2}$/);
	if (!timeMatch) return;
	var parts = timeMatch[0].split(':');
	var minutes = parseInt(parts[0], 10) * 60 + parseInt(parts[1], 10);
	if (minutes % 15 !== 0) {
		var rounded = Math.round(minutes / 15) * 15;
		var h = String(Math.floor(rounded / 60)).padStart(2, '0');
		var m = String(rounded % 60).padStart(2, '0');
		var newVal = val.replace(/\d{2}:\d{2}$/, h + ':' + m);
		input.value = newVal;
	}
}

function validateAndCorrectTime(input) {
	if (!input) return true;
	autoCorrectTimeStep(input);
	var val = input.value;
	if (!val) return true;
	var timeMatch = val.match(/\d{2}:\d{2}$/);
	if (!timeMatch) return true;
	return validateTimeStep(timeMatch[0]);
}

document.addEventListener('DOMContentLoaded', function () {
	var filterForm = document.querySelector('.filter');
	if (filterForm) {
		filterForm.addEventListener('submit', function(e) {
			var availDateInput = this.querySelector('input[name="avail_date"]');
			if (availDateInput && availDateInput.value) {
				if (!validateAndCorrectTime(availDateInput)) {
					e.preventDefault();
					alert('Время должно быть кратно 15 минутам (00, 15, 30, 45)');
					return false;
				}
			}
		});
	}

	var availDateInput = document.querySelector('input[name="avail_date"]');
	if (availDateInput) {
		availDateInput.addEventListener('blur', function() {
			validateAndCorrectTime(this);
		});
		availDateInput.addEventListener('change', function() {
			validateAndCorrectTime(this);
		});
		availDateInput.addEventListener('input', function() {
			var val = this.value;
			if (val) {
				var timeMatch = val.match(/\d{2}:\d{2}$/);
				if (timeMatch) {
					var parts = timeMatch[0].split(':');
					var minutes = parseInt(parts[0], 10) * 60 + parseInt(parts[1], 10);
					if (minutes % 15 !== 0 && val.length >= 5) {
						this.style.color = '#d93c3c';
					} else {
						this.style.color = '';
					}
				}
			}
		});
	}
});
</script>
<?php
$doc->addScript(\Joomla\CMS\Uri\Uri::root(true) . '/templates/ryba/js/chosen.jquery.min.js', ['defer' => true]);
$doc->addScriptDeclaration("
document.addEventListener('DOMContentLoaded', function() {
	var serviceHierarchy = " . json_encode($serviceHierarchy, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . ";
	if (typeof jQuery === 'undefined') {
		return;
	}
	var \$ = jQuery;
	var \$sidebar = $('.category__masters-sidebar');
	var \$cat = $('#vyberite_spetsialnos');
	var \$service = $('#service');
	var \$tag = $('#tag');
	var \$serviceWrap = $('#aktsii-service-wrap');
	var \$tagWrap = $('#aktsii-tag-wrap');

	function rebuildSelect(\$select, items, selectedValue) {
		if (!\$select.length) {
			return;
		}
		\$select.empty();
		\$select.append($('<option>', { value: 0, text: '' }));
		(items || []).forEach(function(item) {
			\$select.append($('<option>', {
				value: parseInt(item.id, 10) || 0,
				text: item.title || ''
			}));
		});
		if (selectedValue) {
			\$select.val(String(selectedValue));
		} else {
			\$select.val('0');
		}
		\$select.trigger('chosen:updated');
	}

	function syncFilterLevels() {
		var catId = parseInt(\$cat.val(), 10) || 0;
		var serviceItems = serviceHierarchy[String(catId)] || [];
		if (!catId || !serviceItems.length) {
			rebuildSelect(\$service, [], 0);
			rebuildSelect(\$tag, [], 0);
			\$serviceWrap.addClass('hidden');
			\$tagWrap.addClass('hidden');
			return;
		}

		\$serviceWrap.removeClass('hidden');
		var currentServiceId = parseInt(\$service.val(), 10) || 0;
		var serviceExists = serviceItems.some(function(item) { return (parseInt(item.id, 10) || 0) === currentServiceId; });
		if (!serviceExists) {
			currentServiceId = 0;
		}
		rebuildSelect(\$service, serviceItems, currentServiceId);

		var selectedService = serviceItems.find(function(item) { return (parseInt(item.id, 10) || 0) === (parseInt(\$service.val(), 10) || 0); }) || null;
		var tagItems = selectedService && Array.isArray(selectedService.tags) ? selectedService.tags : [];
		if (!selectedService || !tagItems.length) {
			rebuildSelect(\$tag, [], 0);
			\$tagWrap.addClass('hidden');
			return;
		}

		var currentTagId = parseInt(\$tag.val(), 10) || 0;
		var tagExists = tagItems.some(function(item) { return (parseInt(item.id, 10) || 0) === currentTagId; });
		if (!tagExists) {
			currentTagId = 0;
		}
		\$tagWrap.removeClass('hidden');
		rebuildSelect(\$tag, tagItems, currentTagId);
	}

	if ($.fn.chosen) {
		\$sidebar.find('select').chosen({
			disable_search_threshold: 100,
			allow_single_deselect: true,
			no_results_text: 'Не найдено:',
			width: '100%'
		});
	}

	var \$availDateWrap = \$('#aktsii-avail-date-wrap');

	function syncAktsiiAvailDate() {
		var cityVal = String(\$('#city').val() || '').trim();
		var catId = parseInt(\$cat.val(), 10) || 0;
		if (cityVal !== '' || catId > 0) {
			\$availDateWrap.removeClass('hidden');
		} else {
			\$availDateWrap.addClass('hidden');
			\$availDateWrap.find('input[type=\"date\"]').val('');
		}
	}

	\$cat.on('change', function() {
		\$service.val('0');
		\$tag.val('0');
		syncFilterLevels();
		syncAktsiiAvailDate();
	});
	\$service.on('change', function() {
		\$tag.val('0');
		syncFilterLevels();
	});
	\$('#city').on('change', syncAktsiiAvailDate);

	syncFilterLevels();
	syncAktsiiAvailDate();

	if (typeof \$.fn.datetimepicker === 'function') {
		$.datetimepicker.setLocale('ru');
		\$('input.vg-datetime-picker').datetimepicker({
			format: 'Y-m-d H:i',
			step: 15,
			minDate: 0,
			dayOfWeekStart: 1,
			validateOnBlur: false,
			closeOnDateSelect: false,
			onChangeDateTime: function(dp, \$input) {
				var val = \$input.val();
				if (val) {
					var timeMatch = val.match(/\d{2}:\d{2}$/);
					if (timeMatch) {
						var parts = timeMatch[0].split(':');
						var minutes = parseInt(parts[0], 10) * 60 + parseInt(parts[1], 10);
						if (minutes % 15 !== 0) {
							var rounded = Math.round(minutes / 15) * 15;
							var h = String(Math.floor(rounded / 60)).padStart(2, '0');
							var m = String(rounded % 60).padStart(2, '0');
							var newVal = val.replace(/\d{2}:\d{2}$/, h + ':' + m);
							\$input.val(newVal);
							\$input.trigger('change');
						}
					}
				}
			}
		});
	}
});
");
?>