(function () {
	'use strict';

	var apiBase = typeof window.PUSHNOTIFY_BASE !== 'undefined' ? window.PUSHNOTIFY_BASE : '';
	var tokenName = typeof window.PUSHNOTIFY_TOKEN_NAME !== 'undefined' ? window.PUSHNOTIFY_TOKEN_NAME : '';
	var tokenValue = typeof window.PUSHNOTIFY_TOKEN_VALUE !== 'undefined' ? window.PUSHNOTIFY_TOKEN_VALUE : '1';

	function post(url, data, cb) {
		var form = new FormData();
		form.append(tokenName, tokenValue);
		Object.keys(data || {}).forEach(function (k) {
			form.append(k, data[k]);
		});
		fetch(url, {
			method: 'POST',
			body: form,
			credentials: 'same-origin'
		}).then(function (r) { return r.json(); }).then(cb).catch(function (e) {
			cb({ success: false, message: e && e.message ? e.message : 'Ошибка сети' });
		});
	}

	function get(url, cb) {
		var u = url + (url.indexOf('?') === -1 ? '?' : '&') + encodeURIComponent(tokenName) + '=' + encodeURIComponent(tokenValue);
		fetch(u, { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(cb).catch(function (e) {
			cb({ success: false, message: e && e.message ? e.message : 'Ошибка сети' });
		});
	}

	var sep = apiBase.indexOf('?') === -1 ? '?' : '&';
	window.PushNotify = {
		subscribe: function (fcmToken, deviceType, browser, callback) {
			post(apiBase + sep + 'task=display.subscribe&format=json', {
				token: fcmToken,
				device_type: deviceType || 'desktop',
				browser: browser || ''
			}, callback || function () {});
		},
		unsubscribe: function (fcmToken, callback) {
			post(apiBase + sep + 'task=display.unsubscribe&format=json', { token: fcmToken }, callback || function () {});
		},
		getPreferences: function (callback) {
			get(apiBase + sep + 'task=display.getPreferences&format=json', callback || function () {});
		},
		updatePreferences: function (enabled, callback) {
			post(apiBase + sep + 'task=display.updatePreferences&format=json', {
				notifications_enabled: enabled ? 1 : 0
			}, callback || function () {});
		},
		bindForeground: function (messaging) {
			if (!messaging || typeof messaging.onMessage !== 'function' || window.__viglingPushBound) return;
			window.__viglingPushBound = true;
			messaging.onMessage(function (payload) {
				var n = payload && payload.notification ? payload.notification : {};
				var d = payload && payload.data ? payload.data : {};
				var title = n.title || d.title || 'Уведомление';
				var body = n.body || d.body || '';
				var tag = d.notification_tag || '';
				var url = d.url || (window.location.origin + '/lk');
				var options = { body: body, silent: false, data: { url: url } };
				if (tag) {
					options.tag = tag;
					options.renotify = true;
				}
				if (navigator.serviceWorker && navigator.serviceWorker.ready) {
					navigator.serviceWorker.ready.then(function (reg) {
						return reg.showNotification(title, options);
					}).catch(function () {});
					return;
				}
				try { new Notification(title, options); } catch (e) {}
			});
		},
		swUrl: apiBase.split('?')[0] + '?option=com_pushnotify&task=display.sw'
	};
})();
