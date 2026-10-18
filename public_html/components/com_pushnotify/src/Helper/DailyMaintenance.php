<?php

namespace Viglin\Component\Pushnotify\Site\Helper;

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Table\Usergroup;
use Joomla\Database\DatabaseInterface;
use Joomla\Plugin\User\Vigling\Helper\MasterGroupHelper;

/**
 * Once-a-day schema work for the reminder cron: indexes and the empty «Мастер» group.
 * It does not move existing users out of Author.
 */
final class DailyMaintenance
{
	public static function runDaily(DatabaseInterface $db): void
	{
		$dir = \defined('JPATH_CACHE') ? JPATH_CACHE : sys_get_temp_dir();
		if (!is_dir($dir)) {
			@mkdir($dir, 0775, true);
		}
		$stamp = $dir . '/vigling-maintenance.stamp';
		if (is_file($stamp) && (time() - (int) filemtime($stamp)) < 86400) {
			return;
		}

		try {
			self::ensureMasterGroup($db);
		} catch (\Throwable $e) {
		}
		try {
			self::ensureIndexes($db);
		} catch (\Throwable $e) {
		}

		@file_put_contents($stamp, (string) time(), LOCK_EX);
	}

	private static function ensureMasterGroup(DatabaseInterface $db): void
	{
		$helper = JPATH_PLUGINS . '/user/vigling/src/Helper/MasterGroupHelper.php';
		if (!class_exists(MasterGroupHelper::class, false) && is_file($helper)) {
			require_once $helper;
		}
		if (!class_exists(MasterGroupHelper::class, false)) {
			return;
		}
		if (MasterGroupHelper::groupId() > 0) {
			return;
		}

		$query = $db->getQuery(true)
			->select($db->quoteName('id'))
			->from($db->quoteName('#__usergroups'))
			->where($db->quoteName('id') . ' = 2')
			->setLimit(1);
		$db->setQuery($query);
		if ((int) $db->loadResult() !== 2) {
			return;
		}

		$group = new Usergroup($db);
		$group->parent_id = 2;
		$group->title = MasterGroupHelper::TITLE;
		$group->store();
	}

	private static function ensureIndexes(DatabaseInterface $db): void
	{
		$plans = [
			'#__vigling_bookings' => [
				'idx_vigling_book_master_time' => ['master_id', 'time', 'time_to'],
				'idx_vigling_book_user_time' => ['user_id', 'time'],
				'idx_vigling_book_course_slot' => ['course_slot_id'],
				'idx_vigling_book_search_slot' => ['search_slot_id'],
			],
			'#__vigling_course_slots' => [
				'idx_vigling_course_slot_master' => ['master_id', 'is_active', 'starts_at_utc'],
			],
			'#__vigling_search_slots' => [
				'idx_vigling_search_slot_master' => ['master_id', 'is_active', 'starts_at_utc'],
			],
			'#__fields_values' => [
				'idx_vigling_fields_field_item' => ['field_id', 'item_id'],
			],
		];

		foreach ($plans as $table => $indexes) {
			try {
				$columns = array_change_key_case((array) $db->getTableColumns($table, false), CASE_LOWER);
			} catch (\Throwable $e) {
				continue;
			}
			if ($columns === []) {
				continue;
			}
			$existing = [];
			try {
				$db->setQuery('SHOW INDEX FROM ' . $db->quoteName($table));
				foreach ($db->loadAssocList() ?: [] as $row) {
					$name = (string) ($row['Key_name'] ?? '');
					$seq = (int) ($row['Seq_in_index'] ?? 0);
					if ($name === '' || $seq < 1) {
						continue;
					}
					$existing[$name][$seq] = strtolower((string) ($row['Column_name'] ?? ''));
				}
			} catch (\Throwable $e) {
				continue;
			}
			$signatures = [];
			foreach ($existing as $parts) {
				ksort($parts);
				$signatures[] = implode(',', $parts);
			}

			foreach ($indexes as $name => $cols) {
				$missing = false;
				foreach ($cols as $col) {
					if (!isset($columns[strtolower($col)])) {
						$missing = true;
						break;
					}
				}
				if ($missing || isset($existing[$name])) {
					continue;
				}
				$signature = implode(',', array_map('strtolower', $cols));
				if (in_array($signature, $signatures, true)) {
					continue;
				}
				$quotedCols = [];
				foreach ($cols as $col) {
					$quotedCols[] = $db->quoteName($col);
				}
				try {
					$db->setQuery(
						'ALTER TABLE ' . $db->quoteName($table)
						. ' ADD INDEX ' . $db->quoteName($name)
						. ' (' . implode(', ', $quotedCols) . ')'
					)->execute();
					$signatures[] = $signature;
				} catch (\Throwable $e) {
				}
			}
		}
	}
}