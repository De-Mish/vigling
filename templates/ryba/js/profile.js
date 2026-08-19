jQuery(document).ready(function ($) {
	let val = '';
	let files;
	jQuery('input[type=file]').on('change', function () {
		files = this.files;
	});
	$('#jform_vyberite_spetsialnos input:checked').parent().addClass('active');
	var empty_txt = "Выберите специальность, чтобы  добавить услугу";
	//$('#jform_vyberite_usl').html(empty_txt);

	$('head').append('<link href="/templates/ryba/css/chosen.min.css" rel="stylesheet" />');

	$('#jform_vyberite_spetsialnos>label>input').change(function (e) {
		if (!e.target.checked) {
			$(this).parent().removeClass('active');
			if (!$('#jform_vyberite_spetsialnos>label.active').length)
				$('#jform_vyberite_usl').html(empty_txt);
		} else {
			if (!$('#jform_vyberite_spetsialnos>label.active').length)
				$('#jform_vyberite_usl').empty();
			$(this).parent().addClass('active');
			if (!$('#jform_vyberite_usl').find('label[data-id="' + parseInt($(e.target).val()) + '"]').length) {
				var item = $('<label class="checkbox type_master_closed" />').html($(e.target).parent().text());
				item.attr('data-id', parseInt($(e.target).val()));
				item.append('<b></b>');
				$('#jform_vyberite_usl').append(item);
				$("#jform_vyberite_usl>label").sort(asc_sort).appendTo('#jform_vyberite_usl');
			}
		}
		updatePrices();
	});
	$('#jform_vyberite_usl').on('click', 'label', function (e) {
		var el = $(e.target);

		if (el.is('b'))
			el = el.parent();
		if (el.is('label')) {
			if (!el.hasClass('type_master_open')) {
  el.addClass('type_master_open');
  if (!el.find('.plus_key').length && el.hasClass('type_master_closed')) {
    el.append('<div class="flex_wrap"><div class="plus_key"></div></div>');
  }
} else {
  el.removeClass('type_master_open');
}
return true;
		}
		e.preventDefault();
		event.stopImmediatePropagation();

		if (el.is('.plus_key')) {
			var cat_id = parseInt($(el).closest('label').data('id'));
			$.getJSON('?option=com_jsn&format=json&task=get_articles&cat_id=' + cat_id, function (data) {
				var ed = $('<div class="service__wrap"><p class="service__item"></p></div>');
				ed.find('p').append('<select name="service_id[]" data-placeholder="Выберите услугу..."><option value=""></option></select>');

				var sel = $('<select />').attr('name', 'time[]');
				for (var i = 1; i <= 12; i++) {
					var t = i * 15;
					sel.append('<option>' + t + '</option>');
				}

				ed.find('p').append('<span class="time"><label>Время:</label>' + sel.clone().wrap('<div/>').parent().html() + '&nbsp;мин.</span>');

				sel.attr('name', 'time2[]');
				ed.find('p').append('<span class="time2"><label>Перерыв:</label>' + sel.clone().wrap('<div/>').parent().html() + '&nbsp;мин.</span>');
				ed.find('p').append('<span class="price"><label>Стоимость:</label><input type="text" name="price[]" value="" data-price="0"/> <i style="display:none;">руб.</i></span><select class="valuta_select" data-select="new_option_select" style="width: 70px;"><option value="RUB">RUB</option><option value="KZT">KZT</option><option value="UZS">UZS</option><option value="KGS">KGS</option><option value="TJS">TJS</option><option value="BYN">BYN</option><option value="AMD">AMD</option></select><i></i>');
				$('#jform_vyberite_usl').on('input', 'input[name="price[]"]', function() {
  $(this).attr('data-price', $(this).val());
});
				ed.insertBefore(el);
				$.each(data.data, function (idx, item) {
					var list_item = $('<optgroup label="' + item.title + '"></optgroup>');
					if (item.tags === undefined || !item.tags.itemTags.length)
						list_item.append('<option value="' + item.id + '">' + item.title + '</option>');
					else if (item.tags.itemTags !== undefined)
						$.each(item.tags.itemTags, function (idx2, tag) {
							list_item.append('<option value="' + item.id + '-' + tag.id + '">' + item.title + ' / ' + tag.title + '</option>');
						});
					ed.find('select[name="service_id[]"]').append(list_item);
				});
				ed.find('select[name="service_id[]"]').chosen({
					disable_search_threshold: 10,
					no_results_text: "Услуга не найдена:"
				});
			});
		} else if (el.is('i')) {
			el.parent().parent().remove();
			updatePrices();
		} else if (el.is('button')) {
			if (!parseInt(el.parent().find('.price input').val())) {
				el.parent().find('.price input').addClass('error');
				return false;
			}

			var time = 0;
			el.parent().find('.price').each(function (idx, elm) {
				var m = $(elm.lastChild).text();
				var inp = $(elm).find('input');
				inp.attr('type', 'hidden');
				$(elm.lastChild).remove();
				console.log(elm);
				$(elm).append(inp.attr('data-price') + ' ' + m);
				//.insertAfter($(elm).find('label'));
				//inp.parent().css('font-weight','bold');
			});

			var s_id = el.parent().find('select[name="time[]"]').val();
			el.parent().find('select[name="time[]"]').after(s_id);
			el.parent().find('select[name="time[]"]').remove();
			el.parent().find('.time').append('<input type="hidden" name="time[]" value="' + s_id + '" />');

			var s_id = el.parent().find('select[name="time2[]"]').val();
			el.parent().find('select[name="time2[]"]').after(s_id);
			el.parent().find('select[name="time2[]"]').remove();
			el.parent().find('.time2').append('<input type="hidden" name="time2[]" value="' + s_id + '" />');

			if (el.parent().find('select[name="service_id[]"]').length) {
				var t = el.parent().find('.chosen-single>span').html();
				el.parent().prepend('<a class="hdr" href="#">' + t + '</a>');
				el.parent().find('.chosen-container').remove();
				var s_id = el.parent().find('select[name="service_id[]"]').val();
				el.parent().find('select[name="service_id[]"]').remove();
				el.parent().append('<input type="hidden" name="service_id[]" value="' + s_id + '" />');
			}
			el.remove();
			updatePrices();
		} else if (el.is('a.hdr')) {
			el.parent().find('.time,.time2,.price').each(function (idx, elm) {
				var m = $(elm).text().split(' ').pop();
				$(elm.lastChild).remove();
				$(elm).find('input').attr('type', 'text');
				$(elm).append(m);

			});
			el.parent().append('<button>Сохранить</button>');
			el.parent().replaceWith('<p class="service__item">' + el.parent().html() + '<p>');
		}
		return false;
	});

	$('#jform_vyberite_usl').on('change', 'select[name="service_id[]"]', function (e, el) {
		var pb = $(this).parent();
		if (!pb.find('button').length)
			pb.append('<button>Добавить</button>');
	});

	$('img.img_avatar').attr('style', '');
	$('#jform_upload_avatar').on('change', function (e) {
		var pb = $(this).parent();
		var src = URL.createObjectURL(e.target.files[0]);
		console.log(src);
		pb.find('img').load(function (e) {
			URL.revokeObjectURL(src)
		});
		pb.find('img').css('opacity', 0);
		pb.attr('style', 'background-image: url("' + src + '");');
		jQuery('#jsn-form #jform_upload_avatar, #jsn-form #jform_avatar').remove();
		var is_load = $(this).next().clone().val('true');
		jQuery('#jsn-form').append($(this).clone().hide()).append(is_load);
	});
	$('#jform_avatar').on('change', function (e) {
		jQuery('#jsn-form #jform_upload_avatar, #jsn-form #jform_avatar').remove();
		jQuery('#jsn-form').append($(this).clone());
	});

	$('#jsn_portfolio .control-group input').attr('name', 'jform[upload_portfolio_field][]');
	$('#jsn_portfolio .control-group').on('change', 'input[type=file]', function (e) {
		var pb = $(this).parent();
		if (!pb.hasClass('preview'))
			pb.parent().append('<div class="controls">' + pb.html() + '</div>');
		var src = URL.createObjectURL(e.target.files[0]);
		pb.find('img').load(function (e) {
			URL.revokeObjectURL(src)
		});
		pb.attr('style', 'background-image: url("' + src + '");');
		pb.addClass('preview');
		pb.find('input').removeAttr('readonly');
		pb.append('<i></i>');
	});
	$('#jsn_portfolio .control-group').on('click', 'i', function (e) {
		$(e.target).parent().remove();
	});

	$('.calendar__table-item').on('click', 'li', function (e) {
		e.preventDefault();
		el = $(e.target).is('a') ? $(e.target) : $(e.target).find('a');
		el.toggleClass('empty');

		var work_from = [], work_to = [];
		$('.calendar__table-item').each(function (idx, el) {
			var workdays = $(el).find('a:not(.empty)').length;
			$('.calendar__table-head li').eq(idx).find('input').prop('checked', workdays);
			if (workdays) {
				var sel = $(el).find('a:not(.empty)');
				console.log(sel.first());
				work_from.push(sel.first().data('time'));
				work_to.push(sel.last().data('time') + 0.25);

			}
		});

		console.log(JSON.stringify(work_from));
		$('#jform_work_from').val(JSON.stringify(work_from));
		$('#jform_work_to').val(JSON.stringify(work_to));

	});

	var clicked = false, clickY;
	$('.table__calendar-left').mousedown(function (e) {
		clickY = e.pageY + $('.calendar__system').scrollTop();
		clicked = true;
	});
	$('.table__calendar-left').mouseup(function () {
		clicked = false;
	});
	$('.table__calendar-left').mouseleave(function () {
		clicked = false;
	});
	$('.table__calendar-left').mousemove(function (e) {
		if (clicked) {
			$('.calendar__system').scrollTop(clickY - e.pageY);
		}
	});

	$('.calendar__table-item').each(function (idx, el) {
		var start = $(el).find('a:not(.empty)').first().parent();
		//console.log(start.position().top+$('.calendar__table-head')[0].clientHeight);

		//var time = Math.ceil(start.attr('data-time'));
		if (start.index() > 0)
			$('.calendar__system').scrollTop(start.offset().top + $('.calendar__table-head')[0].clientHeight);
		//	$('.calendar__system').attr('data-min', Math.min(time, parseInt($('.calendar__system').attr('data-min'))));

	});


	function ensureViglingPayloadField(name) {
		var form = document.profileForm || $('form[name="profileForm"]')[0] || $('form').first()[0];
		if (!form) return null;
		var selector = 'input[name="jform[' + name + ']"]';
		var el = form.querySelector(selector);
		if (!el) {
			el = document.createElement('input');
			el.type = 'hidden';
			el.name = 'jform[' + name + ']';
			form.appendChild(el);
		}
		return $(el);
	}

	function writeViglingPayload(fieldName, items) {
		var field = ensureViglingPayloadField(fieldName);
		if (!field) return;
		field.val(JSON.stringify({
			version: 1,
			items: items || []
		}));
	}

	function updatePrices() {
		var payloadItems = [];
		var i = $('#jform_vyberite_usl>label .service__item');
		i.each(function (idx, el) {
			var ii = $(el).find('input');
			if (ii.length == 4) {
				var tm = parseInt(ii.first().val());
				var tm = tm + '.' + parseInt(0 + ii.eq(1).val());
				var tmp = ii.last().val().split('-');
				var uid = parseInt(tmp[0]);
				var tag_id = 0;
				if (tmp.length > 1)
					tag_id = parseInt(tmp[1]);

				var priceValue = $(el).find('[data-price]').data('price') || $(el).find('input[name="price[]"]').val();
				var serviceRaw = ii.last().val();
				payloadItems.push({
					cat_id: String(uid),
					service_raw: serviceRaw,
					price: parseInt(priceValue),
					duration: String(tm)
				});
			}
		});
		writeViglingPayload('vigling_services_payload', payloadItems);
	}

	function asc_sort(a, b) {
		return ($(b).text()) < ($(a).text()) ? 1 : -1;
	}

///STOCK PRICES
	$('#jform_vyberite_spetsialnos>label>input').change(function (e) {
		if (!e.target.checked) {
			$(this).parent().removeClass('active');
			if (!$('#jform_vyberite_spetsialnos>label.active').length)
				$('#jform_stocks_servis').html(empty_txt);
		} else {
			if (!$('#jform_vyberite_spetsialnos>label.active').length)
				$('#jform_stocks_servis').empty();
			$(this).parent().addClass('active');
			if (!$('#jform_stocks_servis').find('label[data-id="' + parseInt($(e.target).val()) + '"]').length) {
				var item = $('<label class="checkbox type_master_closed" />').html($(e.target).parent().text());
				item.attr('data-id', parseInt($(e.target).val()));
				item.append('<b></b>');
				$('#jform_stocks_servis').append(item);
				$("#jform_stocks_servis>label").sort(asc_sort).appendTo('#jform_stocks_servis');
			}
		}
		updateStocks();
	});
	$('#jform_stocks_servis').on('click', 'label', function (e) {
		var el = $(e.target);

		if (el.is('b'))
			el = el.parent();
		if (el.is('label')) {
			if (!el.hasClass('type_master_open')) {
  el.addClass('type_master_open');
  if (!el.find('.stock_key').length && el.hasClass('type_master_closed')) {
    el.append('<div class="flex_wrap"><div class="stock_key"></div></div>');
  }
} else {
  el.removeClass('type_master_open');
}
return true;
		}
		e.preventDefault();
		event.stopImmediatePropagation();

		if (el.is('.stock_key')) {
			var cat_id = parseInt($(el).closest('label').data('id'));
			$.getJSON('?option=com_jsn&format=json&task=get_articles&cat_id=' + cat_id, function (data) {
				var ed = $('<div class="service__wrap"><p class="service__item"></p></div>');
				ed.find('p').append('<select name="service_id[]" data-placeholder="Выберите услугу..."><option value=""></option></select>');

				var sel = $('<select />').attr('name', 'time[]');
				for (var i = 1; i <= 12; i++) {
					var t = i * 15;
					sel.append('<option>' + t + '</option>');
				}
				ed.find('p').append('<span class="time"><label>Время:</label>' + sel.clone().wrap('<div/>').parent().html() + '&nbsp;мин.</span>');
				sel.attr('name', 'time2[]');

				ed.find('p').append('<span class="time2"><label>Перерыв:</label>' + sel.clone().wrap('<div/>').parent().html() + '&nbsp;мин.</span></br>');
				ed.find('p').append('<span class="stock_price"><label>Акционная стоимость:</label><input type="text" name="stock_price[]" value=""/> <i style="display:none;">руб.</i></span><select class="valuta_select_akzii" data-select="new_option_select" data-akziionnaya_stoimost_select="1" style="width: 70px;"><option value="RUB">RUB</option><option value="KZT">KZT</option><option value="UZS">UZS</option><option value="KGS">KGS</option><option value="TJS">TJS</option><option value="BYN">BYN</option><option value="AMD">AMD</option></select> <i></i></br>');
				ed.find('p').append('<span class="old_price"><label>Цена без скидки:</label><input type="text" name="old_price[]" value=""/> <i style="display:none;">руб.</i></span> <select class="valuta_select_akzii" data-select="new_option_select" style="width: 70px;"><option value="RUB">RUB</option><option value="KZT">KZT</option><option value="UZS">UZS</option><option value="KGS">KGS</option><option value="TJS">TJS</option><option value="BYN">BYN</option><option value="AMD">AMD</option></select> <i></i></br>');
				ed.find('p').append('<span class="about_stock"><label>Условия акции:</label><input type="text" size="280" name="about_stock[]" value=""></span><i></i><br>');
				ed.find('p').append('<span class="count_stock"><label>Всего предложений:</label><input  type="number" size="280" name="count_stock[]" value=""></span><br>');
				ed.insertBefore(el);
				$.each(data.data, function (idx, item) {
					var list_item = $('<optgroup label="' + item.title + '"></optgroup>');
					if (item.tags === undefined || !item.tags.itemTags.length)
						list_item.append('<option value="' + item.id + '">' + item.title + '</option>');
					else if (item.tags.itemTags !== undefined)
						$.each(item.tags.itemTags, function (idx2, tag) {
							list_item.append('<option value="' + item.id + '-' + tag.id + '">' + item.title + ' / ' + tag.title + '</option>');
						});
					ed.find('select[name="service_id[]"]').append(list_item);
				});
				ed.find('select[name="service_id[]"]').chosen({
					disable_search_threshold: 10,
					no_results_text: "Услуга не найдена:"
				});
			});
		} else if (el.is('i')) {
			el.parent().parent().remove();
			updateStocks();
		} else if (el.is('button')) {
			if (!parseInt(el.parent().find('.stock_price input').val())) {
				el.parent().find('.stock_price input').addClass('error');
				return false;
			}
			if (!parseInt(el.parent().find('.old_price input').val())) {
				el.parent().find('.old_price input').addClass('error');
				return false;
			}
			if (!(el.parent().find('.about_stock input').val())) {
				el.parent().find('.about_stock input').addClass('error');
				return false;
			}

			var time = 0;
			el.parent().find('.stock_price,.old_price').each(function (idx, elm) {
				var m = $(elm.lastChild).text();
				var inp = $(elm).find('input');
				inp.attr('type', 'hidden');
				$(elm.lastChild).remove();
				console.log(elm);
				$(elm).append(inp.attr('data-price') + ' ' + m);
				//.insertAfter($(elm).find('label'));
				//inp.parent().css('font-weight','bold');
			});

			var s_id = el.parent().find('select[name="time[]"]').val();
			el.parent().find('select[name="time[]"]').after(s_id);
			el.parent().find('select[name="time[]"]').remove();
			el.parent().find('.time').append('<input type="hidden" name="time[]" value="' + s_id + '" />');

			var s_id = el.parent().find('select[name="time2[]"]').val();
			el.parent().find('select[name="time2[]"]').after(s_id);
			el.parent().find('select[name="time2[]"]').remove();
			el.parent().find('.time2').append('<input type="hidden" name="time2[]" value="' + s_id + '" />');

			if (el.parent().find('select[name="service_id[]"]').length) {
				var t = el.parent().find('.chosen-single>span').html();
				el.parent().prepend('<a class="hdr" href="#">' + t + '</a>');
				el.parent().find('.chosen-container').remove();
				var s_id = el.parent().find('select[name="service_id[]"]').val();
				el.parent().find('select[name="service_id[]"]').remove();
				el.parent().append('<input type="hidden" name="service_id[]" value="' + s_id + '" />');
			}
			el.remove();
			updateStocks();
		} else if (el.is('a.hdr')) {
			el.parent().find('.time,.time2,.stock_price,.old_price,.about_stock,.count_stock').each(function (idx, elm) {
				var m = $(elm).text().split(' ').pop();
				$(elm.lastChild).remove();
				$(elm).find('input').attr('type', 'text');
				$(elm).append(m);

			});
			el.parent().append('<button>Сохранить</button>');
			el.parent().replaceWith('<p class="service__item">' + el.parent().html() + '<p>');
		}
		return false;
	});

	$('#jform_stocks_servis').on('change', 'select[name="service_id[]"]', function (e, el) {
		var pb = $(this).parent();
		if (!pb.find('button').length)
			pb.append('<button>Добавить</button>');
	});

	function updateStocks() {
		var payloadItems = [];
		var i = $('#jform_stocks_servis>label .service__item');
		//console.log(i);
		i.each(function (idx, el) {
			var ii = $(el).find('input');
			//console.log(ii);
			if (ii.length == 7) {
				var tm = parseInt(ii.first().val());
				var tm = tm + '.' + parseInt(0 + ii.eq(1).val());
				var tmp = ii.last().val().split('-');
				var uid = parseInt(tmp[0]);
				var tag_id = 0;
				var old_price = parseInt(0 + ii.eq(3).val());
				var about_stock = ii.eq(4).val();
				var count_stock = ii.eq(5).val();
				if (tmp.length > 1)
					tag_id = parseInt(tmp[1]);
				var stockPrice = parseInt(ii.eq(2).val());
				var serviceRaw = ii.last().val();
				payloadItems.push({
					cat_id: String(uid),
					service_raw: serviceRaw,
					price: stockPrice,
					duration: String(tm),
					old_price: old_price,
					about_stock: about_stock,
					count_stock: count_stock
				});
			}
		});
		writeViglingPayload('vigling_stock_services_payload', payloadItems);
	}
	window.viglingRebuildServicePayloads = function () {
		updatePrices();
		updateStocks();
	};
});

