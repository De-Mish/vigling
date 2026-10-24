<?php

namespace Viglin\Component\Modeli\Administrator\Controller;

\defined('_JEXEC') or die;

use Joomla\CMS\MVC\Controller\BaseController;
use Joomla\CMS\Uri\Uri;

class DisplayController extends BaseController
{
	public function display($cachable = false, $urlparams = [])
	{
		$this->setRedirect(Uri::root() . 'index.php?option=com_modeli&view=list');

		return $this;
	}
}
