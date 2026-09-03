<?php

namespace Viglin\Component\Orders\Site\Model;

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\MVC\Model\ListModel;
use Joomla\Database\DatabaseQuery;
use Joomla\Plugin\User\Vigling\Helper\JsnDecodeHelper;
use Viglin\Component\Orders\Site\Table\OrderTable;

class OrdersModel extends ListModel
{
	protected function populateState($ordering = null, $direction = null): void
	{
		$app = Factory::getApplication();
		$this->setState('list.limit', $app->getInput()->getUint('limit', 50));
		$this->setState('list.start', $app->getInput()->getUint('limitstart', 0));
	}

	protected function getListQuery(): DatabaseQuery
	{
		$user = Factory::getApplication()->getIdentity();
		$db = $this->getDatabase();
		$layout = (string) $this->getState('layout', 'default');
		OrderTable::ensureBookingCommentColumns($db);
		$tableColumns = array_change_key_case($db->getTableColumns('#__vigling_bookings', false), CASE_LOWER);
		$hasCourseColumns = isset($tableColumns['booking_kind'], $tableColumns['course_id'], $tableColumns['course_slot_id']);
		$hasSearchColumns = $hasCourseColumns && isset($tableColumns['search_id'], $tableColumns['search_slot_id']);
		if ($layout === 'journal') {
			$query = $db->getQuery(true)
				->select('o.id, o.user_id, o.master_id, o.time, o.time_to, o.service_name, o.completed')
				->from($db->quoteName('#__vigling_bookings', 'o'))
				->where($db->quoteName('o.master_id') . ' = ' . (int) $user->id)
				->where($db->quoteName('o.user_id') . ' = 0')
				->where($db->quoteName('o.time_to') . ' >= UTC_TIMESTAMP()')
				->order($db->quoteName('o.time') . ' ASC');
			if ($hasCourseColumns) {
				$query->select([
					$db->quoteName('o.booking_kind'),
					$db->quoteName('o.course_id'),
					$db->quoteName('o.course_slot_id'),
				]);
			}
			if ($hasSearchColumns) {
				$query->select([
					$db->quoteName('o.search_id'),
					$db->quoteName('o.search_slot_id'),
				]);
			}
			return $query;
		}
		$query = $db->getQuery(true)
			->select('o.id, o.user_id, o.master_id, o.time, o.time_to, o.service_name')
			->from($db->quoteName('#__vigling_bookings', 'o'))
			->order($db->quoteName('o.time') . ' DESC');
		if (isset($tableColumns['comment'])) {
			$query->select($db->quoteName('o.comment'));
		}
		if (isset($tableColumns['contact_name'])) {
			$query->select($db->quoteName('o.contact_name'));
		}
		if (isset($tableColumns['contact_phone'])) {
			$query->select($db->quoteName('o.contact_phone'));
		}
		if ($hasCourseColumns) {
			$query->select([
				$db->quoteName('o.booking_kind'),
				$db->quoteName('o.course_id'),
				$db->quoteName('o.course_slot_id'),
			]);
		}
		if ($hasSearchColumns) {
			$query->select([
				$db->quoteName('o.search_id'),
				$db->quoteName('o.search_slot_id'),
			]);
		}
		if ((int) $this->getState('as_master', 0) === 1) {
			$query->select('o.completed')
				->where($db->quoteName('o.master_id') . ' = ' . (int) $user->id)
				->where($db->quoteName('o.user_id') . ' > 0');
		} else {
			$query->where($db->quoteName('o.user_id') . ' = ' . (int) $user->id);
		}
		return $query;
	}

