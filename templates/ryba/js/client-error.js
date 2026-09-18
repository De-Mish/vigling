(function () {
	var endpoint = '/templates/ryba/client-error.php';
	var sent = {};

	function redact(value) {
		var text = String(value || '');
		text = text.replace(/password[^=\s]*=[^\s&]*/gi, 'password=***');
		text = text.replace(/\+\d{1,4}[\d\s\-()]{6,}/g, '+***');
		text = text.replace(/\b\d{11,}\b/g, '***');
		return text.slice(0, 2000);
	}

	function report(payload) {
		var message = redact(payload.message || 'error');
		var url = redact(payload.url || '');
		var key = message + '|' + url;
		if (sent[key]) {
			return;
		}
		sent[key] = 1;
		var body = JSON.stringify({
			message: message,
			url: url,
			stack: redact(payload.stack || '')
		});
		try {
			if (navigator.sendBeacon) {
				navigator.sendBeacon(endpoint, body);
				return;
			}
		} catch (e) {}
		try {
			fetch(endpoint, {
				method: 'POST',
				body: body,
				credentials: 'same-origin',
				keepalive: true,
				headers: { 'Content-Type': 'text/plain' }
			}).catch(function () {});
		} catch (e2) {}
	}

	window.addEventListener('error', function (event) {
		report({
			message: event.message || 'error',
			url: location.href,
			stack: (event.error && event.error.stack) || ((event.filename || '') + ':' + (event.lineno || ''))
		});
	});

	window.addEventListener('unhandledrejection', function (event) {
		var reason = event.reason;
		report({
			message: (reason && reason.message) ? reason.message : String(reason || 'rejection'),
			url: location.href,
			stack: (reason && reason.stack) || ''
		});
	});
})();
