<?php

namespace Viglin\Component\Pushnotify\Site\View\Display;

\defined('_JEXEC') or die;

use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;
use Joomla\CMS\Factory;
use Joomla\CMS\Uri\Uri;

class HtmlView extends BaseHtmlView
{
	public function display($tpl = null)
	{
		$app = Factory::getApplication();
		$app->redirect(Uri::root());
		return false;
	}
}
