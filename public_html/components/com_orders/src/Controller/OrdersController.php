<?php

namespace Viglin\Component\Orders\Site\Controller;

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\MVC\Controller\BaseController;
use Joomla\CMS\Session\Session;
use Joomla\CMS\Uri\Uri;
use Viglin\Component\Orders\Site\Helper\CatalogPriceHelper;
use Viglin\Component\Orders\Site\Helper\GroupRescheduleHelper;
use Viglin\Component\Orders\Site\Helper\ReviewHelper;
use Viglin\Component\Orders\Site\Table\OrderTable;

if (!class_exists(ReviewHelper::class, false)) {
	require_once JPATH_SITE . '/components/com_orders/src/Helper/ReviewHelper.php';
}
if (!class_exists(\Viglin\Component\Orders\Site\Helper\AppointmentsHelper::class, false)) {
	require_once JPATH_SITE . '/components/com_orders/src/Helper/AppointmentsHelper.php';
}
if (!class_exists(GroupRescheduleHelper::class, false)) {
	require_once JPATH_SITE . '/components/com_orders/src/Helper/GroupRescheduleHelper.php';
}

class OrdersController extends BaseController
{
	public function cancel()
	{
		Session::checkToken('request') or $this->setRedirectAndExit();
		$user = Factory::getApplication()->getIdentity();
		if (!$user->id) {
			$this->setMessage('Нужна авторизация', 'error');
			$this->setRedirectAndExit();
			return;
		}
		if ($this->denyIfBookingFlagOff('booking_cancel')) {
			return;
		}
		$id = (int) $this->input->get('id', 0);
		if ($id <= 0) {
			$this->setMessage('Неверный идентификатор записи', 'error');
			$this->setRedirectAndExit();
			return;
		}
		$table = new OrderTable(
			Factory::getContainer()->get(\Joomla\Database\DatabaseInterface::class),
			Factory::getContainer()->get(\Joomla\Event\DispatcherInterface::class)
		);
		if (!$table->load($id)) {
			$this->setMessage('Запись не найдена', 'error');
			$this->setRedirectAndExit();
			return;
		}
		if ((int) $table->user_id !== (int) $user->id) {
			$this->setMessage('Нет прав на отмену этой записи', 'error');
			$this->setRedirectAndExit();
			return;
		}
		if (self::hasStarted($table)) {
			$this->setMessage('Прошедшую запись нельзя отменить, её можно только удалить из списка', 'error');
			$this->setRedirectAndExit();
			return;
		}
		$groupDb = Factory::getContainer()->get(\Joomla\Database\DatabaseInterface::class);
		$openReply = GroupRescheduleHelper::isAvailable($groupDb) ? GroupRescheduleHelper::openReplyForBooking($groupDb, $id) : null;
		if (!$table->delete($id)) {
			$this->setMessage($table->getError() ?: 'Ошибка отмены записи', 'error');
			$this->setRedirectAndExit();
			return;
		}
		if ($openReply !== null) {
			$this->afterGroupBookingRemoved($groupDb, $openReply);
		}
		$this->setMessage('Запись отменена');
		$this->setRedirectAndExit();
	}

	public function delete()
	{
		Session::checkToken('request') or $this->setRedirectAndExit();
		$user = Factory::getApplication()->getIdentity();
		if (!$user->id) {
			$this->setMessage('Нужна авторизация', 'error');
			$this->setRedirectAndExit();
			return;
		}
		if ($this->denyIfBookingFlagOff('booking_cancel')) {
			return;
		}
		$id = (int) $this->input->get('id', 0);
		if ($id <= 0) {
			$this->setMessage('Неверный идентификатор записи', 'error');
			$this->setRedirectAndExit();
			return;
		}
		$table = new OrderTable(
			Factory::getContainer()->get(\Joomla\Database\DatabaseInterface::class),
			Factory::getContainer()->get(\Joomla\Event\DispatcherInterface::class)
		);
		if (!$table->load($id)) {
			$this->setMessage('Запись не найдена', 'error');
			$this->setRedirectAndExit();
			return;
		}
		if ((int) $table->user_id !== (int) $user->id) {
			$this->setMessage('Нет прав на удаление этой записи', 'error');
			$this->setRedirectAndExit();
			return;
		}
		$orderTime = !empty($table->time) ? (string) $table->time : '';
		if ($orderTime !== '') {
			try {
				$dt = new \DateTime($orderTime);
				if ($dt >= new \DateTime()) {
					$this->setMessage('Удалить можно только прошедшую запись. Будущую — отмените.', 'error');
					$this->setRedirectAndExit();
					return;
				}
			} catch (\Throwable $e) {
			}
		}
		$db = Factory::getContainer()->get(\Joomla\Database\DatabaseInterface::class);
		$prefix = $db->getPrefix();
		$tbl = $prefix . 'vigling_bookings';
		$db->setQuery('DELETE FROM ' . $db->quoteName($tbl) . ' WHERE ' . $db->quoteName('id') . ' = ' . (int) $id)->execute();
		self::guardRecord('removed', $table);
		$this->setMessage('Запись удалена');
		$this->setRedirectAndExit();
	}

	public function repeat()
	{
		Session::checkToken('request') or $this->setRedirectAndExit();
		$user = Factory::getApplication()->getIdentity();
		if (!$user->id) {
			$this->setMessage('Нужна авторизация', 'error');
			$this->setRedirectAndExit();
			return;
		}
		if ($this->denyIfBookingFlagOff('booking_create')) {
			return;
		}
		if (!$this->allowAnotherBooking((int) $user->id)) {
			$this->setMessage('Слишком много записей за короткое время. Попробуйте через несколько минут.', 'error');
			$this->setRedirectAndExit();
			return;
		}
		$id = (int) $this->input->get('id', 0);
		$timeUtcStr = trim((string) $this->input->get('time_utc', '', 'string'));
		$timeStr = trim((string) $this->input->get('time', '', 'string'));
		if ($id <= 0) {
			$this->setMessage('Укажите запись', 'error');
			$this->setRedirectAndExit();
			return;
		}
		$db = Factory::getContainer()->get(\Joomla\Database\DatabaseInterface::class);
		$dispatcher = Factory::getContainer()->get(\Joomla\Event\DispatcherInterface::class);
		$table = new OrderTable($db, $dispatcher);
		if (!$table->load($id)) {
			$this->setMessage('Запись не найдена', 'error');
			$this->setRedirectAndExit();
			return;
		}
		if ((int) $table->user_id !== (int) $user->id) {
			$this->setMessage('Нет прав на повтор этой записи', 'error');
			$this->setRedirectAndExit();
			return;
		}
		$bookingKind = isset($table->booking_kind) ? trim((string) $table->booking_kind) : 'service';
		if ($bookingKind === 'stock' || (int) ($table->stock_service_id ?? 0) > 0) {
			$this->setMessage('Акцию нельзя повторить', 'error');
			$this->setRedirectAndExit();
			return;
		}
		if ($bookingKind === 'course' && ((int) ($table->course_id ?? 0) > 0 || (int) ($table->course_slot_id ?? 0) > 0)) {
			$this->setMessage('Курс нельзя повторить этой кнопкой', 'error');
			$this->setRedirectAndExit();
			return;
		}
		if ($bookingKind === 'search' && ((int) ($table->search_id ?? 0) > 0 || (int) ($table->search_slot_id ?? 0) > 0)) {
			$this->setMessage('Поиск нельзя повторить этой кнопкой', 'error');
			$this->setRedirectAndExit();
			return;
		}
		$masterId = (int) $table->master_id;
		if ($masterId <= 0) {
			$this->setMessage('Не указан мастер', 'error');
			$this->setRedirectAndExit();
			return;
		}
		if (!class_exists(\Viglin\Component\Orders\Site\Helper\BookingPauseHelper::class, false)) {
			require_once JPATH_SITE . '/components/com_orders/src/Helper/BookingPauseHelper.php';
		}
		if (\Viglin\Component\Orders\Site\Helper\BookingPauseHelper::isPausedFor($masterId)) {
			$this->setMessage(\Viglin\Component\Orders\Site\Helper\BookingPauseHelper::MESSAGE, 'warning');
			$this->setRedirectAndExit();
			return;
		}
		$serviceName = trim((string) ($table->service_name ?? ''));
		if ($serviceName === '') {
			$this->setMessage('У исходной записи не указана услуга', 'error');
			$this->setRedirectAndExit();
			return;
		}
		$masterTimezone = self::getUserTimezoneById($masterId, (string) Factory::getApplication()->get('offset', 'UTC'));
		$time = self::parseRescheduleDateTime($timeUtcStr, $timeStr, $masterTimezone);
		if (!$time) {
			$this->setMessage('Укажите дату и время', 'error');
			$this->setRedirectAndExit();
			return;
		}
		if (!class_exists(CatalogPriceHelper::class, false)) {
			require_once JPATH_SITE . '/components/com_orders/src/Helper/CatalogPriceHelper.php';
		}
		$current = CatalogPriceHelper::findCurrentService(
			$masterId,
			(int) ($table->svc_id ?? 0),
			(int) ($table->tag_id ?? 0),
			$serviceName
		);
		$currentItem = $current['item'];
		if ($current['has_catalog'] && $currentItem === null) {
			$this->setMessage('Мастер больше не оказывает эту услугу. Выберите услугу в профиле мастера.', 'error');
			$this->setRedirectAndExit();
			return;
		}
		$currentTimeSum = 0;
		if ($currentItem !== null) {
			$serviceName = $currentItem['name'] !== '' ? $currentItem['name'] : $serviceName;
			$durationMin = $currentItem['duration'];
			$currentTimeSum = $currentItem['duration'] + $currentItem['pause_min'];
		} else {
			$durationMin = max(15, min(480, self::deriveDurationMinFromOrder($table)));
			$currentTimeSum = isset($table->time_sum) ? (int) $table->time_sum : 0;
		}
		$timeTo = clone $time;
		$timeTo->modify('+' . $durationMin . ' minutes');
		$occupiedTo = self::occupiedProbeEnd($time, $durationMin, $currentTimeSum);
		$timeDb = $time->format('Y-m-d H:i:s');
		$timeToDb = $timeTo->format('Y-m-d H:i:s');
		$occupiedToDb = $occupiedTo->format('Y-m-d H:i:s');
		$startUtc = \DateTimeImmutable::createFromMutable($time);
		$endUtc = \DateTimeImmutable::createFromMutable($occupiedTo);
		$nowUtc = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
		if ($startUtc <= $nowUtc) {
			$this->setMessage('Нельзя записаться на прошедшее время', 'error');
			$this->setRedirectAndExit();
			return;
		}
		$scheduleCheck = self::validateMasterSchedule($masterId, $startUtc, $endUtc, $masterTimezone);
		if (!$scheduleCheck['ok']) {
			$this->setMessage((string) ($scheduleCheck['message'] ?? 'Выбранное время вне рабочего графика мастера'), 'error');
			$this->setRedirectAndExit();
			return;
		}
		$tableName = $db->getPrefix() . 'vigling_bookings';
		$lockHeld = false;
		try {
			if (!OrderTable::acquireMasterBookingLock($db, $masterId)) {
				$this->setMessage('Сейчас идёт другая запись к этому специалисту, попробуйте ещё раз', 'error');
				$this->setRedirectAndExit();
				return;
			}
			$lockHeld = true;
			if (!OrderTable::acquireUserBookingLocks($db, [(int) $user->id])) {
				$this->setMessage('Сейчас идёт другая запись этого клиента, попробуйте ещё раз', 'error');
				$this->setRedirectAndExit();
				return;
			}
			if (self::hasCourseSlotsOverlap($db, $masterId, $timeDb, $occupiedToDb)) {
				$this->setMessage('Это время занято курсом', 'error');
				$this->setRedirectAndExit();
				return;
			}
			if (self::hasSearchSlotsOverlap($db, $masterId, $timeDb, $occupiedToDb)) {
				$this->setMessage('Это время занято поиском', 'error');
				$this->setRedirectAndExit();
				return;
			}
			if (self::hasBookingsOverlap($db, $tableName, $masterId, $timeDb, $occupiedToDb, 0)) {
				$this->setMessage('Это время уже занято', 'error');
				$this->setRedirectAndExit();
				return;
			}
			if (self::hasUserBookingOverlap($db, $tableName, (int) $user->id, $masterId, $timeDb, $timeToDb, 0)) {
				$this->setMessage('У вас уже есть запись на это время к другому специалисту', 'error');
				$this->setRedirectAndExit();
				return;
			}

			OrderTable::ensureBookingCommentColumns($db);
			OrderTable::ensureSourceColumn($db);
			OrderTable::ensureServiceFullNameColumn($db);
			$columns = array_change_key_case($db->getTableColumns('#__vigling_bookings', false), CASE_LOWER);
			$new = new OrderTable($db, $dispatcher);
			$payload = [
				'user_id' => (int) $user->id,
				'master_id' => $masterId,
				'time' => $timeDb,
				'time_to' => $timeToDb,
				'service_name' => $serviceName,
				'completed' => 0,
			];
			if (isset($columns['booking_kind'])) {
				$payload['booking_kind'] = 'service';
			}
			if (isset($columns['source'])) {
				$payload['source'] = 'profile';
			}
			$copyIfPresent = ['contact_name', 'contact_phone', 'remind_minutes'];
			if ($currentItem === null) {
				$copyIfPresent = array_merge($copyIfPresent, ['svc_id', 'tag_id', 'price', 'time_sum', 'service_full_name']);
			}
			foreach ($copyIfPresent as $columnName) {
				if (!isset($columns[$columnName]) || !isset($table->$columnName)) {
					continue;
				}
				$value = $table->$columnName;
				if ($value === null || $value === '') {
					continue;
				}
				$payload[$columnName] = $value;
			}
			if ($currentItem !== null) {
				$fresh = [
					'svc_id' => $currentItem['svc_id'],
					'tag_id' => $currentItem['tag_id'],
					'price' => $currentItem['price'],
					'time_sum' => $currentTimeSum,
					'service_full_name' => $currentItem['category'] !== ''
						? mb_substr($currentItem['category'] . ' - ' . $currentItem['name'], 0, 255)
						: '',
				];
				foreach ($fresh as $columnName => $value) {
					if (isset($columns[$columnName]) && $value !== '' && $value !== 0) {
						$payload[$columnName] = $value;
					}
				}
			}
			if (isset($columns['time_sum']) && empty($payload['time_sum'])) {
				$payload['time_sum'] = $durationMin;
			}
			if (!$new->bind($payload) || !$new->store()) {
				$this->setMessage($new->getError() ?: 'Ошибка сохранения записи', 'error');
				$this->setRedirectAndExit();
				return;
			}
		} finally {
			if ($lockHeld) {
				OrderTable::releaseMasterBookingLock($db, $masterId);
			}
		}
		$this->setMessage('Вы записались!');
		$this->setRedirectAndExit();
	}

