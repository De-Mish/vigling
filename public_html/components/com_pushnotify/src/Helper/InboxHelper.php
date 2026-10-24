<?php

namespace Viglin\Component\Pushnotify\Site\Helper;

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;

class InboxHelper
{
	public static function add(int $userId, string $notificationType, string $title, string $body, ?int $orderId = null, bool $force = false): void
	{
		if (!class_exists(NotificationSettingsHelper::class)) {
			$path = JPATH_SITE . '/components/com_pushnotify/src/Helper/NotificationSettingsHelper.php';
			if (is_file($path)) {
				require_once $path;
			}
		}
		if (!$force && !NotificationSettingsHelper::isInboxEnabled($notificationType)) {
			return;
		}
		$db = Factory::getContainer()->get(\Joomla\Database\DatabaseInterface::class);
		$cols = $db->getTableColumns('#__pushnotify_inbox', false);
		if (empty($cols)) {
			return;
		}
		$now = (new \DateTime('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');
		$o = new \stdClass();
		$o->user_id = $userId;
		$o->notification_type = $notificationType;
		$o->title = $title;
		$o->body = $body;
		$o->order_id = $orderId;
		$o->read_at = null;
		$o->deleted_at = null;
		$o->created_at = $now;
		$db->insertObject('#__pushnotify_inbox', $o);
	}
}
