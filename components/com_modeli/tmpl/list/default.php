<?php

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Uri\Uri;

$items = $this->items;
$pagination = $this->pagination;
$categories = $this->categories;
$pageTitle = $this->pageTitle;
$fieldsByUser = $this->fieldsByUser;
$mapItems = $this->mapItems ?? [];
$mapFieldsByUser = $this->mapFieldsByUser ?? [];
$cities = $this->cities;
$areas = $this->areas;
$homeOptions = $this->homeOptions ?? [];
$listOrder = $this->listOrder;
$listDirn = $this->listDirn;
$modelError = (string) ($this->modelError ?? '');
$baseUrl = (string) Uri::getInstance()->toString(['path']);
if ($baseUrl === '') {
	$baseUrl = '/';
}
$input = Factory::getApplication()->getInput();
$currentCatId = (int) $input->get('cat_id', 0);
$currentCity = $input->getString('city', '');
$currentArea = $input->getString('area', '');
$currentHome = array_map('intval', (array) $input->get('home', [], 'array'));
$currentBookingMode = trim((string) $input->getString('booking_mode', ''));
$limit = (int) $input->getUint('limit', 20);
$currentAvailDate = $input->getString('avail_date', '');
if ($currentAvailDate !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}(?:[ T]\d{2}:\d{2})?$/', $currentAvailDate)) {
	$currentAvailDate = '';
}
$mapMasters = [];

$normalizeAddressPart = static function (string $value): string {
	$value = trim((string) preg_replace('/\s+/u', ' ', $value));

	return trim($value, " ,");
};

if (!empty($mapItems)) {
	foreach ($mapItems as $mapItem) {
		$mapFields = $mapFieldsByUser[(int) ($mapItem->master_id ?? 0)] ?? [];
		$sity = trim((string) ($mapFields['sity'] ?? ''));
		$area = trim((string) ($mapFields['area'] ?? ''));
		$street = trim((string) ($mapFields['street'] ?? ''));
		$house = trim((string) ($mapFields['house_number'] ?? ''));
		$displayAddressParts = array_values(array_filter(array_map($normalizeAddressPart, [$sity, $area, $street, $house])));
		$addr = implode(', ', $displayAddressParts);
		if ($addr === '') {
			$addr = $currentCity !== '' ? $currentCity : 'Москва';
		}

		$geocodeParts = array_values(array_unique(array_filter([
			$normalizeAddressPart($sity),
			$normalizeAddressPart($area),
			$normalizeAddressPart($street),
			$normalizeAddressPart($house),
		])));
		$geocodeQuery = $geocodeParts !== [] ? implode(', ', $geocodeParts) : '';
		$profileLink = '/index.php?option=com_users&view=profile&user_id=' . (int) ($mapItem->master_id ?? 0);
		$searchTitle = htmlspecialchars((string) ($mapItem->title ?? $mapItem->description ?? 'Поиск моделей'), ENT_QUOTES, 'UTF-8');
		$masterName = htmlspecialchars((string) ($mapItem->master_name ?? ''), ENT_QUOTES, 'UTF-8');
		$addrEsc = htmlspecialchars($addr, ENT_QUOTES, 'UTF-8');
		$balloon = '<div class="map-balloon">'
			. '<a href="' . $profileLink . '">' . $searchTitle . '</a><br>'
			. '<span>Мастер: ' . $masterName . '</span><br>'
			. '<span>' . $addrEsc . '</span>'
			. '</div>';
		$mapMasters[] = [
			'search_id' => (int) ($mapItem->search_id ?? 0),
			'user_id' => (int) ($mapItem->master_id ?? 0),
			'name' => (string) ($mapItem->title ?? $mapItem->description ?? 'Поиск моделей'),
			'address' => $addr,
			'geocode_query' => $geocodeQuery,
			'balloon' => $balloon,
		];
	}
}

