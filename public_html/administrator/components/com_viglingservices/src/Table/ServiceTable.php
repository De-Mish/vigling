<?php

namespace Viglin\Component\Viglingservices\Administrator\Table;

\defined('_JEXEC') or die;

use Joomla\CMS\Language\Text;
use Joomla\CMS\Table\Table;
use Joomla\Database\DatabaseInterface;
use Joomla\Event\DispatcherInterface;

class ServiceTable extends Table
{
    public function __construct(DatabaseInterface $db, ?DispatcherInterface $dispatcher = null)
    {
        parent::__construct('#__vigling_service_nodes', 'id', $db, $dispatcher);
    }

    private function makeSlug(string $value): string
    {
        $value = trim(mb_strtolower($value, 'UTF-8'));
        $value = preg_replace('/[^a-z0-9а-яё]+/ui', '-', $value) ?? '';
        $value = trim($value, '-');
        return $value !== '' ? $value : 'node';
    }

    public function check()
    {
        if (trim((string) $this->title) === '') {
            $this->setError(Text::_('COM_VIGLINGSERVICES_FIELD_TITLE_REQUIRED'));
            return false;
        }

        $this->parent_id = (int) $this->parent_id;
        if ($this->parent_id < 0) {
            $this->parent_id = 0;
        }

        if ((int) $this->id > 0 && (int) $this->id === (int) $this->parent_id) {
            $this->setError(Text::_('COM_VIGLINGSERVICES_FIELD_PARENT_SELF_ERROR'));
            return false;
        }

        $db = $this->getDatabase();
        $parentPath = '';
        $parentLevel = -1;
        if ((int) $this->parent_id > 0) {
            $query = $db->getQuery(true)
                ->select([$db->quoteName('id'), $db->quoteName('level'), $db->quoteName('path')])
                ->from($db->quoteName($this->getTableName()))
                ->where($db->quoteName('id') . ' = ' . (int) $this->parent_id);
            $db->setQuery($query);
            $parent = $db->loadAssoc();
            if (!$parent) {
                $this->setError(Text::_('COM_VIGLINGSERVICES_FIELD_PARENT_NOT_FOUND_ERROR'));
                return false;
            }
            $parentPath = (string) ($parent['path'] ?? '');
            $parentLevel = (int) ($parent['level'] ?? 0);
        }

        if ((int) $this->id > 0 && (int) $this->parent_id > 0) {
            $query = $db->getQuery(true)
                ->select($db->quoteName('path'))
                ->from($db->quoteName($this->getTableName()))
                ->where($db->quoteName('id') . ' = ' . (int) $this->id);
            $db->setQuery($query);
            $currentPath = (string) $db->loadResult();

            if ($currentPath !== '' && ($parentPath === $currentPath || str_starts_with($parentPath . '/', $currentPath . '/'))) {
                $this->setError(Text::_('COM_VIGLINGSERVICES_FIELD_PARENT_DESCENDANT_ERROR'));
                return false;
            }
        }

        if (trim((string) $this->slug) === '') {
            $this->slug = $this->makeSlug((string) $this->title);
        } else {
            $this->slug = $this->makeSlug((string) $this->slug);
        }

        $this->level = $parentLevel + 1;
        $this->path = $parentPath !== '' ? ($parentPath . '/' . $this->slug) : $this->slug;

        $this->sort_order = (int) $this->sort_order;
        if ($this->sort_order < 0) {
            $this->sort_order = 0;
        }

        $allowedTypes = ['group', 'service', 'variant'];
        if (!in_array((string) $this->type, $allowedTypes, true)) {
            $this->type = 'service';
        }

        $this->is_active = (int) $this->is_active ? 1 : 0;
        $this->legacy_source = trim((string) $this->legacy_source) ?: null;
        $this->legacy_id = $this->legacy_id === '' ? null : $this->legacy_id;

        return true;
    }
}