///END STOCK PRICES
function saveProfile() {
	var f = document.profileForm;
	if (document.formvalidator.isValid(f)) {
		if (window.viglingRebuildServicePayloads) {
			window.viglingRebuildServicePayloads();
		}
		// Legacy JSN fields are no longer source-of-truth; prevent accidental updates.
		jQuery('#jform_prices, #jform_stock_prices').prop('disabled', true);

		// сохранение валюты

		var valuta_str = "";

		jQuery('select.valuta_select').each(function (ind, element) {

			var cur_valuta = element.value;

			var is_new_option_select = element.getAttribute("data-select");

			if (is_new_option_select) {

				if (element.previousElementSibling.previousElementSibling.previousElementSibling.previousElementSibling.children[0]) {

					var cur_name_of_adv = element.previousElementSibling.previousElementSibling.previousElementSibling.previousElementSibling.children[0].children[0].textContent;

				} else {

					var cur_name_of_adv = element.previousElementSibling.previousElementSibling.previousElementSibling.previousElementSibling.textContent;

				}

			} else {

				var cur_name_of_adv = element.previousElementSibling.previousElementSibling.previousElementSibling.previousElementSibling.textContent;
			}

			if (!valuta_str) {

				valuta_str = cur_name_of_adv + "," + cur_valuta;
			} else {

				valuta_str = valuta_str + "///" + cur_name_of_adv + "," + cur_valuta;
			}

		});

		var profile_user = jQuery('.jsn-p-title h3')[0].textContent;
		profile_user = profile_user.trim();

		var ind = profile_user.indexOf(" ");

		var name_user = profile_user.substring(0, ind);
		var surname_user = profile_user.substring(ind + 1);

		var data = {};

		data.save_valuta = 1;
		data.valuta = valuta_str;
		data.name_user = name_user;
		data.surname_user = surname_user;

		jQuery.post('/templates/ryba/valuta_save.php', data, function (data) {
			console.log('max');
			console.log(data);
		});

		// сохранение акционной валюты

		var valuta_data = [];

		jQuery('select.valuta_select_akzii').each(function (ind, element) {

			var cur_valuta = element.value;

			var is_akzionnaya_stoimost_select = element.getAttribute("data-akziionnaya_stoimost_select");

			if (is_akzionnaya_stoimost_select) {

				var is_new_option_select = element.getAttribute("data-select");

				if (is_new_option_select) {

					if (element.previousElementSibling.previousElementSibling.previousElementSibling.previousElementSibling.previousElementSibling.children[0]) {

						var cur_name_of_adv = element.previousElementSibling.previousElementSibling.previousElementSibling.previousElementSibling.previousElementSibling.children[0].children[0].textContent;

					} else {

						var cur_name_of_adv = element.previousElementSibling.previousElementSibling.previousElementSibling.previousElementSibling.previousElementSibling.textContent;
					}

				} else {

					var cur_name_of_adv = element.previousElementSibling.previousElementSibling.previousElementSibling.previousElementSibling.previousElementSibling.textContent;
				}

			} else {

				var is_new_option_select = element.getAttribute("data-select");

				if (is_new_option_select) {

					if (element.previousElementSibling.previousElementSibling.previousElementSibling.previousElementSibling.previousElementSibling.previousElementSibling.previousElementSibling.previousElementSibling.previousElementSibling.children[0]) {

						var cur_name_of_adv = element.previousElementSibling.previousElementSibling.previousElementSibling.previousElementSibling.previousElementSibling.previousElementSibling.previousElementSibling.previousElementSibling.previousElementSibling.children[0].children[0].textContent;

					} else {

						var cur_name_of_adv = element.previousElementSibling.previousElementSibling.previousElementSibling.previousElementSibling.previousElementSibling.previousElementSibling.previousElementSibling.previousElementSibling.previousElementSibling.textContent;
					}

				} else {

					var cur_name_of_adv = element.previousElementSibling.previousElementSibling.previousElementSibling.previousElementSibling.previousElementSibling.previousElementSibling.previousElementSibling.previousElementSibling.textContent;
				}

			}

			var cur_name_of_adv_without_space = cur_name_of_adv.replace(/ /gi, "");

			if (!valuta_data[cur_name_of_adv_without_space]) {

				valuta_data[cur_name_of_adv_without_space] = [cur_name_of_adv, cur_valuta];
			} else {

				valuta_data[cur_name_of_adv_without_space].push(cur_valuta)
			}

		});

		var valuta_str = "";
		console.log('valuta_date');
		console.log(valuta_data);

		for (key in valuta_data) {

			if (valuta_str) {

				valuta_str = valuta_str + "///";
			}

			valuta_str = valuta_str + valuta_data[key][0] + "," + valuta_data[key][1] + "," + valuta_data[key][2];
		}

		var profile_user = jQuery('.jsn-p-title h3')[0].textContent;
		profile_user = profile_user.trim();

		var ind = profile_user.indexOf(" ");

		var name_user = profile_user.substring(0, ind);
		var surname_user = profile_user.substring(ind + 1);

		var data = {};

		data.save_valuta_akzii = 1;
		data.valuta = valuta_str;
		data.name_user = name_user;
		data.surname_user = surname_user;

		jQuery.post('/templates/ryba/valuta_save.php', data, function (data) {

			console.log(data);
		});

		jQuery('select.valuta_select_akzii').each(function (ind, element) {

			element.parentElement.removeChild(element);

		});
		//// max

		//// #
		f.submit();
	} else {
		var msg = new Array();
		jQuery(f).find('.required.invalid').each(function (idx, el) {

			var name = jQuery(el).attr('placeholder') ? jQuery(el).attr('placeholder') : el.id;

			if (jQuery(el).closest('dd').length)
				name = jQuery(el).closest('dd').prev().text();
			//if(!name) name = ;
			msg.push(name);
		});
		Joomla.renderMessages({error: ['Пожалуйста, проверьте заполнение полей: ' + msg.join(', ')]});

	}
} 
