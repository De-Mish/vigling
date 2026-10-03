<?php

namespace Viglin\Component\Pushnotify\Administrator\Model;

\defined('_JEXEC') or die;

use Joomla\CMS\MVC\Model\ListModel;

class SubscriptionsModel extends ListModel
{
	public function __construct($config = [])
	{
		$config['filter_fields'] = ['id', 'user_id', 'username', 'created', 'last_used'];
		parent::__construct($config);
	}

	protected function populateState($ordering = 's.id', $direction = 'desc')
	{
		$search = $this->getUserStateFromRequest($this->context . '.filter.search', 'filter_search', '', 'string');
		$this->setState('filter.search', trim($search));
		parent::populateState($ordering, $direction);
	}

	protected function getListQuery()
	{
		$db = $this->getDatabase();
		$query = $db->createQuery()
			->select(['s.id', 's.user_id', 's.fcm_token', 's.device_type', 's.browser', 's.created', 's.last_used'])
			->select($db->quoteName('u.username'))
			->from($db->quoteName('#__pushnotify_subscriptions', 's'))
			->leftJoin($db->quoteName('#__users', 'u') . ' ON u.id = s.user_id')
			->order($db->escape($this->getState('list.ordering', 's.id')) . ' ' . $db->escape($this->getState('list.direction', 'DESC')));
		$search = $this->getState('filter.search');
		if ($search !== '') {
			$search = '%' . $db->escape(trim($search), true) . '%';
			$query->where('(s.fcm_token LIKE ' . $db->quote($search) . ' OR u.username LIKE ' . $db->quote($search) . ' OR s.browser LIKE ' . $db->quote($search) . ' OR s.device_type LIKE ' . $db->quote($search) . ' OR s.user_id = ' . (int) trim((string) $this->getState('filter.search')) . ')');
		}
		return $query;
	}

	public function getTotal(): int
	{
		$db = $this->getDatabase();
		$query = $db->createQuery()
			->select('COUNT(*)')
			->from($db->quoteName('#__pushnotify_subscriptions'));
		$db->setQuery($query);
		return (int) $db->loadResult();
	}

	public function delete(array $ids): bool
	{
		if (empty($ids)) {
			return true;
		}
		$db = $this->getDatabase();
		$ids = array_map('intval', $ids);
		$query = $db->createQuery()
			->delete($db->quoteName('#__pushnotify_subscriptions'))
			->whereIn($db->quoteName('id'), $ids);
		$db->setQuery($query);
		$db->execute();
		return true;
	}
}