	public function reschedule()
	{
		Session::checkToken('request') or $this->setRedirectAndExit();
		$user = Factory::getApplication()->getIdentity();
		if (!$user->id) {
			$this->setMessage('Нужна авторизация', 'error');
			$this->setRedirectAndExit();
			return;
		}
		if ($this->denyIfBookingFlagOff('booking_reschedule')) {
			return;
		}
		$id = (int) $this->input->get('id', 0);
		$timeUtcStr = trim((string) $this->input->get('time_utc', '', 'string'));
		$timeStr = trim((string) $this->input->get('time', '', 'string'));
		if ($id <= 0) {
			$this->setMessage('Укажите запись', 'error');
			$this->setRedirectAndExit();
			return;
		}
		$table = new OrderTable(
			Factory::getContainer()->get(\Joomla\Database\DatabaseInterface::class),
			Factory::getContainer()->get(\Joomla\Event\DispatcherInterface::class)
		);
		if (!$table->load($id)) {
			$this->setMessage('Запись не найдена', 'error');
			$this->setRedirectAndExit();
			return;
		}
		if ((int) $table->user_id !== (int) $user->id) {
			$this->setMessage('Нет прав на перенос этой записи', 'error');
			$this->setRedirectAndExit();
			return;
		}
		if (
			isset($table->booking_kind, $table->course_slot_id)
			&& trim((string) $table->booking_kind) === 'course'
			&& (int) $table->course_slot_id > 0
		) {
			$this->setMessage('Фиксированный курс переносится только мастером целиком', 'error');
			$this->setRedirectAndExit();
			return;
		}
		if (
			isset($table->booking_kind, $table->search_slot_id)
			&& trim((string) $table->booking_kind) === 'search'
			&& (int) $table->search_slot_id > 0
		) {
			$this->setMessage('Фиксированный поиск переносится только мастером целиком', 'error');
			$this->setRedirectAndExit();
			return;
		}
		$masterTimezone = self::getUserTimezoneById((int) $table->master_id, (string) Factory::getApplication()->get('offset', 'UTC'));
		$time = self::parseRescheduleDateTime($timeUtcStr, $timeStr, $masterTimezone);
		if (!$time) {
			$this->setMessage('Укажите новое дату/время', 'error');
			$this->setRedirectAndExit();
			return;
		}
		$utc = new \DateTimeZone('UTC');
		$durationMin = max(15, min(480, self::deriveDurationMinFromOrder($table)));
		$timeTo = clone $time;
		$timeTo->modify('+' . $durationMin . ' minutes');
		$occupiedTo = self::occupiedProbeEnd($time, $durationMin, isset($table->time_sum) ? (int) $table->time_sum : 0);
		$timeDb = $time->format('Y-m-d H:i:s');
		$timeToDb = $timeTo->format('Y-m-d H:i:s');
		$occupiedToDb = $occupiedTo->format('Y-m-d H:i:s');
		if (!empty($table->time)) {
			try {
				$utc = new \DateTimeZone('UTC');
				$currentTime = new \DateTime((string) $table->time, $utc);
				$now = new \DateTime('now', $utc);
				if ($currentTime < $now) {
					$this->setMessage('Нельзя перенести прошедшую запись', 'error');
					$this->setRedirectAndExit();
					return;
				}
			} catch (\Throwable $e) {
			}
		}
		$startUtc = \DateTimeImmutable::createFromMutable($time);
		$endUtc = \DateTimeImmutable::createFromMutable($occupiedTo);
		$nowUtc = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
		if ($startUtc <= $nowUtc) {
			$this->setMessage('Нельзя перенести запись на прошедшее время', 'error');
			$this->setRedirectAndExit();
			return;
		}
		$scheduleCheck = self::validateMasterSchedule((int) $table->master_id, $startUtc, $endUtc, $masterTimezone);
		if (!$scheduleCheck['ok']) {
			$this->setMessage((string) ($scheduleCheck['message'] ?? 'Выбранное время вне рабочего графика мастера'), 'error');
			$this->setRedirectAndExit();
			return;
		}
		$db = Factory::getContainer()->get(\Joomla\Database\DatabaseInterface::class);
		$tableName = $db->getPrefix() . 'vigling_bookings';
		$masterId = (int) $table->master_id;
		$lockHeld = false;
		try {
			if (!OrderTable::acquireMasterBookingLock($db, $masterId)) {
				$this->setMessage('Сейчас идёт другая запись к этому специалисту, попробуйте ещё раз', 'error');
				$this->setRedirectAndExit();
				return;
			}
			$lockHeld = true;
			if (!OrderTable::acquireUserBookingLocks($db, [(int) $table->user_id])) {
				$this->setMessage('Сейчас идёт другая запись этого клиента, попробуйте ещё раз', 'error');
				$this->setRedirectAndExit();
				return;
			}
			if (self::hasCourseSlotsOverlap($db, $masterId, $timeDb, $occupiedToDb)) {
				$this->setMessage('Это время занято курсом', 'error');
				$this->setRedirectAndExit();
				return;
			}
			if (self::hasSearchSlotsOverlap($db, $masterId, $timeDb, $occupiedToDb)) {
				$this->setMessage('Это время занято поиском', 'error');
				$this->setRedirectAndExit();
				return;
			}
			if (self::hasBookingsOverlap($db, $tableName, $masterId, $timeDb, $occupiedToDb, (int) $table->id)) {
				$this->setMessage('Это время уже занято', 'error');
				$this->setRedirectAndExit();
				return;
			}
			if (self::hasUserBookingOverlap($db, $tableName, (int) $table->user_id, $masterId, $timeDb, $timeToDb, (int) $table->id)) {
				$this->setMessage('У вас уже есть запись на это время к другому специалисту', 'error');
				$this->setRedirectAndExit();
				return;
			}
			$table->time = $timeDb;
			$table->time_to = $timeToDb;
			if (!$table->store()) {
				$this->setMessage($table->getError() ?: 'Ошибка переноса записи', 'error');
				$this->setRedirectAndExit();
				return;
			}
		} finally {
			if ($lockHeld) {
				OrderTable::releaseMasterBookingLock($db, $masterId);
			}
		}
		$this->setMessage('Запись перенесена');
		$this->setRedirectAndExit();
	}

	public function cancelByMaster()
	{
		Session::checkToken('request') or $this->setRedirectAndExit();
		$user = Factory::getApplication()->getIdentity();
		if (!$user->id) {
			$this->setMessage('Нужна авторизация', 'error');
			$this->setRedirectAndExit();
			return;
		}
		if ($this->denyIfBookingFlagOff('booking_cancel')) {
			return;
		}
		$id = (int) $this->input->get('id', 0);
		if ($id <= 0) {
			$this->setMessage('Неверный идентификатор записи', 'error');
			$this->setRedirectAndExit();
			return;
		}
		$table = new OrderTable(
			Factory::getContainer()->get(\Joomla\Database\DatabaseInterface::class),
			Factory::getContainer()->get(\Joomla\Event\DispatcherInterface::class)
		);
		if (!$table->load($id)) {
			$this->setMessage('Запись не найдена', 'error');
			$this->setRedirectAndExit();
			return;
		}
		if ((int) $table->master_id !== (int) $user->id) {
			$this->setMessage('Нет прав на отмену этой записи', 'error');
			$this->setRedirectAndExit();
			return;
		}
		if (self::hasStarted($table)) {
			$this->setMessage('Прошедшую запись нельзя отменить, её можно только удалить из списка', 'error');
			$this->setRedirectAndExit();
			return;
		}
		$groupDb = Factory::getContainer()->get(\Joomla\Database\DatabaseInterface::class);
		$openReply = GroupRescheduleHelper::isAvailable($groupDb) ? GroupRescheduleHelper::openReplyForBooking($groupDb, $id) : null;
		if (!$table->delete($id)) {
			$this->setMessage($table->getError() ?: 'Ошибка отмены записи', 'error');
			$this->setRedirectAndExit();
			return;
		}
		if ($openReply !== null) {
			$this->afterGroupBookingRemoved($groupDb, $openReply);
		}
		$this->setMessage('Запись отменена');
		$this->setRedirectAndExit();
	}

	public function rescheduleByMaster()
	{
		Session::checkToken('request') or $this->setRedirectAndExit();
		$user = Factory::getApplication()->getIdentity();
		if (!$user->id) {
			$this->setMessage('Нужна авторизация', 'error');
			$this->setRedirectAndExit();
			return;
		}
		if ($this->denyIfBookingFlagOff('booking_reschedule')) {
			return;
		}
		$id = (int) $this->input->get('id', 0);
		$timeUtcStr = trim((string) $this->input->get('time_utc', '', 'string'));
		$timeStr = trim((string) $this->input->get('time', '', 'string'));
		if ($id <= 0) {
			$this->setMessage('Укажите запись', 'error');
			$this->setRedirectAndExit();
			return;
		}
		$table = new OrderTable(
			Factory::getContainer()->get(\Joomla\Database\DatabaseInterface::class),
			Factory::getContainer()->get(\Joomla\Event\DispatcherInterface::class)
		);
		if (!$table->load($id)) {
			$this->setMessage('Запись не найдена', 'error');
			$this->setRedirectAndExit();
			return;
		}
		if ((int) $table->master_id !== (int) $user->id) {
			$this->setMessage('Нет прав на перенос этой записи', 'error');
			$this->setRedirectAndExit();
			return;
		}
		$masterTimezone = self::getUserTimezoneById((int) $table->master_id, (string) Factory::getApplication()->get('offset', 'UTC'));
		$time = self::parseRescheduleDateTime($timeUtcStr, $timeStr, $masterTimezone);
		if (!$time) {
			$this->setMessage('Укажите новое дату/время', 'error');
			$this->setRedirectAndExit();
			return;
		}
		$utc = new \DateTimeZone('UTC');
		$durationMin = max(15, min(480, self::deriveDurationMinFromOrder($table)));
		$timeTo = clone $time;
		$timeTo->modify('+' . $durationMin . ' minutes');
		$occupiedTo = self::occupiedProbeEnd($time, $durationMin, isset($table->time_sum) ? (int) $table->time_sum : 0);
		$timeDb = $time->format('Y-m-d H:i:s');
		$timeToDb = $timeTo->format('Y-m-d H:i:s');
		$occupiedToDb = $occupiedTo->format('Y-m-d H:i:s');
		if (!empty($table->time)) {
			try {
				$currentTime = new \DateTime((string) $table->time, $utc);
				$now = new \DateTime('now', $utc);
				if ($currentTime < $now) {
					$this->setMessage('Нельзя перенести прошедшую запись', 'error');
					$this->setRedirectAndExit();
					return;
				}
			} catch (\Throwable $e) {
			}
		}
		$startUtc = \DateTimeImmutable::createFromMutable($time);
		$endUtc = \DateTimeImmutable::createFromMutable($occupiedTo);
		$nowUtc = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
		if ($startUtc <= $nowUtc) {
			$this->setMessage('Нельзя перенести запись на прошедшее время', 'error');
			$this->setRedirectAndExit();
			return;
		}
		$scheduleCheck = self::validateMasterSchedule((int) $table->master_id, $startUtc, $endUtc, $masterTimezone);
		if (!$scheduleCheck['ok']) {
			$this->setMessage((string) ($scheduleCheck['message'] ?? 'Выбранное время вне рабочего графика мастера'), 'error');
			$this->setRedirectAndExit();
			return;
		}
		$db = Factory::getContainer()->get(\Joomla\Database\DatabaseInterface::class);
		$tableName = $db->getPrefix() . 'vigling_bookings';
		$masterId = (int) $table->master_id;
		$lockHeld = false;
		try {
			if (!OrderTable::acquireMasterBookingLock($db, $masterId)) {
				$this->setMessage('Сейчас идёт другая запись к этому специалисту, попробуйте ещё раз', 'error');
				$this->setRedirectAndExit();
				return;
			}
			$lockHeld = true;
			if (!OrderTable::acquireUserBookingLocks($db, [(int) $table->user_id])) {
				$this->setMessage('Сейчас идёт другая запись этого клиента, попробуйте ещё раз', 'error');
				$this->setRedirectAndExit();
				return;
			}
			if (self::hasCourseSlotsOverlap($db, $masterId, $timeDb, $occupiedToDb)) {
				$this->setMessage('Это время занято курсом', 'error');
				$this->setRedirectAndExit();
				return;
			}
			if (self::hasSearchSlotsOverlap($db, $masterId, $timeDb, $occupiedToDb)) {
				$this->setMessage('Это время занято поиском', 'error');
				$this->setRedirectAndExit();
				return;
			}
			if (self::hasBookingsOverlap($db, $tableName, $masterId, $timeDb, $occupiedToDb, (int) $table->id)) {
				$this->setMessage('Это время уже занято', 'error');
				$this->setRedirectAndExit();
				return;
			}
			if (self::hasUserBookingOverlap($db, $tableName, (int) $table->user_id, $masterId, $timeDb, $timeToDb, (int) $table->id)) {
				$this->setMessage('У клиента уже есть запись на это время к другому специалисту', 'error');
				$this->setRedirectAndExit();
				return;
			}
			$table->time = $timeDb;
			$table->time_to = $timeToDb;
			self::detachFixedGroupBooking($table);
			if (!$table->store()) {
				$this->setMessage($table->getError() ?: 'Ошибка переноса записи', 'error');
				$this->setRedirectAndExit();
				return;
			}
		} finally {
			if ($lockHeld) {
				OrderTable::releaseMasterBookingLock($db, $masterId);
			}
		}
		$this->setMessage('Запись перенесена');
		$this->setRedirectAndExit();
	}

