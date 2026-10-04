<?php

namespace Viglin\Component\Pushnotify\Administrator\Controller;

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Controller\BaseController;
use Joomla\CMS\Router\Route;

class DisplayController extends BaseController
{
	protected $default_view = 'settings';

	public function addMenuItem()
	{
		$this->checkToken('get');
		$db = Factory::getContainer()->get(\Joomla\Database\DatabaseInterface::class);
		$exists = $db->setQuery(
			$db->getQuery(true)
				->select('1')
				->from('#__menu')
				->where('client_id = 1')
				->where('link LIKE ' . $db->quote('%option=com_pushnotify%'))
		)->loadResult();
		if ($exists) {
			$this->setRedirect(Route::_('index.php?option=com_pushnotify&view=settings', false), Text::_('COM_PUSHNOTIFY_MENU_ALREADY_EXISTS'), 'notice');
			return;
		}
		$eid = $db->setQuery(
			$db->getQuery(true)
				->select('extension_id')
				->from('#__extensions')
				->where('type = ' . $db->quote('component'))
				->where('element = ' . $db->quote('com_pushnotify'))
		)->loadResult();
		if (!$eid) {
			$this->setRedirect(Route::_('index.php?option=com_pushnotify&view=settings', false), Text::_('COM_PUSHNOTIFY_COMPONENT_NOT_FOUND'), 'error');
			return;
		}
		$rgtQuery = $db->getQuery(true)
			->select('rgt')
			->from('#__menu')
			->where('menutype = ' . $db->quote('main'))
			->where('client_id = 1')
			->where('parent_id = 1')
			->order('rgt DESC');
		$R = (int) $db->setQuery($rgtQuery)->loadResult();
		if ($R <= 0) {
			$R = (int) $db->setQuery(
				$db->getQuery(true)->select('rgt')->from('#__menu')->where('id = 1')
			)->loadResult();
		}
		if ($R <= 0) {
			$this->setRedirect(Route::_('index.php?option=com_pushnotify&view=settings', false), Text::_('COM_PUSHNOTIFY_MENU_CONTAINER_NOT_FOUND'), 'error');
			return;
		}
		$menu = '#__menu';
		$db->setQuery("UPDATE {$menu} SET rgt = rgt + 2 WHERE menutype = 'main' AND client_id = 1 AND rgt >= " . (int) $R)->execute();
		$db->setQuery("UPDATE {$menu} SET lft = lft + 2 WHERE menutype = 'main' AND client_id = 1 AND lft > " . (int) $R)->execute();
		$title = $db->escape('COM_PUSHNOTIFY_MANAGEMENT');
		$link = $db->escape('index.php?option=com_pushnotify');
		$alias = $db->escape('notification-management');
		$img = $db->escape('class:bell');
		$insert = "INSERT INTO {$menu} (menutype, title, alias, path, link, type, published, parent_id, level, component_id, checked_out, checked_out_time, browserNav, access, img, template_style_id, params, lft, rgt, home, language, client_id) VALUES "
			. "('main', '{$title}', '{$alias}', '{$alias}', '{$link}', 'component', 1, 1, 2, " . (int) $eid . ", 0, '1970-01-01 00:00:00', 0, 1, '{$img}', 0, '{}', {$R}, " . ($R + 1) . ", 0, '*', 1)";
		try {
			$db->setQuery($insert)->execute();
		} catch (\Exception $e) {
			$this->setRedirect(Route::_('index.php?option=com_pushnotify&view=settings', false), $e->getMessage(), 'error');
			return;
		}
		$this->setRedirect(Route::_('index.php?option=com_pushnotify&view=settings', false), Text::_('COM_PUSHNOTIFY_MENU_ADDED'));
	}
}
