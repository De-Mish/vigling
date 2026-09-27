<?php

\defined('_JEXEC') or die;

/**
 * One copy of the schedule parser lives in the user plugin.
 * This file only loads it so older template paths keep working.
 */
$viglingWorkScheduleRoot = \defined('JPATH_ROOT') ? JPATH_ROOT : dirname(__DIR__, 3);
$viglingWorkSchedulePlugin = $viglingWorkScheduleRoot . '/plugins/user/vigling/src/Helper/WorkScheduleHelper.php';
if (
	is_file($viglingWorkSchedulePlugin)
	&& !class_exists(\Joomla\Plugin\User\Vigling\Helper\WorkScheduleHelper::class, false)
) {
	require_once $viglingWorkSchedulePlugin;
}
