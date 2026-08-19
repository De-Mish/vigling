<?php

namespace Viglin\Component\Poisk\Administrator\Controller;

\defined('_JEXEC') or die;

use Joomla\CMS\MVC\Controller\BaseController;

class DisplayController extends BaseController
{
	public function display($cachable = false, $urlparams = [])
	{
		$this->setRedirect(\Joomla\CMS\Uri\Uri::root() . 'index.php?option=com_poisk&view=list');
		return $this;
	}
}
