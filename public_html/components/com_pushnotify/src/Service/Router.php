<?php

namespace Viglin\Component\Pushnotify\Site\Service;

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
		return [];
	}

	public function parse(&$segments): array
	{
		return [];
	}
}
