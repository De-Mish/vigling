<?php

namespace Viglin\Plugin\System\Pushnotifybooking\Extension;

\defined('_JEXEC') or die;

use Joomla\CMS\Event\Table\AfterDeleteEvent;
use Joomla\CMS\Event\Table\AfterStoreEvent;
use Joomla\CMS\Event\Table\BeforeDeleteEvent;
use Joomla\CMS\Event\Table\BeforeStoreEvent;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\Event\SubscriberInterface;
use Viglin\Plugin\System\Pushnotifybooking\Helper\BookingNotifyHelper;

final class Pushnotifybooking extends CMSPlugin implements SubscriberInterface
{
	private static $deletedOrder = null;

	private static $beforeStoreTime = null;

	public static function getSubscribedEvents(): array
	{
		return [
			'onTableBeforeDelete' => 'onTableBeforeDelete',
			'onTableBeforeStore'  => 'onTableBeforeStore',
			'onTableAfterDelete'  => 'onTableAfterDelete',
			'onTableAfterStore'  => 'onTableAfterStore',
		];
	}

	public function onTableBeforeStore(BeforeStoreEvent $event): void
	{
		$table = $event->getArgument('subject');
		if (!$this->isBookingsTable($table)) {
			return;
		}
		if ($this->isJournalOrder($table)) {
			self::$beforeStoreTime = null;
			return;
		}
		$pk = $table->getKeyName();
		$pkName = is_array($pk) ? $pk[0] : $pk;
		$id = (int) ($table->$pkName ?? 0);
		if ($id > 0) {
			$db = \Joomla\CMS\Factory::getContainer()->get(\Joomla\Database\DatabaseInterface::class);
			$db->setQuery(
				$db->getQuery(true)
					->select($db->quoteName('time'))
					->from($db->quoteName($table->getTableName()))
					->where($db->quoteName($pkName) . ' = ' . $id)
			);
			$oldTime = $db->loadResult();
			self::$beforeStoreTime = $oldTime !== null ? $oldTime : null;
		} else {
			self::$beforeStoreTime = null;
		}
	}

	public function onTableBeforeDelete(BeforeDeleteEvent $event): void
	{
		$table = $event->getArgument('subject');
		if (!$this->isBookingsTable($table)) {
			return;
		}
		if ($this->isJournalOrder($table)) {
			self::$deletedOrder = null;
			return;
		}
		$pk = $table->getKeyName();
		$pkName = is_array($pk) ? $pk[0] : $pk;
		self::$deletedOrder = [
			'id'           => isset($table->$pkName) ? (int) $table->$pkName : 0,
			'user_id'      => isset($table->user_id) ? (int) $table->user_id : 0,
			'master_id'    => isset($table->master_id) ? (int) $table->master_id : 0,
			'time'         => isset($table->time) ? $table->time : '',
			'time_to'      => isset($table->time_to) ? $table->time_to : '',
			'service_name' => isset($table->service_name) ? $table->service_name : '',
			'booking_kind' => isset($table->booking_kind) ? $table->booking_kind : 'service',
			'course_id'    => isset($table->course_id) ? (int) $table->course_id : 0,
			'course_slot_id' => isset($table->course_slot_id) ? (int) $table->course_slot_id : 0,
			'search_id'    => isset($table->search_id) ? (int) $table->search_id : 0,
			'search_slot_id' => isset($table->search_slot_id) ? (int) $table->search_slot_id : 0,
		];
	}

	public function onTableAfterDelete(AfterDeleteEvent $event): void
	{
		$table = $event->getArgument('subject');
		if (!$this->isBookingsTable($table) || self::$deletedOrder === null || $this->isJournalOrder($table)) {
			return;
		}
		$order = self::$deletedOrder;
		self::$deletedOrder = null;
		BookingNotifyHelper::notifyCancelled($order);
	}

	public function onTableAfterStore(AfterStoreEvent $event): void
	{
		if (!$event->getArgument('result')) {
			return;
		}
		$table = $event->getArgument('subject');
		if (!$this->isBookingsTable($table)) {
			return;
		}
		if ($this->isJournalOrder($table)) {
			self::$beforeStoreTime = null;
			return;
		}
		$pk = $table->getKeyName();
		$pkName = is_array($pk) ? $pk[0] : $pk;
		$order = [
			'id'           => isset($table->$pkName) ? (int) $table->$pkName : 0,
			'user_id'      => isset($table->user_id) ? (int) $table->user_id : 0,
			'master_id'    => isset($table->master_id) ? (int) $table->master_id : 0,
			'time'         => isset($table->time) ? $table->time : '',
			'time_to'      => isset($table->time_to) ? $table->time_to : '',
			'service_name' => isset($table->service_name) ? $table->service_name : '',
			'booking_kind' => isset($table->booking_kind) ? $table->booking_kind : 'service',
			'course_id'    => isset($table->course_id) ? (int) $table->course_id : 0,
			'course_slot_id' => isset($table->course_slot_id) ? (int) $table->course_slot_id : 0,
			'search_id'    => isset($table->search_id) ? (int) $table->search_id : 0,
			'search_slot_id' => isset($table->search_slot_id) ? (int) $table->search_slot_id : 0,
		];
		$newTime = isset($table->time) ? trim((string) $table->time) : '';
		$oldTime = self::$beforeStoreTime !== null ? trim((string) self::$beforeStoreTime) : null;
		self::$beforeStoreTime = null;
		$isReschedule = $oldTime !== null && $newTime !== '' && self::timesDiffer($oldTime, $newTime);
		if ($isReschedule) {
			BookingNotifyHelper::notifyRescheduled($order);
		} elseif ($oldTime === null) {
			BookingNotifyHelper::notifyConfirmedOrRescheduled($order);
		}
	}

	private function isBookingsTable($table): bool
	{
		if (!is_object($table) || !method_exists($table, 'getTableName')) {
			return false;
		}
		$name = $table->getTableName();
		return strpos($name, 'vigling_bookings') !== false || strpos($name, 'jsn_orders') !== false;
	}

	private function isJournalOrder($table): bool
	{
		if (!is_object($table)) {
			return false;
		}
		if (isset($table->user_id) && (int) $table->user_id <= 0) {
			return true;
		}
		$serviceName = isset($table->service_name) ? trim((string) $table->service_name) : '';
		return $serviceName !== '' && strpos($serviceName, '[journal]') === 0;
	}

	private static function timesDiffer(string $time1, string $time2): bool
	{
		$ts1 = strtotime($time1);
		$ts2 = strtotime($time2);
		if ($ts1 !== false && $ts2 !== false) {
			return $ts1 !== $ts2;
		}
		return $time1 !== $time2;
	}
}
