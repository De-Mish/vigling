<?php

\defined('_JEXEC') or die;

/**
 * The schedule class lives in templates/ryba/helpers/offline.php.
 * This file only loads it. templates/ryba/offline.php is the site offline page.
 */
$viglingWorkScheduleRoot = \defined('JPATH_ROOT') ? JPATH_ROOT : dirname(__DIR__, 5);
$viglingWorkScheduleClass = $viglingWorkScheduleRoot . '/templates/ryba/helpers/offline.php';
if (
	is_file($viglingWorkScheduleClass)
	&& realpath($viglingWorkScheduleClass) !== realpath(__FILE__)
	&& !class_exists(\Joomla\Plugin\User\Vigling\Helper\WorkScheduleHelper::class, false)
) {
	require_once $viglingWorkScheduleClass;
}
