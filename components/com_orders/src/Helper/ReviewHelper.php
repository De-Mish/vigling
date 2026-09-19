<?php

namespace Viglin\Component\Orders\Site\Helper;

\defined('_JEXEC') or die;

use Joomla\Database\DatabaseInterface;

class ReviewHelper
{
	public const DIRECTION_CLIENT_TO_MASTER = 'client_to_master';
	public const DIRECTION_MASTER_TO_CLIENT = 'master_to_client';
	public const ANONYMOUS_NAME = 'Анонимно';

	public static function ensureSchema(DatabaseInterface $db): bool
	{
		try {
			$prefix = $db->getPrefix();
			$table = $prefix . 'vigling_reviews';
			$db->setQuery('SHOW TABLES LIKE ' . $db->quote($table));
			if (!$db->loadResult()) {
				$db->setQuery(
					'CREATE TABLE ' . $db->quoteName($table) . ' ('
					. $db->quoteName('id') . ' INT UNSIGNED NOT NULL AUTO_INCREMENT,'
					. $db->quoteName('booking_id') . ' INT UNSIGNED NOT NULL,'
					. $db->quoteName('from_user_id') . ' INT UNSIGNED NOT NULL,'
					. $db->quoteName('to_user_id') . ' INT UNSIGNED NOT NULL,'
					. $db->quoteName('direction') . ' VARCHAR(24) NOT NULL,'
					. $db->quoteName('rating') . ' TINYINT UNSIGNED NOT NULL,'
					. $db->quoteName('review_text') . ' VARCHAR(1000) NULL DEFAULT NULL,'
					. $db->quoteName('is_anonymous') . ' TINYINT(1) NOT NULL DEFAULT 0,'
					. $db->quoteName('created') . ' DATETIME NOT NULL,'
					. $db->quoteName('modified') . ' DATETIME NOT NULL,'
					. ' PRIMARY KEY (' . $db->quoteName('id') . '),'
					. ' UNIQUE KEY ' . $db->quoteName('uniq_booking_direction') . ' (' . $db->quoteName('booking_id') . ', ' . $db->quoteName('direction') . '),'
					. ' KEY ' . $db->quoteName('idx_to_user') . ' (' . $db->quoteName('to_user_id') . ', ' . $db->quoteName('direction') . ')'
					. ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
				)->execute();
			}

			$columns = array_change_key_case($db->getTableColumns('#__vigling_bookings', false), CASE_LOWER);
			$after = isset($columns['comment']) ? 'comment' : 'service_name';
			if (!isset($columns['client_after_comment'])) {
				$db->setQuery(
					'ALTER TABLE ' . $db->quoteName('#__vigling_bookings')
					. ' ADD COLUMN ' . $db->quoteName('client_after_comment') . ' VARCHAR(500) NULL DEFAULT NULL'
					. ' AFTER ' . $db->quoteName($after)
				)->execute();
				$after = 'client_after_comment';
			} else {
				$after = 'client_after_comment';
			}
			if (!isset($columns['master_after_comment'])) {
				$db->setQuery(
					'ALTER TABLE ' . $db->quoteName('#__vigling_bookings')
					. ' ADD COLUMN ' . $db->quoteName('master_after_comment') . ' VARCHAR(500) NULL DEFAULT NULL'
					. ' AFTER ' . $db->quoteName($after)
				)->execute();
			}

			return true;
		} catch (\Throwable $e) {
			return false;
		}
	}

	public static function normalizeComment(string $raw): string
	{
		$text = preg_replace('/\s+/u', ' ', trim($raw));
		$text = trim((string) $text);
		if ($text === '') {
			return '';
		}
		if (function_exists('mb_substr')) {
			return mb_substr($text, 0, 500);
		}

		return substr($text, 0, 500);
	}

	public static function normalizeReviewText(string $raw): string
	{
		$text = trim($raw);
		if ($text === '') {
			return '';
		}
		if (function_exists('mb_substr')) {
			return mb_substr($text, 0, 1000);
		}

		return substr($text, 0, 1000);
	}

	public static function bookingIsPast($booking): bool
	{
		$timeRaw = '';
		if (is_object($booking)) {
			$timeRaw = trim((string) ($booking->time ?? ''));
		} elseif (is_array($booking)) {
			$timeRaw = trim((string) ($booking['time'] ?? ''));
		}
		if ($timeRaw === '') {
			return false;
		}
		try {
			$utc = new \DateTimeZone('UTC');
			$start = new \DateTimeImmutable($timeRaw, $utc);

			return $start < new \DateTimeImmutable('now', $utc);
		} catch (\Throwable $e) {
			return false;
		}
	}

