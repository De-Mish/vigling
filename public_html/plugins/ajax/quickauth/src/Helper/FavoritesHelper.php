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