	public function getItems(): array
	{
		$items = parent::getItems();
		if (empty($items)) {
			return [];
		}
		if ((string) $this->getState('layout', 'default') === 'journal') {
			return $items;
		}
		$asMaster = (int) $this->getState('as_master', 0) === 1;
		$tableColumns = array_change_key_case($this->getDatabase()->getTableColumns('#__vigling_bookings', false), CASE_LOWER);
		$hasCourseColumns = isset($tableColumns['booking_kind'], $tableColumns['course_id'], $tableColumns['course_slot_id']);
		$hasSearchColumns = $hasCourseColumns && isset($tableColumns['search_id'], $tableColumns['search_slot_id']);
		if ($asMaster) {
			$clientIds = array_unique(array_map(function ($o) {
				return (int) $o->user_id;
			}, $items));
			$clientInfo = $this->getClientInfo($clientIds);
			$serviceDisplayMap = $this->getMasterServiceDisplayMap(array_unique(array_map(function ($o) {
				return (int) $o->master_id;
			}, $items)));
			$courseInfoMap = [];
			$courseSlotInfoMap = [];
			$searchInfoMap = [];
			$searchSlotInfoMap = [];
			if ($hasCourseColumns) {
				$courseIds = array_unique(array_map(static function ($o): int {
					return (int) ($o->course_id ?? 0);
				}, $items));
				$courseSlotIds = array_unique(array_map(static function ($o): int {
					return (int) ($o->course_slot_id ?? 0);
				}, $items));
				$courseInfoMap = $this->getCourseInfoMap($courseIds);
				$courseSlotInfoMap = $this->getCourseSlotInfoMap($courseSlotIds);
			}
			if ($hasSearchColumns) {
				$searchIds = array_unique(array_map(static function ($o): int {
					return (int) ($o->search_id ?? 0);
				}, $items));
				$searchSlotIds = array_unique(array_map(static function ($o): int {
					return (int) ($o->search_slot_id ?? 0);
				}, $items));
				$searchInfoMap = $this->getSearchInfoMap($searchIds);
				$searchSlotInfoMap = $this->getSearchSlotInfoMap($searchSlotIds);
			}
			foreach ($items as $item) {
				$c = $clientInfo[(int) $item->user_id] ?? null;
				$item->client_name = $c ? $c['name'] : '—';
				$item->client_email = $c ? $c['email'] : '—';
				$item->client_phone = $c ? ($c['phone'] ?? '') : '';
				$item->completed = isset($item->completed) ? (int) $item->completed : 0;
				$item->booking_kind = $hasCourseColumns ? trim((string) ($item->booking_kind ?? 'service')) : 'service';
				$item->course_id = $hasCourseColumns ? (int) ($item->course_id ?? 0) : 0;
				$item->course_slot_id = $hasCourseColumns ? (int) ($item->course_slot_id ?? 0) : 0;
				$item->search_id = $hasSearchColumns ? (int) ($item->search_id ?? 0) : 0;
				$item->search_slot_id = $hasSearchColumns ? (int) ($item->search_slot_id ?? 0) : 0;
				$item->service_display_name = $this->resolveOrderServiceDisplayName(
					trim((string) ($item->service_name ?? '')),
					$serviceDisplayMap[(int) $item->master_id] ?? ['exact' => [], 'suffix' => []]
				);
				if ($item->booking_kind === 'course' && $item->course_id > 0) {
					$courseInfo = $courseInfoMap[$item->course_id] ?? [];
					$slotInfo = $courseSlotInfoMap[$item->course_slot_id] ?? [];
					$item->course_category_title = trim((string) ($courseInfo['category_title'] ?? ''));
					$item->course_title = trim((string) ($courseInfo['title'] ?? $courseInfo['description'] ?? ''));
					$item->course_description = trim((string) ($courseInfo['description'] ?? ''));
					$item->course_capacity = (int) ($courseInfo['capacity'] ?? 0);
					$item->course_slot_capacity_total = (int) ($slotInfo['capacity_total'] ?? 0);
					$item->course_slot_start_utc = trim((string) ($slotInfo['starts_at_utc'] ?? ''));
					$item->course_slot_end_utc = trim((string) ($slotInfo['ends_at_utc'] ?? ''));
					if ($item->course_title !== '') {
						$item->service_display_name = 'Курс: ' . $item->course_title;
					}
				}
				if ($item->booking_kind === 'search' && $item->search_id > 0) {
					$searchInfo = $searchInfoMap[$item->search_id] ?? [];
					$slotInfo = $searchSlotInfoMap[$item->search_slot_id] ?? [];
					$item->search_category_title = trim((string) ($searchInfo['category_title'] ?? ''));
					$item->search_title = trim((string) ($searchInfo['title'] ?? $searchInfo['description'] ?? ''));
					$item->search_description = trim((string) ($searchInfo['description'] ?? ''));
					$item->search_capacity = (int) ($searchInfo['capacity'] ?? 0);
					$item->search_slot_capacity_total = (int) ($slotInfo['capacity_total'] ?? 0);
					$item->search_slot_start_utc = trim((string) ($slotInfo['starts_at_utc'] ?? ''));
					$item->search_slot_end_utc = trim((string) ($slotInfo['ends_at_utc'] ?? ''));
					if ($item->search_title !== '') {
						$item->service_display_name = 'Поиск моделей: ' . $item->search_title;
					}
				}
			}
		} else {
			$masterIds = array_unique(array_map(function ($o) {
				return (int) $o->master_id;
			}, $items));
			$names = $this->getUserNames($masterIds);
			$serviceDisplayMap = $this->getMasterServiceDisplayMap($masterIds);
			$courseInfoMap = [];
			$courseSlotInfoMap = [];
			$searchInfoMap = [];
			$searchSlotInfoMap = [];
			if ($hasCourseColumns) {
				$courseIds = array_unique(array_map(static function ($o): int {
					return (int) ($o->course_id ?? 0);
				}, $items));
				$courseSlotIds = array_unique(array_map(static function ($o): int {
					return (int) ($o->course_slot_id ?? 0);
				}, $items));
				$courseInfoMap = $this->getCourseInfoMap($courseIds);
				$courseSlotInfoMap = $this->getCourseSlotInfoMap($courseSlotIds);
			}
			if ($hasSearchColumns) {
				$searchIds = array_unique(array_map(static function ($o): int {
					return (int) ($o->search_id ?? 0);
				}, $items));
				$searchSlotIds = array_unique(array_map(static function ($o): int {
					return (int) ($o->search_slot_id ?? 0);
				}, $items));
				$searchInfoMap = $this->getSearchInfoMap($searchIds);
				$searchSlotInfoMap = $this->getSearchSlotInfoMap($searchSlotIds);
			}
			foreach ($items as $item) {
				$item->master_name = $names[(int) $item->master_id] ?? '—';
				$item->booking_kind = $hasCourseColumns ? trim((string) ($item->booking_kind ?? 'service')) : 'service';
				$item->course_id = $hasCourseColumns ? (int) ($item->course_id ?? 0) : 0;
				$item->course_slot_id = $hasCourseColumns ? (int) ($item->course_slot_id ?? 0) : 0;
				$item->search_id = $hasSearchColumns ? (int) ($item->search_id ?? 0) : 0;
				$item->search_slot_id = $hasSearchColumns ? (int) ($item->search_slot_id ?? 0) : 0;
				$item->service_display_name = $this->resolveOrderServiceDisplayName(
					trim((string) ($item->service_name ?? '')),
					$serviceDisplayMap[(int) $item->master_id] ?? ['exact' => [], 'suffix' => []]
				);
				if ($item->booking_kind === 'course' && $item->course_id > 0) {
					$courseInfo = $courseInfoMap[$item->course_id] ?? [];
					$slotInfo = $courseSlotInfoMap[$item->course_slot_id] ?? [];
					$item->course_category_title = trim((string) ($courseInfo['category_title'] ?? ''));
					$item->course_title = trim((string) ($courseInfo['title'] ?? $courseInfo['description'] ?? ''));
					$item->course_description = trim((string) ($courseInfo['description'] ?? ''));
					$item->course_capacity = (int) ($courseInfo['capacity'] ?? 0);
					$item->course_slot_capacity_total = (int) ($slotInfo['capacity_total'] ?? 0);
					$item->course_slot_start_utc = trim((string) ($slotInfo['starts_at_utc'] ?? ''));
					$item->course_slot_end_utc = trim((string) ($slotInfo['ends_at_utc'] ?? ''));
					if ($item->course_title !== '') {
						$item->service_display_name = 'Курс: ' . $item->course_title;
					} elseif (trim((string) ($item->service_name ?? '')) !== '') {
						$item->service_display_name = 'Курс: ' . trim((string) $item->service_name);
					} else {
						$item->service_display_name = 'Курс';
					}
				}
				if ($item->booking_kind === 'search' && $item->search_id > 0) {
					$searchInfo = $searchInfoMap[$item->search_id] ?? [];
					$slotInfo = $searchSlotInfoMap[$item->search_slot_id] ?? [];
					$item->search_category_title = trim((string) ($searchInfo['category_title'] ?? ''));
					$item->search_title = trim((string) ($searchInfo['title'] ?? $searchInfo['description'] ?? ''));
					$item->search_description = trim((string) ($searchInfo['description'] ?? ''));
					$item->search_capacity = (int) ($searchInfo['capacity'] ?? 0);
					$item->search_slot_capacity_total = (int) ($slotInfo['capacity_total'] ?? 0);
					$item->search_slot_start_utc = trim((string) ($slotInfo['starts_at_utc'] ?? ''));
					$item->search_slot_end_utc = trim((string) ($slotInfo['ends_at_utc'] ?? ''));
					if ($item->search_title !== '') {
						$item->service_display_name = 'Поиск моделей: ' . $item->search_title;
					} elseif (trim((string) ($item->service_name ?? '')) !== '') {
						$item->service_display_name = 'Поиск моделей: ' . trim((string) $item->service_name);
					} else {
						$item->service_display_name = 'Поиск моделей';
					}
				}
			}
		}
		return $items;
	}

