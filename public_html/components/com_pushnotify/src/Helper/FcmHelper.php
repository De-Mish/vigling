<?php

namespace Viglin\Component\Pushnotify\Site\Helper;

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;

class FcmHelper
{
	/** @var list<int> */
	private static array $immediateIds = [];

	public static function sendNotification($userId, $title, $body, array $data = [], $notificationType = 'booking_confirmed', $recipientRole = '')
	{
		if (!class_exists(NotificationSettingsHelper::class)) {
			$path = JPATH_SITE . '/components/com_pushnotify/src/Helper/NotificationSettingsHelper.php';
			if (is_file($path)) {
				require_once $path;
			}
		}
		if (!NotificationSettingsHelper::isFcmEnabled((string) $notificationType)) {
			return ['sent' => 0, 'failed' => 0];
		}
		if (!\defined('VIGLING_PUSH_DRAIN')) {
			if (self::enqueue($userId, $title, $body, $data, $notificationType, $recipientRole)) {
				return ['sent' => 0, 'failed' => 0, 'queued' => 1];
			}
		}
		$db = Factory::getContainer()->get(\Joomla\Database\DatabaseInterface::class);
		$db->setQuery(
			$db->getQuery(true)
				->select('fcm_token')
				->from($db->quoteName('#__pushnotify_subscriptions'))
				->where($db->quoteName('user_id') . ' = ' . (int) $userId)
		);
		$tokens = $db->loadColumn();
		if (empty($tokens)) {
			self::log($userId, $notificationType, $title, $body, 'failed', 'no_tokens', $recipientRole);
			return ['sent' => 0, 'failed' => 0];
		}

		$pref = $db->setQuery(
			$db->getQuery(true)
				->select('notifications_enabled')
				->from($db->quoteName('#__pushnotify_preferences'))
				->where($db->quoteName('user_id') . ' = ' . (int) $userId)
		)->loadResult();
		if ((int) $pref === 0) {
			self::log($userId, $notificationType, $title, $body, 'failed', 'notifications_disabled', $recipientRole);
			return ['sent' => 0, 'failed' => 0];
		}

		$credentialsPath = JPATH_ROOT . '/configuration/firebase-credentials.json';
		if (!is_file($credentialsPath)) {
			self::log($userId, $notificationType, $title, $body, 'failed', 'No credentials file', $recipientRole);
			return ['sent' => 0, 'failed' => count($tokens)];
		}
		$credentialsProject = self::credentialsProjectId($credentialsPath);
		$siteProject = self::siteProjectId();
		if ($credentialsProject !== '' && $siteProject !== '' && $credentialsProject !== $siteProject) {
			self::log(
				$userId,
				$notificationType,
				$title,
				$body,
				'failed',
				'Firebase key project ' . $credentialsProject . ' does not match site project ' . $siteProject,
				$recipientRole
			);
			return ['sent' => 0, 'failed' => count($tokens)];
		}

		if (!class_exists('\Kreait\Firebase\Factory')) {
			$autoload = JPATH_LIBRARIES . '/vendor/autoload.php';
			if (is_file($autoload)) {
				require_once $autoload;
			}
			if (!class_exists('\Kreait\Firebase\Factory')) {
				$autoload = JPATH_SITE . '/components/com_pushnotify/vendor/autoload.php';
				if (is_file($autoload)) {
					require_once $autoload;
				}
			}
		}
		if (!class_exists('\Kreait\Firebase\Factory')) {
			self::log($userId, $notificationType, $title, $body, 'failed', 'kreait/firebase-php not installed', $recipientRole);
			return ['sent' => 0, 'failed' => count($tokens)];
		}

		$dataStrings = [];
		$data['title'] = $title;
		$data['body'] = $body;
		foreach ($data as $k => $v) {
			$dataStrings[(string) $k] = (string) $v;
		}

		$sent = 0;
		try {
			$factory = (new \Kreait\Firebase\Factory)->withServiceAccount($credentialsPath);
			$messaging = $factory->createMessaging();
			$link = isset($data['url']) ? (string) $data['url'] : '';
			if ($link === '' && class_exists(\Joomla\CMS\Uri\Uri::class)) {
				$link = rtrim(\Joomla\CMS\Uri\Uri::root(), '/') . '/lk';
			}
			$topicSource = (string) ($dataStrings['notification_tag'] ?? ($title . "\n" . $body));
			$topic = substr(preg_replace('/[^A-Za-z0-9_-]/', '', hash('sha256', $topicSource)) ?? '', 0, 32);
			if ($topic === '') {
				$topic = substr(hash('sha256', $topicSource), 0, 32);
			}
			// Data-only: a notification payload is collapsible, so an idle phone keeps just one banner.
			$webPushArray = [
				'headers' => [
					'Urgency' => 'high',
					'TTL' => '2419200',
					'Topic' => $topic,
				],
				'data' => [
					'title' => $title,
					'body' => $body,
				],
			];
			if ($link !== '') {
				$webPushArray['fcm_options'] = ['link' => $link];
			}
			$webPush = \Kreait\Firebase\Messaging\WebPushConfig::fromArray($webPushArray)->withHighUrgency();
			$androidConfig = \Kreait\Firebase\Messaging\AndroidConfig::fromArray([
				'priority' => 'high',
				'ttl' => '2419200s',
			]);
			$apnsConfig = \Kreait\Firebase\Messaging\ApnsConfig::fromArray([
				'headers' => [
					'apns-priority' => '10',
					'apns-push-type' => 'alert',
				],
				'payload' => [
					'aps' => [
						'alert' => [
							'title' => $title,
							'body' => $body,
						],
						'sound' => 'default',
					],
				],
			]);
			foreach ($tokens as $token) {
				try {
					$message = \Kreait\Firebase\Messaging\CloudMessage::new()
						->toToken($token)
						->withData($dataStrings)
						->withWebPushConfig($webPush)
						->withAndroidConfig($androidConfig)
						->withApnsConfig($apnsConfig);
					$messaging->send($message);
					self::log($userId, $notificationType, $title, $body, 'sent', 'ok', $recipientRole);
					$sent++;
				} catch (\Throwable $e) {
					$msg = $e->getMessage();
					self::log($userId, $notificationType, $title, $body, 'failed', $msg, $recipientRole);
					if (self::isDeadToken($msg)) {
						self::removeToken($db, $token);
					}
				}
			}
		} catch (\Throwable $e) {
			self::log($userId, $notificationType, $title, $body, 'failed', $e->getMessage(), $recipientRole);
		}
		return ['sent' => $sent, 'failed' => count($tokens) - $sent];
	}

