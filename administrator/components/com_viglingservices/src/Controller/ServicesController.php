<?php

namespace Viglin\Component\Viglingservices\Administrator\Controller;

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Controller\AdminController;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Session\Session;
use Joomla\Database\DatabaseInterface;
use RuntimeException;

class ServicesController extends AdminController
{
    public function getModel($name = 'Services', $prefix = 'Administrator', $config = ['ignore_request' => true])
    {
        return parent::getModel($name, $prefix, $config);
    }

    public function massActivate(): void
    {
        $this->setActiveForSelection(1);
    }

    public function massDeactivate(): void
    {
        $this->setActiveForSelection(0);
    }

    public function massSortUp(): void
    {
        $this->shiftSortForSelection(-10);
    }

    public function massSortDown(): void
    {
        $this->shiftSortForSelection(10);
    }

    public function massMoveParent(): void
    {
        Session::checkToken() or jexit(Text::_('JINVALID_TOKEN'));

        $app = Factory::getApplication();
        $db = $this->getDb();
        $cid = $this->input->post->get('cid', [], 'array');
        $selected = array_values(array_unique(array_map('intval', (array) $cid)));
        $selected = array_filter($selected, static fn (int $id): bool => $id > 0);
        $newParentId = (int) $this->input->post->getInt('mass_parent_id', 0);

        if (empty($selected)) {
            $app->enqueueMessage(Text::_('COM_VIGLINGSERVICES_NO_ITEM_SELECTED'), 'warning');
            $this->setRedirect(Route::_('index.php?option=com_viglingservices&view=services', false));
            return;
        }

        if ($newParentId > 0 && in_array($newParentId, $selected, true)) {
            $app->enqueueMessage(Text::_('COM_VIGLINGSERVICES_MASS_PARENT_IN_SELECTION'), 'error');
            $this->setRedirect(Route::_('index.php?option=com_viglingservices&view=services', false));
            return;
        }

        try {
            $pathById = $this->getPathByIds($selected);
            $parentPath = '';
            if ($newParentId > 0) {
                $query = $db->getQuery(true)
                    ->select($db->quoteName('path'))
                    ->from($db->quoteName('#__vigling_service_nodes'))
                    ->where($db->quoteName('id') . ' = ' . $newParentId);
                $db->setQuery($query);
                $parentPath = (string) $db->loadResult();
                if ($parentPath === '') {
                    throw new RuntimeException(Text::_('COM_VIGLINGSERVICES_FIELD_PARENT_NOT_FOUND_ERROR'));
                }
            }

            foreach ($selected as $id) {
                $path = (string) ($pathById[$id] ?? '');
                if ($path !== '' && $parentPath !== '' && ($parentPath === $path || str_starts_with($parentPath . '/', $path . '/'))) {
                    throw new RuntimeException(Text::_('COM_VIGLINGSERVICES_FIELD_PARENT_DESCENDANT_ERROR'));
                }
            }

            foreach ($selected as $id) {
                $query = $db->getQuery(true)
                    ->update($db->quoteName('#__vigling_service_nodes'))
                    ->set($db->quoteName('parent_id') . ' = ' . $newParentId)
                    ->where($db->quoteName('id') . ' = ' . (int) $id);
                $db->setQuery($query)->execute();
            }

            $this->rebuildTree();
            $app->enqueueMessage(Text::sprintf('COM_VIGLINGSERVICES_MASS_PARENT_CHANGED', count($selected)));
        } catch (\Throwable $e) {
            $app->enqueueMessage($e->getMessage(), 'error');
        }

        $this->setRedirect(Route::_('index.php?option=com_viglingservices&view=services', false));
    }

    private function setActiveForSelection(int $active): void
    {
        Session::checkToken() or jexit(Text::_('JINVALID_TOKEN'));
        $app = Factory::getApplication();
        $db = $this->getDb();
        $cid = $this->input->post->get('cid', [], 'array');
        $selected = array_values(array_unique(array_map('intval', (array) $cid)));
        $selected = array_filter($selected, static fn (int $id): bool => $id > 0);

        if (empty($selected)) {
            $app->enqueueMessage(Text::_('COM_VIGLINGSERVICES_NO_ITEM_SELECTED'), 'warning');
            $this->setRedirect(Route::_('index.php?option=com_viglingservices&view=services', false));
            return;
        }

        $query = $db->getQuery(true)
            ->update($db->quoteName('#__vigling_service_nodes'))
            ->set($db->quoteName('is_active') . ' = ' . $active)
            ->where($db->quoteName('id') . ' IN (' . implode(',', $selected) . ')');
        $db->setQuery($query)->execute();

        $msgKey = $active ? 'COM_VIGLINGSERVICES_MASS_ACTIVATED' : 'COM_VIGLINGSERVICES_MASS_DEACTIVATED';
        $app->enqueueMessage(Text::sprintf($msgKey, count($selected)));
        $this->setRedirect(Route::_('index.php?option=com_viglingservices&view=services', false));
    }

