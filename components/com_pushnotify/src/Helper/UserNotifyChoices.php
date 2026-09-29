<?php

namespace Viglin\Component\Pushnotify\Site\Helper;

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\Database\DatabaseInterface;

/**
 * Per-account push and bell choices.
 * Site-wide switches still apply first.
 */
class UserNotifyChoices
{
	public const KINDS = [
		'service' => 'Услуги',
		'stock' => 'Акции',
		'course' => 'Курсы',
		'search' => 'Модели',
	];

	public const EVENTS = [
		'confirmed' => 'Запись',
		'rescheduled' => 'Перенос',
		'cancelled' => 'Отмена',
	];

	public const EVENT_TYPES = [
		'booking_rescheduled' => 'rescheduled',
		'booking_cancelled' => 'cancelled',
		'booking_confirmed' => 'confirmed',
	];

	public const REMINDERS = [
		30 => '30 мин',
		60 => '60 мин',
		720 => '12 часов',
		1440 => '24 часа',
	];

	/** @var array<int, array<string, mixed>> */
	private static array $cache = [];

	private static ?bool $tableReady = null;

	public static function defaults(): array
	{
		$events = [
			'rescheduled' => true,
			'cancelled' => true,
			'confirmed' => true,
		];
		$push = [];
		$inbox = [];
		foreach (array_keys(self::KINDS) as $kind) {
			$push[$kind] = $events;
			$push[$kind]['remind'] = null;
			$inbox[$kind] = $events;
		}

		return [
			'push' => $push,
			'inbox' => $inbox,
		];
	}

	public static function isGridKind(string $bookingKind): bool
	{
		return isset(self::KINDS[$bookingKind]);
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

	public static function allowsChannel(int $userId, string $channel, string $event, string $bookingKind, ?int $reminderMinutes = null): bool
	{
		return self::allowsPrefs(self::get($userId), $channel, $event, $bookingKind, $reminderMinutes);
	}

	public static function allowsPrefs(array $prefs, string $channel, string $event, string $bookingKind, ?int $reminderMinutes = null): bool
	{
		if (!self::isGridKind($bookingKind)) {
			return true;
		}
		$prefs = self::normalize($prefs);
		$channel = $channel === 'inbox' ? 'inbox' : 'push';
		if ($event === 'booking_in_30min') {
			$reminderMinutes = 30;
		}
		if ($event === 'booking_reminder' || $event === 'booking_in_30min') {
			if ($channel !== 'push') {
				return false;
			}
			$chosen = $prefs['push'][$bookingKind]['remind'] ?? null;

			return $chosen !== null && $reminderMinutes !== null && (int) $chosen === (int) $reminderMinutes;
		}
		$eventKey = self::EVENT_TYPES[$event] ?? null;
		if ($eventKey === null) {
			return true;
		}

		return !empty($prefs[$channel][$bookingKind][$eventKey]);
	}

	public static function normalize(array $input): array
	{
		$defaults = self::defaults();
		if (!isset($input['push']) && !isset($input['inbox'])) {
			return $defaults;
		}
		$push = is_array($input['push'] ?? null) ? $input['push'] : [];
		$inbox = is_array($input['inbox'] ?? null) ? $input['inbox'] : [];
		foreach (array_keys(self::KINDS) as $kind) {
			$pushRow = is_array($push[$kind] ?? null) ? $push[$kind] : [];
			$inboxRow = is_array($inbox[$kind] ?? null) ? $inbox[$kind] : [];
			foreach (array_keys(self::EVENTS) as $eventKey) {
				$defaults['push'][$kind][$eventKey] = self::flag($pushRow, $eventKey, true);
				$defaults['inbox'][$kind][$eventKey] = self::flag($inboxRow, $eventKey, true);
			}
			$defaults['push'][$kind]['remind'] = self::remindValue($pushRow['remind'] ?? null);
		}

		return $defaults;
	}

	private static function remindValue($value): ?int
	{
		if ($value === null || $value === '' || $value === '-') {
			return null;
		}
		$minutes = (int) $value;

		return isset(self::REMINDERS[$minutes]) ? $minutes : null;
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
