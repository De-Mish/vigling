<?php

namespace Viglin\Plugin\Ajax\Quickauth\Helper;

\defined('_JEXEC') or die;

use Joomla\Database\DatabaseInterface;

final class FavoritesHelper
{
	public const COMMENT_MAX = 90;

	public static function tableName(DatabaseInterface $db): string
	{
		return $db->replacePrefix('#__vigling_user_favorites');
	}

	public static function ensureTable(DatabaseInterface $db): void
	{
		$table = self::tableName($db);
		$db->setQuery('SHOW TABLES LIKE ' . $db->quote($table));
		if (!$db->loadResult()) {
			$db->setQuery(
				'CREATE TABLE IF NOT EXISTS ' . $db->quoteName('#__vigling_user_favorites') . " (
					`id` int unsigned NOT NULL AUTO_INCREMENT,
					`user_id` int NOT NULL,
					`master_id` int NOT NULL,
					`comment` varchar(90) NOT NULL DEFAULT '',
					`created_at` datetime NOT NULL,
					PRIMARY KEY (`id`),
					UNIQUE KEY `uniq_user_master` (`user_id`,`master_id`),
					KEY `idx_master_id` (`master_id`)
				) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
			)->execute();
			return;
		}

		$db->setQuery('SHOW COLUMNS FROM ' . $db->quoteName('#__vigling_user_favorites') . ' LIKE ' . $db->quote('comment'));
		if (!$db->loadResult()) {
			$db->setQuery(
				'ALTER TABLE ' . $db->quoteName('#__vigling_user_favorites')
				. ' ADD ' . $db->quoteName('comment') . " varchar(90) NOT NULL DEFAULT ''"
			)->execute();
		}
	}

	public static function listForUser(DatabaseInterface $db, int $userId): array
	{
		if ($userId <= 0) {
			return [];
		}

		self::ensureTable($db);

		$query = $db->getQuery(true)
			->select([
				$db->quoteName('f.master_id', 'id'),
				$db->quoteName('f.comment'),
				$db->quoteName('u.name'),
			])
			->from($db->quoteName('#__vigling_user_favorites', 'f'))
			->innerJoin(
				$db->quoteName('#__users', 'u')
				. ' ON ' . $db->quoteName('u.id') . ' = ' . $db->quoteName('f.master_id')
			)
			->where($db->quoteName('f.user_id') . ' = ' . $userId)
			->where($db->quoteName('u.block') . ' = 0')
			->order($db->quoteName('f.created_at') . ' DESC');
		$db->setQuery($query);

		$rows = $db->loadAssocList() ?: [];
		$list = [];
		foreach ($rows as $row) {
			$id = (int) ($row['id'] ?? 0);
			if ($id <= 0) {
				continue;
			}
			$list[] = [
				'id' => $id,
				'name' => trim((string) ($row['name'] ?? '')),
				'comment' => self::normalizeComment((string) ($row['comment'] ?? '')),
			];
		}

		return $list;
	}

	public static function normalizeComment(string $comment): string
	{
		$comment = trim(preg_replace("/\r\n?/", "\n", $comment) ?? $comment);
		if ($comment === '') {
			return '';
		}
		if (function_exists('mb_substr')) {
			return mb_substr($comment, 0, self::COMMENT_MAX);
		}

		return substr($comment, 0, self::COMMENT_MAX);
	}
}