	/**
	 * @param array<int> $courseIds
	 * @return array<int, array{category_title: string, title: string, description: string, capacity: int}>
	 */
	private function getCourseInfoMap(array $courseIds): array
	{
		$ids = array_values(array_filter(array_map('intval', $courseIds), static fn(int $id): bool => $id > 0));
		if ($ids === []) {
			return [];
		}

		$db = $this->getDatabase();
		$query = $db->getQuery(true)
			->select([
				$db->quoteName('c.id'),
				$db->quoteName('c.title'),
				$db->quoteName('c.description'),
				$db->quoteName('c.capacity'),
				$db->quoteName('cat.title', 'category_title'),
			])
			->from($db->quoteName('#__vigling_user_courses', 'c'))
			->join('LEFT', $db->quoteName('#__categories', 'cat') . ' ON ' . $db->quoteName('cat.id') . ' = ' . $db->quoteName('c.category_id'))
			->whereIn($db->quoteName('c.id'), $ids);
		$db->setQuery($query);
		$rows = $db->loadAssocList() ?: [];

		$out = [];
		foreach ($rows as $row) {
			$id = (int) ($row['id'] ?? 0);
			if ($id <= 0) {
				continue;
			}
			$out[$id] = [
				'category_title' => trim((string) ($row['category_title'] ?? '')),
				'title' => trim((string) ($row['title'] ?? $row['description'] ?? '')),
				'description' => trim((string) ($row['description'] ?? '')),
				'capacity' => (int) ($row['capacity'] ?? 0),
			];
		}

		return $out;
	}

