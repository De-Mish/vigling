<?php

namespace Viglin\Plugin\System\Pushnotifybooking\Helper;

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Viglin\Component\Pushnotify\Site\Helper\FcmHelper;
use Viglin\Component\Pushnotify\Site\Helper\InboxHelper;
use Viglin\Component\Pushnotify\Site\Helper\NotificationSettingsHelper;

class BookingNotifyHelper
{
	private static function ensureSettingsHelperLoaded(): void
	{
		if (class_exists(NotificationSettingsHelper::class)) {
			return;
		}
		$path = JPATH_SITE . '/components/com_pushnotify/src/Helper/NotificationSettingsHelper.php';
		if (is_file($path)) {
			require_once $path;
		}
	}

	public static function notifyConfirmedOrRescheduled(array $order)
	{
		self::ensureSettingsHelperLoaded();
		$type = 'booking_confirmed';
		if (!self::shouldSendEvent($order, $type)) {
			return;
		}
		$serviceName = self::resolveOfferingName($order);
		$title = self::resolveTitle($type, 'Запись подтверждена');
		$clientId = (int) ($order['user_id'] ?? 0);
		$masterId = (int) ($order['master_id'] ?? 0);
		$timeStr = self::formatDateTime($order['time'] ?? '', $order['time_to'] ?? '', self::getUserTimezone($clientId));
		$bodyClient = self::buildClientBody($order, $timeStr ? $timeStr : null);
		$bodyMaster = $masterId !== $clientId ? self::buildMasterBody($order, $serviceName, 'К вам записался') : $bodyClient;
		$bodyClient = self::resolveBody($type, $order, 'client', $bodyClient, ['time' => $timeStr]);
		$bodyMaster = self::resolveBody($type, $order, 'master', $bodyMaster, ['time' => self::formatDateTime($order['time'] ?? '', $order['time_to'] ?? '', self::getUserTimezone($masterId))]);
		$data = ['url' => self::getLkUrl()];
		self::sendToClientAndMaster($order, $title, $bodyClient, $type, $data, $bodyMaster);
	}

	public static function notifyCancelled(array $order)
	{
		self::ensureSettingsHelperLoaded();
		$type = 'booking_cancelled';
		if (!self::shouldSendEvent($order, $type)) {
			return;
		}
		$serviceName = self::resolveOfferingName($order);
		$title = self::resolveTitle($type, 'Запись отменена');
		$clientId = (int) ($order['user_id'] ?? 0);
		$masterId = (int) ($order['master_id'] ?? 0);
		$dateClient = self::formatDateTime($order['time'] ?? '', '', self::getUserTimezone($clientId));
		$bodyClient = self::buildClientBody($order, $dateClient ?: null);
		$bodyMaster = $masterId !== $clientId ? self::buildMasterBody($order, $serviceName, 'Отменил запись') : $bodyClient;
		$bodyClient = self::resolveBody($type, $order, 'client', $bodyClient, ['time' => $dateClient]);
		$bodyMaster = self::resolveBody($type, $order, 'master', $bodyMaster, ['time' => self::formatDateTime($order['time'] ?? '', '', self::getUserTimezone($masterId))]);
		$data = ['url' => self::getLkUrl()];
		self::sendToClientAndMaster($order, $title, $bodyClient, $type, $data, $bodyMaster);
	}

