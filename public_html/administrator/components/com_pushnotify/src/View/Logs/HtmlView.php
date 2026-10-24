<?php

namespace Viglin\Component\Pushnotify\Administrator\View\Logs;

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
		ToolbarHelper::title(Text::_('COM_PUSHNOTIFY_LOGS'), 'list');
		ToolbarHelper::deleteList('JGLOBAL_CONFIRM_DELETE', 'logs.delete');
		ToolbarHelper::custom('logs.purge', 'trash', '', 'COM_PUSHNOTIFY_PURGE_LOGS', false);
	}
}
