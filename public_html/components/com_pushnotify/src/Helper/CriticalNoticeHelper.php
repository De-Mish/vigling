<?php

namespace Viglin\Component\Pushnotify\Site\Helper;

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\Database\DatabaseInterface;

/**
 * Notices a person must see: shown in a modal after login and behind an envelope icon until the
 * person acknowledges them (information) or answers them (requires_action). They do not depend on
 * personal push / inbox preferences. A push and an inbox entry are sent as an extra channel.
 */
final class CriticalNoticeHelper
{
	private const TABLE = '#__vigling_critical_notices';

	public static function add(
		int $userId,
		string $type,
		string $title,
		string $body,
		string $url = '',
		bool $requiresAction = false,
		string $refType = '',
		int $refId = 0
	): void {
		if ($userId <= 0) {
			return;
		}
		try {
			$db = self::db();
			$row = new \stdClass();
			$row->user_id = $userId;
			$row->notice_type = mb_substr($type, 0, 40);
			$row->title = mb_substr($title, 0, 255);
			$row->body = $body;
			$row->url = mb_substr($url, 0, 500);
			$row->requires_action = $requiresAction ? 1 : 0;
			$row->ref_type = mb_substr($refType, 0, 30);
			$row->ref_id = max(0, $refId);
			$row->created_at = self::nowUtc();
			$db->insertObject(self::TABLE, $row);
		} catch (\Throwable $e) {
			// Table not installed yet: the extra channels below still reach the person.
		}
		self::notifyInfo($userId, $type, $title, $body, $url);
	}

	/**
	 * Push and inbox only. Used for messages that are useful but do not need a mandatory acknowledgement.
	 */
	public static function notifyInfo(int $userId, string $type, string $title, string $body, string $url = ''): void
	{
		if ($userId <= 0) {
			return;
		}
		try {
			self::load(InboxHelper::class, 'InboxHelper.php');
			InboxHelper::add($userId, $type, $title, $body, null, true);
		} catch (\Throwable $e) {
		}
		try {
			self::load(FcmHelper::class, 'FcmHelper.php');
			FcmHelper::sendNotification($userId, $title, $body, ['url' => $url !== '' ? $url : self::lkUrl()], $type, 'client');
		} catch (\Throwable $e) {
		}
	}

	/**
	 * @return array<int, array<string,mixed>>
	 */
	public static function listForUser(int $userId): array
	{
		if ($userId <= 0) {
			return [];
		}
		try {
			$db = self::db();
			$query = $db->getQuery(true)
				->select('id, notice_type, title, body, url, requires_action, ref_type, ref_id, created_at')
				->from($db->quoteName(self::TABLE))
				->where($db->quoteName('user_id') . ' = ' . $userId)
				->where($db->quoteName('acknowledged_at') . ' IS NULL')
				->where($db->quoteName('resolved_at') . ' IS NULL')
				->order($db->quoteName('created_at') . ' DESC')
				->setLimit(30);
			$db->setQuery($query);

			return $db->loadAssocList() ?: [];
		} catch (\Throwable $e) {
			return [];
		}
	}

	public static function acknowledge(int $userId, int $noticeId): bool
	{
		if ($userId <= 0 || $noticeId <= 0) {
			return false;
		}
		try {
			$db = self::db();
			$query = $db->getQuery(true)
				->update($db->quoteName(self::TABLE))
				->set($db->quoteName('acknowledged_at') . ' = ' . $db->quote(self::nowUtc()))
				->where($db->quoteName('id') . ' = ' . $noticeId)
				->where($db->quoteName('user_id') . ' = ' . $userId)
				->where($db->quoteName('requires_action') . ' = 0')
				->where($db->quoteName('acknowledged_at') . ' IS NULL');
			$db->setQuery($query)->execute();

			return true;
		} catch (\Throwable $e) {
			return false;
		}
	}

	public static function resolveByRef(string $refType, int $refId, int $userId = 0): void
	{
		if ($refType === '' || $refId <= 0) {
			return;
		}
		try {
			$db = self::db();
			$query = $db->getQuery(true)
				->update($db->quoteName(self::TABLE))
				->set($db->quoteName('resolved_at') . ' = ' . $db->quote(self::nowUtc()))
				->where($db->quoteName('ref_type') . ' = ' . $db->quote($refType))
				->where($db->quoteName('ref_id') . ' = ' . $refId)
				->where($db->quoteName('requires_action') . ' = 1')
				->where($db->quoteName('resolved_at') . ' IS NULL');
			if ($userId > 0) {
				$query->where($db->quoteName('user_id') . ' = ' . $userId);
			}
			$db->setQuery($query)->execute();
		} catch (\Throwable $e) {
		}
	}

	/**
	 * Date and time as the person sees them (their own time zone), for use inside notice texts.
	 */
	public static function formatTime(int $userId, string $utcDateTime): string
	{
		$utcDateTime = trim($utcDateTime);
		if ($utcDateTime === '') {
			return '';
		}
		try {
			$tz = new \DateTimeZone(self::userTimezone($userId));
			$dt = new \DateTimeImmutable($utcDateTime, new \DateTimeZone('UTC'));

			return $dt->setTimezone($tz)->format('d.m.Y H:i');
		} catch (\Throwable $e) {
			return $utcDateTime;
		}
	}

	private static function userTimezone(int $userId): string
	{
		$fallback = (string) Factory::getApplication()->get('offset', 'UTC');
		if ($userId <= 0) {
			return $fallback;
		}
		try {
			$db = self::db();
			$db->setQuery(
				$db->getQuery(true)
					->select($db->quoteName('params'))
					->from($db->quoteName('#__users'))
					->where($db->quoteName('id') . ' = ' . $userId)
			);
			$params = json_decode((string) $db->loadResult(), true);
			$tz = is_array($params) ? trim((string) ($params['timezone'] ?? '')) : '';
			if ($tz !== '') {
				new \DateTimeZone($tz);

				return $tz;
			}
		} catch (\Throwable $e) {
		}

		return $fallback;
	}

	private static function lkUrl(): string
	{
		return rtrim(\Joomla\CMS\Uri\Uri::root(), '/') . '/lk';
	}

	private static function load(string $class, string $file): void
	{
		if (class_exists($class)) {
			return;
		}
		$path = JPATH_SITE . '/components/com_pushnotify/src/Helper/' . $file;
		if (is_file($path)) {
			require_once $path;
		}
	}

	private static function nowUtc(): string
	{
		return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');
	}

	private static function db(): DatabaseInterface
	{
		return Factory::getContainer()->get(DatabaseInterface::class);
	}
}