	public static function notifyRescheduled(array $order, $oldTime = null)
	{
		self::ensureSettingsHelperLoaded();
		$type = 'booking_rescheduled';
		if (!self::shouldSendEvent($order, $type)) {
			return;
		}
		$serviceName = self::resolveOfferingName($order);
		$title = self::resolveTitle($type, 'Запись перенесена');
		$clientId = (int) ($order['user_id'] ?? 0);
		$masterId = (int) ($order['master_id'] ?? 0);
		$timeStr = self::formatDateTime($order['time'] ?? '', $order['time_to'] ?? '', self::getUserTimezone($clientId));
		$bodyClient = self::buildClientBody($order, $timeStr ? 'Новое время: ' . $timeStr : null);
		$bodyMaster = $masterId !== $clientId ? self::buildMasterBody($order, $serviceName, 'Перенёс запись', true) : $bodyClient;
		$bodyClient = self::resolveBody($type, $order, 'client', $bodyClient, ['time' => $timeStr]);
		$bodyMaster = self::resolveBody($type, $order, 'master', $bodyMaster, ['time' => self::formatDateTime($order['time'] ?? '', $order['time_to'] ?? '', self::getUserTimezone($masterId))]);
		$data = ['url' => self::getLkUrl()];
		self::sendToClientAndMaster($order, $title, $bodyClient, $type, $data, $bodyMaster);
	}

	public static function notifyIn30Minutes(array $order)
	{
		self::ensureSettingsHelperLoaded();
		$type = 'booking_in_30min';
		if (!self::shouldSendEvent($order, $type)) {
			return;
		}
		$serviceName = self::resolveOfferingName($order);
		$title = self::resolveTitle($type, 'Через 30 минут приём');
		$clientId = (int) ($order['user_id'] ?? 0);
		$masterId = (int) ($order['master_id'] ?? 0);
		$timeStr = self::formatDateTime($order['time'] ?? '', $order['time_to'] ?? '', self::getUserTimezone($clientId));
		$bodyClient = self::buildClientBody($order, $timeStr ?: null);
		$bodyMaster = $masterId !== $clientId ? self::buildMasterBody($order, $serviceName, 'Через 30 минут приём') : $bodyClient;
		$bodyClient = self::resolveBody($type, $order, 'client', $bodyClient, ['time' => $timeStr, 'reminder' => 'Через 30 минут']);
		$bodyMaster = self::resolveBody($type, $order, 'master', $bodyMaster, ['time' => self::formatDateTime($order['time'] ?? '', $order['time_to'] ?? '', self::getUserTimezone($masterId)), 'reminder' => 'Через 30 минут']);
		$data = ['url' => self::getLkUrl()];
		self::sendToClientAndMaster($order, $title, $bodyClient, $type, $data, $bodyMaster);
	}

	public static function notifyStarted(array $order)
	{
		self::ensureSettingsHelperLoaded();
		$type = 'booking_started';
		if (!self::shouldSendEvent($order, $type)) {
			return;
		}
		$serviceName = self::resolveOfferingName($order);
		$title = self::resolveTitle($type, 'Приём начался');
		$clientId = (int) ($order['user_id'] ?? 0);
		$masterId = (int) ($order['master_id'] ?? 0);
		$timeStr = self::formatDateTime($order['time'] ?? '', $order['time_to'] ?? '', self::getUserTimezone($clientId));
		$bodyClient = self::buildClientBody($order, $timeStr !== '' ? $timeStr : null);
		$bodyMaster = $masterId !== $clientId ? self::buildMasterBody($order, $serviceName, 'Приём начался') : $bodyClient;
		$bodyClient = self::resolveBody($type, $order, 'client', $bodyClient, ['time' => $timeStr]);
		$bodyMaster = self::resolveBody($type, $order, 'master', $bodyMaster, ['time' => self::formatDateTime($order['time'] ?? '', $order['time_to'] ?? '', self::getUserTimezone($masterId))]);
		$data = ['url' => self::getLkUrl()];
		self::sendToClientAndMaster($order, $title, $bodyClient, $type, $data, $bodyMaster);
	}

