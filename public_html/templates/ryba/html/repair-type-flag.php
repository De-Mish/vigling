<?php
defined('_JEXEC') or die;

/**
 * The "Заточка/Ремонт" account type is hidden from the site for now.
 * Set the return value to true to show it again (registration button,
 * profile role label, menu items).
 */
if (!function_exists('ryba_repair_type_enabled')) {
	function ryba_repair_type_enabled(): bool
	{
		return false;
	}
}
