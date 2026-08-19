<?php

namespace Viglin\Component\Pushnotify\Administrator\Controller;

\defined('_JEXEC') or die;

use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Controller\BaseController;
use Joomla\CMS\Router\Route;

class SubscriptionsController extends BaseController
{
	public function delete()
	{
		$this->checkToken();
		$cid = (array) $this->input->get('cid', [], 'int');
		$cid = array_filter($cid);
		if (empty($cid)) {
			$this->setRedirect(Route::_('index.php?option=com_pushnotify&view=subscriptions', false), Text::_('COM_PUSHNOTIFY_NO_ITEMS_SELECTED'), 'warning');
			return;
		}
		$model = $this->getModel('Subscriptions');
		$model->delete($cid);
		$this->setRedirect(Route::_('index.php?option=com_pushnotify&view=subscriptions', false), Text::plural('COM_PUSHNOTIFY_N_TOKENS_DELETED', \count($cid)));
	}

	public function getModel($name = 'Subscriptions', $prefix = 'Administrator', $config = ['ignore_request' => true])
	{
		return parent::getModel($name, $prefix, $config);
	}
}
