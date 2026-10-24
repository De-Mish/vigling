(function () {
	'use strict';

	var mq = window.matchMedia && window.matchMedia('(max-width: 1020px), (display-mode: standalone), (display-mode: minimal-ui)');
	if (!mq || !mq.matches) {
		return;
	}

	var bar = document.createElement('div');
	bar.id = 'vg-progress';
	bar.setAttribute('role', 'progressbar');
	bar.setAttribute('aria-hidden', 'true');
	bar.innerHTML = '<i></i>';
	var fill = bar.firstChild;
	document.documentElement.appendChild(bar);

	var value = 0;
	var timer = 0;
	var hideTimer = 0;
	var failsafe = 0;

	function paint() {
		fill.style.transform = 'scaleX(' + value + ')';
	}

	function stopTimer() {
		if (timer) {
			clearInterval(timer);
			timer = 0;
		}
	}

	function start() {
		clearTimeout(hideTimer);
		clearTimeout(failsafe);
		stopTimer();
		bar.classList.remove('is-done');
		bar.classList.add('is-active');
		value = 0.08;
		paint();
		timer = setInterval(function () {
			// Asymptotic crawl: fast at first, never reaches 100% on its own.
			value += (0.92 - value) * 0.07;
			paint();
		}, 180);
		failsafe = setTimeout(done, 20000);
	}

	function done() {
		clearTimeout(failsafe);
		stopTimer();
		value = 1;
		paint();
		bar.classList.add('is-done');
		hideTimer = setTimeout(function () {
			bar.classList.remove('is-active', 'is-done');
			value = 0;
			paint();
		}, 420);
	}

	function isInternalNavigation(a, event) {
		if (!a || a.target && a.target !== '_self' || a.hasAttribute('download')) {
			return false;
		}
		if (event.defaultPrevented || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey || event.button > 0) {
			return false;
		}
		var href = a.getAttribute('href');
		if (!href || href.charAt(0) === '#' || /^(javascript|tel|mailto|sms|blob):/i.test(href)) {
			return false;
		}
		if (a.hasAttribute('data-toggle') || a.hasAttribute('data-bs-toggle')) {
			return false;
		}
		try {
			var url = new URL(a.href, window.location.href);
			if (url.origin !== window.location.origin) {
				return false;
			}
			if (url.pathname === window.location.pathname && url.search === window.location.search) {
				return false;
			}
		} catch (e) {
			return false;
		}
		return true;
	}

	start();
	if (document.readyState === 'complete') {
		done();
	} else {
		window.addEventListener('load', done);
	}

	document.addEventListener('click', function (event) {
		var a = event.target && event.target.closest ? event.target.closest('a[href]') : null;
		if (!isInternalNavigation(a, event)) {
			return;
		}
		setTimeout(function () {
			if (!event.defaultPrevented) {
				start();
			}
		}, 0);
	}, false);

	document.addEventListener('submit', function (event) {
		var form = event.target;
		if (!form || form.target && form.target !== '_self') {
			return;
		}
		setTimeout(function () {
			if (!event.defaultPrevented) {
				start();
			}
		}, 0);
	}, false);

	window.addEventListener('beforeunload', function () {
		if (!bar.classList.contains('is-active') || bar.classList.contains('is-done')) {
			start();
		}
	});

	window.addEventListener('pageshow', function (event) {
		if (event.persisted) {
			done();
		}
	});
})();