	public static function notifyReminder(array $order, int $minutes)
	{
		self::ensureSettingsHelperLoaded();
		if ($minutes === 0) {
			self::notifyStarted($order);
			return;
		}
		if ($minutes === 30) {
			self::notifyIn30Minutes($order);
			return;
		}
		$type = 'booking_reminder';
		if (!self::shouldSendEvent($order, $type)) {
			return;
		}
		$serviceName = self::resolveOfferingName($order);
		$title = self::resolveTitle($type, 'Напоминание о записи');
		$clientId = (int) ($order['user_id'] ?? 0);
		$masterId = (int) ($order['master_id'] ?? 0);
		$timeStr = self::formatDateTime($order['time'] ?? '', $order['time_to'] ?? '', self::getUserTimezone($clientId));
		$label = self::formatReminderLabel($minutes);
		$bodyClient = self::buildClientBody($order, trim($label . ($timeStr !== '' ? ': ' . $timeStr : '')));
		$bodyMaster = $masterId !== $clientId ? self::buildMasterBody($order, $serviceName, $label) : $bodyClient;
		$bodyClient = self::resolveBody($type, $order, 'client', $bodyClient, ['time' => $timeStr, 'reminder' => $label]);
		$bodyMaster = self::resolveBody($type, $order, 'master', $bodyMaster, ['time' => self::formatDateTime($order['time'] ?? '', $order['time_to'] ?? '', self::getUserTimezone($masterId)), 'reminder' => $label]);
		$data = ['url' => self::getLkUrl()];
		self::sendToClientAndMaster($order, $title, $bodyClient, $type, $data, $bodyMaster);
	}

	public static function sendToClientAndMaster(array $order, $title, $body, $notificationType, array $data = [], ?string $bodyMaster = null)
	{
		$clientId = (int) ($order['user_id'] ?? 0);
		$masterId = (int) ($order['master_id'] ?? 0);
		$orderId = isset($order['id']) ? (int) $order['id'] : null;
		if ($orderId <= 0) {
			$orderId = null;
		}
		$bodyForMaster = $bodyMaster !== null ? $bodyMaster : $body;
		$bookingKind = self::resolveBookingKind($order);
			if ($clientId > 0 && NotificationSettingsHelper::isRecipientEnabled($notificationType, 'client')) {
				if (NotificationSettingsHelper::isFcmEnabled($notificationType, $bookingKind)) {
					self::sendWithRetry($clientId, $title, $body, self::withOrderData($data, $orderId), $notificationType, 'client');
				}
				if (NotificationSettingsHelper::isInboxEnabled($notificationType, $bookingKind)) {
					InboxHelper::add($clientId, $notificationType, $title, $body, $orderId);
				}
			}
			if ($masterId > 0 && $masterId !== $clientId && NotificationSettingsHelper::isRecipientEnabled($notificationType, 'master')) {
				if (NotificationSettingsHelper::isFcmEnabled($notificationType, $bookingKind)) {
					self::sendWithRetry($masterId, $title, $bodyForMaster, self::withOrderData($data, $orderId), $notificationType, 'master');
				}
				if (NotificationSettingsHelper::isInboxEnabled($notificationType, $bookingKind)) {
					InboxHelper::add($masterId, $notificationType, $title, $bodyForMaster, $orderId);
				}
		}
	}

	private static function sendWithRetry(int $userId, string $title, string $body, array $data, string $type, string $recipientRole = ''): void
	{
		$settings = NotificationSettingsHelper::get('notifications');
		$maxAttempts = max(1, (int) ($settings['global']['fcm_retry_attempts'] ?? 2));
		$delayMs = max(0, (int) ($settings['global']['fcm_retry_delay_ms'] ?? 300));
		$data['notification_type'] = $type;
		$data['recipient_role'] = $recipientRole;
		if (empty($data['notification_tag'])) {
			$tagParts = [$type, $recipientRole, (string) ($data['order_id'] ?? ''), (string) $userId, md5($title . "\n" . $body)];
			$data['notification_tag'] = implode(':', array_filter($tagParts, static function ($part) {
				return $part !== '';
			}));
		}
		$attempt = 0;
		$result = ['sent' => 0, 'failed' => 1];
		while ($attempt < $maxAttempts) {
			$result = FcmHelper::sendNotification($userId, $title, $body, $data, $type, $recipientRole);
			if ($result['sent'] > 0 || $result['failed'] === 0) {
				break;
			}
			$attempt++;
			if ($attempt < $maxAttempts && $delayMs > 0) {
				usleep($delayMs * 1000);
			}
		}
	}

