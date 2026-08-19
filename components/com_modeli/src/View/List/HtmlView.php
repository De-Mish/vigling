<?php

namespace Viglin\Component\Modeli\Site\View\List;

\defined('_JEXEC') or die;

use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;
use Joomla\CMS\Pagination\Pagination;

class HtmlView extends BaseHtmlView
{
	protected $items = [];
	protected $pagination;
	protected $categories = [];
	protected $pageTitle = 'Поиск моделей';
	protected $fieldsByUser = [];
	protected $mapItems = [];
	protected $mapFieldsByUser = [];
	protected $cities = [];
	protected $areas = [];
	protected $homeOptions = [];
	protected $listOrder = 'newest';
	protected $listDirn = 'DESC';
	protected $currentBookingMode = '';
	protected $modelError = '';

	public function display($tpl = null)
	{
		/** @var \Viglin\Component\Modeli\Site\Model\ListModel $model */
		$model = $this->getModel();
		$model->populateState();

		$this->items = $model->getItems();
		$this->mapItems = $model->getMapItems();
		$this->modelError = (string) $model->getError();
		$total = $model->getTotal();
		$limit = (int) $model->getState('list.limit');
		$start = (int) $model->getState('list.start');
		$this->pagination = new Pagination($total, $start, $limit);

		$this->categories = \Viglin\Component\Modeli\Site\Helper\ModeliHelper::getCategories();
		$this->cities = \Viglin\Component\Modeli\Site\Helper\ModeliHelper::getCities();
		$this->areas = \Viglin\Component\Modeli\Site\Helper\ModeliHelper::getAreas();
		$this->homeOptions = \Viglin\Component\Modeli\Site\Helper\ModeliHelper::getHomeOptions();
		$this->listOrder = $model->getState('list.ordering', 'newest');
		$this->listDirn = $model->getState('list.direction', 'DESC');
		$this->currentBookingMode = (string) $model->getState('booking_mode', '');

		$catId = (int) $model->getState('cat_id');
		if ($catId && isset($this->categories[$catId])) {
			$this->pageTitle = 'Поиск моделей: ' . trim((string) ($this->categories[$catId]['filter_title'] ?? $this->categories[$catId]['title'] ?? ''));
		}

		$userIds = array_values(array_unique(array_map(static function ($item) {
			return (int) ($item->master_id ?? 0);
		}, $this->items)));
		if ($userIds !== []) {
			$this->fieldsByUser = \Viglin\Component\Modeli\Site\Helper\ModeliHelper::getFieldsForUserIds($userIds, [
				'sity', 'area', 'street', 'house_number', 'about', 'avatar', 'portfolio_field', 'home',
			]);
		}

		$mapUserIds = array_values(array_unique(array_map(static function ($item) {
			return (int) ($item->master_id ?? 0);
		}, $this->mapItems)));
		if ($mapUserIds !== []) {
			$this->mapFieldsByUser = \Viglin\Component\Modeli\Site\Helper\ModeliHelper::getFieldsForUserIds($mapUserIds, [
				'sity', 'area', 'street', 'house_number',
			]);
		}

		return parent::display($tpl);
	}
}