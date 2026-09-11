<?php

namespace Joomla\Plugin\User\Vigling\Helper;

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\Database\DatabaseInterface;

final class UserProfileExtraFieldsHelper
{
	public const HOME_LABELS = [
		1 => 'Салон',
		2 => 'Вызов на дом',
		3 => 'Мастер на дому',
	];

	public const PAYMENT_LABELS = [
		'card' => 'Банковская карта',
		'cash' => 'Наличные',
		'transfer' => 'Банковский перевод',
	];

	public const TEXT_FIELDS = ['doorway', 'floor', 'apartment'];

	/**
	 * @return array<string,string>
	 */
	public static function fieldTitles(): array
	{
		return [
			'doorway' => 'Подъезд',
			'floor' => 'Этаж',
			'apartment' => 'Квартира',
			'home' => 'Форма работы',
			'payment_method' => 'Способ оплаты',
			'suitable_for_children' => 'Подходит для детей',
		];
	}

	public static function loadClass(string $shortName): bool
	{
		$class = __NAMESPACE__ . '\\' . $shortName;
		if (class_exists($class, false)) {
			return true;
		}
		if (!preg_match('/^[A-Za-z][A-Za-z0-9_]*$/', $shortName)) {
			return false;
		}
		$path = __DIR__ . '/' . $shortName . '.php';
		if (!is_file($path)) {
			return false;
		}
		require_once $path;

		return class_exists($class, false);
	}

	public static function decodeText(string $raw): string
	{
		$raw = trim($raw);
		if ($raw === '') {
			return '';
		}
		$decoded = json_decode($raw, true);
		if (is_string($decoded)) {
			return trim($decoded);
		}

		return $raw;
	}

	public static function parseHomeIds(string $raw): array
	{
		$ids = [];
		$decoded = json_decode(trim($raw), true);
		if (is_array($decoded)) {
			foreach ($decoded as $value) {
				$id = (int) $value;
				if (isset(self::HOME_LABELS[$id])) {
					$ids[] = $id;
				}
			}
		} elseif (preg_match_all('/[123]/', $raw, $matches)) {
			foreach ($matches[0] as $digit) {
				$id = (int) $digit;
				if (isset(self::HOME_LABELS[$id])) {
					$ids[] = $id;
				}
			}
		}

		return array_values(array_unique($ids));
	}

	public static function parsePaymentKeys(string $raw): array
	{
		$keys = [];
		$decoded = json_decode(trim($raw), true);
		$values = is_array($decoded) ? $decoded : (preg_split('/[,\s]+/', $raw) ?: []);
		foreach ($values as $value) {
			$key = strtolower(trim((string) $value));
			if (isset(self::PAYMENT_LABELS[$key])) {
				$keys[] = $key;
			}
		}

		return array_values(array_unique($keys));
	}

	public static function homeDisplay(string $raw): string
	{
		$parts = [];
		foreach (self::parseHomeIds($raw) as $id) {
			$parts[] = self::HOME_LABELS[$id];
		}

		return implode(', ', $parts);
	}

	public static function paymentDisplay(string $raw): string
	{
		$parts = [];
		foreach (self::parsePaymentKeys($raw) as $key) {
			$parts[] = self::PAYMENT_LABELS[$key];
		}

		return implode(', ', $parts);
	}

	public static function extraAddressLine(string $doorway, string $floor, string $apartment): string
	{
		$parts = [];
		if (trim($doorway) !== '') {
			$parts[] = 'подъезд ' . trim($doorway);
		}
		if (trim($floor) !== '') {
			$parts[] = 'этаж ' . trim($floor);
		}
		if (trim($apartment) !== '') {
			$parts[] = 'кв. ' . trim($apartment);
		}

		return implode(', ', $parts);
	}

	public static function isChildrenYes(string $raw): bool
	{
		$raw = strtolower(trim($raw));
		if ($raw === '') {
			return false;
		}
		$decoded = json_decode($raw, true);
		if (is_array($decoded)) {
			$raw = strtolower(trim((string) reset($decoded)));
		}

		return in_array($raw, ['1', 'yes', 'true', 'on', 'да'], true);
	}

