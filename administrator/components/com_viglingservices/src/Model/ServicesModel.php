<?php

namespace Viglin\Component\Viglingservices\Administrator\Model;

\defined('_JEXEC') or die;

use Joomla\CMS\MVC\Factory\MVCFactoryInterface;
use Joomla\CMS\MVC\Model\ListModel;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\Database\QueryInterface;

class ServicesModel extends ListModel
{
    public function __construct($config = [], ?MVCFactoryInterface $factory = null)
    {
        if (empty($config['filter_fields'])) {
            $config['filter_fields'] = [
                'id', 'a.id',
                'title', 'a.title',
                'type', 'a.type',
                'is_active', 'a.is_active',
                'level', 'a.level',
                'sort_order', 'a.sort_order',
                'path', 'a.path',
                'legacy_source', 'a.legacy_source',
                'legacy_id', 'a.legacy_id',
                'parent_title', 'p.title',
            ];
        }
        parent::__construct($config, $factory);
    }

    protected function populateState($ordering = 'a.path', $direction = 'asc')
    {
        $app = Factory::getApplication();
        $this->setState('filter.type', $app->getUserStateFromRequest($this->context . '.filter.type', 'filter_type', '', 'cmd'));
        $this->setState('filter.is_active', $app->getUserStateFromRequest($this->context . '.filter.is_active', 'filter_is_active', '', 'cmd'));
        $this->setState('filter.show_legacy', $app->getUserStateFromRequest($this->context . '.filter.show_legacy', 'filter_show_legacy', '0', 'cmd'));
        parent::populateState($ordering, $direction);
    }

    protected function getListQuery(): QueryInterface
    {
        $db = $this->getDatabase();
        $query = $db->getQuery(true)
            ->select([
                'a.id',
                'a.parent_id',
                'a.level',
                'a.path',
                'a.sort_order',
                'a.type',
                'a.title',
                'a.slug',
                'a.is_active',
                'a.legacy_source',
                'a.legacy_id',
                'p.title AS parent_title',
            ])
            ->from($db->quoteName('#__vigling_service_nodes', 'a'))
            ->leftJoin(
                $db->quoteName('#__vigling_service_nodes', 'p')
                . ' ON ' . $db->quoteName('p.id') . ' = ' . $db->quoteName('a.parent_id')
            );

        $search = $this->getState('filter.search');
        if ($search !== null && trim($search) !== '') {
            $search = trim($search);
            $searchEsc = $db->escape(trim($search), true);
            if (is_numeric($search)) {
                $query->where(
                    '(a.id = ' . (int) $search
                    . ' OR a.title LIKE ' . $db->quote('%' . $searchEsc . '%')
                    . ' OR a.path LIKE ' . $db->quote('%' . $searchEsc . '%')
                    . ')'
                );
            } else {
                $searchLike = $db->quote('%' . str_replace(' ', '%', $searchEsc) . '%');
                $query->where(
                    '(a.title LIKE ' . $searchLike
                    . ' OR a.path LIKE ' . $searchLike
                    . ' OR p.title LIKE ' . $searchLike
                    . ')'
                );
            }
        }

        $type = $this->getState('filter.type');
        if ($type !== '') {
            $query->where($db->quoteName('a.type') . ' = ' . $db->quote($type));
        }

        $isActive = $this->getState('filter.is_active');
        if ($isActive !== '') {
            $query->where($db->quoteName('a.is_active') . ' = ' . (int) $isActive);
        }

        $showLegacy = (string) $this->getState('filter.show_legacy', '0');
        if ($showLegacy !== '1') {
            $query->where("(a.path <> " . $db->quote('legacy') . " AND a.path NOT LIKE " . $db->quote('legacy/%') . ")");
        }

        $listOrder = $this->getState('list.ordering', 'a.path');
        $listDirn = $this->getState('list.direction', 'ASC');
        $query->order($db->escape($listOrder) . ' ' . $db->escape($listDirn));

        return $query;
    }

    public function getTable($type = 'Service', $prefix = 'Administrator', $config = [])
    {
        return parent::getTable($type, $prefix, $config);
    }

    public function delete(&$pks): bool
    {
        $table = $this->getTable();
        foreach ((array) $pks as $pk) {
            if ($table->load($pk) && !$table->delete($pk)) {
                $this->setError($table->getError());
                return false;
            }
        }
        return true;
    }

    public function getParentOptions(): array
    {
        $db = $this->getDatabase();
        $query = $db->getQuery(true)
            ->select([$db->quoteName('id'), $db->quoteName('path'), $db->quoteName('title')])
            ->from($db->quoteName('#__vigling_service_nodes'))
            ->order($db->quoteName('path') . ' ASC');

        $showLegacy = (string) $this->getState('filter.show_legacy', '0');
        if ($showLegacy !== '1') {
            $query->where("(" . $db->quoteName('path') . " <> " . $db->quote('legacy')
                . " AND " . $db->quoteName('path') . " NOT LIKE " . $db->quote('legacy/%') . ")");
        }
        $db->setQuery($query);

        $rows = (array) $db->loadAssocList();
        $options = [
            (object) [
                'value' => 0,
                'text' => Text::_('COM_VIGLINGSERVICES_PARENT_ROOT'),
            ],
        ];

        foreach ($rows as $row) {
            $options[] = (object) [
                'value' => (int) ($row['id'] ?? 0),
                'text' => (string) (($row['path'] ?? '') . ' - ' . ($row['title'] ?? '')),
            ];
        }

        return $options;
    }
}