	public function cancelCourseSlotByMaster()
	{
		Session::checkToken('request') or $this->setRedirectAndExit();
		$user = Factory::getApplication()->getIdentity();
		if (!$user->id) {
			$this->setMessage('Нужна авторизация', 'error');
			$this->setRedirectAndExit();
			return;
		}
		if ($this->denyIfBookingFlagOff('booking_cancel')) {
			return;
		}

		$courseSlotId = (int) $this->input->get('course_slot_id', 0);
		if ($courseSlotId <= 0) {
			$this->setMessage('Неверный идентификатор слота курса', 'error');
			$this->setRedirectAndExit();
			return;
		}

		$db = Factory::getContainer()->get(\Joomla\Database\DatabaseInterface::class);
		$courseContext = self::loadCourseSlotContext($db, $courseSlotId);
		if ($courseContext === null) {
			$this->setMessage('Слот курса не найден', 'error');
			$this->setRedirectAndExit();
			return;
		}
		if ((int) $courseContext['master_id'] !== (int) $user->id) {
			$this->setMessage('Нет прав на отмену этого курса', 'error');
			$this->setRedirectAndExit();
			return;
		}

		$bookingIds = self::getCourseSlotBookingIds($db, $courseSlotId, (int) $user->id);
		if ($bookingIds === []) {
			$this->setMessage('В слоте нет активных записей', 'error');
			$this->setRedirectAndExit();
			return;
		}

		$table = new OrderTable(
			Factory::getContainer()->get(\Joomla\Database\DatabaseInterface::class),
			Factory::getContainer()->get(\Joomla\Event\DispatcherInterface::class)
		);
		foreach ($bookingIds as $bookingId) {
			if (!$table->load((int) $bookingId)) {
				continue;
			}
			if ((int) $table->master_id !== (int) $user->id) {
				continue;
			}
			if (!$table->delete((int) $bookingId)) {
				$this->setMessage($table->getError() ?: 'Ошибка отмены записей курса', 'error');
				$this->setRedirectAndExit();
				return;
			}
		}

		self::deactivateCourseSlot($db, $courseSlotId);
		$this->setMessage('Курс отменён для всех участников');
		$this->setRedirectAndExit();
	}

	public function rescheduleCourseSlotByMaster()
	{
		Session::checkToken('request') or $this->setRedirectAndExit();
		$user = Factory::getApplication()->getIdentity();
		if (!$user->id) {
			$this->setMessage('Нужна авторизация', 'error');
			$this->setRedirectAndExit();
			return;
		}
		if ($this->denyIfBookingFlagOff('booking_reschedule')) {
			return;
		}

		$courseSlotId = (int) $this->input->get('course_slot_id', 0);
		$timeUtcStr = trim((string) $this->input->get('time_utc', '', 'string'));
		$timeStr = trim((string) $this->input->get('time', '', 'string'));
		if ($courseSlotId <= 0) {
			$this->setMessage('Укажите слот курса', 'error');
			$this->setRedirectAndExit();
			return;
		}

		$db = Factory::getContainer()->get(\Joomla\Database\DatabaseInterface::class);
		$courseContext = self::loadCourseSlotContext($db, $courseSlotId);
		if ($courseContext === null) {
			$this->setMessage('Слот курса не найден', 'error');
			$this->setRedirectAndExit();
			return;
		}
		if ((int) $courseContext['master_id'] !== (int) $user->id) {
			$this->setMessage('Нет прав на перенос этого курса', 'error');
			$this->setRedirectAndExit();
			return;
		}

		$masterTimezone = self::getUserTimezoneById((int) $user->id, (string) Factory::getApplication()->get('offset', 'UTC'));
		$time = self::parseRescheduleDateTime($timeUtcStr, $timeStr, $masterTimezone);
		if (!$time) {
			$this->setMessage('Укажите новое дату/время', 'error');
			$this->setRedirectAndExit();
			return;
		}
		$utc = new \DateTimeZone('UTC');

		$durationMin = (int) ($courseContext['duration_min'] ?? 0);
		if ($durationMin <= 0) {
			$durationMin = (int) $this->input->get('duration_min', 0);
		}
		$durationMin = max(15, min(480, $durationMin > 0 ? $durationMin : 60));

		$timeTo = clone $time;
		$timeTo->modify('+' . $durationMin . ' minutes');
		$timeDb = $time->format('Y-m-d H:i:s');
		$timeToDb = $timeTo->format('Y-m-d H:i:s');

		try {
			$currentTime = new \DateTime((string) $courseContext['starts_at_utc'], $utc);
			$now = new \DateTime('now', $utc);
			if ($currentTime < $now) {
				$this->setMessage('Нельзя перенести прошедший курс', 'error');
				$this->setRedirectAndExit();
				return;
			}
		} catch (\Throwable $e) {
		}

		$startUtc = \DateTimeImmutable::createFromMutable($time);
		$endUtc = \DateTimeImmutable::createFromMutable($timeTo);
		$nowUtc = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
		if ($startUtc <= $nowUtc) {
			$this->setMessage('Нельзя перенести курс на прошедшее время', 'error');
			$this->setRedirectAndExit();
			return;
		}

		$scheduleCheck = self::validateMasterSchedule((int) $user->id, $startUtc, $endUtc, $masterTimezone);
		if (!$scheduleCheck['ok']) {
			$this->setMessage((string) ($scheduleCheck['message'] ?? 'Выбранное время вне рабочего графика мастера'), 'error');
			$this->setRedirectAndExit();
			return;
		}

		$tableName = $db->getPrefix() . 'vigling_bookings';
		$excludeIds = self::getCourseSlotBookingIds($db, $courseSlotId, (int) $user->id);
		$masterId = (int) $user->id;
		$lockHeld = false;
		try {
			if (!OrderTable::acquireMasterBookingLock($db, $masterId)) {
				$this->setMessage('Сейчас идёт другая запись к этому специалисту, попробуйте ещё раз', 'error');
				$this->setRedirectAndExit();
				return;
			}
			$lockHeld = true;
			if (self::hasCourseSlotsOverlap($db, $masterId, $timeDb, $timeToDb, $courseSlotId)) {
				$this->setMessage('Это время занято другим курсом', 'error');
				$this->setRedirectAndExit();
				return;
			}
			if (self::hasSearchSlotsOverlap($db, $masterId, $timeDb, $timeToDb, 0)) {
				$this->setMessage('Это время занято поиском', 'error');
				$this->setRedirectAndExit();
				return;
			}
			if (self::hasBookingsOverlap($db, $tableName, $masterId, $timeDb, $timeToDb, 0, $excludeIds)) {
				$this->setMessage('Это время уже занято', 'error');
				$this->setRedirectAndExit();
				return;
			}

			if ($this->proposeGroupMove($db, GroupRescheduleHelper::KIND_COURSE, $courseSlotId, (int) ($courseContext['course_id'] ?? 0), $masterId, $courseContext, $timeDb, $timeToDb)) {
				return;
			}

			$dispatcher = Factory::getContainer()->get(\Joomla\Event\DispatcherInterface::class);
			$table = new OrderTable($db, $dispatcher);
			foreach ($excludeIds as $bookingId) {
				if (!$table->load((int) $bookingId)) {
					continue;
				}
				if ((int) $table->master_id !== $masterId) {
					continue;
				}
				$table->time = $timeDb;
				$table->time_to = $timeToDb;
				if (!$table->store()) {
					$this->setMessage($table->getError() ?: 'Ошибка переноса курса', 'error');
					$this->setRedirectAndExit();
					return;
				}
			}

			self::updateCourseSlotTime($db, $courseSlotId, $timeDb, $timeToDb);
		} finally {
			if ($lockHeld) {
				OrderTable::releaseMasterBookingLock($db, $masterId);
			}
		}
		$this->setMessage('Курс перенесён для всех участников');
		$this->setRedirectAndExit();
	}

	public function cancelSearchSlotByMaster()
	{
		Session::checkToken('request') or $this->setRedirectAndExit();
		$user = Factory::getApplication()->getIdentity();
		if (!$user->id) {
			$this->setMessage('Нужна авторизация', 'error');
			$this->setRedirectAndExit();
			return;
		}
		if ($this->denyIfBookingFlagOff('booking_cancel')) {
			return;
		}

		$searchSlotId = (int) $this->input->get('search_slot_id', 0);
		if ($searchSlotId <= 0) {
			$this->setMessage('Неверный идентификатор слота поиска', 'error');
			$this->setRedirectAndExit();
			return;
		}

		$db = Factory::getContainer()->get(\Joomla\Database\DatabaseInterface::class);
		$searchContext = self::loadSearchSlotContext($db, $searchSlotId);
		if ($searchContext === null) {
			$this->setMessage('Слот поиска не найден', 'error');
			$this->setRedirectAndExit();
			return;
		}
		if ((int) $searchContext['master_id'] !== (int) $user->id) {
			$this->setMessage('Нет прав на отмену этого поиска', 'error');
			$this->setRedirectAndExit();
			return;
		}

		$bookingIds = self::getSearchSlotBookingIds($db, $searchSlotId, (int) $user->id);
		if ($bookingIds === []) {
			$this->setMessage('В слоте нет активных записей', 'error');
			$this->setRedirectAndExit();
			return;
		}

		$table = new OrderTable(
			Factory::getContainer()->get(\Joomla\Database\DatabaseInterface::class),
			Factory::getContainer()->get(\Joomla\Event\DispatcherInterface::class)
		);
		foreach ($bookingIds as $bookingId) {
			if (!$table->load((int) $bookingId)) {
				continue;
			}
			if ((int) $table->master_id !== (int) $user->id) {
				continue;
			}
			if (!$table->delete((int) $bookingId)) {
				$this->setMessage($table->getError() ?: 'Ошибка отмены записей поиска', 'error');
				$this->setRedirectAndExit();
				return;
			}
		}

		self::deactivateSearchSlot($db, $searchSlotId);
		$this->setMessage('Поиск моделей отменён для всех участников');
		$this->setRedirectAndExit();
	}

	public function rescheduleSearchSlotByMaster()
	{
		Session::checkToken('request') or $this->setRedirectAndExit();
		$user = Factory::getApplication()->getIdentity();
		if (!$user->id) {
			$this->setMessage('Нужна авторизация', 'error');
			$this->setRedirectAndExit();
			return;
		}
		if ($this->denyIfBookingFlagOff('booking_reschedule')) {
			return;
		}

		$searchSlotId = (int) $this->input->get('search_slot_id', 0);
		$timeUtcStr = trim((string) $this->input->get('time_utc', '', 'string'));
		$timeStr = trim((string) $this->input->get('time', '', 'string'));
		if ($searchSlotId <= 0) {
			$this->setMessage('Укажите слот поиска', 'error');
			$this->setRedirectAndExit();
			return;
		}

		$db = Factory::getContainer()->get(\Joomla\Database\DatabaseInterface::class);
		$searchContext = self::loadSearchSlotContext($db, $searchSlotId);
		if ($searchContext === null) {
			$this->setMessage('Слот поиска не найден', 'error');
			$this->setRedirectAndExit();
			return;
		}
		if ((int) $searchContext['master_id'] !== (int) $user->id) {
			$this->setMessage('Нет прав на перенос этого поиска', 'error');
			$this->setRedirectAndExit();
			return;
		}

		$masterTimezone = self::getUserTimezoneById((int) $user->id, (string) Factory::getApplication()->get('offset', 'UTC'));
		$time = self::parseRescheduleDateTime($timeUtcStr, $timeStr, $masterTimezone);
		if (!$time) {
			$this->setMessage('Укажите новое дату/время', 'error');
			$this->setRedirectAndExit();
			return;
		}
		$utc = new \DateTimeZone('UTC');

		$durationMin = (int) ($searchContext['duration_min'] ?? 0);
		if ($durationMin <= 0) {
			$durationMin = (int) $this->input->get('duration_min', 0);
		}
		$durationMin = max(15, min(480, $durationMin > 0 ? $durationMin : 60));

		$timeTo = clone $time;
		$timeTo->modify('+' . $durationMin . ' minutes');
		$timeDb = $time->format('Y-m-d H:i:s');
		$timeToDb = $timeTo->format('Y-m-d H:i:s');

		try {
			$currentTime = new \DateTime((string) $searchContext['starts_at_utc'], $utc);
			$now = new \DateTime('now', $utc);
			if ($currentTime < $now) {
				$this->setMessage('Нельзя перенести прошедший поиск', 'error');
				$this->setRedirectAndExit();
				return;
			}
		} catch (\Throwable $e) {
		}

		$startUtc = \DateTimeImmutable::createFromMutable($time);
		$endUtc = \DateTimeImmutable::createFromMutable($timeTo);
		$nowUtc = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
		if ($startUtc <= $nowUtc) {
			$this->setMessage('Нельзя перенести поиск на прошедшее время', 'error');
			$this->setRedirectAndExit();
			return;
		}

		$scheduleCheck = self::validateMasterSchedule((int) $user->id, $startUtc, $endUtc, $masterTimezone);
		if (!$scheduleCheck['ok']) {
			$this->setMessage((string) ($scheduleCheck['message'] ?? 'Выбранное время вне рабочего графика мастера'), 'error');
			$this->setRedirectAndExit();
			return;
		}

		$tableName = $db->getPrefix() . 'vigling_bookings';
		$excludeIds = self::getSearchSlotBookingIds($db, $searchSlotId, (int) $user->id);
		$masterId = (int) $user->id;
		$lockHeld = false;
		try {
			if (!OrderTable::acquireMasterBookingLock($db, $masterId)) {
				$this->setMessage('Сейчас идёт другая запись к этому специалисту, попробуйте ещё раз', 'error');
				$this->setRedirectAndExit();
				return;
			}
			$lockHeld = true;
			if (self::hasSearchSlotsOverlap($db, $masterId, $timeDb, $timeToDb, $searchSlotId)) {
				$this->setMessage('Это время занято другим поиском', 'error');
				$this->setRedirectAndExit();
				return;
			}
			if (self::hasCourseSlotsOverlap($db, $masterId, $timeDb, $timeToDb, 0)) {
				$this->setMessage('Это время занято курсом', 'error');
				$this->setRedirectAndExit();
				return;
			}
			if (self::hasBookingsOverlap($db, $tableName, $masterId, $timeDb, $timeToDb, 0, $excludeIds)) {
				$this->setMessage('Это время уже занято', 'error');
				$this->setRedirectAndExit();
				return;
			}

			if ($this->proposeGroupMove($db, GroupRescheduleHelper::KIND_SEARCH, $searchSlotId, (int) ($searchContext['search_id'] ?? 0), $masterId, $searchContext, $timeDb, $timeToDb)) {
				return;
			}

			$dispatcher = Factory::getContainer()->get(\Joomla\Event\DispatcherInterface::class);
			$table = new OrderTable($db, $dispatcher);
			foreach ($excludeIds as $bookingId) {
				if (!$table->load((int) $bookingId)) {
					continue;
				}
				if ((int) $table->master_id !== $masterId) {
					continue;
				}
				$table->time = $timeDb;
				$table->time_to = $timeToDb;
				if (!$table->store()) {
					$this->setMessage($table->getError() ?: 'Ошибка переноса поиска', 'error');
					$this->setRedirectAndExit();
					return;
				}
			}

			self::updateSearchSlotTime($db, $searchSlotId, $timeDb, $timeToDb);
		} finally {
			if ($lockHeld) {
				OrderTable::releaseMasterBookingLock($db, $masterId);
			}
		}
		$this->setMessage('Поиск моделей перенесён для всех участников');
		$this->setRedirectAndExit();
	}