$doc = Factory::getDocument();
$doc->addStyleSheet(Uri::root(true) . '/templates/ryba/css/chosen.min.css');
$doc->addScript(Uri::root(true) . '/templates/ryba/js/chosen.jquery.min.js', ['defer' => true]);
$doc->addStyleDeclaration('
	@media (max-width: 820px) {
		.search-catalog .category__item {
			padding: 12px 0 !important;
			margin-left: -10px !important;
			margin-right: -10px !important;
			border-bottom: 1px solid #f0f0f0 !important;
			border-radius: 0 !important;
			box-shadow: none !important;
			align-items: flex-start !important;
			margin-bottom: 2px !important;
			display: flex !important;
		}
		.search-catalog .category__item-img {
			background-image: none !important;
			width: 100px !important;
			height: 100px !important;
			min-width: 100px;
			border-radius: 10px !important;
			overflow: hidden;
			flex-shrink: 0;
			float: left;
			margin-right: 8px !important;
			margin-left: 0 !important;
			margin-top: 4px !important;
		}
		.search-catalog .category__item-img img {
			display: block !important;
			width: 100% !important;
			height: 100% !important;
			object-fit: cover !important;
			border-radius: 10px !important;
		}
		.search-catalog .category__item-content {
			width: calc(100% - 108px) !important;
			padding-left: 0 !important;
			flex-direction: column !important;
			align-items: flex-start !important;
			display: flex !important;
		}
		.search-catalog .category__item-content-left {
			width: 100% !important;
			padding-top: 0 !important;
		}
		.search-catalog .category__content-info {
			padding-left: 0 !important;
		}
		.search-catalog .category__item-content-right {
			display: block !important;
			width: 100% !important;
			height: auto !important;
			margin-top: 6px !important;
			margin-right: 0 !important;
		}
		.search-catalog .category__item-content-right .btn__time-zapis {
			margin-top: 0 !important;
			margin-right: 0 !important;
			margin-left: 0 !important;
			display: inline-block !important;
			padding: 4px 12px !important;
			height: 30px !important;
			line-height: 22px !important;
			font-size: 12px !important;
			border-radius: 15px !important;
			background-color: #f9ce54 !important;
			color: #000 !important;
			text-align: center !important;
			font-weight: 500 !important;
			min-width: 80px !important;
		}
		.search-catalog .category__content-info-list {
			margin-top: 4px !important;
			margin-bottom: 0 !important;
		}
		.search-catalog .category__content-info-list ul {
			display: flex !important;
			flex-wrap: wrap !important;
			gap: 4px 12px !important;
		}
		.search-catalog .category__content-info-list li {
			margin-bottom: 2px !important;
			width: auto !important;
			flex: 0 1 auto !important;
			border: none !important;
		}
		.search-catalog .category__content-info-list li::before {
			display: none !important;
		}
		.search-catalog .category__content-info-list li span:first-child {
			float: none !important;
			display: inline !important;
			font-size: 12px !important;
			color: #666 !important;
		}
		.search-catalog .category__content-info-list li span:last-child {
			float: none !important;
			display: inline !important;
			font-size: 12px !important;
			font-weight: 600 !important;
			padding-left: 2px !important;
		}
		.search-catalog .category__content-info-list strong {
			display: none !important;
		}
		.search-catalog .category_cinfo-name {
			font-size: 14px !important;
			font-weight: 600 !important;
			margin-bottom: 2px !important;
			line-height: 1.3 !important;
		}
		.search-catalog .category_cinfo-spec {
			font-size: 11px !important;
			margin-bottom: 1px !important;
			color: #666 !important;
		}
		.search-catalog .category_cinfo-address {
			font-size: 11px !important;
			margin-bottom: 2px !important;
			color: #999 !important;
		}
	}
');
?>
<div id="map" style="width:100%; height:380px"></div>
<div class="category jsn_stockList search-catalog">
	<style>
		main > .container { padding: 0; max-width: 1170px; }
		.category__masters-sidebar .clearable { width: 100%; }
		.category__masters-sidebar select { width: 100%; }
		.category__masters-sidebar .chosen-container { width: 100% !important; }
		.category__masters-sidebar input.filed__master.vg-datetime-picker { width: 100%; box-sizing: border-box; }
		@media (max-width: 767px) {
			.search-catalog .search-catalog__item {
				align-items: flex-start;
			}
			.search-catalog .search-catalog__item .category__item-content {
				min-width: 0;
			}
			.search-catalog .search-catalog__item .category__content-info-list {
				clear: both;
				width: 100%;
				margin: 12px 0 0 0;
				padding-left: 0;
			}
			.search-catalog .search-catalog__item .category__content-info-list strong {
				display: block;
				margin-bottom: 8px;
			}
			.search-catalog .search-catalog__item .category__content-info-list ul {
				margin: 0;
				padding: 0;
			}
			.search-catalog .search-catalog__item .category__content-info-list li {
				margin-bottom: 10px;
				overflow: visible;
			}
			.search-catalog .search-catalog__item .category__content-info-list li::before {
				display: none;
			}
		}
	</style>
	<div class="category__head">
		<h2><?php echo htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8'); ?></h2>
		<?php if ($pagination && $pagination->total > 0) : ?>
			<span class="cat_head-res">Найдено поиска - <span><?php echo (int) $pagination->total; ?></span></span>
		<?php endif; ?>
		<form action="<?php echo $baseUrl; ?>" class="form-horizontal" method="get">
			<ul class="sort">
				<li>Сортировка:</li>
				<li><label class="radioBox">Новые <input type="radio" name="filter_order" value="newest"<?php echo $listOrder === 'newest' ? ' checked' : ''; ?> onchange="this.form.submit()"><span class="checkmark"></span></label></li>
				<li><label class="radioBox">Цена <input type="radio" name="filter_order" value="price"<?php echo $listOrder === 'price' ? ' checked' : ''; ?> onchange="this.form.submit()"><span class="checkmark"></span></label></li>
				<li><label class="radioBox">Дата <input type="radio" name="filter_order" value="date"<?php echo $listOrder === 'date' ? ' checked' : ''; ?> onchange="this.form.submit()"><span class="checkmark"></span></label></li>
			</ul>
			<input type="hidden" name="filter_order_Dir" value="<?php echo htmlspecialchars($listDirn, ENT_QUOTES, 'UTF-8'); ?>">
			<input type="hidden" name="limit" value="<?php echo $limit; ?>">
			<input type="hidden" name="cat_id" value="<?php echo $currentCatId; ?>">
			<input type="hidden" name="booking_mode" value="<?php echo htmlspecialchars($currentBookingMode, ENT_QUOTES, 'UTF-8'); ?>">
			<input type="hidden" name="city" value="<?php echo htmlspecialchars($currentCity, ENT_QUOTES, 'UTF-8'); ?>">
			<input type="hidden" name="area" value="<?php echo htmlspecialchars($currentArea, ENT_QUOTES, 'UTF-8'); ?>">
			<?php foreach ($currentHome as $home) : ?>
				<input type="hidden" name="home[]" value="<?php echo (int) $home; ?>">
			<?php endforeach; ?>
			<input type="hidden" name="avail_date" value="<?php echo htmlspecialchars($currentAvailDate, ENT_QUOTES, 'UTF-8'); ?>">
		</form>
	</div>
	<div class="category__body">
		<div class="clearFloat">&nbsp;</div>
		<div class="category__masters">
			<?php if ($modelError !== '') : ?>
				<div class="alert alert-danger"><?php echo htmlspecialchars($modelError, ENT_QUOTES, 'UTF-8'); ?></div>
			<?php endif; ?>
			<?php if (empty($items)) : ?>
				<div class="alert alert-warning">Поиск моделей не найдены.</div>
			<?php else : ?>
				<?php foreach ($items as $item) :
					include __DIR__ . '/default_item.php';
				endforeach; ?>
				<?php if ($pagination && $pagination->pagesTotal > 1) : ?>
					<div class="pagination__wrap" style="clear:both"><?php echo $pagination->getPagesLinks(); ?></div>
				<?php endif; ?>
			<?php endif; ?>
		</div>
		<div data-da=".pagination__wrap,922,1" class="category__masters-sidebar">
			<h2>Фильтр поиска</h2>
			<form action="<?php echo $baseUrl; ?>" class="form-horizontal filter" method="get">
				<div class="masters-sidebar__body">
					<span class="clearable">
						<select id="city" name="city" data-placeholder="Город" class="filed__master">
							<option value=""></option>
							<?php foreach ($cities as $city) : ?>
								<option value="<?php echo htmlspecialchars($city, ENT_QUOTES, 'UTF-8'); ?>"<?php echo $currentCity === $city ? ' selected' : ''; ?>><?php echo htmlspecialchars($city, ENT_QUOTES, 'UTF-8'); ?></option>
							<?php endforeach; ?>
						</select>
					</span>
					<span class="clearable">
						<select id="area" name="area" data-placeholder="Район" class="filed__master">
							<option value=""></option>
							<?php foreach ($areas as $area) : ?>
								<option value="<?php echo htmlspecialchars($area, ENT_QUOTES, 'UTF-8'); ?>"<?php echo $currentArea === $area ? ' selected' : ''; ?>><?php echo htmlspecialchars($area, ENT_QUOTES, 'UTF-8'); ?></option>
							<?php endforeach; ?>
						</select>
					</span>
					<span class="clearable">
						<select id="vyberite_spetsialnos" name="cat_id" data-placeholder="Категория" class="filed__master">
							<option value="0"></option>
							<?php foreach ($categories as $id => $cat) : ?>
								<option value="<?php echo (int) $id; ?>"<?php echo (int) $id === $currentCatId ? ' selected' : ''; ?>><?php echo htmlspecialchars((string) ($cat['filter_title'] ?? $cat['title'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></option>
							<?php endforeach; ?>
						</select>
					</span>
					<span class="clearable">
						<select id="booking_mode" name="booking_mode" data-placeholder="Режим записи" class="filed__master">
							<option value=""></option>
							<option value="free"<?php echo $currentBookingMode === 'free' ? ' selected' : ''; ?>>Любое время</option>
							<option value="fixed"<?php echo $currentBookingMode === 'fixed' ? ' selected' : ''; ?>>Фиксированная дата</option>
						</select>
					</span>
					<span class="clearable">
						<select id="home" name="home[]" data-placeholder="Форма работы" class="filed__master">
							<option value=""></option>
							<?php foreach ($homeOptions as $id => $label) : ?>
								<option value="<?php echo $id; ?>"<?php echo in_array($id, $currentHome, true) ? ' selected' : ''; ?>><?php echo htmlspecialchars($label, ENT_QUOTES, 'UTF-8'); ?></option>
							<?php endforeach; ?>
						</select>
					</span>
					<span class="clearable<?php echo ($currentCity !== '' || $currentCatId > 0) ? '' : ' hidden'; ?>" id="modeli-avail-date-wrap">
						<input type="text" name="avail_date" class="filed__master vg-datetime-picker" value="<?php echo htmlspecialchars($currentAvailDate, ENT_QUOTES, 'UTF-8'); ?>" placeholder="Дата и время записи" autocomplete="off">
					</span>
				</div>
				<input type="hidden" name="filter_order" value="<?php echo htmlspecialchars($listOrder, ENT_QUOTES, 'UTF-8'); ?>">
				<input type="hidden" name="filter_order_Dir" value="<?php echo htmlspecialchars($listDirn, ENT_QUOTES, 'UTF-8'); ?>">
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

window.onModeliYmapsReady = function() {
	var mapCenterCity = <?php echo json_encode($currentCity !== '' ? $currentCity : 'Москва'); ?>;
	var masters = <?php echo json_encode($mapMasters, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
	var mapObj = null;
	var geocodeCache = {};

	function geocodeAddress(address) {
		var key = String(address || '').trim();
		if (!key) return Promise.resolve(null);
		if (geocodeCache[key]) return geocodeCache[key];
		geocodeCache[key] = ymaps.geocode(key, { results: 1 }).then(function(res) {
			var first = res.geoObjects.get(0);
			if (!first) return null;
			var meta = first.properties.get('metaDataProperty');
			var geocoderMeta = meta && meta.GeocoderMetaData ? meta.GeocoderMetaData : null;
			var precision = geocoderMeta && geocoderMeta.precision ? String(geocoderMeta.precision) : '';
			if (precision && ['exact', 'number', 'near', 'street'].indexOf(precision) === -1) return null;
			return first.geometry.getCoordinates();
		}).catch(function() { return null; });
		return geocodeCache[key];
	}

	function updateMap() {
		if (!mapObj) return;
		mapObj.geoObjects.removeAll();
		if (!masters.length) {
			geocodeAddress(mapCenterCity).then(function(coords) {
				if (coords) {
					mapObj.setCenter(coords);
					mapObj.setZoom(11);
				} else {
					mapObj.setCenter([55.751244, 37.618423]);
					mapObj.setZoom(10);
				}
			});
			return;
		}

		Promise.all(masters.map(function(master) {
			var query = master.geocode_query || master.address || '';
			if (!query) {
				return null;
			}
			return geocodeAddress(query).then(function(coords) {
				if (coords) {
					var marker = new ymaps.Placemark(coords, {
						balloonContent: master.balloon
					}, {
						preset: 'islands#darkBlueCircleDotIcon'
					});
					mapObj.geoObjects.add(marker);
				}
				return coords;
			});
		})).then(function() {
			if (mapObj.geoObjects.getLength() > 0) {
				mapObj.setBounds(mapObj.geoObjects.getBounds(), { checkZoomRange: true, zoomMargin: 50 });
			} else {
				geocodeAddress(mapCenterCity).then(function(coords) {
					if (coords) {
						mapObj.setCenter(coords);
						mapObj.setZoom(11);
					} else {
						mapObj.setCenter([55.751244, 37.618423]);
						mapObj.setZoom(10);
					}
				});
			}
		});
	}
	ymaps.ready(function() {
		mapObj = new ymaps.Map('map', {
			center: [61.5240, 105.3188],
			zoom: 3,
			controls: []
		});
		updateMap();
	});
};

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

(function() {
	function padTimePart(value) {
		return value < 10 ? '0' + value : String(value);
	}

	function formatLocalSearchTime(rawUtc) {
		var value = String(rawUtc || '').trim();
		if (!value) return '';
		var normalized = value.replace(' ', 'T');
		if (!/[zZ]|[+-]\d{2}:?\d{2}$/.test(normalized)) {
			normalized += 'Z';
		}
		var date = new Date(normalized);
		if (Number.isNaN(date.getTime())) return '';

		return [
			padTimePart(date.getDate()),
			padTimePart(date.getMonth() + 1),
			date.getFullYear()
		].join('.') + ' ' + [
			padTimePart(date.getHours()),
			padTimePart(date.getMinutes())
		].join(':');
	}

	function localizeSearchTimes() {
		document.querySelectorAll('.modeli-time-utc[data-time-utc]').forEach(function(node) {
			var label = formatLocalSearchTime(node.getAttribute('data-time-utc'));
			if (label) {
				node.textContent = label;
			}
		});
	}

	function initChosen() {
		if (typeof jQuery === 'undefined' || typeof jQuery.fn.chosen === 'undefined') return;
		jQuery('.category__masters-sidebar select').chosen({
			disable_search_threshold: 100,
			allow_single_deselect: true,
			no_results_text: 'Не найдено:',
			width: '100%'
		});
		var $ = jQuery;
		var $dateWrap = $('#modeli-avail-date-wrap');
		function syncModeliAvailDate() {
			var cityVal = String($('#city').val() || '').trim();
			var catId = parseInt($('#vyberite_spetsialnos').val(), 10) || 0;
			if (cityVal !== '' || catId > 0) {
				$dateWrap.removeClass('hidden');
			} else {
				$dateWrap.addClass('hidden');
				$dateWrap.find('input.vg-datetime-picker').val('');
			}
		}
		$('#city').on('change', syncModeliAvailDate);
		$('#vyberite_spetsialnos').on('change', syncModeliAvailDate);
		syncModeliAvailDate();

		if (typeof $.fn.datetimepicker === 'function') {
			$.datetimepicker.setLocale('ru');
			$('input.vg-datetime-picker').datetimepicker({
				format: 'Y-m-d H:i',
				step: 15,
				minDate: 0,
				dayOfWeekStart: 1,
				validateOnBlur: false,
				closeOnDateSelect: false,
				onChangeDateTime: function(dp, $input) {
					var val = $input.val();
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
								$input.val(newVal);
								$input.trigger('change');
							}
						}
					}
				}
			});
		}
	}

	function loadYmaps() {
		if (window.ymaps) {
			window.onModeliYmapsReady();
			return;
		}
		var script = document.createElement('script');
		script.src = 'https://api-maps.yandex.ru/2.1/?lang=ru-RU&apikey=705d45a1-9138-4d99-afd4-dc261c612036';
		script.async = true;
		script.defer = true;
		script.onload = function() {
			if (typeof window.onModeliYmapsReady === 'function') {
				window.onModeliYmapsReady();
			}
		};
		script.onerror = function() {
			var mapEl = document.getElementById('map');
			if (mapEl) {
				mapEl.style.display = 'none';
			}
		};
		document.head.appendChild(script);
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', function() {
			localizeSearchTimes();
			initChosen();
			loadYmaps();
		});
	} else {
		localizeSearchTimes();
		initChosen();
		loadYmaps();
	}
})();

document.addEventListener('DOMContentLoaded', function() {
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