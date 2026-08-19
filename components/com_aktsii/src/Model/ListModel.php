<?php

namespace Viglin\Component\Aktsii\Site\Model;

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\MVC\Model\ListModel as BaseListModel;

class ListModel extends BaseListModel
{
	private const MAP_ITEMS_LIMIT = 500;

	private $totalCache = [];
	private $itemsCache = [];
	private $mapItemsCache = [];
	private $fieldIdsCache = null;

	protected function getStoreId($id = '')
	{
		$id .= ':' . (int) $this->getState('cat_id');
		$id .= ':' . (int) $this->getState('service');
		$id .= ':' . (int) $this->getState('tag');
		$id .= ':' . $this->getState('city');
		$id .= ':' . $this->getState('area');
		$id .= ':' . serialize($this->getState('home'));
		$id .= ':' . $this->getState('avail_date');
		$id .= ':' . $this->getState('list.ordering');
		$id .= ':' . $this->getState('list.direction');
		$id .= ':' . (int) $this->getState('list.start');
		$id .= ':' . (int) $this->getState('list.limit');
		return parent::getStoreId($id);
	}

	public function getTotal(): int
	{
		$store = $this->getStoreId('total');
		if (array_key_exists($store, $this->totalCache)) {
			return (int) $this->totalCache[$store];
		}
		try {
			$db = $this->getDatabase();
			$db->setQuery($this->buildCountQuery());
			$this->totalCache[$store] = (int) $db->loadResult();
		} catch (\Throwable $e) {
			$this->setError($e->getMessage());
			return 0;
		}
		return (int) $this->totalCache[$store];
	}

	public function getItems(): array
	{
		$store = $this->getStoreId();
		if (array_key_exists($store, $this->itemsCache)) {
			return (array) $this->itemsCache[$store];
		}
		try {
			$limit = (int) $this->getState('list.limit');
			$start = (int) $this->getState('list.start');
			if ($limit < 1) {
				$limit = 20;
			}
			if ($limit > 50) {
				$limit = 50;
			}
			$query = $this->buildListQuery();
			$query->setLimit($limit, $start);
			$this->getDatabase()->setQuery($query);
			$this->itemsCache[$store] = $this->getDatabase()->loadObjectList() ?: [];
		} catch (\Throwable $e) {
			$this->setError($e->getMessage());
			return [];
		}
		return (array) $this->itemsCache[$store];
	}

	public function getMapItems(): array
	{
		$store = $this->getStoreId('map');
		if (array_key_exists($store, $this->mapItemsCache)) {
			return (array) $this->mapItemsCache[$store];
		}
		try {
			$query = $this->buildListQuery();
			$query->setLimit(self::MAP_ITEMS_LIMIT, 0);
			$this->getDatabase()->setQuery($query);
			$this->mapItemsCache[$store] = $this->getDatabase()->loadObjectList() ?: [];
		} catch (\Throwable $e) {
			$this->setError($e->getMessage());
			return [];
		}
		return (array) $this->mapItemsCache[$store];
	}

	protected function buildCountQuery()
	{
		$db = $this->getDatabase();
		$prefix = $db->getPrefix();
		$tblU = $db->quoteName($prefix . 'users', 'u');
		$fieldIds = $this->getUserFieldIds($db, $prefix);

		$q = $db->getQuery(true)
			->select('COUNT(DISTINCT u.id)')
			->from($tblU)
			->where('u.block = 0');
		$this->applyFiltersToQuery($q, $db, $prefix, $fieldIds);
		return $q;
	}

	protected function buildListQuery()
	{
		$db = $this->getDatabase();
		$prefix = $db->getPrefix();
		$tblU = $db->quoteName($prefix . 'users', 'u');
		$fieldIds = $this->getUserFieldIds($db, $prefix);

		$q = $db->getQuery(true)
			->select('DISTINCT u.id, u.name')
			->from($tblU)
			->where('u.block = 0');
		$this->applyFiltersToQuery($q, $db, $prefix, $fieldIds);

		$orderCol = $this->getState('list.ordering', 'id');
		$orderDir = strtoupper((string) $this->getState('list.direction', 'ASC'));
		if ($orderDir !== 'DESC') {
			$orderDir = 'ASC';
		}

		if ($orderCol === 'price') {
			$stockTable = $db->quoteName($prefix . 'vigling_user_stock_services', 'vuss');
			$q->select('MIN(' . $db->quoteName('vuss.price') . ') AS min_stock_price');
			$q->join('LEFT', $stockTable . ' ON ' . $db->quoteName('vuss.user_id') . ' = ' . $db->quoteName('u.id') . ' AND ' . $db->quoteName('vuss.is_active') . ' = 1');
			$q->group('u.id');
			$q->order('min_stock_price ' . $orderDir);
		} elseif ($orderCol === 'id') {
			$q->order('u.id DESC');
		} elseif ($orderCol === 'rate') {
			$q->order('u.id DESC');
		} else {
			$q->order('u.name ' . $orderDir);
		}
		return $q;
	}

