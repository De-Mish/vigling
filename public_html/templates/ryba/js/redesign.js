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