	private static function credentialsProjectId(string $path): string
	{
		$raw = @file_get_contents($path);
		if (!is_string($raw) || $raw === '') {
			return '';
		}
		$data = json_decode($raw, true);
		return is_array($data) ? trim((string) ($data['project_id'] ?? '')) : '';
	}

	private static function siteProjectId(): string
	{
		$path = JPATH_ROOT . '/configuration/firebase-config.php';
		if (!is_file($path)) {
			return '';
		}
		$config = include $path;
		return is_array($config) ? trim((string) ($config['projectId'] ?? '')) : '';
	}

	private static function isDeadToken(string $msg): bool
	{
		foreach ([
			'NotRegistered',
			'UNREGISTERED',
			'registration-token-not-registered',
			'invalid-registration-token',
			'InvalidRegistration',
			'Requested entity was not found',
		] as $needle) {
			if (stripos($msg, $needle) !== false) {
				return true;
			}
		}
		return false;
	}

	private static function removeToken($db, $token)
	{
		$db->setQuery(
			$db->getQuery(true)
				->delete($db->quoteName('#__pushnotify_subscriptions'))
				->where($db->quoteName('fcm_token') . ' = ' . $db->quote($token))
		)->execute();
	}

	public static function enqueue($userId, $title, $body, array $data, $notificationType, $recipientRole = ''): bool
	{
		$userId = (int) $userId;
		if ($userId <= 0) {
			return false;
		}
		try {
			$db = Factory::getContainer()->get(\Joomla\Database\DatabaseInterface::class);
			self::ensureQueueTable($db);
			$row = new \stdClass();
			$row->user_id = $userId;
			$row->title = function_exists('mb_substr') ? mb_substr((string) $title, 0, 255) : substr((string) $title, 0, 255);
			$row->body = (string) $body;
			$row->payload = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
			$row->notification_type = substr((string) $notificationType, 0, 64);
			$row->recipient_role = substr((string) $recipientRole, 0, 32);
			$row->attempts = 0;
			$row->created_at = (new \DateTime('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');

			if (!$db->insertObject('#__vigling_push_queue', $row)) {
				return false;
			}
			$id = (int) $db->insertid();
			if ($id > 0) {
				self::scheduleImmediateDrain($id);
			}

			return true;
		} catch (\Throwable $e) {
			return false;
		}
	}

	private static function scheduleImmediateDrain(int $id): void
	{
		self::$immediateIds[] = $id;
		static $scheduled = false;
		if ($scheduled) {
			return;
		}
		$scheduled = true;
		register_shutdown_function(static function (): void {
			$ids = self::$immediateIds;
			self::$immediateIds = [];
			if ($ids === []) {
				return;
			}
			if (\function_exists('fastcgi_finish_request')) {
				@\fastcgi_finish_request();
			}
			self::drainQueue(\count($ids), $ids);
		});
	}

	public static function drainQueue(int $limit = 40, array $onlyIds = []): int
	{
		if (!\defined('VIGLING_PUSH_DRAIN')) {
			\define('VIGLING_PUSH_DRAIN', true);
		}
		$onlyIds = array_values(array_unique(array_filter(array_map('intval', $onlyIds), static function (int $id): bool {
			return $id > 0;
		})));
		if ($onlyIds === [] && \func_num_args() > 1) {
			return 0;
		}
		$sentRows = 0;
		try {
			$db = Factory::getContainer()->get(\Joomla\Database\DatabaseInterface::class);
			self::ensureQueueTable($db);
			$query = $db->getQuery(true)
				->select('*')
				->from($db->quoteName('#__vigling_push_queue'))
				->where($db->quoteName('attempts') . ' < 3')
				->order($db->quoteName('id') . ' ASC');
			if ($onlyIds !== []) {
				$query->where($db->quoteName('id') . ' IN (' . implode(',', $onlyIds) . ')');
				$limit = \count($onlyIds);
			}
			$db->setQuery($query, 0, max(1, $limit));
			$rows = $db->loadObjectList() ?: [];
			foreach ($rows as $row) {
				$id = (int) ($row->id ?? 0);
				$attempts = (int) ($row->attempts ?? 0);
				if ($id <= 0) {
					continue;
				}
				$db->setQuery(
					'UPDATE ' . $db->quoteName('#__vigling_push_queue')
					. ' SET ' . $db->quoteName('attempts') . ' = ' . ($attempts + 1)
					. ' WHERE ' . $db->quoteName('id') . ' = ' . $id
					. ' AND ' . $db->quoteName('attempts') . ' = ' . $attempts
				)->execute();
				if ((int) $db->getAffectedRows() !== 1) {
					continue;
				}
				$payload = json_decode((string) ($row->payload ?? ''), true);
				if (!is_array($payload)) {
					$payload = [];
				}
				$result = self::sendNotification(
					(int) $row->user_id,
					(string) $row->title,
					(string) $row->body,
					$payload,
					(string) $row->notification_type,
					(string) ($row->recipient_role ?? '')
				);
				$done = ((int) ($result['sent'] ?? 0) > 0) || ((int) ($result['failed'] ?? 1) === 0) || ($attempts + 1) >= 3;
				if ($done) {
					$db->setQuery(
						$db->getQuery(true)
							->delete($db->quoteName('#__vigling_push_queue'))
							->where($db->quoteName('id') . ' = ' . $id)
					)->execute();
					$sentRows++;
				}
			}
		} catch (\Throwable $e) {
			return $sentRows;
		}

		return $sentRows;
	}

	private static function ensureQueueTable($db): void
	{
		static $ready = false;
		if ($ready) {
			return;
		}
		$db->setQuery(
			'CREATE TABLE IF NOT EXISTS ' . $db->quoteName('#__vigling_push_queue') . ' (
				`id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
				`user_id` INT UNSIGNED NOT NULL,
				`title` VARCHAR(255) NOT NULL,
				`body` TEXT NOT NULL,
				`payload` MEDIUMTEXT NOT NULL,
				`notification_type` VARCHAR(64) NOT NULL,
				`recipient_role` VARCHAR(32) NOT NULL DEFAULT \'\',
				`attempts` TINYINT UNSIGNED NOT NULL DEFAULT 0,
				`created_at` DATETIME NOT NULL,
				PRIMARY KEY (`id`),
				KEY `idx_created` (`created_at`)
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
		)->execute();
		$ready = true;
	}

	private static function log($userId, $notificationType, $title, $body, $status, $fcmResponse, $recipientRole = '')
	{
		$settings = NotificationSettingsHelper::get('notifications');
		if (empty($settings['global']['logging_enabled'])) {
			return;
		}
		$db = Factory::getContainer()->get(\Joomla\Database\DatabaseInterface::class);
		$now = (new \DateTime('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');
		$o = new \stdClass();
		$o->user_id = $userId;
		$o->notification_type = $notificationType;
		$o->title = $title;
		$o->body = $body;
		$o->fcm_response = is_string($fcmResponse) ? $fcmResponse : json_encode($fcmResponse);
		$o->status = $status;
		$o->sent_at = $now;
		$o->delivered_at = null;
		$o->clicked_at = null;
		$cols = $db->getTableColumns('#__pushnotify_logs', false);
		if (isset($cols['recipient_role'])) {
			$o->recipient_role = in_array($recipientRole, ['client', 'master'], true) ? $recipientRole : null;
		}
		$db->insertObject('#__pushnotify_logs', $o);
	}
}