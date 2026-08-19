<?php

namespace Viglin\Component\Pushnotify\Administrator\Controller;

\defined('_JEXEC') or die;

use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Controller\BaseController;
use Joomla\CMS\Router\Route;

class SettingsController extends BaseController
{
	public function save()
	{
		$this->checkToken();
		$model = $this->getModel('Settings');
		$data = $this->input->post->get('jform', [], 'array');
		if ($model->saveFromInput($data)) {
			$this->setRedirect(Route::_('index.php?option=com_pushnotify&view=settings', false), Text::_('COM_PUSHNOTIFY_SETTINGS_SAVED'));
			return;
		}
		$this->setRedirect(Route::_('index.php?option=com_pushnotify&view=settings', false), Text::_('COM_PUSHNOTIFY_SETTINGS_SAVE_FAILED'), 'error');
	}

	public function cancel()
	{
		$this->setRedirect(Route::_('index.php?option=com_pushnotify&view=settings', false));
	}

	public function getModel($name = 'Settings', $prefix = 'Administrator', $config = ['ignore_request' => true])
	{
		return parent::getModel($name, $prefix, $config);
	}
}
