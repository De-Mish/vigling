(function () {
	var cfg = window.VG_CRIT;
	if (!cfg || !cfg.base) {
		return;
	}
	var sep = cfg.base.indexOf('?') === -1 ? '?' : '&';
	var items = [];
	var button = null;
	var badge = null;
	var overlay = null;

	function endpoint(task) {
		return cfg.base + sep + 'task=' + task + '&format=json';
	}

	function el(tag, className, text) {
		var node = document.createElement(tag);
		if (className) {
			node.className = className;
		}
		if (text !== undefined) {
			node.textContent = text;
		}
		return node;
	}

	function seenKey() {
		return 'vg_crit_seen_' + (cfg.user || '0');
	}

	function signature() {
		return items.map(function (item) { return item.id; }).join(',');
	}

	function ensureButton() {
		if (button) {
			return;
		}
		button = el('button', 'vg-crit-btn');
		button.type = 'button';
		button.setAttribute('aria-label', 'Важные уведомления');
		button.innerHTML = '<svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="5" width="18" height="14" rx="3"/><path d="M4 7l8 6 8-6"/></svg>';
		badge = el('span', 'vg-crit-badge');
		button.appendChild(badge);
		button.addEventListener('click', openModal);
		var bar = document.querySelector('.header-mobile__bar');
		var toggle = bar ? bar.querySelector('.header-mobile__toggle') : null;
		if (bar && toggle && bar.getClientRects().length > 0) {
			button.classList.add('is-in-header');
			bar.insertBefore(button, toggle);
		} else {
			document.body.appendChild(button);
		}
	}

	function updateButton() {
		if (!items.length) {
			if (button && button.parentNode) {
				button.parentNode.removeChild(button);
			}
			button = null;
			return;
		}
		ensureButton();
		badge.textContent = String(items.length);
	}

	function closeModal() {
		if (overlay && overlay.parentNode) {
			overlay.parentNode.removeChild(overlay);
		}
		overlay = null;
		document.removeEventListener('keydown', onKey);
		document.documentElement.classList.remove('vg-crit-open');
	}

	function onKey(e) {
		if (e.key === 'Escape') {
			closeModal();
		}
	}

	function acknowledge(item, card) {
		var fd = new FormData();
		fd.append(cfg.tokenName, '1');
		fd.append('id', String(item.id));
		fetch(endpoint('display.ackCritical'), { method: 'POST', body: fd, credentials: 'same-origin' })
			.then(function (r) { return r.json(); })
			.then(function (data) {
				if (!data || !data.success) {
					return;
				}
				items = items.filter(function (it) { return it.id !== item.id; });
				if (card && card.parentNode) {
					card.parentNode.removeChild(card);
				}
				updateButton();
				if (!items.length) {
					closeModal();
				}
			});
	}

	function renderCard(item) {
		var card = el('div', 'vg-crit-card' + (item.requires_action ? ' is-action' : ''));
		card.appendChild(el('div', 'vg-crit-card__title', item.title || ''));
		card.appendChild(el('div', 'vg-crit-card__body', item.body || ''));
		var actions = el('div', 'vg-crit-card__actions');
		if (item.url) {
			var link = el('a', 'vg-crit-link', item.requires_action ? 'Ответить на запрос' : 'Открыть');
			link.href = item.url;
			actions.appendChild(link);
		}
		if (!item.requires_action) {
			var ok = el('button', 'vg-crit-ok', 'Понятно');
			ok.type = 'button';
			ok.addEventListener('click', function () { acknowledge(item, card); });
			actions.appendChild(ok);
		}
		card.appendChild(actions);
		return card;
	}

	function openModal() {
		if (overlay || !items.length) {
			return;
		}
		overlay = el('div', 'vg-crit-overlay');
		var dialog = el('div', 'vg-crit-dialog');
		dialog.setAttribute('role', 'dialog');
		dialog.setAttribute('aria-modal', 'true');
		dialog.setAttribute('aria-label', 'Важные уведомления');
		dialog.appendChild(el('div', 'vg-crit-dialog__head', 'Важные уведомления'));
		var list = el('div', 'vg-crit-dialog__list');
		items.forEach(function (item) { list.appendChild(renderCard(item)); });
		dialog.appendChild(list);
		var close = el('button', 'vg-crit-close', 'Закрыть');
		close.type = 'button';
		close.addEventListener('click', closeModal);
		dialog.appendChild(close);
		overlay.appendChild(dialog);
		overlay.addEventListener('click', function (e) {
			if (e.target === overlay) {
				closeModal();
			}
		});
		document.body.appendChild(overlay);
		document.documentElement.classList.add('vg-crit-open');
		document.addEventListener('keydown', onKey);
		try {
			sessionStorage.setItem(seenKey(), signature());
		} catch (e) {}
	}

	function start() {
		fetch(endpoint('display.getCritical'), { credentials: 'same-origin' })
			.then(function (r) { return r.json(); })
			.then(function (data) {
				if (!data || !data.success || !Array.isArray(data.items)) {
					return;
				}
				items = data.items;
				updateButton();
				if (!items.length) {
					return;
				}
				var seen = '';
				try {
					seen = sessionStorage.getItem(seenKey()) || '';
				} catch (e) {}
				if (seen !== signature()) {
					openModal();
				}
			})
			.catch(function () {});
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', start);
	} else {
		start();
	}
})();
