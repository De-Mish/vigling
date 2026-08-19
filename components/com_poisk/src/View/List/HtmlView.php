<?php

namespace Viglin\Component\Poisk\Site\View\List;

\defined('_JEXEC') or die;

use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;
use Joomla\CMS\Factory;
use Joomla\CMS\Pagination\Pagination;
use Joomla\CMS\Uri\Uri;

class HtmlView extends BaseHtmlView
{
	protected $items = [];
	protected $pagination;
	protected $categories = [];
	protected $pageTitle = 'Все специалисты';
	protected $filterTitle = 'Фильтр специалистов';
	protected $fieldsByUser = [];
	protected $mapItems = [];
	protected $mapFieldsByUser = [];
	protected $cities = [];
	protected $areas = [];
	protected $services = [];
	protected $tags = [];
	protected $serviceHierarchy = [];
	protected $currentService = 0;
	protected $currentTag = 0;
	protected $listOrder = 'id';
	protected $listDirn = 'ASC';

	public function display($tpl = null)
	{
		/** @var \Viglin\Component\Poisk\Site\Model\ListModel $model */
		$model = $this->getModel();
		$model->populateState();

		$pathPrefix = null;
		$branchScope = 'default';
		$menu = Factory::getApplication()->getMenu()->getActive();
		if ($menu) {
			$pathPrefix = isset($menu->query['category_path']) && $menu->query['category_path'] !== ''
				? (string) $menu->query['category_path']
				: null;
			$menuAlias = (string) ($menu->alias ?? '');
			$menuPath = (string) ($menu->path ?? '');
			$params = $menu->getParams();
			if ($pathPrefix === null && $params) {
				$pathPrefix = $params->get('category_path', '');
				$pathPrefix = $pathPrefix !== '' ? (string) $pathPrefix : null;
			}
			if ($pathPrefix === 'zatochka-remont'
				|| $menuAlias === 'zatochka-remont'
				|| $menuPath === 'zatochka-remont'
				|| str_starts_with($menuPath, 'zatochka-remont/')) {
				$branchScope = 'zatochka-remont';
			}
			if ($pathPrefix !== null && $params) {
				$t = $params->get('list_page_title', '');
				if ($t !== '') {
					$this->pageTitle = (string) $t;
				} elseif ($pathPrefix === 'zatochka-remont') {
					$this->pageTitle = 'Заточка ремонт';
				}
				$t = $params->get('list_filter_title', '');
				if ($t !== '') {
					$this->filterTitle = (string) $t;
				} elseif ($pathPrefix === 'zatochka-remont') {
					$this->filterTitle = 'Фильтр';
				}
			}
		}
		$requestPath = trim((string) Uri::getInstance()->getPath(), '/');
		if ($requestPath === 'zatochka-remont' || str_starts_with($requestPath, 'zatochka-remont/')) {
			$branchScope = 'zatochka-remont';
			$pathPrefix = 'zatochka-remont';
		} elseif ($requestPath === 'poisk-spetsialistov' || str_starts_with($requestPath, 'poisk-spetsialistov/')) {
			$branchScope = 'default';
		}

		$model->setState('category_path_prefix', $pathPrefix);
		$model->setState('branch_scope', $branchScope);

		$this->items = $model->getItems();
		$this->mapItems = $model->getMapItems();
		
		$total = $model->getTotal();
		$limit = (int) $model->getState('list.limit');
		$start = (int) $model->getState('list.start');
		$this->pagination = new Pagination($total, $start, $limit);

		$this->categories = \Viglin\Component\Poisk\Site\Helper\PoiskHelper::getCategories($branchScope === 'zatochka-remont' ? 'zatochka-remont' : null);
		$this->serviceHierarchy = \Viglin\Component\Poisk\Site\Helper\PoiskHelper::getServiceHierarchy($branchScope === 'zatochka-remont' ? 'zatochka-remont' : null);
		$this->cities = \Viglin\Component\Poisk\Site\Helper\PoiskHelper::getCities();
		$this->areas = \Viglin\Component\Poisk\Site\Helper\PoiskHelper::getAreas();
		$this->listOrder = $model->getState('list.ordering', 'id');
		$this->listDirn = $model->getState('list.direction', 'ASC');
		$catId = (int) $model->getState('cat_id');
		$this->currentService = (int) $model->getState('service', 0);
		$this->currentTag = (int) $model->getState('tag', 0);
		$this->services = $catId > 0 ? (array) ($this->serviceHierarchy[$catId] ?? []) : [];
		$this->tags = [];
		if ($this->currentService > 0) {
			foreach ($this->services as $service) {
				if ((int) ($service['id'] ?? 0) === $this->currentService) {
					$this->tags = (array) ($service['tags'] ?? []);
					break;
				}
			}
		}
		if ($catId && isset($this->categories[$catId])) {
			$this->pageTitle = $this->categories[$catId]['title'];
		}

		$userIds = array_map(function ($o) {
			return $o->id;
		}, $this->items);
		if (!empty($userIds)) {
			$this->fieldsByUser = \Viglin\Component\Poisk\Site\Helper\PoiskHelper::getFieldsForUserIds($userIds, [
				'sity', 'area', 'street', 'house_number', 'telefon', 'about', 'avatar', 'portfolio_field', 'home', 'vyberite_spetsialnos'
			]);
		}
		$mapUserIds = array_map(static function ($o) { return (int) $o->id; }, (array) $this->mapItems);
		if (!empty($mapUserIds)) {
			$this->mapFieldsByUser = \Viglin\Component\Poisk\Site\Helper\PoiskHelper::getFieldsForUserIds($mapUserIds, [
				'sity', 'area', 'street', 'house_number', 'vyberite_spetsialnos'
			]);
		}

		return parent::display($tpl);
	}
}