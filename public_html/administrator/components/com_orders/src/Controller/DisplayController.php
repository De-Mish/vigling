<?php

namespace Viglin\Component\Orders\Administrator\Controller;

\defined('_JEXEC') or die;

use Joomla\CMS\MVC\Controller\BaseController;

class DisplayController extends BaseController
{
	public function display($cachable = false, $urlparams = [])
	{
		$this->setRedirect(\Joomla\CMS\Uri\Uri::root() . 'index.php?option=com_orders&view=orders');
		return $this;
	}
}
