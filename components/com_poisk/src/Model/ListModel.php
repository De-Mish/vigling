<?php

namespace Viglin\Component\Poisk\Site\Model;

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
		$id .= ':' . (string) $this->getState('category_path_prefix');
		$id .= ':' . (string) $this->getState('branch_scope', 'default');
		$id .= ':' . (int) $this->getState('cat_id');
		$id .= ':' . (int) $this->getState('service');
		$id .= ':' . (int) $this->getState('tag');
		$id .= ':' . $this->getState('city');
		$id .= ':' . $this->getState('area');
		$id .= ':' . (string) $this->getState('master_name');
		$id .= ':' . serialize($this->getState('home'));
		$id .= ':' . serialize($this->getState('payment'));
		$id .= ':' . (int) $this->getState('children');
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
		$this->applyFiltersToQuery($q, $db, $prefix, $fieldIds, true);
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
		$this->applyFiltersToQuery($q, $db, $prefix, $fieldIds, false);
		$orderCol = $this->getState('list.ordering', 'id');
		$orderDir = strtoupper((string) $this->getState('list.direction', 'ASC'));
		if ($orderDir !== 'DESC') {
			$orderDir = 'ASC';
		}
		if ($orderCol === 'id') {
			$q->order('u.id DESC');
		} elseif ($orderCol === 'rate') {
			$q->order('u.id DESC');
		} elseif ($orderCol === 'price') {
			$q->order('u.name ' . $orderDir);
		} else {
			$q->order('u.name ' . $orderDir);
		}
		return $q;
	}

	private function applyFiltersToQuery($q, $db, $prefix, array $fieldIds, bool $forCount): void
	{
		$fieldVyberite = (int) ($fieldIds['vyberite_spetsialnos'] ?? 0);
		$fieldCity = (int) ($fieldIds['sity'] ?? 0);
		$fieldArea = (int) ($fieldIds['area'] ?? 0);
		$fieldHome = (int) ($fieldIds['home'] ?? 0);
		$fieldPayment = (int) ($fieldIds['payment_method'] ?? 0);
		$fieldChildren = (int) ($fieldIds['suitable_for_children'] ?? 0);
		$fieldWorkDay = (int) ($fieldIds['work_day'] ?? 0);
		$fieldWorkFrom = (int) ($fieldIds['work_from'] ?? 0);
		$fieldWorkTo = (int) ($fieldIds['work_to'] ?? 0);

		if ($fieldVyberite <= 0) {
			$q->where('1 = 0');
			return;
		}

		$pathPrefix = $this->getState('category_path_prefix');
		$branchScope = (string) $this->getState('branch_scope', 'default');
		$specialistConds = [
			$db->quoteName('specfv.item_id') . ' = ' . $this->userIdAsFieldItemId(),
			$db->quoteName('specfv.field_id') . ' = ' . $fieldVyberite,
			$db->quoteName('specfv.value') . ' <> ' . $db->quote(''),
			$db->quoteName('specfv.value') . ' <> ' . $db->quote('{}'),
		];

		$zatochkaCatIds = \Viglin\Component\Poisk\Site\Helper\PoiskHelper::getCategoryIdsByPathPrefix('zatochka-remont');
		if ($zatochkaCatIds !== []) {
			$zatochkaConds = [];
			foreach ($zatochkaCatIds as $cid) {
				$zatochkaConds[] = $db->quoteName('specfv.value') . ' LIKE ' . $db->quote('%"' . (int) $cid . '"%');
			}
			$zatochkaExpr = '(' . implode(' OR ', $zatochkaConds) . ')';
			if ($branchScope === 'zatochka-remont' || $pathPrefix === 'zatochka-remont') {
				$specialistConds[] = $zatochkaExpr;
			} else {
				$specialistConds[] = 'NOT ' . $zatochkaExpr;
			}
		}
		$catId = (int) $this->getState('cat_id');
		if ($catId > 0 && $zatochkaCatIds !== []) {
			$catIsZatochka = in_array($catId, $zatochkaCatIds, true);
			$inZatochkaScope = ($branchScope === 'zatochka-remont' || $pathPrefix === 'zatochka-remont');
			if (($inZatochkaScope && !$catIsZatochka) || (!$inZatochkaScope && $catIsZatochka)) {
				$catId = 0;
			}
		}
		if ($catId > 0) {
			$specialistConds[] = $db->quoteName('specfv.value') . ' LIKE ' . $db->quote('%"' . $catId . '"%');
		}
		$q->where(
			'EXISTS (SELECT 1 FROM ' . $db->quoteName($prefix . 'fields_values', 'specfv')
			. ' WHERE ' . implode(' AND ', $specialistConds) . ')'
		);

		$city = trim((string) $this->getState('city'));
		if ($city !== '' && $fieldCity > 0) {
			$cityClean = trim($city);
			$q->where(
				'EXISTS (SELECT 1 FROM ' . $db->quoteName($prefix . 'fields_values', 'cityfv')
				. ' WHERE ' . $db->quoteName('cityfv.item_id') . ' = ' . $this->userIdAsFieldItemId()
				. ' AND ' . $db->quoteName('cityfv.field_id') . ' = ' . $fieldCity
				. ' AND LOWER(TRIM(' . $db->quoteName('cityfv.value') . ')) = LOWER(' . $db->quote($cityClean) . '))'
			);
		}
		
		$area = trim((string) $this->getState('area'));
		if ($area !== '' && $fieldArea > 0) {
			$areaClean = trim($area);
			$q->where(
				'EXISTS (SELECT 1 FROM ' . $db->quoteName($prefix . 'fields_values', 'areafv')
				. ' WHERE ' . $db->quoteName('areafv.item_id') . ' = ' . $this->userIdAsFieldItemId()
				. ' AND ' . $db->quoteName('areafv.field_id') . ' = ' . $fieldArea
				. ' AND LOWER(TRIM(' . $db->quoteName('areafv.value') . ')) = LOWER(' . $db->quote($areaClean) . '))'
			);
		}
		$masterName = trim((string) $this->getState('master_name'));
		if ($masterName !== '') {
			$q->where('u.name LIKE ' . $db->quote('%' . $db->escape($masterName, true) . '%'));
		}

		$serviceId = (int) $this->getState('service');
		$tagId = (int) $this->getState('tag');

		if ($serviceId > 0) {
			$q->where(
				'EXISTS (SELECT 1 FROM ' . $db->quoteName($prefix . 'vigling_user_services', 'us')
				. ' WHERE ' . $db->quoteName('us.user_id') . ' = ' . $db->quoteName('u.id')
				. ' AND ' . $db->quoteName('us.is_active') . ' = 1'
				. ' AND ' . $db->quoteName('us.legacy_cat_id') . ' = ' . $serviceId . ')'
			);
		}

		if ($tagId > 0) {
			$q->where(
				'EXISTS (SELECT 1 FROM ' . $db->quoteName($prefix . 'vigling_user_services', 'us')
				. ' WHERE ' . $db->quoteName('us.user_id') . ' = ' . $db->quoteName('u.id')
				. ' AND ' . $db->quoteName('us.is_active') . ' = 1'
				. ' AND ' . $db->quoteName('us.legacy_tag_id') . ' = ' . $tagId . ')'
			);
		}

		$homeArr = $this->getState('home');
		if (!empty($homeArr) && is_array($homeArr) && $fieldHome > 0) {
			$conds = [];
			foreach ($homeArr as $h) {
				if ((int) $h >= 1 && (int) $h <= 3) {
					$conds[] = 'fv.value LIKE ' . $db->quote('%"' . (int) $h . '"%');
				}
			}
			if ($conds !== []) {
				$q->where(
					'EXISTS (SELECT 1 FROM ' . $db->quoteName($prefix . 'fields_values', 'homefv')
					. ' WHERE ' . $db->quoteName('homefv.item_id') . ' = ' . $this->userIdAsFieldItemId()
					. ' AND ' . $db->quoteName('homefv.field_id') . ' = ' . $fieldHome
					. ' AND (' . str_replace('fv.', 'homefv.', implode(' OR ', $conds)) . '))'
				);
			}
		}

		$payArr = $this->getState('payment');
		if (!empty($payArr) && is_array($payArr)) {
			$conds = [];
			foreach ($payArr as $payKey) {
				$payKey = strtolower(trim((string) $payKey));
				if (in_array($payKey, ['card', 'cash', 'transfer'], true)) {
					$conds[] = 'payfv.value LIKE ' . $db->quote('%"' . $payKey . '"%');
				}
			}
			if ($conds !== [] && $fieldPayment > 0) {
				$q->where(
					'EXISTS (SELECT 1 FROM ' . $db->quoteName($prefix . 'fields_values', 'payfv')
					. ' WHERE ' . $db->quoteName('payfv.item_id') . ' = ' . $this->userIdAsFieldItemId()
					. ' AND ' . $db->quoteName('payfv.field_id') . ' = ' . $fieldPayment
					. ' AND (' . implode(' OR ', $conds) . '))'
				);
			} elseif ($conds !== []) {
				$q->where('1 = 0');
			}
		}

		if ((int) $this->getState('children') === 1) {
			if ($fieldChildren > 0) {
				$q->where(
					'EXISTS (SELECT 1 FROM ' . $db->quoteName($prefix . 'fields_values', 'childfv')
					. ' WHERE ' . $db->quoteName('childfv.item_id') . ' = ' . $this->userIdAsFieldItemId()
					. ' AND ' . $db->quoteName('childfv.field_id') . ' = ' . $fieldChildren
					. ' AND (' . $db->quoteName('childfv.value') . ' = ' . $db->quote('1')
					. ' OR ' . $db->quoteName('childfv.value') . ' = ' . $db->quote('"1"')
					. ' OR ' . $db->quoteName('childfv.value') . ' LIKE ' . $db->quote('%"1"%') . '))'
				);
			} else {
				$q->where('1 = 0');
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
						if (!class_exists(\Joomla\Plugin\User\Vigling\Helper\WorkScheduleHelper::class, false)) {
							$vgWorkScheduleLoader = (defined('JPATH_THEMES') ? JPATH_THEMES : JPATH_ROOT . '/templates') . '/ryba/helpers/vigling_work_schedule.php';
							if (is_file($vgWorkScheduleLoader)) {
								require_once $vgWorkScheduleLoader;
							}
							if (function_exists('vigling_load_work_schedule_helper')) {
								vigling_load_work_schedule_helper();
							} else {
								foreach ([
									JPATH_PLUGINS . '/user/vigling/src/Helper/WorkScheduleHelper.php',
									(defined('JPATH_THEMES') ? JPATH_THEMES : JPATH_ROOT . '/templates') . '/ryba/helpers/WorkScheduleHelper.php',
								] as $vgWorkScheduleFile) {
									if (is_file($vgWorkScheduleFile)) {
										require_once $vgWorkScheduleFile;
										break;
									}
								}
							}
						}
						if (class_exists(\Joomla\Plugin\User\Vigling\Helper\WorkScheduleHelper::class, false)) {
							$q->where(\Joomla\Plugin\User\Vigling\Helper\WorkScheduleHelper::sqlWorksAt(
								$db,
								$this->userIdAsFieldItemId(),
								$fieldWorkDay,
								$fieldWorkFrom,
								$fieldWorkTo,
								$weekday,
								$timeCompare,
								$prefix . 'fields_values'
							));
						}
					}
					
				} catch (\Throwable $e) {
				}
			}
		}
		
	}

	private function userIdAsFieldItemId(): string
	{
		$db = $this->getDatabase();

		return 'CAST(' . $db->quoteName('u.id') . ' AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_unicode_ci';
	}

	private function getUserFieldIds($db, string $prefix): array
	{
		if (is_array($this->fieldIdsCache)) {
			return $this->fieldIdsCache;
		}

		$names = ['is_master', 'vyberite_spetsialnos', 'sity', 'telefon', 'area', 'home', 'payment_method', 'suitable_for_children', 'work_day', 'work_from', 'work_to'];
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
		$this->setState('master_name', $input->getString('master_name', $input->getString('filter_master_name', '')));
		$home = $input->get('home', $input->get('filter_home', []), 'array');
		$this->setState('home', array_map('intval', array_filter($home)));
		$payment = [];
		foreach ((array) $input->get('payment', $input->get('filter_payment', []), 'array') as $payKey) {
			$payKey = strtolower(trim((string) $payKey));
			if (in_array($payKey, ['card', 'cash', 'transfer'], true)) {
				$payment[] = $payKey;
			}
		}
		$this->setState('payment', array_values(array_unique($payment)));
		$childrenRaw = strtolower(trim((string) $input->get('children', $input->get('filter_children', ''), 'string')));
		$this->setState('children', in_array($childrenRaw, ['1', 'yes', 'on', 'true', 'да'], true) ? 1 : 0);
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