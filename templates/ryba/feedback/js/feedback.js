/**
 * Created with JetBrains PhpStorm.
 * User: Vitaly
 * Date: 06.06.13
 * Time: 20:22
 * To change this template use File | Settings | File Templates.
 */
function inArray(needle, haystack) {
  var length = haystack.length;
  for (var i = 0; i < length; i++) {
    if (typeof haystack[i] == 'object') {
      if (arrayCompare(haystack[i], needle)) return true;
    } else {
      if (haystack[i] == needle) return true;
    }
  }
  return false;
}
window.isset = function(v) {
  if (typeof(v) == 'object' && v == 'undefined') {
    return false;
  } else if (arguments.length === 0) {
    return false;
  } else {
    var buff = arguments[0];
    for (var i = 0; i < arguments.length; i++) {
      if (typeof(buff) === 'undefined' || buff === null) return false;
      buff = buff[arguments[i + 1]];
    }
  }
  return true;
}
function myconf() {
  var cf = jQuery.Deferred();
  jQuery.ajax({
    type: 'POST',
    url: '/templates/saporepizza/feedback/',
    dataType: 'json',
    data: 'act=cfg',
    success: function(answer) {
      cf.resolve(answer.configs);
    }
  });
  return cf;
}
var mcf = myconf();
mcf.done(function(conf) {
  jQuery(document).ready(function() {
    (function() {
      var fb = jQuery('.feedback');
      if (fb.length > 0) {
        fb.each(function() {
          var form = jQuery(this).closest('form'),
            name = form.attr('name');
          //console.log(form);
          if (isset(conf[name]) && isset(conf[name].cfg.antispamjs)) {
            jQuery(form).prepend('<input type="text" name="' + conf[name].cfg.antispamjs + '" value="tesby" style="display:none;">');
          }
        });
      }
    })();
  });
  /**
   * Отправка форм.
   *
   */
  function feedback(vars) {
    var bt = jQuery(vars.form).find('.feedback');
    var btc = bt.clone();
    var bvc = bt.val();
    var cfg = conf[vars.act].cfg;
    jQuery.ajax({
      type: 'POST',
      url: '/templates/saporepizza/feedback/',
      cache: false,
      dataType: 'json',
      data: 'act=' + vars.act + '&' + vars.data,
      beforeSend: function() {
        jQuery(bt).prop("disabled", true);
        jQuery(bt).addClass('loading');
      },
      success: function(answer) {
        if (isset(cfg.notify) && !/none/i.test(cfg.notify)) {
          if (/textbox/i.test(cfg.notify)) {
            if (isset(answer.errors)) {
              jQuery.each(answer.errors, function(k, val) {
                jQuery.jGrowl(val, {
                  theme: 'error',
                  header: 'Ошибка!',
                  life: 3000
                });
              });
            }
            if (isset(answer.infos)) {
              jQuery.each(answer.infos, function(k, val) {
                jQuery.jGrowl(val, {
                  theme: 'infos',
                  header: 'Внимание!',
                  life: 3000
                });
              });
            }
          }
          if (/color/i.test(cfg.notify)) {
            jQuery(vars.form).find('input[type=text]:visible, textarea:visible, select:visible').css({
              'border': '2px solid #bebebe'
            }, 300);
            if (isset(answer.errors)) {
              jQuery.each(answer.errors, function(k, val) {
                var reg = /[a-z]/i;
                if (reg.test(k)) {
                  var e = jQuery(vars.form).find('[name=' + k + ']');
                  if (e.length == 1) {
                    jQuery(e).css({
                      'border': '2px solid #FF1928'
                    }, 100);
                  }
                }
              });
            }
            if (isset(answer.infos)) {
              var li = '',
                $inf = jQuery('<ul>', {
                  id: 'feedback-infolist'
                });
              jQuery.each(answer.infos, function(k, val) {
                li += '<li>' + val + '</li>';
              });
              $inf.html(li);
			  
			  
              
			  jQuery('#dialog_modal1').modal('hide')
               jQuery('#dialog_modal2').modal('hide')
              jQuery('#basket_modal').modal('hide');
              if (/modal/i.test(cfg.notify)) {
                var m = jQuery('<div class="box-modal" id="feedback-modal-box" />');
                m.html($inf);
                m.prepend('<div class="modal-close arcticmodal-close">X</div>');
                jQuery.arcticmodal({
                  content: m
                });
              }
            }
          }
        }
        jQuery(bt).prop("disabled", false);
        jQuery(bt).removeClass('loading');
        if (isset(answer.ok) && answer.ok == 1) {
          jQuery(vars.form)[0].reset();
        }
      }
    });
  }
  jQuery(document).on('mouseenter mouseover', '.feedback', function() {
    var form = jQuery(this).closest('form'),
      name = form.attr('name');
    if (isset(conf[name]) && isset(conf[name].cfg.antispamjs)) {
      jQuery('input[name=' + conf[name].cfg.antispamjs + ']').val('');
    }
  });
  /**
   * Обработчик кнопки форм.
   * Кнопка должна быть внутри тегов <form> c классом .feedback
   * будет отправлено любое кол-во полей, кроме файлов
   *
   */
  jQuery(document).on('click', '.feedback', function() {
    var form = jQuery(this).closest('form'),
      name = form.attr('name'),
      obj = {};
	  if (name == "form-3") {parse();}
    obj.form = form;
    obj.act = name;
    obj.data = jQuery(form).serialize();
    feedback(obj);
    return false;
  });
}); // done



function parse(){
	
	var name = new Array(),
		count = new Array(),
		cost = new Array(),
		item = '\r\n\r\n';
	jQuery("input[name='name[]']").each(function(){name.push(jQuery(this).val());});
	jQuery("input[name='count[]']").each(function(){count.push(jQuery(this).val());});
	jQuery("input[name='cost[]']").each(function(){cost.push(jQuery(this).val());});
	name.forEach(function(val, ind) {
		item += ind+1+'. '+val+' - '+count[ind]+' ед.; Сумма: '+cost[ind]+'руб;\r\n';
	});
	jQuery(".prod").val(item);
};




























