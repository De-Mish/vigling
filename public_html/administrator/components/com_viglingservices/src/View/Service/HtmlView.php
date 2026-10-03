<?php

namespace Viglin\Component\Viglingservices\Administrator\View\Service;

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Helper\ContentHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;
use Joomla\CMS\Toolbar\ToolbarHelper;

class HtmlView extends BaseHtmlView
{
    protected $item;
    protected $form;
    protected $state;

    public function display($tpl = null)
    {
        $model = $this->getModel();
        $this->form = $model->getForm();
        $this->item = $model->getItem();
        $this->state = $model->getState();
        $this->form->addControlField('task', '');
        $this->addToolbar();
        parent::display($tpl);
    }

    protected function addToolbar()
    {
        Factory::getApplication()->getInput()->set('hidemainmenu', true);
        $isNew = (int) $this->item->id === 0;
        $canDo = ContentHelper::getActions('com_viglingservices');
        $toolbar = $this->getDocument()->getToolbar();
        ToolbarHelper::title($isNew ? Text::_('COM_VIGLINGSERVICES_SERVICE_NEW') : Text::_('COM_VIGLINGSERVICES_SERVICE_EDIT'), 'list');
        if ($canDo->get('core.edit')) {
            $toolbar->apply('service.apply');
        }
        $saveGroup = $toolbar->dropdownButton('save-group');
        $saveGroup->configure(function ($childBar) use ($canDo) {
            if ($canDo->get('core.edit')) {
                $childBar->save('service.save');
            }
            if ($canDo->get('core.edit') && $canDo->get('core.create')) {
                $childBar->save2new('service.save2new');
            }
        });
        $toolbar->cancel('service.cancel');
    }
}
