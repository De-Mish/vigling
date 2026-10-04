<?php

namespace Viglin\Component\Pushnotify\Site\Helper;

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\Database\DatabaseInterface;

class NotificationSettingsHelper
{
	private static array $cache = [];

	public static function get(string $key = 'notifications'): array
	{
		if (isset(self::$cache[$key])) {
			return self::$cache[$key];
		}

		$defaults = self::defaults($key);
		$value = [];

		try {
			$db = self::db();
			if (!self::tableExists($db)) {
				return self::$cache[$key] = $defaults;
			}

			$query = $db->getQuery(true)
				->select($db->quoteName('setting_value'))
				->from($db->quoteName('#__vigling_app_settings'))
				->where($db->quoteName('setting_key') . ' = ' . $db->quote($key))
				->setLimit(1);
			$db->setQuery($query);
			$raw = (string) ($db->loadResult() ?? '');
			$decoded = $raw !== '' ? json_decode($raw, true) : null;
			if (is_array($decoded)) {
				$value = $decoded;
			}
		} catch (\Throwable $e) {
			return self::$cache[$key] = $defaults;
		}

		return self::$cache[$key] = self::mergeDefaults($defaults, $value);
	}

	public static function save(string $key, array $value): bool
	{
		$defaults = self::defaults($key);
		$value = self::mergeDefaults($defaults, $value);

		try {
			$db = self::db();
			self::ensureTable($db);
			$now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');
			$json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
			$query = $db->getQuery(true)
				->insert($db->quoteName('#__vigling_app_settings'))
				->columns($db->quoteName(['setting_key', 'setting_value', 'created_at', 'updated_at']))
				->values($db->quote($key) . ', ' . $db->quote($json) . ', ' . $db->quote($now) . ', ' . $db->quote($now));
			$sql = (string) $query . ' ON DUPLICATE KEY UPDATE '
				. $db->quoteName('setting_value') . ' = VALUES(' . $db->quoteName('setting_value') . '), '
				. $db->quoteName('updated_at') . ' = VALUES(' . $db->quoteName('updated_at') . ')';
			$db->setQuery($sql)->execute();
			self::$cache[$key] = $value;
			return true;
		} catch (\Throwable $e) {
			return false;
		}
	}

	public static function isNotificationsEnabled(): bool
	{
		return self::boolPath(self::get('notifications'), ['global', 'enabled'], true);
	}

	public static function isFcmEnabled(?string $event = null, ?string $bookingKind = null): bool
	{
		$settings = self::get('notifications');
		if (!self::isNotificationsEnabled()) {
			return false;
		}
		if (!self::boolPath($settings, ['global', 'fcm_enabled'], true)) {
			return false;
		}
		if ($event !== null && !self::boolPath($settings, ['events', $event, 'fcm'], true)) {
			return false;
		}
		return $bookingKind === null || self::isBookingKindEnabled($bookingKind);
	}

	public static function isInboxEnabled(?string $event = null, ?string $bookingKind = null): bool
	{
		$settings = self::get('notifications');
		if (!self::isNotificationsEnabled()) {
			return false;
		}
		if (!self::boolPath($settings, ['global', 'inbox_enabled'], true)) {
			return false;
		}
		if ($event !== null && !self::boolPath($settings, ['events', $event, 'inbox'], true)) {
			return false;
		}
		return $bookingKind === null || self::isBookingKindEnabled($bookingKind);
	}

	public static function isEventEnabled(string $event, ?string $bookingKind = null): bool
	{
		$settings = self::get('notifications');
		if (!self::isNotificationsEnabled()) {
			return false;
		}
		if (!self::boolPath($settings, ['events', $event, 'enabled'], true)) {
			return false;
		}
		return $bookingKind === null || self::isBookingKindEnabled($bookingKind);
	}

	public static function isRecipientEnabled(string $event, string $recipient): bool
	{
		$recipient = in_array($recipient, ['client', 'master'], true) ? $recipient : 'client';
		return self::boolPath(self::get('notifications'), ['events', $event, 'recipients', $recipient], true);
	}

	public static function isBookingKindEnabled(string $bookingKind): bool
	{
		$bookingKind = in_array($bookingKind, ['service', 'stock', 'course', 'search', 'journal'], true) ? $bookingKind : 'service';
		return self::boolPath(self::get('notifications'), ['booking_kinds', $bookingKind], true);
	}

	public static function getReminderOffsets(): array
	{
		$items = self::get('notifications')['reminders'] ?? [];
		$result = [];
		foreach ($items as $item) {
			if (empty($item['enabled'])) {
				continue;
			}
			$minutes = (int) ($item['minutes'] ?? 0);
			if ($minutes < 0) {
				continue;
			}
			$result[] = [
				'minutes' => $minutes,
				'event' => $minutes === 0 ? 'booking_started' : 'booking_reminder',
				'reminder_type' => self::reminderType($minutes),
				'label' => trim((string) ($item['label'] ?? '')),
			];
		}
		return $result;
	}

