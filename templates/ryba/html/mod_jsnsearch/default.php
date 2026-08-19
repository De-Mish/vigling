<?php
/**
* @copyright	Copyright (C) 2013 Jsn Project company. All rights reserved.
* @license		http://www.gnu.org/licenses/gpl-2.0.html GNU/GPL
* @package		Easy Profile
* website		www.easy-profile.com
* Technical Support : Forum -	http://www.easy-profile.com/support.html
*/

defined('_JEXEC') or die;

$jsnConfig=JComponentHelper::getParams('com_jsn');

$doc = JFactory::getDocument();
$doc->addScriptDeclaration("
	jQuery(document).ready(function($){
		$('.filed1>input').on('keyup', function(){
			var search = $(this).val();
			if(!$(this).next().is('div.search_box-result'))
				$('<div class=\"search_box-result\"></div>').insertAfter($(this));
			var result = $(this).next();
			if((search != '') && (search.length > 1)){
				$.getJSON('?option=com_jsn&format=json&task=get_services', 
				  {'search': search}, function(data) {
					if(data.success){
						if(data.data.length !== undefined && data.data.length==0)
							result.fadeOut(100);
						else {
							result.html('');
							$.each(data.data, function(idx, t){console.log(t);
								result.append('<div class=\"result\" data-id=\"'+idx+'\">'+t+'</div>');
							});
							result.fadeIn();
						}
					}
				});
			}
			else {
				result.fadeOut(100).html('');
			}
		});
		$('.filed1 input').on('click', function(){
			if($(this).val()!='' && $(this).next().text()!='')
				$(this).next().fadeIn();
		});

		$('.filed1').on('click', '.search_box-result>div', function(){
			$(this).parent().fadeOut(200);
			$(this).parent().prev().val($(this).text());
			var f = $(this).closest('form');
			if(!f.data('base-url'))
				f.attr('data-base-url', f[0].action);
			var url = f.data('base-url');
			f.attr('action', url+'/'+$(this).data('id'));
		});
		$('.filed2').on('focus', 'input', function(){
			if(!$(this).hasClass('hasDatepicker')){
				$.datepicker.regional['ru'] = {closeText: 'Закрыть', prevText: '<Пред', nextText: 'След>', currentText: 'Сегодня', monthNames: ['Январь','Февраль','Март','Апрель','Май','Июнь', 'Июль','Август','Сентябрь','Октябрь','Ноябрь','Декабрь'], 
				monthNamesShort: ['Янв','Фев','Мар','Апр','Май','Июн', 'Июл','Авг','Сен','Окт','Ноя','Дек'], dayNames: ['воскресенье','понедельник','вторник','среда','четверг','пятница','суббота'], 
				dayNamesShort: ['вск','пнд','втр','срд','чтв','птн','сбт'],dayNamesMin: ['Вс','Пн','Вт','Ср','Чт','Пт','Сб'], weekHeader: 'Не', dateFormat: 'dd.mm.yy', firstDay: 1, isRTL: false, 
				showMonthAfterYear: false, yearSuffix: ''};
				$.datepicker.setDefaults($.datepicker.regional['ru']);
				
				$('.filed2>input').datepicker({minDate: 'now'});				
			}
		});
		//$('#at_home input').on('change', function(){
		//	$('#at_home input:checked').not(this).prop('checked', false);
		//});
	});
");
$svc_types = JsnHelper::getFieldOptions('home');

?>
<div class="container">
	<div class="search__coll-left">
		<h2 class="search_title">поиск специалистов</h2>
		<span class="search__sub"></span>
		<form action="<?php echo JRoute::_('index.php?Itemid='.$params->get('menuitem',''),false); ?>" method="get">
			<input type="hidden" name="search" value="1"/>
			<div class="jsn_search_module-ext<?php $moduleclass_sfx; ?> jsn_result_<?php echo $params->get('menuitem',''); ?>">
				<div class="filed filed1">
					<input type="text" placeholder="Услуга или специальность" />
				</div>
				<div class="filed filed2">
					<input type="text" name="date" placeholder="Дата" />
				</div>
				<div class="filed filed3">
					<div class="control-group">
						<div class="controls">
							<fieldset class="checkboxes" id="at_home" >
								<?php foreach($svc_types as $v=>$type): if(!$type) continue;?>
								<label class="checkbox"><input type="checkbox" name="home[]" value="<?php echo $v?>" /><b><?php echo $type?></b></label>
								<?php endforeach;?>
							</fieldset>
						</div>
					</div>
				</div>
				<span class="form-sub">Популярные запросы: </span>
				<input type="submit" class="btn search-sbmt" value="<?php echo JText::_('COM_JSN_SEARCH'); ?>" />				
			</div>
			<?php echo JHtml::_('form.token'); ?>
		</form>
	</div>
	<p class="search__text"></p>
	<div class="clearFloat"></div>
	<script src="https://code.jquery.com/ui/1.12.1/jquery-ui.js"></script>
	<link rel="stylesheet" href="//code.jquery.com/ui/1.12.1/themes/base/jquery-ui.css">
</div>
