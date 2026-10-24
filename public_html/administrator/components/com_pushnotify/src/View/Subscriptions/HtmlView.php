<?php

namespace Viglin\Component\Pushnotify\Administrator\View\Subscriptions;

\defined('_JEXEC') or die;

use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Session\Session;
use Joomla\CMS\Toolbar\ToolbarHelper;

class HtmlView extends BaseHtmlView
{
	protected $items;

	protected $pagination;

	protected $total;
	protected $state;

	public function display($tpl = null)
	{
		$model = $this->getModel();
		$this->items = $model->getItems();
		$this->pagination = $model->getPagination();
		$this->total = $model->getTotal();
		$this->state = $model->getState();
		$this->addToolbar();
		parent::display($tpl);
	}

	protected function addToolbar(): void
	{
		ToolbarHelper::title(Text::_('COM_PUSHNOTIFY_TOKENS'), 'bell');
		$toolbar = $this->getDocument()->getToolbar();
		$toolbar->link(
			Text::_('COM_PUSHNOTIFY_ADD_TO_MENU'),
			Route::_('index.php?option=com_pushnotify&task=display.addMenuItem&' . Session::getFormToken() . '=1')
		)->icon('icon-list');
		$toolbar->delete('subscriptions.delete')
			->message('JGLOBAL_CONFIRM_DELETE')
			->listCheck(true);
	}
}
