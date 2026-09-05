<?php
defined('_JEXEC') or die;

use Joomla\CMS\Uri\Uri;

$vgMapCity = isset($vgMapCity) ? (string) $vgMapCity : 'Москва';
$vgMapPinsUrl = isset($vgMapPinsUrl) ? (string) $vgMapPinsUrl : '';
$vgMapTotal = isset($vgMapTotal) ? (int) $vgMapTotal : 0;
$vgMapCityLocked = !empty($vgMapCityLocked);

$doc = \Joomla\CMS\Factory::getDocument();
$doc->addStyleSheet(Uri::root(true) . '/templates/ryba/css/list-map.css', ['version' => 'auto']);
$doc->addScript(Uri::root(true) . '/templates/ryba/js/list-map.js', ['version' => 'auto'], ['defer' => true]);
?>
<div
	id="map"
	class="vg-list-map"
	data-city="<?php echo htmlspecialchars($vgMapCity, ENT_QUOTES, 'UTF-8'); ?>"
	data-city-filter="<?php echo $vgMapCityLocked ? '1' : '0'; ?>"
	data-auto-open="1"
	data-pins-url="<?php echo htmlspecialchars($vgMapPinsUrl, ENT_QUOTES, 'UTF-8'); ?>"
	data-total="<?php echo $vgMapTotal; ?>"
>
	<div class="vg-list-map__canvas"></div>
	<div class="vg-list-map__preview">
		<div class="vg-list-map__overlay">
			<button type="button" class="vg-list-map__btn">Показать на карте</button>
		</div>
	</div>
</div>
