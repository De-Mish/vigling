<?php

namespace Viglin\Component\Aktsii\Site\View\List;

\defined('_JEXEC') or die;

use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;
use Joomla\CMS\Factory;
use Joomla\CMS\Pagination\Pagination;

class HtmlView extends BaseHtmlView
{
	protected $items = [];
	protected $pagination;
	protected $categories = [];
	protected $allCategories = [];
	protected $allServices = [];
	protected $allTags = [];
	protected $pageTitle = 'Акции';
	protected $filterTitle = 'Фильтр акций';
	protected $fieldsByUser = [];
	protected $stocksByUser = [];
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
	protected $categoryByUser = [];

	public function display($tpl = null)
	{
		/** @var \Viglin\Component\Aktsii\Site\Model\ListModel $model */
		$model = $this->getModel();
		$model->populateState();

		$this->items = $model->getItems();
		$this->mapItems = $model->getMapItems();
		$total = $model->getTotal();
		$limit = (int) $model->getState('list.limit');
		$start = (int) $model->getState('list.start');
		$this->pagination = new Pagination($total, $start, $limit);

		$this->categories = \Viglin\Component\Aktsii\Site\Helper\AktsiiHelper::getCategories();
		$this->serviceHierarchy = \Viglin\Component\Aktsii\Site\Helper\AktsiiHelper::getServiceHierarchy();
		$this->cities = \Viglin\Component\Aktsii\Site\Helper\AktsiiHelper::getCities();
		$this->areas = \Viglin\Component\Aktsii\Site\Helper\AktsiiHelper::getAreas();

		$this->allCategories = $this->loadAllCategories();
		$this->allServices = $this->loadAllServices();
		$this->allTags = $this->loadAllTags();

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
			$this->fieldsByUser = \Viglin\Component\Aktsii\Site\Helper\AktsiiHelper::getFieldsForUserIds($userIds, [
				'sity', 'area', 'street', 'house_number', 'telefon', 'about', 'avatar', 'portfolio_field', 'home', 'vyberite_spetsialnos'
			]);
			$this->stocksByUser = $this->loadStocksForUsers($userIds);
			$this->categoryByUser = $this->loadCategoryByUser($userIds);
		}

		$mapUserIds = array_map(static function ($o) { return (int) $o->id; }, (array) $this->mapItems);
		if (!empty($mapUserIds)) {
			$this->mapFieldsByUser = \Viglin\Component\Aktsii\Site\Helper\AktsiiHelper::getFieldsForUserIds($mapUserIds, [
				'sity', 'area', 'street', 'house_number', 'vyberite_spetsialnos'
			]);
		}

		return parent::display($tpl);
	}

	private function loadStocksForUsers(array $userIds): array
	{
		if (empty($userIds)) {
			return [];
		}
		$db = Factory::getContainer()->get(\Joomla\Database\DatabaseInterface::class);
		$prefix = $db->getPrefix();
		$ids = array_map('intval', $userIds);

		$query = $db->getQuery(true)
			->select('s.user_id, s.legacy_cat_id, s.legacy_tag_id, s.price, s.old_price, s.count_stock, s.about_stock, s.duration_min')
			->from($db->quoteName($prefix . 'vigling_user_stock_services', 's'))
			->whereIn('s.user_id', $ids)
			->where('s.is_active = 1');
		$db->setQuery($query);
		$rows = $db->loadObjectList() ?: [];

		$result = [];
		foreach ($rows as $row) {
			$userId = (int) $row->user_id;
			if (!isset($result[$userId])) {
				$result[$userId] = [];
			}
			$result[$userId][] = [
				'cat_id' => (int) $row->legacy_cat_id,
				'tag_id' => (int) $row->legacy_tag_id,
				'price' => (float) $row->price,
				'old_price' => $row->old_price !== null ? (float) $row->old_price : null,
				'stock_count' => (int) $row->count_stock,
				'comment' => $row->about_stock,
				'duration' => (int) $row->duration_min,
			];
		}
		return $result;
	}

	private function loadCategoryByUser(array $userIds): array
	{
		if (empty($userIds)) {
			return [];
		}
		$db = Factory::getContainer()->get(\Joomla\Database\DatabaseInterface::class);
		$prefix = $db->getPrefix();
		$ids = array_map('intval', $userIds);

		$fieldIdQuery = $db->getQuery(true)
			->select('id')
			->from($db->quoteName($prefix . 'fields'))
			->where($db->quoteName('name') . ' = ' . $db->quote('vyberite_spetsialnos'))
			->where($db->quoteName('context') . ' = ' . $db->quote('com_users.user'));
		$db->setQuery($fieldIdQuery);
		$fieldId = (int) $db->loadResult();

		if ($fieldId <= 0) {
			return [];
		}

		$query = $db->getQuery(true)
			->select('fv.item_id, fv.value')
			->from($db->quoteName($prefix . 'fields_values', 'fv'))
			->where($db->quoteName('fv.field_id') . ' = ' . $fieldId)
			->whereIn($db->quoteName('fv.item_id'), $ids);
		$db->setQuery($query);
		$rows = $db->loadObjectList() ?: [];

		$result = [];
		foreach ($rows as $row) {
			$value = json_decode($row->value, true);
			if (is_array($value) && isset($value[0])) {
				$result[(int) $row->item_id] = (int) $value[0];
			}
		}
		return $result;
	}

	private function loadAllCategories(): array
	{
		$db = Factory::getContainer()->get(\Joomla\Database\DatabaseInterface::class);
		$prefix = $db->getPrefix();
		$query = $db->getQuery(true)
			->select('id, title')
			->from($db->quoteName($prefix . 'categories'))
			->where($db->quoteName('extension') . ' = ' . $db->quote('com_content'))
			->where($db->quoteName('published') . ' = 1')
			->where($db->quoteName('level') . ' = 2');
		$db->setQuery($query);
		$rows = $db->loadAssocList('id') ?: [];
		return $rows;
	}

	private function loadAllServices(): array
	{
		$db = Factory::getContainer()->get(\Joomla\Database\DatabaseInterface::class);
		$prefix = $db->getPrefix();
		$query = $db->getQuery(true)
			->select('id, title, catid')
			->from($db->quoteName($prefix . 'content'))
			->where($db->quoteName('state') . ' = 1');
		$db->setQuery($query);
		$rows = $db->loadAssocList() ?: [];
		$result = [];
		foreach ($rows as $row) {
			$result[$row['id']] = $row;
		}
		return $result;
	}

	private function loadAllTags(): array
	{
		$db = Factory::getContainer()->get(\Joomla\Database\DatabaseInterface::class);
		$prefix = $db->getPrefix();
		$query = $db->getQuery(true)
			->select('id, title')
			->from($db->quoteName($prefix . 'tags'))
			->where($db->quoteName('published') . ' = 1');
		$db->setQuery($query);
		$rows = $db->loadAssocList() ?: [];
		$result = [];
		foreach ($rows as $row) {
			$result[$row['id']] = $row;
		}
		return $result;
	}
}