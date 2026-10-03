<?php

\defined('_JEXEC') or die;

/**
 * Older template path. The schedule class is templates/ryba/helpers/offline.php.
 * This file loads the plugin loader, which includes that class.
 * templates/ryba/offline.php is the site offline page.
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
