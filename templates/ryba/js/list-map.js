(function (window, document) {
	'use strict';

	var API_KEY = '705d45a1-9138-4d99-afd4-dc261c612036';
	var API_SRC = 'https://api-maps.yandex.ru/2.1/?lang=ru-RU&apikey=' + API_KEY;
	var CITY_ZOOM = 10;
	var MAX_FIT_ZOOM = 11;
	var STREET_ZOOM = 13;
	var CITY_LL = {
		'москва': [37.6173, 55.7558],
		'санкт-петербург': [30.3159, 59.9391],
		'новосибирск': [82.9346, 55.0084],
		'екатеринбург': [60.6122, 56.8519],
		'казань': [49.1064, 55.7961],
		'нижний новгород': [44.002, 56.3269],
		'челябинск': [61.4026, 55.1644],
		'самара': [50.1018, 53.1959],
		'омск': [73.3686, 54.9885],
		'ростов-на-дону': [39.7015, 47.2357],
		'уфа': [55.9587, 54.7351],
		'красноярск': [92.8526, 56.0153],
		'воронеж': [39.1843, 51.672],
		'пермь': [56.2502, 58.0105],
		'волгоград': [44.5018, 48.708],
		'краснодар': [38.9753, 45.0355],
		'саратов': [46.0343, 51.5336],
		'тюмень': [65.5272, 57.1522],
		'тольятти': [49.4192, 53.5303],
		'ижевск': [53.2045, 56.8528],
		'барнаул': [83.7636, 53.3548],
		'иркутск': [104.2804, 52.2864],
		'хабаровск': [135.0719, 48.4827],
		'ярославль': [39.8946, 57.6266],
		'владивосток': [131.8855, 43.1155],
		'махачкала': [47.5047, 42.9849],
		'томск': [84.9482, 56.4846],
		'оренбург': [55.0969, 51.7682],
		'кемерово': [86.0873, 55.3543],
		'новокузнецк': [87.1361, 53.7596],
		'рязань': [39.734, 54.629],
		'астрахань': [48.0408, 46.3479],
		'пенза': [45.0195, 53.195],
		'липецк': [39.5987, 52.6031],
		'киров': [49.6601, 58.6036],
		'чебоксары': [47.2511, 56.1439],
		'калининград': [20.4522, 54.7104],
		'тула': [37.6173, 54.1931],
		'курск': [36.192, 51.7304],
		'сочи': [39.7231, 43.6028]
	};
	var ymapsLoading = null;
	var geocodeCache = {};

	function cityKey(name) {
		return String(name || '').trim().toLowerCase().replace(/\s+/g, ' ');
	}

	function cityLonLat(name) {
		var hit = CITY_LL[cityKey(name)];
		return hit ? hit.slice() : CITY_LL['москва'].slice();
	}

	function cityLatLon(name) {
		var ll = cityLonLat(name);
		return [ll[1], ll[0]];
	}

	function hashString(value) {
		var hash = 0;
		var text = String(value || '');
		for (var i = 0; i < text.length; i++) {
			hash = ((hash << 5) - hash) + text.charCodeAt(i);
			hash |= 0;
		}
		return Math.abs(hash);
	}

	function groupKey(pin) {
		var city = String(pin.city || '').trim();
		var area = String(pin.area || '').trim();
		if (city && area) {
			return city + ', ' + area;
		}
		return city || 'Москва';
	}

	function streetQuery(pin) {
		return String(pin.query || '').trim();
	}

	function offsetAroundCity(cityName, key) {
		var center = cityLatLon(cityName);
		var hash = hashString(key);
		var angle = (hash % 360) * Math.PI / 180;
		var distance = 0.018 + ((hash >> 8) % 10) * 0.004;
		return [
			center[0] + Math.sin(angle) * distance,
			center[1] + Math.cos(angle) * distance * 1.55
		];
	}

	function coordsForGroup(key) {
		var cityName = String(key.split(',')[0] || '').trim();
		if (CITY_LL[cityKey(cityName)]) {
			if (key.indexOf(',') === -1) {
				return cityLatLon(cityName);
			}
			return offsetAroundCity(cityName, key);
		}
		return offsetAroundCity('Москва', key);
	}

	function pinHref(pin) {
		var href = String((pin && pin.href) || '').trim();
		if (!href || href === '#') {
			return '';
		}
		if (href.charAt(0) === '/' || /^https?:/i.test(href)) {
			return href;
		}
		return '/' + href;
	}

	function openPinProfile(pin) {
		var href = pinHref(pin);
		if (!href || href === '#') {
			return false;
		}
		window.location.href = href;
		return true;
	}

	function pinBalloon(pin) {
		if (pin.balloon) {
			return pin.balloon;
		}
		var href = pinHref(pin) || '#';
		var name = pin.name || '';
		var line = pin.line || '';
		var addr = pin.addr || '';
		return '<div class="map-balloon">'
			+ '<a href="' + href + '">' + name + '</a>'
			+ (line ? '<br><span>' + line + '</span>' : '')
			+ (addr ? '<br><span>' + addr + '</span>' : '')
			+ '</div>';
	}

	function loadYmaps() {
		if (window.ymaps) {
			return Promise.resolve(window.ymaps);
		}
		if (ymapsLoading) {
			return ymapsLoading;
		}
		ymapsLoading = new Promise(function (resolve, reject) {
			var script = document.createElement('script');
			script.src = API_SRC;
			script.async = true;
			script.onload = function () {
				if (!window.ymaps) {
					reject(new Error('ymaps missing'));
					return;
				}
				window.ymaps.ready(function () {
					resolve(window.ymaps);
				});
			};
			script.onerror = function () {
				reject(new Error('ymaps failed'));
			};
			document.head.appendChild(script);
		});
		return ymapsLoading;
	}

	function geocodeAddress(ymaps, address) {
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
				return first.geometry.getCoordinates();
			})
			.catch(function () {
				return null;
			});
		return geocodeCache[key];
	}

	function runPool(items, worker, limit) {
		var index = 0;
		var running = 0;
		return new Promise(function (resolve) {
			function next() {
				if (index >= items.length && running === 0) {
					resolve();
					return;
				}
				while (running < limit && index < items.length) {
					running += 1;
					worker(items[index++]).then(function () {
						running -= 1;
						next();
					}, function () {
						running -= 1;
						next();
					});
				}
			}
			next();
		});
	}

	function yellowDotHref() {
		var svg = '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 18 18">'
			+ '<circle cx="9" cy="9" r="7" fill="#f9ce54" stroke="#111" stroke-width="2"/>'
			+ '</svg>';
		return 'data:image/svg+xml;charset=UTF-8,' + encodeURIComponent(svg);
	}

	function createLayouts(ymaps) {
		return {
			cluster: ymaps.templateLayoutFactory.createClass(
				'<div class="vg-list-map__cluster-icon">{{ properties.geoObjects.length }}</div>'
			)
		};
	}

	function createClusterer(ymaps, layouts) {
		return new ymaps.Clusterer({
			clusterIconLayout: layouts.cluster,
			clusterIconShape: {
				type: 'Circle',
				coordinates: [0, 0],
				radius: 20
			},
			clusterIconOffset: [-20, -20],
			groupByCoordinates: false,
			clusterDisableClickZoom: false,
			clusterHideIconOnBalloonOpen: false,
			geoObjectHideIconOnBalloonOpen: false,
			gridSize: 80,
			minClusterSize: 2,
			hasBalloon: true,
			clusterOpenBalloonOnClick: true,
			geoObjectOpenBalloonOnClick: false,
			clusterBalloonContentLayout: 'cluster#balloonAccordion',
			clusterBalloonItemContentLayout: 'cluster#balloonAccordionItemContent',
			clusterBalloonPanelMaxMapArea: 0
		});
	}

	function setBusy(root, busy, label) {
		var btn = root.querySelector('.vg-list-map__btn');
		if (!btn) {
			return;
		}
		btn.disabled = !!busy;
		if (label) {
			btn.textContent = label;
		}
	}

	function hidePreview(root) {
		root.classList.add('is-open');
		var overlay = root.querySelector('.vg-list-map__preview');
		if (overlay) {
			overlay.setAttribute('hidden', 'hidden');
		}
		if (root._vgMap) {
			root._vgMap.container.fitToViewport();
		}
	}

	function fitCityView(mapObj, clusterer, city, cityLocked) {
		if (cityLocked && city) {
			mapObj.setCenter(cityLatLon(city), CITY_ZOOM);
			return Promise.resolve();
		}
		var bounds = clusterer.getBounds();
		if (!bounds) {
			mapObj.setCenter(cityLatLon(city || 'Москва'), CITY_ZOOM);
			return Promise.resolve();
		}
		var south = bounds[0][0];
		var north = bounds[1][0];
		var west = bounds[0][1];
		var east = bounds[1][1];
		var span = Math.max(Math.abs(north - south), Math.abs(east - west));
		if (span < 0.02) {
			mapObj.setCenter(cityLatLon(city || 'Москва'), CITY_ZOOM);
			return Promise.resolve();
		}
		var ready = mapObj.setBounds(bounds, { checkZoomRange: true, zoomMargin: 56 }) || Promise.resolve();
		return Promise.resolve(ready).then(function () {
			if (mapObj.getZoom() > MAX_FIT_ZOOM) {
				mapObj.setZoom(MAX_FIT_ZOOM);
			}
		});
	}

	function openMap(root) {
		if (root.getAttribute('data-open') === '1') {
			return;
		}
		root.setAttribute('data-open', '1');
		setBusy(root, true, 'Загрузка…');

		var city = root.getAttribute('data-city') || 'Москва';
		var cityLocked = root.getAttribute('data-city-filter') === '1';
		var pinsUrl = root.getAttribute('data-pins-url') || '';
		var canvas = root.querySelector('.vg-list-map__canvas');

		loadYmaps().then(function (ymaps) {
			hidePreview(root);
			var layouts = createLayouts(ymaps);
			var mapObj = new ymaps.Map(canvas, {
				center: cityLatLon(city),
				zoom: CITY_ZOOM,
				controls: ['zoomControl']
			});
			var clusterer = createClusterer(ymaps, layouts);
			root._vgLayouts = layouts;
			mapObj.geoObjects.add(clusterer);
			root._vgMap = mapObj;
			root._vgClusterer = clusterer;
			mapObj.container.fitToViewport();

			return fetch(pinsUrl, { credentials: 'same-origin' })
				.then(function (res) {
					if (!res.ok) {
						throw new Error('pins http');
					}
					return res.json();
				})
				.then(function (payload) {
					var pins = (payload && payload.pins) ? payload.pins : [];
					return placePins(ymaps, mapObj, clusterer, pins, city, cityLocked, layouts);
				});
		}).then(function () {
			setBusy(root, false, 'Показать на карте');
		}).catch(function () {
			root.setAttribute('data-open', '0');
			root.classList.remove('is-open');
			var overlay = root.querySelector('.vg-list-map__preview');
			if (overlay) {
				overlay.removeAttribute('hidden');
			}
			setBusy(root, false, 'Показать на карте');
			window.alert('Не удалось загрузить карту. Попробуйте ещё раз.');
		});
	}

	function placePins(ymaps, mapObj, clusterer, pins, fallbackCity, cityLocked, layouts) {
		if (!pins.length) {
			mapObj.setCenter(cityLatLon(fallbackCity), CITY_ZOOM);
			return Promise.resolve();
		}

		var groups = {};
		pins.forEach(function (pin) {
			var key = groupKey(pin);
			if (!groups[key]) {
				groups[key] = [];
			}
			groups[key].push(pin);
		});

		var placemarks = [];
		Object.keys(groups).forEach(function (key) {
			var coords = coordsForGroup(key);
			groups[key].forEach(function (pin) {
				var mark = new ymaps.Placemark(coords, {
					balloonContent: pinBalloon(pin),
					hintContent: pin.name || '',
					clusterCaption: pin.name || ''
				}, {
					iconLayout: 'default#image',
					iconImageHref: yellowDotHref(),
					iconImageSize: [18, 18],
					iconImageOffset: [-9, -9],
					iconShape: {
						type: 'Circle',
						coordinates: [0, 0],
						radius: 14
					},
					hasBalloon: false,
					hasHint: true,
					cursor: 'pointer'
				});
				mark._vgPin = pin;
				mark._vgStreetReady = false;
				mark.events.add('click', function (event) {
					if (typeof event.preventDefault === 'function') {
						event.preventDefault();
					}
					openPinProfile(pin);
				});
				placemarks.push(mark);
			});
		});

		clusterer.add(placemarks);
		clusterer.events.add('click', function (event) {
			var target = event.get('target');
			if (!target || typeof target.getGeoObjects === 'function') {
				return;
			}
			if (openPinProfile(target._vgPin || {})) {
				event.preventDefault();
			}
		});

		var refining = false;
		function maybeRefine() {
			if (mapObj.getZoom() < STREET_ZOOM || refining) {
				return;
			}
			refining = true;
			refineStreets(ymaps, mapObj, clusterer, placemarks).then(function () {
				refining = false;
			});
		}

		mapObj.events.add('boundschange', maybeRefine);
		return fitCityView(mapObj, clusterer, fallbackCity, cityLocked);
	}

	function refineStreets(ymaps, mapObj, clusterer, placemarks) {
		var bounds = mapObj.getBounds();
		var pending = placemarks.filter(function (mark) {
			if (mark._vgStreetReady) {
				return false;
			}
			var pin = mark._vgPin || {};
			var query = streetQuery(pin);
			if (!query) {
				mark._vgStreetReady = true;
				return false;
			}
			var pos = mark.geometry.getCoordinates();
			return ymaps.util.bounds.containsPoint(bounds, pos);
		});

		if (!pending.length) {
			return Promise.resolve();
		}

		return runPool(pending, function (mark) {
			var query = streetQuery(mark._vgPin || {});
			return geocodeAddress(ymaps, query).then(function (coords) {
				mark._vgStreetReady = true;
				if (coords) {
					mark.geometry.setCoordinates(coords);
				}
			});
		}, 4).then(function () {
			clusterer.removeAll();
			clusterer.add(placemarks);
		});
	}

	function initRoot(root) {
		if (!root || root.getAttribute('data-ready') === '1') {
			return;
		}
		root.setAttribute('data-ready', '1');
		root.classList.add('is-static-fallback');
		var btn = root.querySelector('.vg-list-map__btn');
		if (btn) {
			btn.addEventListener('click', function (event) {
				event.preventDefault();
				openMap(root);
			});
		}
		if (root.getAttribute('data-auto-open') === '1') {
			root.classList.add('is-open');
			openMap(root);
		}
	}

	function initAll() {
		var nodes = document.querySelectorAll('.vg-list-map');
		for (var i = 0; i < nodes.length; i++) {
			initRoot(nodes[i]);
		}
		if (nodes.length && 'requestIdleCallback' in window) {
			window.requestIdleCallback(function () {
				loadYmaps();
			}, { timeout: 2500 });
		} else if (nodes.length) {
			window.setTimeout(loadYmaps, 1200);
		}
	}

	window.ViglingListMap = {
		initAll: initAll,
		initRoot: initRoot
	};

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', initAll);
	} else {
		initAll();
	}
})(window, document);
