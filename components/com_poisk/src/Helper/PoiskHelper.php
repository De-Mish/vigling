<?php

namespace Viglin\Component\Poisk\Site\Helper;

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\Plugin\User\Vigling\Helper\JsnDecodeHelper;

class PoiskHelper
{
	private static $fieldMapCache = null;
	private static $serviceHierarchyCache = [];
	private static array $filterListCache = [];
	private const FILTER_LIST_CACHE_TTL = 3600;
	private const CATEGORY_ORDER = [
		'барбер' => 10,
		'брови' => 20,
		'ресницы' => 30,
		'визажист (макияж)' => 40,
		'загар (солярий)' => 50,
		'косметология' => 60,
		'маникюр' => 70,
		'массаж' => 80,
		'парикмахер' => 90,
		'педикюр' => 100,
		'спа' => 110,
		'тату-пирсинг' => 120,
		'эпиляция' => 130,
	];

	private const CATEGORY_FILTER_TITLES = [
		'визажист (макияж)' => 'Визажист',
		'загар (солярий)' => 'Загар',
		'тату-пирсинг' => 'Тату',
	];

	private static function getUserFieldMap(): array
	{
		if (is_array(self::$fieldMapCache)) {
			return self::$fieldMapCache;
		}

		$db = Factory::getContainer()->get(\Joomla\Database\DatabaseInterface::class);
		$prefix = $db->getPrefix();
		$query = $db->getQuery(true)
			->select([$db->quoteName('id'), $db->quoteName('name')])
			->from($db->quoteName($prefix . 'fields'))
			->where($db->quoteName('context') . ' = ' . $db->quote('com_users.user'));
		$db->setQuery($query);
		$rows = $db->loadAssocList() ?: [];
		$map = [];
		foreach ($rows as $row) {
			$map[(string) $row['name']] = (int) $row['id'];
		}
		self::$fieldMapCache = $map;
		return $map;
	}

	public static function getCategories(?string $pathPrefix = null): array
	{
		$db = Factory::getContainer()->get(\Joomla\Database\DatabaseInterface::class);
		$prefix = $db->getPrefix();
		$query = $db->getQuery(true)
			->select('id, title')
			->from($db->quoteName($prefix . 'categories'))
			->where($db->quoteName('extension') . ' = ' . $db->quote('com_content'))
			->where($db->quoteName('published') . ' = 1');
		if ($pathPrefix === 'zatochka-remont') {
			$query->where($db->quoteName('level') . ' = 2')
				->where($db->quoteName('path') . ' LIKE ' . $db->quote('zatochka-remont/%'));
		} else {
			$query->where('(' . $db->quoteName('parent_id') . ' = 39 OR ' . $db->quoteName('path') . ' LIKE ' . $db->quote('uslugi/%') . ' OR ' . $db->quoteName('id') . ' IN (9,10,11,12,13,14,16,17,18,19,20,21))');
			$query->where($db->quoteName('path') . ' NOT LIKE ' . $db->quote('zatochka-remont/%'));
			$query->where($db->quoteName('path') . ' <> ' . $db->quote('zatochka-remont'));
		}
		$query->order($db->quoteName('id') . ' ASC');
		$db->setQuery($query);
		try {
			$rows = $db->loadAssocList('id') ?: [];
			if ($pathPrefix !== 'zatochka-remont') {
				$rows = array_filter($rows, static function (array $row): bool {
					$title = trim((string) ($row['title'] ?? ''));
					if ($title === '') {
						return false;
					}

					return isset(self::CATEGORY_ORDER[self::normalizeTitle($title)]);
				});
				foreach ($rows as &$row) {
					$row['filter_title'] = self::getFilterTitle((string) ($row['title'] ?? ''));
				}
				unset($row);
				$rows = self::sortCategories($rows);
			}
			return $rows;
		} catch (\Throwable $e) {
			return [];
		}
	}

	public static function getServiceHierarchy(?string $pathPrefix = null): array
	{
		$cacheKey = $pathPrefix ?? '__default__';
		if (isset(self::$serviceHierarchyCache[$cacheKey])) {
			return self::$serviceHierarchyCache[$cacheKey];
		}

		$categories = self::getCategories($pathPrefix);
		if ($categories === []) {
			return self::$serviceHierarchyCache[$cacheKey] = [];
		}

		$categoryIds = array_map('intval', array_keys($categories));
		$hierarchy = [];
		foreach ($categoryIds as $categoryId) {
			$hierarchy[$categoryId] = [];
		}

		try {
			$runtimeHierarchy = JsnDecodeHelper::getFilterServiceHierarchy($categoryIds);
			foreach ($runtimeHierarchy as $categoryId => $services) {
				$hierarchy[(int) $categoryId] = (array) $services;
			}
		} catch (\Throwable $e) {
			return self::$serviceHierarchyCache[$cacheKey] = [];
		}

		foreach ($hierarchy as $categoryId => $services) {
			uasort($services, static function (array $a, array $b): int {
				return strnatcasecmp((string) ($a['title'] ?? ''), (string) ($b['title'] ?? ''));
			});
			foreach ($services as $serviceId => $service) {
				$tags = (array) ($service['tags'] ?? []);
				uasort($tags, static function (array $a, array $b): int {
					return strnatcasecmp((string) ($a['title'] ?? ''), (string) ($b['title'] ?? ''));
				});
				$services[$serviceId]['tags'] = array_values($tags);
			}
			$hierarchy[$categoryId] = array_values($services);
		}

		return self::$serviceHierarchyCache[$cacheKey] = $hierarchy;
	}

