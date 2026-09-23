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
		swUrl: apiBase.split('?')[0] + '?option=com_pushnotify&task=display.sw'
	};
})();
