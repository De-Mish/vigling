<?php

namespace Viglin\Component\Kurs\Site\Helper;

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Viglin\Component\Poisk\Site\Helper\PoiskHelper;

class KursHelper
{
	private static ?array $courseMasterIdsCache = null;
	private static ?array $courseCategoryIdsCache = null;
	private static ?array $fieldMapCache = null;

	private static function getDatabase()
	{
		return Factory::getContainer()->get(\Joomla\Database\DatabaseInterface::class);
	}

	private static function getUserFieldMap(): array
	{
		if (is_array(self::$fieldMapCache)) {
			return self::$fieldMapCache;
		}

		$db = self::getDatabase();
		$query = $db->getQuery(true)
			->select([$db->quoteName('id'), $db->quoteName('name')])
			->from($db->quoteName('#__fields'))
			->where($db->quoteName('context') . ' = ' . $db->quote('com_users.user'));
		$db->setQuery($query);
		$rows = $db->loadAssocList() ?: [];

		self::$fieldMapCache = [];
		foreach ($rows as $row) {
			self::$fieldMapCache[(string) $row['name']] = (int) $row['id'];
		}

		return self::$fieldMapCache;
	}

	public static function getCourseMasterIds(): array
	{
		if (is_array(self::$courseMasterIdsCache)) {
			return self::$courseMasterIdsCache;
		}

		$db = self::getDatabase();
		$query = $db->getQuery(true)
			->select('DISTINCT ' . $db->quoteName('c.user_id'))
			->from($db->quoteName('#__vigling_user_courses', 'c'))
			->join('INNER', $db->quoteName('#__users', 'u') . ' ON ' . $db->quoteName('u.id') . ' = ' . $db->quoteName('c.user_id'))
			->where($db->quoteName('c.is_active') . ' = 1')
			->where($db->quoteName('u.block') . ' = 0')
			->order($db->quoteName('c.user_id') . ' ASC');
		$db->setQuery($query);

		try {
			$rows = $db->loadColumn() ?: [];
		} catch (\Throwable $e) {
			return self::$courseMasterIdsCache = [];
		}

		return self::$courseMasterIdsCache = array_values(array_filter(array_map('intval', (array) $rows)));
	}

	public static function getCourseCategoryIds(): array
	{
		if (is_array(self::$courseCategoryIdsCache)) {
			return self::$courseCategoryIdsCache;
		}

		$db = self::getDatabase();
		$query = $db->getQuery(true)
			->select('DISTINCT ' . $db->quoteName('c.category_id'))
			->from($db->quoteName('#__vigling_user_courses', 'c'))
			->join('INNER', $db->quoteName('#__users', 'u') . ' ON ' . $db->quoteName('u.id') . ' = ' . $db->quoteName('c.user_id'))
			->where($db->quoteName('c.is_active') . ' = 1')
			->where($db->quoteName('u.block') . ' = 0')
			->where($db->quoteName('c.category_id') . ' > 0');
		$db->setQuery($query);

		try {
			$rows = $db->loadColumn() ?: [];
		} catch (\Throwable $e) {
			return self::$courseCategoryIdsCache = [];
		}

		return self::$courseCategoryIdsCache = array_values(array_filter(array_map('intval', (array) $rows)));
	}

	public static function getCategories(): array
	{
		$categories = PoiskHelper::getCategories();
		$categoryIds = self::getCourseCategoryIds();
		if ($categoryIds === []) {
			return [];
		}

		return array_intersect_key($categories, array_flip($categoryIds));
	}

	public static function getFieldsForUserIds(array $userIds, array $fieldNames): array
	{
		return PoiskHelper::getFieldsForUserIds($userIds, $fieldNames);
	}

	private static function getDistinctFieldValues(string $fieldName): array
	{
		$masterIds = self::getCourseMasterIds();
		if ($masterIds === []) {
			return [];
		}

		$fieldMap = self::getUserFieldMap();
		$fieldId = (int) ($fieldMap[$fieldName] ?? 0);
		if ($fieldId <= 0) {
			return [];
		}

		$db = self::getDatabase();
		$query = $db->getQuery(true)
			->select('DISTINCT TRIM(' . $db->quoteName('fv.value') . ') AS ' . $db->quoteName('value'))
			->from($db->quoteName('#__fields_values', 'fv'))
			->where($db->quoteName('fv.field_id') . ' = ' . $fieldId)
			->whereIn($db->quoteName('fv.item_id'), $masterIds)
			->where('TRIM(' . $db->quoteName('fv.value') . ') <> ' . $db->quote(''))
			->order($db->quoteName('value') . ' ASC');
		$db->setQuery($query);

		try {
			$rows = $db->loadColumn() ?: [];
		} catch (\Throwable $e) {
			return [];
		}

		return is_array($rows) ? array_values(array_unique(array_map('strval', $rows))) : [];
	}

	public static function getCities(): array
	{
		return self::getDistinctFieldValues('sity');
	}

	public static function getAreas(): array
	{
		return self::getDistinctFieldValues('area');
	}

	public static function getHomeOptions(): array
	{
		$masterIds = self::getCourseMasterIds();
		if ($masterIds === []) {
			return [];
		}

		$fieldMap = self::getUserFieldMap();
		$fieldId = (int) ($fieldMap['home'] ?? 0);
		if ($fieldId <= 0) {
			return [];
		}

		$db = self::getDatabase();
		$query = $db->getQuery(true)
			->select($db->quoteName('fv.value'))
			->from($db->quoteName('#__fields_values', 'fv'))
			->where($db->quoteName('fv.field_id') . ' = ' . $fieldId)
			->whereIn($db->quoteName('fv.item_id'), $masterIds);
		$db->setQuery($query);

		try {
			$rows = $db->loadColumn() ?: [];
		} catch (\Throwable $e) {
			return [];
		}

		$labels = [1 => 'Салон', 2 => 'Вызов на дом', 3 => 'Мастер на дому'];
		$available = [];

		foreach ((array) $rows as $row) {
			if (preg_match_all('/"([123])"/', (string) $row, $matches)) {
				foreach ($matches[1] as $value) {
					$id = (int) $value;
					if (isset($labels[$id])) {
						$available[$id] = $labels[$id];
					}
				}
			}
		}

		ksort($available);

		return $available;
	}
}
