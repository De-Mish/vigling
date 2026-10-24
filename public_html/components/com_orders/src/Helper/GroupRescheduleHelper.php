<?php

namespace Viglin\Component\Orders\Site\Helper;

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\Database\DatabaseInterface;
use Viglin\Component\Pushnotify\Site\Helper\CriticalNoticeHelper;

/**
 * Master-initiated move of a group slot (course or model search) that already has participants.
 *
 * The slot keeps its old time until the master confirms. Each participant answers with one of two
 * choices: agree to the new time, or leave the group (their booking is removed). On confirmation the
 * participants who agreed are moved and those who did not answer are removed.
 */
final class GroupRescheduleHelper
{
	public const KIND_COURSE = 'course';
	public const KIND_SEARCH = 'search';

	public const REF_PARTICIPANT = 'group_reschedule';
	public const REF_MASTER = 'group_reschedule_master';

	private static ?bool $available = null;

	public static function isAvailable(DatabaseInterface $db): bool
	{
		if (self::$available !== null) {
			return self::$available;
		}
		try {
			$prefix = $db->getPrefix();
			$ok = true;
			foreach (['vigling_group_reschedules', 'vigling_group_reschedule_replies'] as $name) {
				$db->setQuery('SHOW TABLES LIKE ' . $db->quote($prefix . $name));
				$ok = $ok && (bool) $db->loadResult();
			}
			self::$available = $ok;
		} catch (\Throwable $e) {
			self::$available = false;
		}

		return self::$available;
	}

	public static function slotColumn(string $kind): string
	{
		return $kind === self::KIND_SEARCH ? 'search_slot_id' : 'course_slot_id';
	}

	public static function kindLabel(string $kind): string
	{
		return $kind === self::KIND_SEARCH ? 'поиск моделей' : 'курс';
	}

	/**
	 * Bookings of other people in the slot. A master's own booking in the slot has no one to ask.
	 *
	 * @return array<int, array{id:int,user_id:int}>
	 */
	public static function participants(DatabaseInterface $db, string $kind, int $slotId, int $masterId): array
	{
		if ($slotId <= 0) {
			return [];
		}
		$query = $db->getQuery(true)
			->select($db->quoteName(['id', 'user_id']))
			->from($db->quoteName('#__vigling_bookings'))
			->where($db->quoteName('master_id') . ' = ' . $masterId)
			->where($db->quoteName('booking_kind') . ' = ' . $db->quote($kind))
			->where($db->quoteName(self::slotColumn($kind)) . ' = ' . $slotId)
			->where($db->quoteName('user_id') . ' > 0')
			->where($db->quoteName('user_id') . ' <> ' . $masterId);
		$db->setQuery($query);
		$rows = $db->loadAssocList() ?: [];

		return array_map(static function (array $row): array {
			return ['id' => (int) $row['id'], 'user_id' => (int) $row['user_id']];
		}, $rows);
	}

