<?php

namespace Viglin\Component\Orders\Site\View\Orders;

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;
use Joomla\CMS\Uri\Uri;
use Viglin\Component\Orders\Site\Helper\AppointmentsHelper;

class HtmlView extends BaseHtmlView
{
	public $items = [];

	public $appointmentsMode = 'day';

	public $appointmentsEmbed = false;

	public $canBookTime = false;

	public $weekStartLocal;

	public $weekPrevUrl = '';

	public $weekNextUrl = '';

	public $monthCursor;

	public $monthPrevUrl = '';

	public $monthNextUrl = '';

	public $appointmentsBaseUrl = '';

	public $dayUrl = '';

	public $weekUrl = '';

	public $monthUrl = '';

	public function display($tpl = null)
	{
		$app = Factory::getApplication();
		$user = $app->getIdentity();
		if (!$user->id) {
			$return = base64_encode(Uri::getInstance()->toString());
			$app->enqueueMessage('Войдите в личный кабинет', 'notice');
			$app->redirect(\Joomla\CMS\Router\Route::_('index.php?option=com_users&view=login&return=' . $return));
			return;
		}

		if (!class_exists(AppointmentsHelper::class, false)) {
			require_once JPATH_SITE . '/components/com_orders/src/Helper/AppointmentsHelper.php';
		}

		$input = $app->getInput();
		$layout = $input->getCmd('layout', 'default');
		$format = $input->getCmd('format', 'html');
		$combined = in_array($layout, ['default', 'clients', 'journal', 'appointments'], true);
		if ($combined && $format === 'html') {
			$app->redirect(AppointmentsHelper::redirectUrlFromRequest($input));
			return;
		}

		AppointmentsHelper::fill($this, $input);
		$this->setLayout('appointments');
		$app->getDocument()->setTitle('Записи');
		return parent::display($tpl);
	}
}