	public static function clientHasBookingWithMaster(int $clientId, int $masterId): bool
	{
		if ($masterId <= 0) {
			return false;
		}
		if ($clientId > 0 && $clientId === $masterId) {
			return true;
		}
		if ($clientId <= 0) {
			return false;
		}

		try {
			$db = Factory::getContainer()->get(DatabaseInterface::class);
			$query = $db->getQuery(true)
				->select($db->quoteName('id'))
				->from($db->quoteName('#__vigling_bookings'))
				->where($db->quoteName('user_id') . ' = ' . $clientId)
				->where($db->quoteName('master_id') . ' = ' . $masterId)
				->setLimit(1);
			$db->setQuery($query);

			return (int) $db->loadResult() > 0;
		} catch (\Throwable $e) {
			return false;
		}
	}

	public static function saveFromPost(int $userId): void
	{
		if ($userId <= 0) {
			return;
		}

		$comFields = isset($_POST['jform']['com_fields']) && is_array($_POST['jform']['com_fields'])
			? $_POST['jform']['com_fields']
			: [];
		$jform = isset($_POST['jform']) && is_array($_POST['jform']) ? $_POST['jform'] : [];

		$forceAll = array_key_exists('vigling_profile_extra', $jform);
		$posted = $forceAll;
		foreach (array_merge(self::TEXT_FIELDS, ['home', 'payment_method', 'suitable_for_children']) as $name) {
			if (array_key_exists($name, $comFields) || array_key_exists($name, $jform)) {
				$posted = true;
				break;
			}
		}
		if (!$posted) {
			return;
		}

		$toSave = [];
		foreach (self::TEXT_FIELDS as $name) {
			$raw = $comFields[$name] ?? $jform[$name] ?? null;
			if ($raw === null && !$forceAll) {
				continue;
			}
			$value = is_scalar($raw) ? trim((string) $raw) : '';
			if (mb_strlen($value) > 80) {
				$value = mb_substr($value, 0, 80);
			}
			$toSave[$name] = $value;
		}

		if ($forceAll || array_key_exists('home', $comFields) || array_key_exists('home', $jform)) {
			$raw = $comFields['home'] ?? $jform['home'] ?? [];
			$ids = [];
			if (is_array($raw)) {
				foreach ($raw as $value) {
					$id = (int) $value;
					if (isset(self::HOME_LABELS[$id])) {
						$ids[] = $id;
					}
				}
			} elseif (is_scalar($raw)) {
				$ids = self::parseHomeIds((string) $raw);
			}
			$ids = array_values(array_unique($ids));
			sort($ids);
			$toSave['home'] = $ids === [] ? '' : json_encode(array_map('strval', $ids));
		}

		if ($forceAll || array_key_exists('payment_method', $comFields) || array_key_exists('payment_method', $jform)) {
			$raw = $comFields['payment_method'] ?? $jform['payment_method'] ?? [];
			$keys = [];
			if (is_array($raw)) {
				foreach ($raw as $value) {
					$key = strtolower(trim((string) $value));
					if (isset(self::PAYMENT_LABELS[$key])) {
						$keys[] = $key;
					}
				}
			} elseif (is_scalar($raw)) {
				$keys = self::parsePaymentKeys((string) $raw);
			}
			$keys = array_values(array_unique($keys));
			$toSave['payment_method'] = $keys === [] ? '' : json_encode($keys);
		}

		if ($forceAll || array_key_exists('suitable_for_children', $comFields) || array_key_exists('suitable_for_children', $jform)) {
			$raw = $comFields['suitable_for_children'] ?? $jform['suitable_for_children'] ?? '';
			if (is_array($raw)) {
				$raw = reset($raw);
			}
			$toSave['suitable_for_children'] = self::isChildrenYes((string) $raw) ? '1' : '';
		}

		if ($toSave === []) {
			return;
		}

		try {
			$db = Factory::getContainer()->get(DatabaseInterface::class);
			self::ensureFieldsExist($db);
			self::writeFieldValues($db, $userId, $toSave);
		} catch (\Throwable $e) {
		}
	}