	/**
	 * Opens a request (replacing any earlier open request for the slot) and tells every participant.
	 *
	 * @param array<int, array{id:int,user_id:int}> $participants
	 * @return array{ok:bool,message:string,proposal_id:int,count:int}
	 */
	public static function createProposal(
		DatabaseInterface $db,
		string $kind,
		int $slotId,
		int $entityId,
		int $masterId,
		string $title,
		string $oldStartUtc,
		string $oldEndUtc,
		string $newStartUtc,
		string $newEndUtc,
		array $participants
	): array {
		if (!class_exists(AppointmentsHelper::class, false)) {
			require_once __DIR__ . '/AppointmentsHelper.php';
		}
		$now = self::nowUtc();
		$proposalId = 0;
		$started = false;
		try {
			$db->transactionStart();
			$started = true;

			$existing = self::openProposalForSlot($db, $kind, $slotId);
			if ($existing !== null) {
				self::closeProposal($db, (int) $existing['id'], 'cancelled');
			}

			$row = new \stdClass();
			$row->kind = $kind;
			$row->slot_id = $slotId;
			$row->entity_id = $entityId;
			$row->master_id = $masterId;
			$row->title = mb_substr($title, 0, 255);
			$row->old_start_utc = $oldStartUtc;
			$row->old_end_utc = $oldEndUtc;
			$row->new_start_utc = $newStartUtc;
			$row->new_end_utc = $newEndUtc;
			$row->status = 'open';
			$row->created_at = $now;
			$db->insertObject('#__vigling_group_reschedules', $row);
			$proposalId = (int) $db->insertid();

			foreach ($participants as $participant) {
				$reply = new \stdClass();
				$reply->proposal_id = $proposalId;
				$reply->booking_id = (int) $participant['id'];
				$reply->user_id = (int) $participant['user_id'];
				$reply->status = 'pending';
				$db->insertObject('#__vigling_group_reschedule_replies', $reply);
			}
			$db->transactionCommit();
		} catch (\Throwable $e) {
			if ($started) {
				try {
					$db->transactionRollback();
				} catch (\Throwable $rollbackError) {
				}
			}

			return ['ok' => false, 'message' => 'Не удалось отправить предложение о переносе. Попробуйте ещё раз.', 'proposal_id' => 0, 'count' => 0];
		}

		self::loadNotices();
		$masterName = self::userName($masterId);
		$label = self::kindLabel($kind);
		$url = AppointmentsHelper::profileUrl(['zapisi' => 'list']);
		foreach ($participants as $participant) {
			$uid = (int) $participant['user_id'];
			CriticalNoticeHelper::add(
				$uid,
				'group_reschedule',
				'Перенос: нужен ваш ответ',
				($masterName !== '' ? $masterName : 'Мастер') . ' предлагает перенести ' . $label . ' «' . $title . '».' . "\n"
					. 'Было: ' . CriticalNoticeHelper::formatTime($uid, $oldStartUtc) . "\n"
					. 'Станет: ' . CriticalNoticeHelper::formatTime($uid, $newStartUtc) . "\n"
					. 'Откройте «Мои записи» и выберите: согласиться на перенос или отказаться от записи.',
				$url,
				true,
				self::REF_PARTICIPANT,
				$proposalId
			);
		}

		return ['ok' => true, 'message' => '', 'proposal_id' => $proposalId, 'count' => count($participants)];
	}

	/**
	 * @return array<string,mixed>|null
	 */
	public static function loadProposal(DatabaseInterface $db, int $proposalId): ?array
	{
		if ($proposalId <= 0) {
			return null;
		}
		$db->setQuery(
			$db->getQuery(true)
				->select('*')
				->from($db->quoteName('#__vigling_group_reschedules'))
				->where($db->quoteName('id') . ' = ' . $proposalId)
		);
		$row = $db->loadAssoc();

		return is_array($row) ? $row : null;
	}

	/**
	 * @return array<string,mixed>|null
	 */
	public static function openProposalForSlot(DatabaseInterface $db, string $kind, int $slotId): ?array
	{
		$db->setQuery(
			$db->getQuery(true)
				->select('*')
				->from($db->quoteName('#__vigling_group_reschedules'))
				->where($db->quoteName('kind') . ' = ' . $db->quote($kind))
				->where($db->quoteName('slot_id') . ' = ' . $slotId)
				->where($db->quoteName('status') . ' = ' . $db->quote('open'))
				->order($db->quoteName('id') . ' DESC')
				->setLimit(1)
		);
		$row = $db->loadAssoc();

		return is_array($row) ? $row : null;
	}

	/**
	 * An open request that still has someone to ask. When everybody has left, it no longer blocks the slot.
	 *
	 * @return array<string,mixed>|null
	 */
	public static function blockingProposalForSlot(DatabaseInterface $db, string $kind, int $slotId): ?array
	{
		$proposal = self::openProposalForSlot($db, $kind, $slotId);
		if ($proposal === null || self::replies($db, (int) $proposal['id']) === []) {
			return null;
		}

		return $proposal;
	}

	/**
	 * @return array<int, array<string,mixed>>
	 */
	public static function replies(DatabaseInterface $db, int $proposalId): array
	{
		$db->setQuery(
			$db->getQuery(true)
				->select('r.*')
				->from($db->quoteName('#__vigling_group_reschedule_replies', 'r'))
				->join('INNER', $db->quoteName('#__vigling_bookings', 'b') . ' ON b.id = r.booking_id')
				->where('r.proposal_id = ' . $proposalId)
		);

		return $db->loadAssocList() ?: [];
	}

