<?php

namespace Viglin\Component\Pushnotify\Administrator\Model;

\defined('_JEXEC') or die;

use Joomla\CMS\MVC\Model\ListModel;

class LogsModel extends ListModel
{
	public function __construct($config = [])
	{
		$config['filter_fields'] = ['id', 'user_id', 'notification_type', 'status', 'recipient_role', 'sent_at'];
		parent::__construct($config);
	}

	protected function populateState($ordering = 'l.sent_at', $direction = 'desc')
	{
		$search = $this->getUserStateFromRequest($this->context . '.filter.search', 'filter_search', '', 'string');
		$status = $this->getUserStateFromRequest($this->context . '.filter.status', 'filter_status', '', 'cmd');
		$this->setState('filter.search', trim($search));
		$this->setState('filter.status', trim($status));
		parent::populateState($ordering, $direction);
	}

	protected function getListQuery()
	{
		$db = $this->getDatabase();
		$query = $db->getQuery(true)
			->select([
				'l.id',
				'l.user_id',
				'l.notification_type',
				'l.title',
				'l.body',
				'l.fcm_response',
				'l.status',
				'l.recipient_role',
				'l.sent_at',
				'l.delivered_at',
				'l.clicked_at',
				'u.name',
				'u.username',
				'u.email',
			])
			->from($db->quoteName('#__pushnotify_logs', 'l'))
			->leftJoin($db->quoteName('#__users', 'u') . ' ON u.id = l.user_id');

		$search = (string) $this->getState('filter.search');
		if ($search !== '') {
			$like = '%' . $db->escape($search, true) . '%';
			$query->where('(l.notification_type LIKE ' . $db->quote($like)
				. ' OR l.title LIKE ' . $db->quote($like)
				. ' OR l.body LIKE ' . $db->quote($like)
				. ' OR u.name LIKE ' . $db->quote($like)
				. ' OR u.username LIKE ' . $db->quote($like)
				. ' OR u.email LIKE ' . $db->quote($like)
				. ' OR l.user_id = ' . (int) $search . ')');
		}

		$status = (string) $this->getState('filter.status');
		if (in_array($status, ['sent', 'failed', 'pending'], true)) {
			$query->where($db->quoteName('l.status') . ' = ' . $db->quote($status));
		}

		$ordering = (string) $this->getState('list.ordering', 'l.sent_at');
		$direction = (string) $this->getState('list.direction', 'DESC');
		$allowed = ['id', 'user_id', 'notification_type', 'status', 'recipient_role', 'sent_at', 'l.sent_at'];
		$ordering = in_array($ordering, $allowed, true) ? $ordering : 'l.sent_at';
		if ($ordering === 'id') {
			$ordering = 'l.id';
		}
		$query->order($db->escape($ordering) . ' ' . $db->escape($direction));

		return $query;
	}

	public function delete(array $ids): bool
	{
		$ids = array_values(array_filter(array_map('intval', $ids)));
		if ($ids === []) {
			return true;
		}
		$db = $this->getDatabase();
		$query = $db->getQuery(true)
			->delete($db->quoteName('#__pushnotify_logs'))
			->whereIn($db->quoteName('id'), $ids);
		$db->setQuery($query)->execute();
		return true;
	}

	public function purge(): bool
	{
		$db = $this->getDatabase();
		$db->setQuery($db->getQuery(true)->delete($db->quoteName('#__pushnotify_logs')))->execute();
		return true;
	}
}