	/**
	 * A group slot with participants is not moved straight away: every participant is asked first.
	 * Returns true when a request was created (the response is already redirected).
	 *
	 * @param array<string,mixed> $context
	 */
	private function proposeGroupMove(
		\Joomla\Database\DatabaseInterface $db,
		string $kind,
		int $slotId,
		int $entityId,
		int $masterId,
		array $context,
		string $newStartDb,
		string $newEndDb
	): bool {
		if (!GroupRescheduleHelper::isAvailable($db)) {
			return false;
		}
		$participants = GroupRescheduleHelper::participants($db, $kind, $slotId, $masterId);
		if ($participants === []) {
			return false;
		}
		$title = GroupRescheduleHelper::entityTitle($db, $kind, $entityId);
		$result = GroupRescheduleHelper::createProposal(
			$db,
			$kind,
			$slotId,
			$entityId,
			$masterId,
			$title,
			(string) ($context['starts_at_utc'] ?? ''),
			(string) ($context['ends_at_utc'] ?? ''),
			$newStartDb,
			$newEndDb,
			$participants
		);
		if (!$result['ok']) {
			$this->setMessage($result['message'], 'error');
			$this->setRedirectAndExit();

			return true;
		}
		$this->setMessage(
			'Предложение о переносе отправлено участникам (' . (int) $result['count'] . '). '
			. 'Время изменится только после того, как вы подтвердите перенос в карточке: согласившиеся будут перенесены, '
			. 'не ответившие — сняты. Подтвердить можно, не дожидаясь всех ответов.'
		);
		$this->setRedirectAndExit();

		return true;
	}

	public function respondGroupReschedule()
	{
		Session::checkToken('request') or $this->setRedirectAndExit();
		$user = Factory::getApplication()->getIdentity();
		if (!$user->id) {
			$this->setMessage('Нужна авторизация', 'error');
			$this->setRedirectAndExit();
			return;
		}
		if ($this->denyIfBookingFlagOff('booking_reschedule')) {
			return;
		}
		$replyId = (int) $this->input->get('reply_id', 0);
		$decision = (string) $this->input->get('decision', '', 'cmd');
		if ($replyId <= 0 || !in_array($decision, ['agree', 'decline'], true)) {
			$this->setMessage('Выберите ответ: согласиться на перенос или отказаться от записи', 'error');
			$this->setRedirectAndExit();
			return;
		}
		$db = Factory::getContainer()->get(\Joomla\Database\DatabaseInterface::class);
		if (!GroupRescheduleHelper::isAvailable($db)) {
			$this->setMessage('Запрос не найден', 'error');
			$this->setRedirectAndExit();
			return;
		}
		$reply = GroupRescheduleHelper::loadReplyForUser($db, $replyId, (int) $user->id);
		if ($reply === null) {
			$this->setMessage('Запрос не найден', 'error');
			$this->setRedirectAndExit();
			return;
		}
		$proposalId = (int) $reply['proposal_id'];
		if ((string) $reply['proposal_status'] !== 'open' || (string) $reply['reply_status'] !== 'pending') {
			$this->setMessage('Этот запрос уже закрыт', 'error');
			$this->setRedirectAndExit();
			return;
		}
		$utc = new \DateTimeZone('UTC');
		$now = new \DateTimeImmutable('now', $utc);
		$oldStart = new \DateTimeImmutable((string) $reply['old_start_utc'], $utc);
		$newStart = new \DateTimeImmutable((string) $reply['new_start_utc'], $utc);
		if ($oldStart <= $now || $newStart <= $now) {
			GroupRescheduleHelper::closeProposal($db, $proposalId, 'cancelled');
			$this->setMessage('Время переноса уже прошло, запрос закрыт', 'error');
			$this->setRedirectAndExit();
			return;
		}

		$dispatcher = Factory::getContainer()->get(\Joomla\Event\DispatcherInterface::class);
		$table = new OrderTable($db, $dispatcher);
		$bookingId = (int) $reply['booking_id'];
		if (!$table->load($bookingId) || (int) $table->user_id !== (int) $user->id) {
			$this->setMessage('Запись не найдена', 'error');
			$this->setRedirectAndExit();
			return;
		}
		$masterId = (int) $reply['master_id'];
		$title = (string) $reply['title'];
		$label = GroupRescheduleHelper::kindLabel((string) $reply['kind']);
		$userName = GroupRescheduleHelper::userName((int) $user->id);
		$userName = $userName !== '' ? $userName : 'Участник';
		$newStartDb = (string) $reply['new_start_utc'];
		$newEndDb = (string) $reply['new_end_utc'];

		if ($decision === 'agree') {
			$tableName = $db->getPrefix() . 'vigling_bookings';
			if (self::hasUserBookingOverlap($db, $tableName, (int) $user->id, $masterId, $newStartDb, $newEndDb, $bookingId)) {
				$this->setMessage('На новое время у вас уже есть запись к другому специалисту. Согласиться нельзя — можно только отказаться от записи.', 'error');
				$this->setRedirectAndExit();
				return;
			}
			GroupRescheduleHelper::setReplyStatus($db, $replyId, 'agreed');
			$masterText = $userName . ' согласен(на) на перенос: ' . $label . ' «' . $title . '».';
			$masterTitle = 'Участник согласился на перенос';
			$message = 'Вы согласились на перенос. Новое время вступит в силу, когда мастер подтвердит перенос.';
		} else {
			OrderTable::silenceNotifications(true);
			try {
				$deleted = $table->delete($bookingId);
			} finally {
				OrderTable::silenceNotifications(false);
			}
			if (!$deleted) {
				$this->setMessage($table->getError() ?: 'Не удалось отказаться от записи', 'error');
				$this->setRedirectAndExit();
				return;
			}
			GroupRescheduleHelper::setReplyStatus($db, $replyId, 'declined');
			$masterText = $userName . ' отказался(ась) от записи: ' . $label . ' «' . $title . '». Запись снята.';
			$masterTitle = 'Участник отказался от записи';
			$message = 'Вы отказались от записи, она снята.';
		}

		GroupRescheduleHelper::loadNotices();
		\Viglin\Component\Pushnotify\Site\Helper\CriticalNoticeHelper::resolveByRef(GroupRescheduleHelper::REF_PARTICIPANT, $proposalId, (int) $user->id);
		\Viglin\Component\Pushnotify\Site\Helper\CriticalNoticeHelper::notifyInfo($masterId, 'group_reschedule_reply', $masterTitle, $masterText);

		$this->settleGroupProposal($db, $proposalId, true);

		$this->setMessage($message);
		$this->setRedirectAndExit();
	}

	/**
	 * A participant's booking disappeared while a request was open (cancelled by the person or by the master).
	 * The standard cancellation push already tells the other side, so only the request itself is settled here.
	 *
	 * @param array<string,mixed> $openReply
	 */
	private function afterGroupBookingRemoved(\Joomla\Database\DatabaseInterface $db, array $openReply): void
	{
		$proposalId = (int) $openReply['proposal_id'];
		GroupRescheduleHelper::loadNotices();
		\Viglin\Component\Pushnotify\Site\Helper\CriticalNoticeHelper::resolveByRef(GroupRescheduleHelper::REF_PARTICIPANT, $proposalId, (int) $openReply['user_id']);
		$this->settleGroupProposal($db, $proposalId, (string) $openReply['reply_status'] === 'pending');
	}

	/**
	 * Nobody left to ask: apply the new time. Everybody answered: ask the master to confirm.
	 */
	private function settleGroupProposal(\Joomla\Database\DatabaseInterface $db, int $proposalId, bool $answerWasPending): void
	{
		$proposal = GroupRescheduleHelper::loadProposal($db, $proposalId);
		if ($proposal === null || (string) $proposal['status'] !== 'open') {
			return;
		}
		$masterId = (int) $proposal['master_id'];
		$title = (string) $proposal['title'];
		$label = GroupRescheduleHelper::kindLabel((string) $proposal['kind']);
		$newStartDb = (string) $proposal['new_start_utc'];
		$notice = '\\Viglin\\Component\\Pushnotify\\Site\\Helper\\CriticalNoticeHelper';
		$url = \Viglin\Component\Orders\Site\Helper\AppointmentsHelper::profileUrl(['zapisi' => 'list']);

		$pending = 0;
		$liveReplies = GroupRescheduleHelper::replies($db, $proposalId);
		foreach ($liveReplies as $other) {
			if ((string) $other['status'] === 'pending') {
				$pending++;
			}
		}
		if ($liveReplies === []) {
			$labelCap = mb_strtoupper(mb_substr($label, 0, 1)) . mb_substr($label, 1);
			$applied = $this->runGroupApply($db, $proposal, $masterId);
			if (!$applied['ok']) {
				GroupRescheduleHelper::closeProposal($db, $proposalId, 'cancelled');
			}
			$notice::add(
				$masterId,
				'group_reschedule_result',
				'Участников не осталось',
				$applied['ok']
					? $labelCap . ' «' . $title . '»: участников не осталось, время изменено на ' . $notice::formatTime($masterId, $newStartDb) . '.'
					: $labelCap . ' «' . $title . '»: участников не осталось, но новое время применить не удалось (' . $applied['message'] . '). Время осталось прежним.',
				$url
			);
		} elseif ($pending === 0 && $answerWasPending) {
			$notice::add(
				$masterId,
				'group_reschedule_ready',
				'Все участники ответили на перенос',
				'Ответили все участники: ' . $label . ' «' . $title . '». Подтвердите перенос, чтобы согласившиеся были перенесены на новое время.',
				$url,
				true,
				GroupRescheduleHelper::REF_MASTER,
				$proposalId
			);
		}
	}

	public function cancelGroupReschedule()
	{
		Session::checkToken('request') or $this->setRedirectAndExit();
		$user = Factory::getApplication()->getIdentity();
		if (!$user->id) {
			$this->setMessage('Нужна авторизация', 'error');
			$this->setRedirectAndExit();
			return;
		}
		if ($this->denyIfBookingFlagOff('booking_reschedule')) {
			return;
		}
		$db = Factory::getContainer()->get(\Joomla\Database\DatabaseInterface::class);
		$proposal = GroupRescheduleHelper::isAvailable($db)
			? GroupRescheduleHelper::loadProposal($db, (int) $this->input->get('proposal_id', 0))
			: null;
		if ($proposal === null || (int) $proposal['master_id'] !== (int) $user->id || (string) $proposal['status'] !== 'open') {
			$this->setMessage('Предложение о переносе не найдено', 'error');
			$this->setRedirectAndExit();
			return;
		}
		$proposalId = (int) $proposal['id'];
		$replies = GroupRescheduleHelper::replies($db, $proposalId);
		GroupRescheduleHelper::closeProposal($db, $proposalId, 'cancelled');
		$label = GroupRescheduleHelper::kindLabel((string) $proposal['kind']);
		foreach ($replies as $reply) {
			if ((string) $reply['status'] === 'declined') {
				continue;
			}
			\Viglin\Component\Pushnotify\Site\Helper\CriticalNoticeHelper::add(
				(int) $reply['user_id'],
				'group_reschedule_cancelled',
				'Перенос отменён',
				'Мастер отменил перенос: ' . $label . ' «' . $proposal['title'] . '». Ваша запись остаётся на прежнее время.',
				\Viglin\Component\Orders\Site\Helper\AppointmentsHelper::profileUrl(['zapisi' => 'list'])
			);
		}
		$this->setMessage('Предложение о переносе отменено');
		$this->setRedirectAndExit();
	}

	public function applyGroupReschedule()
	{
		Session::checkToken('request') or $this->setRedirectAndExit();
		$user = Factory::getApplication()->getIdentity();
		if (!$user->id) {
			$this->setMessage('Нужна авторизация', 'error');
			$this->setRedirectAndExit();
			return;
		}
		if ($this->denyIfBookingFlagOff('booking_reschedule')) {
			return;
		}
		$db = Factory::getContainer()->get(\Joomla\Database\DatabaseInterface::class);
		$proposal = GroupRescheduleHelper::isAvailable($db)
			? GroupRescheduleHelper::loadProposal($db, (int) $this->input->get('proposal_id', 0))
			: null;
		$masterId = (int) $user->id;
		if ($proposal === null || (int) $proposal['master_id'] !== $masterId || (string) $proposal['status'] !== 'open') {
			$this->setMessage('Предложение о переносе не найдено или уже закрыто', 'error');
			$this->setRedirectAndExit();
			return;
		}
		$result = $this->runGroupApply($db, $proposal, $masterId);
		$this->setMessage($result['message'], $result['ok'] ? 'message' : 'error');
		$this->setRedirectAndExit();
	}

