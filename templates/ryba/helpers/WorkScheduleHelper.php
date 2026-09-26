<?php

\defined('_JEXEC') or die;

/**
 * One copy of the schedule parser lives in the user plugin.
 * This file only loads it so older template paths keep working.
 */
$viglingWorkSchedulePlugin = dirname(__DIR__, 3) . '/plugins/user/vigling/src/Helper/WorkScheduleHelper.php';
if (is_file($viglingWorkSchedulePlugin)) {
	require_once $viglingWorkSchedulePlugin;
}
