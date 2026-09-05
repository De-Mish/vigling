<?php

namespace Viglin\Component\Kurs\Site\Model;

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\MVC\Model\ListModel as BaseListModel;

class ListModel extends BaseListModel
{
	private const MAP_ITEMS_LIMIT = 800;

	private array $totalCache = [];
	private array $itemsCache = [];
	private array $mapItemsCache = [];
	private ?array $fieldIdsCache = null;

	protected function getStoreId($id = '')
	{
		$id .= ':' . (int) $this->getState('cat_id');
		$id .= ':' . $this->getState('city');
		$id .= ':' . $this->getState('area');
		$id .= ':' . serialize($this->getState('home'));
		$id .= ':' . $this->getState('booking_mode');
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
		$query = $db->getQuery(true)
			->select('COUNT(DISTINCT c.id)')
			->from($db->quoteName('#__vigling_user_courses', 'c'))
			->join('INNER', $db->quoteName('#__users', 'u') . ' ON ' . $db->quoteName('u.id') . ' = ' . $db->quoteName('c.user_id'))
			->join('LEFT', $db->quoteName('#__vigling_course_slots', 'slot') . ' ON ' . $db->quoteName('slot.course_id') . ' = ' . $db->quoteName('c.id') . ' AND ' . $db->quoteName('slot.is_active') . ' = 1')
			->where($db->quoteName('c.is_active') . ' = 1')
			->where($db->quoteName('u.block') . ' = 0');

		$this->applyFiltersToQuery($query, $db);

		return $query;
	}

	protected function buildListQuery()
	{
		$db = $this->getDatabase();
		$query = $db->getQuery(true)
			->select([
				$db->quoteName('c.id', 'course_id'),
				$db->quoteName('c.user_id', 'master_id'),
				$db->quoteName('u.name', 'master_name'),
				$db->quoteName('c.category_id'),
				$db->quoteName('cat.title', 'category_title'),
				$db->quoteName('c.title'),
				$db->quoteName('c.description'),
				$db->quoteName('c.media_path'),
				$db->quoteName('c.price'),
				$db->quoteName('c.duration_min'),
				$db->quoteName('c.capacity'),
				$db->quoteName('c.booking_mode'),
				$db->quoteName('c.updated_at'),
				$db->quoteName('slot.id', 'slot_id'),
				$db->quoteName('slot.starts_at_utc'),
				$db->quoteName('slot.ends_at_utc'),
				$db->quoteName('slot.capacity_total'),
			])
			->from($db->quoteName('#__vigling_user_courses', 'c'))
			->join('INNER', $db->quoteName('#__users', 'u') . ' ON ' . $db->quoteName('u.id') . ' = ' . $db->quoteName('c.user_id'))
			->join('LEFT', $db->quoteName('#__categories', 'cat') . ' ON ' . $db->quoteName('cat.id') . ' = ' . $db->quoteName('c.category_id'))
			->join('LEFT', $db->quoteName('#__vigling_course_slots', 'slot') . ' ON ' . $db->quoteName('slot.course_id') . ' = ' . $db->quoteName('c.id') . ' AND ' . $db->quoteName('slot.is_active') . ' = 1')
			->where($db->quoteName('c.is_active') . ' = 1')
			->where($db->quoteName('u.block') . ' = 0');

		$this->applyFiltersToQuery($query, $db);

		$orderCol = (string) $this->getState('list.ordering', 'newest');
		$orderDir = strtoupper((string) $this->getState('list.direction', 'DESC'));
		if ($orderDir !== 'ASC' && $orderDir !== 'DESC') {
			$orderDir = 'DESC';
		}

		switch ($orderCol) {
			case 'price':
				$query->order($db->quoteName('c.price') . ' ' . $orderDir);
				$query->order($db->quoteName('c.id') . ' DESC');
				break;

			case 'date':
				$query->order('CASE WHEN ' . $db->quoteName('slot.starts_at_utc') . ' IS NULL THEN 1 ELSE 0 END ASC');
				$query->order($db->quoteName('slot.starts_at_utc') . ' ' . $orderDir);
				$query->order($db->quoteName('c.id') . ' DESC');
				break;

			default:
				$query->order($db->quoteName('c.updated_at') . ' DESC');
				$query->order($db->quoteName('c.id') . ' DESC');
				break;
		}

		return $query;
	}

