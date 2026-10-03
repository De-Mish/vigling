<?php

\defined('_JEXEC') or die;

/**
 * One copy of the schedule parser lives in the user plugin.
 * This file only loads plugins/user/vigling/src/Helper/WorkScheduleHelper.php.
 * It is not the class. Do not replace templates/ryba/offline.php with the class.
 */
$viglingWorkScheduleRoot = \defined('JPATH_ROOT') ? JPATH_ROOT : dirname(__DIR__, 3);
$viglingWorkSchedulePlugin = $viglingWorkScheduleRoot . '/plugins/user/vigling/src/Helper/WorkScheduleHelper.php';
if (
	is_file($viglingWorkSchedulePlugin)
	&& realpath($viglingWorkSchedulePlugin) !== realpath(__FILE__)
	&& !class_exists(\Joomla\Plugin\User\Vigling\Helper\WorkScheduleHelper::class, false)
) {
	require_once $viglingWorkSchedulePlugin;
}
