<?php

\defined('_JEXEC') or die;

/**
 * Older template path. The schedule class is templates/ryba/helpers/offline.php.
 * templates/ryba/offline.php is the site offline page and is a different file.
 */
$viglingWorkScheduleRoot = \defined('JPATH_ROOT') ? JPATH_ROOT : dirname(__DIR__, 3);
$viglingWorkScheduleClass = $viglingWorkScheduleRoot . '/templates/ryba/helpers/offline.php';
$viglingWorkSchedulePlugin = $viglingWorkScheduleRoot . '/plugins/user/vigling/src/Helper/WorkScheduleHelper.php';
if (
	is_file($viglingWorkScheduleClass)
	&& realpath($viglingWorkScheduleClass) !== realpath(__FILE__)
	&& !class_exists(\Joomla\Plugin\User\Vigling\Helper\WorkScheduleHelper::class, false)
) {
	require_once $viglingWorkScheduleClass;
} elseif (
	is_file($viglingWorkSchedulePlugin)
	&& realpath($viglingWorkSchedulePlugin) !== realpath(__FILE__)
	&& !class_exists(\Joomla\Plugin\User\Vigling\Helper\WorkScheduleHelper::class, false)
) {
	require_once $viglingWorkSchedulePlugin;
}