	/**
	 * @param array<int> $bookingIds
	 * @return array<int, array<string, object>>
	 */
	public static function loadForBookings(DatabaseInterface $db, array $bookingIds): array
	{
		$ids = array_values(array_unique(array_filter(array_map('intval', $bookingIds), static fn(int $id): bool => $id > 0)));
		if ($ids === []) {
			return [];
		}
		if (!self::ensureSchema($db)) {
			return [];
		}
		$query = $db->getQuery(true)
			->select([
				$db->quoteName('r.id'),
				$db->quoteName('r.booking_id'),
				$db->quoteName('r.from_user_id'),
				$db->quoteName('r.to_user_id'),
				$db->quoteName('r.direction'),
				$db->quoteName('r.rating'),
				$db->quoteName('r.review_text'),
				$db->quoteName('r.is_anonymous'),
				$db->quoteName('r.created'),
				$db->quoteName('r.modified'),
				$db->quoteName('u.name', 'from_name'),
			])
			->from($db->quoteName('#__vigling_reviews', 'r'))
			->join('LEFT', $db->quoteName('#__users', 'u') . ' ON ' . $db->quoteName('u.id') . ' = ' . $db->quoteName('r.from_user_id'))
			->whereIn($db->quoteName('r.booking_id'), $ids);
		$db->setQuery($query);
		$rows = $db->loadObjectList() ?: [];
		$out = [];
		foreach ($rows as $row) {
			$bookingId = (int) ($row->booking_id ?? 0);
			$direction = trim((string) ($row->direction ?? ''));
			if ($bookingId <= 0 || $direction === '') {
				continue;
			}
			$out[$bookingId][$direction] = $row;
		}

		return $out;
	}

	/**
	 * @return list<object>
	 */
	public static function loadAboutUser(DatabaseInterface $db, int $userId, string $direction = ''): array
	{
		if ($userId <= 0) {
			return [];
		}
		if (!self::ensureSchema($db)) {
			return [];
		}
		$query = $db->getQuery(true)
			->select([
				$db->quoteName('r.id'),
				$db->quoteName('r.booking_id'),
				$db->quoteName('r.from_user_id'),
				$db->quoteName('r.to_user_id'),
				$db->quoteName('r.direction'),
				$db->quoteName('r.rating'),
				$db->quoteName('r.review_text'),
				$db->quoteName('r.is_anonymous'),
				$db->quoteName('r.created'),
				$db->quoteName('r.modified'),
				$db->quoteName('u.name', 'from_name'),
			])
			->from($db->quoteName('#__vigling_reviews', 'r'))
			->join('LEFT', $db->quoteName('#__users', 'u') . ' ON ' . $db->quoteName('u.id') . ' = ' . $db->quoteName('r.from_user_id'))
			->where($db->quoteName('r.to_user_id') . ' = ' . $userId)
			->order($db->quoteName('r.created') . ' DESC');
		if ($direction !== '') {
			$query->where($db->quoteName('r.direction') . ' = ' . $db->quote($direction));
		}
		$db->setQuery($query);

		return $db->loadObjectList() ?: [];
	}

	public static function displayName($review): string
	{
		if (is_object($review) && (int) ($review->is_anonymous ?? 0) === 1) {
			return self::ANONYMOUS_NAME;
		}
		if (is_array($review) && (int) ($review['is_anonymous'] ?? 0) === 1) {
			return self::ANONYMOUS_NAME;
		}
		$name = '';
		if (is_object($review)) {
			$name = trim((string) ($review->from_name ?? ''));
		} elseif (is_array($review)) {
			$name = trim((string) ($review['from_name'] ?? ''));
		}

		return $name !== '' ? $name : self::ANONYMOUS_NAME;
	}

	/**
	 * @param list<object> $reviews
	 */
	public static function averageRating(array $reviews): ?float
	{
		$sum = 0;
		$count = 0;
		foreach ($reviews as $review) {
			$rating = is_object($review) ? (int) ($review->rating ?? 0) : (int) ($review['rating'] ?? 0);
			if ($rating >= 1 && $rating <= 5) {
				$sum += $rating;
				$count++;
			}
		}
		if ($count === 0) {
			return null;
		}

		return round($sum / $count, 1);
	}