	/**
	 * The participant's own request together with the proposal it belongs to.
	 *
	 * @return array<string,mixed>|null
	 */
	public static function loadReplyForUser(DatabaseInterface $db, int $replyId, int $userId): ?array
	{
		if ($replyId <= 0 || $userId <= 0) {
			return null;
		}
		$query = $db->getQuery(true)
			->select([
				'r.id AS reply_id', 'r.proposal_id', 'r.booking_id', 'r.user_id', 'r.status AS reply_status',
				'p.kind', 'p.slot_id', 'p.entity_id', 'p.master_id', 'p.title',
				'p.old_start_utc', 'p.old_end_utc', 'p.new_start_utc', 'p.new_end_utc', 'p.status AS proposal_status',
			])
			->from($db->quoteName('#__vigling_group_reschedule_replies', 'r'))
			->join('INNER', $db->quoteName('#__vigling_group_reschedules', 'p') . ' ON p.id = r.proposal_id')
			->where('r.id = ' . $replyId)
			->where('r.user_id = ' . $userId);
		$db->setQuery($query);
		$row = $db->loadAssoc();

		return is_array($row) ? $row : null;
	}

	/**
	 * Requests waiting for the person's answer, keyed by booking id.
	 *
	 * @return array<int, array<string,mixed>>
	 */
	public static function pendingRepliesForUser(DatabaseInterface $db, int $userId): array
	{
		if ($userId <= 0 || !self::isAvailable($db)) {
			return [];
		}
		try {
			$query = $db->getQuery(true)
				->select([
					'r.id AS reply_id', 'r.proposal_id', 'r.booking_id',
					'p.kind', 'p.title', 'p.old_start_utc', 'p.new_start_utc', 'p.new_end_utc',
				])
				->from($db->quoteName('#__vigling_group_reschedule_replies', 'r'))
				->join('INNER', $db->quoteName('#__vigling_group_reschedules', 'p') . ' ON p.id = r.proposal_id')
				->join('INNER', $db->quoteName('#__vigling_bookings', 'b') . ' ON b.id = r.booking_id')
				->where('r.user_id = ' . $userId)
				->where('r.status = ' . $db->quote('pending'))
				->where('p.status = ' . $db->quote('open'));
			$db->setQuery($query);
			$rows = $db->loadAssocList() ?: [];
		} catch (\Throwable $e) {
			return [];
		}
		$byBooking = [];
		foreach ($rows as $row) {
			$byBooking[(int) $row['booking_id']] = $row;
		}

		return $byBooking;
	}

	/**
	 * Open requests of a master with answer counters, keyed by "kind:slot_id".
	 *
	 * @return array<string, array<string,mixed>>
	 */
	public static function openProposalsForMaster(DatabaseInterface $db, int $masterId): array
	{
		if ($masterId <= 0 || !self::isAvailable($db)) {
			return [];
		}
		try {
			$db->setQuery(
				$db->getQuery(true)
					->select('*')
					->from($db->quoteName('#__vigling_group_reschedules'))
					->where($db->quoteName('master_id') . ' = ' . $masterId)
					->where($db->quoteName('status') . ' = ' . $db->quote('open'))
			);
			$proposals = $db->loadAssocList() ?: [];
			$result = [];
			foreach ($proposals as $proposal) {
				$counts = ['pending' => 0, 'agreed' => 0, 'declined' => 0];
				$byBooking = [];
				foreach (self::replies($db, (int) $proposal['id']) as $reply) {
					$status = (string) $reply['status'];
					if (isset($counts[$status])) {
						$counts[$status]++;
					}
					$byBooking[(int) $reply['booking_id']] = $status;
				}
				$result[$proposal['kind'] . ':' . (int) $proposal['slot_id']] = [
					'proposal' => $proposal,
					'counts' => $counts,
					'by_booking' => $byBooking,
				];
			}

			return $result;
		} catch (\Throwable $e) {
			return [];
		}
	}

