<?php

namespace Viglin\Component\Pushnotify\Administrator\Model;

\defined('_JEXEC') or die;

use Joomla\CMS\MVC\Model\ListModel;

class SubscribersModel extends ListModel
{
	public function __construct($config = [])
	{
		$config['filter_fields'] = ['user_id', 'name', 'email', 'device_count', 'first_subscription', 'last_activity'];
		parent::__construct($config);
	}

	protected function populateState($ordering = 'last_activity', $direction = 'desc')
	{
		$search = $this->getUserStateFromRequest($this->context . '.filter.search', 'filter_search', '', 'string');
		$this->setState('filter.search', trim($search));
		parent::populateState($ordering, $direction);
	}

	protected function getListQuery()
	{
		$db = $this->getDatabase();
		$query = $db->createQuery()
			->select([
				's.user_id',
				'COUNT(s.id) AS device_count',
				'MIN(s.created) AS first_subscription',
				'MAX(s.last_used) AS last_activity',
				'GROUP_CONCAT(DISTINCT NULLIF(s.browser, "") ORDER BY s.browser SEPARATOR ", ") AS browsers',
				'GROUP_CONCAT(DISTINCT NULLIF(s.device_type, "") ORDER BY s.device_type SEPARATOR ", ") AS devices',
				'u.name',
				'u.username',
				'u.email',
				'(SELECT GROUP_CONCAT(g.title ORDER BY g.title SEPARATOR ", ") FROM ' . $db->quoteName('#__user_usergroup_map', 'ug') . ' INNER JOIN ' . $db->quoteName('#__usergroups', 'g') . ' ON g.id = ug.group_id WHERE ug.user_id = s.user_id) AS group_names',
				'p.notifications_enabled',
			])
			->from($db->quoteName('#__pushnotify_subscriptions', 's'))
			->leftJoin($db->quoteName('#__users', 'u') . ' ON u.id = s.user_id')
			->leftJoin($db->quoteName('#__pushnotify_preferences', 'p') . ' ON p.user_id = s.user_id')
			->group('s.user_id, u.name, u.username, u.email, p.notifications_enabled');

		$search = (string) $this->getState('filter.search');
		if ($search !== '') {
			$like = '%' . $db->escape($search, true) . '%';
			$query->where('(u.name LIKE ' . $db->quote($like) . ' OR u.username LIKE ' . $db->quote($like) . ' OR u.email LIKE ' . $db->quote($like) . ' OR s.user_id = ' . (int) $search . ')');
		}

		$order = $this->getState('list.ordering', 'last_activity');
		$direction = $this->getState('list.direction', 'DESC');
		$allowed = ['user_id', 'name', 'email', 'device_count', 'first_subscription', 'last_activity'];
		$order = in_array($order, $allowed, true) ? $order : 'last_activity';
		$query->order($db->escape($order) . ' ' . $db->escape($direction));

		return $query;
	}

	public function deleteForUsers(array $userIds): bool
	{
		$userIds = array_values(array_filter(array_map('intval', $userIds)));
		if ($userIds === []) {
			return true;
		}
		$db = $this->getDatabase();
		$query = $db->getQuery(true)
			->delete($db->quoteName('#__pushnotify_subscriptions'))
			->whereIn($db->quoteName('user_id'), $userIds);
		$db->setQuery($query)->execute();
		return true;
	}
}
