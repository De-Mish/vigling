(function () {
	'use strict';

	var FILTER_KEYS = ['city', 'area', 'master_name', 'cat_id', 'service', 'tag', 'home', 'home[]', 'payment', 'payment[]', 'children', 'avail_date', 'avail_day', 'avail_time'];

	function hasActiveFilters() {
		var params = new URLSearchParams(window.location.search);
		var active = false;
		FILTER_KEYS.forEach(function (key) {
			var values = params.getAll(key);
			values.forEach(function (value) {
				if (value !== '' && value !== '0') {
					active = true;
				}
			});
		});
		return active;
	}

	function icon(path) {
		return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' + path + '</svg>';
	}

	function initFilterSheet() {
		var category = document.querySelector('.category');
		var sidebar = category ? category.querySelector('.category__masters-sidebar') : null;
		var head = category ? category.querySelector('.category__head') : null;
		if (!sidebar || !head || head.querySelector('.rd-filter-btn')) {
			return;
		}

		var trigger = document.createElement('button');
		trigger.type = 'button';
		trigger.className = 'rd-filter-btn';
		trigger.setAttribute('aria-label', 'Фильтры');
		trigger.setAttribute('aria-expanded', 'false');
		trigger.innerHTML = icon('<line x1="4" y1="6" x2="20" y2="6"/><line x1="4" y1="12" x2="20" y2="12"/><line x1="4" y1="18" x2="20" y2="18"/><circle cx="9" cy="6" r="2" fill="#fff"/><circle cx="15" cy="12" r="2" fill="#fff"/><circle cx="8" cy="18" r="2" fill="#fff"/>');
		if (hasActiveFilters()) {
			var dot = document.createElement('span');
			dot.className = 'rd-filter-dot';
			trigger.appendChild(dot);
		}
		var title = head.querySelector('h2');
		if (title) {
			title.after(trigger);
		} else {
			head.prepend(trigger);
		}

		var backdrop = document.createElement('div');
		backdrop.className = 'rd-sheet-backdrop';
		document.body.appendChild(backdrop);

		var close = document.createElement('button');
		close.type = 'button';
		close.className = 'rd-sheet-close';
		close.setAttribute('aria-label', 'Закрыть');
		close.innerHTML = icon('<line x1="5" y1="5" x2="19" y2="19"/><line x1="19" y1="5" x2="5" y2="19"/>');
		sidebar.prepend(close);

		var form = sidebar.querySelector('form.filter');
		if (form) {
			var reset = document.createElement('a');
			reset.className = 'rd-sheet-reset';
			reset.href = window.location.pathname;
			reset.textContent = 'Сбросить';
			form.appendChild(reset);
		}

		function setOpen(open) {
			document.body.classList.toggle('rd-filter-open', open);
			trigger.setAttribute('aria-expanded', open ? 'true' : 'false');
		}

		trigger.addEventListener('click', function () {
			setOpen(true);
		});
		backdrop.addEventListener('click', function () {
			setOpen(false);
		});
		close.addEventListener('click', function () {
			setOpen(false);
		});
		document.addEventListener('keydown', function (event) {
			if (event.key === 'Escape') {
				setOpen(false);
			}
		});
		window.addEventListener('resize', function () {
			if (window.innerWidth > 1020) {
				setOpen(false);
			}
		});
	}

	function pad(n) {
		return n < 10 ? '0' + n : String(n);
	}

	var WEEKDAYS = ['вс', 'пн', 'вт', 'ср', 'чт', 'пт', 'сб'];

	function dateTimeHtml(date) {
		var label = WEEKDAYS[date.getDay()] + ', ' + pad(date.getDate()) + '.' + pad(date.getMonth() + 1);
		if (date.getFullYear() !== new Date().getFullYear()) {
			label += '.' + date.getFullYear();
		}
		return '<span class="rd-dt"><span class="rd-dt__d">' + label + '</span><span class="rd-dt__t">' + pad(date.getHours()) + ':' + pad(date.getMinutes()) + '</span></span>';
	}

	window.rdFormatDateTime = function (isoOrDate) {
		var date = isoOrDate instanceof Date ? isoOrDate : new Date(isoOrDate);
		return isNaN(date.getTime()) ? '' : dateTimeHtml(date);
	};

	function formatTimes() {
		document.querySelectorAll('[data-rd-time], .lk-time-utc[data-time-utc]').forEach(function (el) {
			var date = new Date(el.getAttribute('data-rd-time') || el.getAttribute('data-time-utc'));
			if (isNaN(date.getTime())) {
				return;
			}
			el.innerHTML = dateTimeHtml(date);
		});
	}

	function initStickyFilter() {
		var panel = document.querySelector('.category__masters-sidebar');
		if (!panel || !window.matchMedia) {
			return;
		}
		var wide = window.matchMedia('(min-width: 1021px)');
		function update() {
			if (!wide.matches) {
				panel.style.removeProperty('top');
				return;
			}
			var head = 96;
			var gap = 16;
			var height = panel.offsetHeight;
			var top = height + head + gap <= window.innerHeight ? head : window.innerHeight - height - gap;
			panel.style.setProperty('top', top + 'px', 'important');
		}
		update();
		window.addEventListener('resize', update);
		if (window.ResizeObserver) {
			new ResizeObserver(update).observe(panel);
		}
	}

	function init() {
		initFilterSheet();
		initStickyFilter();
		formatTimes();
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();

/* Vigling lightbox: opens image links marked with data-fancybox="group" and
   keeps jQuery.fancybox.open()/close() working for older inline code. */
(function () {
	'use strict';

	var root = null;
	var stage = null;
	var captionEl = null;
	var prevBtn = null;
	var nextBtn = null;
	var items = [];
	var index = 0;
	var lastFocus = null;

	function build() {
		if (root) {
			return;
		}
		root = document.createElement('div');
		root.className = 'vg-lb';
		root.hidden = true;
		root.setAttribute('role', 'dialog');
		root.setAttribute('aria-modal', 'true');
		root.innerHTML =
			'<div class="vg-lb__backdrop" data-vg-lb-close></div>' +
			'<button type="button" class="vg-lb__close" data-vg-lb-close aria-label="Закрыть">&times;</button>' +
			'<button type="button" class="vg-lb__nav vg-lb__nav--prev" aria-label="Предыдущее">&lsaquo;</button>' +
			'<button type="button" class="vg-lb__nav vg-lb__nav--next" aria-label="Следующее">&rsaquo;</button>' +
			'<div class="vg-lb__stage"></div>' +
			'<div class="vg-lb__caption"></div>';
		document.body.appendChild(root);
		stage = root.querySelector('.vg-lb__stage');
		captionEl = root.querySelector('.vg-lb__caption');
		prevBtn = root.querySelector('.vg-lb__nav--prev');
		nextBtn = root.querySelector('.vg-lb__nav--next');

		root.addEventListener('click', function (event) {
			if (event.target.closest('[data-vg-lb-close]')) {
				close();
			}
		});
		prevBtn.addEventListener('click', function () { show(index - 1); });
		nextBtn.addEventListener('click', function () { show(index + 1); });
		document.addEventListener('keydown', function (event) {
			if (root.hidden) {
				return;
			}
			if (event.key === 'Escape') {
				close();
			} else if (event.key === 'ArrowLeft') {
				show(index - 1);
			} else if (event.key === 'ArrowRight') {
				show(index + 1);
			}
		});
	}

	function show(next) {
		if (!items.length) {
			return;
		}
		index = (next + items.length) % items.length;
		var item = items[index];
		stage.textContent = '';
		if (item.html !== undefined) {
			var box = document.createElement('div');
			box.className = 'vg-lb__html';
			box.innerHTML = item.html;
			stage.appendChild(box);
		} else {
			var img = document.createElement('img');
			img.src = item.src;
			img.alt = item.caption || '';
			stage.appendChild(img);
		}
		captionEl.textContent = item.caption || '';
		captionEl.hidden = !item.caption;
		var multiple = items.length > 1;
		prevBtn.hidden = !multiple;
		nextBtn.hidden = !multiple;
	}

	function open(list, start) {
		build();
		items = list;
		lastFocus = document.activeElement;
		root.hidden = false;
		document.documentElement.classList.add('vg-lb-open');
		show(start || 0);
		root.querySelector('.vg-lb__close').focus();
	}

	function close() {
		if (!root || root.hidden) {
			return;
		}
		root.hidden = true;
		stage.textContent = '';
		document.documentElement.classList.remove('vg-lb-open');
		if (lastFocus && lastFocus.focus) {
			lastFocus.focus();
		}
	}

	document.addEventListener('click', function (event) {
		var link = event.target.closest ? event.target.closest('a[data-fancybox]') : null;
		if (!link) {
			return;
		}
		event.preventDefault();
		var group = link.getAttribute('data-fancybox');
		var nodes = Array.prototype.slice.call(
			document.querySelectorAll('a[data-fancybox]')
		).filter(function (node) {
			return node.getAttribute('data-fancybox') === group;
		});
		var list = nodes.map(function (node) {
			return { src: node.getAttribute('href'), caption: node.getAttribute('data-caption') || '' };
		});
		open(list, Math.max(0, nodes.indexOf(link)));
	});

	function attachLegacyApi() {
		if (!window.jQuery) {
			return;
		}
		window.jQuery.fancybox = {
			open: function (html) {
				open([{ html: String(html) }], 0);
			},
			close: close
		};
	}

	attachLegacyApi();
	document.addEventListener('DOMContentLoaded', attachLegacyApi);
})();
