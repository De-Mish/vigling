<?php

/**
 * @package     Joomla.Site
 * @subpackage  mod_menu
 */

defined('_JEXEC') or die;

require_once __DIR__ . '/appointments_filter.php';

$startLevel = isset($params) ? (int) $params->get('startLevel', 1) : 1;
$list = ryba_filter_appointment_menu_items(is_array($list ?? null) ? $list : [], $startLevel);
if ($list === []) {
	return;
}
foreach ($list as $item) {
	if (!is_object($item)) {
		continue;
	}
	if ((string) ($item->title ?? '') === 'Нужна помощь в установке приложение?') {
		$item->title = 'Нужна помощь в установке приложения?';
	}
}

include JPATH_SITE . '/modules/mod_menu/tmpl/default.php';
