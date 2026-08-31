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

	public static function ensureStockServiceIdColumn(DatabaseInterface $db): bool
	{
		try {
			$columns = array_change_key_case($db->getTableColumns('#__vigling_bookings', false), CASE_LOWER);
			if (isset($columns['stock_service_id'])) {
				return true;
			}
			$after = 'service_name';
			if (isset($columns['search_slot_id'])) {
				$after = 'search_slot_id';
			} elseif (isset($columns['course_slot_id'])) {
				$after = 'course_slot_id';
			}
			$db->setQuery(
				'ALTER TABLE ' . $db->quoteName('#__vigling_bookings')
				. ' ADD COLUMN ' . $db->quoteName('stock_service_id') . ' BIGINT UNSIGNED DEFAULT NULL'
				. ' AFTER ' . $db->quoteName($after)
			)->execute();
			try {
				$db->setQuery(
					'ALTER TABLE ' . $db->quoteName('#__vigling_bookings')
					. ' ADD KEY ' . $db->quoteName('idx_stock_service_id') . ' (' . $db->quoteName('stock_service_id') . ')'
				)->execute();
			} catch (\Throwable $e) {
			}

			return true;
		} catch (\Throwable $e) {
			return false;
		}
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