	private function applyFiltersToQuery($q, $db, $prefix, array $fieldIds): void
	{
		$fieldVyberite = (int) ($fieldIds['vyberite_spetsialnos'] ?? 0);
		$fieldCity = (int) ($fieldIds['sity'] ?? 0);
		$fieldArea = (int) ($fieldIds['area'] ?? 0);
		$fieldHome = (int) ($fieldIds['home'] ?? 0);
		$fieldWorkDay = (int) ($fieldIds['work_day'] ?? 0);
		$fieldWorkFrom = (int) ($fieldIds['work_from'] ?? 0);
		$fieldWorkTo = (int) ($fieldIds['work_to'] ?? 0);

		if ($fieldVyberite <= 0) {
			$q->where('1 = 0');
			return;
		}

		$catId = (int) $this->getState('cat_id');
		$serviceId = (int) $this->getState('service');
		$tagId = (int) $this->getState('tag');

		$specialistConds = [
			$db->quoteName('specfv.item_id') . ' = CAST(' . $db->quoteName('u.id') . ' AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_unicode_ci',
			$db->quoteName('specfv.field_id') . ' = ' . $fieldVyberite,
			$db->quoteName('specfv.value') . ' <> ' . $db->quote(''),
			$db->quoteName('specfv.value') . ' <> ' . $db->quote('{}'),
		];
		if ($catId > 0) {
			$specialistConds[] = $db->quoteName('specfv.value') . ' LIKE ' . $db->quote('%"' . $catId . '"%');
		}
		$q->where(
			'EXISTS (SELECT 1 FROM ' . $db->quoteName($prefix . 'fields_values', 'specfv')
			. ' WHERE ' . implode(' AND ', $specialistConds) . ')'
		);

		$stockTable = $db->quoteName($prefix . 'vigling_user_stock_services', 'vuss');
		$stockConds = [
			$db->quoteName('vuss.user_id') . ' = ' . $db->quoteName('u.id'),
			$db->quoteName('vuss.is_active') . ' = 1',
		];

		if ($serviceId > 0) {
			$stockConds[] = $db->quoteName('vuss.legacy_cat_id') . ' = ' . $serviceId;
		}

		if ($tagId > 0) {
			$stockConds[] = $db->quoteName('vuss.legacy_tag_id') . ' = ' . $tagId;
		}

		$q->where(
			'EXISTS (SELECT 1 FROM ' . $stockTable
			. ' WHERE ' . implode(' AND ', $stockConds) . ')'
		);

		$city = trim((string) $this->getState('city'));
		if ($city !== '' && $fieldCity > 0) {
			$q->where(
				'EXISTS (SELECT 1 FROM ' . $db->quoteName($prefix . 'fields_values', 'cityfv')
				. ' WHERE ' . $db->quoteName('cityfv.item_id') . ' = CAST(' . $db->quoteName('u.id') . ' AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_unicode_ci'
				. ' AND ' . $db->quoteName('cityfv.field_id') . ' = ' . $fieldCity
				. ' AND (' . $db->quoteName('cityfv.value') . ' = ' . $db->quote($city)
				. ' OR ' . $db->quoteName('cityfv.value') . ' = ' . $db->quote(' ' . $city)
				. ' OR ' . $db->quoteName('cityfv.value') . ' = ' . $db->quote($city . ' ') . '))'
			);
		}

		$area = trim((string) $this->getState('area'));
		if ($area !== '' && $fieldArea > 0) {
			$q->where(
				'EXISTS (SELECT 1 FROM ' . $db->quoteName($prefix . 'fields_values', 'areafv')
				. ' WHERE ' . $db->quoteName('areafv.item_id') . ' = CAST(' . $db->quoteName('u.id') . ' AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_unicode_ci'
				. ' AND ' . $db->quoteName('areafv.field_id') . ' = ' . $fieldArea
				. ' AND (' . $db->quoteName('areafv.value') . ' = ' . $db->quote($area)
				. ' OR ' . $db->quoteName('areafv.value') . ' = ' . $db->quote(' ' . $area)
				. ' OR ' . $db->quoteName('areafv.value') . ' = ' . $db->quote($area . ' ') . '))'
			);
		}

		$homeArr = $this->getState('home');
		if (!empty($homeArr) && is_array($homeArr) && $fieldHome > 0) {
			$conds = [];
			foreach ($homeArr as $h) {
				$h = (int) $h;
				if ($h >= 1 && $h <= 3) {
					$conds[] = 'homefv.value LIKE ' . $db->quote('%"' . $h . '"%');
				}
			}
			if ($conds !== []) {
				$q->where(
					'EXISTS (SELECT 1 FROM ' . $db->quoteName($prefix . 'fields_values', 'homefv')
					. ' WHERE ' . $db->quoteName('homefv.item_id') . ' = CAST(' . $db->quoteName('u.id') . ' AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_unicode_ci'
					. ' AND ' . $db->quoteName('homefv.field_id') . ' = ' . $fieldHome
					. ' AND (' . implode(' OR ', $conds) . '))'
				);
			}
		}

		$availDate = trim((string) $this->getState('avail_date'));
		if ($availDate !== '') {
			$dateOnly = '';
			$time = '';
			if (preg_match('/^(\d{4}-\d{2}-\d{2})(?:[ T](\d{2}:\d{2}))?$/', $availDate, $m)) {
				$dateOnly = $m[1];
				$time = $m[2] ?? '';
			}
			if ($dateOnly !== '' && $time !== '') {
				try {
					$dt = new \DateTime($dateOnly);
					$weekday = (int) $dt->format('N');
					$timeCompare = $time . ':00';
					
					$conditions = array();
					$hasWorkData = false;
					
					if ($fieldWorkDay > 0) {
						$conditions[] = 'EXISTS (SELECT 1 FROM ' . $db->quoteName($prefix . 'fields_values', 'wdfv')
							. ' WHERE ' . $db->quoteName('wdfv.item_id') . ' = CAST(' . $db->quoteName('u.id') . ' AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_unicode_ci'
							. ' AND ' . $db->quoteName('wdfv.field_id') . ' = ' . $fieldWorkDay
							. ' AND ' . $db->quoteName('wdfv.value') . ' LIKE ' . $db->quote('%"' . $weekday . '"%') . ')';
						$hasWorkData = true;
					}
					
					if ($fieldWorkFrom > 0) {
						$conditions[] = 'EXISTS (SELECT 1 FROM ' . $db->quoteName($prefix . 'fields_values', 'wffv')
							. ' WHERE ' . $db->quoteName('wffv.item_id') . ' = CAST(' . $db->quoteName('u.id') . ' AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_unicode_ci'
							. ' AND ' . $db->quoteName('wffv.field_id') . ' = ' . $fieldWorkFrom
							. ' AND ' . $db->quoteName('wffv.value') . ' <> ' . $db->quote('')
							. ' AND STR_TO_DATE(REPLACE(' . $db->quoteName('wffv.value') . ', ".", ":"), "%H:%i") <= STR_TO_DATE(' . $db->quote($timeCompare) . ', "%H:%i:%s"))';
						$hasWorkData = true;
					}
					
					if ($fieldWorkTo > 0) {
						$conditions[] = 'EXISTS (SELECT 1 FROM ' . $db->quoteName($prefix . 'fields_values', 'wtfv')
							. ' WHERE ' . $db->quoteName('wtfv.item_id') . ' = CAST(' . $db->quoteName('u.id') . ' AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_unicode_ci'
							. ' AND ' . $db->quoteName('wtfv.field_id') . ' = ' . $fieldWorkTo
							. ' AND ' . $db->quoteName('wtfv.value') . ' <> ' . $db->quote('')
							. ' AND STR_TO_DATE(REPLACE(' . $db->quoteName('wtfv.value') . ', ".", ":"), "%H:%i") >= STR_TO_DATE(' . $db->quote($timeCompare) . ', "%H:%i:%s"))';
						$hasWorkData = true;
					}
					
					if ($hasWorkData) {
						$q->where('(' . implode(' AND ', $conditions) . ')');
					}
					
					$this->applyBusyMasterFilter($q, $db, $prefix, $dateOnly . ' ' . $time . ':00');
					
				} catch (\Throwable $e) {
				}
			}
		}
	}