	private static function ensureFieldsExist(DatabaseInterface $db): void
	{
		$titles = self::fieldTitles();
		$names = array_keys($titles);
		$query = $db->getQuery(true)
			->select([$db->quoteName('id'), $db->quoteName('name')])
			->from($db->quoteName('#__fields'))
			->where($db->quoteName('context') . ' = ' . $db->quote('com_users.user'))
			->where($db->quoteName('name') . ' IN (' . implode(',', array_map([$db, 'quote'], $names)) . ')');
		$db->setQuery($query);
		$existing = [];
		foreach ($db->loadObjectList() ?: [] as $row) {
			$existing[(string) $row->name] = (int) $row->id;
		}

		$now = Factory::getDate()->toSql();
		foreach ($titles as $name => $title) {
			if (isset($existing[$name])) {
				continue;
			}
			$object = (object) [
				'context' => 'com_users.user',
				'group_id' => 0,
				'title' => $title,
				'name' => $name,
				'label' => $title,
				'type' => 'text',
				'default_value' => '',
				'state' => 1,
				'required' => 0,
				'created_time' => $now,
				'modified_time' => $now,
				'language' => '*',
				'access' => 1,
				'ordering' => 50,
				'params' => '{}',
				'fieldparams' => '{}',
				'description' => '',
				'note' => '',
			];
			try {
				$db->insertObject('#__fields', $object);
			} catch (\Throwable $e) {
			}
		}
	}

	/**
	 * @param array<string,string> $toSave
	 */
	private static function writeFieldValues(DatabaseInterface $db, int $userId, array $toSave): void
	{
		$names = array_keys($toSave);
		$query = $db->getQuery(true)
			->select([$db->quoteName('id'), $db->quoteName('name')])
			->from($db->quoteName('#__fields'))
			->where($db->quoteName('context') . ' = ' . $db->quote('com_users.user'))
			->where($db->quoteName('name') . ' IN (' . implode(',', array_map([$db, 'quote'], $names)) . ')');
		$db->setQuery($query);
		$rows = $db->loadObjectList('name') ?: [];

		foreach ($toSave as $name => $value) {
			if (!isset($rows[$name])) {
				continue;
			}
			$fieldId = (int) $rows[$name]->id;
			$db->setQuery(
				$db->getQuery(true)
					->delete($db->quoteName('#__fields_values'))
					->where($db->quoteName('field_id') . ' = ' . $fieldId)
					->where($db->quoteName('item_id') . ' = ' . $db->quote((string) $userId))
			)->execute();

			if ($value === '') {
				continue;
			}

			$db->setQuery(
				$db->getQuery(true)
					->insert($db->quoteName('#__fields_values'))
					->columns([$db->quoteName('field_id'), $db->quoteName('item_id'), $db->quoteName('value')])
					->values($fieldId . ', ' . $db->quote((string) $userId) . ', ' . $db->quote($value))
			)->execute();
		}
	}

	/**
	 * @param mixed $raw
	 * @return list<string>
	 */
	public static function sanitizePaymentKeys($raw): array
	{
		$keys = [];
		foreach ((array) $raw as $value) {
			$key = strtolower(trim((string) $value));
			if (isset(self::PAYMENT_LABELS[$key])) {
				$keys[] = $key;
			}
		}

		return array_values(array_unique($keys));
	}

	public static function childrenFilterOn($raw): bool
	{
		if (is_array($raw)) {
			$raw = reset($raw);
		}

		return self::isChildrenYes((string) $raw);
	}

	/**
	 * @param list<string|int> $needles
	 * @return list<string>
	 */
	public static function jsonContainsConds($db, string $columnSql, array $needles): array
	{
		$conds = [];
		foreach ($needles as $needle) {
			$needle = trim((string) $needle);
			if ($needle === '' || strpbrk($needle, '%_\\\'"') !== false) {
				continue;
			}
			$conds[] = $columnSql . ' LIKE ' . $db->quote('%"' . $needle . '"%');
		}

		return $conds;
	}

	public static function childrenValueSql($db, string $columnSql): string
	{
		return '(' . $columnSql . ' = ' . $db->quote('1')
			. ' OR ' . $columnSql . ' = ' . $db->quote('"1"')
			. ' OR ' . $columnSql . ' LIKE ' . $db->quote('%"1"%') . ')';
	}
}
