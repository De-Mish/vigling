(function (root) {
	'use strict';

	var DEFAULT_ISO = 'RU';
	var COUNTRIES = [
		{ iso: 'RU', name: 'Россия', dial: '7', nsn: 10, groups: [3, 3, 2, 2], trunk8: true },
		{ iso: 'KZ', name: 'Казахстан', dial: '7', nsn: 10, groups: [3, 3, 2, 2], trunk8: true },
		{ iso: 'BY', name: 'Беларусь', dial: '375', nsn: 9, groups: [2, 3, 2, 2] },
		{ iso: 'KG', name: 'Кыргызстан', dial: '996', nsn: 9, groups: [3, 3, 3] },
		{ iso: 'UZ', name: 'Узбекистан', dial: '998', nsn: 9, groups: [2, 3, 2, 2] },
		{ iso: 'AM', name: 'Армения', dial: '374', nsn: 8, groups: [2, 3, 3] },
		{ iso: 'AZ', name: 'Азербайджан', dial: '994', nsn: 9, groups: [2, 3, 2, 2] },
		{ iso: 'GE', name: 'Грузия', dial: '995', nsn: 9, groups: [3, 2, 2, 2] },
		{ iso: 'MD', name: 'Молдова', dial: '373', nsn: 8, groups: [2, 3, 3] },
		{ iso: 'TJ', name: 'Таджикистан', dial: '992', nsn: 9, groups: [2, 3, 2, 2] }
	];

	var DIALS = COUNTRIES.slice().sort(function (a, b) {
		return b.dial.length - a.dial.length;
	});

	function digitsOnly(value) {
		return String(value || '').replace(/\D/g, '');
	}

	function countryByIso(iso) {
		var i;
		for (i = 0; i < COUNTRIES.length; i++) {
			if (COUNTRIES[i].iso === iso) {
				return COUNTRIES[i];
			}
		}
		return COUNTRIES[0];
	}

	function applyTrunk(digits, country) {
		if (country && country.trunk8 && digits.charAt(0) === '8') {
			return '7' + digits.slice(1);
		}
		return digits;
	}

	function plus7Country(nsn, preferredIso) {
		var first = nsn.charAt(0);
		if (first === '6' || first === '7') {
			return countryByIso('KZ');
		}
		if (first) {
			return countryByIso('RU');
		}
		if (preferredIso === 'KZ') {
			return countryByIso('KZ');
		}
		return countryByIso('RU');
	}

	function matchDial(digits) {
		var i;
		var country;
		for (i = 0; i < DIALS.length; i++) {
			country = DIALS[i];
			if (digits.indexOf(country.dial) === 0) {
				return country;
			}
		}
		return null;
	}

	function detectCountry(digits, selectedIso) {
		var selected = countryByIso(selectedIso || DEFAULT_ISO);
		var matched;
		digits = applyTrunk(digits, selected);
		matched = matchDial(digits);
		if (matched) {
			if (matched.dial === '7') {
				return plus7Country(digits.slice(1), selected.dial === '7' ? selected.iso : DEFAULT_ISO);
			}
			return matched;
		}
		return selected;
	}

	function formatPhone(country, nsn) {
		var s = '+' + country.dial;
		var firstLen;
		var first;
		var rest;
		var parts;
		var g;
		var j;
		if (!nsn) {
			return s;
		}
		firstLen = country.groups[0] || 3;
		first = nsn.slice(0, firstLen);
		s += ' (' + first;
		if (first.length < firstLen) {
			return s;
		}
		s += ')';
		rest = nsn.slice(firstLen);
		if (!rest) {
			return s;
		}
		s += ' ';
		parts = [];
		j = 0;
		for (g = 1; g < country.groups.length && j < rest.length; g++) {
			parts.push(rest.slice(j, j + country.groups[g]));
			j += country.groups[g];
		}
		if (j < rest.length) {
			parts.push(rest.slice(j));
		}
		return s + parts.join('-');
	}

	function placeholderFor(country) {
		var nsn = '';
		var i;
		for (i = 0; i < country.nsn; i++) {
			nsn += '_';
		}
		return formatPhone(country, nsn);
	}

	function parse(value, selectedIso) {
		var selected = countryByIso(selectedIso || DEFAULT_ISO);
		var digits = digitsOnly(value);
		var country;
		var nsn;
		if (!digits) {
			return {
				country: selected,
				nsn: '',
				formatted: '',
				digits: ''
			};
		}
		digits = applyTrunk(digits, selected);
		country = detectCountry(digits, selected.iso);
		if (digits.indexOf(country.dial) === 0) {
			nsn = digits.slice(country.dial.length, country.dial.length + country.nsn);
		} else {
			nsn = digits.slice(0, country.nsn);
		}
		return {
			country: country,
			nsn: nsn,
			formatted: formatPhone(country, nsn),
			digits: country.dial + nsn
		};
	}

	function prefixOf(country) {
		return '+' + country.dial;
	}

	function isBarePrefix(value, country) {
		var trimmed = String(value || '').trim();
		return trimmed === prefixOf(country) || trimmed === '+' + country.dial + ' (' || trimmed === '+' + country.dial + ' (';
	}

	function ensureWrap(input) {
		var wrap = input.closest ? input.closest('.js-phone-wrap') : null;
		var prefix;
		if (wrap) {
			return wrap;
		}
		prefix = input.closest ? input.closest('.phone-prefix-wrap') : null;
		if (prefix) {
			prefix.classList.add('js-phone-wrap');
			return prefix;
		}
		wrap = document.createElement('div');
		wrap.className = 'js-phone-wrap';
		input.parentNode.insertBefore(wrap, input);
		wrap.appendChild(input);
		return wrap;
	}

	function fillSelect(select) {
		var i;
		var opt;
		var country;
		select.innerHTML = '';
		for (i = 0; i < COUNTRIES.length; i++) {
			country = COUNTRIES[i];
			opt = document.createElement('option');
			opt.value = country.iso;
			opt.textContent = country.iso + ' +' + country.dial;
			opt.title = country.name;
			select.appendChild(opt);
		}
	}

	function applyParsed(input, select, parsed) {
		select.value = parsed.country.iso;
		input.dataset.phoneIso = parsed.country.iso;
		input.placeholder = placeholderFor(parsed.country);
		input.value = parsed.formatted;
	}

	function syncInput(input) {
		var wrap;
		var select;
		var parsed;
		if (!input) {
			return;
		}
		wrap = input.closest ? input.closest('.js-phone-wrap') : null;
		select = wrap ? wrap.querySelector('select.js-phone-country') : null;
		parsed = parse(input.value, (select && select.value) || input.dataset.phoneIso || DEFAULT_ISO);
		if (select) {
			applyParsed(input, select, parsed);
		} else {
			input.dataset.phoneIso = parsed.country.iso;
			input.placeholder = placeholderFor(parsed.country);
			input.value = parsed.formatted;
		}
	}

	function bindInput(input) {
		var wrap;
		var select;
		if (!input || input.getAttribute('data-phone-mask-bound') === '1') {
			return;
		}
		input.setAttribute('data-phone-mask-bound', '1');
		if (!input.getAttribute('autocomplete')) {
			input.setAttribute('autocomplete', 'tel');
		}
		wrap = ensureWrap(input);
		select = wrap.querySelector('select.js-phone-country');
		if (!select) {
			select = document.createElement('select');
			select.className = 'js-phone-country';
			select.setAttribute('aria-label', 'Код страны');
			select.tabIndex = 0;
			fillSelect(select);
			wrap.insertBefore(select, input);
		}
		select.value = DEFAULT_ISO;
		syncInput(input);

		select.addEventListener('change', function () {
			var country = countryByIso(select.value);
			var prev = countryByIso(input.dataset.phoneIso || DEFAULT_ISO);
			var digits = applyTrunk(digitsOnly(input.value), prev);
			var nsn;
			if (digits.indexOf(prev.dial) === 0) {
				nsn = digits.slice(prev.dial.length);
			} else {
				nsn = digits;
			}
			nsn = nsn.slice(0, country.nsn);
			input.dataset.phoneIso = country.iso;
			input.placeholder = placeholderFor(country);
			input.value = nsn ? formatPhone(country, nsn) : '';
			input.focus();
		});

		input.addEventListener('keydown', function (e) {
			var pos;
			var val;
			var prev;
			var parsed;
			if (e.key !== 'Backspace') {
				return;
			}
			pos = this.selectionStart;
			val = this.value;
			if (pos == null || pos <= 0) {
				return;
			}
			prev = val.charAt(pos - 1);
			if (prev >= '0' && prev <= '9') {
				return;
			}
			e.preventDefault();
			parsed = parse(val, select.value);
			if (!parsed.nsn) {
				this.value = '';
				return;
			}
			parsed = parse(parsed.country.dial + parsed.nsn.slice(0, -1), parsed.country.iso);
			if (!parsed.nsn) {
				this.value = '';
				this.dataset.phoneIso = parsed.country.iso;
				select.value = parsed.country.iso;
				this.placeholder = placeholderFor(parsed.country);
				return;
			}
			applyParsed(this, select, parsed);
			this.setSelectionRange(this.value.length, this.value.length);
		});

		input.addEventListener('input', function () {
			var parsed = parse(this.value, select.value);
			applyParsed(this, select, parsed);
		});

		input.addEventListener('blur', function () {
			var country = countryByIso(select.value);
			if (!digitsOnly(this.value) || isBarePrefix(this.value, country)) {
				this.value = '';
			}
		});
	}

	function bindAll(root) {
		var scope = root || (typeof document !== 'undefined' ? document : null);
		var nodes;
		var i;
		if (!scope || !scope.querySelectorAll) {
			return;
		}
		nodes = scope.querySelectorAll('.js-phone-mask, input[name="qa_phone"]');
		for (i = 0; i < nodes.length; i++) {
			bindInput(nodes[i]);
		}
	}

	var api = {
		COUNTRIES: COUNTRIES,
		DEFAULT_ISO: DEFAULT_ISO,
		countryByIso: countryByIso,
		detect: detectCountry,
		parse: parse,
		format: formatPhone,
		placeholder: placeholderFor,
		bind: bindInput,
		bindAll: bindAll,
		sync: syncInput
	};

	if (typeof module !== 'undefined' && module.exports) {
		module.exports = api;
	}
	if (typeof document !== 'undefined') {
		root.ViglingPhoneMask = api;
		if (document.readyState === 'loading') {
			document.addEventListener('DOMContentLoaded', function () {
				bindAll(document);
			});
		} else {
			bindAll(document);
		}
	}
})(typeof window !== 'undefined' ? window : this);