	/**
	 * @param array<int> $slotIds
	 * @return array<int, array{starts_at_utc: string, ends_at_utc: string, capacity_total: int}>
	 */
	private function getCourseSlotInfoMap(array $slotIds): array
	{
		$ids = array_values(array_filter(array_map('intval', $slotIds), static fn(int $id): bool => $id > 0));
		if ($ids === []) {
			return [];
		}

		$db = $this->getDatabase();
		$query = $db->getQuery(true)
			->select([
				$db->quoteName('id'),
				$db->quoteName('starts_at_utc'),
				$db->quoteName('ends_at_utc'),
				$db->quoteName('capacity_total'),
			])
			->from($db->quoteName('#__vigling_course_slots'))
			->whereIn($db->quoteName('id'), $ids);
		$db->setQuery($query);
		$rows = $db->loadAssocList() ?: [];

		$out = [];
		foreach ($rows as $row) {
			$id = (int) ($row['id'] ?? 0);
			if ($id <= 0) {
				continue;
			}
			$out[$id] = [
				'starts_at_utc' => trim((string) ($row['starts_at_utc'] ?? '')),
				'ends_at_utc' => trim((string) ($row['ends_at_utc'] ?? '')),
				'capacity_total' => (int) ($row['capacity_total'] ?? 0),
			];
		}

		return $out;
	}

