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

	public static function releaseMasterBookingLock(DatabaseInterface $db, int $masterId): void
	{
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