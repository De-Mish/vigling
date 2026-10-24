<?php

namespace Viglin\Component\Modeli\Site\Model;

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\MVC\Model\ListModel as BaseListModel;
use Joomla\Plugin\User\Vigling\Helper\CatalogCacheTrait;
use Joomla\Plugin\User\Vigling\Helper\CatalogSortHelper;

require_once JPATH_PLUGINS . '/user/vigling/src/Helper/CatalogCacheTrait.php';
require_once JPATH_PLUGINS . '/user/vigling/src/Helper/CatalogSortHelper.php';

class ListModel extends BaseListModel
{
	use CatalogCacheTrait;

	private const MAP_ITEMS_LIMIT = 800;

	/** @var array<string, int> */
	private array $totalCache = [];

	/** @var array<string, list<\stdClass>> */
	private array $itemsCache = [];

	/** @var array<string, list<\stdClass>> */
	private array $mapItemsCache = [];

	/** @var array<string, int>|null */
	private ?array $fieldIdsCache = null;

	/** @var list<string>|null */
	private ?array $busyTablesCache = null;

	protected function getStoreId($id = '')
	{
		$id .= ':' . (int) $this->getState('cat_id');
		$id .= ':' . $this->getState('city');
		$id .= ':' . $this->getState('area');
		$id .= ':' . serialize($this->getState('home'));
		$id .= ':' . serialize($this->getState('payment'));
		$id .= ':' . (int) $this->getState('children');
		$id .= ':' . $this->getState('booking_mode');
		$id .= ':' . $this->getState('avail_date');
		$id .= ':' . $this->getState('list.ordering');
		$id .= ':' . $this->getState('list.direction');
		$id .= ':' . (int) $this->getState('list.start');
		$id .= ':' . (int) $this->getState('list.limit');
		$id .= ':one-offer';

		return parent::getStoreId($id);
	}