	private static function withOrderData(array $data, ?int $orderId): array
	{
		if ($orderId !== null && $orderId > 0) {
			$data['order_id'] = (string) $orderId;
		}
		return $data;
	}

	private static function shouldSendEvent(array $order, string $event): bool
	{
		return NotificationSettingsHelper::isEventEnabled($event, self::resolveBookingKind($order));
	}

	private static function resolveTitle(string $event, string $fallback): string
	{
		$settings = NotificationSettingsHelper::get('notifications');
		$title = trim((string) ($settings['events'][$event]['title'] ?? ''));
		return $title !== '' ? $title : $fallback;
	}

	private static function resolveBody(string $event, array $order, string $recipientRole, string $fallback, array $extra = []): string
	{
		$settings = NotificationSettingsHelper::get('notifications');
		$template = trim((string) ($settings['events'][$event]['body'] ?? ''));
		if ($template === '') {
			return $fallback;
		}

		$clientId = (int) ($order['user_id'] ?? 0);
		$masterId = (int) ($order['master_id'] ?? 0);
		$time = (string) ($extra['time'] ?? '');
		if ($time === '') {
			$timezone = $recipientRole === 'master' ? self::getUserTimezone($masterId) : self::getUserTimezone($clientId);
			$time = self::formatDateTime($order['time'] ?? '', $order['time_to'] ?? '', $timezone);
		}

		$values = [
			'{service}' => self::resolveOfferingName($order),
			'{master}' => $masterId > 0 ? self::getMasterDisplayText($masterId) : '',
			'{client}' => $clientId > 0 ? self::getClientDisplayText($clientId) : '',
			'{time}' => $time,
			'{date}' => $time,
			'{recipient}' => $recipientRole === 'master' ? 'Мастер' : 'Клиент',
			'{recipient_role}' => $recipientRole,
			'{reminder}' => (string) ($extra['reminder'] ?? ''),
		];

		$body = trim(strtr($template, $values));
		return $body !== '' ? $body : $fallback;
	}

	private static function resolveBookingKind(array $order): string
	{
		$bookingKind = trim((string) ($order['booking_kind'] ?? 'service'));
		return in_array($bookingKind, ['service', 'stock', 'course', 'search', 'journal'], true) ? $bookingKind : 'service';
	}

	private static function formatReminderLabel(int $minutes): string
	{
		if ($minutes >= 1440 && $minutes % 1440 === 0) {
			$days = (int) ($minutes / 1440);
			return 'Через ' . $days . ' ' . ($days === 1 ? 'день' : 'дн.') . ' приём';
		}
		if ($minutes >= 60 && $minutes % 60 === 0) {
			return 'Через ' . (int) ($minutes / 60) . ' ч. приём';
		}
		return 'Через ' . $minutes . ' мин. приём';
	}

	public static function formatDateTime(string $time, string $timeTo, ?string $timezone = null): string
	{
		if ($time === '') {
			return '';
		}
		try {
			$utc = new \DateTimeZone('UTC');
			$tz = $timezone !== null && $timezone !== '' ? new \DateTimeZone($timezone) : new \DateTimeZone(Factory::getApplication()->get('offset', 'UTC'));
			$dt = new \DateTime($time, $utc);
			$dt->setTimezone($tz);
			$formatted = $dt->format('d.m.Y H:i');
			if ($timeTo !== '') {
				$dtTo = new \DateTime($timeTo, $utc);
				$dtTo->setTimezone($tz);
				$formatted .= ' – ' . $dtTo->format('H:i');
			}
			return $formatted;
		} catch (\Throwable $e) {
			return $time;
		}
	}

	public static function getUserTimezone(int $userId): ?string
	{
		try {
			$db = Factory::getContainer()->get(\Joomla\Database\DatabaseInterface::class);
			$table = new \Joomla\CMS\Table\User($db);
			if (!$table->load($userId)) {
				return null;
			}
			$params = json_decode($table->params ?? '{}', true);
			return isset($params['timezone']) && $params['timezone'] !== '' ? (string) $params['timezone'] : null;
		} catch (\Throwable $e) {
			return null;
		}
	}

