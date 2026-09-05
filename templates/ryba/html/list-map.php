<?php
defined('_JEXEC') or die;

use Joomla\CMS\Uri\Uri;

$vgMapCity = isset($vgMapCity) ? (string) $vgMapCity : 'Москва';
$vgMapPinsUrl = isset($vgMapPinsUrl) ? (string) $vgMapPinsUrl : '';
$vgMapTotal = isset($vgMapTotal) ? (int) $vgMapTotal : 0;
$vgMapCityLocked = !empty($vgMapCityLocked);

$doc = \Joomla\CMS\Factory::getDocument();
$doc->addStyleSheet(Uri::root(true) . '/templates/ryba/css/list-map.css', ['version' => 'auto']);
$jsPath = JPATH_ROOT . '/templates/ryba/js/list-map.js';
$js = is_readable($jsPath) ? (string) file_get_contents($jsPath) : '';
if ($js !== '' && preg_match('/openMap\(root\);\s*\}\);\s*if \(root\.getAttribute\([\'"]data-auto-open[\'"]\)/', $js)) {
	$js = preg_replace(
		'/openMap\(root\);\s*\}\);\s*if \(root\.getAttribute\([\'"]data-auto-open[\'"]\)/',
		"openMap(root);\n\t\t\t});\n\t\t}\n\t\tif (root.getAttribute('data-auto-open')",
		$js,
		1
	);
}
if ($js !== '') {
	$doc->addScriptDeclaration($js);
} else {
	$doc->addScript(Uri::root(true) . '/templates/ryba/js/list-map.js', ['version' => 'auto'], ['defer' => true]);
}
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
