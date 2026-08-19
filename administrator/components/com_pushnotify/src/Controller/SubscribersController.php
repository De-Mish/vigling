<?php

namespace Viglin\Component\Pushnotify\Administrator\Controller;

\defined('_JEXEC') or die;

use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Controller\BaseController;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Uri\Uri;
use Viglin\Component\Pushnotify\Site\Helper\FcmHelper;

class SubscribersController extends BaseController
{
	public function deleteTokens()
	{
		$this->checkToken();
		$cid = (array) $this->input->get('cid', [], 'int');
		$cid = array_filter($cid);
		if ($cid === []) {
			$this->setRedirect(Route::_('index.php?option=com_pushnotify&view=subscribers', false), Text::_('COM_PUSHNOTIFY_NO_ITEMS_SELECTED'), 'warning');
			return;
		}
		$this->getModel('Subscribers')->deleteForUsers($cid);
		$this->setRedirect(Route::_('index.php?option=com_pushnotify&view=subscribers', false), Text::sprintf('COM_PUSHNOTIFY_USER_TOKENS_DELETED', count($cid)));
	}

	public function sendTest()
	{
		$this->checkToken();
		if (!class_exists(FcmHelper::class)) {
			$path = JPATH_SITE . '/components/com_pushnotify/src/Helper/FcmHelper.php';
			if (is_file($path)) {
				require_once $path;
			}
		}
		$cid = array_values(array_filter(array_map('intval', (array) $this->input->get('cid', [], 'int'))));
		if ($cid === []) {
			$this->setRedirect(Route::_('index.php?option=com_pushnotify&view=subscribers', false), Text::_('COM_PUSHNOTIFY_NO_ITEMS_SELECTED'), 'warning');
			return;
		}
		$sent = 0;
		foreach ($cid as $userId) {
			$result = FcmHelper::sendNotification(
				$userId,
				'Тест VIGLING',
				'Проверка push-уведомлений из админки.',
				['url' => rtrim(Uri::root(), '/') . '/lk'],
				'test',
				''
			);
			$sent += (int) ($result['sent'] ?? 0);
		}
		$this->setRedirect(Route::_('index.php?option=com_pushnotify&view=subscribers', false), Text::sprintf('COM_PUSHNOTIFY_TEST_SENT', $sent));
	}

	public function getModel($name = 'Subscribers', $prefix = 'Administrator', $config = ['ignore_request' => true])
	{
		return parent::getModel($name, $prefix, $config);
	}
}
