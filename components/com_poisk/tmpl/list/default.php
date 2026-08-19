<?php

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Uri\Uri;

/** @var \Viglin\Component\Poisk\Site\View\List\HtmlView $this */
$items = $this->items;
$pagination = $this->pagination;
$categories = $this->categories;
$pageTitle = $this->pageTitle;
$filterTitle = isset($this->filterTitle) ? $this->filterTitle : 'Фильтр специалистов';
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
$baseUrl = trim((string) Uri::getInstance()->toString(['path']));
if ($baseUrl === '' || $baseUrl === '/') {
	$baseUrl = '/';
}
$homeLabels = [1 => 'Салон', 2 => 'Вызов на дом', 3 => 'Мастер на дому'];
$app = Factory::getApplication();
$input = $app->getInput();
$currentCatId = (int) $input->get('cat_id', 0);
$currentCity = $input->get('city', '', 'STRING');
$currentArea = $input->get('area', '', 'STRING');
$currentMasterName = $input->get('master_name', '', 'STRING');
$currentHome = array_map('intval', (array) $input->get('home', [], 'ARRAY'));
$limit = (int) $input->getUint('limit', 20);
$currentAvailDate = $input->get('avail_date', '', 'STRING');
if ($currentAvailDate !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}(?:[ T]\d{2}:\d{2})?$/', $currentAvailDate)) {
	$currentAvailDate = '';
}
$mapMasters = [];

$normalizeAddressPart = static function (string $value): string {
	$value = trim(preg_replace('/\s+/u', ' ', $value));
	return trim($value, " ,");
};

