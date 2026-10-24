<?php

namespace Viglin\Component\Kurs\Administrator\Controller;

\defined('_JEXEC') or die;

use Joomla\CMS\MVC\Controller\BaseController;
use Joomla\CMS\Uri\Uri;

class DisplayController extends BaseController
{
	public function display($cachable = false, $urlparams = [])
	{
		$this->setRedirect(Uri::root() . 'index.php?option=com_kurs&view=list');

		return $this;
	}
}