	private static function buildClientBody(array $order, ?string $contentPart = null): string
	{
		$serviceName = self::resolveOfferingName($order);
		$masterId = (int) ($order['master_id'] ?? 0);
		$masterName = $masterId > 0 ? self::getMasterDisplayText($masterId) : '';
		$parts = [$serviceName];
		if ($masterName !== '') {
			$parts[] = 'Мастер: ' . $masterName;
		}
		if ($contentPart !== null && $contentPart !== '') {
			$parts[] = $contentPart;
		}
		return implode('. ', $parts);
	}

	private static function getMasterDisplayText(int $userId): string
	{
		try {
			$db = Factory::getContainer()->get(\Joomla\Database\DatabaseInterface::class);
			$db->setQuery(
				$db->getQuery(true)
					->select($db->quoteName('name'))
					->from($db->quoteName('#__users'))
					->where($db->quoteName('id') . ' = ' . (int) $userId)
			);
			$name = trim((string) ($db->loadResult() ?? ''));
			return $name !== '' ? $name : 'Мастер';
		} catch (\Throwable $e) {
			return 'Мастер';
		}
	}

	private static function getClientDisplayText(int $userId): string
	{
		try {
			$db = Factory::getContainer()->get(\Joomla\Database\DatabaseInterface::class);
			$db->setQuery(
				$db->getQuery(true)
					->select($db->quoteName(['name', 'email']))
					->from($db->quoteName('#__users'))
					->where($db->quoteName('id') . ' = ' . (int) $userId)
			);
			$row = $db->loadObject();
			if (!$row) {
				return 'Клиент';
			}
			$name = trim((string) ($row->name ?? ''));
			$email = trim((string) ($row->email ?? ''));
			$parts = [];
			if ($name !== '') {
				$parts[] = $name;
			}
			if ($email !== '') {
				$parts[] = $email;
			}
			return $parts !== [] ? implode(', ', $parts) : 'Клиент';
		} catch (\Throwable $e) {
			return 'Клиент';
		}
	}

	private static function buildMasterBody(array $order, string $serviceName, string $prefix, bool $newTime = false): string
	{
		$clientId = (int) ($order['user_id'] ?? 0);
		$masterId = (int) ($order['master_id'] ?? 0);
		$clientText = self::getClientDisplayText($clientId);
		$timeStr = self::formatDateTime($order['time'] ?? '', $order['time_to'] ?? '', self::getUserTimezone($masterId));
		$parts = [$prefix . ': ' . $clientText . '.', $serviceName . '.', $timeStr];
		if ($newTime && $timeStr !== '') {
			$parts[2] = 'Новое время: ' . $timeStr;
		}
		return implode(' ', array_filter($parts));
	}

	private static function resolveOfferingName(array $order): string
	{
		$bookingKind = trim((string) ($order['booking_kind'] ?? 'service'));
		$serviceName = trim((string) ($order['service_name'] ?? ''));
		if ($bookingKind === 'course') {
			if ($serviceName === '') {
				return 'Курс';
			}
			return preg_match('/^\s*Курс\s*:/u', $serviceName) ? $serviceName : ('Курс: ' . $serviceName);
		}
		if ($bookingKind === 'search') {
			if ($serviceName === '') {
				return 'Поиск моделей';
			}
			return preg_match('/^\s*Поиск моделей\s*:/u', $serviceName) ? $serviceName : ('Поиск моделей: ' . $serviceName);
		}
		return $serviceName !== '' ? $serviceName : 'Услуга';
	}

	private static function getLkUrl(): string
	{
		if (class_exists(\Joomla\CMS\Uri\Uri::class)) {
			return rtrim(\Joomla\CMS\Uri\Uri::root(), '/') . '/lk';
		}
		return '';
	}
}