$masterWorkDataCache = [];
if (!empty($mapItems)) {
	$categoryTitleById = [];
	foreach ((array) $categories as $cid => $cat) {
		$categoryTitleById[(int) $cid] = trim((string) ($cat['title'] ?? ''));
	}
	
	$userIds = array_map(function($item) { return $item->id; }, $mapItems);
	if (!empty($userIds)) {
		$db = Factory::getDbo();
		$query = $db->getQuery(true)
			->select(['item_id', 'field_id', 'value'])
			->from($db->quoteName('#__fields_values'))
			->where($db->quoteName('item_id') . ' IN (' . implode(',', array_map('intval', $userIds)) . ')')
			->where($db->quoteName('field_id') . ' IN (85, 86, 87)');
		$db->setQuery($query);
		$results = $db->loadObjectList() ?: [];
		
		foreach ($results as $row) {
			if (!isset($masterWorkDataCache[$row->item_id])) {
				$masterWorkDataCache[$row->item_id] = ['85' => '[]', '86' => '9.00', '87' => '17.00'];
			}
			$masterWorkDataCache[$row->item_id][$row->field_id] = $row->value;
		}
	}
	
	foreach ($mapItems as $mapItem) {
		$mapFields = $mapFieldsByUser[$mapItem->id] ?? [];
		$sity = trim((string) ($mapFields['sity'] ?? ''));
		$area = trim((string) ($mapFields['area'] ?? ''));
		$street = trim((string) ($mapFields['street'] ?? ''));
		$house = trim((string) ($mapFields['house_number'] ?? ''));
		$specialitiesRaw = (string) ($mapFields['vyberite_spetsialnos'] ?? '');
		$displayAddressParts = array_values(array_filter(array_map($normalizeAddressPart, [$sity, $area, $street, $house])));
		$addr = implode(', ', $displayAddressParts);
		if ($addr === '') {
			$addr = $currentCity !== '' ? $currentCity : 'Москва';
		}
		$geocodeParts = [];
		if ($sity !== '') {
			$geocodeParts[] = $normalizeAddressPart($sity);
		}
		if ($area !== '') {
			$geocodeParts[] = $normalizeAddressPart($area);
		}
		if ($street !== '') {
			$geocodeParts[] = $normalizeAddressPart($street);
		}
		if ($house !== '') {
			$geocodeParts[] = $normalizeAddressPart($house);
		}
		$geocodeParts = array_values(array_unique(array_filter($geocodeParts)));
		$geocodeQuery = count($geocodeParts) >= 3
			? implode(', ', $geocodeParts)
			: '';
		$profileLink = '/index.php?option=com_users&view=profile&user_id=' . (int) $mapItem->id;
		$specialityIds = json_decode($specialitiesRaw, true);
		$specialityTitles = [];
		if (is_array($specialityIds)) {
			foreach ($specialityIds as $sid) {
				$sid = (int) $sid;
				if ($sid > 0 && !empty($categoryTitleById[$sid])) {
					$specialityTitles[] = $categoryTitleById[$sid];
				}
			}
		}
		$specialityTitles = array_values(array_unique(array_filter($specialityTitles)));
		$servicesText = $specialityTitles ? implode(', ', $specialityTitles) : 'Услуги не указаны';
		$name = htmlspecialchars((string) ($mapItem->name ?? ''), ENT_QUOTES, 'UTF-8');
		$addrEsc = htmlspecialchars($addr, ENT_QUOTES, 'UTF-8');
		$servicesEsc = htmlspecialchars($servicesText, ENT_QUOTES, 'UTF-8');
		$iconUser = '<svg width="12" height="12" viewBox="0 0 24 24" aria-hidden="true" style="vertical-align:-1px;margin-right:4px"><path fill="currentColor" d="M12 12a5 5 0 1 0-5-5a5 5 0 0 0 5 5m0 2c-4.42 0-8 2.24-8 5v1h16v-1c0-2.76-3.58-5-8-5"/></svg>';
		$iconServices = '<svg width="12" height="12" viewBox="0 0 24 24" aria-hidden="true" style="vertical-align:-1px;margin-right:4px"><path fill="currentColor" d="M10 2h4v4h-4zM4 8h16v2H4zm1 4h6v8H5zm8 0h6v8h-6z"/></svg>';
		$iconMap = '<svg width="12" height="12" viewBox="0 0 24 24" aria-hidden="true" style="vertical-align:-1px;margin-right:4px"><path fill="currentColor" d="M12 2a7 7 0 0 0-7 7c0 4.89 7 13 7 13s7-8.11 7-13a7 7 0 0 0-7-7m0 9.5A2.5 2.5 0 1 1 14.5 9A2.5 2.5 0 0 1 12 11.5"/></svg>';
		$balloon = '<div class="map-balloon">'
			. '<a href="' . $profileLink . '">' . $iconUser . $name . '</a><br>'
			. '<span>' . $iconServices . $servicesEsc . '</span><br>'
			. '<span>' . $iconMap . $addrEsc . '</span>'
			. '</div>';
		
		$workData = $masterWorkDataCache[$mapItem->id] ?? ['85' => '[]', '86' => '9.00', '87' => '17.00'];
		$daysJson = $workData['85'] ?? '[]';
		$days = json_decode($daysJson, true);
		$workDays = is_array($days) ? array_map('intval', $days) : [];
		$workStart = str_replace('.', ':', $workData['86'] ?? '9.00');
		$workEnd = str_replace('.', ':', $workData['87'] ?? '17.00');
		
		$mapMasters[] = [
			'user_id' => (int) $mapItem->id,
			'name' => (string) ($mapItem->name ?? ''),
			'address' => $addr,
			'geocode_query' => $geocodeQuery,
			'services' => $servicesText,
			'balloon' => $balloon,
			'work_days' => $workDays,
			'work_start' => $workStart,
			'work_end' => $workEnd
		];
	}
}
$doc = Factory::getDocument();
$doc->addStyleSheet(\Joomla\CMS\Uri\Uri::root(true) . '/templates/ryba/css/chosen.min.css');
?>
<div id="map" style="width:100%; height:380px"></div>
<div class="category jsn_list">
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
			<input type="hidden" name="master_name" value="<?php echo htmlspecialchars($currentMasterName); ?>">
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
				<div class="alert alert-warning">Специалисты не найдены.</div>
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
			<h2><?php echo htmlspecialchars($filterTitle); ?></h2>
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
					<span class="clearable" style="display:none">
						<input type="text" name="master_name" class="filed__master" placeholder="Имя мастера" value="<?php echo htmlspecialchars($currentMasterName); ?>" autocomplete="off">
					</span>
					<span class="clearable">
						<select id="vyberite_spetsialnos" name="cat_id" data-placeholder="Мастер" class="filed__master">
							<option value="0"></option>
							<?php foreach ($categories as $id => $cat) : ?>
								<option value="<?php echo (int) $id; ?>"<?php echo (int) $id === $currentCatId ? ' selected' : ''; ?>><?php echo htmlspecialchars($cat['filter_title'] ?? $cat['title'] ?? ''); ?></option>
							<?php endforeach; ?>
						</select>
					</span>
					<span class="clearable<?php echo $currentCatId > 0 ? '' : ' hidden'; ?>" id="poisk-service-wrap">
						<select id="service" name="service" data-placeholder="Услуга" class="filed__master">
							<option value="0"></option>
							<?php foreach ($services as $service) : ?>
								<option value="<?php echo (int) ($service['id'] ?? 0); ?>"<?php echo (int) ($service['id'] ?? 0) === $currentService ? ' selected' : ''; ?>><?php echo htmlspecialchars((string) ($service['title'] ?? '')); ?></option>
							<?php endforeach; ?>
						</select>
					</span>
					<span class="clearable<?php echo ($currentCatId > 0 && $currentService > 0 && $tags !== []) ? '' : ' hidden'; ?>" id="poisk-tag-wrap">
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
					<span class="clearable<?php echo ($currentCity !== '' || $currentCatId > 0) ? '' : ' hidden'; ?>" id="poisk-avail-date-wrap">
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

