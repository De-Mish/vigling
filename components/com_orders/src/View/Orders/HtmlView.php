<?php

namespace Viglin\Component\Orders\Site\View\Orders;

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;
use Joomla\CMS\Uri\Uri;

class HtmlView extends BaseHtmlView
{
	protected $items = [];

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
		$layout = $app->getInput()->getCmd('layout', 'default');
		if (in_array($layout, ['clients', 'journal'], true)) {
			$groups = $user->getAuthorisedGroups();
			if (!in_array(3, $groups) && !in_array(8, $groups)) {
				$app->enqueueMessage('Доступ только для мастеров', 'notice');
				$app->redirect(\Joomla\CMS\Router\Route::_('index.php?option=com_orders&view=orders'));
				return;
			}
		}
		/** @var \Viglin\Component\Orders\Site\Model\OrdersModel $model */
		$model = $this->getModel();
		$model->setState('layout', $layout);
		$model->setState('as_master', ($layout === 'clients' || $layout === 'journal') ? 1 : 0);
		if ($layout === 'journal') {
			$model->setState('list.limit', 500);
			$model->setState('list.start', 0);
			try {
				$db = Factory::getContainer()->get(\Joomla\Database\DatabaseInterface::class);
				require_once JPATH_SITE . '/components/com_orders/tmpl/orders/_reschedule_helper.php';
				$tzName = viglingOrdersGetUserTimezone($db, (int) $user->id, (string) $app->get('offset', 'UTC'));
				$tz = new \DateTimeZone($tzName !== '' ? $tzName : 'UTC');
				$fromUtc = (new \DateTimeImmutable('today', $tz))->modify('-21 days')->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
				$model->setState('journal.from_utc', $fromUtc);
			} catch (\Throwable $e) {
				$model->setState('journal.from_utc', (new \DateTimeImmutable('today', new \DateTimeZone('UTC')))->modify('-21 days')->format('Y-m-d H:i:s'));
			}
		}
		$this->items = $model->getItems();
		return parent::display($tpl);
	}
}
