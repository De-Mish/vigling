<?php
namespace Viglin\Component\Viglingservices\Administrator\View\Services;
\defined('_JEXEC') or die;
use Joomla\CMS\Helper\ContentHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;
use Joomla\CMS\Toolbar\ToolbarHelper;
class HtmlView extends BaseHtmlView {
    protected $items;
    protected $pagination;
    protected $state;
    protected $parentOptions;
    public $filterForm;
    public $activeFilters;
    public function display($tpl = null) {
        $model = $this->getModel();
        $this->items = $model->getItems();
        $this->pagination = $model->getPagination();
        $this->state = $model->getState();
        $this->parentOptions = $model->getParentOptions();
        $this->filterForm = $model->getFilterForm();
        $this->activeFilters = $model->getActiveFilters();
        $this->addToolbar();
        parent::display($tpl);
    }
    protected function addToolbar() {
        $canDo = ContentHelper::getActions('com_viglingservices');
        $toolbar = $this->getDocument()->getToolbar();
        ToolbarHelper::title(Text::_('COM_VIGLINGSERVICES'), 'list');
        if ($canDo->get('core.create')) { $toolbar->addNew('service.add'); }
        if ($canDo->get('core.delete') && !empty($this->items)) {
            $toolbar->delete('services.delete')->message('JGLOBAL_CONFIRM_DELETE')->listCheck(true);
        }
    }
}