	public static function getCategoryIdsByPathPrefix(?string $pathPrefix = null): array
	{
		$categories = self::getCategories($pathPrefix);
		return array_keys($categories);
	}

	/**
	 * Per-specialist price for the selected service + method (3-level filter).
	 *
	 * @param array<int> $userIds
	 * @return array<int, int> userId => price
	 */
	public static function getServicePricesForUsers(array $userIds, int $serviceId, int $tagId): array
	{
		$ids = array_values(array_unique(array_filter(array_map('intval', $userIds), static function (int $id): bool {
			return $id > 0;
		})));
		if ($ids === [] || $serviceId <= 0 || $tagId <= 0) {
			return [];
		}

		try {
			$db = Factory::getContainer()->get(\Joomla\Database\DatabaseInterface::class);
			$query = $db->getQuery(true)
				->select([
					$db->quoteName('us.user_id'),
					$db->quoteName('us.price'),
					$db->quoteName('us.legacy_cat_id'),
					$db->quoteName('n.legacy_id', 'node_legacy_id'),
					$db->quoteName('parent.legacy_id', 'parent_legacy_id'),
				])
				->from($db->quoteName('#__vigling_user_services', 'us'))
				->join(
					'LEFT',
					$db->quoteName('#__vigling_service_nodes', 'n')
					. ' ON ' . $db->quoteName('us.service_node_id') . ' = ' . $db->quoteName('n.id')
				)
				->join(
					'LEFT',
					$db->quoteName('#__vigling_service_nodes', 'parent')
					. ' ON ' . $db->quoteName('n.parent_id') . ' = ' . $db->quoteName('parent.id')
				)
				->whereIn($db->quoteName('us.user_id'), $ids)
				->where($db->quoteName('us.is_active') . ' = 1')
				->where($db->quoteName('us.legacy_tag_id') . ' = ' . $tagId)
				->where($db->quoteName('us.price') . ' > 0')
				->where(
					'('
					. $db->quoteName('us.legacy_cat_id') . ' = ' . $serviceId
					. ' OR ' . $db->quoteName('n.legacy_id') . ' = ' . $serviceId
					. ' OR ' . $db->quoteName('parent.legacy_id') . ' = ' . $serviceId
					. ')'
				)
				->order($db->quoteName('us.id') . ' ASC');
			$db->setQuery($query);
			$rows = $db->loadAssocList() ?: [];
		} catch (\Throwable $e) {
			return [];
		}

		$exact = [];
		$fallback = [];
		foreach ($rows as $row) {
			$userId = (int) ($row['user_id'] ?? 0);
			$price = (int) round((float) ($row['price'] ?? 0));
			if ($userId <= 0 || $price <= 0) {
				continue;
			}
			if ((int) ($row['legacy_cat_id'] ?? 0) === $serviceId) {
				if (!isset($exact[$userId])) {
					$exact[$userId] = $price;
				}
				continue;
			}
			if (!isset($fallback[$userId])) {
				$fallback[$userId] = $price;
			}
		}

		return $exact + array_diff_key($fallback, $exact);
	}

	public static function getFieldsForUserIds(array $userIds, array $fieldNames): array
	{
		if (empty($userIds)) {
			return [];
		}
		$db = Factory::getContainer()->get(\Joomla\Database\DatabaseInterface::class);
		$prefix = $db->getPrefix();
		$ids = array_map('intval', $userIds);
		$fieldMap = self::getUserFieldMap();
		$fieldIdsByName = [];
		$fieldIds = [];
		foreach ($fieldNames as $name) {
			if (!isset($fieldMap[$name])) {
				continue;
			}
			$fId = (int) $fieldMap[$name];
			$fieldIdsByName[$fId] = (string) $name;
			$fieldIds[] = $fId;
		}
		if ($fieldIds === []) {
			return [];
		}

		$query = $db->getQuery(true)
			->select('fv.item_id, fv.field_id, fv.value')
			->from($db->quoteName($prefix . 'fields_values', 'fv'))
			->whereIn($db->quoteName('fv.field_id'), $fieldIds)
			->whereIn($db->quoteName('fv.item_id'), $ids);
		$db->setQuery($query);
		$rows = $db->loadObjectList() ?: [];
		$byUser = [];
		foreach ($rows as $r) {
			$fieldId = (int) $r->field_id;
			if (!isset($fieldIdsByName[$fieldId])) {
				continue;
			}
			$byUser[$r->item_id][$fieldIdsByName[$fieldId]] = $r->value;
		}
		return $byUser;
	}