	/**
	 * Moves the slot and everyone who agreed to the proposed time; the rest are removed.
	 * Used by the master's confirmation and when the last participant leaves.
	 *
	 * @param array<string,mixed> $proposal
	 * @return array{ok:bool,message:string,moved:int,removed:int}
	 */
	private function runGroupApply(\Joomla\Database\DatabaseInterface $db, array $proposal, int $masterId): array
	{
		$proposalId = (int) $proposal['id'];
		$kind = (string) $proposal['kind'];
		$slotId = (int) $proposal['slot_id'];
		$isCourse = $kind === GroupRescheduleHelper::KIND_COURSE;
		$label = GroupRescheduleHelper::kindLabel($kind);
		$title = (string) $proposal['title'];

		$context = $isCourse ? self::loadCourseSlotContext($db, $slotId) : self::loadSearchSlotContext($db, $slotId);
		if ($context === null || (int) ($context['is_active'] ?? 0) !== 1 || (int) $context['master_id'] !== $masterId) {
			GroupRescheduleHelper::closeProposal($db, $proposalId, 'cancelled');
			return self::groupApplyResult(false, 'Слот больше не существует, предложение закрыто');
		}

		$utc = new \DateTimeZone('UTC');
		$nowUtc = new \DateTimeImmutable('now', $utc);
		$newStartDb = (string) $proposal['new_start_utc'];
		$newEndDb = (string) $proposal['new_end_utc'];
		$startUtc = new \DateTimeImmutable($newStartDb, $utc);
		$endUtc = new \DateTimeImmutable($newEndDb, $utc);
		$currentStart = new \DateTimeImmutable((string) $context['starts_at_utc'], $utc);
		if ($startUtc <= $nowUtc || $currentStart <= $nowUtc) {
			$replies = GroupRescheduleHelper::replies($db, $proposalId);
			GroupRescheduleHelper::closeProposal($db, $proposalId, 'cancelled');
			foreach ($replies as $reply) {
				if ((string) $reply['status'] === 'declined') {
					continue;
				}
				\Viglin\Component\Pushnotify\Site\Helper\CriticalNoticeHelper::add(
					(int) $reply['user_id'],
					'group_reschedule_cancelled',
					'Перенос отменён',
					'Время истекло, перенос не состоялся: ' . $label . ' «' . $title . '». Ваша запись остаётся без изменений.',
					\Viglin\Component\Orders\Site\Helper\AppointmentsHelper::profileUrl(['zapisi' => 'list'])
				);
			}
			return self::groupApplyResult(false, 'Время уже прошло, предложение о переносе закрыто');
		}

		$masterTimezone = self::getUserTimezoneById($masterId, (string) Factory::getApplication()->get('offset', 'UTC'));
		$scheduleCheck = self::validateMasterSchedule($masterId, $startUtc, $endUtc, $masterTimezone);
		if (!$scheduleCheck['ok']) {
			return self::groupApplyResult(false, (string) ($scheduleCheck['message'] ?? 'Выбранное время вне рабочего графика мастера'));
		}

		$tableName = $db->getPrefix() . 'vigling_bookings';
		$slotBookingIds = $isCourse
			? self::getCourseSlotBookingIds($db, $slotId, $masterId)
			: self::getSearchSlotBookingIds($db, $slotId, $masterId);
		$replyByBooking = [];
		foreach (GroupRescheduleHelper::replies($db, $proposalId) as $reply) {
			$replyByBooking[(int) $reply['booking_id']] = $reply;
		}

		$moved = 0;
		$movedUserIds = [];
		$removed = [];
		$lockHeld = false;
		$transactionStarted = false;
		try {
			if (!OrderTable::acquireMasterBookingLock($db, $masterId)) {
				return self::groupApplyResult(false, 'Сейчас идёт другая запись к этому специалисту, попробуйте ещё раз');
			}
			$lockHeld = true;
			$participantUserIds = [];
			foreach ($replyByBooking as $participantReply) {
				$participantUserIds[] = (int) $participantReply['user_id'];
			}
			if (!OrderTable::acquireUserBookingLocks($db, $participantUserIds)) {
				return self::groupApplyResult(false, 'Сейчас идёт другая запись участника, попробуйте ещё раз');
			}
			if (self::hasCourseSlotsOverlap($db, $masterId, $newStartDb, $newEndDb, $isCourse ? $slotId : 0)) {
				return self::groupApplyResult(false, $isCourse ? 'Это время занято другим курсом' : 'Это время занято курсом');
			}
			if (self::hasSearchSlotsOverlap($db, $masterId, $newStartDb, $newEndDb, $isCourse ? 0 : $slotId)) {
				return self::groupApplyResult(false, $isCourse ? 'Это время занято поиском' : 'Это время занято другим поиском');
			}
			if (self::hasBookingsOverlap($db, $tableName, $masterId, $newStartDb, $newEndDb, 0, $slotBookingIds)) {
				return self::groupApplyResult(false, 'Это время уже занято');
			}

			$dispatcher = Factory::getContainer()->get(\Joomla\Event\DispatcherInterface::class);
			$db->transactionStart();
			$transactionStarted = true;
			OrderTable::silenceNotifications(true);
			foreach ($slotBookingIds as $bookingId) {
				$table = new OrderTable($db, $dispatcher);
				if (!$table->load((int) $bookingId) || (int) $table->master_id !== $masterId) {
					continue;
				}
				$participantId = (int) $table->user_id;
				$reply = $replyByBooking[(int) $bookingId] ?? null;
				$agreed = $reply !== null && (string) $reply['status'] === 'agreed';
				$ownBooking = $participantId <= 0 || $participantId === $masterId;
				if ($agreed || $ownBooking) {
					if (!$ownBooking && self::hasUserBookingOverlap($db, $tableName, $participantId, $masterId, $newStartDb, $newEndDb, (int) $bookingId)) {
						$table->delete((int) $bookingId);
						$removed[] = [$participantId, 'на новое время у вас уже есть запись к другому специалисту'];
						if ($reply !== null) {
							GroupRescheduleHelper::setReplyStatus($db, (int) $reply['id'], 'removed');
						}
						continue;
					}
					$table->time = $newStartDb;
					$table->time_to = $newEndDb;
					if (!$table->store()) {
						throw new \RuntimeException($table->getError() ?: 'Ошибка переноса записи');
					}
					$moved++;
					if (!$ownBooking) {
						$movedUserIds[] = $participantId;
					}
					continue;
				}
				$table->delete((int) $bookingId);
				$removed[] = [$participantId, 'вы не ответили на запрос о переносе'];
				if ($reply !== null) {
					GroupRescheduleHelper::setReplyStatus($db, (int) $reply['id'], 'removed');
				}
			}

			if ($isCourse) {
				self::updateCourseSlotTime($db, $slotId, $newStartDb, $newEndDb);
			} else {
				self::updateSearchSlotTime($db, $slotId, $newStartDb, $newEndDb);
			}
			GroupRescheduleHelper::closeProposal($db, $proposalId, 'applied');
			$db->transactionCommit();
			$transactionStarted = false;
		} catch (\Throwable $e) {
			if ($transactionStarted) {
				try {
					$db->transactionRollback();
				} catch (\Throwable $rollbackError) {
				}
			}
			return self::groupApplyResult(false, 'Не удалось применить перенос, ничего не изменено. Попробуйте ещё раз.');
		} finally {
			OrderTable::silenceNotifications(false);
			if ($lockHeld) {
				OrderTable::releaseMasterBookingLock($db, $masterId);
			}
		}

		$labelCap = mb_strtoupper(mb_substr($label, 0, 1)) . mb_substr($label, 1);
		foreach (array_unique($movedUserIds) as $movedUserId) {
			\Viglin\Component\Pushnotify\Site\Helper\CriticalNoticeHelper::notifyInfo(
				(int) $movedUserId,
				'group_reschedule_applied',
				'Перенос подтверждён',
				$labelCap . ' «' . $title . '» перенесён на '
					. \Viglin\Component\Pushnotify\Site\Helper\CriticalNoticeHelper::formatTime((int) $movedUserId, $newStartDb)
					. '. Ваша запись сохранена.'
			);
		}
		foreach ($removed as [$removedUserId, $reason]) {
			if ($removedUserId <= 0 || $removedUserId === $masterId) {
				continue;
			}
			\Viglin\Component\Pushnotify\Site\Helper\CriticalNoticeHelper::add(
				$removedUserId,
				'group_reschedule_removed',
				'Вы сняты с записи',
				mb_strtoupper(mb_substr($label, 0, 1)) . mb_substr($label, 1) . ' «' . $title . '» перенесён на '
					. \Viglin\Component\Pushnotify\Site\Helper\CriticalNoticeHelper::formatTime($removedUserId, $newStartDb)
					. ', но ' . $reason . '. Ваша запись снята; при желании запишитесь заново.',
				\Viglin\Component\Orders\Site\Helper\AppointmentsHelper::profileUrl(['zapisi' => 'list'])
			);
		}

		return self::groupApplyResult(true, 'Перенос подтверждён. Перенесено участников: ' . $moved . ', снято с записи: ' . count($removed) . '.', $moved, count($removed));
	}

	/**
	 * @return array{ok:bool,message:string,moved:int,removed:int}
	 */
	private static function groupApplyResult(bool $ok, string $message, int $moved = 0, int $removed = 0): array
	{
		return ['ok' => $ok, 'message' => $message, 'moved' => $moved, 'removed' => $removed];
	}

	public function completeByMaster()
	{
		Session::checkToken('request') or $this->setRedirectAndExit();
		$user = Factory::getApplication()->getIdentity();
		if (!$user->id) {
			$this->setMessage('Нужна авторизация', 'error');
			$this->setRedirectAndExit();
			return;
		}
		if ($this->denyIfBookingFlagOff('booking_complete')) {
			return;
		}
		$id = (int) $this->input->get('id', 0);
		if ($id <= 0) {
			$this->setMessage('Неверный идентификатор записи', 'error');
			$this->setRedirectAndExit();
			return;
		}
		$table = new OrderTable(
			Factory::getContainer()->get(\Joomla\Database\DatabaseInterface::class),
			Factory::getContainer()->get(\Joomla\Event\DispatcherInterface::class)
		);
		if (!$table->load($id)) {
			$this->setMessage('Запись не найдена', 'error');
			$this->setRedirectAndExit();
			return;
		}
		if ((int) $table->master_id !== (int) $user->id) {
			$this->setMessage('Нет прав', 'error');
			$this->setRedirectAndExit();
			return;
		}
		$utc = new \DateTimeZone('UTC');
		$now = new \DateTime('now', $utc);
		try {
			$orderTime = new \DateTime((string) $table->time, $utc);
			if ($orderTime >= $now) {
				$this->setMessage('Отметить выполненной можно только прошедшую запись', 'error');
				$this->setRedirectAndExit();
				return;
			}
		} catch (\Throwable $e) {
			$this->setRedirectAndExit();
			return;
		}
		$db = Factory::getContainer()->get(\Joomla\Database\DatabaseInterface::class);
		$tbl = $db->getPrefix() . 'vigling_bookings';
		$db->setQuery(
			'UPDATE ' . $db->quoteName($tbl) . ' SET ' . $db->quoteName('completed') . ' = 1 WHERE ' . $db->quoteName('id') . ' = ' . (int) $id
		)->execute();
		self::guardRecord('completed', $table);
		$this->setMessage('Запись отмечена как выполненная');
		$this->setRedirectAndExit();
	}

	public function review()
	{
		Session::checkToken('request') or $this->setRedirectAndExit();
		$user = Factory::getApplication()->getIdentity();
		if (!$user->id) {
			$this->setMessage('Нужна авторизация', 'error');
			$this->setRedirectAndExit();
			return;
		}
		$id = (int) $this->input->get('id', 0);
		$rating = (int) $this->input->get('rating', 0);
		$reviewText = (string) $this->input->get('review_text', '', 'string');
		$anonymous = (int) $this->input->get('anonymous', 0) === 1;
		if ($id <= 0) {
			$this->setMessage('Неверный идентификатор записи', 'error');
			$this->setRedirectAndExit();
			return;
		}
		$db = Factory::getContainer()->get(\Joomla\Database\DatabaseInterface::class);
		$dispatcher = Factory::getContainer()->get(\Joomla\Event\DispatcherInterface::class);
		$table = new OrderTable($db, $dispatcher);
		if (!$table->load($id)) {
			$this->setMessage('Запись не найдена', 'error');
			$this->setRedirectAndExit();
			return;
		}
		$direction = '';
		if ((int) $table->user_id === (int) $user->id) {
			$direction = ReviewHelper::DIRECTION_CLIENT_TO_MASTER;
		} elseif ((int) $table->master_id === (int) $user->id) {
			$direction = ReviewHelper::DIRECTION_MASTER_TO_CLIENT;
		} else {
			$this->setMessage('Нет прав на этот отзыв', 'error');
			$this->setRedirectAndExit();
			return;
		}
		$result = ReviewHelper::saveReview($db, $table, (int) $user->id, $direction, $rating, $reviewText, $anonymous);
		$this->setMessage($result['message'], !empty($result['ok']) ? 'message' : 'error');
		$this->setRedirectAndExit();
	}

	public function commentAfter()
	{
		Session::checkToken('request') or $this->setRedirectAndExit();
		$user = Factory::getApplication()->getIdentity();
		if (!$user->id) {
			$this->setMessage('Нужна авторизация', 'error');
			$this->setRedirectAndExit();
			return;
		}
		$id = (int) $this->input->get('id', 0);
		$comment = (string) $this->input->get('after_comment', '', 'string');
		if ($id <= 0) {
			$this->setMessage('Неверный идентификатор записи', 'error');
			$this->setRedirectAndExit();
			return;
		}
		$db = Factory::getContainer()->get(\Joomla\Database\DatabaseInterface::class);
		$dispatcher = Factory::getContainer()->get(\Joomla\Event\DispatcherInterface::class);
		$table = new OrderTable($db, $dispatcher);
		if (!$table->load($id)) {
			$this->setMessage('Запись не найдена', 'error');
			$this->setRedirectAndExit();
			return;
		}
		$role = '';
		if ((int) $table->user_id === (int) $user->id) {
			$role = 'client';
		} elseif ((int) $table->master_id === (int) $user->id) {
			$role = 'master';
		} else {
			$this->setMessage('Нет прав на этот комментарий', 'error');
			$this->setRedirectAndExit();
			return;
		}
		$result = ReviewHelper::saveAfterComment($db, $table, (int) $user->id, $role, $comment);
		$this->setMessage($result['message'], !empty($result['ok']) ? 'message' : 'error');
		$this->setRedirectAndExit();
	}