	private function applyFiltersToQuery($query, $db): void
	{
		$catId = (int) $this->getState('cat_id');
		if ($catId > 0) {
			$query->where($db->quoteName('c.category_id') . ' = ' . $catId);
		}

		$bookingMode = trim((string) $this->getState('booking_mode'));
		if ($bookingMode === 'free' || $bookingMode === 'fixed') {
			$query->where($db->quoteName('c.booking_mode') . ' = ' . $db->quote($bookingMode));
		}

		$fieldIds = $this->getUserFieldIds($db);
		$fieldCity = (int) ($fieldIds['sity'] ?? 0);
		$fieldArea = (int) ($fieldIds['area'] ?? 0);
		$fieldHome = (int) ($fieldIds['home'] ?? 0);
		$fieldWorkDay = (int) ($fieldIds['work_day'] ?? 0);
		$fieldWorkFrom = (int) ($fieldIds['work_from'] ?? 0);
		$fieldWorkTo = (int) ($fieldIds['work_to'] ?? 0);

		$city = trim((string) $this->getState('city'));
		if ($city !== '' && $fieldCity > 0) {
			$citySub = $db->getQuery(true)
				->select('DISTINCT fv.item_id')
				->from($db->quoteName('#__fields_values', 'fv'))
				->where('fv.field_id = ' . $fieldCity)
				->where('(fv.value = ' . $db->quote($city) . ' OR fv.value = ' . $db->quote(' ' . $city) . ' OR fv.value = ' . $db->quote($city . ' ') . ')');
			$query->join('INNER', '(' . (string) $citySub . ') AS cityfilter ON cityfilter.item_id = u.id');
		}

		$area = trim((string) $this->getState('area'));
		if ($area !== '' && $fieldArea > 0) {
			$areaSub = $db->getQuery(true)
				->select('DISTINCT fv.item_id')
				->from($db->quoteName('#__fields_values', 'fv'))
				->where('fv.field_id = ' . $fieldArea)
				->where('(fv.value = ' . $db->quote($area) . ' OR fv.value = ' . $db->quote(' ' . $area) . ' OR fv.value = ' . $db->quote($area . ' ') . ')');
			$query->join('INNER', '(' . (string) $areaSub . ') AS areafilter ON areafilter.item_id = u.id');
		}

		$homeArr = $this->getState('home');
		if (!empty($homeArr) && is_array($homeArr) && $fieldHome > 0) {
			$conds = [];
			foreach ($homeArr as $home) {
				$home = (int) $home;
				if ($home >= 1 && $home <= 3) {
					$conds[] = 'fv.value LIKE ' . $db->quote('%"' . $home . '"%');
				}
			}

			if ($conds !== []) {
				$homeSub = $db->getQuery(true)
					->select('DISTINCT fv.item_id')
					->from($db->quoteName('#__fields_values', 'fv'))
					->where('fv.field_id = ' . $fieldHome)
					->where('(' . implode(' OR ', $conds) . ')');
				$query->join('INNER', '(' . (string) $homeSub . ') AS homefilter ON homefilter.item_id = u.id');
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

					if ($fieldWorkDay > 0) {
						$query->where(
							'EXISTS (SELECT 1 FROM ' . $db->quoteName('#__fields_values', 'wdfv')
							. ' WHERE ' . $db->quoteName('wdfv.item_id') . ' = ' . $db->quoteName('u.id')
							. ' AND ' . $db->quoteName('wdfv.field_id') . ' = ' . $fieldWorkDay
							. ' AND ' . $db->quoteName('wdfv.value') . ' LIKE ' . $db->quote('%"' . $weekday . '"%') . ')'
						);
					}

					if ($fieldWorkFrom > 0) {
						$query->where(
							'EXISTS (SELECT 1 FROM ' . $db->quoteName('#__fields_values', 'wffv')
							. ' WHERE ' . $db->quoteName('wffv.item_id') . ' = ' . $db->quoteName('u.id')
							. ' AND ' . $db->quoteName('wffv.field_id') . ' = ' . $fieldWorkFrom
							. ' AND ' . $db->quoteName('wffv.value') . ' <> ' . $db->quote('')
							. ' AND STR_TO_DATE(REPLACE(' . $db->quoteName('wffv.value') . ', ".", ":"), "%H:%i") <= STR_TO_DATE(' . $db->quote($timeCompare) . ', "%H:%i:%s"))'
						);
					}

					if ($fieldWorkTo > 0) {
						$query->where(
							'EXISTS (SELECT 1 FROM ' . $db->quoteName('#__fields_values', 'wtfv')
							. ' WHERE ' . $db->quoteName('wtfv.item_id') . ' = ' . $db->quoteName('u.id')
							. ' AND ' . $db->quoteName('wtfv.field_id') . ' = ' . $fieldWorkTo
							. ' AND ' . $db->quoteName('wtfv.value') . ' <> ' . $db->quote('')
							. ' AND STR_TO_DATE(REPLACE(' . $db->quoteName('wtfv.value') . ', ".", ":"), "%H:%i") >= STR_TO_DATE(' . $db->quote($timeCompare) . ', "%H:%i:%s"))'
						);
					}

					$this->applyBusyMasterFilter($query, $db, $dateOnly . ' ' . $time . ':00');

				} catch (\Throwable $e) {
				}
			} elseif ($dateOnly !== '') {
				$query->where('DATE(' . $db->quoteName('slot.starts_at_utc') . ') = ' . $db->quote($dateOnly));
			}
		}
	}

	private function applyBusyMasterFilter($query, $db, string $dt): void
	{
		$dtQ = $db->quote($dt);
		$query->where(
			'NOT EXISTS (SELECT 1 FROM ' . $db->quoteName('#__vigling_bookings', 'b')
			. ' WHERE ' . $db->quoteName('b.master_id') . ' = ' . $db->quoteName('u.id')
			. ' AND ' . $db->quoteName('b.time') . ' <= ' . $dtQ
			. ' AND ' . $db->quoteName('b.time_to') . ' > ' . $dtQ . ')'
		);
		try {
			$tables = $db->getTableList();
			$prefix = $db->getPrefix();
			$prefixLc = strtolower($prefix);
			$courseSlotsTable = $prefixLc . 'vigling_course_slots';
			$searchSlotsTable = $prefixLc . 'vigling_search_slots';
			$tablesLc = array_map('strtolower', (array) $tables);
			if (in_array($courseSlotsTable, $tablesLc, true)) {
				$query->where(
					'NOT EXISTS (SELECT 1 FROM ' . $db->quoteName('#__vigling_course_slots', 'cs')
					. ' WHERE ' . $db->quoteName('cs.master_id') . ' = ' . $db->quoteName('u.id')
					. ' AND ' . $db->quoteName('cs.is_active') . ' = 1'
					. ' AND ' . $db->quoteName('cs.starts_at_utc') . ' <= ' . $dtQ
					. ' AND ' . $db->quoteName('cs.ends_at_utc') . ' > ' . $dtQ . ')'
				);
			}
			if (in_array($searchSlotsTable, $tablesLc, true)) {
				$query->where(
					'NOT EXISTS (SELECT 1 FROM ' . $db->quoteName('#__vigling_search_slots', 'ss')
					. ' WHERE ' . $db->quoteName('ss.master_id') . ' = ' . $db->quoteName('u.id')
					. ' AND ' . $db->quoteName('ss.is_active') . ' = 1'
					. ' AND ' . $db->quoteName('ss.starts_at_utc') . ' <= ' . $dtQ
					. ' AND ' . $db->quoteName('ss.ends_at_utc') . ' > ' . $dtQ . ')'
				);
			}
		} catch (\Throwable $ignored) {
		}
	}

	private function getUserFieldIds($db): array
	{
		if (is_array($this->fieldIdsCache)) {
			return $this->fieldIdsCache;
		}

		$names = ['sity', 'area', 'home', 'work_day', 'work_from', 'work_to'];
		$query = $db->getQuery(true)
			->select([$db->quoteName('name'), $db->quoteName('id')])
			->from($db->quoteName('#__fields'))
			->where($db->quoteName('context') . ' = ' . $db->quote('com_users.user'))
			->where($db->quoteName('name') . ' IN (' . implode(',', array_map([$db, 'quote'], $names)) . ')');
		$db->setQuery($query);
		$rows = $db->loadAssocList() ?: [];

		$this->fieldIdsCache = [];
		foreach ($rows as $row) {
			$this->fieldIdsCache[(string) $row['name']] = (int) $row['id'];
		}

		return $this->fieldIdsCache;
	}

	public function populateState($ordering = null, $direction = null)
	{
		$app = Factory::getApplication();
		$input = $app->getInput();

		$this->setState('cat_id', $input->getInt('cat_id', 0));
		$this->setState('city', trim((string) $input->getString('city', '')));
		$this->setState('area', trim((string) $input->getString('area', '')));
		$this->setState('home', array_map('intval', (array) $input->get('home', [], 'array')));
		$this->setState('booking_mode', trim((string) $input->getString('booking_mode', '')));
		$availDate = $input->getString('avail_date', '');
		if ($availDate !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}(?:[ T+]\d{2}:\d{2})?$/', $availDate)) {
			$availDate = '';
		}
		$availDate = str_replace('+', ' ', $availDate);
		$this->setState('avail_date', $availDate);

		$orderCol = trim((string) $input->getString('filter_order', 'newest'));
		$allowedOrder = ['newest', 'price', 'date'];
		if (!in_array($orderCol, $allowedOrder, true)) {
			$orderCol = 'newest';
		}

		$orderDir = strtoupper(trim((string) $input->getString('filter_order_Dir', 'DESC')));
		if ($orderDir !== 'ASC' && $orderDir !== 'DESC') {
			$orderDir = 'DESC';
		}

		$limit = (int) $input->getUInt('limit', 20);
		if ($limit < 1) {
			$limit = 20;
		}
		if ($limit > 50) {
			$limit = 50;
		}

		$start = (int) $input->getUInt('limitstart', 0);

		$this->setState('list.ordering', $orderCol);
		$this->setState('list.direction', $orderDir);
		$this->setState('list.limit', $limit);
		$this->setState('list.start', $start);
	}
}