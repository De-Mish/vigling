<?php

namespace Viglin\Component\Pushnotify\Administrator\View\Settings;

\defined('_JEXEC') or die;

use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;
use Joomla\CMS\Toolbar\ToolbarHelper;

class HtmlView extends BaseHtmlView
{
	protected array $notifications = [];
	protected array $emailVerification = [];
	protected array $stats = [];

	public function display($tpl = null)
	{
		$model = $this->getModel();
		$this->notifications = $model->getNotificationSettings();
		$this->emailVerification = $model->getEmailVerificationSettings();
		$this->stats = $model->getStats();
		$this->addToolbar();
		parent::display($tpl);
	}

	protected function addToolbar(): void
	{
		ToolbarHelper::title(Text::_('COM_PUSHNOTIFY_MANAGEMENT'), 'bell');
		ToolbarHelper::apply('settings.save');
		ToolbarHelper::save('settings.save');
		ToolbarHelper::cancel('settings.cancel');
	}
}