	private function applyBusyMasterFilter($q, $db, string $prefix, string $dt): void
	{
		$dtQ = $db->quote($dt);
		$q->where(
			'NOT EXISTS (SELECT 1 FROM ' . $db->quoteName($prefix . 'vigling_bookings', 'b')
			. ' WHERE ' . $db->quoteName('b.master_id') . ' = ' . $db->quoteName('u.id')
			. ' AND ' . $db->quoteName('b.time') . ' <= ' . $dtQ
			. ' AND ' . $db->quoteName('b.time_to') . ' > ' . $dtQ . ')'
		);
		try {
			$tables = $db->getTableList();
			$prefixLc = strtolower($prefix);
			$courseSlotsTable = $prefixLc . 'vigling_course_slots';
			$searchSlotsTable = $prefixLc . 'vigling_search_slots';
			$tablesLc = array_map('strtolower', (array) $tables);
			if (in_array($courseSlotsTable, $tablesLc, true)) {
				$q->where(
					'NOT EXISTS (SELECT 1 FROM ' . $db->quoteName($prefix . 'vigling_course_slots', 'cs')
					. ' WHERE ' . $db->quoteName('cs.master_id') . ' = ' . $db->quoteName('u.id')
					. ' AND ' . $db->quoteName('cs.is_active') . ' = 1'
					. ' AND ' . $db->quoteName('cs.starts_at_utc') . ' <= ' . $dtQ
					. ' AND ' . $db->quoteName('cs.ends_at_utc') . ' > ' . $dtQ . ')'
				);
			}
			if (in_array($searchSlotsTable, $tablesLc, true)) {
				$q->where(
					'NOT EXISTS (SELECT 1 FROM ' . $db->quoteName($prefix . 'vigling_search_slots', 'ss')
					. ' WHERE ' . $db->quoteName('ss.master_id') . ' = ' . $db->quoteName('u.id')
					. ' AND ' . $db->quoteName('ss.is_active') . ' = 1'
					. ' AND ' . $db->quoteName('ss.starts_at_utc') . ' <= ' . $dtQ
					. ' AND ' . $db->quoteName('ss.ends_at_utc') . ' > ' . $dtQ . ')'
				);
			}
		} catch (\Throwable $ignored) {
		}
	}