    private function shiftSortForSelection(int $delta): void
    {
        Session::checkToken() or jexit(Text::_('JINVALID_TOKEN'));
        $app = Factory::getApplication();
        $db = $this->getDb();
        $cid = $this->input->post->get('cid', [], 'array');
        $selected = array_values(array_unique(array_map('intval', (array) $cid)));
        $selected = array_filter($selected, static fn (int $id): bool => $id > 0);

        if (empty($selected)) {
            $app->enqueueMessage(Text::_('COM_VIGLINGSERVICES_NO_ITEM_SELECTED'), 'warning');
            $this->setRedirect(Route::_('index.php?option=com_viglingservices&view=services', false));
            return;
        }

        $query = $db->getQuery(true)
            ->select([$db->quoteName('id'), $db->quoteName('sort_order')])
            ->from($db->quoteName('#__vigling_service_nodes'))
            ->where($db->quoteName('id') . ' IN (' . implode(',', $selected) . ')');
        $db->setQuery($query);
        $rows = (array) $db->loadAssocList('id');

        foreach ($selected as $id) {
            $current = (int) ($rows[$id]['sort_order'] ?? 0);
            $next = max(0, $current + $delta);
            $update = $db->getQuery(true)
                ->update($db->quoteName('#__vigling_service_nodes'))
                ->set($db->quoteName('sort_order') . ' = ' . $next)
                ->where($db->quoteName('id') . ' = ' . (int) $id);
            $db->setQuery($update)->execute();
        }

        $msgKey = $delta < 0 ? 'COM_VIGLINGSERVICES_MASS_SORT_UP' : 'COM_VIGLINGSERVICES_MASS_SORT_DOWN';
        $app->enqueueMessage(Text::sprintf($msgKey, count($selected)));
        $this->setRedirect(Route::_('index.php?option=com_viglingservices&view=services', false));
    }

    private function getPathByIds(array $ids): array
    {
        $db = $this->getDb();
        if (empty($ids)) {
            return [];
        }

        $query = $db->getQuery(true)
            ->select([$db->quoteName('id'), $db->quoteName('path')])
            ->from($db->quoteName('#__vigling_service_nodes'))
            ->where($db->quoteName('id') . ' IN (' . implode(',', array_map('intval', $ids)) . ')');
        $db->setQuery($query);

        $rows = (array) $db->loadAssocList();
        $result = [];
        foreach ($rows as $row) {
            $result[(int) ($row['id'] ?? 0)] = (string) ($row['path'] ?? '');
        }
        return $result;
    }

    private function rebuildTree(): void
    {
        $db = $this->getDb();
        $query = $db->getQuery(true)
            ->select([
                $db->quoteName('id'),
                $db->quoteName('parent_id'),
                $db->quoteName('slug'),
                $db->quoteName('title'),
                $db->quoteName('sort_order'),
            ])
            ->from($db->quoteName('#__vigling_service_nodes'))
            ->order($db->quoteName('parent_id') . ' ASC, ' . $db->quoteName('sort_order') . ' ASC, ' . $db->quoteName('id') . ' ASC');
        $db->setQuery($query);
        $rows = (array) $db->loadAssocList();

        $byId = [];
        $children = [];
        foreach ($rows as $row) {
            $id = (int) ($row['id'] ?? 0);
            $parentId = (int) ($row['parent_id'] ?? 0);
            $byId[$id] = $row;
            if (!isset($children[$parentId])) {
                $children[$parentId] = [];
            }
            $children[$parentId][] = $id;
        }

        $visited = [];
        $walk = function (int $parentId, int $level, string $parentPath) use (&$walk, &$visited, $children, $byId, $db): void {
            $ids = $children[$parentId] ?? [];
            foreach ($ids as $id) {
                if (isset($visited[$id])) {
                    continue;
                }
                $visited[$id] = true;
                $row = $byId[$id] ?? null;
                if (!$row) {
                    continue;
                }
                $slug = trim((string) ($row['slug'] ?? ''));
                if ($slug === '') {
                    $slug = 'node-' . $id;
                }
                $path = $parentPath !== '' ? ($parentPath . '/' . $slug) : $slug;

                $query = $db->getQuery(true)
                    ->update($db->quoteName('#__vigling_service_nodes'))
                    ->set($db->quoteName('level') . ' = ' . $level)
                    ->set($db->quoteName('path') . ' = ' . $db->quote($path))
                    ->where($db->quoteName('id') . ' = ' . $id);
                $db->setQuery($query)->execute();

                $walk($id, $level + 1, $path);
            }
        };

        $walk(0, 0, '');
    }

    private function getDb(): DatabaseInterface
    {
        return Factory::getContainer()->get(DatabaseInterface::class);
    }
}
