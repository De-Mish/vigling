<?php

namespace Viglin\Component\Pushnotify\Administrator\Controller;

\defined('_JEXEC') or die;

use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Controller\BaseController;
use Joomla\CMS\Router\Route;

class LogsController extends BaseController
{
	public function delete()
	{
		$this->checkToken();
		$cid = array_values(array_filter(array_map('intval', (array) $this->input->get('cid', [], 'int'))));
		if ($cid === []) {
			$this->setRedirect(Route::_('index.php?option=com_pushnotify&view=logs', false), Text::_('COM_PUSHNOTIFY_NO_ITEMS_SELECTED'), 'warning');
			return;
		}
		$this->getModel('Logs')->delete($cid);
		$this->setRedirect(Route::_('index.php?option=com_pushnotify&view=logs', false), Text::sprintf('COM_PUSHNOTIFY_LOGS_DELETED', count($cid)));
	}

	public function purge()
	{
		$this->checkToken();
		$this->getModel('Logs')->purge();
		$this->setRedirect(Route::_('index.php?option=com_pushnotify&view=logs', false), Text::_('COM_PUSHNOTIFY_LOGS_PURGED'));
	}

	public function getModel($name = 'Logs', $prefix = 'Administrator', $config = ['ignore_request' => true])
	{
		return parent::getModel($name, $prefix, $config);
	}
}