	private function getUserFieldIds($db, string $prefix): array
	{
		if (is_array($this->fieldIdsCache)) {
			return $this->fieldIdsCache;
		}

		$names = ['vyberite_spetsialnos', 'sity', 'area', 'home', 'work_day', 'work_from', 'work_to'];
		$q = $db->getQuery(true)
			->select([$db->quoteName('name'), $db->quoteName('id')])
			->from($db->quoteName($prefix . 'fields'))
			->where($db->quoteName('context') . ' = ' . $db->quote('com_users.user'))
			->where($db->quoteName('name') . ' IN (' . implode(',', array_map([$db, 'quote'], $names)) . ')');
		$db->setQuery($q);
		$rows = $db->loadAssocList() ?: [];

		$this->fieldIdsCache = [];
		foreach ($rows as $r) {
			$this->fieldIdsCache[(string) $r['name']] = (int) $r['id'];
		}
		return $this->fieldIdsCache;
	}

	public function populateState($ordering = null, $direction = null)
	{
		$app = Factory::getApplication();
		$input = $app->getInput();
		$this->setState('cat_id', $input->getUint('cat_id', $input->getUint('filter_cat_id')));
		$this->setState('service', $input->getUint('service', $input->getUint('filter_service')));
		$this->setState('tag', $input->getUint('tag', $input->getUint('filter_tag')));
		$this->setState('city', $input->getString('city', $input->getString('filter_city')));
		$this->setState('area', $input->getString('area', $input->getString('filter_area')));
		$home = $input->get('home', $input->get('filter_home', []), 'array');
		$this->setState('home', array_map('intval', array_filter($home)));
		$availDate = $input->getString('avail_date', '');
		if ($availDate !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}(?:[ T+]\d{2}:\d{2})?$/', $availDate)) {
			$availDate = '';
		}
		$availDate = str_replace('+', ' ', $availDate);
		$this->setState('avail_date', $availDate);
		$order = $input->getString('filter_order', 'id');
		if (!in_array($order, ['id', 'name', 'rate', 'price'], true)) {
			$order = 'id';
		}
		$this->setState('list.ordering', $order);
		$dir = strtoupper((string) $input->getString('filter_order_Dir', 'ASC'));
		$this->setState('list.direction', $dir === 'DESC' ? 'DESC' : 'ASC');
		$limit = (int) $input->getUint('limit', 20);
		$this->setState('list.limit', $limit > 50 ? 50 : ($limit < 1 ? 20 : $limit));
		$this->setState('list.start', $input->getUint('limitstart', 0));
	}
}