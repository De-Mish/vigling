<?php

namespace Viglin\Component\Pushnotify\Site\Helper;

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\Database\DatabaseInterface;

/**
 * Per-account choices for which booking notices to receive.
 * Site-wide switches in the administrator still apply first.
 */
class UserNotifyChoices
{
	public const EVENTS = [
		'booking_confirmed' => 'Запись',
		'booking_cancelled' => 'Отмена',
		'booking_rescheduled' => 'Перенос',
	];

	public const KINDS = [
		'service' => 'Записи',
		'course' => 'Курсы',
		'stock' => 'Акции',
	];

	public const REMINDERS = [
		30 => 'За 30 минут',
		1440 => 'За 1 день',
	];

	/** @var array<int, array<string, mixed>> */
	private static array $cache = [];

	private static ?bool $tableReady = null;

	public static function defaults(): array
	{
		return [
			'events' => [
				'booking_confirmed' => true,
				'booking_cancelled' => true,
				'booking_rescheduled' => true,
			],
			'kinds' => [
				'service' => true,
				'course' => true,
				'stock' => true,
			],
			'reminders' => [
				'30' => true,
				'1440' => false,
			],
		];
	}

	public static function get(int $userId): array
	{
		if ($userId <= 0) {
			return self::defaults();
		}
		if (isset(self::$cache[$userId])) {
			return self::$cache[$userId];
		}

		$prefs = self::defaults();
		try {
			$db = self::db();
			if (!self::tableReady($db)) {
				return self::$cache[$userId] = $prefs;
			}
			$db->setQuery(
				$db->getQuery(true)
					->select($db->quoteName('choices'))
					->from($db->quoteName('#__pushnotify_preferences'))
					->where($db->quoteName('user_id') . ' = ' . $userId)
			);
			$raw = $db->loadResult();
			$decoded = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
			if (is_array($decoded)) {
				$prefs = self::normalize($decoded);
			}
		} catch (\Throwable $e) {
			$prefs = self::defaults();
		}

		return self::$cache[$userId] = $prefs;
	}

	public static function save(int $userId, array $input): bool
	{
		if ($userId <= 0) {
			return false;
		}
		$prefs = self::normalize($input);
		try {
			$db = self::db();
			if (!self::tableReady($db)) {
				return false;
			}
			$json = json_encode($prefs, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
			if (!is_string($json)) {
				return false;
			}
			$db->setQuery(
				$db->getQuery(true)
					->select($db->quoteName('user_id'))
					->from($db->quoteName('#__pushnotify_preferences'))
					->where($db->quoteName('user_id') . ' = ' . $userId)
			);
			if ($db->loadResult()) {
				$db->setQuery(
					$db->getQuery(true)
						->update($db->quoteName('#__pushnotify_preferences'))
						->set($db->quoteName('choices') . ' = ' . $db->quote($json))
						->where($db->quoteName('user_id') . ' = ' . $userId)
				)->execute();
			} else {
				$row = new \stdClass();
				$row->user_id = $userId;
				$row->notifications_enabled = 1;
				$row->choices = $json;
				$db->insertObject('#__pushnotify_preferences', $row);
			}
			self::$cache[$userId] = $prefs;
			return true;
		} catch (\Throwable $e) {
			return false;
		}
	}

	public static function allows(int $userId, string $event, string $bookingKind, ?int $reminderMinutes = null): bool
	{
		return self::allowsPrefs(self::get($userId), $event, $bookingKind, $reminderMinutes);
	}

	public static function allowsPrefs(array $prefs, string $event, string $bookingKind, ?int $reminderMinutes = null): bool
	{
		$prefs = self::normalize($prefs);
		if (isset(self::KINDS[$bookingKind]) && empty($prefs['kinds'][$bookingKind])) {
			return false;
		}
		if ($reminderMinutes !== null && isset(self::REMINDERS[$reminderMinutes])) {
			return !empty($prefs['reminders'][(string) $reminderMinutes]);
		}
		if (isset(self::EVENTS[$event])) {
			return !empty($prefs['events'][$event]);
		}

		return true;
	}

	public static function normalize(array $input): array
	{
		$defaults = self::defaults();
		$events = is_array($input['events'] ?? null) ? $input['events'] : [];
		$kinds = is_array($input['kinds'] ?? null) ? $input['kinds'] : [];
		$reminders = is_array($input['reminders'] ?? null) ? $input['reminders'] : [];

		foreach ($defaults['events'] as $key => $default) {
			$defaults['events'][$key] = self::flag($events, $key, $default);
		}
		foreach ($defaults['kinds'] as $key => $default) {
			$defaults['kinds'][$key] = self::flag($kinds, $key, $default);
		}
		foreach ($defaults['reminders'] as $key => $default) {
			$defaults['reminders'][$key] = self::flag($reminders, $key, $default);
		}

		return $defaults;
	}

	private static function flag(array $source, string $key, bool $default): bool
	{
		if (!array_key_exists($key, $source)) {
			return $default;
		}
		$value = $source[$key];
		if (is_bool($value)) {
			return $value;
		}
		if (is_int($value) || is_float($value)) {
			return (int) $value === 1;
		}
		$normalized = strtolower(trim((string) $value));

		return in_array($normalized, ['1', 'true', 'yes', 'on'], true);
	}

	private static function db(): DatabaseInterface
	{
		return Factory::getContainer()->get(DatabaseInterface::class);
	}

	private static function tableReady(DatabaseInterface $db): bool
	{
		if (self::$tableReady !== null) {
			return self::$tableReady;
		}
		$columns = array_change_key_case($db->getTableColumns('#__pushnotify_preferences', false), CASE_LOWER);
		if ($columns === []) {
			return self::$tableReady = false;
		}
		if (isset($columns['choices'])) {
			return self::$tableReady = true;
		}
		$db->setQuery(
			'ALTER TABLE ' . $db->quoteName('#__pushnotify_preferences')
			. ' ADD ' . $db->quoteName('choices') . ' JSON NULL'
		)->execute();
		$columns = array_change_key_case($db->getTableColumns('#__pushnotify_preferences', false), CASE_LOWER);

		return self::$tableReady = isset($columns['choices']);
	}
}
