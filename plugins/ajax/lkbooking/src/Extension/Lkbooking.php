<?php

namespace Viglin\Plugin\Ajax\Lkbooking\Extension;

\defined('_JEXEC') or die;

use Joomla\CMS\Event\Plugin\AjaxEvent;
use Joomla\CMS\Factory;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Session\Session;
use Joomla\Event\SubscriberInterface;
use Viglin\Component\Orders\Site\Table\OrderTable;
use Viglin\Plugin\System\Pushnotifybooking\Helper\BookingNotifyHelper;

final class Lkbooking extends CMSPlugin implements SubscriberInterface
{
	public static function getSubscribedEvents(): array
	{
		return [
			'onAjaxLkbooking' => 'onAjaxLkbooking',
		];
	}

	public function onAjaxLkbooking(AjaxEvent $event): void
	{
		$app = $event->getApplication();
		$input = $app->getInput();

		if (!Session::checkToken('request')) {
			$event->updateEventResult(['success' => false, 'message' => 'Неверный токен']);
			return;
		}

		$user = $app->getIdentity();
		if (!$user->id) {
			$event->updateEventResult(['success' => false, 'message' => 'Нужна авторизация']);
			return;
		}

		$masterId = (int) $input->post->get('master_id', 0);
		$bookingKind = trim((string) $input->post->get('booking_kind', 'service', 'string'));
		$stockServiceId = (int) $input->post->get('stock_service_id', 0);
		$courseId = (int) $input->post->get('course_id', 0);
		$courseSlotId = (int) $input->post->get('course_slot_id', 0);
		$searchId = (int) $input->post->get('search_id', 0);
		$searchSlotId = (int) $input->post->get('search_slot_id', 0);
		$serviceName = trim((string) $input->post->get('service_name', '', 'string'));
		$timeUtcStr = trim((string) $input->post->get('time_utc', '', 'string'));
		$userTz = trim((string) $input->post->get('timezone', '', 'string'));
		if ($userTz !== '') {
			try {
				new \DateTimeZone($userTz);
				$db = Factory::getContainer()->get(\Joomla\Database\DatabaseInterface::class);
				$userTable = new \Joomla\CMS\Table\User($db);
				if ($userTable->load($user->id)) {
					$params = json_decode($userTable->params ?? '{}', true);
					if (!is_array($params)) $params = [];
					$params['timezone'] = $userTz;
					$userTable->params = json_encode($params);
					$userTable->store();
				}
			} catch (\Throwable $e) {
			}
		}
		$durationMin = (int) $input->post->get('duration_min', 60);

		if ($masterId <= 0) {
			$event->updateEventResult(['success' => false, 'message' => 'Не указан мастер']);
			return;
		}
		if ($bookingKind !== 'course' && $bookingKind !== 'search' && $serviceName === '') {
			$event->updateEventResult(['success' => false, 'message' => 'Выберите или введите услугу']);
			return;
		}
		$masterTimezone = self::getUserTimezoneById($masterId, (string) $app->get('offset', 'UTC'));

		$utc = new \DateTimeZone('UTC');
		if ($timeUtcStr !== '') {
			$time = \DateTime::createFromFormat(\DateTimeInterface::ATOM, $timeUtcStr);
			if (!$time) {
				$time = new \DateTime($timeUtcStr, $utc);
			}
			if (!$time) {
				$event->updateEventResult(['success' => false, 'message' => 'Неверный формат даты и времени']);
				return;
			}
			$time->setTimezone($utc);
		} else {
			$timeStr = trim((string) $input->post->get('time', '', 'string'));
			if ($timeStr === '') {
				$bookingDate = trim((string) $input->post->get('booking_date', '', 'string'));
				$bookingTime = trim((string) $input->post->get('booking_time', '', 'string'));
				if ($bookingDate !== '' && $bookingTime !== '') {
					$timeStr = $bookingDate . ' ' . $bookingTime;
				}
			}
			if ($timeStr === '') {
				$event->updateEventResult(['success' => false, 'message' => 'Укажите дату и время']);
				return;
			}
			$masterTz = new \DateTimeZone($masterTimezone);
			$time = \DateTime::createFromFormat('Y-m-d H:i:s', $timeStr, $masterTz);
			if (!$time) {
				$time = \DateTime::createFromFormat('Y-m-d H:i', $timeStr, $masterTz);
			}
			if (!$time) {
				$time = \DateTime::createFromFormat('d.m.Y H:i', $timeStr, $masterTz);
			}
			if (!$time) {
				$event->updateEventResult(['success' => false, 'message' => 'Неверный формат даты и времени']);
				return;
			}
			$time->setTimezone($utc);
		}
		$timeTo = clone $time;
		$timeTo->modify('+' . max(15, min(480, $durationMin)) . ' minutes');
		$timeDb = $time->format('Y-m-d H:i:s');
		$timeToDb = $timeTo->format('Y-m-d H:i:s');

		$db = Factory::getContainer()->get(\Joomla\Database\DatabaseInterface::class);
		$prefix = $db->getPrefix();
		$table = $prefix . 'vigling_bookings';

		$db->setQuery('SHOW TABLES LIKE ' . $db->quote($table));
		if (!$db->loadResult()) {
			$event->updateEventResult(['success' => false, 'message' => 'Таблица ' . $table . ' не найдена. Выполните миграцию бронирований.']);
			return;
		}
		$tableColumns = array_change_key_case($db->getTableColumns('#__vigling_bookings', false), CASE_LOWER);
		self::ensureOrderTableLoaded();
		if (class_exists(OrderTable::class) && OrderTable::ensureBookingCommentColumns($db)) {
			$tableColumns = array_change_key_case($db->getTableColumns('#__vigling_bookings', false), CASE_LOWER);
		}
		$bookingExtras = self::bookingExtrasFromInput($input);
		$hasCourseBookingColumns = isset($tableColumns['booking_kind'], $tableColumns['course_id'], $tableColumns['course_slot_id']);
		$hasSearchBookingColumns = $hasCourseBookingColumns && isset($tableColumns['search_id'], $tableColumns['search_slot_id']);

		$bookingKind = in_array($bookingKind, ['service', 'stock', 'course', 'search', 'journal'], true) ? $bookingKind : 'service';
		if ($bookingKind === 'course' && !$hasCourseBookingColumns) {
			$event->updateEventResult(['success' => false, 'message' => 'Для записи на курс не выполнена миграция booking-схемы']);
			return;
		}
		if ($bookingKind === 'search' && !$hasSearchBookingColumns) {
			$event->updateEventResult(['success' => false, 'message' => 'Для записи на поиск не выполнена миграция booking-схемы']);
			return;
		}
		if ($bookingKind === 'stock' && $stockServiceId <= 0) {
			$event->updateEventResult(['success' => false, 'message' => 'Не удалось определить акцию для записи']);
			return;
		}
		if ($bookingKind === 'stock') {
			self::ensureOrderTableLoaded();
			if (class_exists(OrderTable::class) && OrderTable::ensureStockServiceIdColumn($db)) {
				$tableColumns['stock_service_id'] = true;
			}
		}

		$courseContext = null;
		$searchContext = null;
		if ($bookingKind === 'course') {
			if ($courseId <= 0) {
				$event->updateEventResult(['success' => false, 'message' => 'Не указан курс']);
				return;
			}
			$courseContext = self::loadCourseContext($db, $courseId, $masterId, $courseSlotId);
			if ($courseContext === null) {
				$event->updateEventResult(['success' => false, 'message' => 'Курс или слот курса не найден']);
				return;
			}
			$courseMode = trim((string) ($courseContext['booking_mode'] ?? 'free'));
			if ($courseMode === 'fixed' && $courseSlotId <= 0) {
				$event->updateEventResult(['success' => false, 'message' => 'Для fixed-курса нужно выбрать слот курса']);
				return;
			}
			if ($courseMode !== 'fixed' && $courseSlotId > 0) {
				$courseSlotId = 0;
			}
			if ($serviceName === '') {
				$serviceName = (string) ($courseContext['service_name'] ?? 'Курс');
			}
			if (self::hasUserCourseBooking($db, (int) $user->id, $courseId)) {
				$event->updateEventResult(['success' => false, 'message' => 'Вы уже записаны на этот курс']);
				return;
			}
			if ($courseSlotId > 0) {
				$slotStartUtc = trim((string) ($courseContext['slot_start_utc'] ?? ''));
				$slotEndUtc = trim((string) ($courseContext['slot_end_utc'] ?? ''));
				if ($slotStartUtc === '' || $slotEndUtc === '') {
					$event->updateEventResult(['success' => false, 'message' => 'У курса не задано корректное время проведения']);
					return;
				}
				try {
					$time = new \DateTime($slotStartUtc, $utc);
					$time->setTimezone($utc);
					$timeTo = new \DateTime($slotEndUtc, $utc);
					$timeTo->setTimezone($utc);
					$durationMin = max(15, (int) round(($timeTo->getTimestamp() - $time->getTimestamp()) / 60));
					$timeDb = $time->format('Y-m-d H:i:s');
					$timeToDb = $timeTo->format('Y-m-d H:i:s');
				} catch (\Throwable $e) {
					$event->updateEventResult(['success' => false, 'message' => 'Неверное время слота курса']);
					return;
				}
			}
		}
		if ($bookingKind === 'search') {
			if ($searchId <= 0) {
				$event->updateEventResult(['success' => false, 'message' => 'Не указано предложение поиска']);
				return;
			}
			$searchContext = self::loadSearchContext($db, $searchId, $masterId, $searchSlotId);
			if ($searchContext === null) {
				$event->updateEventResult(['success' => false, 'message' => 'Предложение поиска или слот не найден']);
				return;
			}
			$searchMode = trim((string) ($searchContext['booking_mode'] ?? 'free'));
			if ($searchMode === 'fixed' && $searchSlotId <= 0) {
				$event->updateEventResult(['success' => false, 'message' => 'Для fixed-поиска нужно выбрать слот']);
				return;
			}
			if ($searchMode !== 'fixed' && $searchSlotId > 0) {
				$searchSlotId = 0;
			}
			if ($serviceName === '') {
				$serviceName = (string) ($searchContext['service_name'] ?? 'Поиск моделей');
			}
			if (self::hasUserSearchBooking($db, (int) $user->id, $searchId)) {
				$event->updateEventResult(['success' => false, 'message' => 'Вы уже записаны на это предложение поиска']);
				return;
			}
			if ($searchSlotId > 0) {
				$slotStartUtc = trim((string) ($searchContext['slot_start_utc'] ?? ''));
				$slotEndUtc = trim((string) ($searchContext['slot_end_utc'] ?? ''));
				if ($slotStartUtc === '' || $slotEndUtc === '') {
					$event->updateEventResult(['success' => false, 'message' => 'У предложения поиска не задано корректное время проведения']);
					return;
				}
				try {
					$time = new \DateTime($slotStartUtc, $utc);
					$time->setTimezone($utc);
					$timeTo = new \DateTime($slotEndUtc, $utc);
					$timeTo->setTimezone($utc);
					$durationMin = max(15, (int) round(($timeTo->getTimestamp() - $time->getTimestamp()) / 60));
					$timeDb = $time->format('Y-m-d H:i:s');
					$timeToDb = $timeTo->format('Y-m-d H:i:s');
				} catch (\Throwable $e) {
					$event->updateEventResult(['success' => false, 'message' => 'Неверное время слота поиска']);
					return;
				}
			}
		}

		$nowUtc = new \DateTimeImmutable('now', $utc);
		$startUtc = \DateTimeImmutable::createFromMutable($time);
		$endUtc = \DateTimeImmutable::createFromMutable($timeTo);
		if ($startUtc <= $nowUtc) {
			$event->updateEventResult(['success' => false, 'message' => 'Нельзя записаться на прошедшее время']);
			return;
		}

		$scheduleCheck = self::validateMasterSchedule($masterId, $startUtc, $endUtc, $masterTimezone);
		if (!$scheduleCheck['ok']) {
			$event->updateEventResult(['success' => false, 'message' => (string) ($scheduleCheck['message'] ?? 'Время вне расписания мастера')]);
			return;
		}

		$conflict = self::bookingCreateConflictMessage(
			$db,
			$table,
			(int) $user->id,
			$masterId,
			$bookingKind,
			$courseId,
			$courseSlotId,
			$searchId,
			$searchSlotId,
			$timeDb,
			$timeToDb,
			$courseContext,
			$searchContext,
			$hasCourseBookingColumns,
			$hasSearchBookingColumns
		);
		if ($conflict !== null) {
			$event->updateEventResult(['success' => false, 'message' => $conflict]);
			return;
		}

		$columns = ['user_id', 'master_id', 'time', 'time_to', 'service_name'];
		$values = [(int) $user->id, $masterId, $db->quote($timeDb), $db->quote($timeToDb), $db->quote($serviceName)];
		$extraColumnValues = [
			'comment' => $bookingExtras['comment'],
			'contact_name' => $bookingExtras['contact_name'],
			'contact_phone' => $bookingExtras['contact_phone'],
		];
		foreach ($extraColumnValues as $columnName => $columnValue) {
			if (isset($tableColumns[$columnName]) && $columnValue !== '') {
				$columns[] = $columnName;
				$values[] = $db->quote($columnValue);
			}
		}
		$optionalColumnValues = [
			'svc_id' => (int) $input->post->get('svc_id', 0),
			'tag_id' => (int) $input->post->get('tag_id', 0),
			'price' => max(0, (int) $input->post->get('price', 0)),
			'time_sum' => max(0, (int) $input->post->get('time_sum', 0)),
		];
		foreach ($optionalColumnValues as $columnName => $columnValue) {
			if (isset($tableColumns[$columnName]) && $columnValue > 0) {
				$columns[] = $columnName;
				$values[] = (string) $columnValue;
			}
		}
		if ($hasCourseBookingColumns) {
			$columns[] = 'booking_kind';
			$values[] = $db->quote($bookingKind);
			$columns[] = 'course_id';
			$values[] = $courseId > 0 ? (string) $courseId : 'NULL';
			$columns[] = 'course_slot_id';
			$values[] = $courseSlotId > 0 ? (string) $courseSlotId : 'NULL';
		}
		if ($hasSearchBookingColumns) {
			$columns[] = 'search_id';
			$values[] = $searchId > 0 ? (string) $searchId : 'NULL';
			$columns[] = 'search_slot_id';
			$values[] = $searchSlotId > 0 ? (string) $searchSlotId : 'NULL';
		}
		if ($bookingKind === 'stock' && $stockServiceId > 0 && isset($tableColumns['stock_service_id'])) {
			$columns[] = 'stock_service_id';
			$values[] = (string) $stockServiceId;
		}

		$lockHeld = false;
		$transactionStarted = false;
		$insertedId = 0;
		try {
			self::ensureOrderTableLoaded();
			if (!class_exists(OrderTable::class) || !OrderTable::acquireMasterBookingLock($db, $masterId)) {
				$event->updateEventResult(['success' => false, 'message' => 'Сейчас идёт другая запись к этому специалисту, попробуйте ещё раз']);
				return;
			}
			$lockHeld = true;

			$db->transactionStart();
			$transactionStarted = true;
			self::lockBookingParents($db, $bookingKind, $masterId, $courseId, $courseSlotId, $searchId, $searchSlotId);

			$conflict = self::bookingCreateConflictMessage(
				$db,
				$table,
				(int) $user->id,
				$masterId,
				$bookingKind,
				$courseId,
				$courseSlotId,
				$searchId,
				$searchSlotId,
				$timeDb,
				$timeToDb,
				$courseContext,
				$searchContext,
				$hasCourseBookingColumns,
				$hasSearchBookingColumns
			);
			if ($conflict !== null) {
				$db->transactionRollback();
				$transactionStarted = false;
				$event->updateEventResult(['success' => false, 'message' => $conflict]);
				return;
			}

			if ($bookingKind === 'stock') {
				self::reserveStockOffer($db, $stockServiceId, $masterId);
			}
			$db->setQuery(
				'INSERT INTO ' . $db->quoteName($table) . ' (' . implode(', ', array_map([$db, 'quoteName'], $columns)) . ') VALUES (' . implode(', ', $values) . ')'
			)->execute();
			$insertedId = (int) $db->insertid();
			$db->transactionCommit();
			$transactionStarted = false;
		} catch (\Throwable $e) {
			if ($transactionStarted) {
				try {
					$db->transactionRollback();
				} catch (\Throwable $rollbackError) {
				}
			}
			$msg = $e->getMessage();
			if (strpos($msg, 'Duplicate entry') !== false) {
				if (strpos($msg, 'uniq_user_course_id') !== false) {
					$msg = 'Вы уже записаны на этот курс';
				} elseif (strpos($msg, 'uniq_user_search_id') !== false) {
					$msg = 'Вы уже записаны на это предложение поиска';
				} else {
					$msg = 'Запись на это время уже есть';
				}
			}
			if ($msg === 'stock-sold-out') {
				$msg = 'Акция закончилась';
			} elseif ($msg === 'stock-not-found') {
				$msg = 'Акция не найдена';
			} elseif ($msg === 'course-not-found') {
				$msg = 'Курс или слот курса не найден';
			} elseif ($msg === 'search-not-found') {
				$msg = 'Предложение поиска или слот не найден';
			}
			$event->updateEventResult(['success' => false, 'message' => 'Ошибка сохранения: ' . $msg]);
			return;
		} finally {
			if ($lockHeld) {
				OrderTable::releaseMasterBookingLock($db, $masterId);
			}
		}

		$order = [
			'id'           => $insertedId,
			'user_id'      => (int) $user->id,
			'master_id'    => $masterId,
			'time'         => $timeDb,
			'time_to'      => $timeToDb,
			'service_name' => $serviceName,
			'booking_kind' => $bookingKind,
			'course_id'    => $courseId > 0 ? $courseId : null,
			'course_slot_id' => $courseSlotId > 0 ? $courseSlotId : null,
			'search_id'    => $searchId > 0 ? $searchId : null,
			'search_slot_id' => $searchSlotId > 0 ? $searchSlotId : null,
		];

		if (class_exists(BookingNotifyHelper::class)) {
			BookingNotifyHelper::notifyConfirmedOrRescheduled($order);
		}

		$event->updateEventResult(['success' => true, 'message' => 'Вы записались!']);
	}

