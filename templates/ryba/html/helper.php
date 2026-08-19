<?php defined('_JEXEC') or die;

class JTplHelper
{
	private static function getNormalizedUserServiceMap($form, $fieldName, $user = null)
	{
		$userId = 0;
		if (is_object($user) && isset($user->id)) {
			$userId = (int) $user->id;
		}
		if ($userId <= 0 && is_object($form) && method_exists($form, 'getValue')) {
			$userId = (int) $form->getValue('id');
		}
		if ($userId <= 0) {
			return null;
		}

		$serviceClass = '\\Joomla\\Plugin\\User\\Vigling\\Service\\UserServicesService';
		if (!class_exists($serviceClass)) {
			$svcFile = JPATH_SITE . '/plugins/user/vigling/src/Service/UserServicesService.php';
			if (is_file($svcFile)) {
				require_once $svcFile;
			}
		}
		if (class_exists($serviceClass)) {
			try {
				if ($fieldName === 'stock_prices') {
					/** @var array $data */
					$data = $serviceClass::getUserStockServicesLegacyShape($userId);
					return $data;
				}
				/** @var array $data */
				$data = $serviceClass::getUserServicesLegacyShape($userId);
				return $data;
			} catch (\Throwable $e) {
				// fallback to legacy field value below
			}
		}
		return null;
	}

