<?php

namespace Joomla\Plugin\User\Vigling\Helper;

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\Database\DatabaseInterface;

/**
 * A master is a member of the Joomla group titled «Мастер», a Super User,
 * or the legacy Author group (id 3) until those accounts are moved.
 */
final class MasterGroupHelper
{
	public const TITLE = 'Мастер';
	public const LEGACY_GROUP_ID = 3;
	public const SUPER_USER_GROUP_ID = 8;

	public static function isMaster($user): bool
	{
		if (!is_object($user) || empty($user->id) || !method_exists($user, 'getAuthorisedGroups')) {
			return false;
		}

		return self::isMasterGroupList((array) $user->getAuthorisedGroups());
	}

	public static function isMasterGroupList(array $groups): bool
	{
		$groups = array_values(array_unique(array_map('intval', $groups)));
		if (in_array(self::SUPER_USER_GROUP_ID, $groups, true) || in_array(self::LEGACY_GROUP_ID, $groups, true)) {
			return true;
		}
		$id = self::groupId();

		return $id > 0 && in_array($id, $groups, true);
	}

	public static function groupId(): int
	{
		static $id = null;
		if ($id !== null) {
			return $id;
		}
		$id = 0;
		try {
			$db = Factory::getContainer()->get(DatabaseInterface::class);
			$query = $db->getQuery(true)
				->select($db->quoteName('id'))
				->from($db->quoteName('#__usergroups'))
				->where($db->quoteName('title') . ' = ' . $db->quote(self::TITLE))
				->order($db->quoteName('id') . ' ASC')
				->setLimit(1);
			$db->setQuery($query);
			$id = (int) $db->loadResult();
		} catch (\Throwable $e) {
			$id = 0;
		}

		return $id;
	}
}
