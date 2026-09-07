(function (window) {
	'use strict';

	var ACCEPT = '.jpg,.jpeg,.png,.webp,.gif,.heic,.heif,image/jpeg,image/png,image/webp,image/gif';
	var MAX_BYTES = 20 * 1024 * 1024;
	var MAX_EDGE = 1920;
	var QUALITY = 0.82;
	var ALLOWED_TYPE = /^(image\/jpeg|image\/png|image\/webp|image\/gif)$/i;
	var ALLOWED_NAME = /\.(jpe?g|png|webp|gif)$/i;
	var HEIC_NAME = /\.(heic|heif)$/i;

	function isHeic(file) {
		var type = String(file && file.type || '').toLowerCase();
		var name = String(file && file.name || '');
		return type === 'image/heic' || type === 'image/heif' || HEIC_NAME.test(name);
	}

	function isAllowed(file) {
		if (!file) {
			return false;
		}
		if (ALLOWED_TYPE.test(String(file.type || ''))) {
			return true;
		}
		if (ALLOWED_NAME.test(String(file.name || ''))) {
			return true;
		}
		if (isHeic(file)) {
			return 'heic';
		}
		return false;
	}

	function loadBitmap(file) {
		if (typeof window.createImageBitmap === 'function') {
			return window.createImageBitmap(file).catch(function () {
				return loadImageElement(file);
			});
		}
		return loadImageElement(file);
	}

	function loadImageElement(file) {
		return new Promise(function (resolve, reject) {
			var url = URL.createObjectURL(file);
			var img = new Image();
			img.onload = function () {
				URL.revokeObjectURL(url);
				resolve(img);
			};
			img.onerror = function () {
				URL.revokeObjectURL(url);
				reject(new Error('decode'));
			};
			img.src = url;
		});
	}

	function canvasToJpeg(source, name) {
		var width = source.width || source.videoWidth || 0;
		var height = source.height || source.videoHeight || 0;
		if (!width || !height) {
			return Promise.resolve(null);
		}
		var scale = Math.min(1, MAX_EDGE / Math.max(width, height));
		var canvas = document.createElement('canvas');
		canvas.width = Math.max(1, Math.round(width * scale));
		canvas.height = Math.max(1, Math.round(height * scale));
		var ctx = canvas.getContext('2d');
		if (!ctx) {
			return Promise.resolve(null);
		}
		ctx.fillStyle = '#fff';
		ctx.fillRect(0, 0, canvas.width, canvas.height);
		ctx.drawImage(source, 0, 0, canvas.width, canvas.height);
		return new Promise(function (resolve) {
			canvas.toBlob(function (blob) {
				if (!blob) {
					resolve(null);
					return;
				}
				var outName = String(name || 'photo').replace(/\.[^.]+$/, '') + '.jpg';
				resolve(new File([blob], outName, { type: 'image/jpeg', lastModified: Date.now() }));
			}, 'image/jpeg', QUALITY);
		});
	}

	var inflight = 0;
	var inflightWaiters = [];

	function beginInflight() {
		inflight += 1;
	}

	function endInflight() {
		inflight = Math.max(0, inflight - 1);
		if (inflight === 0 && inflightWaiters.length) {
			var waiters = inflightWaiters.slice();
			inflightWaiters = [];
			waiters.forEach(function (resolve) {
				resolve();
			});
		}
	}

	function whenReady() {
		if (inflight <= 0) {
			return Promise.resolve();
		}
		return new Promise(function (resolve) {
			inflightWaiters.push(resolve);
		});
	}

	function bindFormSubmit(form) {
		if (!form || form.getAttribute('data-vigling-image-submit') === '1') {
			return;
		}
		form.setAttribute('data-vigling-image-submit', '1');
		form.addEventListener('submit', function (e) {
			if (inflight <= 0) {
				return;
			}
			e.preventDefault();
			e.stopImmediatePropagation();
			whenReady().then(function () {
				if (typeof form.requestSubmit === 'function') {
					form.requestSubmit();
					return;
				}
				HTMLFormElement.prototype.submit.call(form);
			});
		}, true);
	}

	function prepareFile(file) {
		beginInflight();
		var allowed = isAllowed(file);
		var done = function (result) {
			endInflight();
			return result;
		};
		if (!allowed) {
			return Promise.resolve(done({
				ok: false,
				error: 'Файл «' + (file && file.name ? file.name : 'изображение') + '» не загружен. Допустимы JPEG, PNG, WebP и GIF.'
			}));
		}
		if (file.size > MAX_BYTES) {
			return Promise.resolve(done({
				ok: false,
				error: 'Файл «' + file.name + '» больше 20 МБ и не будет загружен.'
			}));
		}
		return loadBitmap(file).then(function (bitmap) {
			return canvasToJpeg(bitmap, file.name).then(function (prepared) {
				if (bitmap && typeof bitmap.close === 'function') {
					bitmap.close();
				}
				if (prepared && prepared.size > 0) {
					return { ok: true, file: prepared };
				}
				return null;
			});
		}).catch(function () {
			return null;
		}).then(function (result) {
			if (result) {
				return done(result);
			}
			if (allowed === 'heic') {
				return done({
					ok: false,
					error: 'Файл «' + file.name + '» не загружен: формат HEIC/HEIF нужно сохранить как JPEG или PNG.'
				});
			}
			return done({ ok: true, file: file });
		});
	}

	function prepareInput(input) {
		if (!input || !input.files || !input.files.length || typeof DataTransfer === 'undefined') {
			return Promise.resolve(true);
		}
		var files = Array.prototype.slice.call(input.files);
		var dt = new DataTransfer();
		var chain = Promise.resolve();
		var ok = true;
		files.forEach(function (file) {
			chain = chain.then(function () {
				return prepareFile(file).then(function (result) {
					if (!result || !result.ok) {
						ok = false;
						if (result && result.error) {
							window.alert(result.error);
						}
						return;
					}
					dt.items.add(result.file);
				});
			});
		});
		return chain.then(function () {
			input.files = dt.files;
			return ok || dt.files.length > 0;
		});
	}

	function bindInput(input) {
		if (!input || input.getAttribute('data-vigling-image') === '1' || input.getAttribute('data-vigling-manual') === '1') {
			return;
		}
		var name = String(input.getAttribute('name') || '');
		if (name.indexOf('upload_') === -1 && String(input.getAttribute('accept') || '').indexOf('image') === -1) {
			return;
		}
		input.setAttribute('data-vigling-image', '1');
		input.setAttribute('accept', ACCEPT);
		input.addEventListener('change', function () {
			prepareInput(input);
		});
	}

	window.ViglingImageUpload = {
		accept: ACCEPT,
		maxBytes: MAX_BYTES,
		isAllowed: isAllowed,
		prepareFile: prepareFile,
		prepareInput: prepareInput,
		bindInput: bindInput,
		whenReady: whenReady,
		bind: function (root) {
			root = root || document;
			if (root.querySelectorAll) {
				root.querySelectorAll('input[type="file"]').forEach(bindInput);
			}
			if (root.tagName === 'FORM') {
				bindFormSubmit(root);
			} else if (root.querySelectorAll) {
				root.querySelectorAll('form').forEach(bindFormSubmit);
			}
		}
	};

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', function () {
			window.ViglingImageUpload.bind(document);
		});
	} else {
		window.ViglingImageUpload.bind(document);
	}
})(window);
