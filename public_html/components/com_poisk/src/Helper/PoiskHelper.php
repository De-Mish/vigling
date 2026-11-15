<?php

namespace Viglin\Component\Poisk\Site\Helper;

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\Plugin\User\Vigling\Helper\JsnDecodeHelper;

class PoiskHelper
{
	private static $fieldMapCache = null;
	private static $serviceHierarchyCache = [];
	private static array $categoriesCache = [];
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
		$cacheKey = $pathPrefix ?? '__default__';
		if (isset(self::$categoriesCache[$cacheKey])) {
			return self::$categoriesCache[$cacheKey];
		}

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
			return self::$categoriesCache[$cacheKey] = $rows;
		} catch (\Throwable $e) {
			return self::$categoriesCache[$cacheKey] = [];
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
		$hierarchy = self::loadCatalogServiceHierarchy($categoryIds);

		try {
			$runtimeHierarchy = JsnDecodeHelper::getFilterServiceHierarchy($categoryIds);
		} catch (\Throwable $e) {
			$runtimeHierarchy = [];
		}
		// Masters may still hold services that are no longer in the catalog; keep them selectable.
		foreach ((array) $runtimeHierarchy as $categoryId => $services) {
			$categoryId = (int) $categoryId;
			if (!isset($hierarchy[$categoryId])) {
				continue;
			}
			foreach ((array) $services as $service) {
				$serviceId = (int) ($service['id'] ?? 0);
				if ($serviceId <= 0) {
					continue;
				}
				if (!isset($hierarchy[$categoryId][$serviceId])) {
					$hierarchy[$categoryId][$serviceId] = [
						'id' => $serviceId,
						'title' => (string) ($service['title'] ?? ''),
						'tags' => [],
					];
				}
				foreach ((array) ($service['tags'] ?? []) as $tag) {
					$tagId = (int) ($tag['id'] ?? 0);
					if ($tagId > 0 && !isset($hierarchy[$categoryId][$serviceId]['tags'][$tagId])) {
						$hierarchy[$categoryId][$serviceId]['tags'][$tagId] = [
							'id' => $tagId,
							'title' => (string) ($tag['title'] ?? ''),
						];
					}
				}
			}
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

	/**
	 * Specialty → service → method catalog, the same source the registration form uses.
	 *
	 * @param array<int> $categoryIds
	 * @return array<int, array<int, array{id:int,title:string,tags:array<int,array{id:int,title:string}>}>>
	 */
	private static function loadCatalogServiceHierarchy(array $categoryIds): array
	{
		$hierarchy = [];
		foreach ($categoryIds as $categoryId) {
			$hierarchy[(int) $categoryId] = [];
		}
		if ($categoryIds === []) {
			return $hierarchy;
		}

		try {
			$db = Factory::getContainer()->get(\Joomla\Database\DatabaseInterface::class);
			$query = $db->getQuery(true)
				->select([$db->quoteName('id'), $db->quoteName('catid'), $db->quoteName('title')])
				->from($db->quoteName('#__content'))
				->where($db->quoteName('state') . ' = 1')
				->whereIn($db->quoteName('catid'), $categoryIds);
			$db->setQuery($query);
			$serviceRows = $db->loadAssocList() ?: [];

			$categoryByService = [];
			foreach ($serviceRows as $row) {
				$serviceId = (int) ($row['id'] ?? 0);
				$categoryId = (int) ($row['catid'] ?? 0);
				$title = trim((string) ($row['title'] ?? ''));
				if ($serviceId <= 0 || $title === '' || !isset($hierarchy[$categoryId])) {
					continue;
				}
				$hierarchy[$categoryId][$serviceId] = ['id' => $serviceId, 'title' => $title, 'tags' => []];
				$categoryByService[$serviceId] = $categoryId;
			}

			if ($categoryByService !== []) {
				$query = $db->getQuery(true)
					->select([
						$db->quoteName('m.content_item_id', 'content_id'),
						$db->quoteName('t.id', 'tag_id'),
						$db->quoteName('t.title', 'tag_title'),
					])
					->from($db->quoteName('#__contentitem_tag_map', 'm'))
					->join('INNER', $db->quoteName('#__tags', 't') . ' ON ' . $db->quoteName('t.id') . ' = ' . $db->quoteName('m.tag_id'))
					->where($db->quoteName('m.type_alias') . ' = ' . $db->quote('com_content.article'))
					->whereIn($db->quoteName('m.content_item_id'), array_keys($categoryByService))
					->where($db->quoteName('t.published') . ' = 1');
				$db->setQuery($query);
				foreach ($db->loadAssocList() ?: [] as $row) {
					$serviceId = (int) ($row['content_id'] ?? 0);
					$tagId = (int) ($row['tag_id'] ?? 0);
					$tagTitle = trim((string) ($row['tag_title'] ?? ''));
					$categoryId = $categoryByService[$serviceId] ?? 0;
					if ($categoryId <= 0 || $tagId <= 0 || $tagTitle === '') {
						continue;
					}
					$hierarchy[$categoryId][$serviceId]['tags'][$tagId] = ['id' => $tagId, 'title' => $tagTitle];
				}
			}
		} catch (\Throwable $e) {
			return $hierarchy;
		}

		return $hierarchy;
	}

	public static function getCategoryIdsByPathPrefix(?string $pathPrefix = null): array
	{
		$categories = self::getCategories($pathPrefix);
		return array_keys($categories);
	}

	/**
	 * Per-specialist price for the selected service, optionally narrowed by method (tag).
	 *
	 * @param array<int> $userIds
	 * @return array<int, int> userId => price
	 */
	public static function getServicePricesForUsers(array $userIds, int $serviceId, int $tagId = 0): array
	{
		$details = self::getFilteredServiceDetailsForUsers($userIds, $serviceId, $tagId);
		$prices = [];
		foreach ($details as $userId => $row) {
			$prices[$userId] = (int) ($row['price'] ?? 0);
		}

		return $prices;
	}

	/**
	 * @param array<int> $userIds
	 * @return array<int, array{price:int, recommendation:string}>
	 */
	public static function getFilteredServiceDetailsForUsers(array $userIds, int $serviceId, int $tagId = 0): array
	{
		$ids = array_values(array_unique(array_filter(array_map('intval', $userIds), static function (int $id): bool {
			return $id > 0;
		})));
		if ($ids === [] || $serviceId <= 0) {
			return [];
		}

		$hasRecommendation = false;
		try {
			$db = Factory::getContainer()->get(\Joomla\Database\DatabaseInterface::class);
			$select = [
				$db->quoteName('us.user_id'),
				$db->quoteName('us.price'),
				$db->quoteName('us.legacy_cat_id'),
				$db->quoteName('n.legacy_id', 'node_legacy_id'),
				$db->quoteName('parent.legacy_id', 'parent_legacy_id'),
			];
			$hasRecommendation = class_exists('\\Joomla\\Plugin\\User\\Vigling\\Service\\UserServicesService')
				&& \Joomla\Plugin\User\Vigling\Service\UserServicesService::ensureRecommendationColumn();
			if ($hasRecommendation) {
				$select[] = $db->quoteName('us.recommendation');
			}
			$query = $db->getQuery(true)
				->select($select)
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
				->where($db->quoteName('us.price') . ' > 0')
				->where(
					'('
					. $db->quoteName('us.legacy_cat_id') . ' = ' . $serviceId
					. ' OR ' . $db->quoteName('n.legacy_id') . ' = ' . $serviceId
					. ' OR ' . $db->quoteName('parent.legacy_id') . ' = ' . $serviceId
					. ')'
				)
				->order($db->quoteName('us.id') . ' ASC');
			if ($tagId > 0) {
				$query->where($db->quoteName('us.legacy_tag_id') . ' = ' . $tagId);
			}
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
			$payload = [
				'price' => $price,
				'recommendation' => $hasRecommendation && class_exists('\\Joomla\\Plugin\\User\\Vigling\\Service\\UserServicesService')
					? \Joomla\Plugin\User\Vigling\Service\UserServicesService::sanitizeRecommendation($row['recommendation'] ?? '')
					: trim((string) ($row['recommendation'] ?? '')),
			];
			if ((int) ($row['legacy_cat_id'] ?? 0) === $serviceId) {
				if (!isset($exact[$userId])) {
					$exact[$userId] = $payload;
				}
				continue;
			}
			if (!isset($fallback[$userId])) {
				$fallback[$userId] = $payload;
			}
		}

		return $exact + array_diff_key($fallback, $exact);
	}

	/**
	 * List-page variant: the same data kept for a few minutes (the item lists themselves are cached for
	 * 3 minutes). Not for single-user lookups such as the viewer's own city, which must follow edits at once.
	 *
	 * @param array<int> $userIds
	 * @return array<int|string, array<string, mixed>>
	 */
	public static function getFieldsForUserIdsCached(array $userIds, array $fieldNames, int $ttl = 180): array
	{
		$ids = array_map('intval', $userIds);
		sort($ids);
		$names = array_values(array_map('strval', $fieldNames));
		sort($names);

		return self::rememberCatalog('card-fields', implode(',', $ids) . '|' . implode(',', $names), $ttl, static function () use ($userIds, $fieldNames) {
			return self::getFieldsForUserIds($userIds, $fieldNames);
		});
	}

	/**
	 * @param array<int> $userIds
	 * @return array<int, array{price:int, recommendation:string}>
	 */
	public static function getFilteredServiceDetailsForUsersCached(array $userIds, int $serviceId, int $tagId = 0, int $ttl = 180): array
	{
		$ids = array_map('intval', $userIds);
		sort($ids);

		return self::rememberCatalog('card-prices', implode(',', $ids) . '|' . $serviceId . '|' . $tagId, $ttl, static function () use ($userIds, $serviceId, $tagId) {
			return self::getFilteredServiceDetailsForUsers($userIds, $serviceId, $tagId);
		});
	}

	private static function rememberCatalog(string $bucket, string $key, int $ttl, callable $loader)
	{
		if (!class_exists(\Joomla\Plugin\User\Vigling\Helper\CatalogQueryCache::class, false)) {
			$path = JPATH_PLUGINS . '/user/vigling/src/Helper/CatalogQueryCache.php';
			if (is_file($path)) {
				require_once $path;
			}
		}
		if (class_exists(\Joomla\Plugin\User\Vigling\Helper\CatalogQueryCache::class, false)) {
			return \Joomla\Plugin\User\Vigling\Helper\CatalogQueryCache::remember($bucket, $key, $ttl, $loader);
		}

		return $loader();
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
		if (\in_array('avatar', $fieldNames, true)) {
			self::fillMissingAvatarsFromDisk($byUser, $ids);
		}

		return $byUser;
	}

	/**
	 * @param array<int|string,array<string,mixed>> $byUser
	 * @param list<int> $ids
	 */
	public static function fillMissingAvatarsFromDisk(array &$byUser, array $ids): void
	{
		$helperFile = JPATH_PLUGINS . '/user/vigling/src/Helper/ImageUploadHelper.php';
		if (!class_exists(\Joomla\Plugin\User\Vigling\Helper\ImageUploadHelper::class, false) && is_file($helperFile)) {
			require_once $helperFile;
		}
		if (!class_exists(\Joomla\Plugin\User\Vigling\Helper\ImageUploadHelper::class, false)) {
			return;
		}
		$helper = \Joomla\Plugin\User\Vigling\Helper\ImageUploadHelper::class;
		foreach ($ids as $userId) {
			$userId = (int) $userId;
			if ($userId <= 0) {
				continue;
			}
			$stored = isset($byUser[$userId]['avatar']) && is_scalar($byUser[$userId]['avatar'])
				? trim((string) $byUser[$userId]['avatar'])
				: '';
			if ($stored !== '' && $helper::webUrl($stored) !== '') {
				continue;
			}
			$found = $helper::latestRelative('images/profiler', 'avatar_' . $userId);
			if ($found !== '') {
				$byUser[$userId]['avatar'] = $found;
			}
		}
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