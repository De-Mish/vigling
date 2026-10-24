<?php

namespace Viglin\Component\Orders\Site\Table;

\defined('_JEXEC') or die;

use Joomla\CMS\Table\Table;
use Joomla\Database\DatabaseInterface;
use Joomla\Event\DispatcherInterface;

class OrderTable extends Table
{
	public function __construct(DatabaseInterface $db, DispatcherInterface $dispatcher = null)
	{
		parent::__construct('#__vigling_bookings', 'id', $db, $dispatcher);
	}

	public function delete($pk = null)
	{
		$kind = isset($this->booking_kind) ? trim((string) $this->booking_kind) : '';
		$completed = isset($this->completed) ? (int) $this->completed : 0;
		$stockServiceId = isset($this->stock_service_id) ? (int) $this->stock_service_id : 0;
		$masterId = isset($this->master_id) ? (int) $this->master_id : 0;

		$result = parent::delete($pk);
		if (
			$result
			&& $kind === 'stock'
			&& $completed !== 1
			&& $stockServiceId > 0
			&& $masterId > 0
		) {
			try {
				self::restoreStockOffer($this->getDbo(), $stockServiceId, $masterId);
			} catch (\Throwable $e) {
			}
		}

		return $result;
	}

	private static function bookingTableHasColumns(DatabaseInterface $db, array $names): bool
	{
		try {
			$columns = array_change_key_case($db->getTableColumns('#__vigling_bookings', false), CASE_LOWER);
		} catch (\Throwable $e) {
			return false;
		}
		foreach ($names as $name) {
			if (!isset($columns[$name])) {
				return false;
			}
		}

		return true;
	}

	public static function ensureBookingCommentColumns(DatabaseInterface $db): bool
	{
		return self::bookingTableHasColumns($db, ['comment', 'contact_name', 'contact_phone']);
	}

	public static function ensureStockServiceIdColumn(DatabaseInterface $db): bool
	{
		return self::bookingTableHasColumns($db, ['stock_service_id']);
	}

	public static function ensureServiceFullNameColumn(DatabaseInterface $db): bool
	{
		return self::bookingTableHasColumns($db, ['service_full_name']);
	}

	public static function ensureRemindMinutesColumn(DatabaseInterface $db): bool
	{
		return self::bookingTableHasColumns($db, ['remind_minutes']);
	}

	public static function ensureSourceColumn(DatabaseInterface $db): bool
	{
		return self::bookingTableHasColumns($db, ['source']);
	}

	public static function restoreStockOffer(DatabaseInterface $db, int $stockServiceId, int $masterId): void
	{
		if ($stockServiceId <= 0 || $masterId <= 0) {
			return;
		}
		$query = $db->getQuery(true)
			->update($db->quoteName('#__vigling_user_stock_services'))
			->set($db->quoteName('count_stock') . ' = ' . $db->quoteName('count_stock') . ' + 1')
			->where($db->quoteName('id') . ' = ' . $stockServiceId)
			->where($db->quoteName('user_id') . ' = ' . $masterId);
		$db->setQuery($query)->execute();
	}

	public static function masterBookingLockName(int $masterId): string
	{
		return 'vigling-book-master-' . $masterId;
	}

	public static function acquireMasterBookingLock(DatabaseInterface $db, int $masterId, int $timeoutSeconds = 10): bool
	{
		if ($masterId <= 0) {
			return false;
		}
		$db->setQuery(
			'SELECT GET_LOCK(' . $db->quote(self::masterBookingLockName($masterId)) . ', ' . (int) $timeoutSeconds . ')'
		);
		return (int) $db->loadResult() === 1;
	}

	private static bool $notificationsSilenced = false;

	/**
	 * While on, the standard "booking cancelled / rescheduled" push of the pushnotifybooking plugin is skipped.
	 * Used by flows that send their own, more specific notice (the group reschedule).
	 */
	public static function silenceNotifications(bool $on): void
	{
		self::$notificationsSilenced = $on;
	}

	public static function notificationsSilenced(): bool
	{
		return self::$notificationsSilenced;
	}

	/** @var array<int,string> user id => lock name, held on this connection */
	private static array $heldUserLocks = [];

	private static function userBookingLockName(int $userId): string
	{
		return 'vigling-book-user-' . $userId;
	}

	/**
	 * Serialises bookings of the same person with different specialists, so the "no two appointments at once" check
	 * cannot be passed by two requests at the same moment. Always take it after the master lock and in ascending
	 * user order; it is released together with the master lock.
	 *
	 * @param int[] $userIds
	 */
	public static function acquireUserBookingLocks(DatabaseInterface $db, array $userIds, int $timeoutSeconds = 5): bool
	{
		$userIds = array_values(array_unique(array_filter(array_map('intval', $userIds), static fn (int $id): bool => $id > 0)));
		sort($userIds);
		foreach ($userIds as $userId) {
			if (isset(self::$heldUserLocks[$userId])) {
				continue;
			}
			$name = self::userBookingLockName($userId);
			$db->setQuery('SELECT GET_LOCK(' . $db->quote($name) . ', ' . (int) $timeoutSeconds . ')');
			if ((int) $db->loadResult() !== 1) {
				self::releaseUserBookingLocks($db);

				return false;
			}
			self::$heldUserLocks[$userId] = $name;
		}

		return true;
	}

	public static function releaseUserBookingLocks(DatabaseInterface $db): void
	{
		foreach (self::$heldUserLocks as $userId => $name) {
			try {
				$db->setQuery('SELECT RELEASE_LOCK(' . $db->quote($name) . ')');
				$db->loadResult();
			} catch (\Throwable $e) {
			}
			unset(self::$heldUserLocks[$userId]);
		}
	}

	public static function releaseMasterBookingLock(DatabaseInterface $db, int $masterId): void
	{
		self::releaseUserBookingLocks($db);
		if ($masterId <= 0) {
			return;
		}
		try {
			$db->setQuery('SELECT RELEASE_LOCK(' . $db->quote(self::masterBookingLockName($masterId)) . ')');
			$db->loadResult();
		} catch (\Throwable $e) {
		}
	}
}