	/**
	 * @param array<int> $searchIds
	 * @return array<int, array{category_title: string, title: string, description: string, capacity: int}>
	 */
	private function getSearchInfoMap(array $searchIds): array
	{
		$ids = array_values(array_filter(array_map('intval', $searchIds), static fn(int $id): bool => $id > 0));
		if ($ids === []) {
			return [];
		}

		$db = $this->getDatabase();
		$query = $db->getQuery(true)
			->select([
				$db->quoteName('s.id'),
				$db->quoteName('s.title'),
				$db->quoteName('s.description'),
				$db->quoteName('s.capacity'),
				$db->quoteName('cat.title', 'category_title'),
			])
			->from($db->quoteName('#__vigling_user_searches', 's'))
			->join('LEFT', $db->quoteName('#__categories', 'cat') . ' ON ' . $db->quoteName('cat.id') . ' = ' . $db->quoteName('s.category_id'))
			->whereIn($db->quoteName('s.id'), $ids);
		$db->setQuery($query);
		$rows = $db->loadAssocList() ?: [];

		$out = [];
		foreach ($rows as $row) {
			$id = (int) ($row['id'] ?? 0);
			if ($id <= 0) {
				continue;
			}
			$out[$id] = [
				'category_title' => trim((string) ($row['category_title'] ?? '')),
				'title' => trim((string) ($row['title'] ?? $row['description'] ?? '')),
				'description' => trim((string) ($row['description'] ?? '')),
				'capacity' => (int) ($row['capacity'] ?? 0),
			];
		}

		return $out;
	}

	/**
	 * @param array<int> $slotIds
	 * @return array<int, array{starts_at_utc: string, ends_at_utc: string, capacity_total: int}>
	 */
	private function getSearchSlotInfoMap(array $slotIds): array
	{
		$ids = array_values(array_filter(array_map('intval', $slotIds), static fn(int $id): bool => $id > 0));
		if ($ids === []) {
			return [];
		}

		$db = $this->getDatabase();
		$query = $db->getQuery(true)
			->select([
				$db->quoteName('id'),
				$db->quoteName('starts_at_utc'),
				$db->quoteName('ends_at_utc'),
				$db->quoteName('capacity_total'),
			])
			->from($db->quoteName('#__vigling_search_slots'))
			->whereIn($db->quoteName('id'), $ids);
		$db->setQuery($query);
		$rows = $db->loadAssocList() ?: [];

		$out = [];
		foreach ($rows as $row) {
			$id = (int) ($row['id'] ?? 0);
			if ($id <= 0) {
				continue;
			}
			$out[$id] = [
				'starts_at_utc' => trim((string) ($row['starts_at_utc'] ?? '')),
				'ends_at_utc' => trim((string) ($row['ends_at_utc'] ?? '')),
				'capacity_total' => (int) ($row['capacity_total'] ?? 0),
			];
		}

		return $out;
	}

	/**
	 * @param array<int> $masterIds
	 * @return array<int, array{exact: array<string,string>, suffix: array<string,string>}>
	 */
	private function getMasterServiceDisplayMap(array $masterIds): array
	{
		$out = [];
		foreach ($masterIds as $masterId) {
			$masterId = (int) $masterId;
			if ($masterId <= 0 || isset($out[$masterId])) {
				continue;
			}

			$services = JsnDecodeHelper::getUserServicesStructuredWithIds($masterId);
			$stocks = JsnDecodeHelper::getUserStockServicesStructuredWithIds($masterId);
			$groups = array_merge($services, $stocks);
			$exact = [];
			$suffixCandidates = [];

			foreach ($groups as $group) {
				$categoryTitle = trim((string) ($group['title'] ?? ''));
				foreach ((array) ($group['items'] ?? []) as $item) {
					$itemName = trim((string) ($item['name'] ?? ''));
					if ($itemName === '') {
						continue;
					}
					$fullName = $categoryTitle !== '' ? ($categoryTitle . ' - ' . $itemName) : $itemName;
					$normalizedItemName = $this->normalizeServiceKey($itemName);
					$normalizedFullName = $this->normalizeServiceKey($fullName);

					if ($normalizedItemName !== '' && !isset($exact[$normalizedItemName])) {
						$exact[$normalizedItemName] = $fullName;
					}
					if ($normalizedFullName !== '' && !isset($exact[$normalizedFullName])) {
						$exact[$normalizedFullName] = $fullName;
					}

					if (strpos($itemName, '/') !== false) {
						$parts = array_map('trim', explode('/', $itemName));
						$tail = trim((string) end($parts));
						$normalizedTail = $this->normalizeServiceKey($tail);
						if ($normalizedTail !== '') {
							$suffixCandidates[$normalizedTail] = $suffixCandidates[$normalizedTail] ?? [];
							$suffixCandidates[$normalizedTail][$fullName] = true;
						}
					}
				}
			}

			$suffix = [];
			foreach ($suffixCandidates as $alias => $matches) {
				if (count($matches) === 1) {
					$suffix[$alias] = (string) array_key_first($matches);
				}
			}

			$out[$masterId] = ['exact' => $exact, 'suffix' => $suffix];
		}

		return $out;
	}