	public function deleteByMaster()
	{
		Session::checkToken('request') or $this->setRedirectAndExit();
		$user = Factory::getApplication()->getIdentity();
		if (!$user->id) {
			$this->setMessage('Нужна авторизация', 'error');
			$this->setRedirectAndExit();
			return;
		}
		if ($this->denyIfBookingFlagOff('booking_cancel')) {
			return;
		}
		$id = (int) $this->input->get('id', 0);
		if ($id <= 0) {
			$this->setMessage('Неверный идентификатор записи', 'error');
			$this->setRedirectAndExit();
			return;
		}
		$table = new OrderTable(
			Factory::getContainer()->get(\Joomla\Database\DatabaseInterface::class),
			Factory::getContainer()->get(\Joomla\Event\DispatcherInterface::class)
		);
		if (!$table->load($id)) {
			$this->setMessage('Запись не найдена', 'error');
			$this->setRedirectAndExit();
			return;
		}
		if ((int) $table->master_id !== (int) $user->id) {
			$this->setMessage('Нет прав на удаление этой записи', 'error');
			$this->setRedirectAndExit();
			return;
		}
		$completed = isset($table->completed) ? (int) $table->completed : 0;
		if ($completed !== 1) {
			$this->setMessage('Удалить можно только выполненную запись. Сначала отметьте «Запись выполнена».', 'error');
			$this->setRedirectAndExit();
			return;
		}
		$db = Factory::getContainer()->get(\Joomla\Database\DatabaseInterface::class);
		$tbl = $db->getPrefix() . 'vigling_bookings';
		$db->setQuery('DELETE FROM ' . $db->quoteName($tbl) . ' WHERE ' . $db->quoteName('id') . ' = ' . (int) $id)->execute();
		self::guardRecord('removed', $table);
		$this->setMessage('Запись удалена');
		$this->setRedirectAndExit();
	}

	public function journalAdd()
	{
		Session::checkToken('request') or $this->setRedirectAndExit();
		$user = Factory::getApplication()->getIdentity();
		if (!$user->id || !self::isMasterUser($user)) {
			$this->setMessage('Доступ только для мастеров', 'error');
			$this->setRedirectAndExit();
			return;
		}
		if ($this->denyIfBookingFlagOff('booking_journal')) {
			return;
		}

		$startUtcStr = trim((string) $this->input->get('time_utc', '', 'string'));
		$endUtcStr = trim((string) $this->input->get('time_to_utc', '', 'string'));
		$commentRaw = trim((string) $this->input->get('comment', '', 'string'));
		$start = self::parseUtcDateTime($startUtcStr);
		$end = self::parseUtcDateTime($endUtcStr);
		if (!$start || !$end) {
			$this->setMessage('Выберите временные слоты, которые хотите заблокировать', 'error');
			$this->setRedirectAndExit();
			return;
		}

		$utc = new \DateTimeZone('UTC');
		$startUtc = $start;
		$endUtc = $end;
		if ($endUtc <= $startUtc) {
			$this->setMessage('Выберите временные слоты, которые хотите заблокировать', 'error');
			$this->setRedirectAndExit();
			return;
		}
		$durationMin = (int) round(($endUtc->getTimestamp() - $startUtc->getTimestamp()) / 60);
		if ($durationMin < 15 || $durationMin > 720) {
			$this->setMessage('Выберите непрерывный интервал не длиннее 12 часов', 'error');
			$this->setRedirectAndExit();
			return;
		}
		$nowUtc = new \DateTimeImmutable('now', $utc);
		if ($startUtc <= $nowUtc) {
			$this->setMessage('Нельзя блокировать прошедшее время', 'error');
			$this->setRedirectAndExit();
			return;
		}

		$masterTimezone = self::getUserTimezoneById((int) $user->id, (string) Factory::getApplication()->get('offset', 'UTC'));
		$scheduleCheck = self::validateMasterSchedule((int) $user->id, $startUtc, $endUtc, $masterTimezone);
		if (!$scheduleCheck['ok']) {
			$this->setMessage((string) ($scheduleCheck['message'] ?? 'Выбранное время вне рабочего графика мастера'), 'error');
			$this->setRedirectAndExit();
			return;
		}

		$db = Factory::getContainer()->get(\Joomla\Database\DatabaseInterface::class);
		$tableName = $db->getPrefix() . 'vigling_bookings';
		$startDb = $startUtc->format('Y-m-d H:i:s');
		$endDb = $endUtc->format('Y-m-d H:i:s');
		$masterId = (int) $user->id;
		$lockHeld = false;
		try {
			if (!OrderTable::acquireMasterBookingLock($db, $masterId)) {
				$this->setMessage('Сейчас идёт другая запись к этому специалисту, попробуйте ещё раз', 'error');
				$this->setRedirectAndExit();
				return;
			}
			$lockHeld = true;
			if (self::hasCourseSlotsOverlap($db, $masterId, $startDb, $endDb)) {
				$this->setMessage('Это время занято курсом', 'error');
				$this->setRedirectAndExit();
				return;
			}
			if (self::hasSearchSlotsOverlap($db, $masterId, $startDb, $endDb)) {
				$this->setMessage('Это время занято поиском', 'error');
				$this->setRedirectAndExit();
				return;
			}
			if (self::hasBookingsOverlap($db, $tableName, $masterId, $startDb, $endDb, 0)) {
				$this->setMessage('Это время уже занято', 'error');
				$this->setRedirectAndExit();
				return;
			}

			$comment = preg_replace('/\s+/u', ' ', $commentRaw);
			$comment = trim((string) $comment);
			if (function_exists('mb_substr')) {
				$comment = mb_substr($comment, 0, 500);
			} else {
				$comment = substr($comment, 0, 500);
			}

			$serviceName = '[journal] Забронировать время';
			if ($comment !== '') {
				$serviceName .= ' | Комментарий: ' . $comment;
			}

			$columns = array_change_key_case($db->getTableColumns($tableName, false), CASE_LOWER);
			$insertColumns = ['user_id', 'master_id', 'time', 'time_to', 'service_name', 'completed', 'time_sum'];
			$insertValues = [
				'0',
				(string) $masterId,
				$db->quote($startDb),
				$db->quote($endDb),
				$db->quote($serviceName),
				'0',
				(string) (int) $durationMin,
			];
			if (isset($columns['booking_kind'])) {
				$insertColumns[] = 'booking_kind';
				$insertValues[] = $db->quote('journal');
			}

			$query = $db->getQuery(true)
				->insert($db->quoteName($tableName))
				->columns($db->quoteName($insertColumns))
				->values(implode(', ', $insertValues));

			try {
				$db->setQuery($query)->execute();
				self::guardRecord('journal_created', [
					'id' => (int) $db->insertid(),
					'user_id' => 0,
					'master_id' => $masterId,
					'time' => $startDb,
				]);
			} catch (\Throwable $e) {
				$this->setMessage('Не удалось сохранить блок времени', 'error');
				$this->setRedirectAndExit();
				return;
			}
		} finally {
			if ($lockHeld) {
				OrderTable::releaseMasterBookingLock($db, $masterId);
			}
		}

		$this->setMessage('Время заблокировано');
		$this->setRedirectAndExit();
	}

	public function journalDelete()
	{
		Session::checkToken('request') or $this->setRedirectAndExit();
		$user = Factory::getApplication()->getIdentity();
		if (!$user->id || !self::isMasterUser($user)) {
			$this->setMessage('Доступ только для мастеров', 'error');
			$this->setRedirectAndExit();
			return;
		}
		if ($this->denyIfBookingFlagOff('booking_journal')) {
			return;
		}

		$id = (int) $this->input->get('id', 0);
		if ($id <= 0) {
			$this->setMessage('Неверный идентификатор блока', 'error');
			$this->setRedirectAndExit();
			return;
		}

		$table = new OrderTable(
			Factory::getContainer()->get(\Joomla\Database\DatabaseInterface::class),
			Factory::getContainer()->get(\Joomla\Event\DispatcherInterface::class)
		);
		if (!$table->load($id)) {
			$this->setMessage('Блок не найден', 'error');
			$this->setRedirectAndExit();
			return;
		}
		if ((int) $table->master_id !== (int) $user->id || (int) $table->user_id !== 0) {
			$this->setMessage('Нет прав на удаление этого блока', 'error');
			$this->setRedirectAndExit();
			return;
		}

		$db = Factory::getContainer()->get(\Joomla\Database\DatabaseInterface::class);
		$tbl = $db->getPrefix() . 'vigling_bookings';
		$db->setQuery(
			'DELETE FROM ' . $db->quoteName($tbl)
			. ' WHERE ' . $db->quoteName('id') . ' = ' . (int) $id
			. ' AND ' . $db->quoteName('master_id') . ' = ' . (int) $user->id
			. ' AND ' . $db->quoteName('user_id') . ' = 0'
		)->execute();
		self::guardRecord('journal_removed', $table);

		$this->setMessage('Блок удалён');
		$this->setRedirectAndExit();
	}