	public static function setReplyStatus(DatabaseInterface $db, int $replyId, string $status): void
	{
		$db->setQuery(
			$db->getQuery(true)
				->update($db->quoteName('#__vigling_group_reschedule_replies'))
				->set($db->quoteName('status') . ' = ' . $db->quote($status))
				->set($db->quoteName('responded_at') . ' = ' . $db->quote(self::nowUtc()))
				->where($db->quoteName('id') . ' = ' . $replyId)
		)->execute();
	}

	public static function closeProposal(DatabaseInterface $db, int $proposalId, string $status): void
	{
		$db->setQuery(
			$db->getQuery(true)
				->update($db->quoteName('#__vigling_group_reschedules'))
				->set($db->quoteName('status') . ' = ' . $db->quote($status))
				->set($db->quoteName('resolved_at') . ' = ' . $db->quote(self::nowUtc()))
				->where($db->quoteName('id') . ' = ' . $proposalId)
				->where($db->quoteName('status') . ' = ' . $db->quote('open'))
		)->execute();
		self::loadNotices();
		CriticalNoticeHelper::resolveByRef(self::REF_PARTICIPANT, $proposalId);
		CriticalNoticeHelper::resolveByRef(self::REF_MASTER, $proposalId);
	}

	public static function userName(int $userId): string
	{
		if ($userId <= 0) {
			return '';
		}
		try {
			$db = Factory::getContainer()->get(DatabaseInterface::class);
			$db->setQuery(
				$db->getQuery(true)
					->select($db->quoteName('name'))
					->from($db->quoteName('#__users'))
					->where($db->quoteName('id') . ' = ' . $userId)
			);

			return trim((string) $db->loadResult());
		} catch (\Throwable $e) {
			return '';
		}
	}

	public static function entityTitle(DatabaseInterface $db, string $kind, int $entityId): string
	{
		$fallback = $kind === self::KIND_SEARCH ? 'Поиск моделей' : 'Курс';
		if ($entityId <= 0) {
			return $fallback;
		}
		$table = $kind === self::KIND_SEARCH ? '#__vigling_user_searches' : '#__vigling_user_courses';
		try {
			$db->setQuery(
				$db->getQuery(true)
					->select($db->quoteName('title'))
					->from($db->quoteName($table))
					->where($db->quoteName('id') . ' = ' . $entityId)
			);
			$title = trim((string) $db->loadResult());

			return $title !== '' ? $title : $fallback;
		} catch (\Throwable $e) {
			return $fallback;
		}
	}

	/**
	 * Used when a master changes the time of a group slot in the profile form. Returns the number of
	 * participants who were asked, or null when the slot must simply be moved (nobody to ask, or the
	 * database tables are not installed yet).
	 *
	 * @param array<string,mixed> $slotInfo Current slot row (starts_at_utc, ends_at_utc)
	 */
	public static function proposeFromSlotEdit(
		DatabaseInterface $db,
		string $kind,
		int $slotId,
		int $entityId,
		int $masterId,
		array $slotInfo,
		string $newStartUtc,
		string $newEndUtc
	): ?int {
		if (!self::isAvailable($db)) {
			return null;
		}
		$participants = self::participants($db, $kind, $slotId, $masterId);
		if ($participants === []) {
			return null;
		}
		$result = self::createProposal(
			$db,
			$kind,
			$slotId,
			$entityId,
			$masterId,
			self::entityTitle($db, $kind, $entityId),
			(string) ($slotInfo['starts_at_utc'] ?? ''),
			(string) ($slotInfo['ends_at_utc'] ?? ''),
			$newStartUtc,
			$newEndUtc,
			$participants
		);

		return $result['ok'] ? (int) $result['count'] : null;
	}

	public static function loadNotices(): void
	{
		if (class_exists(CriticalNoticeHelper::class, false)) {
			return;
		}
		$path = JPATH_SITE . '/components/com_pushnotify/src/Helper/CriticalNoticeHelper.php';
		if (is_file($path)) {
			require_once $path;
		}
	}

	private static function nowUtc(): string
	{
		return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');
	}
}