	private static function ensureOrderTableLoaded(): void
	{
		if (class_exists(OrderTable::class)) {
			return;
		}
		$path = JPATH_ROOT . '/components/com_orders/src/Table/OrderTable.php';
		if (is_file($path)) {
			require_once $path;
		}
	}

	/**
	 * @param array<string,mixed>|null $courseContext
	 * @param array<string,mixed>|null $searchContext
	 */
	private static function bookingCreateConflictMessage(
		\Joomla\Database\DatabaseInterface $db,
		string $tableName,
		int $userId,
		int $masterId,
		string $bookingKind,
		int $courseId,
		int $courseSlotId,
		int $searchId,
		int $searchSlotId,
		string $timeDb,
		string $timeToDb,
		?array $courseContext,
		?array $searchContext,
		bool $hasCourseBookingColumns,
		bool $hasSearchBookingColumns
	): ?string {
		if ($bookingKind === 'course' && self::hasUserCourseBooking($db, $userId, $courseId)) {
			return 'Вы уже записаны на этот курс';
		}
		if ($bookingKind === 'search' && self::hasUserSearchBooking($db, $userId, $searchId)) {
			return 'Вы уже записаны на это предложение поиска';
		}

		if ($bookingKind === 'course' && $courseSlotId > 0) {
			$capacityTotal = (int) ($courseContext['slot_capacity_total'] ?? 0);
			if ($capacityTotal > 0 && self::countCourseBookings($db, $courseId, $courseSlotId) >= $capacityTotal) {
				return 'На курсе больше нет свободных мест';
			}
		} elseif ($bookingKind === 'course') {
			$capacityTotal = (int) ($courseContext['capacity'] ?? 0);
			if ($capacityTotal > 0 && self::countCourseBookings($db, $courseId, 0) >= $capacityTotal) {
				return 'На курсе больше нет свободных мест';
			}
			$concurrent = max(1, (int) ($courseContext['concurrent_participants'] ?? 1));
			if ($capacityTotal > 0) {
				$concurrent = min($concurrent, $capacityTotal);
			}
			if (self::countCourseBookingsOverlapOtherStart($db, $courseId, $timeDb, $timeToDb) > 0) {
				return 'Это время занято курсом';
			}
			if (self::countCourseBookingsAtStart($db, $courseId, $timeDb) >= $concurrent) {
				return 'На это время больше нет мест в группе';
			}
		}

		if ($bookingKind === 'search' && $searchSlotId > 0) {
			$capacityTotal = (int) ($searchContext['slot_capacity_total'] ?? 0);
			if ($capacityTotal > 0 && self::countSearchBookings($db, $searchId, $searchSlotId) >= $capacityTotal) {
				return 'На этом предложении поиска больше нет свободных мест';
			}
		} elseif ($bookingKind === 'search') {
			$capacityTotal = (int) ($searchContext['capacity'] ?? 0);
			if ($capacityTotal > 0 && self::countSearchBookings($db, $searchId, 0) >= $capacityTotal) {
				return 'На этом предложении поиска больше нет свободных мест';
			}
		}

		if (self::hasCourseSlotsOverlap($db, $masterId, $timeDb, $timeToDb, $bookingKind === 'course' ? $courseSlotId : 0)) {
			return $bookingKind === 'course' ? 'На данное время запланирован другой курс' : 'Это время занято курсом';
		}
		if (self::hasSearchSlotsOverlap($db, $masterId, $timeDb, $timeToDb, $bookingKind === 'search' ? $searchSlotId : 0)) {
			return $bookingKind === 'search' ? 'На данное время запланирован другой поиск' : 'Это время занято поиском';
		}
		if (self::hasBookingsOverlap($db, $tableName, $masterId, $timeDb, $timeToDb, $bookingKind, $courseSlotId, $searchSlotId, $hasCourseBookingColumns, $hasSearchBookingColumns, $courseId)) {
			return ($bookingKind === 'course' || $bookingKind === 'search') ? 'На данное время запланировано другое' : 'Это время уже занято';
		}

		return null;
	}