	public function weekRange()
	{
		$app = Factory::getApplication();
		if (!Session::checkToken('request')) {
			$this->jsonResponse(['success' => false, 'message' => 'Неверный токен']);
			return;
		}
		$user = $app->getIdentity();
		if (!$user->id) {
			$this->jsonResponse(['success' => false, 'message' => 'Нужна авторизация']);
			return;
		}

		$fromRaw = trim((string) $this->input->getString('from', ''));
		$days = (int) $this->input->getInt('days', 14);
		$days = max(1, min(14, $days));
		if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fromRaw)) {
			$this->jsonResponse(['success' => false, 'message' => 'Неверная дата']);
			return;
		}

		if (!class_exists(\Viglin\Component\Orders\Site\Helper\AppointmentsHelper::class, false)) {
			require_once JPATH_SITE . '/components/com_orders/src/Helper/AppointmentsHelper.php';
		}

		$this->input->set('zapisi', 'week');
		$this->input->set('from', $fromRaw);
		$this->input->set('days', $days);

		$src = new \stdClass();
		\Viglin\Component\Orders\Site\Helper\AppointmentsHelper::fill($src, $this->input);
		$src->appointmentsEmbed = true;

		$renderer = new class ($src) {
			public $appointments;

			public function __construct($src)
			{
				$this->appointments = $src;
			}

			public function escape($s)
			{
				return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
			}

			public function renderChunk(): array
			{
				$appointments = $this->appointments;
				$journalRangeChunk = true;
				$journalChunkHeads = '';
				$journalChunkCols = '';
				$journalChunkDetails = '';
				$journalChunkFrom = '';
				$journalChunkDays = 0;
				include JPATH_SITE . '/components/com_orders/tmpl/orders/journal.php';

				return [
					'heads' => $journalChunkHeads,
					'cols' => $journalChunkCols,
					'details' => $journalChunkDetails,
					'from' => $journalChunkFrom,
					'days' => $journalChunkDays,
				];
			}
		};

		$chunk = $renderer->renderChunk();
		$this->jsonResponse([
			'success' => true,
			'from' => $chunk['from'],
			'days' => (int) $chunk['days'],
			'heads' => $chunk['heads'],
			'cols' => $chunk['cols'],
			'details' => $chunk['details'],
		]);
	}

	public function rescheduleSlots()
	{
		$app = Factory::getApplication();
		if (!Session::checkToken('request')) {
			$this->jsonResponse(['success' => false, 'message' => 'Неверный токен']);
			return;
		}
		$user = $app->getIdentity();
		if (!$user->id) {
			$this->jsonResponse(['success' => false, 'message' => 'Нужна авторизация']);
			return;
		}
		if ($this->bookingFlagOff('booking_reschedule')) {
			$this->jsonResponse(['success' => false, 'message' => $this->bookingFlagMessage('booking_reschedule')]);
			return;
		}
		$viewerId = (int) $user->id;
		$isMasterGroup = self::isMasterUser($user);

		$orderId = (int) $this->input->getInt('id', 0);
		$courseSlotId = (int) $this->input->getInt('course_slot_id', 0);
		$searchSlotId = (int) $this->input->getInt('search_slot_id', 0);
		$durationMin = (int) $this->input->getInt('duration', 60);
		if ($durationMin <= 0) {
			$durationMin = 60;
		}
		$durationMin = max(15, min(480, $durationMin));
		if ($orderId <= 0 && $courseSlotId <= 0 && $searchSlotId <= 0) {
			$this->jsonResponse(['success' => false, 'message' => 'Укажите запись']);
			return;
		}

		$db = Factory::getContainer()->get(\Joomla\Database\DatabaseInterface::class);
		$masterId = 0;
		$excludeOrderId = 0;
		$excludeCourseSlotId = 0;
		$excludeSearchSlotId = 0;

		if ($orderId > 0) {
			$table = new OrderTable($db, Factory::getContainer()->get(\Joomla\Event\DispatcherInterface::class));
			if (!$table->load($orderId)) {
				$this->jsonResponse(['success' => false, 'message' => 'Нет прав на перенос этой записи']);
				return;
			}
			$isBookingMaster = (int) $table->master_id === $viewerId;
			$isBookingClient = (int) $table->user_id === $viewerId;
			if (!$isBookingMaster && !$isBookingClient) {
				$this->jsonResponse(['success' => false, 'message' => 'Нет прав на перенос этой записи']);
				return;
			}
			$masterId = (int) $table->master_id;
			$excludeOrderId = $orderId;
		} elseif ($courseSlotId > 0) {
			if (!$isMasterGroup) {
				$this->jsonResponse(['success' => false, 'message' => 'Доступ только для мастеров']);
				return;
			}
			$courseContext = self::loadCourseSlotContext($db, $courseSlotId);
			if ($courseContext === null || (int) $courseContext['master_id'] !== $viewerId) {
				$this->jsonResponse(['success' => false, 'message' => 'Нет прав на перенос этого курса']);
				return;
			}
			$masterId = (int) $courseContext['master_id'];
			$excludeCourseSlotId = $courseSlotId;
		} elseif ($searchSlotId > 0) {
			if (!$isMasterGroup) {
				$this->jsonResponse(['success' => false, 'message' => 'Доступ только для мастеров']);
				return;
			}
			$searchContext = self::loadSearchSlotContext($db, $searchSlotId);
			if ($searchContext === null || (int) $searchContext['master_id'] !== $viewerId) {
				$this->jsonResponse(['success' => false, 'message' => 'Нет прав на перенос этого поиска']);
				return;
			}
			$masterId = (int) $searchContext['master_id'];
			$excludeSearchSlotId = $searchSlotId;
		}

		if ($masterId <= 0) {
			$this->jsonResponse(['success' => false, 'message' => 'Не удалось загрузить свободное время']);
			return;
		}

		$helper = JPATH_SITE . '/components/com_orders/tmpl/orders/_reschedule_helper.php';
		if (is_file($helper)) {
			require_once $helper;
		}
		if (!function_exists('viglingOrdersBuildRescheduleSlots')) {
			$this->jsonResponse(['success' => false, 'message' => 'Не удалось загрузить свободное время']);
			return;
		}

		if ($orderId > 0 && isset($table->time_sum)) {
			$timeSumMin = (int) $table->time_sum;
			if ($timeSumMin > $durationMin) {
				$durationMin = min(480, $timeSumMin);
			}
		}

		$payload = viglingOrdersBuildRescheduleSlots($db, $masterId, $durationMin, $excludeOrderId, $excludeCourseSlotId, 45, $excludeSearchSlotId);
		$this->jsonResponse([
			'success' => true,
			'days' => $payload['days'] ?? [],
			'timezone' => $payload['timezone'] ?? 'UTC',
		]);
	}

	private function jsonResponse(array $data): void
	{
		$app = Factory::getApplication();
		$app->setHeader('Content-Type', 'application/json; charset=utf-8');
		$app->sendHeaders();
		echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
		$app->close();
	}

	private function setRedirectAndExit()
	{
		$return = $this->input->get('return', '', 'base64');
		$url = $return ? base64_decode($return) : '';
		if ($url === '' || !Uri::isInternal($url)) {
			if (!class_exists(\Viglin\Component\Orders\Site\Helper\AppointmentsHelper::class, false)) {
				require_once JPATH_SITE . '/components/com_orders/src/Helper/AppointmentsHelper.php';
			}
			$url = \Viglin\Component\Orders\Site\Helper\AppointmentsHelper::profileUrl(['zapisi' => 'day']);
		}
		$this->setRedirect($url);
		$this->redirect();
	}

	/**
	 * @return array{ok:bool,message:string}
	 */
	private static function validateMasterSchedule(int $masterId, \DateTimeImmutable $startUtc, \DateTimeImmutable $endUtc, string $siteOffset): array
	{
		$schedule = self::loadMasterScheduleByDay($masterId);
		if ($schedule === []) {
			return ['ok' => true, 'message' => ''];
		}

		try {
			$siteTz = new \DateTimeZone($siteOffset !== '' ? $siteOffset : 'UTC');
		} catch (\Throwable $e) {
			$siteTz = new \DateTimeZone('UTC');
		}

		$startLocal = $startUtc->setTimezone($siteTz);
		$endLocal = $endUtc->setTimezone($siteTz);
		if ($endLocal <= $startLocal) {
			return ['ok' => false, 'message' => 'Некорректный интервал записи'];
		}
		if ($startLocal->format('Y-m-d') !== $endLocal->format('Y-m-d')) {
			return ['ok' => false, 'message' => 'Запись должна укладываться в один рабочий день'];
		}

		$dayNum = (int) $startLocal->format('N');
		$startMin = ((int) $startLocal->format('H')) * 60 + (int) $startLocal->format('i');
		$endMin = ((int) $endLocal->format('H')) * 60 + (int) $endLocal->format('i');
		if (!isset($schedule[$dayNum])) {
			return ['ok' => false, 'message' => 'Мастер не работает в выбранный день'];
		}

		$range = $schedule[$dayNum];
		$fromMin = (int) ($range[0] ?? 0);
		$toMin = (int) ($range[1] ?? 0);
		if ($startMin < $fromMin || $endMin > $toMin) {
			return ['ok' => false, 'message' => 'Выбранное время вне рабочего графика мастера'];
		}

		return ['ok' => true, 'message' => ''];
	}

	private static function occupiedEndSql(\Joomla\Database\DatabaseInterface $db, string $tableName): string
	{
		$columns = array_change_key_case($db->getTableColumns($tableName, false), CASE_LOWER);
		$timeTo = $db->quoteName('time_to');
		if (!isset($columns['time_sum'])) {
			return $timeTo;
		}
		$time = $db->quoteName('time');
		$timeSum = $db->quoteName('time_sum');

		return '(CASE WHEN ' . $timeSum . ' > TIMESTAMPDIFF(MINUTE, ' . $time . ', ' . $timeTo . ')'
			. ' THEN DATE_ADD(' . $time . ', INTERVAL ' . $timeSum . ' MINUTE) ELSE ' . $timeTo . ' END)';
	}

	private static function occupiedProbeEnd(\DateTime $start, int $serviceMin, int $timeSumMin): \DateTime
	{
		$blockMin = max($serviceMin, $timeSumMin > $serviceMin ? min(480, $timeSumMin) : $serviceMin);
		$end = clone $start;
		$end->modify('+' . $blockMin . ' minutes');

		return $end;
	}

	private static function hasBookingsOverlap(
		\Joomla\Database\DatabaseInterface $db,
		string $tableName,
		int $masterId,
		string $startUtc,
		string $endUtc,
		int $excludeId = 0,
		array $excludeIds = []
	): bool {
		$query = $db->getQuery(true)
			->select('COUNT(*)')
			->from($db->quoteName($tableName))
			->where($db->quoteName('master_id') . ' = ' . (int) $masterId)
			->where($db->quoteName('time') . ' < ' . $db->quote($endUtc))
			->where(self::occupiedEndSql($db, $tableName) . ' > ' . $db->quote($startUtc));
		if ($excludeId > 0) {
			$query->where($db->quoteName('id') . ' <> ' . (int) $excludeId);
		}
		$excludeIds = array_values(array_filter(array_map('intval', $excludeIds), static function (int $id): bool {
			return $id > 0;
		}));
		if ($excludeIds !== []) {
			$query->where($db->quoteName('id') . ' NOT IN (' . implode(', ', $excludeIds) . ')');
		}
		$db->setQuery($query);
		return ((int) $db->loadResult()) > 0;
	}

	/**
	 * A person can be in one place at a time: the same user must not hold two overlapping
	 * appointments with different specialists. Self-bookings (master_id = user_id, made for
	 * people outside the site) never count, and neither do journal blocks (user_id = 0).
	 */
	private static function hasUserBookingOverlap(
		\Joomla\Database\DatabaseInterface $db,
		string $tableName,
		int $userId,
		int $masterId,
		string $startUtc,
		string $endUtc,
		int $excludeId = 0
	): bool {
		if ($userId <= 0 || $userId === $masterId) {
			return false;
		}
		$query = $db->getQuery(true)
			->select('COUNT(*)')
			->from($db->quoteName($tableName))
			->where($db->quoteName('user_id') . ' = ' . $userId)
			->where($db->quoteName('master_id') . ' <> ' . $userId)
			->where($db->quoteName('time') . ' < ' . $db->quote($endUtc))
			->where($db->quoteName('time_to') . ' > ' . $db->quote($startUtc));
		if ($excludeId > 0) {
			$query->where($db->quoteName('id') . ' <> ' . $excludeId);
		}
		$db->setQuery($query);

		return ((int) $db->loadResult()) > 0;
	}

	private static function loadCourseSlotContext(\Joomla\Database\DatabaseInterface $db, int $courseSlotId): ?array
	{
		if ($courseSlotId <= 0) {
			return null;
		}

		$query = $db->getQuery(true)
			->select([
				$db->quoteName('slot.id'),
				$db->quoteName('slot.course_id'),
				$db->quoteName('slot.master_id'),
				$db->quoteName('slot.starts_at_utc'),
				$db->quoteName('slot.ends_at_utc'),
				$db->quoteName('slot.capacity_total'),
				$db->quoteName('slot.is_active'),
				$db->quoteName('course.duration_min'),
				$db->quoteName('course.description'),
			])
			->from($db->quoteName('#__vigling_course_slots', 'slot'))
			->join('LEFT', $db->quoteName('#__vigling_user_courses', 'course') . ' ON ' . $db->quoteName('course.id') . ' = ' . $db->quoteName('slot.course_id'))
			->where($db->quoteName('slot.id') . ' = ' . (int) $courseSlotId);
		$db->setQuery($query);
		$row = $db->loadAssoc();
		return is_array($row) ? $row : null;
	}

	/**
	 * @return array<int>
	 */
	private static function getCourseSlotBookingIds(\Joomla\Database\DatabaseInterface $db, int $courseSlotId, int $masterId): array
	{
		if ($courseSlotId <= 0 || $masterId <= 0) {
			return [];
		}

		$query = $db->getQuery(true)
			->select($db->quoteName('id'))
			->from($db->quoteName('#__vigling_bookings'))
			->where($db->quoteName('master_id') . ' = ' . (int) $masterId)
			->where($db->quoteName('booking_kind') . ' = ' . $db->quote('course'))
			->where($db->quoteName('course_slot_id') . ' = ' . (int) $courseSlotId);
		$db->setQuery($query);
		$ids = $db->loadColumn() ?: [];
		return array_values(array_filter(array_map('intval', $ids), static function (int $id): bool {
			return $id > 0;
		}));
	}

	private static function loadSearchSlotContext(\Joomla\Database\DatabaseInterface $db, int $searchSlotId): ?array
	{
		if ($searchSlotId <= 0) {
			return null;
		}

		$query = $db->getQuery(true)
			->select([
				$db->quoteName('slot.id'),
				$db->quoteName('slot.search_id'),
				$db->quoteName('slot.master_id'),
				$db->quoteName('slot.starts_at_utc'),
				$db->quoteName('slot.ends_at_utc'),
				$db->quoteName('slot.capacity_total'),
				$db->quoteName('slot.is_active'),
				$db->quoteName('search.duration_min'),
				$db->quoteName('search.description'),
			])
			->from($db->quoteName('#__vigling_search_slots', 'slot'))
			->join('LEFT', $db->quoteName('#__vigling_user_searches', 'search') . ' ON ' . $db->quoteName('search.id') . ' = ' . $db->quoteName('slot.search_id'))
			->where($db->quoteName('slot.id') . ' = ' . (int) $searchSlotId);
		$db->setQuery($query);
		$row = $db->loadAssoc();
		return is_array($row) ? $row : null;
	}

	/**
	 * @return array<int>
	 */
	private static function getSearchSlotBookingIds(\Joomla\Database\DatabaseInterface $db, int $searchSlotId, int $masterId): array
	{
		if ($searchSlotId <= 0 || $masterId <= 0) {
			return [];
		}

		$query = $db->getQuery(true)
			->select($db->quoteName('id'))
			->from($db->quoteName('#__vigling_bookings'))
			->where($db->quoteName('master_id') . ' = ' . (int) $masterId)
			->where($db->quoteName('booking_kind') . ' = ' . $db->quote('search'))
			->where($db->quoteName('search_slot_id') . ' = ' . (int) $searchSlotId);
		$db->setQuery($query);
		$ids = $db->loadColumn() ?: [];
		return array_values(array_filter(array_map('intval', $ids), static function (int $id): bool {
			return $id > 0;
		}));
	}

	private static function hasCourseSlotsOverlap(
		\Joomla\Database\DatabaseInterface $db,
		int $masterId,
		string $startUtc,
		string $endUtc,
		int $excludeCourseSlotId = 0
	): bool {
		$query = $db->getQuery(true)
			->select('COUNT(*)')
			->from($db->quoteName('#__vigling_course_slots'))
			->where($db->quoteName('master_id') . ' = ' . (int) $masterId)
			->where($db->quoteName('is_active') . ' = 1')
			->where($db->quoteName('starts_at_utc') . ' < ' . $db->quote($endUtc))
			->where($db->quoteName('ends_at_utc') . ' > ' . $db->quote($startUtc));
		if ($excludeCourseSlotId > 0) {
			$query->where($db->quoteName('id') . ' <> ' . (int) $excludeCourseSlotId);
		}
		$db->setQuery($query);
		return ((int) $db->loadResult()) > 0;
	}

	private static function hasSearchSlotsOverlap(
		\Joomla\Database\DatabaseInterface $db,
		int $masterId,
		string $startUtc,
		string $endUtc,
		int $excludeSearchSlotId = 0
	): bool {
		try {
			$query = $db->getQuery(true)
				->select('COUNT(*)')
				->from($db->quoteName('#__vigling_search_slots'))
				->where($db->quoteName('master_id') . ' = ' . (int) $masterId)
				->where($db->quoteName('is_active') . ' = 1')
				->where($db->quoteName('starts_at_utc') . ' < ' . $db->quote($endUtc))
				->where($db->quoteName('ends_at_utc') . ' > ' . $db->quote($startUtc));
			if ($excludeSearchSlotId > 0) {
				$query->where($db->quoteName('id') . ' <> ' . (int) $excludeSearchSlotId);
			}
			$db->setQuery($query);

			return ((int) $db->loadResult()) > 0;
		} catch (\Throwable $e) {
			return false;
		}
	}

	private static function updateCourseSlotTime(\Joomla\Database\DatabaseInterface $db, int $courseSlotId, string $startUtc, string $endUtc): void
	{
		if ($courseSlotId <= 0 || $startUtc === '' || $endUtc === '') {
			return;
		}

		$query = $db->getQuery(true)
			->update($db->quoteName('#__vigling_course_slots'))
			->set($db->quoteName('starts_at_utc') . ' = ' . $db->quote($startUtc))
			->set($db->quoteName('ends_at_utc') . ' = ' . $db->quote($endUtc))
			->where($db->quoteName('id') . ' = ' . (int) $courseSlotId);
		$db->setQuery($query)->execute();
	}

	private static function deactivateCourseSlot(\Joomla\Database\DatabaseInterface $db, int $courseSlotId): void
	{
		if ($courseSlotId <= 0) {
			return;
		}

		$query = $db->getQuery(true)
			->update($db->quoteName('#__vigling_course_slots'))
			->set($db->quoteName('is_active') . ' = 0')
			->where($db->quoteName('id') . ' = ' . (int) $courseSlotId);
		$db->setQuery($query)->execute();
	}

	private static function updateSearchSlotTime(\Joomla\Database\DatabaseInterface $db, int $searchSlotId, string $startUtc, string $endUtc): void
	{
		if ($searchSlotId <= 0 || $startUtc === '' || $endUtc === '') {
			return;
		}

		$query = $db->getQuery(true)
			->update($db->quoteName('#__vigling_search_slots'))
			->set($db->quoteName('starts_at_utc') . ' = ' . $db->quote($startUtc))
			->set($db->quoteName('ends_at_utc') . ' = ' . $db->quote($endUtc))
			->where($db->quoteName('id') . ' = ' . (int) $searchSlotId);
		$db->setQuery($query)->execute();
	}

	private static function deactivateSearchSlot(\Joomla\Database\DatabaseInterface $db, int $searchSlotId): void
	{
		if ($searchSlotId <= 0) {
			return;
		}

		$query = $db->getQuery(true)
			->update($db->quoteName('#__vigling_search_slots'))
			->set($db->quoteName('is_active') . ' = 0')
			->where($db->quoteName('id') . ' = ' . (int) $searchSlotId);
		$db->setQuery($query)->execute();
	}

	private static function isMasterUser($user): bool
	{
		if (!is_object($user) || empty($user->id)) {
			return false;
		}
		$helper = JPATH_PLUGINS . '/user/vigling/src/Helper/MasterGroupHelper.php';
		if (is_file($helper)) {
			require_once $helper;
		}
		if (class_exists(\Joomla\Plugin\User\Vigling\Helper\MasterGroupHelper::class, false)) {
			return \Joomla\Plugin\User\Vigling\Helper\MasterGroupHelper::isMaster($user);
		}
		$groups = method_exists($user, 'getAuthorisedGroups') ? (array) $user->getAuthorisedGroups() : [];
		$groups = array_map('intval', $groups);

		return in_array(3, $groups, true) || in_array(8, $groups, true);
	}

	private static function hasStarted(OrderTable $table): bool
	{
		$raw = trim((string) ($table->time ?? ''));
		if ($raw === '') {
			return false;
		}
		try {
			$start = new \DateTimeImmutable($raw, new \DateTimeZone('UTC'));
		} catch (\Throwable $e) {
			return false;
		}

		return $start < new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
	}

	private static function parseUtcDateTime(string $raw): ?\DateTimeImmutable
	{
		$raw = trim($raw);
		if ($raw === '') {
			return null;
		}
		$utc = new \DateTimeZone('UTC');
		try {
			return new \DateTimeImmutable($raw, $utc);
		} catch (\Throwable $e) {
		}
		return null;
	}

	/**
	 * Moving one person off a fixed group makes them a normal visit,
	 * so the remaining group keeps its shared time and a seat opens.
	 */
	private static function detachFixedGroupBooking(OrderTable $table): void
	{
		$kind = isset($table->booking_kind) ? trim((string) $table->booking_kind) : '';
		$courseSlotId = isset($table->course_slot_id) ? (int) $table->course_slot_id : 0;
		$searchSlotId = isset($table->search_slot_id) ? (int) $table->search_slot_id : 0;
		if ($kind === 'course' && $courseSlotId > 0) {
			$table->booking_kind = 'service';
			$table->course_slot_id = null;
		}
		if ($kind === 'search' && $searchSlotId > 0) {
			$table->booking_kind = 'service';
			$table->search_slot_id = null;
		}
	}

	/**
	 * Prefer an explicit UTC instant. If only a local clock string is sent,
	 * interpret it in the specialist timezone, not the site offset.
	 */
	private static function parseRescheduleDateTime(string $timeUtcStr, string $timeStr, string $specialistTimezone): ?\DateTime
	{
		$utc = new \DateTimeZone('UTC');
		$timeUtcStr = trim($timeUtcStr);
		$timeStr = trim($timeStr);
		if ($timeUtcStr !== '') {
			try {
				$time = new \DateTime($timeUtcStr, $utc);
				$time->setTimezone($utc);

				return $time;
			} catch (\Throwable $e) {
			}
		}
		if ($timeStr === '') {
			return null;
		}
		try {
			$localTz = new \DateTimeZone(trim($specialistTimezone) !== '' ? trim($specialistTimezone) : 'UTC');
		} catch (\Throwable $e) {
			$localTz = $utc;
		}
		$time = \DateTime::createFromFormat('Y-m-d H:i:s', $timeStr, $localTz);
		if (!$time) {
			$time = \DateTime::createFromFormat('Y-m-d H:i', $timeStr, $localTz);
		}
		if (!$time) {
			$time = \DateTime::createFromFormat('Y-m-d\TH:i', $timeStr, $localTz);
		}
		if (!$time) {
			return null;
		}
		$time->setTimezone($utc);

		return $time;
	}

	private static function parseDurationMinutes(string $raw): int
	{
		$raw = trim(mb_strtolower($raw));
		if ($raw === '') {
			return 0;
		}
		if (preg_match('/^(\d+)\s*[:.,]\s*(\d{1,2})$/u', $raw, $m)) {
			return ((int) $m[1]) * 60 + (int) $m[2];
		}
		if (preg_match('/^(\d+)\s*(?:h|ч|час|часа|часов)\s*(\d{1,2})\s*(?:m|м|мин|минут|минуты)?$/u', $raw, $m)) {
			return ((int) $m[1]) * 60 + (int) $m[2];
		}
		if (preg_match('/^(\d+)\s*(?:m|м|мин|минут|минуты)?$/u', $raw, $m)) {
			return (int) $m[1];
		}
		if (is_numeric($raw)) {
			return (int) round((float) $raw);
		}
		if (preg_match('/(\d+)/u', $raw, $m)) {
			return (int) $m[1];
		}
		return 0;
	}

	/**
	 * @return array<int, array{0:int,1:int}>
	 */
	private static function loadMasterScheduleByDay(int $masterId): array
	{
		if ($masterId <= 0) {
			return [];
		}

		try {
			$db = Factory::getContainer()->get(\Joomla\Database\DatabaseInterface::class);
			$query = $db->getQuery(true)
				->select([
					$db->quoteName('f.name', 'field_name'),
					$db->quoteName('fv.value', 'field_value'),
				])
				->from($db->quoteName('#__fields_values', 'fv'))
				->join('INNER', $db->quoteName('#__fields', 'f') . ' ON ' . $db->quoteName('f.id') . ' = ' . $db->quoteName('fv.field_id'))
				->where($db->quoteName('fv.item_id') . ' = ' . (int) $masterId)
				->where($db->quoteName('f.context') . ' = ' . $db->quote('com_users.user'))
				->where($db->quoteName('f.name') . ' IN (' . $db->quote('work_day') . ', ' . $db->quote('work_from') . ', ' . $db->quote('work_to') . ')');
			$db->setQuery($query);
			$rows = $db->loadAssocList() ?: [];
		} catch (\Throwable $e) {
			return [];
		}

		$raw = ['work_day' => '', 'work_from' => '', 'work_to' => ''];
		foreach ($rows as $row) {
			$name = (string) ($row['field_name'] ?? '');
			if (!isset($raw[$name])) {
				continue;
			}
			$raw[$name] = trim((string) ($row['field_value'] ?? ''));
		}

		if (!class_exists(\Joomla\Plugin\User\Vigling\Helper\WorkScheduleHelper::class, false)) {
			foreach ([
				JPATH_PLUGINS . '/user/vigling/src/Helper/WorkScheduleHelper.php',
				(defined('JPATH_THEMES') ? JPATH_THEMES : JPATH_ROOT . '/templates') . '/ryba/helpers/WorkScheduleHelper.php',
			] as $vgWorkScheduleFile) {
				if (is_file($vgWorkScheduleFile)) {
					require_once $vgWorkScheduleFile;
					break;
				}
			}
		}
		if (!function_exists('vigling_profile_ranges_by_day')) {
			$vgScheduleTimes = (defined('JPATH_THEMES') ? JPATH_THEMES : JPATH_ROOT . '/templates') . '/ryba/html/com_users/profile/schedule_times.php';
			if (is_file($vgScheduleTimes)) {
				require_once $vgScheduleTimes;
			}
		}

		if (class_exists(\Joomla\Plugin\User\Vigling\Helper\WorkScheduleHelper::class, false)) {
			return \Joomla\Plugin\User\Vigling\Helper\WorkScheduleHelper::rangesByDay(
				$raw['work_day'],
				$raw['work_from'],
				$raw['work_to']
			);
		}
		if (function_exists('vigling_profile_ranges_by_day')) {
			return vigling_profile_ranges_by_day(
				$raw['work_day'],
				$raw['work_from'],
				$raw['work_to']
			);
		}

		return [];
	}

	/**
	 * @return array<int>
	 */
	private static function decodeIntList(string $raw): array
	{
		$raw = trim($raw);
		if ($raw === '') {
			return [];
		}
		$decoded = json_decode($raw, true);
		$vals = [];
		if (is_array($decoded)) {
			$iter = new \RecursiveIteratorIterator(new \RecursiveArrayIterator($decoded));
			foreach ($iter as $v) {
				if (is_scalar($v) && preg_match('/^\d+$/', (string) $v)) {
					$vals[] = (int) $v;
				}
			}
		} else {
			preg_match_all('/\d+/', $raw, $m);
			foreach (($m[0] ?? []) as $num) {
				$vals[] = (int) $num;
			}
		}
		$vals = array_values(array_unique(array_filter($vals, static function ($v) {
			return $v >= 1 && $v <= 7;
		})));
		sort($vals);
		return $vals;
	}

	/**
	 * @return array<int, string>
	 */
	private static function decodeStringList(string $raw): array
	{
		$raw = trim($raw);
		if ($raw === '') {
			return [];
		}
		$decoded = json_decode($raw, true);
		if (is_array($decoded)) {
			$result = [];
			foreach ($decoded as $item) {
				if (is_scalar($item)) {
					$val = trim((string) $item);
					if ($val !== '') {
						$result[] = $val;
					}
				}
			}
			return $result;
		}
		return [$raw];
	}

	private static function parseTimeToMinutes(string $raw): ?int
	{
		$raw = trim($raw);
		if ($raw === '') {
			return null;
		}
		if (preg_match('/^(\d{1,2}):(\d{2})$/', $raw, $m)) {
			$h = max(0, min(23, (int) $m[1]));
			$i = max(0, min(59, (int) $m[2]));
			return $h * 60 + $i;
		}
		if (is_numeric($raw)) {
			$num = (float) $raw;
			$h = (int) floor($num);
			$m = (int) round(($num - $h) * 60);
			$m = max(0, min(59, $m));
			$h = max(0, min(23, $h));
			return $h * 60 + $m;
		}
		return null;
	}

	private static function deriveDurationMinFromOrder($table): int
	{
		if (!is_object($table)) {
			return 60;
		}
		$startRaw = trim((string) ($table->time ?? ''));
		$endRaw = trim((string) ($table->time_to ?? ''));
		if ($startRaw === '' || $endRaw === '') {
			return 60;
		}
		try {
			$utc = new \DateTimeZone('UTC');
			$start = new \DateTimeImmutable($startRaw, $utc);
			$end = new \DateTimeImmutable($endRaw, $utc);
			$minutes = (int) floor(($end->getTimestamp() - $start->getTimestamp()) / 60);
			return $minutes > 0 ? $minutes : 60;
		} catch (\Throwable $e) {
			return 60;
		}
	}

	private static function getUserTimezoneById(int $userId, string $fallback = 'UTC'): string
	{
		$fallback = trim($fallback) !== '' ? trim($fallback) : 'UTC';
		if ($userId <= 0) {
			return $fallback;
		}
		try {
			$db = Factory::getContainer()->get(\Joomla\Database\DatabaseInterface::class);
			$query = $db->getQuery(true)
				->select($db->quoteName('params'))
				->from($db->quoteName('#__users'))
				->where($db->quoteName('id') . ' = ' . (int) $userId);
			$db->setQuery($query);
			$paramsRaw = (string) ($db->loadResult() ?? '');
			$params = json_decode($paramsRaw, true);
			if (is_array($params) && !empty($params['timezone']) && is_string($params['timezone'])) {
				$tz = trim((string) $params['timezone']);
				if ($tz !== '') {
					try {
						new \DateTimeZone($tz);
						return $tz;
					} catch (\Throwable $e) {
					}
				}
			}
		} catch (\Throwable $e) {
		}
		return $fallback;
	}

	private function denyIfBookingFlagOff(string $flag): bool
	{
		if (!$this->bookingFlagOff($flag)) {
			return false;
		}
		$this->setMessage($this->bookingFlagMessage($flag), 'error');
		$this->setRedirectAndExit();

		return true;
	}

	private function bookingFlagOff(string $flag): bool
	{
		try {
			$class = self::guardClass();

			return $class !== null && !$class::enabled($flag);
		} catch (\Throwable $e) {
			return false;
		}
	}

	private function bookingFlagMessage(string $flag): string
	{
		try {
			$class = self::guardClass();
			if ($class !== null) {
				return $class::disabledMessage($flag);
			}
		} catch (\Throwable $e) {
		}

		return 'Действие временно недоступно. Попробуйте позже.';
	}

	private function allowAnotherBooking(int $userId): bool
	{
		try {
			$class = self::guardClass();

			return $class === null || $class::allowBookingCreate($userId);
		} catch (\Throwable $e) {
			return true;
		}
	}

	/**
	 * @param object|array<string, mixed> $order
	 */
	private static function guardRecord(string $action, $order): void
	{
		try {
			$class = self::guardClass();
			if ($class === null) {
				return;
			}
			if (is_object($order)) {
				$order = [
					'id' => (int) ($order->id ?? 0),
					'user_id' => (int) ($order->user_id ?? 0),
					'master_id' => (int) ($order->master_id ?? 0),
					'time' => (string) ($order->time ?? ''),
				];
			}
			$class::record($action, $order);
		} catch (\Throwable $e) {
		}
	}

	private static function guardClass(): ?string
	{
		$class = \Joomla\Plugin\User\Vigling\Helper\SiteGuardHelper::class;
		if (!class_exists($class, false)) {
			$path = JPATH_PLUGINS . '/user/vigling/src/Helper/SiteGuardHelper.php';
			if (is_file($path)) {
				require_once $path;
			}
		}

		return class_exists($class, false) ? $class : null;
	}
}