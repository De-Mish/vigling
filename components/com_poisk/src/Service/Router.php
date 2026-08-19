<?php

namespace Viglin\Component\Poisk\Site\Service;

\defined('_JEXEC') or die;

use Joomla\CMS\Application\SiteApplication;
use Joomla\CMS\Component\Router\RouterBase;
use Joomla\CMS\Menu\AbstractMenu;

class Router extends RouterBase
{
	public function __construct(SiteApplication $app, AbstractMenu $menu, $categoryFactory = null, $db = null)
	{
		parent::__construct($app, $menu);
	}

	public function build(&$query): array
	{
		$segments = [];
		$item = !empty($query['Itemid']) ? $this->menu->getItem($query['Itemid']) : null;
		if ($item && isset($item->query['option']) && $item->query['option'] === 'com_poisk' && isset($item->query['view']) && $item->query['view'] === 'list') {
			if (!empty($query['cat_id']) && (int) $query['cat_id'] > 0) {
				$segments[] = (int) $query['cat_id'];
				unset($query['cat_id']);
			}
			unset($query['view']);
		}
		return $segments;
	}

	public function parse(&$segments): array
	{
		$vars = [];
		$item = $this->menu->getActive();
		if (!$item || !isset($item->query['option']) || $item->query['option'] !== 'com_poisk') {
			return $vars;
		}
		if (isset($item->query['view']) && $item->query['view'] === 'list' && count($segments) > 0 && is_numeric($segments[0])) {
			$vars['cat_id'] = (int) array_shift($segments);
		}
		return $vars;
	}
}
