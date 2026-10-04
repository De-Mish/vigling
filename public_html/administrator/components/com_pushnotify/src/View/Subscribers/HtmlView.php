<?php

namespace Viglin\Component\Pushnotify\Administrator\View\Subscribers;

\defined('_JEXEC') or die;

use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;
use Joomla\CMS\Toolbar\ToolbarHelper;

class HtmlView extends BaseHtmlView
{
	protected $items;
	protected $pagination;
	protected $state;

	public function display($tpl = null)
	{
		$model = $this->getModel();
		$this->items = $model->getItems();
		$this->pagination = $model->getPagination();
		$this->state = $model->getState();
		$this->addToolbar();
		parent::display($tpl);
	}

	protected function addToolbar(): void
	{
		ToolbarHelper::title(Text::_('COM_PUSHNOTIFY_SUBSCRIBERS'), 'users');
		ToolbarHelper::custom('subscribers.sendTest', 'mail', '', 'COM_PUSHNOTIFY_SEND_TEST', true);
		ToolbarHelper::deleteList('JGLOBAL_CONFIRM_DELETE', 'subscribers.deleteTokens');
	}
}
