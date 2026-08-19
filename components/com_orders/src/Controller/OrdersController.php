<?php

namespace Viglin\Component\Orders\Site\Controller;

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\MVC\Controller\BaseController;
use Joomla\CMS\Session\Session;
use Joomla\CMS\Uri\Uri;
use Viglin\Component\Orders\Site\Table\OrderTable;

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
		if (!$table->delete($id)) {
			$this->setMessage($table->getError() ?: 'Ошибка отмены записи', 'error');
			$this->setRedirectAndExit();
			return;
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
		$this->setMessage('Запись удалена');
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
		$id = (int) $this->input->get('id', 0);
		$timeUtcStr = trim((string) $this->input->get('time_utc', '', 'string'));
		$timeStr = trim((string) $this->input->get('time', '', 'string'));
		if ($id <= 0) {
			$this->setMessage('Укажите запись', 'error');
			$this->setRedirectAndExit();
			return;
		}
		$utc = new \DateTimeZone('UTC');
		$time = null;
		if ($timeUtcStr !== '') {
			try {
				$time = new \DateTime($timeUtcStr, $utc);
			} catch (\Throwable $e) {
			}
		}
		if (!$time && $timeStr !== '') {
			$app = Factory::getApplication();
			$siteTz = new \DateTimeZone($app->get('offset', 'UTC'));
			$time = \DateTime::createFromFormat('Y-m-d H:i:s', $timeStr, $siteTz);
			if (!$time) {
				$time = \DateTime::createFromFormat('Y-m-d H:i', $timeStr, $siteTz);
			}
			if (!$time) {
				$time = \DateTime::createFromFormat('Y-m-d\TH:i', $timeStr, $siteTz);
			}
			if ($time) {
				$time->setTimezone($utc);
			}
		}
		if (!$time) {
			$this->setMessage('Укажите новое дату/время', 'error');
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
		$durationMin = (int) $this->input->get('duration_min', 0);
		if ($durationMin <= 0) {
			$durationMin = self::deriveDurationMinFromOrder($table);
		}
		$durationMin = max(15, min(480, $durationMin));
		$timeTo = clone $time;
		$timeTo->modify('+' . $durationMin . ' minutes');
		$timeDb = $time->format('Y-m-d H:i:s');
		$timeToDb = $timeTo->format('Y-m-d H:i:s');
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
		$endUtc = \DateTimeImmutable::createFromMutable($timeTo);
		$nowUtc = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
		if ($startUtc <= $nowUtc) {
			$this->setMessage('Нельзя перенести запись на прошедшее время', 'error');
			$this->setRedirectAndExit();
			return;
		}
		$masterTimezone = self::getUserTimezoneById((int) $table->master_id, (string) Factory::getApplication()->get('offset', 'UTC'));
		$scheduleCheck = self::validateMasterSchedule((int) $table->master_id, $startUtc, $endUtc, $masterTimezone);
		if (!$scheduleCheck['ok']) {
			$this->setMessage((string) ($scheduleCheck['message'] ?? 'Выбранное время вне рабочего графика мастера'), 'error');
			$this->setRedirectAndExit();
			return;
		}
		$db = Factory::getContainer()->get(\Joomla\Database\DatabaseInterface::class);
		$tableName = $db->getPrefix() . 'vigling_bookings';
		if (self::hasCourseSlotsOverlap($db, (int) $table->master_id, $timeDb, $timeToDb)) {
			$this->setMessage('Это время занято курсом', 'error');
			$this->setRedirectAndExit();
			return;
		}
		if (self::hasSearchSlotsOverlap($db, (int) $table->master_id, $timeDb, $timeToDb)) {
			$this->setMessage('Это время занято поиском', 'error');
			$this->setRedirectAndExit();
			return;
		}
		if (self::hasBookingsOverlap($db, $tableName, (int) $table->master_id, $timeDb, $timeToDb, (int) $table->id)) {
			$this->setMessage('Это время уже занято', 'error');
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
		if (
			isset($table->booking_kind, $table->course_slot_id)
			&& trim((string) $table->booking_kind) === 'course'
			&& (int) $table->course_slot_id > 0
		) {
			$this->setMessage('Фиксированный курс отменяется только мастером целиком', 'error');
			$this->setRedirectAndExit();
			return;
		}
		if (
			isset($table->booking_kind, $table->search_slot_id)
			&& trim((string) $table->booking_kind) === 'search'
			&& (int) $table->search_slot_id > 0
		) {
			$this->setMessage('Фиксированный поиск отменяется только мастером целиком', 'error');
			$this->setRedirectAndExit();
			return;
		}
		if (!$table->delete($id)) {
			$this->setMessage($table->getError() ?: 'Ошибка отмены записи', 'error');
			$this->setRedirectAndExit();
			return;
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
		$id = (int) $this->input->get('id', 0);
		$timeUtcStr = trim((string) $this->input->get('time_utc', '', 'string'));
		$timeStr = trim((string) $this->input->get('time', '', 'string'));
		if ($id <= 0) {
			$this->setMessage('Укажите запись', 'error');
			$this->setRedirectAndExit();
			return;
		}
		$utc = new \DateTimeZone('UTC');
		$time = null;
		if ($timeUtcStr !== '') {
			try {
				$time = new \DateTime($timeUtcStr, $utc);
			} catch (\Throwable $e) {
			}
		}
		if (!$time && $timeStr !== '') {
			$app = Factory::getApplication();
			$siteTz = new \DateTimeZone($app->get('offset', 'UTC'));
			$time = \DateTime::createFromFormat('Y-m-d H:i:s', $timeStr, $siteTz);
			if (!$time) {
				$time = \DateTime::createFromFormat('Y-m-d H:i', $timeStr, $siteTz);
			}
			if (!$time) {
				$time = \DateTime::createFromFormat('Y-m-d\TH:i', $timeStr, $siteTz);
			}
			if ($time) {
				$time->setTimezone($utc);
			}
		}
		if (!$time) {
			$this->setMessage('Укажите новое дату/время', 'error');
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
		$durationMin = (int) $this->input->get('duration_min', 0);
		if ($durationMin <= 0) {
			$durationMin = self::deriveDurationMinFromOrder($table);
		}
		$durationMin = max(15, min(480, $durationMin));
		$timeTo = clone $time;
		$timeTo->modify('+' . $durationMin . ' minutes');
		$timeDb = $time->format('Y-m-d H:i:s');
		$timeToDb = $timeTo->format('Y-m-d H:i:s');
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
		$endUtc = \DateTimeImmutable::createFromMutable($timeTo);
		$nowUtc = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
		if ($startUtc <= $nowUtc) {
			$this->setMessage('Нельзя перенести запись на прошедшее время', 'error');
			$this->setRedirectAndExit();
			return;
		}
		$masterTimezone = self::getUserTimezoneById((int) $table->master_id, (string) Factory::getApplication()->get('offset', 'UTC'));
		$scheduleCheck = self::validateMasterSchedule((int) $table->master_id, $startUtc, $endUtc, $masterTimezone);
		if (!$scheduleCheck['ok']) {
			$this->setMessage((string) ($scheduleCheck['message'] ?? 'Выбранное время вне рабочего графика мастера'), 'error');
			$this->setRedirectAndExit();
			return;
		}
		$db = Factory::getContainer()->get(\Joomla\Database\DatabaseInterface::class);
		$tableName = $db->getPrefix() . 'vigling_bookings';
		if (self::hasCourseSlotsOverlap($db, (int) $table->master_id, $timeDb, $timeToDb)) {
			$this->setMessage('Это время занято курсом', 'error');
			$this->setRedirectAndExit();
			return;
		}
		if (self::hasSearchSlotsOverlap($db, (int) $table->master_id, $timeDb, $timeToDb)) {
			$this->setMessage('Это время занято поиском', 'error');
			$this->setRedirectAndExit();
			return;
		}
		if (self::hasBookingsOverlap($db, $tableName, (int) $table->master_id, $timeDb, $timeToDb, (int) $table->id)) {
			$this->setMessage('Это время уже занято', 'error');
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

		$courseSlotId = (int) $this->input->get('course_slot_id', 0);
		$timeUtcStr = trim((string) $this->input->get('time_utc', '', 'string'));
		$timeStr = trim((string) $this->input->get('time', '', 'string'));
		if ($courseSlotId <= 0) {
			$this->setMessage('Укажите слот курса', 'error');
			$this->setRedirectAndExit();
			return;
		}

		$utc = new \DateTimeZone('UTC');
		$time = null;
		if ($timeUtcStr !== '') {
			try {
				$time = new \DateTime($timeUtcStr, $utc);
			} catch (\Throwable $e) {
			}
		}
		if (!$time && $timeStr !== '') {
			$app = Factory::getApplication();
			$siteTz = new \DateTimeZone($app->get('offset', 'UTC'));
			$time = \DateTime::createFromFormat('Y-m-d H:i:s', $timeStr, $siteTz);
			if (!$time) {
				$time = \DateTime::createFromFormat('Y-m-d H:i', $timeStr, $siteTz);
			}
			if (!$time) {
				$time = \DateTime::createFromFormat('Y-m-d\TH:i', $timeStr, $siteTz);
			}
			if ($time) {
				$time->setTimezone($utc);
			}
		}
		if (!$time) {
			$this->setMessage('Укажите новое дату/время', 'error');
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

		$masterTimezone = self::getUserTimezoneById((int) $user->id, (string) Factory::getApplication()->get('offset', 'UTC'));
		$scheduleCheck = self::validateMasterSchedule((int) $user->id, $startUtc, $endUtc, $masterTimezone);
		if (!$scheduleCheck['ok']) {
			$this->setMessage((string) ($scheduleCheck['message'] ?? 'Выбранное время вне рабочего графика мастера'), 'error');
			$this->setRedirectAndExit();
			return;
		}

		$tableName = $db->getPrefix() . 'vigling_bookings';
		$excludeIds = self::getCourseSlotBookingIds($db, $courseSlotId, (int) $user->id);
		if (self::hasCourseSlotsOverlap($db, (int) $user->id, $timeDb, $timeToDb, $courseSlotId)) {
			$this->setMessage('Это время занято другим курсом', 'error');
			$this->setRedirectAndExit();
			return;
		}
		if (self::hasSearchSlotsOverlap($db, (int) $user->id, $timeDb, $timeToDb, 0)) {
			$this->setMessage('Это время занято поиском', 'error');
			$this->setRedirectAndExit();
			return;
		}
		if (self::hasBookingsOverlap($db, $tableName, (int) $user->id, $timeDb, $timeToDb, 0, $excludeIds)) {
			$this->setMessage('Это время уже занято', 'error');
			$this->setRedirectAndExit();
			return;
		}

		$dispatcher = Factory::getContainer()->get(\Joomla\Event\DispatcherInterface::class);
		$table = new OrderTable($db, $dispatcher);
		foreach ($excludeIds as $bookingId) {
			if (!$table->load((int) $bookingId)) {
				continue;
			}
			if ((int) $table->master_id !== (int) $user->id) {
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

		$searchSlotId = (int) $this->input->get('search_slot_id', 0);
		$timeUtcStr = trim((string) $this->input->get('time_utc', '', 'string'));
		$timeStr = trim((string) $this->input->get('time', '', 'string'));
		if ($searchSlotId <= 0) {
			$this->setMessage('Укажите слот поиска', 'error');
			$this->setRedirectAndExit();
			return;
		}

		$utc = new \DateTimeZone('UTC');
		$time = null;
		if ($timeUtcStr !== '') {
			try {
				$time = new \DateTime($timeUtcStr, $utc);
			} catch (\Throwable $e) {
			}
		}
		if (!$time && $timeStr !== '') {
			$app = Factory::getApplication();
			$siteTz = new \DateTimeZone($app->get('offset', 'UTC'));
			$time = \DateTime::createFromFormat('Y-m-d H:i:s', $timeStr, $siteTz);
			if (!$time) {
				$time = \DateTime::createFromFormat('Y-m-d H:i', $timeStr, $siteTz);
			}
			if (!$time) {
				$time = \DateTime::createFromFormat('Y-m-d\TH:i', $timeStr, $siteTz);
			}
			if ($time) {
				$time->setTimezone($utc);
			}
		}
		if (!$time) {
			$this->setMessage('Укажите новое дату/время', 'error');
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

		$masterTimezone = self::getUserTimezoneById((int) $user->id, (string) Factory::getApplication()->get('offset', 'UTC'));
		$scheduleCheck = self::validateMasterSchedule((int) $user->id, $startUtc, $endUtc, $masterTimezone);
		if (!$scheduleCheck['ok']) {
			$this->setMessage((string) ($scheduleCheck['message'] ?? 'Выбранное время вне рабочего графика мастера'), 'error');
			$this->setRedirectAndExit();
			return;
		}

		$tableName = $db->getPrefix() . 'vigling_bookings';
		$excludeIds = self::getSearchSlotBookingIds($db, $searchSlotId, (int) $user->id);
		if (self::hasSearchSlotsOverlap($db, (int) $user->id, $timeDb, $timeToDb, $searchSlotId)) {
			$this->setMessage('Это время занято другим поиском', 'error');
			$this->setRedirectAndExit();
			return;
		}
		if (self::hasCourseSlotsOverlap($db, (int) $user->id, $timeDb, $timeToDb, 0)) {
			$this->setMessage('Это время занято курсом', 'error');
			$this->setRedirectAndExit();
			return;
		}
		if (self::hasBookingsOverlap($db, $tableName, (int) $user->id, $timeDb, $timeToDb, 0, $excludeIds)) {
			$this->setMessage('Это время уже занято', 'error');
			$this->setRedirectAndExit();
			return;
		}

		$dispatcher = Factory::getContainer()->get(\Joomla\Event\DispatcherInterface::class);
		$table = new OrderTable($db, $dispatcher);
		foreach ($excludeIds as $bookingId) {
			if (!$table->load((int) $bookingId)) {
				continue;
			}
			if ((int) $table->master_id !== (int) $user->id) {
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
		$this->setMessage('Поиск моделей перенесён для всех участников');
		$this->setRedirectAndExit();
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
		$this->setMessage('Запись отмечена как выполненная');
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

		$startUtcStr = trim((string) $this->input->get('time_utc', '', 'string'));
		$durationRaw = trim((string) $this->input->get('duration', '', 'string'));
		$commentRaw = trim((string) $this->input->get('comment', '', 'string'));
		$start = self::parseUtcDateTime($startUtcStr);
		if (!$start) {
			$this->setMessage('Выберите время в журнале', 'error');
			$this->setRedirectAndExit();
			return;
		}

		$durationMin = self::parseDurationMinutes($durationRaw);
		if ($durationMin <= 0) {
			$durationMin = 60;
		}
		$durationMin = max(15, min(720, $durationMin));

		$utc = new \DateTimeZone('UTC');
		$startUtc = $start;
		$endUtc = $startUtc->modify('+' . $durationMin . ' minutes');
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
		if (self::hasBookingsOverlap($db, $tableName, (int) $user->id, $startDb, $endDb, 0)) {
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

		$serviceName = '[journal] Блок времени';
		if ($comment !== '') {
			$serviceName .= ' | Комментарий: ' . $comment;
		}

		$query = $db->getQuery(true)
			->insert($db->quoteName($tableName))
			->columns($db->quoteName(['user_id', 'master_id', 'time', 'time_to', 'service_name', 'completed', 'time_sum']))
			->values(
				'0, '
				. (int) $user->id . ', '
				. $db->quote($startDb) . ', '
				. $db->quote($endDb) . ', '
				. $db->quote($serviceName) . ', '
				. '0, '
				. (int) $durationMin
			);

		try {
			$db->setQuery($query)->execute();
		} catch (\Throwable $e) {
			$this->setMessage('Не удалось сохранить блок времени', 'error');
			$this->setRedirectAndExit();
			return;
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

		$this->setMessage('Блок удалён');
		$this->setRedirectAndExit();
	}

	private function setRedirectAndExit()
	{
		$return = $this->input->get('return', '', 'base64');
		$url = $return ? base64_decode($return) : Uri::base() . 'index.php?option=com_orders&view=orders';
		if (!Uri::isInternal($url)) {
			$url = Uri::base() . 'index.php?option=com_orders&view=orders';
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
			->where($db->quoteName('time_to') . ' > ' . $db->quote($startUtc));
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
		$groups = method_exists($user, 'getAuthorisedGroups') ? (array) $user->getAuthorisedGroups() : [];
		return in_array(3, $groups, true) || in_array(8, $groups, true);
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

		$workDays = self::decodeIntList($raw['work_day']);
		if ($workDays === []) {
			return [];
		}
		$workFrom = self::decodeStringList($raw['work_from']);
		$workTo = self::decodeStringList($raw['work_to']);

		$fromByDay = array_fill(1, 7, '');
		$toByDay = array_fill(1, 7, '');
		if (count($workFrom) === 1) {
			foreach ($workDays as $wd) {
				$fromByDay[$wd] = $workFrom[0];
			}
		} elseif (count($workFrom) === count($workDays)) {
			foreach ($workDays as $idx => $wd) {
				$fromByDay[$wd] = (string) ($workFrom[$idx] ?? '');
			}
		}
		if (count($workTo) === 1) {
			foreach ($workDays as $wd) {
				$toByDay[$wd] = $workTo[0];
			}
		} elseif (count($workTo) === count($workDays)) {
			foreach ($workDays as $idx => $wd) {
				$toByDay[$wd] = (string) ($workTo[$idx] ?? '');
			}
		}

		$result = [];
		foreach ($workDays as $wd) {
			$fromMin = self::parseTimeToMinutes((string) ($fromByDay[$wd] ?? ''));
			$toMin = self::parseTimeToMinutes((string) ($toByDay[$wd] ?? ''));
			if ($fromMin === null || $toMin === null || $toMin <= $fromMin) {
				continue;
			}
			$result[$wd] = [$fromMin, $toMin];
		}
		return $result;
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
}