	public static function getCities(): array
	{
		return self::getCachedFilterList('cities', static function (): array {
		$db = Factory::getContainer()->get(\Joomla\Database\DatabaseInterface::class);
		$prefix = $db->getPrefix();
		$fieldMap = self::getUserFieldMap();
		$fieldId = (int) ($fieldMap['sity'] ?? 0);
		if ($fieldId <= 0) {
			return [];
		}
		$query = $db->getQuery(true)
			->select('DISTINCT TRIM(' . $db->quoteName('fv.value') . ') AS ' . $db->quoteName('city'))
			->from($db->quoteName($prefix . 'fields_values', 'fv'))
			->where($db->quoteName('fv.field_id') . ' = ' . $fieldId)
			->where('TRIM(' . $db->quoteName('fv.value') . ') <> ' . $db->quote(''))
			->order('city ASC');
		$db->setQuery($query);
		try {
			$rows = $db->loadColumn() ?: [];
			return is_array($rows) ? $rows : [];
		} catch (\Throwable $e) {
			return [];
		}
		});
	}

	public static function getAreas(): array
	{
		return self::getCachedFilterList('areas', static function (): array {
		$db = Factory::getContainer()->get(\Joomla\Database\DatabaseInterface::class);
		$prefix = $db->getPrefix();
		$fieldMap = self::getUserFieldMap();
		$fieldId = (int) ($fieldMap['area'] ?? 0);
		if ($fieldId <= 0) {
			return [];
		}
		$query = $db->getQuery(true)
			->select('DISTINCT TRIM(' . $db->quoteName('fv.value') . ') AS ' . $db->quoteName('area'))
			->from($db->quoteName($prefix . 'fields_values', 'fv'))
			->where($db->quoteName('fv.field_id') . ' = ' . $fieldId)
			->where('TRIM(' . $db->quoteName('fv.value') . ') <> ' . $db->quote(''))
			->order('area ASC');
		$db->setQuery($query);
		try {
			$rows = $db->loadColumn() ?: [];
			return is_array($rows) ? $rows : [];
		} catch (\Throwable $e) {
			return [];
		}
		});
	}

	private static function getCachedFilterList(string $key, callable $resolver): array
	{
		if (array_key_exists($key, self::$filterListCache)) {
			return self::$filterListCache[$key];
		}

		$cacheDir = \defined('JPATH_CACHE') ? JPATH_CACHE . '/vigling-public-filters' : '';
		$cacheFile = $cacheDir !== '' ? $cacheDir . '/' . $key . '.json' : '';
		if ($cacheFile !== '' && is_file($cacheFile) && (time() - (int) filemtime($cacheFile)) < self::FILTER_LIST_CACHE_TTL) {
			$cached = json_decode((string) file_get_contents($cacheFile), true);
			if (is_array($cached)) {
				return self::$filterListCache[$key] = array_values(array_map('strval', $cached));
			}
		}

		$values = $resolver();
		$values = is_array($values) ? array_values(array_map('strval', $values)) : [];

		if ($cacheDir !== '') {
			if (!is_dir($cacheDir)) {
				@mkdir($cacheDir, 0775, true);
			}
			if (is_dir($cacheDir) && is_writable($cacheDir)) {
				@file_put_contents($cacheFile, json_encode($values, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
			}
		}

		return self::$filterListCache[$key] = $values;
	}

	private static function sortCategories(array $categories): array
	{
		uasort($categories, static function (array $a, array $b): int {
			$titleA = trim((string) ($a['title'] ?? ''));
			$titleB = trim((string) ($b['title'] ?? ''));
			$rankA = self::CATEGORY_ORDER[self::normalizeTitle($titleA)] ?? 1000;
			$rankB = self::CATEGORY_ORDER[self::normalizeTitle($titleB)] ?? 1000;
			if ($rankA !== $rankB) {
				return $rankA <=> $rankB;
			}
			return strnatcasecmp($titleA, $titleB);
		});

		return $categories;
	}

	private static function normalizeTitle(string $value): string
	{
		$value = preg_replace('/\s+/u', ' ', trim($value));
		if (!is_string($value)) {
			return '';
		}

		return function_exists('mb_strtolower') ? mb_strtolower($value) : strtolower($value);
	}

	private static function getFilterTitle(string $title): string
	{
		$normalized = self::normalizeTitle($title);

		return self::CATEGORY_FILTER_TITLES[$normalized] ?? trim($title);
	}
}