	private static function lockBookingParents(
		\Joomla\Database\DatabaseInterface $db,
		string $bookingKind,
		int $masterId,
		int $courseId,
		int $courseSlotId,
		int $searchId,
		int $searchSlotId
	): void {
		if ($bookingKind === 'course' && $courseId > 0) {
			$db->setQuery(
				'SELECT ' . $db->quoteName('id')
				. ' FROM ' . $db->quoteName('#__vigling_user_courses')
				. ' WHERE ' . $db->quoteName('id') . ' = ' . $courseId
				. ' AND ' . $db->quoteName('user_id') . ' = ' . $masterId
				. ' FOR UPDATE'
			);
			if (!(int) $db->loadResult()) {
				throw new \RuntimeException('course-not-found');
			}
			if ($courseSlotId > 0) {
				$db->setQuery(
					'SELECT ' . $db->quoteName('id')
					. ' FROM ' . $db->quoteName('#__vigling_course_slots')
					. ' WHERE ' . $db->quoteName('id') . ' = ' . $courseSlotId
					. ' AND ' . $db->quoteName('course_id') . ' = ' . $courseId
					. ' FOR UPDATE'
				);
				if (!(int) $db->loadResult()) {
					throw new \RuntimeException('course-not-found');
				}
			}
		}
		if ($bookingKind === 'search' && $searchId > 0) {
			$db->setQuery(
				'SELECT ' . $db->quoteName('id')
				. ' FROM ' . $db->quoteName('#__vigling_user_searches')
				. ' WHERE ' . $db->quoteName('id') . ' = ' . $searchId
				. ' AND ' . $db->quoteName('user_id') . ' = ' . $masterId
				. ' FOR UPDATE'
			);
			if (!(int) $db->loadResult()) {
				throw new \RuntimeException('search-not-found');
			}
			if ($searchSlotId > 0) {
				$db->setQuery(
					'SELECT ' . $db->quoteName('id')
					. ' FROM ' . $db->quoteName('#__vigling_search_slots')
					. ' WHERE ' . $db->quoteName('id') . ' = ' . $searchSlotId
					. ' AND ' . $db->quoteName('search_id') . ' = ' . $searchId
					. ' FOR UPDATE'
				);
				if (!(int) $db->loadResult()) {
					throw new \RuntimeException('search-not-found');
				}
			}
		}
	}