window.onPoiskYmapsReady = function() {
	var mapCenterCity = <?php echo json_encode($currentCity !== '' ? $currentCity : 'Москва'); ?>;
	var masters = <?php echo json_encode($mapMasters, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
	var mapObj = null;
	var geocodeCache = {};

	function geocodeAddress(address) {
		var key = String(address || '').trim();
		if (!key) {
			return Promise.resolve(null);
		}
		if (geocodeCache[key]) {
			return geocodeCache[key];
		}
		geocodeCache[key] = ymaps.geocode(key, { results: 1 })
			.then(function (res) {
				var first = res.geoObjects.get(0);
				if (!first) {
					return null;
				}
				var meta = first.properties.get('metaDataProperty');
				var geocoderMeta = meta && meta.GeocoderMetaData ? meta.GeocoderMetaData : null;
				var precision = geocoderMeta && geocoderMeta.precision ? String(geocoderMeta.precision) : '';
				if (precision && ['exact', 'number', 'near', 'street'].indexOf(precision) === -1) {
					return null;
				}
				return first.geometry.getCoordinates();
			})
			.catch(function () {
				return null;
			});
		return geocodeCache[key];
	}

	function isMasterAvailable(master, dateTime) {
		if (!dateTime || !master) return true;
		var dt = new Date(dateTime);
		if (isNaN(dt.getTime())) return true;
		var dayOfWeek = dt.getDay();
		var dayNum = dayOfWeek === 0 ? 7 : dayOfWeek;
		var workDays = master.work_days || [];
		if (workDays.length > 0 && !workDays.includes(dayNum)) {
			return false;
		}
		var hours = String(dt.getHours()).padStart(2, '0');
		var minutes = String(dt.getMinutes()).padStart(2, '0');
		var timeStr = hours + ':' + minutes;
		if (!isTimeMultipleOf15(timeStr)) {
			return false;
		}
		var timeMin = timeToMinutes(timeStr);
		var startMin = timeToMinutes(master.work_start || '09:00');
		var endMin = timeToMinutes(master.work_end || '17:00');
		return timeMin >= startMin && timeMin <= endMin;
	}

	function updateMap() {
		if (!mapObj) return;
		mapObj.geoObjects.removeAll();
		var filteredMasters = masters;
		var availDate = <?php echo json_encode($currentAvailDate); ?>;
		if (availDate) {
			filteredMasters = masters.filter(function(master) {
				return isMasterAvailable(master, availDate);
			});
		}
		if (!filteredMasters.length) {
			geocodeAddress(mapCenterCity).then(function (coords) {
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
		Promise.all(filteredMasters.map(function (master) {
			var addr = (master && master.geocode_query) ? master.geocode_query : '';
			if (!addr) {
				return null;
			}
			return geocodeAddress(addr).then(function (coords) {
				if (coords) {
					var m = new ymaps.Placemark(coords, { balloonContent: (master && master.balloon) ? master.balloon : '' }, {
						preset: 'islands#darkBlueCircleDotIcon'
					});
					mapObj.geoObjects.add(m);
				}
				return coords;
			});
		})).then(function () {
			if (mapObj.geoObjects.getLength() > 0) {
				mapObj.setBounds(mapObj.geoObjects.getBounds(), { checkZoomRange: true, zoomMargin: 50 });
			} else {
				geocodeAddress(mapCenterCity).then(function (coords) {
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
		mapObj = new ymaps.Map('map', { center: [61.5240, 105.3188], zoom: 3, controls: [] });
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

document.addEventListener('DOMContentLoaded', function () {
	if (window.ymaps && typeof window.onPoiskYmapsReady === 'function') {
		window.onPoiskYmapsReady();
		return;
	}

	var script = document.createElement('script');
	script.src = 'https://api-maps.yandex.ru/2.1/?lang=ru-RU&apikey=705d45a1-9138-4d99-afd4-dc261c612036';
	script.async = true;
	script.defer = true;
	script.onload = function () {
		if (typeof window.onPoiskYmapsReady === 'function') {
			window.onPoiskYmapsReady();
		}
	};
	script.onerror = function () {
		var mapEl = document.getElementById('map');
		if (mapEl) {
			mapEl.style.display = 'none';
		}
	};
	document.head.appendChild(script);

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
$doc->addScript('https://cdnjs.cloudflare.com/ajax/libs/jquery-datetimepicker/2.5.20/jquery.datetimepicker.full.min.js', ['defer' => true]);
$doc->addScriptDeclaration("
document.addEventListener('DOMContentLoaded', function() {
    var serviceHierarchy = " . json_encode((array) $serviceHierarchy, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . ";
    if (typeof jQuery === 'undefined') {
        return;
    }
    var \$ = jQuery;
    var \$sidebar = $('.category__masters-sidebar');
    var \$cat = $('#vyberite_spetsialnos');
    var \$service = $('#service');
    var \$tag = $('#tag');
    var \$serviceWrap = $('#poisk-service-wrap');
    var \$tagWrap = $('#poisk-tag-wrap');

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

    var \$availDateWrap = $('#poisk-avail-date-wrap');

    function syncPoiskAvailDate() {
        var cityVal = String($('#city').val() || '').trim();
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
        syncPoiskAvailDate();
    });
    \$service.on('change', function() {
        \$tag.val('0');
        syncFilterLevels();
    });
    $('#city').on('change', syncPoiskAvailDate);

    syncFilterLevels();
    syncPoiskAvailDate();

    if (typeof $.fn.datetimepicker === 'function') {
        try {
            if (typeof $.datetimepicker !== 'undefined' && $.datetimepicker.setLocale) {
                $.datetimepicker.setLocale('ru');
            }
        } catch(e) {}
        
        $('input.vg-datetime-picker').datetimepicker({
            format: 'Y-m-d H:i',
            step: 15,
            minDate: 0,
            dayOfWeekStart: 1,
            validateOnBlur: false,
            closeOnDateSelect: false,
            months: ['Январь', 'Февраль', 'Март', 'Апрель', 'Май', 'Июнь', 'Июль', 'Август', 'Сентябрь', 'Октябрь', 'Ноябрь', 'Декабрь'],
            monthsShort: ['Янв', 'Фев', 'Мар', 'Апр', 'Май', 'Июн', 'Июл', 'Авг', 'Сен', 'Окт', 'Ноя', 'Дек'],
            dayOfWeek: ['Воскресенье', 'Понедельник', 'Вторник', 'Среда', 'Четверг', 'Пятница', 'Суббота'],
            dayOfWeekShort: ['Вс', 'Пн', 'Вт', 'Ср', 'Чт', 'Пт', 'Сб'],
            dayOfWeekMin: ['Вс', 'Пн', 'Вт', 'Ср', 'Чт', 'Пт', 'Сб'],
            onChangeDateTime: function(dp, \$input) {
                var val = \$input.val();
                if (val) {
                    var timeMatch = val.match(/\\d{2}:\\d{2}$/);
                    if (timeMatch) {
                        var parts = timeMatch[0].split(':');
                        var minutes = parseInt(parts[0], 10) * 60 + parseInt(parts[1], 10);
                        if (minutes % 15 !== 0) {
                            var rounded = Math.round(minutes / 15) * 15;
                            var h = String(Math.floor(rounded / 60)).padStart(2, '0');
                            var m = String(rounded % 60).padStart(2, '0');
                            var newVal = val.replace(/\\d{2}:\\d{2}$/, h + ':' + m);
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