	public static function saveReview(
		DatabaseInterface $db,
		object $booking,
		int $fromUserId,
		string $direction,
		int $rating,
		string $reviewText,
		bool $anonymous
	): array {
		if (!self::ensureSchema($db)) {
			return ['ok' => false, 'message' => 'Не удалось сохранить отзыв'];
		}
		if ($rating < 1 || $rating > 5) {
			return ['ok' => false, 'message' => 'Оценка должна быть от 1 до 5'];
		}
		if (!self::bookingIsPast($booking)) {
			return ['ok' => false, 'message' => 'Отзыв можно оставить только после визита'];
		}

		$clientId = (int) ($booking->user_id ?? 0);
		$masterId = (int) ($booking->master_id ?? 0);
		$bookingId = (int) ($booking->id ?? 0);
		if ($bookingId <= 0 || $clientId <= 0 || $masterId <= 0) {
			return ['ok' => false, 'message' => 'Запись не найдена'];
		}

		if ($direction === self::DIRECTION_CLIENT_TO_MASTER) {
			if ($fromUserId !== $clientId) {
				return ['ok' => false, 'message' => 'Нет прав на этот отзыв'];
			}
			$toUserId = $masterId;
		} elseif ($direction === self::DIRECTION_MASTER_TO_CLIENT) {
			if ($fromUserId !== $masterId) {
				return ['ok' => false, 'message' => 'Нет прав на этот отзыв'];
			}
			$toUserId = $clientId;
		} else {
			return ['ok' => false, 'message' => 'Некорректный тип отзыва'];
		}

		$now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');
		$text = self::normalizeReviewText($reviewText);
		$query = $db->getQuery(true)
			->select($db->quoteName('id'))
			->from($db->quoteName('#__vigling_reviews'))
			->where($db->quoteName('booking_id') . ' = ' . $bookingId)
			->where($db->quoteName('direction') . ' = ' . $db->quote($direction));
		$db->setQuery($query);
		$existingId = (int) $db->loadResult();

		if ($existingId > 0) {
			$update = $db->getQuery(true)
				->update($db->quoteName('#__vigling_reviews'))
				->set($db->quoteName('rating') . ' = ' . $rating)
				->set($db->quoteName('review_text') . ' = ' . ($text !== '' ? $db->quote($text) : 'NULL'))
				->set($db->quoteName('is_anonymous') . ' = ' . ($anonymous ? 1 : 0))
				->set($db->quoteName('modified') . ' = ' . $db->quote($now))
				->where($db->quoteName('id') . ' = ' . $existingId);
			$db->setQuery($update)->execute();
		} else {
			$insert = $db->getQuery(true)
				->insert($db->quoteName('#__vigling_reviews'))
				->columns([
					$db->quoteName('booking_id'),
					$db->quoteName('from_user_id'),
					$db->quoteName('to_user_id'),
					$db->quoteName('direction'),
					$db->quoteName('rating'),
					$db->quoteName('review_text'),
					$db->quoteName('is_anonymous'),
					$db->quoteName('created'),
					$db->quoteName('modified'),
				])
				->values(implode(',', [
					$bookingId,
					$fromUserId,
					$toUserId,
					$db->quote($direction),
					$rating,
					$text !== '' ? $db->quote($text) : 'NULL',
					$anonymous ? 1 : 0,
					$db->quote($now),
					$db->quote($now),
				]));
			$db->setQuery($insert)->execute();
		}

		return ['ok' => true, 'message' => 'Отзыв сохранён'];
	}

	public static function saveAfterComment(
		DatabaseInterface $db,
		object $booking,
		int $fromUserId,
		string $role,
		string $comment
	): array {
		if (!self::ensureSchema($db)) {
			return ['ok' => false, 'message' => 'Не удалось сохранить комментарий'];
		}
		if (!self::bookingIsPast($booking)) {
			return ['ok' => false, 'message' => 'Комментарий к прошедшей записи можно оставить только после визита'];
		}
		$clientId = (int) ($booking->user_id ?? 0);
		$masterId = (int) ($booking->master_id ?? 0);
		$bookingId = (int) ($booking->id ?? 0);
		if ($bookingId <= 0) {
			return ['ok' => false, 'message' => 'Запись не найдена'];
		}
		$column = '';
		if ($role === 'client' && $fromUserId === $clientId) {
			$column = 'client_after_comment';
		} elseif ($role === 'master' && $fromUserId === $masterId) {
			$column = 'master_after_comment';
		} else {
			return ['ok' => false, 'message' => 'Нет прав на этот комментарий'];
		}

		$text = self::normalizeComment($comment);
		$query = $db->getQuery(true)
			->update($db->quoteName('#__vigling_bookings'))
			->set($db->quoteName($column) . ' = ' . ($text !== '' ? $db->quote($text) : 'NULL'))
			->where($db->quoteName('id') . ' = ' . $bookingId);
		$db->setQuery($query)->execute();

		return ['ok' => true, 'message' => $text !== '' ? 'Комментарий сохранён' : 'Комментарий удалён'];
	}
}