	private static function reserveStockOffer(\Joomla\Database\DatabaseInterface $db, int $stockServiceId, int $masterId): void
	{
		$query = $db->getQuery(true)
			->select([$db->quoteName('id'), $db->quoteName('count_stock')])
			->from($db->quoteName('#__vigling_user_stock_services'))
			->where($db->quoteName('id') . ' = ' . (int) $stockServiceId)
			->where($db->quoteName('user_id') . ' = ' . (int) $masterId)
			->where($db->quoteName('is_active') . ' = 1')
			->setLimit(1);
		$db->setQuery($query);
		$row = $db->loadObject();
		if (!$row) {
			throw new \RuntimeException('stock-not-found');
		}
		$countStock = $row->count_stock ?? 0;
		if ((int) $countStock <= 0) {
			throw new \RuntimeException('stock-sold-out');
		}

		$query = $db->getQuery(true)
			->update($db->quoteName('#__vigling_user_stock_services'))
			->set($db->quoteName('count_stock') . ' = ' . $db->quoteName('count_stock') . ' - 1')
			->where($db->quoteName('id') . ' = ' . (int) $stockServiceId)
			->where($db->quoteName('user_id') . ' = ' . (int) $masterId)
			->where($db->quoteName('is_active') . ' = 1')
			->where($db->quoteName('count_stock') . ' > 0');
		$db->setQuery($query)->execute();
		if ((int) $db->getAffectedRows() !== 1) {
			throw new \RuntimeException('stock-sold-out');
		}
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

	private static function hasBookingsOverlap(\Joomla\Database\DatabaseInterface $db, string $tableName, int $masterId, string $startUtc, string $endUtc, string $bookingKind = 'service', int $courseSlotId = 0, int $searchSlotId = 0, bool $hasCourseBookingColumns = false, bool $hasSearchBookingColumns = false, int $courseId = 0): bool
	{
		$query = $db->getQuery(true)
			->select('COUNT(*)')
			->from($db->quoteName($tableName))
			->where($db->quoteName('master_id') . ' = ' . (int) $masterId)
			->where($db->quoteName('time') . ' < ' . $db->quote($endUtc))
			->where($db->quoteName('time_to') . ' > ' . $db->quote($startUtc));
		if ($hasCourseBookingColumns && $bookingKind === 'course' && $courseId > 0) {
			$query->where(
				'NOT (' .
				$db->quoteName('booking_kind') . ' = ' . $db->quote('course') .
				' AND ' . $db->quoteName('course_id') . ' = ' . (int) $courseId .
				')'
			);
		} elseif ($hasCourseBookingColumns && $bookingKind === 'course' && $courseSlotId > 0) {
			$query->where(
				'NOT (' .
				$db->quoteName('booking_kind') . ' = ' . $db->quote('course') .
				' AND ' . $db->quoteName('course_slot_id') . ' = ' . (int) $courseSlotId .
				')'
			);
		}
		if ($hasSearchBookingColumns && $bookingKind === 'search' && $searchSlotId > 0) {
			$query->where(
				'NOT (' .
				$db->quoteName('booking_kind') . ' = ' . $db->quote('search') .
				' AND ' . $db->quoteName('search_slot_id') . ' = ' . (int) $searchSlotId .
				')'
			);
		}
		$db->setQuery($query);
		return ((int) $db->loadResult()) > 0;
	}

	private static function hasCourseSlotsOverlap(\Joomla\Database\DatabaseInterface $db, int $masterId, string $startUtc, string $endUtc, int $excludeCourseSlotId = 0): bool
	{
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

	private static function hasSearchSlotsOverlap(\Joomla\Database\DatabaseInterface $db, int $masterId, string $startUtc, string $endUtc, int $excludeSearchSlotId = 0): bool
	{
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

	/**
	 * @return array<string,mixed>|null
	 */
	private static function loadCourseContext(\Joomla\Database\DatabaseInterface $db, int $courseId, int $masterId, int $courseSlotId = 0): ?array
	{
		if ($courseId <= 0 || $masterId <= 0) {
			return null;
		}

		$query = $db->getQuery(true)
			->select([
				$db->quoteName('c.id'),
				$db->quoteName('c.user_id'),
				$db->quoteName('c.title'),
				$db->quoteName('c.description'),
				$db->quoteName('c.duration_min'),
				$db->quoteName('c.capacity'),
				$db->quoteName('c.booking_mode'),
				$db->quoteName('slot.id', 'slot_id'),
				$db->quoteName('slot.starts_at_utc', 'slot_start_utc'),
				$db->quoteName('slot.ends_at_utc', 'slot_end_utc'),
				$db->quoteName('slot.capacity_total', 'slot_capacity_total'),
			]);
		if (class_exists('\\Joomla\\Plugin\\User\\Vigling\\Service\\UserCoursesService')
			&& \Joomla\Plugin\User\Vigling\Service\UserCoursesService::ensureConcurrentParticipantsColumn($db)
		) {
			$query->select($db->quoteName('c.concurrent_participants'));
		}
		$query
			->from($db->quoteName('#__vigling_user_courses', 'c'))
			->join('LEFT', $db->quoteName('#__vigling_course_slots', 'slot') . ' ON ' . $db->quoteName('slot.course_id') . ' = ' . $db->quoteName('c.id') . ' AND ' . $db->quoteName('slot.is_active') . ' = 1')
			->where($db->quoteName('c.id') . ' = ' . (int) $courseId)
			->where($db->quoteName('c.user_id') . ' = ' . (int) $masterId)
			->where($db->quoteName('c.is_active') . ' = 1');
		if ($courseSlotId > 0) {
			$query->where($db->quoteName('slot.id') . ' = ' . (int) $courseSlotId);
		}
		$db->setQuery($query);
		$row = $db->loadAssoc();
		if (!$row) {
			return null;
		}

		$title = trim((string) ($row['title'] ?? $row['description'] ?? ''));
		$row['service_name'] = $title !== '' ? ('Курс: ' . $title) : 'Курс';
		$row['concurrent_participants'] = max(1, (int) ($row['concurrent_participants'] ?? 1));
		$capacity = max(1, (int) ($row['capacity'] ?? 1));
		if ($row['concurrent_participants'] > $capacity) {
			$row['concurrent_participants'] = $capacity;
		}

		return $row;
	}

	private static function countCourseBookingsAtStart(\Joomla\Database\DatabaseInterface $db, int $courseId, string $startUtc): int
	{
		if ($courseId <= 0 || trim($startUtc) === '') {
			return 0;
		}

		$query = $db->getQuery(true)
			->select('COUNT(*)')
			->from($db->quoteName('#__vigling_bookings'))
			->where($db->quoteName('booking_kind') . ' = ' . $db->quote('course'))
			->where($db->quoteName('course_id') . ' = ' . (int) $courseId)
			->where($db->quoteName('time') . ' = ' . $db->quote($startUtc));
		$db->setQuery($query);

		return (int) $db->loadResult();
	}

	private static function countCourseBookingsOverlapOtherStart(\Joomla\Database\DatabaseInterface $db, int $courseId, string $startUtc, string $endUtc): int
	{
		if ($courseId <= 0 || trim($startUtc) === '' || trim($endUtc) === '') {
			return 0;
		}

		$query = $db->getQuery(true)
			->select('COUNT(*)')
			->from($db->quoteName('#__vigling_bookings'))
			->where($db->quoteName('booking_kind') . ' = ' . $db->quote('course'))
			->where($db->quoteName('course_id') . ' = ' . (int) $courseId)
			->where($db->quoteName('time') . ' < ' . $db->quote($endUtc))
			->where($db->quoteName('time_to') . ' > ' . $db->quote($startUtc))
			->where($db->quoteName('time') . ' <> ' . $db->quote($startUtc));
		$db->setQuery($query);

		return (int) $db->loadResult();
	}

	private static function countCourseBookings(\Joomla\Database\DatabaseInterface $db, int $courseId, int $courseSlotId = 0): int
	{
		if ($courseId <= 0) {
			return 0;
		}

		$query = $db->getQuery(true)
			->select('COUNT(*)')
			->from($db->quoteName('#__vigling_bookings'))
			->where($db->quoteName('booking_kind') . ' = ' . $db->quote('course'))
			->where($db->quoteName('course_id') . ' = ' . (int) $courseId);
		if ($courseSlotId > 0) {
			$query->where($db->quoteName('course_slot_id') . ' = ' . (int) $courseSlotId);
		}
		$db->setQuery($query);

		return (int) $db->loadResult();
	}

	/**
	 * @return array<string,mixed>|null
	 */
	private static function loadSearchContext(\Joomla\Database\DatabaseInterface $db, int $searchId, int $masterId, int $searchSlotId = 0): ?array
	{
		if ($searchId <= 0 || $masterId <= 0) {
			return null;
		}

		$query = $db->getQuery(true)
			->select([
				$db->quoteName('s.id'),
				$db->quoteName('s.user_id'),
				$db->quoteName('s.title'),
				$db->quoteName('s.description'),
				$db->quoteName('s.duration_min'),
				$db->quoteName('s.capacity'),
				$db->quoteName('s.booking_mode'),
				$db->quoteName('slot.id', 'slot_id'),
				$db->quoteName('slot.starts_at_utc', 'slot_start_utc'),
				$db->quoteName('slot.ends_at_utc', 'slot_end_utc'),
				$db->quoteName('slot.capacity_total', 'slot_capacity_total'),
			])
			->from($db->quoteName('#__vigling_user_searches', 's'))
			->join('LEFT', $db->quoteName('#__vigling_search_slots', 'slot') . ' ON ' . $db->quoteName('slot.search_id') . ' = ' . $db->quoteName('s.id') . ' AND ' . $db->quoteName('slot.is_active') . ' = 1')
			->where($db->quoteName('s.id') . ' = ' . (int) $searchId)
			->where($db->quoteName('s.user_id') . ' = ' . (int) $masterId)
			->where($db->quoteName('s.is_active') . ' = 1');
		if ($searchSlotId > 0) {
			$query->where($db->quoteName('slot.id') . ' = ' . (int) $searchSlotId);
		}
		$db->setQuery($query);
		$row = $db->loadAssoc();
		if (!$row) {
			return null;
		}

		$title = trim((string) ($row['title'] ?? $row['description'] ?? ''));
		$row['service_name'] = $title !== '' ? ('Поиск моделей: ' . $title) : 'Поиск моделей';

		return $row;
	}

	private static function countSearchBookings(\Joomla\Database\DatabaseInterface $db, int $searchId, int $searchSlotId = 0): int
	{
		if ($searchId <= 0) {
			return 0;
		}

		$query = $db->getQuery(true)
			->select('COUNT(*)')
			->from($db->quoteName('#__vigling_bookings'))
			->where($db->quoteName('booking_kind') . ' = ' . $db->quote('search'))
			->where($db->quoteName('search_id') . ' = ' . (int) $searchId);
		if ($searchSlotId > 0) {
			$query->where($db->quoteName('search_slot_id') . ' = ' . (int) $searchSlotId);
		}
		$db->setQuery($query);

		return (int) $db->loadResult();
	}

	private static function hasUserCourseBooking(\Joomla\Database\DatabaseInterface $db, int $userId, int $courseId): bool
	{
		if ($userId <= 0 || $courseId <= 0) {
			return false;
		}

		$query = $db->getQuery(true)
			->select('COUNT(*)')
			->from($db->quoteName('#__vigling_bookings'))
			->where($db->quoteName('user_id') . ' = ' . (int) $userId)
			->where($db->quoteName('booking_kind') . ' = ' . $db->quote('course'))
			->where($db->quoteName('course_id') . ' = ' . (int) $courseId);
		$db->setQuery($query);

		return ((int) $db->loadResult()) > 0;
	}

	private static function hasUserSearchBooking(\Joomla\Database\DatabaseInterface $db, int $userId, int $searchId): bool
	{
		if ($userId <= 0 || $searchId <= 0) {
			return false;
		}

		$query = $db->getQuery(true)
			->select('COUNT(*)')
			->from($db->quoteName('#__vigling_bookings'))
			->where($db->quoteName('user_id') . ' = ' . (int) $userId)
			->where($db->quoteName('booking_kind') . ' = ' . $db->quote('search'))
			->where($db->quoteName('search_id') . ' = ' . (int) $searchId);
		$db->setQuery($query);

		return ((int) $db->loadResult()) > 0;
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
		$vals = array_values(array_unique(array_filter($vals, static function (int $v): bool {
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
		$out = [];
		if (is_array($decoded)) {
			foreach ($decoded as $v) {
				if (is_scalar($v)) {
					$s = trim((string) $v);
					if ($s !== '') {
						$out[] = $s;
					}
				}
			}
		} else {
			$out[] = $raw;
		}
		return $out;
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
			$h = max(0, min(23, $h));
			$m = max(0, min(59, $m));
			return $h * 60 + $m;
		}
		return null;
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

	/**
	 * @return array{comment: string, contact_name: string, contact_phone: string}
	 */
	private static function bookingExtrasFromInput($input): array
	{
		$note = self::clipUtf8((string) $input->post->get('note', '', 'string'), 500);
		$name = self::clipUtf8((string) $input->post->get('name', '', 'string'), 150);
		if ($name === '') {
			$name = self::clipUtf8((string) $input->post->get('qa_name', '', 'string'), 150);
		}
		$phone = self::clipUtf8((string) $input->post->get('telefon', '', 'raw'), 50);
		if ($phone === '') {
			$phone = self::clipUtf8((string) $input->post->get('qa_phone', '', 'raw'), 50);
		}
		$phone = trim((string) preg_replace('/[^\d+\s()\-]/', '', $phone));
		$phone = self::clipUtf8($phone, 50);

		return [
			'comment' => $note,
			'contact_name' => $name,
			'contact_phone' => $phone,
		];
	}

	private static function clipUtf8(string $value, int $max): string
	{
		$value = trim((string) preg_replace('/\s+/u', ' ', $value));
		if ($value === '') {
			return '';
		}
		if (function_exists('mb_substr')) {
			return (string) mb_substr($value, 0, $max);
		}

		return substr($value, 0, $max);
	}
}