	public static function getEmailActivationGraceMinutes(): int
	{
		$value = (int) (self::get('email_verification')['activation_grace_minutes'] ?? 4320);
		return max(1, $value);
	}

	public static function getEmailTokenTtlDays(): int
	{
		$value = (int) (self::get('email_verification')['token_ttl_days'] ?? 30);
		return max(1, $value);
	}

	public static function isEmailExpirationBlockEnabled(): bool
	{
		return self::boolPath(self::get('email_verification'), ['expiration_block_enabled'], true);
	}

	public static function isEmailResendEnabled(): bool
	{
		return self::boolPath(self::get('email_verification'), ['resend_enabled'], true);
	}

	public static function getEmailResendCooldownSeconds(): int
	{
		$value = (int) (self::get('email_verification')['resend_cooldown_seconds'] ?? 120);
		return max(1, $value);
	}

	public static function defaultNotifications(): array
	{
		$eventDefaults = [
			'enabled' => true,
			'recipients' => ['client' => true, 'master' => true],
			'fcm' => true,
			'inbox' => true,
			'title' => '',
			'body' => '',
		];
		$events = [];
		foreach ([
			'booking_confirmed' => 'Запись подтверждена',
			'booking_cancelled' => 'Запись отменена',
			'booking_rescheduled' => 'Запись перенесена',
			'booking_reminder' => 'Напоминание о записи',
			'booking_in_30min' => 'Через 30 минут приём',
			'booking_started' => 'Приём начался',
			'course_created' => 'Курс создан/изменён',
			'course_cancelled' => 'Курс отменён',
			'course_rescheduled' => 'Курс перенесён',
			'booking_finished' => 'Окончание записи/курса',
		] as $key => $title) {
			$events[$key] = array_merge($eventDefaults, ['title' => $title]);
		}

		return [
			'global' => [
				'enabled' => true,
				'fcm_enabled' => true,
				'inbox_enabled' => true,
				'logging_enabled' => true,
				'fcm_retry_attempts' => 2,
				'fcm_retry_delay_ms' => 300,
			],
			'events' => $events,
			'reminders' => [
				['minutes' => 30, 'enabled' => true, 'label' => 'За 30 минут'],
				['minutes' => 1440, 'enabled' => false, 'label' => 'За 1 день'],
				['minutes' => 0, 'enabled' => true, 'label' => 'В момент начала'],
			],
			'booking_kinds' => [
				'service' => true,
				'stock' => true,
				'course' => true,
				'search' => true,
				'journal' => true,
			],
		];
	}

	public static function defaultEmailVerification(): array
	{
		return [
			'activation_grace_minutes' => 4320,
			'token_ttl_days' => 30,
			'expiration_block_enabled' => true,
			'resend_enabled' => true,
			'resend_cooldown_seconds' => 120,
		];
	}

	public static function reminderType(int $minutes): string
	{
		if ($minutes === 0) {
			return 'started';
		}
		if ($minutes === 30) {
			return 'in_30min';
		}
		return 'before_' . $minutes . 'm';
	}

	private static function defaults(string $key): array
	{
		if ($key === 'email_verification') {
			return self::defaultEmailVerification();
		}
		return self::defaultNotifications();
	}

	private static function mergeDefaults(array $defaults, array $value): array
	{
		foreach ($defaults as $key => $defaultValue) {
			if (!array_key_exists($key, $value)) {
				$value[$key] = $defaultValue;
				continue;
			}
			if (is_array($defaultValue) && is_array($value[$key]) && !self::isList($defaultValue)) {
				$value[$key] = self::mergeDefaults($defaultValue, $value[$key]);
			}
		}
		return $value;
	}

	private static function isList(array $value): bool
	{
		return array_keys($value) === range(0, count($value) - 1);
	}

	private static function boolPath(array $settings, array $path, bool $default): bool
	{
		$value = $settings;
		foreach ($path as $part) {
			if (!is_array($value) || !array_key_exists($part, $value)) {
				return $default;
			}
			$value = $value[$part];
		}
		return (bool) $value;
	}

	private static function db(): DatabaseInterface
	{
		return Factory::getContainer()->get(DatabaseInterface::class);
	}

	private static function tableExists(DatabaseInterface $db): bool
	{
		$table = $db->replacePrefix('#__vigling_app_settings');
		$db->setQuery('SHOW TABLES LIKE ' . $db->quote($table));
		return (bool) $db->loadResult();
	}

	private static function ensureTable(DatabaseInterface $db): void
	{
		$db->setQuery(
			'CREATE TABLE IF NOT EXISTS ' . $db->quoteName('#__vigling_app_settings') . " (
				`id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				`setting_key` VARCHAR(128) NOT NULL,
				`setting_value` JSON NOT NULL,
				`created_at` DATETIME NOT NULL,
				`updated_at` DATETIME NOT NULL,
				PRIMARY KEY (`id`),
				UNIQUE KEY `uniq_setting_key` (`setting_key`)
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
		)->execute();
	}
}
