<?php

\defined('_JEXEC') or die;

/**
 * Loads WorkScheduleHelper from the plugin folder, then from this template folder.
 * Upload this whole file together with WorkScheduleHelper.php in the same folder.
 */
if (!function_exists('vigling_load_work_schedule_helper')) {
	function vigling_load_work_schedule_helper(): bool
	{
		$class = \Joomla\Plugin\User\Vigling\Helper\WorkScheduleHelper::class;
		if (class_exists($class, false)) {
			return true;
		}

		$themes = defined('JPATH_THEMES') ? JPATH_THEMES : (defined('JPATH_ROOT') ? JPATH_ROOT . '/templates' : '');
		$candidates = [
			defined('JPATH_PLUGINS') ? JPATH_PLUGINS . '/user/vigling/src/Helper/WorkScheduleHelper.php' : '',
			__DIR__ . '/WorkScheduleHelper.php',
			$themes !== '' ? $themes . '/ryba/helpers/WorkScheduleHelper.php' : '',
		];

		foreach (array_unique(array_filter($candidates)) as $file) {
			if (is_file($file)) {
				require_once $file;
				if (class_exists($class, false)) {
					return true;
				}
			}
		}

		return class_exists($class, false);
	}
}
