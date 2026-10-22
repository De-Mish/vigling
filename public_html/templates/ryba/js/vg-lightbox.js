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