	static function renderField($field, $form)
	{
		switch($field->fieldname)
		{
			case 'avatar':
			echo str_replace('width:50px;', '', $form->getInput($field->fieldname));
			break;
			case 'portfolio_field':
			echo '<div class="control-group portfolio_field-group">';
			if($field->value){
				if(!is_array($field->value))
				$field->value=array($field->value);

				foreach($field->value as $img){
					echo '<div class="controls preview" style="background-image: url('.$img.');">
					<input type="file" name="jform[upload_portfolio_field][]" id="jform_upload_portfolio_field" accept="image/*" />
					<input type="hidden" name="jform[upload_portfolio_field][]" id="jform_portfolio_field" value="'.$img.'">
					<i></i></div>';
				}
			}
			echo '<div class="controls"><img src="/templates/ryba/images/3.png" alt="" class="img_portfolio_field">
			<input type="file" name="jform[upload_portfolio_field][]" id="jform_upload_portfolio_field" accept="image/*" readonly />
			<input type="hidden" name="jform[upload_portfolio_field][]" id="jform_portfolio_field_up" value="" readonly />
			</div>';
			echo '</div>';
			break;
			case 'prices':
			echo '<fieldset id="jform_vyberite_usl">';

			$spec_field = $form->getField('vyberite_spetsialnos');
			$usl_list = $spec_field->getOptions();
			$usl_list = array_column($usl_list, 'text', 'value');
			$selected = $spec_field->__get('value');
			$prices = self::getNormalizedUserServiceMap($form, 'prices');
			if ($prices === null) {
				$field->value = preg_replace('/(\w+):/i', '"\1":', $field->value);
				$prices = (array)json_decode($field->value);
			}

			JModelLegacy::addIncludePath(JPATH_SITE.'/components/com_content/models', 'ContentModel');
			$model = JModelLegacy::getInstance('Articles', 'ContentModel');

			if(empty($selected))
			echo 'Выберите специальность, чтобы  добавить услугу';
			else

			/* выборка текущих валют пользователя */

			$valuta_array = array();

			/* $sql = 'SELECT valuta FROM joomla_jsn_users WHERE id = "' . $name_user . '" AND lastname = "' . $surname_user . '"';

			try {

			$result = $pdo -> query ($sql);

		} catch (PDOException $e) {
		$error = "Произошла ошибка - " . $e -> getMessage();

		echo $error;
		exit();
	}

	foreach ($result as $row) {

	$cur_valuta = $row[0];

	echo $cur_valuta;
	exit();

} */

/* **** **** **** **** */

$cat_index = -1;

foreach($selected as $catid){

	$cat_index++;

	$cur_valuta = $valuta_array[$cat_index];

	if(array_key_exists($catid, $usl_list)){
		$model->getState();
		$model->setState('filter.published', 1);
		$model->setState('filter.category_id', $catid);
		$model->setState('list.ordering', 'a.title');
		$model->setState('list.direction', 'ASC');
		$articles = $model->getItems();
		$add_html = $usl_list[$catid].'<b></b><div class="flex_wrap">';
		foreach($articles as $art){
			if(!array_key_exists($art->id, $prices))
			continue;
			if(isset($art->tags) && isset($art->tags->itemTags))
			$tags = array_column($art->tags->itemTags, 'title', 'tag_id');
			else $tags = array();
			foreach((array)$prices[$art->id] as $price){
				$title = $art->title;
				$tid = 0;
				$service_id = $art->id;
				if(count($price) > 2 && $tid = $price[2])
				if(array_key_exists($tid, $tags)){
					$title .= ' /'.$tags[$tid];
					$service_id = $service_id.'-'.$tid;
				}

				$pause = explode('.', (string)$price[1]);
				$pause = (count($pause)==1) ? 0 : (int)$pause[1];
				$price[1] = floor($price[1]);
				$add_html .= '<div class="service__wrap"><p class="service__item"><a href="#" class="hdr">'.$title.'</a>';
				$add_html .= '<span class="time"><label>Время: </label><input name="time[]" value="'.$price[1].'" type="hidden">'.$price[1].' мин.</span>';
				$add_html .= '<span class="time2"><label>Перерыв: </label><input name="time2[]" value="'.$pause.'" type="hidden">'.$pause.' мин.</span>';
				$add_html .= '<span class="price"><label>Стоимость: </label><input type="hidden" name="price[]" value="'.$price[0].'">'.$price[0].'</span><select class="valuta_select" style="width: 70px;"><option value="RUB">RUB</option><option value="KZT">KZT</option><option value="UZS">UZS</option><option value="KGS">KGS</option><option value="TJS">TJS</option><option value="BYN">BYN</option><option value="AMD">AMD</option></select>';

				$add_html .= '<i></i><input type="hidden" name="service_id[]" value="'.$service_id.'">';
				$add_html .= '</p></div>';
			}
		}
		echo '<label class="checkbox type_master_closed" data-id="'.$catid.'">';
		echo $add_html.'<div class="plus_key"></div></div></label>';
	}
}
echo '</fieldset>';

/* получение валют */

echo "<script>

var profile_user = jQuery('.jsn-p-title h3')[0].textContent;

profile_user = profile_user.trim();

var ind = profile_user.indexOf(' ');

var name_user = profile_user.substring(0, ind);
var surname_user = profile_user.substring(ind+1);

var data = {};

	data.get_valuta = 1;
	data.name_user = name_user;
	data.surname_user = surname_user;

	jQuery.post('https://vigling.ru/templates/ryba/valuta_save.php', data, function (data){

		var valuta_array = data.split('///');

		jQuery('.valuta_select').each(function (ind, element) {

			var cur_adv_name = element.previousElementSibling.previousElementSibling.previousElementSibling.previousElementSibling.textContent;

			const re = / /gi;
			cur_adv_name = cur_adv_name.replace(re, '');

			for (var i = 0; i < valuta_array.length; i++) {

				var cur_valuta_array = valuta_array[i].split(',');

				var cur_valuta_array_name = cur_valuta_array[0];

				const re = / /gi;
				cur_valuta_array_name = cur_valuta_array_name.replace(re, '');

				if (cur_adv_name == cur_valuta_array_name) {

					var cur_valuta = cur_valuta_array[1];

					element.value = cur_valuta;
				}

			}

		});

	});

	</script><br>";

	break;
	////STOCK PRICES
	case 'stock_prices':
	echo '<fieldset id="jform_stocks_servis">';

	$spec_field = $form->getField('vyberite_spetsialnos');
	$usl_list = $spec_field->getOptions();
	$usl_list = array_column($usl_list, 'text', 'value');
	$selected = $spec_field->__get('value');
	//$field->value = preg_replace('/(\w+)/iu', '"\1"', str_replace('"', '', $field->value));
	//$field->value = str_replace(array('["[', ']","[', ']"]'), array('[[', '],[', ']]'), str_replace(array(',', ']', '[', '{', ':'), array('","', '"]', '["', '{"', '":'), str_replace('"', '', $field->value)));
	$stock_prices = self::getNormalizedUserServiceMap($form, 'stock_prices');
	if ($stock_prices === null) {
		$field->value = preg_replace('/(\w+):/i', '"\1":', $field->value);
		$stock_prices = (array)json_decode($field->value);
	}

	JModelLegacy::addIncludePath(JPATH_SITE.'/components/com_content/models', 'ContentModel');
	$model = JModelLegacy::getInstance('Articles', 'ContentModel');

	if(empty($selected))
	echo 'Выберите специальность, чтобы  добавить акционную услугу';
	else
	foreach($selected as $catid){
		echo '<!-- catid '.$catid. ' -->';
		if(array_key_exists($catid, $usl_list)){
			$model->getState();
			$model->setState('filter.published', 1);
			$model->setState('filter.category_id', $catid);
			$model->setState('list.ordering', 'a.title');
			$model->setState('list.direction', 'ASC');
			$articles = $model->getItems();
			$add_html = $usl_list[$catid].'<b></b><div class="flex_wrap">';
			foreach($articles as $art) {
				if(!array_key_exists($art->id, $stock_prices))
				continue;
				echo '<!-- artid '.$art->id. ' -->';
				if(isset($art->tags) && isset($art->tags->itemTags))
				$tags = array_column($art->tags->itemTags, 'title', 'tag_id');
				else $tags = array();
				foreach((array)$stock_prices[$art->id] as $stock_price){
					$title = $art->title;
					$tid = 0;
					$service_id = $art->id;
					if(count($stock_price) > 2 && $tid = $stock_price[2])
					if(array_key_exists($tid, $tags)){
						$title .= ' /'.$tags[$tid];
						$service_id = $service_id.'-'.$tid;
					}
					$pause = explode('.', (string)$stock_price[1]);
					$pause = (count($pause)==1) ? 0 : (int)$pause[1];
					$stock_price[1] = floor($stock_price[1]);
					$add_html .= '<div class="service__wrap"><p class="service__item"><a href="#" class="hdr">'.$title.'</a>';
					$add_html .= '<span class="time"><label>Время: </label><input name="time[]" value="'.$stock_price[1].'" type="hidden">'.$stock_price[1].' мин.</span>';
					$add_html .= '<span class="time2"><label>Перерыв: </label><input name="time2[]" value="'.$pause.'" type="hidden">'.$pause.' мин.</span></br>';
					$add_html .= '<span class="stock_price"><label>Акционная стоимость: </label><input type="hidden" name="stock_price[]" value="'.$stock_price[0].'"> ' . $stock_price[0] . '</span><select class="valuta_select_akzii" data-akziionnaya_stoimost_select="1" style="width: 70px;"><option value="RUB">RUB</option><option value="KZT">KZT</option><option value="UZS">UZS</option><option value="KGS">KGS</option><option value="TJS">TJS</option><option value="BYN">BYN</option><option value="AMD">AMD</option></select></br>';
					$add_html .= '<span class="old_price"><label>Цена без скидки: </label><input type="hidden" name="old_price[]" value="'.$stock_price[3].'"> ' . $stock_price[3] . '</span><select class="valuta_select_akzii" style="width: 70px;"><option value="RUB">RUB</option><option value="KZT">KZT</option><option value="UZS">UZS</option><option value="KGS">KGS</option><option value="TJS">TJS</option><option value="BYN">BYN</option><option value="AMD">AMD</option></select></br>';
					$add_html .= '<span class="about_stock"><label>Условия акции: </label><input type="hidden" name="about_stock[]" value="' . $stock_price[4] . '"> ' . $stock_price[4] . ' </span><br>';
					$add_html .= '<span class="count_stock"><label>Количество предложений: </label><input type="hidden" name="count_stock[]" value="' . $stock_price[5] . '"> ' . $stock_price[5] . ' </span><br>';
					//$add_html .= '<br><span class="num_stock"><label>Количество записей: </label><input type="text" name="num_stock[]" value="5"></span>';
					$add_html .= '<i></i><input type="hidden" name="service_id[]" value="'.$service_id.'">';
					$add_html .= '</p></div>';
				}
			}
			echo '<label class="checkbox type_master_closed" data-id="'.$catid.'">';
			echo $add_html.'<div class="stock_key"></div></div></label>';
		}
	}
	echo '</fieldset>';

	/* получение валют для акций */

	echo "<script>

	console.log(10);

	var profile_user = jQuery('.jsn-p-title h3')[0].textContent;

	profile_user = profile_user.trim();

	var ind = profile_user.indexOf(' ');

	var name_user = profile_user.substring(0, ind);
	var surname_user = profile_user.substring(ind+1);

	var data = {};

		data.get_valuta_akzii = 1;
		data.name_user = name_user;
		data.surname_user = surname_user;

		jQuery.post('https://vigling.ru/templates/ryba/valuta_save.php', data, function (data){

			var valuta_array = data.split('///');

			jQuery('.valuta_select_akzii').each(function (ind, element) {

				var is_akziioniy_select = element.getAttribute('data-akziionnaya_stoimost_select');

				if (is_akziioniy_select) {

					var cur_adv_name = element.previousElementSibling.previousElementSibling.previousElementSibling.previousElementSibling.previousElementSibling.textContent;

				} else {

					var cur_adv_name = element.previousElementSibling.previousElementSibling.previousElementSibling.previousElementSibling.previousElementSibling.previousElementSibling.previousElementSibling.previousElementSibling.textContent;

				}

				const re = / /gi;
				cur_adv_name = cur_adv_name.replace(re, '');

				for (var i = 0; i < valuta_array.length; i++) {

					var cur_valuta_array = valuta_array[i].split(',');

					var cur_valuta_array_name = cur_valuta_array[0];

					const re = / /gi;
					cur_valuta_array_name = cur_valuta_array_name.replace(re, '');

					if (cur_adv_name == cur_valuta_array_name) {

						if (is_akziioniy_select) {

							var cur_valuta = cur_valuta_array[1];
						} else {

							var cur_valuta = cur_valuta_array[2];
						}

						element.value = cur_valuta;
					}

				}

			});

		});

		</script><br>";

		break;
		////END CTOCK PRICES
		case 'work_day':
		$work_from = $form->getValue('work_from') ? $form->getValue('work_from') : '[0]';
		$work_from = json_decode(($work_from[0]=='[') ? $work_from : '['.$work_from.']');
		if(count($work_from)==1)
		$work_from = array_fill(0, 7, $work_from[0]);
		elseif(count($work_from)==count($field->value)){
			$work_from = array_replace(array_fill(0, 8, 0), array_combine($field->value, $work_from));
			array_shift($work_from);
		}

		$work_to = $form->getValue('work_to') ? $form->getValue('work_to') : '[0]';
		$work_to = json_decode(($work_to[0]=='[') ? $work_to : '['.$work_to.']');
		if(count($work_to)==1)
		$work_to = array_fill(0, 7, $work_to[0]);
		elseif(count($work_to)==count($field->value)){
			$work_to = array_replace(array_fill(0, 8, 0), array_combine($field->value, $work_to));
			array_shift($work_to);
		}

		$cal = $hdr = $lbl = ''; //print_r($work_from);
		$days = $field->value ?	$field->value : array();
		$week = array('MON','TUE','WED','THU','FRI','SAT','SUN');
		foreach(range(0,6) as $weekday){
			$hdr .= '<li>'.JText::_($week[$weekday]).'<input type="checkbox" name="jform[work_day][]"'
			.' value="'.($weekday+1).'" '.(in_array($weekday+1, $days) ? 'checked' :'').' /></li>';
			$cal .= '<div class="calendar__table-item"><ul>';
			foreach(range(0, 23.75, 0.25) as $hour){
				$empty = 'class="empty" ';
				if(in_array($weekday+1, $days))
				if(array_key_exists($weekday, $work_from) || array_key_exists($weekday, $work_to))
				if($hour >= $work_from[$weekday] && $hour < $work_to[$weekday])
				$empty = '';

				$cal .= '<li><a '.$empty.'data-day="'.($weekday+1).'" data-time="'.$hour.'"'
				.' href="#" title="'.JText::_($week[$weekday]).' '.floor($hour).':'
				.str_pad((($hour - floor($hour))*60), 2, '0').'"></a>'.'</li>';
			}
			$cal .= '</ul></div>';
		}

		echo '<div class="calendar__system" data-min="0">';
		echo '<div class="calendar__table-head">';
		echo '<ul>'.$hdr.'</ul>';
		echo '</div>';

		echo '<div class="table__calendar-left"><ul>';
		foreach(range(0, 23) as $hour)
		echo '<li>'.$hour.'.00<ul><li>.15</li><li>.30</li><li>.45</li></ul></li>';
		echo '</ul></div>';

		echo '<div class="calendar__table">'.$cal.'</div>';
		echo '</div>';
		break;
		case 'favorites':
		echo '<ul class="fav-list">';
		$field = $form->getField('favorites');
		$selected = $field->__get('value');
		if(!$selected || empty($selected))
		echo '<li>Список пуст</li>';
		else
		foreach($selected as $master_id){
			$user=JsnHelper::getUser($master_id);
			if($user->id){
				echo '<li><a href="'.$user->getLink().'">'.$user->getField('avatar', false);
				echo $user->firstname.' '.$user->lastname;
				echo '<input type="hidden" name="jform[favorites][]" value="'.$user->id.'">';
				echo '</a><span class="del_key" onclick="jQuery(this).parent().remove()"></span></li>';
			}
		}
		echo '</ul>';
		break;
		default:
		echo $form->getInput($field->fieldname);
	}
}

static function renderStatic($field, $form, $user)
{
	// ID \пользователя
	// echo "ID пользователя -> "; echo $user->id;
	// -- ID \пользователя
	switch($field->fieldname)
	{
		case 'portfolio_field': //print_r($field);
		echo '<div class="portfolio_field-group">';
		if($field->value){
			if(!is_array($field->value))
			$field->value=array($field->value);

			foreach($field->value as $img)
			echo '<div class="controls preview" style="background-image: url('.(($img[0]=='/') ? $img : '/'.$img).');"></div>';
		}
		echo '</div>';
		break;
		case 'prices':
		echo '<fieldset id="jform_vyberite_usl" class="readonly">';

		$spec_field = $form->getField('vyberite_spetsialnos');
		$usl_list = $spec_field->getOptions();
		$usl_list = array_column($usl_list, 'text', 'value');
		$selected = $spec_field->__get('value');
		$prices = self::getNormalizedUserServiceMap($form, 'prices', $user);
		if ($prices === null) {
			$field->value = preg_replace('/(\w+):/i', '"\1":', $field->value);
			$prices = (array)json_decode($field->value);
		}

		JModelLegacy::addIncludePath(JPATH_SITE.'/components/com_content/models', 'ContentModel');
		$model = JModelLegacy::getInstance('Articles', 'ContentModel');

		if(empty($selected))
		echo 'Выберите специальность, чтобы  добавить услугу';
		else
		foreach($selected as $catid){
			if(array_key_exists($catid, $usl_list)){
				$model->getState();
				$model->setState('filter.published', 1);
				$model->setState('filter.category_id', $catid);
				$model->setState('list.ordering', 'a.title');
				$model->setState('list.direction', 'ASC');
				$articles = $model->getItems();
				$add_html = $usl_list[$catid].'<div class="flex_wrap">';
				foreach($articles as $art){
					if(!array_key_exists($art->id, $prices))
					continue;
					if(isset($art->tags) && isset($art->tags->itemTags))
					$tags = array_column($art->tags->itemTags, 'title', 'tag_id');
					else $tags = array();
					foreach((array)$prices[$art->id] as $price){
						$title = $art->title;
						$tid = 0;
						$service_id = $art->id;
						if(count($price) > 2 && $tid = $price[2])
						if(array_key_exists($tid, $tags)){
							$title .= ' /'.$tags[$tid];
							$service_id = $service_id.'-'.$tid;
						}

						$pause = explode('.', (string)$price[1]);
						$pause = (count($pause)==1) ? 0 : (int)$pause[1];
						$price[1] = floor($price[1]);
						$add_html .= '<div class="service__wrap"><p class="service__item" style="width: auto;min-width: 90%;">';
						$add_html .= '<span class="hdr">'.$title.'</span><span class="time"><label>Время:</label>'.$price[1].' мин.</span>';
						$add_html .= '<span class="time2"><label>Перерыв:</label>'.$pause.' мин.</span>' . count($articles) ;
						$add_html .= '<span class="price"><label>Стоимость:</label>'.$price[0].'  <span class="valuta">руб</span></span></p></div>';
					}
				}
				echo '<label class="checkbox type_master_open" data-id="'.$catid.'">';
				echo $add_html.'</div></label>';

				/* добавление валют */

				echo "<script>

				var profile_user = jQuery('.jsn-p-title h3')[0].textContent;

				profile_user = profile_user.trim();

				var ind = profile_user.indexOf(' ');

				var name_user = profile_user.substring(0, ind);
				var surname_user = profile_user.substring(ind+1);

				var data = {};

					data.get_valuta = 1;
					data.name_user = name_user;
					data.surname_user = surname_user;

					jQuery.post('https://vigling.ru/templates/ryba/valuta_save.php', data, function (data){

						var valuta_array = data.split('///');

						jQuery('.service__item .hdr').each(function (ind, element) {

							var is_akzii_span = element.getAttribute('data-akzii');

							if (!is_akzii_span) {

								var cur_adv_name = element.textContent;

								const re = / /gi;
								cur_adv_name = cur_adv_name.replace(re, '');

								for (var i = 0; i < valuta_array.length; i++) {

									var cur_valuta_array = valuta_array[i].split(',');

									var cur_valuta_array_name = cur_valuta_array[0];

									const re = / /gi;
									cur_valuta_array_name = cur_valuta_array_name.replace(re, '');

									if (cur_adv_name == cur_valuta_array_name) {

										var cur_valuta = cur_valuta_array[1];

										element.nextElementSibling.nextElementSibling.nextElementSibling.children[1].textContent = cur_valuta;

										break;
									}

								}

							}

						});

					});

					</script><br>";

					/* **** **** **** **** */
				}
			}
			echo '</fieldset>';
			break;
			////STOCK PRICES
			case 'stock_prices':
			echo '<fieldset id="jform_stocks_servis" class="readonly">';

			$spec_field = $form->getField('vyberite_spetsialnos');
			$usl_list = $spec_field->getOptions();
			$usl_list = array_column($usl_list, 'text', 'value');
			$selected = $spec_field->__get('value');
			$stock_prices = self::getNormalizedUserServiceMap($form, 'stock_prices', $user);
			if ($stock_prices === null) {
				$field->value = preg_replace('/(\w+):/i', '"\1":', $field->value);
				//$field->value = str_replace(array('["[', ']","[', ']"]'), array('[[', '],[', ']]'), str_replace(array(',', ']', '[', '{', ':'), array('","', '"]', '["', '{"', '":'), str_replace('"', '', $field->value)));
				$stock_prices = (array)json_decode($field->value);
			}

			JModelLegacy::addIncludePath(JPATH_SITE.'/components/com_content/models', 'ContentModel');
			$model = JModelLegacy::getInstance('Articles', 'ContentModel');

			if(empty($selected))
			echo 'Выберите специальность, чтобы акционную добавить услугу';
			else
			foreach($selected as $catid){
				if(array_key_exists($catid, $usl_list)){
					$model->getState();
					$model->setState('filter.published', 1);
					$model->setState('filter.category_id', $catid);
					$model->setState('list.ordering', 'a.title');
					$model->setState('list.direction', 'ASC');
					$articles = $model->getItems();
					$add_html = $usl_list[$catid].'<div class="flex_wrap">';
					foreach($articles as $art){
						if(!array_key_exists($art->id, $stock_prices))
						continue;
						if(isset($art->tags) && isset($art->tags->itemTags))
						$tags = array_column($art->tags->itemTags, 'title', 'tag_id');
						else $tags = array();
						foreach((array)$stock_prices[$art->id] as $stock_price){
							$title = $art->title;
							$tid = 0;
							$service_id = $art->id;
							if(count($stock_price) > 2 && $tid = $stock_price[2])
							if(array_key_exists($tid, $tags)){
								$title .= ' /'.$tags[$tid];
								$service_id = $service_id.'-'.$tid;
							}
							$pause = explode('.', (string)$stock_price[1]);
							$pause = (count($pause)==1) ? 0 : (int)$pause[1];
							$stock_price[1] = floor($stock_price[1]);
							$add_html .= '<div class="service__wrap"><p class="service__item" style="width: auto;min-width: 90%;">';
							$add_html .= '<span class="hdr" data-akzii="true">'.$title.'</span><span class="time"><label>Время:</label>'.$stock_price[1].' мин.</span>';
							$add_html .= '<span class="time2"><label>Перерыв:</label>'.$pause.' мин.</span></br>';
							$add_html .= '<span class="stock_price"><label>Акционная стоимость: </label> '.$stock_price[0].' </span><span class="akzii_price"> руб.</span></br>';
							$add_html .= '<span class="old_price"><label>Цена без скидки: </label> '.$stock_price[3].' </span><span class="akzii_price_1"> руб.</span></br>';
							$add_html .= '<span class="about_stock"><label>Условия акции: </label> '.$stock_price[4].'</span><br>';
							$add_html .= '<span class="count_stock"><label>Осталось предложений: '.$stock_price[5].' </label></span></p></div>';
						}
					}
					echo '<label class="checkbox type_master_open" data-id="'.$catid.'">';
					echo $add_html.'</div></label>';
				}
			}
			echo '</fieldset>';

			/* добавление валют для акции */

			echo "<script>

			var profile_user = jQuery('.jsn-p-title h3')[0].textContent;

			profile_user = profile_user.trim();

			var ind = profile_user.indexOf(' ');

			var name_user = profile_user.substring(0, ind);
			var surname_user = profile_user.substring(ind+1);

			var data = {};

				data.get_valuta_akzii = 1;
				data.name_user = name_user;
				data.surname_user = surname_user;

				jQuery.post('https://vigling.ru/templates/ryba/valuta_save.php', data, function (data) {

					var valuta_array = data.split('///');

					jQuery('.service__item .hdr').each(function (ind, element) {

						var is_akzii_span = element.getAttribute('data-akzii');

						if (is_akzii_span) {

							var cur_adv_name = element.textContent;

							const re = / /gi;
							cur_adv_name = cur_adv_name.replace(re, '');

							for (var i = 0; i < valuta_array.length; i++) {

								var cur_valuta_array = valuta_array[i].split(',');

								var cur_valuta_array_name = cur_valuta_array[0];

								const re = / /gi;
								cur_valuta_array_name = cur_valuta_array_name.replace(re, '');

								if (cur_adv_name == cur_valuta_array_name) {

									var cur_valuta_1 = cur_valuta_array[1];
									var cur_valuta_2 = cur_valuta_array[2];

									element.nextElementSibling.nextElementSibling.nextElementSibling.nextElementSibling.nextElementSibling.textContent = cur_valuta_1;

									element.nextElementSibling.nextElementSibling.nextElementSibling.nextElementSibling.nextElementSibling.nextElementSibling.nextElementSibling.nextElementSibling.textContent = cur_valuta_2;

								}

							}

						}

					});

				});

				</script><br>";

				/* **** **** **** **** */


				break;
				////END STOCK PRICES
				case 'favorites':
				echo '<ul class="fav-list">';
				$field = $form->getField('favorites');
				$selected = $field->__get('value');
				if(!$selected || empty($selected))
				echo '<li>Список пуст</li>';
				else
				foreach($selected as $master_id){
					$user=JsnHelper::getUser($master_id);
					if($user->id){
						echo '<li><a href="'.$user->getLink().'">'.$user->getField('avatar', false);
						echo $user->firstname.' '.$user->lastname.'</a></li>';
					}
				}
				echo '</ul>';
				break;
				default:
				echo $user->getField($field, false);
			}
		}
	}