	/**
	 * @param array{exact: array<string,string>, suffix: array<string,string>} $map
	 */
	private function resolveOrderServiceDisplayName(string $serviceName, array $map): string
	{
		if ($serviceName === '' || strpos($serviceName, '[journal]') === 0) {
			return $serviceName;
		}

		$normalized = $this->normalizeServiceKey($serviceName);
		if ($normalized === '') {
			return $serviceName;
		}

		if (isset($map['exact'][$normalized]) && $map['exact'][$normalized] !== '') {
			return $map['exact'][$normalized];
		}

		if (isset($map['suffix'][$normalized]) && $map['suffix'][$normalized] !== '') {
			return $map['suffix'][$normalized];
		}

		return $serviceName;
	}

	private function normalizeServiceKey(string $value): string
	{
		$value = preg_replace('/\s+/u', ' ', trim($value));
		if (!is_string($value) || $value === '') {
			return '';
		}

		return function_exists('mb_strtolower') ? mb_strtolower($value) : strtolower($value);
	}

	private function getClientInfo(array $userIds): array
	{
		if (empty($userIds)) {
			return [];
		}
		$db = $this->getDatabase();
		$ids = array_map('intval', $userIds);
		$query = $db->getQuery(true)
			->select($db->quoteName(['id', 'name', 'email']))
			->from($db->quoteName('#__users'))
			->whereIn($db->quoteName('id'), $ids);
		$db->setQuery($query);
		$rows = $db->loadObjectList('id') ?: [];
		$out = [];
		foreach ($rows as $id => $r) {
			$out[$id] = [
				'name' => trim((string) ($r->name ?? '')) ?: '—',
				'email' => trim((string) ($r->email ?? '')) ?: '—',
				'phone' => '',
			];
		}
		$query = $db->getQuery(true)
			->select($db->quoteName(['user_id', 'profile_value']))
			->from($db->quoteName('#__user_profiles'))
			->where($db->quoteName('profile_key') . ' = ' . $db->quote('profile.phone'))
			->whereIn($db->quoteName('user_id'), $ids);
		$db->setQuery($query);
		$phones = $db->loadObjectList() ?: [];
		foreach ($phones as $row) {
			$uid = (int) $row->user_id;
			if (isset($out[$uid])) {
				$val = $row->profile_value;
				if (is_string($val) && $val !== '') {
					$decoded = json_decode($val);
					$out[$uid]['phone'] = is_string($decoded) ? trim($decoded) : trim($val);
				}
			}
		}
		return $out;
	}

	private function getUserNames(array $userIds): array
	{
		if (empty($userIds)) {
			return [];
		}
		$db = $this->getDatabase();
		$ids = array_map('intval', $userIds);
		$query = $db->getQuery(true)
			->select($db->quoteName(['id', 'name']))
			->from($db->quoteName('#__users'))
			->whereIn($db->quoteName('id'), $ids);
		$db->setQuery($query);
		$rows = $db->loadObjectList('id') ?: [];
		$out = [];
		foreach ($rows as $id => $r) {
			$out[$id] = $r->name ?: '—';
		}
		return $out;
	}
}
