<?php

namespace Viglin\Component\Pushnotify\Site\Helper;

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;

class FcmHelper
{
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
			$notification = \Kreait\Firebase\Messaging\Notification::fromArray([
				'title' => $title,
				'body' => $body,
			]);
			$link = isset($data['url']) ? (string) $data['url'] : '';
			if ($link === '' && class_exists(\Joomla\CMS\Uri\Uri::class)) {
				$link = rtrim(\Joomla\CMS\Uri\Uri::root(), '/') . '/lk';
			}
			$webPushNotification = ['title' => $title, 'body' => $body];
			if (!empty($dataStrings['notification_tag'])) {
				$webPushNotification['tag'] = $dataStrings['notification_tag'];
				$webPushNotification['renotify'] = false;
			}
			$webPushArray = [
				'notification' => $webPushNotification,
				'headers' => ['Urgency' => 'high'],
			];
			if ($link !== '') {
				$webPushArray['fcm_options'] = ['link' => $link];
			}
			$webPush = \Kreait\Firebase\Messaging\WebPushConfig::fromArray($webPushArray)->withHighUrgency();
			$androidConfig = \Kreait\Firebase\Messaging\AndroidConfig::fromArray([
				'priority' => 'high',
				'notification' => ['notification_priority' => 'PRIORITY_HIGH'],
			]);
			$apnsConfig = \Kreait\Firebase\Messaging\ApnsConfig::new()->withPriority('10');
			foreach ($tokens as $token) {
				try {
					$message = \Kreait\Firebase\Messaging\CloudMessage::new()
						->toToken($token)
						->withNotification($notification)
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
					if (stripos($msg, 'NotRegistered') !== false || stripos($msg, 'invalid') !== false || stripos($msg, 'INVALID_ARGUMENT') !== false || stripos($msg, 'unregistered') !== false) {
						self::removeToken($db, $token);
					}
				}
			}
		} catch (\Throwable $e) {
			self::log($userId, $notificationType, $title, $body, 'failed', $e->getMessage(), $recipientRole);
		}
		return ['sent' => $sent, 'failed' => count($tokens) - $sent];
	}

	private static function removeToken($db, $token)
	{
		$db->setQuery(
			$db->getQuery(true)
				->delete($db->quoteName('#__pushnotify_subscriptions'))
				->where($db->quoteName('fcm_token') . ' = ' . $db->quote($token))
		)->execute();
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
