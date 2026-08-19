<?php

namespace Viglin\Component\Pushnotify\Administrator\Model;

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\MVC\Model\BaseDatabaseModel;
use Viglin\Component\Pushnotify\Site\Helper\NotificationSettingsHelper;

class SettingsModel extends BaseDatabaseModel
{
	private function ensureSettingsHelperLoaded(): void
	{
		if (class_exists(NotificationSettingsHelper::class)) {
			return;
		}
		$path = JPATH_SITE . '/components/com_pushnotify/src/Helper/NotificationSettingsHelper.php';
		if (is_file($path)) {
			require_once $path;
		}
	}

	public function getNotificationSettings(): array
	{
		$this->ensureSettingsHelperLoaded();
		return NotificationSettingsHelper::get('notifications');
	}

	public function getEmailVerificationSettings(): array
	{
		$this->ensureSettingsHelperLoaded();
		return NotificationSettingsHelper::get('email_verification');
	}

	public function saveFromInput(array $data): bool
	{
		$this->ensureSettingsHelperLoaded();
		$notifications = NotificationSettingsHelper::defaultNotifications();
		$currentNotifications = NotificationSettingsHelper::get('notifications');
		$notificationInput = $data['notifications'] ?? [];
		if (!is_array($notificationInput)) {
			$notificationInput = [];
		}

		$notifications = $this->mergeSettings($notifications, $currentNotifications);
		$notifications['global'] = [
			'enabled' => !empty($notificationInput['global']['enabled']),
			'fcm_enabled' => !empty($notificationInput['global']['fcm_enabled']),
			'inbox_enabled' => !empty($notificationInput['global']['inbox_enabled']),
			'logging_enabled' => !empty($notificationInput['global']['logging_enabled']),
			'fcm_retry_attempts' => max(1, min(10, (int) ($notificationInput['global']['fcm_retry_attempts'] ?? 2))),
			'fcm_retry_delay_ms' => max(0, min(10000, (int) ($notificationInput['global']['fcm_retry_delay_ms'] ?? 300))),
		];

		foreach ($notifications['events'] as $event => $defaults) {
			$input = $notificationInput['events'][$event] ?? [];
			if (!is_array($input)) {
				$input = [];
			}
			$notifications['events'][$event] = [
				'enabled' => !empty($input['enabled']),
				'recipients' => [
					'client' => !empty($input['recipients']['client']),
					'master' => !empty($input['recipients']['master']),
				],
				'fcm' => !empty($input['fcm']),
				'inbox' => !empty($input['inbox']),
				'title' => trim((string) ($input['title'] ?? ($defaults['title'] ?? ''))),
				'body' => trim((string) ($input['body'] ?? ($defaults['body'] ?? ''))),
			];
		}

		$notifications['booking_kinds'] = [
			'service' => !empty($notificationInput['booking_kinds']['service']),
			'stock' => !empty($notificationInput['booking_kinds']['stock']),
			'course' => !empty($notificationInput['booking_kinds']['course']),
			'journal' => !empty($notificationInput['booking_kinds']['journal']),
		];

		$reminders = [];
		$reminderInput = $notificationInput['reminders'] ?? [];
		if (is_array($reminderInput)) {
			foreach ($reminderInput as $item) {
				if (!is_array($item)) {
					continue;
				}
				$minutes = max(0, (int) ($item['minutes'] ?? 0));
				$reminders[] = [
					'minutes' => $minutes,
					'enabled' => !empty($item['enabled']),
					'label' => trim((string) ($item['label'] ?? '')),
				];
			}
		}
		$extraMinutes = trim((string) ($notificationInput['extra_reminder_minutes'] ?? ''));
		if ($extraMinutes !== '') {
			foreach (preg_split('/[,\s]+/', $extraMinutes) as $part) {
				$minutes = (int) $part;
				if ($minutes > 0) {
					$reminders[] = ['minutes' => $minutes, 'enabled' => true, 'label' => 'За ' . $minutes . ' мин.'];
				}
			}
		}
		$unique = [];
		foreach ($reminders as $item) {
			$unique[(string) $item['minutes']] = $item;
		}
		ksort($unique, SORT_NUMERIC);
		$notifications['reminders'] = array_values($unique);

		$emailInput = $data['email_verification'] ?? [];
		if (!is_array($emailInput)) {
			$emailInput = [];
		}
		$email = [
			'activation_grace_minutes' => max(1, (int) ($emailInput['activation_grace_minutes'] ?? 4320)),
			'token_ttl_days' => max(1, (int) ($emailInput['token_ttl_days'] ?? 30)),
			'expiration_block_enabled' => !empty($emailInput['expiration_block_enabled']),
			'resend_enabled' => !empty($emailInput['resend_enabled']),
			'resend_cooldown_seconds' => max(1, (int) ($emailInput['resend_cooldown_seconds'] ?? 120)),
		];

		return NotificationSettingsHelper::save('notifications', $notifications)
			&& NotificationSettingsHelper::save('email_verification', $email);
	}

	public function getStats(): array
	{
		$db = Factory::getContainer()->get(\Joomla\Database\DatabaseInterface::class);
		$stats = ['tokens' => 0, 'subscribers' => 0, 'inbox' => 0, 'logs' => 0];
		foreach ([
			'tokens' => '#__pushnotify_subscriptions',
			'inbox' => '#__pushnotify_inbox',
			'logs' => '#__pushnotify_logs',
		] as $key => $table) {
			try {
				$db->setQuery($db->getQuery(true)->select('COUNT(*)')->from($db->quoteName($table)));
				$stats[$key] = (int) $db->loadResult();
			} catch (\Throwable $e) {
				$stats[$key] = 0;
			}
		}
		try {
			$db->setQuery(
				$db->getQuery(true)
					->select('COUNT(DISTINCT ' . $db->quoteName('user_id') . ')')
					->from($db->quoteName('#__pushnotify_subscriptions'))
			);
			$stats['subscribers'] = (int) $db->loadResult();
		} catch (\Throwable $e) {
			$stats['subscribers'] = 0;
		}
		return $stats;
	}

	private function mergeSettings(array $defaults, array $settings): array
	{
		foreach ($defaults as $key => $value) {
			if (!array_key_exists($key, $settings)) {
				$settings[$key] = $value;
				continue;
			}
			if (is_array($value) && is_array($settings[$key]) && array_keys($value) !== range(0, count($value) - 1)) {
				$settings[$key] = $this->mergeSettings($value, $settings[$key]);
			}
		}
		return $settings;
	}
}