	public function getTotal(): int
	{
		$store = $this->getStoreId('total');

		if (array_key_exists($store, $this->totalCache)) {
			return (int) $this->totalCache[$store];
		}

		try {
			$this->totalCache[$store] = (int) $this->rememberCatalog('modeli', $store, function () {
				$db = $this->getDatabase();
				$db->setQuery($this->buildCountQuery());

				return (int) $db->loadResult();
			});
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

			$this->itemsCache[$store] = $this->rememberCatalog('modeli', $store, function () use ($limit, $start) {
				return $this->loadOneOfferPerRow($limit, $start, 'search_id');
			});
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
			$this->mapItemsCache[$store] = $this->rememberCatalog('modeli', $store, function () {
				return $this->loadOneOfferPerRow(self::MAP_ITEMS_LIMIT, 0, 'search_id');
			});
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
			->from($db->quoteName('#__vigling_user_searches', 'c'))
			->join('INNER', $db->quoteName('#__users', 'u') . ' ON ' . $db->quoteName('u.id') . ' = ' . $db->quoteName('c.user_id'))
			->join('LEFT', $db->quoteName('#__vigling_search_slots', 'slot') . ' ON ' . $db->quoteName('slot.search_id') . ' = ' . $db->quoteName('c.id') . ' AND ' . $db->quoteName('slot.is_active') . ' = 1')
			->where($db->quoteName('c.is_active') . ' = 1')
			->where($db->quoteName('u.block') . ' = 0')
			->where(\Joomla\Plugin\User\Vigling\Service\UserSearchesService::activeListWhereSql($db));

		$this->applyFiltersToQuery($query, $db);

		return $query;
	}

	protected function buildListQuery()
	{
		$db = $this->getDatabase();
		$query = $db->getQuery(true)
			->select([
				$db->quoteName('c.id', 'search_id'),
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
			->from($db->quoteName('#__vigling_user_searches', 'c'))
			->join('INNER', $db->quoteName('#__users', 'u') . ' ON ' . $db->quoteName('u.id') . ' = ' . $db->quoteName('c.user_id'))
			->join('LEFT', $db->quoteName('#__categories', 'cat') . ' ON ' . $db->quoteName('cat.id') . ' = ' . $db->quoteName('c.category_id'))
			->join('LEFT', $db->quoteName('#__vigling_search_slots', 'slot') . ' ON ' . $db->quoteName('slot.search_id') . ' = ' . $db->quoteName('c.id') . ' AND ' . $db->quoteName('slot.is_active') . ' = 1')
			->where($db->quoteName('c.is_active') . ' = 1')
			->where($db->quoteName('u.block') . ' = 0')
			->where(\Joomla\Plugin\User\Vigling\Service\UserSearchesService::activeListWhereSql($db));

		$this->applyFiltersToQuery($query, $db);

		$orderCol = (string) $this->getState('list.ordering', 'newest');
		$orderDir = $orderCol === 'newest' ? 'DESC' : 'ASC';

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
				$query->order($db->quoteName('c.id') . ' DESC');
				break;
		}

		$query->select(
			'ROW_NUMBER() OVER (PARTITION BY ' . $db->quoteName('c.id')
			. ' ORDER BY CASE WHEN ' . $db->quoteName('slot.starts_at_utc') . ' IS NULL THEN 1 ELSE 0 END ASC, '
			. $db->quoteName('slot.starts_at_utc') . ' ASC, '
			. $db->quoteName('slot.id') . ' ASC) AS ' . $db->quoteName('vg_row')
		);

		return $query;
	}

	/**
	 * The slot join can repeat one search once per free date.
	 * Keep the earliest remaining date and page by search, matching COUNT(DISTINCT c.id).
	 *
	 * @return array<int, object>
	 */
	private function loadOneOfferPerRow(int $limit, int $start, string $idColumn): array
	{
		$db = $this->getDatabase();
		$query = $db->getQuery(true)
			->select('deduped.*')
			->from('(' . $this->buildListQuery() . ') AS ' . $db->quoteName('deduped'))
			->where($db->quoteName('deduped.vg_row') . ' = 1');
		$this->applyDedupedOrdering($query, $db, $idColumn);
		if ($limit > 0) {
			$query->setLimit($limit, max(0, $start));
		}
		$db->setQuery($query);

		return $db->loadObjectList() ?: [];
	}

	private function applyDedupedOrdering($query, $db, string $idColumn): void
	{
		$orderCol = (string) $this->getState('list.ordering', 'newest');
		$orderDir = $orderCol === 'newest' ? 'DESC' : 'ASC';

		switch ($orderCol) {
			case 'price':
				$query->order($db->quoteName('deduped.price') . ' ' . $orderDir);
				$query->order($db->quoteName('deduped.' . $idColumn) . ' DESC');
				break;

			case 'date':
				$query->order('CASE WHEN ' . $db->quoteName('deduped.starts_at_utc') . ' IS NULL THEN 1 ELSE 0 END ASC');
				$query->order($db->quoteName('deduped.starts_at_utc') . ' ' . $orderDir);
				$query->order($db->quoteName('deduped.' . $idColumn) . ' DESC');
				break;

			default:
				$query->order($db->quoteName('deduped.' . $idColumn) . ' DESC');
				break;
		}
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
		$fieldPayment = (int) ($fieldIds['payment_method'] ?? 0);
		$fieldChildren = (int) ($fieldIds['suitable_for_children'] ?? 0);
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

		$payArr = $this->getState('payment');
		if (!empty($payArr) && is_array($payArr)) {
			$conds = [];
			foreach ($payArr as $payKey) {
				$payKey = strtolower(trim((string) $payKey));
				if (in_array($payKey, ['card', 'cash', 'transfer'], true)) {
					$conds[] = 'fv.value LIKE ' . $db->quote('%"' . $payKey . '"%');
				}
			}
			if ($conds !== [] && $fieldPayment > 0) {
				$paySub = $db->getQuery(true)
					->select('DISTINCT fv.item_id')
					->from($db->quoteName('#__fields_values', 'fv'))
					->where('fv.field_id = ' . $fieldPayment)
					->where('(' . implode(' OR ', $conds) . ')');
				$query->join('INNER', '(' . (string) $paySub . ') AS payfilter ON payfilter.item_id = u.id');
			} elseif ($conds !== []) {
				$query->where('1 = 0');
			}
		}

		if ((int) $this->getState('children') === 1) {
			if ($fieldChildren > 0) {
				$childSub = $db->getQuery(true)
					->select('DISTINCT fv.item_id')
					->from($db->quoteName('#__fields_values', 'fv'))
					->where('fv.field_id = ' . $fieldChildren)
					->where('(' . $db->quoteName('fv.value') . ' = ' . $db->quote('1')
						. ' OR ' . $db->quoteName('fv.value') . ' = ' . $db->quote('"1"')
						. ' OR ' . $db->quoteName('fv.value') . ' LIKE ' . $db->quote('%"1"%') . ')');
				$query->join('INNER', '(' . (string) $childSub . ') AS childfilter ON childfilter.item_id = u.id');
			} else {
				$query->where('1 = 0');
			}
		}

		$availDate = trim((string) $this->getState('avail_date'));
		if ($availDate !== '') {
			$this->requireWorkScheduleHelper();
			if (class_exists(\Joomla\Plugin\User\Vigling\Helper\WorkScheduleHelper::class, false)) {
				$split = \Joomla\Plugin\User\Vigling\Helper\WorkScheduleHelper::splitAvailFilter($availDate);
				$dateOnly = $split['day'];
				$time = $split['time'];
				if ($dateOnly !== '' && $time !== '') {
					try {
						$dt = new \DateTime($dateOnly);
						$weekday = (int) $dt->format('N');
						$timeCompare = $time . ':00';
						$localSlot = $this->slotInSiteTimeSql($db, $dateOnly . ' ' . $timeCompare);
						$orParts = [
							'(' . $db->quoteName('slot.id') . ' IS NOT NULL'
							. ' AND DATE(' . $localSlot . ') = ' . $db->quote($dateOnly)
							. ' AND TIME(' . $localSlot . ') = ' . $db->quote($timeCompare) . ')',
						];

						if ($fieldWorkDay > 0) {
							$scheduleSql = \Joomla\Plugin\User\Vigling\Helper\WorkScheduleHelper::sqlWorksAt(
								$db,
								$db->quoteName('u.id'),
								$fieldWorkDay,
								$fieldWorkFrom,
								$fieldWorkTo,
								$weekday,
								$timeCompare
							);
							$orParts[] = '(' . implode(' AND ', array_merge(
								[$scheduleSql],
								$this->busyMasterConditions($db, $dateOnly . ' ' . $time . ':00')
							)) . ')';
						}

						$query->where('(' . implode(' OR ', $orParts) . ')');
					} catch (\Throwable $e) {
					}
				} elseif ($dateOnly !== '') {
					$localSlot = $this->slotInSiteTimeSql($db, $dateOnly . ' 12:00:00');
					$orParts = [
						'(DATE(' . $localSlot . ') = ' . $db->quote($dateOnly) . ')',
					];
					if ($fieldWorkDay > 0) {
						try {
							$weekday = (int) (new \DateTime($dateOnly))->format('N');
							$orParts[] = '(' . '(' . $db->quoteName('c.booking_mode') . ' IS NULL OR ' . $db->quoteName('c.booking_mode') . ' <> ' . $db->quote('fixed') . ')'
								. ' AND ' . \Joomla\Plugin\User\Vigling\Helper\WorkScheduleHelper::sqlWorksOnWeekday(
									$db,
									$db->quoteName('u.id'),
									$fieldWorkDay,
									$fieldWorkFrom,
									$fieldWorkTo,
									$weekday,
									'#__fields_values',
									\Joomla\Plugin\User\Vigling\Helper\WorkScheduleHelper::clockIfDateIsToday($dateOnly)
								) . ')';
						} catch (\Throwable $e) {
						}
					}
					$query->where('(' . implode(' OR ', $orParts) . ')');
				} elseif ($time !== '') {
					$timeQ = $db->quote($time . ':00');
					$localSlot = $this->slotInSiteTimeSql($db, 'now');
					$orParts = [
						'(' . $db->quoteName('c.booking_mode') . ' = ' . $db->quote('fixed')
						. ' AND ' . $db->quoteName('slot.id') . ' IS NOT NULL'
						. ' AND ' . $db->quoteName('slot.starts_at_utc') . ' >= UTC_TIMESTAMP()'
						. ' AND ' . $db->quoteName('slot.starts_at_utc') . ' < DATE_ADD(UTC_TIMESTAMP(), INTERVAL 45 DAY)'
						. ' AND TIME(' . $localSlot . ') = ' . $timeQ . ')',
					];
					if ($fieldWorkDay > 0) {
						$orParts[] = '(' . '(' . $db->quoteName('c.booking_mode') . ' IS NULL OR ' . $db->quoteName('c.booking_mode') . ' <> ' . $db->quote('fixed') . ')'
							. ' AND ' . \Joomla\Plugin\User\Vigling\Helper\WorkScheduleHelper::sqlWorksAtOnUpcoming(
								$db,
								$db->quoteName('u.id'),
								$fieldWorkDay,
								$fieldWorkFrom,
								$fieldWorkTo,
								$time,
								'#__fields_values',
								function (string $dt) use ($db): string {
									return implode(' AND ', $this->busyMasterConditions($db, $dt));
								}
							) . ')';
					}
					$query->where('(' . implode(' OR ', $orParts) . ')');
				}
			}
		}
	}

	/**
	 * @return string[]
	 */
	private function busyMasterConditions($db, string $dt): array
	{
		$dtQ = $db->quote($dt);
		$conds = [
			'NOT EXISTS (SELECT 1 FROM ' . $db->quoteName('#__vigling_bookings', 'b')
			. ' WHERE ' . $db->quoteName('b.master_id') . ' = ' . $db->quoteName('u.id')
			. ' AND ' . $db->quoteName('b.time') . ' <= ' . $dtQ
			. ' AND ' . $db->quoteName('b.time_to') . ' > ' . $dtQ . ')',
		];
		try {
			if (!is_array($this->busyTablesCache)) {
				$this->busyTablesCache = array_map('strtolower', (array) $db->getTableList());
			}
			$prefix = $db->getPrefix();
			$prefixLc = strtolower($prefix);
			$courseSlotsTable = $prefixLc . 'vigling_course_slots';
			$searchSlotsTable = $prefixLc . 'vigling_search_slots';
			$tablesLc = $this->busyTablesCache;
			if (in_array($courseSlotsTable, $tablesLc, true)) {
				$conds[] = 'NOT EXISTS (SELECT 1 FROM ' . $db->quoteName('#__vigling_course_slots', 'cs')
					. ' WHERE ' . $db->quoteName('cs.master_id') . ' = ' . $db->quoteName('u.id')
					. ' AND ' . $db->quoteName('cs.is_active') . ' = 1'
					. ' AND ' . $db->quoteName('cs.starts_at_utc') . ' <= ' . $dtQ
					. ' AND ' . $db->quoteName('cs.ends_at_utc') . ' > ' . $dtQ . ')';
			}
			if (in_array($searchSlotsTable, $tablesLc, true)) {
				$conds[] = 'NOT EXISTS (SELECT 1 FROM ' . $db->quoteName('#__vigling_search_slots', 'ss')
					. ' WHERE ' . $db->quoteName('ss.master_id') . ' = ' . $db->quoteName('u.id')
					. ' AND ' . $db->quoteName('ss.is_active') . ' = 1'
					. ' AND ' . $db->quoteName('ss.starts_at_utc') . ' <= ' . $dtQ
					. ' AND ' . $db->quoteName('ss.ends_at_utc') . ' > ' . $dtQ . ')';
			}
		} catch (\Throwable $ignored) {
		}

		return $conds;
	}

	/**
	 * Slot rows are stored in UTC. The catalog shows them in the site timezone.
	 */
	private function slotInSiteTimeSql($db, string $localMoment): string
	{
		return 'CONVERT_TZ(' . $db->quoteName('slot.starts_at_utc')
			. ', ' . $db->quote('+00:00')
			. ', ' . $db->quote($this->siteUtcOffset($localMoment)) . ')';
	}

	private function siteUtcOffset(string $localMoment): string
	{
		$name = 'UTC';
		try {
			$configured = trim((string) Factory::getApplication()->get('offset', 'UTC'));
			if ($configured !== '') {
				$name = $configured;
			}
		} catch (\Throwable $e) {
		}
		try {
			$tz = new \DateTimeZone($name);
		} catch (\Throwable $e) {
			$tz = new \DateTimeZone('UTC');
		}
		try {
			$moment = new \DateTimeImmutable($localMoment, $tz);
		} catch (\Throwable $e) {
			$moment = new \DateTimeImmutable('now', $tz);
		}
		$seconds = $moment->getOffset();
		$sign = $seconds < 0 ? '-' : '+';
		$seconds = abs($seconds);

		return sprintf('%s%02d:%02d', $sign, intdiv($seconds, 3600), intdiv($seconds % 3600, 60));
	}

	private function requireWorkScheduleHelper(): void
	{
		if (!class_exists(\Joomla\Plugin\User\Vigling\Helper\WorkScheduleHelper::class, false)) {
			$vgWorkScheduleFile = JPATH_PLUGINS . '/user/vigling/src/Helper/WorkScheduleHelper.php';
			if (is_file($vgWorkScheduleFile)) {
				require_once $vgWorkScheduleFile;
			}
		}
	}

	private function getUserFieldIds($db): array
	{
		if (is_array($this->fieldIdsCache)) {
			return $this->fieldIdsCache;
		}

		$names = ['sity', 'area', 'home', 'payment_method', 'suitable_for_children', 'work_day', 'work_from', 'work_to'];
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
		$payment = [];
		foreach ((array) $input->get('payment', [], 'array') as $payKey) {
			$payKey = strtolower(trim((string) $payKey));
			if (in_array($payKey, ['card', 'cash', 'transfer'], true)) {
				$payment[] = $payKey;
			}
		}
		$this->setState('payment', array_values(array_unique($payment)));
		$childrenRaw = strtolower(trim((string) $input->get('children', '', 'string')));
		$this->setState('children', in_array($childrenRaw, ['1', 'yes', 'on', 'true', 'да'], true) ? 1 : 0);
		$this->setState('booking_mode', trim((string) $input->getString('booking_mode', '')));
		$this->requireWorkScheduleHelper();
		$availDate = class_exists(\Joomla\Plugin\User\Vigling\Helper\WorkScheduleHelper::class, false)
			? \Joomla\Plugin\User\Vigling\Helper\WorkScheduleHelper::composeAvailFilter(
				$input->getString('avail_day', ''),
				$input->getString('avail_time', ''),
				$input->getString('avail_date', '')
			)
			: '';
		$this->setState('avail_date', $availDate);

		$orderCol = CatalogSortHelper::resolveOrdering(CatalogSortHelper::GROUP_OFFERS, ['newest', 'price', 'date'], 'newest');
		$orderDir = $orderCol === 'newest' ? 'DESC' : 'ASC';

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