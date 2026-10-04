<?php

namespace Joomla\Module\Specialists\Site\Helper;

use Joomla\CMS\Factory;

\defined('_JEXEC') or die;

class SpecialistsHelper
{
    public static function getCategories(): array
    {
        $db = Factory::getContainer()->get(\Joomla\Database\DatabaseInterface::class);
        $prefix = $db->getPrefix();

        $query = $db->getQuery(true)
            ->select('id, title')
            ->from($db->quoteName($prefix . 'categories'))
            ->where($db->quoteName('extension') . ' = ' . $db->quote('com_content'))
            ->where($db->quoteName('published') . ' = 1')
            ->where('(' . $db->quoteName('parent_id') . ' = 39 OR ' . $db->quoteName('path') . ' LIKE ' . $db->quote('uslugi/%') . ' OR ' . $db->quoteName('id') . ' IN (9,10,11,12,13,14,16,17,18,19,20,21))')
            ->order('title ASC');

        $db->setQuery($query);

        try {
            return $db->loadAssocList('id') ?: [];
        } catch (\Throwable $e) {
            return [];
        }
    }
}
