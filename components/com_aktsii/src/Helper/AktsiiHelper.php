<?php

namespace Viglin\Component\Aktsii\Site\Helper;

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Viglin\Component\Poisk\Site\Helper\PoiskHelper;

class AktsiiHelper
{
    private static $fieldMapCache = null;

    private static function getUserFieldMap(): array
    {
        if (is_array(self::$fieldMapCache)) {
            return self::$fieldMapCache;
        }

        $db = Factory::getContainer()->get(\Joomla\Database\DatabaseInterface::class);
        $prefix = $db->getPrefix();
        $query = $db->getQuery(true)
            ->select([$db->quoteName('id'), $db->quoteName('name')])
            ->from($db->quoteName($prefix . 'fields'))
            ->where($db->quoteName('context') . ' = ' . $db->quote('com_users.user'));
        $db->setQuery($query);
        $rows = $db->loadAssocList() ?: [];
        $map = [];
        foreach ($rows as $row) {
            $map[(string) $row['name']] = (int) $row['id'];
        }
        self::$fieldMapCache = $map;
        return $map;
    }

    private static function getNormalizedStockServicesByUserIds(array $userIds): array
    {
        if (empty($userIds)) {
            return [];
        }

        $serviceClass = '\\Joomla\\Plugin\\User\\Vigling\\Service\\UserServicesService';
        if (!class_exists($serviceClass)) {
            $svcFile = JPATH_SITE . '/plugins/user/vigling/src/Service/UserServicesService.php';
            if (is_file($svcFile)) {
                require_once $svcFile;
            }
        }

        if (class_exists($serviceClass)) {
            try {
                $items = $serviceClass::getStockItemsForUsers(array_map('intval', $userIds));
                return $items;
            } catch (\Throwable $e) {
            }
        }

        $db = Factory::getContainer()->get(\Joomla\Database\DatabaseInterface::class);
        $ids = array_map('intval', $userIds);
        $query = $db->getQuery(true)
            ->select([
                $db->quoteName('user_id'),
                $db->quoteName('price'),
                $db->quoteName('old_price'),
                $db->quoteName('about_stock'),
                $db->quoteName('count_stock'),
                $db->quoteName('legacy_cat_id'),
                $db->quoteName('legacy_tag_id'),
                $db->quoteName('service_raw'),
            ])
            ->from($db->quoteName('#__vigling_user_stock_services'))
            ->whereIn($db->quoteName('user_id'), $ids)
            ->where($db->quoteName('is_active') . ' = 1')
            ->order($db->quoteName('id') . ' ASC');
        $db->setQuery($query);
        try {
            $rows = $db->loadObjectList() ?: [];
        } catch (\Throwable $e) {
            return [];
        }

        $byUser = [];
        foreach ($rows as $row) {
            $userId = (int) ($row->user_id ?? 0);
            if ($userId <= 0) {
                continue;
            }

            $price = isset($row->price) ? (int) $row->price : 0;
            $oldPrice = isset($row->old_price) ? (int) $row->old_price : 0;
            $aboutStock = isset($row->about_stock) ? trim((string) $row->about_stock) : '';
            $countStock = isset($row->count_stock) ? (int) $row->count_stock : 0;

            $serviceRaw = isset($row->service_raw) ? (string) $row->service_raw : '';
            if (empty($serviceRaw)) {
                $legacyCatId = isset($row->legacy_cat_id) ? (int) $row->legacy_cat_id : 0;
                $legacyTagId = isset($row->legacy_tag_id) ? (int) $row->legacy_tag_id : 0;
                $serviceRaw = $legacyCatId > 0 ? (string) $legacyCatId : '';
                if ($legacyTagId > 0) {
                    $serviceRaw .= '-' . $legacyTagId;
                }
            }

            $byUser[$userId][] = [
                'price' => $price,
                'old_price' => $oldPrice,
                'about_stock' => $aboutStock,
                'count_stock' => $countStock,
                'service_raw' => $serviceRaw,
            ];
        }

        return $byUser;
    }

    public static function getCategories(): array
    {
        return PoiskHelper::getCategories();
    }

    public static function getServiceHierarchy(): array
    {
        return PoiskHelper::getServiceHierarchy();
    }

    public static function getFieldsForUserIds(array $userIds, array $fieldNames): array
    {
        if (empty($userIds)) {
            return [];
        }
        $db = Factory::getContainer()->get(\Joomla\Database\DatabaseInterface::class);
        $prefix = $db->getPrefix();
        $ids = array_map('intval', $userIds);
        $fieldMap = self::getUserFieldMap();
        $fieldIdsByName = [];
        $fieldIds = [];
        foreach ($fieldNames as $name) {
            if (!isset($fieldMap[$name])) {
                continue;
            }
            $fieldId = (int) $fieldMap[$name];
            $fieldIdsByName[$fieldId] = (string) $name;
            $fieldIds[] = $fieldId;
        }
        if ($fieldIds === []) {
            return [];
        }

        $query = $db->getQuery(true)
            ->select('fv.item_id, fv.field_id, fv.value')
            ->from($db->quoteName($prefix . 'fields_values', 'fv'))
            ->whereIn($db->quoteName('fv.field_id'), $fieldIds)
            ->whereIn($db->quoteName('fv.item_id'), $ids);
        $db->setQuery($query);
        $rows = $db->loadObjectList() ?: [];
        $byUser = [];
        foreach ($rows as $r) {
            $fieldId = (int) $r->field_id;
            if (!isset($fieldIdsByName[$fieldId])) {
                continue;
            }
            $byUser[$r->item_id][$fieldIdsByName[$fieldId]] = $r->value;
        }

        if (in_array('stock_prices', $fieldNames, true)) {
            $stockByUser = self::getNormalizedStockServicesByUserIds($ids);
            foreach ($stockByUser as $userId => $stockItems) {
                $byUser[$userId]['stock_prices_items'] = $stockItems;
                $byUser[$userId]['stock_prices'] = '';
            }
        }

        return $byUser;
    }

    public static function getCities(): array
    {
        return PoiskHelper::getCities();
    }

    public static function getAreas(): array
    {
        return PoiskHelper::getAreas();
    }
}